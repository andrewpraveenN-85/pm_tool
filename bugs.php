<?php
include 'config/database.php';
include 'includes/auth.php';
include 'includes/notifications.php';
include 'includes/activity_logger.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireRole(['manager', 'qa']);

// Initialize services
$notification = new Notification($db);
$activityLogger = new ActivityLogger($db);
$current_user_id = $_SESSION['user_id'] ?? null;

// Debug logging
if ($_POST) {
    error_log("POST data received: " . print_r($_POST, true));
    error_log("FILES data: " . print_r($_FILES, true));
}

// Handle form submissions
if ($_POST) {
    if (isset($_POST['create_bug'])) {
        $errors = [];

        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $project_id = $_POST['project_id'] ?? '';
        $task_id = $_POST['task_id'] ?? '';
        $priority = $_POST['priority'] ?? '';
        $status = $_POST['status'] ?? '';
        $start_datetime = $_POST['start_datetime'] ?? '';
        $end_datetime = $_POST['end_datetime'] ?? '';

        // Validate required fields
        if (empty($name)) {
            $errors[] = "Bug name is required.";
        } elseif (strlen($name) > 255) {
            $errors[] = "Bug name must be less than 255 characters.";
        }

        if (empty($project_id)) {
            $errors[] = "Project selection is required.";
        }

        if (empty($task_id)) {
            $errors[] = "Task selection is required.";
        }

        if (empty($priority)) {
            $errors[] = "Priority selection is required.";
        }

        if (empty($status)) {
            $errors[] = "Status selection is required.";
        }

        // Validate dates
        if (!empty($start_datetime) && !empty($end_datetime)) {
            $start_timestamp = strtotime($start_datetime);
            $end_timestamp = strtotime($end_datetime);

            if ($end_timestamp < $start_timestamp) {
                $errors[] = "End date/time cannot be before start date/time.";
            }
        }

        // Validate file uploads
        if (!empty($_FILES['attachments']['name'][0])) {
            $max_file_size = 10 * 1024 * 1024;
            $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt', 'zip'];

            foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                    if ($_FILES['attachments']['size'][$key] > $max_file_size) {
                        $errors[] = "File '" . $_FILES['attachments']['name'][$key] . "' exceeds 10MB limit.";
                        continue;
                    }

                    $original_name = $_FILES['attachments']['name'][$key];
                    $file_extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
                    if (!in_array($file_extension, $allowed_types)) {
                        $errors[] = "File '" . $original_name . "' has an invalid file type.";
                    }
                }
            }
        }

        if (empty($errors)) {
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

                // Handle file uploads
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
                                $query = "INSERT INTO attachments (entity_type, entity_id, filename, original_name, file_path, file_size, file_type, uploaded_by, uploaded_on) 
                                          VALUES ('bug', :entity_id, :filename, :original_name, :file_path, :file_size, :file_type, :uploaded_by, NOW())";
                                $stmt = $db->prepare($query);
                                $stmt->bindParam(':entity_id', $bug_id);
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

                // Log activity
                $activityLogger->logActivity(
                    $current_user_id,
                    'create',
                    'bug',
                    $bug_id,
                    json_encode([
                        'bug_name' => $name,
                        'task_id' => $task_id,
                        'priority' => $priority,
                        'status' => $status,
                        'attachments_count' => count($_FILES['attachments']['name'] ?? []),
                        'created_by' => $current_user_id,
                        'ip_address' => $_SERVER['REMOTE_ADDR']
                    ])
                );

                // Send notification
                $notification->createBugReportNotification($bug_id);

                $success = "Bug reported successfully!";
                $_POST = [];
                $_FILES = [];
                
                error_log("Bug created successfully with ID: " . $bug_id);
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Failed to report bug: " . $e->getMessage();
                
                $activityLogger->logActivity(
                    $current_user_id,
                    'bug_creation_failed',
                    'bug',
                    null,
                    json_encode([
                        'bug_name' => $name,
                        'task_id' => $task_id,
                        'error' => $e->getMessage(),
                        'created_by' => $current_user_id,
                        'ip_address' => $_SERVER['REMOTE_ADDR']
                    ])
                );
                
                error_log("Bug creation failed: " . $e->getMessage());
            }
        } else {
            $activityLogger->logActivity(
                $current_user_id,
                'bug_validation_failed',
                'bug',
                null,
                json_encode([
                    'errors' => $errors,
                    'form_data' => [
                        'name' => $name,
                        'project_id' => $project_id,
                        'task_id' => $task_id,
                        'priority' => $priority,
                        'status' => $status
                    ],
                    'created_by' => $current_user_id,
                    'ip_address' => $_SERVER['REMOTE_ADDR']
                ])
            );

            $error = implode("<br>", $errors);
            error_log("Form validation errors: " . $error);
        }
    }

    if (isset($_POST['update_bug'])) {
        $bug_id = $_POST['bug_id'];
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $project_id = $_POST['project_id'] ?? '';
        $task_id = $_POST['task_id'] ?? '';
        $priority = $_POST['priority'] ?? '';
        $status = $_POST['status'] ?? '';
        $start_datetime = $_POST['start_datetime'] ?? '';
        $end_datetime = $_POST['end_datetime'] ?? '';

        // Get old bug data
        $old_bug_query = $db->prepare("SELECT * FROM bugs WHERE id = ?");
        $old_bug_query->execute([$bug_id]);
        $old_bug = $old_bug_query->fetch(PDO::FETCH_ASSOC);

        $errors = [];
        if (empty($name)) {
            $errors[] = "Bug name is required.";
        }

        if (empty($project_id)) {
            $errors[] = "Project selection is required.";
        }

        if (empty($task_id)) {
            $errors[] = "Task selection is required.";
        }

        if (empty($priority)) {
            $errors[] = "Priority selection is required.";
        }

        if (empty($status)) {
            $errors[] = "Status selection is required.";
        }

        if (!empty($start_datetime) && !empty($end_datetime)) {
            $start_timestamp = strtotime($start_datetime);
            $end_timestamp = strtotime($end_datetime);

            if ($end_timestamp < $start_timestamp) {
                $errors[] = "End date/time cannot be before start date/time.";
            }
        }

        if (empty($errors)) {
            try {
                $db->beginTransaction();

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
                                $query = "INSERT INTO attachments (entity_type, entity_id, filename, original_name, file_path, file_size, file_type, uploaded_by, uploaded_on) 
                                          VALUES ('bug', :entity_id, :filename, :original_name, :file_path, :file_size, :file_type, :uploaded_by, NOW())";
                                $stmt = $db->prepare($query);
                                $stmt->bindParam(':entity_id', $bug_id);
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

                // Log update
                $activityLogger->logActivity(
                    $current_user_id,
                    'update',
                    'bug',
                    $bug_id,
                    json_encode([
                        'bug_name' => $name,
                        'old_data' => [
                            'name' => $old_bug['name'],
                            'task_id' => $old_bug['task_id'],
                            'priority' => $old_bug['priority'],
                            'status' => $old_bug['status'],
                            'start_datetime' => $old_bug['start_datetime'],
                            'end_datetime' => $old_bug['end_datetime']
                        ],
                        'new_data' => [
                            'name' => $name,
                            'task_id' => $task_id,
                            'priority' => $priority,
                            'status' => $status,
                            'start_datetime' => $start_datetime,
                            'end_datetime' => $end_datetime
                        ],
                        'updated_by' => $current_user_id,
                        'ip_address' => $_SERVER['REMOTE_ADDR']
                    ])
                );

                // Send update notification
                $notification->createBugUpdateNotification($bug_id);

                $success = "Bug updated successfully!";
                header("Location: bugs.php");
                exit;
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Failed to update bug: " . $e->getMessage();
                
                $activityLogger->logActivity(
                    $current_user_id,
                    'bug_update_failed',
                    'bug',
                    $bug_id,
                    json_encode([
                        'bug_name' => $name,
                        'error' => $e->getMessage(),
                        'updated_by' => $current_user_id,
                        'ip_address' => $_SERVER['REMOTE_ADDR']
                    ])
                );
            }
        } else {
            $error = implode("<br>", $errors);
        }
    }

    if (isset($_POST['update_bug_status'])) {
        $bug_id = $_POST['bug_id'];
        $status = $_POST['status'];

        try {
            $db->beginTransaction();

            // Get bug details
            $bug_query = "SELECT b.*, t.created_by as task_manager_id, 
                           GROUP_CONCAT(DISTINCT ta.user_id) as assignee_ids
                    FROM bugs b
                    LEFT JOIN tasks t ON b.task_id = t.id
                    LEFT JOIN task_assignments ta ON t.id = ta.task_id
                    WHERE b.id = :bug_id
                    GROUP BY b.id";
            $bug_stmt = $db->prepare($bug_query);
            $bug_stmt->bindParam(':bug_id', $bug_id);
            $bug_stmt->execute();
            $bug = $bug_stmt->fetch(PDO::FETCH_ASSOC);

            // Update bug status
            $query = "UPDATE bugs SET status = :status, updated_at = NOW() WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':id', $bug_id);
            $stmt->execute();

            $db->commit();

            // Log status update
            $activityLogger->logActivity(
                $current_user_id,
                'update_status',
                'bug',
                $bug_id,
                json_encode([
                    'bug_name' => $bug['name'],
                    'old_status' => $bug['status'],
                    'new_status' => $status,
                    'updated_by' => $current_user_id,
                    'user_role' => $_SESSION['user_role'],
                    'ip_address' => $_SERVER['REMOTE_ADDR']
                ])
            );

            // Send notifications
            $assignee_ids = !empty($bug['assignee_ids']) ? explode(',', $bug['assignee_ids']) : [];
            $notification->createBugStatusUpdateNotification($bug_id, $status, $assignee_ids, $bug['task_manager_id']);

            $success = "Bug status updated successfully!";
            header("Location: bugs.php");
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Failed to update bug status: " . $e->getMessage();
            
            $activityLogger->logActivity(
                $current_user_id,
                'status_update_failed',
                'bug',
                $bug_id,
                json_encode([
                    'bug_id' => $bug_id,
                    'status' => $status,
                    'error' => $e->getMessage(),
                    'updated_by' => $current_user_id,
                    'ip_address' => $_SERVER['REMOTE_ADDR']
                ])
            );
        }
    }

    if (isset($_POST['delete_attachment'])) {
        $attachment_id = $_POST['attachment_id'];
        $bug_id = $_POST['bug_id'];

        try {
            // Get attachment details
            $attachment_query = "SELECT * FROM attachments WHERE id = :id AND entity_type = 'bug'";
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

                // Log attachment deletion
                $activityLogger->logActivity(
                    $current_user_id,
                    'delete_attachment',
                    'bug',
                    $bug_id,
                    json_encode([
                        'attachment_id' => $attachment_id,
                        'file_name' => $attachment['original_name'],
                        'file_size' => $attachment['file_size'],
                        'deleted_by' => $current_user_id,
                        'ip_address' => $_SERVER['REMOTE_ADDR']
                    ])
                );

                $success = "Attachment deleted successfully!";
                header("Location: bugs.php?edit_bug=" . $bug_id);
                exit;
            }
        } catch (Exception $e) {
            $error = "Failed to delete attachment: " . $e->getMessage();
        }
    }
}

