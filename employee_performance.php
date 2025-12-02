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

// Get all employees performance
$performance_query = "
    SELECT 
        u.id,
        u.name,
        u.email,
        u.role,
        COUNT(DISTINCT t.id) as total_tasks,
        SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) as completed_tasks,
        AVG(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) * 100 as completion_rate,
        AVG(TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at)) as avg_completion_hours,
        MIN(TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at)) as fastest_completion,
        MAX(TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at)) as slowest_completion,
        COUNT(DISTINCT b.id) as total_bugs_in_tasks,
        COUNT(DISTINCT CASE WHEN b.created_by = u.id THEN b.id END) as bugs_reported,
        COUNT(DISTINCT CASE WHEN b.status = 'closed' AND b.created_by = u.id THEN b.id END) as bugs_resolved,
        SUM(CASE WHEN t.status != 'closed' AND t.end_datetime < NOW() THEN 1 ELSE 0 END) as overdue_tasks
    FROM users u
    LEFT JOIN task_assignments ta ON u.id = ta.user_id
    LEFT JOIN tasks t ON ta.task_id = t.id AND t.created_at BETWEEN :start_date AND :end_date
    LEFT JOIN bugs b ON t.id = b.task_id
    WHERE u.role IN ('developer', 'qa') AND u.status = 'active'
    GROUP BY u.id, u.name, u.email, u.role
    ORDER BY completion_rate DESC, total_tasks DESC
";

$stmt = $db->prepare($performance_query);
$stmt->bindParam(':start_date', $start_date);
$stmt->bindParam(':end_date', $end_date);
$stmt->execute();
$performance_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get summary statistics
$summary_query = "
    SELECT 
        COUNT(DISTINCT u.id) as total_employees,
        AVG(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) * 100 as avg_completion_rate,
        SUM(CASE WHEN t.status != 'closed' AND t.end_datetime < NOW() THEN 1 ELSE 0 END) as total_overdue,
        COUNT(DISTINCT b.id) as total_bugs,
        AVG(TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at)) as overall_avg_time
    FROM users u
    LEFT JOIN task_assignments ta ON u.id = ta.user_id
    LEFT JOIN tasks t ON ta.task_id = t.id AND t.created_at BETWEEN :start_date AND :end_date
    LEFT JOIN bugs b ON t.id = b.task_id
    WHERE u.role IN ('developer', 'qa') AND u.status = 'active'
";

