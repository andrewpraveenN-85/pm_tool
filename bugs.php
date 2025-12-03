<?php
include 'config/database.php';
include 'includes/auth.php';
include 'includes/notifications.php';
include 'includes/activity_logger.php'; // New include for activity logging

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireRole(['manager', 'qa']);

// Initialize services
$notification = new Notification($db);
$activityLogger = new ActivityLogger($db); // Initialize activity logger

// Handle form submissions
if ($_POST) {
    if (isset($_POST['create_bug'])) {
        // Form validation
        $errors = [];
        
        // Required field validation
        $required_fields = ['name', 'description', 'project_id', 'task_id', 'priority', 'status'];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required.";
            }
        }
        
        // Validate project exists
        if (!empty($_POST['project_id'])) {
            $project_check = $db->prepare("SELECT id, name FROM projects WHERE id = ? AND status = 'active'");
            $project_check->execute([$_POST['project_id']]);
            $project_data = $project_check->fetch(PDO::FETCH_ASSOC);
            if ($project_check->rowCount() === 0) {
                $errors[] = "Selected project does not exist or is not active.";
            }
        }
        
        // Validate task exists and belongs to the selected project
        if (!empty($_POST['task_id']) && !empty($_POST['project_id'])) {
            $task_check = $db->prepare("SELECT id, name FROM tasks WHERE id = ? AND project_id = ?");
            $task_check->execute([$_POST['task_id'], $_POST['project_id']]);
            $task_data = $task_check->fetch(PDO::FETCH_ASSOC);
            if ($task_check->rowCount() === 0) {
                $errors[] = "Selected task does not exist in the chosen project.";
            }
        }
        
        // Validate dates if provided
        if (!empty($_POST['start_datetime']) && !empty($_POST['end_datetime'])) {
            $start_date = strtotime($_POST['start_datetime']);
            $end_date = strtotime($_POST['end_datetime']);
            
            if ($end_date < $start_date) {
                $errors[] = "End date cannot be earlier than start date.";
            }
        }
        
        // Validate file uploads
        if (!empty($_FILES['attachments']['name'][0])) {
            $max_file_size = 10 * 1024 * 1024; // 10MB
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 
                            'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'text/plain', 'application/zip'];
            
            foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                    // Check file size
                    if ($_FILES['attachments']['size'][$key] > $max_file_size) {
                        $errors[] = "File '{$_FILES['attachments']['name'][$key]}' exceeds maximum size of 10MB.";
                    }
                    
                    // Check file type
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime_type = finfo_file($finfo, $tmp_name);
                    finfo_close($finfo);
                    
                    if (!in_array($mime_type, $allowed_types)) {
                        $errors[] = "File '{$_FILES['attachments']['name'][$key]}' has an invalid file type.";
                    }
                }
            }
        }
        
        if (empty($errors)) {
            $name = $_POST['name'];
            $description = $_POST['description'];
            $project_id = $_POST['project_id'];
            $task_id = $_POST['task_id'];
            $priority = $_POST['priority'];
            $status = $_POST['status'];
            $start_datetime = !empty($_POST['start_datetime']) ? $_POST['start_datetime'] : null;
            $end_datetime = !empty($_POST['end_datetime']) ? $_POST['end_datetime'] : null;
            
            try {
                $db->beginTransaction();
                
                $query = "INSERT INTO bugs (name, description, task_id, priority, status, start_datetime, end_datetime, created_by) 
                          VALUES (:name, :description, :task_id, :priority, :status, :start_datetime, :end_datetime, :created_by)";
                
                $stmt = $db->prepare($query);
                $stmt->bindParam(':name', $name);
                $stmt->bindParam(':description', $description);
                $stmt->bindParam(':task_id', $task_id);
                $stmt->bindParam(':priority', $priority);
                $stmt->bindParam(':status', $status);
                $stmt->bindParam(':start_datetime', $start_datetime);
                $stmt->bindParam(':end_datetime', $end_datetime);
                $stmt->bindParam(':created_by', $_SESSION['user_id']);
                $stmt->execute();
                
                $bug_id = $db->lastInsertId();
                
                // Handle file uploads for the bug
                $file_count = 0;
                if (!empty($_FILES['attachments']['name'][0])) {
                    $upload_dir = 'uploads/bugs/' . $bug_id . '/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
                        if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                            $original_name = $_FILES['attachments']['name'][$key];
                            $file_extension = pathinfo($original_name, PATHINFO_EXTENSION);
                            $filename = 'bug_' . $bug_id . '_' . uniqid() . '.' . $file_extension;
                            $target_file = $upload_dir . $filename;
                            
                            if (move_uploaded_file($tmp_name, $target_file)) {
                                $query = "INSERT INTO attachments (entity_type, entity_id, filename, original_name, file_path, file_size, file_type, uploaded_by) 
                                          VALUES ('bug', :entity_id, :filename, :original_name, :file_path, :file_size, :file_type, :uploaded_by)";
                                $stmt = $db->prepare($query);
                                $stmt->bindParam(':entity_id', $bug_id);
                                $stmt->bindParam(':filename', $filename);
                                $stmt->bindParam(':original_name', $original_name);
                                $stmt->bindParam(':file_path', $target_file);
                                $stmt->bindParam(':file_size', $_FILES['attachments']['size'][$key]);
                                $stmt->bindParam(':file_type', $_FILES['attachments']['type'][$key]);
                                $stmt->bindParam(':uploaded_by', $_SESSION['user_id']);
                                $stmt->execute();
                                $file_count++;
                            }
                        }
                    }
                }
                
                $db->commit();
                
                // Log the activity
                $activity_details = [
                    'bug_id' => $bug_id,
                    'bug_name' => $name,
                    'task_id' => $task_id,
                    'task_name' => $task_data['name'] ?? 'Unknown Task',
                    'project_id' => $project_id,
                    'project_name' => $project_data['name'] ?? 'Unknown Project',
                    'priority' => $priority,
                    'status' => $status,
                    'files_uploaded' => $file_count
                ];
                
                $activityLogger->logActivity(
                    $_SESSION['user_id'],
                    'bug_created',
                    'Bug reported',
                    json_encode($activity_details),
                    $bug_id
                );
                
                // Send bug report notification
                $notification->createBugReportNotification($bug_id);
                
                $success = "Bug reported successfully!";
                
                // Clear form data after successful submission
                $_POST = array();
                
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Failed to report bug: " . $e->getMessage();
                
                // Log the error
                $activityLogger->logActivity(
                    $_SESSION['user_id'],
                    'bug_create_error',
                    'Failed to report bug',
                    json_encode(['error' => $e->getMessage(), 'bug_name' => $name ?? 'Unknown']),
                    null
                );
            }
        } else {
            $error = implode("<br>", $errors);
        }
    }
    
    // Handle bug update
    if (isset($_POST['update_bug'])) {
        $bug_id = $_POST['bug_id'];
        $name = $_POST['name'];
        $description = $_POST['description'];
        $project_id = $_POST['project_id'];
        $task_id = $_POST['task_id'];
        $priority = $_POST['priority'];
        $status = $_POST['status'];
        $start_datetime = !empty($_POST['start_datetime']) ? $_POST['start_datetime'] : null;
        $end_datetime = !empty($_POST['end_datetime']) ? $_POST['end_datetime'] : null;
        
        // Get current bug data for comparison
        $current_bug_query = "SELECT * FROM bugs WHERE id = :id";
        $current_bug_stmt = $db->prepare($current_bug_query);
        $current_bug_stmt->bindParam(':id', $bug_id);
        $current_bug_stmt->execute();
        $current_bug = $current_bug_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Validate required fields
        $errors = [];
        $required_fields = ['name', 'description', 'project_id', 'task_id', 'priority', 'status'];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required.";
            }
        }
        
        // Validate dates if provided
        if (!empty($start_datetime) && !empty($end_datetime)) {
            $start_date = strtotime($start_datetime);
            $end_date = strtotime($end_datetime);
            
            if ($end_date < $start_date) {
                $errors[] = "End date cannot be earlier than start date.";
            }
        }
        
        // Validate new file uploads
        if (!empty($_FILES['new_attachments']['name'][0])) {
            $max_file_size = 10 * 1024 * 1024; // 10MB
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 
                            'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'text/plain', 'application/zip'];
            
            foreach ($_FILES['new_attachments']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['new_attachments']['error'][$key] === UPLOAD_ERR_OK) {
                    // Check file size
                    if ($_FILES['new_attachments']['size'][$key] > $max_file_size) {
                        $errors[] = "File '{$_FILES['new_attachments']['name'][$key]}' exceeds maximum size of 10MB.";
                    }
                    
                    // Check file type
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime_type = finfo_file($finfo, $tmp_name);
                    finfo_close($finfo);
                    
                    if (!in_array($mime_type, $allowed_types)) {
                        $errors[] = "File '{$_FILES['new_attachments']['name'][$key]}' has an invalid file type.";
                    }
                }
            }
        }
        
        if (empty($errors)) {
            try {
                $db->beginTransaction();
                
                // Update bug
                $query = "UPDATE bugs SET 
                          name = :name, 
                          description = :description, 
                          task_id = :task_id, 
                          priority = :priority, 
                          status = :status, 
                          start_datetime = :start_datetime, 
                          end_datetime = :end_datetime,
                          updated_at = NOW()
                          WHERE id = :id";
                
                $stmt = $db->prepare($query);
                $stmt->bindParam(':name', $name);
                $stmt->bindParam(':description', $description);
                $stmt->bindParam(':task_id', $task_id);
                $stmt->bindParam(':priority', $priority);
                $stmt->bindParam(':status', $status);
                $stmt->bindParam(':start_datetime', $start_datetime);
                $stmt->bindParam(':end_datetime', $end_datetime);
                $stmt->bindParam(':id', $bug_id);
                $stmt->execute();
                
                // Handle new file uploads
                $new_file_count = 0;
                if (!empty($_FILES['new_attachments']['name'][0])) {
                    $upload_dir = 'uploads/bugs/' . $bug_id . '/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    foreach ($_FILES['new_attachments']['tmp_name'] as $key => $tmp_name) {
                        if ($_FILES['new_attachments']['error'][$key] === UPLOAD_ERR_OK) {
                            $original_name = $_FILES['new_attachments']['name'][$key];
                            $file_extension = pathinfo($original_name, PATHINFO_EXTENSION);
                            $filename = 'bug_' . $bug_id . '_' . uniqid() . '.' . $file_extension;
                            $target_file = $upload_dir . $filename;
                            
                            if (move_uploaded_file($tmp_name, $target_file)) {
                                $query = "INSERT INTO attachments (entity_type, entity_id, filename, original_name, file_path, file_size, file_type, uploaded_by) 
                                          VALUES ('bug', :entity_id, :filename, :original_name, :file_path, :file_size, :file_type, :uploaded_by)";
                                $stmt = $db->prepare($query);
                                $stmt->bindParam(':entity_id', $bug_id);
                                $stmt->bindParam(':filename', $filename);
                                $stmt->bindParam(':original_name', $original_name);
                                $stmt->bindParam(':file_path', $target_file);
                                $stmt->bindParam(':file_size', $_FILES['new_attachments']['size'][$key]);
                                $stmt->bindParam(':file_type', $_FILES['new_attachments']['type'][$key]);
                                $stmt->bindParam(':uploaded_by', $_SESSION['user_id']);
                                $stmt->execute();
                                $new_file_count++;
                            }
                        }
                    }
                }
                
                $db->commit();
                
                // Prepare activity details
                $changes = [];
                if ($current_bug['name'] != $name) $changes['name'] = ['from' => $current_bug['name'], 'to' => $name];
                if ($current_bug['description'] != $description) $changes['description'] = ['type' => 'updated'];
                if ($current_bug['task_id'] != $task_id) $changes['task'] = ['from' => $current_bug['task_id'], 'to' => $task_id];
                if ($current_bug['priority'] != $priority) $changes['priority'] = ['from' => $current_bug['priority'], 'to' => $priority];
                if ($current_bug['status'] != $status) $changes['status'] = ['from' => $current_bug['status'], 'to' => $status];
                if ($current_bug['start_datetime'] != $start_datetime) $changes['start_date'] = ['from' => $current_bug['start_datetime'], 'to' => $start_datetime];
                if ($current_bug['end_datetime'] != $end_datetime) $changes['end_date'] = ['from' => $current_bug['end_datetime'], 'to' => $end_datetime];
                
                $activity_details = [
                    'bug_id' => $bug_id,
                    'bug_name' => $name,
                    'changes' => $changes,
                    'new_files_uploaded' => $new_file_count
                ];
                
                // Log the activity
                $activityLogger->logActivity(
                    $_SESSION['user_id'],
                    'bug_updated',
                    'Bug updated',
                    json_encode($activity_details),
                    $bug_id
                );
                
                // Send bug update notification
                $notification->createBugUpdateNotification($bug_id);
                
                $success = "Bug updated successfully!";
                
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Failed to update bug: " . $e->getMessage();
                
                // Log the error
                $activityLogger->logActivity(
                    $_SESSION['user_id'],
                    'bug_update_error',
                    'Failed to update bug',
                    json_encode(['error' => $e->getMessage(), 'bug_id' => $bug_id]),
                    $bug_id
                );
            }
        } else {
            $error = implode("<br>", $errors);
        }
    }
    
    if (isset($_POST['update_bug_status'])) {
        $bug_id = $_POST['bug_id'];
        $status = $_POST['status'];
        
        // Get current bug status and name
        $current_bug_query = "SELECT name, status FROM bugs WHERE id = :id";
        $current_bug_stmt = $db->prepare($current_bug_query);
        $current_bug_stmt->bindParam(':id', $bug_id);
        $current_bug_stmt->execute();
        $current_bug = $current_bug_stmt->fetch(PDO::FETCH_ASSOC);
        
        try {
            $db->beginTransaction();
            
            // Get bug details for notification
            $bug_query = "
                SELECT b.name, b.priority, b.task_id, t.created_by as task_manager_id, 
                       GROUP_CONCAT(DISTINCT ta.user_id) as assignee_ids
                FROM bugs b
                LEFT JOIN tasks t ON b.task_id = t.id
                LEFT JOIN task_assignments ta ON t.id = ta.task_id
                WHERE b.id = :bug_id
                GROUP BY b.id
            ";
            $bug_stmt = $db->prepare($bug_query);
            $bug_stmt->bindParam(':bug_id', $bug_id);
            $bug_stmt->execute();
            $bug = $bug_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Update bug status
            $query = "UPDATE bugs SET status = :status, updated_at = NOW() WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':id', $bug_id);
            
            if ($stmt->execute()) {
                $db->commit();
                
                // Log the status change activity
                $activity_details = [
                    'bug_id' => $bug_id,
                    'bug_name' => $current_bug['name'],
                    'status_change' => [
                        'from' => $current_bug['status'],
                        'to' => $status
                    ]
                ];
                
                $activityLogger->logActivity(
                    $_SESSION['user_id'],
                    'bug_status_updated',
                    'Bug status updated',
                    json_encode($activity_details),
                    $bug_id
                );
                
                // Send bug status update notification
                if ($bug) {
                    $assignee_ids = !empty($bug['assignee_ids']) ? explode(',', $bug['assignee_ids']) : [];
                    $notification->createBugStatusUpdateNotification($bug_id, $status, $assignee_ids, $bug['task_manager_id']);
                }
                
                $success = "Bug status updated successfully!";
            } else {
                throw new Exception("Failed to update bug status");
            }
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Failed to update bug status: " . $e->getMessage();
            
            // Log the error
            $activityLogger->logActivity(
                $_SESSION['user_id'],
                'bug_status_update_error',
                'Failed to update bug status',
                json_encode(['error' => $e->getMessage(), 'bug_id' => $bug_id]),
                $bug_id
            );
        }
    }
    
    // Handle attachment deletion
    if (isset($_POST['delete_attachment'])) {
        $attachment_id = $_POST['attachment_id'];
        $bug_id = $_POST['bug_id'];
        
        try {
            // Get attachment details
            $attachment_query = "SELECT file_path, original_name FROM attachments WHERE id = :id AND entity_type = 'bug'";
            $attachment_stmt = $db->prepare($attachment_query);
            $attachment_stmt->bindParam(':id', $attachment_id);
            $attachment_stmt->execute();
            $attachment = $attachment_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($attachment) {
                // Delete file from server
                if (file_exists($attachment['file_path'])) {
                    unlink($attachment['file_path']);
                }
                
                // Delete record from database
                $delete_query = "DELETE FROM attachments WHERE id = :id";
                $delete_stmt = $db->prepare($delete_query);
                $delete_stmt->bindParam(':id', $attachment_id);
                $delete_stmt->execute();
                
                // Log the activity
                $activity_details = [
                    'bug_id' => $bug_id,
                    'attachment_name' => $attachment['original_name'],
                    'attachment_path' => $attachment['file_path']
                ];
                
                $activityLogger->logActivity(
                    $_SESSION['user_id'],
                    'bug_attachment_deleted',
                    'Bug attachment deleted',
                    json_encode($activity_details),
                    $bug_id
                );
                
                $success = "Attachment deleted successfully!";
            }
        } catch (Exception $e) {
            $error = "Failed to delete attachment: " . $e->getMessage();
            
            // Log the error
            $activityLogger->logActivity(
                $_SESSION['user_id'],
                'attachment_delete_error',
                'Failed to delete attachment',
                json_encode(['error' => $e->getMessage(), 'attachment_id' => $attachment_id]),
                $bug_id
            );
        }
    }
}

