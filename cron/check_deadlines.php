<?php
require_once '../config/database.php';
require_once '../includes/notifications.php';

$database = new Database();
$db = $database->getConnection();

$notification = new Notification($db);
$notification->checkDeadlineNotifications();

echo "Deadline notifications checked at " . date('Y-m-d H:i:s');
?>