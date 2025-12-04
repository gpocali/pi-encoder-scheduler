<?php
require_once 'auth.php';
require_once '../db_connect.php';
$user_id = $_SESSION['user_id'];
$is_admin = is_admin();
$is_full_user = has_role('user');
$is_tag_editor = has_role('tag_editor');

// Initialize variables
$errors = [];
$filter_tag_id = isset($_GET['filter_tag']) ? $_GET['filter_tag'] : null;

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'delete_asset') {
        $asset_id = $_POST['asset_id'];

        // Fetch asset to check permissions and get filename
        $stmt = $pdo->prepare("SELECT * FROM assets WHERE id = ?");
        $stmt->execute([$asset_id]);
        $asset = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($asset) {
            // Check if default asset
            if (!empty($asset['default_for_tags'])) {
                $errors[] = "Cannot delete asset: It is set as a default asset for tags: " . htmlspecialchars($asset['default_for_tags']);
            }
            // Check permissions
            elseif ($is_admin || $asset['uploaded_by'] == $user_id) {
                // Delete file
                $file_path = '/uploads/' . $asset['filename_disk'];
                if (file_exists($file_path)) {
                    unlink($file_path);
                }

                // Delete from DB
                try {
                    $stmt = $pdo->prepare("DELETE FROM assets WHERE id = ?");
                    $stmt->execute([$asset_id]);
                    $success_message = "Asset deleted successfully.";
                } catch (PDOException $e) {
                    $errors[] = "Cannot delete asset: It might be in use by scheduled events.";
                }
            } else {
                $errors[] = "You do not have permission to delete this asset.";
            }
        } else {
            $errors[] = "Asset not found.";
        }
    }
}

$sql_assets = "SELECT a.*, u.username, at.tag_id, 
               GROUP_CONCAT(DISTINCT t_def.tag_name SEPARATOR ', ') as default_for_tags
               FROM assets a 
               LEFT JOIN users u ON a.uploaded_by = u.id 
               LEFT JOIN asset_tags at ON a.id = at.asset_id 
               LEFT JOIN default_assets da ON a.id = da.asset_id
               LEFT JOIN tags t_def ON da.tag_id = t_def.id
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

// Fetch future/active events for all assets
$asset_ids = array_column($assets, 'id');
$asset_events = [];

