<?php
require_once 'auth.php';
require_once '../db_connect.php';

// Require admin role for import/export operations
require_role('admin');

header('Content-Type: application/json');

// --------------------------------------------------------
// EXPORT: GET request with start/end date parameters
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $full_backup = isset($_GET['full_backup']) && ($_GET['full_backup'] === '1' || $_GET['full_backup'] === 'true');
    $tag_id = isset($_GET['tag_id']) && $_GET['tag_id'] !== '' && $_GET['tag_id'] !== 'all' ? (int)$_GET['tag_id'] : null;
    $start_date = $_GET['start'] ?? null;
    $end_date = $_GET['end'] ?? null;
    
    if (!$full_backup && (!$start_date || !$end_date)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required parameters: specify start and end dates or choose full backup']);
        exit;
    }
    
    try {
        $where_clauses = [];
        $params = [];
        
        // Date range filter (if not full backup or if dates provided)
        if (!$full_backup) {
            new DateTime($start_date);
            new DateTime($end_date);
            $where_clauses[] = "e.start_time <= ? AND e.end_time >= ?";
            $params[] = $end_date;
            $params[] = $start_date;
        } elseif ($start_date && $end_date) {
            new DateTime($start_date);
            new DateTime($end_date);
            $where_clauses[] = "e.start_time <= ? AND e.end_time >= ?";
            $params[] = $end_date;
            $params[] = $start_date;
        }
        
        // Tag filter
        if ($tag_id !== null && $tag_id > 0) {
            $where_clauses[] = "e.id IN (SELECT event_id FROM event_tags WHERE tag_id = ?)";
            $params[] = $tag_id;
        }
        
        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
        
        $sql = "
            SELECT 
                e.id,
                e.event_name,
                e.start_time,
                e.end_time,
                e.asset_id,
                e.priority,
                e.parent_event_id,
                GROUP_CONCAT(et.tag_id ORDER BY et.tag_id SEPARATOR ',') as tag_ids
            FROM events e
            LEFT JOIN event_tags et ON e.id = et.event_id
            $where_sql
            GROUP BY e.id
            ORDER BY e.start_time ASC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format the response
        $export_data = [];
        foreach ($events as $event) {
            $tag_ids = [];
            if (!empty($event['tag_ids'])) {
                $tag_ids = explode(',', $event['tag_ids']);
                $tag_ids = array_map('intval', $tag_ids);
            }
            
            $export_data[] = [
                'id' => (int)$event['id'],
                'event_name' => $event['event_name'],
                'start_time' => $event['start_time'],
                'end_time' => $event['end_time'],
                'asset_id' => (int)$event['asset_id'],
                'priority' => (int)$event['priority'],
                'parent_event_id' => $event['parent_event_id'] ? (int)$event['parent_event_id'] : null,
                'tag_ids' => $tag_ids
            ];
        }
        
        echo json_encode($export_data, JSON_PRETTY_PRINT);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Export failed: ' . $e->getMessage()]);
    }
    exit;
}

