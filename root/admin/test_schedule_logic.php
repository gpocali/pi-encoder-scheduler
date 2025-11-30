<?php
require_once 'ScheduleLogic.php';

function runTest($name, $events, $expectedCount)
{
    echo "Running Test: $name\n";
    $resolved = ScheduleLogic::resolveSchedule($events);

    echo "Input Events: " . count($events) . "\n";
    echo "Resolved Segments: " . count($resolved) . "\n";

    foreach ($resolved as $r) {
        echo "  [{$r['priority']}] {$r['start_time']} - {$r['end_time']} ({$r['event_name']})\n";
    }

    if (count($resolved) == $expectedCount) {
        echo "PASS\n";
    } else {
        echo "FAIL (Expected $expectedCount)\n";
    }
    echo "------------------------------------------------\n";
}

// Scenario 1: Simple Overlap (High Prio starts after Low Prio)
$events1 = [
    ['id' => 1, 'tag_id' => 1, 'event_name' => 'Low', 'priority' => 1, 'start_time' => '2025-01-01 10:00:00', 'end_time' => '2025-01-01 12:00:00'],
    ['id' => 2, 'tag_id' => 1, 'event_name' => 'High', 'priority' => 10, 'start_time' => '2025-01-01 11:00:00', 'end_time' => '2025-01-01 13:00:00'],
];
// Expected: Low (10-11), High (11-13) -> 2 segments

// Scenario 2: Enveloped (High Prio completely covers Low Prio)
$events2 = [
    ['id' => 1, 'tag_id' => 1, 'event_name' => 'Low', 'priority' => 1, 'start_time' => '2025-01-01 10:00:00', 'end_time' => '2025-01-01 11:00:00'],
    ['id' => 2, 'tag_id' => 1, 'event_name' => 'High', 'priority' => 10, 'start_time' => '2025-01-01 09:00:00', 'end_time' => '2025-01-01 12:00:00'],
];
// Expected: High (09-12) -> 1 segment (Low is gone)

// Scenario 3: Middle Preemption (High Prio splits Low Prio)
$events3 = [
    ['id' => 1, 'tag_id' => 1, 'event_name' => 'Low', 'priority' => 1, 'start_time' => '2025-01-01 10:00:00', 'end_time' => '2025-01-01 14:00:00'],
    ['id' => 2, 'tag_id' => 1, 'event_name' => 'High', 'priority' => 10, 'start_time' => '2025-01-01 11:00:00', 'end_time' => '2025-01-01 12:00:00'],
];
// Expected: Low (10-11), High (11-12), Low (12-14) -> 3 segments

// Scenario 4: Multi-level Priority
$events4 = [
    ['id' => 1, 'tag_id' => 1, 'event_name' => 'Low', 'priority' => 1, 'start_time' => '2025-01-01 10:00:00', 'end_time' => '2025-01-01 14:00:00'],
    ['id' => 2, 'tag_id' => 1, 'event_name' => 'Med', 'priority' => 5, 'start_time' => '2025-01-01 11:00:00', 'end_time' => '2025-01-01 13:00:00'],
    ['id' => 3, 'tag_id' => 1, 'event_name' => 'High', 'priority' => 10, 'start_time' => '2025-01-01 11:30:00', 'end_time' => '2025-01-01 12:30:00'],
];
// Expected: 
// Low (10-11)
// Med (11-11:30)
// High (11:30-12:30)
// Med (12:30-13)
// Low (13-14)
// Total 5 segments

runTest('Simple Overlap', $events1, 2);
runTest('Enveloped', $events2, 1);
runTest('Middle Preemption', $events3, 3);
runTest('Multi-level Priority', $events4, 5);
