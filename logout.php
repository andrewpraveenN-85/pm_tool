<?php
include 'config/database.php';
include 'includes/auth.php';
include 'includes/activity_logger.php'; // Add ActivityLogger include

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

// Initialize ActivityLogger
$activityLogger = new ActivityLogger($db);

// Get user ID before logout
$user_id = $_SESSION['user_id'] ?? null;

// Log logout activity
if ($user_id) {
    $activityLogger->logActivity(
        $user_id,
        'logout',
        'user',
        $user_id,
        json_encode([
            'ip_address' => $_SERVER['REMOTE_ADDR'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'],
            'logout_type' => 'manual'
        ])
    );
}

// Perform logout
$auth->logout();
?>