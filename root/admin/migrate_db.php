<?php
require_once '../db_connect.php';

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->beginTransaction();

    // 1. Update users table
    // Check if columns exist before adding to avoid errors on re-run
    $columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('role', $columns)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN role ENUM('admin', 'user', 'tag_editor') NOT NULL DEFAULT 'user'");
        echo "Added 'role' column to users table.<br>";
        
        // Set existing users to admin if they are the default or just to be safe for now? 
        // Let's assume the current user is admin.
        // We'll set all current users to 'admin' to prevent lockout, or 'user'?
        // Given it's a small tool, maybe 'admin' for existing users is safer so they don't lose access.
        $pdo->exec("UPDATE users SET role = 'admin'");
        echo "Updated existing users to 'admin' role.<br>";
    }

    if (!in_array('can_change_password', $columns)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN can_change_password TINYINT(1) NOT NULL DEFAULT 0");
        echo "Added 'can_change_password' column to users table.<br>";
    }

    // 2. Update assets table
    $columns_assets = $pdo->query("SHOW COLUMNS FROM assets")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('tag_id', $columns_assets)) {
        $pdo->exec("ALTER TABLE assets ADD COLUMN tag_id INT NULL");
        echo "Added 'tag_id' column to assets table.<br>";
    }
    if (!in_array('uploaded_by', $columns_assets)) {
        $pdo->exec("ALTER TABLE assets ADD COLUMN uploaded_by INT NULL");
        echo "Added 'uploaded_by' column to assets table.<br>";
    }
    if (!in_array('size_bytes', $columns_assets)) {
        $pdo->exec("ALTER TABLE assets ADD COLUMN size_bytes BIGINT NULL");
        echo "Added 'size_bytes' column to assets table.<br>";
    }
    if (!in_array('created_at', $columns_assets)) {
        $pdo->exec("ALTER TABLE assets ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP");
        echo "Added 'created_at' column to assets table.<br>";
    }

    // 3. Update events table
    $columns_events = $pdo->query("SHOW COLUMNS FROM events")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('priority', $columns_events)) {
        $pdo->exec("ALTER TABLE events ADD COLUMN priority INT NOT NULL DEFAULT 0");
        echo "Added 'priority' column to events table.<br>";
    }

    // 4. Update tags table
    $columns_tags = $pdo->query("SHOW COLUMNS FROM tags")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('asset_limit', $columns_tags)) {
        $pdo->exec("ALTER TABLE tags ADD COLUMN asset_limit INT NOT NULL DEFAULT 0"); // 0 means no limit? Or maybe a high number. Let's say 0 is unlimited for now, or we enforce it later.
        echo "Added 'asset_limit' column to tags table.<br>";
    }

    // 5. Create user_tags table
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_tags (
        user_id INT NOT NULL,
        tag_id INT NOT NULL,
        PRIMARY KEY (user_id, tag_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
    )");
    echo "Created/Verified 'user_tags' table.<br>";

    $pdo->commit();
    echo "<strong>Migration completed successfully!</strong>";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Error: " . $e->getMessage();
}
?>
