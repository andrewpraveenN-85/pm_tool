<?php
include 'config/database.php';
include 'includes/auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireAuth();

$task_id = $_GET['id'] ?? 0;

// Get task details
$query = "
    SELECT t.*, p.name as project_name, p.id as project_id, 
           u_created.name as created_by_name,
           GROUP_CONCAT(DISTINCT u_assign.id) as assignee_ids,
           GROUP_CONCAT(DISTINCT u_assign.name) as assignee_names,
           GROUP_CONCAT(DISTINCT u_assign.image) as assignee_images
    FROM tasks t
    LEFT JOIN projects p ON t.project_id = p.id
    LEFT JOIN users u_created ON t.created_by = u_created.id
    LEFT JOIN task_assignments ta ON t.id = ta.task_id
    LEFT JOIN users u_assign ON ta.user_id = u_assign.id
    WHERE t.id = :task_id
    GROUP BY t.id
";
$stmt = $db->prepare($query);
$stmt->bindParam(':task_id', $task_id);
$stmt->execute();
$task = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$task) {
    header("Location: tasks.php");
    exit;
}

// Check if user has permission to view this task
if ($_SESSION['user_role'] == 'developer') {
    $permission_query = "SELECT * FROM task_assignments WHERE task_id = :task_id AND user_id = :user_id";
    $permission_stmt = $db->prepare($permission_query);
    $permission_stmt->bindParam(':task_id', $task_id);
    $permission_stmt->bindParam(':user_id', $_SESSION['user_id']);
    $permission_stmt->execute();

    if ($permission_stmt->rowCount() == 0 && $task['created_by'] != $_SESSION['user_id']) {
        header("Location: unauthorized.php");
        exit;
    }
}

