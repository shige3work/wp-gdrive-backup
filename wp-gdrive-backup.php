<?php
/**
 * Plugin Name: WP Google Drive Backup
 * Description: サイトのバックアップをZipとSQL形式で生成し、定期的にGoogle Driveへアップロードするプラグインです。
 * Version: 1.1.12
 * Author: SHIGE3.WORK
 * Author URI: https://www.shige3.work
 * Text Domain: wp-gdrive-backup
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WP_GDRIVE_BACKUP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_GDRIVE_BACKUP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Composer autoload
if ( file_exists( WP_GDRIVE_BACKUP_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
    require_once WP_GDRIVE_BACKUP_PLUGIN_DIR . 'vendor/autoload.php';
}

// Plugin Update Checker
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

if ( class_exists( 'YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
    $myUpdateChecker = PucFactory::buildUpdateChecker(
        'https://github.com/shige3work/wp-gdrive-backup/',
        __FILE__,
        'wp-gdrive-backup'
    );
    // Set the branch that contains the stable release.
    $myUpdateChecker->setBranch('main');
}


// Load includes
$includes = [
    'includes/class-logger.php',
    'includes/class-db-dumper.php',
    'includes/class-backup-engine.php',
    'includes/class-gdrive-uploader.php',
    'includes/class-retention-manager.php',
    'includes/class-mailer.php',
    'includes/class-cron-manager.php',
];

foreach ( $includes as $file ) {
    if ( file_exists( WP_GDRIVE_BACKUP_PLUGIN_DIR . $file ) ) {
        require_once WP_GDRIVE_BACKUP_PLUGIN_DIR . $file;
    }
}

class WP_GDrive_Backup {
    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'wp_ajax_wpgb_chunk_step', array( $this, 'ajax_chunk_step' ) );
        add_action( 'wp_ajax_wpgb_prepare_retry_upload', array( $this, 'ajax_prepare_retry_upload' ) );
        
        // Handle clear logs action
        if ( isset($_POST['wpgb_clear_logs']) && check_admin_referer('wpgb_clear_logs') ) {
            WP_GDrive_Logger::clear_logs();
        }

        // Initialize cron manager
        if ( class_exists( 'WP_GDrive_Cron_Manager' ) ) {
            WP_GDrive_Cron_Manager::init();
        }
    }

    public function add_admin_menu() {
        add_menu_page(
            'Google Drive Backup',
            'GDrive Backup',
            'manage_options',
            'wp-gdrive-backup',
            [ $this, 'render_settings_page' ],
            'dashicons-backup',
            30
        );
    }

    public function register_settings() {
        register_setting( 'wp_gdrive_backup_settings', 'wpgb_gdrive_client_id' );
        register_setting( 'wp_gdrive_backup_settings', 'wpgb_gdrive_client_secret' );
        register_setting( 'wp_gdrive_backup_settings', 'wpgb_gdrive_folder_id' );
        register_setting( 'wp_gdrive_backup_settings', 'wpgb_backup_interval' );
        register_setting( 'wp_gdrive_backup_settings', 'wpgb_backup_monthly_day' );
        register_setting( 'wp_gdrive_backup_settings', 'wpgb_backup_monthly_hour' );
        register_setting( 'wp_gdrive_backup_settings', 'wpgb_backup_weekly_dow' );
        register_setting( 'wp_gdrive_backup_settings', 'wpgb_backup_weekly_hour' );
        register_setting( 'wp_gdrive_backup_settings', 'wpgb_retention_period' );
        register_setting( 'wp_gdrive_backup_settings', 'wpgb_report_email' );
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $client_id = get_option('wpgb_gdrive_client_id');
        $client_secret = get_option('wpgb_gdrive_client_secret');
        $redirect_uri = admin_url('admin.php?page=wp-gdrive-backup');
        
        if ( class_exists('Google_Client') ) {
            $client = new Google_Client();
            $client->setClientId($client_id);
            $client->setClientSecret($client_secret);
            $client->setRedirectUri($redirect_uri);
            $client->addScope(Google_Service_Drive::DRIVE_FILE);
            $client->setAccessType('offline');
            $client->setPrompt('consent');

            if ( isset($_GET['code']) && !isset($_GET['settings-updated']) ) {
                $token = $client->fetchAccessTokenWithAuthCode(sanitize_text_field($_GET['code']));
                if ( !isset($token['error']) && isset($token['refresh_token']) ) {
                    update_option('wpgb_gdrive_refresh_token', $token['refresh_token']);
                    echo '<div class="updated"><p>Google Drive との連携が完了しました！（リフレッシュトークンを取得しました）</p></div>';
                } elseif ( !isset($token['error']) ) {
                    echo '<div class="error"><p>認証エラー: リフレッシュトークンが取得できませんでした。連携を解除してやり直してください。</p></div>';
                } else {
                    echo '<div class="error"><p>認証エラー: ' . esc_html($token['error_description'] ?? $token['error']) . '</p></div>';
                }
            }

            if ( isset($_POST['wpgb_revoke_auth']) && check_admin_referer('wpgb_revoke_auth') ) {
                delete_option('wpgb_gdrive_refresh_token');
                echo '<div class="updated"><p>Google Drive との連携を解除しました。</p></div>';
            }
        }
        
        $refresh_token = get_option('wpgb_gdrive_refresh_token');
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'settings';

        echo '<div class="wrap">';
        echo '<h1>Google Drive Backup Settings</h1>';

        echo '<h2 class="nav-tab-wrapper">';
        echo '<a href="?page=wp-gdrive-backup&tab=settings" class="nav-tab ' . ($active_tab == 'settings' ? 'nav-tab-active' : '') . '">設定</a>';
        echo '<a href="?page=wp-gdrive-backup&tab=logs" class="nav-tab ' . ($active_tab == 'logs' ? 'nav-tab-active' : '') . '">エラーログ</a>';
        echo '</h2>';

        if ( $active_tab == 'logs' ) {
            $this->render_logs_tab();
            echo '</div>';
            return;
        }

        // --- Settings Tab ---
        ?>
        <form action="options.php" method="post">
                <?php
                settings_fields( 'wp_gdrive_backup_settings' );
                do_settings_sections( 'wp_gdrive_backup_settings' );
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="wpgb_gdrive_client_id">OAuth クライアント ID</label></th>
                        <td>
                            <input type="text" name="wpgb_gdrive_client_id" id="wpgb_gdrive_client_id" value="<?php echo esc_attr( $client_id ); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wpgb_gdrive_client_secret">OAuth クライアント シークレット</label></th>
                        <td>
                            <input type="password" name="wpgb_gdrive_client_secret" id="wpgb_gdrive_client_secret" value="<?php echo esc_attr( $client_secret ); ?>" class="regular-text">
                            <p class="description">Google Cloud Consoleで作成した「ウェブ アプリケーション」のクライアントIDとシークレットを入力してください。</p>
                            <p class="description" style="color:#d63638; font-weight:bold;">※ 承認済みのリダイレクト URI には以下を必ず登録してください：<br>
                            <code><?php echo esc_html($redirect_uri); ?></code></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Google Drive 認証</th>
                        <td>
                            <?php if ( $refresh_token ): ?>
                                <p style="color:#46b450; font-weight:bold;"><span class="dashicons dashicons-yes-alt"></span> 認証済み（連携完了）</p>
                                <p class="description">このサイトは現在Google Driveに自動アップロード可能です。</p>
                            <?php elseif ( $client_id && $client_secret ): ?>
                                <p style="color:#d63638; font-weight:bold;">未認証</p>
                                <a href="<?php echo esc_url($client->createAuthUrl()); ?>" class="button button-primary">Google Driveで認証する</a>
                                <p class="description">上記のボタンをクリックしてGoogleにログインし、このアプリへのアクセスを許可してください。</p>
                            <?php else: ?>
                                <p class="description">クライアントIDとシークレットを入力して保存すると、認証ボタンが表示されます。</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ( $refresh_token ): ?>
                    <tr>
                        <th scope="row">連携の解除</th>
                        <td>
                            <button type="button" class="button" onclick="document.getElementById('revoke-form').submit();">連携を解除する</button>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row"><label for="wpgb_gdrive_folder_id">Google Drive フォルダID</label></th>
                        <td>
                            <input type="text" name="wpgb_gdrive_folder_id" id="wpgb_gdrive_folder_id" value="<?php echo esc_attr( get_option( 'wpgb_gdrive_folder_id' ) ); ?>" class="regular-text">
                            <p class="description">バックアップを保存するフォルダのID。このフォルダはサービスアカウントのメールアドレス（例: xxx@xxx.iam.gserviceaccount.com）に対して「編集者」権限を付与して共有しておく必要があります。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wpgb_backup_interval">バックアップ間隔</label></th>
                        <td>
                            <select name="wpgb_backup_interval" id="wpgb_backup_interval">
                                <?php 
                                    $interval = get_option( 'wpgb_backup_interval', 'monthly' );
                                    // 既存のdailyが設定されている場合はweekly等に自動変換する対応（表示上）
                                    if ($interval === 'daily') $interval = 'weekly';
                                ?>
                                <option value="monthly" <?php selected( $interval, 'monthly' ); ?>>月に1回</option>
                                <option value="weekly" <?php selected( $interval, 'weekly' ); ?>>週に1回</option>
                            </select>

                            <div id="wpgb_schedule_monthly_options" style="margin-top: 10px; display: <?php echo $interval === 'monthly' ? 'block' : 'none'; ?>;">
                                <?php 
                                    $monthly_day = get_option('wpgb_backup_monthly_day', '1');
                                    $monthly_hour = get_option('wpgb_backup_monthly_hour', '3');
                                ?>
                                毎月 
                                <select name="wpgb_backup_monthly_day">
                                    <?php for($i=1; $i<=28; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php selected($monthly_day, (string)$i); ?>><?php echo $i; ?>日</option>
                                    <?php endfor; ?>
                                </select>
                                の 
                                <select name="wpgb_backup_monthly_hour">
                                    <?php for($i=0; $i<=23; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php selected($monthly_hour, (string)$i); ?>><?php echo sprintf('%02d', $i); ?>時</option>
                                    <?php endfor; ?>
                                </select>
                                に実行する
                            </div>

                            <div id="wpgb_schedule_weekly_options" style="margin-top: 10px; display: <?php echo $interval === 'weekly' ? 'block' : 'none'; ?>;">
                                <?php 
                                    $weekly_dow = get_option('wpgb_backup_weekly_dow', '0'); // 0=Sunday
                                    $weekly_hour = get_option('wpgb_backup_weekly_hour', '3');
                                    $dows = ['日', '月', '火', '水', '木', '金', '土'];
                                ?>
                                毎週 
                                <select name="wpgb_backup_weekly_dow">
                                    <?php foreach($dows as $index => $label): ?>
                                        <option value="<?php echo $index; ?>" <?php selected($weekly_dow, (string)$index); ?>><?php echo $label; ?>曜日</option>
                                    <?php endforeach; ?>
                                </select>
                                の 
                                <select name="wpgb_backup_weekly_hour">
                                    <?php for($i=0; $i<=23; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php selected($weekly_hour, (string)$i); ?>><?php echo sprintf('%02d', $i); ?>時</option>
                                    <?php endfor; ?>
                                </select>
                                に実行する
                            </div>

                            <script>
                            jQuery(document).ready(function($) {
                                $('#wpgb_backup_interval').on('change', function() {
                                    if ($(this).val() === 'monthly') {
                                        $('#wpgb_schedule_monthly_options').show();
                                        $('#wpgb_schedule_weekly_options').hide();
                                    } else {
                                        $('#wpgb_schedule_monthly_options').hide();
                                        $('#wpgb_schedule_weekly_options').show();
                                    }
                                });
                            });
                            </script>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wpgb_retention_period">バックアップの保存期間</label></th>
                        <td>
                            <select name="wpgb_retention_period" id="wpgb_retention_period">
                                <?php $retention = get_option( 'wpgb_retention_period', '1_month' ); ?>
                                <option value="1_month" <?php selected( $retention, '1_month' ); ?>>1ヶ月</option>
                                <option value="3_months" <?php selected( $retention, '3_months' ); ?>>3ヶ月</option>
                                <option value="6_months" <?php selected( $retention, '6_months' ); ?>>半年</option>
                                <option value="1_year" <?php selected( $retention, '1_year' ); ?>>1年</option>
                            </select>
                            <p class="description">この期間を過ぎたGoogle Drive上のバックアップフォルダは自動的に削除されます。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wpgb_report_email">通知先メールアドレス</label></th>
                        <td>
                            <input type="email" name="wpgb_report_email" id="wpgb_report_email" value="<?php echo esc_attr( get_option( 'wpgb_report_email', get_option( 'admin_email' ) ) ); ?>" class="regular-text">
                            <p class="description">バックアップ完了やエラー時のレポートを受け取るメールアドレスです。</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <?php if ( $refresh_token ): ?>
            <form id="revoke-form" method="post" action="">
                <?php wp_nonce_field('wpgb_revoke_auth'); ?>
                <input type="hidden" name="wpgb_revoke_auth" value="1">
            </form>
            <?php endif; ?>
            
            <hr>
            <h2>バックアップの保存先 (Google Drive)</h2>
            <?php $folder_id = get_option('wpgb_gdrive_folder_id'); ?>
            <?php if ( $folder_id ): ?>
                <p>以下のリンクからGoogle Drive上のバックアップフォルダへ直接アクセスできます。</p>
                <p><a href="https://drive.google.com/drive/folders/<?php echo esc_attr($folder_id); ?>" target="_blank" class="button button-secondary">Google Drive フォルダを開く <span class="dashicons dashicons-external" style="vertical-align: middle; margin-top: 3px;"></span></a></p>
            <?php else: ?>
                <p>Google Drive フォルダIDが設定されていません。</p>
            <?php endif; ?>

            <hr>
            <h2>バックアップ実行履歴</h2>
            <?php
            $history = get_option('wpgb_backup_history', []);
            if ( empty($history) ) {
                echo '<p>まだバックアップ履歴がありません。（履歴機能はバージョン1.0.3以降に実行されたものが記録・表示されます）</p>';
            } else {
                echo '<table class="wp-list-table widefat fixed striped" style="max-width: 800px; margin-top: 10px;">';
                echo '<thead><tr><th style="width: 25%;">実行日時</th><th style="width: 50%;">バックアップ名</th><th style="width: 25%;">ファイルサイズ</th></tr></thead>';
                echo '<tbody>';
                foreach ( $history as $row ) {
                    $date = date('Y年m月d日 H:i', strtotime($row['date']));
                    // 1048576 = 1MB
                    $size = $row['size'] ? size_format($row['size'], 2) : '不明';
                    echo '<tr>';
                    echo '<td>' . esc_html($date) . '</td>';
                    echo '<td>' . esc_html($row['name']) . '</td>';
                    echo '<td>' . esc_html($size) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
                echo '<p class="description">※直近50件までの履歴を表示します。</p>';
            }
            ?>

            <hr>
            <h2>ローカルバックアップ (サーバー上)</h2>
            <p>サーバー内に保存されているバックアップファイルです。Zip化まで成功したファイルがここに残るため、初めからやり直すことなくGoogle Driveへのアップロードを再試行したり、手動でダウンロードすることができます。</p>
            <?php
            $upload_dir = wp_upload_dir();
            $backup_dir = $upload_dir['basedir'] . '/wpgb_backups';
            $backup_url = $upload_dir['baseurl'] . '/wpgb_backups';
            if ( ! file_exists($backup_dir) ) {
                echo '<p>ローカルバックアップはありません。</p>';
            } else {
                $files = glob($backup_dir . '/*.zip');
                if ( empty($files) ) {
                    echo '<p>ローカルバックアップはありません。</p>';
                } else {
                    rsort($files); // 新しい順
                    echo '<table class="wp-list-table widefat fixed striped" style="max-width: 900px; margin-top: 10px;">';
                    echo '<thead><tr><th style="width: 40%;">ファイル名</th><th style="width: 20%;">サイズ</th><th style="width: 40%;">操作</th></tr></thead>';
                    echo '<tbody>';
                    foreach ( $files as $file ) {
                        $basename = basename($file);
                        $size = size_format(filesize($file), 2);
                        $url = $backup_url . '/' . $basename;
                        echo '<tr>';
                        echo '<td>' . esc_html($basename) . '</td>';
                        echo '<td>' . esc_html($size) . '</td>';
                        echo '<td>';
                        echo '<a href="' . esc_url($url) . '" class="button button-secondary" download>ダウンロード</a> ';
                        echo '<button type="button" class="button button-primary wpgb-retry-upload-btn" data-filename="' . esc_attr($basename) . '">Driveへアップロード</button>';
                        echo '</td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                }
            }
            ?>

            <hr>
            <h2>手動バックアップの実行</h2>
            <p>現在の設定で今すぐバックアップを作成し、Google Driveへアップロードします。</p>
            <div id="wpgb-manual-backup-container">
                <button type="button" id="wpgb-start-backup-btn" class="button button-primary">今すぐバックアップを実行</button>
                <div id="wpgb-progress-wrapper" style="display:none; margin-top:20px;">
                    <div style="width:100%; max-width:600px; background:#ddd; border-radius:4px; overflow:hidden;">
                        <div id="wpgb-progress-bar" style="width:0%; height:20px; background:#2271b1; transition: width 0.5s;"></div>
                    </div>
                    <p id="wpgb-progress-text" style="font-weight:bold; margin-top:8px;">準備中...</p>
                </div>
            </div>
            <script>
            jQuery(document).ready(function($) {
                let totalFiles = 0;
                let currentOffset = 0;
                let nonce = '<?php echo wp_create_nonce("wpgb_ajax_backup"); ?>';

                function showError(msg) {
                    $('#wpgb-progress-bar').css('background', '#dc3232');
                    $('#wpgb-progress-text').html('<span style="color:#dc3232;">エラー: ' + msg + '</span>');
                    $('#wpgb-start-backup-btn').prop('disabled', false);
                    $('.wpgb-retry-upload-btn').prop('disabled', false);
                }

                function doStep(stepName, offset = 0) {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'wpgb_chunk_step',
                            _ajax_nonce: nonce,
                            step: stepName,
                            offset: offset
                        },
                        success: function(res) {
                            if (!res.success) {
                                showError(res.data || '不明なエラー');
                                return;
                            }

                            if (stepName === 'init') {
                                totalFiles = res.data.total_files;
                                $('#wpgb-progress-text').text('Zip圧縮を開始します... (0 / ' + totalFiles + ')');
                                $('#wpgb-progress-bar').css('width', '10%');
                                doStep('zip', 0);
                            } 
                            else if (stepName === 'zip') {
                                currentOffset = res.data.processed;
                                
                                if (currentOffset === -1) {
                                    $('#wpgb-progress-bar').css('width', '50%');
                                    let sizeText = res.data.current_zip_formatted ? ' (現在: ' + res.data.current_zip_formatted + ')' : '';
                                    $('#wpgb-progress-text').text('巨大ファイルをバックグラウンドで圧縮中... ' + sizeText);
                                    // Wait 4 seconds before checking status again
                                    setTimeout(function() { doStep('zip', -1); }, 4000);
                                    return;
                                }

                                let percent = 10 + Math.floor((currentOffset / totalFiles) * 70);
                                if (percent > 80) percent = 80;
                                $('#wpgb-progress-bar').css('width', percent + '%');
                                $('#wpgb-progress-text').text('ファイルを圧縮中... (' + currentOffset + ' / ' + totalFiles + ')');
                                
                                if (currentOffset < totalFiles) {
                                    doStep('zip', currentOffset);
                                } else {
                                    $('#wpgb-progress-bar').css('width', '80%');
                                    $('#wpgb-progress-text').text('Google Driveへアップロード準備中...');
                                    doStep('finalize');
                                }
                            }
                            else if (stepName === 'finalize') {
                                $('#wpgb-progress-bar').css('width', '85%');
                                $('#wpgb-progress-text').text('Google Driveへアップロード中...');
                                doStep('upload');
                            }
                            else if (stepName === 'upload') {
                                if (res.data.done) {
                                    $('#wpgb-progress-bar').css('width', '95%');
                                    $('#wpgb-progress-text').text('完了処理中...');
                                    doStep('cleanup');
                                } else {
                                    let uploaded = res.data.uploaded || 0;
                                    let total = res.data.total || 1;
                                    let percentMB = (uploaded / 1024 / 1024).toFixed(1);
                                    let totalMB = (total / 1024 / 1024).toFixed(1);
                                    let percent = 85 + Math.floor((uploaded / total) * 10);
                                    
                                    $('#wpgb-progress-bar').css('width', percent + '%');
                                    $('#wpgb-progress-text').text('Google Driveへアップロード中... (' + percentMB + 'MB / ' + totalMB + 'MB)');
                                    doStep('upload');
                                }
                            }
                            else if (stepName === 'cleanup') {
                                $('#wpgb-progress-bar').css('width', '100%').css('background', '#46b450');
                                $('#wpgb-progress-text').html('<span style="color:#46b450;">バックアップが完了しました！</span>');
                                $('#wpgb-start-backup-btn').prop('disabled', false);
                                $('.wpgb-retry-upload-btn').prop('disabled', false);
                                setTimeout(function(){ location.reload(); }, 2000);
                            }
                        },
                        error: function(xhr, status, error) {
                            if (xhr.status === 504 || xhr.status === 502) {
                                showError('サーバーのタイムアウト制限に到達しました。');
                            } else {
                                showError('通信エラーが発生しました。');
                            }
                        }
                    });
                }

                $('.wpgb-retry-upload-btn').on('click', function() {
                    if (!confirm('このファイルをGoogle Driveへアップロードしますか？（※エラーになる場合はGoogle Drive側の制限の可能性があります）')) return;
                    
                    var filename = $(this).data('filename');
                    $('#wpgb-start-backup-btn').prop('disabled', true);
                    $('.wpgb-retry-upload-btn').prop('disabled', true);
                    
                    $('#wpgb-progress-wrapper').show();
                    $('#wpgb-progress-bar').css({'width': '0%', 'background': '#2271b1'});
                    $('#wpgb-progress-text').text('アップロードの準備中...').css('color', '#000');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'wpgb_prepare_retry_upload',
                            filename: filename,
                            _ajax_nonce: nonce
                        },
                        success: function(res) {
                            if (res.success) {
                                totalFiles = res.data.total_size; 
                                $('#wpgb-progress-bar').css('width', '85%');
                                $('#wpgb-progress-text').text('Google Driveへアップロード中...');
                                doStep('upload', 0);
                            } else {
                                showError(res.data || '準備エラー');
                            }
                        },
                        error: function() {
                            showError('通信エラー');
                        }
                    });
                });

                $('#wpgb-start-backup-btn').on('click', function() {
                    if ( ! confirm('バックアップを開始します。処理には数分かかる場合があります。よろしいですか？') ) return;
                    
                    $('#wpgb-start-backup-btn').prop('disabled', true);
                    $('.wpgb-retry-upload-btn').prop('disabled', true);
                    $('#wpgb-progress-wrapper').show();
                    $('#wpgb-progress-bar').css('width', '5%').css('background', '#2271b1');
                    $('#wpgb-progress-text').text('準備中...').css('color', '#000');
                    doStep('init', 0);
                });
            });
            </script>
            <?php
            echo '</div>'; // close wrap
    }

    private function render_logs_tab() {
        ?>
        <div style="margin-top: 20px;">
            <h3>システムログ</h3>
            <p class="description">バックアップ処理の進行状況やエラーをここに記録します。（最新1000行）</p>
            
            <textarea readonly style="width: 100%; height: 500px; font-family: monospace; background: #f0f0f1; padding: 10px;"><?php echo esc_textarea( WP_GDrive_Logger::get_logs() ); ?></textarea>

            <form method="post" style="margin-top: 10px;">
                <?php wp_nonce_field('wpgb_clear_logs'); ?>
                <button type="submit" name="wpgb_clear_logs" class="button">ログをクリアする</button>
            </form>
        </div>
        <?php
    }

    public function ajax_prepare_retry_upload() {
        check_ajax_referer( 'wpgb_ajax_backup', '_ajax_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '権限がありません' );

        $filename = sanitize_text_field( $_POST['filename'] );
        if ( empty($filename) || strpos($filename, '.zip') === false ) wp_send_json_error( '無効なファイル名' );

        $upload_dir = wp_upload_dir();
        $backup_dir = $upload_dir['basedir'] . '/wpgb_backups';
        $zip_file = $backup_dir . '/' . $filename;

        if ( ! file_exists($zip_file) ) wp_send_json_error( 'ファイルが存在しません' );

        $basename = str_replace('.zip', '', $filename);

        $state = [
            'basename' => $basename,
            'zip_file' => $zip_file,
            'sql_file' => $backup_dir . '/' . $basename . '.sql',
            'installer_file' => $backup_dir . '/' . $basename . '_installer.php',
            'uploadOffset' => 0,
            'resumeUri' => '',
            'folder_id' => '',
        ];

        $state_path = $backup_dir . '/wpgb_state.json';
        file_put_contents($state_path, wp_json_encode($state));

        wp_send_json_success( [ 'total_size' => filesize($zip_file) ] );
    }

    public function ajax_chunk_step() {
        check_ajax_referer( 'wpgb_ajax_backup' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( '権限がありません。' );
        }

        ignore_user_abort(true);
        set_time_limit(0);

        $step = isset($_POST['step']) ? sanitize_text_field($_POST['step']) : '';
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $limit = 2000;

        try {
            $engine = new WP_GDrive_Backup_Engine();
            $result = [];
            
            switch ($step) {
                case 'init':
                    $result = $engine->step_init();
                    break;
                case 'zip':
                    $result = $engine->step_zip_chunk($offset, $limit);
                    break;
                case 'finalize':
                    $result = $engine->step_finalize_zip();
                    break;
                case 'upload':
                    $result = $engine->step_upload();
                    break;
                case 'cleanup':
                    $result = $engine->step_cleanup();
                    break;
                case 'abort':
                    $result = $engine->step_abort();
                    break;
                default:
                    wp_send_json_error('不明なステップ');
            }

            wp_send_json_success($result);
        } catch ( Exception $e ) {
            if ($step === 'upload' || $step === 'finalize') {
                WP_GDrive_Mailer::send_error_report( $e->getMessage() );
            }
            wp_send_json_error( $e->getMessage() );
        }
    }
}

// Initialize the plugin
WP_GDrive_Backup::get_instance();