// Handle filter submission
$filter_project = $_GET['filter_project'] ?? '';
$filter_task = $_GET['filter_task'] ?? '';
$filter_start_date = $_GET['filter_start_date'] ?? '';
$filter_end_date = $_GET['filter_end_date'] ?? '';

// Check if any filter is active
$isFiltered = !empty($filter_project) || !empty($filter_task) ||
    !empty($filter_start_date) || !empty($filter_end_date);

// Build filter conditions
$filter_conditions = [];
$filter_params = [];

if (!empty($filter_project)) {
    $filter_conditions[] = "t.project_id = :filter_project";
    $filter_params[':filter_project'] = $filter_project;
}

if (!empty($filter_task)) {
    $filter_conditions[] = "b.task_id = :filter_task";
    $filter_params[':filter_task'] = $filter_task;
}

if (!empty($filter_start_date)) {
    $filter_conditions[] = "b.start_datetime >= :filter_start_date";
    $filter_params[':filter_start_date'] = $filter_start_date;
}

if (!empty($filter_end_date)) {
    $filter_conditions[] = "b.end_datetime <= :filter_end_date";
    $filter_params[':filter_end_date'] = $filter_end_date;
}

// Build the query
$bugs_query = "
    SELECT b.*, t.name as task_name, p.name as project_name, 
           u.name as created_by_name, p.id as project_id,
           COUNT(DISTINCT a.id) as attachment_count
    FROM bugs b
    LEFT JOIN tasks t ON b.task_id = t.id
    LEFT JOIN projects p ON t.project_id = p.id
    LEFT JOIN users u ON b.created_by = u.id
    LEFT JOIN attachments a ON a.entity_type = 'bug' AND a.entity_id = b.id
