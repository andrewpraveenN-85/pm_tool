<?php
// user_stats.php
include 'config/database.php';
include 'includes/auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireRole(['manager']);

// Get user ID from query parameter
$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Fetch user details
$query = "SELECT id, name, email, role, status, image, created_at FROM users WHERE id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $user_id);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Redirect if user not found
if (!$user) {
    header("Location: users.php");
    exit;
}

// Function to get statistics
function getUserStatistics($db, $user_id, $period = 'all') {
    $conditions = [];
    $params = [':user_id' => $user_id];
    
    // Add period conditions
    switch ($period) {
        case 'week':
            $conditions[] = "tasks.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            break;
        case 'month':
            $conditions[] = "tasks.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            break;
        case 'year':
            $conditions[] = "tasks.created_at >= DATE_SUB(NOW(), INTERVAL 365 DAY)";
            break;
    }
    
    $where_clause = !empty($conditions) ? "AND " . implode(" AND ", $conditions) : "";
    
    // Task statistics - Fixed: Using table name instead of alias 't'
    $task_stats_query = "
        SELECT 
            COUNT(*) as total_tasks,
            SUM(CASE WHEN tasks.status = 'closed' THEN 1 ELSE 0 END) as completed_tasks,
            SUM(CASE WHEN tasks.status IN ('in_progress', 'await_release', 'in_review') THEN 1 ELSE 0 END) as in_progress_tasks,
            SUM(CASE WHEN tasks.status IN ('todo', 'reopened') THEN 1 ELSE 0 END) as pending_tasks,
            AVG(TIMESTAMPDIFF(HOUR, tasks.start_datetime, tasks.end_datetime)) as avg_completion_hours,
            MIN(tasks.created_at) as first_task_date,
            MAX(tasks.created_at) as last_task_date
        FROM tasks
        INNER JOIN task_assignments ON tasks.id = task_assignments.task_id
        WHERE task_assignments.user_id = :user_id $where_clause
    ";
    
    $task_stmt = $db->prepare($task_stats_query);
    $task_stmt->execute($params);
    $task_stats = $task_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Bug statistics - Fixed period condition
    $bug_conditions = [];
    $bug_params = [':user_id' => $user_id];
    
    switch ($period) {
        case 'week':
            $bug_conditions[] = "bugs.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            break;
        case 'month':
            $bug_conditions[] = "bugs.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            break;
        case 'year':
            $bug_conditions[] = "bugs.created_at >= DATE_SUB(NOW(), INTERVAL 365 DAY)";
            break;
    }
    
    $bug_where_clause = !empty($bug_conditions) ? "AND " . implode(" AND ", $bug_conditions) : "";
    
    $bug_stats_query = "
        SELECT 
            COUNT(*) as total_bugs,
            SUM(CASE WHEN bugs.status = 'closed' THEN 1 ELSE 0 END) as resolved_bugs,
            SUM(CASE WHEN bugs.status IN ('open', 'in_progress') THEN 1 ELSE 0 END) as active_bugs,
            AVG(TIMESTAMPDIFF(HOUR, bugs.start_datetime, bugs.end_datetime)) as avg_resolution_hours
        FROM bugs
        WHERE bugs.created_by = :user_id $bug_where_clause
    ";
    
    $bug_stmt = $db->prepare($bug_stats_query);
    $bug_stmt->execute($bug_params);
    $bug_stats = $bug_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Comment statistics
    $comment_conditions = [];
    $comment_params = [':user_id' => $user_id];
    
    switch ($period) {
        case 'week':
            $comment_conditions[] = "comments.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            break;
        case 'month':
            $comment_conditions[] = "comments.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            break;
        case 'year':
            $comment_conditions[] = "comments.created_at >= DATE_SUB(NOW(), INTERVAL 365 DAY)";
            break;
    }
    
    $comment_where_clause = !empty($comment_conditions) ? "AND " . implode(" AND ", $comment_conditions) : "";
    
    $comment_stats_query = "
        SELECT 
            COUNT(*) as total_comments,
            COUNT(DISTINCT entity_id) as tasks_commented_on
        FROM comments
        WHERE comments.user_id = :user_id $comment_where_clause
    ";
    
    $comment_stmt = $db->prepare($comment_stats_query);
    $comment_stmt->execute($comment_params);
    $comment_stats = $comment_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Activity statistics
    $activity_conditions = [];
    $activity_params = [':user_id' => $user_id];
    
    switch ($period) {
        case 'week':
            $activity_conditions[] = "activity_logs.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            break;
        case 'month':
            $activity_conditions[] = "activity_logs.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            break;
        case 'year':
            $activity_conditions[] = "activity_logs.created_at >= DATE_SUB(NOW(), INTERVAL 365 DAY)";
            break;
    }
    
    $activity_where_clause = !empty($activity_conditions) ? "AND " . implode(" AND ", $activity_conditions) : "";
    
    $activity_stats_query = "
        SELECT 
            COUNT(*) as total_activities,
            COUNT(DISTINCT DATE(created_at)) as active_days,
            GROUP_CONCAT(DISTINCT action) as actions_performed
        FROM activity_logs
        WHERE user_id = :user_id $activity_where_clause
    ";
    
    $activity_stmt = $db->prepare($activity_stats_query);
    $activity_stmt->execute($activity_params);
    $activity_stats = $activity_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get recent tasks
    $recent_tasks_query = "
        SELECT 
            tasks.id,
            tasks.name,
            tasks.status,
            tasks.priority,
            tasks.created_at,
            projects.name as project_name
        FROM tasks
        INNER JOIN task_assignments ON tasks.id = task_assignments.task_id
        LEFT JOIN projects ON tasks.project_id = projects.id
        WHERE task_assignments.user_id = :user_id
        ORDER BY tasks.created_at DESC
        LIMIT 10
    ";
    
    $recent_tasks_stmt = $db->prepare($recent_tasks_query);
    $recent_tasks_stmt->execute([':user_id' => $user_id]);
    $recent_tasks = $recent_tasks_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get performance trend (last 12 months)
    $performance_trend_query = "
        SELECT 
            DATE_FORMAT(tasks.created_at, '%Y-%m') as month,
            COUNT(*) as tasks_assigned,
            SUM(CASE WHEN tasks.status = 'closed' THEN 1 ELSE 0 END) as tasks_completed
        FROM tasks
        INNER JOIN task_assignments ON tasks.id = task_assignments.task_id
        WHERE task_assignments.user_id = :user_id 
            AND tasks.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(tasks.created_at, '%Y-%m')
        ORDER BY month
    ";
    
    $trend_stmt = $db->prepare($performance_trend_query);
    $trend_stmt->execute([':user_id' => $user_id]);
    $performance_trend = $trend_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'task_stats' => $task_stats ?: ['total_tasks' => 0, 'completed_tasks' => 0, 'in_progress_tasks' => 0, 'pending_tasks' => 0, 'avg_completion_hours' => null, 'first_task_date' => null, 'last_task_date' => null],
        'bug_stats' => $bug_stats ?: ['total_bugs' => 0, 'resolved_bugs' => 0, 'active_bugs' => 0, 'avg_resolution_hours' => null],
        'comment_stats' => $comment_stats ?: ['total_comments' => 0, 'tasks_commented_on' => 0],
        'activity_stats' => $activity_stats ?: ['total_activities' => 0, 'active_days' => 0, 'actions_performed' => ''],
        'recent_tasks' => $recent_tasks,
        'performance_trend' => $performance_trend
    ];
}

