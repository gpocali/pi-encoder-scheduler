<?php
// root/graphic/get_image.php
require_once '../db_connect.php';
require_once '../admin/includes/EventRepository.php';

// Set Headers
header("Access-Control-Allow-Origin: *");
header("Cache-Control: no-cache, must-revalidate"); // We control caching manually via logic, but browser should recheck

$tag_name = $_GET['stream'] ?? '';

if (empty($tag_name)) {
    http_response_code(400);
    die("Error: 'stream' parameter (tag) is required.");
}

$repo = new EventRepository($pdo);

// 1. Resolve Asset (Matches get_graphic.php logic)
// We only care about 'current' asset for the player background
$active_event = $repo->getCurrentEvent($tag_name);
$asset = null;

if ($active_event) {
    // Current event found
    $asset = $active_event;
} else {
    // Check Default Asset
    $stmt = $pdo->prepare("
        SELECT a.filename_disk, a.mime_type, a.md5_hash, a.filename_original, a.id
        FROM default_assets da
        JOIN assets a ON da.asset_id = a.id
        JOIN tags t ON da.tag_id = t.id
        WHERE t.tag_name = ?
    ");
    $stmt->execute([$tag_name]);
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$asset) {
    http_response_code(404);
    die("Error: No live event or default asset found for stream '$tag_name'.");
}

// 2. Prepare Cache Paths
$upload_dir = '/uploads'; // Absolute path as used in api_upload.php and get_graphic.php
$cache_dir = $upload_dir . '/cache';

if (!is_dir($cache_dir)) {
    if (!mkdir($cache_dir, 0755, true)) {
        error_log("Failed to create cache directory: $cache_dir");
        // Proceeding might fail if we cant write, but we can try serving original as fallback?
        // User requested 1920x1080 specific output.
    }
}

$source_file = $upload_dir . '/' . $asset['filename_disk'];

if (!file_exists($source_file)) {
    http_response_code(404);
    die("Error: Asset file not found on server.");
}

// Cache Key: MD5 + Resolution + Method. 
// If the asset changes (new upload), it gets a new MD5/Filename usually? 
// Current system uses uniqid filenames, so filename is unique. 
// Use original md5_hash from DB if available, else file md5.
$hash = $asset['md5_hash'] ?? md5_file($source_file);
$cache_filename = "{$hash}_1080p.jpg";
$cache_path = $cache_dir . '/' . $cache_filename;

// 3. Serve Cache if Exists
if (file_exists($cache_path)) {
    serve_image($cache_path);
    exit;
}

// 4. Generate Image if Missing
$mime = $asset['mime_type'] ?? mime_content_type($source_file);
$is_video = strpos($mime, 'video') === 0;

if ($is_video) {
    if (!generate_video_snapshot($source_file, $cache_path)) {
        // Fallback or Error
        http_response_code(500);
        die("Error: Failed to generate video snapshot.");
    }
} else {
    // Image
    if (!generate_image_snapshot($source_file, $cache_path)) {
        // Fallback: serve original if image? But request was specific size.
        // Let's try to serve original as last resort if safe?
        // No, stay strict.
        http_response_code(500);
        die("Error: Failed to generate image snapshot.");
    }
}

// 5. Serve Generated Image
serve_image($cache_path);

// --- Functions ---

function serve_image($path)
{
    $last_mod = filemtime($path);
    $etag = md5($path . $last_mod);

    header("Content-Type: image/jpeg");
    header("Content-Length: " . filesize($path));
    header("Last-Modified: " . gmdate("D, d M Y H:i:s", $last_mod) . " GMT");
    header("ETag: \"$etag\"");

    readfile($path);
}

function generate_video_snapshot($input, $output)
{
    // ffmpeg -i input -ss 1 -vframes 1 -vf "scale=1920:1080:force_original_aspect_ratio=decrease,pad=1920:1080:(ow-iw)/2:(oh-ih)/2" output
    // force_original_aspect_ratio=decrease ensures it fits within 1920x1080
    // pad fills the rest with black to make it exactly 1920x1080

    $cmd_input = escapeshellarg($input);
    $cmd_output = escapeshellarg($output);

    // Check if ffmpeg exists
    // We assume it's in PATH.

    $filter = "scale=1920:1080:force_original_aspect_ratio=decrease,pad=1920:1080:(ow-iw)/2:(oh-ih)/2";
    $cmd = "ffmpeg -y -i $cmd_input -ss 00:00:01 -vframes 1 -vf \"$filter\" -q:v 2 $cmd_output 2>&1";

    exec($cmd, $out, $ret);

    if ($ret !== 0 || !file_exists($output)) {
        error_log("ffmpeg failed: " . implode("\n", $out));
        return false;
    }
    return true;
}

function generate_image_snapshot($input, $output)
{
    // Load
    $info = getimagesize($input);
    $mime = $info['mime'];

    switch ($mime) {
        case 'image/jpeg':
            $src = imagecreatefromjpeg($input);
            break;
        case 'image/png':
            $src = imagecreatefrompng($input);
            break;
        case 'image/gif':
            $src = imagecreatefromgif($input);
            break;
        case 'image/webp':
            $src = imagecreatefromwebp($input);
            break;
        default:
            return false;
    }

    if (!$src)
        return false;

    $width = imagesx($src);
    $height = imagesy($src);

    // Target
    $tw = 1920;
    $th = 1080;

    // Calculate aspect ratio
    $src_ratio = $width / $height;
    $dst_ratio = $tw / $th;

    $new_w = $tw;
    $new_h = $th;
    $dst_x = 0;
    $dst_y = 0;

    // "Fit" logic (Letterbox)
    if ($src_ratio > $dst_ratio) {
        // Wider than target: conform width, shrink height
        $new_h = $tw / $src_ratio;
        $dst_y = ($th - $new_h) / 2;
    } else {
        // Taller than target: conform height, shrink width
        $new_w = $th * $src_ratio;
        $dst_x = ($tw - $new_w) / 2;
    }

    $dst = imagecreatetruecolor($tw, $th);
    $black = imagecolorallocate($dst, 0, 0, 0);
    imagefill($dst, 0, 0, $black);

    imagecopyresampled($dst, $src, (int) $dst_x, (int) $dst_y, 0, 0, (int) $new_w, (int) $new_h, $width, $height);

    // Save as JPEG
    $result = imagejpeg($dst, $output, 90);

    imagedestroy($src);
    imagedestroy($dst);

    return $result;
}
?>