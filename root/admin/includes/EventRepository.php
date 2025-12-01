<?php
require_once __DIR__ . '/../../db_connect.php';

class EventRepository
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Get all events (one-offs + expanded recurring) for a date range.
     * Used for Calendar/Day/Week views.
     */
    public function getEvents($startDate, $endDate, $tagId = null)
    {
        // 1. Fetch One-Off Events
        $oneOffs = $this->fetchOneOffEvents($startDate, $endDate, $tagId);

        // 2. Fetch Recurring Series that overlap with the range
        $series = $this->fetchRecurringSeries($startDate, $endDate, $tagId);

        // 3. Expand Series into Instances
        $instances = [];
        foreach ($series as $s) {
            $instances = array_merge($instances, $this->expandRecurrence($s, $startDate, $endDate));
        }

        // 4. Filter out instances that have exceptions
        // We need to fetch exceptions for the range to know what to suppress
        // Exceptions are just one-off events with is_exception=1 and recurring_event_id set
        // Actually, fetchOneOffEvents already includes them.
        // We just need to check if any one-off event "claims" to replace a recurring instance.

        $finalEvents = [];

        // Index exceptions by recurring_event_id + original_start_time
        $exceptions = [];
        foreach ($oneOffs as $ev) {
            if ($ev['is_exception'] && $ev['recurring_event_id'] && $ev['original_start_time']) {
                $key = $ev['recurring_event_id'] . '_' . $ev['original_start_time'];
                $exceptions[$key] = true;
            }
        }

        // Add instances if not excepted
        foreach ($instances as $inst) {
            $key = $inst['recurring_event_id'] . '_' . $inst['start_time']; // start_time here is the calculated start
            if (!isset($exceptions[$key])) {
                $finalEvents[] = $inst;
            }
        }

        // Add all one-offs (including exceptions)
        foreach ($oneOffs as $ev) {
            $finalEvents[] = $ev;
        }

        // Sort by start time
        usort($finalEvents, function ($a, $b) {
            return strcmp($a['start_time'], $b['start_time']);
        });

        return $finalEvents;
    }

    /**
     * Get raw recurring series for List View.
     */
    public function getRecurringSeries($tagId = null)
    {
        $sql = "
            SELECT re.*, GROUP_CONCAT(t.tag_name SEPARATOR ', ') as tag_names, GROUP_CONCAT(t.id) as tag_ids
            FROM recurring_events re
            LEFT JOIN recurring_event_tags ret ON re.id = ret.recurring_event_id
            LEFT JOIN tags t ON ret.tag_id = t.id
        ";

        $params = [];
        if ($tagId) {
            $sql .= " WHERE ret.tag_id = ? ";
            $params[] = $tagId;
        }

        $sql .= " GROUP BY re.id ORDER BY re.start_date DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get future one-off events for List View.
     */
    public function getFutureEvents($tagId = null)
    {
        $now = gmdate('Y-m-d H:i:s');
        return $this->fetchOneOffEvents($now, '2037-12-31', $tagId);
    }

    /**
     * Get the current active event for a tag (for get_graphic.php).
     * Considers priorities.
     */
    public function getCurrentEvent($tagName)
    {
        $nowUtc = gmdate('Y-m-d H:i:s');

        // 1. Get One-Offs active NOW
        $sql = "
            SELECT e.*, a.filename_disk, a.mime_type, a.md5_hash, a.filename_original
            FROM events e
            JOIN event_tags et ON e.id = et.event_id
            JOIN tags t ON et.tag_id = t.id
            JOIN assets a ON e.asset_id = a.id
            WHERE t.tag_name = ? 
              AND e.start_time <= ? AND e.end_time > ?
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tagName, $nowUtc, $nowUtc]);
        $activeOneOffs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 2. Get Recurring Series active NOW
        // We need series that started before now, ended after now (or null), and match the time/day
        // This is complex to do in SQL alone for recurrence logic, so we fetch candidates and filter in PHP.
        // Optimization: Fetch series where start_date <= Today AND (end_date >= Today OR NULL)

        $today = gmdate('Y-m-d');
        $sqlRecur = "
            SELECT re.*, a.filename_disk, a.mime_type, a.md5_hash, a.filename_original
            FROM recurring_events re
            JOIN recurring_event_tags ret ON re.id = ret.recurring_event_id
            JOIN tags t ON ret.tag_id = t.id
            JOIN assets a ON re.asset_id = a.id
            WHERE t.tag_name = ?
              AND re.start_date <= ?
              AND (re.end_date IS NULL OR re.end_date >= ?)
        ";
        $stmtRecur = $this->pdo->prepare($sqlRecur);
        $stmtRecur->execute([$tagName, $today, $today]);
        $seriesCandidates = $stmtRecur->fetchAll(PDO::FETCH_ASSOC);

        $activeInstances = [];
        // Expand only for TODAY to check overlap
        // We need to be careful with timezones. DB is UTC? 
        // Wait, the previous code used UTC for comparisons. 
        // Recurring events store `start_time` as TIME (local? or UTC?).
        // Let's assume `start_time` in recurring_events is stored in LOCAL time (America/New_York) because users enter it that way.
        // But `events` table stores `start_time` in UTC.
        // We need to convert.

        $tzLocal = new DateTimeZone('America/New_York');
        $tzUtc = new DateTimeZone('UTC');

        foreach ($seriesCandidates as $s) {
            // Generate instance for today (or yesterday/tomorrow if overlap crosses midnight)
            // Let's expand for a small window around NOW.
            $windowStart = (new DateTime('now', $tzUtc))->modify('-1 day')->format('Y-m-d H:i:s');
            $windowEnd = (new DateTime('now', $tzUtc))->modify('+1 day')->format('Y-m-d H:i:s');

            $insts = $this->expandRecurrence($s, $windowStart, $windowEnd);

            foreach ($insts as $inst) {
                if ($inst['start_time'] <= $nowUtc && $inst['end_time'] > $nowUtc) {
                    $activeInstances[] = $inst;
                }
            }
        }

        // 3. Merge and Sort by Priority
        $all = array_merge($activeOneOffs, $activeInstances);

        // Filter out exceptions from instances
        // (If an instance is active, but there is an exception for it, the exception (one-off) would be in $activeOneOffs.
        // We need to remove the instance if it has a matching exception.)

        // Check for exceptions
        $exceptionKeys = [];
        foreach ($activeOneOffs as $ev) {
            if ($ev['is_exception'] && $ev['recurring_event_id']) {
                $exceptionKeys[$ev['recurring_event_id'] . '_' . $ev['original_start_time']] = true;
            }
        }

        $final = [];
        foreach ($all as $ev) {
            // If it's a generated instance (has recurring_event_id but no 'id' in the sense of events table, 
            // actually our expandRecurrence gives it a fake ID or null? Let's check expandRecurrence)
            // expandRecurrence returns array with 'recurring_event_id'.
            // One-offs have 'id'.

            if (isset($ev['is_generated']) && $ev['is_generated']) {
                $key = $ev['recurring_event_id'] . '_' . $ev['start_time'];
                if (isset($exceptionKeys[$key])) {
                    continue; // Skip this instance, it's excepted
                }
            }
            $final[] = $ev;
        }

        // Sort: Priority DESC, StartTime ASC
        usort($final, function ($a, $b) {
            $pA = (int) ($a['priority'] ?? 0);
            $pB = (int) ($b['priority'] ?? 0);
            if ($pA != $pB)
                return $pB - $pA;
            return strcmp($a['start_time'], $b['start_time']);
        });

        return $final[0] ?? null;
    }

    // --- Private Helpers ---

    private function fetchOneOffEvents($start, $end, $tagId)
    {
        $sql = "
            SELECT e.*, GROUP_CONCAT(t.tag_name SEPARATOR ', ') as tag_names, GROUP_CONCAT(t.id) as tag_ids
            FROM events e
            LEFT JOIN event_tags et ON e.id = et.event_id
            LEFT JOIN tags t ON et.tag_id = t.id
            WHERE e.end_time >= ? AND e.start_time <= ?
        ";
        $params = [$start, $end];

        if ($tagId) {
            $sql .= " AND et.tag_id = ? ";
            $params[] = $tagId;
        }

        $sql .= " GROUP BY e.id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function fetchRecurringSeries($start, $end, $tagId)
    {
        // Series that started before the end of the range, and ended after the start of the range (or never)
        $sql = "
            SELECT re.*, GROUP_CONCAT(t.tag_name SEPARATOR ', ') as tag_names, GROUP_CONCAT(t.id) as tag_ids
            FROM recurring_events re
            LEFT JOIN recurring_event_tags ret ON re.id = ret.recurring_event_id
            LEFT JOIN tags t ON ret.tag_id = t.id
            WHERE re.start_date <= ? 
              AND (re.end_date IS NULL OR re.end_date >= ?)
        ";
        $params = [$end, $start];

        if ($tagId) {
            $sql .= " AND ret.tag_id = ? ";
            $params[] = $tagId;
        }

        $sql .= " GROUP BY re.id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function expandRecurrence($series, $rangeStart, $rangeEnd)
    {
        $instances = [];
        $tzLocal = new DateTimeZone('America/New_York');
        $tzUtc = new DateTimeZone('UTC');

        $sDate = new DateTime($series['start_date'], $tzLocal); // Series start date (local)
        $eDate = $series['end_date'] ? new DateTime($series['end_date'], $tzLocal) : null;

        // Range bounds
        $rStart = new DateTime($rangeStart, $tzUtc);
        $rEnd = new DateTime($rangeEnd, $tzUtc);

        // We iterate in LOCAL time because recurrence rules (daily/weekly) are local
        // But we need to convert output to UTC

        // Determine start point for iteration: max(series_start, range_start_local)
        // Actually, simpler to iterate from series start (or optimized start) until range end or series end.

        $iterStart = clone $sDate;
        // Optimization: Jump to range start if series started long ago
        if ($iterStart < $rStart) {
            // This is tricky for "Weekly" if we just set date to rStart. We might miss the day alignment.
            // For now, let's just start from $sDate if it's not too far back, or do simple day math.
            // Let's stick to simple iteration for safety unless performance is bad.
            // Or better: $iterStart = max($sDate, $rStart converted to local minus 1 day)
            $rStartLocal = clone $rStart;
            $rStartLocal->setTimezone($tzLocal);
            if ($iterStart < $rStartLocal) {
                $iterStart = $rStartLocal;
                $iterStart->modify('-1 day'); // Safety buffer
            }
        }

        $iterEnd = clone $rEnd;
        $iterEnd->setTimezone($tzLocal);
        if ($eDate && $eDate < $iterEnd) {
            $iterEnd = $eDate;
        }

        // Parse start time (HH:MM:SS)
        $timeParts = explode(':', $series['start_time']);
        $h = $timeParts[0];
        $m = $timeParts[1];
        $s = $timeParts[2] ?? 0;

        $current = clone $iterStart;
        $current->setTime(0, 0, 0); // Normalize to midnight

        // Weekly days
        $days = [];
        if ($series['recurrence_type'] == 'weekly' && $series['recurrence_days'] !== '') {
            $days = explode(',', $series['recurrence_days']);
        }

        while ($current <= $iterEnd) {
            // Check if matches rule
            $match = false;
            if ($series['recurrence_type'] == 'daily') {
                $match = true;
            } elseif ($series['recurrence_type'] == 'weekly') {
                if (in_array($current->format('w'), $days)) {
                    $match = true;
                }
            }

            if ($match) {
                // Construct Start Time
                $startDt = clone $current;
                $startDt->setTime($h, $m, $s);

                // Construct End Time
                $endDt = clone $startDt;
                $endDt->modify("+{$series['duration']} seconds");

                // Convert to UTC
                $startUtc = clone $startDt;
                $startUtc->setTimezone($tzUtc);
                $endUtc = clone $endDt;
                $endUtc->setTimezone($tzUtc);

                // Check overlap with range
                if ($startUtc < $rEnd && $endUtc > $rStart) {
                    $inst = $series; // Copy properties
                    $inst['id'] = 'recur_' . $series['id'] . '_' . $startUtc->getTimestamp(); // Fake ID
                    $inst['start_time'] = $startUtc->format('Y-m-d H:i:s');
                    $inst['end_time'] = $endUtc->format('Y-m-d H:i:s');
                    $inst['recurring_event_id'] = $series['id'];
                    $inst['is_generated'] = true;
                    $inst['is_exception'] = 0;

                    $instances[] = $inst;
                }
            }

            $current->modify('+1 day');
        }

        return $instances;
    }
}
?>