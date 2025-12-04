<?php
require_once 'auth.php';
require_once '../db_connect.php';
date_default_timezone_set('America/New_York');

if (!isset($_GET['id'])) {
    header("Location: index.php");
    exit;
}

$event_id_param = $_GET['id'];
$event = null;
$is_generated = false;

if (strpos($event_id_param, 'recur_') === 0) {
    // Generated Instance: recur_{id}_{timestamp}
    $parts = explode('_', $event_id_param);
    $recur_id = (int) $parts[1];
    $ts = (int) $parts[2];

    $stmt = $pdo->prepare("SELECT * FROM recurring_events WHERE id = ?");
    $stmt->execute([$recur_id]);
    $recur_row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($recur_row) {
        $is_generated = true;
        // Construct fake event
        if ($ts == 0) {
            // Series Edit Mode: Use original series start
            // recur_row start_time/date are Local
            $local_dt = new DateTime($recur_row['start_date'] . ' ' . $recur_row['start_time'], new DateTimeZone('America/New_York'));
            $start_dt = clone $local_dt;
            $start_dt->setTimezone(new DateTimeZone('UTC'));
        } else {
            $start_dt = new DateTime('@' . $ts); // UTC timestamp
            $start_dt->setTimezone(new DateTimeZone('UTC'));
        }

        $end_dt = clone $start_dt;
        $end_dt->modify("+{$recur_row['duration']} seconds");

        $event = [
            'id' => $event_id_param, // Keep the string ID
            'event_name' => $recur_row['event_name'],
            'start_time' => $start_dt->format('Y-m-d H:i:s'),
            'end_time' => $end_dt->format('Y-m-d H:i:s'),
            'asset_id' => $recur_row['asset_id'],
            'priority' => $recur_row['priority'],
            'recurring_event_id' => $recur_id,
            'parent_event_id' => null, // It's a series itself
            'tag_id' => null // Will fetch tags below
        ];

        // Fetch Tags for Recurring
        $stmt_tags = $pdo->prepare("SELECT tag_id FROM recurring_event_tags WHERE recurring_event_id = ?");
        $stmt_tags->execute([$recur_id]);
        $current_tag_ids = $stmt_tags->fetchAll(PDO::FETCH_COLUMN);
    }
} else {
    // Standard Event
    $event_id = (int) $event_id_param;
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$event_id]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($event) {
        // Fetch Current Tags
        $stmt_tags = $pdo->prepare("SELECT tag_id FROM event_tags WHERE event_id = ?");
        $stmt_tags->execute([$event_id]);
        $current_tag_ids = $stmt_tags->fetchAll(PDO::FETCH_COLUMN);

        // Fallback for legacy
        if (empty($current_tag_ids) && $event['tag_id']) {
            $current_tag_ids[] = $event['tag_id'];
        }
    }
}

if (!$event) {
    die("Event not found.");
}

// Initialize Recurrence Variables
$recurrence = 'none';
$recur_days = [];
$recur_until = '';
$recur_forever = false;

if (!empty($event['recurring_event_id'])) {
    // Fetch Series Data
    $stmt_ser = $pdo->prepare("SELECT * FROM recurring_events WHERE id = ?");
    $stmt_ser->execute([$event['recurring_event_id']]);
    $series_row = $stmt_ser->fetch(PDO::FETCH_ASSOC);

    if ($series_row) {
        $recurrence = $series_row['recurrence_type'];
        $recur_days = $series_row['recurrence_days'] ? explode(',', $series_row['recurrence_days']) : [];
        $recur_until = $series_row['end_date'];
        $recur_forever = is_null($series_row['end_date']);
    }
} elseif ($is_generated && isset($recur_row)) {
    // Already fetched in $recur_row at top
    $recurrence = $recur_row['recurrence_type'];
    $recur_days = $recur_row['recurrence_days'] ? explode(',', $recur_row['recurrence_days']) : [];
    $recur_until = $recur_row['end_date'];
    $recur_forever = is_null($recur_row['end_date']);
}

