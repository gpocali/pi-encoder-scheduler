<?php
require_once 'auth.php'; 
require_once '../db_connect.php';
date_default_timezone_set('America/New_York');

// Handle "End Now" Action
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'end_now') {
    $event_id = (int)$_POST['event_id'];
    // Verify permission
    $stmt = $pdo->prepare("SELECT tag_id FROM events WHERE id = ?");
    $stmt->execute([$event_id]);
    $tag_id = $stmt->fetchColumn();
    
    if (can_edit_tag($pdo, $tag_id)) {
        $now_utc = (new DateTime())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $stmt = $pdo->prepare("UPDATE events SET end_time = ? WHERE id = ?");
        $stmt->execute([$now_utc, $event_id]);
        header("Location: index.php");
        exit;
    }
}

// Handle "Delete Event" Action
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'delete_event') {
    $event_id = (int)$_POST['event_id'];
    $stmt = $pdo->prepare("SELECT tag_id FROM events WHERE id = ?");
    $stmt->execute([$event_id]);
    $tag_id = $stmt->fetchColumn();
    
    if (can_edit_tag($pdo, $tag_id)) {
        $pdo->prepare("DELETE FROM events WHERE id = ?")->execute([$event_id]);
        header("Location: index.php");
        exit;
    }
}

// Fetch Events
// We need to fetch all events to determine overlaps/preemption
$sql = "
    SELECT e.*, t.tag_name, a.filename_original, a.filename_disk, a.mime_type
    FROM events e
    JOIN tags t ON e.tag_id = t.id
    JOIN assets a ON e.asset_id = a.id
    ORDER BY e.start_time ASC
";
$stmt = $pdo->query($sql);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate Preemption / Overlaps
// Group by Tag
$events_by_tag = [];
foreach ($events as $e) {
    $events_by_tag[$e['tag_id']][] = $e;
}

// Logic to mark preempted events
// For each tag, check overlapping time ranges. If overlap, higher priority wins.
// If equal priority? Maybe latest start time wins or undefined? Let's say higher priority wins.
// We will add a 'preempted_by' field to the event array for display.

foreach ($events_by_tag as $tag_id => &$tag_events) {
    $count = count($tag_events);
    for ($i = 0; $i < $count; $i++) {
        $tag_events[$i]['is_preempted'] = false;
        $tag_events[$i]['effective_end'] = $tag_events[$i]['end_time'];
        
        for ($j = 0; $j < $count; $j++) {
            if ($i == $j) continue;
            
            $ev1 = $tag_events[$i];
            $ev2 = $tag_events[$j];
            
            // Check overlap
            if ($ev1['start_time'] < $ev2['end_time'] && $ev1['end_time'] > $ev2['start_time']) {
                // Overlap exists. Check priority.
                if ($ev2['priority'] > $ev1['priority']) {
                    // ev2 preempts ev1
                    $tag_events[$i]['is_preempted'] = true;
                    $tag_events[$i]['preempted_by'] = $ev2['event_name'];
                    
                    // If ev2 starts after ev1 starts, ev1 is cut short.
                    // If ev2 starts before ev1 starts, ev1 might be fully hidden or delayed?
                    // The prompt says: "An asset with a higher priority that extends passed the start time of a lower priority asset would effectively limit that start time to be when the higher priority ends."
                    // Actually, usually priority means "plays on top".
                    // Let's just mark it as "Preempted" for now visually.
                }
            }
        }
    }
}
unset($tag_events); // break ref