// Get statistics for different periods
$stats_all = getUserStatistics($db, $user_id, 'all');
$stats_month = getUserStatistics($db, $user_id, 'month');
$stats_week = getUserStatistics($db, $user_id, 'week');

// Calculate performance metrics
function calculatePerformanceScore($stats) {
    $score = 0;
    
    if ($stats['task_stats']['total_tasks'] > 0) {
        // Completion rate (40% weight)
        $completion_rate = ($stats['task_stats']['completed_tasks'] / $stats['task_stats']['total_tasks']) * 100;
        $score += min($completion_rate, 100) * 0.4;
        
        // Activity level (30% weight)
        if ($stats['activity_stats']['active_days'] > 0) {
            $activity_score = min(($stats['activity_stats']['total_activities'] / $stats['activity_stats']['active_days']) * 10, 30);
            $score += $activity_score;
        }
        
        // Bug resolution (30% weight)
        if ($stats['bug_stats']['total_bugs'] > 0) {
            $bug_resolution_rate = ($stats['bug_stats']['resolved_bugs'] / $stats['bug_stats']['total_bugs']) * 100;
            $score += min($bug_resolution_rate, 100) * 0.3;
        } else {
            // If no bugs, give full points for this category
            $score += 30;
        }
    } else {
        // If no tasks, base score on activity
        if ($stats['activity_stats']['active_days'] > 0) {
            $activity_score = min(($stats['activity_stats']['total_activities'] / $stats['activity_stats']['active_days']) * 10, 100);
            $score = $activity_score;
        }
    }
    
    return round($score, 1);
}

