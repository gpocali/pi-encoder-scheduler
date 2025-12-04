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

    // Update existing users to admin if needed
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
    $count_et = $pdo->query("SELECT COUNT(*) FROM event_tags")->fetchColumn();
    if ($count_et == 0) {
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

    echo "<h2>Starting Migration (Round 4 - UI Enhancements)...</h2>";

    // 5. Add display_name to assets
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM assets LIKE 'display_name'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE assets ADD COLUMN display_name VARCHAR(255) AFTER filename_original");
            $pdo->exec("UPDATE assets SET display_name = filename_original");
            echo "Added 'display_name' to assets.<br>";
        } else {
            echo "'display_name' column already exists.<br>";
        }
    } catch (Exception $e) {
        echo "Error adding 'display_name': " . $e->getMessage() . "<br>";
    }

    echo "<h3>Migration (Round 4) completed successfully!</h3>";

    echo "<h2>Starting Migration (Round 5 - Recurring Events Refactor)...</h2>";

    // 1. Create recurring_events table
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS recurring_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_name VARCHAR(255) NOT NULL,
            start_time TIME NOT NULL,
            duration INT NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NULL,
            recurrence_type ENUM('daily', 'weekly') NOT NULL,
            recurrence_days VARCHAR(255) NULL,
            asset_id INT NOT NULL,
            priority INT NOT NULL DEFAULT 0,
            parent_event_id INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (asset_id) REFERENCES assets(id),
            FOREIGN KEY (parent_event_id) REFERENCES recurring_events(id) ON DELETE SET NULL
        )");
        echo "Created/Verified 'recurring_events' table.<br>";
    } catch (Exception $e) {
        echo "Error creating 'recurring_events': " . $e->getMessage() . "<br>";
    }

    // 2. Create recurring_event_tags table
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS recurring_event_tags (
            recurring_event_id INT NOT NULL,
            tag_id INT NOT NULL,
            PRIMARY KEY (recurring_event_id, tag_id),
            FOREIGN KEY (recurring_event_id) REFERENCES recurring_events(id) ON DELETE CASCADE,
            FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
        )");
        echo "Created/Verified 'recurring_event_tags' table.<br>";
    } catch (Exception $e) {
        echo "Error creating 'recurring_event_tags': " . $e->getMessage() . "<br>";
    }

    // 3. Update events table
    addColumnIfNotExists($pdo, 'events', 'recurring_event_id', "INT NULL");
    addColumnIfNotExists($pdo, 'events', 'original_start_time', "DATETIME NULL");
    addColumnIfNotExists($pdo, 'events', 'is_exception', "TINYINT(1) NOT NULL DEFAULT 0");

    try {
        $pdo->exec("ALTER TABLE events ADD CONSTRAINT fk_events_recurring FOREIGN KEY (recurring_event_id) REFERENCES recurring_events(id) ON DELETE SET NULL");
        echo "Added FK constraint to events.recurring_event_id.<br>";
    } catch (Exception $e) {
        // Ignore if already exists
    }

    echo "<h3>Migration (Round 5) completed successfully!</h3>";

} catch (Exception $e) {
    echo "<h3>Fatal Error: " . $e->getMessage() . "</h3>";
}
?>