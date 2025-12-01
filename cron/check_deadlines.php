<?php
require_once '../config/database.php';
require_once '../includes/notifications.php';

$database = new Database();
$db = $database->getConnection();

$notification = new Notification($db);

$task_count = $notification->checkDeadlineNotifications();
$bug_count = $notification->checkOverdueBugs();

echo "Cron job executed at " . date('Y-m-d H:i:s') . "\n";
echo "Found {$task_count} tasks approaching deadline\n";
echo "Found {$bug_count} overdue bugs\n";

// Log the execution
error_log("Deadline cron executed: {$task_count} tasks, {$bug_count} bugs");
?>