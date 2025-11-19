<?php
require_once 'auth.php';
require_once '../db_connect.php';

// Check permissions: Admin and Full User can manage all assets. Tag Editor can only manage their tags.
// For simplicity, let's allow all logged in users to view, but restrict actions based on role.
// Actually, the prompt says "full user level... access asset edit functions... tag editor level which can only edit assets... for tags which they have been assigned".
// So we need to filter the view for Tag Editors.

$user_id = $_SESSION['user_id'];
$is_admin = is_admin();
$is_full_user = has_role('user');
$is_tag_editor = has_role('tag_editor');

// Fetch allowed tags for the user
$allowed_tag_ids = [];
if ($is_admin || $is_full_user) {
    $stmt = $pdo->query("SELECT id FROM tags");
    $allowed_tag_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
} else {
    $stmt = $pdo->prepare("SELECT tag_id FROM user_tags WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $allowed_tag_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// If no tags assigned to tag editor, they can't do much.
if (empty($allowed_tag_ids) && $is_tag_editor) {
    $allowed_tag_ids = [0]; // Prevent SQL error with empty IN clause
}

$errors = [];
$success_message = '';

// Handle Upload
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'upload_asset') {
    $tag_id = (int)$_POST['tag_id'];
    
    if (!in_array($tag_id, $allowed_tag_ids)) {
        $errors[] = "You do not have permission to upload assets for this tag.";
    } elseif (!isset($_FILES['asset']) || $_FILES['asset']['error'] != UPLOAD_ERR_OK) {
        $errors[] = "File upload failed.";
    } else {
        $asset = $_FILES['asset'];
        $tmp_name = $asset['tmp_name'];
        $md5 = md5_file($tmp_name);
        
        // Check for duplicates
        $stmt_check = $pdo->prepare("SELECT id FROM assets WHERE md5_hash = ?");
        $stmt_check->execute([$md5]);
        if ($stmt_check->fetch()) {
            $errors[] = "This file has already been uploaded.";
        } else {
            // Check Quota
            // "cap on the amount of files that are stored per tag"
            // Let's get the limit for this tag
            $stmt_limit = $pdo->prepare("SELECT asset_limit FROM tags WHERE id = ?");
            $stmt_limit->execute([$tag_id]);
            $limit = $stmt_limit->fetchColumn();
            
            // Count current assets for this tag
            $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE tag_id = ?");
            $stmt_count->execute([$tag_id]);
            $current_count = $stmt_count->fetchColumn();
            
            if ($limit > 0 && $current_count >= $limit) {
                $errors[] = "Asset limit reached for this tag (Limit: $limit). Please delete old assets first.";
            } else {
                // Proceed with upload
                $original_filename = basename($asset['name']);
                $mime_type = $asset['type'];
                $size_bytes = $asset['size'];
                $safe_filename = uniqid('asset_', true) . '_' . preg_replace("/[^a-zA-Z0-9\._-]/", "", $original_filename);
                $upload_dir = '../uploads/'; // Adjusted path relative to admin/
                // Ensure dir exists
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                
                $upload_path = $upload_dir . $safe_filename;
                
                if (move_uploaded_file($tmp_name, $upload_path)) {
                    $stmt_ins = $pdo->prepare("INSERT INTO assets (filename_disk, filename_original, mime_type, md5_hash, tag_id, uploaded_by, size_bytes) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt_ins->execute([$safe_filename, $original_filename, $mime_type, $md5, $tag_id, $user_id, $size_bytes]);
                    $success_message = "Asset uploaded successfully.";
                } else {
                    $errors[] = "Failed to move uploaded file.";
                }
            }
        }
    }
}

// Handle Delete
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'delete_asset') {
    $asset_id = (int)$_POST['asset_id'];
    
    // Verify ownership/permission
    $stmt_get = $pdo->prepare("SELECT * FROM assets WHERE id = ?");
    $stmt_get->execute([$asset_id]);
    $asset_to_del = $stmt_get->fetch(PDO::FETCH_ASSOC);
    
    if ($asset_to_del && in_array($asset_to_del['tag_id'], $allowed_tag_ids)) {
        // Check if used in events or defaults
        $stmt_usage = $pdo->prepare("SELECT COUNT(*) FROM events WHERE asset_id = ?");
        $stmt_usage->execute([$asset_id]);
        $usage_count = $stmt_usage->fetchColumn();
        
        $stmt_def = $pdo->prepare("SELECT COUNT(*) FROM default_assets WHERE asset_id = ?");
        $stmt_def->execute([$asset_id]);
        $def_count = $stmt_def->fetchColumn();
        
        if ($usage_count > 0 || $def_count > 0) {
            $errors[] = "Cannot delete asset: It is currently in use by $usage_count events and $def_count defaults.";
        } else {
            // Delete file
            $file_path = '../uploads/' . $asset_to_del['filename_disk'];
            if (file_exists($file_path)) {
                unlink($file_path);
            }
            // Delete DB record
            $pdo->prepare("DELETE FROM assets WHERE id = ?")->execute([$asset_id]);
            $success_message = "Asset deleted.";
        }
    } else {
        $errors[] = "Asset not found or permission denied.";
    }
}

// Fetch Assets (Filtered)
// We need to handle the case where allowed_tag_ids is empty or has values
$in_clause = implode(',', array_fill(0, count($allowed_tag_ids), '?'));
$sql_assets = "
    SELECT a.*, t.tag_name, u.username 
    FROM assets a 
    LEFT JOIN tags t ON a.tag_id = t.id 
    LEFT JOIN users u ON a.uploaded_by = u.id 
    WHERE a.tag_id IN ($in_clause)
    ORDER BY a.created_at DESC
";
$stmt_assets = $pdo->prepare($sql_assets);
$stmt_assets->execute($allowed_tag_ids);
$assets = $stmt_assets->fetchAll(PDO::FETCH_ASSOC);

// Fetch Tags for Upload Dropdown
$sql_tags = "SELECT * FROM tags WHERE id IN ($in_clause) ORDER BY tag_name";
$stmt_tags = $pdo->prepare($sql_tags);
$stmt_tags->execute($allowed_tag_ids);
$available_tags = $stmt_tags->fetchAll(PDO::FETCH_ASSOC);

// Calculate Total Space Used
$total_space = 0;
foreach ($assets as $a) $total_space += $a['size_bytes'];
function formatBytes($bytes, $precision = 2) { 
    $units = array('B', 'KB', 'MB', 'GB', 'TB'); 
    $bytes = max($bytes, 0); 
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024)); 
    $pow = min($pow, count($units) - 1); 
    $bytes /= pow(1024, $pow); 
    return round($bytes, $precision) . ' ' . $units[$pow]; 
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Assets</title>
    <style>
        /* Reusing Dark Theme */
        :root {
            --bg-color: #121212;
            --card-bg: #1e1e1e;
            --text-color: #e0e0e0;
            --accent-color: #bb86fc;
            --secondary-color: #03dac6;
            --error-color: #cf6679;
            --border-color: #333;
        }
        body { font-family: 'Inter', sans-serif; background: var(--bg-color); color: var(--text-color); margin: 0; padding: 2em; }
        .container { max-width: 1200px; margin: 0 auto; }
        a { color: var(--accent-color); text-decoration: none; }
        h1, h2 { color: #fff; }
        
        .stats { display: flex; gap: 20px; margin-bottom: 2em; }
        .stat-card { background: var(--card-bg); padding: 1.5em; border-radius: 8px; flex: 1; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.3); }
        .stat-val { font-size: 2em; font-weight: bold; color: var(--secondary-color); }
        
        .upload-area { background: var(--card-bg); padding: 2em; border-radius: 8px; margin-bottom: 2em; border: 2px dashed var(--border-color); text-align: center; }
        .upload-area:hover { border-color: var(--accent-color); }
        
        .asset-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 20px; }
        .asset-card { background: var(--card-bg); border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.3); transition: transform 0.2s; }
        .asset-card:hover { transform: translateY(-5px); }
        .asset-thumb { width: 100%; height: 150px; object-fit: cover; background: #000; }
        .asset-info { padding: 1em; font-size: 0.9em; }
        .asset-meta { color: #888; margin-bottom: 0.5em; font-size: 0.8em; }
        
        .btn { padding: 0.5em 1em; background: var(--accent-color); color: #000; border: none; border-radius: 4px; cursor: pointer; }
        .btn-delete { background: var(--error-color); color: #fff; width: 100%; margin-top: 0.5em; }
        
        .message { padding: 1em; border-radius: 4px; margin-bottom: 1em; }
        .error { background: rgba(207, 102, 121, 0.2); border: 1px solid var(--error-color); color: var(--error-color); }
        .success { background: rgba(3, 218, 198, 0.2); border: 1px solid var(--secondary-color); color: var(--secondary-color); }
        
        select, input[type="file"] { padding: 0.8em; background: #2c2c2c; border: 1px solid var(--border-color); color: #fff; border-radius: 4px; margin-bottom: 10px; }
    </style>
</head>
<body>

    <div class="container">
        <a href="index.php">&larr; Back to Dashboard</a>
        <h1>Asset Management</h1>

        <?php if (!empty($errors)): ?>
            <div class="message error">
                <ul><?php foreach ($errors as $e) echo "<li>$e</li>"; ?></ul>
            </div>
        <?php endif; ?>
        
        <?php if ($success_message): ?>
            <div class="message success"><?php echo $success_message; ?></div>
        <?php endif; ?>

        <div class="stats">
            <div class="stat-card">
                <div class="stat-val"><?php echo count($assets); ?></div>
                <div>Total Assets</div>
            </div>
            <div class="stat-card">
                <div class="stat-val"><?php echo formatBytes($total_space); ?></div>
                <div>Total Space Used</div>
            </div>
        </div>

        <div class="upload-area">
            <h2>Upload New Asset</h2>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_asset">
                <select name="tag_id" required>
                    <option value="">-- Select Tag to Associate --</option>
                    <?php foreach ($available_tags as $tag): ?>
                        <option value="<?php echo $tag['id']; ?>"><?php echo htmlspecialchars($tag['tag_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <br>
                <input type="file" name="asset" required>
                <br>
                <button type="submit" class="btn">Upload Asset</button>
            </form>
        </div>

        <h2>Asset Library</h2>
        <div class="asset-grid">
            <?php foreach ($assets as $asset): ?>
                <div class="asset-card">
                    <?php 
                        $file_url = '../uploads/' . $asset['filename_disk'];
                        $is_image = strpos($asset['mime_type'], 'image') !== false;
                        $is_video = strpos($asset['mime_type'], 'video') !== false;
                    ?>
                    <?php if ($is_image): ?>
                        <img src="<?php echo $file_url; ?>" class="asset-thumb" alt="Asset">
                    <?php elseif ($is_video): ?>
                        <video src="<?php echo $file_url; ?>" class="asset-thumb"></video>
                    <?php else: ?>
                        <div class="asset-thumb" style="display:grid; place-items:center; color:#555;">No Preview</div>
                    <?php endif; ?>
                    
                    <div class="asset-info">
                        <div style="font-weight:bold; margin-bottom:5px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?php echo htmlspecialchars($asset['filename_original']); ?>">
                            <?php echo htmlspecialchars($asset['filename_original']); ?>
                        </div>
                        <div class="asset-meta">
                            Tag: <?php echo htmlspecialchars($asset['tag_name']); ?><br>
                            Size: <?php echo formatBytes($asset['size_bytes']); ?><br>
                            By: <?php echo htmlspecialchars($asset['username'] ?? 'Unknown'); ?><br>
                            Date: <?php echo date('M j, Y', strtotime($asset['created_at'])); ?>
                        </div>
                        <form method="POST" onsubmit="return confirm('Delete this asset? This cannot be undone.');">
                            <input type="hidden" name="action" value="delete_asset">
                            <input type="hidden" name="asset_id" value="<?php echo $asset['id']; ?>">
                            <button type="submit" class="btn btn-delete">Delete</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

</body>
</html>
