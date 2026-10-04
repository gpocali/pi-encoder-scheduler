<?php
require_once 'auth.php';
require_once '../db_connect.php';

require_role('admin');

$errors = [];
$success_message = '';
$export_data = null;
$import_results = null;

// Handle Export Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // Export action
    if ($_POST['action'] === 'export_events') {
        $start_date = $_POST['export_start'] ?? '';
        $end_date = $_POST['export_end'] ?? '';
        
        if (empty($start_date) || empty($end_date)) {
            $errors[] = 'Please select both start and end dates for export.';
        } else {
            try {
                // Validate dates
                new DateTime($start_date);
                new DateTime($end_date);
                
                // Fetch events from API endpoint logic
                require_once 'api_import_export.php';
                // We need to simulate the GET request
                $_GET['start'] = $start_date;
                $_GET['end'] = $end_date;
                
                // Use output buffering to capture the JSON
                ob_start();
                // Re-run the export logic (GET portion)
                $export_data = export_events($start_date, $end_date);
                
                if ($export_data === false) {
                    $errors[] = 'Failed to export events.';
                } else {
                    $success_message = "Exported " . count($export_data) . " events. You can download the JSON below.";
                }
            } catch (Exception $e) {
                $errors[] = 'Export error: ' . $e->getMessage();
            }
        }
    }
    
    // Import action
    if ($_POST['action'] === 'import_events') {
        $json_input = $_POST['import_json'] ?? '';
        
        if (empty($json_input)) {
            $errors[] = 'Please paste JSON data or upload a file to import.';
        } else {
            $import_data = json_decode($json_input, true);
            
            if (!is_array($import_data)) {
                $errors[] = 'Invalid JSON format. Please provide a valid JSON array of events.';
            } else {
                require_once 'api_import_export.php';
                $_POST['import_data'] = $import_data;
                $import_results = process_import($import_data);
                
                if ($import_results === false) {
                    $errors[] = 'Import failed. Check the error messages.';
                } else {
                    $success_message = "Import completed: {$import_results['success']} events processed ({$import_results['created']} created, {$import_results['updated']} updated).";
                    if (!empty($import_results['errors'])) {
                        $errors = array_merge($errors, $import_results['errors']);
                    }
                }
            }
        }
    }
}

// Export helper function
function export_events($start_date, $end_date) {
    global $pdo;
    
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
        WHERE e.start_time <= ? AND e.end_time >= ?
        GROUP BY e.id
        ORDER BY e.start_time ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$end_date, $start_date]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
    
    return $export_data;
}

// Import helper function
function process_import($input_data) {
    global $pdo;
    
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
            
            if (empty($event_data['event_name'])) {
                $results['errors'][] = "Event #$event_index: Missing event_name";
                continue;
            }
            
            if (empty($event_data['start_time']) || empty($event_data['end_time'])) {
                $results['errors'][] = "Event #$event_index ('{$event_data['event_name']}'): Missing start_time or end_time";
                continue;
            }
            
            try {
                $start_dt = new DateTime($event_data['start_time']);
                $end_dt = new DateTime($event_data['end_time']);
            } catch (Exception $e) {
                $results['errors'][] = "Event #$event_index ('{$event_data['event_name']}'): Invalid date format";
                continue;
            }
            
            if ($end_dt <= $start_dt) {
                $results['errors'][] = "Event #$event_index ('{$event_data['event_name']}'): end_time must be after start_time";
                continue;
            }
            
            $asset_id = isset($event_data['asset_id']) ? (int)$event_data['asset_id'] : 0;
            if ($asset_id <= 0) {
                $results['errors'][] = "Event #$event_index ('{$event_data['event_name']}'): Invalid or missing asset_id";
                continue;
            }
            
            $stmt_asset = $pdo->prepare("SELECT id FROM assets WHERE id = ?");
            $stmt_asset->execute([$asset_id]);
            if (!$stmt_asset->fetch()) {
                $results['errors'][] = "Event #$event_index ('{$event_data['event_name']}'): Asset ID $asset_id does not exist";
                continue;
            }
            
            $tag_ids = isset($event_data['tag_ids']) && is_array($event_data['tag_ids']) 
                ? array_map('intval', $event_data['tag_ids']) 
                : [];
            
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
            
            $priority = isset($event_data['priority']) ? (int)$event_data['priority'] : 0;
            
            $event_id = isset($event_data['id']) ? (int)$event_data['id'] : 0;
            $is_update = ($event_id > 0);
            
            if ($is_update) {
                $stmt_check = $pdo->prepare("SELECT id FROM events WHERE id = ?");
                $stmt_check->execute([$event_id]);
                if (!$stmt_check->fetch()) {
                    $results['errors'][] = "Event #$event_index ('{$event_data['event_name']}'): Event ID $event_id does not exist";
                    continue;
                }
                
                $sql_update = "UPDATE events SET event_name = ?, start_time = ?, end_time = ?, asset_id = ?, priority = ? WHERE id = ?";
                $stmt_update = $pdo->prepare($sql_update);
                $stmt_update->execute([
                    $event_data['event_name'],
                    $event_data['start_time'],
                    $event_data['end_time'],
                    $asset_id,
                    $priority,
                    $event_id
                ]);
                
                $pdo->prepare("DELETE FROM event_tags WHERE event_id = ?")->execute([$event_id]);
                
                foreach ($tag_ids as $tag_id) {
                    $pdo->prepare("INSERT INTO event_tags (event_id, tag_id) VALUES (?, ?)")->execute([$event_id, $tag_id]);
                }
                
                $results['updated'][] = $event_id;
            } else {
                $sql_insert = "INSERT INTO events (event_name, start_time, end_time, asset_id, priority) VALUES (?, ?, ?, ?, ?)";
                $stmt_insert = $pdo->prepare($sql_insert);
                $stmt_insert->execute([
                    $event_data['event_name'],
                    $event_data['start_time'],
                    $event_data['end_time'],
                    $asset_id,
                    $priority
                ]);
                
                $new_event_id = $pdo->lastInsertId();
                
                foreach ($tag_ids as $tag_id) {
                    $pdo->prepare("INSERT INTO event_tags (event_id, tag_id) VALUES (?, ?)")->execute([$new_event_id, $tag_id]);
                }
                
                $results['created'][] = $new_event_id;
            }
        }
        
        $pdo->commit();
        
        $results['success'] = count($results['created']) + count($results['updated']);
        $results['failed'] = count($results['errors']);
        
        return $results;
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $results['errors'][] = 'Database error: ' . $e->getMessage();
        return false;
    }
}

