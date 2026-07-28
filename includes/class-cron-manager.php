<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_GDrive_Cron_Manager {
    public static function init() {
        add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_schedules' ] );
        add_action( 'wp_gdrive_scheduled_backup_event', [ __CLASS__, 'execute_scheduled_backup' ] );
        add_action( 'update_option_wpgb_backup_interval', [ __CLASS__, 'update_schedule' ], 10, 2 );
        
        self::schedule_event_if_needed();
    }

    public static function add_cron_schedules( $schedules ) {
        $schedules['monthly'] = [
            'interval' => 30 * DAY_IN_SECONDS,
            'display'  => '月に1回'
        ];
        return $schedules;
    }

    public static function schedule_event_if_needed() {
        if ( ! wp_next_scheduled( 'wp_gdrive_scheduled_backup_event' ) ) {
            $interval = get_option( 'wpgb_backup_interval', 'weekly' );
            wp_schedule_event( time(), $interval, 'wp_gdrive_scheduled_backup_event' );
        }
    }

    public static function update_schedule( $old_value, $new_value ) {
        if ( $old_value !== $new_value ) {
            wp_clear_scheduled_hook( 'wp_gdrive_scheduled_backup_event' );
            wp_schedule_event( time(), $new_value, 'wp_gdrive_scheduled_backup_event' );
        }
    }

    public static function execute_scheduled_backup() {
        set_time_limit(0);
        try {
            $engine = new WP_GDrive_Backup_Engine();
            $result = $engine->run_backup();

            if ( $result ) {
                WP_GDrive_Mailer::send_success_report( $engine->get_last_backup_info() );
            }
        } catch ( Exception $e ) {
            WP_GDrive_Mailer::send_error_report( $e->getMessage() );
        }
    }
}
