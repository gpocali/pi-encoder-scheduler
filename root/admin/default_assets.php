<?php
require_once 'auth.php'; 
require_once '../db_connect.php';

$errors = [];
$success_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    if (isset($_POST['defaults']) && is_array($_POST['defaults'])) {
        
        $defaults_to_set = $_POST['defaults'];
        
        $pdo->beginTransaction();
        try {
            $sql = "
                INSERT INTO default_assets (`tag_id`, `asset_id`) 
                VALUES (:tag_id, :asset_id_ins)
                ON DUPLICATE KEY UPDATE `asset_id` = :asset_id_upd
            ";
            $stmt_insert_update = $pdo->prepare($sql);

            $sql_delete = "DELETE FROM default_assets WHERE `tag_id` = :tag_id_del";
            $stmt_delete = $pdo->prepare($sql_delete);

            foreach ($defaults_to_set as $tag_id => $asset_id) {
                $tag_id_int = (int)$tag_id;
                $asset_id_int = (int)$asset_id;

                if ($asset_id_int > 0) {
                    // Use unique names and bindParam for clarity and compatibility
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
    $tag_stmt = $pdo->query("SELECT `id`, `tag_name` FROM tags ORDER BY `tag_name`");
    $tags = $tag_stmt->fetchAll(PDO::FETCH_ASSOC);

    $asset_stmt = $pdo->query("SELECT `id`, `filename_original` FROM assets ORDER BY `uploaded_at` DESC");
    $assets = $asset_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $default_stmt = $pdo->query("SELECT `tag_id`, `asset_id` FROM default_assets");
    $current_defaults_raw = $default_stmt->fetchAll(PDO::FETCH_KEY_PAIR); 
    
    $current_defaults = [];
    foreach ($tags as $tag) {
        $current_defaults[$tag['id']] = $current_defaults_raw[$tag['id']] ?? 0;
    }

} catch (Exception $e) {
    $errors[] = "Could not fetch data: " . $e->getMessage();
    $tags = [];
    $assets = [];
    $current_defaults = [];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Default Assets</title>
    <style>
        body { font-family: sans-serif; margin: 2em; background: #f4f4f4; }
        .container { max-width: 800px; margin: 0 auto; padding: 2em; background: #fff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { text-align: center; }
        .message { padding: 1em; border-radius: 4px; margin-bottom: 1em; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .nav-link { display: inline-block; margin-bottom: 1em; }
        
        .default-table { width: 100%; border-collapse: collapse; margin-top: 1.5em; }
        .default-table th, .default-table td {
            padding: 0.8em;
            border: 1px solid #ddd;
            text-align: left;
        }
        .default-table th { background-color: #f9f9f9; }
        .default-table td:first-child { font-weight: bold; width: 30%; }
        .default-table select { width: 100%; padding: 0.5em; border-radius: 4px; border: 1px solid #ccc; }
        
        .btn { display: block; width: 100%; padding: 1em; background-color: #28a745; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 1em; margin-top: 1.5em; }
        .btn:hover { background-color: #218838; }
        
        .no-data { text-align: center; color: #777; padding: 2em; }
    </style>
</head>
<body>

    <div class="container">
        <a href="index.php" class="nav-link">&larr; Back to Dashboard</a>
        <h1>Manage Default Assets</h1>
        <p>Set the default graphic that will be shown for each tag when no event is scheduled.</p>

        <?php if (!empty($errors)): ?>
            <div class="message error">
                <strong>Error!</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($success_message): ?>
            <div class="message success">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <?php if (empty($tags) || empty($assets)): ?>
            <div class="no-data">
                <p>You must create **Tags** and upload **Assets** before you can set defaults.</p>
                <p>(Assets are uploaded when you create a new event.)</p>
            </div>
        <?php else: ?>
            <form action="default_assets.php" method="POST">
                <table class="default-table">
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
                <button type="submit" class="btn">Save All Defaults</button>
            </form>
        <?php endif; ?>
    </div>

</body>
</html>