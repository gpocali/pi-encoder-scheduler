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

// Scenario 5: Beginning Preemption (High Prio starts before Low and ends during it)
$events5 = [
    ['id' => 1, 'tag_id' => 1, 'event_name' => 'Low', 'priority' => 1, 'start_time' => '2025-01-01 10:00:00', 'end_time' => '2025-01-01 12:00:00'],
    ['id' => 2, 'tag_id' => 1, 'event_name' => 'High', 'priority' => 10, 'start_time' => '2025-01-01 09:00:00', 'end_time' => '2025-01-01 11:00:00'],
];
// Expected: High (09-11), Low (11-12) -> 2 segments (Low's start time becomes 11:00)
runTest('Beginning Preemption', $events5, 2);

// Scenario 6: Beginning Preemption at Same Start Time (High Prio starts at same time, ends earlier)
$events6 = [
    ['id' => 1, 'tag_id' => 1, 'event_name' => 'Low', 'priority' => 1, 'start_time' => '2025-01-01 10:00:00', 'end_time' => '2025-01-01 12:00:00'],
    ['id' => 2, 'tag_id' => 1, 'event_name' => 'High', 'priority' => 10, 'start_time' => '2025-01-01 10:00:00', 'end_time' => '2025-01-01 11:00:00'],
];
// Expected: High (10-11), Low (11-12) -> 2 segments (Low's start time becomes 11:00)
runTest('Beginning Preemption (Same Start)', $events6, 2);

// Scenario 7: Same Priority (No preemption between equal priority events)
$events7 = [
    ['id' => 1, 'tag_id' => 1, 'event_name' => 'Show A', 'priority' => 0, 'start_time' => '2025-01-01 10:00:00', 'end_time' => '2025-01-01 12:00:00'],
    ['id' => 2, 'tag_id' => 1, 'event_name' => 'Show B', 'priority' => 0, 'start_time' => '2025-01-01 11:00:00', 'end_time' => '2025-01-01 13:00:00'],
];
// Expected: Show A (10-12), Show B (11-13) -> 2 segments (neither preempts the other)
runTest('Equal Priority Non-Preemption', $events7, 2);

// Scenario 8: Multi-tag support via tag_ids
$events8 = [
    ['id' => 1, 'tag_ids' => '1,2', 'event_name' => 'Low Multi-tag', 'priority' => 1, 'start_time' => '2025-01-01 10:00:00', 'end_time' => '2025-01-01 12:00:00'],
    ['id' => 2, 'tag_id' => 1, 'event_name' => 'High Tag 1', 'priority' => 10, 'start_time' => '2025-01-01 11:00:00', 'end_time' => '2025-01-01 13:00:00'],
];
// On tag 1: Low (10-11), High (11-13) -> 2 segments
// On tag 2: Low (10-12) -> 1 segment
// Total resolved across tags: 3 segments
runTest('Multi-tag Resolution', $events8, 3);

