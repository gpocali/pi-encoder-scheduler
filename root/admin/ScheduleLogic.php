<?php

class ScheduleLogic
{
    /**
     * Resolves schedule conflicts by priority.
     * 
     * @param array $events List of events. Each event must have:
     *                      'id', 'tag_id', 'start_time', 'end_time', 'priority'
     *                      (start_time and end_time as 'Y-m-d H:i:s' strings)
     * @return array The resolved list of event segments.
     */
    public static function resolveSchedule(array $events)
    {
        // Group by tag_id because priorities only compete within the same tag (channel)
        $eventsByTag = [];
        foreach ($events as $ev) {
            $eventsByTag[$ev['tag_id']][] = $ev;
        }

        $finalSegments = [];

        foreach ($eventsByTag as $tagId => $tagEvents) {
            // Sort by Priority DESC, then Start Time ASC
            usort($tagEvents, function ($a, $b) {
                $pA = (int) ($a['priority'] ?? 0);
                $pB = (int) ($b['priority'] ?? 0);

                if ($pA != $pB) {
                    return $pB - $pA;
                }
                return strcmp($a['start_time'], $b['start_time']);
            });

            $placedSegments = []; // Array of ['start' => ts, 'end' => ts]

            foreach ($tagEvents as $ev) {
                // Use UTC timestamps for calculation to avoid timezone shifts
                $evStart = strtotime($ev['start_time'] . ' UTC');
                $evEnd = strtotime($ev['end_time'] . ' UTC');

                // Start with the full event as a single candidate segment
                $candidates = [['start' => $evStart, 'end' => $evEnd]];

                // Subtract all higher-priority placed segments from these candidates
                foreach ($placedSegments as $placed) {
                    $newCandidates = [];
                    foreach ($candidates as $cand) {
                        $subtracted = self::subtractInterval($cand, $placed);
                        foreach ($subtracted as $s) {
                            $newCandidates[] = $s;
                        }
                    }
                    $candidates = $newCandidates;
                }

                // Add remaining candidates to final output and to placedSegments
                foreach ($candidates as $cand) {
                    // Create a copy of the event with modified times
                    $newEv = $ev;
                    // Format back to UTC string
                    $newEv['start_time'] = gmdate('Y-m-d H:i:s', $cand['start']);
                    $newEv['end_time'] = gmdate('Y-m-d H:i:s', $cand['end']);

                    // Mark as modified if it differs from original
                    if ($cand['start'] != $evStart || $cand['end'] != $evEnd) {
                        $newEv['is_modified'] = true;
                    }

                    $finalSegments[] = $newEv;
                    $placedSegments[] = $cand;
                }
            }
        }

        // Sort final result by start time
        usort($finalSegments, function ($a, $b) {
            return strcmp($a['start_time'], $b['start_time']);
        });

        return $finalSegments;
    }

    /**
     * Subtracts $sub from $source.
     * Returns an array of remaining intervals (0, 1, or 2).
     */
    private static function subtractInterval($source, $sub)
    {
        $sStart = $source['start'];
        $sEnd = $source['end'];
        $uStart = $sub['start'];
        $uEnd = $sub['end'];

        // No overlap
        if ($uEnd <= $sStart || $uStart >= $sEnd) {
            return [$source];
        }

        $result = [];

        // Left part
        if ($uStart > $sStart) {
            $result[] = ['start' => $sStart, 'end' => $uStart];
        }

        // Right part
        if ($uEnd < $sEnd) {
            $result[] = ['start' => $uEnd, 'end' => $sEnd];
        }

        return $result;
    }
}
