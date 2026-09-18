<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_GDrive_Cron_Manager {
    public static function init() {
        add_action( 'wp_gdrive_scheduled_backup_event', [ __CLASS__, 'start_scheduled_backup' ] );
        add_action( 'wpgb_async_cron_step', [ __CLASS__, 'execute_cron_step' ], 10, 2 );
        
        // Settings changed hooks
        add_action( 'added_option', [ __CLASS__, 'on_option_changed' ], 10, 3 );
        add_action( 'updated_option', [ __CLASS__, 'on_option_changed' ], 10, 3 );
        
        self::schedule_event_if_needed();
    }

    public static function on_option_changed( $option, $old_value = null, $value = null ) {
        $trigger_options = [
            'wpgb_backup_interval',
            'wpgb_backup_monthly_day',
            'wpgb_backup_monthly_hour',
            'wpgb_backup_weekly_dow',
            'wpgb_backup_weekly_hour',
        ];
        
        if ( in_array( $option, $trigger_options ) ) {
            wp_clear_scheduled_hook( 'wp_gdrive_scheduled_backup_event' );
            wp_schedule_single_event( self::calculate_next_timestamp(), 'wp_gdrive_scheduled_backup_event' );
        }
    }

    public static function calculate_next_timestamp() {
        $interval = get_option('wpgb_backup_interval', 'weekly');
        $tz_string = get_option('timezone_string');
        if ( ! $tz_string ) {
            $offset = get_option('gmt_offset', 0);
            $hours = (int)$offset;
            $minutes = abs($offset - $hours) * 60;
            $tz_string = sprintf('%+03d:%02d', $hours, $minutes);
        }
        
        try {
            $tz = new DateTimeZone($tz_string);
        } catch(Exception $e) {
            $tz = new DateTimeZone('UTC');
        }
        
        $interval = get_option('wpgb_backup_interval', 'monthly');
        $now = new DateTime('now', $tz);

        if ($interval === 'monthly') {
            $day = (int) get_option('wpgb_backup_monthly_day', '1');
            $hour = (int) get_option('wpgb_backup_monthly_hour', '3');
            
            $target = clone $now;
            // Prevent day overflow (e.g. Feb 30 becomes Mar 2)
            $max_day = (int) date('t', mktime(0,0,0, (int)$now->format('n'), 1, (int)$now->format('Y')));
            $actual_day = min($day, $max_day);

            $target->setDate((int)$now->format('Y'), (int)$now->format('n'), $actual_day);
            $target->setTime($hour, 0, 0);
            
            if ($target <= $now) {
                // Move to next month
                $target->modify('first day of next month');
                $max_day_next = (int) $target->format('t');
                $actual_day_next = min($day, $max_day_next);
                $target->setDate((int)$target->format('Y'), (int)$target->format('n'), $actual_day_next);
                $target->setTime($hour, 0, 0);
            }
            return $target->getTimestamp();

        } else {
            // Weekly
            $dow = (int) get_option('wpgb_backup_weekly_dow', '0'); // 0 (Sun) to 6 (Sat)
            $hour = (int) get_option('wpgb_backup_weekly_hour', '3');
            
            $dows = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $target_dow_str = $dows[$dow];
            
            $target = clone $now;
            if ((int)$now->format('w') === $dow) {
                $target->setTime($hour, 0, 0);
                if ($target <= $now) {
                    $target->modify("next {$target_dow_str}");
                    $target->setTime($hour, 0, 0);
                }
            } else {
                $target->modify("next {$target_dow_str}");
                $target->setTime($hour, 0, 0);
            }
            return $target->getTimestamp();
        }
    }

    public static function schedule_event_if_needed() {
        // Watchdog: Check if a stuck backup state exists (> 3 hours old)
        $upload_dir = wp_upload_dir();
        $state_path = $upload_dir['basedir'] . '/wp-gdrive-backups/backup_state.json';
        if ( file_exists($state_path) && ( time() - filemtime($state_path) > 3 * HOUR_IN_SECONDS ) ) {
            WP_GDrive_Logger::log("Watchdog: Found stuck backup state from " . date('Y-m-d H:i:s', filemtime($state_path)) . ". Cleaning up and resetting...", 'WARNING');
            try {
                $engine = new WP_GDrive_Backup_Engine();
                $engine->step_abort();
            } catch (Exception $ex) {}
        }

        if ( wp_next_scheduled( 'wp_gdrive_scheduled_backup_event' ) ) {
            $schedule = wp_get_schedule( 'wp_gdrive_scheduled_backup_event' );
            if ( $schedule ) { 
                wp_clear_scheduled_hook( 'wp_gdrive_scheduled_backup_event' );
            }
        }
        if ( ! wp_next_scheduled( 'wp_gdrive_scheduled_backup_event' ) ) {
            wp_schedule_single_event( self::calculate_next_timestamp(), 'wp_gdrive_scheduled_backup_event' );
        }
    }

    public static function start_scheduled_backup() {
        WP_GDrive_Logger::log("=== Scheduled Backup Triggered ===");
        
        // 次回のスケジュール（翌月/翌週）を確実にセットしておく
        wp_schedule_single_event( self::calculate_next_timestamp(), 'wp_gdrive_scheduled_backup_event' );
        
        // 非同期バケツリレーの最初のステップをキックする
        wp_schedule_single_event( time(), 'wpgb_async_cron_step', ['init', 0] );
    }

    public static function execute_cron_step( $step, $offset ) {
        set_time_limit(0);
        ignore_user_abort(true);

        // Register shutdown function to catch any fatal error / timeout in cron
        register_shutdown_function(function() use ($step) {
            $error = error_get_last();
            if ( $error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR]) ) {
                WP_GDrive_Logger::log("Fatal Shutdown in step {$step}: " . $error['message'], 'ERROR');
                self::handle_step_failure($step, "サーバーの致命的エラー/強制終了: " . $error['message']);
            }
        });

        try {
            $engine = new WP_GDrive_Backup_Engine();
            $result = [];
            
            WP_GDrive_Logger::log("Cron Step [{$step}] (Offset: {$offset}) starting...");
            
            switch ($step) {
                case 'init':
                    $result = $engine->step_init();
                    wp_schedule_single_event( time(), 'wpgb_async_cron_step', ['zip', 0] );
                    break;
                case 'zip':
                    $result = $engine->step_zip_chunk($offset, 2000);
                    $processed = $result['processed'];
                    
                    $state = $engine->get_state();
                    $total = isset($state['total_files']) ? $state['total_files'] : 999999999;
                    
                    if ( $processed >= $total || $processed >= 999999999 ) {
                        wp_schedule_single_event( time(), 'wpgb_async_cron_step', ['finalize', 0] );
                    } else if ( $processed === -1 ) {
                        wp_schedule_single_event( time() + 15, 'wpgb_async_cron_step', ['zip', -1] );
                    } else {
                        wp_schedule_single_event( time(), 'wpgb_async_cron_step', ['zip', $processed] );
                    }
                    break;
                case 'finalize':
                    $result = $engine->step_finalize_zip();
                    wp_schedule_single_event( time(), 'wpgb_async_cron_step', ['upload', 0] );
                    break;
                case 'upload':
                    $result = $engine->step_upload();
                    if ( isset($result['done']) && $result['done'] ) {
                        wp_schedule_single_event( time(), 'wpgb_async_cron_step', ['cleanup', 0] );
                    } else {
                        wp_schedule_single_event( time(), 'wpgb_async_cron_step', ['upload', 0] );
                    }
                    break;
                case 'cleanup':
                    $result = $engine->step_cleanup();
                    // Reset retry counter on success
                    delete_option( 'wpgb_backup_retry_count' );
                    WP_GDrive_Mailer::send_success_report( $engine->get_last_backup_info() );
                    WP_GDrive_Logger::log("=== Scheduled Backup Fully Completed ===");
                    break;
            }
        } catch ( Exception $e ) {
            self::handle_step_failure($step, $e->getMessage());
        }
    }

    private static function handle_step_failure( $step, $error_message ) {
        WP_GDrive_Logger::log("Cron Failure in step {$step}: {$error_message}", 'ERROR');

        // Cleanup temporary state
        try {
            $engine = new WP_GDrive_Backup_Engine();
            $engine->step_abort();
        } catch (Exception $ex) {}

        $retry_count = (int) get_option( 'wpgb_backup_retry_count', 0 ) + 1;
        update_option( 'wpgb_backup_retry_count', $retry_count );

        if ( $retry_count < 3 ) {
            // Retry in 1 hour
            wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'wpgb_async_cron_step', ['init', 0] );
            WP_GDrive_Logger::log("Scheduling automatic retry attempt {$retry_count}/3 in 1 hour...", 'WARNING');
            WP_GDrive_Mailer::send_error_report( $error_message, true, $retry_count );
        } else {
            // Max retries reached: Reset and send critical alert recommending manual backup
            delete_option( 'wpgb_backup_retry_count' );
            WP_GDrive_Logger::log("Max retries (3/3) reached. Sending critical failure alert recommending manual backup.", 'ERROR');
            WP_GDrive_Mailer::send_critical_failure_alert( $error_message );
        }
    }
}
