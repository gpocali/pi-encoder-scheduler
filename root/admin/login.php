<?php
session_start();

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once '../db_connect.php'; 

$error_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        $error_message = "Username and password are required.";
    } else {
        $sql = "SELECT id, username, password_hash, role, can_change_password, totp_secret FROM users WHERE username = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true); 
            
            // Check for 2FA
            if (!empty($user['totp_secret'])) {
                $_SESSION['partial_login'] = true;
                $_SESSION['temp_user_id'] = $user['id'];
                header("Location: verify_2fa.php");
                exit;
            }

            // No 2FA, log in directly
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['can_change_password'] = $user['can_change_password'];
            
            header("Location: index.php");
            exit;
        } else {
            $error_message = "Invalid username or password.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login</title>
    <style>
        body { font-family: sans-serif; display: grid; place-items: center; min-height: 100vh; background: #f4f4f4; }
        .login-container { width: 320px; padding: 2em; background: #fff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { text-align: center; margin-top: 0; }
        .form-group { margin-bottom: 1.5em; }
        .form-group label { display: block; margin-bottom: 0.5em; font-weight: bold; }
        .form-group input { width: 100%; padding: 0.8em; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px; }
        .btn { display: block; width: 100%; padding: 1em; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 1em; }
        .btn:hover { background-color: #0056b3; }
        .error { text-align: center; background: #f8d7da; color: #721c24; padding: 0.8em; border-radius: 4px; margin-bottom: 1em; }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>Admin Login</h1>

        <?php if (!empty($error_message)): ?>
            <div class="error"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="btn">Login</button>
        </form>
    </div>
</body>
</html>