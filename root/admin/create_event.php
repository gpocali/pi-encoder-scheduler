<?php
require_once 'auth.php'; 
require_once '../db_connect.php'; 

$errors = [];
$success_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    $event_name = trim($_POST['event_name']);
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $tag_id = (int)$_POST['tag_id'];

    if (empty($event_name)) $errors[] = "Event name is required.";
    if (empty($start_time)) $errors[] = "Start time is required.";
    if (empty($end_time)) $errors[] = "End time is required.";
    if (empty($tag_id)) $errors[] = "Tag is required.";

    if ($start_time && $end_time && (new DateTime($start_time) >= new DateTime($end_time))) {
        $errors[] = "End time must be after the start time.";
    }

    if (!isset($_FILES['asset']) || $_FILES['asset']['error'] != UPLOAD_ERR_OK) {
        $errors[] = "Asset file upload failed or no file was selected.";
    }

    $asset = $_FILES['asset'];
    $original_filename = basename($asset['name']);
    $mime_type = $asset['type'];
    
    $safe_filename = uniqid('asset_', true) . '_' . preg_replace("/[^a-zA-Z0-9\._-]/", "", $original_filename);
    $upload_dir = __DIR__ . '/../uploads/'; 
    $upload_path = $upload_dir . $safe_filename;

    if (empty($errors)) {
        
        $pdo->beginTransaction();
        
        try {
            if (!move_uploaded_file($asset['tmp_name'], $upload_path)) {
                throw new Exception("Failed to move uploaded file. Check permissions on /uploads/ folder.");
            }

            $sql_asset = "INSERT INTO assets (filename_disk, filename_original, mime_type) VALUES (?, ?, ?)";
            $stmt_asset = $pdo->prepare($sql_asset);
            $stmt_asset->execute([$safe_filename, $original_filename, $mime_type]);
            
            $new_asset_id = $pdo->lastInsertId();

            $start_time_utc = (new DateTime($start_time))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $end_time_utc = (new DateTime($end_time))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            
            $sql_event = "INSERT INTO events (event_name, start_time, end_time, asset_id, tag_id) VALUES (?, ?, ?, ?, ?)";
            $stmt_event = $pdo->prepare($sql_event);
            $stmt_event->execute([$event_name, $start_time_utc, $end_time_utc, $new_asset_id, $tag_id]);

            $pdo->commit();
            $success_message = "Event '$event_name' created successfully!";

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Database error: " . $e->getMessage();
            
            if (file_exists($upload_path)) {
                unlink($upload_path);
            }
        }
    }
}

try {
    $tag_stmt = $pdo->query("SELECT id, tag_name FROM tags ORDER BY tag_name");
    $tags = $tag_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $errors[] = "Could not fetch tags: " . $e->getMessage();
    $tags = []; 
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New Event</title>
    <style>
        body { font-family: sans-serif; margin: 2em; background: #f4f4f4; }
        .container { max-width: 600px; margin: 0 auto; padding: 2em; background: #fff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { text-align: center; }
        .form-group { margin-bottom: 1.5em; }
        .form-group label { display: block; margin-bottom: 0.5em; font-weight: bold; }
        .form-group input,
        .form-group select { width: 100%; padding: 0.8em; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px; }
        .btn { display: block; width: 100%; padding: 1em; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 1em; }
        .btn:hover { background-color: #0056b3; }
        .message { padding: 1em; border-radius: 4px; margin-bottom: 1em; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .nav-link { display: inline-block; margin-bottom: 1em; }
    </style>
</head>
<body>

    <div class="container">
        <a href="index.php" class="nav-link">&larr; Back to Dashboard</a>
        <h1>Create New Event</h1>

        <?php if (!empty($errors)): ?>
            <div class="message error">
                <strong>Error!</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($success_message): ?>
            <div class="message success">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <form action="create_event.php" method="POST" enctype="multipart/form-data">
            
            <div class="form-group">
                <label for="event_name">Event Name</label>
                <input type="text" id="event_name" name="event_name" required>
            </div>

            <div class="form-group">
                <label for="start_time">Start Time</label>
                <input type="datetime-local" id="start_time" name="start_time" required>
            </div>

            <div class="form-group">
                <label for="end_time">End Time</label>
                <input type="datetime-local" id="end_time" name="end_time" required>
            </div>

            <div class="form-group">
                <label for="tag_id">Output Tag</label>
                <select id="tag_id" name="tag_id" required>
                    <option value="">-- Select a Tag --</option>
                    <?php foreach ($tags as $tag): ?>
                        <option value="<?php echo $tag['id']; ?>">
                            <?php echo htmlspecialchars($tag['tag_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="asset">Graphic Asset (Image, Video, etc.)</label>
                <input type="file" id="asset" name="asset" required>
            </div>

            <button typea="submit" class="btn">Create Event</button>

        </form>
    </div>

</body>
</html>