<?php
/**
 * Plugin Name: WP Google Drive Backup
 * Description: サイトのバックアップをZipとSQL形式で生成し、定期的にGoogle Driveへアップロードするプラグインです。
 * Version: 1.0.4
 * Author: Your Name
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
        add_action( 'wp_ajax_wpgb_chunk_step', [ $this, 'ajax_chunk_step' ] );
        
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
        register_setting( 'wp_gdrive_backup_settings', 'wpgb_gdrive_json_key' );
        register_setting( 'wp_gdrive_backup_settings', 'wpgb_gdrive_folder_id' );
        register_setting( 'wp_gdrive_backup_settings', 'wpgb_backup_interval' );
        register_setting( 'wp_gdrive_backup_settings', 'wpgb_retention_period' );
        register_setting( 'wp_gdrive_backup_settings', 'wpgb_report_email' );
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>WP Google Drive Backup 設定</h1>
            <form action="options.php" method="post">
                <?php
                settings_fields( 'wp_gdrive_backup_settings' );
                do_settings_sections( 'wp_gdrive_backup_settings' );
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="wpgb_gdrive_json_key">サービスアカウント JSONキー</label></th>
                        <td>
                            <textarea name="wpgb_gdrive_json_key" id="wpgb_gdrive_json_key" rows="10" cols="50" class="large-text code"><?php echo esc_textarea( get_option( 'wpgb_gdrive_json_key' ) ); ?></textarea>
                            <p class="description">Google Cloud Platformで発行したサービスアカウントのJSONキーの中身をそのまま貼り付けてください。</p>
                        </td>
                    </tr>
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
                                <?php $interval = get_option( 'wpgb_backup_interval', 'weekly' ); ?>
                                <option value="daily" <?php selected( $interval, 'daily' ); ?>>毎日</option>
                                <option value="weekly" <?php selected( $interval, 'weekly' ); ?>>週に1回</option>
                                <option value="monthly" <?php selected( $interval, 'monthly' ); ?>>月に1回</option>
                            </select>
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
                $('#wpgb-start-backup-btn').on('click', function() {
                    if ( ! confirm('バックアップを開始します。処理には数分かかる場合があります。よろしいですか？') ) return;
                    
                    let btn = $(this);
                    btn.prop('disabled', true);
                    $('#wpgb-progress-wrapper').show();
                    $('#wpgb-progress-bar').css('width', '5%').css('background', '#2271b1');
                    $('#wpgb-progress-text').text('準備中 (データベース保存・ファイル一覧作成)...');
                    
                    let totalFiles = 0;
                    let currentOffset = 0;
                    let nonce = '<?php echo wp_create_nonce("wpgb_ajax_backup"); ?>';

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
                                    let percent = 10 + Math.floor((currentOffset / totalFiles) * 70);
                                    if (percent > 80) percent = 80;
                                    $('#wpgb-progress-bar').css('width', percent + '%');
                                    $('#wpgb-progress-text').text('ファイルを圧縮中... (' + currentOffset + ' / ' + totalFiles + ')');
                                    
                                    if (currentOffset < totalFiles) {
                                        doStep('zip', currentOffset);
                                    } else {
                                        $('#wpgb-progress-bar').css('width', '85%');
                                        $('#wpgb-progress-text').text('Zipファイルの最終処理中...');
                                        doStep('finalize');
                                    }
                                }
                                else if (stepName === 'finalize') {
                                    $('#wpgb-progress-bar').css('width', '88%');
                                    $('#wpgb-progress-text').text('Google Driveへアップロード準備中...');
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
                                        
                                        let uploadPercent = Math.floor((uploaded / total) * 7); // Max 7% added
                                        $('#wpgb-progress-bar').css('width', (88 + uploadPercent) + '%');
                                        $('#wpgb-progress-text').text('Google Driveへアップロード中... (' + percentMB + 'MB / ' + totalMB + 'MB)');
                                        
                                        // Continue upload
                                        doStep('upload');
                                    }
                                }
                                else if (stepName === 'cleanup') {
                                    $('#wpgb-progress-bar').css('width', '100%').css('background', '#46b450');
                                    $('#wpgb-progress-text').text('バックアップが完全に終了しました！');
                                    btn.prop('disabled', false);
                                }
                            },
                            error: function(xhr) {
                                if (xhr.status === 504 || xhr.status === 502) {
                                    showError('サーバーのタイムアウト制限に到達しました。処理が完了しなかった可能性があります。');
                                } else {
                                    showError('通信エラーが発生しました。');
                                }
                            }
                        });
                    }

                    function showError(msg) {
                        $('#wpgb-progress-bar').css('background', '#d63638');
                        $('#wpgb-progress-text').text('エラー: ' + msg);
                        btn.prop('disabled', false);
                        
                        $.post(ajaxurl, { action: 'wpgb_chunk_step', step: 'abort', _ajax_nonce: nonce });
                    }

                    doStep('init');
                });
            });
            </script>
        </div>
        <?php
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
