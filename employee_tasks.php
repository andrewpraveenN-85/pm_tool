<?php
include 'config/database.php';
include 'includes/auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireRole(['manager']);

// Get filter parameters
$employee_id = $_GET['employee_id'] ?? '';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

// Get all employees (developers and QA)
$employees_query = "SELECT id, name, role FROM users 
                   WHERE status = 'active' AND role IN ('developer', 'qa')
                   ORDER BY name";
$employees = $db->query($employees_query)->fetchAll(PDO::FETCH_ASSOC);

// If employee is selected, get their tasks
$employee_data = null;
$tasks_by_status = null;
$task_stats = null;

if (!empty($employee_id)) {
    // Validate employee exists
    $emp_check = $db->prepare("SELECT * FROM users WHERE id = ? AND status = 'active'");
    $emp_check->execute([$employee_id]);
    $employee_data = $emp_check->fetch(PDO::FETCH_ASSOC);

    if ($employee_data) {
        // Get tasks assigned to employee (with status grouping)
        $tasks_by_status = [
            'todo' => [],
            'reopened' => [],
            'in_progress' => [],
            'await_release' => [],
            'in_review' => [],
            'closed' => []
        ];

        $tasks_query = "SELECT t.*, p.name as project_name,
                       COUNT(DISTINCT b.id) as bug_count,
                       DATEDIFF(t.end_datetime, CURDATE()) as days_remaining,
                       -- Updated deadline status calculation
                       CASE 
                           WHEN t.status = 'closed' AND t.updated_at > t.end_datetime THEN 'overdue'
                           WHEN t.status != 'closed' AND t.end_datetime < CURDATE() THEN 'overdue'
                           WHEN t.end_datetime <= DATE_ADD(CURDATE(), INTERVAL 3 DAY) AND t.end_datetime >= CURDATE() THEN 'urgent'
                           ELSE 'normal'
                       END as deadline_status
                      FROM tasks t
                      LEFT JOIN projects p ON t.project_id = p.id
                      LEFT JOIN task_assignments ta ON t.id = ta.task_id
                      LEFT JOIN bugs b ON t.id = b.task_id
                      WHERE ta.user_id = :employee_id 
                      AND DATE(t.created_at) BETWEEN :start_date AND :end_date
                      GROUP BY t.id
                      ORDER BY t.priority DESC, t.end_datetime ASC";

        $stmt = $db->prepare($tasks_query);
        $stmt->execute([
            ':employee_id' => $employee_id,
            ':start_date' => $start_date,
            ':end_date' => $end_date
        ]);
        
        $all_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($all_tasks as $task) {
            $tasks_by_status[$task['status']][] = $task;
        }

        // Get task statistics for this employee with updated overdue calculation
        $stats_query = "SELECT 
            COUNT(DISTINCT t.id) as total_tasks,
            SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) as completed_tasks,
            -- Updated overdue calculation
            SUM(CASE 
                WHEN t.status = 'closed' AND t.updated_at > t.end_datetime THEN 1
                WHEN t.status != 'closed' AND t.end_datetime < CURDATE() THEN 1
                ELSE 0 
            END) as overdue_tasks,
            COUNT(DISTINCT b.id) as total_bugs,
            COUNT(DISTINCT CASE WHEN b.status = 'open' THEN b.id END) as open_bugs
        FROM task_assignments ta
        LEFT JOIN tasks t ON ta.task_id = t.id
        LEFT JOIN bugs b ON t.id = b.task_id
        WHERE ta.user_id = :employee_id
        AND DATE(t.created_at) BETWEEN :start_date AND :end_date";
        
        $stmt = $db->prepare($stats_query);
        $stmt->execute([
            ':employee_id' => $employee_id,
            ':start_date' => $start_date,
            ':end_date' => $end_date
        ]);
        $task_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Tasks - Kanban View</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .kanban-column {
            min-height: 600px;
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .task-card {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 12px;
            transition: all 0.2s;
        }
        .task-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .priority-critical { border-left: 4px solid #dc3545; background: #ffe6e6; }
        .priority-high { border-left: 4px solid #fd7e14; background: #fff3e6; }
        .priority-medium { border-left: 4px solid #ffc107; background: #fff9e6; }
        .priority-low { border-left: 4px solid #28a745; background: #e6ffe6; }
        .column-header { 
            background: #e9ecef; 
            padding: 12px; 
            border-radius: 6px; 
            margin-bottom: 15px;
            text-align: center;
            font-weight: bold;
            font-size: 14px;
            text-transform: uppercase;
        }
        .overdue-task {
            background-color: #fff5f5 !important;
            border-color: #fcaea0 !important;
        }
        .urgent-task {
            background-color: #fff9e6 !important;
            border-color: #ffd666 !important;
        }
        .deadline-badge {
            font-size: 0.7rem;
            padding: 2px 6px;
        }
        .stat-card {
            border-radius: 8px;
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-3px);
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid mt-4">
        <div class="row mb-4">
            <div class="col-12">
                <h2>Employee Tasks - Kanban View</h2>
                <p class="text-muted">View tasks assigned to a specific employee in Kanban format</p>
            </div>
        </div>

        <!-- Filter Form -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="fas fa-filter"></i> Select Employee & Date Range</h5>
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
                                <button type="submit" class="btn btn-primary w-100">View Tasks</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($employee_id) && $employee_data): ?>
        <!-- Employee Summary -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-user"></i> 
                            <?= htmlspecialchars($employee_data['name']) ?> 
                            <small class="text-light">(<?= strtoupper($employee_data['role']) ?>)</small>
                            <span class="float-end">Period: <?= date('M d, Y', strtotime($start_date)) ?> - <?= date('M d, Y', strtotime($end_date)) ?></span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="card stat-card bg-light mb-3">
                                    <div class="card-body text-center">
                                        <h2 class="mb-1"><?= $task_stats['total_tasks'] ?? 0 ?></h2>
                                        <p class="text-muted mb-0">Total Tasks</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card stat-card bg-light mb-3">
                                    <div class="card-body text-center">
                                        <h2 class="mb-1"><?= $task_stats['completed_tasks'] ?? 0 ?></h2>
                                        <p class="text-muted mb-0">Completed Tasks</p>
                                        <small class="text-muted">
                                            <?= $task_stats['total_tasks'] > 0 ? 
                                                round(($task_stats['completed_tasks'] / $task_stats['total_tasks']) * 100, 1) . '%' : '0%' ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card stat-card bg-light mb-3">
                                    <div class="card-body text-center">
                                        <h2 class="mb-1"><?= $task_stats['overdue_tasks'] ?? 0 ?></h2>
                                        <p class="text-muted mb-0">Overdue Tasks</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card stat-card bg-light mb-3">
                                    <div class="card-body text-center">
                                        <h2 class="mb-1"><?= $task_stats['total_bugs'] ?? 0 ?></h2>
                                        <p class="text-muted mb-0">Total Bugs</p>
                                        <small class="text-muted">
                                            <?= $task_stats['open_bugs'] ?? 0 ?> Open
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Kanban Board -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="fas fa-columns"></i> Task Kanban Board</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php 
                            $status_labels = [
                                'todo' => 'To Do',
                                'reopened' => 'Reopened', 
                                'in_progress' => 'In Progress',
                                'await_release' => 'Await Release',
                                'in_review' => 'In Review',
                                'closed' => 'Completed'
                            ];
                            
                            $status_colors = [
                                'todo' => '#6c757d',
                                'reopened' => '#fd7e14', 
                                'in_progress' => '#0dcaf0',
                                'await_release' => '#20c997',
                                'in_review' => '#6610f2',
                                'closed' => '#198754'
                            ];
                            
                            foreach ($tasks_by_status as $status => $tasks): ?>
                            <div class="col-lg-2 col-md-4 mb-4">
                                <div class="column-header" style="border-left: 4px solid <?= $status_colors[$status] ?>;">
                                    <?= $status_labels[$status] ?> 
                                    <span class="badge bg-dark"><?= count($tasks) ?></span>
                                </div>
                                <div class="kanban-column">
                                    <?php if (empty($tasks)): ?>
                                        <div class="text-center text-muted py-4">
                                            <i class="fas fa-inbox fa-2x mb-2"></i>
                                            <p class="mb-0">No tasks</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($tasks as $task): 
                                            $deadline_class = '';
                                            if ($task['deadline_status'] == 'overdue') {
                                                $deadline_class = 'overdue-task';
                                            } elseif ($task['deadline_status'] == 'urgent') {
                                                $deadline_class = 'urgent-task';
                                            }
                                            
                                            // Calculate days overdue for closed tasks
                                            $days_message = '';
                                            if ($task['end_datetime']) {
                                                $end_date = strtotime($task['end_datetime']);
                                                if ($task['status'] == 'closed') {
                                                    $updated_at = strtotime($task['updated_at']);
                                                    if ($updated_at > $end_date) {
                                                        $days_overdue = floor(($updated_at - $end_date) / (60 * 60 * 24));
                                                        $days_message = $days_overdue . " days late";
                                                    }
                                                } else {
                                                    $now = time();
                                                    if ($now > $end_date) {
                                                        $days_overdue = floor(($now - $end_date) / (60 * 60 * 24));
                                                        $days_message = $days_overdue . " days overdue";
                                                    } elseif (($end_date - $now) <= (3 * 24 * 60 * 60)) {
                                                        $days_remaining = floor(($end_date - $now) / (60 * 60 * 24));
                                                        $days_message = $days_remaining . " days left";
                                                    }
                                                }
                                            }
                                        ?>
                                        <div class="task-card priority-<?= $task['priority'] ?> <?= $deadline_class ?>" 
                                             data-task-id="<?= $task['id'] ?>">
                                            
                                            <!-- Task Title -->
                                            <h6 class="mb-2"><?= htmlspecialchars($task['name']) ?></h6>
                                            
                                            <!-- Project Info -->
                                            <small class="text-muted d-block mb-2">
                                                <i class="fas fa-project-diagram"></i> <?= htmlspecialchars($task['project_name']) ?>
                                            </small>
                                            
                                            <!-- Deadline Info -->
                                            <?php if ($task['end_datetime']): ?>
                                                <div class="mb-2">
                                                    <small class="text-muted d-block">
                                                        <i class="far fa-calendar-alt"></i> 
                                                        Deadline: <?= date('M d, Y', strtotime($task['end_datetime'])) ?>
                                                    </small>
                                                    <?php if ($days_message): ?>
                                                        <span class="badge <?= 
                                                            $task['deadline_status'] == 'overdue' ? 'bg-danger' : 
                                                            ($task['deadline_status'] == 'urgent' ? 'bg-warning text-dark' : 'bg-secondary')
                                                        ?> deadline-badge">
                                                            <?= $days_message ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <!-- Bug Count -->
                                            <?php if ($task['bug_count'] > 0): ?>
                                                <div class="mb-2">
                                                    <span class="badge bg-danger">
                                                        <i class="fas fa-bug"></i> <?= $task['bug_count'] ?> bug(s)
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <!-- Created Date -->
                                            <small class="text-muted d-block">
                                                Created: <?= date('M d, Y', strtotime($task['created_at'])) ?>
                                            </small>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Export Options -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body text-center">
                        <h6>Export Report</h6>
                        <a href="javascript:window.print()" class="btn btn-danger me-2">
                            <i class="fas fa-file-pdf"></i> Print/PDF
                        </a>
                        <button class="btn btn-success" onclick="exportToExcel()">
                            <i class="fas fa-file-excel"></i> Export to Excel
                        </button>
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
        <?php else: ?>
        <!-- Initial state -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-columns fa-4x text-muted mb-3"></i>
                        <h4 class="text-muted">Select an employee to view their tasks</h4>
                        <p class="text-muted">Choose an employee from the dropdown above to see their tasks in Kanban format</p>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function exportToExcel() {
            // Simple Excel export - in production, use a proper Excel library
            alert('Excel export would be implemented here with proper library');
        }
    </script>
</body>
<footer class="bg-dark text-light text-center py-3 mt-5">
    <div class="container">
        <p class="mb-0">Developed by APNLAB. 2025.</p>
    </div>
</footer>
</html>