<?php
require_once 'auth.php';
require_once '../db_connect.php';
date_default_timezone_set('America/New_York');

// Helper to get current URL with modified params
function urlWithParam($key, $val) {
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
        $event_id = (int)$_POST['event_id'];
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
    if (isset($_POST['action']) && $_POST['action'] == 'delete_event') {
        $event_id = (int)$_POST['event_id'];
        $stmt = $pdo->prepare("SELECT tag_id FROM events WHERE id = ?");
        $stmt->execute([$event_id]);
        $evt_tag = $stmt->fetchColumn();
        if (in_array($evt_tag, $allowed_tag_ids)) {
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
                 JOIN assets a ON e.asset_id = a.id 
                 WHERE e.tag_id = ? 
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
        $sql_def = "SELECT a.filename_disk, a.filename_original, a.mime_type 
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
$filter_tag = isset($_GET['tag_id']) && $_GET['tag_id'] !== '' ? (int)$_GET['tag_id'] : null;
$filter_date = $_GET['date'] ?? date('Y-m-d');
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20;

// Build Query
$where_clauses = ["1=1"];
$params = [];

// Permission Filter
$in_clause = implode(',', array_fill(0, count($allowed_tag_ids), '?'));
$where_clauses[] = "e.tag_id IN ($in_clause)";
$params = array_merge($params, $allowed_tag_ids);

// User Filters
if ($filter_tag) {
    $where_clauses[] = "e.tag_id = ?";
    $params[] = $filter_tag;
}

// View Specific Logic
$events = [];
$pagination = [];

if ($view == 'list') {
    // Filter by date range if provided? Or just show all future/recent?
    // Let's show all by default, sorted by start_time DESC
    
    // Count for pagination
    $sql_count = "SELECT COUNT(*) FROM events e WHERE " . implode(" AND ", $where_clauses);
    $stmt_count = $pdo->prepare($sql_count);
    $stmt_count->execute($params);
    $total_items = $stmt_count->fetchColumn();
    $total_pages = ceil($total_items / $per_page);
    $offset = ($page - 1) * $per_page;

    $sql = "SELECT e.*, t.tag_name, a.filename_original 
            FROM events e 
            JOIN tags t ON e.tag_id = t.id 
            JOIN assets a ON e.asset_id = a.id 
            WHERE " . implode(" AND ", $where_clauses) . " 
            ORDER BY e.start_time DESC 
            LIMIT $per_page OFFSET $offset";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

} elseif ($view == 'month') {
    $start_month = date('Y-m-01', strtotime($filter_date));
    $end_month = date('Y-m-t', strtotime($filter_date));
    
    $start_utc = (new DateTime($start_month))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d 00:00:00');
    $end_utc = (new DateTime($end_month))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d 23:59:59');

    $where_clauses[] = "e.start_time BETWEEN ? AND ?";
    $params[] = $start_utc;
    $params[] = $end_utc;

    $sql = "SELECT e.*, t.tag_name FROM events e JOIN tags t ON e.tag_id = t.id WHERE " . implode(" AND ", $where_clauses) . " ORDER BY e.start_time ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $raw_events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group by day
    foreach ($raw_events as $ev) {
        $day = (new DateTime($ev['start_time']))->setTimezone(new DateTimeZone('America/New_York'))->format('j');
        $events[$day][] = $ev;
    }

} elseif ($view == 'week') {
    // Calculate start (Mon) and end (Sun) of week for $filter_date
    $dt = new DateTime($filter_date);
    $dt->modify('monday this week');
    $start_week = $dt->format('Y-m-d');
    $dt->modify('sunday this week');
    $end_week = $dt->format('Y-m-d');

    $start_utc = (new DateTime($start_week))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d 00:00:00');
    $end_utc = (new DateTime($end_week))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d 23:59:59');

    $where_clauses[] = "e.start_time BETWEEN ? AND ?";
    $params[] = $start_utc;
    $params[] = $end_utc;

    $sql = "SELECT e.*, t.tag_name FROM events e JOIN tags t ON e.tag_id = t.id WHERE " . implode(" AND ", $where_clauses) . " ORDER BY e.start_time ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $raw_events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by Date (Y-m-d)
    foreach ($raw_events as $ev) {
        $date = (new DateTime($ev['start_time']))->setTimezone(new DateTimeZone('America/New_York'))->format('Y-m-d');
        $events[$date][] = $ev;
    }
} elseif ($view == 'day') {
    $start_utc = (new DateTime($filter_date))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d 00:00:00');
    $end_utc = (new DateTime($filter_date))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d 23:59:59');

    $where_clauses[] = "e.start_time BETWEEN ? AND ?";
    $params[] = $start_utc;
    $params[] = $end_utc;

    $sql = "SELECT e.*, t.tag_name, a.filename_original FROM events e JOIN tags t ON e.tag_id = t.id JOIN assets a ON e.asset_id = a.id WHERE " . implode(" AND ", $where_clauses) . " ORDER BY e.start_time ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Dashboard - WRHU Encoder Scheduler</title>
    <style>
        :root { --bg-color: #121212; --card-bg: #1e1e1e; --text-color: #e0e0e0; --accent-color: #bb86fc; --secondary-color: #03dac6; --error-color: #cf6679; --border-color: #333; }
        body { font-family: 'Inter', sans-serif; background: var(--bg-color); color: var(--text-color); margin: 0; padding: 0; display: flex; flex-direction: column; min-height: 100vh; }
        
        /* Navigation */
        nav { background: #000; padding: 1em 2em; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); }
        nav h1 { margin: 0; font-size: 1.2em; color: #fff; }
        nav ul { list-style: none; margin: 0; padding: 0; display: flex; gap: 20px; }
        nav a { color: #aaa; text-decoration: none; font-weight: bold; transition: color 0.2s; }
        nav a:hover, nav a.active { color: var(--accent-color); }
        
        .container { max-width: 1400px; margin: 0 auto; padding: 2em; width: 100%; box-sizing: border-box; flex: 1; }
        
        /* Live Monitor */
        .live-monitor { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; margin-bottom: 3em; }
        .monitor-card { background: var(--card-bg); border-radius: 8px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.5); border: 1px solid var(--border-color); position: relative; }
        .monitor-thumb { width: 100%; height: 180px; object-fit: cover; background: #000; }
        .monitor-info { padding: 1em; }
        .monitor-tag { font-size: 0.9em; color: #888; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px; }
        .monitor-title { font-size: 1.1em; font-weight: bold; color: #fff; margin-bottom: 10px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.7em; font-weight: bold; text-transform: uppercase; }
        .badge-live { background: var(--error-color); color: #fff; animation: pulse 2s infinite; }
        .badge-default { background: #333; color: #aaa; }
        
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.7; } 100% { opacity: 1; } }

        /* Controls */
        .controls { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1em; flex-wrap: wrap; gap: 10px; }
        .filters { display: flex; gap: 10px; align-items: center; }
        select, input[type="date"] { padding: 0.5em; background: #2c2c2c; border: 1px solid var(--border-color); color: #fff; border-radius: 4px; }
        .btn { padding: 0.5em 1em; background: var(--accent-color); color: #000; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; font-weight: bold; }
        .btn-create { background: var(--secondary-color); }
        
        /* Views */
        .view-tabs { display: flex; gap: 5px; margin-bottom: 1em; }
        .view-tab { padding: 0.5em 1em; background: #2c2c2c; color: #aaa; text-decoration: none; border-radius: 4px 4px 0 0; }
        .view-tab.active { background: var(--card-bg); color: #fff; font-weight: bold; }
        
        /* List View */
        table { width: 100%; border-collapse: collapse; background: var(--card-bg); border-radius: 8px; overflow: hidden; }
        th, td { padding: 1em; text-align: left; border-bottom: 1px solid var(--border-color); }
        th { background: #2c2c2c; }
        tr:hover { background: #252525; }
        
        /* Calendar Grid (Month) */
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 1px; background: var(--border-color); border: 1px solid var(--border-color); }
        .cal-day { background: var(--card-bg); min-height: 120px; padding: 5px; }
        .cal-header { background: #2c2c2c; padding: 10px; text-align: center; font-weight: bold; }
        .cal-date { font-size: 0.9em; color: #777; margin-bottom: 5px; }
        .cal-event { background: #333; padding: 2px 5px; margin-bottom: 2px; border-radius: 2px; font-size: 0.8em; cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; color: #e0e0e0; text-decoration: none; }
        .cal-event.priority-2 { border-left: 3px solid var(--error-color); }
        .cal-event.priority-1 { border-left: 3px solid var(--secondary-color); }
        
        /* Week View */
        .week-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 10px; }
        .week-col { background: var(--card-bg); min-height: 400px; padding: 10px; border-radius: 4px; }
        
        /* Footer */
        footer { text-align: center; margin-top: 2em; color: #777; font-size: 0.9em; padding: 1em; border-top: 1px solid var(--border-color); background: #000; }
        
        .pagination { margin-top: 1em; display: flex; gap: 5px; justify-content: center; }
        .page-link { padding: 5px 10px; background: #2c2c2c; color: #fff; text-decoration: none; border-radius: 4px; }
        .page-link.active { background: var(--accent-color); color: #000; }
    </style>
</head>
<body>

<nav>
    <h1>WRHU Encoder Scheduler</h1>
    <ul>
        <li><a href="index.php" class="active">Dashboard</a></li>
        <li><a href="create_event.php">Create Event</a></li>
        <li><a href="manage_assets.php">Assets</a></li>
        <?php if ($is_admin): ?>
            <li><a href="manage_users.php">Users</a></li>
            <li><a href="manage_tags.php">Tags</a></li>
            <li><a href="default_assets.php">Defaults</a></li>
        <?php endif; ?>
        <li><a href="profile.php">Profile</a></li>
        <li><a href="logout.php">Logout</a></li>
    </ul>
</nav>

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
                        $file_url = '../uploads/' . $asset['filename_disk'];
                        $is_img = strpos($asset['mime_type'], 'image') !== false;
                        $is_vid = strpos($asset['mime_type'], 'video') !== false;
                        // For video, we can't easily generate thumb in PHP without ffmpeg, so just show video tag or icon
                        // For now, if it's image show it, if video show video element muted loop
                    }
                ?>
                <div class="monitor-thumb" style="display:grid; place-items:center; overflow:hidden;">
                    <?php if ($asset): ?>
                        <?php if (strpos($asset['mime_type'], 'image') !== false): ?>
                            <img src="../uploads/<?php echo $asset['filename_disk']; ?>" style="width:100%; height:100%; object-fit:cover;">
                        <?php elseif (strpos($asset['mime_type'], 'video') !== false): ?>
                            <video src="../uploads/<?php echo $asset['filename_disk']; ?>" style="width:100%; height:100%; object-fit:cover;" muted loop autoplay></video>
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
                            <form method="POST" onsubmit="return confirm('End this event now?');" style="margin:0;">
                                <input type="hidden" name="action" value="end_now">
                                <input type="hidden" name="event_id" value="<?php echo $asset['id']; ?>">
                                <button type="submit" style="background:var(--error-color); color:#fff; border:none; border-radius:4px; padding:4px 8px; cursor:pointer; font-size:0.8em;">End Now</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Scheduler Controls -->
    <div class="controls">
        <form class="filters" method="GET">
            <input type="hidden" name="view" value="<?php echo $view; ?>">
            <select name="tag_id" onchange="this.form.submit()">
                <option value="">All Tags</option>
                <?php foreach ($tags as $t): ?>
                    <option value="<?php echo $t['id']; ?>" <?php if($filter_tag == $t['id']) echo 'selected'; ?>>
                        <?php echo htmlspecialchars($t['tag_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <?php if ($view != 'list'): ?>
                <input type="date" name="date" value="<?php echo $filter_date; ?>" onchange="this.form.submit()">
            <?php endif; ?>
        </form>
        
        <a href="create_event.php" class="btn btn-create">+ New Event</a>
    </div>

    <!-- View Tabs -->
    <div class="view-tabs">
        <a href="<?php echo urlWithParam('view', 'list'); ?>" class="view-tab <?php if($view=='list') echo 'active'; ?>">List</a>
        <a href="<?php echo urlWithParam('view', 'day'); ?>" class="view-tab <?php if($view=='day') echo 'active'; ?>">Day</a>
        <a href="<?php echo urlWithParam('view', 'week'); ?>" class="view-tab <?php if($view=='week') echo 'active'; ?>">Week</a>
        <a href="<?php echo urlWithParam('view', 'month'); ?>" class="view-tab <?php if($view=='month') echo 'active'; ?>">Month</a>
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
                    $start = new DateTime($ev['start_time']);
                    $end = new DateTime($ev['end_time']);
                    $now = new DateTime(null, new DateTimeZone('UTC'));
                    
                    $status = 'Future';
                    $status_color = '#aaa';
                    if ($end < $now) { $status = 'Past'; $status_color = '#555'; }
                    elseif ($start <= $now && $end > $now) { $status = 'Live'; $status_color = 'var(--error-color)'; }
                ?>
                <tr>
                    <td><span style="color:<?php echo $status_color; ?>; font-weight:bold;"><?php echo $status; ?></span></td>
                    <td><?php echo htmlspecialchars($ev['event_name']); ?></td>
                    <td><?php echo htmlspecialchars($ev['tag_name']); ?></td>
                    <td><?php echo $start->setTimezone(new DateTimeZone('America/New_York'))->format('M j, g:i A'); ?></td>
                    <td><?php echo $end->setTimezone(new DateTimeZone('America/New_York'))->format('M j, g:i A'); ?></td>
                    <td><?php echo htmlspecialchars($ev['filename_original']); ?></td>
                    <td>
                        <a href="edit_event.php?id=<?php echo $ev['id']; ?>" style="color:var(--secondary-color); margin-right:10px;">Edit</a>
                        <?php if ($status != 'Live'): ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete?');">
                                <input type="hidden" name="action" value="delete_event">
                                <input type="hidden" name="event_id" value="<?php echo $ev['id']; ?>">
                                <button type="submit" style="background:none; border:none; color:var(--error-color); cursor:pointer; padding:0;">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Pagination -->
        <div class="pagination">
            <?php for($i=1; $i<=$total_pages; $i++): ?>
                <a href="<?php echo urlWithParam('page', $i); ?>" class="page-link <?php if($page==$i) echo 'active'; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
        </div>

    <?php elseif ($view == 'month'): ?>
        <div class="calendar-grid">
            <div class="cal-header">Sun</div><div class="cal-header">Mon</div><div class="cal-header">Tue</div><div class="cal-header">Wed</div><div class="cal-header">Thu</div><div class="cal-header">Fri</div><div class="cal-header">Sat</div>
            <?php
                $first_day = date('w', strtotime($start_month));
                $days_in_month = date('t', strtotime($start_month));
                
                // Empty slots
                for ($i=0; $i<$first_day; $i++) echo '<div class="cal-day" style="background:#1a1a1a;"></div>';
                
                for ($d=1; $d<=$days_in_month; $d++) {
                    echo '<div class="cal-day">';
                    echo '<div class="cal-date">' . $d . '</div>';
                    if (isset($events[$d])) {
                        foreach ($events[$d] as $ev) {
                            $time = (new DateTime($ev['start_time']))->setTimezone(new DateTimeZone('America/New_York'))->format('H:i');
                            echo '<a href="edit_event.php?id='.$ev['id'].'" class="cal-event priority-'.$ev['priority'].'">'.$time.' '.$ev['event_name'].'</a>';
                        }
                    }
                    echo '</div>';
                }
            ?>
        </div>

    <?php elseif ($view == 'week'): ?>
        <div class="week-grid">
            <?php 
                $dt = new DateTime($start_week);
                for ($i=0; $i<7; $i++) {
                    $date_str = $dt->format('Y-m-d');
                    echo '<div class="week-col">';
                    echo '<div style="text-align:center; font-weight:bold; margin-bottom:10px;">' . $dt->format('D M j') . '</div>';
                    
                    if (isset($events[$date_str])) {
                        foreach ($events[$date_str] as $ev) {
                            $start = (new DateTime($ev['start_time']))->setTimezone(new DateTimeZone('America/New_York'))->format('H:i');
                            $end = (new DateTime($ev['end_time']))->setTimezone(new DateTimeZone('America/New_York'))->format('H:i');
                            echo '<a href="edit_event.php?id='.$ev['id'].'" class="cal-event priority-'.$ev['priority'].'" style="padding:5px; margin-bottom:5px;">';
                            echo '<b>'.$start.'-'.$end.'</b><br>'.$ev['event_name'];
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
                    $start = (new DateTime($ev['start_time']))->setTimezone(new DateTimeZone('America/New_York'))->format('g:i A');
                    $end = (new DateTime($ev['end_time']))->setTimezone(new DateTimeZone('America/New_York'))->format('g:i A');
                ?>
                    <div style="padding:1em; border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            <div style="font-weight:bold; font-size:1.1em;"><?php echo $start . ' - ' . $end; ?></div>
                            <div style="color:var(--accent-color);"><?php echo htmlspecialchars($ev['event_name']); ?></div>
                            <div style="font-size:0.9em; color:#888;">Tag: <?php echo htmlspecialchars($ev['tag_name']); ?> | Asset: <?php echo htmlspecialchars($ev['filename_original']); ?></div>
                        </div>
                        <a href="edit_event.php?id=<?php echo $ev['id']; ?>" class="btn btn-sm" style="background:var(--secondary-color);">Edit</a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>

<footer>
    &copy;<?php echo date("Y") > 2025 ? "2025-" . date("Y") : "2025"; ?> WRHU Radio Hofstra University. Written by Gregory Pocali for WRHU.
</footer>

</body>
</html>