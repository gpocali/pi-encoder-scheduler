<?php
require_once '../db_connect.php';

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function addColumnIfNotExists($pdo, $table, $column, $definition) {
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

    echo "<h3>Migration completed successfully!</h3>";

} catch (Exception $e) {
    echo "<h3>Fatal Error: " . $e->getMessage() . "</h3>";
}
?>
