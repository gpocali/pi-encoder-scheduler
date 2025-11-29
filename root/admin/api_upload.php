<?php
require_once 'auth.php';
require_once '../db_connect.php';

header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] != "POST") {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$is_admin = is_admin();
$allowed_tag_ids = [];

// Fetch allowed tags
if ($is_admin || has_role('user')) {
    $stmt = $pdo->query("SELECT id FROM tags");
    $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $allowed_tag_ids = array_column($tags, 'id');
} else {
    $stmt = $pdo->prepare("SELECT tag_id FROM user_tags WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $allowed_tag_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$selected_tag_ids = $_POST['tag_ids'] ?? [];
$valid_tags = true;
foreach ($selected_tag_ids as $tid) {
    if (!in_array($tid, $allowed_tag_ids)) {
        $valid_tags = false;
        break;
    }
}

if (!$valid_tags) {
    echo json_encode(['success' => false, 'error' => 'Permission denied for one or more tags.']);
    exit;
}

if (!isset($_FILES['asset']) || $_FILES['asset']['error'] != UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'File upload failed.']);
    exit;
}

$asset = $_FILES['asset'];
$tmp_name = $asset['tmp_name'];
$md5 = md5_file($tmp_name);
$size_bytes = $asset['size'];

// Check Duplicate
$stmt_check = $pdo->prepare("SELECT id FROM assets WHERE md5_hash = ?");
$stmt_check->execute([$md5]);
if ($stmt_check->fetch()) {
    echo json_encode(['success' => false, 'error' => 'File already exists.']);
    exit;
}

// Check Quota
foreach ($selected_tag_ids as $tid) {
    $stmt_limit = $pdo->prepare("SELECT storage_limit_mb FROM tags WHERE id = ?");
    $stmt_limit->execute([$tid]);
    $limit_mb = $stmt_limit->fetchColumn();

    $stmt_usage = $pdo->prepare("SELECT SUM(size_bytes) FROM assets a JOIN asset_tags at ON a.id = at.asset_id WHERE at.tag_id = ?");
    $stmt_usage->execute([$tid]);
    $current_bytes = $stmt_usage->fetchColumn();
    $current_mb = $current_bytes / (1024 * 1024);
    $new_file_mb = $size_bytes / (1024 * 1024);

    if ($limit_mb > 0 && ($current_mb + $new_file_mb) > $limit_mb) {
        echo json_encode(['success' => false, 'error' => "Storage limit reached for tag ID $tid."]);
        exit;
    }
}

$original_filename = basename($asset['name']);
$mime_type = $asset['type'];
$safe_filename = uniqid('asset_', true) . '_' . preg_replace("/[^a-zA-Z0-9\._-]/", "", $original_filename);
$upload_dir = '/uploads/';
if (!is_dir($upload_dir))
    mkdir($upload_dir, 0755, true);
$upload_path = $upload_dir . $safe_filename;

if (move_uploaded_file($tmp_name, $upload_path)) {
    try {
        // Try with display_name
        $stmt_ins = $pdo->prepare("INSERT INTO assets (filename_disk, filename_original, display_name, mime_type, md5_hash, uploaded_by, size_bytes) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt_ins->execute([$safe_filename, $original_filename, $original_filename, $mime_type, $md5, $user_id, $size_bytes]);
        $asset_id = $pdo->lastInsertId();

        $stmt_at = $pdo->prepare("INSERT INTO asset_tags (asset_id, tag_id) VALUES (?, ?)");
        foreach ($selected_tag_ids as $tid) {
            $stmt_at->execute([$asset_id, $tid]);
        }

        echo json_encode(['success' => true, 'message' => 'Asset uploaded successfully.', 'asset_id' => $asset_id]);
    } catch (Exception $e) {
        // Fallback if display_name missing (Legacy Schema)
        if (strpos($e->getMessage(), "Unknown column 'display_name'") !== false) {
            $stmt_ins = $pdo->prepare("INSERT INTO assets (filename_disk, filename_original, mime_type, md5_hash, uploaded_by, size_bytes) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt_ins->execute([$safe_filename, $original_filename, $mime_type, $md5, $user_id, $size_bytes]);
            $asset_id = $pdo->lastInsertId();

            $stmt_at = $pdo->prepare("INSERT INTO asset_tags (asset_id, tag_id) VALUES (?, ?)");
            foreach ($selected_tag_ids as $tid) {
                $stmt_at->execute([$asset_id, $tid]);
            }
            echo json_encode(['success' => true, 'message' => 'Asset uploaded (legacy schema).', 'asset_id' => $asset_id]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        }
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to move uploaded file.']);
}
?>