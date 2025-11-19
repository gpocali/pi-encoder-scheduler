<?php
require_once 'auth.php'; 
require_once '../db_connect.php';

require_role('admin');

$errors = [];
$success_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    if (isset($_POST['action']) && $_POST['action'] == 'create_tag') {
        $tag_name = trim($_POST['tag_name']);
        $storage_limit_mb = (int)$_POST['storage_limit_mb'];

        if (empty($tag_name)) {
            $errors[] = "Tag name cannot be empty.";
        } else {
            $stmt_check = $pdo->prepare("SELECT id FROM tags WHERE tag_name = ?");
            $stmt_check->execute([$tag_name]);
            if ($stmt_check->fetch()) {
                $errors[] = "Tag '{$tag_name}' already exists.";
            } else {
                try {
                    $stmt_insert = $pdo->prepare("INSERT INTO tags (tag_name, storage_limit_mb) VALUES (?, ?)");
                    $stmt_insert->execute([$tag_name, $storage_limit_mb]);
                    $success_message = "Tag '{$tag_name}' created successfully!";
                } catch (Exception $e) {
                    $errors[] = "Error creating tag: " . $e->getMessage();
                }
            }
        }
    }

    if (isset($_POST['action']) && $_POST['action'] == 'update_tag') {
        $tag_id = (int)$_POST['tag_id'];
        $storage_limit_mb = (int)$_POST['storage_limit_mb'];
        
        try {
            $stmt_upd = $pdo->prepare("UPDATE tags SET storage_limit_mb = ? WHERE id = ?");
            $stmt_upd->execute([$storage_limit_mb, $tag_id]);
            $success_message = "Tag updated successfully.";
        } catch (Exception $e) {
            $errors[] = "Error updating tag: " . $e->getMessage();
        }
    }
// ... (delete logic remains same) ...
    if (isset($_POST['action']) && $_POST['action'] == 'delete_tag') {
        $tag_id = (int)$_POST['tag_id'];

        try {
            $stmt_check_events = $pdo->prepare("SELECT id FROM events WHERE tag_id = ? LIMIT 1");
            $stmt_check_events->execute([$tag_id]);
            
            $stmt_check_defaults = $pdo->prepare("SELECT id FROM default_assets WHERE tag_id = ? LIMIT 1");
            $stmt_check_defaults->execute([$tag_id]);
            
            $stmt_check_assets = $pdo->prepare("SELECT id FROM assets WHERE tag_id = ? LIMIT 1");
            $stmt_check_assets->execute([$tag_id]);

            if ($stmt_check_events->fetch() || $stmt_check_defaults->fetch() || $stmt_check_assets->fetch()) {
                $errors[] = "Cannot delete tag: It is currently assigned to events, assets, or defaults.";
            } else {
                $stmt_delete = $pdo->prepare("DELETE FROM tags WHERE id = ?");
                $stmt_delete->execute([$tag_id]);
                $success_message = "Tag deleted successfully.";
            }
        } catch (Exception $e) {
            $errors[] = "Error deleting tag: " . $e->getMessage();
        }
    }
}

