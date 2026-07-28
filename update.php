<?php
$file = 'wp-gdrive-backup.php';
$content = file_get_contents($file);

$content = str_replace(" * Version: 1.0.10", " * Version: 1.0.11", $content);

$php_ajax = <<<PHP
    public function ajax_prepare_retry_upload() {
        check_ajax_referer( 'wpgb_ajax_backup', '_ajax_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '権限がありません' );

        \$filename = sanitize_text_field( \$_POST['filename'] );
        if ( empty(\$filename) || strpos(\$filename, '.zip') === false ) wp_send_json_error( '無効なファイル名' );

        \$upload_dir = wp_upload_dir();
        \$backup_dir = \$upload_dir['basedir'] . '/wpgb_backups';
        \$zip_file = \$backup_dir . '/' . \$filename;

        if ( ! file_exists(\$zip_file) ) wp_send_json_error( 'ファイルが存在しません' );

        \$basename = str_replace('.zip', '', \$filename);

        \$state = [
            'basename' => \$basename,
            'zip_file' => \$zip_file,
            'sql_file' => \$backup_dir . '/' . \$basename . '.sql',
            'installer_file' => \$backup_dir . '/' . \$basename . '_installer.php',
            'uploadOffset' => 0,
            'resumeUri' => '',
            'folder_id' => '',
        ];

        \$state_path = \$backup_dir . '/wpgb_state.json';
        file_put_contents(\$state_path, wp_json_encode(\$state));

        wp_send_json_success( [ 'total_size' => filesize(\$zip_file) ] );
    }

    public function ajax_chunk_step() {
PHP;

$content = str_replace("    public function ajax_chunk_step() {", $php_ajax, $content);
$content = str_replace("add_action( 'wp_ajax_wpgb_chunk_step', [ \$this, 'ajax_chunk_step' ] );", "add_action( 'wp_ajax_wpgb_chunk_step', [ \$this, 'ajax_chunk_step' ] );\n        add_action( 'wp_ajax_wpgb_prepare_retry_upload', [ \$this, 'ajax_prepare_retry_upload' ] );", $content);

$ui_table = <<<HTML
            <hr>
            <h2>ローカルバックアップ (サーバー上)</h2>
            <p>サーバー内に保存されているバックアップファイルです。Zip化まで成功したファイルがここに残るため、初めからやり直すことなくGoogle Driveへのアップロードを再試行したり、手動でダウンロードすることができます。</p>
            <?php
            \$upload_dir = wp_upload_dir();
            \$backup_dir = \$upload_dir['basedir'] . '/wpgb_backups';
            \$backup_url = \$upload_dir['baseurl'] . '/wpgb_backups';
            if ( ! file_exists(\$backup_dir) ) {
                echo '<p>ローカルバックアップはありません。</p>';
            } else {
                \$files = glob(\$backup_dir . '/*.zip');
                if ( empty(\$files) ) {
                    echo '<p>ローカルバックアップはありません。</p>';
                } else {
                    rsort(\$files);
                    echo '<table class="wp-list-table widefat fixed striped" style="max-width: 900px; margin-top: 10px;">';
                    echo '<thead><tr><th style="width: 40%;">ファイル名</th><th style="width: 20%;">サイズ</th><th style="width: 40%;">操作</th></tr></thead>';
                    echo '<tbody>';
                    foreach ( \$files as \$file ) {
                        \$basename = basename(\$file);
                        \$size = size_format(filesize(\$file), 2);
                        \$url = \$backup_url . '/' . \$basename;
                        echo '<tr>';
                        echo '<td>' . esc_html(\$basename) . '</td>';
                        echo '<td>' . esc_html(\$size) . '</td>';
                        echo '<td>';
                        echo '<a href="' . esc_url(\$url) . '" class="button button-secondary" download>ダウンロード</a> ';
                        echo '<button type="button" class="button button-primary wpgb-retry-upload-btn" data-filename="' . esc_attr(\$basename) . '">Driveへアップロード</button>';
                        echo '</td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                }
            }
            ?>

            <hr>
            <h2>手動バックアップの実行</h2>
HTML;

$content = str_replace("<hr>\n            <h2>手動バックアップの実行</h2>", $ui_table, $content);

$js_replacement = <<<JS
            <script>
            jQuery(document).ready(function($) {
                let totalFiles = 0;
                let currentOffset = 0;
                let nonce = '<?php echo wp_create_nonce("wpgb_ajax_backup"); ?>';

                function showError(msg) {
                    $('#wpgb-progress-bar').css('background', '#dc3232');
                    $('#wpgb-progress-text').html('<span style="color:#dc3232;">エラー: ' + msg + '</span>');
                    $('#wpgb-start-backup-btn').prop('disabled', false);
                    $('.wpgb-retry-upload-btn').prop('disabled', false);
                }

                function doStep(stepName, offset = 0) {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'wpgb_chunk_step',
                            _ajax_nonce: nonce,
                            step: stepName,
                            offset: offset
                        },
                        success: function(res) {
                            if (!res.success) {
                                showError(res.data || '不明なエラー');
                                return;
                            }

                            if (stepName === 'init') {
                                totalFiles = res.data.total_files;
                                $('#wpgb-progress-text').text('Zip圧縮を開始します... (0 / ' + totalFiles + ')');
                                $('#wpgb-progress-bar').css('width', '10%');
                                doStep('zip', 0);
                            } 
                            else if (stepName === 'zip') {
                                currentOffset = res.data.processed;
                                let percent = 10 + Math.floor((currentOffset / totalFiles) * 70);
                                if (percent > 80) percent = 80;
                                $('#wpgb-progress-bar').css('width', percent + '%');
                                $('#wpgb-progress-text').text('ファイルを圧縮中... (' + currentOffset + ' / ' + totalFiles + ')');
                                
                                if (currentOffset < totalFiles) {
                                    doStep('zip', currentOffset);
                                } else {
                                    $('#wpgb-progress-bar').css('width', '80%');
                                    $('#wpgb-progress-text').text('Google Driveへアップロード準備中...');
                                    doStep('finalize');
                                }
                            }
                            else if (stepName === 'finalize') {
                                $('#wpgb-progress-bar').css('width', '85%');
                                $('#wpgb-progress-text').text('Google Driveへアップロード中...');
                                doStep('upload');
                            }
                            else if (stepName === 'upload') {
                                if (res.data.done) {
                                    $('#wpgb-progress-bar').css('width', '95%');
                                    $('#wpgb-progress-text').text('完了処理中...');
                                    doStep('cleanup');
                                } else {
                                    let uploaded = res.data.uploaded || 0;
                                    let total = res.data.total || 1;
                                    let percentMB = (uploaded / 1024 / 1024).toFixed(1);
                                    let totalMB = (total / 1024 / 1024).toFixed(1);
                                    let percent = 85 + Math.floor((uploaded / total) * 10);
                                    
                                    $('#wpgb-progress-bar').css('width', percent + '%');
                                    $('#wpgb-progress-text').text('Google Driveへアップロード中... (' + percentMB + 'MB / ' + totalMB + 'MB)');
                                    doStep('upload');
                                }
                            }
                            else if (stepName === 'cleanup') {
                                $('#wpgb-progress-bar').css('width', '100%').css('background', '#46b450');
                                $('#wpgb-progress-text').html('<span style="color:#46b450;">バックアップが完了しました！</span>');
                                $('#wpgb-start-backup-btn').prop('disabled', false);
                                $('.wpgb-retry-upload-btn').prop('disabled', false);
                                setTimeout(function(){ location.reload(); }, 2000);
                            }
                        },
                        error: function(xhr, status, error) {
                            if (xhr.status === 504 || xhr.status === 502) {
                                showError('サーバーのタイムアウト制限に到達しました。');
                            } else {
                                showError('通信エラーが発生しました。');
                            }
                        }
                    });
                }

                $('.wpgb-retry-upload-btn').on('click', function() {
                    if (!confirm('このファイルをGoogle Driveへアップロードしますか？（※エラーになる場合はGoogle Drive側の制限の可能性があります）')) return;
                    
                    var filename = $(this).data('filename');
                    $('#wpgb-start-backup-btn').prop('disabled', true);
                    $('.wpgb-retry-upload-btn').prop('disabled', true);
                    
                    $('#wpgb-progress-wrapper').show();
                    $('#wpgb-progress-bar').css({'width': '0%', 'background': '#2271b1'});
                    $('#wpgb-progress-text').text('アップロードの準備中...').css('color', '#000');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'wpgb_prepare_retry_upload',
                            filename: filename,
                            _ajax_nonce: nonce
                        },
                        success: function(res) {
                            if (res.success) {
                                totalFiles = res.data.total_size; 
                                $('#wpgb-progress-bar').css('width', '85%');
                                $('#wpgb-progress-text').text('Google Driveへアップロード中...');
                                doStep('upload', 0);
                            } else {
                                showError(res.data || '準備エラー');
                            }
                        },
                        error: function() {
                            showError('通信エラー');
                        }
                    });
                });

                $('#wpgb-start-backup-btn').on('click', function() {
                    if ( ! confirm('バックアップを開始します。処理には数分かかる場合があります。よろしいですか？') ) return;
                    
                    $('#wpgb-start-backup-btn').prop('disabled', true);
                    $('.wpgb-retry-upload-btn').prop('disabled', true);
                    $('#wpgb-progress-wrapper').show();
                    $('#wpgb-progress-bar').css('width', '5%').css('background', '#2271b1');
                    $('#wpgb-progress-text').text('準備中 (データベース保存・ファイル一覧作成)...').css('color', '#000');
                    
                    totalFiles = 0;
                    currentOffset = 0;
                    doStep('init');
                });
            });
            </script>
JS;

$pattern = '/<script>[\s\S]*?<\/script>/';
$content = preg_replace($pattern, $js_replacement, $content);

file_put_contents($file, $content);
echo "Done";
?>
