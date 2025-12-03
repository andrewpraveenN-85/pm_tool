<?php
// index.php - Homepage with Current Standings

include 'config/database.php';
include 'includes/auth.php';

$database = new Database();
$db = $database->getConnection();

// Check if user is already logged in
$isLoggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);

// Get current date range (last 30 days by default)
$end_date = date('Y-m-d');
$start_date = date('Y-m-d', strtotime('-30 days'));

// Function to calculate performance rating (same logic as employee_performance.php)
function calculatePerformanceRating($employee) {
    $total_overdue = intval($employee['completed_late'] ?? 0) + intval($employee['pending_overdue'] ?? 0);
    $on_time_rate = floatval($employee['on_time_completion_rate'] ?? 0);
    $bugs_reported = intval($employee['bugs_reported'] ?? 0);
    
    $performance_score = 0;
    $performance_rating = '';
    $performance_class = '';
    $performance_color = '';

    if ($employee['role'] == 'developer') {
        // Developer performance logic
        if ($total_overdue >= 5) {
            $performance_rating = 'FIRED';
            $performance_score = 0;
            $performance_class = 'bg-dark text-white';
            $performance_color = 'dark';
        } elseif ($total_overdue >= 3) {
            $performance_rating = 'VERY BAD';
            $performance_score = 20;
            $performance_class = 'performance-very-bad';
            $performance_color = 'danger';
        } elseif ($total_overdue >= 1) {
            $performance_rating = 'BAD';
            $performance_score = 40;
            $performance_class = 'performance-bad';
            $performance_color = 'warning';
        } elseif ($on_time_rate >= 90 && $bugs_reported == 0) {
            // Check if completed more tasks than allocated (very good)
            $completion_rate = floatval($employee['completion_rate'] ?? 0);
            if ($completion_rate > 100) { // Completed more than allocated
                $performance_rating = 'EXCELLENT';
                $performance_score = 100;
                $performance_class = 'performance-excellent';
                $performance_color = 'success';
            } else {
                $performance_rating = 'VERY GOOD';
                $performance_score = 90;
                $performance_class = 'performance-very-good';
                $performance_color = 'info';
            }
        } elseif ($on_time_rate >= 80) {
            $performance_rating = 'GOOD';
            $performance_score = 80;
            $performance_class = 'performance-good';
            $performance_color = 'primary';
        } elseif ($on_time_rate >= 60) {
            $performance_rating = 'AVERAGE';
            $performance_score = 60;
            $performance_class = 'performance-average';
            $performance_color = 'secondary';
        } else {
            $performance_rating = 'NEEDS IMPROVEMENT';
            $performance_score = 50;
            $performance_class = 'performance-needs-improvement';
            $performance_color = 'warning';
        }

        // Deduct points for bugs (2% per bug)
        $bugs_penalty = min($bugs_reported * 2, 30);
        $performance_score = max(0, $performance_score - $bugs_penalty);

    } elseif ($employee['role'] == 'qa') {
        // QA performance logic
        $tasks_reviewed = intval($employee['tasks_reviewed_closed'] ?? 0);
        $tasks_in_review = intval($employee['tasks_in_review'] ?? 0);
        $total_qa_tasks = $tasks_reviewed + $tasks_in_review;

        if ($total_qa_tasks > 0) {
            $qa_efficiency = ($tasks_reviewed / $total_qa_tasks) * 100;

            if ($qa_efficiency >= 90 && $bugs_reported > 0) {
                $performance_rating = 'EXCELLENT';
                $performance_score = 95;
                $performance_class = 'performance-excellent';
                $performance_color = 'success';
            } elseif ($qa_efficiency >= 80) {
                $performance_rating = 'VERY GOOD';
                $performance_score = 85;
                $performance_class = 'performance-very-good';
                $performance_color = 'info';
            } elseif ($qa_efficiency >= 70) {
                $performance_rating = 'GOOD';
                $performance_score = 75;
                $performance_class = 'performance-good';
                $performance_color = 'primary';
            } elseif ($qa_efficiency >= 50) {
                $performance_rating = 'AVERAGE';
                $performance_score = 60;
                $performance_class = 'performance-average';
                $performance_color = 'secondary';
            } else {
                $performance_rating = 'NEEDS IMPROVEMENT';
                $performance_score = 40;
                $performance_class = 'performance-needs-improvement';
                $performance_color = 'warning';
            }

            // Bonus for finding bugs (5% per bug found, max 25%)
            $bugs_bonus = min($bugs_reported * 5, 25);
            $performance_score = min(100, $performance_score + $bugs_bonus);
        } else {
            $performance_rating = 'NO TASKS';
            $performance_score = 0;
            $performance_class = 'bg-light text-dark';
            $performance_color = 'light';
        }
    }

    return [
        'performance_score' => $performance_score,
        'performance_rating' => $performance_rating,
        'performance_class' => $performance_class,
        'performance_color' => $performance_color,
        'total_overdue' => $total_overdue
    ];
}