$summary_stmt = $db->prepare($summary_query);
$summary_stmt->bindParam(':start_date', $start_date);
$summary_stmt->bindParam(':end_date', $end_date);
$summary_stmt->execute();
$summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Performance Summary</title>
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
        .performance-excellent { background-color: #d4edda !important; }
        .performance-good { background-color: #fff3cd !important; }
        .performance-average { background-color: #f8d7da !important; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid mt-4">
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <h2>Employee Performance Summary</h2>
                    <button class="btn btn-success" onclick="window.print()">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </button>
                </div>
                <p class="text-muted">Overall performance metrics for all employees</p>
            </div>
        </div>

        <!-- Date Range Filter -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
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
                                <a href="employee_performance.php" class="btn btn-secondary">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card stat-card text-center bg-primary text-white">
                    <div class="card-body">
                        <h3><?= $summary['total_employees'] ?? 0 ?></h3>
                        <p class="mb-0">Total Employees</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card text-center bg-success text-white">
                    <div class="card-body">
                        <h3><?= number_format($summary['avg_completion_rate'] ?? 0, 1) ?>%</h3>
                        <p class="mb-0">Avg. Completion Rate</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card text-center bg-warning text-dark">
                    <div class="card-body">
                        <h3><?= $summary['total_overdue'] ?? 0 ?></h3>
                        <p class="mb-0">Total Overdue Tasks</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card text-center bg-info text-white">
                    <div class="card-body">
                        <h3><?= $summary['total_bugs'] ?? 0 ?></h3>
                        <p class="mb-0">Total Bugs</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Performance Table -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Individual Employee Performance</h5>
                <p class="mb-0 text-muted" style="font-size: 0.9rem;">Period: <?= date('M d, Y', strtotime($start_date)) ?> - <?= date('M d, Y', strtotime($end_date)) ?></p>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>Employee</th>
                                <th>Role</th>
                                <th>Tasks</th>
                                <th>Completion Rate</th>
                                <th>Avg. Time</th>
                                <th>Overdue</th>
                                <th>Bugs</th>
                                <th>Performance</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($performance_data as $employee): 
                                $performance_class = '';
                                $performance_rating = '';
                                $performance_color = '';
                                
                                if ($employee['completion_rate'] >= 80) {
                                    $performance_class = 'performance-excellent';
                                    $performance_rating = 'Excellent';
                                    $performance_color = 'success';
                                } elseif ($employee['completion_rate'] >= 60) {
                                    $performance_class = 'performance-good';
                                    $performance_rating = 'Good';
                                    $performance_color = 'warning';
                                } else {
                                    $performance_class = 'performance-average';
                                    $performance_rating = 'Needs Improvement';
                                    $performance_color = 'danger';
                                }
                            ?>
                            <tr class="<?= $performance_class ?>">
                                <td>
                                    <strong><?= htmlspecialchars($employee['name']) ?></strong>
                                    <br><small class="text-muted"><?= htmlspecialchars($employee['email']) ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $employee['role'] == 'developer' ? 'info' : 'warning' ?>">
                                        <?= ucfirst($employee['role']) ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?= $employee['total_tasks'] ?></strong>
                                    <br><small class="text-muted"><?= $employee['completed_tasks'] ?> completed</small>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <span class="me-2 fw-bold"><?= number_format($employee['completion_rate'], 1) ?>%</span>
                                        <div class="progress progress-thin flex-grow-1" style="width: 100px;">
                                            <div class="progress-bar bg-<?= $performance_color ?>" 
                                                 style="width: <?= min(100, $employee['completion_rate']) ?>%"></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($employee['avg_completion_hours']): ?>
                                        <?= number_format($employee['avg_completion_hours'] / 24, 1) ?> days
                                    <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($employee['overdue_tasks'] > 0): ?>
                                        <span class="badge bg-danger"><?= $employee['overdue_tasks'] ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-success">0</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?= 
                                        $employee['bugs_reported'] == 0 ? 'secondary' : 
                                        ($employee['bugs_reported'] <= 5 ? 'info' : 'warning')
                                    ?>">
                                        <?= $employee['bugs_reported'] ?> reported
                                        <?php if ($employee['bugs_resolved'] > 0): ?>
                                            <br><small><?= $employee['bugs_resolved'] ?> resolved</small>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $performance_color ?>">
                                        <?= $performance_rating ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="employee_tasks.php?employee_id=<?= $employee['id'] ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" 
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-eye"></i> View Tasks
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Performance Distribution</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="performanceChart" height="250"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Completion Time Analysis</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="timeChart" height="250"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Performance Distribution Chart
        const performanceCtx = document.getElementById('performanceChart').getContext('2d');
        
        // Calculate performance distribution
        const excellent = <?= count(array_filter($performance_data, fn($emp) => $emp['completion_rate'] >= 80)) ?>;
        const good = <?= count(array_filter($performance_data, fn($emp) => $emp['completion_rate'] >= 60 && $emp['completion_rate'] < 80)) ?>;
        const needsImprovement = <?= count(array_filter($performance_data, fn($emp) => $emp['completion_rate'] < 60)) ?>;
        
        const performanceChart = new Chart(performanceCtx, {
            type: 'doughnut',
            data: {
                labels: ['Excellent (80%+)', 'Good (60-79%)', 'Needs Improvement (<60%)'],
                datasets: [{
                    data: [excellent, good, needsImprovement],
                    backgroundColor: ['#28a745', '#ffc107', '#dc3545']
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
                        text: 'Performance Rating Distribution'
                    }
                }
            }
        });

        // Time Analysis Chart
        const timeCtx = document.getElementById('timeChart').getContext('2d');
        const employeeNames = <?= json_encode(array_column($performance_data, 'name')) ?>;
        const completionTimes = <?= json_encode(array_map(function($emp) {
            return $emp['avg_completion_hours'] ? ($emp['avg_completion_hours'] / 24).toFixed(1) : 0;
        }, $performance_data)) ?>;
        
        const timeChart = new Chart(timeCtx, {
            type: 'bar',
            data: {
                labels: employeeNames,
                datasets: [{
                    label: 'Average Completion Time (Days)',
                    data: completionTimes,
                    backgroundColor: '#36a2eb'
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: 'Average Task Completion Time by Employee'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Days'
                        }
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