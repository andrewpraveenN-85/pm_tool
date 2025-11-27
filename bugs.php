<?php
include 'config/database.php';
include 'includes/auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireRole(['manager', 'qa']);

// Handle form submissions
if ($_POST) {
    if (isset($_POST['create_bug'])) {
        $name = $_POST['name'];
        $description = $_POST['description'];
        $task_id = $_POST['task_id'];
        $priority = $_POST['priority'];
        $start_datetime = $_POST['start_datetime'];
        $end_datetime = $_POST['end_datetime'];
        
        $query = "INSERT INTO bugs (name, description, task_id, priority, start_datetime, end_datetime, created_by) 
                  VALUES (:name, :description, :task_id, :priority, :start_datetime, :end_datetime, :created_by)";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':task_id', $task_id);
        $stmt->bindParam(':priority', $priority);
        $stmt->bindParam(':start_datetime', $start_datetime);
        $stmt->bindParam(':end_datetime', $end_datetime);
        $stmt->bindParam(':created_by', $_SESSION['user_id']);
        
        if ($stmt->execute()) {
            $success = "Bug reported successfully!";
        } else {
            $error = "Failed to report bug!";
        }
    }
    
    if (isset($_POST['update_bug_status'])) {
        $bug_id = $_POST['bug_id'];
        $status = $_POST['status'];
        
        $query = "UPDATE bugs SET status = :status, updated_at = NOW() WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':id', $bug_id);
        
        if ($stmt->execute()) {
            $success = "Bug status updated successfully!";
        } else {
            $error = "Failed to update bug status!";
        }
    }
}

// Get bugs based on user role
if ($_SESSION['user_role'] == 'manager') {
    $bugs_query = "
        SELECT b.*, t.name as task_name, p.name as project_name, u.name as created_by_name
        FROM bugs b
        LEFT JOIN tasks t ON b.task_id = t.id
        LEFT JOIN projects p ON t.project_id = p.id
        LEFT JOIN users u ON b.created_by = u.id
        ORDER BY b.created_at DESC
    ";
} else {
    // For QA, show all bugs
    $bugs_query = "
        SELECT b.*, t.name as task_name, p.name as project_name, u.name as created_by_name
        FROM bugs b
        LEFT JOIN tasks t ON b.task_id = t.id
        LEFT JOIN projects p ON t.project_id = p.id
        LEFT JOIN users u ON b.created_by = u.id
        ORDER BY b.created_at DESC
    ";
}

$bugs = $db->query($bugs_query)->fetchAll(PDO::FETCH_ASSOC);

// Get tasks for dropdown
$tasks = $db->query("
    SELECT t.id, t.name, p.name as project_name 
    FROM tasks t 
    LEFT JOIN projects p ON t.project_id = p.id 
    WHERE t.status != 'closed'
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bugs - Task Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bugs as $bug): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($bug['name']) ?></strong>
                                    <?php if ($bug['description']): ?>
                                        <br><small class="text-muted"><?= substr(htmlspecialchars($bug['description']), 0, 50) ?>...</small>
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
                                    <div class="btn-group">
                                        <button class="btn btn-sm btn-outline-warning update-bug-status" 
                                                data-bug-id="<?= $bug['id'] ?>" 
                                                data-current-status="<?= $bug['status'] ?>">
                                            <i class="fas fa-sync"></i> Status
                                        </button>
                                        <a href="bug_details.php?id=<?= $bug['id'] ?>" class="btn btn-sm btn-outline-primary">
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

    <!-- Create Bug Modal -->
    <div class="modal fade" id="createBugModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Report New Bug</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Bug Name</label>
                                <input type="text" class="form-control" name="name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Task</label>
                                <select class="form-select" name="task_id" required>
                                    <option value="">Select Task</option>
                                    <?php foreach ($tasks as $task): ?>
                                        <option value="<?= $task['id'] ?>">
                                            <?= htmlspecialchars($task['name']) ?> (<?= htmlspecialchars($task['project_name']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="3" required></textarea>
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
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status" required>
                                    <option value="open" selected>Open</option>
                                    <option value="in_progress">In Progress</option>
                                    <option value="resolved">Resolved</option>
                                    <option value="closed">Closed</option>
                                </select>
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
                        <button type="submit" name="create_bug" class="btn btn-primary">Report Bug</button>
                    </div>
                </form>
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
    <script>
        // Update bug status functionality
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
        });
    </script>
</body>
<footer class="bg-dark text-light text-center py-3 mt-5">
    <div class="container">
        <p class="mb-0">Developed by APNLAB. 2025.</p>
    </div>
</footer>
</html>