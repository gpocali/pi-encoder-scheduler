<?php
// Place this in your project's root, NOT in the /admin folder.
require_once 'db_connect.php';

echo "<h1>Admin User Creator</h1>";

try {
    // --- SET YOUR DESIRED ADMIN CREDENTIALS HERE ---
    $admin_username = "admin";
    $admin_password = "YourSecurePassword123!"; // Change this!
    
    // --- Hashing the password ---
    $password_hash = password_hash($admin_password, PASSWORD_DEFAULT);

    // --- Insert the user into the database ---
    $sql = "INSERT INTO users (username, password_hash) VALUES (?, ?)";
    $stmt = $pdo->prepare($sql);
    
    // Check if user already exists
    $check_sql = "SELECT id FROM users WHERE username = ?";
    $check_stmt = $pdo->prepare($check_sql);
    $check_stmt->execute([$admin_username]);
    
    if ($check_stmt->fetch()) {
        echo "<p style='color:red;'><strong>Error:</strong> User '{$admin_username}' already exists. No action taken.</p>";
    } else {
        $stmt->execute([$admin_username, $password_hash]);
        echo "<p style='color:green;'><strong>Success!</strong> Admin user '{$admin_username}' was created.</p>";
        echo "<p>You can now log in at <a href='/admin/login.php'>/admin/login.php</a></p>";
        echo "<p style='font-weight:bold; color:red;'>!!! DELETE THIS FILE (create_admin.php) IMMEDIATELY !!!</p>";
    }

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>