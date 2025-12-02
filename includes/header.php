<?php
// Check if notifications.php is already included
if (!class_exists('Notification')) {
    include 'includes/notifications.php';
}

$database = new Database();
$db = $database->getConnection();
$notification = new Notification($db);
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
                    <a class="nav-link active" href="dashboard.php">Dashboard</a>
                </li>
                <?php if ($_SESSION['user_role'] == 'manager'): ?>
                <li class="nav-item">
                    <a class="nav-link" href="projects.php">Projects</a>
                </li>
                <?php endif; ?>
                <li class="nav-item">
                    <a class="nav-link" href="tasks.php">Tasks</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="bugs.php">Bugs</a>
                </li>
                <!-- My Performance Link for All Users -->
                <li class="nav-item">
                    <a class="nav-link" href="my_performance.php">My Performance</a>
                </li>
                <?php if ($_SESSION['user_role'] == 'manager'): ?>
                <li class="nav-item">
                    <a class="nav-link" href="users.php">Users</a>
                </li>
                <!-- Reports Dropdown for Managers -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="reportsDropdown" role="button" data-bs-toggle="dropdown">
                        Reports
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="reports.php">Basic Reports</a></li>
                        <li><a class="dropdown-item" href="advanced_reports.php">Advanced Analytics</a></li>
                        <li><a class="dropdown-item" href="employee_tasks.php">Employee Tasks</a></li>
                        <li><a class="dropdown-item" href="employee_performance.php">Employee Performance</a></li>
                        <li><a class="dropdown-item" href="activity_logs_report.php">Activity Logs</a></li>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>
            <ul class="navbar-nav">
                <!-- Notifications -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
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
                    <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user"></i> <?= htmlspecialchars($_SESSION['user_name']) ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="profile.php">Profile</a></li>
                        <?php if ($_SESSION['user_role'] == 'manager'): ?>
                        <li><a class="dropdown-item" href="settings.php">Settings</a></li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="logout.php">Logout</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>