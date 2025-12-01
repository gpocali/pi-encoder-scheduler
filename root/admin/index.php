<?php
require_once 'auth.php';
require_once '../db_connect.php';
require_once 'ScheduleLogic.php';
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
            header("Location: index.php");
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
            header("Location: index.php");
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
            header("Location: index.php");
            exit;
        }
    }
}

// --- LIVE MONITOR DATA ---
$live_status = [];
$now_utc_str = (new DateTime())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

foreach ($tags as $tag) {
    // Find highest priority active event
    $sql_live = "SELECT e.*, a.filename_disk, a.filename_original, a.mime_type 
                 FROM events e 
                 JOIN event_tags et ON e.id = et.event_id
                 JOIN assets a ON e.asset_id = a.id 
                 WHERE et.tag_id = ? 
                 AND e.start_time <= ? AND e.end_time > ? 
                 ORDER BY e.priority DESC, e.start_time ASC LIMIT 1";
    $stmt_live = $pdo->prepare($sql_live);
    $stmt_live->execute([$tag['id'], $now_utc_str, $now_utc_str]);
    $live_event = $stmt_live->fetch(PDO::FETCH_ASSOC);

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
            'data' => $def_asset, // Can be false if no default
            'tag_name' => $tag['tag_name']
        ];
    }
}

// --- FILTERS & VIEWS ---
$view = $_GET['view'] ?? 'list';
$filter_tag = isset($_GET['tag_id']) && $_GET['tag_id'] !== '' ? (int) $_GET['tag_id'] : null;
$filter_date = $_GET['date'] ?? date('Y-m-d');
$hide_past = isset($_GET['hide_past']) ? (bool) $_GET['hide_past'] : false;
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$per_page = 20;

// Build Query
$where_clauses = ["1=1"];
$params = [];

// Permission Filter
$in_clause = implode(',', array_fill(0, count($allowed_tag_ids), '?'));
// Query needs to join event_tags to filter
$where_clauses[] = "et.tag_id IN ($in_clause)";
$params = array_merge($params, $allowed_tag_ids);

// User Filters
if ($filter_tag) {
    $where_clauses[] = "et.tag_id = ?";
    $params[] = $filter_tag;
}

if ($hide_past) {
    $now_utc_filter = (new DateTime())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $where_clauses[] = "e.end_time > ?";
    $params[] = $now_utc_filter;
}

// View Specific Logic
$events = [];
$pagination = [];

