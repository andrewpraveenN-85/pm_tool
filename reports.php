<?php
include 'config/database.php';
include 'includes/auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireRole(['manager']);

// Get statistics for charts
$task_stats = $db->query("
    SELECT status, COUNT(*) as count 
    FROM tasks 
    GROUP BY status
")->fetchAll(PDO::FETCH_ASSOC);

$bug_stats = $db->query("
    SELECT status, COUNT(*) as count 
    FROM bugs 
    GROUP BY status
")->fetchAll(PDO::FETCH_ASSOC);

$project_stats = $db->query("
    SELECT p.name, 
           COUNT(t.id) as task_count,
           SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) as completed_tasks,
           COUNT(b.id) as bug_count
    FROM projects p
    LEFT JOIN tasks t ON p.id = t.project_id
    LEFT JOIN bugs b ON t.id = b.task_id
    GROUP BY p.id, p.name
")->fetchAll(PDO::FETCH_ASSOC);

// Overdue tasks - updated calculation
$overdue_tasks = $db->query("
    SELECT t.*, p.name as project_name 
    FROM tasks t 
    LEFT JOIN projects p ON t.project_id = p.id 
    WHERE (
        -- Tasks closed after the deadline
        (t.status = 'closed' AND t.updated_at > t.end_datetime)
        OR 
        -- Tasks not closed and past the deadline
        (t.status != 'closed' AND t.end_datetime < NOW())
    )
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Task Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid mt-4">
        <h2>Performance Reports</h2>
        
        <div class="row">
            <!-- Task Status Chart -->
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5>Task Status Distribution</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="taskStatusChart" width="400" height="200"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Bug Status Chart -->
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5>Bug Status Distribution</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="bugStatusChart" width="400" height="200"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Project Performance -->
            <div class="col-12 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5>Project Performance</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="projectChart" width="400" height="200"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Overdue Tasks -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5>Overdue Tasks</h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($overdue_tasks) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Task Name</th>
                                            <th>Project</th>
                                            <th>Priority</th>
                                            <th>Due Date</th>
                                            <th>Closed Date</th>
                                            <th>Status</th>
                                            <th>Days Overdue</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($overdue_tasks as $task): 
                                            $end_date = strtotime($task['end_datetime']);
                                            $days_overdue = 0;
                                            $closed_date = '';
                                            
                                            if ($task['status'] == 'closed') {
                                                $updated_at = strtotime($task['updated_at']);
                                                $days_overdue = floor(($updated_at - $end_date) / (60 * 60 * 24));
                                                $closed_date = date('M j, Y', $updated_at);
                                            } else {
                                                $now = time();
                                                $days_overdue = floor(($now - $end_date) / (60 * 60 * 24));
                                            }
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars($task['name']) ?></td>
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
                                            <td class="text-muted"><?= date('M j, Y', $end_date) ?></td>
                                            <td>
                                                <?php if ($task['status'] == 'closed'): ?>
                                                    <span class="text-danger"><?= $closed_date ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">Not closed</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?= 
                                                    $task['status'] == 'closed' ? 'success' : 
                                                    ($task['status'] == 'in_progress' ? 'primary' : 
                                                    ($task['status'] == 'todo' ? 'warning' : 'info'))
                                                ?>">
                                                    <?= ucfirst(str_replace('_', ' ', $task['status'])) ?>
                                                </span>
                                            </td>
                                            <td class="text-danger fw-bold"><?= $days_overdue ?> days</td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-muted">No overdue tasks found.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Task Status Chart
        const taskCtx = document.getElementById('taskStatusChart').getContext('2d');
        const taskStatusChart = new Chart(taskCtx, {
            type: 'pie',
            data: {
                labels: <?= json_encode(array_column($task_stats, 'status')) ?>,
                datasets: [{
                    data: <?= json_encode(array_column($task_stats, 'count')) ?>,
                    backgroundColor: [
                        '#ff6384', '#36a2eb', '#ffce56', '#4bc0c0', '#9966ff', '#ff9f40'
                    ]
                }]
            }
        });

        // Bug Status Chart
        const bugCtx = document.getElementById('bugStatusChart').getContext('2d');
        const bugStatusChart = new Chart(bugCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_column($bug_stats, 'status')) ?>,
                datasets: [{
                    data: <?= json_encode(array_column($bug_stats, 'count')) ?>,
                    backgroundColor: [
                        '#ff6384', '#36a2eb', '#ffce56', '#4bc0c0'
                    ]
                }]
            }
        });

        // Project Performance Chart
        const projectCtx = document.getElementById('projectChart').getContext('2d');
        const projectChart = new Chart(projectCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($project_stats, 'name')) ?>,
                datasets: [
                    {
                        label: 'Total Tasks',
                        data: <?= json_encode(array_column($project_stats, 'task_count')) ?>,
                        backgroundColor: '#36a2eb'
                    },
                    {
                        label: 'Completed Tasks',
                        data: <?= json_encode(array_column($project_stats, 'completed_tasks')) ?>,
                        backgroundColor: '#4bc0c0'
                    },
                    {
                        label: 'Bugs',
                        data: <?= json_encode(array_column($project_stats, 'bug_count')) ?>,
                        backgroundColor: '#ff6384'
                    }
                ]
            },
            options: {
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    </script>

    <!-- Add Bootstrap JS for dropdown functionality -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
<footer class="bg-dark text-light text-center py-3 mt-5">
    <div class="container">
        <p class="mb-0">Developed by APNLAB. 2025.</p>
    </div>
</footer>
</html>