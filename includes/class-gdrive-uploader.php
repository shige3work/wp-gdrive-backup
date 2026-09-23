<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_GDrive_Uploader {
    private $client;
    private $service;
    private $parent_folder_id;

    public function __construct() {
        if ( ! class_exists( 'Google\Client' ) ) {
            throw new Exception("Google API Client が見つかりません。composer install が実行されているか確認してください。");
        }

        $client_id = get_option( 'wpgb_gdrive_client_id' );
        $client_secret = get_option( 'wpgb_gdrive_client_secret' );
        $refresh_token = get_option( 'wpgb_gdrive_refresh_token' );
        $this->parent_folder_id = get_option( 'wpgb_gdrive_folder_id' );

        if ( empty( $client_id ) || empty( $client_secret ) || empty( $refresh_token ) || empty( $this->parent_folder_id ) ) {
            throw new Exception("Google Driveの設定（認証情報、フォルダID）が未完了です。設定画面から認証を行ってください。");
        }

        $this->client = new \Google\Client();
        $this->client->setClientId($client_id);
        $this->client->setClientSecret($client_secret);
        $this->client->addScope( \Google\Service\Drive::DRIVE_FILE );
        $this->client->setAccessType('offline');
        
        $token = null;
        $max_retries = 3;
        for ( $i = 1; $i <= $max_retries; $i++ ) {
            try {
                $token = $this->client->refreshToken($refresh_token);
                if ( ! isset($token['error']) ) {
                    break;
                }
            } catch ( \Exception $e ) {
                if ( $i === $max_retries ) {
                    throw $e;
                }
                WP_GDrive_Logger::log("Google Drive 認証トークン更新リトライ中 ({$i}/{$max_retries}): " . $e->getMessage(), 'WARNING');
                sleep(2);
            }
        }
        if ( isset($token['error']) ) {
            throw new Exception("Google Driveの認証トークンが無効です。再度連携を行ってください。");
        }
        
        $this->service = new \Google\Service\Drive( $this->client );
    }

    public function create_folder( $folder_name ) {
        $fileMetadata = new \Google\Service\Drive\DriveFile([
            'name' => $folder_name,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [ $this->parent_folder_id ]
        ]);

        $max_retries = 3;
        for ( $i = 1; $i <= $max_retries; $i++ ) {
            try {
                $folder = $this->service->files->create( $fileMetadata, [
                    'fields' => 'id'
                ]);
                return $folder->id;
            } catch ( \Exception $e ) {
                if ( $i === $max_retries ) {
                    throw $e;
                }
                WP_GDrive_Logger::log("Google Drive フォルダ作成リトライ中 ({$i}/{$max_retries}): " . $e->getMessage(), 'WARNING');
                sleep(2);
            }
        }
    }

    public function upload_file( $local_file_path, $gdrive_file_name, $parent_id ) {
        $fileMetadata = new \Google\Service\Drive\DriveFile([
            'name' => $gdrive_file_name,
            'parents' => [ $parent_id ]
        ]);

        $mime_type = mime_content_type( $local_file_path );
        if ( ! $mime_type ) {
            $mime_type = 'application/octet-stream';
        }

        // Upload in chunks for large files
        $this->client->setDefer(true);
        $request = $this->service->files->create($fileMetadata);
        
        $chunkSizeBytes = 5 * 1024 * 1024; // 5MB
        $media = new \Google\Http\MediaFileUpload(
            $this->client,
            $request,
            $mime_type,
            null,
            true,
            $chunkSizeBytes
        );
        $media->setFileSize(filesize($local_file_path));
        
        $status = false;
        $handle = fopen($local_file_path, "rb");
        while (!$status && !feof($handle)) {
            $chunk = fread($handle, $chunkSizeBytes);
            $chunk_retry = 0;
            while ( true ) {
                try {
                    $status = $media->nextChunk($chunk);
                    break;
                } catch ( \Exception $e ) {
                    $chunk_retry++;
                    if ( $chunk_retry >= 3 ) {
                        fclose($handle);
                        $this->client->setDefer(false);
                        throw $e;
                    }
                    WP_GDrive_Logger::log("ファイルアップロードチャンクリトライ中 ({$chunk_retry}/3): " . $e->getMessage(), 'WARNING');
                    sleep(2);
                }
            }
        }
        fclose($handle);
        $this->client->setDefer(false);

        return $status->id ?? false;
    }
    
    public function get_service() {
        return $this->service;
    }
    
    public function get_client() {
        return $this->client;
    }
    
    public function get_parent_folder_id() {
        return $this->parent_folder_id;
    }
}
