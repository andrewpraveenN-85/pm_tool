<?php
include 'config/database.php';
include 'includes/auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireAuth();

$user_id = $_SESSION['user_id'];

// Get user performance data
$performance_query = "
    SELECT 
        -- Task Statistics
        COUNT(DISTINCT t.id) as total_tasks,
        SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) as completed_tasks,
        SUM(CASE WHEN t.status != 'closed' AND t.end_datetime < NOW() THEN 1 ELSE 0 END) as overdue_tasks,
        AVG(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) * 100 as completion_rate,
        
        -- Time Statistics
        AVG(TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at)) as avg_completion_hours,
        MIN(TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at)) as fastest_completion,
        MAX(TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at)) as slowest_completion,
        
        -- Bug Statistics
        COUNT(DISTINCT b.id) as bugs_reported,
        COUNT(DISTINCT CASE WHEN b.created_by = :user_id THEN b.id END) as bugs_created
        
    FROM task_assignments ta
    LEFT JOIN tasks t ON ta.task_id = t.id
    LEFT JOIN bugs b ON t.id = b.task_id
    WHERE ta.user_id = :user_id2
";

$performance_stmt = $db->prepare($performance_query);
$performance_stmt->bindParam(':user_id', $user_id);
$performance_stmt->bindParam(':user_id2', $user_id);
$performance_stmt->execute();
$performance = $performance_stmt->fetch(PDO::FETCH_ASSOC);

// Get recent tasks
$recent_tasks_query = "
    SELECT t.*, p.name as project_name, 
           COUNT(DISTINCT b.id) as bug_count
    FROM task_assignments ta
    LEFT JOIN tasks t ON ta.task_id = t.id
    LEFT JOIN projects p ON t.project_id = p.id
    LEFT JOIN bugs b ON t.id = b.task_id
    WHERE ta.user_id = :user_id
    GROUP BY t.id
    ORDER BY t.updated_at DESC
    LIMIT 10
";

$recent_tasks_stmt = $db->prepare($recent_tasks_query);
$recent_tasks_stmt->bindParam(':user_id', $user_id);
$recent_tasks_stmt->execute();
$recent_tasks = $recent_tasks_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get task distribution by status
$status_distribution_query = "
    SELECT 
        t.status,
        COUNT(*) as count
    FROM task_assignments ta
    LEFT JOIN tasks t ON ta.task_id = t.id
    WHERE ta.user_id = :user_id
    GROUP BY t.status
";

$status_distribution_stmt = $db->prepare($status_distribution_query);
$status_distribution_stmt->bindParam(':user_id', $user_id);
$status_distribution_stmt->execute();
$status_distribution = $status_distribution_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get monthly performance
$monthly_performance_query = "
    SELECT 
        DATE_FORMAT(t.created_at, '%Y-%m') as month,
        COUNT(*) as tasks_assigned,
        SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) as tasks_completed
    FROM task_assignments ta
    LEFT JOIN tasks t ON ta.task_id = t.id
    WHERE ta.user_id = :user_id AND t.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(t.created_at, '%Y-%m')
    ORDER BY month DESC
";

$monthly_performance_stmt = $db->prepare($monthly_performance_query);
$monthly_performance_stmt->bindParam(':user_id', $user_id);
$monthly_performance_stmt->execute();
$monthly_performance = $monthly_performance_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Performance - Task Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <h2 class="mb-4">My Performance Dashboard</h2>

                <!-- Performance Overview -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card text-center bg-primary text-white">
                            <div class="card-body">
                                <h3><?= $performance['total_tasks'] ?></h3>
                                <p class="mb-0">Total Tasks</p>
                                <small><?= $performance['completed_tasks'] ?> completed</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center bg-success text-white">
                            <div class="card-body">
                                <h3><?= number_format($performance['completion_rate'], 1) ?>%</h3>
                                <p class="mb-0">Completion Rate</p>
                                <small>Overall Success</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center bg-warning text-dark">
                            <div class="card-body">
                                <h3><?= $performance['overdue_tasks'] ?></h3>
                                <p class="mb-0">Overdue Tasks</p>
                                <small>Requires attention</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center bg-info text-white">
                            <div class="card-body">
                                <h3><?= $performance['bugs_reported'] ?></h3>
                                <p class="mb-0">Bugs in Tasks</p>
                                <small><?= $performance['bugs_created'] ?> reported by me</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Task Status Distribution -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Task Status Distribution</h5>
                            </div>
                            <div class="card-body">
                                <canvas id="statusChart" height="250"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Monthly Performance -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Monthly Performance</h5>
                            </div>
                            <div class="card-body">
                                <canvas id="monthlyChart" height="250"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Tasks -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Recent Tasks</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Task Name</th>
                                        <th>Project</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th>Due Date</th>
                                        <th>Bugs</th>
                                        <th>Last Updated</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_tasks as $task): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($task['name']) ?></strong>
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
                                            <span class="badge bg-secondary">
                                                <?= ucfirst(str_replace('_', ' ', $task['status'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($task['end_datetime']): ?>
                                                <?php 
                                                $end_date = strtotime($task['end_datetime']);
                                                $now = time();
                                                $diff = $end_date - $now;
                                                $days = floor($diff / (60 * 60 * 24));
                                                
                                                $class = 'text-muted';
                                                if ($days < 0) {
                                                    $class = 'text-danger fw-bold';
                                                } elseif ($days <= 2) {
                                                    $class = 'text-warning fw-bold';
                                                }
                                                ?>
                                                <span class="<?= $class ?>">
                                                    <?= date('M j, Y', $end_date) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">Not set</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($task['bug_count'] > 0): ?>
                                                <span class="badge bg-danger"><?= $task['bug_count'] ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= date('M j, Y', strtotime($task['updated_at'])) ?></td>
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

    <script>
        // Status Distribution Chart
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        const statusChart = new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_column($status_distribution, 'status')) ?>,
                datasets: [{
                    data: <?= json_encode(array_column($status_distribution, 'count')) ?>,
                    backgroundColor: [
                        '#ff6384', '#36a2eb', '#ffce56', '#4bc0c0', '#9966ff', '#ff9f40'
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    },
                    title: {
                        display: true,
                        text: 'My Task Status Distribution'
                    }
                }
            }
        });

        // Monthly Performance Chart
        const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
        const monthlyChart = new Chart(monthlyCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($monthly_performance, 'month')) ?>,
                datasets: [
                    {
                        label: 'Tasks Assigned',
                        data: <?= json_encode(array_column($monthly_performance, 'tasks_assigned')) ?>,
                        backgroundColor: '#36a2eb'
                    },
                    {
                        label: 'Tasks Completed',
                        data: <?= json_encode(array_column($monthly_performance, 'tasks_completed')) ?>,
                        backgroundColor: '#4bc0c0'
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: 'Monthly Task Performance'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
<footer class="bg-dark text-light text-center py-3 mt-5">
    <div class="container">
        <p class="mb-0">Developed by APNLAB. 2025.</p>
    </div>
</footer>
</html>