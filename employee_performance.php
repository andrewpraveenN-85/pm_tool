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

// Get all employees performance with FIXED query to avoid duplicates
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
";

$stmt = $db->prepare($performance_query);
$stmt->bindParam(':start_date', $start_date);
$stmt->bindParam(':end_date', $end_date);
$stmt->execute();
$performance_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Calculate performance rating based on new logic
// foreach ($performance_data as &$employee) {
//     $total_overdue = intval($employee['completed_late'] ?? 0) + intval($employee['pending_overdue'] ?? 0);
//     $on_time_rate = floatval($employee['on_time_completion_rate'] ?? 0);
//     $bugs_reported = intval($employee['bugs_reported'] ?? 0);

//     // Performance calculation logic
//     $performance_score = 0;
//     $performance_rating = '';
//     $performance_class = '';
//     $performance_color = '';

//     if ($employee['role'] == 'developer') {
//         // Developer performance logic
//         if ($total_overdue >= 5) {
//             $performance_rating = 'FIRED';
//             $performance_score = 0;
//             $performance_class = 'bg-dark text-white';
//             $performance_color = 'dark';
//         } elseif ($total_overdue >= 3) {
//             $performance_rating = 'VERY BAD';
//             $performance_score = 20;
//             $performance_class = 'performance-very-bad';
//             $performance_color = 'danger';
//         } elseif ($total_overdue >= 1) {
//             $performance_rating = 'BAD';
//             $performance_score = 40;
//             $performance_class = 'performance-bad';
//             $performance_color = 'warning';
//         } elseif ($on_time_rate >= 90 && $bugs_reported == 0) {
//             // Check if completed more tasks than allocated (very good)
//             $completion_rate = floatval($employee['completion_rate'] ?? 0);
//             if ($completion_rate > 100) { // Completed more than allocated
//                 $performance_rating = 'EXCELLENT';
//                 $performance_score = 100;
//                 $performance_class = 'performance-excellent';
//                 $performance_color = 'success';
//             } else {
//                 $performance_rating = 'VERY GOOD';
//                 $performance_score = 90;
//                 $performance_class = 'performance-very-good';
//                 $performance_color = 'info';
//             }
//         } elseif ($on_time_rate >= 80) {
//             $performance_rating = 'GOOD';
//             $performance_score = 80;
//             $performance_class = 'performance-good';
//             $performance_color = 'primary';
//         } elseif ($on_time_rate >= 60) {
//             $performance_rating = 'AVERAGE';
//             $performance_score = 60;
//             $performance_class = 'performance-average';
//             $performance_color = 'secondary';
//         } else {
//             $performance_rating = 'NEEDS IMPROVEMENT';
//             $performance_score = 50;
//             $performance_class = 'performance-needs-improvement';
//             $performance_color = 'warning';
//         }

//         // Deduct points for bugs (2% per bug)
//         $bugs_penalty = min($bugs_reported * 2, 30);
//         $performance_score = max(0, $performance_score - $bugs_penalty);

//     } elseif ($employee['role'] == 'qa') {
//         // QA performance logic
//         $tasks_reviewed = intval($employee['tasks_reviewed_closed'] ?? 0);
//         $tasks_in_review = intval($employee['tasks_in_review'] ?? 0);
//         $total_qa_tasks = $tasks_reviewed + $tasks_in_review;

//         if ($total_qa_tasks > 0) {
//             $qa_efficiency = ($tasks_reviewed / $total_qa_tasks) * 100;

//             if ($qa_efficiency >= 90 && $bugs_reported > 0) {
//                 $performance_rating = 'EXCELLENT';
//                 $performance_score = 95;
//                 $performance_class = 'performance-excellent';
//                 $performance_color = 'success';
//             } elseif ($qa_efficiency >= 80) {
//                 $performance_rating = 'VERY GOOD';
//                 $performance_score = 85;
//                 $performance_class = 'performance-very-good';
//                 $performance_color = 'info';
//             } elseif ($qa_efficiency >= 70) {
//                 $performance_rating = 'GOOD';
//                 $performance_score = 75;
//                 $performance_class = 'performance-good';
//                 $performance_color = 'primary';
//             } elseif ($qa_efficiency >= 50) {
//                 $performance_rating = 'AVERAGE';
//                 $performance_score = 60;
//                 $performance_class = 'performance-average';
//                 $performance_color = 'secondary';
//             } else {
//                 $performance_rating = 'NEEDS IMPROVEMENT';
//                 $performance_score = 40;
//                 $performance_class = 'performance-needs-improvement';
//                 $performance_color = 'warning';
//             }