// Handle comment submission
if ($_POST && isset($_POST['add_comment'])) {
    $comment = $_POST['comment'];

    try {
        $db->beginTransaction();

        $query = "INSERT INTO comments (entity_type, entity_id, user_id, comment) 
                  VALUES ('task', :task_id, :user_id, :comment)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':task_id', $task_id);
        $stmt->bindParam(':user_id', $_SESSION['user_id']);
        $stmt->bindParam(':comment', $comment);
        $stmt->execute();

        $comment_id = $db->lastInsertId();

        // Handle file uploads for comment
        if (!empty($_FILES['attachments']['name'][0])) {
            $upload_dir = 'uploads/tasks/' . $task_id . '/comments/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                    $original_name = $_FILES['attachments']['name'][$key];
                    $file_extension = pathinfo($original_name, PATHINFO_EXTENSION);
                    $filename = 'comment_' . $comment_id . '_' . uniqid() . '.' . $file_extension;
                    $target_file = $upload_dir . $filename;

                    if (move_uploaded_file($tmp_name, $target_file)) {
                        $query = "INSERT INTO attachments (entity_type, entity_id, filename, original_name, file_path, file_size, file_type, uploaded_by) 
                                  VALUES ('comment', :entity_id, :filename, :original_name, :file_path, :file_size, :file_type, :uploaded_by)";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':entity_id', $comment_id);
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
        $success = "Comment added successfully!";
    } catch (Exception $e) {
        $db->rollBack();
        $error = "Failed to add comment: " . $e->getMessage();
    }
}

// Handle status update
if ($_POST && isset($_POST['update_status'])) {
    $status = $_POST['status'];

    $query = "UPDATE tasks SET status = :status, updated_at = NOW() WHERE id = :task_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':status', $status);
    $stmt->bindParam(':task_id', $task_id);

    if ($stmt->execute()) {
        $success = "Task status updated successfully!";
        // Refresh task data
        $stmt = $db->prepare("SELECT * FROM tasks WHERE id = :task_id");
        $stmt->bindParam(':task_id', $task_id);
        $stmt->execute();
        $task = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $error = "Failed to update task status!";
    }
}

// Get task comments with attachments
$comments_query = "
    SELECT c.*, u.name as user_name, u.image as user_image 
    FROM comments c 
    LEFT JOIN users u ON c.user_id = u.id 
    WHERE c.entity_type = 'task' AND c.entity_id = :task_id 
    ORDER BY c.created_at DESC
";
$comments_stmt = $db->prepare($comments_query);
$comments_stmt->bindParam(':task_id', $task_id);
$comments_stmt->execute();
$comments = $comments_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get attachments for comments
$comment_attachments = [];
if (!empty($comments)) {
    $comment_ids = array_column($comments, 'id');
    $placeholders = str_repeat('?,', count($comment_ids) - 1) . '?';

    $attachments_query = "
        SELECT a.* 
        FROM attachments a 
        WHERE a.entity_type = 'comment' AND a.entity_id IN ($placeholders)
        ORDER BY a.created_at DESC
    ";
    $attachments_stmt = $db->prepare($attachments_query);
    $attachments_stmt->execute($comment_ids);
    $attachments = $attachments_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group attachments by comment_id
    foreach ($attachments as $attachment) {
        $comment_attachments[$attachment['entity_id']][] = $attachment;
    }
}

// Get task attachments
$task_attachments_query = "
    SELECT a.*, u.name as uploaded_by_name 
    FROM attachments a 
    LEFT JOIN users u ON a.uploaded_by = u.id 
    WHERE a.entity_type = 'task' AND a.entity_id = :task_id 
    ORDER BY a.created_at DESC
";
$task_attachments_stmt = $db->prepare($task_attachments_query);
$task_attachments_stmt->bindParam(':task_id', $task_id);
$task_attachments_stmt->execute();
$task_attachments = $task_attachments_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all developers for assignment
$developers = $db->query("SELECT id, name, image FROM users WHERE role = 'developer' AND status = 'active'")->fetchAll(PDO::FETCH_ASSOC);

// Define status to color mapping (same as tasks.php)
$statusColors = [
    'closed' => 'success',
    'in_progress' => 'primary',
    'todo' => 'warning',
    'reopened' => 'info',
    'await_release' => 'info',
    'in_review' => 'info',
    'default' => 'secondary'
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($task['name']) ?> - Task Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- TinyMCE WYSIWYG Editor -->
    <script src="https://cdn.jsdelivr.net/npm/tinymce@6.8.2/tinymce.min.js"></script>
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.bootstrap5.min.css" rel="stylesheet">
    <style>
        .task-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }

        .comment-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
        }

        .bug-card {
            border-left: 4px solid #dc3545;
        }

        .attachment-preview {
            max-width: 200px;
            max-height: 150px;
            object-fit: cover;
        }

        .attachment-item {
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 8px;
            margin: 2px;
            background: #f8f9fa;
        }

        .assignee-avatar {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #fff;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .assignee-avatar:hover {
            border-color: #007bff;
            transform: scale(1.2);
            z-index: 10;
            position: relative;
        }

        .assignees-container {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }

        .assignee-tooltip {
            position: relative;
            display: inline-block;
        }

        .assignee-tooltip .tooltip-text {
            visibility: hidden;
            width: 100px;
            background-color: #333;
            color: #fff;
            text-align: center;
            border-radius: 6px;
            padding: 5px;
            position: absolute;
            z-index: 100;
            bottom: 125%;
            left: 50%;
            margin-left: -50px;
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 11px;
            white-space: nowrap;
        }

        .assignee-tooltip:hover .tooltip-text {
            visibility: visible;
            opacity: 1;
        }

        .assignee-tooltip .tooltip-text::after {
            content: "";
            position: absolute;
            top: 100%;
            left: 50%;
            margin-left: -5px;
            border-width: 5px;
            border-style: solid;
            border-color: #333 transparent transparent transparent;
        }

        .form-select option img {
            vertical-align: middle;
            margin-right: 8px;
        }
        
        /* Priority badge styles */
        .priority-critical { background-color: #dc3545 !important; }
        .priority-high { background-color: #fd7e14 !important; }
        .priority-medium { background-color: #17a2b8 !important; }
        .priority-low { background-color: #28a745 !important; }
        
        /* Badge enhancements */
        .badge-pill {
            border-radius: 10rem;
            padding: 0.4em 0.8em;
            font-size: 0.85em;
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
    </style>
</head>

<body>
    <?php include 'includes/header.php'; ?>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <!-- Task Header -->
                <div class="task-header">
                    <div class="row">
                        <div class="col-md-8">
                            <h1><?= htmlspecialchars($task['name']) ?></h1>
                            <div class="lead mb-0"><?= $task['description'] ?></div>
                        </div>
                        <div class="col-md-4 text-end">
                            <span class="badge bg-light text-dark fs-6">
                                Project: <?= htmlspecialchars($task['project_name']) ?>
                            </span>
                            <br>
                            <span class="badge badge-pill priority-<?= $task['priority'] ?> fs-6 mt-2">
                                <?= ucfirst($task['priority']) ?> Priority
                            </span>
                        </div>
                    </div>
                </div>

                <?php if (isset($success)): ?>
                    <div class="alert alert-success"><?= $success ?></div>
                <?php endif; ?>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?= $error ?></div>
                <?php endif; ?>

                <div class="row">
                    <!-- Task Details -->
                    <div class="col-md-8">
                        <!-- Task Information -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0">Task Information</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <p><strong>Status:</strong>
                                            <span class="badge badge-pill bg-<?= $statusColors[$task['status']] ?? $statusColors['default'] ?>">
                                                <?= ucfirst(str_replace('_', ' ', $task['status'])) ?>
                                            </span>
                                        </p>
                                        <p><strong>Priority:</strong>
                                            <span class="badge badge-pill priority-<?= $task['priority'] ?>">
                                                <?= ucfirst($task['priority']) ?>
                                            </span>
                                        </p>
                                        <p><strong>Start Date:</strong>
                                            <?= $task['start_datetime'] ? date('F j, Y H:i', strtotime($task['start_datetime'])) : 'Not set' ?>
                                        </p>
                                    </div>
                                    <div class="col-md-6">
                                        <p><strong>Assignees:</strong></p>
                                        <div class="assignees-container">
                                            <?php
                                            $assigneeIds = explode(',', $task['assignee_ids']);
                                            $assigneeNames = explode(',', $task['assignee_names']);
                                            $assigneeImages = !empty($task['assignee_images']) ? explode(',', $task['assignee_images']) : [];

                                            $assignees = [];
                                            for ($i = 0; $i < count($assigneeIds); $i++) {
                                                $assignees[] = [
                                                    'id' => $assigneeIds[$i] ?? '',
                                                    'name' => $assigneeNames[$i] ?? 'Unknown',
                                                    'image' => $assigneeImages[$i] ?? ''
                                                ];
                                            }
                                            $task['assignees'] = $assignees; ?>
                                            <?php if (!empty($task['assignees'])): ?>
                                                <?php foreach ($task['assignees'] as $assignee):
                                                    $profilePic = getProfilePicture($assignee['image'], $assignee['name'], 28);
                                                    $defaultPic = getDefaultProfilePicture(28);
                                                ?>
                                                    <div class="assignee-tooltip">
                                                        <img src="<?= $profilePic ?>"
                                                            class="assignee-avatar"
                                                            alt="<?= htmlspecialchars($assignee['name']) ?>"
                                                            title="<?= htmlspecialchars($assignee['name']) ?>"
                                                            onerror="this.onerror=null; this.src='<?= $defaultPic ?>'">
                                                        <span class="tooltip-text"><?= htmlspecialchars($assignee['name']) ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <div class="assignee-tooltip">
                                                    <img src="<?= getDefaultProfilePicture(28) ?>"
                                                        class="assignee-avatar"
                                                        alt="Unassigned"
                                                        title="Unassigned">
                                                    <span class="tooltip-text">Unassigned</span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <p class="mt-2"><strong>Created By:</strong> <?= htmlspecialchars($task['created_by_name']) ?></p>
                                        <p><strong>End Date:</strong>
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
                                                        $class = 'text-danger fw-bold';
                                                        $days_overdue = floor(($updated_at - $end_date) / (60 * 60 * 24));
                                                        $message = " (Closed " . $days_overdue . " days overdue)";
                                                    }
                                                } else {
                                                    // For non-closed tasks, check if current date is past due date
                                                    if ($now > $end_date) {
                                                        $class = 'text-danger fw-bold';
                                                        $days_overdue = floor(($now - $end_date) / (60 * 60 * 24));
                                                        $message = " (Overdue by " . $days_overdue . " days)";
                                                    } elseif (($end_date - $now) <= (2 * 24 * 60 * 60)) {
                                                        $class = 'text-warning fw-bold';
                                                        $days_remaining = floor(($end_date - $now) / (60 * 60 * 24));
                                                        $message = " (Due in " . $days_remaining . " days)";
                                                    }
                                                }
                                                ?>
                                                <span class="<?= $class ?>">
                                                    <?= date('F j, Y H:i', $end_date) ?>
                                                    <?= $message ?>
                                                </span>
                                            <?php else: ?>
                                                Not set
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>

                                <!-- Status Update Form -->
                                <?php if ($_SESSION['user_role'] == 'manager' || in_array($_SESSION['user_id'], explode(',', $task['assignee_ids']))): ?>
                                    <div class="mt-4">
                                        <form method="POST" class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label">Update Status</label>
                                                <select name="status" class="form-select" required>
                                                    <option value="todo" <?= $task['status'] == 'todo' ? 'selected' : '' ?>>To Do</option>
                                                    <option value="reopened" <?= $task['status'] == 'reopened' ? 'selected' : '' ?>>Reopened</option>
                                                    <option value="in_progress" <?= $task['status'] == 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                                                    <option value="await_release" <?= $task['status'] == 'await_release' ? 'selected' : '' ?>>Await Release</option>
                                                    <option value="in_review" <?= $task['status'] == 'in_review' ? 'selected' : '' ?>>In Review</option>
                                                    <option value="closed" <?= $task['status'] == 'closed' ? 'selected' : '' ?>>Closed</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6 d-flex align-items-end">
                                                <button type="submit" name="update_status" class="btn btn-primary">Update Status</button>
                                            </div>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Task Attachments -->
                        <?php if (!empty($task_attachments)): ?>
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5 class="mb-0">Task Attachments</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <?php foreach ($task_attachments as $attachment): ?>
                                            <div class="col-md-6 mb-3">
                                                <div class="attachment-item">
                                                    <div class="d-flex justify-content-between align-items-start">
                                                        <div>
                                                            <h6 class="mb-1">
                                                                <i class="fas fa-paperclip"></i>
                                                                <?= htmlspecialchars($attachment['original_name']) ?>
                                                            </h6>
                                                            <small class="text-muted">
                                                                <?= round($attachment['file_size'] / 1024, 1) ?> KB •
                                                                <?= $attachment['uploaded_by_name'] ?>
                                                            </small>
                                                        </div>
                                                        <a href="download.php?id=<?= $attachment['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                            <i class="fas fa-download"></i>
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Comments Section -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0">Comments</h5>
                            </div>
                            <div class="card-body">
                                <!-- Add Comment Form -->
                                <form method="POST" class="mb-4" enctype="multipart/form-data">
                                    <div class="mb-3">
                                        <label class="form-label">Add Comment</label>
                                        <textarea class="form-control wysiwyg" name="comment" required placeholder="Enter your comment..."></textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Attachments</label>
                                        <input type="file" class="form-control" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt,.zip">
                                        <small class="text-muted">You can select multiple files. Maximum 10MB per file.</small>
                                    </div>
                                    <button type="submit" name="add_comment" class="btn btn-primary">Post Comment</button>
                                </form>

                                <!-- Comments List -->
                                <div class="comments-list">
                                    <?php if (empty($comments)): ?>
                                        <p class="text-muted text-center">No comments yet.</p>
                                    <?php else: ?>
                                        <?php foreach ($comments as $comment): ?>
                                            <div class="d-flex mb-3">
                                                <img src="<?= $comment['user_image'] ?: getDefaultProfilePicture(40) ?>"
                                                    class="comment-avatar me-3" alt="<?= htmlspecialchars($comment['user_name']) ?>"
                                                    onerror="this.onerror=null; this.src='<?= getDefaultProfilePicture(40) ?>'">
                                                <div class="flex-grow-1">
                                                    <div class="d-flex justify-content-between align-items-start">
                                                        <h6 class="mb-1"><?= htmlspecialchars($comment['user_name']) ?></h6>
                                                        <small class="text-muted"><?= date('M j, Y H:i', strtotime($comment['created_at'])) ?></small>
                                                    </div>
                                                    <div class="mb-1"><?= $comment['comment'] ?></div>

                                                    <!-- Display attachments for this comment -->
                                                    <?php if (isset($comment_attachments[$comment['id']])): ?>
                                                        <div class="mt-2">
                                                            <small class="text-muted">Attachments:</small>
                                                            <div class="d-flex flex-wrap gap-2 mt-1">
                                                                <?php foreach ($comment_attachments[$comment['id']] as $attachment): ?>
                                                                    <div class="attachment-item">
                                                                        <a href="download.php?id=<?= $attachment['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                                                                            <i class="fas fa-paperclip"></i>
                                                                            <?= htmlspecialchars($attachment['original_name']) ?>
                                                                            <small>(<?= round($attachment['file_size'] / 1024, 1) ?> KB)</small>
                                                                        </a>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar -->
                    <div class="col-md-4">
                        <!-- Bugs Section -->
                        <div class="card mb-4">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Related Bugs</h5>
                                <?php 
                                // Get bugs count for this task
                                $bugs_count_query = "SELECT COUNT(*) as count FROM bugs WHERE task_id = :task_id";
                                $bugs_count_stmt = $db->prepare($bugs_count_query);
                                $bugs_count_stmt->bindParam(':task_id', $task_id);
                                $bugs_count_stmt->execute();
                                $bugs_count = $bugs_count_stmt->fetch(PDO::FETCH_ASSOC);
                                ?>
                                <span class="badge bg-danger"><?= $bugs_count['count'] ?? 0 ?></span>
                            </div>
                            <div class="card-body">
                                <?php 
                                // Get bugs for this task
                                $bugs_query = "
                                    SELECT b.*, p.name as project_name, u.name as created_by_name 
                                    FROM bugs b
                                    LEFT JOIN tasks t ON b.task_id = t.id
                                    LEFT JOIN projects p ON t.project_id = p.id
                                    LEFT JOIN users u ON b.created_by = u.id
                                    WHERE b.task_id = :task_id
                                    ORDER BY b.created_at DESC
                                ";
                                $bugs_stmt = $db->prepare($bugs_query);
                                $bugs_stmt->bindParam(':task_id', $task_id);
                                $bugs_stmt->execute();
                                $bugs = $bugs_stmt->fetchAll(PDO::FETCH_ASSOC);
                                ?>
                                
                                <?php if (empty($bugs)): ?>
                                    <p class="text-muted">No bugs reported for this task.</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table id="bugsTable" class="table table-striped table-hover w-100">
                                            <thead>
                                                <tr>
                                                    <th>Bug Name</th>
                                                    <th>Priority</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($bugs as $bug): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?= htmlspecialchars($bug['name']) ?></strong>
                                                            <?php if ($bug['description']): ?>
                                                                <br><small class="text-muted"><?= substr(strip_tags($bug['description']), 0, 30) ?>...</small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <span class="badge badge-pill priority-<?= $bug['priority'] ?>">
                                                                <?= ucfirst($bug['priority']) ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <span class="badge badge-pill bg-<?= 
                                                                $bug['status'] == 'resolved' ? 'success' : 
                                                                ($bug['status'] == 'in_progress' ? 'warning' : 'danger')
                                                            ?>">
                                                                <?= ucfirst(str_replace('_', ' ', $bug['status'])) ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <a href="bug_details.php?id=<?= $bug['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                                <i class="fas fa-eye"></i>
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>

                                <?php if ($_SESSION['user_role'] == 'manager' || $_SESSION['user_role'] == 'qa'): ?>
                                    <div class="mt-3">
                                        <a href="bugs.php?task_id=<?= $task_id ?>" class="btn btn-outline-primary btn-sm w-100">
                                            <i class="fas fa-bug"></i> Report New Bug
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Quick Actions</h5>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <a href="project_details.php?id=<?= $task['project_id'] ?>" class="btn btn-outline-primary">
                                        <i class="fas fa-project-diagram"></i> View Project
                                    </a>
                                    <?php if ($_SESSION['user_role'] == 'manager'): ?>
                                        <button class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#assignDevelopersModal">
                                            <i class="fas fa-users"></i> Assign Developers
                                        </button>
                                    <?php endif; ?>
                                    <a href="tasks.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-arrow-left"></i> Back to Tasks
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Assign Developers Modal -->
    <?php if ($_SESSION['user_role'] == 'manager'): ?>
        <div class="modal fade" id="assignDevelopersModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Assign Developers</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST" action="update_task_assignments.php">
                        <input type="hidden" name="task_id" value="<?= $task_id ?>">
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Select Developers</label>
                                <select class="form-select" name="assignees[]" multiple size="8" style="font-size: 14px;">
                                    <?php
                                    $current_assignees = explode(',', $task['assignee_ids']);
                                    foreach ($developers as $dev):
                                    ?>
                                        <option value="<?= $dev['id'] ?>" 
                                            <?= in_array($dev['id'], $current_assignees) ? 'selected' : '' ?>
                                            style="padding: 8px;">
                                            <img src="<?= $dev['image'] ?: getDefaultProfilePicture(20) ?>" 
                                                class="rounded-circle me-2" width="20" height="20"
                                                onerror="this.onerror=null; this.src='<?= getDefaultProfilePicture(20) ?>'"
                                                style="vertical-align: middle;">
                                            <?= htmlspecialchars($dev['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Hold Ctrl to select multiple developers</small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="submit" class="btn btn-primary">Update Assignments</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

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
            tinymce.init({
                selector: 'textarea.wysiwyg',
                plugins: 'anchor autolink charmap codesample emoticons image link lists media searchreplace table visualblocks wordcount',
                toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | link image media table | align lineheight | numlist bullist indent outdent | emoticons charmap | removeformat',
                menubar: false,
                height: 300,
                promotion: false,
                branding: false
            });
            
            // Initialize DataTable for bugs if table exists
            if (document.getElementById('bugsTable')) {
                $('#bugsTable').DataTable({
                    responsive: true,
                    pageLength: 5,
                    lengthMenu: [[5, 10, 25, -1], [5, 10, 25, "All"]],
                    order: [[0, 'asc']], // Sort by bug name ascending
                    language: {
                        search: "Search bugs:",
                        lengthMenu: "Show _MENU_ bugs",
                        info: "Showing _START_ to _END_ of _TOTAL_ bugs",
                        paginate: {
                            first: "First",
                            last: "Last",
                            next: "Next",
                            previous: "Previous"
                        }
                    },
                    columnDefs: [
                        {
                            targets: [0, 1, 2],
                            orderable: true
                        },
                        {
                            targets: [3], // Actions column
                            orderable: false,
                            searchable: false,
                            width: "70px"
                        }
                    ]
                });
            }
            
            // Apply priority badge colors
            const priorityBadges = document.querySelectorAll('.priority-critical, .priority-high, .priority-medium, .priority-low');
            priorityBadges.forEach(badge => {
                const priority = badge.textContent.trim().toLowerCase();
                badge.className = 'badge badge-pill';
                
                switch(priority) {
                    case 'critical':
                        badge.classList.add('priority-critical');
                        break;
                    case 'high':
                        badge.classList.add('priority-high');
                        break;
                    case 'medium':
                        badge.classList.add('priority-medium');
                        break;
                    case 'low':
                        badge.classList.add('priority-low');
                        break;
                }
            });
            
            // Apply status badge colors
            const statusBadges = document.querySelectorAll('span.badge[class*="bg-"]');
            statusBadges.forEach(badge => {
                const status = badge.textContent.trim().toLowerCase().replace(' ', '_');
                const badgeClass = badge.className;
                
                // Remove existing background classes
                badge.className = badgeClass.replace(/bg-\w+/g, '');
                
                // Add consistent status classes
                switch(status) {
                    case 'closed':
                        badge.classList.add('bg-success');
                        break;
                    case 'in_progress':
                        badge.classList.add('bg-primary');
                        break;
                    case 'todo':
                        badge.classList.add('bg-warning');
                        break;
                    case 'reopened':
                    case 'await_release':
                    case 'in_review':
                        badge.classList.add('bg-info');
                        break;
                    default:
                        badge.classList.add('bg-secondary');
                }
                
                // Ensure badge-pill class
                badge.classList.add('badge-pill');
            });
        });
    </script>
</body>
<footer class="bg-dark text-light text-center py-3 mt-5">
    <div class="container">
        <p class="mb-0">Developed by APNLAB. 2025.</p>
    </div>
</footer>

</html>