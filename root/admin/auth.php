<?php
// Start the session.
session_start();

// Check if the user ID is set in the session.
if (!isset($_SESSION['user_id'])) {
    // If not, the user is not logged in.
    // Redirect them to the login page and stop script execution.
    header("Location: login.php");
    exit;
}
?>