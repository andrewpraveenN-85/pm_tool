<?php
include 'config/database.php';
include 'includes/auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireAuth();

$project_id = $_GET['id'] ?? 0;

// Get project details
$query = "
    SELECT p.*, u.name as created_by_name 
    FROM projects p 
    LEFT JOIN users u ON p.created_by = u.id 
    WHERE p.id = :project_id
";
$stmt = $db->prepare($query);
$stmt->bindParam(':project_id', $project_id);
$stmt->execute();
$project = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    header("Location: dashboard.php");
    exit;
}

// Get project tasks
$tasks_query = "
    SELECT t.*, 
           GROUP_CONCAT(DISTINCT u.name) as assignee_names,
           COUNT(DISTINCT b.id) as bug_count
    FROM tasks t
    LEFT JOIN task_assignments ta ON t.id = ta.task_id
    LEFT JOIN users u ON ta.user_id = u.id
    LEFT JOIN bugs b ON t.id = b.task_id
    WHERE t.project_id = :project_id
    GROUP BY t.id
    ORDER BY t.priority DESC, t.created_at DESC
";
$tasks_stmt = $db->prepare($tasks_query);
$tasks_stmt->bindParam(':project_id', $project_id);
$tasks_stmt->execute();
$tasks = $tasks_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get project statistics
$stats_query = "
    SELECT 
        COUNT(t.id) as total_tasks,
        SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) as completed_tasks,
        COUNT(DISTINCT b.id) as total_bugs,
        AVG(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) * 100 as completion_rate
    FROM projects p
    LEFT JOIN tasks t ON p.id = t.project_id
    LEFT JOIN bugs b ON t.id = b.task_id
    WHERE p.id = :project_id
";
$stats_stmt = $db->prepare($stats_query);
$stats_stmt->bindParam(':project_id', $project_id);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($project['name']) ?> - Task Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- TinyMCE WYSIWYG Editor -->
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
                <!-- Project Header -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8">
                                <h2>
                                    <?php if ($project['icon']): ?>
                                        <i class="<?= $project['icon'] ?> me-2"></i>
                                    <?php endif; ?>
                                    <?= htmlspecialchars($project['name']) ?>
                                </h2>
                                <div class="lead"><?= $project['description'] ?></div>
                                
                                <div class="row mt-3">
                                    <div class="col-md-6">
                                        <small class="text-muted">
                                            <strong>Created by:</strong> <?= htmlspecialchars($project['created_by_name']) ?><br>
                                            <strong>Created:</strong> <?= date('F j, Y', strtotime($project['created_at'])) ?><br>
                                            <strong>Last Updated:</strong> <?= date('F j, Y', strtotime($project['updated_at'])) ?>
                                        </small>
                                    </div>
                                    <div class="col-md-6">
                                        <?php if ($project['git_url']): ?>
                                        <small class="text-muted">
                                            <strong>Repository:</strong><br>
                                            <a href="<?= $project['git_url'] ?>" target="_blank"><?= $project['git_url'] ?></a>
                                        </small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 text-end">
                                <span class="badge bg-<?= $project['status'] == 'active' ? 'success' : ($project['status'] == 'completed' ? 'info' : 'secondary') ?> fs-6">
                                    <?= ucfirst($project['status']) ?>
                                </span>
                                
                                <div class="mt-3">
                                    <a href="tasks.php" class="btn btn-outline-primary">
                                        <i class="fas fa-tasks"></i> View All Tasks
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Project Statistics -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-primary"><?= $stats['total_tasks'] ?></h3>
                                <p class="text-muted">Total Tasks</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-success"><?= $stats['completed_tasks'] ?></h3>
                                <p class="text-muted">Completed Tasks</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-danger"><?= $stats['total_bugs'] ?></h3>
                                <p class="text-muted">Total Bugs</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-info"><?= number_format($stats['completion_rate'], 1) ?>%</h3>
                                <p class="text-muted">Completion Rate</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tasks Table -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Project Tasks</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Task Name</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th>Assignees</th>
                                        <th>Start Date</th>
                                        <th>End Date</th>
                                        <th>Bugs</th>
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
                                            <span class="badge bg-secondary">
                                                <?= ucfirst(str_replace('_', ' ', $task['status'])) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($task['assignee_names']) ?></td>
                                        <td><?= $task['start_datetime'] ? date('M j, Y', strtotime($task['start_datetime'])) : '-' ?></td>
                                        <td><?= $task['end_datetime'] ? date('M j, Y', strtotime($task['end_datetime'])) : '-' ?></td>
                                        <td>
                                            <?php if ($task['bug_count'] > 0): ?>
                                                <span class="badge bg-danger"><?= $task['bug_count'] ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="task_details.php?id=<?= $task['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
<footer class="bg-dark text-light text-center py-3 mt-5">
    <div class="container">
        <p class="mb-0">Developed by APNLAB. 2025.</p>
    </div>
</footer>
</html>