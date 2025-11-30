<?php
require_once 'auth.php';
require_role(['admin', 'user']);

header('Content-Type: application/json');

$file = __DIR__ . '/../data/live_stats.json';

if (file_exists($file)) {
    readfile($file);
} else {
    echo json_encode(['error' => 'Stats file not found']);
}
?>