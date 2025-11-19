<?php
require_once '../db_connect.php';

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    echo "<h2>Starting Schema Fix for Asset Deletion...</h2>";

    // 1. Modify events table to allow NULL asset_id
    try {
        // We need to drop the FK first if it exists to modify the column safely in some MySQL versions/configs
        // But first let's try to just modify the column.
        $pdo->exec("ALTER TABLE events MODIFY COLUMN asset_id INT NULL");
        echo "Modified 'events.asset_id' to allow NULL.<br>";
    } catch (Exception $e) {
        echo "Error modifying column: " . $e->getMessage() . "<br>";
    }

    // 2. Update Foreign Key to ON DELETE SET NULL
    // We need to know the constraint name. The error message said `fk_event_asset`.
    try {
        // Drop existing FK
        $pdo->exec("ALTER TABLE events DROP FOREIGN KEY fk_event_asset");
        echo "Dropped old foreign key 'fk_event_asset'.<br>";
    } catch (Exception $e) {
        // Ignore if it doesn't exist or has a different name (though the error confirmed it)
        echo "Warning dropping FK (might not exist): " . $e->getMessage() . "<br>";
    }

    try {
        // Add new FK with ON DELETE SET NULL
        $pdo->exec("ALTER TABLE events ADD CONSTRAINT fk_event_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE SET NULL ON UPDATE CASCADE");
        echo "Added new foreign key 'fk_event_asset' with ON DELETE SET NULL.<br>";
    } catch (Exception $e) {
        echo "Error adding new FK: " . $e->getMessage() . "<br>";
    }

    echo "<h3>Schema Fix completed successfully!</h3>";

} catch (Exception $e) {
    echo "<h3>Fatal Error: " . $e->getMessage() . "</h3>";
}
?>
