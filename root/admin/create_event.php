<?php
require_once 'auth.php'; 
require_once '../db_connect.php'; 
date_default_timezone_set('America/New_York');

// Check permissions
require_role(['admin', 'user', 'tag_editor']);

$errors = [];
$success_message = '';

// Fetch Tags (filtered for Tag Editor)
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

// Handle Form Submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    $event_name = trim($_POST['event_name']);
    $start_date = $_POST['start_date'];
    $start_time_val = $_POST['start_time'];
    $end_time_val = $_POST['end_time']; // This is just time, we need to calculate date
    $tag_id = (int)$_POST['tag_id'];
    $priority = (int)$_POST['priority'];
    $asset_selection_mode = $_POST['asset_mode']; // 'existing' or 'upload'

    if (empty($event_name)) $errors[] = "Event name is required.";
    if (empty($start_date) || empty($start_time_val)) $errors[] = "Start date and time are required.";
    if (empty($end_time_val)) $errors[] = "End time is required.";
    if (empty($tag_id)) $errors[] = "Tag is required.";
    if (!in_array($tag_id, $allowed_tag_ids)) $errors[] = "Invalid tag selected.";

    // Date/Time Logic
    $start_dt_str = "$start_date $start_time_val";
    $start_dt = new DateTime($start_dt_str);
    $now = new DateTime();

    // Prevent past dates (allow 1 min buffer)
    if ($start_dt < $now->modify('-1 minute')) {
        $errors[] = "Start time cannot be in the past.";
    }

    // End Time Logic (Next Day Assumption)
    $end_dt_str = "$start_date $end_time_val";
    $end_dt = new DateTime($end_dt_str);
    
    if ($end_dt <= $start_dt) {
        // If end time is earlier than start time, assume next day
        $end_dt->modify('+1 day');
    }

    // Asset Handling
    $asset_id = 0;

    if ($asset_selection_mode == 'upload') {
        if (!isset($_FILES['asset']) || $_FILES['asset']['error'] != UPLOAD_ERR_OK) {
            $errors[] = "Asset file upload failed.";
        } else {
            // ... (Upload logic similar to manage_assets.php) ...
            // Reuse upload logic or refactor? For now, duplicate for speed but keep consistent.
            $asset = $_FILES['asset'];
            $tmp_name = $asset['tmp_name'];
            $md5 = md5_file($tmp_name);
            
            // Check duplicates
            $stmt_check = $pdo->prepare("SELECT id FROM assets WHERE md5_hash = ?");
            $stmt_check->execute([$md5]);
            if ($existing = $stmt_check->fetch()) {
                $asset_id = $existing['id']; // Use existing
                // Maybe update tag_id if null? No, keep original owner/tag.
            } else {
                // Check quota
                $stmt_limit = $pdo->prepare("SELECT asset_limit FROM tags WHERE id = ?");
                $stmt_limit->execute([$tag_id]);
                $limit = $stmt_limit->fetchColumn();
                $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE tag_id = ?");
                $stmt_count->execute([$tag_id]);
                if ($limit > 0 && $stmt_count->fetchColumn() >= $limit) {
                    $errors[] = "Asset limit reached for this tag.";
                } else {
                    $original_filename = basename($asset['name']);
                    $safe_filename = uniqid('asset_', true) . '_' . preg_replace("/[^a-zA-Z0-9\._-]/", "", $original_filename);
                    $upload_path = '../uploads/' . $safe_filename;
                    if (move_uploaded_file($tmp_name, $upload_path)) {
                        $stmt_ins = $pdo->prepare("INSERT INTO assets (filename_disk, filename_original, mime_type, md5_hash, tag_id, uploaded_by, size_bytes) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt_ins->execute([$safe_filename, $original_filename, $asset['type'], $md5, $tag_id, $user_id, $asset['size']]);
                        $asset_id = $pdo->lastInsertId();
                    } else {
                        $errors[] = "Failed to move uploaded file.";
                    }
                }
            }
        }
    } else {
        // Existing Asset
        $asset_id = (int)$_POST['existing_asset_id'];
        if ($asset_id <= 0) $errors[] = "Please select an existing asset.";
    }

    if (empty($errors)) {
        try {
            $start_utc = $start_dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $end_utc = $end_dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            
            $sql_event = "INSERT INTO events (event_name, start_time, end_time, asset_id, tag_id, priority) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt_event = $pdo->prepare($sql_event);
            $stmt_event->execute([$event_name, $start_utc, $end_utc, $asset_id, $tag_id, $priority]);
            
            $success_message = "Event created successfully!";
        } catch (Exception $e) {
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

// Fetch Assets for Dropdown (Grouped by Tag?)
// Let's fetch all assets the user can see.
$sql_assets = "SELECT id, filename_original, tag_id FROM assets ORDER BY created_at DESC";
$all_assets = $pdo->query($sql_assets)->fetchAll(PDO::FETCH_ASSOC);

// Default Date/Time
$default_start = new DateTime();
$default_start->modify('+5 minutes');
// Round to nearest 5 mins? Optional but nice.
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
    <title>Create New Event</title>
    <style>
        :root {
            --bg-color: #121212;
            --card-bg: #1e1e1e;
            --text-color: #e0e0e0;
            --accent-color: #bb86fc;
            --secondary-color: #03dac6;
            --error-color: #cf6679;
            --border-color: #333;
        }
        body { font-family: 'Inter', sans-serif; background: var(--bg-color); color: var(--text-color); margin: 0; padding: 2em; }
        .container { max-width: 800px; margin: 0 auto; }
        a { color: var(--accent-color); text-decoration: none; }
        
        .card { background: var(--card-bg); padding: 2em; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.3); }
        
        .form-group { margin-bottom: 1.5em; }
        label { display: block; margin-bottom: 0.5em; color: #aaa; font-weight: bold; }
        input, select { width: 100%; padding: 0.8em; background: #2c2c2c; border: 1px solid var(--border-color); color: #fff; border-radius: 4px; box-sizing: border-box; }
        
        .row { display: flex; gap: 20px; }
        .col { flex: 1; }
        
        .btn { display: block; width: 100%; padding: 1em; background: var(--accent-color); color: #000; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 1em; }
        .btn:hover { opacity: 0.9; }
        
        .message { padding: 1em; border-radius: 4px; margin-bottom: 1em; }
        .error { background: rgba(207, 102, 121, 0.2); border: 1px solid var(--error-color); color: var(--error-color); }
        .success { background: rgba(3, 218, 198, 0.2); border: 1px solid var(--secondary-color); color: var(--secondary-color); }
        
        .asset-preview { margin-top: 10px; max-height: 200px; display: none; border: 1px solid var(--border-color); }
    </style>
    <script>
        function toggleAssetMode() {
            const mode = document.querySelector('input[name="asset_mode"]:checked').value;
            document.getElementById('mode-existing').style.display = mode === 'existing' ? 'block' : 'none';
            document.getElementById('mode-upload').style.display = mode === 'upload' ? 'block' : 'none';
        }
        
        function updateEndTime() {
            // Optional: Auto-adjust end time if start changes? 
            // For now, just let user pick.
        }
    </script>
</head>
<body>

    <div class="container">
        <a href="index.php">&larr; Back to Dashboard</a>
        <h1>Create New Event</h1>

        <?php if (!empty($errors)): ?>
            <div class="message error">
                <ul><?php foreach ($errors as $e) echo "<li>$e</li>"; ?></ul>
            </div>
        <?php endif; ?>
        
        <?php if ($success_message): ?>
            <div class="message success"><?php echo $success_message; ?></div>
        <?php endif; ?>

        <div class="card">
            <form action="create_event.php" method="POST" enctype="multipart/form-data">
                
                <div class="form-group">
                    <label>Event Name</label>
                    <input type="text" name="event_name" required>
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
                            <label>Output Tag</label>
                            <select name="tag_id" required>
                                <?php foreach ($tags as $tag): ?>
                                    <option value="<?php echo $tag['id']; ?>"><?php echo htmlspecialchars($tag['tag_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col">
                        <div class="form-group">
                            <label>Priority</label>
                            <select name="priority">
                                <option value="0">Low (Default)</option>
                                <option value="1">Medium</option>
                                <option value="2">High (Preempts others)</option>
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
                                <option value="<?php echo $a['id']; ?>">
                                    <?php echo htmlspecialchars($a['filename_original']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <!-- Could add JS search filter here for better UX -->
                    </div>

                    <div id="mode-upload" style="display:none;">
                        <input type="file" name="asset">
                    </div>
                </div>

                <button type="submit" class="btn">Create Event</button>

            </form>
        </div>
    </div>

</body>
</html>