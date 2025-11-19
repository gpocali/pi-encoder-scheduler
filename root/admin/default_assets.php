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
    <title>Manage Default Assets - WRHU Encoder Scheduler</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <?php include 'navbar.php'; ?>

    <div class="container">
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

    <footer>
        &copy;<?php echo date("Y") > 2025 ? "2025-" . date("Y") : "2025"; ?> WRHU Radio Hofstra University. Written by Gregory Pocali for WRHU.
    </footer>

</body>
</html>