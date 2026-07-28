<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_GDrive_Mailer {
    public static function send_success_report( $backup_info ) {
        $to = get_option( 'wpgb_report_email', get_option( 'admin_email' ) );
        if ( empty( $to ) ) return;

        $site_name = get_bloginfo( 'name' );
        $subject = "[{$site_name}] バックアップ完了通知";
        
        $message = "サイト「{$site_name}」のバックアップが正常に完了し、Google Driveへアップロードされました。\n\n";
        $message .= "バックアップ名: " . $backup_info['name'] . "\n";
        $message .= "完了日時: " . $backup_info['time'] . "\n";
        
        wp_mail( $to, $subject, $message );
    }

    public static function send_error_report( $error_message ) {
        $to = get_option( 'wpgb_report_email', get_option( 'admin_email' ) );
        if ( empty( $to ) ) return;

        $site_name = get_bloginfo( 'name' );
        $subject = "[{$site_name}] バックアップ失敗通知 (重要)";
        
        $message = "サイト「{$site_name}」のバックアップ処理中にエラーが発生しました。\n\n";
        $message .= "エラー詳細:\n";
        $message .= $error_message . "\n\n";
        $message .= "至急、サーバーおよびプラグインの設定を確認してください。\n";
        
        wp_mail( $to, $subject, $message );
    }
}
