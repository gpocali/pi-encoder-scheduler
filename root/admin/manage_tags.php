<?php
require_once 'auth.php'; 
require_once '../db_connect.php';

$errors = [];
$success_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    if (isset($_POST['action']) && $_POST['action'] == 'create_tag') {
        $tag_name = trim($_POST['tag_name']);

        if (empty($tag_name)) {
            $errors[] = "Tag name cannot be empty.";
        } else {
            $stmt_check = $pdo->prepare("SELECT id FROM tags WHERE tag_name = ?");
            $stmt_check->execute([$tag_name]);
            if ($stmt_check->fetch()) {
                $errors[] = "Tag '{$tag_name}' already exists.";
            } else {
                try {
                    $stmt_insert = $pdo->prepare("INSERT INTO tags (tag_name) VALUES (?)");
                    $stmt_insert->execute([$tag_name]);
                    $success_message = "Tag '{$tag_name}' created successfully!";
                } catch (Exception $e) {
                    $errors[] = "Error creating tag: " . $e->getMessage();
                }
            }
        }
    }

    if (isset($_POST['action']) && $_POST['action'] == 'delete_tag') {
        $tag_id = (int)$_POST['tag_id'];

        try {
            $stmt_check_events = $pdo->prepare("SELECT id FROM events WHERE tag_id = ? LIMIT 1");
            $stmt_check_events->execute([$tag_id]);
            
            $stmt_check_defaults = $pdo->prepare("SELECT id FROM default_assets WHERE tag_id = ? LIMIT 1");
            $stmt_check_defaults->execute([$tag_id]);

            if ($stmt_check_events->fetch() || $stmt_check_defaults->fetch()) {
                $errors[] = "Cannot delete tag: It is currently assigned to one or more events or as a default asset.";
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
    $tag_stmt = $pdo->query("SELECT id, tag_name FROM tags ORDER BY tag_name");
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Tags</title>
    <style>
        body { font-family: sans-serif; margin: 2em; background: #f4f4f4; }
        .container { max-width: 800px; margin: 0 auto; padding: 2em; background: #fff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { text-align: center; }
        .message { padding: 1em; border-radius: 4px; margin-bottom: 1em; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .nav-link { display: inline-block; margin-bottom: 1em; }
        
        .tag-form { display: flex; gap: 10px; margin-bottom: 2em; }
        .tag-form input[type="text"] { flex-grow: 1; padding: 0.8em; border: 1px solid #ccc; border-radius: 4px; }
        .tag-form button { padding: 0.8em 1.5em; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
        .tag-form button:hover { background-color: #0056b3; }
        
        .tag-table { width: 100%; border-collapse: collapse; }
        .tag-table th, .tag-table td { padding: 0.8em; border: 1px solid #ddd; text-align: left; }
        .tag-table th { background-color: #f9f9f9; }
        .tag-table td:last-child { width: 100px; text-align: center; }
        
        .btn-delete { background: #dc3545; color: white; border: none; padding: 0.5em 1em; border-radius: 4px; cursor: pointer; }
        .btn-delete:hover { background: #c82333; }
    </style>
</head>
<body>

    <div class="container">
        <a href="index.php" class="nav-link">&larr; Back to Dashboard</a>
        <h1>Manage Output Tags</h1>
        <p>Tags (e.g., "Lower Third", "Main Screen") are used to link events to specific outputs.</p>

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

        <form class="tag-form" action="manage_tags.php" method="POST">
            <input type="hidden" name="action" value="create_tag">
            <input type="text" name="tag_name" placeholder="Enter new tag name" required>
            <button type="submit">Create Tag</button>
        </form>

        <hr>

        <h2>Existing Tags</h2>
        <?php if (empty($tags)): ?>
            <p>No tags created yet.</p>
        <?php else: ?>
            <table class="tag-table">
                <thead>
                    <tr>
                        <th>Tag Name</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tags as $tag): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($tag['tag_name']); ?></td>
                        <td>
                            <form action="manage_tags.php" method="POST" onsubmit="return confirm('Are you sure you want to delete this tag?');">
                                <input type="hidden" name="action" value="delete_tag">
                                <input type="hidden" name="tag_id" value="<?php echo $tag['id']; ?>">
                                <button type="submit" class="btn-delete">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</body>
</html>