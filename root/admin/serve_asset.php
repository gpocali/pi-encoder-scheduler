<?php
require_once 'auth.php';
require_once '../db_connect.php';

// Allow access to logged-in users
if (!isset($_SESSION['user_id'])) {
    header("HTTP/1.1 403 Forbidden");
    exit;
}

if (!isset($_GET['id'])) {
    header("HTTP/1.1 400 Bad Request");
    exit;
}

$asset_id = (int)$_GET['id'];

$stmt = $pdo->prepare("SELECT filename_disk, mime_type, filename_original FROM assets WHERE id = ?");
$stmt->execute([$asset_id]);
$asset = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$asset) {
    header("HTTP/1.1 404 Not Found");
    exit;
}

$file_path = '/uploads/' . $asset['filename_disk'];

if (!file_exists($file_path)) {
    header("HTTP/1.1 404 Not Found");
    echo "File not found.";
    exit;
}

// Handle caching
$etag = md5_file($file_path);
$last_modified = filemtime($file_path);

header("Last-Modified: " . gmdate("D, d M Y H:i:s", $last_modified) . " GMT");
header("Etag: $etag");

if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) == $etag) {
    header("HTTP/1.1 304 Not Modified");
    exit;
}

// Send headers
header("Content-Type: " . $asset['mime_type']);
header("Content-Length: " . filesize($file_path));
header("Content-Disposition: inline; filename=\"" . $asset['filename_original'] . "\"");

// Output file
readfile($file_path);
?>
