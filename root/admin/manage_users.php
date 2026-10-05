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
        $user_id = (int) $_POST['user_id'];
        if ($user_id == $_SESSION['user_id']) {
            $errors[] = "You cannot delete yourself.";
        } else {
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);
            $success_message = "User deleted.";
        }
    }

    // Reset 2FA
    if (isset($_POST['action']) && $_POST['action'] == 'reset_2fa') {
        $user_id = (int) $_POST['user_id'];
        $stmt = $pdo->prepare("UPDATE users SET totp_secret = NULL WHERE id = ?");
        $stmt->execute([$user_id]);
        $success_message = "2FA reset for user.";
    }
}

// Fetch Users
try {
    $stmt = $pdo->query("SELECT * FROM users ORDER BY username");
    $all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Filter out superadmins if current user is not superadmin
    $current_role = $_SESSION['role'] ?? '';
    $users = [];
    foreach ($all_users as $u) {
        if ($u['role'] === 'superadmin' && $current_role !== 'superadmin') {
            continue; // Hide superadmin from non-superadmin
        }
        $users[] = $u;
    }
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
    <title>Manage Users - WRHU Encoder Scheduler</title>
    <link rel="stylesheet" href="style.css">
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

    <?php include 'navbar.php'; ?>

    <div class="container">
        <h1>Manage Users</h1>

        <?php if (!empty($errors)): ?>
            <div class="message error">
                <ul><?php foreach ($errors as $e)
                    echo "<li>$e</li>"; ?></ul>
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
                        <?php if (($_SESSION['role'] ?? '') === 'superadmin'): ?>
                            <option value="superadmin">Superadmin (Hidden, Full Access + User Mgmt)</option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="form-group" id="tag-selection" style="display:none;">
                    <label>Assign Tags (Hold Ctrl to select multiple)</label>
                    <select name="assigned_tags[]" multiple style="height: 100px;">
                        <?php foreach ($tags as $tag): ?>
                            <option value="<?php echo $tag['id']; ?>"><?php echo htmlspecialchars($tag['tag_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px;">
                        <input type="checkbox" name="can_change_password" style="width:auto;"> User can change their own
                        password
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
                            <?php if ($user['can_change_password'])
                                echo '<span class="tag-badge">Change PW</span>'; ?>
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
                            <a href="edit_user.php?id=<?php echo $user['id']; ?>" class="btn btn-sm btn-secondary"
                                style="display:inline-block; width:auto; padding: 0.5em 1em; margin-right:5px; text-align:center;">Edit</a>

                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                <?php if ($user['totp_secret']): ?>
                                    <form method="POST" onsubmit="return confirm('Reset 2FA for this user?');"
                                        style="display:inline;">
                                        <input type="hidden" name="action" value="reset_2fa">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" class="btn btn-sm"
                                            style="background:#ff9800; color:#000; margin-left:5px;">Reset 2FA</button>
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

    <footer>
        &copy;<?php echo date("Y") > 2025 ? "2025-" . date("Y") : "2025"; ?> WRHU Radio Hofstra University. Written by
        Gregory Pocali for WRHU with assistance from Google Gemini 3.
    </footer>

</body>

</html>