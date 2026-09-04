<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_GDrive_Logger {
    public static function log( $message, $level = 'INFO' ) {
        $upload_dir = wp_upload_dir();
        $log_dir = trailingslashit( $upload_dir['basedir'] ) . 'wp-gdrive-backups';
        $log_file = $log_dir . '/wpgb-debug.log';
        
        if ( ! file_exists( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
        }
        
        $date = date('Y-m-d H:i:s');
        $formatted_message = "[{$date}] [{$level}] {$message}\n";
        
        if ( file_exists($log_file) && filesize($log_file) > 5 * 1024 * 1024 ) {
            rename($log_file, $log_file . '.old');
        }
        
        error_log( $formatted_message, 3, $log_file );
    }

    public static function get_logs() {
        $upload_dir = wp_upload_dir();
        $log_file = trailingslashit( $upload_dir['basedir'] ) . 'wp-gdrive-backups/wpgb-debug.log';
        if ( file_exists( $log_file ) ) {
            $file = file($log_file);
            $lines = array_slice($file, -1000);
            return implode("", $lines);
        }
        return "ログはまだありません。";
    }

    public static function clear_logs() {
        $upload_dir = wp_upload_dir();
        $log_file = trailingslashit( $upload_dir['basedir'] ) . 'wp-gdrive-backups/wpgb-debug.log';
        if ( file_exists( $log_file ) ) {
            unlink( $log_file );
        }
    }
}