try {
    $tag_stmt = $pdo->query("SELECT * FROM tags ORDER BY tag_name");
    $tags = $tag_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $errors[] = "Could not fetch tags: " . $e->getMessage();
    $tags = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Tags - WRHU Encoder Scheduler</title>
    <style>
        :root { --bg-color: #121212; --card-bg: #1e1e1e; --text-color: #e0e0e0; --accent-color: #bb86fc; --secondary-color: #03dac6; --error-color: #cf6679; --border-color: #333; }
        body { font-family: 'Inter', sans-serif; background: var(--bg-color); color: var(--text-color); margin: 0; padding: 2em; display: flex; flex-direction: column; min-height: 100vh; }
        .container { max-width: 1000px; margin: 0 auto; flex: 1; width: 100%; }
        a { color: var(--accent-color); text-decoration: none; }
        h1 { color: #fff; }
        
        .card { background: var(--card-bg); padding: 2em; border-radius: 8px; margin-bottom: 2em; box-shadow: 0 4px 6px rgba(0,0,0,0.3); }
        
        .form-group { margin-bottom: 1em; }
        label { display: block; margin-bottom: 0.5em; color: #aaa; }
        input { width: 100%; padding: 0.8em; background: #2c2c2c; border: 1px solid var(--border-color); color: #fff; border-radius: 4px; box-sizing: border-box; }
        
        button { padding: 0.8em 1.5em; background: var(--accent-color); color: #000; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; }
        .btn-delete { background: var(--error-color); color: #fff; padding: 0.5em 1em; }
        .btn-update { background: var(--secondary-color); color: #000; padding: 0.5em 1em; }
        
        table { width: 100%; border-collapse: collapse; background: var(--card-bg); border-radius: 8px; overflow: hidden; }
        th, td { padding: 1em; text-align: left; border-bottom: 1px solid var(--border-color); }
        th { background: #2c2c2c; }
        
        .message { padding: 1em; border-radius: 4px; margin-bottom: 1em; }
        .error { background: rgba(207, 102, 121, 0.2); border: 1px solid var(--error-color); color: var(--error-color); }
        .success { background: rgba(3, 218, 198, 0.2); border: 1px solid var(--secondary-color); color: var(--secondary-color); }
        
        footer { text-align: center; margin-top: 2em; color: #777; font-size: 0.9em; padding: 1em; border-top: 1px solid var(--border-color); }
    </style>
</head>
<body>

    <div class="container">
        <a href="index.php">&larr; Back to Dashboard</a>
        <h1>Manage Output Tags</h1>

        <?php if (!empty($errors)): ?>
            <div class="message error"><ul><?php foreach ($errors as $e) echo "<li>$e</li>"; ?></ul></div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="message success"><?php echo $success_message; ?></div>
        <?php endif; ?>

        <div class="card">
            <h2>Create New Tag</h2>
            <form action="manage_tags.php" method="POST" style="display:flex; gap:10px; align-items:flex-end;">
                <input type="hidden" name="action" value="create_tag">
                <div style="flex-grow:1;">
                    <label>Tag Name</label>
                    <input type="text" name="tag_name" placeholder="e.g. Main Screen" required>
                </div>
                <div style="width:150px;">
                    <label>Storage Limit (MB)</label>
                    <input type="number" name="storage_limit_mb" value="0" min="0">
                </div>
                <button type="submit">Create</button>
            </form>
        </div>

        <h2>Existing Tags</h2>
        <table>
            <thead>
                <tr>
                    <th>Tag Name</th>
                    <th>Storage Limit (MB)</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tags as $tag): ?>
                <tr>
                    <td><?php echo htmlspecialchars($tag['tag_name']); ?></td>
                    <td>
                        <form action="manage_tags.php" method="POST" style="display:flex; gap:10px;">
                            <input type="hidden" name="action" value="update_tag">
                            <input type="hidden" name="tag_id" value="<?php echo $tag['id']; ?>">
                            <input type="number" name="storage_limit_mb" value="<?php echo $tag['storage_limit_mb']; ?>" style="width:80px; padding:5px;">
                            <button type="submit" class="btn-update">Save</button>
                        </form>
                    </td>
                    <td>
                        <form action="manage_tags.php" method="POST" onsubmit="return confirm('Delete this tag?');">
                            <input type="hidden" name="action" value="delete_tag">
                            <input type="hidden" name="tag_id" value="<?php echo $tag['id']; ?>">
                            <button type="submit" class="btn-delete">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <footer>
        &copy;<?php echo date("Y") > 2025 ? "2025-" . date("Y") : "2025"; ?> WRHU Radio Hofstra University. Written by Gregory Pocali for WRHU.
    </footer>

</body>
</html>