// Get filter parameters
$project_filter = $_GET['project_filter'] ?? '';
$task_filter = $_GET['task_filter'] ?? '';

// Build WHERE clause for filters
$where_conditions = [];
$query_params = [];

if (!empty($project_filter)) {
    $where_conditions[] = "p.id = :project_id";
    $query_params[':project_id'] = $project_filter;
}

if (!empty($task_filter)) {
    $where_conditions[] = "b.task_id = :task_id";
    $query_params[':task_id'] = $task_filter;
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
}

// Get bugs based on user role with filters
if ($_SESSION['user_role'] == 'manager') {
    $bugs_query = "
        SELECT b.*, t.name as task_name, p.name as project_name, u.name as created_by_name, p.id as project_id
        FROM bugs b
        LEFT JOIN tasks t ON b.task_id = t.id
        LEFT JOIN projects p ON t.project_id = p.id
        LEFT JOIN users u ON b.created_by = u.id
        $where_clause
        ORDER BY b.created_at DESC
    ";
} else {
    $bugs_query = "
        SELECT b.*, t.name as task_name, p.name as project_name, u.name as created_by_name, p.id as project_id
        FROM bugs b
        LEFT JOIN tasks t ON b.task_id = t.id
        LEFT JOIN projects p ON t.project_id = p.id
        LEFT JOIN users u ON b.created_by = u.id
        $where_clause
        ORDER BY b.created_at DESC
    ";
}

