<?php
require_once 'ScheduleLogic.php';

function runTest($name, $events)
{
    echo "<h3>Test: $name</h3>";
    $resolved = ScheduleLogic::resolveSchedule($events);
    echo "<pre>";
    foreach ($resolved as $ev) {
        echo "{$ev['event_name']} (Prio {$ev['priority']}): {$ev['start_time']} - {$ev['end_time']}\n";
    }
    echo "</pre>";
}

$events = [
    [
        'id' => 1,
        'tag_id' => 1,
        'event_name' => 'Low Priority (10-12)',
        'start_time' => '2025-01-01 10:00:00',
        'end_time' => '2025-01-01 12:00:00',
        'priority' => 1
    ],
    [
        'id' => 2,
        'tag_id' => 1,
        'event_name' => 'High Priority (10:30-11:30)',
        'start_time' => '2025-01-01 10:30:00',
        'end_time' => '2025-01-01 11:30:00',
        'priority' => 2
    ],
];

runTest('Middle Preemption', $events);
?>