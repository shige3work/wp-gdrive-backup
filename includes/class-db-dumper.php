<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_GDrive_DB_Dumper {
    
    public function dump( $output_file ) {
        global $wpdb;

        $host = DB_HOST;
        $user = DB_USER;
        $pass = DB_PASSWORD;
        $name = DB_NAME;

        $mysqli = new mysqli( $host, $user, $pass, $name );
        if ( $mysqli->connect_error ) {
            throw new Exception("DB接続エラー: " . $mysqli->connect_error);
        }

        $mysqli->set_charset( DB_CHARSET );

        $handle = fopen( $output_file, 'w+' );
        if ( ! $handle ) {
            throw new Exception("SQLファイルの作成に失敗しました: " . $output_file);
        }

        $tables = [];
        $result = $mysqli->query( "SHOW TABLES" );
        while ( $row = $result->fetch_row() ) {
            $tables[] = $row[0];
        }

        foreach ( $tables as $table ) {
            $result = $mysqli->query( "SELECT * FROM `$table`" );
            $num_fields = $result->field_count;

            $row2 = $mysqli->query( "SHOW CREATE TABLE `$table`" )->fetch_row();
            fwrite( $handle, "\n\nDROP TABLE IF EXISTS `$table`;\n" );
            fwrite( $handle, $row2[1] . ";\n\n" );

            while ( $row = $result->fetch_row() ) {
                fwrite( $handle, "INSERT INTO `$table` VALUES(" );
                for ( $j = 0; $j < $num_fields; $j++ ) {
                    $row[$j] = $row[$j] ? $mysqli->real_escape_string( $row[$j] ) : $row[$j];
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
            }
        }

        fclose( $handle );
        $mysqli->close();
    }
}