// Get current standings with same query as employee_performance.php but for recent period
$performance_query = "
    SELECT DISTINCT
        u.id,
        u.name,
        u.email,
        u.role,
        COALESCE(tm.total_tasks, 0) as total_tasks,
        COALESCE(tm.completed_tasks, 0) as completed_tasks,
        COALESCE(tm.on_track_tasks, 0) as on_track_tasks,
        COALESCE(tm.completed_on_time, 0) as completed_on_time,
        COALESCE(tm.completed_late, 0) as completed_late,
        COALESCE(tm.pending_overdue, 0) as pending_overdue,
        COALESCE(tm.completion_rate, 0) as completion_rate,
        COALESCE(tm.on_time_completion_rate, 0) as on_time_completion_rate,
        COALESCE(tm.avg_completion_hours, 0) as avg_completion_hours,
        0 as total_bugs_in_tasks,
        0 as bugs_reported,
        0 as bugs_resolved,
        COALESCE(qam.tasks_in_review, 0) as tasks_in_review,
        COALESCE(qam.tasks_reviewed_closed, 0) as tasks_reviewed_closed
    FROM users u
    LEFT JOIN (
        SELECT 
            ta.user_id,
            COUNT(DISTINCT t.id) as total_tasks,
            SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) as completed_tasks,
            SUM(CASE WHEN t.status IN ('in_progress', 'assigned') AND t.end_datetime >= NOW() THEN 1 ELSE 0 END) as on_track_tasks,
            SUM(CASE WHEN t.status = 'closed' AND t.updated_at <= t.end_datetime THEN 1 ELSE 0 END) as completed_on_time,
            SUM(CASE WHEN t.status = 'closed' AND t.updated_at > t.end_datetime THEN 1 ELSE 0 END) as completed_late,
            SUM(CASE WHEN t.status != 'closed' AND t.end_datetime < NOW() THEN 1 ELSE 0 END) as pending_overdue,
            CASE 
                WHEN COUNT(DISTINCT t.id) > 0 
                THEN (SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) * 100.0 / COUNT(DISTINCT t.id))
                ELSE 0 
            END as completion_rate,
            CASE 
                WHEN SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) > 0 
                THEN (SUM(CASE WHEN t.status = 'closed' AND t.updated_at <= t.end_datetime THEN 1 ELSE 0 END) * 100.0 / SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END))
                ELSE 0 
            END as on_time_completion_rate,
            AVG(CASE WHEN t.status = 'closed' THEN TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at) ELSE NULL END) as avg_completion_hours
        FROM task_assignments ta
        LEFT JOIN tasks t ON ta.task_id = t.id AND t.created_at BETWEEN :start_date AND :end_date
        GROUP BY ta.user_id
    ) tm ON u.id = tm.user_id
    LEFT JOIN (
        SELECT 
            ta.user_id,
            COUNT(DISTINCT CASE WHEN t.status = 'in_review' THEN t.id END) as tasks_in_review,
            COUNT(DISTINCT CASE WHEN t.status = 'closed' THEN t.id END) as tasks_reviewed_closed
        FROM task_assignments ta
        LEFT JOIN tasks t ON ta.task_id = t.id AND t.created_at BETWEEN :start_date AND :end_date
        GROUP BY ta.user_id
    ) qam ON u.id = qam.user_id
    WHERE u.role IN ('developer', 'qa') 
      AND u.status = 'active'
      AND COALESCE(tm.total_tasks, 0) > 0
    ORDER BY COALESCE(tm.completion_rate, 0) DESC, COALESCE(tm.total_tasks, 0) DESC
    LIMIT 10
";

