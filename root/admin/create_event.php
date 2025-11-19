<?php
require_once 'auth.php'; 
require_once '../db_connect.php'; 
date_default_timezone_set('America/New_York');

require_role(['admin', 'user', 'tag_editor']);

$errors = [];
$success_message = '';

$user_id = $_SESSION['user_id'];
$allowed_tag_ids = [];
if (is_admin() || has_role('user')) {
    $stmt = $pdo->query("SELECT id, tag_name FROM tags ORDER BY tag_name");
    $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $allowed_tag_ids = array_column($tags, 'id');
} else {
    $stmt = $pdo->prepare("SELECT t.id, t.tag_name FROM tags t JOIN user_tags ut ON t.id = ut.tag_id WHERE ut.user_id = ? ORDER BY t.tag_name");
    $stmt->execute([$user_id]);
    $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $allowed_tag_ids = array_column($tags, 'id');
}

// Default values
$event_name = '';
$tag_id = '';
$priority = 0;
$asset_id = 0;
$asset_selection_mode = 'existing';

// Handle Duplicate Request
if (isset($_GET['duplicate_id'])) {
    $dup_id = (int)$_GET['duplicate_id'];
    $stmt_dup = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $stmt_dup->execute([$dup_id]);
    $dup_event = $stmt_dup->fetch(PDO::FETCH_ASSOC);
    
    if ($dup_event) {
        // Check permission
        if (in_array($dup_event['tag_id'], $allowed_tag_ids)) {
            $event_name = $dup_event['event_name'] . " (Copy)";
            $tag_id = $dup_event['tag_id'];
            $priority = $dup_event['priority'];
            $asset_id = $dup_event['asset_id'];
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    $event_name = trim($_POST['event_name']);
    $start_date = $_POST['start_date'];
    $start_time_val = $_POST['start_time'];
    $end_time_val = $_POST['end_time'];
    $tag_id = (int)$_POST['tag_id'];
    $priority = (int)$_POST['priority'];
    $asset_selection_mode = $_POST['asset_mode'];
    
    // Recurrence
    $recurrence = $_POST['recurrence'] ?? 'none';
    $recur_until = $_POST['recur_until'] ?? '';

    if (empty($event_name)) $errors[] = "Event name is required.";
    if (empty($start_date) || empty($start_time_val)) $errors[] = "Start date and time are required.";
    if (empty($end_time_val)) $errors[] = "End time is required.";
    if (empty($tag_id)) $errors[] = "Tag is required.";
    if (!in_array($tag_id, $allowed_tag_ids)) $errors[] = "Invalid tag selected.";

    $start_dt = new DateTime("$start_date $start_time_val");
    $now = new DateTime();
    if ($start_dt < $now->modify('-1 minute')) $errors[] = "Start time cannot be in the past.";

    $end_dt = new DateTime("$start_date $end_time_val");
    if ($end_dt <= $start_dt) $end_dt->modify('+1 day');
    
    $duration = $end_dt->getTimestamp() - $start_dt->getTimestamp();

    // Asset Handling
    $asset_id = 0;
    if ($asset_selection_mode == 'upload') {
        if (!isset($_FILES['asset']) || $_FILES['asset']['error'] != UPLOAD_ERR_OK) {
            $errors[] = "Asset file upload failed.";
        } else {
            $asset = $_FILES['asset'];
            $tmp_name = $asset['tmp_name'];
            $md5 = md5_file($tmp_name);
            
            $stmt_check = $pdo->prepare("SELECT id FROM assets WHERE md5_hash = ?");
            $stmt_check->execute([$md5]);
            if ($existing = $stmt_check->fetch()) {
                $asset_id = $existing['id'];
            } else {
                // Quota Check (MB)
                $stmt_limit = $pdo->prepare("SELECT storage_limit_mb FROM tags WHERE id = ?");
                $stmt_limit->execute([$tag_id]);
                $limit_mb = $stmt_limit->fetchColumn();
                
                $stmt_usage = $pdo->prepare("SELECT SUM(size_bytes) FROM assets WHERE tag_id = ?");
                $stmt_usage->execute([$tag_id]);
                $current_mb = $stmt_usage->fetchColumn() / (1024 * 1024);
                $new_mb = $asset['size'] / (1024 * 1024);
                
                if ($limit_mb > 0 && ($current_mb + $new_mb) > $limit_mb) {
                    $errors[] = "Storage limit reached.";
                } else {
                    $safe_filename = uniqid('asset_', true) . '_' . preg_replace("/[^a-zA-Z0-9\._-]/", "", basename($asset['name']));
                    $upload_path = '/uploads/' . $safe_filename;
                    if (move_uploaded_file($tmp_name, $upload_path)) {
                        $stmt_ins = $pdo->prepare("INSERT INTO assets (filename_disk, filename_original, mime_type, md5_hash, tag_id, uploaded_by, size_bytes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                        $stmt_ins->execute([$safe_filename, basename($asset['name']), $asset['type'], $md5, $tag_id, $user_id, $asset['size']]);
                        $asset_id = $pdo->lastInsertId();
                    } else {
                        $errors[] = "Failed to move uploaded file.";
                    }
                }
            }
        }
    } else {
        $asset_id = (int)$_POST['existing_asset_id'];
        if ($asset_id <= 0) $errors[] = "Please select an existing asset.";
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Create First Event
            $start_utc = $start_dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $end_utc = $end_dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            
            $sql_event = "INSERT INTO events (event_name, start_time, end_time, asset_id, tag_id, priority) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt_event = $pdo->prepare($sql_event);
            $stmt_event->execute([$event_name, $start_utc, $end_utc, $asset_id, $tag_id, $priority]);
            $parent_id = $pdo->lastInsertId();
            
            // Handle Recurrence
            if ($recurrence != 'none' && !empty($recur_until)) {
                $until_dt = new DateTime($recur_until);
                $until_dt->setTime(23, 59, 59); // End of that day
                
                $interval_spec = ($recurrence == 'daily') ? 'P1D' : 'P1W';
                $interval = new DateInterval($interval_spec);
                
                // Reset start_dt to local for loop
                $start_dt->setTimezone(new DateTimeZone('America/New_York'));
                
                $next_start = clone $start_dt;
                $next_start->add($interval);
                
                while ($next_start <= $until_dt) {
                    $next_end = clone $next_start;
                    $next_end->add(new DateInterval('PT' . $duration . 'S'));
                    
                    $s_utc = $next_start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                    $e_utc = $next_end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                    
                    $stmt_recur = $pdo->prepare("INSERT INTO events (event_name, start_time, end_time, asset_id, tag_id, priority, parent_event_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt_recur->execute([$event_name, $s_utc, $e_utc, $asset_id, $tag_id, $priority, $parent_id]);
                    
                    // Restore timezone for next iteration logic
                    $next_start->setTimezone(new DateTimeZone('America/New_York'));
                    $next_start->add($interval);
                }
            }
            
            $pdo->commit();
            $success_message = "Event(s) created successfully!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

$sql_assets = "SELECT id, filename_original, tag_id FROM assets ORDER BY created_at DESC";
$all_assets = $pdo->query($sql_assets)->fetchAll(PDO::FETCH_ASSOC);

$default_start = new DateTime();
$default_start->modify('+5 minutes');
$default_date = $default_start->format('Y-m-d');
$default_time = $default_start->format('H:i');
$default_end = clone $default_start;
$default_end->modify('+1 hour');
$default_end_time = $default_end->format('H:i');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Event - WRHU Encoder Scheduler</title>
    <link rel="stylesheet" href="style.css">
    <script>
        function toggleAssetMode() {
            const mode = document.querySelector('input[name="asset_mode"]:checked').value;
            document.getElementById('mode-existing').style.display = mode === 'existing' ? 'block' : 'none';
            document.getElementById('mode-upload').style.display = mode === 'upload' ? 'block' : 'none';
        }
        function toggleRecurrence() {
            const val = document.getElementById('recurrence').value;
            document.getElementById('recur-until-group').style.display = val === 'none' ? 'none' : 'block';
        }
    </script>
</head>
<body>

    <?php include 'navbar.php'; ?>

    <div class="container">
        <h1>Create New Event</h1>

        <?php if (!empty($errors)): ?>
            <div class="message error"><ul><?php foreach ($errors as $e) echo "<li>$e</li>"; ?></ul></div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="message success"><?php echo $success_message; ?></div>
        <?php endif; ?>

        <div class="card">
            <form action="create_event.php" method="POST" enctype="multipart/form-data">
                
                <div class="form-group">
                    <label>Event Name</label>
                    <input type="text" name="event_name" value="<?php echo htmlspecialchars($event_name); ?>" required>
                </div>

                <div class="row">
                    <div class="col">
                        <div class="form-group">
                            <label>Start Date</label>
                            <input type="date" name="start_date" value="<?php echo $default_date; ?>" min="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    <div class="col">
                        <div class="form-group">
                            <label>Start Time</label>
                            <input type="time" name="start_time" value="<?php echo $default_time; ?>" required>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>End Time (Duration: 1h default)</label>
                    <input type="time" name="end_time" value="<?php echo $default_end_time; ?>" required>
                    <small style="color:#777;">If earlier than start time, next day is assumed.</small>
                </div>

                <div class="row">
                    <div class="col">
                        <div class="form-group">
                            <label>Recurrence</label>
                            <select name="recurrence" id="recurrence" onchange="toggleRecurrence()">
                                <option value="none">None (One-time)</option>
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                            </select>
                        </div>
                    </div>
                    <div class="col">
                        <div class="form-group" id="recur-until-group" style="display:none;">
                            <label>Repeat Until</label>
                            <input type="date" name="recur_until">
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col">
                        <div class="form-group">
                            <label>Output Tag</label>
                            <select name="tag_id" required>
                                <?php foreach ($tags as $tag): ?>
                                    <option value="<?php echo $tag['id']; ?>" <?php if($tag['id'] == $tag_id) echo 'selected'; ?>>
                                        <?php echo htmlspecialchars($tag['tag_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col">
                        <div class="form-group">
                            <label>Priority</label>
                            <select name="priority">
                                <option value="0" <?php if($priority == 0) echo 'selected'; ?>>Low (Default)</option>
                                <option value="1" <?php if($priority == 1) echo 'selected'; ?>>Medium</option>
                                <option value="2" <?php if($priority == 2) echo 'selected'; ?>>High (Preempts others)</option>
                            </select>
                        </div>
                    </div>
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
                        <select name="existing_asset_id" id="asset-select">
                            <option value="">-- Search/Select Asset --</option>
                            <?php foreach ($all_assets as $a): ?>
                                <option value="<?php echo $a['id']; ?>" <?php if($a['id'] == $asset_id) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($a['filename_original']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="mode-upload" style="display:none;">
                        <input type="file" name="asset">
                    </div>
                </div>

                <button type="submit" class="btn">Create Event</button>

            </form>
        </div>
    </div>

    <footer>
        &copy;<?php echo date("Y") > 2025 ? "2025-" . date("Y") : "2025"; ?> WRHU Radio Hofstra University. Written by Gregory Pocali for WRHU.
    </footer>

</body>
</html>