// --------------------------------------------------------
// IMPORT: POST request with JSON body
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Read JSON input
    $raw_input = file_get_contents('php://input');
    $input_data = json_decode($raw_input, true);
    
    if (!is_array($input_data)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON input. Expected an array of event objects.']);
        exit;
    }
    
    if (empty($input_data)) {
        http_response_code(400);
        echo json_encode(['error' => 'Empty input. No events to import.']);
        exit;
    }
    
    $results = [
        'created' => [],
        'updated' => [],
        'errors' => [],
        'total' => count($input_data)
    ];
    
    try {
        $pdo->beginTransaction();
        
        foreach ($input_data as $index => $event_data) {
            $event_index = $index + 1;
            
            // Validate required fields
            if (empty($event_data['event_name'])) {
                $results['errors'][] = "Event #$event_index: Missing event_name";
                continue;
            }
            
            if (empty($event_data['start_time']) || empty($event_data['end_time'])) {
                $results['errors'][] = "Event #$event_index ('{$event_data['event_name']}'): Missing start_time or end_time";
                continue;
            }
            
            // Validate date formats
            try {
                $start_dt = new DateTime($event_data['start_time']);
                $end_dt = new DateTime($event_data['end_time']);
            } catch (Exception $e) {
                $results['errors'][] = "Event #$event_index ('{$event_data['event_name']}'): Invalid date format - " . $e->getMessage();
                continue;
            }
            
            if ($end_dt <= $start_dt) {
                $results['errors'][] = "Event #$event_index ('{$event_data['event_name']}'): end_time must be after start_time";
                continue;
            }
            
            // Get asset_id (required)
            $asset_id = isset($event_data['asset_id']) ? (int)$event_data['asset_id'] : 0;
            if ($asset_id <= 0) {
                $results['errors'][] = "Event #$event_index ('{$event_data['event_name']}'): Invalid or missing asset_id";
                continue;
            }
            
            // Verify asset exists
            $stmt_asset = $pdo->prepare("SELECT id FROM assets WHERE id = ?");
            $stmt_asset->execute([$asset_id]);
            if (!$stmt_asset->fetch()) {
                $results['errors'][] = "Event #$event_index ('{$event_data['event_name']}'): Asset ID $asset_id does not exist";
                continue;
            }
            
            // Get tag_ids (optional, defaults to empty array)
            $tag_ids = isset($event_data['tag_ids']) && is_array($event_data['tag_ids']) 
                ? array_map('intval', $event_data['tag_ids']) 
                : [];
            
            // Filter out invalid tag IDs
            if (!empty($tag_ids)) {
                $valid_tag_ids = [];
                $invalid_tag_ids = [];
                foreach ($tag_ids as $tid) {
                    if ($tid > 0) {
                        $stmt_tag = $pdo->prepare("SELECT id FROM tags WHERE id = ?");
                        $stmt_tag->execute([$tid]);
                        if ($stmt_tag->fetch()) {
                            $valid_tag_ids[] = $tid;
                        } else {
                            $invalid_tag_ids[] = $tid;
                        }
                    }
                }
                if (!empty($invalid_tag_ids)) {
                    $results['errors'][] = "Event #$event_index ('{$event_data['event_name']}'): Invalid tag IDs: " . implode(', ', $invalid_tag_ids);
                }
                $tag_ids = $valid_tag_ids;
            }
            
            // Get priority (optional, defaults to 0)
            $priority = isset($event_data['priority']) ? (int)$event_data['priority'] : 0;
            
            // Determine if this is an update or insert
            $event_id = isset($event_data['id']) ? (int)$event_data['id'] : 0;
            $is_update = ($event_id > 0);
            
            if ($is_update) {
                // Verify event exists
                $stmt_check = $pdo->prepare("SELECT id FROM events WHERE id = ?");
                $stmt_check->execute([$event_id]);
                if (!$stmt_check->fetch()) {
                    $results['errors'][] = "Event #$event_index ('{$event_data['event_name']}'): Event ID $event_id does not exist";
                    continue;
                }
                
                // Update existing event
                $sql_update = "
                    UPDATE events 
                    SET event_name = ?, start_time = ?, end_time = ?, asset_id = ?, priority = ?
                    WHERE id = ?
                ";
                $stmt_update = $pdo->prepare($sql_update);
                $stmt_update->execute([
                    $event_data['event_name'],
                    $event_data['start_time'],
                    $event_data['end_time'],
                    $asset_id,
                    $priority,
                    $event_id
                ]);
                
                // Replace all tags for this event
                $pdo->prepare("DELETE FROM event_tags WHERE event_id = ?")->execute([$event_id]);
                
                foreach ($tag_ids as $tag_id) {
                    $pdo->prepare("INSERT INTO event_tags (event_id, tag_id) VALUES (?, ?)")->execute([$event_id, $tag_id]);
                }
                
                $results['updated'][] = $event_id;
                
            } else {
                // Insert new event
                $sql_insert = "
                    INSERT INTO events (event_name, start_time, end_time, asset_id, priority)
                    VALUES (?, ?, ?, ?, ?)
                ";
                $stmt_insert = $pdo->prepare($sql_insert);
                $stmt_insert->execute([
                    $event_data['event_name'],
                    $event_data['start_time'],
                    $event_data['end_time'],
                    $asset_id,
                    $priority
                ]);
                
                $new_event_id = $pdo->lastInsertId();
                
                // Insert tags
                foreach ($tag_ids as $tag_id) {
                    $pdo->prepare("INSERT INTO event_tags (event_id, tag_id) VALUES (?, ?)")->execute([$new_event_id, $tag_id]);
                }
                
                $results['created'][] = $new_event_id;
            }
        }
        
        $pdo->commit();
        
        $results['success'] = count($results['created']) + count($results['updated']);
        $results['failed'] = count($results['errors']);
        
        echo json_encode($results, JSON_PRETTY_PRINT);
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode([
            'error' => 'Import failed',
            'message' => $e->getMessage(),
            'errors' => $results['errors']
        ]);
    }
    exit;
}

// Method not allowed
http_response_code(405);
echo json_encode(['error' => 'Method not allowed. Use GET for export, POST for import.']);
exit;
?>
