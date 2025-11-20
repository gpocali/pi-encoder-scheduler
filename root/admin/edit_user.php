<?php
require_once 'auth.php';
require_once '../db_connect.php';

require_role('admin');

if (!isset($_GET['id'])) {
    header("Location: manage_users.php");
    exit;
}

$user_id = (int)$_GET['id'];

// Fetch User
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("User not found.");
}

// Fetch All Tags for Tag Editor assignment
$stmt_tags = $pdo->query("SELECT * FROM tags ORDER BY tag_name");
$all_tags = $stmt_tags->fetchAll(PDO::FETCH_ASSOC);

// Fetch Assigned Tags
$stmt_assigned = $pdo->prepare("SELECT tag_id FROM user_tags WHERE user_id = ?");
$stmt_assigned->execute([$user_id]);
$assigned_tags = $stmt_assigned->fetchAll(PDO::FETCH_COLUMN);

$errors = [];
$success_message = '';

// Handle Form Submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    if (isset($_POST['action']) && $_POST['action'] == 'update_user') {
        $role = $_POST['role'];
        $can_change_password = isset($_POST['can_change_password']) ? 1 : 0;
        
        // Update Role & Permissions
        $stmt_upd = $pdo->prepare("UPDATE users SET role = ?, can_change_password = ? WHERE id = ?");
        $stmt_upd->execute([$role, $can_change_password, $user_id]);
        
        // Update Tags (if Tag Editor)
        $pdo->prepare("DELETE FROM user_tags WHERE user_id = ?")->execute([$user_id]);
        
        if ($role == 'tag_editor' && isset($_POST['tags'])) {
            $stmt_ins_tag = $pdo->prepare("INSERT INTO user_tags (user_id, tag_id) VALUES (?, ?)");
            foreach ($_POST['tags'] as $tag_id) {
                $stmt_ins_tag->execute([$user_id, $tag_id]);
            }
        }
        
        $success_message = "User updated successfully.";
        
        // Refresh Data
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt_assigned = $pdo->prepare("SELECT tag_id FROM user_tags WHERE user_id = ?");
        $stmt_assigned->execute([$user_id]);
        $assigned_tags = $stmt_assigned->fetchAll(PDO::FETCH_COLUMN);
    }
    
    if (isset($_POST['action']) && $_POST['action'] == 'reset_password') {
        $new_pass = $_POST['new_password'];
        if (strlen($new_pass) < 6) {
            $errors[] = "Password must be at least 6 characters.";
        } else {
            $hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt_pw = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt_pw->execute([$hash, $user_id]);
            $success_message = "Password reset successfully.";
        }
    }

    if (isset($_POST['action']) && $_POST['action'] == 'reset_2fa') {
        $stmt_2fa = $pdo->prepare("UPDATE users SET totp_secret = NULL WHERE id = ?");
        $stmt_2fa->execute([$user_id]);
        $success_message = "Two-Factor Authentication has been reset for this user.";
        // Refresh user data
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit User - WRHU Encoder Scheduler</title>
    <link rel="stylesheet" href="style.css">
    <script>
        function toggleTags() {
            const role = document.getElementById('role').value;
            document.getElementById('tag-selection').style.display = (role === 'tag_editor') ? 'block' : 'none';
        }
    </script>
</head>
<body>

    <?php include 'navbar.php'; ?>

    <div class="container">
        <h1>Edit User: <?php echo htmlspecialchars($user['username']); ?></h1>

        <?php if (!empty($errors)): ?>
            <div class="message error"><ul><?php foreach ($errors as $e) echo "<li>$e</li>"; ?></ul></div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="message success"><?php echo $success_message; ?></div>
        <?php endif; ?>

        <div class="card">
            <h2>User Details</h2>
            <form method="POST">
                <input type="hidden" name="action" value="update_user">
                
                <div class="form-group">
                    <label>Role</label>
                    <?php if ($user_id == $_SESSION['user_id']): ?>
                        <input type="hidden" name="role" value="<?php echo $user['role']; ?>">
                        <select disabled style="background: #333; color: #aaa;">
                            <option value="admin" <?php if($user['role'] == 'admin') echo 'selected'; ?>>Admin (Full Access)</option>
                            <option value="user" <?php if($user['role'] == 'user') echo 'selected'; ?>>Full User (All Tags)</option>
                            <option value="tag_editor" <?php if($user['role'] == 'tag_editor') echo 'selected'; ?>>Tag Editor (Restricted)</option>
                        </select>
                        <small style="color: #777;">You cannot change your own role.</small>
                    <?php else: ?>
                        <select name="role" id="role" onchange="toggleTags()">
                            <option value="admin" <?php if($user['role'] == 'admin') echo 'selected'; ?>>Admin (Full Access)</option>
                            <option value="user" <?php if($user['role'] == 'user') echo 'selected'; ?>>Full User (All Tags)</option>
                            <option value="tag_editor" <?php if($user['role'] == 'tag_editor') echo 'selected'; ?>>Tag Editor (Restricted)</option>
                        </select>
                    <?php endif; ?>
                </div>

                <div id="tag-selection" style="display: <?php echo ($user['role'] == 'tag_editor') ? 'block' : 'none'; ?>; margin-bottom: 1em; padding: 1em; background: #2c2c2c; border-radius: 4px;">
                    <label style="margin-bottom: 10px;">Assign Tags (for Tag Editors only):</label>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px;">
                        <?php foreach ($all_tags as $tag): ?>
                            <label style="display: flex; align-items: center; gap: 5px; font-weight: normal; color: #fff;">
                                <input type="checkbox" name="tags[]" value="<?php echo $tag['id']; ?>" <?php if(in_array($tag['id'], $assigned_tags)) echo 'checked'; ?> style="width: auto;">
                                <?php echo htmlspecialchars($tag['tag_name']); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px;">
                        <input type="checkbox" name="can_change_password" value="1" <?php if($user['can_change_password']) echo 'checked'; ?> style="width: auto;">
                        User can change their own password
                    </label>
                </div>

                <button type="submit">Save Changes</button>
            </form>
        </div>

        <div class="card">
            <h2>Reset Password</h2>
            <form method="POST">
                <input type="hidden" name="action" value="reset_password">
                <div class="form-group">
                    <label>New Password</label>
                    <input type="text" name="new_password" placeholder="Enter new password" required>
                </div>
            </form>
        </div>

        <div class="card">
            <h2>Security Settings</h2>
            <p><strong>2FA Status:</strong> <?php echo !empty($user['totp_secret']) ? '<span style="color:var(--success-color);">Enabled</span>' : '<span style="color:#777;">Disabled</span>'; ?></p>
            
            <?php if (!empty($user['totp_secret'])): ?>
                <form method="POST" onsubmit="return confirm('Are you sure you want to remove 2FA for this user?');">
                    <input type="hidden" name="action" value="reset_2fa">
                    <button type="submit" class="btn-secondary" style="background:#d9534f;">Remove 2FA</button>
                </form>
            <?php else: ?>
                <p style="font-size:0.9em; color:#aaa;">User has not enabled 2FA.</p>
            <?php endif; ?>
        </div>

        <?php if ($user_id != $_SESSION['user_id']): ?>
        <div class="card">
            <h2>Danger Zone</h2>
            <form action="manage_users.php" method="POST" onsubmit="return confirm('Are you sure you want to delete this user? This cannot be undone.');">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                <button type="submit" class="btn-delete">Delete User</button>
            </form>
        </div>
        <?php endif; ?>

    </div>

    <footer>
        &copy;<?php echo date("Y") > 2025 ? "2025-" . date("Y") : "2025"; ?> WRHU Radio Hofstra University. Written by Gregory Pocali for WRHU.
    </footer>

</body>
</html>
