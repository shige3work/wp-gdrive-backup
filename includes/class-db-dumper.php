<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_GDrive_DB_Dumper {
    
    public function dump( $output_file ) {
        global $wpdb;

        WP_GDrive_Logger::log("Starting database dump...");

        $host = DB_HOST;
        $user = DB_USER;
        $pass = DB_PASSWORD;
        $name = DB_NAME;
        
        // Try mysqldump first (CLI)
        $use_cli = false;
        if ( function_exists('exec') && ! in_array('exec', array_map('trim', explode(',', ini_get('disable_functions')))) ) {
            $mysqldump_path = exec('which mysqldump');
            if ( $mysqldump_path ) {
                $use_cli = true;
                WP_GDrive_Logger::log("Using mysqldump CLI: {$mysqldump_path}");
                
                // Extract host and port if host is like "127.0.0.1:3306"
                $host_parts = explode(':', $host);
                $host_str = $host_parts[0];
                $port_str = isset($host_parts[1]) ? " -P {$host_parts[1]}" : "";
                
                $cmd = sprintf(
                    "%s -h %s%s -u %s -p%s %s > %s 2>&1",
                    escapeshellcmd($mysqldump_path),
                    escapeshellarg($host_str),
                    $port_str,
                    escapeshellarg($user),
                    escapeshellarg($pass),
                    escapeshellarg($name),
                    escapeshellarg($output_file)
                );
                
                exec($cmd, $output, $return_var);
                if ( $return_var === 0 ) {
                    WP_GDrive_Logger::log("mysqldump CLI completed successfully.");
                    return;
                } else {
                    WP_GDrive_Logger::log("mysqldump CLI failed with code {$return_var}. Output: " . implode(" ", $output) . ". Falling back to PHP dump.", 'WARNING');
                }
            }
        }
        
        if ( ! $use_cli ) {
            WP_GDrive_Logger::log("mysqldump CLI not available. Using PHP fallback.");
        }

        // Fallback to PHP (with unbuffered queries to prevent OOM)
        $mysqli = new mysqli( $host, $user, $pass, $name );
        if ( $mysqli->connect_error ) {
            WP_GDrive_Logger::log("DB Connect Error: " . $mysqli->connect_error, 'ERROR');
            throw new Exception("DB接続エラー: " . $mysqli->connect_error);
        }

        $mysqli->set_charset( DB_CHARSET );

        $handle = fopen( $output_file, 'w+' );
        if ( ! $handle ) {
            WP_GDrive_Logger::log("Failed to create SQL file: " . $output_file, 'ERROR');
            throw new Exception("SQLファイルの作成に失敗しました: " . $output_file);
        }

        $tables = [];
        $result = $mysqli->query( "SHOW TABLES" );
        while ( $row = $result->fetch_row() ) {
            $tables[] = $row[0];
        }
        
        WP_GDrive_Logger::log("Found " . count($tables) . " tables to dump.");

        foreach ( $tables as $table ) {
            $row2 = $mysqli->query( "SHOW CREATE TABLE `$table`" )->fetch_row();
            fwrite( $handle, "\n\nDROP TABLE IF EXISTS `$table`;\n" );
            fwrite( $handle, $row2[1] . ";\n\n" );

            // Use MYSQLI_USE_RESULT to prevent memory exhaustion
            $result = $mysqli->query( "SELECT * FROM `$table`", MYSQLI_USE_RESULT );
            if ( $result ) {
                $num_fields = $result->field_count;
                $row_count = 0;
                while ( $row = $result->fetch_row() ) {
                    fwrite( $handle, "INSERT INTO `$table` VALUES(" );
                    for ( $j = 0; $j < $num_fields; $j++ ) {
                        $row[$j] = isset($row[$j]) ? $mysqli->real_escape_string( $row[$j] ) : null;
                        if ( isset( $row[$j] ) ) {
                            fwrite( $handle, "'" . $row[$j] . "'" );
                        } else {
                            fwrite( $handle, "NULL" );
                        }
                        if ( $j < ( $num_fields - 1 ) ) {
                            fwrite( $handle, "," );
                        }
                    }
                    fwrite( $handle, ");\n" );
                    $row_count++;
                }
                $result->free();
                WP_GDrive_Logger::log("Dumped table {$table} ({$row_count} rows).");
            }
        }

        fclose( $handle );
        $mysqli->close();
        WP_GDrive_Logger::log("PHP database dump completed successfully.");
    }
}