//             // Bonus for finding bugs (5% per bug found, max 25%)
//             $bugs_bonus = min($bugs_reported * 5, 25);
//             $performance_score = min(100, $performance_score + $bugs_bonus);
//         } else {
//             $performance_rating = 'NO TASKS';
//             $performance_score = 0;
//             $performance_class = 'bg-light text-dark';
//             $performance_color = 'light';
//         }
//     }

//     $employee['performance_score'] = $performance_score;
//     $employee['performance_rating'] = $performance_rating;
//     $employee['performance_class'] = $performance_class;
//     $employee['performance_color'] = $performance_color;
//     $employee['total_overdue'] = $total_overdue;
//     print_r($employee['name'].'</br>'); 
// }
// Get summary statistics with optimized query
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
$performance_data = $performance_data ?: [];
$summary = $summary ?: [
    'total_employees' => 0,
    'avg_completion_rate' => 0,
    'total_overdue' => 0,
    'overall_avg_time' => 0
];

// Remove bug-related summary since bugs table is empty
$summary['total_bugs'] = 0;
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

        .performance-excellent {
            background-color: #d4edda !important;
            border-left: 4px solid #28a745;
        }

        .performance-very-good {
            background-color: #d1ecf1 !important;
            border-left: 4px solid #17a2b8;
        }

        .performance-good {
            background-color: #fff3cd !important;
            border-left: 4px solid #ffc107;
        }

        .performance-average {
            background-color: #f8d7da !important;
            border-left: 4px solid #dc3545;
        }

        .performance-bad {
            background-color: #f5c6cb !important;
            border-left: 4px solid #dc3545;
        }

        .performance-very-bad {
            background-color: #721c24 !important;
            color: white !important;
            border-left: 4px solid #dc3545;
        }

        .performance-needs-improvement {
            background-color: #ffeaa7 !important;
            border-left: 4px solid #fdcb6e;
        }

        .performance-fired {
            background-color: #2d3436 !important;
            color: white !important;
            border-left: 4px solid #000;
        }

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

        .performance-bar {
            width: 100px;
            height: 20px;
            background: linear-gradient(90deg,
                    #dc3545 0%,
                    #dc3545 20%,
                    #ffc107 20%,
                    #ffc107 40%,
                    #17a2b8 40%,
                    #17a2b8 60%,
                    #28a745 60%,
                    #28a745 80%,
                    #198754 80%,
                    #198754 100%);
            position: relative;
        }

        .performance-marker {
            position: absolute;
            top: -5px;
            width: 3px;
            height: 30px;
            background-color: #000;
        }

        .employee-row:hover {
            transform: scale(1.002);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transition: all 0.2s ease;
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
                <p class="text-muted">Overall performance metrics for all employees (Only employees with tasks are shown)</p>
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

        <!-- Performance Legend -->
        <div class="row mb-4 no-print">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Performance Rating Legend</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Developers:</h6>
                                <div class="d-flex flex-wrap gap-2 mb-3">
                                    <span class="badge bg-success">EXCELLENT: >100% completion, 0 bugs</span>
                                    <span class="badge bg-info">VERY GOOD: ≥90% on-time, 0 bugs</span>
                                    <span class="badge bg-primary">GOOD: ≥80% on-time</span>
                                    <span class="badge bg-secondary">AVERAGE: ≥60% on-time</span>
                                    <span class="badge bg-warning">BAD: 1-2 overdue tasks</span>
                                    <span class="badge bg-danger">VERY BAD: 3-4 overdue tasks</span>
                                    <span class="badge bg-dark">FIRED: 5+ overdue tasks</span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h6>QA Engineers:</h6>
                                <div class="d-flex flex-wrap gap-2">
                                    <span class="badge bg-success">EXCELLENT: ≥90% efficiency, bugs found</span>
                                    <span class="badge bg-info">VERY GOOD: ≥80% efficiency</span>
                                    <span class="badge bg-primary">GOOD: ≥70% efficiency</span>
                                    <span class="badge bg-secondary">AVERAGE: ≥50% efficiency</span>
                                    <span class="badge bg-warning">NEEDS IMPROVEMENT: <50% efficiency</span>
                                </div>
                            </div>
                        </div>
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
                        <p class="mb-0">Active Employees</p>
                        <small>With assigned tasks</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card text-center bg-success text-white">
                    <div class="card-body">
                        <h3><?= number_format($summary['avg_completion_rate'], 1) ?>%</h3>
                        <p class="mb-0">Avg. Completion Rate</p>
                        <small>All employees</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card text-center bg-warning text-dark">
                    <div class="card-body">
                        <h3><?= htmlspecialchars($summary['total_overdue']) ?></h3>
                        <p class="mb-0">Total Overdue Tasks</p>
                        <small>Requiring attention</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card text-center bg-info text-white">
                    <div class="card-body">
                        <h3><?= htmlspecialchars($summary['overall_avg_time']) ?></h3>
                        <p class="mb-0">Avg. Completion Time</p>
                        <small>Hours per task</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Performance Table -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Individual Employee Performance</h5>
                <p class="mb-0 text-muted" style="font-size: 0.9rem;">Period: <?= date('M d, Y', strtotime($start_date)) ?> - <?= date('M d, Y', strtotime($end_date)) ?></p>
                <?php if (!empty($performance_data)): ?>
                    <p class="mb-0 text-muted" style="font-size: 0.8rem;">Showing <?= count($performance_data) ?> employee(s) with tasks</p>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (empty($performance_data)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> No employee performance data available for the selected period. Employees with 0 tasks are not shown.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>Employee</th>
                                    <th>Role</th>
                                    <th>Tasks</th>
                                    <th>Performance Score</th>
                                    <th>On-Time Rate</th>
                                    <th>Overdue</th>
                                    <th>Performance Rating</th>
                                    <th class="no-print">Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                foreach ($performance_data as $employee):
                                    $total_overdue = intval($employee['completed_late'] ?? 0) + intval($employee['pending_overdue'] ?? 0);
                                    $on_time_rate = floatval($employee['on_time_completion_rate'] ?? 0);
                                    $bugs_reported = intval($employee['bugs_reported'] ?? 0);

                                    // Performance calculation logic
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

                                    $employee['performance_score'] = $performance_score;
                                    $employee['performance_rating'] = $performance_rating;
                                    $employee['performance_class'] = $performance_class;
                                    $employee['performance_color'] = $performance_color;
                                    $employee['total_overdue'] = $total_overdue;
                                    $total_overdue = intval($employee['completed_late'] ?? 0) + intval($employee['pending_overdue'] ?? 0);
                                    $on_time_rate = floatval($employee['on_time_completion_rate'] ?? 0);
                                    $bugs_reported = intval($employee['bugs_reported'] ?? 0);

                                    // Calculate position for performance marker (0-100 scale)
                                    $marker_position = min(100, max(0, $employee['performance_score'] ?? 0));
                                ?>
                                    <tr class="<?= $employee['performance_class'] ?> employee-row">
                                        <td>
                                            <strong><?= htmlspecialchars($employee['name'] ?? 'Unknown') ?></strong>
                                            <br><small class="text-muted"><?= htmlspecialchars($employee['email'] ?? 'N/A') ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= ($employee['role'] ?? '') == 'developer' ? 'info' : 'warning' ?>">
                                                <?= strtoupper($employee['role'] ?? 'unknown') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($employee['total_tasks'] ?? 0) ?></strong>
                                            <br>
                                            <small class="text-muted">
                                                <?= htmlspecialchars($employee['completed_tasks'] ?? 0) ?> completed
                                                <?php if ($employee['role'] == 'qa'): ?>
                                                    <br><?= htmlspecialchars($employee['tasks_in_review'] ?? 0) ?> in review
                                                <?php endif; ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <span class="me-2 fw-bold"><?= number_format($employee['performance_score'], 0) ?>%</span>
                                                <div class="performance-bar">
                                                    <div class="performance-marker" style="left: <?= $marker_position ?>%;"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($employee['role'] == 'developer'): ?>
                                                <div class="d-flex align-items-center">
                                                    <span class="me-2 fw-bold"><?= number_format($on_time_rate, 1) ?>%</span>
                                                    <div class="progress progress-thin flex-grow-1" style="width: 100px;">
                                                        <div class="progress-bar bg-<?=
                                                                                    $on_time_rate >= 90 ? 'success' : ($on_time_rate >= 80 ? 'info' : ($on_time_rate >= 60 ? 'warning' : 'danger'))
                                                                                    ?>" style="width: <?= min(100, $on_time_rate) ?>%"></div>
                                                    </div>
                                                </div>
                                            <?php elseif ($employee['role'] == 'qa'): ?>
                                                <?php
                                                $tasks_reviewed = intval($employee['tasks_reviewed_closed'] ?? 0);
                                                $tasks_in_review = intval($employee['tasks_in_review'] ?? 0);
                                                $total_qa_tasks = $tasks_reviewed + $tasks_in_review;
                                                $qa_efficiency = $total_qa_tasks > 0 ? ($tasks_reviewed / $total_qa_tasks) * 100 : 0;
                                                ?>
                                                <div class="d-flex align-items-center">
                                                    <span class="me-2 fw-bold"><?= number_format($qa_efficiency, 1) ?>%</span>
                                                    <div class="progress progress-thin flex-grow-1" style="width: 100px;">
                                                        <div class="progress-bar bg-<?=
                                                                                    $qa_efficiency >= 90 ? 'success' : ($qa_efficiency >= 80 ? 'info' : ($qa_efficiency >= 70 ? 'primary' : ($qa_efficiency >= 50 ? 'secondary' : 'warning')))
                                                                                    ?>" style="width: <?= min(100, $qa_efficiency) ?>%"></div>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($total_overdue > 0): ?>
                                                <span class="badge bg-danger">
                                                    <?= $total_overdue ?> overdue
                                                    <br>
                                                    <small>
                                                        (<?= htmlspecialchars($employee['completed_late'] ?? 0) ?> late,
                                                        <?= htmlspecialchars($employee['pending_overdue'] ?? 0) ?> pending)
                                                    </small>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-success">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $employee['performance_color'] ?>">
                                                <?= $employee['performance_rating'] ?>
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
                        <h5 class="mb-0">Overdue Tasks Analysis</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="overdueChart" height="250"></canvas>
                        <div id="overdueChartNoData" class="no-data-message" style="display: none;">
                            <p class="text-muted">No overdue task data available.</p>
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
            const performanceData = <?= json_encode($performance_data) ?>;

            if (performanceData && performanceData.length > 0) {
                // Count performance ratings
                const ratings = {
                    'EXCELLENT': 0,
                    'VERY GOOD': 0,
                    'GOOD': 0,
                    'AVERAGE': 0,
                    'NEEDS IMPROVEMENT': 0,
                    'BAD': 0,
                    'VERY BAD': 0,
                    'FIRED': 0,
                    'NO TASKS': 0
                };

                performanceData.forEach(emp => {
                    const rating = emp.performance_rating || 'NO RATING';
                    if (ratings[rating] !== undefined) {
                        ratings[rating]++;
                    }
                });

                // Filter out zero counts
                const labels = [];
                const data = [];
                const colors = [];

                Object.entries(ratings).forEach(([rating, count]) => {
                    if (count > 0) {
                        labels.push(rating);
                        data.push(count);

                        // Assign colors based on rating
                        if (rating === 'EXCELLENT') colors.push('#28a745');
                        else if (rating === 'VERY GOOD') colors.push('#17a2b8');
                        else if (rating === 'GOOD') colors.push('#ffc107');
                        else if (rating === 'AVERAGE') colors.push('#6c757d');
                        else if (rating === 'NEEDS IMPROVEMENT') colors.push('#fd7e14');
                        else if (rating === 'BAD') colors.push('#dc3545');
                        else if (rating === 'VERY BAD') colors.push('#721c24');
                        else if (rating === 'FIRED') colors.push('#2d3436');
                        else colors.push('#adb5bd');
                    }
                });

                if (data.length > 0) {
                    try {
                        new Chart(performanceCtx.getContext('2d'), {
                            type: 'doughnut',
                            data: {
                                labels: labels,
                                datasets: [{
                                    data: data,
                                    backgroundColor: colors,
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
                                                return `${label}: ${value} employee(s) (${percentage}%)`;
                                            }
                                        }
                                    }
                                }
                            }
                        });
                    } catch (error) {
                        console.error('Error creating performance chart:', error);
                        performanceCtx.style.display = 'none';
                        if (performanceNoData) performanceNoData.style.display = 'flex';
                    }
                } else {
                    performanceCtx.style.display = 'none';
                    if (performanceNoData) performanceNoData.style.display = 'flex';
                }
            } else {
                performanceCtx.style.display = 'none';
                if (performanceNoData) performanceNoData.style.display = 'flex';
            }
        }

        // Overdue Tasks Chart
        const overdueCtx = document.getElementById('overdueChart');
        const overdueNoData = document.getElementById('overdueChartNoData');

        if (overdueCtx) {
            const performanceData = <?= json_encode($performance_data) ?>;

            if (performanceData && performanceData.length > 0) {
                const employeeNames = [];
                const overdueData = [];
                const colors = [];

                // Sort by overdue tasks (descending) and limit to top 10
                const sortedData = [...performanceData].sort((a, b) => {
                    const overdueA = (parseInt(a.completed_late) || 0) + (parseInt(a.pending_overdue) || 0);
                    const overdueB = (parseInt(b.completed_late) || 0) + (parseInt(b.pending_overdue) || 0);
                    return overdueB - overdueA;
                }).slice(0, 10); // Top 10 only

                sortedData.forEach(emp => {
                    const overdue = (parseInt(emp.completed_late) || 0) + (parseInt(emp.pending_overdue) || 0);
                    if (overdue > 0) {
                        employeeNames.push(emp.name.substring(0, 15) + (emp.name.length > 15 ? '...' : ''));
                        overdueData.push(overdue);

                        // Color based on severity
                        if (overdue >= 5) colors.push('#721c24');
                        else if (overdue >= 3) colors.push('#dc3545');
                        else if (overdue >= 1) colors.push('#fd7e14');
                        else colors.push('#ffc107');
                    }
                });

                if (overdueData.length > 0) {
                    try {
                        new Chart(overdueCtx.getContext('2d'), {
                            type: 'bar',
                            data: {
                                labels: employeeNames,
                                datasets: [{
                                    label: 'Overdue Tasks',
                                    data: overdueData,
                                    backgroundColor: colors,
                                    borderColor: colors.map(color => color.replace('0.8', '1')),
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
                                                const empName = sortedData[context.dataIndex]?.name || 'Unknown';
                                                const completedLate = sortedData[context.dataIndex]?.completed_late || 0;
                                                const pendingOverdue = sortedData[context.dataIndex]?.pending_overdue || 0;
                                                return [
                                                    `Employee: ${empName}`,
                                                    `Total Overdue: ${context.raw}`,
                                                    `• Completed Late: ${completedLate}`,
                                                    `• Pending Overdue: ${pendingOverdue}`
                                                ];
                                            }
                                        }
                                    }
                                },
                                scales: {
                                    y: {
                                        beginAtZero: true,
                                        title: {
                                            display: true,
                                            text: 'Number of Overdue Tasks'
                                        },
                                        ticks: {
                                            stepSize: 1
                                        }
                                    },
                                    x: {
                                        ticks: {
                                            maxRotation: 45,
                                            minRotation: 45
                                        }
                                    }
                                }
                            }
                        });
                    } catch (error) {
                        console.error('Error creating overdue chart:', error);
                        overdueCtx.style.display = 'none';
                        if (overdueNoData) overdueNoData.style.display = 'flex';
                    }
                } else {
                    overdueCtx.style.display = 'none';
                    if (overdueNoData) overdueNoData.style.display = 'flex';
                }
            } else {
                overdueCtx.style.display = 'none';
                if (overdueNoData) overdueNoData.style.display = 'flex';
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