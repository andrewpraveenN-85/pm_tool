<?php
include 'config/database.php';
include 'includes/auth.php';
include 'includes/notifications.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireAuth();

// Initialize notification service
$notification = new Notification($db);

// Handle form submissions
if ($_POST) {
    if (isset($_POST['create_task'])) {
        // Server-side validation
        $errors = [];
        
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $project_id = $_POST['project_id'] ?? '';
        $priority = $_POST['priority'] ?? '';
        $start_datetime = $_POST['start_datetime'] ?? '';
        $end_datetime = $_POST['end_datetime'] ?? '';
        $assignees = $_POST['assignees'] ?? [];
        
        // Validate required fields
        if (empty($name)) {
            $errors[] = "Task name is required.";
        } elseif (strlen($name) > 255) {
            $errors[] = "Task name must be less than 255 characters.";
        }
        
        if (empty($project_id)) {
            $errors[] = "Project selection is required.";
        }
        
        if (empty($priority)) {
            $errors[] = "Priority selection is required.";
        }
        
        // Validate date range
        if (!empty($start_datetime) && !empty($end_datetime)) {
            $start_timestamp = strtotime($start_datetime);
            $end_timestamp = strtotime($end_datetime);
            
            if ($end_timestamp < $start_timestamp) {
                $errors[] = "End date/time cannot be before start date/time.";
            }
        }
        
        // Validate file uploads
        if (!empty($_FILES['attachments']['name'][0])) {
            $max_file_size = 10 * 1024 * 1024; // 10MB
            $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt', 'zip'];
            
            foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                    // Check file size
                    if ($_FILES['attachments']['size'][$key] > $max_file_size) {
                        $errors[] = "File '" . $_FILES['attachments']['name'][$key] . "' exceeds 10MB limit.";
                        continue;
                    }
                    
                    // Check file type
                    $original_name = $_FILES['attachments']['name'][$key];
                    $file_extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
                    if (!in_array($file_extension, $allowed_types)) {
                        $errors[] = "File '" . $original_name . "' has an invalid file type. Allowed types: " . implode(', ', $allowed_types);
                    }
                }
            }
        }
        
        // If no validation errors, proceed with task creation
        if (empty($errors)) {
            try {
                $db->beginTransaction();
                
                // Insert task
                $query = "INSERT INTO tasks (name, description, project_id, priority, start_datetime, end_datetime, created_by, status) 
                          VALUES (:name, :description, :project_id, :priority, :start_datetime, :end_datetime, :created_by, 'todo')";
                
                $stmt = $db->prepare($query);
                $stmt->bindParam(':name', $name);
                $stmt->bindParam(':description', $description);
                $stmt->bindParam(':project_id', $project_id);
                $stmt->bindParam(':priority', $priority);
                $stmt->bindParam(':start_datetime', $start_datetime);
                $stmt->bindParam(':end_datetime', $end_datetime);
                $stmt->bindParam(':created_by', $_SESSION['user_id']);
                $stmt->execute();
                
                $task_id = $db->lastInsertId();
                
                // Assign developers
                foreach ($assignees as $user_id) {
                    $assign_query = "INSERT INTO task_assignments (task_id, user_id) VALUES (:task_id, :user_id)";
                    $assign_stmt = $db->prepare($assign_query);
                    $assign_stmt->bindParam(':task_id', $task_id);
                    $assign_stmt->bindParam(':user_id', $user_id);
                    $assign_stmt->execute();
                }
                
                // Handle file uploads for the task
                if (!empty($_FILES['attachments']['name'][0])) {
                    $upload_dir = 'uploads/tasks/' . $task_id . '/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
                        if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                            $original_name = $_FILES['attachments']['name'][$key];
                            $file_extension = pathinfo($original_name, PATHINFO_EXTENSION);
                            $filename = 'task_' . $task_id . '_' . uniqid() . '.' . $file_extension;
                            $target_file = $upload_dir . $filename;
                            
                            if (move_uploaded_file($tmp_name, $target_file)) {
                                $query = "INSERT INTO attachments (entity_type, entity_id, filename, original_name, file_path, file_size, file_type, uploaded_by, uploaded_on) 
                                          VALUES ('task', :entity_id, :filename, :original_name, :file_path, :file_size, :file_type, :uploaded_by, NOW())";
                                $stmt = $db->prepare($query);
                                $stmt->bindParam(':entity_id', $task_id);
                                $stmt->bindParam(':filename', $filename);
                                $stmt->bindParam(':original_name', $original_name);
                                $stmt->bindParam(':file_path', $target_file);
                                $stmt->bindParam(':file_size', $_FILES['attachments']['size'][$key]);
                                $stmt->bindParam(':file_type', $_FILES['attachments']['type'][$key]);
                                $stmt->bindParam(':uploaded_by', $_SESSION['user_id']);
                                $stmt->execute();
                            }
                        }
                    }
                }
                
                $db->commit();
                
                // Send assignment notifications and emails
                if (!empty($assignees)) {
                    $notification->createTaskAssignmentNotification($task_id, $assignees);
                }
                
                $success = "Task created successfully!";
                // Clear form values after successful submission
                $_POST = [];
                $_FILES = [];
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Failed to create task: " . $e->getMessage();
            }
        } else {
            $error = implode("<br>", $errors);
        }
    }
    
    // Handle task update
    if (isset($_POST['update_task'])) {
        $task_id = $_POST['task_id'];
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $project_id = $_POST['project_id'] ?? '';
        $priority = $_POST['priority'] ?? '';
        $start_datetime = $_POST['start_datetime'] ?? '';
        $end_datetime = $_POST['end_datetime'] ?? '';
        $assignees = $_POST['assignees'] ?? [];
        
        // Validate required fields
        $errors = [];
        if (empty($name)) {
            $errors[] = "Task name is required.";
        }
        
        if (empty($project_id)) {
            $errors[] = "Project selection is required.";
        }
        
        if (empty($priority)) {
            $errors[] = "Priority selection is required.";
        }
        
        // Validate date range
        if (!empty($start_datetime) && !empty($end_datetime)) {
            $start_timestamp = strtotime($start_datetime);
            $end_timestamp = strtotime($end_datetime);
            
            if ($end_timestamp < $start_timestamp) {
                $errors[] = "End date/time cannot be before start date/time.";
            }
        }
        
        // Validate file uploads for new attachments
        if (!empty($_FILES['new_attachments']['name'][0])) {
            $max_file_size = 10 * 1024 * 1024; // 10MB
            $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt', 'zip'];
            
            foreach ($_FILES['new_attachments']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['new_attachments']['error'][$key] === UPLOAD_ERR_OK) {
                    // Check file size
                    if ($_FILES['new_attachments']['size'][$key] > $max_file_size) {
                        $errors[] = "File '" . $_FILES['new_attachments']['name'][$key] . "' exceeds 10MB limit.";
                        continue;
                    }
                    
                    // Check file type
                    $original_name = $_FILES['new_attachments']['name'][$key];
                    $file_extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
                    if (!in_array($file_extension, $allowed_types)) {
                        $errors[] = "File '" . $original_name . "' has an invalid file type. Allowed types: " . implode(', ', $allowed_types);
                    }
                }
            }
        }
        
        if (empty($errors)) {
            try {
                $db->beginTransaction();
                
                // Update task
                $query = "UPDATE tasks SET 
                          name = :name, 
                          description = :description, 
                          project_id = :project_id, 
                          priority = :priority, 
                          start_datetime = :start_datetime, 
                          end_datetime = :end_datetime,
                          updated_at = NOW()
                          WHERE id = :id";
                
                $stmt = $db->prepare($query);
                $stmt->bindParam(':name', $name);
                $stmt->bindParam(':description', $description);
                $stmt->bindParam(':project_id', $project_id);
                $stmt->bindParam(':priority', $priority);
                $stmt->bindParam(':start_datetime', $start_datetime);
                $stmt->bindParam(':end_datetime', $end_datetime);
                $stmt->bindParam(':id', $task_id);
                $stmt->execute();
                
                // Update assignees - remove existing and add new
                $delete_query = "DELETE FROM task_assignments WHERE task_id = :task_id";
                $delete_stmt = $db->prepare($delete_query);
                $delete_stmt->bindParam(':task_id', $task_id);
                $delete_stmt->execute();
                
                // Assign new developers
                foreach ($assignees as $user_id) {
                    $assign_query = "INSERT INTO task_assignments (task_id, user_id) VALUES (:task_id, :user_id)";
                    $assign_stmt = $db->prepare($assign_query);
                    $assign_stmt->bindParam(':task_id', $task_id);
                    $assign_stmt->bindParam(':user_id', $user_id);
                    $assign_stmt->execute();
                }
                
                // Handle new file uploads
                if (!empty($_FILES['new_attachments']['name'][0])) {
                    $upload_dir = 'uploads/tasks/' . $task_id . '/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    foreach ($_FILES['new_attachments']['tmp_name'] as $key => $tmp_name) {
                        if ($_FILES['new_attachments']['error'][$key] === UPLOAD_ERR_OK) {
                            $original_name = $_FILES['new_attachments']['name'][$key];
                            $file_extension = pathinfo($original_name, PATHINFO_EXTENSION);
                            $filename = 'task_' . $task_id . '_' . uniqid() . '.' . $file_extension;
                            $target_file = $upload_dir . $filename;
                            
                            if (move_uploaded_file($tmp_name, $target_file)) {
                                $query = "INSERT INTO attachments (entity_type, entity_id, filename, original_name, file_path, file_size, file_type, uploaded_by, uploaded_on) 
                                          VALUES ('task', :entity_id, :filename, :original_name, :file_path, :file_size, :file_type, :uploaded_by, NOW())";
                                $stmt = $db->prepare($query);
                                $stmt->bindParam(':entity_id', $task_id);
                                $stmt->bindParam(':filename', $filename);
                                $stmt->bindParam(':original_name', $original_name);
                                $stmt->bindParam(':file_path', $target_file);
                                $stmt->bindParam(':file_size', $_FILES['new_attachments']['size'][$key]);
                                $stmt->bindParam(':file_type', $_FILES['new_attachments']['type'][$key]);
                                $stmt->bindParam(':uploaded_by', $_SESSION['user_id']);
                                $stmt->execute();
                            }
                        }
                    }
                }
                
                $db->commit();
                
                // Send update notification to assignees
                if (!empty($assignees)) {
                    $notification->createTaskUpdateNotification($task_id, $assignees);
                }
                
                $success = "Task updated successfully!";
                
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Failed to update task: " . $e->getMessage();
            }
        } else {
            $error = implode("<br>", $errors);
        }
    }
    
    // Handle task status update
    if (isset($_POST['update_task_status'])) {
        $task_id = $_POST['task_id'];
        $status = $_POST['status'];
        
        try {
            $db->beginTransaction();
            
            // Get current task details
            $task_query = "SELECT created_by FROM tasks WHERE id = :task_id";
            $task_stmt = $db->prepare($task_query);
            $task_stmt->bindParam(':task_id', $task_id);
            $task_stmt->execute();
            $task = $task_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get assigned users
            $assignees_query = "SELECT user_id FROM task_assignments WHERE task_id = :task_id";
            $assignees_stmt = $db->prepare($assignees_query);
            $assignees_stmt->bindParam(':task_id', $task_id);
            $assignees_stmt->execute();
            $assignees = $assignees_stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Update task status
            $query = "UPDATE tasks SET status = :status, updated_at = NOW() WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':id', $task_id);
            
            if ($stmt->execute()) {
                $db->commit();
                
                // Send status update notifications
                $notification->createTaskStatusUpdateNotification($task_id, $status, $assignees, $task['created_by']);
                
                $success = "Task status updated successfully!";
            } else {
                throw new Exception("Failed to update task status");
            }
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Failed to update task status: " . $e->getMessage();
        }
    }
    
    // Handle attachment deletion
    if (isset($_POST['delete_attachment'])) {
        $attachment_id = $_POST['attachment_id'];
        $task_id = $_POST['task_id'];
        
        try {
            // Get attachment details
            $attachment_query = "SELECT file_path FROM attachments WHERE id = :id AND entity_type = 'task'";
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
                
                $success = "Attachment deleted successfully!";
            }
        } catch (Exception $e) {
            $error = "Failed to delete attachment: " . $e->getMessage();
        }
    }
}

