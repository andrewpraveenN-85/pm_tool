<?php
// Include EmailService
require_once 'EmailService.php';

class Notification {
    private $conn;
    private $table_name = "notifications";
    private $emailService;

    public function __construct($db) {
        $this->conn = $db;
        try {
            $this->emailService = new EmailService($db);
        } catch (Exception $e) {
            // Log error but don't break the application
            error_log("EmailService initialization failed: " . $e->getMessage());
            $this->emailService = null;
        }
    }

    public function checkDeadlineNotifications() {
        // Get deadline warning hours from settings
        $warning_hours = $this->getSetting('deadline_warning_hours', 8);
        
        $query = "
            SELECT t.id, t.name, t.end_datetime, 
                   GROUP_CONCAT(DISTINCT ta.user_id) as assignee_ids,
                   t.created_by as manager_id,
                   u.email as manager_email,
                   GROUP_CONCAT(DISTINCT u2.email) as assignee_emails
            FROM tasks t
            LEFT JOIN task_assignments ta ON t.id = ta.task_id
            LEFT JOIN users u ON t.created_by = u.id
            LEFT JOIN users u2 ON ta.user_id = u2.id
            WHERE t.end_datetime BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL ? HOUR)
            AND t.status != 'closed'
            GROUP BY t.id
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$warning_hours]);
        
        while ($task = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $assignee_ids = !empty($task['assignee_ids']) ? explode(',', $task['assignee_ids']) : [];
            $assignee_emails = !empty($task['assignee_emails']) ? explode(',', $task['assignee_emails']) : [];
            
            // Notify developers
            foreach ($assignee_ids as $index => $user_id) {
                if (!empty($user_id)) {
                    $this->createNotification(
                        $user_id,
                        'Task Deadline Approaching',
                        "Task '{$task['name']}' is due in less than {$warning_hours} hours",
                        'deadline'
                    );

                    // Send email to developer if email service is available
                    if ($this->emailService && !empty($assignee_emails[$index])) {
                        $this->sendDeadlineEmail(
                            $assignee_emails[$index],
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

                // Send email to manager if email service is available
                if ($this->emailService && !empty($task['manager_email'])) {
                    $this->sendDeadlineEmail(
                        $task['manager_email'],
                        $task['name'],
                        $task['end_datetime'],
                        $warning_hours,
                        'manager'
                    );
                }
            }
        }
    }

    private function sendDeadlineEmail($email, $taskName, $deadline, $warningHours, $recipientType) {
        if (!$this->emailService || !$this->emailService->isConfigured()) {
            return false;
        }

        $subject = "Task Deadline Approaching: {$taskName}";
        
        $message = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #f8f9fa; padding: 15px; border-radius: 5px; }
                    .content { margin: 20px 0; }
                    .deadline { color: #dc3545; font-weight: bold; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>Task Deadline Notification</h2>
                    </div>
                    <div class='content'>
                        <p>Hello,</p>
                        <p>The task <strong>'{$taskName}'</strong> is approaching its deadline.</p>
                        <p><strong>Deadline:</strong> <span class='deadline'>{$deadline}</span></p>
                        <p><strong>Time remaining:</strong> Less than {$warningHours} hours</p>
                        <p>Please take appropriate action to ensure timely completion.</p>
                    </div>
                </div>
            </body>
            </html>
        ";

        return $this->emailService->sendEmail($email, $subject, $message);
    }

    public function createTaskAssignmentNotification($task_id, $user_ids) {
        $task_query = "SELECT name, created_by FROM tasks WHERE id = :task_id";
        $task_stmt = $this->conn->prepare($task_query);
        $task_stmt->bindParam(':task_id', $task_id);
        $task_stmt->execute();
        $task = $task_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) return;

        // Get user emails
        $user_emails = [];
        if (!empty($user_ids)) {
            $placeholders = str_repeat('?,', count($user_ids) - 1) . '?';
            $email_query = "SELECT id, email FROM users WHERE id IN ($placeholders)";
            $email_stmt = $this->conn->prepare($email_query);
            $email_stmt->execute($user_ids);
            $user_emails = $email_stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        foreach ($user_ids as $user_id) {
            $this->createNotification(
                $user_id,
                'New Task Assignment',
                "You have been assigned to task: '{$task['name']}'",
                'assignment'
            );

            // Send assignment email if email service is available
            if ($this->emailService) {
                $user_email = array_filter($user_emails, function($u) use ($user_id) {
                    return $u['id'] == $user_id;
                });
                $user_email = reset($user_email);

                if ($user_email) {
                    $this->sendAssignmentEmail($user_email['email'], $task['name'], $task['created_by']);
                }
            }
        }
    }

    private function sendAssignmentEmail($email, $taskName, $assignedBy) {
        if (!$this->emailService || !$this->emailService->isConfigured()) {
            return false;
        }

        $subject = "New Task Assignment: {$taskName}";
        
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
                        <h2>New Task Assignment</h2>
                    </div>
                    <div class='content'>
                        <p>Hello,</p>
                        <p>You have been assigned to a new task: <strong>'{$taskName}'</strong></p>
                        <p>This task was assigned by your manager.</p>
                        <p>Please log in to the Task Manager system to view the task details and get started.</p>
                    </div>
                </div>
            </body>
            </html>
        ";

        return $this->emailService->sendEmail($email, $subject, $message);
    }

    public function createBugReportNotification($bug_id) {
        $bug_query = "
            SELECT b.name, t.name as task_name, u.id as manager_id, u.email as manager_email
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
                "A new bug '{$bug['name']}' has been reported for task: '{$bug['task_name']}'",
                'update'
            );

            // Send bug report email to manager if email service is available
            if ($this->emailService && !empty($bug['manager_email'])) {
                $this->sendBugReportEmail($bug['manager_email'], $bug['name'], $bug['task_name']);
            }
        }
    }

    private function sendBugReportEmail($email, $bugName, $taskName) {
        if (!$this->emailService || !$this->emailService->isConfigured()) {
            return false;
        }

        $subject = "New Bug Reported: {$bugName}";
        
        $message = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #dc3545; color: white; padding: 15px; border-radius: 5px; }
                    .content { margin: 20px 0; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>New Bug Report</h2>
                    </div>
                    <div class='content'>
                        <p>Hello Manager,</p>
                        <p>A new bug has been reported in your project:</p>
                        <p><strong>Bug:</strong> {$bugName}</p>
                        <p><strong>Task:</strong> {$taskName}</p>
                        <p>Please review the bug report and take appropriate action.</p>
                    </div>
                </div>
            </body>
            </html>
        ";

        return $this->emailService->sendEmail($email, $subject, $message);
    }

    private function getSetting($key, $default = null) {
        $query = "SELECT setting_value FROM settings WHERE setting_key = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$key]);
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