<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_GDrive_Backup_Engine {
    private $backup_dir;
    private $last_backup_info = [];

    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->backup_dir = trailingslashit( $upload_dir['basedir'] ) . 'wp-gdrive-backups/';
        if ( ! file_exists( $this->backup_dir ) ) {
            wp_mkdir_p( $this->backup_dir );
            // Protect backup directory
            file_put_contents( $this->backup_dir . '.htaccess', "Deny from all\n" );
            file_put_contents( $this->backup_dir . 'index.php', "<?php\n// Silence is golden.\n" );
        }
    }

    public function run_backup() {
        $site_name = preg_replace('/[^a-zA-Z0-9_-]/', '', get_bloginfo('name'));
        if ( empty($site_name) ) $site_name = 'wordpress';
        $timestamp = date( 'Ymd_His' );
        
        $backup_basename = "{$site_name}_backup_{$timestamp}";
        
        $sql_file = $this->backup_dir . 'database.sql';
        $zip_file = $this->backup_dir . "{$backup_basename}.zip";
        $installer_file = $this->backup_dir . 'installer.php';

        try {
            // 1. Dump Database
            $db_dumper = new WP_GDrive_DB_Dumper();
            $db_dumper->dump( $sql_file );

            // 2. Generate installer.php
            $this->generate_installer( $installer_file, $backup_basename . '.zip' );

            // 3. Zip files
            $this->create_zip( $zip_file, $sql_file );

            // 4. Upload to Google Drive (folder format)
            $uploader = new WP_GDrive_Uploader();
            $folder_id = $uploader->create_folder( $backup_basename );
            
            if ( $folder_id ) {
                $uploader->upload_file( $zip_file, "{$backup_basename}.zip", $folder_id );
                $uploader->upload_file( $installer_file, 'installer.php', $folder_id );
            } else {
                throw new Exception("Google Driveへのフォルダ作成に失敗しました。");
            }

            // 5. Cleanup local files
            @unlink( $sql_file );
            @unlink( $zip_file );
            @unlink( $installer_file );

            // 6. Manage retention on Google Drive
            $retention = new WP_GDrive_Retention_Manager();
            $retention->cleanup_old_backups( $uploader );

            $this->last_backup_info = [
                'name' => $backup_basename,
                'time' => current_time('mysql')
            ];

            return true;

        } catch ( Exception $e ) {
            @unlink( $sql_file );
            @unlink( $zip_file );
            @unlink( $installer_file );
            throw $e;
        }
    }

    private function create_zip( $zip_file, $sql_file ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            throw new Exception("サーバーに ZipArchive 拡張機能がインストールされていません。");
        }

        $zip = new ZipArchive();
        if ( $zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
            throw new Exception("Zipファイルの作成に失敗しました: " . $zip_file);
        }

        $root_path = ABSPATH;

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $root_path, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ( $files as $name => $file ) {
            if ( ! $file->isDir() ) {
                $file_path = $file->getRealPath();
                // Exclude the backup directory itself to prevent recursive loops
                if ( strpos( $file_path, $this->backup_dir ) !== false ) {
                    continue;
                }
                
                $relative_path = substr( $file_path, strlen( $root_path ) );
                $zip->addFile( $file_path, $relative_path );
            }
        }

        // Add SQL dump to the root of the zip
        if ( file_exists( $sql_file ) ) {
            $zip->addFile( $sql_file, 'database.sql' );
        }

        $zip->close();
    }

    private function generate_installer( $installer_file, $zip_filename ) {
        $template = WP_GDRIVE_BACKUP_PLUGIN_DIR . 'templates/installer.template.php';
        if ( ! file_exists( $template ) ) {
            throw new Exception("Installer template not found.");
        }

        $content = file_get_contents( $template );
        $content = str_replace( '{{ZIP_FILENAME}}', $zip_filename, $content );
        file_put_contents( $installer_file, $content );
    }

    public function get_last_backup_info() {
        return $this->last_backup_info;
    }
}
