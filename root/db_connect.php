<?php
/*
 * Database Connection
 */

$credentials = json_decode(file_get_contents("/etc/scheduler/db_info.conf"), true);

// --- UPDATE WITH YOUR DATABASE DETAILS ---
$db_host = 'localhost';    // or 'localhost'
$db_name = 'scheduler_db'; // The database name you created
$db_user = $credentials["username"];     // Your database username
$db_pass = $credentials["password"];     // Your database password
// -----------------------------------------

$charset = 'utf8mb4';

$dsn = "mysql:host=$db_host;dbname=$db_name;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $db_user, $db_pass, $options);
} catch (\PDOException $e) {
     throw new \PDOException($e->getMessage(), (int)$e->getCode());
}
?>
