<?php
require_once '../config/database.php';
require_once '../includes/notifications.php';

$database = new Database();
$db = $database->getConnection();

$notification = new Notification($db);

// Check all types of notifications
$task_count = $notification->checkDeadlineNotifications();
$bug_count = $notification->checkOverdueBugs();

echo "Daily notification check at " . date('Y-m-d H:i:s') . "\n";
echo "Tasks approaching deadline: {$task_count}\n";
echo "Overdue bugs: {$bug_count}\n";

// Log the execution
error_log("Daily notification check: {$task_count} tasks, {$bug_count} bugs");
?>