$stmt = $db->prepare($performance_query);
$stmt->bindParam(':start_date', $start_date);
$stmt->bindParam(':end_date', $end_date);
$stmt->execute();
$currentStandings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate performance ratings for each employee
foreach ($currentStandings as &$employee) {
    $performanceData = calculatePerformanceRating($employee);
    $employee = array_merge($employee, $performanceData);
}

// Get top performers by role
$topDevelopers = array_filter($currentStandings, function($emp) {
    return $emp['role'] == 'developer';
});
$topDevelopers = array_slice($topDevelopers, 0, 5);

$topQA = array_filter($currentStandings, function($emp) {
    return $emp['role'] == 'qa';
});
$topQA = array_slice($topQA, 0, 5);

// Get summary statistics
$summary_query = "
    SELECT 
        COUNT(DISTINCT u.id) as total_employees,
        CASE 
            WHEN SUM(COALESCE(tm.total_tasks, 0)) > 0 
            THEN (SUM(COALESCE(tm.completed_tasks, 0)) * 100.0 / SUM(COALESCE(tm.total_tasks, 0)))
            ELSE 0 
        END as avg_completion_rate,
        SUM(COALESCE(tm.completed_late, 0) + COALESCE(tm.pending_overdue, 0)) as total_overdue,
        AVG(COALESCE(tm.avg_completion_hours, 0)) as overall_avg_time
    FROM users u
    LEFT JOIN (
        SELECT 
            ta.user_id,
            COUNT(DISTINCT t.id) as total_tasks,
            SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) as completed_tasks,
            SUM(CASE WHEN t.status = 'closed' AND t.updated_at > t.end_datetime THEN 1 ELSE 0 END) as completed_late,
            SUM(CASE WHEN t.status != 'closed' AND t.end_datetime < NOW() THEN 1 ELSE 0 END) as pending_overdue,
            AVG(CASE WHEN t.status = 'closed' THEN TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at) ELSE NULL END) as avg_completion_hours
        FROM task_assignments ta
        LEFT JOIN tasks t ON ta.task_id = t.id AND t.created_at BETWEEN :start_date AND :end_date
        GROUP BY ta.user_id
    ) tm ON u.id = tm.user_id
    WHERE u.role IN ('developer', 'qa') AND u.status = 'active'
    HAVING COUNT(DISTINCT u.id) > 0
";

$summary_stmt = $db->prepare($summary_query);
$summary_stmt->bindParam(':start_date', $start_date);
$summary_stmt->bindParam(':end_date', $end_date);
$summary_stmt->execute();
$summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

