<?php
require_once 'EmailService.php';

class Notification {
    private $conn;
    private $table_name = "notifications";
    public $emailService;

    public function __construct($db) {
        $this->conn = $db;
        try {
            $this->emailService = new EmailService($db);
        } catch (Exception $e) {
            error_log("EmailService initialization failed: " . $e->getMessage());
            $this->emailService = null;
        }
    }

    // TASK NOTIFICATIONS
    public function checkDeadlineNotifications() {
        $warning_hours = $this->getSetting('deadline_warning_hours', 8);
        
        $query = "
            SELECT t.id, t.name, t.end_datetime, 
                   GROUP_CONCAT(DISTINCT ta.user_id) as assignee_ids,
                   t.created_by as manager_id,
                   u.email as manager_email,
                   u.name as manager_name,
                   GROUP_CONCAT(DISTINCT u2.email) as assignee_emails,
                   GROUP_CONCAT(DISTINCT u2.name) as assignee_names
            FROM tasks t
            LEFT JOIN task_assignments ta ON t.id = ta.task_id
            LEFT JOIN users u ON t.created_by = u.id
            LEFT JOIN users u2 ON ta.user_id = u2.id
            WHERE t.end_datetime BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL ? HOUR)
            AND t.status NOT IN ('completed', 'cancelled')
            AND t.end_datetime IS NOT NULL
            GROUP BY t.id
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute(array($warning_hours));
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($tasks as $task) {
            $assignee_ids = !empty($task['assignee_ids']) ? explode(',', $task['assignee_ids']) : array();
            $assignee_emails = !empty($task['assignee_emails']) ? explode(',', $task['assignee_emails']) : array();
            $assignee_names = !empty($task['assignee_names']) ? explode(',', $task['assignee_names']) : array();
            
            // Notify developers
            foreach ($assignee_ids as $index => $user_id) {
                if (!empty($user_id)) {
                    $this->createNotification(
                        $user_id,
                        'Task Deadline Approaching',
                        "Task '{$task['name']}' is due in less than {$warning_hours} hours",
                        'deadline'
                    );

                    if ($this->emailService && isset($assignee_emails[$index])) {
                        $assignee_name = isset($assignee_names[$index]) ? $assignee_names[$index] : 'Developer';
                        $this->sendDeadlineEmail(
                            $assignee_emails[$index],
                            $assignee_name,
                            $task['name'],
                            $task['end_datetime'],
                            $warning_hours,
                            'developer'
                        );
                    }
                }
            }
            
            // Notify manager
            if (!empty($task['manager_id'])) {
                $this->createNotification(
                    $task['manager_id'],
                    'Task Deadline Approaching', 
                    "Task '{$task['name']}' is due in less than {$warning_hours} hours",
                    'deadline'
                );

                if ($this->emailService && !empty($task['manager_email'])) {
                    $this->sendDeadlineEmail(
                        $task['manager_email'],
                        $task['manager_name'],
                        $task['name'],
                        $task['end_datetime'],
                        $warning_hours,
                        'manager'
                    );
                }
            }
        }
        
