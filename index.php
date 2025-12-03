<?php
// index.php - Homepage with Current Standings

include 'config/database.php';
include 'includes/auth.php';

$database = new Database();
$db = $database->getConnection();

// Check if user is already logged in
$isLoggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);

// Function to get default profile picture URL (same as users.php)
function getDefaultProfilePicture()
{
    return 'https://ui-avatars.com/api/?name=User&background=007bff&color=fff&size=100';
}

// Function to get profile picture URL with fallback (same as users.php)
function getProfilePicture($userImage, $userName, $size = 100)
{
    if (!empty($userImage) && file_exists($userImage)) {
        return $userImage;
    }

    // Generate avatar with user's initials
    $initials = '';
    $nameParts = explode(' ', $userName);
    if (count($nameParts) > 0) {
        $initials = strtoupper(substr($nameParts[0], 0, 1));
        if (count($nameParts) > 1) {
            $initials .= strtoupper(substr($nameParts[1], 0, 1));
        }
    }

    if (empty($initials)) {
        $initials = 'U';
    }

    return 'https://ui-avatars.com/api/?name=' . urlencode($initials) . '&background=007bff&color=fff&size=' . $size;
}

// Get current date range (last 30 days by default)
$end_date = date('Y-m-d');
$start_date = date('Y-m-d', strtotime('-30 days'));

