<?php
require_once 'root/db_connect.php';

try {
    $stmt = $pdo->query("SELECT DISTINCT priority FROM events");
    $priorities = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Distinct Priorities found in DB: " . implode(", ", $priorities) . "\n";

    $stmt2 = $pdo->query("SELECT id, event_name, priority FROM events ORDER BY id DESC LIMIT 10");
    echo "\nLast 10 events:\n";
    foreach ($stmt2 as $row) {
        echo "ID: {$row['id']}, Name: {$row['event_name']}, Priority: {$row['priority']}\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>