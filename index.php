<?php
// index.php - Homepage with Best Performers Overview

include 'config/database.php';
include 'includes/auth.php';

$database = new Database();
$db = $database->getConnection();

// Check if user is already logged in
$isLoggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);

// Get best performers for last week and month
function getBestPerformers($db, $period = 'week') {
    $end_date = date('Y-m-d');
    
    if ($period === 'week') {
        $start_date = date('Y-m-d', strtotime('-7 days'));
    } else {
        $start_date = date('Y-m-d', strtotime('-30 days'));
    }
    
    $query = "
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
            AVG(CASE WHEN t.status = 'closed' THEN TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at) ELSE NULL END) as avg_completion_hours
        FROM users u
        LEFT JOIN task_assignments ta ON u.id = ta.user_id
        LEFT JOIN tasks t ON ta.task_id = t.id 
            AND t.created_at BETWEEN :start_date AND :end_date
        WHERE u.role IN ('developer', 'qa') 
            AND u.status = 'active'
        GROUP BY u.id, u.name, u.email, u.role
        HAVING completion_rate > 0
        ORDER BY completion_rate DESC
        LIMIT 5
    ";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':start_date', $start_date);
    $stmt->bindParam(':end_date', $end_date);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get best performers
$bestPerformersWeek = getBestPerformers($db, 'week');
$bestPerformersMonth = getBestPerformers($db, 'month');

