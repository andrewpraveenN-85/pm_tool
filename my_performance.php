<?php
include 'config/database.php';
include 'includes/auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireAuth();

$user_id = $_SESSION['user_id'];

// Get user info
$user_query = "SELECT role FROM users WHERE id = :user_id";
$user_stmt = $db->prepare($user_query);
$user_stmt->bindParam(':user_id', $user_id);
$user_stmt->execute();
$user_info = $user_stmt->fetch(PDO::FETCH_ASSOC);
$user_role = $user_info['role'] ?? '';

// Get user performance data with new logic
if ($user_role == 'developer') {
    $performance_query = "
        SELECT 
            -- Task Statistics
            COUNT(DISTINCT t.id) as total_tasks,
            SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) as completed_tasks,
            SUM(CASE WHEN t.status != 'closed' AND t.end_datetime < NOW() THEN 1 ELSE 0 END) as pending_overdue,
            SUM(CASE WHEN t.status = 'closed' AND t.updated_at > t.end_datetime THEN 1 ELSE 0 END) as completed_late,
            -- Completion rates
            CASE 
                WHEN COUNT(DISTINCT t.id) > 0 
                THEN (SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) * 100.0 / COUNT(DISTINCT t.id))
                ELSE 0 
            END as completion_rate,
            -- On-time completion rate
            CASE 
                WHEN SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) > 0 
                THEN (SUM(CASE WHEN t.status = 'closed' AND t.updated_at <= t.end_datetime THEN 1 ELSE 0 END) * 100.0 / SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END))
                ELSE 0 
            END as on_time_completion_rate,
            -- Time Statistics
            AVG(CASE WHEN t.status = 'closed' THEN TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at) ELSE NULL END) as avg_completion_hours,
            MIN(CASE WHEN t.status = 'closed' THEN TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at) ELSE NULL END) as fastest_completion,
            MAX(CASE WHEN t.status = 'closed' THEN TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at) ELSE NULL END) as slowest_completion,
            -- Bug Statistics
            COUNT(DISTINCT b.id) as total_bugs_in_tasks,
            COUNT(DISTINCT CASE WHEN b.created_by = :user_id THEN b.id END) as bugs_created,
            COUNT(DISTINCT CASE WHEN b.status = 'closed' AND b.created_by = :user_id2 THEN b.id END) as bugs_resolved
            
        FROM task_assignments ta
        LEFT JOIN tasks t ON ta.task_id = t.id
        LEFT JOIN bugs b ON t.id = b.task_id
        WHERE ta.user_id = :user_id3
    ";
} else {
    // QA performance query
    $performance_query = "
        SELECT 
            COUNT(DISTINCT t.id) as total_tasks,
            SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) as tasks_reviewed_closed,
            SUM(CASE WHEN t.status = 'in_review' THEN 1 ELSE 0 END) as tasks_in_review,
            COUNT(DISTINCT b.id) as total_bugs_found,
            COUNT(DISTINCT CASE WHEN b.created_by = :user_id THEN b.id END) as bugs_reported,
            COUNT(DISTINCT CASE WHEN b.status = 'closed' THEN b.id END) as bugs_closed_in_reviewed_tasks
        FROM task_assignments ta
        LEFT JOIN tasks t ON ta.task_id = t.id
        LEFT JOIN bugs b ON t.id = b.task_id
        WHERE ta.user_id = :user_id2
    ";
}

$performance_stmt = $db->prepare($performance_query);
$performance_stmt->bindParam(':user_id', $user_id);
$performance_stmt->bindParam(':user_id2', $user_id);
$performance_stmt->bindParam(':user_id3', $user_id);
$performance_stmt->execute();
$performance = $performance_stmt->fetch(PDO::FETCH_ASSOC);

// Calculate performance rating
$performance_rating = '';
$performance_color = '';
$performance_score = 0;

