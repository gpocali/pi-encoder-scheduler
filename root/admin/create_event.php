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
$selected_tag_ids = [];
$priority = 0;
$asset_id = 0;
$recurrence = 'none';
$recur_days = [];
$recur_days = [];
$recur_forever = true;
$recur_until = '';
$recur_until = '';

// Handle Duplicate Request
$event_id = 0;
if (isset($_GET['duplicate_id'])) {
    $event_id = (int) $_GET['duplicate_id'];
}

// Capture Dashboard State
$dashboard_state = [
    'view' => $_GET['view'] ?? 'list',
    'date' => $_GET['date'] ?? date('Y-m-d'),
    'page' => $_GET['page'] ?? 1,
    'tag_id' => $_GET['tag_id'] ?? '',
    'hide_past' => $_GET['hide_past'] ?? ''
];
if (isset($_GET['duplicate_id'])) {
    $stmt_dup = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $stmt_dup->execute([$event_id]);
    $dup_event = $stmt_dup->fetch(PDO::FETCH_ASSOC);

    if ($dup_event) {
        // Check permission (simplified)
        $event_name = $dup_event['event_name'] . " (Copy)";
        // Fetch tags for duplicate
        $stmt_tags = $pdo->prepare("SELECT tag_id FROM event_tags WHERE event_id = ?");
        $stmt_tags->execute([$dup_id]);
        $selected_tag_ids = $stmt_tags->fetchAll(PDO::FETCH_COLUMN);

        $priority = $dup_event['priority'];
        $asset_id = $dup_event['asset_id'];

        // Infer Recurrence
        $recurrence = 'none';
        $recur_days = [];
        $recur_forever = false;
        $recur_until = '';

        $series_id = $dup_event['parent_event_id'] ?: ($dup_event['id']); // Assume I might be parent
        // Check if I am really a parent or child of a series
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM events WHERE parent_event_id = ? OR id = ?");
        $stmt_check->execute([$series_id, $series_id]);
        if ($stmt_check->fetchColumn() > 1) {
            // It is a series. Find next event to guess interval.
            $stmt_next = $pdo->prepare("SELECT start_time FROM events WHERE (parent_event_id = ? OR id = ?) AND start_time > ? ORDER BY start_time ASC LIMIT 1");
            $stmt_next->execute([$series_id, $series_id, $dup_event['start_time']]);
            $next_start = $stmt_next->fetchColumn();

            if ($next_start) {
                $d1 = new DateTime($dup_event['start_time']);
                $d2 = new DateTime($next_start);
                $diff = $d1->diff($d2);

                if ($diff->days == 1) {
                    $recurrence = 'daily';
                } elseif ($diff->days == 7) {
                    $recurrence = 'weekly';
                    $recur_days[] = $d1->format('w');
                }
            }

            // Check end date
            $stmt_last = $pdo->prepare("SELECT start_time FROM events WHERE (parent_event_id = ? OR id = ?) ORDER BY start_time DESC LIMIT 1");
            $stmt_last->execute([$series_id, $series_id]);
            $last_start = $stmt_last->fetchColumn();

            if ($last_start) {
                $last_dt = new DateTime($last_start);
                $now = new DateTime();
                if ($last_dt->diff($now)->y > 5) { // Arbitrary: if last event is > 5 years away (or just very far), assume forever
                    $recur_forever = true;
                } else {
                    $recur_until = $last_dt->format('Y-m-d');
                }
            }
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $event_name = trim($_POST['event_name']);
    $start_date = $_POST['start_date'];
    $start_time_val = $_POST['start_time'];
    $end_time_val = $_POST['end_time'];
    $selected_tag_ids = $_POST['tag_ids'] ?? [];
    $priority = (int) $_POST['priority'];
    $asset_id = (int) $_POST['existing_asset_id'];

    // Recurrence
    $recurrence = $_POST['recurrence'] ?? 'none';
    $recur_until = $_POST['recur_until'] ?? '';
    $recur_until = $_POST['recur_until'] ?? '';
    // If POST, trust the checkbox. If not set in POST, it means unchecked.
    // BUT, we need to distinguish between initial load (where we want default true) and form submission (where unchecked means false).
    // Actually, checking isset($_POST['recur_forever']) is correct for form submission.
    // If it's a POST, we use the posted value.
    $recur_forever = isset($_POST['recur_forever']);
    $recur_days = $_POST['recur_days'] ?? []; // Array of days (0=Sun, 6=Sat)

    if ($recur_forever) {
        $recur_until = '2037-12-31'; // Or handle as NULL in DB if supported, but date logic uses it
    }

    if (empty($event_name))
        $errors[] = "Event name is required.";
    if (empty($start_date) || empty($start_time_val))
        $errors[] = "Start date and time are required.";
    if (empty($end_time_val))
        $errors[] = "End time is required.";
    if (empty($selected_tag_ids))
        $errors[] = "At least one tag is required.";
    foreach ($selected_tag_ids as $tid) {
        if (!in_array($tid, $allowed_tag_ids)) {
            $errors[] = "Invalid tag selected: $tid";
            break;
        }
    }

    $start_dt = new DateTime("$start_date $start_time_val");
    $now = new DateTime();
    if ($start_dt < $now->modify('-1 minute'))
        $errors[] = "Start time cannot be in the past.";

    $end_dt = new DateTime("$start_date $end_time_val");
    if ($end_dt <= $start_dt)
        $end_dt->modify('+1 day');

    $duration = $end_dt->getTimestamp() - $start_dt->getTimestamp();

    if ($asset_id <= 0) {
        $errors[] = "Please select an asset.";
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Create First Event
            $start_utc = $start_dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $end_utc = $end_dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

            $sql_event = "INSERT INTO events (event_name, start_time, end_time, asset_id, priority, tag_id) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt_event = $pdo->prepare($sql_event);
            $stmt_event->execute([$event_name, $start_utc, $end_utc, $asset_id, $priority, $selected_tag_ids[0]]);
            $parent_id = $pdo->lastInsertId();

            // Insert Tags
            $stmt_et = $pdo->prepare("INSERT INTO event_tags (event_id, tag_id) VALUES (?, ?)");
            foreach ($selected_tag_ids as $tid) {
                $stmt_et->execute([$parent_id, $tid]);
            }

            // Handle Recurrence
            if ($recurrence != 'none' && !empty($recur_until)) {
                $until_dt = new DateTime($recur_until);
                $until_dt->setTime(23, 59, 59); // End of that day

                // Reset start_dt to local for loop
                $start_dt->setTimezone(new DateTimeZone('America/New_York'));

                if ($recurrence == 'weekly' && !empty($recur_days)) {
                    // Weekly with specific days
                    $next_start = clone $start_dt;
                    $next_start->modify('+1 day'); // Start checking from tomorrow (parent already created)

                    while ($next_start <= $until_dt) {
                        // Check if current day is in selected days
                        // 'w' returns 0 (Sun) to 6 (Sat)
                        if (in_array($next_start->format('w'), $recur_days)) {
                            $next_end = clone $next_start;
                            $next_end->add(new DateInterval('PT' . $duration . 'S'));

                            $s_utc = $next_start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                            $e_utc = $next_end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

                            $stmt_recur = $pdo->prepare("INSERT INTO events (event_name, start_time, end_time, asset_id, priority, parent_event_id, tag_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $stmt_recur->execute([$event_name, $s_utc, $e_utc, $asset_id, $priority, $parent_id, $selected_tag_ids[0]]);
                            $child_id = $pdo->lastInsertId();

                            // Tags for child
                            foreach ($selected_tag_ids as $tid) {
                                $stmt_et->execute([$child_id, $tid]);
                            }

                            // Restore timezone
                            $next_start->setTimezone(new DateTimeZone('America/New_York'));
                        }
                        $next_start->modify('+1 day');
                    }
                } else {
                    // Daily or Simple Weekly (same day each week)
                    $interval_spec = ($recurrence == 'daily') ? 'P1D' : 'P1W';
                    $interval = new DateInterval($interval_spec);

                    $next_start = clone $start_dt;
                    $next_start->add($interval);

                    while ($next_start <= $until_dt) {
                        $next_end = clone $next_start;
                        $next_end->add(new DateInterval('PT' . $duration . 'S'));

                        $s_utc = $next_start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                        $e_utc = $next_end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

                        $stmt_recur = $pdo->prepare("INSERT INTO events (event_name, start_time, end_time, asset_id, priority, parent_event_id) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt_recur->execute([$event_name, $s_utc, $e_utc, $asset_id, $priority, $parent_id]);
                        $child_id = $pdo->lastInsertId();

                        // Tags for child
                        foreach ($selected_tag_ids as $tid) {
                            $stmt_et->execute([$child_id, $tid]);
                        }

                        // Restore timezone for next iteration logic
                        $next_start->setTimezone(new DateTimeZone('America/New_York'));
                        $next_start->add($interval);
                    }
                }
            }

            $pdo->commit();

            // Restore Dashboard State
            $redirect_params = [
                'view' => $_POST['dashboard_view'] ?? 'list',
                'date' => $_POST['dashboard_date'] ?? date('Y-m-d'),
                'page' => $_POST['dashboard_page'] ?? 1,
                'tag_id' => $_POST['dashboard_tag_id'] ?? '',
                'hide_past' => $_POST['dashboard_hide_past'] ?? ''
            ];
            // Filter out empty
            $redirect_params = array_filter($redirect_params, fn($v) => $v !== '');

            header("Location: index.php?" . http_build_query($redirect_params));
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

// Fetch assets with tags
$sql_assets = "
    SELECT a.id, a.filename_original, a.display_name, a.filename_disk, a.mime_type,
           GROUP_CONCAT(at.tag_id) as tag_ids
    FROM assets a
    LEFT JOIN asset_tags at ON a.id = at.asset_id
    GROUP BY a.id
    ORDER BY a.created_at DESC";
$all_assets = $pdo->query($sql_assets)->fetchAll(PDO::FETCH_ASSOC);

$default_start = new DateTime();
$default_start->modify('+5 minutes');
$default_date = $default_start->format('Y-m-d');
$default_time = $default_start->format('H:i');
$default_end = clone $default_start;
$default_end->modify('+1 hour');
$default_end_time = $default_end->format('H:i');

// Pre-select asset name if editing/duplicating
$selected_asset_name = "No Asset Selected";
if ($asset_id > 0) {
    foreach ($all_assets as $a) {
        if ($a['id'] == $asset_id) {
            $selected_asset_name = $a['display_name'] ?? $a['filename_original'];
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Event - WRHU Encoder Scheduler</title>
    <link rel="stylesheet" href="style.css">
    <script>
        function toggleRecurrence() {
            const val = document.getElementById('recurrence').value;
            document.getElementById('recur-options').style.display = val === 'none' ? 'none' : 'block';
            document.getElementById('recur-days-group').style.display = val === 'weekly' ? 'block' : 'none';
        }

        function toggleRecurForever() {
            const forever = document.getElementById('recur_forever').checked;
            document.getElementById('recur_until').disabled = forever;
            if (forever) document.getElementById('recur_until').value = '';
        }

        // Asset Modal Logic
        let assets = <?php echo json_encode($all_assets); ?>;

        function openAssetModal() {
            document.getElementById('assetSelectionModal').style.display = 'block';
            filterAssetModal();
        }

        function closeAssetModal() {
            document.getElementById('assetSelectionModal').style.display = 'none';
        }

        function filterAssetModal() {
            const search = document.getElementById('assetModalSearch').value.toLowerCase();
            const tagFilter = document.getElementById('assetModalTagFilter').value;
            const list = document.getElementById('assetModalList');
            list.innerHTML = '';

            // Get selected tags from main form
            const selectedTags = Array.from(document.querySelectorAll('input[name="tag_ids[]"]:checked')).map(cb => cb.value);

            assets.forEach(asset => {
                const name = (asset.display_name || asset.filename_original).toLowerCase();
                const assetTags = asset.tag_ids ? asset.tag_ids.split(',') : [];

                let matchesSearch = name.includes(search);
                let matchesTag = true;
                if (tagFilter && !assetTags.includes(tagFilter)) {
                    matchesTag = false;
                }

                if (matchesSearch && matchesTag) {
                    const div = document.createElement('div');
                    div.className = 'asset-item';
                    div.style.border = '1px solid #444';
                    div.style.padding = '10px';
                    div.style.cursor = 'pointer';
                    div.style.borderRadius = '4px';
                    div.style.background = '#2a2a2a';

                    // Check tag match
                    let tagMatch = false;
                    const assetTags = asset.tag_ids ? asset.tag_ids.split(',') : [];
                    if (selectedTags.length === 0) tagMatch = true;
                    else {
                        // If asset has ANY of the selected tags
                        selectedTags.forEach(t => {
                            if (assetTags.includes(t)) tagMatch = true;
                        });
                    }

                    if (!tagMatch && selectedTags.length > 0) {
                        div.style.opacity = '0.5';
                        div.title = "Tag Mismatch";
                    }

                    // Thumbnail
                    let thumb = '';
                    if (asset.mime_type.includes('image')) {
                        thumb = `<img src="serve_asset.php?id=${asset.id}" style="width:100%; aspect-ratio:16/9; object-fit:cover; margin-bottom:5px;">`;
                    } else if (asset.mime_type.includes('video')) {
                        thumb = `<div style="width:100%; aspect-ratio:16/9; background:#000; display:flex; align-items:center; justify-content:center; margin-bottom:5px;"><span style="font-size:20px;">▶</span></div>`;
                    }

                    div.innerHTML = `
                        ${thumb}
                        <div style="font-weight:bold; font-size:0.9em; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${asset.display_name || asset.filename_original}</div>
                    `;

                    div.onclick = function () {
                        selectAsset(asset.id, asset.display_name || asset.filename_original);
                    };

                    list.appendChild(div);
                }
            });
        }

        function selectAsset(id, name) {
            document.getElementById('selected_asset_id').value = id;
            document.getElementById('selected_asset_name').value = name;
            closeAssetModal();

            // Check tags warning
            const selectedTags = Array.from(document.querySelectorAll('input[name="tag_ids[]"]:checked')).map(cb => cb.value);
            const asset = assets.find(a => a.id == id);
            const assetTags = asset.tag_ids ? asset.tag_ids.split(',') : [];

            let match = false;
            if (selectedTags.length === 0) match = true;
            selectedTags.forEach(t => { if (assetTags.includes(t)) match = true; });

            const warning = document.getElementById('asset-warning');
            if (!match && selectedTags.length > 0) {
                warning.style.display = 'block';
                warning.innerText = "Warning: Selected asset does not share any tags with this event.";
            } else {
                warning.style.display = 'none';
            }
        }

        function toggleAssetModalUpload() {
            const div = document.getElementById('assetModalUpload');
            div.style.display = div.style.display === 'none' ? 'block' : 'none';
        }

        function uploadAssetInModal() {
            const fileInput = document.getElementById('modalAssetFile');
            const file = fileInput.files[0];
            if (!file) return;

            const formData = new FormData();
            formData.append('action', 'upload_asset');
            formData.append('asset', file);

            // Add selected tags to upload
            const selectedTags = Array.from(document.querySelectorAll('input[name="tag_ids[]"]:checked')).map(cb => cb.value);
            selectedTags.forEach(t => formData.append('tag_ids[]', t));

            const btn = event.target;
            btn.disabled = true;
            btn.innerText = 'Uploading...';

            fetch('api_upload.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    btn.disabled = false;
                    btn.innerText = 'Upload';
                    if (data.success) {
                        // Refresh assets list
                        // We can't easily refresh the PHP array 'assets' without reload or separate API.
                        // For now, let's just reload the page or alert success.
                        // Better: Add to 'assets' array manually.
                        assets.unshift({
                            id: data.asset.id,
                            filename_original: data.asset.filename_original,
                            display_name: data.asset.display_name,
                            mime_type: data.asset.mime_type,
                            tag_ids: selectedTags.join(',')
                        });
                        filterAssetModal();
                        toggleAssetModalUpload();
                        selectAsset(data.asset.id, data.asset.display_name || data.asset.filename_original);
                    } else {
                        alert('Upload failed: ' + (data.errors ? data.errors.join(', ') : 'Unknown error'));
                    }
                })
                .catch(err => {
                    btn.disabled = false;
                    btn.innerText = 'Upload';
                    alert('Upload error.');
                    console.error(err);
                });
        }

        function toggleEventTag(btn) {
            btn.classList.toggle('active');
            if (btn.classList.contains('active')) {
                btn.style.background = 'var(--accent-color)';
                btn.style.color = '#000';
                btn.style.borderColor = 'var(--accent-color)';
                btn.style.fontWeight = 'bold';
            } else {
                btn.style.background = '#333';
                btn.style.color = '#ccc';
                btn.style.borderColor = '#555';
                btn.style.fontWeight = 'normal';
            }
            updateEventHiddenInputs();
        }

        function updateEventHiddenInputs() {
            const container = document.getElementById('eventTagInputs');
            container.innerHTML = '';
            const activeBtns = document.querySelectorAll('#eventTagButtons .tag-btn.active');
            activeBtns.forEach(btn => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'tag_ids[]';
                input.value = btn.dataset.id;
                container.appendChild(input);
            });
        }
    </script>
</head>

<body>

    <?php include 'navbar.php'; ?>

    <div class="container">
        <div style="margin-bottom: 20px;">
            <a href="index.php?<?php echo http_build_query($_GET); ?>"
                style="color:var(--accent-color); text-decoration:none;">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
        <h1>Create New Event</h1>

        <?php if (!empty($errors)): ?>
            <div class="message error">
                <ul><?php foreach ($errors as $e)
                    echo "<li>$e</li>"; ?></ul>
            </div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="message success"><?php echo $success_message; ?></div>
        <?php endif; ?>

        <div class="card">
            <form action="create_event.php" method="POST">
                <!-- Preserve Dashboard State -->
                <input type="hidden" name="dashboard_view"
                    value="<?php echo htmlspecialchars($dashboard_state['view']); ?>">
                <input type="hidden" name="dashboard_date"
                    value="<?php echo htmlspecialchars($dashboard_state['date']); ?>">
                <input type="hidden" name="dashboard_page"
                    value="<?php echo htmlspecialchars($dashboard_state['page']); ?>">
                <input type="hidden" name="dashboard_tag_id"
                    value="<?php echo htmlspecialchars($dashboard_state['tag_id']); ?>">
                <input type="hidden" name="dashboard_hide_past"
                    value="<?php echo htmlspecialchars($dashboard_state['hide_past']); ?>">

                <div class="form-group">
                    <label>Event Name</label>
                    <input type="text" name="event_name" value="<?php echo htmlspecialchars($event_name); ?>" required>
                </div>

                <div class="form-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date" value="<?php echo $default_date; ?>"
                        min="<?php echo date('Y-m-d'); ?>" required>
                </div>

                <div class="row">
                    <div class="col">
                        <div class="form-group">
                            <label>Start Time</label>
                            <input type="time" name="start_time" value="<?php echo $default_time; ?>" required>
                        </div>
                    </div>
                    <div class="col">
                        <div class="form-group">
                            <label>End Time</label>
                            <input type="time" name="end_time" value="<?php echo $default_end_time; ?>" required>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Recurrence</label>
                    <select name="recurrence" id="recurrence" onchange="toggleRecurrence()">
                        <option value="none" <?php if ($recurrence == 'none')
                            echo 'selected'; ?>>None (One-time)</option>
                        <option value="daily" <?php if ($recurrence == 'daily')
                            echo 'selected'; ?>>Daily</option>
                        <option value="weekly" <?php if ($recurrence == 'weekly')
                            echo 'selected'; ?>>Weekly</option>
                    </select>
                </div>

                <div id="recur-options"
                    style="display:none; padding:10px; background:#2a2a2a; margin-bottom:15px; border-radius:4px;">
                    <div class="form-group">
                        <label>Repeat Until</label>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <input type="date" name="recur_until" id="recur_until" value="<?php echo $recur_until; ?>">
                            <label><input type="checkbox" name="recur_forever" id="recur_forever"
                                    onchange="toggleRecurForever()" <?php if ($recur_forever)
                                        echo 'checked'; ?>>
                                Forever</label>
                        </div>
                    </div>

                    <div class="form-group" id="recur-days-group" style="display:none;">
                        <label>Repeat On (Weekly)</label>
                        <div style="display:flex; gap:10px; flex-wrap:wrap;">
                            <label><input type="checkbox" name="recur_days[]" value="0" <?php if (in_array(0, $recur_days))
                                echo 'checked'; ?>> Sun</label>
                            <label><input type="checkbox" name="recur_days[]" value="1" <?php if (in_array(1, $recur_days))
                                echo 'checked'; ?>> Mon</label>
                            <label><input type="checkbox" name="recur_days[]" value="2" <?php if (in_array(2, $recur_days))
                                echo 'checked'; ?>> Tue</label>
                            <label><input type="checkbox" name="recur_days[]" value="3" <?php if (in_array(3, $recur_days))
                                echo 'checked'; ?>> Wed</label>
                            <label><input type="checkbox" name="recur_days[]" value="4" <?php if (in_array(4, $recur_days))
                                echo 'checked'; ?>> Thu</label>
                            <label><input type="checkbox" name="recur_days[]" value="5" <?php if (in_array(5, $recur_days))
                                echo 'checked'; ?>> Fri</label>
                            <label><input type="checkbox" name="recur_days[]" value="6" <?php if (in_array(6, $recur_days))
                                echo 'checked'; ?>> Sat</label>
                        </div>
                    </div>
                </div>

                <script>
                    // Trigger initial state
                    document.addEventListener('DOMContentLoaded', function () {
                        toggleRecurrence();
                        toggleRecurForever();
                    });
                </script>

                <div class="row">
                    <div class="col">
                        <div class="form-group">
                            <label>Output Tags</label>
                            <div class="tag-button-group" id="eventTagButtons"
                                style="display:flex; flex-wrap:wrap; gap:8px;">
                                <?php foreach ($tags as $tag): ?>
                                    <button type="button"
                                        class="tag-btn <?php echo in_array($tag['id'], $selected_tag_ids) ? 'active' : ''; ?>"
                                        data-id="<?php echo $tag['id']; ?>" onclick="toggleEventTag(this)"
                                        style="padding:6px 12px; background:<?php echo in_array($tag['id'], $selected_tag_ids) ? 'var(--accent-color)' : '#333'; ?>; border:1px solid <?php echo in_array($tag['id'], $selected_tag_ids) ? 'var(--accent-color)' : '#555'; ?>; color:<?php echo in_array($tag['id'], $selected_tag_ids) ? '#000' : '#ccc'; ?>; border-radius:20px; cursor:pointer; font-size:0.9em; font-weight:<?php echo in_array($tag['id'], $selected_tag_ids) ? 'bold' : 'normal'; ?>;">
                                        <?php echo htmlspecialchars($tag['tag_name']); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            <div id="eventTagInputs">
                                <?php foreach ($selected_tag_ids as $tid): ?>
                                    <input type="hidden" name="tag_ids[]" value="<?php echo $tid; ?>">
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="form-group">
                            <label>Priority</label>
                            <select name="priority">
                                <option value="0" <?php if ($priority == 0)
                                    echo 'selected'; ?>>Low (Default)</option>
                                <option value="1" <?php if ($priority == 1)
                                    echo 'selected'; ?>>Medium</option>
                                <option value="2" <?php if ($priority == 2)
                                    echo 'selected'; ?>>High (Preempts others)
                                </option>
                            </select>
                            <small style="color:#aaa; display:block; margin-top:5px;">Higher priority events will
                                interrupt lower priority events playing at the same time.</small>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Asset</label>
                    <div style="display:flex; gap:10px;">
                        <input type="hidden" name="existing_asset_id" id="selected_asset_id"
                            value="<?php echo $asset_id; ?>">
                        <input type="text" id="selected_asset_name" readonly
                            value="<?php echo htmlspecialchars($selected_asset_name); ?>"
                            style="flex:1; background:#333; border:1px solid #444; color:#fff; padding:8px;">
                        <button type="button" class="btn btn-secondary" onclick="openAssetModal()">Select Asset</button>
                    </div>
                    <div id="asset-warning" style="color:orange; display:none; margin-top:5px;"></div>
                </div>

                <button type="submit" class="btn">Create Event</button>

            </form>
        </div>
    </div>

    <!-- Asset Selection Modal -->
    <div id="assetSelectionModal" class="modal">
        <div class="modal-content" style="width: 80%; max-width: 800px;">
            <span class="close" onclick="closeAssetModal()">&times;</span>
            <h2>Select Asset</h2>
            <div style="margin-bottom: 15px; display:flex; justify-content:space-between; gap:10px;">
                <input type="text" id="assetModalSearch" placeholder="Search assets..." onkeyup="filterAssetModal()"
                    style="flex:1;">
                <select id="assetModalTagFilter" onchange="filterAssetModal()"
                    style="flex:0 0 150px; padding: 5px; background: #333; color: #fff; border: 1px solid #444; border-radius: 4px;">
                    <option value="">All Tags</option>
                    <?php foreach ($tags as $tag): ?>
                        <option value="<?php echo $tag['id']; ?>"><?php echo htmlspecialchars($tag['tag_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-sm" onclick="toggleAssetModalUpload()">+ Upload New</button>
            </div>

            <div id="assetModalUpload"
                style="display:none; margin-bottom:15px; padding:15px; background:#222; border-radius:4px;">
                <h3>Upload New Asset</h3>
                <input type="file" id="modalAssetFile" style="margin-bottom:10px;">
                <button type="button" class="btn btn-sm" onclick="uploadAssetInModal()">Upload & Select</button>
            </div>

            <div id="assetModalList"
                style="display:grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap:10px; max-height:400px; overflow-y:auto;">
                <!-- JS populates this -->
            </div>
        </div>
    </div>

    <footer>
        &copy;<?php echo date("Y") > 2025 ? "2025-" . date("Y") : "2025"; ?> WRHU Radio Hofstra University. Written by
        Gregory Pocali for WRHU with assistance from Google Gemini 3.
    </footer>

</body>

</html>