// Handle filter submission
$filter_project = $_GET['filter_project'] ?? '';
$filter_assignee = $_GET['filter_assignee'] ?? '';
$filter_start_date = $_GET['filter_start_date'] ?? '';
$filter_end_date = $_GET['filter_end_date'] ?? '';

// Build filter conditions
$filter_conditions = [];
$filter_params = [];

if (!empty($filter_project)) {
    $filter_conditions[] = "t.project_id = :filter_project";
    $filter_params[':filter_project'] = $filter_project;
}

if (!empty($filter_assignee)) {
    $filter_conditions[] = "ta.user_id = :filter_assignee";
    $filter_params[':filter_assignee'] = $filter_assignee;
}

if (!empty($filter_start_date)) {
    $filter_conditions[] = "t.start_datetime >= :filter_start_date";
    $filter_params[':filter_start_date'] = $filter_start_date;
}

if (!empty($filter_end_date)) {
    $filter_conditions[] = "t.end_datetime <= :filter_end_date";
    $filter_params[':filter_end_date'] = $filter_end_date;
}

// Get tasks based on user role with filters
if ($_SESSION['user_role'] == 'manager') {
    $tasks_query = "
        SELECT t.*, p.name as project_name, 
               GROUP_CONCAT(DISTINCT u.name) as assignee_names,
               GROUP_CONCAT(DISTINCT u.id) as assignee_ids,
               COUNT(DISTINCT b.id) as bug_count,
               COUNT(DISTINCT a.id) as attachment_count
        FROM tasks t
        LEFT JOIN projects p ON t.project_id = p.id
        LEFT JOIN task_assignments ta ON t.id = ta.task_id
        LEFT JOIN users u ON ta.user_id = u.id
        LEFT JOIN bugs b ON t.id = b.task_id
        LEFT JOIN attachments a ON a.entity_type = 'task' AND a.entity_id = t.id
    ";
    
    if (!empty($filter_conditions)) {
        $tasks_query .= " WHERE " . implode(" AND ", $filter_conditions);
    }
    
    $tasks_query .= " GROUP BY t.id ORDER BY t.created_at DESC";
} else {
    // For developers, only show assigned tasks
    $filter_conditions[] = "ta.user_id = :user_id";
    $filter_params[':user_id'] = $_SESSION['user_id'];
    
    $tasks_query = "
        SELECT t.*, p.name as project_name, 
               GROUP_CONCAT(DISTINCT u.name) as assignee_names,
               GROUP_CONCAT(DISTINCT u.id) as assignee_ids,
               COUNT(DISTINCT b.id) as bug_count,
               COUNT(DISTINCT a.id) as attachment_count
        FROM tasks t
        LEFT JOIN projects p ON t.project_id = p.id
        LEFT JOIN task_assignments ta ON t.id = ta.task_id
        LEFT JOIN users u ON ta.user_id = u.id
        LEFT JOIN bugs b ON t.id = b.task_id
        LEFT JOIN attachments a ON a.entity_type = 'task' AND a.entity_id = t.id
    ";
    
    if (!empty($filter_conditions)) {
        $tasks_query .= " WHERE " . implode(" AND ", $filter_conditions);
    }
    
    $tasks_query .= " GROUP BY t.id ORDER BY t.created_at DESC";
}

