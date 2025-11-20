<?php
require_once 'auth.php';
require_once '../db_connect.php';
require_once 'TOTP.php';

$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$success = '';
$error = '';

// Enable 2FA
if (isset($_POST['action']) && $_POST['action'] == 'enable_2fa') {
    $secret = $_POST['secret'];
    $code = $_POST['code'];
    
    if (TOTP::verify($secret, $code)) {
        $stmt = $pdo->prepare("UPDATE users SET totp_secret = ? WHERE id = ?");
        $stmt->execute([$secret, $user_id]);
        $success = "2FA Enabled Successfully!";
        $user['totp_secret'] = $secret; // Update local
    } else {
        $error = "Invalid code. 2FA not enabled.";
    }
}

// Disable 2FA
if (isset($_POST['action']) && $_POST['action'] == 'disable_2fa') {
    $stmt = $pdo->prepare("UPDATE users SET totp_secret = NULL WHERE id = ?");
    $stmt->execute([$user_id]);
    $success = "2FA Disabled.";
    $user['totp_secret'] = null;
}

// Change Password
if (isset($_POST['action']) && $_POST['action'] == 'change_password') {
    if (!$user['can_change_password']) {
        $error = "You do not have permission to change your password.";
    } else {
        $new_pass = $_POST['new_password'];
        if (strlen($new_pass) < 6) {
            $error = "Password must be at least 6 characters.";
        } else {
            $hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hash, $user_id]);
            $success = "Password updated.";
        }
    }
}

// Generate new secret for setup
$new_secret = TOTP::generateSecret();
$qr_url = TOTP::getProvisioningUri($user['username'], $new_secret);
$qr_img_url = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($qr_url);


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Profile - WRHU</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <?php include 'navbar.php'; ?>

    <div class="container">
        <h1>My Profile: <?php echo htmlspecialchars($user['username']); ?></h1>
        
        <?php if ($success): ?><div class="message success"><?php echo $success; ?></div><?php endif; ?>
        <?php if ($error): ?><div class="message error"><?php echo $error; ?></div><?php endif; ?>

        <!-- 2FA Section -->
        <div class="card">
            <h2>Two-Factor Authentication</h2>
            <?php if ($user['totp_secret']): ?>
                <p style="color:var(--secondary-color);">Status: <strong>ENABLED</strong></p>
                <form method="POST" onsubmit="return confirm('Disable 2FA?');">
                    <input type="hidden" name="action" value="disable_2fa">
                    <button type="submit" class="btn-delete">Disable 2FA</button>
                </form>
            <?php else: ?>
                <p>Status: <strong>DISABLED</strong></p>
                <p>To enable 2FA, scan this QR code with your authenticator app (Google Authenticator, Authy, etc.) and enter the code below.</p>
                <div style="text-align:center; margin:20px;">
                    <img src="<?php echo $qr_img_url; ?>" alt="QR Code">
                    <p style="font-family:monospace; color:#777;"><?php echo $new_secret; ?></p>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="enable_2fa">
                    <input type="hidden" name="secret" value="<?php echo $new_secret; ?>">
                    <div class="form-group">
                        <label>Enter 6-digit Code:</label>
                        <input type="text" name="code" placeholder="000000" required style="width:150px; text-align:center;">
                    </div>
                    <button type="submit">Verify & Enable</button>
                </form>
            <?php endif; ?>
        </div>

        <!-- Password Section -->
        <?php if ($user['can_change_password']): ?>
        <div class="card">
            <h2>Change Password</h2>
            <form method="POST">
                <input type="hidden" name="action" value="change_password">
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" required>
                </div>
                <button type="submit">Update Password</button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <footer>
        &copy;<?php echo date("Y") > 2025 ? "2025-" . date("Y") : "2025"; ?> WRHU Radio Hofstra University. Written by Gregory Pocali for WRHU.
    </footer>

</body>
</html>
