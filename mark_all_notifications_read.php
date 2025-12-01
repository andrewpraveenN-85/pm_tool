<?php
include 'config/database.php';
include 'includes/auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireAuth();

header('Content-Type: application/json');

$notification = new Notification($db);
$success = $notification->markAllAsRead($_SESSION['user_id']);

echo json_encode(['success' => $success]);
?>