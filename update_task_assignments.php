<?php
include 'config/database.php';
include 'includes/auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireRole(['manager']);

if ($_POST) {
    $task_id = $_POST['task_id'];
    $assignees = $_POST['assignees'] ?? [];
    
    try {
        $db->beginTransaction();
        
        // Remove existing assignments
        $delete_query = "DELETE FROM task_assignments WHERE task_id = :task_id";
        $delete_stmt = $db->prepare($delete_query);
        $delete_stmt->bindParam(':task_id', $task_id);
        $delete_stmt->execute();
        
        // Add new assignments
        foreach ($assignees as $user_id) {
            $insert_query = "INSERT INTO task_assignments (task_id, user_id) VALUES (:task_id, :user_id)";
            $insert_stmt = $db->prepare($insert_query);
            $insert_stmt->bindParam(':task_id', $task_id);
            $insert_stmt->bindParam(':user_id', $user_id);
            $insert_stmt->execute();
        }
        
        $db->commit();
        
        // Redirect back to task details
        header("Location: task_details.php?id=" . $task_id . "&success=Assignments+updated+successfully");
        exit;
        
    } catch (Exception $e) {
        $db->rollBack();
        header("Location: task_details.php?id=" . $task_id . "&error=Failed+to+update+assignments");
        exit;
    }
} else {
    header("Location: tasks.php");
    exit;
}
?>