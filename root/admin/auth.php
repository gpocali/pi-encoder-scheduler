<?php
// Start the session.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if the user ID is set in the session.
if (!isset($_SESSION['user_id'])) {
    // If not, the user is not logged in.
    // Redirect them to the login page and stop script execution.
    header("Location: login.php");
    exit;
}

// Helper function to check roles
function has_role($roles) {
    if (!is_array($roles)) {
        $roles = [$roles];
    }
    return in_array($_SESSION['role'] ?? '', $roles);
}

function require_role($roles) {
    if (!has_role($roles)) {
        die("Access Denied: You do not have permission to view this page.");
    }
}

function is_admin() {
    return has_role('admin');
}

// Helper to check if user can edit a specific tag (for Tag Editors)
// This requires fetching assigned tags from DB if not in session. 
// For simplicity, let's fetch them if needed or store in session on login?
// Storing in session is easier but requires login update. 
// Let's fetch on demand for now to be safe and dynamic.
function can_edit_tag($pdo, $tag_id) {
    if (is_admin() || has_role('user')) { // Admin and Full User can edit all
        return true;
    }
    if (has_role('tag_editor')) {
        $stmt = $pdo->prepare("SELECT 1 FROM user_tags WHERE user_id = ? AND tag_id = ?");
        $stmt->execute([$_SESSION['user_id'], $tag_id]);
        return (bool)$stmt->fetch();
    }
    return false;
}
?>