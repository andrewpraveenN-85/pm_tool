<?php
include 'config/database.php';
include 'includes/auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireRole(['manager']);

// Get filter parameters
$employee_id = $_GET['employee_id'] ?? '';
$start_date = $_GET['start_date'] ?? date('Y-m-01', strtotime('-1 month'));
$end_date = $_GET['end_date'] ?? date('Y-m-t');

// Get all employees
$employees_query = "SELECT id, name, role FROM users 
                   WHERE status = 'active' AND role IN ('developer', 'qa')
                   ORDER BY name";
$employees = $db->query($employees_query)->fetchAll(PDO::FETCH_ASSOC);

$employee_data = null;
$advanced_stats = null;
$weekly_trends = null;
$priority_analysis = null;

if (!empty($employee_id)) {
    // Get employee data
    $emp_check = $db->prepare("SELECT * FROM users WHERE id = ? AND status = 'active'");
    $emp_check->execute([$employee_id]);
    $employee_data = $emp_check->fetch(PDO::FETCH_ASSOC);

    if ($employee_data) {
        // Advanced Statistics with updated overdue calculation
        $stats_query = "
            SELECT 
                -- Task Metrics
                COUNT(DISTINCT t.id) as total_tasks,
                SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) as completed_tasks,
                AVG(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) * 100 as completion_rate,
                -- Updated overdue calculation
                SUM(CASE 
                    WHEN t.status = 'closed' AND t.updated_at > t.end_datetime THEN 1
                    WHEN t.status != 'closed' AND t.end_datetime < NOW() THEN 1
                    ELSE 0 
                END) as overdue_tasks,
                
                -- Time Metrics
                AVG(TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at)) as avg_completion_hours,
                MIN(TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at)) as fastest_completion,
                MAX(TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at)) as slowest_completion,
                
                -- Bug Metrics
                COUNT(DISTINCT b.id) as total_bugs,
                COUNT(DISTINCT CASE WHEN b.status = 'open' THEN b.id END) as open_bugs,
                COUNT(DISTINCT CASE WHEN b.status = 'resolved' THEN b.id END) as resolved_bugs,
                
                -- Efficiency Metrics
                COUNT(DISTINCT c.id) as total_comments,
                COUNT(DISTINCT a.id) as total_attachments,
                
                -- Project Distribution
                COUNT(DISTINCT p.id) as projects_involved
                
            FROM users u
            LEFT JOIN task_assignments ta ON u.id = ta.user_id
            LEFT JOIN tasks t ON ta.task_id = t.id AND t.created_at BETWEEN :start_date AND :end_date
            LEFT JOIN bugs b ON t.id = b.task_id
            LEFT JOIN comments c ON t.id = c.entity_id AND c.entity_type = 'task' AND c.user_id = u.id
            LEFT JOIN attachments a ON t.id = a.entity_id AND a.entity_type = 'task' AND a.uploaded_by = u.id
            LEFT JOIN projects p ON t.project_id = p.id
            WHERE u.id = :employee_id
        ";

        $stmt = $db->prepare($stats_query);
        $stmt->execute([
            ':employee_id' => $employee_id,
            ':start_date' => $start_date,
            ':end_date' => $end_date
        ]);
        $advanced_stats = $stmt->fetch(PDO::FETCH_ASSOC);

        // Weekly Trends
        $trends_query = "
            SELECT 
                YEARWEEK(t.created_at) as week,
                COUNT(DISTINCT t.id) as tasks_created,
                SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) as tasks_completed,
                COUNT(DISTINCT b.id) as bugs_reported,
                AVG(TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at)) as avg_completion_time
            FROM tasks t
            LEFT JOIN task_assignments ta ON t.id = ta.task_id
            LEFT JOIN bugs b ON t.id = b.task_id
            WHERE ta.user_id = :employee_id
            AND t.created_at BETWEEN :start_date AND :end_date
            GROUP BY YEARWEEK(t.created_at)
            ORDER BY week DESC
            LIMIT 8
        ";

        $stmt = $db->prepare($trends_query);
        $stmt->execute([
            ':employee_id' => $employee_id,
            ':start_date' => $start_date,
            ':end_date' => $end_date
        ]);
        $weekly_trends = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Priority Analysis with updated overdue calculation
        $priority_query = "
            SELECT 
                t.priority,
                COUNT(DISTINCT t.id) as task_count,
                SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) as completed_count,
                AVG(TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at)) as avg_completion_hours,
                -- Updated overdue calculation
                SUM(CASE 
                    WHEN t.status = 'closed' AND t.updated_at > t.end_datetime THEN 1
                    WHEN t.status != 'closed' AND t.end_datetime < NOW() THEN 1
                    ELSE 0 
                END) as overdue_count
            FROM tasks t
            LEFT JOIN task_assignments ta ON t.id = ta.task_id
            WHERE ta.user_id = :employee_id
            AND t.created_at BETWEEN :start_date AND :end_date
            GROUP BY t.priority
            ORDER BY FIELD(t.priority, 'critical', 'high', 'medium', 'low')
        ";

        $stmt = $db->prepare($priority_query);
        $stmt->execute([
            ':employee_id' => $employee_id,
            ':start_date' => $start_date,
            ':end_date' => $end_date
        ]);
        $priority_analysis = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advanced Employee Analytics</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .stat-card {
            transition: transform 0.2s;
            border: none;
            border-radius: 10px;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .metric-value {
            font-size: 2rem;
            font-weight: bold;
        }
        .metric-label {
            font-size: 0.9rem;
            color: #6c757d;
        }
        .progress-thin {
            height: 6px;
        }
        .trend-up {
            color: #28a745;
        }
        .trend-down {
            color: #dc3545;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid mt-4">
        <div class="row mb-4">
            <div class="col-12">
                <h2><i class="fas fa-chart-line"></i> Advanced Employee Analytics</h2>
                <p class="text-muted">Deep dive analytics for individual employee performance</p>
            </div>
        </div>

        <!-- Filter Form -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="fas fa-filter"></i> Analytics Filters</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Select Employee *</label>
                                <select name="employee_id" class="form-select" required>
                                    <option value="">Choose employee...</option>
                                    <?php foreach ($employees as $emp): ?>
                                        <option value="<?= $emp['id'] ?>" <?= $employee_id == $emp['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($emp['name']) ?> (<?= strtoupper($emp['role']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">End Date</label>
                                <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-chart-bar"></i> Analyze
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($employee_id) && $employee_data && $advanced_stats): ?>
        <!-- Employee Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8">
                                <h3 class="mb-1">
                                    <i class="fas fa-user"></i> <?= htmlspecialchars($employee_data['name']) ?>
                                </h3>
                                <p class="mb-0">
                                    <span class="badge bg-light text-dark"><?= strtoupper($employee_data['role']) ?></span>
                                    <span class="ms-2">Analytics Period: <?= date('M d, Y', strtotime($start_date)) ?> - <?= date('M d, Y', strtotime($end_date)) ?></span>
                                </p>
                            </div>
                            <div class="col-md-4 text-end">
                                <a href="employee_tasks.php?employee_id=<?= $employee_id ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" 
                                   class="btn btn-light me-2">
                                    <i class="fas fa-columns"></i> View Tasks
                                </a>
                                <button class="btn btn-outline-light" onclick="window.print()">
                                    <i class="fas fa-print"></i> Print Report
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Key Metrics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card stat-card text-white" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                    <div class="card-body text-center">
                        <div class="metric-value"><?= $advanced_stats['total_tasks'] ?></div>
                        <div class="metric-label">Total Tasks</div>
                        <div class="mt-2">
                            <small><?= $advanced_stats['completed_tasks'] ?> completed</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card text-white" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <div class="card-body text-center">
                        <div class="metric-value"><?= number_format($advanced_stats['completion_rate'], 1) ?>%</div>
                        <div class="metric-label">Completion Rate</div>
                        <div class="mt-2">
                            <small><?= $advanced_stats['overdue_tasks'] ?> overdue</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card text-white" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                    <div class="card-body text-center">
                        <div class="metric-value"><?= $advanced_stats['total_bugs'] ?></div>
                        <div class="metric-label">Total Bugs</div>
                        <div class="mt-2">
                            <small><?= $advanced_stats['open_bugs'] ?> open, <?= $advanced_stats['resolved_bugs'] ?> resolved</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card text-white" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                    <div class="card-body text-center">
                        <div class="metric-value">
                            <?= $advanced_stats['avg_completion_hours'] ? number_format($advanced_stats['avg_completion_hours'] / 24, 1) : '0' ?>
                        </div>
                        <div class="metric-label">Avg. Days/Task</div>
                        <div class="mt-2">
                            <small>Fastest: <?= $advanced_stats['fastest_completion'] ? number_format($advanced_stats['fastest_completion'] / 24, 1) : '0' ?> days</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Weekly Performance Trends</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="trendsChart" height="250"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Priority Analysis</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="priorityChart" height="250"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Detailed Analysis -->
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Priority Performance Details</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Priority</th>
                                        <th>Tasks</th>
                                        <th>Completed</th>
                                        <th>Completion Rate</th>
                                        <th>Avg. Days</th>
                                        <th>Overdue</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($priority_analysis as $priority): ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-<?= 
                                                $priority['priority'] == 'critical' ? 'danger' : 
                                                ($priority['priority'] == 'high' ? 'warning' : 
                                                ($priority['priority'] == 'medium' ? 'info' : 'success')) 
                                            ?>">
                                                <?= ucfirst($priority['priority']) ?>
                                            </span>
                                        </td>
                                        <td><?= $priority['task_count'] ?></td>
                                        <td><?= $priority['completed_count'] ?></td>
                                        <td>
                                            <?= $priority['task_count'] > 0 ? 
                                                round(($priority['completed_count'] / $priority['task_count']) * 100, 1) . '%' : '0%' ?>
                                        </td>
                                        <td>
                                            <?= $priority['avg_completion_hours'] ? 
                                                number_format($priority['avg_completion_hours'] / 24, 1) . ' days' : 'N/A' ?>
                                        </td>
                                        <td>
                                            <?php if ($priority['overdue_count'] > 0): ?>
                                                <span class="badge bg-danger"><?= $priority['overdue_count'] ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-success">0</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Additional Metrics</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-6 mb-3">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <div class="metric-value"><?= $advanced_stats['projects_involved'] ?></div>
                                        <div class="metric-label">Projects Involved</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 mb-3">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <div class="metric-value"><?= $advanced_stats['total_comments'] ?></div>
                                        <div class="metric-label">Comments Made</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <div class="metric-value"><?= $advanced_stats['total_attachments'] ?></div>
                                        <div class="metric-label">Files Attached</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <div class="metric-value">
                                            <?= $advanced_stats['fastest_completion'] ? 
                                                number_format($advanced_stats['fastest_completion'] / 24, 1) : '0' ?>
                                        </div>
                                        <div class="metric-label">Fastest Task (days)</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php elseif (!empty($employee_id) && !$employee_data): ?>
        <!-- Employee not found -->
        <div class="row">
            <div class="col-12">
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> 
                    Employee not found or is inactive.
                </div>
            </div>
        </div>
        <?php elseif (!empty($employee_id) && $employee_data && !$advanced_stats): ?>
        <!-- No data -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-chart-bar fa-4x text-muted mb-3"></i>
                        <h4 class="text-muted">No Data Available</h4>
                        <p class="text-muted">No activity found for the selected period</p>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <!-- Initial state -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-chart-line fa-4x text-muted mb-3"></i>
                        <h4 class="text-muted">Advanced Analytics</h4>
                        <p class="text-muted">Select an employee to view detailed performance analytics</p>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        <?php if (!empty($weekly_trends)): ?>
        // Trends Chart
        const trendsCtx = document.getElementById('trendsChart').getContext('2d');
        const weeks = <?= json_encode(array_column($weekly_trends, 'week')) ?>;
        const tasksCreated = <?= json_encode(array_column($weekly_trends, 'tasks_created')) ?>;
        const tasksCompleted = <?= json_encode(array_column($weekly_trends, 'tasks_completed')) ?>;
        
        const trendsChart = new Chart(trendsCtx, {
            type: 'line',
            data: {
                labels: weeks,
                datasets: [
                    {
                        label: 'Tasks Created',
                        data: tasksCreated,
                        borderColor: '#36a2eb',
                        backgroundColor: 'rgba(54, 162, 235, 0.1)',
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Tasks Completed',
                        data: tasksCompleted,
                        borderColor: '#4bc0c0',
                        backgroundColor: 'rgba(75, 192, 192, 0.1)',
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
                        text: 'Weekly Task Trends'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        // Priority Chart
        const priorityCtx = document.getElementById('priorityChart').getContext('2d');
        const priorities = <?= json_encode(array_column($priority_analysis, 'priority')) ?>;
        const taskCounts = <?= json_encode(array_column($priority_analysis, 'task_count')) ?>;
        
        const priorityChart = new Chart(priorityCtx, {
            type: 'bar',
            data: {
                labels: priorities.map(p => p.charAt(0).toUpperCase() + p.slice(1)),
                datasets: [{
                    label: 'Task Count by Priority',
                    data: taskCounts,
                    backgroundColor: [
                        'rgba(220, 53, 69, 0.7)',
                        'rgba(253, 126, 20, 0.7)',
                        'rgba(255, 193, 7, 0.7)',
                        'rgba(40, 167, 69, 0.7)'
                    ],
                    borderColor: [
                        '#dc3545',
                        '#fd7e14',
                        '#ffc107',
                        '#28a745'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: 'Task Distribution by Priority'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Number of Tasks'
                        }
                    }
                }
            }
        });
        <?php endif; ?>
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
<footer class="bg-dark text-light text-center py-3 mt-5">
    <div class="container">
        <p class="mb-0">Developed by APNLAB. 2025.</p>
    </div>
</footer>
</html>