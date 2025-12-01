<?php
include 'config/database.php';
include 'includes/auth.php';
include 'includes/activity_logger.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireRole(['manager']);

// Initialize activity logger
$activityLogger = new ActivityLogger($db);

// Date range filter
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$employee_filter = $_GET['employee'] ?? '';
$action_filter = $_GET['action'] ?? '';

// Build filter conditions
$filter_conditions = [];
$filter_params = [];

// Date range filter
$filter_conditions[] = "al.created_at BETWEEN :start_date AND :end_date";
$start_date_param = $start_date . ' 00:00:00';
$end_date_param = $end_date . ' 23:59:59';

// Employee filter
if (!empty($employee_filter)) {
    $filter_conditions[] = "al.user_id = :user_id";
    $user_id_param = $employee_filter;
}

// Action filter
if (!empty($action_filter)) {
    $filter_conditions[] = "al.action = :action";
    $action_param = $action_filter;
}

$filter_sql = !empty($filter_conditions) ? "WHERE " . implode(" AND ", $filter_conditions) : "";

// Get activity logs with filters
$activity_query = "
    SELECT 
        al.*,
        u.name as user_name,
        u.role as user_role,
        u.email as user_email
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.id
    $filter_sql
    ORDER BY al.created_at DESC
    LIMIT 1000
";

$activity_stmt = $db->prepare($activity_query);
$activity_stmt->bindParam(':start_date', $start_date_param);
$activity_stmt->bindParam(':end_date', $end_date_param);

if (!empty($employee_filter)) {
    $activity_stmt->bindParam(':user_id', $user_id_param);
}

if (!empty($action_filter)) {
    $activity_stmt->bindParam(':action', $action_param);
}

$activity_stmt->execute();
$activity_logs = $activity_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique actions for filter dropdown
$actions_query = "SELECT DISTINCT action FROM activity_logs ORDER BY action";
$actions = $db->query($actions_query)->fetchAll(PDO::FETCH_COLUMN);

// Get employees for filter dropdown
$employees_query = "SELECT id, name, role FROM users WHERE status = 'active' ORDER BY name";
$employees = $db->query($employees_query)->fetchAll(PDO::FETCH_ASSOC);

// Get activity statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_activities,
        COUNT(DISTINCT al.user_id) as unique_users,
        COUNT(DISTINCT al.action) as unique_actions,
        COUNT(DISTINCT al.entity_type) as unique_entities
    FROM activity_logs al
    $filter_sql
";

$stats_stmt = $db->prepare($stats_query);
$stats_stmt->bindParam(':start_date', $start_date_param);
$stats_stmt->bindParam(':end_date', $end_date_param);

if (!empty($employee_filter)) {
    $stats_stmt->bindParam(':user_id', $user_id_param);
}

if (!empty($action_filter)) {
    $stats_stmt->bindParam(':action', $action_param);
}

$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Get top activities
$top_activities_query = "
    SELECT 
        action,
        COUNT(*) as count
    FROM activity_logs al
    $filter_sql
    GROUP BY action
    ORDER BY count DESC
    LIMIT 10
";

$top_activities_stmt = $db->prepare($top_activities_query);
$top_activities_stmt->bindParam(':start_date', $start_date_param);
$top_activities_stmt->bindParam(':end_date', $end_date_param);

if (!empty($employee_filter)) {
    $top_activities_stmt->bindParam(':user_id', $user_id_param);
}

if (!empty($action_filter)) {
    $top_activities_stmt->bindParam(':action', $action_param);
}

$top_activities_stmt->execute();
$top_activities = $top_activities_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get user activity summary
$user_activity_query = "
    SELECT 
        u.name,
        u.role,
        COUNT(al.id) as activity_count
    FROM users u
    LEFT JOIN activity_logs al ON u.id = al.user_id AND al.created_at BETWEEN :start_date2 AND :end_date2
    WHERE u.status = 'active'
    GROUP BY u.id, u.name, u.role
    ORDER BY activity_count DESC
";

