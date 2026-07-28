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

        $json_key_str = get_option( 'wpgb_gdrive_json_key' );
        $this->parent_folder_id = get_option( 'wpgb_gdrive_folder_id' );

        if ( empty( $json_key_str ) || empty( $this->parent_folder_id ) ) {
            throw new Exception("Google Driveの設定（JSONキー、フォルダID）が未設定です。");
        }

        $json_key = json_decode( trim($json_key_str), true );
        if ( ! $json_key || ! isset( $json_key['client_email'] ) ) {
            throw new Exception("無効なJSONキーです。");
        }

        $this->client = new \Google\Client();
        $this->client->setAuthConfig( $json_key );
        $this->client->addScope( \Google\Service\Drive::DRIVE );
        
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
