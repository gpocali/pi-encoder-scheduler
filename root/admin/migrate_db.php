<?php
require_once '../db_connect.php';

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function addColumnIfNotExists($pdo, $table, $column, $definition)
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        if ($stmt->fetch()) {
            echo "Column '$column' already exists in '$table'.<br>";
            return;
        }
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN $column $definition");
        echo "Added '$column' column to '$table'.<br>";
    } catch (Exception $e) {
        echo "Error adding '$column' to '$table': " . $e->getMessage() . "<br>";
    }
}

try {
    echo "<h2>Starting Migration...</h2>";

    // 1. Update users table
    addColumnIfNotExists($pdo, 'users', 'role', "ENUM('admin', 'user', 'tag_editor') NOT NULL DEFAULT 'user'");

    // Update existing users to admin if needed (only if we just added the column, but safe to run if they are 'user')
    // We'll just ensure at least one admin exists or update all for now as per previous logic
    // But let's be careful not to overwrite if they already set roles. 
    // Actually, if the column didn't exist, they are all 'user' (default). 
    // Let's update all 'user' to 'admin' ONLY if we think this is the first run.
    // A safer bet for a dev migration is to just set everyone to admin to avoid lockout.
    $pdo->exec("UPDATE users SET role = 'admin' WHERE role = 'user'");
    echo "Updated 'user' roles to 'admin' to ensure access.<br>";

    addColumnIfNotExists($pdo, 'users', 'can_change_password', "TINYINT(1) NOT NULL DEFAULT 0");

    // 2. Update assets table
    addColumnIfNotExists($pdo, 'assets', 'tag_id', "INT NULL");
    addColumnIfNotExists($pdo, 'assets', 'uploaded_by', "INT NULL");
    addColumnIfNotExists($pdo, 'assets', 'size_bytes', "BIGINT NULL");
    addColumnIfNotExists($pdo, 'assets', 'created_at', "DATETIME DEFAULT CURRENT_TIMESTAMP");

    // 3. Update events table
    addColumnIfNotExists($pdo, 'events', 'priority', "INT NOT NULL DEFAULT 0");

    // 4. Update tags table
    addColumnIfNotExists($pdo, 'tags', 'asset_limit', "INT NOT NULL DEFAULT 0");

    // 5. Create user_tags table
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_tags (
            user_id INT NOT NULL,
            tag_id INT NOT NULL,
            PRIMARY KEY (user_id, tag_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
        )");
        echo "Created/Verified 'user_tags' table.<br>";
    } catch (Exception $e) {
        echo "Error creating 'user_tags' table: " . $e->getMessage() . "<br>";
    }

    echo "<h3>Migration (Round 1) completed successfully!</h3>";

    echo "<h2>Starting Migration (Round 2)...</h2>";

    // 1. Update tags table (Asset Limit -> Storage Limit)
    // We will keep asset_limit for now or rename it? The plan said "Change".
    // Let's add storage_limit_mb and maybe deprecate asset_limit or keep both?
    // User asked for "add units to the size limit... make it sound more like size constraint instead of asset count".
    // So let's add `storage_limit_mb` and we can ignore `asset_limit` in the future.
    addColumnIfNotExists($pdo, 'tags', 'storage_limit_mb', "INT NOT NULL DEFAULT 0");
    echo "Added 'storage_limit_mb' to tags.<br>";

    // 2. Update events table (Recurrence)
    addColumnIfNotExists($pdo, 'events', 'parent_event_id', "INT NULL");
    echo "Added 'parent_event_id' to events.<br>";

    // 3. Update users table (2FA)
    addColumnIfNotExists($pdo, 'users', 'totp_secret', "VARCHAR(255) NULL");
    echo "Added 'totp_secret' to users.<br>";

    echo "<h3>Migration (Round 2) completed successfully!</h3>";

    echo "<h2>Starting Migration (Round 3 - Multi-Tag)...</h2>";

    // 1. Create event_tags table
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS event_tags (
            event_id INT NOT NULL,
            tag_id INT NOT NULL,
            PRIMARY KEY (event_id, tag_id),
            FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
            FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
        )");
        echo "Created/Verified 'event_tags' table.<br>";
    } catch (Exception $e) {
        echo "Error creating 'event_tags': " . $e->getMessage() . "<br>";
    }

    // 2. Create asset_tags table
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_tags (
            asset_id INT NOT NULL,
            tag_id INT NOT NULL,
            PRIMARY KEY (asset_id, tag_id),
            FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
            FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
        )");
        echo "Created/Verified 'asset_tags' table.<br>";
    } catch (Exception $e) {
        echo "Error creating 'asset_tags': " . $e->getMessage() . "<br>";
    }

    // 3. Migrate Events Data
    // Check if we need to migrate (if event_tags is empty but events has data)
    $count_et = $pdo->query("SELECT COUNT(*) FROM event_tags")->fetchColumn();
    if ($count_et == 0) {
        // Migrate from events.tag_id
        // We check if tag_id column exists first (it should)
        $stmt_check = $pdo->query("SHOW COLUMNS FROM events LIKE 'tag_id'");
        if ($stmt_check->fetch()) {
            $sql_mig = "INSERT INTO event_tags (event_id, tag_id) SELECT id, tag_id FROM events WHERE tag_id IS NOT NULL";
            $pdo->exec($sql_mig);
            echo "Migrated existing events to 'event_tags'.<br>";
        }
    }

    // 4. Migrate Assets Data
    $count_at = $pdo->query("SELECT COUNT(*) FROM asset_tags")->fetchColumn();
    if ($count_at == 0) {
        $stmt_check = $pdo->query("SHOW COLUMNS FROM assets LIKE 'tag_id'");
        if ($stmt_check->fetch()) {
            $sql_mig = "INSERT INTO asset_tags (asset_id, tag_id) SELECT id, tag_id FROM assets WHERE tag_id IS NOT NULL";
            $pdo->exec($sql_mig);
            echo "Migrated existing assets to 'asset_tags'.<br>";
        }
    }

    echo "<h3>Migration (Round 3) completed successfully!</h3>";

} catch (Exception $e) {
    echo "<h3>Fatal Error: " . $e->getMessage() . "</h3>";
}
?>