if ($view == 'list') {
    // Filter by date range if provided? Or just show all future/recent?
    // Let's show all by default, sorted by start_time DESC

    // Count for pagination
    $sql_count = "SELECT COUNT(DISTINCT e.id) FROM events e JOIN event_tags et ON e.id = et.event_id WHERE " . implode(" AND ", $where_clauses);
    $stmt_count = $pdo->prepare($sql_count);
    $stmt_count->execute($params);
    $total_items = $stmt_count->fetchColumn();
    $total_pages = ceil($total_items / $per_page);
    $offset = ($page - 1) * $per_page;

    $sql = "SELECT DISTINCT e.*, a.filename_original 
            FROM events e 
            JOIN event_tags et ON e.id = et.event_id
            JOIN assets a ON e.asset_id = a.id 
            WHERE " . implode(" AND ", $where_clauses) . " 
            ORDER BY e.start_time ASC 
            LIMIT $per_page OFFSET $offset";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Resolve Schedule Conflicts
    if (!empty($events)) {
        $min_start = $events[0]['start_time'];
        $max_end = $events[0]['end_time'];
        $page_ids = [];
        $page_tag_ids = [];

        foreach ($events as $ev) {
            if ($ev['start_time'] < $min_start)
                $min_start = $ev['start_time'];
            if ($ev['end_time'] > $max_end)
                $max_end = $ev['end_time'];
            $page_ids[] = $ev['id'];
            $page_tag_ids[] = $ev['tag_id'];
        }
        $page_tag_ids = array_unique($page_tag_ids);

        if (!empty($page_tag_ids)) {
            $placeholders = implode(',', array_fill(0, count($page_tag_ids), '?'));
            $not_in_ids = implode(',', $page_ids);

            $ctx_events = [];
            if ($filter_tag) {
                // If filtering by tag, context events must be on that tag
                $sql_ctx = "SELECT DISTINCT e.*, a.filename_original 
                            FROM events e 
                            JOIN event_tags et ON e.id = et.event_id
                            JOIN assets a ON e.asset_id = a.id 
                            WHERE et.tag_id = ?
                            AND e.start_time < ? AND e.end_time > ?
                            AND e.id NOT IN ($not_in_ids)";
                $stmt_ctx = $pdo->prepare($sql_ctx);
                $stmt_ctx->execute([$filter_tag, $max_end, $min_start]);
                $ctx_events = $stmt_ctx->fetchAll(PDO::FETCH_ASSOC);

                // Force tag_id to filter_tag for resolution
                foreach ($events as &$ev)
                    $ev['tag_id'] = $filter_tag;
                foreach ($ctx_events as &$ev)
                    $ev['tag_id'] = $filter_tag;
                unset($ev);
            } else {
                // Default behavior: use primary tag_id
                $sql_ctx = "SELECT e.*, a.filename_original 
                            FROM events e 
                            JOIN assets a ON e.asset_id = a.id 
                            WHERE e.tag_id IN ($placeholders)
                            AND e.start_time < ? AND e.end_time > ?
                            AND e.id NOT IN ($not_in_ids)";
                $stmt_ctx = $pdo->prepare($sql_ctx);
                $params_ctx = array_merge($page_tag_ids, [$max_end, $min_start]);
                $stmt_ctx->execute($params_ctx);
                $ctx_events = $stmt_ctx->fetchAll(PDO::FETCH_ASSOC);
            }

            $all_relevant = array_merge($events, $ctx_events);
            $resolved = ScheduleLogic::resolveSchedule($all_relevant);

            // Filter back to page events
            $final_events = [];
            foreach ($resolved as $r) {
                if (in_array($r['id'], $page_ids)) {
                    $final_events[] = $r;
                }
            }
            $events = $final_events;
        }
    }

} elseif ($view == 'month') {
    $start_month = date('Y-m-01', strtotime($filter_date));
    $end_month = date('Y-m-t', strtotime($filter_date));

    $start_utc = (new DateTime($start_month . ' 00:00:00'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $end_utc = (new DateTime($end_month . ' 23:59:59'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

    $where_clauses[] = "e.start_time < ? AND e.end_time > ?";
    $params[] = $end_utc;
    $params[] = $start_utc;

    $sql = "SELECT DISTINCT e.* FROM events e JOIN event_tags et ON e.id = et.event_id WHERE " . implode(" AND ", $where_clauses) . " ORDER BY e.start_time ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $raw_events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Resolve Schedule
    if ($filter_tag) {
        foreach ($raw_events as &$ev)
            $ev['tag_id'] = $filter_tag;
        unset($ev);
    }
    $raw_events = ScheduleLogic::resolveSchedule($raw_events);

    // Group by day (handling spans)
    $month_start_ts = strtotime($start_month);
    $year = date('Y', $month_start_ts);
    $month = date('m', $month_start_ts);
    $days_in_month = date('t', $month_start_ts);

    foreach ($raw_events as $ev) {
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
    // Calculate start (Sun) and end (Sat) of week for $filter_date
    $dt = new DateTime($filter_date);
    // If today is Sunday (0), we are at start. If not, go back to last Sunday.
    if ($dt->format('w') != 0) {
        $dt->modify('last sunday');
    }
    $start_week = $dt->format('Y-m-d');
    $dt->modify('+6 days');
    $end_week = $dt->format('Y-m-d');

    $start_utc = (new DateTime($start_week . ' 00:00:00'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $end_utc = (new DateTime($end_week . ' 23:59:59'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

    $where_clauses[] = "e.start_time < ? AND e.end_time > ?";
    $params[] = $end_utc;
    $params[] = $start_utc;

    $sql = "SELECT DISTINCT e.* FROM events e JOIN event_tags et ON e.id = et.event_id WHERE " . implode(" AND ", $where_clauses) . " ORDER BY e.start_time ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $raw_events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Resolve Schedule
    if ($filter_tag) {
        foreach ($raw_events as &$ev)
            $ev['tag_id'] = $filter_tag;
        unset($ev);
    }
    $raw_events = ScheduleLogic::resolveSchedule($raw_events);

    // Group by Date (Y-m-d)
    $week_dates = [];
    $dt = new DateTime($start_week);
    for ($i = 0; $i < 7; $i++) {
        $week_dates[] = $dt->format('Y-m-d');
        $dt->modify('+1 day');
    }

    foreach ($raw_events as $ev) {
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

    $where_clauses[] = "e.start_time < ? AND e.end_time > ?";
    $params[] = $end_utc;
    $params[] = $start_utc;

    $sql = "SELECT DISTINCT e.*, a.filename_original FROM events e JOIN event_tags et ON e.id = et.event_id JOIN assets a ON e.asset_id = a.id WHERE " . implode(" AND ", $where_clauses) . " ORDER BY e.start_time ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Resolve Schedule
    if ($filter_tag) {
        foreach ($events as &$ev)
            $ev['tag_id'] = $filter_tag;
        unset($ev);
    }
    $events = ScheduleLogic::resolveSchedule($events);
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
                    <div class="monitor-thumb" style="display:grid; place-items:center; overflow:hidden;">
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
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="action" value="extend_event">
                                        <input type="hidden" name="event_id" value="<?php echo $asset['id']; ?>">
                                        <button type="submit"
                                            style="background:var(--secondary-color); color:#fff; border:none; border-radius:4px; padding:4px 8px; cursor:pointer; font-size:0.8em;">+15m</button>
                                    </form>
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

                <?php if ($view != 'list'): ?>
                    <input type="date" name="date" value="<?php echo $filter_date; ?>" onchange="this.form.submit()">
                <?php endif; ?>

                <label
                    style="margin-left:10px; display:flex; align-items:center; gap:5px; cursor:pointer; white-space:nowrap;">
                    <input type="checkbox" name="hide_past" value="1" <?php if ($hide_past)
                        echo 'checked'; ?>
                        onchange="this.form.submit()">
                    Hide Past Events
                </label>
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
                        <th>Status</th>
                        <th>Event Name</th>
                        <th>Tag</th>
                        <th>Start Time</th>
                        <th>End Time</th>
                        <th>Asset</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $ev):
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
                        ?>
                        <tr>
                            <td><span
                                    style="color:<?php echo $status_color; ?>; font-weight:bold;"><?php echo $status; ?></span>
                            </td>
                            <td><?php echo htmlspecialchars($ev['event_name']); ?></td>
                            <td>
                                <?php
                                $stmt_t = $pdo->prepare("SELECT t.tag_name FROM event_tags et JOIN tags t ON et.tag_id = t.id WHERE et.event_id = ?");
                                $stmt_t->execute([$ev['id']]);
                                $tag_names = $stmt_t->fetchAll(PDO::FETCH_COLUMN);
                                echo htmlspecialchars(implode(', ', $tag_names));
                                ?>
                            </td>
                            <td>
                                <?php
                                $start_local = $start->setTimezone(new DateTimeZone('America/New_York'));
                                $format = ($start_local->format('Y') != date('Y')) ? 'M j, Y, g:i A' : 'M j, g:i A';
                                echo $start_local->format($format);
                                ?>
                            </td>
                            <td>
                                <?php
                                $end_local = $end->setTimezone(new DateTimeZone('America/New_York'));
                                $format = ($end_local->format('Y') != date('Y')) ? 'M j, Y, g:i A' : 'M j, g:i A';
                                echo $end_local->format($format);
                                ?>
                            </td>
                            <td><?php echo htmlspecialchars($ev['filename_original']); ?></td>
                            <td>
                                <a href="edit_event.php?id=<?php echo $ev['id']; ?>&<?php echo http_build_query($_GET); ?>"
                                    class="btn btn-sm btn-secondary">Edit</a>
                                <?php if ($status != 'Live'): ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete?');">
                                        <input type="hidden" name="action" value="delete_event">
                                        <input type="hidden" name="event_id" value="<?php echo $ev['id']; ?>">
                                        <button type="submit"
                                            style="background:none; border:none; color:var(--error-color); cursor:pointer; padding:0;">Delete</button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('End this event now?');">
                                        <input type="hidden" name="action" value="end_now">
                                        <input type="hidden" name="event_id" value="<?php echo $ev['id']; ?>">
                                        <button type="submit"
                                            style="background:var(--error-color); color:#fff; border:none; border-radius:4px; padding:4px 8px; cursor:pointer; font-size:0.8em;">End
                                            Now</button>
                                    </form>
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
                    echo '<div class="cal-day">';
                    echo '<div class="cal-date">' . $d . '</div>';
                    if (isset($events[$d])) {
                        foreach ($events[$d] as $ev) {
                            $time = (new DateTime($ev['start_time'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/New_York'))->format('H:i');
                            echo '<a href="edit_event.php?id=' . $ev['id'] . '" class="cal-event priority-' . $ev['priority'] . '">' . $time . ' ' . $ev['event_name'] . '</a>';
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
                            $start = (new DateTime($ev['start_time'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/New_York'))->format('H:i');
                            $end = (new DateTime($ev['end_time'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/New_York'))->format('H:i');
                            echo '<a href="edit_event.php?id=' . $ev['id'] . '" class="cal-event priority-' . $ev['priority'] . '" style="padding:5px; margin-bottom:5px;">';
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
                        $start = (new DateTime($ev['start_time'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/New_York'))->format('g:i A');
                        $end = (new DateTime($ev['end_time'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/New_York'))->format('g:i A');
                        ?>
                        <div
                            style="padding:1em; border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center;">
                            <div>
                                <div style="font-weight:bold; font-size:1.1em;"><?php echo $start . ' - ' . $end; ?></div>
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
                            <a href="edit_event.php?id=<?php echo $ev['id']; ?>" class="btn btn-sm btn-secondary">Edit</a>
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

</body>

</html>