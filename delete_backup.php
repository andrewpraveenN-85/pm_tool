<?php
include 'config/database.php';
include 'includes/auth.php';
include 'includes/activity_logger.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireRole(['manager']);

$activityLogger = new ActivityLogger($db);
$current_user_id = $_SESSION['user_id'] ?? null;

if (isset($_GET['file'])) {
    $filename = basename($_GET['file']);
    $filepath = __DIR__ . '/backups/' . $filename;
    
    if (file_exists($filepath) && (strpos($filename, '.sql') !== false || strpos($filename, '.zip') !== false)) {
        if (unlink($filepath)) {
            // Log deletion activity
            $activityLogger->logActivity(
                $current_user_id,
                'backup_deleted',
                'system',
                "",
                json_encode([
                    'filename' => $filename,
                    'ip_address' => $_SERVER['REMOTE_ADDR']
                ])
            );
            header('Location: settings.php?success=Backup deleted successfully');
        } else {
            header('Location: settings.php?error=Failed to delete backup');
        }
    } else {
        header('Location: settings.php?error=Invalid file or file not found');
    }
} else {
    header('Location: settings.php');
}
?>