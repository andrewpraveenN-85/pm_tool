<?php
include 'config/database.php';
include 'includes/auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireAuth();

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
        $update_query = "UPDATE tasks SET status = :status, updated_at = NOW() WHERE id = :task_id";
        $update_stmt = $db->prepare($update_query);
        $update_stmt->bindParam(':status', $status);
        $update_stmt->bindParam(':task_id', $task_id);
        
        if ($update_stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Database update failed']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
    }
}
?>