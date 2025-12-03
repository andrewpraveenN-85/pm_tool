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

// Get project tasks with assignee images
$tasks_query = "
    SELECT t.*, 
           GROUP_CONCAT(DISTINCT u.id) as assignee_ids,
           GROUP_CONCAT(DISTINCT u.name) as assignee_names,
           GROUP_CONCAT(DISTINCT u.image) as assignee_images,
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

// Process assignee data for each task
foreach ($tasks as &$task) {
    $assigneeIds = !empty($task['assignee_ids']) ? explode(',', $task['assignee_ids']) : [];
    $assigneeNames = !empty($task['assignee_names']) ? explode(',', $task['assignee_names']) : [];
    $assigneeImages = !empty($task['assignee_images']) ? explode(',', $task['assignee_images']) : [];
    
    // Create array of assignees with their details
    $assignees = [];
    for ($i = 0; $i < count($assigneeIds); $i++) {
        $assignees[] = [
            'id' => $assigneeIds[$i] ?? '',
            'name' => $assigneeNames[$i] ?? 'Unknown',
            'image' => $assigneeImages[$i] ?? ''
        ];
    }
    
    $task['assignees'] = $assignees;
}
unset($task); // Break the reference

// Get project statistics
$stats_query = "
    SELECT 
        COUNT(t.id) as total_tasks,
        SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) as completed_tasks,
        COUNT(DISTINCT b.id) as total_bugs,
        AVG(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) * 100 as completion_rate,
        DATEDIFF(NOW(), p.created_at) as days_since_creation,
        p.duration_days
    FROM projects p
    LEFT JOIN tasks t ON p.id = t.project_id
    LEFT JOIN bugs b ON t.id = b.task_id
    WHERE p.id = :project_id
";
$stats_stmt = $db->prepare($stats_query);
$stats_stmt->bindParam(':project_id', $project_id);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Calculate project progress and time statistics
$project_duration = $stats['duration_days'] ?? 0;
$days_since_creation = $stats['days_since_creation'] ?? 0;
$progress_percentage = $project_duration > 0 ? min(100, ($days_since_creation / $project_duration) * 100) : 0;
$remaining_days = max(0, $project_duration - $days_since_creation);

