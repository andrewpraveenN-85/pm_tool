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
$employee_performance_query = "
    SELECT 
        u.id,
        u.name,
        u.email,
        u.role,
        u.status,
        
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
        COUNT(DISTINCT b.id) as total_bugs_in_tasks,
        COUNT(DISTINCT CASE WHEN b.created_by = u.id THEN b.id END) as bugs_reported,
        COUNT(DISTINCT CASE WHEN b.status = 'closed' AND b.created_by = u.id THEN b.id END) as bugs_resolved,
        
        -- Activity Statistics
        COUNT(DISTINCT c.id) as comments_made,
        COUNT(DISTINCT al.id) as activities_logged
        
    FROM users u
    LEFT JOIN task_assignments ta ON u.id = ta.user_id
    LEFT JOIN tasks t ON ta.task_id = t.id AND t.created_at BETWEEN :start_date AND :end_date
    LEFT JOIN bugs b ON t.id = b.task_id
    LEFT JOIN comments c ON u.id = c.user_id AND c.created_at BETWEEN :start_date2 AND :end_date2
    LEFT JOIN activity_logs al ON u.id = al.user_id AND al.created_at BETWEEN :start_date3 AND :end_date3
    WHERE u.role IN ('developer', 'qa') AND u.status = 'active'
    GROUP BY u.id, u.name, u.email, u.role, u.status
    ORDER BY completion_rate DESC, total_tasks DESC
";

$employee_performance_stmt = $db->prepare($employee_performance_query);
$employee_performance_stmt->bindParam(':start_date', $start_date);
$employee_performance_stmt->bindParam(':end_date', $end_date);
$employee_performance_stmt->bindParam(':start_date2', $start_date);
$employee_performance_stmt->bindParam(':end_date2', $end_date);
$employee_performance_stmt->bindParam(':start_date3', $start_date);
$employee_performance_stmt->bindParam(':end_date3', $end_date);
$employee_performance_stmt->execute();
$employee_performance = $employee_performance_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get performance by role
$role_performance_query = "
    SELECT 
        u.role,
        COUNT(DISTINCT u.id) as employee_count,
        COUNT(DISTINCT t.id) as total_tasks,
        AVG(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) * 100 as avg_completion_rate,
        AVG(TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at)) as avg_completion_hours
    FROM users u
    LEFT JOIN task_assignments ta ON u.id = ta.user_id
    LEFT JOIN tasks t ON ta.task_id = t.id AND t.created_at BETWEEN :start_date AND :end_date
    WHERE u.role IN ('developer', 'qa') AND u.status = 'active'
    GROUP BY u.role
";

$role_performance_stmt = $db->prepare($role_performance_query);
$role_performance_stmt->bindParam(':start_date', $start_date);
$role_performance_stmt->bindParam(':end_date', $end_date);
$role_performance_stmt->execute();
$role_performance = $role_performance_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Performance - Task Manager</title>
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
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>Employee Performance Report</h2>
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
                                <a href="employee_performance.php" class="btn btn-secondary">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Role-wise Summary -->
                <div class="row mb-4">
                    <?php foreach ($role_performance as $role): ?>
                    <div class="col-md-4">
                        <div class="card stat-card text-center">
                            <div class="card-body">
                                <h5 class="card-title text-uppercase"><?= $role['role'] ?>s</h5>
                                <h3><?= $role['employee_count'] ?></h3>
                                <p class="mb-1">Employees</p>
                                <p class="mb-1">Completion Rate: <strong><?= number_format($role['avg_completion_rate'], 1) ?>%</strong></p>
                                <p class="mb-0">Avg. Time: <strong>
                                    <?php if ($role['avg_completion_hours']): ?>
                                        <?= number_format($role['avg_completion_hours'] / 24, 1) ?> days
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </strong></p>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <div class="col-md-4">
                        <div class="card stat-card text-center bg-primary text-white">
                            <div class="card-body">
                                <h5 class="card-title">TOTAL EMPLOYEES</h5>
                                <h3><?= count($employee_performance) ?></h3>
                                <p class="mb-0">Active Team Members</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Employee Performance Table -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Individual Employee Performance</h5>
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
                                        <th>Overdue Tasks</th>
                                        <th>Avg. Completion Time</th>
                                        <th>Bugs Reported</th>
                                        <th>Activities</th>
                                        <th>Performance Rating</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($employee_performance as $employee): 
                                        $performance_class = '';
                                        $performance_rating = '';
                                        
                                        if ($employee['completion_rate'] >= 80) {
                                            $performance_class = 'performance-excellent';
                                            $performance_rating = 'Excellent';
                                        } elseif ($employee['completion_rate'] >= 60) {
                                            $performance_class = 'performance-good';
                                            $performance_rating = 'Good';
                                        } else {
                                            $performance_class = 'performance-average';
                                            $performance_rating = 'Needs Improvement';
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
                                                    <div class="progress-bar bg-<?= 
                                                        $employee['completion_rate'] >= 80 ? 'success' : 
                                                        ($employee['completion_rate'] >= 60 ? 'warning' : 'danger')
                                                    ?>" style="width: <?= $employee['completion_rate'] ?>%"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($employee['overdue_tasks'] > 0): ?>
                                                <span class="badge bg-danger"><?= $employee['overdue_tasks'] ?> overdue</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">On track</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($employee['avg_completion_hours']): ?>
                                                <?= number_format($employee['avg_completion_hours'] / 24, 1) ?> days
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
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
                                            <small>
                                                <?= $employee['comments_made'] ?> comments<br>
                                                <?= $employee['activities_logged'] ?> activities
                                            </small>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= 
                                                $performance_rating == 'Excellent' ? 'success' : 
                                                ($performance_rating == 'Good' ? 'warning' : 'danger')
                                            ?>">
                                                <?= $performance_rating ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Performance Summary -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Performance Distribution</h5>
                            </div>
                            <div class="card-body">
                                <canvas id="performanceChart" height="200"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Role Comparison</h5>
                            </div>
                            <div class="card-body">
                                <canvas id="roleChart" height="200"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Performance Distribution Chart
        const performanceCtx = document.getElementById('performanceChart').getContext('2d');
        
        // Calculate performance distribution
        const excellent = <?= count(array_filter($employee_performance, fn($emp) => $emp['completion_rate'] >= 80)) ?>;
        const good = <?= count(array_filter($employee_performance, fn($emp) => $emp['completion_rate'] >= 60 && $emp['completion_rate'] < 80)) ?>;
        const needsImprovement = <?= count(array_filter($employee_performance, fn($emp) => $emp['completion_rate'] < 60)) ?>;
        
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
                        text: 'Employee Performance Distribution'
                    }
                }
            }
        });

        // Role Comparison Chart
        const roleCtx = document.getElementById('roleChart').getContext('2d');
        const roleChart = new Chart(roleCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($role_performance, 'role')) ?>,
                datasets: [{
                    label: 'Average Completion Rate (%)',
                    data: <?= json_encode(array_column($role_performance, 'avg_completion_rate')) ?>,
                    backgroundColor: '#36a2eb'
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: 'Average Completion Rate by Role'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100
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