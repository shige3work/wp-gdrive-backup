<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_GDrive_Backup_Engine {
    private $backup_dir;
    private $file_list_path;
    private $state_path;

    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->backup_dir = trailingslashit( $upload_dir['basedir'] ) . 'wp-gdrive-backups/';
        $this->file_list_path = $this->backup_dir . 'file_list.txt';
        $this->state_path = $this->backup_dir . 'backup_state.json';

        if ( ! file_exists( $this->backup_dir ) ) {
            wp_mkdir_p( $this->backup_dir );
            file_put_contents( $this->backup_dir . '.htaccess', "Deny from all\n" );
            file_put_contents( $this->backup_dir . 'index.php', "<?php\n// Silence is golden.\n" );
        }
    }

    public function step_init() {
        $site_name = preg_replace('/[^a-zA-Z0-9_-]/', '', get_bloginfo('name'));
        if ( empty($site_name) ) $site_name = 'wordpress';
        $timestamp = date( 'Ymd_His' );
        $backup_basename = "{$site_name}_backup_{$timestamp}";

        $state = [
            'basename' => $backup_basename,
            'sql_file' => $this->backup_dir . 'database.sql',
            'zip_file' => $this->backup_dir . "{$backup_basename}.zip",
            'installer_file' => $this->backup_dir . 'installer.php'
        ];
        file_put_contents($this->state_path, wp_json_encode($state));

        // 1. Dump Database
        $db_dumper = new WP_GDrive_DB_Dumper();
        $db_dumper->dump( $state['sql_file'] );

        // 2. Generate installer
        $this->generate_installer( $state['installer_file'], $backup_basename . '.zip' );

        // 3. Scan files
        $root_path = ABSPATH;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $root_path, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $fp = fopen($this->file_list_path, 'w');
        $count = 0;
        foreach ( $files as $file ) {
            if ( ! $file->isDir() ) {
                $file_path = $file->getRealPath();
                if ( strpos( $file_path, $this->backup_dir ) !== false ) {
                    continue;
                }
                fwrite($fp, $file_path . "\n");
                $count++;
            }
        }
        fclose($fp);

        return [
            'total_files' => $count,
            'message' => "ファイル一覧を作成しました。全 {$count} ファイル"
        ];
    }

    public function step_zip_chunk( $offset, $limit ) {
        $state = json_decode(file_get_contents($this->state_path), true);
        if ( ! $state ) throw new Exception("バックアップ状態が見つかりません。");

        if ( ! class_exists( 'ZipArchive' ) ) {
            throw new Exception("ZipArchive 拡張機能がインストールされていません。");
        }

        $zip = new ZipArchive();
        $flags = ($offset === 0) ? (ZipArchive::CREATE | ZipArchive::OVERWRITE) : 0;
        
        if ($offset === 0) {
            if ($zip->open( $state['zip_file'], ZipArchive::CREATE ) !== true) {
                throw new Exception("Zipファイルの作成に失敗しました。");
            }
        } else {
            if ($zip->open( $state['zip_file'] ) !== true) {
                throw new Exception("Zipファイルの追記オープンに失敗しました。");
            }
        }

        $root_path = ABSPATH;
        $fp = fopen($this->file_list_path, 'r');
        
        $current = 0;
        while ( $current < $offset && ! feof($fp) ) {
            fgets($fp);
            $current++;
        }

        $added = 0;
        while ( ! feof($fp) && $added < $limit ) {
            $line = fgets($fp);
            if ($line === false) break;
            
            $file_path = trim($line);
            if ( ! empty($file_path) && file_exists($file_path) ) {
                $relative_path = substr( $file_path, strlen( $root_path ) );
                $zip->addFile( $file_path, ltrim($relative_path, '/\\') );
            }
            $added++;
        }
        fclose($fp);
        $zip->close();

        return [
            'processed' => $offset + $added
        ];
    }

    public function step_finalize_zip() {
        $state = json_decode(file_get_contents($this->state_path), true);
        $zip = new ZipArchive();
        if ( $zip->open( $state['zip_file'] ) === true ) {
            if ( file_exists( $state['sql_file'] ) ) {
                $zip->addFile( $state['sql_file'], 'database.sql' );
            }
            $zip->close();
        }
        return ['message' => 'Zipファイルの作成を完了しました'];
    }

    public function step_upload() {
        $state = json_decode(file_get_contents($this->state_path), true);
        if ( ! $state ) throw new Exception("バックアップ状態が見つかりません。");

        $uploader = new WP_GDrive_Uploader();
        $folder_id = $uploader->create_folder( $state['basename'] );
        
        if ( $folder_id ) {
            $uploader->upload_file( $state['zip_file'], "{$state['basename']}.zip", $folder_id );
            $uploader->upload_file( $state['installer_file'], 'installer.php', $folder_id );
        } else {
            throw new Exception("Google Driveへのフォルダ作成に失敗しました。");
        }

        return ['message' => 'アップロードが完了しました。'];
    }

    public function step_cleanup() {
        $state = json_decode(file_get_contents($this->state_path), true);
        $zip_size = 0;
        if ( $state && file_exists($state['zip_file']) ) {
            $zip_size = filesize($state['zip_file']);
        }

        if ( $state ) {
            @unlink( $state['sql_file'] );
            @unlink( $state['zip_file'] );
            @unlink( $state['installer_file'] );
            @unlink( $this->file_list_path );
            @unlink( $this->state_path );
        }

        $uploader = new WP_GDrive_Uploader();
        $retention = new WP_GDrive_Retention_Manager();
        $retention->cleanup_old_backups( $uploader );
        
        if ( $state ) {
            // 履歴の保存
            $history = get_option('wpgb_backup_history', []);
            array_unshift($history, [
                'date' => current_time('mysql'),
                'name' => $state['basename'],
                'size' => $zip_size
            ]);
            $history = array_slice($history, 0, 50); // 直近50件まで保持
            update_option('wpgb_backup_history', $history);

            WP_GDrive_Mailer::send_success_report( [
                'name' => $state['basename'],
                'time' => current_time('mysql')
            ] );
        }
        return ['message' => '完了しました'];
    }

    public function step_abort() {
        $state = json_decode(file_get_contents($this->state_path), true);
        if ( $state ) {
            @unlink( $state['sql_file'] );
            @unlink( $state['zip_file'] );
            @unlink( $state['installer_file'] );
        }
        @unlink( $this->file_list_path );
        @unlink( $this->state_path );
        return ['message' => '処理を中断しました。'];
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

    public function run_backup() {
        try {
            $init = $this->step_init();
            $total = $init['total_files'];
            $offset = 0;
            while ($offset < $total) {
                $res = $this->step_zip_chunk($offset, 2000);
                $offset = $res['processed'];
            }
            $this->step_finalize_zip();
            $this->step_upload();
            $this->step_cleanup();
            return true;
        } catch (Exception $e) {
            $this->step_abort();
            throw $e;
        }
    }
}