// Check Permission
$has_permission = false;
$user_id = $_SESSION['user_id'];
$allowed_tag_ids = [];

if (is_admin() || has_role('user')) {
    $stmt = $pdo->query("SELECT id, tag_name FROM tags ORDER BY tag_name");
    $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $allowed_tag_ids = array_column($tags, 'id');
    $has_permission = true;
} else {
    $stmt = $pdo->prepare("SELECT t.id, t.tag_name FROM tags t JOIN user_tags ut ON t.id = ut.tag_id WHERE ut.user_id = ? ORDER BY t.tag_name");
    $stmt->execute([$user_id]);
    $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $allowed_tag_ids = array_column($tags, 'id');

    foreach ($current_tag_ids as $tid) {
        if (in_array($tid, $allowed_tag_ids)) {
            $has_permission = true;
            break;
        }
    }
}

if (!$has_permission) {
    die("You do not have permission to edit events for these tags.");
}

// Prepare Back Params
$back_params = $_GET;
unset($back_params['id']);
$back_query = http_build_query($back_params);

// Check if Series
$is_series = false;
if ($is_generated || !empty($event['recurring_event_id'])) {
    $is_series = true;
} else {
    // Check legacy parent/child (should be rare now if we migrate, but keep for safety)
    if (!empty($event['parent_event_id'])) {
        $is_series = true;
        $parent_id = $event['parent_event_id'];
    } else {
        // Check if I am a parent (legacy)
        $stmt_children = $pdo->prepare("SELECT COUNT(*) FROM events WHERE parent_event_id = ?");
        $stmt_children->execute([isset($event['id']) && is_numeric($event['id']) ? $event['id'] : 0]);
        if ($stmt_children->fetchColumn() > 0) {
            $is_series = true;
            $parent_id = $event['id'];
        }
    }
}

$errors = [];
$success_message = '';