if ($user_role == 'developer') {
    $total_overdue = intval($performance['completed_late'] ?? 0) + intval($performance['pending_overdue'] ?? 0);
    $on_time_rate = floatval($performance['on_time_completion_rate'] ?? 0);
    $bugs_created = intval($performance['bugs_created'] ?? 0);
    
    if ($total_overdue >= 5) {
        $performance_rating = 'FIRED';
        $performance_score = 0;
        $performance_color = 'dark';
    } elseif ($total_overdue >= 3) {
        $performance_rating = 'VERY BAD';
        $performance_score = 20;
        $performance_color = 'danger';
    } elseif ($total_overdue >= 1) {
        $performance_rating = 'BAD';
        $performance_score = 40;
        $performance_color = 'warning';
    } elseif ($on_time_rate >= 90 && $bugs_created == 0) {
        $completion_rate = floatval($performance['completion_rate'] ?? 0);
        if ($completion_rate > 100) {
            $performance_rating = 'EXCELLENT';
            $performance_score = 100;
            $performance_color = 'success';
        } else {
            $performance_rating = 'VERY GOOD';
            $performance_score = 90;
            $performance_color = 'info';
        }
    } elseif ($on_time_rate >= 80) {
        $performance_rating = 'GOOD';
        $performance_score = 80;
        $performance_color = 'primary';
    } elseif ($on_time_rate >= 60) {
        $performance_rating = 'AVERAGE';
        $performance_score = 60;
        $performance_color = 'secondary';
    } else {
        $performance_rating = 'NEEDS IMPROVEMENT';
        $performance_score = 50;
        $performance_color = 'warning';
    }
    
    // Deduct points for bugs (2% per bug)
    $bugs_penalty = min($bugs_created * 2, 30);
    $performance_score = max(0, $performance_score - $bugs_penalty);
    
} elseif ($user_role == 'qa') {
    $tasks_reviewed = intval($performance['tasks_reviewed_closed'] ?? 0);
    $tasks_in_review = intval($performance['tasks_in_review'] ?? 0);
    $total_qa_tasks = $tasks_reviewed + $tasks_in_review;
    $bugs_reported = intval($performance['bugs_reported'] ?? 0);
    
    if ($total_qa_tasks > 0) {
        $qa_efficiency = ($tasks_reviewed / $total_qa_tasks) * 100;
        
        if ($qa_efficiency >= 90 && $bugs_reported > 0) {
            $performance_rating = 'EXCELLENT';
            $performance_score = 95;
            $performance_color = 'success';
        } elseif ($qa_efficiency >= 80) {
            $performance_rating = 'VERY GOOD';
            $performance_score = 85;
            $performance_color = 'info';
        } elseif ($qa_efficiency >= 70) {
            $performance_rating = 'GOOD';
            $performance_score = 75;
            $performance_color = 'primary';
        } elseif ($qa_efficiency >= 50) {
            $performance_rating = 'AVERAGE';
            $performance_score = 60;
            $performance_color = 'secondary';
        } else {
            $performance_rating = 'NEEDS IMPROVEMENT';
            $performance_score = 40;
            $performance_color = 'warning';
        }
        
        // Bonus for finding bugs (5% per bug found, max 25%)
        $bugs_bonus = min($bugs_reported * 5, 25);
        $performance_score = min(100, $performance_score + $bugs_bonus);
    } else {
        $performance_rating = 'NO TASKS';
        $performance_score = 0;
        $performance_color = 'light';
    }
}

// Get recent tasks
$recent_tasks_query = "
    SELECT t.*, p.name as project_name, 
           COUNT(DISTINCT b.id) as bug_count,
           CASE 
               WHEN t.status = 'closed' AND t.updated_at > t.end_datetime THEN 'completed_late'
               WHEN t.status != 'closed' AND t.end_datetime < NOW() THEN 'overdue'
               ELSE 'on_track'
           END as task_status
    FROM task_assignments ta
    LEFT JOIN tasks t ON ta.task_id = t.id
    LEFT JOIN projects p ON t.project_id = p.id
    LEFT JOIN bugs b ON t.id = b.task_id
    WHERE ta.user_id = :user_id
    GROUP BY t.id
    ORDER BY t.updated_at DESC
    LIMIT 10
";

$recent_tasks_stmt = $db->prepare($recent_tasks_query);
$recent_tasks_stmt->bindParam(':user_id', $user_id);
$recent_tasks_stmt->execute();
$recent_tasks = $recent_tasks_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get task distribution by status
$status_distribution_query = "
    SELECT 
        t.status,
        COUNT(*) as count
    FROM task_assignments ta
    LEFT JOIN tasks t ON ta.task_id = t.id
    WHERE ta.user_id = :user_id
    GROUP BY t.status
