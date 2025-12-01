<?php
include 'config/database.php';
include 'includes/auth.php';
include 'includes/EmailService.php';
include 'includes/activity_logger.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireRole(['manager']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Initialize services
        $emailService = new EmailService($db);
        $activityLogger = new ActivityLogger($db);
        
        // Check if SMTP is configured
        if (!$emailService->isConfigured()) {
            // Log failed attempt
            $activityLogger->logActivity(
                $_SESSION['user_id'],
                'smtp_test_failed',
                'system',
                null,
                'SMTP test failed: SMTP settings not configured'
            );
            
            echo json_encode([
                'success' => false,
                'error' => 'SMTP settings are not fully configured. Please check your SMTP host, username, and password.'
            ]);
            exit;
        }
        
        // Get notification email from settings
        $query = "SELECT setting_value FROM settings WHERE setting_key = 'notification_email'";
        $stmt = $db->query($query);
        $notification_email = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $test_email = $notification_email ? $notification_email['setting_value'] : '';
        
        if (empty($test_email)) {
            // Log failed attempt
            $activityLogger->logActivity(
                $_SESSION['user_id'],
                'smtp_test_failed',
                'system',
                null,
                'SMTP test failed: Notification email not configured'
            );
            
            echo json_encode([
                'success' => false,
                'error' => 'Notification email is not configured. Please set a notification email in the settings.'
            ]);
            exit;
        }
        
        // Get SMTP settings for logging
        $smtp_settings_query = "SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'smtp_%'";
        $smtp_settings_stmt = $db->query($smtp_settings_query);
        $smtp_settings = $smtp_settings_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $smtp_config = [];
        foreach ($smtp_settings as $setting) {
            if ($setting['setting_key'] === 'smtp_password') {
                $smtp_config[$setting['setting_key']] = '***'; // Hide password
            } else {
                $smtp_config[$setting['setting_key']] = $setting['setting_value'];
            }
        }
        
        // Test email content
        $subject = "SMTP Test Email - Task Manager";
        $message = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #007bff; color: white; padding: 20px; border-radius: 5px 5px 0 0; }
                    .content { background: #f8f9fa; padding: 20px; border-radius: 0 0 5px 5px; }
                    .success { color: #28a745; font-weight: bold; }
                    .info { background: #d1ecf1; padding: 10px; border-radius: 3px; margin: 10px 0; }
                    .config { background: #e9ecef; padding: 10px; border-radius: 3px; margin: 10px 0; font-family: monospace; font-size: 0.9em; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>✅ SMTP Test Successful</h2>
                    </div>
                    <div class='content'>
                        <p>Hello,</p>
                        <p>This is a test email from your <strong>Task Manager</strong> system.</p>
                        <div class='info'>
                            <p><strong>Test Details:</strong></p>
                            <ul>
                                <li>Sent at: " . date('Y-m-d H:i:s') . "</li>
                                <li>From: Task Manager System</li>
                                <li>To: " . htmlspecialchars($test_email) . "</li>
                                <li>Purpose: SMTP Configuration Test</li>
                                <li>Initiated by: " . htmlspecialchars($_SESSION['user_name']) . " (" . $_SESSION['user_role'] . ")</li>
                            </ul>
                        </div>
                        <div class='config'>
                            <p><strong>SMTP Configuration:</strong></p>
                            <ul>
                                <li>Host: " . htmlspecialchars($smtp_config['smtp_host']) . "</li>
                                <li>Port: " . htmlspecialchars($smtp_config['smtp_port']) . "</li>
                                <li>Username: " . htmlspecialchars($smtp_config['smtp_username']) . "</li>
                                <li>Encryption: " . htmlspecialchars($smtp_config['smtp_encryption']) . "</li>
                                <li>From Email: " . htmlspecialchars($smtp_config['smtp_from_email']) . "</li>
                                <li>From Name: " . htmlspecialchars($smtp_config['smtp_from_name']) . "</li>
                            </ul>
                        </div>
                        <p class='success'>✅ Your SMTP settings are working correctly!</p>
                        <p>You will now receive email notifications for:</p>
                        <ul>
                            <li>Task assignments</li>
                            <li>Approaching deadlines</li>
                            <li>Bug reports</li>
                            <li>System notifications</li>
                            <li>Performance reports</li>
                            <li>Activity summaries</li>
                        </ul>
                        <p>If you received this email, your SMTP configuration is properly set up and ready for production use.</p>
                    </div>
                </div>
            </body>
            </html>
        ";
        
        // Send test email
        $result = $emailService->sendEmail($test_email, $subject, $message);
        
        if ($result) {
            // Log successful test
            $activityLogger->logActivity(
                $_SESSION['user_id'],
                'smtp_test_success',
                'system',
                null,
                "SMTP test completed successfully. Email sent to: " . $test_email . 
                " | Configuration: " . json_encode($smtp_config)
            );
            
            echo json_encode([
                'success' => true,
                'message' => 'Test email sent successfully to: ' . $test_email,
                'details' => [
                    'recipient' => $test_email,
                    'timestamp' => date('Y-m-d H:i:s'),
                    'initiated_by' => $_SESSION['user_name'],
                    'smtp_config' => $smtp_config
                ]
            ]);
        } else {
            // Log failed email sending
            $activityLogger->logActivity(
                $_SESSION['user_id'],
                'smtp_test_failed',
                'system',
                null,
                "SMTP test failed: Email sending failed for " . $test_email . 
                " | Configuration: " . json_encode($smtp_config)
            );
            
            echo json_encode([
                'success' => false,
                'error' => 'Failed to send test email. Please check your SMTP settings and try again. ' .
                          'Common issues: incorrect credentials, firewall blocking port, or SMTP server restrictions.'
            ]);
        }
        
    } catch (Exception $e) {
        // Log exception
        error_log("SMTP Test Error: " . $e->getMessage());
        
        $activityLogger->logActivity(
            $_SESSION['user_id'],
            'smtp_test_error',
            'system',
            null,
            "SMTP test error: " . $e->getMessage()
        );
        
        echo json_encode([
            'success' => false,
            'error' => 'SMTP test failed: ' . $e->getMessage() . 
                      ' Please check your SMTP configuration and server settings.'
        ]);
    }
} else {
    // Log invalid request method
    $activityLogger = new ActivityLogger($db);
    $activityLogger->logActivity(
        $_SESSION['user_id'],
        'smtp_test_invalid_request',
        'system',
        null,
        "Invalid request method: " . $_SERVER['REQUEST_METHOD']
    );
    
    echo json_encode([
        'success' => false,
        'error' => 'Invalid request method. Only POST requests are allowed.'
    ]);
}
?>