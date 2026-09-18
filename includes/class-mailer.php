<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_GDrive_Mailer {

    private static function get_headers() {
        $site_name = get_bloginfo( 'name' );
        $admin_email = get_option( 'admin_email' );
        $host = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'wordpress';
        $from_email = 'no-reply@' . preg_replace('/^www\./', '', $host);

        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            sprintf( 'From: %s <%s>', $site_name, $from_email ),
            sprintf( 'Reply-To: %s', $admin_email ),
        ];
        return $headers;
    }

    public static function send_success_report( $backup_info ) {
        $to = get_option( 'wpgb_report_email', get_option( 'admin_email' ) );
        if ( empty( $to ) ) {
            WP_GDrive_Logger::log("Report email is not configured. Skipping success email notification.");
            return false;
        }

        $site_name = get_bloginfo( 'name' );
        $site_url = site_url();
        $subject = "[{$site_name}] バックアップ完了通知";
        
        $message = "サイト「{$site_name}」({$site_url}) のバックアップが正常に完了し、Google Driveへアップロードされました。\n\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "■ バックアップ詳細\n";
        $message .= "・バックアップ名: " . ( isset($backup_info['name']) ? $backup_info['name'] : 'N/A' ) . "\n";
        $message .= "・完了日時: " . ( isset($backup_info['time']) ? $backup_info['time'] : current_time('mysql') ) . "\n";
        if ( isset($backup_info['size']) ) {
            $message .= "・ファイルサイズ: " . size_format($backup_info['size'], 2) . "\n";
        }
        $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $message .= "次回のバックアップもスケジュール通り自動的に実行されます。\n";
        
        $headers = self::get_headers();
        $sent = wp_mail( $to, $subject, $message, $headers );
        WP_GDrive_Logger::log("Sending success email to {$to}... Result: " . ($sent ? 'Success' : 'Failed'));
        return $sent;
    }

    public static function send_error_report( $error_message, $is_retry = false, $attempt = 1 ) {
        $to = get_option( 'wpgb_report_email', get_option( 'admin_email' ) );
        if ( empty( $to ) ) return false;

        $site_name = get_bloginfo( 'name' );
        $site_url = site_url();
        $subject = "[{$site_name}] バックアップ失敗通知 (重要)";
        
        $message = "サイト「{$site_name}」({$site_url}) の定期バックアップ処理中にエラーが発生しました。\n\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "■ エラー詳細\n";
        $message .= "・エラー内容: " . $error_message . "\n";
        $message .= "・発生日時: " . current_time('Y-m-d H:i:s') . "\n";
        if ( $is_retry ) {
            $message .= "・再試行状況: 1時間後に自動再試行を行います (試行 {$attempt}/3)\n";
        }
        $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $message .= "※管理画面の「Google Drive Backup」→「エラーログ」より詳細をご確認いただけます。\n";
        
        $headers = self::get_headers();
        $sent = wp_mail( $to, $subject, $message, $headers );
        WP_GDrive_Logger::log("Sending error email to {$to}... Result: " . ($sent ? 'Success' : 'Failed'));
        return $sent;
    }

    public static function send_critical_failure_alert( $error_message ) {
        $to = get_option( 'wpgb_report_email', get_option( 'admin_email' ) );
        if ( empty( $to ) ) return false;

        $site_name = get_bloginfo( 'name' );
        $site_url = site_url();
        $subject = "[{$site_name}] 【要対応】バックアップが3回連続で失敗しました（手動切り替え推奨）";
        
        $message = "サイト「{$site_name}」({$site_url}) の定期バックアップにおいて、自動再試行（3回）をすべて行いましたが、完了できませんでした。\n\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "■ 状況と原因\n";
        $message .= "・最後のエラー: " . $error_message . "\n";
        $message .= "・判定: サイトのデータ容量またはファイル数がサーバーの制限を超えている可能性が高いです。\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $message .= "■ 推奨される対処方法\n";
        $message .= "1. 「WP Storage Cleaner」プラグイン等を使用して、過去のバックアップ残骸やキャッシュを削除し、サイトをスリム化してください。\n";
        $message .= "2. または、管理画面の「Google Drive Backup」より【手動バックアップ】を実行してください。\n\n";
        $message .= "※次回の月次/週次スケジュールまで自動処理は一時停止されます。\n";
        
        $headers = self::get_headers();
        $sent = wp_mail( $to, $subject, $message, $headers );
        WP_GDrive_Logger::log("Sending critical failure alert to {$to}... Result: " . ($sent ? 'Success' : 'Failed'));
        return $sent;
    }
}