        return count($tasks);
    }

    public function createTaskAssignmentNotification($task_id, $user_ids) {
        $task_query = "SELECT name, created_by FROM tasks WHERE id = :task_id";
        $task_stmt = $this->conn->prepare($task_query);
        $task_stmt->bindParam(':task_id', $task_id);
        $task_stmt->execute();
        $task = $task_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) return;

        // Get user details
        $user_details = array();
        if (!empty($user_ids)) {
            $placeholders = str_repeat('?,', count($user_ids) - 1) . '?';
            $user_query = "SELECT id, email, name FROM users WHERE id IN ($placeholders)";
            $user_stmt = $this->conn->prepare($user_query);
            $user_stmt->execute($user_ids);
            $user_details = $user_stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        foreach ($user_ids as $user_id) {
            $this->createNotification(
                $user_id,
                'New Task Assignment',
                "You have been assigned to task: '{$task['name']}'",
                'assignment'
            );

            if ($this->emailService) {
                $user_detail = null;
                foreach ($user_details as $ud) {
                    if ($ud['id'] == $user_id) {
                        $user_detail = $ud;
                        break;
                    }
                }

                if ($user_detail) {
                    $this->sendAssignmentEmail(
                        $user_detail['email'],
                        $user_detail['name'],
                        $task['name'],
                        $task['created_by']
                    );
                }
            }
        }
    }

    public function createTaskUpdateNotification($task_id, $user_ids) {
        $task_query = "SELECT name, created_by FROM tasks WHERE id = :task_id";
        $task_stmt = $this->conn->prepare($task_query);
        $task_stmt->bindParam(':task_id', $task_id);
        $task_stmt->execute();
        $task = $task_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) return;

        // Get user details
        $user_details = array();
        if (!empty($user_ids)) {
            $placeholders = str_repeat('?,', count($user_ids) - 1) . '?';
            $user_query = "SELECT id, email, name FROM users WHERE id IN ($placeholders)";
            $user_stmt = $this->conn->prepare($user_query);
            $user_stmt->execute($user_ids);
            $user_details = $user_stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        foreach ($user_ids as $user_id) {
            $this->createNotification(
                $user_id,
                'Task Updated',
                "Task '{$task['name']}' has been updated",
                'task_update'
            );

            if ($this->emailService) {
                $user_detail = null;
                foreach ($user_details as $ud) {
                    if ($ud['id'] == $user_id) {
                        $user_detail = $ud;
                        break;
                    }
                }

                if ($user_detail) {
                    $this->sendTaskUpdateEmail(
                        $user_detail['email'],
                        $user_detail['name'],
                        $task['name'],
                        $task['created_by']
                    );
                }
            }
        }
    }

    public function createTaskStatusUpdateNotification($task_id, $new_status, $assignee_ids, $manager_id) {
        $task_query = "SELECT name FROM tasks WHERE id = :task_id";
        $task_stmt = $this->conn->prepare($task_query);
        $task_stmt->bindParam(':task_id', $task_id);
        $task_stmt->execute();
        $task = $task_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) return;

        // Get user details
        $user_ids = array_merge($assignee_ids, array($manager_id));
        $user_details = array();
        if (!empty($user_ids)) {
            $placeholders = str_repeat('?,', count($user_ids) - 1) . '?';
            $user_query = "SELECT id, email, name FROM users WHERE id IN ($placeholders)";
            $user_stmt = $this->conn->prepare($user_query);
            $user_stmt->execute($user_ids);
            $user_details = $user_stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $status_display = ucfirst(str_replace('_', ' ', $new_status));

        // Notify all assignees
        foreach ($assignee_ids as $user_id) {
            $this->createNotification(
                $user_id,
                'Task Status Updated',
                "Task '{$task['name']}' status has been changed to: {$status_display}",
                'status_update'
            );

            if ($this->emailService) {
                $user_detail = null;
                foreach ($user_details as $ud) {
                    if ($ud['id'] == $user_id) {
                        $user_detail = $ud;
                        break;
                    }
                }

                if ($user_detail) {
                    $this->sendTaskStatusUpdateEmail(
                        $user_detail['email'],
                        $user_detail['name'],
                        $task['name'],
                        $new_status,
                        'developer'
                    );
                }
            }
        }

        // Notify manager
        $this->createNotification(
            $manager_id,
            'Task Status Updated',
            "Task '{$task['name']}' status has been changed to: {$status_display}",
            'status_update'
        );

        if ($this->emailService) {
            $manager_detail = null;
            foreach ($user_details as $ud) {
                if ($ud['id'] == $manager_id) {
                    $manager_detail = $ud;
                    break;
                }
            }

            if ($manager_detail) {
                $this->sendTaskStatusUpdateEmail(
                    $manager_detail['email'],
                    $manager_detail['name'],
                    $task['name'],
                    $new_status,
                    'manager'
                );
            }
        }
    }

    // BUG NOTIFICATIONS
    public function createBugReportNotification($bug_id) {
        $bug_query = "
            SELECT b.name, b.priority, t.name as task_name, t.created_by as manager_id, 
                   u.email as manager_email, u.name as manager_name
            FROM bugs b 
            LEFT JOIN tasks t ON b.task_id = t.id 
            LEFT JOIN users u ON t.created_by = u.id 
            WHERE b.id = :bug_id
        ";
        $bug_stmt = $this->conn->prepare($bug_query);
        $bug_stmt->bindParam(':bug_id', $bug_id);
        $bug_stmt->execute();
        $bug = $bug_stmt->fetch(PDO::FETCH_ASSOC);

        if ($bug && $bug['manager_id']) {
            $this->createNotification(
                $bug['manager_id'],
                'New Bug Reported',
                "A new {$bug['priority']} priority bug '{$bug['name']}' has been reported for task: '{$bug['task_name']}'",
                'bug_report'
            );

            if ($this->emailService && !empty($bug['manager_email'])) {
                $this->sendBugReportEmail(
                    $bug['manager_email'],
                    $bug['manager_name'],
                    $bug['name'],
                    $bug['task_name'],
                    $bug['priority']
                );
            }
        }
    }

    public function createBugUpdateNotification($bug_id) {
        $bug_query = "
            SELECT b.name, b.priority, b.task_id, t.name as task_name, 
                   t.created_by as manager_id, u.email as manager_email, u.name as manager_name
            FROM bugs b 
            LEFT JOIN tasks t ON b.task_id = t.id 
            LEFT JOIN users u ON t.created_by = u.id 
            WHERE b.id = :bug_id
        ";
        $bug_stmt = $this->conn->prepare($bug_query);
        $bug_stmt->bindParam(':bug_id', $bug_id);
        $bug_stmt->execute();
        $bug = $bug_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$bug) return;

        // Get assignees from the associated task
        $assignees_query = "
            SELECT ta.user_id, u.email, u.name 
            FROM task_assignments ta
            LEFT JOIN users u ON ta.user_id = u.id
            WHERE ta.task_id = :task_id
        ";
        $assignees_stmt = $this->conn->prepare($assignees_query);
        $assignees_stmt->bindParam(':task_id', $bug['task_id']);
        $assignees_stmt->execute();
        $assignees = $assignees_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Notify manager
        if ($bug['manager_id']) {
            $this->createNotification(
                $bug['manager_id'],
                'Bug Updated',
                "Bug '{$bug['name']}' has been updated for task: '{$bug['task_name']}'",
                'bug_update'
            );

            if ($this->emailService && !empty($bug['manager_email'])) {
                $this->sendBugUpdateEmail(
                    $bug['manager_email'],
                    $bug['manager_name'],
                    $bug['name'],
                    $bug['task_name'],
                    'manager'
                );
            }
        }

        // Notify assignees
        foreach ($assignees as $assignee) {
            $this->createNotification(
                $assignee['user_id'],
                'Bug Updated',
                "Bug '{$bug['name']}' has been updated for task: '{$bug['task_name']}'",
                'bug_update'
            );

            if ($this->emailService && !empty($assignee['email'])) {
                $this->sendBugUpdateEmail(
                    $assignee['email'],
                    $assignee['name'],
                    $bug['name'],
                    $bug['task_name'],
                    'developer'
                );
            }
        }
    }

    public function createBugStatusUpdateNotification($bug_id, $new_status, $assignee_ids, $manager_id) {
        $bug_query = "
            SELECT b.name, b.priority, t.name as task_name, 
                   u.email as manager_email, u.name as manager_name
            FROM bugs b 
            LEFT JOIN tasks t ON b.task_id = t.id 
            LEFT JOIN users u ON t.created_by = u.id 
            WHERE b.id = :bug_id
        ";
        $bug_stmt = $this->conn->prepare($bug_query);
        $bug_stmt->bindParam(':bug_id', $bug_id);
        $bug_stmt->execute();
        $bug = $bug_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$bug) return;

        // Get user details
        $user_ids = array_merge($assignee_ids, array($manager_id));
        $user_details = array();
        if (!empty($user_ids)) {
            $placeholders = str_repeat('?,', count($user_ids) - 1) . '?';
            $user_query = "SELECT id, email, name FROM users WHERE id IN ($placeholders)";
            $user_stmt = $this->conn->prepare($user_query);
            $user_stmt->execute($user_ids);
            $user_details = $user_stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $status_display = ucfirst(str_replace('_', ' ', $new_status));

        // Notify all assignees
        foreach ($assignee_ids as $user_id) {
            $this->createNotification(
                $user_id,
                'Bug Status Updated',
                "Bug '{$bug['name']}' status has been changed to: {$status_display}",
                'bug_status_update'
            );

            if ($this->emailService) {
                $user_detail = null;
                foreach ($user_details as $ud) {
                    if ($ud['id'] == $user_id) {
                        $user_detail = $ud;
                        break;
                    }
                }

                if ($user_detail) {
                    $this->sendBugStatusUpdateEmail(
                        $user_detail['email'],
                        $user_detail['name'],
                        $bug['name'],
                        $new_status,
                        $bug['task_name'],
                        'developer'
                    );
                }
            }
        }

        // Notify manager
        $this->createNotification(
            $manager_id,
            'Bug Status Updated', 
            "Bug '{$bug['name']}' status has been changed to: {$status_display}",
            'bug_status_update'
        );

        if ($this->emailService && !empty($bug['manager_email'])) {
            $this->sendBugStatusUpdateEmail(
                $bug['manager_email'],
                $bug['manager_name'],
                $bug['name'],
                $new_status,
                $bug['task_name'],
                'manager'
            );
        }
    }

    public function checkOverdueBugs() {
        $query = "
            SELECT b.id, b.name, b.end_datetime, b.priority, b.status,
                   t.name as task_name, t.created_by as manager_id,
                   u.email as manager_email, u.name as manager_name
            FROM bugs b
            LEFT JOIN tasks t ON b.task_id = t.id
            LEFT JOIN users u ON t.created_by = u.id
            WHERE b.end_datetime < NOW() 
            AND b.status NOT IN ('resolved', 'closed')
            AND b.end_datetime IS NOT NULL
        ";

        $bugs = $this->conn->query($query)->fetchAll(PDO::FETCH_ASSOC);

        foreach ($bugs as $bug) {
            // Notify manager about overdue bug
            $this->createNotification(
                $bug['manager_id'],
                'Overdue Bug',
                "Bug '{$bug['name']}' is overdue. It was due on " . date('M j, Y', strtotime($bug['end_datetime'])),
                'overdue'
            );

            // Send email notification
            if ($this->emailService && !empty($bug['manager_email'])) {
                $this->sendOverdueBugEmail(
                    $bug['manager_email'],
                    $bug['manager_name'],
                    $bug['name'],
                    $bug['task_name'],
                    $bug['priority'],
                    $bug['end_datetime']
                );
            }
        }
        
        return count($bugs);
    }

    // EMAIL TEMPLATES
    private function sendDeadlineEmail($email, $name, $taskName, $deadline, $warningHours, $recipientType) {
        if (!$this->emailService || !$this->emailService->isConfigured()) {
            return false;
        }

        $subject = "⏰ Task Deadline Approaching: {$taskName}";
        
        $message = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #ffc107; color: #212529; padding: 15px; border-radius: 5px; }
                    .content { margin: 20px 0; }
                    .deadline { color: #dc3545; font-weight: bold; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>⏰ Task Deadline Approaching</h2>
                    </div>
                    <div class='content'>
                        <p>Hello {$name},</p>
                        <p>The task <strong>'{$taskName}'</strong> is approaching its deadline.</p>
                        <p><strong>Deadline:</strong> <span class='deadline'>{$deadline}</span></p>
                        <p><strong>Time remaining:</strong> Less than {$warningHours} hours</p>
                        <p>Please take appropriate action to ensure timely completion.</p>
                        <p><a href='{$this->getAppUrl()}tasks.php' style='background: #007bff; color: white; padding: 10px 15px; text-decoration: none; border-radius: 3px; display: inline-block;'>View Task</a></p>
                    </div>
                </div>
            </body>
            </html>
        ";

        return $this->emailService->sendEmail($email, $subject, $message);
    }

    private function sendAssignmentEmail($email, $name, $taskName, $assignedBy) {
        if (!$this->emailService || !$this->emailService->isConfigured()) {
            return false;
        }

        $subject = "📋 New Task Assignment: {$taskName}";
        
        $message = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #007bff; color: white; padding: 15px; border-radius: 5px; }
                    .content { margin: 20px 0; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>📋 New Task Assignment</h2>
                    </div>
                    <div class='content'>
                        <p>Hello {$name},</p>
                        <p>You have been assigned to a new task: <strong>'{$taskName}'</strong></p>
                        <p>This task was assigned by your manager.</p>
                        <p>Please log in to the Task Manager system to view the task details and get started.</p>
                        <p><a href='{$this->getAppUrl()}tasks.php' style='background: #28a745; color: white; padding: 10px 15px; text-decoration: none; border-radius: 3px; display: inline-block;'>View Task Details</a></p>
                    </div>
                </div>
            </body>
            </html>
        ";

        return $this->emailService->sendEmail($email, $subject, $message);
    }

    private function sendTaskUpdateEmail($email, $name, $taskName, $updatedBy) {
        if (!$this->emailService || !$this->emailService->isConfigured()) {
            return false;
        }

        $subject = "📝 Task Updated: {$taskName}";
        
        $message = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #17a2b8; color: white; padding: 15px; border-radius: 5px; }
                    .content { margin: 20px 0; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>📝 Task Updated</h2>
                    </div>
                    <div class='content'>
                        <p>Hello {$name},</p>
                        <p>The task <strong>'{$taskName}'</strong> has been updated.</p>
                        <p><strong>Updated by:</strong> Manager</p>
                        <p><strong>Updated at:</strong> " . date('M j, Y g:i A') . "</p>
                        <p>Please review the updated task details as changes may affect your work.</p>
                        <p><a href='{$this->getAppUrl()}tasks.php' style='background: #17a2b8; color: white; padding: 10px 15px; text-decoration: none; border-radius: 3px; display: inline-block;'>View Updated Task</a></p>
                    </div>
                </div>
            </body>
            </html>
        ";

        return $this->emailService->sendEmail($email, $subject, $message);
    }

    private function sendTaskStatusUpdateEmail($email, $name, $taskName, $newStatus, $recipientType) {
        if (!$this->emailService || !$this->emailService->isConfigured()) {
            return false;
        }

        $status_colors = array(
            'todo' => '#ffc107',
            'reopened' => '#fd7e14',
            'in_progress' => '#007bff',
            'await_release' => '#17a2b8',
            'in_review' => '#6f42c1',
            'closed' => '#28a745'
        );

        $subject = "🔄 Task Status Updated: {$taskName}";
        
        $status_display = ucfirst(str_replace('_', ' ', $newStatus));
        $status_color = isset($status_colors[$newStatus]) ? $status_colors[$newStatus] : '#6c757d';
        
        $message = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #6c757d; color: white; padding: 15px; border-radius: 5px; }
                    .content { margin: 20px 0; }
                    .status { display: inline-block; padding: 5px 10px; border-radius: 3px; color: white; font-weight: bold; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>🔄 Task Status Update</h2>
                    </div>
                    <div class='content'>
                        <p>Hello {$name},</p>
                        <p>The status of task <strong>'{$taskName}'</strong> has been updated.</p>
                        <p><strong>New Status:</strong> <span class='status' style='background: {$status_color}'>{$status_display}</span></p>
                        <p><strong>Updated:</strong> " . date('M j, Y g:i A') . "</p>
                        <p><a href='{$this->getAppUrl()}tasks.php' style='background: #6f42c1; color: white; padding: 10px 15px; text-decoration: none; border-radius: 3px; display: inline-block;'>View Task Details</a></p>
                    </div>
                </div>
            </body>
            </html>
        ";

        return $this->emailService->sendEmail($email, $subject, $message);
    }

    private function sendBugReportEmail($email, $name, $bugName, $taskName, $priority) {
        if (!$this->emailService || !$this->emailService->isConfigured()) {
            return false;
        }

        $priority_color = array(
            'low' => '#28a745',
            'medium' => '#ffc107', 
            'high' => '#fd7e14',
            'critical' => '#dc3545'
        );

        $subject = "🚨 New {$priority} Priority Bug: {$bugName}";
        $priority_bg_color = isset($priority_color[$priority]) ? $priority_color[$priority] : '#6c757d';

        $message = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #dc3545; color: white; padding: 15px; border-radius: 5px; }
                    .content { margin: 20px 0; }
                    .priority { display: inline-block; padding: 5px 10px; border-radius: 3px; color: white; font-weight: bold; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>🚨 New Bug Report</h2>
                    </div>
                    <div class='content'>
                        <p>Hello {$name},</p>
                        <p>A new bug has been reported that requires your attention:</p>
                        
                        <div style='background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0;'>
                            <p><strong>Bug:</strong> {$bugName}</p>
                            <p><strong>Task:</strong> {$taskName}</p>
                            <p><strong>Priority:</strong> <span class='priority' style='background: {$priority_bg_color}'>{$priority}</span></p>
                            <p><strong>Reported:</strong> " . date('M j, Y g:i A') . "</p>
                        </div>
                        
                        <p>Please review the bug report and assign it to the appropriate developer for resolution.</p>
                        
                        <p><a href='{$this->getAppUrl()}bugs.php' style='background: #007bff; color: white; padding: 10px 15px; text-decoration: none; border-radius: 3px; display: inline-block;'>View Bug Details</a></p>
                    </div>
                </div>
            </body>
            </html>
        ";

        return $this->emailService->sendEmail($email, $subject, $message);
    }

    private function sendBugUpdateEmail($email, $name, $bugName, $taskName, $recipientType) {
        if (!$this->emailService || !$this->emailService->isConfigured()) {
            return false;
        }

        $subject = "📝 Bug Updated: {$bugName}";
        
        $message = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #17a2b8; color: white; padding: 15px; border-radius: 5px; }
                    .content { margin: 20px 0; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>📝 Bug Updated</h2>
                    </div>
                    <div class='content'>
                        <p>Hello {$name},</p>
                        <p>The bug <strong>'{$bugName}'</strong> has been updated.</p>
                        <p><strong>Associated Task:</strong> {$taskName}</p>
                        <p><strong>Updated at:</strong> " . date('M j, Y g:i A') . "</p>
                        <p>Please review the updated bug details as changes may affect your work.</p>
                        <p><a href='{$this->getAppUrl()}bugs.php' style='background: #17a2b8; color: white; padding: 10px 15px; text-decoration: none; border-radius: 3px; display: inline-block;'>View Updated Bug</a></p>
                    </div>
                </div>
            </body>
            </html>
        ";

        return $this->emailService->sendEmail($email, $subject, $message);
    }

    private function sendBugStatusUpdateEmail($email, $name, $bugName, $newStatus, $taskName, $recipientType) {
        if (!$this->emailService || !$this->emailService->isConfigured()) {
            return false;
        }

        $status_colors = array(
            'open' => '#dc3545',
            'in_progress' => '#fd7e14',
            'resolved' => '#28a745',
            'closed' => '#6c757d'
        );

        $subject = "🔄 Bug Status Updated: {$bugName}";
        
        $status_display = ucfirst(str_replace('_', ' ', $newStatus));
        $status_color = isset($status_colors[$newStatus]) ? $status_colors[$newStatus] : '#6c757d';
        
        $message = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #6f42c1; color: white; padding: 15px; border-radius: 5px; }
                    .content { margin: 20px 0; }
                    .status { display: inline-block; padding: 5px 10px; border-radius: 3px; color: white; font-weight: bold; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>🔄 Bug Status Update</h2>
                    </div>
                    <div class='content'>
                        <p>Hello {$name},</p>
                        <p>The status of a bug has been updated in the system:</p>
                        
                        <div style='background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0;'>
                            <p><strong>Bug:</strong> {$bugName}</p>
                            <p><strong>Task:</strong> {$taskName}</p>
                            <p><strong>New Status:</strong> <span class='status' style='background: {$status_color}'>{$status_display}</span></p>
                            <p><strong>Updated:</strong> " . date('M j, Y g:i A') . "</p>
                        </div>
                        
                        <p><a href='{$this->getAppUrl()}bugs.php' style='background: #6f42c1; color: white; padding: 10px 15px; text-decoration: none; border-radius: 3px; display: inline-block;'>View Bug Details</a></p>
                    </div>
                </div>
            </body>
            </html>
        ";

        return $this->emailService->sendEmail($email, $subject, $message);
    }

    private function sendOverdueBugEmail($email, $name, $bugName, $taskName, $priority, $dueDate) {
        if (!$this->emailService || !$this->emailService->isConfigured()) {
            return false;
        }

        $subject = "⚠️ Overdue Bug: {$bugName}";
        $message = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #fd7e14; color: white; padding: 15px; border-radius: 5px; }
                    .content { margin: 20px 0; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>⚠️ Overdue Bug</h2>
                    </div>
                    <div class='content'>
                        <p>Hello {$name},</p>
                        <p>The following bug is overdue and requires immediate attention:</p>
                        <div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin: 15px 0;'>
                            <p><strong>Bug:</strong> {$bugName}</p>
                            <p><strong>Task:</strong> {$taskName}</p>
                            <p><strong>Priority:</strong> {$priority}</p>
                            <p><strong>Due Date:</strong> " . date('M j, Y', strtotime($dueDate)) . "</p>
                            <p><strong>Days Overdue:</strong> " . $this->calculateDaysOverdue($dueDate) . "</p>
                        </div>
                        <p>Please review and take appropriate action.</p>
                        <p><a href='{$this->getAppUrl()}bugs.php' style='background: #fd7e14; color: white; padding: 10px 15px; text-decoration: none; border-radius: 3px; display: inline-block;'>View Bug Details</a></p>
                    </div>
                </div>
            </body>
            </html>
        ";

        return $this->emailService->sendEmail($email, $subject, $message);
    }

    private function calculateDaysOverdue($dueDate) {
        $dueDateTime = new DateTime($dueDate);
        $currentDateTime = new DateTime();
        $interval = $dueDateTime->diff($currentDateTime);
        return $interval->days;
    }

    // UTILITY METHODS
    private function getSetting($key, $default = null) {
        $query = "SELECT setting_value FROM settings WHERE setting_key = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute(array($key));
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? $result['setting_value'] : $default;
    }

    private function createNotification($user_id, $title, $message, $type) {
        $query = "INSERT INTO " . $this->table_name . " 
                  (user_id, title, message, type) 
                  VALUES (:user_id, :title, :message, :type)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':title', $title);
        $stmt->bindParam(':message', $message);
        $stmt->bindParam(':type', $type);
        
        return $stmt->execute();
    }

    private function getAppUrl() {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];
        $path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
        return "{$protocol}://{$host}{$path}/";
    }

    // PUBLIC METHODS FOR NOTIFICATION MANAGEMENT
    public function getUserNotifications($user_id, $limit = 10) {
        $query = "SELECT * FROM " . $this->table_name . " 
                  WHERE user_id = :user_id 
                  ORDER BY created_at DESC 
                  LIMIT :limit";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markAsRead($notification_id, $user_id) {
        $query = "UPDATE " . $this->table_name . " 
                  SET is_read = 1 
                  WHERE id = :id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $notification_id);
        $stmt->bindParam(':user_id', $user_id);
        
        return $stmt->execute();
    }

    public function markAllAsRead($user_id) {
        $query = "UPDATE " . $this->table_name . " 
                  SET is_read = 1 
                  WHERE user_id = :user_id AND is_read = 0";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        
        return $stmt->execute();
    }

    public function getUnreadCount($user_id) {
        $query = "SELECT COUNT(*) as count FROM " . $this->table_name . " 
                  WHERE user_id = :user_id AND is_read = 0";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'];
    }
}
?>