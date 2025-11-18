<?php
// Include your database connection
require_once '../db_connect.php'; 

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
    SELECT a.filename_disk, a.mime_type
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

$asset_to_serve = null;

if ($active_event_asset) {
    // 4. Found an active event! Use this asset.
    $asset_to_serve = $active_event_asset;
} else {
    // 5. No active event. Find the *default asset* for this tag.
    $sql_default = "
        SELECT a.filename_disk, a.mime_type
        FROM default_assets da
        JOIN assets a ON da.asset_id = a.id
        JOIN tags t ON da.tag_id = t.id
        WHERE t.tag_name = ?
    ";
    $stmt_default = $pdo->prepare($sql_default);
    $stmt_default->execute([$tag_name]);
    $default_asset = $stmt_default->fetch(PDO::FETCH_ASSOC);
    
    if ($default_asset) {
        $asset_to_serve = $default_asset;
    }
}

// 6. Serve the file as an octet-stream
if ($asset_to_serve) {
    $file_path = '/uploads/' . $asset_to_serve['filename_disk'];

    if (file_exists($file_path)) {
		if(isset($_REQUEST['md5_hash'])){
			echo $asset_to_serve['md5_hash'];
		} else {
			$extension = pathinfo(basename($file_path), PATHINFO_EXTENSION);
			// Set headers as requested
			header('Content-Type: application/octet-stream');
			header('Content-Disposition: attachment; filename="' . strtolower($_REQUEST['tag']) . "." . $extension . '"');
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
die('ERROR: No active or default asset found for tag: ' . htmlspecialchars($tag_name));
?>