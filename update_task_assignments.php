<?php
include 'config/database.php';
include 'includes/auth.php';
include 'includes/activity_logger.php'; // Add ActivityLogger include

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireRole(['manager']);

// Initialize ActivityLogger
$activityLogger = new ActivityLogger($db);
$current_user_id = $_SESSION['user_id'] ?? null;

if ($_POST) {
    $task_id = $_POST['task_id'];
    $assignees = $_POST['assignees'] ?? [];
    
    try {
        $db->beginTransaction();
        
        // Get old assignees for logging
        $old_assignees_query = "SELECT user_id FROM task_assignments WHERE task_id = :task_id";
        $old_assignees_stmt = $db->prepare($old_assignees_query);
        $old_assignees_stmt->bindParam(':task_id', $task_id);
        $old_assignees_stmt->execute();
        $old_assignees = $old_assignees_stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Get task details
        $task_query = "SELECT name FROM tasks WHERE id = :task_id";
        $task_stmt = $db->prepare($task_query);
        $task_stmt->bindParam(':task_id', $task_id);
        $task_stmt->execute();
        $task = $task_stmt->fetch(PDO::FETCH_ASSOC);
        
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
        
        // Get user names for logging
        $user_names = [];
        if (!empty($assignees)) {
            $placeholders = str_repeat('?,', count($assignees) - 1) . '?';
            $user_query = "SELECT id, name FROM users WHERE id IN ($placeholders)";
            $user_stmt = $db->prepare($user_query);
            $user_stmt->execute($assignees);
            $users = $user_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($users as $user) {
                $user_names[$user['id']] = $user['name'];
            }
        }
        
        $db->commit();
        
        // Log the assignment update activity
        $activityLogger->logActivity(
            $current_user_id,
            'update_assignments',
            'task',
            $task_id,
            json_encode([
                'task_name' => $task['name'],
                'old_assignees' => $old_assignees,
                'new_assignees' => $assignees,
                'new_assignee_names' => $user_names,
                'updated_by' => $current_user_id,
                'ip_address' => $_SERVER['REMOTE_ADDR']
            ])
        );
        
        // Redirect back to task details
        header("Location: task_details.php?id=" . $task_id . "&success=Assignments+updated+successfully");
        exit;
        
    } catch (Exception $e) {
        $db->rollBack();
        
        // Log the failed assignment update
        $activityLogger->logActivity(
            $current_user_id,
            'assignment_update_failed',
            'task',
            $task_id,
            json_encode([
                'task_id' => $task_id,
                'assignees' => $assignees,
                'error' => $e->getMessage(),
                'updated_by' => $current_user_id,
                'ip_address' => $_SERVER['REMOTE_ADDR']
            ])
        );
        
        header("Location: task_details.php?id=" . $task_id . "&error=Failed+to+update+assignments");
        exit;
    }
} else {
    header("Location: tasks.php");
    exit;
}
?>