// Handle Update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'update_event') {
    $event_name = trim($_POST['event_name']);
    $start_date = $_POST['start_date'];
    $start_time_val = $_POST['start_time'];
    $end_time_val = $_POST['end_time'];
    $priority = (int) $_POST['priority'];
    $asset_id = (int) $_POST['existing_asset_id'];
    $update_scope = $_POST['update_scope'] ?? 'only_this';
    $selected_tag_ids = $_POST['tag_ids'] ?? [];

    // Recurrence Params
    $recurrence = $_POST['recurrence'] ?? 'none';
    $recur_until = $_POST['recur_until'] ?? '';
    $recur_forever = isset($_POST['recur_forever']);
    $recur_days = $_POST['recur_days'] ?? [];
    $recur_days_str = implode(',', $recur_days);

    if ($recur_forever || empty($recur_until)) {
        $recur_until = null;
    }

    if (empty($event_name))
        $errors[] = "Event name is required.";
    if (empty($selected_tag_ids))
        $errors[] = "At least one tag is required.";
    foreach ($selected_tag_ids as $tid) {
        if (!in_array($tid, $allowed_tag_ids)) {
            $errors[] = "Invalid tag selected: $tid";
            break;
        }
    }

    $start_dt = new DateTime("$start_date $start_time_val");
    $end_dt = new DateTime("$start_date $end_time_val");
    if ($end_dt <= $start_dt)
        $end_dt->modify('+1 day');

    if ($asset_id <= 0) {
        $errors[] = "Please select an asset.";
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $start_utc = $start_dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $end_utc = $end_dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

            if ($is_series) {
                // RECURRING EVENT UPDATE
                $recur_id = $event['recurring_event_id'];

                if ($update_scope == 'only_this') {
                    // Create Exception
                    // 1. Insert into events table with is_exception=1
                    // We need to know the ORIGINAL start time of the instance we are replacing to suppress it.
                    // The $event['start_time'] passed from GET should be the generated start time.
                    // BUT wait, if we are editing a generated instance, we don't have a row in `events` yet.
                    // The GET logic at the top needs to handle fetching generated events.
                    // Assuming GET logic is updated (I need to update it next), $event will have 'start_time' of the instance.

                    $original_start = $event['start_time']; // This is the time of the instance we clicked

                    $sql_ex = "INSERT INTO events (event_name, start_time, end_time, asset_id, priority, recurring_event_id, original_start_time, is_exception) VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
                    $stmt_ex = $pdo->prepare($sql_ex);
                    $stmt_ex->execute([$event_name, $start_utc, $end_utc, $asset_id, $priority, $recur_id, $original_start]);
                    $new_event_id = $pdo->lastInsertId();

                    // Tags
                    $stmt_et = $pdo->prepare("INSERT INTO event_tags (event_id, tag_id) VALUES (?, ?)");
                    foreach ($selected_tag_ids as $tid) {
                        $stmt_et->execute([$new_event_id, $tid]);
                    }

                } elseif ($update_scope == 'future') {
                    // Split Series
                    // 1. End current series at yesterday (or just before this event)
                    // We set end_date of the recurring_event to $start_date - 1 day
                    $split_date = new DateTime($start_date);
                    $split_date->modify('-1 day');
                    $new_end_date = $split_date->format('Y-m-d');

                    $stmt_upd = $pdo->prepare("UPDATE recurring_events SET end_date = ? WHERE id = ?");
                    $stmt_upd->execute([$new_end_date, $recur_id]);

                    if ($recurrence == 'none') {
                        // Create One-off
                        // Legacy tag_id required
                        $primary_tag = $selected_tag_ids[0];
                        $sql_one = "INSERT INTO events (event_name, start_time, end_time, asset_id, priority, tag_id) VALUES (?, ?, ?, ?, ?, ?)";
                        $stmt_one = $pdo->prepare($sql_one);
                        $stmt_one->execute([$event_name, $start_utc, $end_utc, $asset_id, $priority, $primary_tag]);
                        $new_id = $pdo->lastInsertId();

                        // Tags
                        $stmt_tags = $pdo->prepare("INSERT INTO event_tags (event_id, tag_id) VALUES (?, ?)");
                        foreach ($selected_tag_ids as $tid) {
                            $stmt_tags->execute([$new_id, $tid]);
                        }
                    } else {
                        // 2. Create NEW recurring series starting today
                        $duration = $end_dt->getTimestamp() - $start_dt->getTimestamp();

                        $sql_new = "INSERT INTO recurring_events (event_name, start_time, duration, start_date, end_date, recurrence_type, recurrence_days, asset_id, priority, parent_event_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        $stmt_new = $pdo->prepare($sql_new);
                        $stmt_new->execute([
                            $event_name,
                            $start_time_val,
                            $duration,
                            $start_date, // Starts today
                            $recur_until, // New end date
                            $recurrence, // New type
                            $recur_days_str, // New days
                            $asset_id,
                            $priority,
                            $recur_id // Link to old series
                        ]);
                        $new_recur_id = $pdo->lastInsertId();

                        // Tags
                        $stmt_ret = $pdo->prepare("INSERT INTO recurring_event_tags (recurring_event_id, tag_id) VALUES (?, ?)");
                        foreach ($selected_tag_ids as $tid) {
                            $stmt_ret->execute([$new_recur_id, $tid]);
                        }
                    }
                } elseif ($update_scope == 'all') {
                    if ($recurrence == 'none') {
                        // Create One-off
                        // Legacy tag_id required
                        $primary_tag = $selected_tag_ids[0];
                        $sql_one = "INSERT INTO events (event_name, start_time, end_time, asset_id, priority, tag_id) VALUES (?, ?, ?, ?, ?, ?)";
                        $stmt_one = $pdo->prepare($sql_one);
                        $stmt_one->execute([$event_name, $start_utc, $end_utc, $asset_id, $priority, $primary_tag]);
                        $new_id = $pdo->lastInsertId();

                        $stmt_tags = $pdo->prepare("INSERT INTO event_tags (event_id, tag_id) VALUES (?, ?)");
                        foreach ($selected_tag_ids as $tid) {
                            $stmt_tags->execute([$new_id, $tid]);
                        }
                    } else {
                        // Update Entire Series
                        $duration = $end_dt->getTimestamp() - $start_dt->getTimestamp();

                        $sql_upd = "UPDATE recurring_events SET 
                                    event_name = ?, 
                                    start_time = ?, 
                                    duration = ?, 
                                    end_date = ?, 
                                    recurrence_type = ?, 
                                    recurrence_days = ?, 
                                    asset_id = ?, 
                                    priority = ? 
                                    WHERE id = ?";
                        $stmt_upd = $pdo->prepare($sql_upd);
                        $stmt_upd->execute([
                            $event_name,
                            $start_time_val,
                            $duration,
                            $recur_until,
                            $recurrence,
                            $recur_days_str,
                            $asset_id,
                            $priority,
                            $recur_id
                        ]);

                        // Update Tags
                        $pdo->prepare("DELETE FROM recurring_event_tags WHERE recurring_event_id = ?")->execute([$recur_id]);
                        $stmt_ret = $pdo->prepare("INSERT INTO recurring_event_tags (recurring_event_id, tag_id) VALUES (?, ?)");
                        foreach ($selected_tag_ids as $tid) {
                            $stmt_ret->execute([$recur_id, $tid]);
                        }
                    }
                }

            } else {
                // ONE-OFF EVENT UPDATE (Standard)
                $sql_upd = "UPDATE events SET 
                            event_name = ?, 
                            asset_id = ?, 
                            priority = ?,
                            start_time = ?,
                            end_time = ?
                            WHERE id = ?";
                $stmt_upd = $pdo->prepare($sql_upd);
                $stmt_upd->execute([$event_name, $asset_id, $priority, $start_utc, $end_utc, $event_id]);

                // Update Tags
                $pdo->prepare("DELETE FROM event_tags WHERE event_id = ?")->execute([$event_id]);
                $stmt_et = $pdo->prepare("INSERT INTO event_tags (event_id, tag_id) VALUES (?, ?)");
                foreach ($selected_tag_ids as $tag_id) {
                    $stmt_et->execute([$event_id, $tag_id]);
                }
            }

            $pdo->commit();
            $success_message = "Event updated successfully.";

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = "Database Error: " . $e->getMessage();
        }
    }
}

