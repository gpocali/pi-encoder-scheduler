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
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <?php include 'navbar.php'; ?>

    <div class="container">
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
                            <input type="number" name="storage_limit_mb" value="<?php echo $tag['storage_limit_mb']; ?>" style="width:80px; padding:5px; background:#2c2c2c; border:1px solid #333; color:#fff; border-radius:4px;">
                            <button type="submit" class="btn-secondary btn-sm">Save</button>
                        </form>
                    </td>
                    <td>
                        <form action="manage_tags.php" method="POST" onsubmit="return confirm('Delete this tag?');">
                            <input type="hidden" name="action" value="delete_tag">
                            <input type="hidden" name="tag_id" value="<?php echo $tag['id']; ?>">
                            <button type="submit" class="btn-delete btn-sm">Delete</button>
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