";

$status_distribution_stmt = $db->prepare($status_distribution_query);
$status_distribution_stmt->bindParam(':user_id', $user_id);
$status_distribution_stmt->execute();
$status_distribution = $status_distribution_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get monthly performance
if ($user_role == 'developer') {
    $monthly_performance_query = "
        SELECT 
            DATE_FORMAT(t.created_at, '%Y-%m') as month,
            COUNT(*) as tasks_assigned,
            SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) as tasks_completed,
            SUM(CASE WHEN t.status = 'closed' AND t.updated_at <= t.end_datetime THEN 1 ELSE 0 END) as on_time_completed,
            SUM(CASE WHEN t.status != 'closed' AND t.end_datetime < NOW() THEN 1 ELSE 0 END) as overdue_current
        FROM task_assignments ta
        LEFT JOIN tasks t ON ta.task_id = t.id
        WHERE ta.user_id = :user_id AND t.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(t.created_at, '%Y-%m')
        ORDER BY month DESC
    ";
} else {
    $monthly_performance_query = "
        SELECT 
            DATE_FORMAT(t.created_at, '%Y-%m') as month,
            COUNT(*) as tasks_assigned,
            SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) as tasks_reviewed,
            COUNT(DISTINCT b.id) as bugs_found
        FROM task_assignments ta
        LEFT JOIN tasks t ON ta.task_id = t.id
        LEFT JOIN bugs b ON t.id = b.task_id
        WHERE ta.user_id = :user_id AND t.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(t.created_at, '%Y-%m')
        ORDER BY month DESC
    ";
}