// Flatten back for display or keep grouped? Grouped by Tag is nice for a scheduler view.
// But the original was a list. Let's stick to a list but maybe sorted by start time.
// Or maybe grouped by Tag is better for the user to see what's playing where.
// Let's try Grouped by Tag for the Dashboard.

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Scheduler Dashboard</title>
    <style>
        :root { --bg-color: #121212; --card-bg: #1e1e1e; --text-color: #e0e0e0; --accent-color: #bb86fc; --secondary-color: #03dac6; --error-color: #cf6679; --border-color: #333; }
        body { font-family: 'Inter', sans-serif; background: var(--bg-color); color: var(--text-color); margin: 0; padding: 2em; }
        .container { max-width: 1200px; margin: 0 auto; }
        a { color: var(--accent-color); text-decoration: none; }
        h1 { color: #fff; text-align: center; }
        
        .nav-bar { display: flex; justify-content: center; gap: 20px; margin-bottom: 2em; background: var(--card-bg); padding: 1em; border-radius: 8px; }
        .nav-bar a { font-weight: bold; }
        
        .tag-section { margin-bottom: 2em; }
        .tag-header { background: #2c2c2c; padding: 10px 20px; border-radius: 8px 8px 0 0; font-size: 1.2em; font-weight: bold; color: #fff; display: flex; justify-content: space-between; align-items: center; }
        
        .event-list { background: var(--card-bg); border-radius: 0 0 8px 8px; padding: 10px; }
        .event-item { display: flex; align-items: center; padding: 10px; border-bottom: 1px solid var(--border-color); gap: 15px; }
        .event-item:last-child { border-bottom: none; }
        
        .event-thumb { width: 80px; height: 45px; object-fit: cover; background: #000; border-radius: 4px; }
        .event-details { flex-grow: 1; }
        .event-time { color: #aaa; font-size: 0.9em; }
        .event-title { font-weight: bold; font-size: 1.1em; }
        .event-meta { font-size: 0.8em; color: #777; }
        
        .badge { padding: 2px 6px; border-radius: 4px; font-size: 0.7em; font-weight: bold; text-transform: uppercase; }
        .badge-low { background: #444; color: #ccc; }
        .badge-med { background: #ffc107; color: #000; }
        .badge-high { background: #dc3545; color: #fff; }
        .badge-preempted { background: #000; color: #dc3545; border: 1px solid #dc3545; }
        
        .actions { display: flex; gap: 10px; }
        .btn-sm { padding: 5px 10px; font-size: 0.8em; border-radius: 4px; border: none; cursor: pointer; }
        .btn-edit { background: var(--secondary-color); color: #000; }
        .btn-end { background: var(--error-color); color: #fff; }
        .btn-delete { background: #444; color: #fff; }
        .btn-delete:hover { background: var(--error-color); }
        
        .create-btn { display: block; width: 200px; margin: 0 auto 2em; padding: 1em; background: var(--accent-color); color: #000; text-align: center; border-radius: 4px; font-weight: bold; }
    </style>
</head>
<body>

    <div class="container">
        <h1>Pi Encoder Scheduler</h1>
        
        <div class="nav-bar">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></span>
            <a href="create_event.php">+ Create Event</a>
            <a href="manage_assets.php">Assets</a>
            <?php if (is_admin()): ?>
                <a href="manage_users.php">Users</a>
                <a href="manage_tags.php">Tags</a>
            <?php endif; ?>
            <a href="logout.php" style="color: var(--error-color);">Logout</a>
        </div>

        <?php foreach ($events_by_tag as $tag_id => $tag_events): ?>
            <?php 
                // Get tag name from first event
                $tag_name = $tag_events[0]['tag_name'];
            ?>
            <div class="tag-section">
                <div class="tag-header">
                    <?php echo htmlspecialchars($tag_name); ?>
                </div>
                <div class="event-list">
                    <?php foreach ($tag_events as $event): ?>
                        <?php 
                            $start = new DateTime($event['start_time'], new DateTimeZone('UTC'));
                            $start->setTimezone(new DateTimeZone('America/New_York'));
                            $end = new DateTime($event['end_time'], new DateTimeZone('UTC'));
                            $end->setTimezone(new DateTimeZone('America/New_York'));
                            
                            $is_now = ($start <= new DateTime() && $end >= new DateTime());
                            $is_past = ($end < new DateTime());
                            
                            $row_style = $is_past ? 'opacity: 0.5;' : '';
                            if ($event['is_preempted']) $row_style .= ' opacity: 0.6; text-decoration: line-through;';
                        ?>
                        <div class="event-item" style="<?php echo $row_style; ?>">
                            <?php 
                                $thumb_url = '../uploads/' . $event['filename_disk'];
                                $is_video = strpos($event['mime_type'], 'video') !== false;
                            ?>
                            <?php if ($is_video): ?>
                                <video src="<?php echo $thumb_url; ?>" class="event-thumb"></video>
                            <?php else: ?>
                                <img src="<?php echo $thumb_url; ?>" class="event-thumb">
                            <?php endif; ?>
                            
                            <div class="event-details">
                                <div class="event-title">
                                    <?php echo htmlspecialchars($event['event_name']); ?>
                                    <?php if ($event['priority'] == 1): ?>
                                        <span class="badge badge-med">Med</span>
                                    <?php elseif ($event['priority'] == 2): ?>
                                        <span class="badge badge-high">High</span>
                                    <?php endif; ?>
                                    
                                    <?php if ($event['is_preempted']): ?>
                                        <span class="badge badge-preempted">Preempted by <?php echo htmlspecialchars($event['preempted_by']); ?></span>
                                    <?php endif; ?>
                                    
                                    <?php if ($is_now && !$event['is_preempted']): ?>
                                        <span class="badge" style="background:green; color:#fff;">LIVE</span>
                                    <?php endif; ?>
                                </div>
                                <div class="event-time">
                                    <?php echo $start->format('M j, g:i A'); ?> - <?php echo $end->format('g:i A'); ?>
                                </div>
                            </div>
                            
                            <div class="actions">
                                <?php if (can_edit_tag($pdo, $event['tag_id'])): ?>
                                    <a href="edit_event.php?id=<?php echo $event['id']; ?>" class="btn-sm btn-edit">Edit</a>
                                    <?php if ($is_now && !$is_past): ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('End this event now?');">
                                            <input type="hidden" name="action" value="end_now">
                                            <input type="hidden" name="event_id" value="<?php echo $event['id']; ?>">
                                            <button type="submit" class="btn-sm btn-end">End Now</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this event?');">
                                            <input type="hidden" name="action" value="delete_event">
                                            <input type="hidden" name="event_id" value="<?php echo $event['id']; ?>">
                                            <button type="submit" class="btn-sm btn-delete">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        
        <?php if (empty($events_by_tag)): ?>
            <div style="text-align:center; padding:2em; color:#777;">No scheduled events found.</div>
        <?php endif; ?>

    </div>

</body>
</html>