$stmt = $db->prepare($bugs_query);
foreach ($query_params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$bugs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get projects for filters and form
$projects = $db->query("SELECT id, name FROM projects WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get tasks for dropdown (all active tasks) - this is for the filter dropdown
$all_tasks = $db->query("
    SELECT t.id, t.name, p.name as project_name, p.id as project_id 
    FROM tasks t 
    LEFT JOIN projects p ON t.project_id = p.id 
    WHERE t.status != 'closed' AND t.status != 'completed' AND p.status = 'active'
    ORDER BY p.name, t.name
")->fetchAll(PDO::FETCH_ASSOC);

// Get tasks filtered by selected project for the form
$selected_project_id = $_POST['project_id'] ?? $_GET['form_project'] ?? '';
$form_tasks = [];
if (!empty($selected_project_id)) {
    $task_stmt = $db->prepare("
        SELECT t.id, t.name 
        FROM tasks t 
        WHERE t.project_id = ? 
        AND t.status != 'closed' 
        AND t.status != 'completed'
        ORDER BY t.name
    ");
    $task_stmt->execute([$selected_project_id]);
    $form_tasks = $task_stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // If no project selected, show all active tasks
    $task_stmt = $db->prepare("
        SELECT t.id, t.name 
        FROM tasks t 
        LEFT JOIN projects p ON t.project_id = p.id
        WHERE t.status != 'closed' 
        AND t.status != 'completed'
        AND p.status = 'active'
        ORDER BY t.name
    ");
    $task_stmt->execute();
    $form_tasks = $task_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get bug details for edit if bug_id is provided
$edit_bug = null;
$bug_attachments = [];
if (isset($_GET['edit_bug'])) {
    $bug_id = $_GET['edit_bug'];
    
    $bug_query = "SELECT b.*, t.name as task_name, p.name as project_name, p.id as project_id 
                  FROM bugs b 
                  LEFT JOIN tasks t ON b.task_id = t.id 
                  LEFT JOIN projects p ON t.project_id = p.id 
                  WHERE b.id = :id";
    $bug_stmt = $db->prepare($bug_query);
    $bug_stmt->bindParam(':id', $bug_id);
    $bug_stmt->execute();
    $edit_bug = $bug_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($edit_bug) {
        // Get attachments
        $attachments_query = "SELECT * FROM attachments WHERE entity_type = 'bug' AND entity_id = :bug_id ORDER BY uploaded_at DESC";
        $attachments_stmt = $db->prepare($attachments_query);
        $attachments_stmt->bindParam(':bug_id', $bug_id);
        $attachments_stmt->execute();
        $bug_attachments = $attachments_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bugs - Task Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/tinymce@6.8.2/tinymce.min.js"></script>
    <style>
        .attachment-item {
            padding: 8px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            margin-bottom: 8px;
            background: #f8f9fa;
        }
        .attachment-item:hover {
            background: #e9ecef;
        }
        .activity-log {
            max-height: 300px;
            overflow-y: auto;
        }
        .activity-item {
            padding: 8px;
            border-bottom: 1px solid #dee2e6;
            font-size: 0.9rem;
        }
        .activity-item:last-child {
            border-bottom: none;
        }
        .activity-icon {
            width: 24px;
            text-align: center;
            margin-right: 8px;
        }
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        tinymce.init({
            selector: 'textarea.wysiwyg',
            plugins: 'anchor autolink charmap codesample emoticons image link lists media searchreplace table visualblocks wordcount',
            toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | link image media table | align lineheight | numlist bullist indent outdent | emoticons charmap | removeformat',
            menubar: false,
            height: 300,
            promotion: false,
            branding: false
        });
        
        // Handle attachment deletion
        const deleteAttachmentButtons = document.querySelectorAll('.delete-attachment');
        deleteAttachmentButtons.forEach(button => {
            button.addEventListener('click', function(e) {
                if (!confirm('Are you sure you want to delete this attachment?')) {
                    e.preventDefault();
                }
            });
        });
    });
    </script>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>Bug Tracking</h2>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createBugModal">
                        <i class="fas fa-bug"></i> Report Bug
                    </button>
                </div>

                <?php if (isset($success)): ?>
                    <div class="alert alert-success"><?= $success ?></div>
                <?php endif; ?>
                
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?= $error ?></div>
                <?php endif; ?>

                <!-- Filter Section -->
                <div class="card mb-4">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Filters</h5>
                            <a href="?view_activities=<?= isset($_GET['view_activities']) && $_GET['view_activities'] == 'true' ? 'false' : 'true' ?>" 
                               class="btn btn-sm btn-outline-info">
                                <i class="fas fa-history"></i> 
                                <?= isset($_GET['view_activities']) && $_GET['view_activities'] == 'true' ? 'Hide Activities' : 'Show Activities' ?>
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <input type="hidden" name="view_activities" value="<?= $_GET['view_activities'] ?? '' ?>">
                            <div class="col-md-4">
                                <label class="form-label">Project</label>
                                <select class="form-select" name="project_filter" id="projectFilter" onchange="this.form.submit()">
                                    <option value="">All Projects</option>
                                    <?php foreach ($projects as $project): ?>
                                        <option value="<?= $project['id'] ?>" <?= ($project_filter == $project['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($project['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Task</label>
                                <select class="form-select" name="task_filter" id="taskFilter" onchange="this.form.submit()">
                                    <option value="">All Tasks</option>
                                    <?php 
                                    $filtered_tasks = $all_tasks;
                                    if (!empty($project_filter)) {
                                        $filtered_tasks = array_filter($all_tasks, function($task) use ($project_filter) {
                                            return $task['project_id'] == $project_filter;
                                        });
                                    }
                                    foreach ($filtered_tasks as $task): ?>
                                        <option value="<?= $task['id'] ?>" <?= ($task_filter == $task['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($task['name']) ?> (<?= htmlspecialchars($task['project_name']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <a href="bugs.php<?= isset($_GET['view_activities']) && $_GET['view_activities'] == 'true' ? '?view_activities=true' : '' ?>" 
                                   class="btn btn-outline-secondary">Clear Filters</a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>Bug Name</th>
                                <th>Task</th>
                                <th>Project</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Reported By</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Attachments</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bugs as $bug): 
                                $attachments_count = $db->query("SELECT COUNT(*) FROM attachments WHERE entity_type = 'bug' AND entity_id = " . $bug['id'])->fetchColumn();
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($bug['name']) ?></strong>
                                    <?php if ($bug['description']): ?>
                                        <br><small class="text-muted"><?= substr(strip_tags($bug['description']), 0, 50) ?>...</small>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($bug['task_name']) ?></td>
                                <td><?= htmlspecialchars($bug['project_name']) ?></td>
                                <td>
                                    <span class="badge bg-<?= 
                                        $bug['priority'] == 'critical' ? 'danger' : 
                                        ($bug['priority'] == 'high' ? 'warning' : 
                                        ($bug['priority'] == 'medium' ? 'info' : 'success')) 
                                    ?>">
                                        <?= ucfirst($bug['priority']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?= 
                                        $bug['status'] == 'open' ? 'danger' : 
                                        ($bug['status'] == 'in_progress' ? 'warning' : 
                                        ($bug['status'] == 'resolved' ? 'info' : 'success')) 
                                    ?>">
                                        <?= ucfirst(str_replace('_', ' ', $bug['status'])) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($bug['created_by_name']) ?></td>
                                <td><?= $bug['start_datetime'] ? date('M j, Y', strtotime($bug['start_datetime'])) : '-' ?></td>
                                <td><?= $bug['end_datetime'] ? date('M j, Y', strtotime($bug['end_datetime'])) : '-' ?></td>
                                <td>
                                    <?php if ($attachments_count > 0): ?>
                                        <span class="badge bg-info">
                                            <i class="fas fa-paperclip"></i> <?= $attachments_count ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <button class="btn btn-sm btn-outline-warning update-bug-status" 
                                                data-bug-id="<?= $bug['id'] ?>" 
                                                data-current-status="<?= $bug['status'] ?>">
                                            <i class="fas fa-sync"></i> Status
                                        </button>
                                        <a href="bug_details.php?id=<?= $bug['id'] ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <?php if ($_SESSION['user_role'] == 'manager' || $_SESSION['user_role'] == 'qa'): ?>
                                        <a href="?edit_bug=<?= $bug['id'] ?>" class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#editBugModal">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Bug Modal -->
    <div class="modal fade" id="createBugModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Report New Bug</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="createBugForm" onsubmit="return validateBugForm()">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Bug Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="name" id="bug_name" required maxlength="255" value="<?= isset($_POST['name']) ? htmlspecialchars($_POST['name']) : '' ?>">
                                <div class="invalid-feedback">Please enter a bug name.</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Project <span class="text-danger">*</span></label>
                                <select class="form-select" name="project_id" id="projectSelect" required onchange="updateTaskDropdown()">
                                    <option value="">Select Project</option>
                                    <?php foreach ($projects as $project): ?>
                                        <option value="<?= $project['id'] ?>" <?= (isset($_POST['project_id']) && $_POST['project_id'] == $project['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($project['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">Please select a project.</div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Task <span class="text-danger">*</span></label>
                                <select class="form-select" name="task_id" id="taskSelect" required>
                                    <option value="">Select Task</option>
                                    <?php foreach ($form_tasks as $task): ?>
                                        <option value="<?= $task['id'] ?>" <?= (isset($_POST['task_id']) && $_POST['task_id'] == $task['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($task['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">Please select a task.</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Priority <span class="text-danger">*</span></label>
                                <select class="form-select" name="priority" id="bug_priority" required>
                                    <option value="low" <?= (isset($_POST['priority']) && $_POST['priority'] == 'low') ? 'selected' : '' ?>>Low</option>
                                    <option value="medium" <?= (!isset($_POST['priority']) || $_POST['priority'] == 'medium') ? 'selected' : '' ?>>Medium</option>
                                    <option value="high" <?= (isset($_POST['priority']) && $_POST['priority'] == 'high') ? 'selected' : '' ?>>High</option>
                                    <option value="critical" <?= (isset($_POST['priority']) && $_POST['priority'] == 'critical') ? 'selected' : '' ?>>Critical</option>
                                </select>
                                <div class="invalid-feedback">Please select a priority.</div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description <span class="text-danger">*</span></label>
                            <textarea class="form-control wysiwyg" name="description" id="bug_description" required><?= isset($_POST['description']) ? htmlspecialchars($_POST['description']) : '' ?></textarea>
                            <div class="invalid-feedback">Please enter a description.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Attachments</label>
                            <input type="file" class="form-control" name="attachments[]" id="bug_attachments" multiple 
                                   accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt,.zip" 
                                   onchange="validateFiles(this)">
                            <small class="text-muted">You can select multiple files. Maximum 10MB per file.</small>
                            <div class="invalid-feedback" id="fileError"></div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status <span class="text-danger">*</span></label>
                                <select class="form-select" name="status" id="bug_status" required>
                                    <option value="open" <?= (!isset($_POST['status']) || $_POST['status'] == 'open') ? 'selected' : '' ?>>Open</option>
                                    <option value="in_progress" <?= (isset($_POST['status']) && $_POST['status'] == 'in_progress') ? 'selected' : '' ?>>In Progress</option>
                                    <option value="resolved" <?= (isset($_POST['status']) && $_POST['status'] == 'resolved') ? 'selected' : '' ?>>Resolved</option>
                                    <option value="closed" <?= (isset($_POST['status']) && $_POST['status'] == 'closed') ? 'selected' : '' ?>>Closed</option>
                                </select>
                                <div class="invalid-feedback">Please select a status.</div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Start Date & Time</label>
                                <input type="datetime-local" class="form-control" name="start_datetime" id="start_datetime" value="<?= isset($_POST['start_datetime']) ? htmlspecialchars($_POST['start_datetime']) : '' ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">End Date & Time</label>
                                <input type="datetime-local" class="form-control" name="end_datetime" id="end_datetime" value="<?= isset($_POST['end_datetime']) ? htmlspecialchars($_POST['end_datetime']) : '' ?>">
                                <div class="invalid-feedback" id="dateError"></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="create_bug" class="btn btn-primary">Report Bug</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Bug Modal -->
    <div class="modal fade" id="editBugModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Bug</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <?php if ($edit_bug): ?>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="bug_id" value="<?= $edit_bug['id'] ?>">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Bug Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="name" required maxlength="255" 
                                       value="<?= htmlspecialchars($edit_bug['name']) ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Project <span class="text-danger">*</span></label>
                                <select class="form-select" name="project_id" id="editProjectSelect" required onchange="updateEditTaskDropdown()">
                                    <option value="">Select Project</option>
                                    <?php foreach ($projects as $project): ?>
                                        <option value="<?= $project['id'] ?>" <?= $edit_bug['project_id'] == $project['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($project['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Task <span class="text-danger">*</span></label>
                                <select class="form-select" name="task_id" id="editTaskSelect" required>
                                    <option value="">Select Task</option>
                                    <?php foreach ($form_tasks as $task): ?>
                                        <option value="<?= $task['id'] ?>" <?= $edit_bug['task_id'] == $task['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($task['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Priority <span class="text-danger">*</span></label>
                                <select class="form-select" name="priority" required>
                                    <option value="low" <?= $edit_bug['priority'] == 'low' ? 'selected' : '' ?>>Low</option>
                                    <option value="medium" <?= $edit_bug['priority'] == 'medium' ? 'selected' : '' ?>>Medium</option>
                                    <option value="high" <?= $edit_bug['priority'] == 'high' ? 'selected' : '' ?>>High</option>
                                    <option value="critical" <?= $edit_bug['priority'] == 'critical' ? 'selected' : '' ?>>Critical</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description <span class="text-danger">*</span></label>
                            <textarea class="form-control wysiwyg" name="description" required><?= htmlspecialchars($edit_bug['description']) ?></textarea>
                        </div>

                        <!-- Existing Attachments -->
                        <?php if (!empty($bug_attachments)): ?>
                        <div class="mb-3">
                            <label class="form-label">Existing Attachments</label>
                            <div class="attachments-container">
                                <?php foreach ($bug_attachments as $attachment): ?>
                                <div class="attachment-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <i class="fas fa-paperclip me-2"></i>
                                        <a href="<?= $attachment['file_path'] ?>" target="_blank" class="text-decoration-none">
                                            <?= htmlspecialchars($attachment['original_name']) ?>
                                        </a>
                                        <small class="text-muted ms-2">(<?= round($attachment['file_size'] / 1024, 2) ?> KB)</small>
                                    </div>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="attachment_id" value="<?= $attachment['id'] ?>">
                                        <input type="hidden" name="bug_id" value="<?= $edit_bug['id'] ?>">
                                        <button type="submit" name="delete_attachment" class="btn btn-sm btn-danger delete-attachment">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label">Add New Attachments</label>
                            <input type="file" class="form-control" name="new_attachments[]" multiple 
                                   accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt,.zip">
                            <small class="text-muted">You can select multiple files. Maximum 10MB per file.</small>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status <span class="text-danger">*</span></label>
                                <select class="form-select" name="status" required>
                                    <option value="open" <?= $edit_bug['status'] == 'open' ? 'selected' : '' ?>>Open</option>
                                    <option value="in_progress" <?= $edit_bug['status'] == 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                                    <option value="resolved" <?= $edit_bug['status'] == 'resolved' ? 'selected' : '' ?>>Resolved</option>
                                    <option value="closed" <?= $edit_bug['status'] == 'closed' ? 'selected' : '' ?>>Closed</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Start Date & Time</label>
                                <input type="datetime-local" class="form-control" name="start_datetime" 
                                       value="<?= htmlspecialchars($edit_bug['start_datetime']) ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">End Date & Time</label>
                                <input type="datetime-local" class="form-control" name="end_datetime" 
                                       value="<?= htmlspecialchars($edit_bug['end_datetime']) ?>">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="update_bug" class="btn btn-primary">Update Bug</button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Update Bug Status Modal -->
    <div class="modal fade" id="updateBugStatusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Bug Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="bug_id" id="update_bug_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="update_bug_status" required>
                                <option value="open">Open</option>
                                <option value="in_progress">In Progress</option>
                                <option value="resolved">Resolved</option>
                                <option value="closed">Closed</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="update_bug_status" class="btn btn-primary">Update Status</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const updateButtons = document.querySelectorAll('.update-bug-status');
            const updateModal = new bootstrap.Modal(document.getElementById('updateBugStatusModal'));
            
            updateButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const bugId = this.dataset.bugId;
                    const currentStatus = this.dataset.currentStatus;
                    
                    document.getElementById('update_bug_id').value = bugId;
                    document.getElementById('update_bug_status').value = currentStatus;
                    
                    updateModal.show();
                });
            });
            
            // If project is already selected when modal opens, populate tasks
            const selectedProjectId = document.getElementById('projectSelect').value;
            if (selectedProjectId) {
                updateTaskDropdown();
            }
            
            // Auto-open edit modal if edit_bug parameter exists
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('edit_bug')) {
                const editModal = new bootstrap.Modal(document.getElementById('editBugModal'));
                editModal.show();
                
                // Remove edit_bug parameter from URL without reloading
                const newUrl = window.location.pathname + window.location.search.replace(/&?edit_bug=[^&]*/g, '');
                window.history.replaceState({}, document.title, newUrl);
            }
        });
        
        function updateTaskDropdown() {
            const projectId = document.getElementById('projectSelect').value;
            const taskSelect = document.getElementById('taskSelect');
            
            if (projectId) {
                // Fetch tasks for the selected project via AJAX
                fetch(`get_tasks.php?project_id=${projectId}`)
                    .then(response => response.json())
                    .then(data => {
                        taskSelect.innerHTML = '<option value="">Select Task</option>';
                        data.forEach(task => {
                            const option = document.createElement('option');
                            option.value = task.id;
                            option.textContent = task.name;
                            taskSelect.appendChild(option);
                        });
                    })
                    .catch(error => {
                        console.error('Error fetching tasks:', error);
                    });
            } else {
                // Reset to all tasks
                taskSelect.innerHTML = '<option value="">Select Task</option>';
                <?php foreach ($form_tasks as $task): ?>
                    const option = document.createElement('option');
                    option.value = '<?= $task['id'] ?>';
                    option.textContent = '<?= addslashes($task['name']) ?>';
                    taskSelect.appendChild(option);
                <?php endforeach; ?>
            }
        }
        
        function updateEditTaskDropdown() {
            const projectId = document.getElementById('editProjectSelect').value;
            const taskSelect = document.getElementById('editTaskSelect');
            
            if (projectId) {
                // Fetch tasks for the selected project via AJAX
                fetch(`get_tasks.php?project_id=${projectId}`)
                    .then(response => response.json())
                    .then(data => {
                        taskSelect.innerHTML = '<option value="">Select Task</option>';
                        data.forEach(task => {
                            const option = document.createElement('option');
                            option.value = task.id;
                            option.textContent = task.name;
                            taskSelect.appendChild(option);
                        });
                    })
                    .catch(error => {
                        console.error('Error fetching tasks:', error);
                    });
            } else {
                // Reset to all tasks
                taskSelect.innerHTML = '<option value="">Select Task</option>';
                <?php foreach ($form_tasks as $task): ?>
                    const option = document.createElement('option');
                    option.value = '<?= $task['id'] ?>';
                    option.textContent = '<?= addslashes($task['name']) ?>';
                    taskSelect.appendChild(option);
                <?php endforeach; ?>
            }
        }
        
        function validateFiles(input) {
            const maxSize = 10 * 1024 * 1024; // 10MB
            const allowedTypes = [
                'image/jpeg', 'image/png', 'image/gif',
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'text/plain',
                'application/zip'
            ];
            
            const fileError = document.getElementById('fileError');
            let isValid = true;
            
            for (let i = 0; i < input.files.length; i++) {
                const file = input.files[i];
                
                if (file.size > maxSize) {
                    fileError.textContent = `File "${file.name}" exceeds maximum size of 10MB.`;
                    input.classList.add('is-invalid');
                    isValid = false;
                    break;
                }
                
                if (!allowedTypes.includes(file.type)) {
                    fileError.textContent = `File "${file.name}" has an invalid file type.`;
                    input.classList.add('is-invalid');
                    isValid = false;
                    break;
                }
            }
            
            if (isValid) {
                input.classList.remove('is-invalid');
                fileError.textContent = '';
            }
            
            return isValid;
        }
        
        function validateBugForm() {
            const form = document.getElementById('createBugForm');
            const startDate = document.getElementById('start_datetime').value;
            const endDate = document.getElementById('end_datetime').value;
            const dateError = document.getElementById('dateError');
            
            // Validate dates
            if (startDate && endDate) {
                const start = new Date(startDate);
                const end = new Date(endDate);
                
                if (end < start) {
                    dateError.textContent = "End date cannot be earlier than start date.";
                    document.getElementById('end_datetime').classList.add('is-invalid');
                    return false;
                } else {
                    document.getElementById('end_datetime').classList.remove('is-invalid');
                    dateError.textContent = '';
                }
            }
            
            // Validate files
            const fileInput = document.getElementById('bug_attachments');
            if (fileInput.files.length > 0) {
                if (!validateFiles(fileInput)) {
                    return false;
                }
            }
            
            return true;
        }
        
        // Auto-open modal if there was a form error
        <?php if (isset($error) && isset($_POST['create_bug'])): ?>
            document.addEventListener('DOMContentLoaded', function() {
                var createBugModal = new bootstrap.Modal(document.getElementById('createBugModal'));
                createBugModal.show();
            });
        <?php endif; ?>
    </script>
</body>
</html>