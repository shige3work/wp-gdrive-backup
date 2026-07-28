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
        
        $token = $this->client->refreshToken($refresh_token);
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

        $folder = $this->service->files->create( $fileMetadata, [
            'fields' => 'id'
        ]);

        return $folder->id;
    }

    public function upload_file( $local_file_path, $gdrive_file_name, $parent_id ) {
        $fileMetadata = new \Google\Service\Drive\DriveFile([
            'name' => $gdrive_file_name,
            'parents' => [ $parent_id ]
        ]);

        $content = file_get_contents( $local_file_path );
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
            $status = $media->nextChunk($chunk);
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
