<?php
require_once 'auth.php';
require_once '../db_connect.php';

// Only admin should run this
if (!is_admin()) {
    die("Access denied.");
}

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['confirm'])) {
    try {
        $pdo->beginTransaction();

        $cutoff_date = date('Y-m-d H:i:s', strtotime('+1 week'));
        $cutoff_date_only = date('Y-m-d', strtotime('+1 week'));

        // 1. Delete One-Off Events starting after cutoff
        $stmt1 = $pdo->prepare("DELETE FROM events WHERE start_time > ?");
        $stmt1->execute([$cutoff_date]);
        $deleted_events = $stmt1->rowCount();

        // 2. Delete Recurring Series starting after cutoff
        $stmt2 = $pdo->prepare("DELETE FROM recurring_events WHERE start_date > ?");
        $stmt2->execute([$cutoff_date_only]);
        $deleted_series = $stmt2->rowCount();

        // 3. Truncate Active Recurring Series (set end_date to cutoff)
        // Only for those that don't have an end_date OR end_date is after cutoff
        $stmt3 = $pdo->prepare("UPDATE recurring_events SET end_date = ? WHERE (end_date IS NULL OR end_date > ?) AND start_date <= ?");
        $stmt3->execute([$cutoff_date_only, $cutoff_date_only, $cutoff_date_only]);
        $truncated_series = $stmt3->rowCount();

        $pdo->commit();

        $message = "Cleanup Successful:<br>" .
            "- Deleted $deleted_events one-off events.<br>" .
            "- Deleted $deleted_series future recurring series.<br>" .
            "- Truncated $truncated_series active recurring series.";

    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "Error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Cleanup Events</title>
    <link rel="stylesheet" href="style.css">
</head>

<body>
    <?php include 'navbar.php'; ?>
    <div class="container">
        <h2>Cleanup Future Events</h2>
        <p>This tool will remove all schedule data more than <b>1 week</b> from now.</p>
        <ul>
            <li>One-off events starting after 1 week will be <b>deleted</b>.</li>
            <li>Recurring series starting after 1 week will be <b>deleted</b>.</li>
            <li>Active recurring series will be <b>ended</b> (truncated) at the 1-week mark.</li>
        </ul>

        <?php if ($message): ?>
            <div style="background: #eef; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
                <?php echo $message; ?>
            </div>
            <a href="index.php" class="btn btn-secondary">Back to Dashboard</a>
        <?php else: ?>
            <form method="POST" onsubmit="return confirm('Are you sure? This cannot be undone.');">
                <input type="hidden" name="confirm" value="1">
                <button type="submit" class="btn" style="background: var(--error-color); color: white;">Remove Future Events
                    (> 1 Week)</button>
            </form>
        <?php endif; ?>
    </div>
</body>

</html>