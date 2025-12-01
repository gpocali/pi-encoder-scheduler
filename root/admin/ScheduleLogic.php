<?php

class ScheduleLogic
{
    public static function resolveSchedule(array $events)
    {
        // 1. Prepare events with parsed tags
        foreach ($events as &$ev) {
            if (!empty($ev['all_tags'])) {
                $ev['parsed_tags'] = explode(',', $ev['all_tags']);
            } elseif (!empty($ev['tag_id'])) {
                $ev['parsed_tags'] = [$ev['tag_id']];
            } else {
                $ev['parsed_tags'] = [];
            }
        }
        unset($ev);

        // 2. Sort ALL events by Priority DESC, then Start Time ASC
        usort($events, function ($a, $b) {
            $pA = (int) ($a['priority'] ?? 0);
            $pB = (int) ($b['priority'] ?? 0);

            if ($pA != $pB) {
                return $pB - $pA;
            }
            return strcmp($a['start_time'], $b['start_time']);
        });

        $finalSegments = [];
        $placedSegments = []; // Array of ['start'=>ts, 'end'=>ts, 'tags'=>[]]

        foreach ($events as $ev) {
            // Use UTC timestamps
            $evStart = strtotime($ev['start_time'] . ' UTC');
            $evEnd = strtotime($ev['end_time'] . ' UTC');

            $candidates = [['start' => $evStart, 'end' => $evEnd]];

            // Subtract overlapping HIGHER priority segments that SHARE A TAG
            foreach ($placedSegments as $placed) {
                // Check tag intersection
                if (empty(array_intersect($ev['parsed_tags'], $placed['tags']))) {
                    continue; // No shared tags, no conflict
                }

                $newCandidates = [];
                foreach ($candidates as $cand) {
                    $subtracted = self::subtractInterval($cand, $placed);
                    foreach ($subtracted as $s) {
                        $newCandidates[] = $s;
                    }
                }
                $candidates = $newCandidates;
            }

            // Add remaining candidates
            foreach ($candidates as $cand) {
                $newEv = $ev;
                $newEv['start_time'] = gmdate('Y-m-d H:i:s', $cand['start']);
                $newEv['end_time'] = gmdate('Y-m-d H:i:s', $cand['end']);

                if ($cand['start'] != $evStart || $cand['end'] != $evEnd) {
                    $newEv['is_modified'] = true;
                }

                $finalSegments[] = $newEv;
                $placedSegments[] = [
                    'start' => $cand['start'],
                    'end' => $cand['end'],
                    'tags' => $ev['parsed_tags']
                ];
            }
        }

        // 3. Sort final result by start time
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
