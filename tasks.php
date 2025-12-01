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
        $name = $_POST['name'];
        $description = $_POST['description'];
        $project_id = $_POST['project_id'];
        $priority = $_POST['priority'];
        $start_datetime = $_POST['start_datetime'];
        $end_datetime = $_POST['end_datetime'];
        $assignees = $_POST['assignees'] ?? [];
        
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
                            $query = "INSERT INTO attachments (entity_type, entity_id, filename, original_name, file_path, file_size, file_type, uploaded_by) 
                                      VALUES ('task', :entity_id, :filename, :original_name, :file_path, :file_size, :file_type, :uploaded_by)";
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
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Failed to create task: " . $e->getMessage();
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
}

// Get tasks based on user role
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
        GROUP BY t.id
        ORDER BY t.created_at DESC
    ";
} else {
    // For developers, only show assigned tasks
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
        WHERE ta.user_id = :user_id
        GROUP BY t.id
        ORDER BY t.created_at DESC
    ";
}

$stmt = $db->prepare($tasks_query);
if ($_SESSION['user_role'] != 'manager') {
    $stmt->bindParam(':user_id', $_SESSION['user_id']);
}
$stmt->execute();
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get active projects for dropdown
$projects = $db->query("SELECT id, name FROM projects WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);

// Get developers for assignment
$developers = $db->query("SELECT id, name FROM users WHERE role = 'developer' AND status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
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

                <div class="table-responsive">
                    <table class="table table-striped table-hover">
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
                                    <span class="badge bg-<?= 
                                        $task['status'] == 'completed' ? 'success' : 
                                        ($task['status'] == 'in_progress' ? 'primary' : 
                                        ($task['status'] == 'pending' ? 'warning' : 'secondary')) 
                                    ?>">
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
                                        $diff = $end_date - $now;
                                        $days = floor($diff / (60 * 60 * 24));
                                        
                                        $class = 'text-muted';
                                        if ($days < 0) {
                                            $class = 'text-danger';
                                        } elseif ($days <= 2) {
                                            $class = 'text-warning';
                                        }
                                        ?>
                                        <span class="<?= $class ?>">
                                            <?= date('M j, Y', $end_date) ?>
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

    <?php if ($_SESSION['user_role'] == 'manager'): ?>
    <!-- Create Task Modal -->
    <div class="modal fade" id="createTaskModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Task</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Task Name</label>
                                <input type="text" class="form-control" name="name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Project</label>
                                <select class="form-select" name="project_id" required>
                                    <option value="">Select Project</option>
                                    <?php foreach ($projects as $project): ?>
                                        <option value="<?= $project['id'] ?>"><?= htmlspecialchars($project['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control wysiwyg" name="description"></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Attachments</label>
                            <input type="file" class="form-control" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt,.zip">
                            <small class="text-muted">You can select multiple files. Maximum 10MB per file.</small>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Priority</label>
                                <select class="form-select" name="priority" required>
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                    <option value="critical">Critical</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Assign Developers</label>
                                <select class="form-select" name="assignees[]" multiple>
                                    <?php foreach ($developers as $dev): ?>
                                        <option value="<?= $dev['id'] ?>"><?= htmlspecialchars($dev['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Hold Ctrl to select multiple developers</small>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Start Date & Time</label>
                                <input type="datetime-local" class="form-control" name="start_datetime">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">End Date & Time</label>
                                <input type="datetime-local" class="form-control" name="end_datetime">
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
                                <option value="pending">Pending</option>
                                <option value="in_progress">In Progress</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
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
        });
    </script>
</body>
</html>
