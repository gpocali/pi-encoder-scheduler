<?php
require_once 'auth.php'; 
require_once '../db_connect.php';

$start_date = $_GET['start'];
$end_date = $_GET['end'];

$sql = "
    SELECT 
        e.id, 
        e.event_name, 
        e.start_time, 
        e.end_time, 
        t.tag_name 
    FROM events e
    JOIN tags t ON e.tag_id = t.id
    WHERE 
        e.start_time <= ? AND e.end_time >= ?
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$end_date, $start_date]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $events_json = [];
    foreach ($results as $row) {
        $events_json[] = [
            'id'    => $row['id'],
            'title' => "({$row['tag_name']}) " . $row['event_name'],
            'start' => $row['start_time'], 
            'end'   => $row['end_time']
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