$monthly_performance_stmt = $db->prepare($monthly_performance_query);
$monthly_performance_stmt->bindParam(':user_id', $user_id);
$monthly_performance_stmt->execute();
$monthly_performance = $monthly_performance_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Performance - Task Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .performance-bar {
            width: 100%;
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
                #198754 100%
            );
            position: relative;
            border-radius: 10px;
        }
        .performance-marker {
            position: absolute;
            top: -5px;
            width: 3px;
            height: 30px;
            background-color: #000;
            border-radius: 2px;
        }
        .card-stat {
            transition: transform 0.2s;
        }
        .card-stat:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .overdue-task {
            background-color: #f8d7da !important;
            border-left: 4px solid #dc3545;
        }
        .completed-late {
            background-color: #fff3cd !important;
            border-left: 4px solid #ffc107;
        }
        .on-track {
            background-color: #d4edda !important;
            border-left: 4px solid #28a745;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <h2 class="mb-4">My Performance Dashboard</h2>
                
                <!-- Performance Rating Card -->
                <div class="card mb-4 bg-light">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h4 class="card-title">Your Current Performance Rating</h4>
                                <div class="d-flex align-items-center">
                                    <span class="badge bg-<?= $performance_color ?> fs-5 me-3">
                                        <?= $performance_rating ?>
                                    </span>
                                    <div class="performance-bar" style="width: 200px;">
                                        <div class="performance-marker" style="left: <?= min(100, max(0, $performance_score)) ?>%;"></div>
                                    </div>
                                    <span class="ms-3 fs-5 fw-bold"><?= $performance_score ?>%</span>
                                </div>
                            </div>
                            <div class="col-md-4 text-end">
                                <h5>Role: <?= strtoupper($user_role) ?></h5>
                                <?php if ($user_role == 'developer'): ?>
                                    <small class="text-muted">Based on: On-time completion, overdue tasks, and bugs created</small>
                                <?php else: ?>
                                    <small class="text-muted">Based on: Review efficiency and bugs found</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Performance Overview -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card card-stat text-center bg-primary text-white">
                            <div class="card-body">
                                <h3><?= $performance['total_tasks'] ?? 0 ?></h3>
                                <p class="mb-0">Total Tasks</p>
                                <?php if ($user_role == 'developer'): ?>
                                    <small><?= $performance['completed_tasks'] ?? 0 ?> completed</small>
                                <?php else: ?>
                                    <small><?= $performance['tasks_reviewed_closed'] ?? 0 ?> reviewed</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($user_role == 'developer'): ?>
                        <div class="col-md-3">
                            <div class="card card-stat text-center bg-success text-white">
                                <div class="card-body">
                                    <h3><?= number_format($performance['on_time_completion_rate'] ?? 0, 1) ?>%</h3>
                                    <p class="mb-0">On-Time Rate</p>
                                    <small>Tasks completed before deadline</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card card-stat text-center bg-warning text-dark">
                                <div class="card-body">
                                    <?php $total_overdue = (intval($performance['completed_late'] ?? 0) + intval($performance['pending_overdue'] ?? 0)); ?>
                                    <h3><?= $total_overdue ?></h3>
                                    <p class="mb-0">Overdue Tasks</p>
                                    <small>
                                        <?= $performance['completed_late'] ?? 0 ?> late,
                                        <?= $performance['pending_overdue'] ?? 0 ?> pending
                                    </small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card card-stat text-center bg-info text-white">
                                <div class="card-body">
                                    <h3><?= $performance['bugs_created'] ?? 0 ?></h3>
                                    <p class="mb-0">Bugs Created</p>
                                    <small><?= $performance['bugs_resolved'] ?? 0 ?> resolved</small>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="col-md-3">
                            <div class="card card-stat text-center bg-success text-white">
                                <div class="card-body">
                                    <?php 
                                    $tasks_reviewed = intval($performance['tasks_reviewed_closed'] ?? 0);
                                    $tasks_in_review = intval($performance['tasks_in_review'] ?? 0);
                                    $total_qa_tasks = $tasks_reviewed + $tasks_in_review;
                                    $qa_efficiency = $total_qa_tasks > 0 ? ($tasks_reviewed / $total_qa_tasks) * 100 : 0;
                                    ?>
                                    <h3><?= number_format($qa_efficiency, 1) ?>%</h3>
                                    <p class="mb-0">Review Efficiency</p>
                                    <small><?= $tasks_reviewed ?>/<?= $total_qa_tasks ?> tasks</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card card-stat text-center bg-warning text-dark">
                                <div class="card-body">
                                    <h3><?= $performance['tasks_in_review'] ?? 0 ?></h3>
                                    <p class="mb-0">Tasks In Review</p>
                                    <small>Awaiting your action</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card card-stat text-center bg-info text-white">
                                <div class="card-body">
                                    <h3><?= $performance['bugs_reported'] ?? 0 ?></h3>
                                    <p class="mb-0">Bugs Found</p>
                                    <small><?= $performance['bugs_closed_in_reviewed_tasks'] ?? 0 ?> closed</small>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="row">
                    <!-- Task Status Distribution -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Task Status Distribution</h5>
                            </div>
                            <div class="card-body">
                                <canvas id="statusChart" height="250"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Monthly Performance -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Monthly Performance</h5>
                            </div>
                            <div class="card-body">
                                <canvas id="monthlyChart" height="250"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Tasks -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Recent Tasks</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Task Name</th>
                                        <th>Project</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th>Due Date</th>
                                        <th>Bugs</th>
                                        <th>Timeline</th>
                                        <th>Last Updated</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_tasks as $task): 
                                        $task_class = '';
                                        if ($task['task_status'] == 'overdue') {
                                            $task_class = 'overdue-task';
                                        } elseif ($task['task_status'] == 'completed_late') {
                                            $task_class = 'completed-late';
                                        } elseif ($task['task_status'] == 'on_track') {
                                            $task_class = 'on-track';
                                        }
                                    ?>
                                    <tr class="<?= $task_class ?>">
                                        <td>
                                            <strong><?= htmlspecialchars($task['name']) ?></strong>
                                        </td>
                                        <td><?= htmlspecialchars($task['project_name']) ?></td>
                                        <td>
                                            <span class="badge bg-<?= 
                                                $task['priority'] == 'critical' ? 'danger' : 
                                                ($task['priority'] == 'high' ? 'warning' : 
                                                ($task['priority'] == 'medium' ? 'info' : 'success')) 
                                            ?>">
                                                <?= ucfirst($task['priority']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary">
                                                <?= ucfirst(str_replace('_', ' ', $task['status'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($task['end_datetime']): ?>
                                                <?php 
                                                $end_date = strtotime($task['end_datetime']);
                                                $now = time();
                                                $diff = $end_date - $now;
                                                $days = floor($diff / (60 * 60 * 24));
                                                
                                                $class = 'text-muted';
                                                $status_text = date('M j, Y', $end_date);
                                                
                                                if ($days < 0) {
                                                    $class = 'text-danger fw-bold';
                                                    $status_text .= " (".abs($days)." days overdue)";
                                                } elseif ($days == 0) {
                                                    $class = 'text-warning fw-bold';
                                                    $status_text .= " (Today)";
                                                } elseif ($days <= 2) {
                                                    $class = 'text-warning fw-bold';
                                                    $status_text .= " (in $days days)";
                                                }
                                                ?>
                                                <span class="<?= $class ?>">
                                                    <?= $status_text ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">Not set</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($task['bug_count'] > 0): ?>
                                                <span class="badge bg-danger"><?= $task['bug_count'] ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($task['task_status'] == 'overdue'): ?>
                                                <span class="badge bg-danger">OVERDUE</span>
                                            <?php elseif ($task['task_status'] == 'completed_late'): ?>
                                                <span class="badge bg-warning">COMPLETED LATE</span>
                                            <?php elseif ($task['status'] == 'closed'): ?>
                                                <span class="badge bg-success">ON TIME</span>
                                            <?php else: ?>
                                                <span class="badge bg-info">ON TRACK</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= date('M j, Y', strtotime($task['updated_at'])) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Status Distribution Chart
        const statusCtx = document.getElementById('statusChart');
        if (statusCtx) {
            const statusChart = new Chart(statusCtx.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode(array_column($status_distribution, 'status')) ?>,
                    datasets: [{
                        data: <?= json_encode(array_column($status_distribution, 'count')) ?>,
                        backgroundColor: [
                            '#ff6384', '#36a2eb', '#ffce56', '#4bc0c0', '#9966ff', '#ff9f40'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        },
                        title: {
                            display: true,
                            text: 'My Task Status Distribution'
                        }
                    }
                }
            });
        }

        // Monthly Performance Chart
        const monthlyCtx = document.getElementById('monthlyChart');
        if (monthlyCtx) {
            const monthlyData = <?= json_encode($monthly_performance) ?>;
            const labels = monthlyData.map(item => item.month);
            const tasksAssigned = monthlyData.map(item => item.tasks_assigned || 0);
                
            <?php if ($user_role == 'developer'): ?>
                const tasksCompleted = monthlyData.map(item => item.tasks_completed || 0);
                const onTimeCompleted = monthlyData.map(item => item.on_time_completed || 0);
                
                const monthlyChart_ = new Chart(monthlyCtx.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [
                            {
                                label: 'Tasks Assigned',
                                data: tasksAssigned,
                                backgroundColor: '#36a2eb'
                            },
                            {
                                label: 'Tasks Completed',
                                data: tasksCompleted,
                                backgroundColor: '#4bc0c0'
                            },
                            {
                                label: 'On-Time Completed',
                                data: onTimeCompleted,
                                backgroundColor: '#28a745'
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
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
                            }
                        }
                    }
                });
            <?php else: ?>
                const tasksReviewed = monthlyData.map(item => item.tasks_reviewed || 0);
                const bugsFound = monthlyData.map(item => item.bugs_found || 0);
                
                const monthlyChart = new Chart(monthlyCtx.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [
                            {
                                label: 'Tasks Assigned',
                                data: tasksAssigned,
                                backgroundColor: '#36a2eb'
                            },
                            {
                                label: 'Tasks Reviewed',
                                data: tasksReviewed,
                                backgroundColor: '#4bc0c0'
                            },
                            {
                                label: 'Bugs Found',
                                data: bugsFound,
                                backgroundColor: '#dc3545',
                                type: 'line',
                                yAxisID: 'y1'
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            title: {
                                display: true,
                                text: 'Monthly QA Performance'
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
                            y1: {
                                position: 'right',
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Number of Bugs'
                                },
                                grid: {
                                    drawOnChartArea: false
                                }
                            }
                        }
                    }
                });
            <?php endif; ?>
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