if (!empty($asset_ids)) {
    $in_ids = implode(',', array_fill(0, count($asset_ids), '?'));

    // 1. Single Events (future or active)
    // "occur now or in the future" => end_time >= NOW()
    $sql_single = "SELECT asset_id, event_name, start_time 
                   FROM events 
                   WHERE asset_id IN ($in_ids) 
                   AND end_time >= NOW()";

    // 2. Recurring Events (active series)
    // "occur now or in the future" => end_date IS NULL OR end_date >= CURDATE()
    $sql_recur = "SELECT asset_id, event_name, start_time 
                  FROM recurring_events 
                  WHERE asset_id IN ($in_ids) 
                  AND (end_date IS NULL OR end_date >= CURDATE())";

    $stmt_single = $pdo->prepare($sql_single);
    $stmt_single->execute($asset_ids);
    $single_events = $stmt_single->fetchAll(PDO::FETCH_ASSOC);

    $stmt_recur = $pdo->prepare($sql_recur);
    $stmt_recur->execute($asset_ids);
    $recur_events = $stmt_recur->fetchAll(PDO::FETCH_ASSOC);

    // Group by asset_id
    foreach ($single_events as $ev) {
        $asset_events[$ev['asset_id']][] = $ev['event_name'] . " (Single)";
    }
    foreach ($recur_events as $ev) {
        $asset_events[$ev['asset_id']][] = $ev['event_name'] . " (Recurring)";
    }
}

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
                <h3 style="margin-top:0; margin-bottom:10px; font-size:1.1em; text-align:center;">Storage Usage per Tag
                </h3>
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
                    </table>
                </div>
            </div>

            <div class="card" style="flex: 1; display:flex; flex-direction:column; padding: 1em; margin-bottom:0;">
                <h3 style="margin-top:0; margin-bottom:10px; font-size:1.1em; text-align:center;">Filter by Tag</h3>
                <form method="GET" style="width:100%;">
                    <select name="filter_tag" onchange="this.form.submit()"
                        style="width:100%; padding:10px; background:#333; color:#fff; border:1px solid #444; border-radius:4px;">
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
        </div>

        <!-- Search Bar -->
        <div style="margin-bottom: 20px;">
            <input type="text" id="assetSearch" placeholder="Search assets by name, tag, or assigned event..."
                style="width: 100%; padding: 10px; font-size: 1em; background: #333; color: #fff; border: 1px solid #444; border-radius: 4px;">
        </div>


        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 20px;">
            <?php foreach ($assets as $asset): 
                $my_events = $asset_events[$asset['id']] ?? [];
                $has_future_events = !empty($my_events);
                $is_default = !empty($asset['default_for_tags']);
                $events_list_str = implode(', ', $my_events);
                
                $is_protected = $has_future_events || $is_default;
                $protection_reasons = [];
                if ($is_default) $protection_reasons[] = "Default Asset (" . $asset['default_for_tags'] . ")";
                if ($has_future_events) $protection_reasons[] = "Assigned to " . $events_list_str;
                $protection_tooltip = "Cannot delete: " . implode('; ', $protection_reasons);

                // Fetch tags for this asset (moved up for data attribute)
                $stmt_at = $pdo->prepare("SELECT t.tag_name FROM asset_tags at JOIN tags t ON at.asset_id = ? AND at.tag_id = t.id");
                $stmt_at->execute([$asset['id']]);
                $at_names = $stmt_at->fetchAll(PDO::FETCH_COLUMN);
                $tag_display = empty($at_names) ? 'None' : implode(', ', $at_names);
                ?>
                <div class="card asset-card"
                    data-name="<?php echo htmlspecialchars(strtolower($asset['display_name'] ?? $asset['filename_original'])); ?>"
                    data-tags="<?php echo htmlspecialchars(strtolower($tag_display)); ?>"
                    data-events="<?php echo htmlspecialchars(strtolower($events_list_str)); ?>"
                    style="padding: 1em; margin-bottom: 0; display: flex; flex-direction: column; height: 100%; box-sizing: border-box;">
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
                        Tags: <?php echo htmlspecialchars($tag_display); ?><br>
                        <?php if ($has_future_events): ?>
                            <div style="color: var(--warning-color); font-size: 0.9em; margin-top: 4px; line-height: 1.2;">
                                <strong>In Use:</strong> <?php echo htmlspecialchars($events_list_str); ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($is_default): ?>
                            <div style="color: var(--secondary-color); font-size: 0.9em; margin-top: 4px; line-height: 1.2;">
                                <strong>Default Asset</strong>
                            </div>
                        <?php endif; ?>
                        By: <?php echo htmlspecialchars($asset['username'] ?? 'System'); ?><br>
                        Date: <?php echo date('M j, Y', strtotime($asset['created_at'])); ?>
                    </div>

                    <div style="display:flex; gap:10px; margin-top: auto;">
                        <a href="edit_asset.php?id=<?php echo $asset['id']; ?>" class="btn btn-sm btn-secondary"
                            style="flex:1; text-align:center;">Edit</a>

                        <?php if ($is_protected): ?>
                            <button class="btn btn-sm btn-danger" disabled
                                title="<?php echo htmlspecialchars($protection_tooltip); ?>"
                                style="flex:1; opacity: 0.5; cursor: not-allowed;">Delete</button>
                        <?php else: ?>
                            <form method="POST" style="flex:1;"
                                onsubmit="return confirm('Are you sure you want to delete this asset?');">
                                <input type="hidden" name="action" value="delete_asset">
                                <input type="hidden" name="asset_id" value="<?php echo $asset['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger" style="width:100%;">Delete</button>
                            </form>
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
        window.onclick = function (event) {
            if (event.target == document.getElementById('previewModal')) {
                document.getElementById('previewModal').style.display = 'none';
            }
        }

        // Preview Logic
        function showPreview(url, type) {
            const container = document.getElementById('previewContainer');
            container.innerHTML = '<div id="previewLoader" style="color: #fff; font-size: 1.2em; padding: 20px;">Loading preview...</div>';
            document.getElementById('previewModal').style.display = 'block';

            let mediaElement;
            if (type.includes('image')) {
                mediaElement = new Image();
                mediaElement.src = url;
                mediaElement.className = 'preview-media';
                mediaElement.style.display = 'none';
                mediaElement.onload = function () {
                    const loader = document.getElementById('previewLoader');
                    if (loader) loader.remove();
                    this.style.display = 'block';
                };
            } else if (type.includes('video')) {
                mediaElement = document.createElement('video');
                mediaElement.src = url;
                mediaElement.controls = true;
                mediaElement.autoplay = true;
                mediaElement.className = 'preview-media';
                mediaElement.style.display = 'none';
                mediaElement.onloadeddata = function () {
                    const loader = document.getElementById('previewLoader');
                    if (loader) loader.remove();
                    this.style.display = 'block';
                };
            }

            if (mediaElement) {
                container.appendChild(mediaElement);
            }
        }

        // Search Logic
        document.getElementById('assetSearch').addEventListener('keyup', function () {
            const query = this.value.toLowerCase();
            const cards = document.querySelectorAll('.asset-card');

            cards.forEach(card => {
                const name = card.getAttribute('data-name');
                const tags = card.getAttribute('data-tags');
                const events = card.getAttribute('data-events');

                if (name.includes(query) || tags.includes(query) || events.includes(query)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        });
    </script>
</body>

</html>