$stmt = $db->prepare($tasks_query);
foreach ($filter_params as $key => $value) {
    $stmt->bindParam($key, $value);
}
$stmt->execute();
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get active projects for dropdown
$projects = $db->query("SELECT id, name FROM projects WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);

// Get developers for assignment
$developers = $db->query("SELECT id, name FROM users WHERE role = 'developer' AND status = 'active'")->fetchAll(PDO::FETCH_ASSOC);

// Define status to color mapping for better maintainability
$statusColors = [
    'closed' => 'success',
    'in_progress' => 'primary',
    'todo' => 'warning',
    'reopened' => 'info',
    'await_release' => 'info',
    'in_review' => 'info',
    'default' => 'secondary'
];

// Get task details for edit if task_id is provided
$edit_task = null;
$task_attachments = [];
if (isset($_GET['edit_task'])) {
    $task_id = $_GET['edit_task'];
    
    $task_query = "SELECT t.*, p.name as project_name FROM tasks t 
                   LEFT JOIN projects p ON t.project_id = p.id 
                   WHERE t.id = :id";
    $task_stmt = $db->prepare($task_query);
    $task_stmt->bindParam(':id', $task_id);
    $task_stmt->execute();
    $edit_task = $task_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($edit_task) {
        // Get assigned developers
        $assignees_query = "SELECT user_id FROM task_assignments WHERE task_id = :task_id";
        $assignees_stmt = $db->prepare($assignees_query);
        $assignees_stmt->bindParam(':task_id', $task_id);
        $assignees_stmt->execute();
        $edit_task['assignees'] = $assignees_stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Get attachments - FIXED: Use uploaded_on instead of uploaded_at
        $attachments_query = "SELECT * FROM attachments WHERE entity_type = 'task' AND entity_id = :task_id ORDER BY created_at DESC";
        $attachments_stmt = $db->prepare($attachments_query);
        $attachments_stmt->bindParam(':task_id', $task_id);
        $attachments_stmt->execute();
        $task_attachments = $attachments_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tasks - Task Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/tinymce@6.8.2/tinymce.min.js"></script>
    <style>
        .filter-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .form-label.required:after {
            content: " *";
            color: #dc3545;
        }
        .filter-btn-group .btn {
            margin-right: 5px;
            margin-bottom: 5px;
        }
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
        .task-table tr {
            cursor: pointer;
        }
        .task-table tr:hover {
            background-color: #f5f5f5;
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
        
        // Client-side form validation for create task
        const createTaskForm = document.querySelector('form[method="POST"]');
        if (createTaskForm && createTaskForm.querySelector('button[name="create_task"]')) {
            createTaskForm.addEventListener('submit', function(e) {
                let isValid = true;
                const errorMessages = [];
                
                // Task name validation
                const taskName = this.querySelector('input[name="name"]');
                if (!taskName.value.trim()) {
                    isValid = false;
                    errorMessages.push("Task name is required.");
                    taskName.classList.add('is-invalid');
                } else if (taskName.value.trim().length > 255) {
                    isValid = false;
                    errorMessages.push("Task name must be less than 255 characters.");
                    taskName.classList.add('is-invalid');
                } else {
                    taskName.classList.remove('is-invalid');
                }
                
                // Project validation
                const projectSelect = this.querySelector('select[name="project_id"]');
                if (!projectSelect.value) {
                    isValid = false;
                    errorMessages.push("Project selection is required.");
                    projectSelect.classList.add('is-invalid');
                } else {
                    projectSelect.classList.remove('is-invalid');
                }
                
                // Priority validation
                const prioritySelect = this.querySelector('select[name="priority"]');
                if (!prioritySelect.value) {
                    isValid = false;
                    errorMessages.push("Priority selection is required.");
                    prioritySelect.classList.add('is-invalid');
                } else {
                    prioritySelect.classList.remove('is-invalid');
                }
                
                // Date validation
                const startDate = this.querySelector('input[name="start_datetime"]');
                const endDate = this.querySelector('input[name="end_datetime"]');
                
                if (startDate.value && endDate.value) {
                    const start = new Date(startDate.value);
                    const end = new Date(endDate.value);
                    
                    if (end < start) {
                        isValid = false;
                        errorMessages.push("End date/time cannot be before start date/time.");
                        startDate.classList.add('is-invalid');
                        endDate.classList.add('is-invalid');
                    } else {
                        startDate.classList.remove('is-invalid');
                        endDate.classList.remove('is-invalid');
                    }
                }
                
                // File validation
                const fileInput = this.querySelector('input[name="attachments[]"]');
                if (fileInput && fileInput.files.length > 0) {
                    const maxSize = 10 * 1024 * 1024; // 10MB
                    const allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt', 'zip'];
                    
                    for (let i = 0; i < fileInput.files.length; i++) {
                        const file = fileInput.files[i];
                        
                        // Check file size
                        if (file.size > maxSize) {
                            isValid = false;
                            errorMessages.push(`File "${file.name}" exceeds 10MB limit.`);
                            fileInput.classList.add('is-invalid');
                            break;
                        }
                        
                        // Check file extension
                        const fileName = file.name.toLowerCase();
                        const fileExt = fileName.substring(fileName.lastIndexOf('.') + 1);
                        if (!allowedTypes.includes(fileExt)) {
                            isValid = false;
                            errorMessages.push(`File "${file.name}" has an invalid file type. Allowed types: ${allowedTypes.join(', ')}`);
                            fileInput.classList.add('is-invalid');
                            break;
                        }
                        
                        fileInput.classList.remove('is-invalid');
                    }
                }
                
                if (!isValid) {
                    e.preventDefault();
                    
                    // Show error alert
                    let alertDiv = document.querySelector('.validation-error-alert');
                    if (!alertDiv) {
                        alertDiv = document.createElement('div');
                        alertDiv.className = 'alert alert-danger validation-error-alert mt-3';
                        this.prepend(alertDiv);
                    }
                    
                    alertDiv.innerHTML = '<strong>Please fix the following errors:</strong><br>' + 
                                        errorMessages.map(msg => `• ${msg}`).join('<br>');
                    
                    // Scroll to errors
                    alertDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });
        }
        
        // Filter form handling
        const filterForm = document.getElementById('filterForm');
        if (filterForm) {
            // Clear filters button
            const clearFiltersBtn = document.getElementById('clearFilters');
            if (clearFiltersBtn) {
                clearFiltersBtn.addEventListener('click', function() {
                    filterForm.reset();
                    filterForm.submit();
                });
            }
        }
        
        // Date range validation for filters
        const filterStartDate = document.getElementById('filter_start_date');
        const filterEndDate = document.getElementById('filter_end_date');
        
        if (filterStartDate && filterEndDate) {
            function validateFilterDates() {
                if (filterStartDate.value && filterEndDate.value) {
                    const start = new Date(filterStartDate.value);
                    const end = new Date(filterEndDate.value);
                    
                    if (end < start) {
                        alert('Filter end date cannot be before start date.');
                        filterEndDate.value = '';
                    }
                }
            }
            
            filterStartDate.addEventListener('change', validateFilterDates);
            filterEndDate.addEventListener('change', validateFilterDates);
        }
        
        // Handle attachment deletion
        const deleteAttachmentButtons = document.querySelectorAll('.delete-attachment');
        deleteAttachmentButtons.forEach(button => {
            button.addEventListener('click', function(e) {
                if (!confirm('Are you sure you want to delete this attachment?')) {
                    e.preventDefault();
                }
            });
        });
        
        // Auto-open edit modal if edit_task parameter exists
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('edit_task')) {
            // Wait for modal to be fully loaded
            setTimeout(function() {
                const editModalElement = document.getElementById('editTaskModal');
                if (editModalElement) {
                    const editModal = new bootstrap.Modal(editModalElement);
                    editModal.show();
                }
            }, 100);
        }
    });
    </script>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>
                        <?= $_SESSION['user_role'] == 'manager' ? 'All Tasks' : 'My Tasks' ?>
                    </h2>
                    <?php if ($_SESSION['user_role'] == 'manager'): ?>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTaskModal">
                        <i class="fas fa-plus"></i> Create Task
                    </button>
                    <?php endif; ?>
                </div>

                <?php if (isset($success)): ?>
                    <div class="alert alert-success"><?= $success ?></div>
                <?php endif; ?>
                
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?= $error ?></div>
                <?php endif; ?>

                <!-- Filters Card -->
                <div class="filter-card">
                    <h5><i class="fas fa-filter"></i> Filter Tasks</h5>
                    <form method="GET" id="filterForm">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Project</label>
                                <select class="form-select" name="filter_project" id="filter_project">
                                    <option value="">All Projects</option>
                                    <?php foreach ($projects as $project): ?>
                                        <option value="<?= $project['id'] ?>" <?= $filter_project == $project['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($project['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <?php if ($_SESSION['user_role'] == 'manager'): ?>
                            <div class="col-md-3">
                                <label class="form-label">Assigned Developer</label>
                                <select class="form-select" name="filter_assignee" id="filter_assignee">
                                    <option value="">All Developers</option>
                                    <?php foreach ($developers as $dev): ?>
                                        <option value="<?= $dev['id'] ?>" <?= $filter_assignee == $dev['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($dev['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            
                            <div class="col-md-3">
                                <label class="form-label">Start Date From</label>
                                <input type="date" class="form-control" name="filter_start_date" id="filter_start_date" 
                                       value="<?= htmlspecialchars($filter_start_date) ?>">
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">End Date To</label>
                                <input type="date" class="form-control" name="filter_end_date" id="filter_end_date" 
                                       value="<?= htmlspecialchars($filter_end_date) ?>">
                            </div>
                        </div>
                        
                        <div class="mt-3 filter-btn-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Apply Filters
                            </button>
                            <button type="button" class="btn btn-secondary" id="clearFilters">
                                <i class="fas fa-times"></i> Clear Filters
                            </button>
                            
                            <?php if (!empty($filter_project) || !empty($filter_assignee) || !empty($filter_start_date) || !empty($filter_end_date)): ?>
                            <span class="badge bg-info ms-2">
                                <i class="fas fa-info-circle"></i> 
                                <?php
                                $activeFilters = [];
                                if (!empty($filter_project)) {
                                    foreach ($projects as $p) {
                                        if ($p['id'] == $filter_project) {
                                            $activeFilters[] = "Project: " . $p['name'];
                                            break;
                                        }
                                    }
                                }
                                if (!empty($filter_assignee) && $_SESSION['user_role'] == 'manager') {
                                    foreach ($developers as $d) {
                                        if ($d['id'] == $filter_assignee) {
                                            $activeFilters[] = "Assignee: " . $d['name'];
                                            break;
                                        }
                                    }
                                }
                                if (!empty($filter_start_date)) {
                                    $activeFilters[] = "From: " . date('M j, Y', strtotime($filter_start_date));
                                }
                                if (!empty($filter_end_date)) {
                                    $activeFilters[] = "To: " . date('M j, Y', strtotime($filter_end_date));
                                }
                                echo count($tasks) . " tasks found with filters: " . implode(', ', $activeFilters);
                                ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <div class="table-responsive">
                    <table class="table table-striped table-hover task-table">
                        <thead class="table-dark">
                            <tr>
                                <th>Task Name</th>
                                <th>Project</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Assignees</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Bugs</th>
                                <th>Attachments</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($tasks)): ?>
                            <tr>
                                <td colspan="10" class="text-center py-4">
                                    <div class="text-muted">
                                        <i class="fas fa-inbox fa-2x mb-3"></i>
                                        <p>No tasks found<?= (!empty($filter_project) || !empty($filter_assignee) || !empty($filter_start_date) || !empty($filter_end_date)) ? ' with the current filters' : '' ?>.</p>
                                        <?php if (!empty($filter_project) || !empty($filter_assignee) || !empty($filter_start_date) || !empty($filter_end_date)): ?>
                                            <a href="tasks.php" class="btn btn-sm btn-outline-primary">
                                                Clear filters to see all tasks
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($tasks as $task): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($task['name']) ?></strong>
                                    <?php if ($task['description']): ?>
                                        <br><small class="text-muted"><?= substr(strip_tags($task['description']), 0, 50) ?>...</small>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($task['project_name']) ?></td>
                                <td>
                                    <span class="badge bg-<?= 
                                        $task['priority'] == 'critical' ? 'danger' : 
                                        ($task['priority'] == 'high' ? 'warning' : 
                                        ($task['priority'] == 'medium' ? 'info' : 'success')) 
                                    ?>">
                                        <?= ucfirst($task['priority']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $statusColors[$task['status']] ?? $statusColors['default'] ?>">
                                        <?= ucfirst(str_replace('_', ' ', $task['status'])) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($task['assignee_names']) ?></td>
                                <td><?= $task['start_datetime'] ? date('M j, Y', strtotime($task['start_datetime'])) : '-' ?></td>
                                <td>
                                    <?php if ($task['end_datetime']): ?>
                                        <?php 
                                        $end_date = strtotime($task['end_datetime']);
                                        $now = time();
                                        $updated_at = strtotime($task['updated_at']);
                                        $status = $task['status'];
                                        
                                        $class = 'text-muted';
                                        $message = '';
                                        
                                        if ($status == 'closed') {
                                            // For closed tasks, check if it was closed after the due date
                                            if ($updated_at > $end_date) {
                                                $class = 'text-danger';
                                                $days_overdue = floor(($updated_at - $end_date) / (60 * 60 * 24));
                                                $message = " (Closed " . $days_overdue . " days late)";
                                            }
                                        } else {
                                            // For non-closed tasks, check if current date is past due date
                                            if ($now > $end_date) {
                                                $class = 'text-danger';
                                                $days_overdue = floor(($now - $end_date) / (60 * 60 * 24));
                                                $message = " (Overdue by " . $days_overdue . " days)";
                                            } elseif (($end_date - $now) <= (2 * 24 * 60 * 60)) {
                                                $class = 'text-warning';
                                                $days_remaining = floor(($end_date - $now) / (60 * 60 * 24));
                                                $message = " (Due in " . $days_remaining . " days)";
                                            }
                                        }
                                        ?>
                                        <span class="<?= $class ?>">
                                            <?= date('M j, Y', $end_date) ?>
                                            <?= $message ?>
                                        </span>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($task['bug_count'] > 0): ?>
                                        <span class="badge bg-danger"><?= $task['bug_count'] ?> bugs</span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($task['attachment_count'] > 0): ?>
                                        <span class="badge bg-info">
                                            <i class="fas fa-paperclip"></i> <?= $task['attachment_count'] ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <?php if ($_SESSION['user_role'] == 'manager' || in_array($_SESSION['user_id'], explode(',', $task['assignee_ids'] ?? ''))): ?>
                                        <button class="btn btn-sm btn-outline-warning update-task-status" 
                                                data-task-id="<?= $task['id'] ?>" 
                                                data-current-status="<?= $task['status'] ?>">
                                            <i class="fas fa-sync"></i> Status
                                        </button>
                                        <?php endif; ?>
                                        <a href="task_details.php?id=<?= $task['id'] ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <?php if ($_SESSION['user_role'] == 'manager'): ?>
                                        <a href="tasks.php?edit_task=<?= $task['id'] ?>" class="btn btn-sm btn-outline-info">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php if ($_SESSION['user_role'] == 'manager'): ?>
    <!-- Create Task Modal -->
    <div class="modal fade" id="createTaskModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Task</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="createTaskForm">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label required">Task Name</label>
                                <input type="text" class="form-control" name="name" required
                                       value="<?= isset($_POST['name']) ? htmlspecialchars($_POST['name']) : '' ?>"
                                       maxlength="255">
                                <div class="invalid-feedback">
                                    Please enter a task name (max 255 characters).
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label required">Project</label>
                                <select class="form-select" name="project_id" required>
                                    <option value="">Select Project</option>
                                    <?php foreach ($projects as $project): ?>
                                        <option value="<?= $project['id'] ?>" 
                                            <?= (isset($_POST['project_id']) && $_POST['project_id'] == $project['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($project['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">
                                    Please select a project.
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control wysiwyg" name="description"><?= isset($_POST['description']) ? htmlspecialchars($_POST['description']) : '' ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Attachments</label>
                            <input type="file" class="form-control" name="attachments[]" multiple 
                                   accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt,.zip">
                            <small class="text-muted">You can select multiple files. Maximum 10MB per file. Allowed types: JPG, PNG, GIF, PDF, DOC, DOCX, TXT, ZIP</small>
                            <div class="invalid-feedback">
                                Please check file size and type restrictions.
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label required">Priority</label>
                                <select class="form-select" name="priority" required>
                                    <option value="low" <?= (isset($_POST['priority']) && $_POST['priority'] == 'low') ? 'selected' : '' ?>>Low</option>
                                    <option value="medium" <?= (isset($_POST['priority']) && $_POST['priority'] == 'medium') || !isset($_POST['priority']) ? 'selected' : '' ?>>Medium</option>
                                    <option value="high" <?= (isset($_POST['priority']) && $_POST['priority'] == 'high') ? 'selected' : '' ?>>High</option>
                                    <option value="critical" <?= (isset($_POST['priority']) && $_POST['priority'] == 'critical') ? 'selected' : '' ?>>Critical</option>
                                </select>
                                <div class="invalid-feedback">
                                    Please select a priority.
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Assign Developers</label>
                                <select class="form-select" name="assignees[]" multiple>
                                    <?php foreach ($developers as $dev): ?>
                                        <option value="<?= $dev['id'] ?>"
                                            <?= (isset($_POST['assignees']) && in_array($dev['id'], $_POST['assignees'])) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($dev['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Hold Ctrl to select multiple developers</small>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label required">Start Date & Time</label>
                                <input type="datetime-local" class="form-control" name="start_datetime" required
                                       value="<?= isset($_POST['start_datetime']) ? htmlspecialchars($_POST['start_datetime']) : '' ?>">
                                <div class="invalid-feedback">
                                    Start date cannot be after end date.
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label required">End Date & Time</label>
                                <input type="datetime-local" class="form-control" name="end_datetime" required
                                       value="<?= isset($_POST['end_datetime']) ? htmlspecialchars($_POST['end_datetime']) : '' ?>">
                                <div class="invalid-feedback">
                                    End date cannot be before start date.
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="create_task" class="btn btn-primary">Create Task</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Task Modal - Always in DOM but only populated when needed -->
    <div class="modal fade" id="editTaskModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Task</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <?php if ($edit_task): ?>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="task_id" value="<?= $edit_task['id'] ?>">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label required">Task Name</label>
                                <input type="text" class="form-control" name="name" required
                                       value="<?= htmlspecialchars($edit_task['name']) ?>"
                                       maxlength="255">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label required">Project</label>
                                <select class="form-select" name="project_id" required>
                                    <option value="">Select Project</option>
                                    <?php foreach ($projects as $project): ?>
                                        <option value="<?= $project['id'] ?>" 
                                            <?= $edit_task['project_id'] == $project['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($project['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control wysiwyg" name="description"><?= htmlspecialchars($edit_task['description']) ?></textarea>
                        </div>

                        <!-- Existing Attachments -->
                        <?php if (!empty($task_attachments)): ?>
                        <div class="mb-3">
                            <label class="form-label">Existing Attachments</label>
                            <div class="attachments-container">
                                <?php foreach ($task_attachments as $attachment): ?>
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
                                        <input type="hidden" name="task_id" value="<?= $edit_task['id'] ?>">
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
                            <small class="text-muted">You can select multiple files. Maximum 10MB per file. Allowed types: JPG, PNG, GIF, PDF, DOC, DOCX, TXT, ZIP</small>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label required">Priority</label>
                                <select class="form-select" name="priority" required>
                                    <option value="low" <?= $edit_task['priority'] == 'low' ? 'selected' : '' ?>>Low</option>
                                    <option value="medium" <?= $edit_task['priority'] == 'medium' ? 'selected' : '' ?>>Medium</option>
                                    <option value="high" <?= $edit_task['priority'] == 'high' ? 'selected' : '' ?>>High</option>
                                    <option value="critical" <?= $edit_task['priority'] == 'critical' ? 'selected' : '' ?>>Critical</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Assign Developers</label>
                                <select class="form-select" name="assignees[]" multiple>
                                    <?php foreach ($developers as $dev): ?>
                                        <option value="<?= $dev['id'] ?>"
                                            <?= in_array($dev['id'], $edit_task['assignees'] ?? []) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($dev['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Hold Ctrl to select multiple developers</small>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label required">Start Date & Time</label>
                                <input type="datetime-local" class="form-control" name="start_datetime" required
                                       value="<?= htmlspecialchars($edit_task['start_datetime']) ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label required">End Date & Time</label>
                                <input type="datetime-local" class="form-control" name="end_datetime" required
                                       value="<?= htmlspecialchars($edit_task['end_datetime']) ?>">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="update_task" class="btn btn-primary">Update Task</button>
                    </div>
                </form>
                <?php else: ?>
                <div class="modal-body text-center py-5">
                    <div class="spinner-border text-primary mb-3" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p>Loading task data...</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Update Task Status Modal -->
    <div class="modal fade" id="updateTaskStatusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Task Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="task_id" id="update_task_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="update_task_status" required>
                                <option value="todo">To Do</option>
                                <option value="reopened">Re Opened</option>
                                <option value="in_progress">In Progress</option>
                                <option value="await_release">Await Release</option>
                                <option value="in_review">In Review</option>
                                <option value="closed">Closed</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="update_task_status" class="btn btn-primary">Update Status</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const updateButtons = document.querySelectorAll('.update-task-status');
            const updateModal = new bootstrap.Modal(document.getElementById('updateTaskStatusModal'));
            
            updateButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const taskId = this.dataset.taskId;
                    const currentStatus = this.dataset.currentStatus;
                    
                    document.getElementById('update_task_id').value = taskId;
                    document.getElementById('update_task_status').value = currentStatus;
                    
                    updateModal.show();
                });
            });
            
            // Clear validation errors when modal is closed
            const createTaskModal = document.getElementById('createTaskModal');
            if (createTaskModal) {
                createTaskModal.addEventListener('hidden.bs.modal', function() {
                    const invalidElements = this.querySelectorAll('.is-invalid');
                    invalidElements.forEach(el => el.classList.remove('is-invalid'));
                    
                    const errorAlert = this.querySelector('.validation-error-alert');
                    if (errorAlert) {
                        errorAlert.remove();
                    }
                });
            }
            
            // Edit modal handling
            const editModalElement = document.getElementById('editTaskModal');
            if (editModalElement) {
                editModalElement.addEventListener('hidden.bs.modal', function() {
                    // Clear the edit_task parameter from URL when modal is closed
                    const urlParams = new URLSearchParams(window.location.search);
                    if (urlParams.has('edit_task')) {
                        urlParams.delete('edit_task');
                        const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
                        window.history.replaceState({}, document.title, newUrl);
                    }
                });
            }
        });
    </script>
</body>
</html>