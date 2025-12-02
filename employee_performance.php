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

// Get all employees performance with updated overdue calculation
$performance_query = "
    SELECT 
        u.id,
        u.name,
        u.email,
        u.role,
        COUNT(DISTINCT t.id) as total_tasks,
        SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) as completed_tasks,
        CASE 
            WHEN COUNT(DISTINCT t.id) > 0 
            THEN (SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) * 100.0 / COUNT(DISTINCT t.id))
            ELSE 0 
        END as completion_rate,
        AVG(CASE WHEN t.status = 'closed' THEN TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at) ELSE NULL END) as avg_completion_hours,
        MIN(CASE WHEN t.status = 'closed' THEN TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at) ELSE NULL END) as fastest_completion,
        MAX(CASE WHEN t.status = 'closed' THEN TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at) ELSE NULL END) as slowest_completion,
        COUNT(DISTINCT b.id) as total_bugs_in_tasks,
        COUNT(DISTINCT CASE WHEN b.created_by = u.id THEN b.id END) as bugs_reported,
        COUNT(DISTINCT CASE WHEN b.status = 'closed' AND b.created_by = u.id THEN b.id END) as bugs_resolved,
        -- Updated overdue calculation: only count tasks that were closed after deadline OR are not closed and past deadline
        SUM(CASE 
            WHEN t.status = 'closed' AND t.updated_at > t.end_datetime THEN 1
            WHEN t.status != 'closed' AND t.end_datetime < NOW() THEN 1
            ELSE 0 
        END) as overdue_tasks
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

// Get summary statistics with updated overdue calculation
$summary_query = "
    SELECT 
        COUNT(DISTINCT u.id) as total_employees,
        CASE 
            WHEN COUNT(DISTINCT t.id) > 0 
            THEN (SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) * 100.0 / COUNT(DISTINCT t.id))
            ELSE 0 
        END as avg_completion_rate,
        -- Updated overdue calculation
        SUM(CASE 
            WHEN t.status = 'closed' AND t.updated_at > t.end_datetime THEN 1
            WHEN t.status != 'closed' AND t.end_datetime < NOW() THEN 1
            ELSE 0 
        END) as total_overdue,
        COUNT(DISTINCT b.id) as total_bugs,
        AVG(CASE WHEN t.status = 'closed' THEN TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at) ELSE NULL END) as overall_avg_time
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

