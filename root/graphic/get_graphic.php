<?php
// Include your database connection
require_once '../db_connect.php';
date_default_timezone_set('America/New_York');

require_once '../admin/includes/EventRepository.php';

// 1. Get the requested tag from the URL
$tag_name = $_GET['tag'] ?? '';
if (empty($tag_name)) {
    http_response_code(400);
    die('ERROR: Tag parameter is missing.');
}

$repo = new EventRepository($pdo);

// Initialize variables
$asset_to_serve = null;
$target_asset_type = isset($_REQUEST['next']) ? 'next' : 'current';

if ($target_asset_type === 'next') {
    // Get Tag ID
    $stmt_tag = $pdo->prepare("SELECT id FROM tags WHERE tag_name = ?");
    $stmt_tag->execute([$tag_name]);
    $tag_id = $stmt_tag->fetchColumn();

    if ($tag_id) {
        $now_utc = gmdate('Y-m-d H:i:s');
        $future_utc = gmdate('Y-m-d H:i:s', strtotime('+1 year'));

        // Get all events starting from now
        $events = $repo->getEvents($now_utc, $future_utc, $tag_id);

        // Filter for start_time > now (getEvents includes overlaps, so might include current)
        $next_event = null;
        foreach ($events as $ev) {
            if ($ev['start_time'] > $now_utc) {
                $next_event = $ev;
                break; // Events are sorted by start time
            }
        }

        if ($next_event) {
            // Fetch asset details
            $stmt_a = $pdo->prepare("SELECT filename_disk, mime_type, md5_hash, filename_original FROM assets WHERE id = ?");
            $stmt_a->execute([$next_event['asset_id']]);
            $asset_to_serve = $stmt_a->fetch(PDO::FETCH_ASSOC);
        }
    }

    // Default fallback
    if (!$asset_to_serve) {
        $sql_default = "
            SELECT a.filename_disk, a.mime_type, a.md5_hash, a.filename_original
            FROM default_assets da
            JOIN assets a ON da.asset_id = a.id
            JOIN tags t ON da.tag_id = t.id
            WHERE t.tag_name = ?
        ";
        $stmt_default = $pdo->prepare($sql_default);
        $stmt_default->execute([$tag_name]);
        $asset_to_serve = $stmt_default->fetch(PDO::FETCH_ASSOC);
    }

} else {
    // Current
    $active_event = $repo->getCurrentEvent($tag_name);

    if ($active_event) {
        $asset_to_serve = $active_event; // getCurrentEvent returns asset details
    } else {
        // Default
        $sql_default = "
            SELECT a.filename_disk, a.mime_type, a.md5_hash, a.filename_original
            FROM default_assets da
            JOIN assets a ON da.asset_id = a.id
            JOIN tags t ON da.tag_id = t.id
            WHERE t.tag_name = ?
        ";
        $stmt_default = $pdo->prepare($sql_default);
        $stmt_default->execute([$tag_name]);
        $asset_to_serve = $stmt_default->fetch(PDO::FETCH_ASSOC);
    }
}

// 6. Serve the file or hash if an asset was found.
if ($asset_to_serve) {
    // Ensure the file path is correct for your environment.
    $file_path = '/uploads/' . $asset_to_serve['filename_disk'];

    if (file_exists($file_path)) {
        if (isset($_REQUEST['md5_hash'])) {
            echo $asset_to_serve['md5_hash'];
            exit;
        } else {
            // Serve the file
            // Use the actual mime type if possible, or fallback to octet-stream if forced download is needed.
            // User asked to match dashboard previews, which display the image.
            // But this script might be used by an encoder that expects a download.
            // Let's use the actual mime type but add Content-Disposition inline if it's viewable, or attachment if not?
            // Actually, the previous code used application/octet-stream.
            // I will switch to the real MIME type to be safe for "previews" but keep Content-Disposition.

            $mime_type = $asset_to_serve['mime_type'] ?: 'application/octet-stream';

            header('Content-Type: ' . $mime_type);
            header('Content-Disposition: inline; filename="' . $asset_to_serve['filename_original'] . '"');
            header('Content-Length: ' . filesize($file_path));
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');

            ob_clean();
            flush();
            readfile($file_path);
            exit;
        }
    }
}

// 7. Failsafe: If no asset is found (no default exists, no events scheduled), return a 404
http_response_code(404);
die('ERROR: No ' . $target_asset_type . ' asset found for tag: ' . htmlspecialchars($tag_name) . ' and no default set.');
?>