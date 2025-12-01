<?php
require_once 'auth.php';
require_once '../db_connect.php';

$start_date = $_GET['start'];
$end_date = $_GET['end'];

require_once 'includes/EventRepository.php';

$repo = new EventRepository($pdo);

try {
    // getEvents expects UTC strings or local? 
    // FullCalendar sends ISO8601 strings usually.
    // EventRepository handles expansion.

    // Convert input to UTC if needed, but getEvents assumes input is the range to check.
    // Let's pass them directly.

    $results = $repo->getEvents($start_date, $end_date);

    $events_json = [];
    foreach ($results as $row) {
        $tag_names = $row['tag_names'] ?? '';
        // If tag_names missing (e.g. generated instance might not have them joined yet if I didn't add it to expandRecurrence)
        // expandRecurrence copies properties from series. Series fetch includes GROUP_CONCAT tags.
        // So it should be there.

        $events_json[] = [
            'id' => $row['id'],
            'title' => "({$tag_names}) " . $row['event_name'],
            'start' => $row['start_time'],
            'end' => $row['end_time']
        ];
    }

    header('Content-Type: application/json');
    echo json_encode($events_json);
    exit;

} catch (Exception $e) {
    header('Content-Type: application/json', true, 500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
?>