<?php
include 'config/database.php';
include 'includes/auth.php';
include 'includes/activity_logger.php'; // Add ActivityLogger include

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireAuth();

// Initialize ActivityLogger
$activityLogger = new ActivityLogger($db);
$current_user_id = $_SESSION['user_id'] ?? null;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $task_id = $input['task_id'];
    $status = $input['status'];
    
    // Verify user has permission to update this task
    $query = "SELECT t.* FROM tasks t 
              LEFT JOIN task_assignments ta ON t.id = ta.task_id 
              WHERE t.id = :task_id AND (t.created_by = :user_id OR ta.user_id = :user_id OR :user_role = 'manager')";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':task_id', $task_id);
    $stmt->bindParam(':user_id', $_SESSION['user_id']);
    $stmt->bindParam(':user_role', $_SESSION['user_role']);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $task = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get old status for logging
        $old_status = $task['status'];
        
        $update_query = "UPDATE tasks SET status = :status, updated_at = NOW() WHERE id = :task_id";
        $update_stmt = $db->prepare($update_query);
        $update_stmt->bindParam(':status', $status);
        $update_stmt->bindParam(':task_id', $task_id);
        
        if ($update_stmt->execute()) {
            // Log the status update activity
            $activityLogger->logActivity(
                $current_user_id,
                'update_status',
                'task',
                $task_id,
                json_encode([
                    'task_name' => $task['name'],
                    'old_status' => $old_status,
                    'new_status' => $status,
                    'updated_by' => $current_user_id,
                    'user_role' => $_SESSION['user_role'],
                    'ip_address' => $_SERVER['REMOTE_ADDR']
                ])
            );
            
            echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
        } else {
            // Log the failed status update
            $activityLogger->logActivity(
                $current_user_id,
                'status_update_failed',
                'task',
                $task_id,
                json_encode([
                    'task_name' => $task['name'],
                    'old_status' => $old_status,
                    'new_status' => $status,
                    'error' => 'Database update failed',
                    'updated_by' => $current_user_id,
                    'ip_address' => $_SERVER['REMOTE_ADDR']
                ])
            );
            
            echo json_encode(['success' => false, 'error' => 'Database update failed']);
        }
    } else {
        // Log unauthorized status update attempt
        $activityLogger->logActivity(
            $current_user_id,
            'unauthorized_status_update',
            'task',
            $task_id,
            json_encode([
                'task_id' => $task_id,
                'requested_status' => $status,
                'user_id' => $current_user_id,
                'user_role' => $_SESSION['user_role'],
                'ip_address' => $_SERVER['REMOTE_ADDR'],
                'error' => 'Permission denied'
            ])
        );
        
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
    }
}
?>