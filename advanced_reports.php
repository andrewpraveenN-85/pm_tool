<?php
include 'config/database.php';
include 'includes/auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireRole(['manager']);

// Date range filter
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

// Get comprehensive statistics
$stats_query = "
    SELECT 
        -- Task Statistics
        COUNT(t.id) as total_tasks,
        SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) as completed_tasks,
        SUM(CASE WHEN t.status != 'closed' AND t.end_datetime < NOW() THEN 1 ELSE 0 END) as overdue_tasks,
        AVG(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) * 100 as completion_rate,
        
        -- Bug Statistics
        COUNT(b.id) as total_bugs,
        SUM(CASE WHEN b.status = 'closed' THEN 1 ELSE 0 END) as resolved_bugs,
        
        -- User Statistics
        COUNT(DISTINCT u.id) as total_users,
        COUNT(DISTINCT CASE WHEN u.role = 'developer' THEN u.id END) as developers,
        COUNT(DISTINCT CASE WHEN u.role = 'qa' THEN u.id END) as qa_users,
        
        -- Project Statistics
        COUNT(DISTINCT p.id) as total_projects,
        COUNT(DISTINCT CASE WHEN p.status = 'active' THEN p.id END) as active_projects
        
    FROM tasks t
    LEFT JOIN bugs b ON t.id = b.task_id
    LEFT JOIN users u ON 1=1
    LEFT JOIN projects p ON 1=1
    WHERE t.created_at BETWEEN :start_date AND :end_date
";

$stats_stmt = $db->prepare($stats_query);
$stats_stmt->bindParam(':start_date', $start_date);
$stats_stmt->bindParam(':end_date', $end_date);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Get task trends by week
$trends_query = "
    SELECT 
        YEARWEEK(t.created_at) as week,
        COUNT(t.id) as tasks_created,
        SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) as tasks_completed,
        COUNT(b.id) as bugs_reported
    FROM tasks t
    LEFT JOIN bugs b ON t.id = b.task_id AND b.created_at BETWEEN :start_date AND :end_date
    WHERE t.created_at BETWEEN :start_date2 AND :end_date2
    GROUP BY YEARWEEK(t.created_at)
    ORDER BY week DESC
    LIMIT 12
";

$trends_stmt = $db->prepare($trends_query);
$trends_stmt->bindParam(':start_date', $start_date);
$trends_stmt->bindParam(':end_date', $end_date);
$trends_stmt->bindParam(':start_date2', $start_date);
$trends_stmt->bindParam(':end_date2', $end_date);
$trends_stmt->execute();
$trends = $trends_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get developer performance
$dev_performance_query = "
    SELECT 
        u.id,
        u.name,
        COUNT(DISTINCT t.id) as total_tasks,
        SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) as completed_tasks,
        AVG(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) * 100 as completion_rate,
        COUNT(DISTINCT b.id) as bugs_assigned,
        AVG(TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at)) as avg_completion_hours
    FROM users u
    LEFT JOIN task_assignments ta ON u.id = ta.user_id
    LEFT JOIN tasks t ON ta.task_id = t.id AND t.created_at BETWEEN :start_date AND :end_date
    LEFT JOIN bugs b ON t.id = b.task_id
    WHERE u.role = 'developer' AND u.status = 'active'
    GROUP BY u.id, u.name
    ORDER BY completion_rate DESC
";

$dev_performance_stmt = $db->prepare($dev_performance_query);
$dev_performance_stmt->bindParam(':start_date', $start_date);
$dev_performance_stmt->bindParam(':end_date', $end_date);
$dev_performance_stmt->execute();
$dev_performance = $dev_performance_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get project performance
$project_performance_query = "
    SELECT 
        p.id,
        p.name,
        p.status,
        COUNT(DISTINCT t.id) as total_tasks,
        SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) as completed_tasks,
        AVG(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) * 100 as completion_rate,
        COUNT(DISTINCT b.id) as total_bugs,
        SUM(CASE WHEN b.status = 'closed' THEN 1 ELSE 0 END) as resolved_bugs,
        COUNT(DISTINCT ta.user_id) as team_size
    FROM projects p
    LEFT JOIN tasks t ON p.id = t.project_id
    LEFT JOIN bugs b ON t.id = b.task_id
    LEFT JOIN task_assignments ta ON t.id = ta.task_id
    WHERE p.created_at BETWEEN :start_date AND :end_date
    GROUP BY p.id, p.name, p.status
    ORDER BY completion_rate DESC