// If no performers available and user is not logged in, redirect to login
// if (empty($bestPerformersWeek) && empty($bestPerformersMonth) && !$isLoggedIn) {
//     header("Location: login.php");
//     exit;
// }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performance Dashboard - Home</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .hero-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 100px 0;
            margin-bottom: 50px;
            border-radius: 0 0 20px 20px;
        }
        .performer-card {
            transition: all 0.3s ease;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            overflow: hidden;
        }
        .performer-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
            border-color: #667eea;
        }
        .rank-badge {
            position: absolute;
            top: 10px;
            left: 10px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
            font-size: 1.2rem;
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
        .completion-rate {
            font-size: 1.5rem;
            font-weight: bold;
            color: #28a745;
        }
        .feature-icon {
            font-size: 3rem;
            margin-bottom: 20px;
            color: #667eea;
        }
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
        .period-badge {
            font-size: 0.8rem;
            padding: 3px 10px;
            border-radius: 20px;
        }
        .badge-week {
            background-color: #28a745;
            color: white;
        }
        .badge-month {
            background-color: #17a2b8;
            color: white;
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
                    <h1 class="display-4 fw-bold mb-3">Employee Performance Dashboard</h1>
                    <p class="lead mb-4">Track, analyze, and optimize your team's productivity with real-time performance metrics and insights.</p>
                    <div class="d-flex gap-3">
                        <?php if ($isLoggedIn): ?>
                            <a href="dashboard.php" class="btn btn-light btn-lg">
                                <i class="fas fa-tachometer-alt me-2"></i>Go to Dashboard
                            </a>
                            <a href="employee_performance.php" class="btn btn-outline-light btn-lg">
                                <i class="fas fa-users me-2"></i>View Performance
                            </a>
                        <?php else: ?>
                            <a href="login.php" class="btn btn-light btn-lg login-btn">
                                <i class="fas fa-sign-in-alt me-2"></i>Login to Access Dashboard
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
        <?php if (empty($bestPerformersWeek) && empty($bestPerformersMonth)): ?>
            <!-- No Data Available -->
            <div class="no-data-section">
                <div class="text-center">
                    <i class="fas fa-users fa-5x text-muted mb-4"></i>
                    <h3 class="mb-3">No Performance Data Available</h3>
                    <p class="text-muted mb-4">There is no performance data available for the selected periods.</p>
                    <a href="login.php" class="btn btn-primary btn-lg">
                        <i class="fas fa-sign-in-alt me-2"></i>Login to Get Started
                    </a>
                </div>
            </div>
        <?php else: ?>
            <!-- Best Performers Section -->
            <div class="row mb-5">
                <div class="col-12">
                    <h2 class="mb-4">Top Performers</h2>
                </div>
                
                <!-- Last Week's Best Performers -->
                <?php if (!empty($bestPerformersWeek)): ?>
                <div class="col-lg-6 mb-4">
                    <div class="card h-100">
                        <div class="card-header bg-success text-white">
                            <h4 class="mb-0">
                                <i class="fas fa-calendar-week me-2"></i>
                                Best of Last Week
                                <span class="badge bg-light text-success ms-2 period-badge">7 Days</span>
                            </h4>
                        </div>
                        <div class="card-body">
                            <div class="row row-cols-1 g-3">
                                <?php foreach ($bestPerformersWeek as $index => $performer): 
                                    $rank = $index + 1;
                                    $rankClass = $rank === 1 ? 'rank-1' : ($rank === 2 ? 'rank-2' : ($rank === 3 ? 'rank-3' : 'rank-other'));
                                    $completionRate = floatval($performer['completion_rate'] ?? 0);
                                ?>
                                <div class="col">
                                    <div class="performer-card p-3 position-relative">
                                        <div class="rank-badge <?= $rankClass ?>">
                                            <?= $rank ?>
                                        </div>
                                        <div class="d-flex align-items-center">
                                            <div class="flex-shrink-0">
                                                <div class="rounded-circle bg-light d-flex align-items-center justify-content-center" 
                                                     style="width: 60px; height: 60px;">
                                                    <i class="fas fa-user fa-2x text-primary"></i>
                                                </div>
                                            </div>
                                            <div class="flex-grow-1 ms-3">
                                                <h5 class="mb-1"><?= htmlspecialchars($performer['name'] ?? 'Unknown') ?></h5>
                                                <p class="mb-1 text-muted">
                                                    <i class="fas fa-envelope me-1"></i>
                                                    <?= htmlspecialchars($performer['email'] ?? 'N/A') ?>
                                                </p>
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <span class="badge bg-info">
                                                        <?= ucfirst($performer['role'] ?? 'unknown') ?>
                                                    </span>
                                                    <div class="text-end">
                                                        <span class="completion-rate">
                                                            <?= number_format($completionRate, 1) ?>%
                                                        </span>
                                                        <p class="mb-0 small text-muted">
                                                            <?= htmlspecialchars($performer['completed_tasks'] ?? 0) ?>/<?= htmlspecialchars($performer['total_tasks'] ?? 0) ?> tasks
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Last Month's Best Performers -->
                <?php if (!empty($bestPerformersMonth)): ?>
                <div class="col-lg-6 mb-4">
                    <div class="card h-100">
                        <div class="card-header bg-info text-white">
                            <h4 class="mb-0">
                                <i class="fas fa-calendar-alt me-2"></i>
                                Best of Last Month
                                <span class="badge bg-light text-info ms-2 period-badge">30 Days</span>
                            </h4>
                        </div>
                        <div class="card-body">
                            <div class="row row-cols-1 g-3">
                                <?php foreach ($bestPerformersMonth as $index => $performer): 
                                    $rank = $index + 1;
                                    $rankClass = $rank === 1 ? 'rank-1' : ($rank === 2 ? 'rank-2' : ($rank === 3 ? 'rank-3' : 'rank-other'));
                                    $completionRate = floatval($performer['completion_rate'] ?? 0);
                                ?>
                                <div class="col">
                                    <div class="performer-card p-3 position-relative">
                                        <div class="rank-badge <?= $rankClass ?>">
                                            <?= $rank ?>
                                        </div>
                                        <div class="d-flex align-items-center">
                                            <div class="flex-shrink-0">
                                                <div class="rounded-circle bg-light d-flex align-items-center justify-content-center" 
                                                     style="width: 60px; height: 60px;">
                                                    <i class="fas fa-user fa-2x text-primary"></i>
                                                </div>
                                            </div>
                                            <div class="flex-grow-1 ms-3">
                                                <h5 class="mb-1"><?= htmlspecialchars($performer['name'] ?? 'Unknown') ?></h5>
                                                <p class="mb-1 text-muted">
                                                    <i class="fas fa-envelope me-1"></i>
                                                    <?= htmlspecialchars($performer['email'] ?? 'N/A') ?>
                                                </p>
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <span class="badge bg-info">
                                                        <?= ucfirst($performer['role'] ?? 'unknown') ?>
                                                    </span>
                                                    <div class="text-end">
                                                        <span class="completion-rate">
                                                            <?= number_format($completionRate, 1) ?>%
                                                        </span>
                                                        <p class="mb-0 small text-muted">
                                                            <?= htmlspecialchars($performer['completed_tasks'] ?? 0) ?>/<?= htmlspecialchars($performer['total_tasks'] ?? 0) ?> tasks
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
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

            <!-- Features Section -->
            <div class="row mb-5">
                <div class="col-12">
                    <h2 class="mb-4">Dashboard Features</h2>
                </div>
                <div class="col-md-4 mb-4">
                    <div class="card h-100 text-center border-0 shadow-sm">
                        <div class="card-body">
                            <div class="feature-icon">
                                <i class="fas fa-chart-bar"></i>
                            </div>
                            <h4 class="card-title">Performance Analytics</h4>
                            <p class="card-text">Track completion rates, task efficiency, and team productivity with detailed analytics.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-4">
                    <div class="card h-100 text-center border-0 shadow-sm">
                        <div class="card-body">
                            <div class="feature-icon">
                                <i class="fas fa-bug"></i>
                            </div>
                            <h4 class="card-title">Bug Tracking</h4>
                            <p class="card-text">Monitor bugs reported, resolved, and their impact on overall project timelines.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-4">
                    <div class="card h-100 text-center border-0 shadow-sm">
                        <div class="card-body">
                            <div class="feature-icon">
                                <i class="fas fa-trophy"></i>
                            </div>
                            <h4 class="card-title">Performance Recognition</h4>
                            <p class="card-text">Identify top performers and recognize outstanding contributions automatically.</p>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Login Prompt Section -->
        <?php if (!$isLoggedIn): ?>
        <div class="row mt-5">
            <div class="col-12">
                <div class="card bg-light border-0">
                    <div class="card-body text-center py-5">
                        <h3 class="mb-3">Ready to Access Full Features?</h3>
                        <p class="text-muted mb-4">Login to access the complete performance dashboard with all features.</p>
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