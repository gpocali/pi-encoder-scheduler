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

// Handle Actions (End Now, Delete)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['action']) && $_POST['action'] == 'end_now') {
        $event_id = (int) $_POST['event_id'];
        // Verify permission
        $stmt = $pdo->prepare("SELECT tag_id FROM events WHERE id = ?");
        $stmt->execute([$event_id]);
        $evt_tag = $stmt->fetchColumn();
        if (in_array($evt_tag, $allowed_tag_ids)) {
            $now_utc = (new DateTime())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $pdo->prepare("UPDATE events SET end_time = ? WHERE id = ?")->execute([$now_utc, $event_id]);
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
        }
    }

    if (isset($_POST['action']) && $_POST['action'] == 'extend_event') {
        $event_id = (int) $_POST['event_id'];
        // Verify permission
        $stmt = $pdo->prepare("SELECT tag_id, end_time FROM events WHERE id = ?");
        $stmt->execute([$event_id]);
        $evt = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($evt && in_array($evt['tag_id'], $allowed_tag_ids)) {
            $new_end = date('Y-m-d H:i:s', strtotime($evt['end_time'] . ' +15 minutes'));
            $pdo->prepare("UPDATE events SET end_time = ? WHERE id = ?")->execute([$new_end, $event_id]);
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
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
    <script>
        // Auto-refresh every 60 seconds to update Live Monitor
        setTimeout(function () {
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
                                            <button type="submit"
                                                style="background:var(--secondary-color); color:#fff; border:none; border-radius:4px; padding:4px 8px; cursor:pointer; font-size:0.8em;">+15m</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" onsubmit="return confirm('End this event now?');" style="margin:0;">
                                        <input type="hidden" name="action" value="end_now">
                                        <input type="hidden" name="event_id" value="<?php echo $asset['id']; ?>">
                                        <button type="submit"
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
                            $live_tags = [];
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
                            <td><?php echo htmlspecialchars($ev['event_name']); ?><?php if (!empty($ev['is_modified']))
                                   echo ' <small style="color:orange;">(Modified)</small>'; ?>
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

                                    <?php if ($status == 'Live'): ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('End this event now?');">
                                            <input type="hidden" name="action" value="end_now">
                                            <input type="hidden" name="event_id" value="<?php echo $ev['id']; ?>">
                                            <button type="submit"
                                                style="background:none; border:none; color:var(--error-color); cursor:pointer; padding:0;">End
                                                Now</button>
                                        </form>
                                    <?php endif; ?>
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

                            echo '<a href="' . $link_url . '" class="cal-event priority-' . $ev['priority'] . ' ' . $status_class . '">' . $time . ' ' . $ev['event_name'] . '</a>';
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

                            echo '<a href="' . $link_url . '" class="cal-event priority-' . $ev['priority'] . ' ' . $status_class . '" style="padding:5px; margin-bottom:5px;">';
                            echo '<b>' . $start . '-' . $end . '</b><br>' . $ev['event_name'];
                            echo '</a>';
                        }
                    }

                    echo '</div>';
                    $dt->modify('+1 day');
                }
                ?>
            </div>

        <?php elseif ($view == 'day'): ?>
            <div style="background:var(--card-bg); padding:2em; border-radius:8px;">
                <h3>Events for <?php echo date('F j, Y', strtotime($filter_date)); ?></h3>
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
                                <div style="color:var(--accent-color);"><?php echo htmlspecialchars($ev['event_name']); ?></div>
                                <div style="font-size:0.9em; color:#888;">
                                    Tags:
                                    <?php
                                    $stmt_t = $pdo->prepare("SELECT t.tag_name FROM event_tags et JOIN tags t ON et.tag_id = t.id WHERE et.event_id = ?");
                                    $stmt_t->execute([$ev['id']]);
                                    $tag_names = $stmt_t->fetchAll(PDO::FETCH_COLUMN);
                                    echo htmlspecialchars(implode(', ', $tag_names));
                                    ?>
                                    | Asset: <?php echo htmlspecialchars($ev['filename_original']); ?>
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
                                <a href="edit_event.php?id=<?php echo $ev['id']; ?>" class="btn btn-sm <?php echo $btn_class; ?>" style="<?php echo $status_class == 'status-past' ? 'background:#555; color:#aaa;' : ''; ?>"><?php echo $btn_text; ?></a>
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

    <script>
        window.onclick = function (event) {
            if (event.target == document.getElementById('previewModal')) {
                document.getElementById('previewModal').style.display = 'none';
            }
        }

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
    </script>
</body>

</html>