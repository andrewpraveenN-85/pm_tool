<?php
include 'config/database.php';
include 'includes/auth.php';
include 'includes/notifications.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireAuth();

// Initialize notification system
$notification = new Notification($db);

// Get filter parameters
$project_filter = $_GET['project'] ?? '';
$priority_filter = $_GET['priority'] ?? '';
$assignee_filter = $_GET['assignee'] ?? '';

// Build filter conditions
$filter_conditions = [];
$filter_params = [];

if (!empty($project_filter)) {
    $filter_conditions[] = "t.project_id = :project_id";
    $filter_params[':project_id'] = $project_filter;
}

if (!empty($priority_filter)) {
    $filter_conditions[] = "t.priority = :priority";
    $filter_params[':priority'] = $priority_filter;
}

if (!empty($assignee_filter)) {
    $filter_conditions[] = "ta.user_id = :assignee_id";
    $filter_params[':assignee_id'] = $assignee_filter;
}

// For developers, only show their assigned tasks
if ($_SESSION['user_role'] == 'developer') {
    $filter_conditions[] = "ta.user_id = :current_user";
    $filter_params[':current_user'] = $_SESSION['user_id'];
}

$filter_sql = !empty($filter_conditions) ? "AND " . implode(" AND ", $filter_conditions) : "";

// Get tasks for kanban board
$tasks_by_status = [
    'todo' => [],
    'reopened' => [],
    'in_progress' => [],
    'await_release' => [],
    'in_review' => [],
    'closed' => []
];

// Modified query to get assignees with their profile images
$query = "SELECT t.*, p.name as project_name, 
          GROUP_CONCAT(DISTINCT u.id) as assignee_ids,
          GROUP_CONCAT(DISTINCT u.name) as assignee_names,
          GROUP_CONCAT(DISTINCT u.image) as assignee_images,
          COUNT(DISTINCT b.id) as bug_count
          FROM tasks t
          LEFT JOIN projects p ON t.project_id = p.id
          LEFT JOIN task_assignments ta ON t.id = ta.task_id
          LEFT JOIN users u ON ta.user_id = u.id
          LEFT JOIN bugs b ON t.id = b.task_id
          WHERE 1=1 $filter_sql
          GROUP BY t.id
          ORDER BY t.priority DESC, t.created_at DESC";

$stmt = $db->prepare($query);
foreach ($filter_params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // Process assignee data to create arrays
    $assigneeIds = !empty($row['assignee_ids']) ? explode(',', $row['assignee_ids']) : [];
    $assigneeNames = !empty($row['assignee_names']) ? explode(',', $row['assignee_names']) : [];
    $assigneeImages = !empty($row['assignee_images']) ? explode(',', $row['assignee_images']) : [];
    
    // Create array of assignees with their details
    $assignees = [];
    for ($i = 0; $i < count($assigneeIds); $i++) {
        $assignees[] = [
            'id' => $assigneeIds[$i] ?? '',
            'name' => $assigneeNames[$i] ?? 'Unknown',
            'image' => $assigneeImages[$i] ?? ''
        ];
    }
    
    $row['assignees'] = $assignees;
    $tasks_by_status[$row['status']][] = $row;
}

