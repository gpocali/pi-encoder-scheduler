<?php
require_once 'auth.php'; 
require_once '../db_connect.php';

require_role('admin');

$errors = [];
$success_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['defaults']) && is_array($_POST['defaults'])) {
        $defaults_to_set = $_POST['defaults'];
        $pdo->beginTransaction();
        try {
            $sql = "INSERT INTO default_assets (`tag_id`, `asset_id`) VALUES (:tag_id, :asset_id_ins) ON DUPLICATE KEY UPDATE `asset_id` = :asset_id_upd";
            $stmt_insert_update = $pdo->prepare($sql);
            $sql_delete = "DELETE FROM default_assets WHERE `tag_id` = :tag_id_del";
            $stmt_delete = $pdo->prepare($sql_delete);

            foreach ($defaults_to_set as $tag_id => $asset_id) {
                $tag_id_int = (int)$tag_id;
                $asset_id_int = (int)$asset_id;

                if ($asset_id_int > 0) {
                    $stmt_insert_update->bindValue(':tag_id', $tag_id_int, PDO::PARAM_INT);
                    $stmt_insert_update->bindValue(':asset_id_ins', $asset_id_int, PDO::PARAM_INT);
                    $stmt_insert_update->bindValue(':asset_id_upd', $asset_id_int, PDO::PARAM_INT);
                    $stmt_insert_update->execute();
                } else {
                    $stmt_delete->execute([':tag_id_del' => $tag_id_int]);
                }
            }
            $pdo->commit();
            $success_message = "Default assets updated successfully!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

try {
    $tags = $pdo->query("SELECT `id`, `tag_name` FROM tags ORDER BY `tag_name`")->fetchAll(PDO::FETCH_ASSOC);
    $assets = $pdo->query("SELECT `id`, `filename_original` FROM assets ORDER BY `uploaded_at` DESC")->fetchAll(PDO::FETCH_ASSOC);
    $current_defaults_raw = $pdo->query("SELECT `tag_id`, `asset_id` FROM default_assets")->fetchAll(PDO::FETCH_KEY_PAIR); 
    
    $current_defaults = [];
    foreach ($tags as $tag) {
        $current_defaults[$tag['id']] = $current_defaults_raw[$tag['id']] ?? 0;
    }
} catch (Exception $e) {
    $errors[] = "Could not fetch data: " . $e->getMessage();
    $tags = []; $assets = []; $current_defaults = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Default Assets</title>
    <style>
        :root { --bg-color: #121212; --card-bg: #1e1e1e; --text-color: #e0e0e0; --accent-color: #bb86fc; --secondary-color: #03dac6; --error-color: #cf6679; --border-color: #333; }
        body { font-family: 'Inter', sans-serif; background: var(--bg-color); color: var(--text-color); margin: 0; padding: 2em; }
        .container { max-width: 800px; margin: 0 auto; }
        a { color: var(--accent-color); text-decoration: none; }
        h1 { color: #fff; }
        
        .card { background: var(--card-bg); padding: 2em; border-radius: 8px; margin-bottom: 2em; box-shadow: 0 4px 6px rgba(0,0,0,0.3); }
        
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 1em; text-align: left; border-bottom: 1px solid var(--border-color); }
        th { background: #2c2c2c; }
        
        select { width: 100%; padding: 0.8em; background: #2c2c2c; border: 1px solid var(--border-color); color: #fff; border-radius: 4px; }
        
        button { display: block; width: 100%; padding: 1em; background: var(--accent-color); color: #000; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; margin-top: 1.5em; }
        
        .message { padding: 1em; border-radius: 4px; margin-bottom: 1em; }
        .error { background: rgba(207, 102, 121, 0.2); border: 1px solid var(--error-color); color: var(--error-color); }
        .success { background: rgba(3, 218, 198, 0.2); border: 1px solid var(--secondary-color); color: var(--secondary-color); }
    </style>
</head>
<body>

    <div class="container">
        <a href="index.php">&larr; Back to Dashboard</a>
        <h1>Manage Default Assets</h1>
        <p style="color:#aaa;">Set the default graphic that will be shown for each tag when no event is scheduled.</p>

        <?php if (!empty($errors)): ?>
            <div class="message error"><ul><?php foreach ($errors as $e) echo "<li>$e</li>"; ?></ul></div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="message success"><?php echo $success_message; ?></div>
        <?php endif; ?>

        <div class="card">
            <form action="default_assets.php" method="POST">
                <table>
                    <thead>
                        <tr>
                            <th>Output Tag</th>
                            <th>Default Asset</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tags as $tag): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($tag['tag_name']); ?></td>
                            <td>
                                <select name="defaults[<?php echo $tag['id']; ?>]">
                                    <option value="0">-- None --</option>
                                    <?php foreach ($assets as $asset): ?>
                                        <option value="<?php echo $asset['id']; ?>"
                                            <?php if ($current_defaults[$tag['id']] == $asset['id']) echo 'selected'; ?>>
                                            <?php echo htmlspecialchars($asset['filename_original']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <button type="submit">Save All Defaults</button>
            </form>
        </div>
    </div>

</body>
</html>