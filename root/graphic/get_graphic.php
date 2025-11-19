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

// 2. Get the current time in UTC (best for scheduling)
$now = new DateTime('now', new DateTimeZone('America/New_York'));
$now_formatted = $now->format('Y-m-d H:i:s');

// 3. Find an *active, scheduled event* for this tag
$sql = "
    SELECT a.filename_disk, a.mime_type, a.md5_hash
    FROM events e
    JOIN assets a ON e.asset_id = a.id
    JOIN tags t ON e.tag_id = t.id
    WHERE t.tag_name = ? 
      AND ? BETWEEN e.start_time AND e.end_time
    ORDER BY e.start_time DESC 
    LIMIT 1
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$tag_name, $now_formatted]);
$active_event_asset = $stmt->fetch(PDO::FETCH_ASSOC);


// Assuming $pdo and $tag_name are already defined and properly sanitized/validated.

$asset_to_serve = null;
$target_asset_type = isset($_REQUEST['next']) ? 'next' : 'current';

if ($target_asset_type === 'next') {
    // Logic to find the *next scheduled asset* for this tag.
    // Note: The table name for scheduled events was changed from 'scheduled_events' to 'events'
    // to match the table used in the top part of your script.
    $sql_target = "
        SELECT a.filename_disk, a.mime_type, a.md5_hash
        FROM events se
        JOIN assets a ON se.asset_id = a.id
        JOIN tags t ON se.tag_id = t.id
        WHERE t.tag_name = ? AND se.start_time > NOW()
        ORDER BY se.start_time ASC
        LIMIT 1
    ";
    $stmt_target = $pdo->prepare($sql_target);
    $stmt_target->execute([$tag_name]);
    $asset_to_serve = $stmt_target->fetch(PDO::FETCH_ASSOC);

} else {
    // Logic to find the *current active or default asset*.

    // First, check for an active event (which was retrieved in the top part of the script)
    if ($active_event_asset) {
        $asset_to_serve = $active_event_asset;
    } else {
        // If no active event, find the *default asset* for this tag.
        $sql_target = "
            SELECT a.filename_disk, a.mime_type, a.md5_hash
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
    // You might need a full path, e.g., $_SERVER['DOCUMENT_ROOT'] . '/uploads/' . ...
    $file_path = '/uploads/' . $asset_to_serve['filename_disk']; 

    if (file_exists($file_path)) {
        if(isset($_REQUEST['md5_hash'])){
            echo $asset_to_serve['md5_hash'];
            exit;
        } else {
            // Serve the file as an octet-stream
            $extension = pathinfo(basename($file_path), PATHINFO_EXTENSION);
            // Construct a meaningful filename for download
            $download_filename = strtolower($tag_name) . ($target_asset_type === 'next' ? '_next' : '') . "." . $extension;

            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $download_filename . '"');
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

// 7. Failsafe: If no asset is found, return a 404
http_response_code(404);
die('ERROR: No ' . $target_asset_type . ' asset found for tag: ' . htmlspecialchars($tag_name));
?>