<?php
include 'config/database.php';
include 'includes/auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireRole(['manager']);

// Handle settings update
if ($_POST && isset($_POST['update_settings'])) {
    foreach ($_POST['settings'] as $key => $value) {
        // Encrypt SMTP password
        if ($key === 'smtp_password' && !empty($value)) {
            $value = base64_encode($value); // Simple encryption, consider using proper encryption
        } elseif ($key === 'smtp_password' && empty($value)) {
            // Don't update password if empty (keep existing)
            continue;
        }
        
        $query = "UPDATE settings SET setting_value = :value, updated_at = NOW() WHERE setting_key = :key";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':value', $value);
        $stmt->bindParam(':key', $key);
        $stmt->execute();
    }
    $success = "Settings updated successfully!";
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - Task Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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

                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">General Settings</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
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
                                           value="<?= htmlspecialchars($settings_array['deadline_warning_hours']['setting_value']) ?>">
                                    <small class="text-muted"><?= $settings_array['deadline_warning_hours']['description'] ?></small>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Auto Archive (Days)</label>
                                    <input type="number" class="form-control" 
                                           name="settings[auto_archive_days]" 
                                           value="<?= htmlspecialchars($settings_array['auto_archive_days']['setting_value']) ?>">
                                    <small class="text-muted"><?= $settings_array['auto_archive_days']['description'] ?></small>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Max File Size (MB)</label>
                                    <input type="number" class="form-control" 
                                           name="settings[max_file_size]" 
                                           value="<?= htmlspecialchars($settings_array['max_file_size']['setting_value']) ?>">
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
                                               value="<?= htmlspecialchars($settings_array['smtp_port']['setting_value']) ?>">
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
                                               placeholder="Leave blank to keep current password">
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
                                <button type="reset" class="btn btn-secondary">Reset Changes</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- System Information -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0">System Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>PHP Version:</strong> <?= phpversion() ?></p>
                                <p><strong>Database:</strong> MySQL</p>
                                <p><strong>Server Software:</strong> <?= $_SERVER['SERVER_SOFTWARE'] ?></p>
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
        // You can implement an AJAX call to test SMTP settings
        alert('SMTP test feature would be implemented here. This would send a test email to the configured notification email.');
        // Example implementation:
        // fetch('test_smtp.php', { method: 'POST' })
        // .then(response => response.json())
        // .then(data => {
        //     if (data.success) {
        //         alert('Test email sent successfully!');
        //     } else {
        //         alert('Failed to send test email: ' + data.error);
        //     }
        // });
    }
    </script>
</body>
</html>