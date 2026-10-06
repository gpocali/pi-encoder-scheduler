<?php
require_once 'auth.php';
require_once '../db_connect.php';
require_once 'ScheduleLogic.php';
require_once 'includes/EventRepository.php';
date_default_timezone_set('America/New_York');

// Helper to get current URL with modified params
function urlWithParam($key, $val)
{
    $params = $_GET;
    $params[$key] = $val;
    return '?' . http_build_query($params);
}

$user_id = $_SESSION['user_id'];
$is_admin = is_admin();
$allowed_tag_ids = [];

if ($is_admin || has_role('user')) {
    $stmt = $pdo->query("SELECT id, tag_name FROM tags ORDER BY tag_name");
    $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $allowed_tag_ids = array_column($tags, 'id');
} else {
    $stmt = $pdo->prepare("SELECT t.id, t.tag_name FROM tags t JOIN user_tags ut ON t.id = ut.tag_id WHERE ut.user_id = ? ORDER BY t.tag_name");
    $stmt->execute([$user_id]);
    $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $allowed_tag_ids = array_column($tags, 'id');
}

$all_tags_stmt = $pdo->query("SELECT id, tag_name FROM tags");
$tag_names_by_id = $all_tags_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

function getEventTagNames($ev, $pdo, $tag_names_by_id)
{
    static $tags_cache = [];
    $ev_id = $ev['id'] ?? null;

    if (!$ev_id) {
        if (!empty($ev['tag_id']) && isset($tag_names_by_id[$ev['tag_id']])) {
            return [$tag_names_by_id[$ev['tag_id']]];
        }
        return [];
    }

    if (isset($tags_cache[$ev_id])) {
        return $tags_cache[$ev_id];
    }

    // Check if it's a recurring instance: recur_{series_id}_{timestamp}
    if (is_string($ev_id) && strpos($ev_id, 'recur_') === 0) {
        $parts = explode('_', $ev_id);
        $recur_id = (int) $parts[1];
        $stmt = $pdo->prepare("SELECT t.tag_name FROM recurring_event_tags ret JOIN tags t ON ret.tag_id = t.id WHERE ret.recurring_event_id = ? ORDER BY t.tag_name");
        $stmt->execute([$recur_id]);
        $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($names) && !empty($ev['tag_id']) && isset($tag_names_by_id[$ev['tag_id']])) {
            $names = [$tag_names_by_id[$ev['tag_id']]];
        }
        $tags_cache[$ev_id] = $names;
        return $names;
    }

    // Standard one-off event (or exception)
    $stmt = $pdo->prepare("SELECT t.tag_name FROM event_tags et JOIN tags t ON et.tag_id = t.id WHERE et.event_id = ? ORDER BY t.tag_name");
    $stmt->execute([(int) $ev_id]);
    $names = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($names) && !empty($ev['tag_id']) && isset($tag_names_by_id[$ev['tag_id']])) {
        $names = [$tag_names_by_id[$ev['tag_id']]];
    }

    if (empty($names) && !empty($ev['tag_names'])) {
        $names = array_map('trim', explode(',', $ev['tag_names']));
    }

    $tags_cache[$ev_id] = $names;
    return $names;
}

// Handle Actions (End Now, Delete)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['action']) && $_POST['action'] == 'end_now') {
        $event_id = (int) $_POST['event_id'];
        // Verify permission (Check ALL tags)
        $stmt = $pdo->prepare("SELECT tag_id FROM event_tags WHERE event_id = ?");
        $stmt->execute([$event_id]);
        $evt_tags = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $has_perm = false;
        foreach ($evt_tags as $tid) {
            if (in_array($tid, $allowed_tag_ids)) {
                $has_perm = true;
                break;
            }
        }

        if ($has_perm) {
            $now_utc = (new DateTime())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $pdo->prepare("UPDATE events SET end_time = ? WHERE id = ?")->execute([$now_utc, $event_id]);
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
        }
    }

    if (isset($_POST['action']) && $_POST['action'] == 'extend_event') {
        $event_id = (int) $_POST['event_id'];
        // Verify permission (Check ALL tags)
        $stmt = $pdo->prepare("SELECT tag_id FROM event_tags WHERE event_id = ?");
        $stmt->execute([$event_id]);
        $evt_tags = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $has_perm = false;
        foreach ($evt_tags as $tid) {
            if (in_array($tid, $allowed_tag_ids)) {
                $has_perm = true;
                break;
            }
        }

        if ($has_perm) {
            // Need current end time
            $stmt_end = $pdo->prepare("SELECT end_time FROM events WHERE id = ?");
            $stmt_end->execute([$event_id]);
            $current_end = $stmt_end->fetchColumn();

            if ($current_end) {
                $new_end = date('Y-m-d H:i:s', strtotime($current_end . ' +15 minutes'));
                $pdo->prepare("UPDATE events SET end_time = ? WHERE id = ?")->execute([$new_end, $event_id]);
                header("Location: " . $_SERVER['REQUEST_URI']);
                exit;
            }
        }
    }
    if (isset($_POST['action']) && $_POST['action'] == 'delete_event') {
        $event_id = (int) $_POST['event_id'];
        $stmt = $pdo->prepare("SELECT tag_id FROM event_tags WHERE event_id = ?");
        $stmt->execute([$event_id]);
        $evt_tags = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $has_perm = false;
        foreach ($evt_tags as $tid) {
            if (in_array($tid, $allowed_tag_ids)) {
                $has_perm = true;
                break;
            }
        }

        if ($has_perm) {
            $pdo->prepare("DELETE FROM events WHERE id = ?")->execute([$event_id]);
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
        }
    }
}

// --- LIVE MONITOR DATA ---
$live_status = [];
$repo = new EventRepository($pdo);

foreach ($tags as $tag) {
    $live_event = $repo->getCurrentEvent($tag['tag_name']);

    if ($live_event) {
        $live_status[$tag['id']] = [
            'type' => 'event',
            'data' => $live_event,
            'tag_name' => $tag['tag_name']
        ];
    } else {
        // Fallback to default
        $sql_def = "SELECT a.id, a.filename_disk, a.filename_original, a.mime_type 
                    FROM default_assets da 
                    JOIN assets a ON da.asset_id = a.id 
                    WHERE da.tag_id = ?";
        $stmt_def = $pdo->prepare($sql_def);
        $stmt_def->execute([$tag['id']]);
        $def_asset = $stmt_def->fetch(PDO::FETCH_ASSOC);

        $live_status[$tag['id']] = [
            'type' => 'default',
            'data' => $def_asset,
            'tag_name' => $tag['tag_name']
        ];
    }
}

// --- FILTERS & VIEWS ---
$view = $_GET['view'] ?? 'list';
$filter_tag = isset($_GET['tag_id']) && $_GET['tag_id'] !== '' ? (int) $_GET['tag_id'] : null;
$filter_date = $_GET['date'] ?? date('Y-m-d');
$filter_type = $_GET['type'] ?? 'all';
$sort_col = $_GET['sort'] ?? 'start_time';
$sort_order = $_GET['order'] ?? 'asc';
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$per_page = 20;

// $repo is already initialized
$events = [];
$total_pages = 1;