// Default values if no data
$summary = $summary ?: [
    'total_employees' => 0,
    'avg_completion_rate' => 0,
    'total_overdue' => 0,
    'overall_avg_time' => 0
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performance Dashboard - Current Standings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .hero-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 80px 0;
            margin-bottom: 50px;
            border-radius: 0 0 20px 20px;
        }
        .standing-card {
            transition: all 0.3s ease;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            overflow: hidden;
            height: 100%;
        }
        .standing-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        .rank-badge {
            position: absolute;
            top: 10px;
            left: 10px;
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
            font-size: 1rem;
        }
        .rank-1 {
            background: linear-gradient(135deg, #FFD700 0%, #FFA500 100%);
        }
        .rank-2 {
            background: linear-gradient(135deg, #C0C0C0 0%, #A9A9A9 100%);
        }
        .rank-3 {
            background: linear-gradient(135deg, #CD7F32 0%, #8B4513 100%);
        }
        .rank-other {
            background: linear-gradient(135deg, #36a2eb 0%, #2a8bd9 100%);
        }
        .performance-badge {
            font-size: 0.75rem;
            padding: 4px 8px;
            border-radius: 4px;
        }
        .badge-excellent { background-color: #28a745; color: white; }
        .badge-very-good { background-color: #17a2b8; color: white; }
        .badge-good { background-color: #ffc107; color: black; }
        .badge-average { background-color: #6c757d; color: white; }
        .badge-needs-improvement { background-color: #fd7e14; color: white; }
        .badge-bad { background-color: #dc3545; color: white; }
        .badge-very-bad { background-color: #721c24; color: white; }
        .badge-fired { background-color: #2d3436; color: white; }
        .stat-card {
            transition: transform 0.2s;
            border-radius: 10px;
        }
        .stat-card:hover {
            transform: translateY(-3px);
        }
        .progress-thin {
            height: 8px;
        }
        .period-info {
            font-size: 0.85rem;
            color: #6c757d;
        }
        .role-badge {
            font-size: 0.8rem;
            padding: 3px 8px;
        }
        .badge-developer { background-color: #17a2b8; }
        .badge-qa { background-color: #ffc107; color: black; }
        .login-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 12px 30px;
            font-size: 1.1rem;
            transition: all 0.3s ease;
        }
        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        .no-data-section {
            min-height: 400px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-chart-line text-primary"></i>
                <span class="fw-bold ms-2">PerfTrack</span>
            </a>
            <?php if ($isLoggedIn): ?>
                <div class="navbar-nav ms-auto">
                    <a href="dashboard.php" class="nav-link btn btn-primary text-white me-2">Dashboard</a>
                    <a href="logout.php" class="nav-link btn btn-outline-danger">Logout</a>
                </div>
            <?php else: ?>
                <div class="navbar-nav ms-auto">
                    <a href="login.php" class="nav-link btn btn-primary text-white">Login</a>
                </div>
            <?php endif; ?>
        </div>
    </nav>

    <!-- Hero Section -->
    <div class="hero-section">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-8">
                    <h1 class="display-4 fw-bold mb-3">Current Performance Standings</h1>
                    <p class="lead mb-4">Real-time performance rankings based on task completion, timeliness, and quality metrics.</p>
                    <p class="period-info">
                        <i class="fas fa-calendar-alt me-1"></i>
                        Period: <?= date('M d', strtotime($start_date)) ?> - <?= date('M d, Y', strtotime($end_date)) ?>
                    </p>
                    <div class="d-flex gap-3">
                        <?php if ($isLoggedIn): ?>
                            <a href="dashboard.php" class="btn btn-light btn-lg">
                                <i class="fas fa-tachometer-alt me-2"></i>Full Dashboard
                            </a>
                            <a href="employee_performance.php" class="btn btn-outline-light btn-lg">
                                <i class="fas fa-users me-2"></i>Detailed Reports
                            </a>
                        <?php else: ?>
                            <a href="login.php" class="btn btn-light btn-lg login-btn">
                                <i class="fas fa-sign-in-alt me-2"></i>Login for Full Access
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-lg-4 text-center">
                    <i class="fas fa-trophy fa-10x" style="opacity: 0.8;"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="container py-5">
        <!-- Summary Statistics -->
        <div class="row mb-5">
            <div class="col-md-3 mb-4">
                <div class="card stat-card text-center bg-primary text-white">
                    <div class="card-body">
                        <h3><?= htmlspecialchars($summary['total_employees']) ?></h3>
                        <p class="mb-0">Active Employees</p>
                        <small>With tasks in period</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-4">
                <div class="card stat-card text-center bg-success text-white">
                    <div class="card-body">
                        <h3><?= number_format($summary['avg_completion_rate'], 1) ?>%</h3>
                        <p class="mb-0">Avg. Completion</p>
                        <small>Team average</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-4">
                <div class="card stat-card text-center bg-warning text-dark">
                    <div class="card-body">
                        <h3><?= htmlspecialchars($summary['total_overdue']) ?></h3>
                        <p class="mb-0">Overdue Tasks</p>
                        <small>Requiring attention</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-4">
                <div class="card stat-card text-center bg-info text-white">
                    <div class="card-body">
                        <h3><?= number_format($summary['overall_avg_time'], 1) ?></h3>
                        <p class="mb-0">Avg. Hours</p>
                        <small>Per task completion</small>
                    </div>
                </div>
            </div>
        </div>

        <?php if (empty($currentStandings)): ?>
            <!-- No Data Available -->
            <div class="no-data-section">
                <div class="text-center">
                    <i class="fas fa-users fa-5x text-muted mb-4"></i>
                    <h3 class="mb-3">No Performance Data Available</h3>
                    <p class="text-muted mb-4">There is no performance data available for the current period.</p>
                    <?php if (!$isLoggedIn): ?>
                        <a href="login.php" class="btn btn-primary btn-lg">
                            <i class="fas fa-sign-in-alt me-2"></i>Login to Get Started
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <!-- Current Standings -->
            <div class="row mb-5">
                <div class="col-12">
                    <h2 class="mb-4">Current Standings</h2>
                    <p class="text-muted mb-4">Top performers ranked by performance score (last 30 days)</p>
                </div>
                
                <?php foreach ($currentStandings as $index => $employee): 
                    $rank = $index + 1;
                    $rankClass = $rank === 1 ? 'rank-1' : ($rank === 2 ? 'rank-2' : ($rank === 3 ? 'rank-3' : 'rank-other'));
                    $performanceClass = 'badge-' . strtolower(str_replace(' ', '-', $employee['performance_rating']));
                    
                    // Calculate on-time rate or QA efficiency
                    if ($employee['role'] == 'developer') {
                        $efficiency_rate = floatval($employee['on_time_completion_rate'] ?? 0);
                        $efficiency_label = 'On-Time';
                    } else {
                        $tasks_reviewed = intval($employee['tasks_reviewed_closed'] ?? 0);
                        $tasks_in_review = intval($employee['tasks_in_review'] ?? 0);
                        $total_qa_tasks = $tasks_reviewed + $tasks_in_review;
                        $efficiency_rate = $total_qa_tasks > 0 ? ($tasks_reviewed / $total_qa_tasks) * 100 : 0;
                        $efficiency_label = 'Efficiency';
                    }
                ?>
                <div class="col-lg-6 mb-4">
                    <div class="standing-card p-4 position-relative">
                        <div class="rank-badge <?= $rankClass ?>">
                            <?= $rank ?>
                        </div>
                        <div class="d-flex align-items-start">
                            <div class="flex-shrink-0">
                                <div class="rounded-circle bg-light d-flex align-items-center justify-content-center" 
                                     style="width: 70px; height: 70px;">
                                    <i class="fas fa-user fa-2x text-primary"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h5 class="mb-1"><?= htmlspecialchars($employee['name'] ?? 'Unknown') ?></h5>
                                        <p class="mb-1 text-muted small">
                                            <i class="fas fa-envelope me-1"></i>
                                            <?= htmlspecialchars($employee['email'] ?? 'N/A') ?>
                                        </p>
                                    </div>
                                    <span class="badge <?= $performanceClass ?> performance-badge">
                                        <?= $employee['performance_rating'] ?>
                                    </span>
                                </div>
                                
                                <div class="d-flex align-items-center mb-2">
                                    <span class="badge <?= $employee['role'] == 'developer' ? 'badge-developer' : 'badge-qa' ?> role-badge me-2">
                                        <?= strtoupper($employee['role'] ?? 'unknown') ?>
                                    </span>
                                    <span class="fw-bold text-primary me-3">
                                        Score: <?= number_format($employee['performance_score'], 0) ?>%
                                    </span>
                                    <?php if ($employee['total_overdue'] > 0): ?>
                                        <span class="badge bg-danger">
                                            <?= $employee['total_overdue'] ?> overdue
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="row">
                                    <div class="col-6">
                                        <div class="mb-2">
                                            <small class="text-muted d-block">Tasks</small>
                                            <span class="fw-bold"><?= htmlspecialchars($employee['completed_tasks'] ?? 0) ?>/<?= htmlspecialchars($employee['total_tasks'] ?? 0) ?></span>
                                            <small class="text-muted"> completed</small>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="mb-2">
                                            <small class="text-muted d-block"><?= $efficiency_label ?></small>
                                            <span class="fw-bold"><?= number_format($efficiency_rate, 1) ?>%</span>
                                            <div class="progress progress-thin mt-1">
                                                <div class="progress-bar 
                                                    <?php if ($employee['role'] == 'developer'): ?>
                                                        <?= $efficiency_rate >= 90 ? 'bg-success' : ($efficiency_rate >= 80 ? 'bg-info' : ($efficiency_rate >= 60 ? 'bg-warning' : 'bg-danger')) ?>
                                                    <?php else: ?>
                                                        <?= $efficiency_rate >= 90 ? 'bg-success' : ($efficiency_rate >= 80 ? 'bg-info' : ($efficiency_rate >= 70 ? 'bg-primary' : ($efficiency_rate >= 50 ? 'bg-secondary' : 'bg-warning'))) ?>
                                                    <?php endif; ?>"
                                                    style="width: <?= min(100, $efficiency_rate) ?>%">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if ($employee['role'] == 'qa'): ?>
                                <div class="mt-2">
                                    <small class="text-muted">
                                        QA Tasks: <?= htmlspecialchars($employee['tasks_reviewed_closed'] ?? 0) ?> reviewed, 
                                        <?= htmlspecialchars($employee['tasks_in_review'] ?? 0) ?> in review
                                    </small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Role-Based Rankings -->
            <div class="row">
                <?php if (!empty($topDevelopers)): ?>
                <div class="col-lg-6 mb-4">
                    <div class="card h-100">
                        <div class="card-header bg-info text-white">
                            <h4 class="mb-0">
                                <i class="fas fa-code me-2"></i>
                                Top Developers
                            </h4>
                        </div>
                        <div class="card-body">
                            <div class="row row-cols-1 g-3">
                                <?php foreach ($topDevelopers as $index => $developer): 
                                    $rank = $index + 1;
                                ?>
                                <div class="col">
                                    <div class="d-flex align-items-center p-2 border rounded">
                                        <div class="rank-badge <?= $rank === 1 ? 'rank-1' : ($rank === 2 ? 'rank-2' : ($rank === 3 ? 'rank-3' : 'rank-other')) ?>" 
                                             style="position: relative; width: 30px; height: 30px; font-size: 0.9rem;">
                                            <?= $rank ?>
                                        </div>
                                        <div class="ms-3">
                                            <h6 class="mb-1"><?= htmlspecialchars($developer['name']) ?></h6>
                                            <div class="d-flex gap-2">
                                                <span class="badge <?= 'badge-' . strtolower(str_replace(' ', '-', $developer['performance_rating'])) ?>">
                                                    <?= $developer['performance_rating'] ?>
                                                </span>
                                                <span class="text-primary fw-bold">
                                                    <?= number_format($developer['performance_score'], 0) ?>%
                                                </span>
                                            </div>
                                        </div>
                                        <div class="ms-auto text-end">
                                            <small class="text-muted d-block">Tasks</small>
                                            <span class="fw-bold"><?= $developer['completed_tasks'] ?>/<?= $developer['total_tasks'] ?></span>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($topQA)): ?>
                <div class="col-lg-6 mb-4">
                    <div class="card h-100">
                        <div class="card-header bg-warning text-dark">
                            <h4 class="mb-0">
                                <i class="fas fa-bug me-2"></i>
                                Top QA Engineers
                            </h4>
                        </div>
                        <div class="card-body">
                            <div class="row row-cols-1 g-3">
                                <?php foreach ($topQA as $index => $qa): 
                                    $rank = $index + 1;
                                ?>
                                <div class="col">
                                    <div class="d-flex align-items-center p-2 border rounded">
                                        <div class="rank-badge <?= $rank === 1 ? 'rank-1' : ($rank === 2 ? 'rank-2' : ($rank === 3 ? 'rank-3' : 'rank-other')) ?>" 
                                             style="position: relative; width: 30px; height: 30px; font-size: 0.9rem;">
                                            <?= $rank ?>
                                        </div>
                                        <div class="ms-3">
                                            <h6 class="mb-1"><?= htmlspecialchars($qa['name']) ?></h6>
                                            <div class="d-flex gap-2">
                                                <span class="badge <?= 'badge-' . strtolower(str_replace(' ', '-', $qa['performance_rating'])) ?>">
                                                    <?= $qa['performance_rating'] ?>
                                                </span>
                                                <span class="text-primary fw-bold">
                                                    <?= number_format($qa['performance_score'], 0) ?>%
                                                </span>
                                            </div>
                                        </div>
                                        <div class="ms-auto text-end">
                                            <small class="text-muted d-block">Reviewed</small>
                                            <span class="fw-bold"><?= $qa['tasks_reviewed_closed'] ?></span>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Login Prompt Section -->
        <?php if (!$isLoggedIn): ?>
        <div class="row mt-5">
            <div class="col-12">
                <div class="card bg-light border-0">
                    <div class="card-body text-center py-5">
                        <h3 class="mb-3">Get Complete Performance Insights</h3>
                        <p class="text-muted mb-4">Login to access detailed analytics, historical data, and team management features.</p>
                        <a href="login.php" class="btn btn-primary btn-lg login-btn">
                            <i class="fas fa-sign-in-alt me-2"></i>Login to Dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Footer -->
    <footer class="bg-dark text-light text-center py-4 mt-5">
        <div class="container">
            <p class="mb-0">Developed by APNLAB. 2025.</p>
            <p class="mb-0 small text-muted">Employee Performance Tracking System</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>