<?php
include 'config/database.php';
include 'includes/auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireAuth();

header('Content-Type: application/json');

// Get unread notifications
$notification = new Notification($db);
$notifications = $notification->getUserNotifications($_SESSION['user_id'], 10);
$unread_count = $notification->getUnreadCount($_SESSION['user_id']);

echo json_encode([
    'notifications' => $notifications,
    'unread_count' => $unread_count
]);
?>