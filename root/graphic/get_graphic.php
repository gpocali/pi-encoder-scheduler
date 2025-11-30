<?php
// Include your database connection
require_once '../db_connect.php';
date_default_timezone_set('America/New_York');

// 1. Get the requested tag from the URL
$tag_name = $_GET['tag'] ?? '';
if (empty($tag_name)) {
    http_response_code(400);
    die('ERROR: Tag parameter is missing.');
}

// 2. Get the current time in UTC (Database stores times in UTC)
$now_utc = (new DateTime())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

// 3. Find an *active, scheduled event* for this tag
// Logic matches dashboard: Priority DESC, Start Time ASC
$sql = "
    SELECT a.filename_disk, a.mime_type, a.md5_hash, a.filename_original
    FROM events e
    JOIN event_tags et ON e.id = et.event_id
    JOIN assets a ON e.asset_id = a.id
    JOIN tags t ON et.tag_id = t.id
    WHERE t.tag_name = ? 
      AND e.start_time <= ? AND e.end_time > ?
    ORDER BY e.priority DESC, e.start_time ASC 
    LIMIT 1
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$tag_name, $now_utc, $now_utc]);
$active_event_asset = $stmt->fetch(PDO::FETCH_ASSOC);


// Initialize variables
$asset_to_serve = null;
$target_asset_type = isset($_REQUEST['next']) ? 'next' : 'current';

if ($target_asset_type === 'next') {
    // Logic to find the *next scheduled asset* for this tag.
    // This logic wasn't explicitly requested to match dashboard (dashboard doesn't show 'next'), 
    // but we should update it to use UTC and Priority if applicable, or just Start Time.
    // Usually 'next' means the one starting soonest after now.

    $sql_target = "
        SELECT a.filename_disk, a.mime_type, a.md5_hash, a.filename_original
        FROM events se
        JOIN event_tags et ON se.id = et.event_id
        JOIN assets a ON se.asset_id = a.id
        JOIN tags t ON et.tag_id = t.id
        WHERE t.tag_name = ? AND se.start_time > ?
        ORDER BY se.start_time ASC
        LIMIT 1
    ";
    $stmt_target = $pdo->prepare($sql_target);
    $stmt_target->execute([$tag_name, $now_utc]);
    $asset_to_serve = $stmt_target->fetch(PDO::FETCH_ASSOC);

    // If no future event is found, fall back to the default asset
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
    // Logic to find the *current active or default asset*.

    if ($active_event_asset) {
        $asset_to_serve = $active_event_asset;
    } else {
        // If no active event, find the *default asset* for this tag.
        $sql_target = "
            SELECT a.filename_disk, a.mime_type, a.md5_hash, a.filename_original
            FROM default_assets da
            JOIN assets a ON da.asset_id = a.id
            JOIN tags t ON da.tag_id = t.id
            WHERE t.tag_name = ?
        ";
        $stmt_target = $pdo->prepare($sql_target);
        $stmt_target->execute([$tag_name]);
        $asset_to_serve = $stmt_target->fetch(PDO::FETCH_ASSOC);
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