<?php
require_once 'auth.php';
require_once '../db_connect.php';

// Only admin can access this page
require_role('admin');

$errors = [];
$success_message = '';

// Handle Form Submissions
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // Create User
    if (isset($_POST['action']) && $_POST['action'] == 'create_user') {
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $role = $_POST['role'];
        $can_change_pw = isset($_POST['can_change_password']) ? 1 : 0;
        $assigned_tags = $_POST['assigned_tags'] ?? [];

        if (empty($username) || empty($password)) {
            $errors[] = "Username and password are required.";
        } else {
            // Check if username exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $errors[] = "Username already exists.";
            } else {
                try {
                    $pdo->beginTransaction();
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, can_change_password) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$username, $hash, $role, $can_change_pw]);
                    $new_user_id = $pdo->lastInsertId();

                    if ($role == 'tag_editor' && !empty($assigned_tags)) {
                        $stmt_tag = $pdo->prepare("INSERT INTO user_tags (user_id, tag_id) VALUES (?, ?)");
                        foreach ($assigned_tags as $tag_id) {
                            $stmt_tag->execute([$new_user_id, $tag_id]);
                        }
                    }
                    $pdo->commit();
                    $success_message = "User created successfully.";
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $errors[] = "Error creating user: " . $e->getMessage();
                }
            }
        }
    }

    // Delete User
    if (isset($_POST['action']) && $_POST['action'] == 'delete_user') {
        $user_id = (int)$_POST['user_id'];
        if ($user_id == $_SESSION['user_id']) {
            $errors[] = "You cannot delete yourself.";
        } else {
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);
            $success_message = "User deleted.";
        }
    }

    // Reset 2FA
    if (isset($_POST['action']) && $_POST['action'] == 'reset_2fa') {
        $user_id = (int)$_POST['user_id'];
        $stmt = $pdo->prepare("UPDATE users SET totp_secret = NULL WHERE id = ?");
        $stmt->execute([$user_id]);
        $success_message = "2FA reset for user.";
    }
}

// Fetch Users
try {
    $stmt = $pdo->query("SELECT * FROM users ORDER BY username");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $errors[] = "Could not fetch users: " . $e->getMessage();
    $users = [];
}

