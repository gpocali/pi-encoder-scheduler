<?php
$current_page = basename($_SERVER['PHP_SELF']);
$is_admin = is_admin();
?>
<nav>
    <h1>WRHU Encoder Scheduler</h1>
    <ul>
        <li><a href="index.php" class="<?php echo $current_page == 'index.php' ? 'active' : ''; ?>">Dashboard</a></li>
        <li><a href="create_event.php" class="<?php echo $current_page == 'create_event.php' ? 'active' : ''; ?>">Create Event</a></li>
        <li><a href="manage_assets.php" class="<?php echo $current_page == 'manage_assets.php' ? 'active' : ''; ?>">Assets</a></li>
        <?php if ($is_admin): ?>
            <li><a href="manage_users.php" class="<?php echo ($current_page == 'manage_users.php' || $current_page == 'edit_user.php') ? 'active' : ''; ?>">Users</a></li>
            <li><a href="manage_tags.php" class="<?php echo $current_page == 'manage_tags.php' ? 'active' : ''; ?>">Tags</a></li>
            <li><a href="default_assets.php" class="<?php echo $current_page == 'default_assets.php' ? 'active' : ''; ?>">Defaults</a></li>
        <?php endif; ?>
        <li><a href="profile.php" class="<?php echo $current_page == 'profile.php' ? 'active' : ''; ?>">Profile</a></li>
        <li><a href="logout.php">Logout</a></li>
    </ul>
</nav>
