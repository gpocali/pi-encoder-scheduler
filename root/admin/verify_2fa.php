<?php
session_start();
require_once '../db_connect.php';
require_once 'TOTP.php';

if (!isset($_SESSION['partial_login']) || !isset($_SESSION['temp_user_id'])) {
    header("Location: login.php");
    exit;
}

$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $code = $_POST['code'];
    $user_id = $_SESSION['temp_user_id'];
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && TOTP::verify($user['totp_secret'], $code)) {
        // Success
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['can_change_password'] = $user['can_change_password'];
        
        unset($_SESSION['partial_login']);
        unset($_SESSION['temp_user_id']);
        
        header("Location: index.php");
        exit;
    } else {
        $error = "Invalid code. Please try again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Two-Factor Authentication - WRHU</title>
    <style>
        body { background: #121212; color: #e0e0e0; font-family: 'Inter', sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .login-container { background: #1e1e1e; padding: 2em; border-radius: 8px; width: 300px; text-align: center; box-shadow: 0 4px 10px rgba(0,0,0,0.5); }
        input { width: 100%; padding: 10px; margin: 10px 0; background: #2c2c2c; border: 1px solid #333; color: #fff; border-radius: 4px; box-sizing: border-box; text-align: center; font-size: 1.2em; letter-spacing: 5px; }
        button { width: 100%; padding: 10px; background: #bb86fc; color: #000; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; }
        .error { color: #cf6679; margin-bottom: 10px; font-size: 0.9em; }
    </style>
</head>
<body>
    <div class="login-container">
        <h2>Two-Factor Auth</h2>
        <p>Enter the 6-digit code from your authenticator app.</p>
        <?php if ($error): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>
        <form method="POST">
            <input type="text" name="code" placeholder="000000" maxlength="6" required autofocus autocomplete="one-time-code">
            <button type="submit">Verify</button>
        </form>
        <p style="margin-top:1em; font-size:0.8em;"><a href="login.php" style="color:#888;">Back to Login</a></p>
    </div>
</body>
</html>