// Get projects for filter
$projects = $db->query("SELECT id, name FROM projects WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);

// Get developers for filter
$developers = $db->query("SELECT id, name FROM users WHERE role = 'developer' AND status = 'active'")->fetchAll(PDO::FETCH_ASSOC);

// Get quick stats
$total_tasks = $db->query("SELECT COUNT(*) FROM tasks")->fetchColumn();
$completed_tasks = $db->query("SELECT COUNT(*) FROM tasks WHERE status = 'closed'")->fetchColumn();
$total_bugs = $db->query("SELECT COUNT(*) FROM bugs")->fetchColumn();
$overdue_tasks = $db->query("SELECT COUNT(*) FROM tasks WHERE end_datetime < NOW() AND status != 'closed'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Task Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .kanban-column {
            min-height: 600px;
            background: #f8f9fa;
            border-radius: 5px;
            padding: 15px;
        }
        .task-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 10px;
            margin-bottom: 10px;
            cursor: move;
            transition: transform 0.2s;
        }
        .task-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .priority-high { border-left: 4px solid #dc3545; }
        .priority-medium { border-left: 4px solid #ffc107; }
        .priority-low { border-left: 4px solid #28a745; }
        .priority-critical { border-left: 4px solid #6f42c1; }
        .column-header { 
            background: #e9ecef; 
            padding: 10px; 
            border-radius: 5px; 
            margin-bottom: 15px;
            text-align: center;
            font-weight: bold;
        }
        .stat-card {
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .assignee-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            cursor: pointer;
        }
        .assignee-avatar:hover {
            border-color: #007bff;
            transform: scale(1.1);
        }
        .assignees-container {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 8px;
        }
        .assignee-tooltip {
            position: relative;
            display: inline-block;
        }
        .assignee-tooltip .tooltip-text {
            visibility: hidden;
            width: 120px;
            background-color: #333;
            color: #fff;
            text-align: center;
            border-radius: 6px;
            padding: 5px;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            margin-left: -60px;
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 12px;
        }
        .assignee-tooltip:hover .tooltip-text {
            visibility: visible;
            opacity: 1;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid mt-4">
        <!-- Quick Stats -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card stat-card text-center bg-primary text-white">
                    <div class="card-body">
                        <h3><?= $total_tasks ?></h3>
                        <p class="mb-0">Total Tasks</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card text-center bg-success text-white">
                    <div class="card-body">
                        <h3><?= $completed_tasks ?></h3>
                        <p class="mb-0">Completed</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card text-center bg-warning text-dark">
                    <div class="card-body">
                        <h3><?= $total_bugs ?></h3>
                        <p class="mb-0">Bugs Reported</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card text-center bg-danger text-white">
                    <div class="card-body">
                        <h3><?= $overdue_tasks ?></h3>
                        <p class="mb-0">Overdue Tasks</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Project</label>
                                <select name="project" class="form-select">
                                    <option value="">All Projects</option>
                                    <?php foreach ($projects as $project): ?>
                                        <option value="<?= $project['id'] ?>" <?= $project_filter == $project['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($project['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Priority</label>
                                <select name="priority" class="form-select">
                                    <option value="">All Priorities</option>
                                    <option value="critical" <?= $priority_filter == 'critical' ? 'selected' : '' ?>>Critical</option>
                                    <option value="high" <?= $priority_filter == 'high' ? 'selected' : '' ?>>High</option>
                                    <option value="medium" <?= $priority_filter == 'medium' ? 'selected' : '' ?>>Medium</option>
                                    <option value="low" <?= $priority_filter == 'low' ? 'selected' : '' ?>>Low</option>
                                </select>
                            </div>
                            <?php if ($_SESSION['user_role'] == 'manager'): ?>
                            <div class="col-md-3">
                                <label class="form-label">Assignee</label>
                                <select name="assignee" class="form-select">
                                    <option value="">All Assignees</option>
                                    <?php foreach ($developers as $dev): ?>
                                        <option value="<?= $dev['id'] ?>" <?= $assignee_filter == $dev['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($dev['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div class="col-md-3">
                                <label class="form-label">&nbsp;</label>
                                <div>
                                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                                    <a href="dashboard.php" class="btn btn-secondary">Clear</a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Kanban Board -->
        <div class="row">
            <?php 
            $status_labels = [
                'todo' => 'To Do',
                'reopened' => 'Reopened', 
                'in_progress' => 'In Progress',
                'await_release' => 'Await Release',
                'in_review' => 'In Review',
                'closed' => 'Closed'
            ];
            
            foreach ($tasks_by_status as $status => $tasks): ?>
            <div class="col-lg-2 col-md-4 mb-4">
                <div class="column-header">
                    <?= $status_labels[$status] ?> (<?= count($tasks) ?>)
                </div>
                <div class="kanban-column" data-status="<?= $status ?>">
                    <?php foreach ($tasks as $task): ?>
                    <div class="task-card priority-<?= $task['priority'] ?>" 
                         data-task-id="<?= $task['id'] ?>" 
                         draggable="true">
                        <h6 class="mb-1"><?= htmlspecialchars($task['name']) ?></h6>
                        <small class="text-muted">Project: <?= htmlspecialchars($task['project_name']) ?></small>
                        
                        <!-- Assignees with Profile Pictures -->
                        <?php if (!empty($task['assignees'])): ?>
                            <div class="assignees-container">
                                <?php foreach ($task['assignees'] as $assignee): 
                                    $profilePic = getProfilePicture($assignee['image'], $assignee['name'], 32);
                                    $defaultPic = getDefaultProfilePicture(32);
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
                            </div>
                        <?php else: ?>
                            <div class="assignees-container">
                                <div class="assignee-tooltip">
                                    <img src="<?= getDefaultProfilePicture(32) ?>" 
                                         class="assignee-avatar" 
                                         alt="Unassigned"
                                         title="Unassigned">
                                    <span class="tooltip-text">Unassigned</span>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($task['bug_count'] > 0): ?>
                            <div class="mt-2">
                                <small class="text-danger"><i class="fas fa-bug"></i> <?= $task['bug_count'] ?> bug(s)</small>
                            </div>
                        <?php endif; ?>
                        
                        <div class="mt-2">
                            <span class="badge bg-<?= 
                                $task['priority'] == 'critical' ? 'danger' : 
                                ($task['priority'] == 'high' ? 'warning' : 
                                ($task['priority'] == 'medium' ? 'info' : 'success')) 
                            ?>">
                                <?= ucfirst($task['priority']) ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Drag and drop functionality
        document.addEventListener('DOMContentLoaded', function() {
            const taskCards = document.querySelectorAll('.task-card');
            const columns = document.querySelectorAll('.kanban-column');

            taskCards.forEach(card => {
                card.addEventListener('dragstart', handleDragStart);
                card.addEventListener('dragend', handleDragEnd);
            });

            columns.forEach(column => {
                column.addEventListener('dragover', handleDragOver);
                column.addEventListener('drop', handleDrop);
            });

            function handleDragStart(e) {
                e.dataTransfer.setData('text/plain', e.target.dataset.taskId);
                e.target.classList.add('dragging');
            }

            function handleDragEnd(e) {
                e.target.classList.remove('dragging');
            }

            function handleDragOver(e) {
                e.preventDefault();
            }

            function handleDrop(e) {
                e.preventDefault();
                const taskId = e.dataTransfer.getData('text/plain');
                const newStatus = e.target.closest('.kanban-column').dataset.status;
                
                // Update task status via AJAX
                fetch('update_task_status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        task_id: taskId,
                        status: newStatus
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Error updating task status');
                    }
                });
            }
        });
    </script>
</body>
<footer class="bg-dark text-light text-center py-3 mt-5">
    <div class="container">
        <p class="mb-0">Developed by APNLAB. 2025.</p>
    </div>
</footer>
</html>