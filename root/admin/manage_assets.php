<?php
require_once 'auth.php';
require_once '../db_connect.php';

$user_id = $_SESSION['user_id'];
$is_admin = is_admin();
$is_full_user = has_role('user');
$is_tag_editor = has_role('tag_editor');

// Fetch allowed tags
$allowed_tag_ids = [];
if ($is_admin || $is_full_user) {
    $stmt = $pdo->query("SELECT id FROM tags");
    $allowed_tag_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
} else {
    $stmt = $pdo->prepare("SELECT tag_id FROM user_tags WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $allowed_tag_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
}
if (empty($allowed_tag_ids) && $is_tag_editor)
    $allowed_tag_ids = [0];

$errors = [];
$success_message = '';

// Handle Scan & Index (Admin Only)
if ($is_admin && isset($_POST['action']) && $_POST['action'] == 'scan_index') {
    $upload_dir = '/uploads/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    if (is_dir($upload_dir)) {
        $files = scandir($upload_dir);
        $count_added = 0;
        foreach ($files as $file) {
            if ($file === '.' || $file === '..')
                continue;
            $filepath = $upload_dir . $file;
            if (is_file($filepath)) {
                $md5 = md5_file($filepath);
                // Check if exists
                $stmt_check = $pdo->prepare("SELECT id FROM assets WHERE md5_hash = ?");
                $stmt_check->execute([$md5]);
                if (!$stmt_check->fetch()) {
                    // Insert
                    $mime_type = mime_content_type($filepath);
                    $size = filesize($filepath);
                    // Try to guess original filename from disk name if it has prefix
                    // Format: asset_UNIQUEID_ORIGINAL
                    $parts = explode('_', $file, 3);
                    $original_name = count($parts) >= 3 ? $parts[2] : $file;

                    $stmt_ins = $pdo->prepare("INSERT INTO assets (filename_disk, filename_original, mime_type, md5_hash, uploaded_by, size_bytes, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                    $stmt_ins->execute([$file, $original_name, $mime_type, $md5, $user_id, $size]);
                    $count_added++;
                }
            }
        }
        $success_message = "Scan complete. Indexed $count_added new assets.";
    } else {
        $errors[] = "Could not create or access upload directory.";
    }
}

// Handle Upload
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'upload_asset') {
    $selected_tag_ids = $_POST['tag_ids'] ?? [];

    $valid_tags = true;
    foreach ($selected_tag_ids as $tid) {
        if (!in_array($tid, $allowed_tag_ids)) {
            $valid_tags = false;
            break;
        }
    }

    if (!$valid_tags) {
        $errors[] = "You do not have permission to upload assets for one or more selected tags.";
    } elseif (!isset($_FILES['asset']) || $_FILES['asset']['error'] != UPLOAD_ERR_OK) {
        $errors[] = "File upload failed.";
    } else {
        $asset = $_FILES['asset'];
        $tmp_name = $asset['tmp_name'];
        $md5 = md5_file($tmp_name);
        $size_bytes = $asset['size'];

        $stmt_check = $pdo->prepare("SELECT id FROM assets WHERE md5_hash = ?");
        $stmt_check->execute([$md5]);
        if ($stmt_check->fetch()) {
            $errors[] = "This file has already been uploaded.";
        } else {
            // Check Storage Quota (MB)
            foreach ($selected_tag_ids as $tid) {
                $stmt_limit = $pdo->prepare("SELECT storage_limit_mb FROM tags WHERE id = ?");
                $stmt_limit->execute([$tid]);
                $limit_mb = $stmt_limit->fetchColumn();

                // Calculate current usage
                $stmt_usage = $pdo->prepare("SELECT SUM(size_bytes) FROM assets a JOIN asset_tags at ON a.id = at.asset_id WHERE at.tag_id = ?");
                $stmt_usage->execute([$tid]);
                $current_bytes = $stmt_usage->fetchColumn();
                $current_mb = $current_bytes / (1024 * 1024);

                $new_file_mb = $size_bytes / (1024 * 1024);

                if ($limit_mb > 0 && ($current_mb + $new_file_mb) > $limit_mb) {
                    $errors[] = "Storage limit reached for tag ID $tid (Limit: {$limit_mb} MB). Current usage: " . round($current_mb, 2) . " MB.";
                    break;
                }
            }

            if (empty($errors)) {
                $original_filename = basename($asset['name']);
                $mime_type = $asset['type'];
                $safe_filename = uniqid('asset_', true) . '_' . preg_replace("/[^a-zA-Z0-9\._-]/", "", $original_filename);
                $upload_dir = '/uploads/';
                if (!is_dir($upload_dir))
                    mkdir($upload_dir, 0755, true);

                $upload_path = $upload_dir . $safe_filename;

                if (move_uploaded_file($tmp_name, $upload_path)) {
                    $stmt_ins = $pdo->prepare("INSERT INTO assets (filename_disk, filename_original, mime_type, md5_hash, uploaded_by, size_bytes) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt_ins->execute([$safe_filename, $original_filename, $mime_type, $md5, $user_id, $size_bytes]);
                    $asset_id = $pdo->lastInsertId();

                    // Link tags
                    $stmt_at = $pdo->prepare("INSERT INTO asset_tags (asset_id, tag_id) VALUES (?, ?)");
                    foreach ($selected_tag_ids as $tid) {
                        $stmt_at->execute([$asset_id, $tid]);
                    }

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
    $asset_id = (int) $_POST['asset_id'];
    $stmt_get = $pdo->prepare("SELECT * FROM assets WHERE id = ?");
    $stmt_get->execute([$asset_id]);
    $asset_to_del = $stmt_get->fetch(PDO::FETCH_ASSOC);

    // Allow delete if user has permission for ANY of the asset's tags OR if asset has NO tags and user is admin
    $can_delete = false;
    if ($asset_to_del) {
        $stmt_tags = $pdo->prepare("SELECT tag_id FROM asset_tags WHERE asset_id = ?");
        $stmt_tags->execute([$asset_id]);
        $asset_tags = $stmt_tags->fetchAll(PDO::FETCH_COLUMN);

        if (empty($asset_tags)) {
            if ($is_admin)
                $can_delete = true;
        } else {
            foreach ($asset_tags as $tid) {
                if (in_array($tid, $allowed_tag_ids)) {
                    $can_delete = true;
                    break;
                }
            }
        }
    }

    if ($can_delete) {
        $blocking_reasons = [];

        // Check Default Assets
        $stmt_def = $pdo->prepare("SELECT t.tag_name FROM default_assets da JOIN tags t ON da.tag_id = t.id WHERE da.asset_id = ?");
        $stmt_def->execute([$asset_id]);
        $def_tags = $stmt_def->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($def_tags)) {
            foreach ($def_tags as $tag_name) {
                $blocking_reasons[] = "Set as default asset for tag: " . htmlspecialchars($tag_name);
            }
        }

        // Check Future/Current Events (Compare against UTC)
        $now_utc = gmdate('Y-m-d H:i:s');
        $stmt_usage = $pdo->prepare("SELECT event_name, start_time FROM events WHERE asset_id = ? AND end_time > ?");
        $stmt_usage->execute([$asset_id, $now_utc]);
        $active_events = $stmt_usage->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($active_events)) {
            foreach ($active_events as $ev) {
                // Convert UTC start time to local for display
                $start_local = (new DateTime($ev['start_time'], new DateTimeZone('UTC')))
                    ->setTimezone(new DateTimeZone('America/New_York'))
                    ->format('M j, Y H:i');
                $blocking_reasons[] = "Used in event: " . htmlspecialchars($ev['event_name']) . " (" . $start_local . ")";
            }
        }

        if (!empty($blocking_reasons)) {
            $errors[] = "Cannot delete asset. It is currently in use by the following:<br><ul><li>" . implode("</li><li>", $blocking_reasons) . "</li></ul>";
        } else {
            // Set asset_id to NULL for past events to satisfy FK constraint
            $pdo->prepare("UPDATE events SET asset_id = NULL WHERE asset_id = ?")->execute([$asset_id]);

            $file_path = '/uploads/' . $asset_to_del['filename_disk'];
            if (file_exists($file_path))
                unlink($file_path);

            // Delete from DB
            $pdo->prepare("DELETE FROM assets WHERE id = ?")->execute([$asset_id]);
            $success_message = "Asset deleted successfully.";
        }
    } else {
        $errors[] = "You do not have permission to delete this asset.";
    }
}

// Fetch Assets
$filter_tag_id = isset($_GET['filter_tag']) ? $_GET['filter_tag'] : null;
$sql_assets = "SELECT a.*, u.username, 
               (SELECT GROUP_CONCAT(t.tag_name SEPARATOR ', ') FROM default_assets da JOIN tags t ON da.tag_id = t.id WHERE da.asset_id = a.id) as default_for_tags
               FROM assets a 
               LEFT JOIN users u ON a.uploaded_by = u.id 
               LEFT JOIN asset_tags at ON a.id = at.asset_id
               WHERE 1=1";
$params = [];

// Filter by allowed tags
if (!$is_admin && !empty($allowed_tag_ids)) {
    $in_clause = implode(',', array_fill(0, count($allowed_tag_ids), '?'));
    $sql_assets .= " AND (a.uploaded_by = ? OR at.tag_id IN ($in_clause))";
    $params[] = $user_id;
    $params = array_merge($params, $allowed_tag_ids);
} elseif (!$is_admin && empty($allowed_tag_ids)) {
    $sql_assets .= " AND a.uploaded_by = ?";
    $params[] = $user_id;
}

if ($filter_tag_id) {
    $sql_assets .= " AND at.tag_id = ?";
    $params[] = $filter_tag_id;
}

$sql_assets .= " GROUP BY a.id ORDER BY a.created_at DESC";

$stmt_assets = $pdo->prepare($sql_assets);
$stmt_assets->execute($params);
$assets = $stmt_assets->fetchAll(PDO::FETCH_ASSOC);

// Fetch Tags
$in_clause = implode(',', array_fill(0, count($allowed_tag_ids), '?'));
$sql_tags = "SELECT * FROM tags WHERE id IN ($in_clause) ORDER BY tag_name";
$stmt_tags = $pdo->prepare($sql_tags);
$stmt_tags->execute($allowed_tag_ids);
$available_tags = $stmt_tags->fetchAll(PDO::FETCH_ASSOC);

$total_space = 0;
foreach ($assets as $a)
    $total_space += $a['size_bytes'];
function formatBytes($bytes, $precision = 2)
{
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
    <title>Manage Assets - WRHU Encoder Scheduler</title>
    <link rel="stylesheet" href="style.css">
</head>

<body>
    <?php include 'navbar.php'; ?>
    <div class="container">
        <h1>Manage Assets</h1>
        <?php if (!empty($errors)): ?>
            <div class="message error">
                <ul><?php foreach ($errors as $e)
                    echo "<li>$e</li>"; ?></ul>
            </div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="message success"><?php echo $success_message; ?></div>
        <?php endif; ?>

        <div class="stats" style="display: flex; gap: 20px; margin-bottom: 2em;">
            <div class="card" style="flex: 1; text-align: center; margin-bottom:0;">
                <div style="font-size: 2em; font-weight: bold; color: var(--secondary-color);">
                    <?php echo count($assets); ?></div>
                <div>Total Assets</div>
            </div>
            <div class="card" style="flex: 1; text-align: center; margin-bottom:0;">
                <div style="font-size: 2em; font-weight: bold; color: var(--secondary-color);">
                    <?php
                    if ($filter_tag_id) {
                        $stmt_limit = $pdo->prepare("SELECT storage_limit_mb FROM tags WHERE id = ?");
                        $stmt_limit->execute([$filter_tag_id]);
                        $limit_mb = $stmt_limit->fetchColumn();
                        if ($limit_mb > 0) {
                            $percent = ($total_space / ($limit_mb * 1024 * 1024)) * 100;
                            echo round($percent, 1) . '%';
                        } else {
                            echo formatBytes($total_space);
                        }
                    } else {
                        echo formatBytes($total_space);
                    }
                    ?>
                </div>
                <div>Total Space Used <?php echo $filter_tag_id && $limit_mb > 0 ? "(of Limit)" : ""; ?></div>
            </div>
        </div>

        <div class="card">
            <h2>Upload New Asset</h2>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_asset">
                <div class="form-group">
                    <label>Select Tags to Associate (Hold Ctrl/Cmd to select multiple)</label>
                    <select name="tag_ids[]" multiple required style="height: 100px;">
                        <?php foreach ($available_tags as $tag): ?>
                            <option value="<?php echo $tag['id']; ?>"><?php echo htmlspecialchars($tag['tag_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>File</label>
                    <input type="file" name="asset" required>
                </div>
                <button type="submit">Upload Asset</button>
            </form>
        </div>

        <div class="controls"
            style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1em; flex-wrap: wrap; gap: 10px;">
            <h2 style="margin:0;">Asset Library</h2>
            <form class="filters" method="GET" style="display:flex; gap:10px; align-items:center;">
                <select name="filter_tag" onchange="this.form.submit()" style="padding:5px;">
                    <option value="">All Tags</option>
                    <?php foreach ($available_tags as $tag): ?>
                        <option value="<?php echo $tag['id']; ?>" <?php if ($filter_tag_id == $tag['id'])
                               echo 'selected'; ?>>
                            <?php echo htmlspecialchars($tag['tag_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 20px;">
            <?php foreach ($assets as $asset): ?>
                <div class="card" style="padding: 1em; margin-bottom: 0;">
                    <?php
                    $file_url = 'serve_asset.php?id=' . $asset['id'];
                    $is_image = strpos($asset['mime_type'], 'image') !== false;
                    $is_video = strpos($asset['mime_type'], 'video') !== false;
                    ?>
                    <div onclick="showPreview('<?php echo $file_url; ?>', '<?php echo $asset['mime_type']; ?>')"
                        style="aspect-ratio: 16/9; width: 100%; background: #000; display: flex; align-items: center; justify-content: center; margin-bottom: 10px; overflow: hidden; border-radius: 4px; cursor: pointer;">
                        <?php if ($is_image): ?>
                            <img src="<?php echo $file_url; ?>" style="width: 100%; height: 100%; object-fit: cover;">
                        <?php elseif ($is_video): ?>
                            <video src="<?php echo $file_url; ?>" style="width: 100%; height: 100%; object-fit: cover;"></video>
                        <?php else: ?>
                            <span style="color: #777;">No Preview</span>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($asset['default_for_tags'])): ?>
                        <div style="font-size: 0.8em; color: var(--accent-color); font-weight: bold; margin-bottom: 5px;">
                            Default: <?php echo htmlspecialchars($asset['default_for_tags']); ?>
                        </div>
                    <?php endif; ?>

                    <div style="font-weight: bold; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 5px;"
                        title="<?php echo htmlspecialchars($asset['display_name'] ?? $asset['filename_original']); ?>">
                        <?php echo htmlspecialchars($asset['display_name'] ?? $asset['filename_original']); ?>
                    </div>

                    <div style="font-size: 0.8em; color: #aaa; margin-bottom: 10px;">
                        Size: <?php echo formatBytes($asset['size_bytes']); ?><br>
                        <?php
                        // Fetch tags for this asset
                        $stmt_at = $pdo->prepare("SELECT t.tag_name FROM asset_tags at JOIN tags t ON at.asset_id = ? AND at.tag_id = t.id");
                        $stmt_at->execute([$asset['id']]);
                        $at_names = $stmt_at->fetchAll(PDO::FETCH_COLUMN);
                        $tag_display = empty($at_names) ? 'None' : implode(', ', $at_names);
                        ?>
                        Tags: <?php echo htmlspecialchars($tag_display); ?><br>
                        By: <?php echo htmlspecialchars($asset['username'] ?? 'System'); ?><br>
                        Date: <?php echo date('M j, Y', strtotime($asset['created_at'])); ?>
                    </div>

                    <div style="display:flex; gap:10px;">
                        <a href="edit_asset.php?id=<?php echo $asset['id']; ?>" class="btn btn-sm btn-secondary"
                            style="flex:1; text-align:center;">Edit</a>
                        <?php if (($is_admin || $asset['uploaded_by'] == $_SESSION['user_id']) && empty($asset['default_for_tags'])): ?>
                            <form method="POST" onsubmit="return confirm('Delete this asset? This cannot be undone.');"
                                style="flex:1;">
                                <input type="hidden" name="action" value="delete_asset">
                                <input type="hidden" name="asset_id" value="<?php echo $asset['id']; ?>">
                                <button type="submit" class="btn-delete btn-sm" style="width: 100%;">Delete</button>
                            </form>
                        <?php else: ?>
                            <button class="btn-delete btn-sm" style="flex:1; opacity:0.5; cursor:not-allowed;"
                                disabled>Delete</button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <footer>
        &copy;<?php echo date("Y") > 2025 ? "2025-" . date("Y") : "2025"; ?> WRHU Radio Hofstra University. Written by
        Gregory Pocali for WRHU with assistance from Google Gemini 3.
    </footer>

    <!-- Large Preview Modal -->
    <div id="previewModal" class="modal" onclick="this.style.display='none'">
        <div class="modal-content preview-modal-content">
            <span class="close">&times;</span>
            <div id="previewContainer"></div>
        </div>
    </div>
    <script>
        function showPreview(url, type) {
            const container = document.getElementById('previewContainer');
            container.innerHTML = '';
            if (type.includes('image')) {
                container.innerHTML = '<img src="' + url + '" class="preview-media">';
            } else if (type.includes('video')) {
                container.innerHTML = '<video src="' + url + '" controls autoplay class="preview-media"></video>';
            }
            document.getElementById('previewModal').style.display = 'block';
        }
    </script>
</body>

</html>