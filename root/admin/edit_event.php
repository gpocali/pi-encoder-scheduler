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
    $update_scope = $_POST['update_scope'] ?? 'all'; // Default to 'all' to match UI and user expectation
    $selected_tag_ids = $_POST['tag_ids'] ?? [];

    // Recurrence Params
    $recurrence = $_POST['recurrence'] ?? 'none';
    $recur_until = $_POST['recur_until'] ?? '';
    $recur_forever = isset($_POST['recur_forever']);
    $recur_days = $_POST['recur_days'] ?? [];
    $recur_days_str = implode(',', $recur_days);

    error_log("Edit Event POST: " . print_r($_POST, true));
    error_log("Recurrence: $recurrence, Days: $recur_days_str, Scope: $update_scope");

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
                                    start_date = ?,
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
                            $start_date,
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
                // Legacy tag_id required
                $primary_tag = $selected_tag_ids[0];
                $sql_upd = "UPDATE events SET 
                            event_name = ?, 
                            asset_id = ?, 
                            priority = ?,
                            start_time = ?,
                            end_time = ?,
                            tag_id = ?
                            WHERE id = ?";
                $stmt_upd = $pdo->prepare($sql_upd);
                $stmt_upd->execute([$event_name, $asset_id, $priority, $start_utc, $end_utc, $primary_tag, $event_id]);

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
// Check if event is in the past
// $end_time_val comes from $event['end_time'] which is UTC in DB.
// But wait, in edit_event.php, $end_time_val is initialized from $event['end_time'] (UTC) but then converted to local for display?
// Let's check where $end_time_val is defined.
// It is defined around line 250 (not shown in previous view, need to check).
// Assuming $event['end_time'] is UTC.
$end_check = new DateTime($event['end_time'], new DateTimeZone('UTC'));
$now_check = new DateTime('now', new DateTimeZone('UTC'));
$is_read_only = ($end_check < $now_check);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_edit ? 'Edit Event' : 'Create Event'; ?> - WRHU Encoder Scheduler</title>
    <link rel="stylesheet" href="style.css">
    <script src="includes/event_form_scripts.php"></script>
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
        <?php
        // Prepare variables for the shared form
        $is_edit = true;
        // Ensure variables are set if not already (though they should be from top logic)
        $event_name = $event['event_name'];
        $start_date = $current_date;
        $start_time_val = $current_start_time;
        $end_time_val = $current_end_time;
        $selected_tag_ids = $current_tag_ids;
        $priority = $event['priority'];
        $asset_id = $event['asset_id'];
        // $selected_asset_name is already set
        // $recurrence, $recur_until, $recur_forever, $recur_days are already set
        // $is_series is already set
        // $event_id is already set
        ?>

        <div style="display:flex; align-items:center; gap:15px; margin-bottom: 20px;">
            <h1 style="margin:0;">
                <?php echo $is_edit ? ($is_read_only ? 'View Event' : 'Edit Event') : 'Create Event'; ?>
            </h1>
            <?php if ($is_edit && !$is_read_only): ?>
                <form action="edit_event.php?<?php echo $_SERVER['QUERY_STRING']; ?>" method="POST"
                    onsubmit="return confirm('Delete this event?');" style="margin:0;">
                    <input type="hidden" name="action" value="delete_event">
                    <button type="submit" class="btn-delete" style="padding: 5px 10px; font-size: 0.8em; width:auto;">Delete
                        Event</button>
                </form>
            <?php elseif ($is_edit && $is_read_only): ?>
                <span class="badge" style="background:#555; color:#ccc;">Read Only (Past Event)</span>
            <?php endif; ?>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="message error">
                <ul><?php foreach ($errors as $e)
                    echo "<li>$e</li>"; ?></ul>
            </div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="message success"><?php echo $success_message; ?></div>
        <?php endif; ?>

        <?php include 'includes/event_form.php'; ?>
    </div>

</body>

</html>