";

if (!empty($filter_conditions)) {
    $bugs_query .= " WHERE " . implode(" AND ", $filter_conditions);
}

$bugs_query .= " GROUP BY b.id ORDER BY b.created_at DESC";

$stmt = $db->prepare($bugs_query);
foreach ($filter_params as $key => $value) {
    $stmt->bindParam($key, $value);
}
$stmt->execute();
$bugs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get projects for dropdowns
$projects = $db->query("SELECT id, name FROM projects WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);

// Get tasks for dropdown (all active tasks initially)
$all_tasks = $db->query("
    SELECT t.id, t.name, p.name as project_name, p.id as project_id 
    FROM tasks t 
    LEFT JOIN projects p ON t.project_id = p.id 
    WHERE t.status != 'closed' AND t.status != 'completed' AND p.status = 'active'
    ORDER BY p.name, t.name
")->fetchAll(PDO::FETCH_ASSOC);

// Get bug details for edit if bug_id is provided
$edit_bug = null;
$bug_attachments = [];
if (isset($_GET['edit_bug']) && !isset($_POST['update_bug'])) {
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
        $attachments_query = "SELECT * FROM attachments WHERE entity_type = 'bug' AND entity_id = :bug_id ORDER BY uploaded_on DESC";
        $attachments_stmt = $db->prepare($attachments_query);
        $attachments_stmt->bindParam(':bug_id', $bug_id);
        $attachments_stmt->execute();
        $bug_attachments = $attachments_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Define status to color mapping
$statusColors = [
    'closed' => 'success',
    'in_progress' => 'primary',
    'open' => 'warning',
    'resolved' => 'info',
    'default' => 'secondary'
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bugs - Task Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.bootstrap5.min.css" rel="stylesheet">
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

        .attachment-item {
            padding: 8px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            margin-bottom: 8px;
            background: #f8f9fa;
        }

        .activity-log {
            max-height: 400px;
            overflow-y: auto;
        }

        .activity-item {
            border-left: 4px solid;
            margin-bottom: 8px;
            padding: 8px;
            background-color: #f8f9fa;
            font-size: 0.9rem;
        }

        .activity-create {
            border-color: #28a745;
        }

        .activity-update {
            border-color: #007bff;
        }

        .activity-update_status {
            border-color: #6f42c1;
        }

        .activity-delete_attachment {
            border-color: #dc3545;
        }

        .activity-failed {
            border-color: #fd7e14;
        }

        .activity-item small {
            font-size: 0.8rem;
            color: #6c757d;
        }

        /* DataTables custom styling */
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter,
        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_processing,
        .dataTables_wrapper .dataTables_paginate {
            color: #333;
        }

        .dataTables_wrapper .dataTables_filter input {
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 4px 8px;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button {
            padding: 4px 10px;
            margin: 0 2px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: #007bff;
            color: white !important;
            border-color: #007bff;
        }

        /* Filter note styling */
        .filter-note {
            border-left: 4px solid #17a2b8;
            margin-top: 15px;
        }

        /* Table wrapper styling */
        .table-wrapper {
            position: relative;
        }

        .table-wrapper.loading::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .table-wrapper.loading::before {
            content: 'Loading...';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1001;
            color: #007bff;
            font-weight: bold;
            font-size: 1.2rem;
        }

        /* Enhanced table styling for filtered mode */
        .table-enhanced {
            width: 100% !important;
            border-collapse: collapse;
        }

        .table-enhanced th {
            background-color: #343a40;
            color: white;
            font-weight: 600;
            padding: 12px;
        }

        .table-enhanced td {
            padding: 12px;
            vertical-align: middle;
        }

        .table-enhanced tbody tr:nth-child(even) {
            background-color: #f8f9fa;
        }

        .table-enhanced tbody tr:hover {
            background-color: #e9ecef;
        }

        /* Status indicator */
        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 5px;
        }

        .status-active {
            background-color: #28a745;
        }

        .status-pending {
            background-color: #ffc107;
        }

        .status-inactive {
            background-color: #6c757d;
        }

        /* Badge enhancements */
        .badge-pill {
            border-radius: 10rem;
            padding: 0.4em 0.8em;
            font-size: 0.85em;
        }

        /* Priority colors */
        .priority-critical {
            background-color: #dc3545;
        }

        .priority-high {
            background-color: #fd7e14;
        }

        .priority-medium {
            background-color: #17a2b8;
        }

        .priority-low {
            background-color: #28a745;
        }
        
        /* Loading spinner */
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #007bff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .position-relative .loading-spinner {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
        }
    </style>
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

                <!-- Filters Card -->
                <div class="filter-card">
                    <h5><i class="fas fa-filter"></i> Filter Bugs</h5>
                    <form method="GET" id="filterForm">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Project</label>
                                <select class="form-select" name="filter_project" id="filterProject">
                                    <option value="">All Projects</option>
                                    <?php foreach ($projects as $project): ?>
                                        <option value="<?= $project['id'] ?>" <?= $filter_project == $project['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($project['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Task</label>
                                <select class="form-select" name="filter_task" id="filterTask">
                                    <option value="">All Tasks</option>
                                    <?php foreach ($all_tasks as $task): ?>
                                        <option value="<?= $task['id'] ?>" <?= $filter_task == $task['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($task['name']) ?> (<?= htmlspecialchars($task['project_name']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Start Date From</label>
                                <input type="date" class="form-control" name="filter_start_date" id="filterStartDate"
                                    value="<?= htmlspecialchars($filter_start_date) ?>">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">End Date To</label>
                                <input type="date" class="form-control" name="filter_end_date" id="filterEndDate"
                                    value="<?= htmlspecialchars($filter_end_date) ?>">
                            </div>
                        </div>

                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Apply Filters
                            </button>
                            <button type="button" class="btn btn-secondary" id="clearFilters">
                                <i class="fas fa-times"></i> Clear Filters
                            </button>
                        </div>
                    </form>

                    <?php if ($isFiltered): ?>
                        <div class="alert alert-info filter-note mt-3">
                            <i class="fas fa-info-circle"></i>
                            <strong>Filter Mode Active:</strong> Showing filtered results.
                            <a href="bugs.php" class="alert-link">Clear filters</a> to enable full DataTable features.
                        </div>
                    <?php endif; ?>
                </div>

                <div class="table-wrapper <?= $isFiltered ? 'loading' : '' ?>">
                    <table id="bugsTable" class="table table-striped table-hover w-100 table-enhanced">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
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
                            <?php if (empty($bugs)): ?>
                                <tr>
                                    <td colspan="11" class="text-center py-4">
                                        <div class="text-muted">
                                            <i class="fas fa-inbox fa-2x mb-3"></i>
                                            <p>No bugs found.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($bugs as $bug): ?>
                                    <tr>
                                        <td><?= $bug['id'] ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($bug['name']) ?></strong>
                                            <?php if ($bug['description']): ?>
                                                <br><small class="text-muted"><?= substr(strip_tags($bug['description']), 0, 50) ?>...</small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($bug['task_name']) ?></td>
                                        <td><?= htmlspecialchars($bug['project_name']) ?></td>
                                        <td>
                                            <span class="badge badge-pill priority-<?= $bug['priority'] ?>">
                                                <?= ucfirst($bug['priority']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $statusColors[$bug['status']] ?? $statusColors['default'] ?> badge-pill">
                                                <?= ucfirst(str_replace('_', ' ', $bug['status'])) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($bug['created_by_name']) ?></td>
                                        <td><?= $bug['start_datetime'] ? date('M j, Y', strtotime($bug['start_datetime'])) : '-' ?></td>
                                        <td>
                                            <?php if ($bug['end_datetime']): ?>
                                                <?php
                                                $end_date = strtotime($bug['end_datetime']);
                                                $now = time();
                                                $status = $bug['status'];

                                                $class = 'text-muted';
                                                $message = '';

                                                if ($status == 'closed') {
                                                    // Check if closed after due date
                                                } else {
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
                                            <?php if ($bug['attachment_count'] > 0): ?>
                                                <span class="badge bg-info badge-pill">
                                                    <i class="fas fa-paperclip"></i> <?= $bug['attachment_count'] ?>
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
                                                <a href="bugs.php?edit_bug=<?= $bug['id'] ?>" class="btn btn-sm btn-outline-info">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
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

    <!-- Create Bug Modal -->
    <div class="modal fade" id="createBugModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Report New Bug</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="createBugForm">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label required">Bug Name</label>
                                <input type="text" class="form-control" name="name" id="bugName" required
                                    value="<?= isset($_POST['name']) ? htmlspecialchars($_POST['name']) : '' ?>"
                                    maxlength="255">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label required">Project</label>
                                <select class="form-select" name="project_id" id="projectSelect" required>
                                    <option value="">Select Project</option>
                                    <?php foreach ($projects as $project): ?>
                                        <option value="<?= $project['id'] ?>"
                                            <?= (isset($_POST['project_id']) && $_POST['project_id'] == $project['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($project['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label required">Task</label>
                                <div class="position-relative">
                                    <select class="form-select" name="task_id" id="taskSelect" required>
                                        <option value="">Select Task</option>
                                        <?php foreach ($all_tasks as $task): ?>
                                            <option value="<?= $task['id'] ?>"
                                                <?= (isset($_POST['task_id']) && $_POST['task_id'] == $task['id']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($task['name']) ?> (<?= htmlspecialchars($task['project_name']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div id="taskLoading" class="position-absolute" style="display: none;">
                                        <div class="loading-spinner"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label required">Priority</label>
                                <select class="form-select" name="priority" id="bugPriority" required>
                                    <option value="low" <?= (isset($_POST['priority']) && $_POST['priority'] == 'low') ? 'selected' : '' ?>>Low</option>
                                    <option value="medium" <?= (!isset($_POST['priority']) || $_POST['priority'] == 'medium') ? 'selected' : '' ?>>Medium</option>
                                    <option value="high" <?= (isset($_POST['priority']) && $_POST['priority'] == 'high') ? 'selected' : '' ?>>High</option>
                                    <option value="critical" <?= (isset($_POST['priority']) && $_POST['priority'] == 'critical') ? 'selected' : '' ?>>Critical</option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label required">Description</label>
                            <textarea class="form-control wysiwyg" name="description" id="bugDescription" required><?= isset($_POST['description']) ? htmlspecialchars($_POST['description']) : '' ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Attachments</label>
                            <input type="file" class="form-control" name="attachments[]" id="bugAttachments" multiple
                                accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt,.zip">
                            <small class="text-muted">Max 10MB per file. Allowed types: JPG, PNG, GIF, PDF, DOC, DOCX, TXT, ZIP</small>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label required">Status</label>
                                <select class="form-select" name="status" id="bugStatus" required>
                                    <option value="open" <?= (!isset($_POST['status']) || $_POST['status'] == 'open') ? 'selected' : '' ?>>Open</option>
                                    <option value="in_progress" <?= (isset($_POST['status']) && $_POST['status'] == 'in_progress') ? 'selected' : '' ?>>In Progress</option>
                                    <option value="resolved" <?= (isset($_POST['status']) && $_POST['status'] == 'resolved') ? 'selected' : '' ?>>Resolved</option>
                                    <option value="closed" <?= (isset($_POST['status']) && $_POST['status'] == 'closed') ? 'selected' : '' ?>>Closed</option>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Start Date & Time</label>
                                <input type="datetime-local" class="form-control" name="start_datetime" id="startDatetime"
                                    value="<?= isset($_POST['start_datetime']) ? htmlspecialchars($_POST['start_datetime']) : '' ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">End Date & Time</label>
                                <input type="datetime-local" class="form-control" name="end_datetime" id="endDatetime"
                                    value="<?= isset($_POST['end_datetime']) ? htmlspecialchars($_POST['end_datetime']) : '' ?>">
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
                    <form method="POST" enctype="multipart/form-data" id="editBugForm">
                        <input type="hidden" name="bug_id" value="<?= $edit_bug['id'] ?>">
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label required">Bug Name</label>
                                    <input type="text" class="form-control" name="name" id="editBugName" required
                                        value="<?= htmlspecialchars($edit_bug['name']) ?>"
                                        maxlength="255">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label required">Project</label>
                                    <select class="form-select" name="project_id" id="editProjectSelect" required>
                                        <option value="">Select Project</option>
                                        <?php foreach ($projects as $project): ?>
                                            <option value="<?= $project['id'] ?>"
                                                <?= $edit_bug['project_id'] == $project['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($project['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label required">Task</label>
                                    <div class="position-relative">
                                        <select class="form-select" name="task_id" id="editTaskSelect" required>
                                            <option value="">Select Task</option>
                                            <?php 
                                            $edit_tasks = $db->prepare("
                                                SELECT t.id, t.name 
                                                FROM tasks t 
                                                WHERE t.project_id = ? 
                                                AND t.status != 'closed' 
                                                AND t.status != 'completed'
                                                ORDER BY t.name
                                            ");
                                            $edit_tasks->execute([$edit_bug['project_id']]);
                                            $edit_task_list = $edit_tasks->fetchAll(PDO::FETCH_ASSOC);
                                            foreach ($edit_task_list as $task): ?>
                                                <option value="<?= $task['id'] ?>" <?= $edit_bug['task_id'] == $task['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($task['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div id="editTaskLoading" class="position-absolute" style="display: none;">
                                            <div class="loading-spinner"></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label required">Priority</label>
                                    <select class="form-select" name="priority" id="editBugPriority" required>
                                        <option value="low" <?= $edit_bug['priority'] == 'low' ? 'selected' : '' ?>>Low</option>
                                        <option value="medium" <?= $edit_bug['priority'] == 'medium' ? 'selected' : '' ?>>Medium</option>
                                        <option value="high" <?= $edit_bug['priority'] == 'high' ? 'selected' : '' ?>>High</option>
                                        <option value="critical" <?= $edit_bug['priority'] == 'critical' ? 'selected' : '' ?>>Critical</option>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label required">Description</label>
                                <textarea class="form-control wysiwyg" name="description" id="editBugDescription" required><?= htmlspecialchars($edit_bug['description']) ?></textarea>
                            </div>

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
                                <input type="file" class="form-control" name="new_attachments[]" id="editBugAttachments" multiple
                                    accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt,.zip">
                                <small class="text-muted">Max 10MB per file. Allowed types: JPG, PNG, GIF, PDF, DOC, DOCX, TXT, ZIP</small>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label required">Status</label>
                                    <select class="form-select" name="status" id="editBugStatus" required>
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
                                    <input type="datetime-local" class="form-control" name="start_datetime" id="editStartDatetime"
                                        value="<?= $edit_bug['start_datetime'] ? date('Y-m-d\TH:i', strtotime($edit_bug['start_datetime'])) : '' ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">End Date & Time</label>
                                    <input type="datetime-local" class="form-control" name="end_datetime" id="editEndDatetime"
                                        value="<?= $edit_bug['end_datetime'] ? date('Y-m-d\TH:i', strtotime($edit_bug['end_datetime'])) : '' ?>">
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="submit" name="update_bug" class="btn btn-primary">Update Bug</button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="modal-body text-center py-5">
                        <div class="spinner-border text-primary mb-3" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p>Loading bug data...</p>
                    </div>
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
                <form method="POST" id="updateBugStatusForm">
                    <input type="hidden" name="bug_id" id="updateBugId">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="updateBugStatus" required>
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

    <!-- jQuery (required for DataTables) -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.4.1/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.4.1/js/responsive.bootstrap5.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize TinyMCE
            tinymce.init({
                selector: 'textarea.wysiwyg',
                plugins: 'anchor autolink charmap codesample emoticons image link lists media searchreplace table visualblocks wordcount',
                toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | link image media table | align lineheight | numlist bullist indent outdent | emoticons charmap | removeformat',
                menubar: false,
                height: 300,
                promotion: false,
                branding: false
            });

            // Setup event listeners
            setupEventListeners();

            // Auto-open edit modal only if we have edit_bug parameter and no POST submission
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('edit_bug') && !<?= isset($_POST['update_bug']) ? 'true' : 'false' ?>) {
                setTimeout(function() {
                    const editModalElement = document.getElementById('editBugModal');
                    if (editModalElement) {
                        const editModal = new bootstrap.Modal(editModalElement);
                        editModal.show();

                        // Close modal when user clicks outside
                        editModalElement.addEventListener('hidden.bs.modal', function() {
                            const url = new URL(window.location);
                            url.searchParams.delete('edit_bug');
                            window.history.replaceState({}, '', url);
                        });
                    }
                }, 100);
            }

            // Initialize DataTables based on filter state
            initializeDataTables();

            // Remove loading class after page load
            setTimeout(function() {
                document.querySelector('.table-wrapper').classList.remove('loading');
            }, 300);
        });

        function setupEventListeners() {
            // Form validation for create bug
            const createBugForm = document.getElementById('createBugForm');
            if (createBugForm) {
                createBugForm.addEventListener('submit', function(e) {
                    let isValid = true;
                    const errorMessages = [];

                    const bugName = document.getElementById('bugName');
                    if (!bugName.value.trim()) {
                        isValid = false;
                        errorMessages.push("Bug name is required.");
                        bugName.classList.add('is-invalid');
                    } else if (bugName.value.trim().length > 255) {
                        isValid = false;
                        errorMessages.push("Bug name must be less than 255 characters.");
                        bugName.classList.add('is-invalid');
                    } else {
                        bugName.classList.remove('is-invalid');
                    }

                    const projectSelect = document.getElementById('projectSelect');
                    if (!projectSelect.value) {
                        isValid = false;
                        errorMessages.push("Project selection is required.");
                        projectSelect.classList.add('is-invalid');
                    } else {
                        projectSelect.classList.remove('is-invalid');
                    }

                    const taskSelect = document.getElementById('taskSelect');
                    if (!taskSelect.value) {
                        isValid = false;
                        errorMessages.push("Task selection is required.");
                        taskSelect.classList.add('is-invalid');
                    } else {
                        taskSelect.classList.remove('is-invalid');
                    }

                    const prioritySelect = document.getElementById('bugPriority');
                    if (!prioritySelect.value) {
                        isValid = false;
                        errorMessages.push("Priority selection is required.");
                        prioritySelect.classList.add('is-invalid');
                    } else {
                        prioritySelect.classList.remove('is-invalid');
                    }

                    const statusSelect = document.getElementById('bugStatus');
                    if (!statusSelect.value) {
                        isValid = false;
                        errorMessages.push("Status selection is required.");
                        statusSelect.classList.add('is-invalid');
                    } else {
                        statusSelect.classList.remove('is-invalid');
                    }

                    const startDate = document.getElementById('startDatetime');
                    const endDate = document.getElementById('endDatetime');

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

                    if (!isValid) {
                        e.preventDefault();

                        let alertDiv = createBugForm.querySelector('.validation-error-alert');
                        if (!alertDiv) {
                            alertDiv = document.createElement('div');
                            alertDiv.className = 'alert alert-danger validation-error-alert mt-3';
                            createBugForm.prepend(alertDiv);
                        }

                        alertDiv.innerHTML = '<strong>Please fix the following errors:</strong><br>' +
                            errorMessages.map(msg => `• ${msg}`).join('<br>');

                        alertDiv.scrollIntoView({
                            behavior: 'smooth',
                            block: 'center'
                        });
                    }
                });
            }

            // Filter form handling
            const filterForm = document.getElementById('filterForm');
            if (filterForm) {
                const clearFiltersBtn = document.getElementById('clearFilters');
                if (clearFiltersBtn) {
                    clearFiltersBtn.addEventListener('click', function() {
                        filterForm.reset();
                        filterForm.submit();
                    });
                }

                // Show loading when submitting filters
                filterForm.addEventListener('submit', function() {
                    document.querySelector('.table-wrapper').classList.add('loading');
                });

                // Update task filter when project filter changes (using DOM event)
                const filterProject = document.getElementById('filterProject');
                if (filterProject) {
                    filterProject.addEventListener('change', updateFilterTaskOptions);
                }
            }

            // Create Bug Modal - Project change event
            const projectSelect = document.getElementById('projectSelect');
            if (projectSelect) {
                projectSelect.addEventListener('change', handleCreateProjectChange);
            }

            // Edit Bug Modal - Project change event
            const editProjectSelect = document.getElementById('editProjectSelect');
            if (editProjectSelect) {
                editProjectSelect.addEventListener('change', handleEditProjectChange);
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

            // Update bug status buttons
            const updateButtons = document.querySelectorAll('.update-bug-status');
            const updateModal = new bootstrap.Modal(document.getElementById('updateBugStatusModal'));

            updateButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const bugId = this.dataset.bugId;
                    const currentStatus = this.dataset.currentStatus;

                    document.getElementById('updateBugId').value = bugId;
                    document.getElementById('updateBugStatus').value = currentStatus;

                    updateModal.show();
                });
            });

            // Close modal handler for create bug modal
            const createModalElement = document.getElementById('createBugModal');
            if (createModalElement) {
                createModalElement.addEventListener('hidden.bs.modal', function() {
                    const errorAlert = this.querySelector('.validation-error-alert');
                    if (errorAlert) {
                        errorAlert.remove();
                    }
                });
            }
        }

        // Filter form functions
        function updateFilterTaskOptions() {
            const projectId = document.getElementById('filterProject').value;
            const taskFilter = document.getElementById('filterTask');
            
            if (!taskFilter) return;
            
            const currentValue = taskFilter.value;
            
            taskFilter.innerHTML = '<option value="">All Tasks</option>';
            
            <?php 
            $all_tasks_js = $db->query("
                SELECT t.id, t.name, p.name as project_name, p.id as project_id 
                FROM tasks t 
                LEFT JOIN projects p ON t.project_id = p.id 
                WHERE t.status != 'closed' AND t.status != 'completed' AND p.status = 'active'
                ORDER BY p.name, t.name
            ")->fetchAll(PDO::FETCH_ASSOC);
            ?>
            
            const allTasks = <?= json_encode($all_tasks_js) ?>;
            
            if (projectId) {
                const filteredTasks = allTasks.filter(task => task.project_id == projectId);
                
                filteredTasks.forEach(task => {
                    const option = document.createElement('option');
                    option.value = task.id;
                    option.textContent = task.name + ' (' + task.project_name + ')';
                    taskFilter.appendChild(option);
                });
            } else {
                allTasks.forEach(task => {
                    const option = document.createElement('option');
                    option.value = task.id;
                    option.textContent = task.name + ' (' + task.project_name + ')';
                    taskFilter.appendChild(option);
                });
            }
            
            if (currentValue) {
                taskFilter.value = currentValue;
            }
        }

        // Create modal functions
        function handleCreateProjectChange() {
            const projectId = this.value;
            const taskSelect = document.getElementById('taskSelect');
            const taskLoading = document.getElementById('taskLoading');

            if (!taskSelect) return;

            if (projectId) {
                if (taskLoading) taskLoading.style.display = 'block';
                
                taskSelect.innerHTML = '<option value="">Select Task</option>';
                
                fetch(`get_tasks.php?project_id=${projectId}`)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data && data.length > 0) {
                            data.forEach(task => {
                                const option = document.createElement('option');
                                option.value = task.id;
                                option.textContent = task.name;
                                taskSelect.appendChild(option);
                            });
                        } else {
                            const option = document.createElement('option');
                            option.value = '';
                            option.textContent = 'No tasks available for this project';
                            option.disabled = true;
                            taskSelect.appendChild(option);
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching tasks:', error);
                        const option = document.createElement('option');
                        option.value = '';
                        option.textContent = 'Error loading tasks. Please try again.';
                        option.disabled = true;
                        taskSelect.appendChild(option);
                    })
                    .finally(() => {
                        if (taskLoading) taskLoading.style.display = 'none';
                    });
            } else {
                resetCreateTaskDropdown();
            }
        }

        function resetCreateTaskDropdown() {
            const taskSelect = document.getElementById('taskSelect');
            if (!taskSelect) return;
            
            taskSelect.innerHTML = '<option value="">Select Task</option>';
            <?php foreach ($all_tasks as $task): ?>
                const option = document.createElement('option');
                option.value = '<?= $task['id'] ?>';
                option.textContent = '<?= addslashes($task['name']) ?> (<?= addslashes($task['project_name']) ?>)';
                taskSelect.appendChild(option);
            <?php endforeach; ?>
        }

        function handleEditProjectChange() {
            const projectId = this.value;
            const taskSelect = document.getElementById('editTaskSelect');
            const taskLoading = document.getElementById('editTaskLoading');
            const currentTaskId = <?= $edit_bug ? $edit_bug['task_id'] : 'null' ?>;

            if (!taskSelect) return;

            if (projectId) {
                if (taskLoading) taskLoading.style.display = 'block';
                
                taskSelect.innerHTML = '<option value="">Select Task</option>';
                
                fetch(`get_tasks.php?project_id=${projectId}`)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data && data.length > 0) {
                            data.forEach(task => {
                                const option = document.createElement('option');
                                option.value = task.id;
                                option.textContent = task.name;
                                
                                if (currentTaskId && task.id == currentTaskId) {
                                    option.selected = true;
                                }
                                
                                taskSelect.appendChild(option);
                            });
                        } else {
                            const option = document.createElement('option');
                            option.value = '';
                            option.textContent = 'No tasks available for this project';
                            option.disabled = true;
                            taskSelect.appendChild(option);
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching tasks:', error);
                        const option = document.createElement('option');
                        option.value = '';
                        option.textContent = 'Error loading tasks. Please try again.';
                        option.disabled = true;
                        taskSelect.appendChild(option);
                    })
                    .finally(() => {
                        if (taskLoading) taskLoading.style.display = 'none';
                    });
            } else {
                resetEditTaskDropdown(currentTaskId);
            }
        }

        function resetEditTaskDropdown(currentTaskId) {
            const taskSelect = document.getElementById('editTaskSelect');
            if (!taskSelect) return;
            
            taskSelect.innerHTML = '<option value="">Select Task</option>';
            <?php 
            if ($edit_bug) {
                $all_edit_tasks = $db->query("
                    SELECT t.id, t.name, p.name as project_name
                    FROM tasks t 
                    LEFT JOIN projects p ON t.project_id = p.id
                    WHERE t.status != 'closed' 
                    AND t.status != 'completed'
                    AND p.status = 'active'
                    ORDER BY t.name
                ")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($all_edit_tasks as $task): ?>
                    const option = document.createElement('option');
                    option.value = '<?= $task['id'] ?>';
                    option.textContent = '<?= addslashes($task['name']) ?> (<?= addslashes($task['project_name']) ?>)';
                    
                    if (currentTaskId && <?= $task['id'] ?> == currentTaskId) {
                        option.selected = true;
                    }
                    
                    taskSelect.appendChild(option);
                <?php endforeach;
            } ?>
        }

        function initializeDataTables() {
            const isFiltered = <?= $isFiltered ? 'true' : 'false' ?>;

            if (!isFiltered) {
                $('#bugsTable').DataTable({
                    responsive: true,
                    pageLength: 10,
                    lengthMenu: [
                        [10, 25, 50, 100, -1],
                        [10, 25, 50, 100, "All"]
                    ],
                    order: [[0, 'desc']],
                    stateSave: true,
                    stateDuration: -1,
                    language: {
                        search: "Search bugs:",
                        lengthMenu: "Show _MENU_ bugs",
                        info: "Showing _START_ to _END_ of _TOTAL_ bugs",
                        infoEmpty: "No bugs available",
                        zeroRecords: "No matching bugs found",
                        paginate: {
                            first: "First",
                            last: "Last",
                            next: "Next",
                            previous: "Previous"
                        }
                    },
                    columnDefs: [{
                            targets: [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
                            orderable: true
                        },
                        {
                            targets: [10],
                            orderable: false,
                            searchable: false
                        }
                    ]
                });
            } else {
                $('#bugsTable').addClass('table-striped table-hover table-enhanced');
            }
        }
    </script>
</body>
</html>