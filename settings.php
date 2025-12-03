<?php
include 'config/database.php';
include 'includes/auth.php';
include 'includes/activity_logger.php'; // Add ActivityLogger include

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireRole(['manager']);

// Initialize ActivityLogger
$activityLogger = new ActivityLogger($db);
$current_user_id = $_SESSION['user_id'] ?? null;

// Handle settings update
if ($_POST && isset($_POST['update_settings'])) {
    $changed_settings = [];
    $old_settings_values = [];
    $new_settings_values = [];
    
    // Get current settings first for comparison
    $current_settings_query = "SELECT * FROM settings";
    $current_settings_stmt = $db->query($current_settings_query);
    $current_settings = $current_settings_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $current_settings_map = [];
    foreach ($current_settings as $setting) {
        $current_settings_map[$setting['setting_key']] = $setting['setting_value'];
    }
    
    try {
        $db->beginTransaction();
        
        foreach ($_POST['settings'] as $key => $value) {
            $old_value = $current_settings_map[$key] ?? '';
            
            // Encrypt SMTP password
            if ($key === 'smtp_password' && !empty($value) && $value !== '********') {
                $value = base64_encode($value); // Simple encryption
                $display_value = '********';
            } elseif ($key === 'smtp_password' && empty($value)) {
                // Don't update password if empty (keep existing)
                continue;
            } else {
                $display_value = $value;
            }
            
            // Check if value actually changed
            if ($old_value !== $value) {
                $changed_settings[] = $key;
                $old_settings_values[$key] = $old_value;
                $new_settings_values[$key] = $display_value;
                
                $query = "UPDATE settings SET setting_value = :value, updated_at = NOW() WHERE setting_key = :key";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':value', $value);
                $stmt->bindParam(':key', $key);
                $stmt->execute();
            }
        }
        
        $db->commit();
        
        // Log settings update activity if anything changed
        if (!empty($changed_settings)) {
            $activityLogger->logActivity(
                $current_user_id,
                'update',
                'settings',
                null,
                json_encode([
                    'changed_settings' => $changed_settings,
                    'old_values' => $old_settings_values,
                    'new_values' => $new_settings_values,
                    'updated_by' => $current_user_id
                ])
            );
        }
        
        $success = "Settings updated successfully!";
        
        // Clear sensitive data from session
        unset($_POST['settings']['smtp_password']);
        
    } catch (Exception $e) {
        $db->rollBack();
        $error = "Failed to update settings: " . $e->getMessage();
        
        // Log failed update
        $activityLogger->logActivity(
            $current_user_id,
            'settings_update_failed',
            'settings',
            null,
            json_encode([
                'error' => $e->getMessage(),
                'attempted_changes' => array_keys($_POST['settings']),
                'ip_address' => $_SERVER['REMOTE_ADDR']
            ])
        );
    }
}

// Handle SMTP test
if ($_POST && isset($_POST['test_smtp'])) {
    include 'includes/EmailService.php';
    $emailService = new EmailService($db);
    
    try {
        $test_email = $settings_array['notification_email']['setting_value'] ?? $_SESSION['user_email'];
        $subject = "SMTP Configuration Test - Task Manager";
        $message = "
            <html>
            <body>
                <h2>SMTP Configuration Test</h2>
                <p>This is a test email to verify your SMTP configuration.</p>
                <p>If you're receiving this email, your SMTP settings are working correctly.</p>
                <p>Sent at: " . date('Y-m-d H:i:s') . "</p>
            </body>
            </html>
        ";
        
        if ($emailService->sendEmail($test_email, $subject, $message)) {
            $smtp_test_success = "Test email sent successfully to $test_email!";
            
            // Log successful SMTP test
            $activityLogger->logActivity(
                $current_user_id,
                'smtp_test',
                'settings',
                null,
                json_encode([
                    'test_email' => $test_email,
                    'result' => 'success',
                    'ip_address' => $_SERVER['REMOTE_ADDR']
                ])
            );
        } else {
            $smtp_test_error = "Failed to send test email. Please check your SMTP settings.";
            
            // Log failed SMTP test
            $activityLogger->logActivity(
                $current_user_id,
                'smtp_test_failed',
                'settings',
                null,
                json_encode([
                    'test_email' => $test_email,
                    'result' => 'failed',
                    'ip_address' => $_SERVER['REMOTE_ADDR']
                ])
            );
        }
    } catch (Exception $e) {
        $smtp_test_error = "Error testing SMTP: " . $e->getMessage();
    }
}

