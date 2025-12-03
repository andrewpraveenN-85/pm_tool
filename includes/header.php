<?php
// Check if notifications.php is already included
if (!class_exists('Notification')) {
    include 'includes/notifications.php';
}

$database = new Database();
$db = $database->getConnection();
$notification = new Notification($db);

// Get current page filename
$current_page = basename($_SERVER['PHP_SELF']);

// Function to check if page is active
function isActive($page_name) {
    global $current_page;
    return $current_page == $page_name ? 'active' : '';
}
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="dashboard.php">Task Manager</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link <?= isActive('dashboard.php') ?>" href="dashboard.php">Dashboard</a>
                </li>
                <?php if ($_SESSION['user_role'] == 'manager'): ?>
                <li class="nav-item">
                    <a class="nav-link <?= isActive('projects.php') ?>" href="projects.php">Projects</a>
                </li>
                <?php endif; ?>
                <li class="nav-item">
                    <a class="nav-link <?= isActive('tasks.php') ?>" href="tasks.php">Tasks</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= isActive('bugs.php') ?>" href="bugs.php">Bugs</a>
                </li>
                <!-- My Performance Link for All Users -->
                <?php if ($_SESSION['user_role'] != 'manager'): ?>
                <li class="nav-item">
                    <a class="nav-link <?= isActive('my_performance.php') ?>" href="my_performance.php">My Performance</a>
                </li>
                <?php endif; ?>
                <?php if ($_SESSION['user_role'] == 'manager'): ?>
                <li class="nav-item">
                    <a class="nav-link <?= isActive('users.php') ?>" href="users.php">Users</a>
                </li>
                <!-- Reports Dropdown for Managers -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= in_array($current_page, ['reports.php', 'advanced_reports.php', 'employee_tasks.php', 'employee_performance.php', 'activity_logs_report.php']) ? 'active' : '' ?>" 
                       href="#" id="reportsDropdown" role="button" data-bs-toggle="dropdown">
                        Reports
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item <?= isActive('reports.php') ?>" href="reports.php">Basic Reports</a></li>
                        <li><a class="dropdown-item <?= isActive('advanced_reports.php') ?>" href="advanced_reports.php">Advanced Analytics</a></li>
                        <li><a class="dropdown-item <?= isActive('employee_tasks.php') ?>" href="employee_tasks.php">Employee Tasks</a></li>
                        <li><a class="dropdown-item <?= isActive('employee_performance.php') ?>" href="employee_performance.php">Employee Performance</a></li>
                        <li><a class="dropdown-item <?= isActive('activity_logs_report.php') ?>" href="activity_logs_report.php">Activity Logs</a></li>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>
            <ul class="navbar-nav">
                <!-- Notifications -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= isActive('notifications.php') ?>" 
                       href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-bell"></i>
                        <?php
                        $unread_count = $notification->getUnreadCount($_SESSION['user_id']);
                        if ($unread_count > 0): ?>
                            <span class="badge bg-danger"><?= $unread_count ?></span>
                        <?php endif; ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <?php
                        $notifications = $notification->getUserNotifications($_SESSION['user_id'], 5);
                        if (empty($notifications)): ?>
                            <li><a class="dropdown-item text-muted" href="#">No notifications</a></li>
                        <?php else: ?>
                            <?php foreach ($notifications as $notif): ?>
                            <li>
                                <a class="dropdown-item <?= $notif['is_read'] ? '' : 'fw-bold' ?>" href="#">
                                    <div class="small"><?= htmlspecialchars($notif['title']) ?></div>
                                    <div class="text-muted" style="font-size: 0.8rem;"><?= htmlspecialchars($notif['message']) ?></div>
                                </a>
                            </li>
                            <?php endforeach; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-center" href="notifications.php">View All</a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <!-- User menu -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= in_array($current_page, ['profile.php', 'settings.php']) ? 'active' : '' ?>" 
                       href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user"></i> <?= htmlspecialchars($_SESSION['user_name']) ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item <?= isActive('profile.php') ?>" href="profile.php">Profile</a></li>
                        <?php if ($_SESSION['user_role'] == 'manager'): ?>
                        <li><a class="dropdown-item <?= isActive('settings.php') ?>" href="settings.php">Settings</a></li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="logout.php">Logout</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>