// Format duration for display
$duration_text = '';
if ($project['duration_days']) {
    if ($project['duration_days'] % 30 === 0) {
        $months = $project['duration_days'] / 30;
        $duration_text = $months . ($months == 1 ? ' month' : ' months');
    } elseif ($project['duration_days'] % 7 === 0) {
        $weeks = $project['duration_days'] / 7;
        $duration_text = $weeks . ($weeks == 1 ? ' week' : ' weeks');
    } else {
        $duration_text = $project['duration_days'] . ($project['duration_days'] == 1 ? ' day' : ' days');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($project['name']) ?> - Task Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.bootstrap5.min.css" rel="stylesheet">
    <!-- Chart.js for progress visualization -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .assignee-avatar {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
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
        .table td {
            vertical-align: middle;
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
                                            <strong>Duration:</strong> <?= $duration_text ?><br>
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
                                        
                                        <!-- Time Progress -->
                                        <div class="mt-2">
                                            <small class="text-muted">
                                                <strong>Project Progress:</strong><br>
                                                <div class="progress mt-1" style="height: 8px;">
                                                    <div class="progress-bar bg-<?= 
                                                        $progress_percentage < 50 ? 'success' : 
                                                        ($progress_percentage < 80 ? 'warning' : 'danger')
                                                    ?>" role="progressbar" 
                                                    style="width: <?= $progress_percentage ?>%;" 
                                                    aria-valuenow="<?= $progress_percentage ?>" 
                                                    aria-valuemin="0" aria-valuemax="100"></div>
                                                </div>
                                                <small>
                                                    <?= number_format($progress_percentage, 1) ?>% complete • 
                                                    <?= $remaining_days ?> <?= $remaining_days == 1 ? 'day' : 'days' ?> remaining
                                                </small>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 text-end">
                                <span class="badge bg-<?= 
                                    $project['status'] == 'active' ? 'success' : 
                                    ($project['status'] == 'completed' ? 'info' : 'secondary') 
                                ?> fs-6">
                                    <?= ucfirst($project['status']) ?>
                                </span>
                                
                                <div class="mt-3">
                                    <?php if ($_SESSION['user_role'] == 'manager'): ?>
                                    <a href="projects.php" class="btn btn-outline-primary">
                                        <i class="fas fa-edit"></i> Edit Project
                                    </a>
                                    <?php endif; ?>
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
                        <div class="card text-center h-100">
                            <div class="card-body">
                                <h3 class="text-primary"><?= $stats['total_tasks'] ?></h3>
                                <p class="text-muted">Total Tasks</p>
                                <?php if ($stats['total_tasks'] > 0): ?>
                                <small class="text-success">
                                    <?= $stats['completed_tasks'] ?> completed
                                </small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center h-100">
                            <div class="card-body">
                                <h3 class="text-success"><?= $stats['completed_tasks'] ?></h3>
                                <p class="text-muted">Completed Tasks</p>
                                <?php if ($stats['total_tasks'] > 0): ?>
                                <small class="text-info">
                                    <?= number_format(($stats['completed_tasks'] / $stats['total_tasks']) * 100, 1) ?>% completion
                                </small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center h-100">
                            <div class="card-body">
                                <h3 class="text-danger"><?= $stats['total_bugs'] ?></h3>
                                <p class="text-muted">Total Bugs</p>
                                <?php if ($stats['total_tasks'] > 0): ?>
                                <small class="text-muted">
                                    <?= number_format(($stats['total_bugs'] / max(1, $stats['total_tasks'])), 1) ?> bugs per task
                                </small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center h-100">
                            <div class="card-body">
                                <h3 class="text-info"><?= number_format($stats['completion_rate'], 1) ?>%</h3>
                                <p class="text-muted">Completion Rate</p>
                                <small class="text-muted">
                                    <i class="fas fa-calendar-alt"></i> <?= $days_since_creation ?> days running
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Time Progress Chart -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Project Timeline</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <canvas id="timeProgressChart" height="100"></canvas>
                            </div>
                            <div class="col-md-6">
                                <div class="row">
                                    <div class="col-6">
                                        <div class="card bg-light">
                                            <div class="card-body text-center">
                                                <h4 class="text-success"><?= $project['duration_days'] ?></h4>
                                                <p class="text-muted mb-0">Planned Duration (days)</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="card bg-light">
                                            <div class="card-body text-center">
                                                <h4 class="text-info"><?= $days_since_creation ?></h4>
                                                <p class="text-muted mb-0">Days Since Creation</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6 mt-3">
                                        <div class="card bg-light">
                                            <div class="card-body text-center">
                                                <h4 class="text-warning"><?= $remaining_days ?></h4>
                                                <p class="text-muted mb-0">Days Remaining</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6 mt-3">
                                        <div class="card bg-light">
                                            <div class="card-body text-center">
                                                <h4 class="text-primary"><?= number_format($progress_percentage, 1) ?>%</h4>
                                                <p class="text-muted mb-0">Time Progress</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tasks Table -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Project Tasks</h5>
                        <div>
                            <a href="tasks.php?project_filter=<?= $project_id ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-filter"></i> Filter by Project
                            </a>
                            <?php if ($_SESSION['user_role'] == 'manager'): ?>
                            <a href="tasks.php?create_task=1&project_id=<?= $project_id ?>" class="btn btn-sm btn-success">
                                <i class="fas fa-plus"></i> Add Task
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($tasks)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> No tasks found for this project. 
                                <?php if ($_SESSION['user_role'] == 'manager'): ?>
                                <a href="tasks.php?create_task=1&project_id=<?= $project_id ?>" class="alert-link">Create the first task</a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table id="tasksTable" class="table table-striped table-hover w-100">
                                    <thead class="table-dark">
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
                                                <span class="badge bg-<?= 
                                                    $task['status'] == 'open' ? 'secondary' : 
                                                    ($task['status'] == 'in_progress' ? 'warning' : 
                                                    ($task['status'] == 'completed' ? 'success' : 
                                                    ($task['status'] == 'closed' ? 'info' : 'light'))) 
                                                ?>">
                                                    <?= ucfirst(str_replace('_', ' ', $task['status'])) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="assignees-container">
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
                                            </td>
                                            <td>
                                                <?php if ($task['start_datetime']): ?>
                                                    <?= date('M j, Y', strtotime($task['start_datetime'])) ?>
                                                    <br><small class="text-muted"><?= date('g:i A', strtotime($task['start_datetime'])) ?></small>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($task['end_datetime']): ?>
                                                    <?php if (strtotime($task['end_datetime']) < time() && $task['status'] != 'completed' && $task['status'] != 'closed'): ?>
                                                        <span class="text-danger">
                                                            <?= date('M j, Y', strtotime($task['end_datetime'])) ?>
                                                            <br><small>Overdue</small>
                                                        </span>
                                                    <?php else: ?>
                                                        <?= date('M j, Y', strtotime($task['end_datetime'])) ?>
                                                        <br><small class="text-muted"><?= date('g:i A', strtotime($task['end_datetime'])) ?></small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($task['bug_count'] > 0): ?>
                                                    <a href="bugs.php?task_filter=<?= $task['id'] ?>" class="badge bg-danger text-decoration-none">
                                                        <i class="fas fa-bug"></i> <?= $task['bug_count'] ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group">
                                                    <a href="task_details.php?id=<?= $task['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                        <i class="fas fa-eye"></i> View
                                                    </a>
                                                    <?php if ($_SESSION['user_role'] == 'manager'): ?>
                                                    <a href="tasks.php?edit_task=<?= $task['id'] ?>" class="btn btn-sm btn-outline-warning">
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
                        <?php endif; ?>
                    </div>
                </div>
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
        $(document).ready(function() {
            $('#tasksTable').DataTable({
                responsive: true,
                pageLength: 10,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                order: [[5, 'desc']], // Sort by Created At descending by default
                language: {
                    search: "Search tasks:",
                    lengthMenu: "Show _MENU_ tasks",
                    info: "Showing _START_ to _END_ of _TOTAL_ tasks",
                    paginate: {
                        first: "First",
                        last: "Last",
                        next: "Next",
                        previous: "Previous"
                    }
                },
                columnDefs: [
                    {
                        targets: [0, 1, 2, 3, 4, 5, 6],
                        orderable: true
                    },
                    {
                        targets: [6], // Actions column
                        orderable: false,
                        searchable: false
                    }
                ]
            });
        });

        document.addEventListener('DOMContentLoaded', function() {
            // Time Progress Chart
            const timeCtx = document.getElementById('timeProgressChart').getContext('2d');
            const timeProgressChart = new Chart(timeCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Days Passed', 'Days Remaining'],
                    datasets: [{
                        data: [<?= $days_since_creation ?>, <?= $remaining_days ?>],
                        backgroundColor: [
                            'rgba(54, 162, 235, 0.8)',
                            'rgba(255, 206, 86, 0.8)'
                        ],
                        borderColor: [
                            'rgba(54, 162, 235, 1)',
                            'rgba(255, 206, 86, 1)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom',
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    label += context.parsed + ' days';
                                    return label;
                                }
                            }
                        }
                    }
                }
            });
            
            // Status Badge Colors
            const statusBadges = document.querySelectorAll('.status-badge');
            statusBadges.forEach(badge => {
                const status = badge.textContent.trim().toLowerCase();
                let bgClass = 'bg-secondary';
                
                switch(status) {
                    case 'open':
                        bgClass = 'bg-secondary';
                        break;
                    case 'in progress':
                        bgClass = 'bg-warning';
                        break;
                    case 'completed':
                        bgClass = 'bg-success';
                        break;
                    case 'closed':
                        bgClass = 'bg-info';
                        break;
                }
                
                badge.classList.add(bgClass);
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