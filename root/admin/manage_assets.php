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
                    <?php echo count($assets); ?>
                </div>
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
                <div>Total Storage Used</div>
            </div>
            <div class="card"
                style="flex: 1; display:flex; align-items:center; justify-content:center; margin-bottom:0;">
                <form method="GET" style="width:100%;">
                    <select name="filter_tag" onchange="this.form.submit()" style="width:100%; padding:10px;">
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

        <div class="card">
            <h2>Upload New Asset</h2>
            <form method="POST" enctype="multipart/form-data" id="uploadForm">
                <input type="hidden" name="action" value="upload_asset">

                <div class="form-group">
                    <label>Select Tags (Optional)</label>
                    <div class="tag-toggle-group">
                        <?php foreach ($available_tags as $tag): ?>
                            <label class="tag-toggle" onclick="this.classList.toggle('active')">
                                <?php echo htmlspecialchars($tag['tag_name']); ?>
                                <input type="checkbox" name="tag_ids[]" value="<?php echo $tag['id']; ?>">
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label>Files</label>
                    <div id="drop-zone" class="drop-zone">
                        <p>Drag & Drop files here or click to select</p>
                        <div id="file-list" style="margin-top:10px; font-size:0.9em; color:#fff;"></div>
                    </div>
                    <input type="file" name="assets[]" id="file-input" multiple style="display:none;">
                </div>

                <button type="submit" class="btn">Upload Assets</button>
            </form>
        </div>

        <script>
            const dropZone = document.getElementById('drop-zone');
            const fileInput = document.getElementById('file-input');
            const fileList = document.getElementById('file-list');

            dropZone.onclick = () => fileInput.click();

            dropZone.ondragover = (e) => {
                e.preventDefault();
                dropZone.classList.add('dragover');
            };
            dropZone.ondragleave = () => dropZone.classList.remove('dragover');

            dropZone.ondrop = (e) => {
                e.preventDefault();
                dropZone.classList.remove('dragover');
                fileInput.files = e.dataTransfer.files;
                updateFileList();
            };

            fileInput.onchange = updateFileList;

            function updateFileList() {
                fileList.innerHTML = '';
                if (fileInput.files.length > 0) {
                    for (let i = 0; i < fileInput.files.length; i++) {
                        fileList.innerHTML += '<div>' + fileInput.files[i].name + '</div>';
                    }
                } else {
                    fileList.innerHTML = '';
                }
            }
        </script>

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