// Function to calculate performance rating (same logic as employee_performance.php)
function calculatePerformanceRating($employee)
{
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
        u.image as user_image,
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
    LIMIT 3
";

$stmt = $db->prepare($performance_query);
$stmt->bindParam(':start_date', $start_date);
$stmt->bindParam(':end_date', $end_date);
$stmt->execute();
$currentStandings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate performance ratings for each employee
foreach ($currentStandings as &$employee) {
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Current Performance Standings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .standing-card {
            transition: all 0.3s ease;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            overflow: hidden;
            background: white;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .standing-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }

        .rank-badge {
            position: absolute;
            top: 15px;
            left: 15px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
            font-size: 1.4rem;
            z-index: 1;
        }

        .rank-1 {
            background: linear-gradient(135deg, #FFD700 0%, #FFA500 100%);
            border: 3px solid #ffd700;
        }

        .rank-2 {
            background: linear-gradient(135deg, #C0C0C0 0%, #A9A9A9 100%);
            border: 3px solid #c0c0c0;
        }

        .rank-3 {
            background: linear-gradient(135deg, #CD7F32 0%, #8B4513 100%);
            border: 3px solid #cd7f32;
        }

        .performance-badge {
            font-size: 0.8rem;
            padding: 5px 10px;
            border-radius: 4px;
            font-weight: 600;
        }

        .badge-excellent {
            background-color: #28a745;
            color: white;
        }

        .badge-very-good {
            background-color: #17a2b8;
            color: white;
        }

        .badge-good {
            background-color: #ffc107;
            color: black;
        }

        .badge-average {
            background-color: #6c757d;
            color: white;
        }

        .badge-needs-improvement {
            background-color: #fd7e14;
            color: white;
        }

        .badge-bad {
            background-color: #dc3545;
            color: white;
        }

        .badge-very-bad {
            background-color: #721c24;
            color: white;
        }

        .badge-fired {
            background-color: #2d3436;
            color: white;
        }

        .progress-thin {
            height: 8px;
            border-radius: 4px;
            background-color: #e9ecef;
        }

        .period-info {
            font-size: 0.9rem;
            color: #6c757d;
        }

        .role-badge {
            font-size: 0.85rem;
            padding: 4px 10px;
            border-radius: 4px;
            font-weight: 600;
        }

        .badge-developer {
            background-color: #17a2b8;
            color: white;
        }

        .badge-qa {
            background-color: #ffc107;
            color: black;
        }

        .page-title {
            color: #2c3e50;
            font-weight: 700;
            margin-bottom: 0.3rem;
            font-size: 1.8rem;
        }

        .page-subtitle {
            color: #7f8c8d;
            margin-bottom: 1.5rem;
        }

        .header-section {
            background: white;
            padding: 1.5rem 0;
            margin-bottom: 2rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .performance-score {
            font-size: 1.8rem;
            font-weight: 700;
            color: #2c3e50;
        }

        .employee-name {
            font-size: 1.2rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.2rem;
        }

        .employee-email {
            font-size: 0.85rem;
            color: #6c757d;
            margin-bottom: 0.5rem;
        }

        .main-content {
            flex: 1;
        }

        .no-data-section {
            min-height: 300px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            padding: 2rem;
        }

        .profile-img {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #e0e0e0;
            background-color: #f8f9fa;
        }

        .default-profile-img {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100px;
            height: 100px;
            border-radius: 50%;
            border: 3px solid #e0e0e0;
            background-color: #007bff;
            color: white;
            font-size: 2rem;
            font-weight: bold;
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
                    <a href="employee_performance.php" class="nav-link btn btn-outline-primary me-2">Full Report</a>
                    <a href="logout.php" class="nav-link btn btn-outline-danger">Logout</a>
                </div>
            <?php else: ?>
                <div class="navbar-nav ms-auto">
                    <a href="login.php" class="nav-link btn btn-primary text-white">Login</a>
                </div>
            <?php endif; ?>
        </div>
    </nav>

    <!-- Page Header -->
    <div class="header-section">
        <div class="container">
            <div class="text-center">
                <h1 class="page-title">Top Performers</h1>
                <p class="page-subtitle mb-0">
                    <i class="fas fa-calendar-alt me-1"></i>
                    Period: <?= date('M d', strtotime($start_date)) ?> - <?= date('M d, Y', strtotime($end_date)) ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="container main-content py-3">
        <?php if (empty($currentStandings)): ?>
            <!-- No Data Available -->
            <div class="no-data-section">
                <div class="text-center">
                    <i class="fas fa-users fa-5x text-muted mb-4"></i>
                    <h3 class="mb-3">No Performance Data Available</h3>
                    <p class="text-muted mb-4">There is no performance data available for the current period.</p>
                    <?php if (!$isLoggedIn): ?>
                        <a href="login.php" class="btn btn-primary">
                            <i class="fas fa-sign-in-alt me-2"></i>Login to Get Started
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <!-- Current Standings - First 3 Best Performers -->
            <div class="row justify-content-center">
                <?php foreach ($currentStandings as $index => $employee):
                    $performanceData = calculatePerformanceRating($employee);
                    $employee = array_merge($employee, $performanceData);
                    $rank = $index + 1;
                    $rankClass = $rank === 1 ? 'rank-1' : ($rank === 2 ? 'rank-2' : 'rank-3');
                    $performanceClass = 'badge-' . strtolower(str_replace(' ', '-', $employee['performance_rating']));

                    // Get profile picture using the same function as users.php
                    $profilePic = getProfilePicture($employee['user_image'] ?? '', $employee['name'], 100);
                    $defaultPic = getDefaultProfilePicture();

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

                    // Determine card width based on rank
                    $colClass = $rank === 1 ? 'col-lg-8 col-md-10' : 'col-lg-6 col-md-8';
                ?>
                    <div class="<?= $colClass ?> mb-4">
                        <div class="standing-card p-4 position-relative">
                            <div class="rank-badge <?= $rankClass ?>">
                                <?= $rank ?>
                            </div>
                            <div class="row align-items-center">
                                <div class="col-md-4 text-center mb-3 mb-md-0">
                                    <div class="position-relative">
                                        <img src="<?= $profilePic ?>"
                                            class="profile-img"
                                            alt="<?= htmlspecialchars($employee['name'] ?? 'Unknown') ?>"
                                            onerror="this.onerror=null; this.src='<?= $defaultPic ?>'">
                                    </div>
                                </div>
                                <div class="col-md-8">
                                    <div class="mb-3">
                                        <h3 class="employee-name"><?= htmlspecialchars($employee['name'] ?? 'Unknown') ?></h3>
                                        <p class="employee-email mb-2">
                                            <i class="fas fa-envelope me-1"></i>
                                            <?= htmlspecialchars($employee['email'] ?? 'N/A') ?>
                                        </p>
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <span class="badge <?= $employee['role'] == 'developer' ? 'badge-developer' : 'badge-qa' ?> role-badge">
                                                <?= strtoupper($employee['role'] ?? 'unknown') ?>
                                            </span>
                                            <span class="badge <?= $performanceClass ?> performance-badge">
                                                <?= $employee['performance_rating'] ?>
                                            </span>
                                            <?php if ($employee['total_overdue'] > 0): ?>
                                                <span class="badge bg-danger small">
                                                    <i class="fas fa-clock me-1"></i><?= $employee['total_overdue'] ?> overdue
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-6">
                                            <div class="mb-3">
                                                <div class="performance-score">
                                                    <?= number_format($employee['performance_score'], 0) ?>%
                                                </div>
                                                <small class="text-muted">Performance Score</small>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="mb-3">
                                                <div class="fw-bold" style="font-size: 1.2rem;">
                                                    <?= htmlspecialchars($employee['completed_tasks'] ?? 0) ?>/<?= htmlspecialchars($employee['total_tasks'] ?? 0) ?>
                                                </div>
                                                <small class="text-muted">Tasks Completed</small>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between mb-1">
                                            <small class="text-muted"><?= $efficiency_label ?> Rate</small>
                                            <small class="fw-bold"><?= number_format($efficiency_rate, 1) ?>%</small>
                                        </div>
                                        <div class="progress progress-thin">
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

                                    <?php if ($employee['role'] == 'qa'): ?>
                                        <div class="small text-muted">
                                            <i class="fas fa-check-circle me-1"></i>
                                            <?= htmlspecialchars($employee['tasks_reviewed_closed'] ?? 0) ?> tasks reviewed
                                            <span class="mx-2">•</span>
                                            <i class="fas fa-hourglass-half me-1"></i>
                                            <?= htmlspecialchars($employee['tasks_in_review'] ?? 0) ?> in review
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Simple Login Note -->
            <?php if (!$isLoggedIn): ?>
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="text-center">
                            <p class="text-muted mb-2 small">
                                <i class="fas fa-info-circle me-1"></i>
                                Login to access detailed performance reports and full dashboard features
                            </p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Footer -->
    <footer class="bg-dark text-light text-center py-3">
        <div class="container">
            <p class="mb-0 small">Developed by APNLAB. 2025. Employee Performance Tracking System</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>