// Get current settings
$settings_query = "SELECT * FROM settings";
$settings_stmt = $db->query($settings_query);
$settings = $settings_stmt->fetchAll(PDO::FETCH_ASSOC);

$settings_array = [];
foreach ($settings as $setting) {
    $settings_array[$setting['setting_key']] = $setting;
    // Decrypt SMTP password for display (show placeholder instead of actual password)
    if ($setting['setting_key'] === 'smtp_password' && !empty($setting['setting_value'])) {
        $settings_array[$setting['setting_key']]['display_value'] = '********';
    } else {
        $settings_array[$setting['setting_key']]['display_value'] = $setting['setting_value'];
    }
}

// Get recent settings changes for audit log
$recent_settings_changes = $activityLogger->getRecentActivities(10);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - Task Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .audit-log {
            max-height: 300px;
            overflow-y: auto;
        }
        .audit-item {
            border-left: 4px solid;
            margin-bottom: 8px;
            padding: 8px;
            background-color: #f8f9fa;
            font-size: 0.9rem;
        }
        .audit-update { border-color: #007bff; }
        .audit-success { border-color: #28a745; }
        .audit-error { border-color: #dc3545; }
        .audit-info { border-color: #17a2b8; }
        .audit-item small {
            font-size: 0.8rem;
            color: #6c757d;
        }
        .changed-settings {
            font-size: 0.85rem;
            color: #495057;
            background: #e9ecef;
            padding: 2px 6px;
            border-radius: 3px;
            margin: 2px;
            display: inline-block;
        }
    </style>
</head>

<body>
    <?php include 'includes/header.php'; ?>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <h2 class="mb-4">System Settings</h2>

                <?php if (isset($success)): ?>
                    <div class="alert alert-success"><?= $success ?></div>
                <?php endif; ?>
                
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?= $error ?></div>
                <?php endif; ?>
                
                <?php if (isset($smtp_test_success)): ?>
                    <div class="alert alert-success"><?= $smtp_test_success ?></div>
                <?php endif; ?>
                
                <?php if (isset($smtp_test_error)): ?>
                    <div class="alert alert-danger"><?= $smtp_test_error ?></div>
                <?php endif; ?>

                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">General Settings</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="settingsForm">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Company Name</label>
                                    <input type="text" class="form-control"
                                        name="settings[company_name]"
                                        value="<?= htmlspecialchars($settings_array['company_name']['setting_value']) ?>">
                                    <small class="text-muted"><?= $settings_array['company_name']['description'] ?></small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Notification Email</label>
                                    <input type="email" class="form-control"
                                        name="settings[notification_email]"
                                        value="<?= htmlspecialchars($settings_array['notification_email']['setting_value']) ?>">
                                    <small class="text-muted"><?= $settings_array['notification_email']['description'] ?></small>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Deadline Warning (Hours)</label>
                                    <input type="number" class="form-control"
                                        name="settings[deadline_warning_hours]"
                                        value="<?= htmlspecialchars($settings_array['deadline_warning_hours']['setting_value']) ?>"
                                        min="1" max="168">
                                    <small class="text-muted"><?= $settings_array['deadline_warning_hours']['description'] ?></small>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Auto Archive (Days)</label>
                                    <input type="number" class="form-control"
                                        name="settings[auto_archive_days]"
                                        value="<?= htmlspecialchars($settings_array['auto_archive_days']['setting_value']) ?>"
                                        min="1" max="365">
                                    <small class="text-muted"><?= $settings_array['auto_archive_days']['description'] ?></small>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Max File Size (MB)</label>
                                    <input type="number" class="form-control"
                                        name="settings[max_file_size]"
                                        value="<?= htmlspecialchars($settings_array['max_file_size']['setting_value']) ?>"
                                        min="1" max="100">
                                    <small class="text-muted"><?= $settings_array['max_file_size']['description'] ?></small>
                                </div>
                            </div>

                            <!-- SMTP Settings Section -->
                            <div class="mt-4 pt-4 border-top">
                                <h6 class="mb-3">SMTP Email Settings</h6>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">SMTP Host</label>
                                        <input type="text" class="form-control"
                                            name="settings[smtp_host]"
                                            value="<?= htmlspecialchars($settings_array['smtp_host']['setting_value']) ?>">
                                        <small class="text-muted"><?= $settings_array['smtp_host']['description'] ?></small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">SMTP Port</label>
                                        <input type="number" class="form-control"
                                            name="settings[smtp_port]"
                                            value="<?= htmlspecialchars($settings_array['smtp_port']['setting_value']) ?>"
                                            min="1" max="65535">
                                        <small class="text-muted"><?= $settings_array['smtp_port']['description'] ?></small>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">SMTP Username</label>
                                        <input type="text" class="form-control"
                                            name="settings[smtp_username]"
                                            value="<?= htmlspecialchars($settings_array['smtp_username']['setting_value']) ?>">
                                        <small class="text-muted"><?= $settings_array['smtp_username']['description'] ?></small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">SMTP Password</label>
                                        <input type="password" class="form-control"
                                            name="settings[smtp_password]"
                                            value="<?= htmlspecialchars($settings_array['smtp_password']['display_value']) ?>"
                                            placeholder="Leave blank to keep current password"
                                            autocomplete="new-password">
                                        <small class="text-muted"><?= $settings_array['smtp_password']['description'] ?></small>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">SMTP Encryption</label>
                                        <select class="form-control" name="settings[smtp_encryption]">
                                            <option value="tls" <?= $settings_array['smtp_encryption']['setting_value'] == 'tls' ? 'selected' : '' ?>>TLS</option>
                                            <option value="ssl" <?= $settings_array['smtp_encryption']['setting_value'] == 'ssl' ? 'selected' : '' ?>>SSL</option>
                                            <option value="none" <?= $settings_array['smtp_encryption']['setting_value'] == 'none' ? 'selected' : '' ?>>None</option>
                                        </select>
                                        <small class="text-muted"><?= $settings_array['smtp_encryption']['description'] ?></small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">From Email</label>
                                        <input type="email" class="form-control"
                                            name="settings[smtp_from_email]"
                                            value="<?= htmlspecialchars($settings_array['smtp_from_email']['setting_value']) ?>">
                                        <small class="text-muted"><?= $settings_array['smtp_from_email']['description'] ?></small>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">From Name</label>
                                        <input type="text" class="form-control"
                                            name="settings[smtp_from_name]"
                                            value="<?= htmlspecialchars($settings_array['smtp_from_name']['setting_value']) ?>">
                                        <small class="text-muted"><?= $settings_array['smtp_from_name']['description'] ?></small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Test Email</label>
                                        <div class="d-grid">
                                            <button type="button" class="btn btn-outline-primary" onclick="testSmtpSettings()">
                                                <i class="fas fa-paper-plane"></i> Test SMTP Settings
                                            </button>
                                        </div>
                                        <small class="text-muted">Send a test email to verify SMTP configuration</small>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4">
                                <button type="submit" name="update_settings" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Settings
                                </button>
                                <button type="reset" class="btn btn-secondary" onclick="resetSettingsForm()">Reset Changes</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Settings Audit Log -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Recent Settings Changes</h5>
                        <small class="text-muted">Last 10 changes</small>
                    </div>
                    <div class="card-body">
                        <div class="audit-log">
                            <?php if (empty($recent_settings_changes)): ?>
                                <div class="text-center text-muted py-3">
                                    <i class="fas fa-info-circle fa-2x mb-2"></i>
                                    <p>No settings changes recorded yet.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($recent_settings_changes as $log): ?>
                                    <?php if (in_array($log['action'], ['update', 'smtp_test', 'smtp_test_failed', 'settings_update_failed'])): ?>
                                        <div class="audit-item audit-<?= 
                                            $log['action'] == 'update' ? 'update' : 
                                            ($log['action'] == 'smtp_test' ? 'success' : 'error')
                                        ?>">
                                            <div class="d-flex justify-content-between">
                                                <strong>
                                                    <?= htmlspecialchars($log['user_name'] ?? 'System') ?>
                                                    <?php if ($log['action'] == 'update'): ?>
                                                        updated settings
                                                    <?php elseif ($log['action'] == 'smtp_test'): ?>
                                                        tested SMTP configuration
                                                    <?php elseif ($log['action'] == 'smtp_test_failed'): ?>
                                                        failed SMTP test
                                                    <?php elseif ($log['action'] == 'settings_update_failed'): ?>
                                                        failed to update settings
                                                    <?php endif; ?>
                                                </strong>
                                                <small><?= date('M j, H:i', strtotime($log['created_at'])) ?></small>
                                            </div>
                                            
                                            <?php if ($log['details']): 
                                                $details = json_decode($log['details'], true);
                                            ?>
                                                <div class="mt-2">
                                                    <?php if (isset($details['changed_settings'])): ?>
                                                        <div class="mb-1">
                                                            <strong>Changed:</strong>
                                                            <?php foreach ($details['changed_settings'] as $setting): ?>
                                                                <span class="changed-settings"><?= $setting ?></span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (isset($details['test_email'])): ?>
                                                        <small>Test email: <?= htmlspecialchars($details['test_email']) ?></small>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (isset($details['error'])): ?>
                                                        <small class="text-danger">Error: <?= htmlspecialchars($details['error']) ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <small class="d-block mt-1">
                                                <i class="fas fa-globe"></i> <?= htmlspecialchars($log['ip_address']) ?>
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- System Information -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">System Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>PHP Version:</strong> <?= phpversion() ?></p>
                                <p><strong>Database:</strong> MySQL</p>
                                <p><strong>Server Software:</strong> <?= $_SERVER['SERVER_SOFTWARE'] ?></p>
                                <p><strong>Server IP:</strong> <?= $_SERVER['SERVER_ADDR'] ?></p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>Total Users:</strong>
                                    <?= $db->query("SELECT COUNT(*) FROM users")->fetchColumn() ?>
                                </p>
                                <p><strong>Total Projects:</strong>
                                    <?= $db->query("SELECT COUNT(*) FROM projects")->fetchColumn() ?>
                                </p>
                                <p><strong>Total Tasks:</strong>
                                    <?= $db->query("SELECT COUNT(*) FROM tasks")->fetchColumn() ?>
                                </p>
                                <p><strong>Activity Logs:</strong>
                                    <?= $db->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn() ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function testSmtpSettings() {
            if (confirm('This will send a test email to your configured notification email address. Continue?')) {
                // Show loading state
                const btn = event.target;
                const originalText = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Testing...';
                btn.disabled = true;

                // Create a hidden form for SMTP test
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'test_smtp';
                input.value = '1';
                
                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function resetSettingsForm() {
            if (confirm('Are you sure you want to reset all changes? This cannot be undone.')) {
                document.getElementById('settingsForm').reset();
            }
        }
        
        // Form validation
        document.getElementById('settingsForm').addEventListener('submit', function(e) {
            const smtpPassword = this.querySelector('input[name="settings[smtp_password]"]');
            const smtpPasswordValue = smtpPassword.value;
            
            // If password field has the placeholder value, clear it to avoid updating
            if (smtpPasswordValue === '********') {
                smtpPassword.value = '';
            }
            
            // Validate email fields
            const emailFields = this.querySelectorAll('input[type="email"]');
            let isValid = true;
            
            emailFields.forEach(field => {
                const email = field.value.trim();
                if (email && !validateEmail(email)) {
                    field.classList.add('is-invalid');
                    isValid = false;
                } else {
                    field.classList.remove('is-invalid');
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                alert('Please check your email addresses. One or more are invalid.');
            }
        });
        
        function validateEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        }
    </script>
</body>
<footer class="bg-dark text-light text-center py-3 mt-5">
    <div class="container">
        <p class="mb-0">Developed by APNLAB. 2025.</p>
    </div>
</footer>
</html>