if ($view == 'list') {
    // Consolidated List View
    // 1. Get Recurring Series
    $series = $repo->getRecurringSeries($filter_tag);

    // 2. Get Future One-Offs (and Exceptions)
    $oneOffs = $repo->getFutureEvents($filter_tag);

    // Merge and Filter
    $combined = [];

    // Process Series
    if ($filter_type == 'all' || $filter_type == 'recurring') {
        foreach ($series as $s) {
            $s['type'] = 'series';
            $s['sort_time'] = $s['start_date'] . ' ' . $s['start_time'];
            $combined[] = $s;
        }
    }

    // Process One-Offs
    if ($filter_type == 'all' || $filter_type == 'one_off') {
        foreach ($oneOffs as $e) {
            $e['type'] = 'event';
            $dt = new DateTime($e['start_time'], new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone('America/New_York'));
            $e['sort_time'] = $dt->format('Y-m-d H:i:s');
            $combined[] = $e;
        }
    }

    // Sort
    usort($combined, function ($a, $b) use ($sort_col, $sort_order) {
        $valA = '';
        $valB = '';

        switch ($sort_col) {
            case 'status':
                // Determine status string for sorting
                $valA = isset($a['type']) && $a['type'] == 'series' ? 'Recurring' : 'Future'; // Simplified
                $valB = isset($b['type']) && $b['type'] == 'series' ? 'Recurring' : 'Future';
                break;
            case 'priority':
                $valA = $a['priority'];
                $valB = $b['priority'];
                break;
            case 'name':
                $valA = strtolower($a['event_name']);
                $valB = strtolower($b['event_name']);
                break;
            case 'start_time':
            default:
                $valA = $a['sort_time'];
                $valB = $b['sort_time'];
                break;
        }

        if ($valA == $valB)
            return 0;

        // Numeric comparison for priority
        if ($sort_col == 'priority') {
            return ($sort_order == 'asc') ? ($valA - $valB) : ($valB - $valA);
        }

        // String comparison for others
        return ($sort_order == 'asc') ? strcmp($valA, $valB) : strcmp($valB, $valA);
    });

    // Pagination (PHP side)
    $total_items = count($combined);
    $total_pages = ceil($total_items / $per_page);
    $offset = ($page - 1) * $per_page;
    $events = array_slice($combined, $offset, $per_page);

} elseif ($view == 'month') {
    // ... (rest of month view logic remains same, just need to close the if block correctly later)
    $start_month = date('Y-m-01', strtotime($filter_date));
    $end_month = date('Y-m-t', strtotime($filter_date));

    $start_utc = (new DateTime($start_month . ' 00:00:00'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $end_utc = (new DateTime($end_month . ' 23:59:59'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

    $raw_events = $repo->getEvents($start_utc, $end_utc, $filter_tag);
    $resolved = ScheduleLogic::resolveSchedule($raw_events);
    $resolved = ScheduleLogic::deduplicateSegments($resolved);

    // Group by day
    $month_start_ts = strtotime($start_month);
    $year = date('Y', $month_start_ts);
    $month = date('m', $month_start_ts);
    $days_in_month = date('t', $month_start_ts);

    foreach ($resolved as $ev) {
        $ev_start = (new DateTime($ev['start_time'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/New_York'));
        $ev_end = (new DateTime($ev['end_time'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/New_York'));

        for ($d = 1; $d <= $days_in_month; $d++) {
            $day_start = new DateTime("$year-$month-$d 00:00:00", new DateTimeZone('America/New_York'));
            $day_end = clone $day_start;
            $day_end->modify('+1 day');

            if ($ev_start < $day_end && $ev_end > $day_start) {
                $events[$d][] = $ev;
            }
        }
    }

} elseif ($view == 'week') {
    // ... (rest of week view logic)
    $dt = new DateTime($filter_date);
    if ($dt->format('w') != 0) {
        $dt->modify('last sunday');
    }
    $start_week = $dt->format('Y-m-d');
    $dt->modify('+6 days');
    $end_week = $dt->format('Y-m-d');

    $start_utc = (new DateTime($start_week . ' 00:00:00'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $end_utc = (new DateTime($end_week . ' 23:59:59'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

    $raw_events = $repo->getEvents($start_utc, $end_utc, $filter_tag);
    $resolved = ScheduleLogic::resolveSchedule($raw_events);
    $resolved = ScheduleLogic::deduplicateSegments($resolved);

    // Group by Date
    $week_dates = [];
    $dt = new DateTime($start_week);
    for ($i = 0; $i < 7; $i++) {
        $week_dates[] = $dt->format('Y-m-d');
        $dt->modify('+1 day');
    }

    foreach ($resolved as $ev) {
        $ev_start = (new DateTime($ev['start_time'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/New_York'));
        $ev_end = (new DateTime($ev['end_time'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/New_York'));

        foreach ($week_dates as $date_str) {
            $day_start = new DateTime("$date_str 00:00:00", new DateTimeZone('America/New_York'));
            $day_end = clone $day_start;
            $day_end->modify('+1 day');

            if ($ev_start < $day_end && $ev_end > $day_start) {
                $events[$date_str][] = $ev;
            }
        }
    }

} elseif ($view == 'day') {
    $start_utc = (new DateTime($filter_date . ' 00:00:00'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $end_utc = (new DateTime($filter_date . ' 23:59:59'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

    $raw_events = $repo->getEvents($start_utc, $end_utc, $filter_tag);
    $events = ScheduleLogic::resolveSchedule($raw_events);
    $events = ScheduleLogic::deduplicateSegments($events);
    $events = ScheduleLogic::fillGaps($events, $filter_date);
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Dashboard - WRHU Encoder Scheduler</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Auto-refresh every 60 seconds to update Live Monitor (paused when viewing stats modal)
        setTimeout(function autoReload() {
            var statsModal = document.getElementById('eventStatsModal');
            if (statsModal && statsModal.style.display === 'block') {
                setTimeout(autoReload, 30000);
                return;
            }
            window.location.reload();
        }, 60000);

        // Scroll Preservation
        document.addEventListener("DOMContentLoaded", function () {
            var scrollPos = sessionStorage.getItem('scrollPos');
            if (scrollPos) {
                window.scrollTo(0, scrollPos);
                sessionStorage.removeItem('scrollPos');
            }

            // Bind saveScroll to all forms
            var forms = document.querySelectorAll('form');
            forms.forEach(function (f) {
                f.addEventListener('submit', function () {
                    sessionStorage.setItem('scrollPos', window.scrollY);
                });
            });
        });
    </script>
</head>

<body>

    <?php include 'navbar.php'; ?>

    <div class="container">

        <!-- Live Monitor -->
        <h2 style="margin-top:0;">Live Monitor</h2>
        <div class="live-monitor">
            <?php foreach ($live_status as $tag_id => $status): ?>
                <div class="monitor-card">
                    <?php
                    $asset = $status['data'];
                    $is_live = $status['type'] == 'event';
                    $thumb_url = '';
                    if ($asset) {
                        $asset_pk = $is_live ? $asset['asset_id'] : $asset['id'];
                        $file_url = 'serve_asset.php?id=' . $asset_pk;
                        $is_img = strpos($asset['mime_type'], 'image') !== false;
                        $is_vid = strpos($asset['mime_type'], 'video') !== false;
                    }
                    ?>
                    <div class="monitor-thumb"
                        onclick="showPreview('<?php echo $file_url; ?>', '<?php echo $asset['mime_type']; ?>')"
                        style="display:grid; place-items:center; overflow:hidden; cursor:pointer;">
                        <?php if ($asset): ?>
                            <?php if (strpos($asset['mime_type'], 'image') !== false): ?>
                                <img src="<?php echo $file_url; ?>" style="width:100%; height:100%; object-fit:cover;">
                            <?php elseif (strpos($asset['mime_type'], 'video') !== false): ?>
                                <video src="<?php echo $file_url; ?>" style="width:100%; height:100%; object-fit:cover;" muted loop
                                    autoplay></video>
                            <?php else: ?>
                                <span style="color:#555;">No Preview</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:#555;">No Signal</span>
                        <?php endif; ?>
                    </div>

                    <div class="monitor-info">
                        <div class="monitor-tag"><?php echo htmlspecialchars($status['tag_name']); ?></div>
                        <div class="monitor-title">
                            <?php echo $asset ? htmlspecialchars($is_live ? $asset['event_name'] : $asset['filename_original']) : 'Nothing Scheduled'; ?>
                        </div>
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <span class="badge <?php echo $is_live ? 'badge-live' : 'badge-default'; ?>">
                                <?php echo $is_live ? 'LIVE' : 'DEFAULT'; ?>
                            </span>
                            <?php if ($is_live): ?>
                                <div style="display:flex; gap:5px;">
                                    <?php if ($asset['recurrence_type'] == 'none'): ?>
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="action" value="extend_event">
                                            <input type="hidden" name="event_id" value="<?php echo $asset['id']; ?>">
                                            <button type="submit" title="Add 15 Minutes to the end of this event"
                                                style="background:var(--secondary-color); color:#fff; border:none; border-radius:4px; padding:4px 8px; cursor:pointer; font-size:0.8em;">+15m</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="action" value="end_now">
                                        <input type="hidden" name="event_id" value="<?php echo $asset['id']; ?>">
                                        <button type="submit" title="End this event now and return to normal programming"
                                            onclick="return confirm('Are you sure you want to end this live event early?');"
                                            style="background:var(--error-color); color:#fff; border:none; border-radius:4px; padding:4px 8px; cursor:pointer; font-size:0.8em;">End
                                            Now</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Scheduler Controls -->
        <div class="controls"
            style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1em; flex-wrap: wrap; gap: 10px;">
            <form class="filters" method="GET" style="display: flex; gap: 10px; align-items: center;">
                <input type="hidden" name="view" value="<?php echo $view; ?>">
                <select name="tag_id" onchange="this.form.submit()">
                    <option value="">All Tags</option>
                    <?php foreach ($tags as $t): ?>
                        <option value="<?php echo $t['id']; ?>" <?php if ($filter_tag == $t['id'])
                               echo 'selected'; ?>>
                            <?php echo htmlspecialchars($t['tag_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php if ($view == 'list'): ?>
                    <select name="type" onchange="this.form.submit()">
                        <option value="all" <?php if ($filter_type == 'all')
                            echo 'selected'; ?>>All Types</option>
                        <option value="recurring" <?php if ($filter_type == 'recurring')
                            echo 'selected'; ?>>Recurring Only
                        </option>
                        <option value="one_off" <?php if ($filter_type == 'one_off')
                            echo 'selected'; ?>>One-off Only</option>
                    </select>
                <?php endif; ?>

                <?php if ($view != 'list'): ?>
                    <input type="date" name="date" value="<?php echo $filter_date; ?>" onchange="this.form.submit()">
                <?php endif; ?>
            </form>

            <a href="create_event.php?<?php echo http_build_query($_GET); ?>" class="btn btn-secondary">+ New Event</a>
        </div>

        <!-- View Tabs -->
        <div class="view-tabs">
            <a href="<?php echo urlWithParam('view', 'list'); ?>" class="view-tab <?php if ($view == 'list')
                    echo 'active'; ?>">List</a>
            <a href="<?php echo urlWithParam('view', 'day'); ?>" class="view-tab <?php if ($view == 'day')
                    echo 'active'; ?>">Day</a>
            <a href="<?php echo urlWithParam('view', 'week'); ?>" class="view-tab <?php if ($view == 'week')
                    echo 'active'; ?>">Week</a>
            <a href="<?php echo urlWithParam('view', 'month'); ?>" class="view-tab <?php if ($view == 'month')
                    echo 'active'; ?>">Month</a>
        </div>

        <!-- Views Content -->
        <?php if ($view == 'list'): ?>
            <table>
                <thead>
                    <tr>
                        <th><a href="<?php echo urlWithParam('sort', 'status') . '&order=' . ($sort_col == 'status' && $sort_order == 'asc' ? 'desc' : 'asc'); ?>"
                                style="color:inherit; text-decoration:none;">Status
                                <?php if ($sort_col == 'status')
                                    echo $sort_order == 'asc' ? '▲' : '▼'; ?></a></th>
                        <th><a href="<?php echo urlWithParam('sort', 'priority') . '&order=' . ($sort_col == 'priority' && $sort_order == 'asc' ? 'desc' : 'asc'); ?>"
                                style="color:inherit; text-decoration:none;">Priority
                                <?php if ($sort_col == 'priority')
                                    echo $sort_order == 'asc' ? '▲' : '▼'; ?></a></th>
                        <th><a href="<?php echo urlWithParam('sort', 'name') . '&order=' . ($sort_col == 'name' && $sort_order == 'asc' ? 'desc' : 'asc'); ?>"
                                style="color:inherit; text-decoration:none;">Event Name
                                <?php if ($sort_col == 'name')
                                    echo $sort_order == 'asc' ? '▲' : '▼'; ?></a></th>
                        <th>Tag</th>
                        <th><a href="<?php echo urlWithParam('sort', 'start_time') . '&order=' . ($sort_col == 'start_time' && $sort_order == 'asc' ? 'desc' : 'asc'); ?>"
                                style="color:inherit; text-decoration:none;">Start Time
                                <?php if ($sort_col == 'start_time')
                                    echo $sort_order == 'asc' ? '▲' : '▼'; ?></a></th>
                        <th>End Time</th>
                        <th>Asset</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <tbody>
                    <?php foreach ($events as $ev):
                        $live_tags = [];
                        $is_series = isset($ev['type']) && $ev['type'] == 'series';
                        $is_exception = !empty($ev['is_exception']);

                        if ($is_series) {
                            $status = 'Recurring';
                            $status_color = 'var(--accent-color)';

                            // Recurrence Pattern
                            $recur_info = ucfirst($ev['recurrence_type']);
                            if ($ev['recurrence_type'] == 'weekly' && !empty($ev['recurrence_days'])) {
                                $days_map = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                                $days = explode(',', $ev['recurrence_days']);
                                $day_names = array_map(function ($d) use ($days_map) {
                                    return $days_map[$d];
                                }, $days);
                                $recur_info .= ' (' . implode(', ', $day_names) . ')';
                            }
                            // Format Time to AM/PM
                            $time_obj = new DateTime($ev['start_time']); // Local time string
                            $end_time_obj = clone $time_obj;
                            $end_time_obj->modify("+{$ev['duration']} seconds");

                            $start_display = $time_obj->format('g:i A') . '<br><small>Starts: ' . $ev['start_date'] . '</small>';
                            $end_display = $end_time_obj->format('g:i A') . '<br><small>' . $recur_info . '</small>';

                            $edit_link = "edit_event.php?id=recur_" . $ev['id'] . "_0"; // 0 timestamp for series edit? Or just recur_ID
                            // Actually edit_event expects recur_{id}_{timestamp} for instances.
                            // But for editing the SERIES definition, we might need a different way or just pick a dummy timestamp.
                            // Let's pass a special flag or just handle it in edit_event.
                            // Wait, edit_event logic I wrote expects `recur_ID_timestamp`.
                            // If I want to edit the series *definition*, I should probably link to an instance or handle "recur_ID" without timestamp.
                            // Let's update edit_event to handle "recur_ID" without timestamp later if needed, 
                            // but for now let's link to the NEXT instance? 
                            // Or just link to "recur_{id}_0" and handle 0 in edit_event as "Series Edit Mode".
                            // For now, let's use a dummy timestamp 0.
                            $edit_link = "edit_event.php?id=recur_" . $ev['id'] . "_0&" . http_build_query($_GET);

                            // Check if this recurring series is currently live
                            foreach ($live_status as $tag_id => $l_status) {
                                if ($l_status['type'] == 'event' && isset($l_status['data']['recurring_event_id']) && $l_status['data']['recurring_event_id'] == $ev['id']) {
                                    $live_tags[] = $l_status['tag_name'];
                                }
                            }


                        } else {
                            // One-off / Exception
                            $start = new DateTime($ev['start_time'], new DateTimeZone('UTC'));
                            $end = new DateTime($ev['end_time'], new DateTimeZone('UTC'));
                            $now = new DateTime(null, new DateTimeZone('UTC'));

                            $status = 'Future';
                            $status_color = '#aaa';
                            if ($end < $now) {
                                $status = 'Past';
                                $status_color = '#555';
                            } elseif ($start <= $now && $end > $now) {
                                $status = 'Live';
                                $status_color = 'var(--error-color)';
                            }

                            if ($is_exception) {
                                $status = 'Exception';
                                $status_color = 'orange';
                            }

                            $start_local = $start->setTimezone(new DateTimeZone('America/New_York'));
                            $format = ($start_local->format('Y') != date('Y')) ? 'M j, Y, g:i A' : 'M j, g:i A';
                            $start_display = $start_local->format($format);

                            $end_local = $end->setTimezone(new DateTimeZone('America/New_York'));
                            $format = ($end_local->format('Y') != date('Y')) ? 'M j, Y, g:i A' : 'M j, g:i A';
                            $end_display = $end_local->format($format);

                            $edit_link = "edit_event.php?id=" . $ev['id'] . "&" . http_build_query($_GET);
                        }
                        ?>
                        <?php
                        $is_default_gap = isset($ev['type']) && $ev['type'] == 'default_gap';
                        ?>
                        <tr>
                            <td>
                                <?php if (!empty($live_tags)): ?>
                                    <?php foreach ($live_tags as $ltag): ?>
                                        <div class="badge badge-live" style="margin-bottom:2px;">Live:
                                            <?php echo htmlspecialchars($ltag); ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <span
                                    style="color:<?php echo $status_color; ?>; font-weight:bold;"><?php echo $status; ?></span>
                            </td>
                            <td>
                                <?php
                                $prio_label = 'Normal';
                                $prio_class = 'p-0';
                                if ($ev['priority'] == 2) {
                                    $prio_label = 'High';
                                    $prio_class = 'p-2';
                                } elseif ($ev['priority'] == 1) {
                                    $prio_label = 'Medium';
                                    $prio_class = 'p-1';
                                } elseif ($ev['priority'] == -1) {
                                    $prio_label = 'None';
                                    $prio_class = 'p-0';
                                }
                                ?>
                                <span class="priority-badge <?php echo $prio_class; ?>"><?php echo $prio_label; ?></span>
                            </td>
                            <td>
                                <?php
                                $ev_tags_array = getEventTagNames($ev, $pdo, $tag_names_by_id);
                                $ev_tag_names = implode(', ', $ev_tags_array);
                                $ev_tags_data = implode(',', $ev_tags_array);
                                ?>
                                <?php if ($status == 'Past' && !$is_default_gap && isset($start) && isset($end)): ?>
                                    <span class="event-name-stats-clickable" title="Click to view listener statistics" style="cursor:pointer; text-decoration:underline; font-weight:600; color:var(--accent-color);"
                                        data-event-id="<?php echo htmlspecialchars($ev['id']); ?>"
                                        data-event-name="<?php echo htmlspecialchars($ev['event_name'], ENT_QUOTES); ?>"
                                        data-start-ts="<?php echo $start->getTimestamp(); ?>"
                                        data-end-ts="<?php echo $end->getTimestamp(); ?>"
                                        data-start-display="<?php echo htmlspecialchars($start_display); ?>"
                                        data-end-display="<?php echo htmlspecialchars($end_display); ?>"
                                        data-tags="<?php echo htmlspecialchars($ev_tags_data, ENT_QUOTES); ?>"
                                        data-tag-names="<?php echo htmlspecialchars($ev_tag_names, ENT_QUOTES); ?>">
                                        <?php echo htmlspecialchars($ev['event_name']); ?> <i class="bi bi-graph-up" style="font-size:0.8em;"></i>
                                    </span>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($ev['event_name']); ?>
                                <?php endif; ?>
                                <?php if (!empty($ev['is_modified'])) echo ' <small style="color:orange;">(Modified)</small>'; ?>
                            </td>
                            <td>
                                <?php
                                // Tags might be pre-fetched in 'tag_names' for series/one-offs by Repository
                                if (isset($ev['tag_names'])) {
                                    echo htmlspecialchars($ev['tag_names']);
                                } elseif (!$is_default_gap) {
                                    // Fallback query
                                    $stmt_t = $pdo->prepare("SELECT t.tag_name FROM event_tags et JOIN tags t ON et.tag_id = t.id WHERE et.event_id = ?");
                                    $stmt_t->execute([$ev['id']]);
                                    $tag_names = $stmt_t->fetchAll(PDO::FETCH_COLUMN);
                                    echo htmlspecialchars(implode(', ', $tag_names));
                                }
                                ?>
                            </td>
                            <td><?php echo $start_display; ?></td>
                            <td><?php echo $end_display; ?></td>
                            <td><?php echo htmlspecialchars($ev['filename_original'] ?? 'N/A'); ?></td>
                            <td>
                                <?php if ($is_default_gap): ?>
                                    <?php
                                    // Link to create event with pre-filled start time
                                    // $start_local is available from the 'else' block above? 
                                    // Wait, the 'else' block above (lines 517-547) handles one-offs AND default gaps (since is_series is false).
                                    // So $start_local is set.
                                    $add_url = "create_event.php?start_date=" . $start_local->format('Y-m-d') . "&start_time=" . $start_local->format('H:i');
                                    ?>
                                    <a href="<?php echo $add_url; ?>" class="btn btn-sm"
                                        style="background-color: #28a745; color: #fff; border: none;">Add Event</a>
                                <?php else: ?>
                                    <?php
                                    $btn_text = 'Edit';
                                    $btn_class = 'btn-secondary';
                                    if ($status == 'Past') {
                                        $btn_text = 'View';
                                        $btn_class = ''; // Default style
                                    }
                                    ?>
                                    <a href="<?php echo $edit_link; ?>" class="btn btn-sm <?php echo $btn_class; ?>"
                                        style="<?php echo $status == 'Past' ? 'background:#555; color:#aaa;' : ''; ?>"><?php echo $btn_text; ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <div class="pagination">
                <?php
                $range = 5;
                $show_first = ($page > $range + 1);
                $show_last = ($page < $total_pages - $range);

                if ($show_first) {
                    echo '<a href="' . urlWithParam('page', 1) . '" class="page-link">1</a>';
                    echo '<span class="page-link" style="border:none; background:none;">...</span>';
                }

                for ($i = max(1, $page - $range); $i <= min($total_pages, $page + $range); $i++) {
                    $active = ($page == $i) ? 'active' : '';
                    echo '<a href="' . urlWithParam('page', $i) . '" class="page-link ' . $active . '">' . $i . '</a>';
                }

                if ($show_last) {
                    echo '<span class="page-link" style="border:none; background:none;">...</span>';
                    echo '<a href="' . urlWithParam('page', $total_pages) . '" class="page-link">' . $total_pages . '</a>';
                }
                ?>
            </div>

        <?php elseif ($view == 'month'): ?>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <a href="<?php echo urlWithParam('date', date('Y-m-d', strtotime($filter_date . ' -1 month'))); ?>"
                    class="btn btn-sm btn-secondary">&laquo; Previous Month</a>
                <h3 style="margin:0;"><?php echo date('F Y', strtotime($start_month)); ?></h3>
                <a href="<?php echo urlWithParam('date', date('Y-m-d', strtotime($filter_date . ' +1 month'))); ?>"
                    class="btn btn-sm btn-secondary">Next Month &raquo;</a>
            </div>
            <div class="calendar-grid">
                <div class="cal-header">Sun</div>
                <div class="cal-header">Mon</div>
                <div class="cal-header">Tue</div>
                <div class="cal-header">Wed</div>
                <div class="cal-header">Thu</div>
                <div class="cal-header">Fri</div>
                <div class="cal-header">Sat</div>
                <?php
                $first_day = date('w', strtotime($start_month));
                $days_in_month = date('t', strtotime($start_month));

                // Empty slots
                for ($i = 0; $i < $first_day; $i++)
                    echo '<div class="cal-day" style="background:#1a1a1a;"></div>';

                for ($d = 1; $d <= $days_in_month; $d++) {
                    $is_today = ($d == date('j') && $month == date('m') && $year == date('Y'));
                    echo '<div class="cal-day' . ($is_today ? ' current-day' : '') . '">';
                    echo '<div class="cal-date">' . $d . '</div>';
                    if (isset($events[$d])) {
                        foreach ($events[$d] as $ev) {
                            $start_utc = new DateTime($ev['start_time'], new DateTimeZone('UTC'));
                            $end_utc = new DateTime($ev['end_time'], new DateTimeZone('UTC'));
                            $now_utc = new DateTime('now', new DateTimeZone('UTC'));

                            $status_class = 'status-future';
                            if ($end_utc < $now_utc) {
                                $status_class = 'status-past';
                            } elseif ($start_utc <= $now_utc && $end_utc > $now_utc) {
                                $status_class = 'status-live';
                            }

                            $time_obj = $start_utc->setTimezone(new DateTimeZone('America/New_York'));
                            $time = $time_obj->format('H:i');

                            $is_gap = isset($ev['type']) && $ev['type'] == 'default_gap';
                            if ($is_gap) {
                                // Month view date construction
                                $this_date = date('Y-m-d', strtotime("$start_month + " . ($d - 1) . " days"));
                                $link_url = "create_event.php?start_date=" . $this_date . "&start_time=" . $time;
                                $status_class = ''; // No status for gaps
                            } else {
                                $link_url = "edit_event.php?id=" . $ev['id'];
                            }

                            if ($status_class === 'status-past' && !$is_gap) {
                                $start_local = (clone $start_utc)->setTimezone(new DateTimeZone('America/New_York'));
                                $end_local = (clone $end_utc)->setTimezone(new DateTimeZone('America/New_York'));
                                $start_display_str = $start_local->format('D, M j, Y g:i A');
                                $end_display_str = $end_local->format('g:i A');
                                $ev_tags_array = getEventTagNames($ev, $pdo, $tag_names_by_id);
                                $ev_tag_names = implode(', ', $ev_tags_array);
                                $ev_tags_data = implode(',', $ev_tags_array);

                                echo '<div class="cal-event priority-' . $ev['priority'] . ' ' . $status_class . '" style="display:flex; justify-content:space-between; align-items:center; gap:4px;">';
                                echo '<span class="event-name-stats-clickable" title="Click to view listener statistics" style="cursor:pointer; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; flex-grow:1;" ' .
                                    'data-event-id="' . htmlspecialchars($ev['id']) . '" ' .
                                    'data-event-name="' . htmlspecialchars($ev['event_name'], ENT_QUOTES) . '" ' .
                                    'data-start-ts="' . $start_utc->getTimestamp() . '" ' .
                                    'data-end-ts="' . $end_utc->getTimestamp() . '" ' .
                                    'data-start-display="' . htmlspecialchars($start_display_str) . '" ' .
                                    'data-end-display="' . htmlspecialchars($end_display_str) . '" ' .
                                    'data-tags="' . htmlspecialchars($ev_tags_data, ENT_QUOTES) . '" ' .
                                    'data-tag-names="' . htmlspecialchars($ev_tag_names, ENT_QUOTES) . '">';
                                echo $time . ' <span style="text-decoration:underline;">' . htmlspecialchars($ev['event_name']) . '</span> <i class="bi bi-graph-up" style="font-size:0.75em;"></i>';
                                echo '</span>';
                                echo '<a href="' . $link_url . '" title="View event details" style="color:#777; flex-shrink:0; text-decoration:none;"><i class="bi bi-info-circle"></i></a>';
                                echo '</div>';
                            } else {
                                echo '<a href="' . $link_url . '" class="cal-event priority-' . $ev['priority'] . ' ' . $status_class . '">' . $time . ' ' . $ev['event_name'] . '</a>';
                            }
                        }
                    }
                    echo '</div>';
                }
                ?>
            </div>

        <?php elseif ($view == 'week'): ?>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <a href="<?php echo urlWithParam('date', date('Y-m-d', strtotime($filter_date . ' -1 week'))); ?>"
                    class="btn btn-sm btn-secondary">&laquo; Previous Week</a>
                <h3 style="margin:0;">
                    <?php
                    echo date('M j', strtotime($start_week)) . ' - ' . date('M j, Y', strtotime($end_week));
                    ?>
                </h3>
                <a href="<?php echo urlWithParam('date', date('Y-m-d', strtotime($filter_date . ' +1 week'))); ?>"
                    class="btn btn-sm btn-secondary">Next Week &raquo;</a>
            </div>
            <div class="week-grid">
                <?php
                $dt = new DateTime($start_week);
                for ($i = 0; $i < 7; $i++) {
                    $date_str = $dt->format('Y-m-d');
                    echo '<div class="week-col">';
                    echo '<div style="text-align:center; font-weight:bold; margin-bottom:10px;">' . $dt->format('D M j') . '</div>';

                    if (isset($events[$date_str])) {
                        foreach ($events[$date_str] as $ev) {
                            $start_utc = new DateTime($ev['start_time'], new DateTimeZone('UTC'));
                            $end_utc = new DateTime($ev['end_time'], new DateTimeZone('UTC'));
                            $now_utc = new DateTime('now', new DateTimeZone('UTC'));

                            $status_class = 'status-future';
                            if ($end_utc < $now_utc) {
                                $status_class = 'status-past';
                            } elseif ($start_utc <= $now_utc && $end_utc > $now_utc) {
                                $status_class = 'status-live';
                            }

                            $start = $start_utc->setTimezone(new DateTimeZone('America/New_York'))->format('H:i');
                            $end = $end_utc->setTimezone(new DateTimeZone('America/New_York'))->format('H:i');

                            $is_gap = isset($ev['type']) && $ev['type'] == 'default_gap';
                            if ($is_gap) {
                                $link_url = "create_event.php?start_date=" . $date_str . "&start_time=" . $start;
                                $status_class = '';
                            } else {
                                $link_url = "edit_event.php?id=" . $ev['id'];
                            }

                            if ($status_class === 'status-past' && !$is_gap) {
                                $start_local = (clone $start_utc)->setTimezone(new DateTimeZone('America/New_York'));
                                $end_local = (clone $end_utc)->setTimezone(new DateTimeZone('America/New_York'));
                                $start_display_str = $start_local->format('D, M j, Y g:i A');
                                $end_display_str = $end_local->format('g:i A');
                                $ev_tags_array = getEventTagNames($ev, $pdo, $tag_names_by_id);
                                $ev_tag_names = implode(', ', $ev_tags_array);
                                $ev_tags_data = implode(',', $ev_tags_array);

                                echo '<div class="cal-event priority-' . $ev['priority'] . ' ' . $status_class . '" style="padding:5px; margin-bottom:5px;">';
                                echo '<div style="display:flex; justify-content:space-between; align-items:center;">';
                                echo '<b>' . $start . '-' . $end . '</b>';
                                echo '<a href="' . $link_url . '" title="View event details" style="color:#777; text-decoration:none;"><i class="bi bi-info-circle"></i></a>';
                                echo '</div>';
                                echo '<div class="event-name-stats-clickable" title="Click to view listener statistics" style="cursor:pointer; text-decoration:underline; margin-top:3px; word-break:break-word; font-weight:500;" ' .
                                    'data-event-id="' . htmlspecialchars($ev['id']) . '" ' .
                                    'data-event-name="' . htmlspecialchars($ev['event_name'], ENT_QUOTES) . '" ' .
                                    'data-start-ts="' . $start_utc->getTimestamp() . '" ' .
                                    'data-end-ts="' . $end_utc->getTimestamp() . '" ' .
                                    'data-start-display="' . htmlspecialchars($start_display_str) . '" ' .
                                    'data-end-display="' . htmlspecialchars($end_display_str) . '" ' .
                                    'data-tags="' . htmlspecialchars($ev_tags_data, ENT_QUOTES) . '" ' .
                                    'data-tag-names="' . htmlspecialchars($ev_tag_names, ENT_QUOTES) . '">';
                                echo htmlspecialchars($ev['event_name']) . ' <i class="bi bi-graph-up" style="font-size:0.8em;"></i>';
                                echo '</div>';
                                echo '</div>';
                            } else {
                                echo '<a href="' . $link_url . '" class="cal-event priority-' . $ev['priority'] . ' ' . $status_class . '" style="padding:5px; margin-bottom:5px;">';
                                echo '<b>' . $start . '-' . $end . '</b><br>' . $ev['event_name'];
                                echo '</a>';
                            }
                        }
                    }

                    echo '</div>';
                    $dt->modify('+1 day');
                }
                ?>
            </div>

        <?php elseif ($view == 'day'): ?>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <a href="<?php echo urlWithParam('date', date('Y-m-d', strtotime($filter_date . ' -1 day'))); ?>"
                    class="btn btn-sm btn-secondary">&laquo; Previous Day</a>
                <h3 style="margin:0;"><?php echo date('l, F j, Y', strtotime($filter_date)); ?></h3>
                <a href="<?php echo urlWithParam('date', date('Y-m-d', strtotime($filter_date . ' +1 day'))); ?>"
                    class="btn btn-sm btn-secondary">Next Day &raquo;</a>
            </div>
            <div style="background:var(--card-bg); padding:2em; border-radius:8px;">
                <?php if (empty($events)): ?>
                    <p style="color:#777;">No events scheduled for this day.</p>
                <?php else: ?>
                    <?php foreach ($events as $ev):
                        $start_utc = new DateTime($ev['start_time'], new DateTimeZone('UTC'));
                        $end_utc = new DateTime($ev['end_time'], new DateTimeZone('UTC'));
                        $now_utc = new DateTime('now', new DateTimeZone('UTC'));

                        $status_class = 'status-future';
                        $status_label = '';
                        if ($end_utc < $now_utc) {
                            $status_class = 'status-past';
                            $status_label = '<span class="badge" style="background:#555; margin-left:10px; font-size:0.7em;">PAST</span>';
                        } elseif ($start_utc <= $now_utc && $end_utc > $now_utc) {
                            $status_class = 'status-live';
                            $status_label = '<span class="badge badge-live" style="margin-left:10px; font-size:0.7em;">LIVE</span>';
                        }

                        $start = $start_utc->setTimezone(new DateTimeZone('America/New_York'))->format('g:i A');
                        $end = $end_utc->setTimezone(new DateTimeZone('America/New_York'))->format('g:i A');

                        if (isset($ev['type']) && $ev['type'] == 'default_gap') {
                            $status_class = '';
                            $status_label = '';
                        }
                        ?>
                        <div class="day-event priority-<?php echo $ev['priority']; ?> <?php echo $status_class; ?>">
                            <div>
                                <div style="font-weight:bold; font-size:1.1em;">
                                    <?php echo $start . ' - ' . $end; ?>
                                    <?php echo $status_label; ?>
                                    <?php if ($ev['priority'] == 2): ?>
                                        <span class="badge badge-live" style="margin-left:10px; font-size:0.7em;">HIGH PRIORITY</span>
                                    <?php endif; ?>
                                </div>
                                <?php
                                $ev_tags_array = getEventTagNames($ev, $pdo, $tag_names_by_id);
                                $ev_tag_names = implode(', ', $ev_tags_array);
                                $ev_tags_data = implode(',', $ev_tags_array);
                                $start_local = (clone $start_utc)->setTimezone(new DateTimeZone('America/New_York'));
                                $end_local = (clone $end_utc)->setTimezone(new DateTimeZone('America/New_York'));
                                $start_display_str = $start_local->format('D, M j, Y g:i A');
                                $end_display_str = $end_local->format('g:i A');
                                ?>
                                <div style="color:var(--accent-color); margin: 3px 0;">
                                    <?php if ($status_class == 'status-past' && (!isset($ev['type']) || $ev['type'] != 'default_gap')): ?>
                                        <span class="event-name-stats-clickable" title="Click to view listener statistics" style="cursor:pointer; text-decoration:underline; font-weight:600; font-size:1.05em;"
                                            data-event-id="<?php echo htmlspecialchars($ev['id']); ?>"
                                            data-event-name="<?php echo htmlspecialchars($ev['event_name'], ENT_QUOTES); ?>"
                                            data-start-ts="<?php echo $start_utc->getTimestamp(); ?>"
                                            data-end-ts="<?php echo $end_utc->getTimestamp(); ?>"
                                            data-start-display="<?php echo htmlspecialchars($start_display_str); ?>"
                                            data-end-display="<?php echo htmlspecialchars($end_display_str); ?>"
                                            data-tags="<?php echo htmlspecialchars($ev_tags_data, ENT_QUOTES); ?>"
                                            data-tag-names="<?php echo htmlspecialchars($ev_tag_names, ENT_QUOTES); ?>">
                                            <?php echo htmlspecialchars($ev['event_name']); ?> <i class="bi bi-graph-up" style="font-size:0.85em;"></i>
                                        </span>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($ev['event_name']); ?>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size:0.9em; color:#888;">
                                    Tags: <?php echo htmlspecialchars($ev_tag_names); ?>
                                    | Asset: <?php echo htmlspecialchars($ev['filename_original'] ?? ''); ?>
                                </div>
                            </div>
                            <?php if (isset($ev['type']) && $ev['type'] == 'default_gap'): ?>
                                <?php
                                $add_url = "create_event.php?start_date=" . date('Y-m-d', strtotime($filter_date)) . "&start_time=" . (new DateTime($ev['start_time'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/New_York'))->format('H:i');
                                ?>
                                <a href="<?php echo $add_url; ?>" class="btn btn-sm"
                                    style="background-color: #28a745; color: #fff; border: none;">Add Event</a>
                            <?php else: ?>
                                <?php
                                $btn_text = 'Edit';
                                $btn_class = 'btn-secondary';
                                if ($status_class == 'status-past') {
                                    $btn_text = 'View';
                                    $btn_class = '';
                                }
                                ?>
                                <a href="edit_event.php?id=<?php echo $ev['id']; ?>" class="btn btn-sm <?php echo $btn_class; ?>"
                                    style="<?php echo $status_class == 'status-past' ? 'background:#555; color:#aaa;' : ''; ?>"><?php echo $btn_text; ?></a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>

    <footer>
        &copy;<?php echo date("Y") > 2025 ? "2025-" . date("Y") : "2025"; ?> WRHU Radio Hofstra University. Written by
        Gregory Pocali for WRHU with assistance from Google Gemini 3.
    </footer>

    <!-- Large Preview Modal -->
    <div id="previewModal" class="modal" onclick="this.style.display='none'">
        <div class="modal-content preview-modal-content">
            <span class="close">&times;</span>
            <div id="previewContainer"></div>
        </div>
    </div>

    <!-- Event Listener Statistics Modal -->
    <div id="eventStatsModal" class="modal">
        <div class="modal-content event-stats-modal-content">
            <span class="close" id="eventStatsCloseBtn" onclick="closeEventStatsModal()">&times;</span>
            
            <div style="margin-bottom: 15px;">
                <div style="display:flex; align-items:center; gap:10px; margin-bottom:5px; flex-wrap:wrap;">
                    <h3 id="eventStatsTitle" style="margin:0; font-size:1.35rem; color:#fff;">Event Statistics</h3>
                    <span class="badge" style="background:#555; font-size:0.75rem; vertical-align:middle;">PAST EVENT</span>
                </div>
                <div id="eventStatsTime" style="color:#aaa; font-size:0.95rem;"></div>
                <div id="eventStatsTags" style="color:#888; font-size:0.85rem; margin-top:3px;"></div>
            </div>

            <!-- Stream Selector Bar -->
            <div style="margin-bottom: 15px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; border-bottom:1px solid #333; padding-bottom:12px;">
                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                    <label style="font-size:0.8rem; color:#888; margin:0; font-weight:600; text-transform:uppercase;">Stream Channel:</label>
                    <div id="eventStatsStreamPills" class="stream-pill-group" style="display:flex; gap:6px; flex-wrap:wrap;">
                        <!-- Dynamically populated stream buttons -->
                    </div>
                </div>
                <div id="eventStatsDurationBadge" style="font-size:0.85rem; color:#4db8ff; font-weight:600;"></div>
            </div>

            <!-- Summary Metrics Bar -->
            <div class="metrics-row" style="background:#222; border-radius:6px; padding:12px 15px; display:grid; grid-template-columns:repeat(auto-fit, minmax(120px, 1fr)); gap:10px; border:1px solid #333; margin-bottom:15px; text-align:center;">
                <div>
                    <div style="font-size:0.75rem; color:#888; text-transform:uppercase;">Peak Listeners</div>
                    <div id="eventStatsPeak" style="font-size:1.6rem; font-weight:bold; color:#ff5555;">--</div>
                </div>
                <div>
                    <div style="font-size:0.75rem; color:#888; text-transform:uppercase;">Average Listeners</div>
                    <div id="eventStatsAvg" style="font-size:1.6rem; font-weight:bold; color:#4db8ff;">--</div>
                </div>
                <div>
                    <div style="font-size:0.75rem; color:#888; text-transform:uppercase;">Minimum Listeners</div>
                    <div id="eventStatsMin" style="font-size:1.6rem; font-weight:bold; color:#aaa;">--</div>
                </div>
                <div>
                    <div style="font-size:0.75rem; color:#888; text-transform:uppercase;">Data Points</div>
                    <div id="eventStatsPoints" style="font-size:1.6rem; font-weight:bold; color:#e0e0e0;">--</div>
                </div>
            </div>

            <!-- Chart Container -->
            <div style="position:relative; height:320px; background:#181818; border:1px solid #333; border-radius:6px; padding:10px;">
                <div id="eventStatsLoading" style="display:none; position:absolute; top:0; left:0; right:0; bottom:0; background:rgba(24,24,24,0.88); z-index:10; align-items:center; justify-content:center; flex-direction:column; gap:10px; border-radius:6px;">
                    <div class="stats-spinner"></div>
                    <span style="color:#aaa; font-size:0.9rem;">Fetching listener statistics from database...</span>
                </div>
                <div id="eventStatsEmpty" style="display:none; position:absolute; top:0; left:0; right:0; bottom:0; align-items:center; justify-content:center; color:#777; font-size:0.95rem; text-align:center; padding:20px; flex-direction:column;">
                    <i class="bi bi-info-circle" style="font-size:2rem; display:block; margin-bottom:8px; opacity:0.5;"></i>
                    <span>No listener statistics found in the database for this event time period.</span>
                </div>
                <canvas id="eventStatsChart" style="width:100%; height:100%;"></canvas>
            </div>
            <div style="margin-top:10px; font-size:0.75rem; color:#666; text-align:right;">
                Source: Station RRD Metrics Database &bull; Window bounded strictly to event duration
            </div>
        </div>
    </div>

    <style>
        .event-stats-modal-content {
            background-color: #1e1e1e;
            max-width: 850px;
            width: 90%;
            margin: 5% auto;
            border: 1px solid #333;
            border-radius: 8px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.7);
            padding: 24px;
        }
        .stream-pill {
            background: #2a2a2a;
            border: 1px solid #444;
            color: #bbb;
            padding: 4px 12px;
            border-radius: 14px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .stream-pill:hover {
            background: #383838;
            color: #fff;
        }
        .stream-pill.active {
            background: #4db8ff;
            color: #000;
            border-color: #4db8ff;
        }
        .stats-spinner {
            width: 30px;
            height: 30px;
            border: 3px solid #333;
            border-top-color: #4db8ff;
            border-radius: 50%;
            animation: stats-spin 1s linear infinite;
        }
        @keyframes stats-spin {
            to { transform: rotate(360deg); }
        }
        .event-name-stats-clickable:hover {
            opacity: 0.85;
        }
    </style>

    <script>
        // Modal & Preview Handlers
        window.onclick = function (event) {
            if (event.target == document.getElementById('previewModal')) {
                document.getElementById('previewModal').style.display = 'none';
            }
            if (event.target == document.getElementById('eventStatsModal')) {
                closeEventStatsModal();
            }
        };

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                const previewModal = document.getElementById('previewModal');
                if (previewModal) previewModal.style.display = 'none';
                closeEventStatsModal();
            }
        });

        // Preview Logic
        function showPreview(url, type) {
            const container = document.getElementById('previewContainer');
            container.innerHTML = '';
            if (type.includes('image')) {
                container.innerHTML = '<img src="' + url + '" class="preview-media">';
            } else if (type.includes('video')) {
                container.innerHTML = '<video src="' + url + '" controls autoplay class="preview-media"></video>';
            }
            document.getElementById('previewModal').style.display = 'block';
        }

        // Listener Statistics Logic for Past Events
        let currentStatsEvent = null;
        let currentStatsStream = null;
        let eventStatsChartInstance = null;

        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/[&<>"']/g, function (m) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[m];
            });
        }
        function escapeJs(str) {
            if (!str) return '';
            return String(str).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
        }

        document.addEventListener('click', function (e) {
            const el = e.target.closest('.event-name-stats-clickable');
            if (el) {
                e.preventDefault();
                e.stopPropagation();
                openEventStats({
                    id: el.dataset.eventId,
                    name: el.dataset.eventName,
                    startTs: parseInt(el.dataset.startTs, 10),
                    endTs: parseInt(el.dataset.endTs, 10),
                    startDisplay: el.dataset.startDisplay,
                    endDisplay: el.dataset.endDisplay,
                    tags: el.dataset.tags || '',
                    tagNames: el.dataset.tagNames || ''
                });
            }
        });

        function openEventStats(eventData) {
            currentStatsEvent = eventData;

            // Extract tags specifically assigned to this event
            let rawTags = eventData.tags || eventData.tagNames || '';
            let eventTags = rawTags ? rawTags.split(',').map(s => s.trim()).filter(Boolean) : [];
            // Remove duplicates
            eventTags = [...new Set(eventTags)];
            currentStatsEvent.eventTags = eventTags;

            document.getElementById('eventStatsTitle').innerText = eventData.name;
            document.getElementById('eventStatsTime').innerText = eventData.startDisplay + ' – ' + eventData.endDisplay;
            document.getElementById('eventStatsTags').innerText = eventTags.length > 0 ? ('Tag(s): ' + eventTags.join(', ')) : '';

            const durSec = Math.max(0, eventData.endTs - eventData.startTs);
            const durHrs = Math.floor(durSec / 3600);
            const durMins = Math.floor((durSec % 3600) / 60);
            let durText = '';
            if (durHrs > 0) durText += durHrs + 'h ';
            if (durMins > 0 || durHrs === 0) durText += durMins + 'm';
            document.getElementById('eventStatsDurationBadge').innerText = 'Duration: ' + durText.trim();

            // Default stream is the first tag assigned to the event
            const initialStream = eventTags.length > 0 ? eventTags[0] : '';
            currentStatsStream = initialStream;

            document.getElementById('eventStatsModal').style.display = 'block';
            loadEventStats(currentStatsStream);
        }

        function closeEventStatsModal() {
            const modal = document.getElementById('eventStatsModal');
            if (modal) modal.style.display = 'none';
            if (eventStatsChartInstance) {
                eventStatsChartInstance.destroy();
                eventStatsChartInstance = null;
            }
            currentStatsEvent = null;
            currentStatsStream = null;
        }

        async function loadEventStats(stream) {
            if (!currentStatsEvent) return;
            currentStatsStream = stream;

            const loadingEl = document.getElementById('eventStatsLoading');
            const emptyEl = document.getElementById('eventStatsEmpty');
            const chartCanvas = document.getElementById('eventStatsChart');
            const pillsContainer = document.getElementById('eventStatsStreamPills');

            loadingEl.style.display = 'flex';
            emptyEl.style.display = 'none';

            document.getElementById('eventStatsPeak').innerText = '--';
            document.getElementById('eventStatsAvg').innerText = '--';
            document.getElementById('eventStatsMin').innerText = '--';
            document.getElementById('eventStatsPoints').innerText = '--';

            // Show ONLY the tags that were selected for this event
            const eventTags = currentStatsEvent.eventTags || [];
            if (eventTags.length === 0) {
                pillsContainer.innerHTML = '<span style="color:#888; font-size:0.85rem; font-style:italic;">No stream tags assigned</span>';
                loadingEl.style.display = 'none';
                emptyEl.innerHTML = '<i class="bi bi-info-circle" style="font-size:2rem; display:block; margin-bottom:8px; opacity:0.5;"></i>No stream tags are associated with this event.';
                emptyEl.style.display = 'flex';
                if (eventStatsChartInstance) {
                    eventStatsChartInstance.destroy();
                    eventStatsChartInstance = null;
                }
                return;
            }

            // Ensure currentStatsStream is valid within eventTags
            if (!currentStatsStream || !eventTags.includes(currentStatsStream)) {
                currentStatsStream = eventTags[0];
            }

            // Render stream pills strictly for the selected tags of the event
            let pillsHtml = '';
            if (eventTags.length === 1) {
                // If only 1 tag was selected for this event, display it as an active badge
                pillsHtml = `<button type="button" class="stream-pill active" style="cursor:default;">${escapeHtml(eventTags[0])}</button>`;
            } else {
                // Multiple tags were selected: render selector buttons ONLY for those tags
                eventTags.forEach(t => {
                    const activeClass = (t === currentStatsStream) ? ' active' : '';
                    pillsHtml += `<button type="button" class="stream-pill${activeClass}" onclick="loadEventStats('${escapeJs(t)}')">${escapeHtml(t)}</button>`;
                });
            }
            pillsContainer.innerHTML = pillsHtml;

            try {
                const url = `api_graph_data.php?s=${encodeURIComponent(currentStatsStream)}&n=Global&start=${currentStatsEvent.startTs}&end=${currentStatsEvent.endTs}&nocache=${Date.now()}`;
                const response = await fetch(url);
                if (!response.ok) throw new Error('Network response was not ok');
                const data = await response.json();

                if (data.error && (!data.datasets || data.datasets.length === 0)) {
                    throw new Error(data.error);
                }

                // Strictly bound data points between startTs and endTs
                const startMs = currentStatsEvent.startTs * 1000;
                const endMs = currentStatsEvent.endTs * 1000;

                const filteredLabels = [];
                const filteredDatasets = (data.datasets || []).map(ds => ({
                    label: ds.label,
                    data: []
                }));

                if (data.labels && data.labels.length > 0) {
                    data.labels.forEach((t, idx) => {
                        if (t >= startMs && t <= endMs) {
                            filteredLabels.push(t);
                            filteredDatasets.forEach((fds, dIdx) => {
                                const val = (data.datasets[dIdx] && data.datasets[dIdx].data) ? data.datasets[dIdx].data[idx] : null;
                                fds.data.push(val);
                            });
                        }
                    });
                }

                const totalPoints = filteredLabels.length;

                // Extract valid series points
                const peakDs = filteredDatasets.find(ds => ds.label === 'Peak');
                const peakValues = peakDs ? peakDs.data.filter(v => typeof v === 'number' && !isNaN(v)) : [];

                const avgDs = filteredDatasets.find(ds => ds.label === 'Average') || filteredDatasets[0];
                const avgValues = avgDs ? avgDs.data.filter(v => typeof v === 'number' && !isNaN(v)) : [];

                if (avgValues.length === 0 && peakValues.length === 0 && (data.max_listeners === null || data.max_listeners === undefined)) {
                    emptyEl.style.display = 'flex';
                    if (eventStatsChartInstance) {
                        eventStatsChartInstance.destroy();
                        eventStatsChartInstance = null;
                    }
                    loadingEl.style.display = 'none';
                    return;
                }

                // Peak listeners: true MAX value with ZERO averaging
                // Priority:
                // 1) data.max_listeners from RRDTool VDEF MAXIMUM calculation
                // 2) Math.max of raw Peak dataset points (MAX consolidation)
                // 3) Math.max of raw Average points (fallback if Peak dataset empty)
                let peak = null;
                if (data.max_listeners !== null && data.max_listeners !== undefined && !isNaN(data.max_listeners)) {
                    peak = Number(data.max_listeners);
                } else if (peakValues.length > 0) {
                    peak = Math.max(...peakValues);
                } else if (avgValues.length > 0) {
                    peak = Math.max(...avgValues);
                }

                let min = avgValues.length > 0 ? Math.min(...avgValues) : 0;
                let avg = avgValues.length > 0 ? (avgValues.reduce((a, b) => a + b, 0) / avgValues.length) : 0;

                document.getElementById('eventStatsPeak').innerText = (peak !== null) ? Math.ceil(peak).toLocaleString() : '--';
                document.getElementById('eventStatsAvg').innerText = (avgValues.length > 0) ? (Math.round(avg * 10) / 10).toLocaleString() : '--';
                document.getElementById('eventStatsMin').innerText = (avgValues.length > 0) ? Math.floor(min).toLocaleString() : '--';
                document.getElementById('eventStatsPoints').innerText = totalPoints.toLocaleString();

                // Prepare Chart.js
                if (eventStatsChartInstance) {
                    eventStatsChartInstance.destroy();
                    eventStatsChartInstance = null;
                }

                const chartDatasets = filteredDatasets.map(ds => {
                    const isPeak = (ds.label === 'Peak');
                    const color = isPeak ? '#ff5555' : '#4db8ff';
                    return {
                        label: isPeak ? 'Peak (Max)' : 'Average',
                        data: ds.data,
                        borderColor: color,
                        backgroundColor: isPeak ? 'rgba(255, 85, 85, 0.08)' : 'rgba(77, 184, 255, 0.2)',
                        fill: !isPeak,
                        borderWidth: 2,
                        pointRadius: totalPoints <= 25 ? 3 : 0,
                        pointHoverRadius: 5,
                        tension: 0.2
                    };
                });

                const ctx = chartCanvas.getContext('2d');
                eventStatsChartInstance = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: filteredLabels.map(t => {
                            const d = new Date(t);
                            return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                        }),
                        datasets: chartDatasets
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: false,
                        scales: {
                            x: {
                                grid: { color: 'rgba(255,255,255,0.05)' },
                                ticks: { color: '#888', maxTicksLimit: 8 }
                            },
                            y: {
                                beginAtZero: true,
                                grid: { color: 'rgba(255,255,255,0.07)' },
                                ticks: { color: '#888', precision: 0 }
                            }
                        },
                        plugins: {
                            legend: {
                                display: true,
                                labels: { color: '#ccc', boxWidth: 12 }
                            },
                            tooltip: {
                                mode: 'index',
                                intersect: false
                            }
                        },
                        interaction: {
                            mode: 'nearest',
                            axis: 'x',
                            intersect: false
                        }
                    }
                });

            } catch (err) {
                console.error('Error loading event stats:', err);
                emptyEl.innerHTML = `<i class="bi bi-info-circle" style="font-size:2rem; display:block; margin-bottom:8px; opacity:0.5;"></i>No listener statistics found in the database for this event time period.<br><small style="color:#666;">(${err.message || 'No data recorded'})</small>`;
                emptyEl.style.display = 'flex';
                if (eventStatsChartInstance) {
                    eventStatsChartInstance.destroy();
                    eventStatsChartInstance = null;
                }
            } finally {
                loadingEl.style.display = 'none';
            }
        }
    </script>
</body>

</html>