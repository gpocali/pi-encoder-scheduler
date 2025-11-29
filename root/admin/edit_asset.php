<?php
require_once 'auth.php';
require_once '../db_connect.php';

$user_id = $_SESSION['user_id'];
$is_admin = is_admin();
$allowed_tag_ids = [];

if ($is_admin || has_role('user')) {
    $stmt = $pdo->query("SELECT id FROM tags");
    $allowed_tag_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
} else {
    $stmt = $pdo->prepare("SELECT tag_id FROM user_tags WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $allowed_tag_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$asset_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$stmt = $pdo->prepare("SELECT * FROM assets WHERE id = ?");
$stmt->execute([$asset_id]);
$asset = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$asset) {
    die("Asset not found.");
}

// Permission Check
$stmt_tags = $pdo->prepare("SELECT tag_id FROM asset_tags WHERE asset_id = ?");
$stmt_tags->execute([$asset_id]);
$current_tag_ids = $stmt_tags->fetchAll(PDO::FETCH_COLUMN);

$has_permission = false;
if ($is_admin)
    $has_permission = true;
elseif ($asset['uploaded_by'] == $user_id)
    $has_permission = true;
else {
    foreach ($current_tag_ids as $tid) {
        if (in_array($tid, $allowed_tag_ids)) {
            $has_permission = true;
            break;
        }
    }
}

if (!$has_permission) {
    die("Permission denied.");
}

$errors = [];
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $display_name = trim($_POST['display_name']);
    $tag_ids = $_POST['tag_ids'] ?? [];

    if (empty($display_name)) {
        $errors[] = "Display Name is required.";
    }

    // Validate Tags
    foreach ($tag_ids as $tid) {
        if (!in_array($tid, $allowed_tag_ids)) {
            $errors[] = "You do not have permission for one or more selected tags.";
            break;
        }
    }

    if (empty($errors)) {
        $pdo->prepare("UPDATE assets SET display_name = ? WHERE id = ?")->execute([$display_name, $asset_id]);

        // Update Tags
        $pdo->prepare("DELETE FROM asset_tags WHERE asset_id = ?")->execute([$asset_id]);
        $stmt_ins = $pdo->prepare("INSERT INTO asset_tags (asset_id, tag_id) VALUES (?, ?)");
        foreach ($tag_ids as $tid) {
            $stmt_ins->execute([$asset_id, $tid]);
        }

        $success_message = "Asset updated.";
        // Refresh current tags
        $stmt_tags->execute([$asset_id]);
        $current_tag_ids = $stmt_tags->fetchAll(PDO::FETCH_COLUMN);
    }
}

// Fetch all tags for selection
$in_clause = implode(',', array_fill(0, count($allowed_tag_ids), '?'));
$stmt_all_tags = $pdo->prepare("SELECT * FROM tags WHERE id IN ($in_clause) ORDER BY tag_name");
$stmt_all_tags->execute($allowed_tag_ids);
$all_tags = $stmt_all_tags->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Edit Asset - WRHU Encoder Scheduler</title>
    <link rel="stylesheet" href="style.css">
</head>

<body>
    <?php include 'navbar.php'; ?>
    <div class="container">
        <h1>Edit Asset</h1>

        <?php if (!empty($errors)): ?>
            <div class="message error">
                <ul><?php foreach ($errors as $e)
                    echo "<li>$e</li>"; ?></ul>
            </div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="message success"><?php echo $success_message; ?></div>
        <?php endif; ?>

        <div class="card">
            <form method="POST">
                <div class="form-group">
                    <label>Original Filename (Read-only)</label>
                    <input type="text" value="<?php echo htmlspecialchars($asset['filename_original']); ?>" readonly
                        disabled style="opacity:0.7;">
                </div>
                <div class="form-group">
                    <label>Display Name</label>
                    <input type="text" name="display_name"
                        value="<?php echo htmlspecialchars($asset['display_name'] ?? $asset['filename_original']); ?>"
                        required>
                </div>

            <div class="form-group">
                <label>Tags</label>
                <div class="tag-toggle-group">
                    <?php foreach ($all_tags as $tag): ?>
                        <label class="tag-toggle <?php echo in_array($tag['id'], $current_tag_ids) ? 'active' : ''; ?>"
                            onclick="this.classList.toggle('active')">
                            <?php echo htmlspecialchars($tag['tag_name']); ?>
                            <input type="checkbox" name="tag_ids[]" value="<?php echo $tag['id']; ?>" <?php echo in_array($tag['id'], $current_tag_ids) ? 'checked' : ''; ?>>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="display:flex; gap:10px;">
                <button type="submit" class="btn">Save Changes</button>
                <a href="manage_assets.php" class="btn btn-secondary">Back to Assets</a>
            </div>
            </form>
        </div>
    </div>
</body>

</html>