$user_activity_stmt = $db->prepare($user_activity_query);
$user_activity_stmt->bindParam(':start_date2', $start_date_param);
$user_activity_stmt->bindParam(':end_date2', $end_date_param);
$user_activity_stmt->execute();
$user_activity = $user_activity_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Logs Report - Task Manager</title>
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
        .activity-table {
            font-size: 0.9rem;
        }
        .entity-badge {
            font-size: 0.7rem;
        }
        .log-details {
            max-width: 300px;
            word-wrap: break-word;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>Activity Logs Report</h2>
                    <button class="btn btn-success" onclick="exportToPDF()">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </button>
                </div>

                <!-- Filters -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Report Filters</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Start Date</label>
                                <input type="date" class="form-control" name="start_date" value="<?= $start_date ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">End Date</label>
                                <input type="date" class="form-control" name="end_date" value="<?= $end_date ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Employee</label>
                                <select name="employee" class="form-select">
                                    <option value="">All Employees</option>
                                    <?php foreach ($employees as $employee): ?>
                                        <option value="<?= $employee['id'] ?>" <?= $employee_filter == $employee['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($employee['name']) ?> (<?= $employee['role'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Action Type</label>
                                <select name="action" class="form-select">
                                    <option value="">All Actions</option>
                                    <?php foreach ($actions as $action): ?>
                                        <option value="<?= $action ?>" <?= $action_filter == $action ? 'selected' : '' ?>>
                                            <?= ucfirst(str_replace('_', ' ', $action)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary me-2">Apply Filters</button>
                                <a href="activity_logs_report.php" class="btn btn-secondary">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card stat-card text-center bg-primary text-white">
                            <div class="card-body">
                                <h3><?= $stats['total_activities'] ?></h3>
                                <p class="mb-0">Total Activities</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card text-center bg-success text-white">
                            <div class="card-body">
                                <h3><?= $stats['unique_users'] ?></h3>
                                <p class="mb-0">Active Users</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card text-center bg-info text-white">
                            <div class="card-body">
                                <h3><?= $stats['unique_actions'] ?></h3>
                                <p class="mb-0">Action Types</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card text-center bg-warning text-dark">
                            <div class="card-body">
                                <h3><?= $stats['unique_entities'] ?></h3>
                                <p class="mb-0">Entity Types</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Activity Charts -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Top Activities</h5>
                            </div>
                            <div class="card-body">
                                <canvas id="activitiesChart" height="250"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">User Activity Summary</h5>
                            </div>
                            <div class="card-body">
                                <canvas id="usersChart" height="250"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Activity Logs Table -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Activity Logs</h5>
                        <small class="text-muted">Showing <?= count($activity_logs) ?> records</small>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped activity-table">
                                <thead>
                                    <tr>
                                        <th>Timestamp</th>
                                        <th>User</th>
                                        <th>Action</th>
                                        <th>Entity</th>
                                        <th>Entity ID</th>
                                        <th>Details</th>
                                        <th>IP Address</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($activity_logs as $log): ?>
                                    <tr>
                                        <td>
                                            <small><?= date('M j, Y H:i', strtotime($log['created_at'])) ?></small>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($log['user_name']) ?></strong>
                                            <br>
                                            <span class="badge bg-secondary entity-badge"><?= $log['user_role'] ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-info">
                                                <?= ucfirst(str_replace('_', ' ', $log['action'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary entity-badge">
                                                <?= ucfirst(str_replace('_', ' ', $log['entity_type'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <code>#<?= $log['entity_id'] ?></code>
                                        </td>
                                        <td class="log-details">
                                            <?php if ($log['details']): ?>
                                                <small><?= htmlspecialchars(substr($log['details'], 0, 100)) ?><?= strlen($log['details']) > 100 ? '...' : '' ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small class="text-muted"><?= $log['ip_address'] ?></small>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <?php if (empty($activity_logs)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No activity logs found for the selected filters.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Top Activities Chart
        const activitiesCtx = document.getElementById('activitiesChart').getContext('2d');
        const activitiesChart = new Chart(activitiesCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_map(function($action) {
                    return ucfirst(str_replace('_', ' ', $action));
                }, array_column($top_activities, 'action'))) ?>,
                datasets: [{
                    label: 'Activity Count',
                    data: <?= json_encode(array_column($top_activities, 'count')) ?>,
                    backgroundColor: '#36a2eb'
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: 'Most Frequent Activities'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        // User Activity Chart
        const usersCtx = document.getElementById('usersChart').getContext('2d');
        const usersChart = new Chart(usersCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_column($user_activity, 'name')) ?>,
                datasets: [{
                    data: <?= json_encode(array_column($user_activity, 'activity_count')) ?>,
                    backgroundColor: [
                        '#ff6384', '#36a2eb', '#ffce56', '#4bc0c0', '#9966ff', 
                        '#ff9f40', '#ff6384', '#36a2eb', '#ffce56', '#4bc0c0'
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
                        text: 'Activity Distribution by User'
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