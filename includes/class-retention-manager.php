<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_GDrive_Retention_Manager {

    public function cleanup_old_backups( WP_GDrive_Uploader $uploader ) {
        $retention_period = get_option( 'wpgb_retention_period', '1_month' );
        
        $cutoff_date = new DateTime();
        switch ( $retention_period ) {
            case '1_month':
                $cutoff_date->modify( '-1 month' );
                break;
            case '3_months':
                $cutoff_date->modify( '-3 months' );
                break;
            case '6_months':
                $cutoff_date->modify( '-6 months' );
                break;
            case '1_year':
                $cutoff_date->modify( '-1 year' );
                break;
            default:
                $cutoff_date->modify( '-1 month' );
        }
        
        $cutoff_str = $cutoff_date->format( 'Y-m-d\TH:i:s\Z' );
        $service = $uploader->get_service();
        $parent_id = $uploader->get_parent_folder_id();

        // Query folders inside the parent folder modified before the cutoff
        $query = sprintf(
            "mimeType='application/vnd.google-apps.folder' and '%s' in parents and modifiedTime < '%s' and trashed=false",
            $parent_id,
            $cutoff_str
        );

        $optParams = [
            'q' => $query,
            'fields' => 'files(id, name, modifiedTime)',
            'spaces' => 'drive'
        ];

        try {
            $results = $service->files->listFiles($optParams);
            
            if ( count( $results->getFiles() ) > 0 ) {
                foreach ( $results->getFiles() as $file ) {
                    // Delete the old folder permanently
                    $service->files->delete( $file->getId() );
                }
            }
        } catch (Exception $e) {
            error_log("Google Drive old backup cleanup failed: " . $e->getMessage());
        }
    }
}
