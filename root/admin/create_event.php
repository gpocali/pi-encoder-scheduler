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
$start_date = '';
$start_time_val = '';
$end_time_val = '';
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

// Handle Pre-fill from URL (e.g. from Gap Filling)
if (isset($_GET['start_date'])) {
    $start_date = $_GET['start_date'];
}
if (isset($_GET['start_time'])) {
    $start_time_val = $_GET['start_time'];
    // Default end time to +1 hour
    $dt = new DateTime($start_time_val);
    $dt->modify('+1 hour');
    $end_time_val = $dt->format('H:i');
}

// Capture Dashboard State
$dashboard_state = [
    'view' => $_GET['view'] ?? 'list',
    'date' => $_GET['date'] ?? date('Y-m-d'),
    'page' => $_GET['page'] ?? 1,
    'tag_id' => $_GET['tag_id'] ?? '',
    'type' => $_GET['type'] ?? '',
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

            // Handle Recurrence
            if ($recurrence != 'none') {
                // Insert into recurring_events
                $recur_days_str = ($recurrence == 'weekly') ? implode(',', $recur_days) : '';
                $end_date_val = ($recur_forever || empty($recur_until)) ? null : $recur_until;

                // Calculate duration in seconds
                $duration = $end_dt->getTimestamp() - $start_dt->getTimestamp();

                $sql_recur = "INSERT INTO recurring_events (event_name, start_time, duration, start_date, end_date, recurrence_type, recurrence_days, asset_id, priority) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt_recur = $pdo->prepare($sql_recur);
                $stmt_recur->execute([
                    $event_name,
                    $start_time_val, // Store local time as string 'HH:MM' or 'HH:MM:SS'
                    $duration,
                    $start_date,
                    $end_date_val,
                    $recurrence,
                    $recur_days_str,
                    $asset_id,
                    $priority
                ]);
                $recur_id = $pdo->lastInsertId();

                // Insert Tags for Recurring Event
                $stmt_ret = $pdo->prepare("INSERT INTO recurring_event_tags (recurring_event_id, tag_id) VALUES (?, ?)");
                foreach ($selected_tag_ids as $tid) {
                    $stmt_ret->execute([$recur_id, $tid]);
                }

            } else {
                // One-time Event (Insert into events table)
                $start_utc = $start_dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                $end_utc = $end_dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

                // Legacy tag_id required
                $primary_tag = $selected_tag_ids[0];
                $sql_event = "INSERT INTO events (event_name, start_time, end_time, asset_id, priority, tag_id) VALUES (?, ?, ?, ?, ?, ?)";
                $stmt_event = $pdo->prepare($sql_event);
                $stmt_event->execute([$event_name, $start_utc, $end_utc, $asset_id, $priority, $primary_tag]);
                $event_id = $pdo->lastInsertId();

                // Insert Tags
                $stmt_et = $pdo->prepare("INSERT INTO event_tags (event_id, tag_id) VALUES (?, ?)");
                foreach ($selected_tag_ids as $tid) {
                    $stmt_et->execute([$event_id, $tid]);
                }
            }

            $pdo->commit();

            // Restore Dashboard State
            $redirect_params = [
                'view' => $_POST['dashboard_view'] ?? 'list',
                'date' => $_POST['dashboard_date'] ?? date('Y-m-d'),
                'page' => $_POST['dashboard_page'] ?? 1,
                'tag_id' => $_POST['dashboard_tag_id'] ?? '',
                'type' => $_POST['dashboard_type'] ?? '',
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
<?php
// ... PHP logic remains above ...
$is_edit = false;
$is_series = false; // Not used in create, but good to define
$event_id = 0;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Event - WRHU Encoder Scheduler</title>
    <link rel="stylesheet" href="style.css">
    <?php include 'includes/event_form_scripts.php'; ?>
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

        <?php include 'includes/event_form.php'; ?>
    </div>

    <footer>
        &copy;<?php echo date("Y") > 2025 ? "2025-" . date("Y") : "2025"; ?> WRHU Radio Hofstra University. Written by
        Gregory Pocali for WRHU with assistance from Google Gemini 3.
    </footer>

</body>

</html>