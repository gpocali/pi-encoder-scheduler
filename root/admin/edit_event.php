<?php
require_once 'auth.php'; 
require_once '../db_connect.php';
date_default_timezone_set('America/New_York');

$errors = [];
$success_message = '';
$event = null;
$is_read_only = false;

$event_id = $_GET['id'] ?? null;
if (empty($event_id)) {
    header("Location: index.php");
    exit;
}

try {
    $sql_fetch = "
        SELECT e.*, a.filename_original 
        FROM events e
        LEFT JOIN assets a ON e.asset_id = a.id
        WHERE e.id = ?
    ";
    $stmt_fetch = $pdo->prepare($sql_fetch);
    $stmt_fetch->execute([$event_id]);
    $event = $stmt_fetch->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        header("Location: index.php");
        exit;
    }

    $end_time = new DateTime($event['end_time'], new DateTimeZone('America/New_York'));
    $now = new DateTime('now', new DateTimeZone('America/New_York'));
    
    if ($end_time < $now) {
        $is_read_only = true;
    }

} catch (Exception $e) {
    $errors[] = "Error fetching event: " . $e->getMessage();
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && !$is_read_only) {
    
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

    $new_asset_id = $event['asset_id']; 
    $new_asset_filename = $event['filename_original'];
    $upload_path = null;

    if (isset($_FILES['asset']) && $_FILES['asset']['error'] == UPLOAD_ERR_OK) {
        $asset = $_FILES['asset'];
        $original_filename = basename($asset['name']);
        $mime_type = $asset['type'];
        
        $safe_filename = uniqid('asset_', true) . '_' . preg_replace("/[^a-zA-Z0-9\._-]/", "", $original_filename);
        $upload_dir = '/uploads/';
        $upload_path = $upload_dir . $safe_filename;
        
        if (empty($errors)) {
            $pdo->beginTransaction();
            try {
                if (!move_uploaded_file($asset['tmp_name'], $upload_path)) {
                    throw new Exception("Failed to move uploaded file.");
                }

                $sql_asset = "INSERT INTO assets (filename_disk, filename_original, mime_type) VALUES (?, ?, ?)";
                $stmt_asset = $pdo->prepare($sql_asset);
                $stmt_asset->execute([$safe_filename, $original_filename, $mime_type]);
                
                $new_asset_id = $pdo->lastInsertId(); 
                $new_asset_filename = $original_filename;
                
                $pdo->commit(); 
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = "Error processing new asset: " . $e->getMessage();
                if ($upload_path && file_exists($upload_path)) {
                    unlink($upload_path); 
                }
            }
        }
    }

    if (empty($errors)) {
        try {
            $start_time_utc = (new DateTime($start_time))->setTimezone(new DateTimeZone('America/New_York'))->format('Y-m-d H:i:s');
            $end_time_utc = (new DateTime($end_time))->setTimezone(new DateTimeZone('America/New_York'))->format('Y-m-d H:i:s');

            $sql_update = "UPDATE events 
                           SET event_name = ?, start_time = ?, end_time = ?, asset_id = ?, tag_id = ?
                           WHERE id = ?";
            $stmt_update = $pdo->prepare($sql_update);
            $stmt_update->execute([$event_name, $start_time_utc, $end_time_utc, $new_asset_id, $tag_id, $event_id]);
            
            $success_message = "Event '$event_name' updated successfully!";
            
            $stmt_fetch->execute([$event_id]);
            $event = $stmt_fetch->fetch(PDO::FETCH_ASSOC);
            $event['filename_original'] = $new_asset_filename; 

        } catch (Exception $e) {
            $errors[] = "Error updating event: " . $e->getMessage();
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

function format_utc_for_local_input($utc_datetime_str) {
    if (empty($utc_datetime_str)) return '';
    $dt = new DateTime($utc_datetime_str, new DateTimeZone('America/New_York'));
    $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
    return $dt->format('Y-m-d\TH:i');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Event</title>
    <style>
        body { font-family: sans-serif; margin: 2em; background: #f4f4f4; }
        .container { max-width: 600px; margin: 0 auto; padding: 2em; background: #fff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { text-align: center; }
        .form-group { margin-bottom: 1.5em; }
        .form-group label { display: block; margin-bottom: 0.5em; font-weight: bold; }
        .form-group input,
        .form-group select { width: 100%; padding: 0.8em; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px; }
        .form-group input:disabled,
        .form-group select:disabled { background: #eee; cursor: not-allowed; }
        .btn { display: block; width: 100%; padding: 1em; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 1em; }
        .btn:hover { background-color: #0056b3; }
        .btn:disabled { background: #aaa; }
        .message { padding: 1em; border-radius: 4px; margin-bottom: 1em; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .warning { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .nav-link { display: inline-block; margin-bottom: 1em; }
        .current-asset { font-size: 0.9em; color: #555; }
    </style>
</head>
<body>

    <div class="container">
        <a href="index.php" class="nav-link">&larr; Back to Dashboard</a>
        <h1>Edit Event</h1>

        <?php if ($is_read_only): ?>
            <div class="message warning">
                <strong>Read-Only:</strong> This event has already occurred and cannot be modified.
            </div>
        <?php endif; ?>

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

        <?php if ($event): ?>
        <form action="edit_event.php?id=<?php echo $event_id; ?>" method="POST" enctype="multipart/form-data">
            
            <div class="form-group">
                <label for="event_name">Event Name</label>
                <input type="text" id="event_name" name="event_name" required
                       value="<?php echo htmlspecialchars($event['event_name']); ?>"
                       <?php if ($is_read_only) echo 'disabled'; ?>>
            </div>

            <div class="form-group">
                <label for="start_time">Start Time</label>
                <input type="datetime-local" id="start_time" name="start_time" required
                       value="<?php echo format_utc_for_local_input($event['start_time']); ?>"
                       <?php if ($is_read_only) echo 'disabled'; ?>>
            </div>

            <div class="form-group">
                <label for="end_time">End Time</label>
                <input type="datetime-local" id="end_time" name="end_time" required
                       value="<?php echo format_utc_for_local_input($event['end_time']); ?>"
                       <?php if ($is_read_only) echo 'disabled'; ?>>
            </div>

            <div class="form-group">
                <label for="tag_id">Output Tag</label>
                <select id="tag_id" name="tag_id" required <?php if ($is_read_only) echo 'disabled'; ?>>
                    <option value="">-- Select a Tag --</option>
                    <?php foreach ($tags as $tag): ?>
                        <option value="<?php echo $tag['id']; ?>"
                            <?php if ($tag['id'] == $event['tag_id']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($tag['tag_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="asset">Graphic Asset</label>
                <p class="current-asset">
                    Current: <strong><?php echo htmlspecialchars($event['filename_original'] ?? 'None'); ?></strong>
                </p>
                <input type="file" id="asset" name="asset" <?php if ($is_read_only) echo 'disabled'; ?>>
                <small><?php if (!$is_read_only) echo 'Only select a file if you want to replace the current one.'; ?></small>
            </div>

            <button type="submit" class="btn" <?php if ($is_read_only) echo 'disabled'; ?>>
                <?php echo $is_read_only ? 'Event Complete (Read-Only)' : 'Save Changes'; ?>
            </button>

        </form>
        <?php endif; ?>
    </div>

</body>
</html>