";

$project_performance_stmt = $db->prepare($project_performance_query);
$project_performance_stmt->bindParam(':start_date', $start_date);
$project_performance_stmt->bindParam(':end_date', $end_date);
$project_performance_stmt->execute();
$project_performance = $project_performance_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advanced Reports - Task Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .stat-card {
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .progress-thin {
            height: 8px;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>Advanced Analytics & Reports</h2>
                    <button class="btn btn-success" onclick="exportToPDF()">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </button>
                </div>

                <!-- Date Range Filter -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Report Period</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Start Date</label>
                                <input type="date" class="form-control" name="start_date" value="<?= $start_date ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">End Date</label>
                                <input type="date" class="form-control" name="end_date" value="<?= $end_date ?>">
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary me-2">Apply Filter</button>
                                <a href="advanced_reports.php" class="btn btn-secondary">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Key Metrics -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card stat-card text-center bg-primary text-white">
                            <div class="card-body">
                                <h3><?= $stats['total_tasks'] ?></h3>
                                <p class="mb-0">Total Tasks</p>
                                <small><?= $stats['completed_tasks'] ?> completed</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card text-center bg-success text-white">
                            <div class="card-body">
                                <h3><?= number_format($stats['completion_rate'], 1) ?>%</h3>
                                <p class="mb-0">Completion Rate</p>
                                <small>Task Success</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card text-center bg-warning text-dark">
                            <div class="card-body">
                                <h3><?= $stats['total_bugs'] ?></h3>
                                <p class="mb-0">Bugs Reported</p>
                                <small><?= $stats['resolved_bugs'] ?> resolved</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card text-center bg-info text-white">
                            <div class="card-body">
                                <h3><?= $stats['overdue_tasks'] ?></h3>
                                <p class="mb-0">Overdue Tasks</p>
                                <small>Requires attention</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Trends Chart -->
                    <div class="col-md-8 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Performance Trends</h5>
                            </div>
                            <div class="card-body">
                                <canvas id="trendsChart" height="250"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Project Distribution -->
                    <div class="col-md-4 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Project Distribution</h5>
                            </div>
                            <div class="card-body">
                                <canvas id="projectChart" height="250"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Developer Performance -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Developer Performance</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Developer</th>
                                        <th>Total Tasks</th>
                                        <th>Completed</th>
                                        <th>Completion Rate</th>
                                        <th>Bugs Assigned</th>
                                        <th>Avg. Completion Time</th>
                                        <th>Performance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dev_performance as $dev): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($dev['name']) ?></strong>
                                        </td>
                                        <td><?= $dev['total_tasks'] ?></td>
                                        <td><?= $dev['completed_tasks'] ?></td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <span class="me-2"><?= number_format($dev['completion_rate'], 1) ?>%</span>
                                                <div class="progress progress-thin flex-grow-1" style="width: 100px;">
                                                    <div class="progress-bar bg-<?= 
                                                        $dev['completion_rate'] >= 80 ? 'success' : 
                                                        ($dev['completion_rate'] >= 60 ? 'warning' : 'danger')
                                                    ?>" style="width: <?= $dev['completion_rate'] ?>%"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?= $dev['bugs_assigned'] ?></td>
                                        <td>
                                            <?php if ($dev['avg_completion_hours']): ?>
                                                <?= number_format($dev['avg_completion_hours'] / 24, 1) ?> days
                                            <?php else: ?>
                                                N/A
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= 
                                                $dev['completion_rate'] >= 80 ? 'success' : 
                                                ($dev['completion_rate'] >= 60 ? 'warning' : 'danger')
                                            ?>">
                                                <?= 
                                                    $dev['completion_rate'] >= 80 ? 'Excellent' : 
                                                    ($dev['completion_rate'] >= 60 ? 'Good' : 'Needs Improvement')
                                                ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Project Performance -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Project Performance</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Project</th>
                                        <th>Status</th>
                                        <th>Total Tasks</th>
                                        <th>Completed</th>
                                        <th>Completion Rate</th>
                                        <th>Bugs</th>
                                        <th>Team Size</th>
                                        <th>Health</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($project_performance as $project): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($project['name']) ?></strong>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= 
                                                $project['status'] == 'active' ? 'success' : 
                                                ($project['status'] == 'completed' ? 'info' : 'secondary')
                                            ?>">
                                                <?= ucfirst($project['status']) ?>
                                            </span>
                                        </td>
                                        <td><?= $project['total_tasks'] ?></td>
                                        <td><?= $project['completed_tasks'] ?></td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <span class="me-2"><?= number_format($project['completion_rate'], 1) ?>%</span>
                                                <div class="progress progress-thin flex-grow-1" style="width: 100px;">
                                                    <div class="progress-bar bg-<?= 
                                                        $project['completion_rate'] >= 80 ? 'success' : 
                                                        ($project['completion_rate'] >= 60 ? 'warning' : 'danger')
                                                    ?>" style="width: <?= $project['completion_rate'] ?>%"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= 
                                                $project['total_bugs'] == 0 ? 'success' : 
                                                ($project['total_bugs'] <= 5 ? 'warning' : 'danger')
                                            ?>">
                                                <?= $project['total_bugs'] ?> bugs
                                            </span>
                                        </td>
                                        <td><?= $project['team_size'] ?> members</td>
                                        <td>
                                            <span class="badge bg-<?= 
                                                $project['completion_rate'] >= 80 && $project['total_bugs'] <= 5 ? 'success' : 
                                                ($project['completion_rate'] >= 60 ? 'warning' : 'danger')
                                            ?>">
                                                <?= 
                                                    $project['completion_rate'] >= 80 && $project['total_bugs'] <= 5 ? 'Healthy' : 
                                                    ($project['completion_rate'] >= 60 ? 'Moderate' : 'Critical')
                                                ?>
                                            </span>
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

    <script>
        // Trends Chart
        const trendsCtx = document.getElementById('trendsChart').getContext('2d');
        const trendsChart = new Chart(trendsCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode(array_column($trends, 'week')) ?>,
                datasets: [
                    {
                        label: 'Tasks Created',
                        data: <?= json_encode(array_column($trends, 'tasks_created')) ?>,
                        borderColor: '#36a2eb',
                        backgroundColor: 'rgba(54, 162, 235, 0.1)',
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Tasks Completed',
                        data: <?= json_encode(array_column($trends, 'tasks_completed')) ?>,
                        borderColor: '#4bc0c0',
                        backgroundColor: 'rgba(75, 192, 192, 0.1)',
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Bugs Reported',
                        data: <?= json_encode(array_column($trends, 'bugs_reported')) ?>,
                        borderColor: '#ff6384',
                        backgroundColor: 'rgba(255, 99, 132, 0.1)',
                        tension: 0.4,
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: 'Weekly Performance Trends'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        // Project Distribution Chart
        const projectCtx = document.getElementById('projectChart').getContext('2d');
        const projectChart = new Chart(projectCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_column($project_performance, 'name')) ?>,
                datasets: [{
                    data: <?= json_encode(array_column($project_performance, 'total_tasks')) ?>,
                    backgroundColor: [
                        '#ff6384', '#36a2eb', '#ffce56', '#4bc0c0', '#9966ff', '#ff9f40',
                        '#ff6384', '#36a2eb', '#ffce56', '#4bc0c0'
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
                        text: 'Task Distribution by Project'
                    }
                }
            }
        });

        function exportToPDF() {
            // Simple PDF export - in production, use a proper PDF library
            window.print();
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
<footer class="bg-dark text-light text-center py-3 mt-5">
    <div class="container">
        <p class="mb-0">Developed by APNLAB. 2025.</p>
    </div>
</footer>
</html>