// Handle Delete
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'delete_event') {
    $update_scope = $_POST['update_scope'] ?? 'only_this';

    try {
        $pdo->beginTransaction();

        if ($is_series) {
            $recur_id = $event['recurring_event_id'];

            if ($update_scope == 'future' || $update_scope == 'all') {
                // End Series
                // If 'future', set end date to yesterday.
                // If 'all', well, we can't delete past history easily without breaking audit.
                // So 'delete' for a series really just means "Stop it now".

                // Determine end date
                // If deleting "this instance", we can't really.
                // If deleting "future", we set end date to yesterday.

                $start_date = (new DateTime($event['start_time']))->format('Y-m-d');
                $split_date = new DateTime($start_date);
                $split_date->modify('-1 day');
                $new_end_date = $split_date->format('Y-m-d');

                $stmt_upd = $pdo->prepare("UPDATE recurring_events SET end_date = ? WHERE id = ?");
                $stmt_upd->execute([$new_end_date, $recur_id]);
            }
            // 'only_this' delete not supported for recurring, maybe show error or disable button?

        } else {
            // One-off delete
            $pdo->prepare("DELETE FROM events WHERE id = ?")->execute([$event_id]);
        }

        $pdo->commit();
        header("Location: index.php?" . $back_query);
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        $errors[] = "Delete Error: " . $e->getMessage();
    }
}

