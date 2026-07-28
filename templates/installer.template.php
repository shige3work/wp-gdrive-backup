<?php
/**
 * WP GDrive Backup Installer
 * This file extracts the backup ZIP and restores the database.
 */

$zip_filename = '{{ZIP_FILENAME}}';
$sql_filename = 'database.sql';

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$error = '';
$success = '';

function get_db_connection() {
    $host = $_POST['db_host'] ?? 'localhost';
    $user = $_POST['db_user'] ?? '';
    $pass = $_POST['db_pass'] ?? '';
    $name = $_POST['db_name'] ?? '';
    
    $mysqli = new mysqli($host, $user, $pass, $name);
    if ($mysqli->connect_error) {
        return false;
    }
    return $mysqli;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step === 1) {
        // Step 1: Extract ZIP
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive;
            if ($zip->open($zip_filename) === TRUE) {
                $zip->extractTo(__DIR__);
                $zip->close();
                $success = "ファイルの展開が完了しました。";
                $step = 2;
            } else {
                $error = "ZIPファイルの展開に失敗しました。ファイルが存在するか確認してください。";
            }
        } else {
            $error = "ZipArchiveクラスがサーバーに存在しません。";
        }
    } elseif ($step === 2) {
        // Step 2: Restore Database
        $mysqli = get_db_connection();
        if ($mysqli) {
            if (file_exists($sql_filename)) {
                $sql = file_get_contents($sql_filename);
                if ($mysqli->multi_query($sql)) {
                    do {
                        if ($result = $mysqli->store_result()) {
                            $result->free();
                        }
                    } while ($mysqli->more_results() && $mysqli->next_result());
                    
                    $success = "データベースの復元が完了しました。これですべての復元プロセスが完了です！";
                    $step = 3;
                    
                    // Cleanup installer files for security
                    @unlink($zip_filename);
                    @unlink($sql_filename);
                    @unlink(__FILE__);
                } else {
                    $error = "データベースのインポートに失敗しました: " . $mysqli->error;
                }
            } else {
                $error = "展開された database.sql が見つかりません。";
            }
        } else {
            $error = "データベースに接続できません。認証情報を確認してください。";
        }
    }
}

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>WP GDrive Backup - Installer</title>
    <style>
        body { font-family: sans-serif; background: #f1f1f1; color: #444; }
        .container { max-width: 600px; margin: 50px auto; background: #fff; padding: 20px; border-radius: 5px; box-shadow: 0 1px 3px rgba(0,0,0,.13); }
        h1 { font-size: 24px; border-bottom: 1px solid #ddd; padding-bottom: 10px; }
        .alert { padding: 10px; margin-bottom: 15px; border-left: 4px solid; }
        .alert-error { background: #fde8ec; border-color: #d63638; }
        .alert-success { background: #edfaeb; border-color: #00a32a; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="text"], input[type="password"] { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 3px; box-sizing: border-box; }
        button { background: #2271b1; color: #fff; border: none; padding: 10px 15px; font-size: 16px; cursor: pointer; border-radius: 3px; }
        button:hover { background: #135e96; }
    </style>
</head>
<body>

<div class="container">
    <h1>バックアップ復元ウィザード</h1>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <?php if ($step === 1): ?>
        <p>対象アーカイブ: <strong><?php echo htmlspecialchars($zip_filename); ?></strong></p>
        <p>このスクリプトは、上記Zipファイルを現在のディレクトリに展開し、WordPressのファイルを復元します。</p>
        <form method="post" action="?step=1">
            <button type="submit">ファイルの展開を開始する</button>
        </form>

    <?php elseif ($step === 2): ?>
        <p>ファイルの展開が完了しました。次にデータベースの復元を行います。</p>
        <p>復元先のデータベース接続情報を入力してください。</p>
        <form method="post" action="?step=2">
            <div class="form-group">
                <label>ホスト (Host)</label>
                <input type="text" name="db_host" value="localhost" required>
            </div>
            <div class="form-group">
                <label>データベース名 (Database Name)</label>
                <input type="text" name="db_name" required>
            </div>
            <div class="form-group">
                <label>ユーザー名 (User)</label>
                <input type="text" name="db_user" required>
            </div>
            <div class="form-group">
                <label>パスワード (Password)</label>
                <input type="password" name="db_pass">
            </div>
            <button type="submit">データベースを復元する</button>
        </form>

    <?php elseif ($step === 3): ?>
        <p>復元処理がすべて完了しました。セキュリティのため、インストール用ファイルとアーカイブは削除されました。</p>
        <p>新しいWordPressサイトにアクセスして、動作を確認してください。</p>
    <?php endif; ?>

</div>

</body>
</html>