$performance_score_all = calculatePerformanceScore($stats_all);
$performance_score_month = calculatePerformanceScore($stats_month);
$performance_score_week = calculatePerformanceScore($stats_week);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($user['name']) ?> - Performance Stats</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .stat-card {
            transition: transform 0.3s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .performance-badge {
            font-size: 0.9rem;
            padding: 0.5rem 1rem;
        }
        .chart-container {
            position: relative;
            height: 300px;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid mt-4">
        <!-- Back Button -->
        <div class="mb-3">
            <a href="users.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Users
            </a>
        </div>
        
        <!-- User Profile Header -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-2 text-center">
                        <img src="<?= $user['image'] ?: 'https://via.placeholder.com/100' ?>" 
                             class="rounded-circle" width="100" height="100">
                    </div>
                    <div class="col-md-8">
                        <h2 class="mb-1"><?= htmlspecialchars($user['name']) ?></h2>
                        <p class="text-muted mb-1">
                            <i class="fas fa-envelope"></i> <?= htmlspecialchars($user['email']) ?>
                            | <span class="badge bg-<?= $user['role'] == 'manager' ? 'primary' : ($user['role'] == 'developer' ? 'success' : 'warning') ?>">
                                <?= ucfirst($user['role']) ?>
                            </span>
                            | <span class="badge bg-<?= $user['status'] == 'active' ? 'success' : 'secondary' ?>">
                                <?= ucfirst($user['status']) ?>
                            </span>
                        </p>
                        <p class="text-muted mb-0">
                            <i class="fas fa-calendar-alt"></i> Member since: <?= date('M j, Y', strtotime($user['created_at'])) ?>
                        </p>
                    </div>
                    <div class="col-md-2 text-end">
                        <div class="display-6 text-<?= 
                            $performance_score_all >= 80 ? 'success' : 
                            ($performance_score_all >= 60 ? 'warning' : 'danger')
                        ?>">
                            <?= $performance_score_all ?>%
                        </div>
                        <small class="text-muted">Performance Score</small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Performance Period Tabs -->
        <ul class="nav nav-tabs mb-4" id="periodTabs">
            <li class="nav-item">
                <a class="nav-link active" data-bs-toggle="tab" href="#allTime">All Time</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#monthly">Last 30 Days</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#weekly">Last 7 Days</a>
            </li>
        </ul>
        
        <div class="tab-content">
            <!-- All Time Tab -->
            <div class="tab-pane fade show active" id="allTime">
                <?php $stats = $stats_all; ?>
                <div class="row mb-4">
                    <!-- Task Statistics -->
                    <div class="col-md-3">
                        <div class="card text-center h-100 stat-card">
                            <div class="card-body">
                                <h1 class="text-primary"><?= $stats['task_stats']['total_tasks'] ?></h1>
                                <p class="text-muted mb-1">Total Tasks</p>
                                <div class="small">
                                    <span class="text-success"><?= $stats['task_stats']['completed_tasks'] ?> completed</span><br>
                                    <span class="text-warning"><?= $stats['task_stats']['in_progress_tasks'] ?> in progress</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Bug Statistics -->
                    <div class="col-md-3">
                        <div class="card text-center h-100 stat-card">
                            <div class="card-body">
                                <h1 class="text-danger"><?= $stats['bug_stats']['total_bugs'] ?></h1>
                                <p class="text-muted mb-1">Bugs Reported</p>
                                <div class="small">
                                    <span class="text-success"><?= $stats['bug_stats']['resolved_bugs'] ?> resolved</span><br>
                                    <span class="text-danger"><?= $stats['bug_stats']['active_bugs'] ?> active</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Activity Statistics -->
                    <div class="col-md-3">
                        <div class="card text-center h-100 stat-card">
                            <div class="card-body">
                                <h1 class="text-info"><?= $stats['activity_stats']['total_activities'] ?></h1>
                                <p class="text-muted mb-1">Activities</p>
                                <div class="small">
                                    <span class="text-info"><?= $stats['activity_stats']['active_days'] ?> active days</span><br>
                                    Avg: <?= $stats['activity_stats']['active_days'] > 0 ? 
                                        round($stats['activity_stats']['total_activities'] / $stats['activity_stats']['active_days'], 1) : 0 ?> per day
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Comment Statistics -->
                    <div class="col-md-3">
                        <div class="card text-center h-100 stat-card">
                            <div class="card-body">
                                <h1 class="text-success"><?= $stats['comment_stats']['total_comments'] ?></h1>
                                <p class="text-muted mb-1">Comments</p>
                                <div class="small">
                                    On <?= $stats['comment_stats']['tasks_commented_on'] ?> tasks<br>
                                    <span class="text-success">Collaboration</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Performance Metrics -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Performance Metrics</h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label">Task Completion Rate</label>
                                    <div class="progress" style="height: 25px;">
                                        <div class="progress-bar bg-success" role="progressbar" 
                                             style="width: <?= $stats['task_stats']['total_tasks'] > 0 ? 
                                                 ($stats['task_stats']['completed_tasks'] / $stats['task_stats']['total_tasks'] * 100) : 0 ?>%">
                                            <?= $stats['task_stats']['total_tasks'] > 0 ? 
                                                round(($stats['task_stats']['completed_tasks'] / $stats['task_stats']['total_tasks']) * 100, 1) : 0 ?>%
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Bug Resolution Rate</label>
                                    <div class="progress" style="height: 25px;">
                                        <div class="progress-bar bg-danger" role="progressbar" 
                                             style="width: <?= $stats['bug_stats']['total_bugs'] > 0 ? 
                                                 ($stats['bug_stats']['resolved_bugs'] / $stats['bug_stats']['total_bugs'] * 100) : 0 ?>%">
                                            <?= $stats['bug_stats']['total_bugs'] > 0 ? 
                                                round(($stats['bug_stats']['resolved_bugs'] / $stats['bug_stats']['total_bugs']) * 100, 1) : 0 ?>%
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Avg Task Completion Time</label>
                                            <div class="display-6">
                                                <?= $stats['task_stats']['avg_completion_hours'] ? 
                                                    round($stats['task_stats']['avg_completion_hours'], 1) . ' hrs' : 'N/A' ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Avg Bug Resolution Time</label>
                                            <div class="display-6">
                                                <?= $stats['bug_stats']['avg_resolution_hours'] ? 
                                                    round($stats['bug_stats']['avg_resolution_hours'], 1) . ' hrs' : 'N/A' ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Performance Chart -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Monthly Performance Trend</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="performanceChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Monthly Tab -->
            <div class="tab-pane fade" id="monthly">
                <?php $stats = $stats_month; ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Showing statistics for the last 30 days
                </div>
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card text-center h-100 stat-card">
                            <div class="card-body">
                                <h1 class="text-primary"><?= $stats['task_stats']['total_tasks'] ?></h1>
                                <p class="text-muted mb-1">Tasks (30 days)</p>
                                <div class="small">
                                    <span class="text-success"><?= $stats['task_stats']['completed_tasks'] ?> completed</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card text-center h-100 stat-card">
                            <div class="card-body">
                                <h1 class="text-danger"><?= $stats['bug_stats']['total_bugs'] ?></h1>
                                <p class="text-muted mb-1">Bugs (30 days)</p>
                                <div class="small">
                                    <span class="text-success"><?= $stats['bug_stats']['resolved_bugs'] ?> resolved</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card text-center h-100 stat-card">
                            <div class="card-body">
                                <h1 class="text-info"><?= $stats['activity_stats']['total_activities'] ?></h1>
                                <p class="text-muted mb-1">Activities (30 days)</p>
                                <div class="small">
                                    <?= $stats['activity_stats']['active_days'] ?> active days
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card text-center h-100 stat-card">
                            <div class="card-body">
                                <h3 class="display-6 text-<?= 
                                    $performance_score_month >= 80 ? 'success' : 
                                    ($performance_score_month >= 60 ? 'warning' : 'danger')
                                ?>">
                                    <?= $performance_score_month ?>%
                                </h3>
                                <p class="text-muted mb-1">Monthly Score</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Weekly Tab -->
            <div class="tab-pane fade" id="weekly">
                <?php $stats = $stats_week; ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Showing statistics for the last 7 days
                </div>
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card text-center h-100 stat-card">
                            <div class="card-body">
                                <h1 class="text-primary"><?= $stats['task_stats']['total_tasks'] ?></h1>
                                <p class="text-muted mb-1">Tasks (7 days)</p>
                                <div class="small">
                                    <span class="text-success"><?= $stats['task_stats']['completed_tasks'] ?> completed</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card text-center h-100 stat-card">
                            <div class="card-body">
                                <h1 class="text-danger"><?= $stats['bug_stats']['total_bugs'] ?></h1>
                                <p class="text-muted mb-1">Bugs (7 days)</p>
                                <div class="small">
                                    <span class="text-success"><?= $stats['bug_stats']['resolved_bugs'] ?> resolved</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card text-center h-100 stat-card">
                            <div class="card-body">
                                <h1 class="text-info"><?= $stats['activity_stats']['total_activities'] ?></h1>
                                <p class="text-muted mb-1">Activities (7 days)</p>
                                <div class="small">
                                    Daily avg: <?= $stats['activity_stats']['active_days'] > 0 ? 
                                        round($stats['activity_stats']['total_activities'] / $stats['activity_stats']['active_days'], 1) : 0 ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card text-center h-100 stat-card">
                            <div class="card-body">
                                <h3 class="display-6 text-<?= 
                                    $performance_score_week >= 80 ? 'success' : 
                                    ($performance_score_week >= 60 ? 'warning' : 'danger')
                                ?>">
                                    <?= $performance_score_week ?>%
                                </h3>
                                <p class="text-muted mb-1">Weekly Score</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Tasks -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Recent Tasks</h5>
                <a href="tasks.php?assignee=<?= $user_id ?>" class="btn btn-sm btn-outline-primary">
                    View All Tasks
                </a>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Task</th>
                                <th>Project</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($stats_all['recent_tasks'])): ?>
                                <?php foreach ($stats_all['recent_tasks'] as $task): ?>
                                <tr>
                                    <td>
                                        <a href="task_view.php?id=<?= $task['id'] ?>">
                                            <?= htmlspecialchars($task['name']) ?>
                                        </a>
                                    </td>
                                    <td><?= htmlspecialchars($task['project_name']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= 
                                            $task['status'] == 'closed' ? 'success' : 
                                            ($task['status'] == 'in_progress' ? 'primary' : 
                                            ($task['status'] == 'in_review' ? 'info' : 'secondary'))
                                        ?>">
                                            <?= ucfirst(str_replace('_', ' ', $task['status'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= 
                                            $task['priority'] == 'critical' ? 'danger' : 
                                            ($task['priority'] == 'high' ? 'warning' : 
                                            ($task['priority'] == 'medium' ? 'info' : 'secondary'))
                                        ?>">
                                            <?= ucfirst($task['priority']) ?>
                                        </span>
                                    </td>
                                    <td><?= date('M j, Y', strtotime($task['created_at'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted">
                                        No tasks assigned yet
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Summary -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Performance Summary</h5>
            </div>
            <div class="card-body">
                <?php
                $summary = "";
                $color = "success";
                $icon = "fa-check-circle";
                
                if ($performance_score_all >= 80) {
                    $summary = "Excellent performance! Consistently completes tasks on time and maintains high quality.";
                    $color = "success";
                    $icon = "fa-trophy";
                } elseif ($performance_score_all >= 60) {
                    $summary = "Good performance. Meets most deadlines with acceptable quality.";
                    $color = "warning";
                    $icon = "fa-chart-line";
                } else {
                    $summary = "Needs improvement. Consider providing additional support or training.";
                    $color = "danger";
                    $icon = "fa-exclamation-triangle";
                }
                ?>
                
                <div class="alert alert-<?= $color ?>">
                    <h5><i class="fas <?= $icon ?>"></i> Overall Assessment</h5>
                    <p class="mb-0"><?= $summary ?></p>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <h6><i class="fas fa-thumbs-up text-success"></i> Strengths:</h6>
                        <ul class="list-group list-group-flush">
                            <?php if ($stats_all['task_stats']['completed_tasks'] > 0): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    Task Completion
                                    <span class="badge bg-success rounded-pill"><?= $stats_all['task_stats']['completed_tasks'] ?> tasks</span>
                                </li>
                            <?php endif; ?>
                            <?php if ($stats_all['comment_stats']['total_comments'] > 5): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    Team Collaboration
                                    <span class="badge bg-info rounded-pill"><?= $stats_all['comment_stats']['total_comments'] ?> comments</span>
                                </li>
                            <?php endif; ?>
                            <?php if ($stats_all['activity_stats']['active_days'] > 0): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    Activity Consistency
                                    <span class="badge bg-primary rounded-pill"><?= $stats_all['activity_stats']['active_days'] ?> active days</span>
                                </li>
                            <?php endif; ?>
                            <?php if ($stats_all['bug_stats']['resolved_bugs'] > 0): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    Bug Resolution
                                    <span class="badge bg-danger rounded-pill"><?= $stats_all['bug_stats']['resolved_bugs'] ?> resolved</span>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6><i class="fas fa-bullseye text-warning"></i> Areas for Improvement:</h6>
                        <ul class="list-group list-group-flush">
                            <?php if ($stats_all['task_stats']['pending_tasks'] > 0): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    Reduce Pending Tasks
                                    <span class="badge bg-warning rounded-pill"><?= $stats_all['task_stats']['pending_tasks'] ?> pending</span>
                                </li>
                            <?php endif; ?>
                            <?php if ($stats_all['bug_stats']['active_bugs'] > 0): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    Improve Bug Resolution Time
                                    <span class="badge bg-danger rounded-pill"><?= $stats_all['bug_stats']['active_bugs'] ?> active bugs</span>
                                </li>
                            <?php endif; ?>
                            <?php if ($stats_all['task_stats']['avg_completion_hours'] > 24): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    Faster Task Completion
                                    <span class="badge bg-info rounded-pill"><?= round($stats_all['task_stats']['avg_completion_hours'], 1) ?> hrs avg</span>
                                </li>
                            <?php endif; ?>
                            <?php if ($stats_all['activity_stats']['active_days'] == 0 && $stats_all['task_stats']['total_tasks'] == 0): ?>
                                <li class="list-group-item">
                                    <i>No recent activity recorded</i>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Performance Trend Chart
            const trendCtx = document.getElementById('performanceChart');
            if (trendCtx) {
                const trendData = <?= json_encode($stats_all['performance_trend']) ?>;
                
                const months = trendData.map(item => item.month);
                const assigned = trendData.map(item => item.tasks_assigned);
                const completed = trendData.map(item => item.tasks_completed);
                
                new Chart(trendCtx, {
                    type: 'line',
                    data: {
                        labels: months,
                        datasets: [
                            {
                                label: 'Tasks Assigned',
                                data: assigned,
                                borderColor: 'rgba(54, 162, 235, 1)',
                                backgroundColor: 'rgba(54, 162, 235, 0.1)',
                                borderWidth: 2,
                                tension: 0.3,
                                fill: true
                            },
                            {
                                label: 'Tasks Completed',
                                data: completed,
                                borderColor: 'rgba(75, 192, 192, 1)',
                                backgroundColor: 'rgba(75, 192, 192, 0.1)',
                                borderWidth: 2,
                                tension: 0.3,
                                fill: true
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'top',
                            },
                            title: {
                                display: true,
                                text: 'Monthly Task Performance'
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Number of Tasks'
                                }
                            },
                            x: {
                                title: {
                                    display: true,
                                    text: 'Month'
                                }
                            }
                        }
                    }
                });
            }
            
            // Tab switching
            const tabEls = document.querySelectorAll('#periodTabs button[data-bs-toggle="tab"]');
            tabEls.forEach(tab => {
                tab.addEventListener('shown.bs.tab', event => {
                    // Could add AJAX loading here for different periods
                    console.log('Switched to:', event.target.textContent);
                });
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