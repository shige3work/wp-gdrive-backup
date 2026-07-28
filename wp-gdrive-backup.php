<?php
/**
 * Plugin Name: WP Google Drive Backup
 * Description: サイトのバックアップをZipとSQL形式で生成し、定期的にGoogle Driveへアップロードするプラグインです。
 * Version: 1.0.0
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
        add_action( 'admin_post_wp_gdrive_manual_backup', [ $this, 'handle_manual_backup' ] );
        
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
            <h2>手動バックアップの実行</h2>
            <p>現在の設定で今すぐバックアップを作成し、Google Driveへアップロードします。</p>
            <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
                <input type="hidden" name="action" value="wp_gdrive_manual_backup">
                <?php wp_nonce_field( 'wp_gdrive_manual_backup_nonce', '_wpnonce' ); ?>
                <button type="submit" class="button button-secondary">今すぐバックアップを実行</button>
            </form>
        </div>
        <?php
    }

    public function handle_manual_backup() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '権限がありません。' );
        }
        check_admin_referer( 'wp_gdrive_manual_backup_nonce' );

        // タイムアウトを伸ばす
        set_time_limit(0);

        try {
            $engine = new WP_GDrive_Backup_Engine();
            $result = $engine->run_backup();

            if ( $result ) {
                WP_GDrive_Mailer::send_success_report( $engine->get_last_backup_info() );
                wp_redirect( add_query_arg( [ 'page' => 'wp-gdrive-backup', 'msg' => 'success' ], admin_url( 'admin.php' ) ) );
                exit;
            } else {
                throw new Exception("バックアップ処理が失敗しました。");
            }
        } catch ( Exception $e ) {
            WP_GDrive_Mailer::send_error_report( $e->getMessage() );
            wp_die( 'バックアップ中にエラーが発生しました: ' . esc_html( $e->getMessage() ) );
        }
    }
}

// Initialize the plugin
WP_GDrive_Backup::get_instance();
