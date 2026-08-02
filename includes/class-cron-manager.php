<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_GDrive_Cron_Manager {
    public static function init() {
        add_action( 'wp_gdrive_scheduled_backup_event', [ __CLASS__, 'execute_scheduled_backup' ] );
        
        // Settings changed hooks
        add_action( 'added_option', [ __CLASS__, 'on_option_changed' ], 10, 3 );
        add_action( 'updated_option', [ __CLASS__, 'on_option_changed' ], 10, 3 );
        
        self::schedule_event_if_needed();
    }

    public static function on_option_changed( $option, $old_value, $value ) {
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
        // Clear any old recurring events (from v1.1.1 and earlier)
        if ( wp_next_scheduled( 'wp_gdrive_scheduled_backup_event' ) ) {
            $schedule = wp_get_schedule( 'wp_gdrive_scheduled_backup_event' );
            if ( $schedule ) { 
                // If $schedule is not false, it's a recurring event!
                wp_clear_scheduled_hook( 'wp_gdrive_scheduled_backup_event' );
            }
        }

        if ( ! wp_next_scheduled( 'wp_gdrive_scheduled_backup_event' ) ) {
            wp_schedule_single_event( self::calculate_next_timestamp(), 'wp_gdrive_scheduled_backup_event' );
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
        
        // バックアップ完了後、次回のスケジュールをセットする
        wp_schedule_single_event( self::calculate_next_timestamp(), 'wp_gdrive_scheduled_backup_event' );
    }
}