// Fetch Tags for assignment
$tags = $pdo->query("SELECT * FROM tags ORDER BY tag_name")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Users</title>
    <style>
        /* Basic Reset & Variables */
        :root {
            --bg-color: #121212;
            --card-bg: #1e1e1e;
            --text-color: #e0e0e0;
            --accent-color: #bb86fc;
            --secondary-color: #03dac6;
            --error-color: #cf6679;
            --border-color: #333;
        }
        body { font-family: 'Inter', sans-serif; background: var(--bg-color); color: var(--text-color); margin: 0; padding: 2em; }
        .container { max-width: 1000px; margin: 0 auto; }
        a { color: var(--accent-color); text-decoration: none; }
        h1, h2 { color: #fff; }
        
        /* Tables */
        table { width: 100%; border-collapse: collapse; margin-top: 1em; background: var(--card-bg); border-radius: 8px; overflow: hidden; }
        th, td { padding: 1em; text-align: left; border-bottom: 1px solid var(--border-color); }
        th { background: #2c2c2c; font-weight: 600; }
        
        /* Forms */
        .card { background: var(--card-bg); padding: 2em; border-radius: 8px; margin-bottom: 2em; box-shadow: 0 4px 6px rgba(0,0,0,0.3); }
        .form-group { margin-bottom: 1em; }
        label { display: block; margin-bottom: 0.5em; color: #aaa; }
        input, select { width: 100%; padding: 0.8em; background: #2c2c2c; border: 1px solid var(--border-color); color: #fff; border-radius: 4px; }
        button { padding: 0.8em 1.5em; background: var(--accent-color); color: #000; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; }
        button:hover { opacity: 0.9; }
        .btn-delete { background: var(--error-color); color: #fff; padding: 0.5em 1em; }

        /* Utilities */
        .message { padding: 1em; border-radius: 4px; margin-bottom: 1em; }
        .error { background: rgba(207, 102, 121, 0.2); border: 1px solid var(--error-color); color: var(--error-color); }
        .success { background: rgba(3, 218, 198, 0.2); border: 1px solid var(--secondary-color); color: var(--secondary-color); }
        .tag-badge { display: inline-block; background: #333; padding: 2px 6px; border-radius: 4px; font-size: 0.8em; margin-right: 4px; }
    </style>
    <script>
        function generatePassword() {
            const chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*";
            let password = "";
            for (let i = 0; i < 12; i++) {
                password += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            document.getElementById('password').value = password;
        }

        function toggleTagSelection() {
            const role = document.getElementById('role').value;
            const tagSelect = document.getElementById('tag-selection');
            if (role === 'tag_editor') {
                tagSelect.style.display = 'block';
            } else {
                tagSelect.style.display = 'none';
            }
        }
    </script>
</head>
<body>

    <div class="container">
        <a href="index.php">&larr; Back to Dashboard</a>
        <h1>Manage Users</h1>

        <?php if (!empty($errors)): ?>
            <div class="message error">
                <ul><?php foreach ($errors as $e) echo "<li>$e</li>"; ?></ul>
            </div>
        <?php endif; ?>
        
        <?php if ($success_message): ?>
            <div class="message success"><?php echo $success_message; ?></div>
        <?php endif; ?>

        <div class="card">
            <h2>Create New User</h2>
            <form method="POST">
                <input type="hidden" name="action" value="create_user">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" required>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <div style="display:flex; gap:10px;">
                        <input type="text" id="password" name="password" required>
                        <button type="button" onclick="generatePassword()">Generate</button>
                    </div>
                </div>
                <div class="form-group">
                    <label>Role</label>
                    <select id="role" name="role" onchange="toggleTagSelection()">
                        <option value="user">Full User (Can create events for all tags)</option>
                        <option value="tag_editor">Tag Editor (Restricted to specific tags)</option>
                        <option value="admin">Admin (Full Access)</option>
                    </select>
                </div>
                
                <div class="form-group" id="tag-selection" style="display:none;">
                    <label>Assign Tags (Hold Ctrl to select multiple)</label>
                    <select name="assigned_tags[]" multiple style="height: 100px;">
                        <?php foreach ($tags as $tag): ?>
                            <option value="<?php echo $tag['id']; ?>"><?php echo htmlspecialchars($tag['tag_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" name="can_change_password" style="width:auto;"> User can change their own password
                    </label>
                </div>

                <button type="submit">Create User</button>
            </form>
        </div>

        <h2>Existing Users</h2>
        <table>
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Permissions</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                <tr>
                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                    <td><?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?></td>
                    <td>
                        <?php if ($user['can_change_password']) echo '<span class="tag-badge">Change PW</span>'; ?>
                        <?php 
                            if ($user['role'] == 'tag_editor') {
                                // Fetch assigned tags
                                $stmt = $pdo->prepare("SELECT t.tag_name FROM tags t JOIN user_tags ut ON t.id = ut.tag_id WHERE ut.user_id = ?");
                                $stmt->execute([$user['id']]);
                                $user_tags = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                echo "<br><small>Tags: " . implode(", ", $user_tags) . "</small>";
                            }
                        ?>
                    </td>
                    <td>
                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                        <form method="POST" onsubmit="return confirm('Delete this user?');" style="display:inline;">
                            <input type="hidden" name="action" value="delete_user">
                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                            <button type="submit" class="btn-delete">Delete</button>
                        </form>
                        <?php if ($user['totp_secret']): ?>
                            <form method="POST" onsubmit="return confirm('Reset 2FA for this user?');" style="display:inline;">
                                <input type="hidden" name="action" value="reset_2fa">
                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                <button type="submit" style="background:#ff9800; color:#000; padding:0.5em 1em; border:none; border-radius:4px; cursor:pointer;">Reset 2FA</button>
                            </form>
                        <?php endif; ?>
                        <?php else: ?>
                            <span style="color:#777;">(You)</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</body>
</html>