// Prepare Display Data
$start_dt_local = (new DateTime($event['start_time'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/New_York'));
$end_dt_local = (new DateTime($event['end_time'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/New_York'));

$current_date = $start_dt_local->format('Y-m-d');
$current_start_time = $start_dt_local->format('H:i');
$current_end_time = $end_dt_local->format('H:i');

// Fetch assets with tags
$sql_assets = "
    SELECT a.id, a.filename_original, a.display_name, a.filename_disk, a.mime_type,
           GROUP_CONCAT(at.tag_id) as tag_ids
    FROM assets a
    LEFT JOIN asset_tags at ON a.id = at.asset_id
    GROUP BY a.id
    ORDER BY a.created_at DESC";
$all_assets = $pdo->query($sql_assets)->fetchAll(PDO::FETCH_ASSOC);

// Pre-select asset name
$selected_asset_name = "No Asset Selected";
if ($event['asset_id'] > 0) {
    foreach ($all_assets as $a) {
        if ($a['id'] == $event['asset_id']) {
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
    <title>Edit Event - WRHU Encoder Scheduler</title>
    <link rel="stylesheet" href="style.css">
    <script>
        function toggleRecurrence() {
            const val = document.getElementById('recurrence').value;
            const options = document.getElementById('recur-options');
            if (options) {
                options.style.display = val === 'none' ? 'none' : 'block';
                document.getElementById('recur-days-group').style.display = val === 'weekly' ? 'block' : 'none';
            }
        }

        function toggleRecurForever() {
            const forever = document.getElementById('recur_forever').checked;
            const until = document.getElementById('recur_until');
            if (until) {
                until.disabled = forever;
                if (forever) until.value = '';
            }
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
            <?php
            // Remove 'id' from query params for the back link
            $back_params = $_GET;
            unset($back_params['id']);
            ?>
            <a href="index.php?<?php echo http_build_query($back_params); ?>"
                style="color:var(--accent-color); text-decoration:none;">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
        <h1>Edit Event</h1>

        <?php if (!empty($errors)): ?>
            <div class="message error">
                <ul><?php foreach ($errors as $e)
                    echo "<li>$e</li>"; ?></ul>
            </div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="message success"><?php echo $success_message; ?></div>
        <?php endif; ?>
        <?php if ($is_series): ?>
            <div style="background:#2a2a2a; padding:15px; border:1px solid #444; border-radius:4px; margin-bottom:20px;">
                <h3 style="margin-top:0;">Recurrence Settings</h3>
                <div class="form-group">
                    <label>Recurrence Type</label>
                    <select name="recurrence" id="recurrence" onchange="toggleRecurrence()">
                        <option value="none" <?php if ($recurrence == 'none')
                            echo 'selected'; ?>>None (One-time)</option>
                        <option value="daily" <?php if ($recurrence == 'daily')
                            echo 'selected'; ?>>Daily</option>
                        <option value="weekly" <?php if ($recurrence == 'weekly')
                            echo 'selected'; ?>>Weekly</option>
                    </select>
                </div>

                <div id="recur-options" style="display:none;">
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
                    document.addEventListener('DOMContentLoaded', function () {
                        toggleRecurrence();
                        toggleRecurForever();
                    });
                </script>
            </div>
        <?php endif; ?>
        <div class="card">
            <form action="edit_event.php?<?php echo $_SERVER['QUERY_STRING']; ?>" method="POST">
                <input type="hidden" name="action" value="update_event">

                <div class="form-group">
                    <label>Event Name</label>
                    <input type="text" name="event_name" value="<?php echo htmlspecialchars($event['event_name']); ?>"
                        required>
                </div>



                <div class="form-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date" value="<?php echo $current_date; ?>" required>
                </div>

                <div class="row">
                    <div class="col">
                        <div class="form-group">
                            <label>Start Time</label>
                            <input type="time" name="start_time" value="<?php echo $current_start_time; ?>" required>
                        </div>
                    </div>
                    <div class="col">
                        <div class="form-group">
                            <label>End Time</label>
                            <input type="time" name="end_time" value="<?php echo $current_end_time; ?>" required>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col">
                        <div class="form-group">
                            <label>Output Tags</label>
                            <div class="tag-button-group" id="eventTagButtons"
                                style="display:flex; flex-wrap:wrap; gap:8px;">
                                <?php foreach ($tags as $tag): ?>
                                    <button type="button"
                                        class="tag-btn <?php echo in_array($tag['id'], $current_tag_ids) ? 'active' : ''; ?>"
                                        data-id="<?php echo $tag['id']; ?>" onclick="toggleEventTag(this)"
                                        style="padding:6px 12px; background:<?php echo in_array($tag['id'], $current_tag_ids) ? 'var(--accent-color)' : '#333'; ?>; border:1px solid <?php echo in_array($tag['id'], $current_tag_ids) ? 'var(--accent-color)' : '#555'; ?>; color:<?php echo in_array($tag['id'], $current_tag_ids) ? '#000' : '#ccc'; ?>; border-radius:20px; cursor:pointer; font-size:0.9em; font-weight:<?php echo in_array($tag['id'], $current_tag_ids) ? 'bold' : 'normal'; ?>;">
                                        <?php echo htmlspecialchars($tag['tag_name']); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            <div id="eventTagInputs">
                                <?php foreach ($current_tag_ids as $tid): ?>
                                    <input type="hidden" name="tag_ids[]" value="<?php echo $tid; ?>">
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="form-group">
                            <label>Priority</label>
                            <select name="priority">
                                <option value="0" <?php if ($event['priority'] == 0)
                                    echo 'selected'; ?>>Normal (Default)
                                </option>
                                <option value="1" <?php if ($event['priority'] == 1)
                                    echo 'selected'; ?>>Medium</option>
                                <option value="2" <?php if ($event['priority'] == 2)
                                    echo 'selected'; ?>>High (Preempts
                                    others)</option>
                            </select>
                            <small style="color:#aaa; display:block; margin-top:5px;">High priority events will
                                interrupt lower priority events playing at the same time.</small>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Asset</label>
                    <div style="display:flex; gap:10px;">
                        <input type="hidden" name="existing_asset_id" id="selected_asset_id"
                            value="<?php echo $event['asset_id']; ?>">
                        <input type="text" id="selected_asset_name" readonly
                            value="<?php echo htmlspecialchars($selected_asset_name); ?>"
                            style="flex:1; background:#333; border:1px solid #444; color:#fff; padding:8px;">
                        <button type="button" class="btn btn-secondary" onclick="openAssetModal()">Select Asset</button>
                    </div>
                    <div id="asset-warning" style="color:orange; display:none; margin-top:5px;"></div>
                </div>

                <div style="display:flex; gap:10px; align-items:center; flex-wrap:nowrap;">
                    <?php if ($is_series): ?>
                        <div style="display:flex; align-items:center; gap:5px; white-space:nowrap;">
                            <label for="update_scope" style="margin:0;">Update Scope:</label>
                            <select name="update_scope" id="update_scope"
                                style="padding: 10px; background: #333; color: #fff; border: 1px solid #444; border-radius: 4px;">
                                <?php $scope = $_POST['update_scope'] ?? 'all'; ?>
                                <option value="all" <?php if ($scope == 'all')
                                    echo 'selected'; ?>>Entire Series</option>
                                <option value="future" <?php if ($scope == 'future')
                                    echo 'selected'; ?>>This & Future
                                </option>
                                <option value="only_this" <?php if ($scope == 'only_this')
                                    echo 'selected'; ?>>Only This
                                    Instance</option>
                            </select>
                        </div>
                    <?php endif; ?>
                    <button type="submit" class="btn" style="flex:1; white-space:nowrap;">Update Event</button>
                </div>
            </form>

            <div style="display:flex; gap:10px; margin-top:1em;">
                <a href="create_event.php?duplicate_id=<?php echo $event_id; ?>" class="btn btn-secondary"
                    style="text-align:center; flex:1;">Duplicate Event</a>

                <form action="edit_event.php?<?php echo $_SERVER['QUERY_STRING']; ?>" method="POST"
                    onsubmit="return confirm('Delete this event?');" style="flex:1;">
                    <input type="hidden" name="action" value="delete_event">

                    <button type="submit" class="btn-delete" style="width:100%;">Delete Event</button>
                </form>
            </div>
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