// Default values if no data
$performance_data = $performance_data ?: [];
$summary = $summary ?: [
    'total_employees' => 0,
    'avg_completion_rate' => 0,
    'total_overdue' => 0,
    'total_bugs' => 0,
    'overall_avg_time' => 0
];
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
        .no-data-message {
            min-height: 250px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        @media print {
            .no-print {
                display: none !important;
            }
            .card {
                border: 1px solid #ddd !important;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid mt-4">
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <h2>Employee Performance Summary</h2>
                    <button class="btn btn-success no-print" onclick="window.print()">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </button>
                </div>
                <p class="text-muted">Overall performance metrics for all employees</p>
            </div>
        </div>

        <!-- Date Range Filter -->
        <div class="row mb-4 no-print">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Report Period</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Start Date</label>
                                <input type="date" class="form-control" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">End Date</label>
                                <input type="date" class="form-control" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
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
                        <h3><?= htmlspecialchars($summary['total_employees']) ?></h3>
                        <p class="mb-0">Total Employees</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card text-center bg-success text-white">
                    <div class="card-body">
                        <h3><?= number_format($summary['avg_completion_rate'], 1) ?>%</h3>
                        <p class="mb-0">Avg. Completion Rate</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card text-center bg-warning text-dark">
                    <div class="card-body">
                        <h3><?= htmlspecialchars($summary['total_overdue']) ?></h3>
                        <p class="mb-0">Total Overdue Tasks</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card text-center bg-info text-white">
                    <div class="card-body">
                        <h3><?= htmlspecialchars($summary['total_bugs']) ?></h3>
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
                <?php if (empty($performance_data)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> No employee performance data available for the selected period.
                    </div>
                <?php else: ?>
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
                                <th class="no-print">Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($performance_data as $employee): 
                                $completion_rate = floatval($employee['completion_rate'] ?? 0);
                                $performance_class = '';
                                $performance_rating = '';
                                $performance_color = '';
                                
                                if ($completion_rate >= 80) {
                                    $performance_class = 'performance-excellent';
                                    $performance_rating = 'Excellent';
                                    $performance_color = 'success';
                                } elseif ($completion_rate >= 60) {
                                    $performance_class = 'performance-good';
                                    $performance_rating = 'Good';
                                    $performance_color = 'warning';
                                } else {
                                    $performance_class = 'performance-average';
                                    $performance_rating = 'Needs Improvement';
                                    $performance_color = 'danger';
                                }
                                
                                $avg_completion_hours = floatval($employee['avg_completion_hours'] ?? 0);
                                $completion_days = $avg_completion_hours > 0 ? number_format($avg_completion_hours / 24, 1) : 0;
                            ?>
                            <tr class="<?= $performance_class ?>">
                                <td>
                                    <strong><?= htmlspecialchars($employee['name'] ?? 'Unknown') ?></strong>
                                    <br><small class="text-muted"><?= htmlspecialchars($employee['email'] ?? 'N/A') ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-<?= ($employee['role'] ?? '') == 'developer' ? 'info' : 'warning' ?>">
                                        <?= ucfirst($employee['role'] ?? 'unknown') ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($employee['total_tasks'] ?? 0) ?></strong>
                                    <br><small class="text-muted"><?= htmlspecialchars($employee['completed_tasks'] ?? 0) ?> completed</small>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <span class="me-2 fw-bold"><?= number_format($completion_rate, 1) ?>%</span>
                                        <div class="progress progress-thin flex-grow-1" style="width: 100px;">
                                            <div class="progress-bar bg-<?= $performance_color ?>" 
                                                 style="width: <?= min(100, $completion_rate) ?>%"></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($avg_completion_hours > 0): ?>
                                        <?= $completion_days ?> days
                                    <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (($employee['overdue_tasks'] ?? 0) > 0): ?>
                                        <span class="badge bg-danger"><?= htmlspecialchars($employee['overdue_tasks']) ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-success">0</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $bugs_reported = intval($employee['bugs_reported'] ?? 0);
                                    $bugs_resolved = intval($employee['bugs_resolved'] ?? 0);
                                    $bugs_badge_color = $bugs_reported == 0 ? 'secondary' : 
                                                       ($bugs_reported <= 5 ? 'info' : 'warning');
                                    ?>
                                    <span class="badge bg-<?= $bugs_badge_color ?>">
                                        <?= $bugs_reported ?> reported
                                        <?php if ($bugs_resolved > 0): ?>
                                            <br><small><?= $bugs_resolved ?> resolved</small>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $performance_color ?>">
                                        <?= $performance_rating ?>
                                    </span>
                                </td>
                                <td class="no-print">
                                    <a href="employee_tasks.php?employee_id=<?= urlencode($employee['id']) ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>" 
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-eye"></i> View Tasks
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
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
                        <div id="performanceChartNoData" class="no-data-message" style="display: none;">
                            <p class="text-muted">No performance data available for the selected period.</p>
                        </div>
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
                        <div id="timeChartNoData" class="no-data-message" style="display: none;">
                            <p class="text-muted">No completion time data available.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Performance Distribution Chart
        const performanceCtx = document.getElementById('performanceChart');
        const performanceNoData = document.getElementById('performanceChartNoData');
        
        if (performanceCtx) {
            // Get performance data from PHP
            const performanceData = <?= json_encode($performance_data) ?>;
            
            // Calculate performance distribution with null checks
            const excellent = performanceData && performanceData.length > 0 
                ? performanceData.filter(emp => (parseFloat(emp.completion_rate) || 0) >= 80).length 
                : 0;
            const good = performanceData && performanceData.length > 0 
                ? performanceData.filter(emp => {
                    const rate = parseFloat(emp.completion_rate) || 0;
                    return rate >= 60 && rate < 80;
                }).length 
                : 0;
            const needsImprovement = performanceData && performanceData.length > 0 
                ? performanceData.filter(emp => (parseFloat(emp.completion_rate) || 0) < 60).length 
                : 0;
            
            // Only create chart if we have data
            if (excellent > 0 || good > 0 || needsImprovement > 0) {
                try {
                    const performanceChart = new Chart(performanceCtx.getContext('2d'), {
                        type: 'doughnut',
                        data: {
                            labels: ['Excellent (80%+)', 'Good (60-79%)', 'Needs Improvement (<60%)'],
                            datasets: [{
                                data: [excellent, good, needsImprovement],
                                backgroundColor: ['#28a745', '#ffc107', '#dc3545'],
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        padding: 20,
                                        usePointStyle: true
                                    }
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            const label = context.label || '';
                                            const value = context.raw || 0;
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                            return `${label}: ${value} (${percentage}%)`;
                                        }
                                    }
                                }
                            }
                        }
                    });
                } catch (error) {
                    console.error('Error creating performance chart:', error);
                    performanceCtx.style.display = 'none';
                    if (performanceNoData) {
                        performanceNoData.style.display = 'flex';
                    }
                }
            } else {
                performanceCtx.style.display = 'none';
                if (performanceNoData) {
                    performanceNoData.style.display = 'flex';
                }
            }
        }

        // Time Analysis Chart
        const timeCtx = document.getElementById('timeChart');
        const timeNoData = document.getElementById('timeChartNoData');
        
        if (timeCtx) {
            const performanceData = <?= json_encode($performance_data) ?>;
            
            if (performanceData && performanceData.length > 0) {
                // Extract employee names and completion times
                const employeeNames = [];
                const completionTimes = [];
                
                performanceData.forEach(emp => {
                    if (emp.name && emp.avg_completion_hours) {
                        const hours = parseFloat(emp.avg_completion_hours);
                        if (hours > 0) {
                            employeeNames.push(emp.name.substring(0, 20)); // Limit name length
                            completionTimes.push(parseFloat((hours / 24).toFixed(1)));
                        }
                    }
                });
                
                // Only create chart if we have valid data
                if (completionTimes.length > 0) {
                    try {
                        const timeChart = new Chart(timeCtx.getContext('2d'), {
                            type: 'bar',
                            data: {
                                labels: employeeNames,
                                datasets: [{
                                    label: 'Average Completion Time (Days)',
                                    data: completionTimes,
                                    backgroundColor: '#36a2eb',
                                    borderColor: '#2a8bd9',
                                    borderWidth: 1
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: {
                                        display: false
                                    },
                                    tooltip: {
                                        callbacks: {
                                            label: function(context) {
                                                return `${context.dataset.label}: ${context.raw} days`;
                                            }
                                        }
                                    }
                                },
                                scales: {
                                    y: {
                                        beginAtZero: true,
                                        title: {
                                            display: true,
                                            text: 'Days'
                                        },
                                        ticks: {
                                            precision: 1
                                        }
                                    },
                                    x: {
                                        ticks: {
                                            maxRotation: 45,
                                            minRotation: 45,
                                            callback: function(value) {
                                                // Truncate long names for display
                                                const label = this.getLabelForValue(value);
                                                return label.length > 15 ? label.substring(0, 15) + '...' : label;
                                            }
                                        }
                                    }
                                }
                            }
                        });
                    } catch (error) {
                        console.error('Error creating time chart:', error);
                        timeCtx.style.display = 'none';
                        if (timeNoData) {
                            timeNoData.style.display = 'flex';
                        }
                    }
                } else {
                    timeCtx.style.display = 'none';
                    if (timeNoData) {
                        timeNoData.style.display = 'flex';
                    }
                }
            } else {
                timeCtx.style.display = 'none';
                if (timeNoData) {
                    timeNoData.style.display = 'flex';
                }
            }
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