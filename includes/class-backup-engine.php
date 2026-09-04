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
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root_path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $exclude_dirs = [
            $this->backup_dir,
            WP_CONTENT_DIR . '/cache',
            WP_CONTENT_DIR . '/backups-dup-lite',
            WP_CONTENT_DIR . '/updraft',
            WP_CONTENT_DIR . '/upgrade',
        ];

        $skipped_log = $this->backup_dir . '/wpgb_skipped_files.txt';
        $skipped_fp = fopen($skipped_log, 'w');
        fwrite($skipped_fp, "以下のファイルはバックアップから除外されました（容量制限50MB超過、またはキャッシュ/別バックアップ）:\n\n");

        $fp = fopen($this->file_list_path, 'w');
        $count = 0;
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $file_path = $file->getPathname();
                
                // ディレクトリ除外
                $skip = false;
                foreach ($exclude_dirs as $ex_dir) {
                    if ( strpos($file_path, $ex_dir) === 0 ) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) continue;

                // 50MB以上の超巨大ファイルを除外（ZipArchiveのフリーズ・メモリ枯渇を防ぐため）
                if ( filesize($file_path) > 50 * 1024 * 1024 ) {
                    fwrite($skipped_fp, "[>50MB] " . $file_path . "\n");
                    continue;
                }

                fwrite($fp, $file_path . "\n");
                $count++;
            }
        }
        fclose($fp);
        fclose($skipped_fp);

        $state['total_files'] = $count;
        file_put_contents($this->state_path, wp_json_encode($state));

        return [
            'total_files' => $count,
            'message' => "ファイル一覧を作成しました。全 {$count} ファイル"
        ];
    }

    public function get_state() {
        if ( file_exists($this->state_path) ) {
            return json_decode(file_get_contents($this->state_path), true);
        }
        return false;
    }

    public function step_zip_chunk($offset, $limit = 2000000) {
        $state = json_decode(file_get_contents($this->state_path), true);
        if ( ! $state ) throw new Exception("バックアップ状態が見つかりません。");

        $root_path = untrailingslashit( ABSPATH );
        $zip_file = $state['zip_file'];
        
        $zip_done_file = $this->backup_dir . '/zip_done.txt';
        $zip_error_file = $this->backup_dir . '/zip_error.txt';

        if ( $offset === -1 ) {
            if ( file_exists($zip_done_file) ) {
                @unlink($zip_done_file);
                @unlink($zip_error_file);
                WP_GDrive_Logger::log("Background zip CLI completed successfully.");
                return ['processed' => 999999999];
            } else if ( file_exists($zip_error_file) ) {
                $err = file_get_contents($zip_error_file);
                @unlink($zip_done_file);
                @unlink($zip_error_file);
                WP_GDrive_Logger::log("Background zip CLI failed. Output: {$err}. Falling back to PHP ZipArchive.", 'WARNING');
                $offset = 0; // Fallback to standard chunking
            } else {
                return ['processed' => -1];
            }
        }

        // Try CLI zip on first chunk if available
        if ( $offset == 0 && function_exists('exec') && ! in_array('exec', array_map('trim', explode(',', ini_get('disable_functions')))) ) {
            $zip_path = exec('which zip');
            if ( $zip_path ) {
                WP_GDrive_Logger::log("Starting zip CLI in background: {$zip_path}");
                
                // Exclude dirs
                $exclude_str = "";
                $exclude_dirs = [
                    'wp-content/uploads/wp-gdrive-backups/*',
                    'wp-content/cache/*',
                    'wp-content/backups-dup-lite/*',
                    'wp-content/updraft/*',
                    'wp-content/upgrade/*'
                ];
                foreach ($exclude_dirs as $ex) {
                    $exclude_str .= " -x " . escapeshellarg($ex);
                }

                @unlink($zip_done_file);
                @unlink($zip_error_file);

                // Run in background using parentheses and &
                $cmd = sprintf(
                    "cd %s && ( %s -r -q %s . %s > /dev/null 2>&1 && touch %s || echo 'error' > %s ) > /dev/null 2>&1 &",
                    escapeshellarg($root_path),
                    escapeshellcmd($zip_path),
                    escapeshellarg($zip_file),
                    $exclude_str,
                    escapeshellarg($zip_done_file),
                    escapeshellarg($zip_error_file)
                );
                
                exec($cmd);
                return ['processed' => -1];
            }
        }
        
        if ( $offset == 0 ) {
            WP_GDrive_Logger::log("zip CLI not available or failed. Using PHP ZipArchive (Chunking).");
        }

        $zip = new ZipArchive();
        if ( $zip->open($zip_file, ZipArchive::CREATE) !== true ) {
            WP_GDrive_Logger::log("ZipArchive Open Failed: " . $zip_file, 'ERROR');
            throw new Exception("Zipファイルの作成/展開に失敗しました。");
        }

        $fp = fopen($this->file_list_path, 'r');
        $current = 0;
        while ( $current < $offset && ! feof($fp) ) {
            fgets($fp);
            $current++;
        }

        $added = 0;
        $chunk_bytes = 0;
        while ( ! feof($fp) ) {
            $line = fgets($fp);
            if ($line === false) break;
            
            $file_path = trim($line);
            if ( ! empty($file_path) && file_exists($file_path) ) {
                $relative_path = substr( $file_path, strlen( $root_path ) );
                $zip->addFile( $file_path, ltrim($relative_path, '/\\') );
                $chunk_bytes += filesize($file_path);
            }
            $added++;
            
            // ZipArchive::addFile is deferred until close(), so microtime() check is useless here.
            // We must limit by file count or bytes to ensure close() finishes quickly.
            if ($added >= 500 || $chunk_bytes >= 30 * 1024 * 1024) {
                break;
            }
        }
        fclose($fp);
        $zip->close();
        
        WP_GDrive_Logger::log("Zip chunk processed: " . ($offset + $added) . " files added.");

        return ['processed' => $offset + $added];
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
        $client = $uploader->get_client();
        $local_file_path = $state['zip_file'];
        
        if ( ! file_exists($local_file_path) ) {
            throw new Exception("アップロードするZipファイルが見つかりません。");
        }
        
        $file_size = filesize($local_file_path);
        $chunkSizeBytes = 2 * 1024 * 1024; // 2MB chunk

        // If resumeUri is not set, initialize upload session
        if ( empty($state['resumeUri']) ) {
            $client->setDefer(false);
            $folder_id = $uploader->create_folder( $state['basename'] );
            if ( ! $folder_id ) throw new Exception("Google Driveへのフォルダ作成に失敗しました。");
            $state['folder_id'] = $folder_id;

            $client->setDefer(true);
            $fileMetadata = new \Google\Service\Drive\DriveFile([
                'name' => $state['basename'] . '.zip',
                'parents' => [ $folder_id ]
            ]);
            $request = $uploader->get_service()->files->create($fileMetadata);
            $media = new \Google\Http\MediaFileUpload(
                $client, $request, 'application/zip', null, true, $chunkSizeBytes
            );
            $media->setFileSize($file_size);
            
            $resumeUri = $media->getResumeUri();
            $state['resumeUri'] = $resumeUri;
            $state['uploadOffset'] = 0;
            file_put_contents($this->state_path, wp_json_encode($state));
            
            return [
                'uploaded' => 0,
                'total' => $file_size,
                'done' => false
            ];
        }

        // Resume upload
        $client->setDefer(true);
        $request = $uploader->get_service()->files->create(new \Google\Service\Drive\DriveFile());
        $media = new \Google\Http\MediaFileUpload(
            $client, $request, 'application/zip', null, true, $chunkSizeBytes
        );
        $media->setFileSize($file_size);
        $media->resume($state['resumeUri']);

        $status = false;
        $handle = fopen($local_file_path, "rb");
        fseek($handle, $state['uploadOffset']);
        
        $start_time = microtime(true);
        while ( ! $status && ! feof($handle) ) {
            $chunk = fread($handle, $chunkSizeBytes);
            $status = $media->nextChunk($chunk);
            $state['uploadOffset'] = ftell($handle);
            
            // Break if we exceed 10 seconds
            if ( (microtime(true) - $start_time) > 10 ) {
                break;
            }
        }
        fclose($handle);
        
        $percent = round(($state['uploadOffset'] / $file_size) * 100, 1);
        WP_GDrive_Logger::log("Upload progress: {$percent}% ({$state['uploadOffset']} / {$file_size} bytes)");

        if ($status) {
            WP_GDrive_Logger::log("Zip upload completed. Uploading installer...");
            // ZIP upload is done. Upload the tiny installer synchronously.
            $client->setDefer(false);
            $uploader->upload_file( $state['installer_file'], 'installer.php', $state['folder_id'] );
            
            return [
                'uploaded' => $file_size,
                'total' => $file_size,
                'done' => true,
                'message' => 'アップロードが完了しました。'
            ];
        } else {
            file_put_contents($this->state_path, wp_json_encode($state));
            return [
                'uploaded' => $state['uploadOffset'],
                'total' => $file_size,
                'done' => false
            ];
        }
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
                // Ignore time limit in cli/cron, we just loop until done
                $res = $this->step_zip_chunk($offset, 2000000); 
                $offset = $res['processed'];
            }
            $this->step_finalize_zip();
            
            $upload_done = false;
            while (!$upload_done) {
                $res = $this->step_upload();
                $upload_done = $res['done'];
            }
            
            $this->step_cleanup();
            return true;
        } catch (Exception $e) {
            $this->step_abort();
            throw $e;
        }
    }
}
