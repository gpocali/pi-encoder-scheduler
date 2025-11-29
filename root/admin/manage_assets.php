<?php
require_once 'auth.php';
require_once '../db_connect.php';

$user_id = $_SESSION['user_id'];
$is_admin = is_admin();
$is_full_user = has_role('user');
$is_tag_editor = has_role('tag_editor');

// Initialize variables
$filter_tag_id = isset($_GET['filter_tag']) ? $_GET['filter_tag'] : null;
$sql_assets = "SELECT a.*, u.username, at.tag_id 
               FROM assets a 
               LEFT JOIN users u ON a.uploaded_by = u.id 
               LEFT JOIN asset_tags at ON a.id = at.asset_id 
               WHERE 1=1";
$params = [];

// Fetch allowed tags
$allowed_tag_ids = [];
if ($is_admin || $is_full_user) {
    // Admin/Full User sees all tags
} elseif ($is_tag_editor) {
    // Tag Editor: View assets they uploaded OR assets with tags they are assigned to
    $stmt = $pdo->prepare("SELECT tag_id FROM user_tags WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $allowed_tag_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($allowed_tag_ids)) {
        $in_clause = implode(',', array_fill(0, count($allowed_tag_ids), '?'));
        $sql_assets .= " AND (a.uploaded_by = ? OR at.tag_id IN ($in_clause))";
        $params[] = $user_id;
        $params = array_merge($params, $allowed_tag_ids);
    } else {
        $sql_assets .= " AND a.uploaded_by = ?";
        $params[] = $user_id;
    }
} else {
    // Regular user (restricted)
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

// Fetch Tags for Filter/Upload
if ($is_admin || $is_full_user) {
    $stmt_tags = $pdo->query("SELECT * FROM tags ORDER BY tag_name");
    $available_tags = $stmt_tags->fetchAll(PDO::FETCH_ASSOC);
} else {
    if (!empty($allowed_tag_ids)) {
        $in_clause = implode(',', array_fill(0, count($allowed_tag_ids), '?'));
        $sql_tags = "SELECT * FROM tags WHERE id IN ($in_clause) ORDER BY tag_name";
        $stmt_tags = $pdo->prepare($sql_tags);
        $stmt_tags->execute($allowed_tag_ids);
        $available_tags = $stmt_tags->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $available_tags = [];
    }
}

$total_space = 0;
foreach ($assets as $a)
    $total_space += $a['size_bytes'];

if (!function_exists('formatBytes')) {
    function formatBytes($bytes, $precision = 2)
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
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
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
            <h1 style="margin:0;">Manage Assets</h1>
        </div>

        <?php if (!empty($errors)): ?>
                <div class="message error">
                    <ul><?php foreach ($errors as $e)
                        echo "<li>$e</li>"; ?></ul>
                </div>
        <?php endif; ?>
        <?php if (isset($success_message) && $success_message): ?>
                <div class="message success"><?php echo $success_message; ?></div>
        <?php endif; ?>

        <div class="stats" style="display: flex; gap: 20px; margin-bottom: 2em;">
            <div class="card" style="flex: 1; text-align: center; margin-bottom:0;">
                <div style="font-size: 2em; font-weight: bold; color: var(--secondary-color);">
                    <?php echo count($assets); ?>
                </div>
                <div>Total Assets</div>
            </div>
            
            <div class="card" style="flex: 2; margin-bottom:0; padding: 1em; display: flex; flex-direction: column;">
                <h3 style="margin-top:0; margin-bottom:10px; font-size:1.1em; text-align:center;">Storage Usage per Tag</h3>
                <div style="flex: 1; overflow-y: auto; max-height: 120px;">
                    <table style="width:100%; font-size:0.9em; border-collapse: collapse;">
                        <?php
                        // Calculate usage per tag
                        $sql_usage = "SELECT t.id, t.tag_name, t.storage_limit_mb, COALESCE(SUM(a.size_bytes), 0) as used_bytes 
                                      FROM tags t 
                                      LEFT JOIN asset_tags at ON t.id = at.tag_id 
                                      LEFT JOIN assets a ON at.asset_id = a.id 
                                      GROUP BY t.id 
                                      ORDER BY t.tag_name";
                        $stmt_usage = $pdo->query($sql_usage);
                        $usage_data = $stmt_usage->fetchAll(PDO::FETCH_ASSOC);

                        // Filter by available tags
                        $available_tag_ids = array_column($available_tags, 'id');

                        foreach ($usage_data as $row):
                            if (!in_array($row['id'], $available_tag_ids))
                                continue;

                            $limit_bytes = $row['storage_limit_mb'] * 1024 * 1024;
                            $used = $row['used_bytes'];
                            $percent = ($limit_bytes > 0) ? round(($used / $limit_bytes) * 100, 1) : 0;

                            // Color coding for usage
                            $color = 'var(--secondary-color)';
                            if ($limit_bytes > 0) {
                                if ($percent >= 90)
                                    $color = 'var(--error-color)';
                                elseif ($percent >= 70)
                                    $color = 'orange';
                            }
                            ?>
                            <tr style="border-bottom: 1px solid #333;">
                                <td style="padding: 5px;"><?php echo htmlspecialchars($row['tag_name']); ?></td>
                                <td style="padding: 5px; text-align:right; font-weight:bold; color:<?php echo $color; ?>;">
                                    <?php if ($limit_bytes > 0): ?>
                                            <?php echo $percent; ?>%
                                    <?php else: ?>
                                            <span style="color:#aaa; font-weight:normal;">Unlimited</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 5px; text-align:right; color:#777; font-size:0.85em;">
                                    <?php echo formatBytes($used); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
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
        window.onclick = function(event) {
            if (event.target == document.getElementById('previewModal')) {
                 document.getElementById('previewModal').style.display = 'none';
            }
        }

        // Preview Logic
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