// Get date range defaults
$default_start = date('Y-m-d', strtotime('-7 days'));
$default_end = date('Y-m-d', strtotime('+7 days'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Import / Export Events - WRHU Encoder Scheduler</title>
    <link rel="stylesheet" href="style.css">
    <script>
        function downloadExportData() {
            if (exportData.length === 0) {
                alert('No data to export. Please perform an export first.');
                return;
            }
            const blob = new Blob([JSON.stringify(exportData, null, 2)], {type: 'application/json'});
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'events_export_' + new Date().toISOString().slice(0, 10) + '.json';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }

        function loadImportFile(file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('import_json').value = e.target.result;
            };
            reader.readAsText(file);
        }

        function previewImportData() {
            const jsonStr = document.getElementById('import_json').value;
            if (!jsonStr.trim()) {
                alert('Please enter or upload JSON data first.');
                return;
            }
            try {
                const data = JSON.parse(jsonStr);
                if (!Array.isArray(data)) {
                    alert('JSON must be an array of event objects.');
                    return;
                }
                if (data.length === 0) {
                    alert('JSON array is empty.');
                    return;
                }
                // Show preview in console
                console.log('Events to import:', data.length);
                data.forEach((event, i) => {
                    console.log(`Event #${i + 1}:`, event.event_name, '-', event.start_time, 'to', event.end_time);
                });
                alert(`Preview: ${data.length} events will be imported.\\nCheck the browser console for details.`);
            } catch (e) {
                alert('Invalid JSON: ' + e.message);
            }
        }

        // Store exported data for download
        let exportData = [];
        
        function handleExportResponse(jsonStr) {
            try {
                exportData = JSON.parse(jsonStr);
                alert('Export successful! ' + exportData.length + ' events exported.\\nClick "Download JSON" to save the file.');
            } catch (e) {
                alert('Export completed but could not parse response.');
            }
        }
    </script>
</head>
<body>

    <?php include 'navbar.php'; ?>

    <div class="container">
        <h1>Import / Export Events</h1>

        <?php if (!empty($errors)): ?>
            <div class="message error">
                <ul><?php foreach ($errors as $e) echo "<li>" . htmlspecialchars($e) . "</li>"; ?></ul>
            </div>
        <?php endif; ?>
        
        <?php if ($success_message): ?>
            <div class="message success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <!-- Export Section -->
        <div class="card">
            <h2>Export Events</h2>
            <p style="color:#aaa; margin-top:-10px; margin-bottom:15px;">Export all events within a date range as JSON.</p>
            
            <form method="POST" action="import_export.php">
                <input type="hidden" name="action" value="export_events">
                
                <div style="display:flex; gap:20px; align-items:flex-end; margin-bottom:15px;">
                    <div style="flex:1;">
                        <label for="export_start">Start Date</label>
                        <input type="date" id="export_start" name="export_start" 
                               value="<?php echo htmlspecialchars($default_start); ?>" required
                               style="width:100%; padding:8px; background:#2c2c2c; border:1px solid #333; color:#fff; border-radius:4px;">
                    </div>
                    <div style="flex:1;">
                        <label for="export_end">End Date</label>
                        <input type="date" id="export_end" name="export_end" 
                               value="<?php echo htmlspecialchars($default_end); ?>" required
                               style="width:100%; padding:8px; background:#2c2c2c; border:1px solid #333; color:#fff; border-radius:4px;">
                    </div>
                    <button type="submit" class="btn" style="margin-bottom:0;">
                        <i class="bi bi-download"></i> Export
                    </button>
                </div>
            </form>
            
            <?php if ($export_data !== null && count($export_data) > 0): ?>
            <div style="margin-top:20px; padding:15px; background:#222; border-radius:4px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <h3>Exported Data (<?php echo count($export_data); ?> events)</h3>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="downloadExportData()">
                        <i class="bi bi-file-earmark-arrow-down"></i> Download JSON
                    </button>
                </div>
                <div style="background:#1a1a1a; border:1px solid #333; border-radius:4px; padding:10px; max-height:300px; overflow:auto; font-family:monospace; font-size:12px; white-space:pre-wrap; color:#0f0;">
                    <?php echo htmlspecialchars(json_encode($export_data, JSON_PRETTY_PRINT)); ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Import Section -->
        <div class="card" style="margin-top:20px;">
            <h2>Import Events</h2>
            <p style="color:#aaa; margin-top:-10px; margin-bottom:15px;">
                Import events from JSON. Each event object should have: <code>event_name</code>, <code>start_time</code>, <code>end_time</code>, <code>asset_id</code>, and optionally <code>id</code>, <code>tag_ids</code>, <code>priority</code>.
            </p>
            <p style="color:#aaa; margin-top:-10px; margin-bottom:15px; font-size:0.9em;">
                <strong>Upsert Logic:</strong> If <code>id</code> is provided and exists, the event is updated. If <code>id</code> is null/missing/0, a new event is created.
            </p>
            
            <form method="POST" action="import_export.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_events">
                
                <div style="margin-bottom:15px;">
                    <label for="import_json">JSON Data</label>
                    <textarea id="import_json" name="import_json" rows="10" 
                              style="width:100%; padding:10px; background:#2c2c2c; border:1px solid #333; color:#fff; border-radius:4px; font-family:monospace; font-size:12px;"
                              placeholder='[{"event_name": "Show Name", "start_time": "2025-01-01 10:00:00", "end_time": "2025-01-01 11:00:00", "asset_id": 1, "tag_ids": [1, 2], "priority": 1}]'></textarea>
                </div>
                
                <div style="display:flex; gap:10px; align-items:center; margin-bottom:15px;">
                    <label style="display:flex; align-items:center; gap:5px;">
                        <span style="color:#aaa; font-size:0.9em;">Or upload JSON file:</span>
                    </label>
                    <input type="file" id="import_file" accept=".json" 
                           onchange="loadImportFile(this.files[0])" 
                           style="margin-left:10px;">
                </div>
                
                <div style="display:flex; gap:10px;">
                    <button type="submit" class="btn" style="flex:1;">
                        <i class="bi bi-upload"></i> Import Events
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="previewImportData()">
                        <i class="bi bi-eye"></i> Preview
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('import_json').value = '';">
                        <i class="bi bi-eraser"></i> Clear
                    </button>
                </div>
            </form>
        </div>

        <!-- Example Template -->
        <div class="card" style="margin-top:20px;">
            <h2>JSON Format Reference</h2>
            <div style="background:#1a1a1a; border:1px solid #333; border-radius:4px; padding:15px; font-family:monospace; font-size:13px; white-space:pre-wrap; color:#ddd;">
<?php
$example_json = [
    [
        "id" => 1,
        "event_name" => "Morning Show - Update",
        "start_time" => "2025-06-01 09:00:00",
        "end_time" => "2025-06-01 10:00:00",
        "asset_id" => 5,
        "tag_ids" => [1, 2],
        "priority" => 1,
        "parent_event_id" => null
    ],
    [
        "event_name" => "New Show - Create",
        "start_time" => "2025-06-02 14:00:00",
        "end_time" => "2025-06-02 15:30:00",
        "asset_id" => 3,
        "tag_ids" => [1],
        "priority" => 0
    ]
];
echo json_encode($example_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
            </div>
            <p style="margin-top:10px; color:#aaa; font-size:0.9em;">
                <strong>Field descriptions:</strong><br>
                - <code>id</code>: Existing event ID (if present and > 0, updates; otherwise creates new)<br>
                - <code>event_name</code>: Display name for the event (required)<br>
                - <code>start_time</code> / <code>end_time</code>: DateTime in format "Y-m-d H:i:s" (required)<br>
                - <code>asset_id</code>: ID of the asset to play (required, must exist)<br>
                - <code>tag_ids</code>: Array of tag IDs to associate (optional)<br>
                - <code>priority</code>: Priority level (optional, defaults to 0)<br>
                - <code>parent_event_id</code>: For series events (optional)
            </p>
        </div>
    </div>

    <footer>
        &copy;<?php echo date("Y") > 2025 ? "2025-" . date("Y") : "2025"; ?> WRHU Radio Hofstra University. Written by Gregory Pocali for WRHU with assistance from Google Gemini 3.
    </footer>

</body>
</html>
