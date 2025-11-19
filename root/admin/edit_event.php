<?php
require_once 'auth.php';
require_once '../db_connect.php';
date_default_timezone_set('America/New_York');

if (!isset($_GET['id'])) {
    header("Location: index.php");
    exit;
}

$event_id = (int)$_GET['id'];

// Fetch Event
$stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
$stmt->execute([$event_id]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    die("Event not found.");
}

// Check Permission
if (!can_edit_tag($pdo, $event['tag_id'])) {
    die("You do not have permission to edit events for this tag.");
}

$errors = [];
$success_message = '';

// Handle Update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'update_event') {
    $event_name = trim($_POST['event_name']);
    $start_date = $_POST['start_date'];
    $start_time_val = $_POST['start_time'];
    $end_time_val = $_POST['end_time'];
    $priority = (int)$_POST['priority'];
    $asset_selection_mode = $_POST['asset_mode'];
    
    if (empty($event_name)) $errors[] = "Event name is required.";
    
    $start_dt = new DateTime("$start_date $start_time_val");
    $end_dt = new DateTime("$start_date $end_time_val");
    if ($end_dt <= $start_dt) $end_dt->modify('+1 day');
    
    // Asset Logic
    $asset_id = $event['asset_id']; // Default to current
    if ($asset_selection_mode == 'upload') {
        if (isset($_FILES['asset']) && $_FILES['asset']['error'] == UPLOAD_ERR_OK) {
            // ... (Upload logic similar to create_event.php) ...
            // For brevity, reusing logic or assuming user uploads new if selected
            $asset = $_FILES['asset'];
            $tmp_name = $asset['tmp_name'];
            $md5 = md5_file($tmp_name);
            $stmt_check = $pdo->prepare("SELECT id FROM assets WHERE md5_hash = ?");
            $stmt_check->execute([$md5]);
            if ($existing = $stmt_check->fetch()) {
                $asset_id = $existing['id'];
            } else {
                // Quota check would go here
                $safe_filename = uniqid('asset_', true) . '_' . preg_replace("/[^a-zA-Z0-9\._-]/", "", basename($asset['name']));
                $upload_path = '/uploads/' . $safe_filename;
                move_uploaded_file($tmp_name, $upload_path);
                $stmt_ins = $pdo->prepare("INSERT INTO assets (filename_disk, filename_original, mime_type, md5_hash, tag_id, uploaded_by, size_bytes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt_ins->execute([$safe_filename, basename($asset['name']), $asset['type'], $md5, $event['tag_id'], $_SESSION['user_id'], $asset['size']]);
                $asset_id = $pdo->lastInsertId();
            }
        }
    } elseif ($asset_selection_mode == 'existing') {
        $asset_id = (int)$_POST['existing_asset_id'];
    }

    if (empty($errors)) {
        $start_utc = $start_dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $end_utc = $end_dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        
        $sql_upd = "UPDATE events SET event_name = ?, start_time = ?, end_time = ?, asset_id = ?, priority = ? WHERE id = ?";
        $stmt_upd = $pdo->prepare($sql_upd);
        $stmt_upd->execute([$event_name, $start_utc, $end_utc, $asset_id, $priority, $event_id]);
        $success_message = "Event updated successfully.";
        
        // Refresh event data
        $stmt->execute([$event_id]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// Handle Delete
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'delete_event') {
    $pdo->prepare("DELETE FROM events WHERE id = ?")->execute([$event_id]);
    header("Location: index.php");
    exit;
}

// Prepare Display Data
$start_dt_local = (new DateTime($event['start_time']))->setTimezone(new DateTimeZone('America/New_York'));
$end_dt_local = (new DateTime($event['end_time']))->setTimezone(new DateTimeZone('America/New_York'));

$current_date = $start_dt_local->format('Y-m-d');
$current_start_time = $start_dt_local->format('H:i');
$current_end_time = $end_dt_local->format('H:i');

$sql_assets = "SELECT id, filename_original FROM assets ORDER BY created_at DESC";
$all_assets = $pdo->query($sql_assets)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Event - WRHU Encoder Scheduler</title>
    <link rel="stylesheet" href="style.css">
    <script>
        function toggleAssetMode() {
            const mode = document.querySelector('input[name="asset_mode"]:checked').value;
            document.getElementById('mode-existing').style.display = mode === 'existing' ? 'block' : 'none';
            document.getElementById('mode-upload').style.display = mode === 'upload' ? 'block' : 'none';
        }
    </script>
</head>
<body>

    <?php include 'navbar.php'; ?>

    <div class="container">
        <h1>Edit Event</h1>

        <?php if (!empty($errors)): ?>
            <div class="message error"><ul><?php foreach ($errors as $e) echo "<li>$e</li>"; ?></ul></div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="message success"><?php echo $success_message; ?></div>
        <?php endif; ?>

        <div class="card">
            <form action="edit_event.php?id=<?php echo $event_id; ?>" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_event">
                
                <div class="form-group">
                    <label>Event Name</label>
                    <input type="text" name="event_name" value="<?php echo htmlspecialchars($event['event_name']); ?>" required>
                </div>

                <div class="row">
                    <div class="col">
                        <div class="form-group">
                            <label>Start Date</label>
                            <input type="date" name="start_date" value="<?php echo $current_date; ?>" required>
                        </div>
                    </div>
                    <div class="col">
                        <div class="form-group">
                            <label>Start Time</label>
                            <input type="time" name="start_time" value="<?php echo $current_start_time; ?>" required>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>End Time</label>
                    <input type="time" name="end_time" value="<?php echo $current_end_time; ?>" required>
                </div>

                <div class="form-group">
                    <label>Priority</label>
                    <select name="priority">
                        <option value="0" <?php if($event['priority'] == 0) echo 'selected'; ?>>Low (Default)</option>
                        <option value="1" <?php if($event['priority'] == 1) echo 'selected'; ?>>Medium</option>
                        <option value="2" <?php if($event['priority'] == 2) echo 'selected'; ?>>High (Preempts others)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Asset Selection</label>
                    <div style="margin-bottom:10px;">
                        <label style="display:inline; margin-right:15px;">
                            <input type="radio" name="asset_mode" value="existing" checked onclick="toggleAssetMode()" style="width:auto;"> Use Existing Asset
                        </label>
                        <label style="display:inline;">
                            <input type="radio" name="asset_mode" value="upload" onclick="toggleAssetMode()" style="width:auto;"> Upload New
                        </label>
                    </div>

                    <div id="mode-existing">
                        <select name="existing_asset_id">
                            <?php foreach ($all_assets as $a): ?>
                                <option value="<?php echo $a['id']; ?>" <?php if($a['id'] == $event['asset_id']) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($a['filename_original']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="mode-upload" style="display:none;">
                        <input type="file" name="asset">
                    </div>
                </div>

                <button type="submit" class="btn">Update Event</button>
            </form>

            <div style="display:flex; gap:10px; margin-top:1em;">
                <a href="create_event.php?duplicate_id=<?php echo $event_id; ?>" class="btn btn-secondary" style="text-align:center; flex:1;">Duplicate Event</a>

                <form action="edit_event.php?id=<?php echo $event_id; ?>" method="POST" onsubmit="return confirm('Delete this event?');" style="flex:1;">
                    <input type="hidden" name="action" value="delete_event">
                    <button type="submit" class="btn-delete" style="width:100%;">Delete Event</button>
                </form>
            </div>
        </div>
    </div>

    <footer>
        &copy;<?php echo date("Y") > 2025 ? "2025-" . date("Y") : "2025"; ?> WRHU Radio Hofstra University. Written by Gregory Pocali for WRHU.
    </footer>

</body>
</html>