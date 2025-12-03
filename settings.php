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

// Handle Database Backup
if (isset($_POST['backup_db'])) {
    try {
        $backup_dir = __DIR__ . '/../backups/';
        if (!is_dir($backup_dir)) {
            mkdir($backup_dir, 0755, true);
        }
        
        $timestamp = date('Y-m-d_H-i-s');
        $backup_file = $backup_dir . 'db_backup_' . $timestamp . '.sql';
        
        // Get database configuration
        $host = $database->getHost();
        $dbname = $database->getDBName();
        $username = $database->getUsername();
        $password = $database->getPassword();
        
        // Create backup using mysqldump command
        $command = "mysqldump --host={$host} --user={$username} --password={$password} {$dbname} > {$backup_file}";
        system($command, $output);
        
        if (file_exists($backup_file) && filesize($backup_file) > 0) {
            $db_backup_success = "Database backup created successfully! File: " . basename($backup_file);
            
            // Log backup activity
            $activityLogger->logActivity(
                $current_user_id,
                'db_backup',
                'system',
                null,
                json_encode([
                    'backup_file' => basename($backup_file),
                    'file_size' => filesize($backup_file),
                    'ip_address' => $_SERVER['REMOTE_ADDR']
                ])
            );
        } else {
            $db_backup_error = "Failed to create database backup. Please check server permissions.";
        }
    } catch (Exception $e) {
        $db_backup_error = "Error creating database backup: " . $e->getMessage();
    }
}

// Handle Uploads Folder Backup
if (isset($_POST['backup_uploads'])) {
    try {
        $backup_dir = __DIR__ . '/../backups/';
        if (!is_dir($backup_dir)) {
            mkdir($backup_dir, 0755, true);
        }
        
        $timestamp = date('Y-m-d_H-i-s');
        $uploads_dir = __DIR__ . '/../uploads/';
        $backup_file = $backup_dir . 'uploads_backup_' . $timestamp . '.zip';
        
        if (is_dir($uploads_dir)) {
            // Create Zip Archive
            $zip = new ZipArchive();
            if ($zip->open($backup_file, ZipArchive::CREATE) === TRUE) {
                // Create recursive directory iterator
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($uploads_dir),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                
                foreach ($files as $name => $file) {
                    // Skip directories (they would be added automatically)
                    if (!$file->isDir()) {
                        // Get real and relative path for current file
                        $filePath = $file->getRealPath();
                        $relativePath = substr($filePath, strlen($uploads_dir));
                        
                        // Add current file to archive
                        $zip->addFile($filePath, $relativePath);
                    }
                }
                
                $zip->close();
                
                if (file_exists($backup_file)) {
                    $uploads_backup_success = "Uploads folder backup created successfully! File: " . basename($backup_file);
                    
                    // Log backup activity
                    $activityLogger->logActivity(
                        $current_user_id,
                        'uploads_backup',
                        'system',
                        null,
                        json_encode([
                            'backup_file' => basename($backup_file),
                            'file_size' => filesize($backup_file),
                            'ip_address' => $_SERVER['REMOTE_ADDR']
                        ])
                    );
                } else {
                    $uploads_backup_error = "Failed to create uploads backup.";
                }
            } else {
                $uploads_backup_error = "Failed to create zip archive. Please check ZipArchive extension.";
            }
        } else {
            $uploads_backup_error = "Uploads directory not found.";
        }
    } catch (Exception $e) {
        $uploads_backup_error = "Error creating uploads backup: " . $e->getMessage();
    }
}

// Handle Full System Backup (DB + Uploads)
if (isset($_POST['backup_full'])) {
    try {
        $backup_dir = __DIR__ . '/../backups/';
        if (!is_dir($backup_dir)) {
            mkdir($backup_dir, 0755, true);
        }
        
        $timestamp = date('Y-m-d_H-i-s');
        $full_backup_file = $backup_dir . 'full_backup_' . $timestamp . '.zip';
        $temp_dir = sys_get_temp_dir() . '/backup_' . $timestamp . '/';
        
        if (!is_dir($temp_dir)) {
            mkdir($temp_dir, 0755, true);
        }
        
        // Step 1: Backup Database
        $db_backup_file = $temp_dir . 'database_backup.sql';
        $host = $database->getHost();
        $dbname = $database->getDBName();
        $username = $database->getUsername();
        $password = $database->getPassword();
        
        $command = "mysqldump --host={$host} --user={$username} --password={$password} {$dbname} > {$db_backup_file}";
        system($command, $output);
        
        // Step 2: Copy uploads folder
        $uploads_dir = __DIR__ . '/../uploads/';
        if (is_dir($uploads_dir)) {
            $uploads_temp = $temp_dir . 'uploads/';
            mkdir($uploads_temp, 0755, true);
            
            // Copy all files from uploads directory
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($uploads_dir),
                RecursiveIteratorIterator::SELF_FIRST
            );
            
            foreach ($files as $file) {
                if ($file->isFile()) {
                    $dest = $uploads_temp . substr($file->getPathname(), strlen($uploads_dir));
                    $destDir = dirname($dest);
                    if (!is_dir($destDir)) {
                        mkdir($destDir, 0755, true);
                    }
                    copy($file->getPathname(), $dest);
                }
            }
        }
        
        // Step 3: Create zip archive
        $zip = new ZipArchive();
        if ($zip->open($full_backup_file, ZipArchive::CREATE) === TRUE) {
            // Add database backup
            if (file_exists($db_backup_file)) {
                $zip->addFile($db_backup_file, 'database_backup.sql');
            }
            
            // Add uploads folder
            if (is_dir($temp_dir . 'uploads/')) {
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($temp_dir . 'uploads/'),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                
                foreach ($files as $name => $file) {
                    if (!$file->isDir()) {
                        $filePath = $file->getRealPath();
                        $relativePath = substr($filePath, strlen($temp_dir));
                        $zip->addFile($filePath, $relativePath);
                    }
                }
            }
            
            $zip->close();
            
            // Cleanup temp directory
            $this->deleteDirectory($temp_dir);
            
            if (file_exists($full_backup_file)) {
                $full_backup_success = "Full system backup created successfully! File: " . basename($full_backup_file);
                
                // Log backup activity
                $activityLogger->logActivity(
                    $current_user_id,
                    'full_system_backup',
                    'system',
                    null,
                    json_encode([
                        'backup_file' => basename($full_backup_file),
                        'file_size' => filesize($full_backup_file),
                        'ip_address' => $_SERVER['REMOTE_ADDR']
                    ])
                );
            } else {
                $full_backup_error = "Failed to create full system backup.";
            }
        } else {
            $full_backup_error = "Failed to create zip archive.";
        }
        
    } catch (Exception $e) {
        $full_backup_error = "Error creating full system backup: " . $e->getMessage();
    }
}

// Helper function to delete directory recursively
function deleteDirectory($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    
    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
        (is_dir("$dir/$file")) ? deleteDirectory("$dir/$file") : unlink("$dir/$file");
    }
    return rmdir($dir);
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
        .backup-section {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .backup-btn {
            margin: 5px;
            transition: all 0.3s;
        }
        .backup-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
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
                
                <?php if (isset($db_backup_success)): ?>
                    <div class="alert alert-success"><?= $db_backup_success ?></div>
                <?php endif; ?>
                
                <?php if (isset($db_backup_error)): ?>
                    <div class="alert alert-danger"><?= $db_backup_error ?></div>
                <?php endif; ?>
                
                <?php if (isset($uploads_backup_success)): ?>
                    <div class="alert alert-success"><?= $uploads_backup_success ?></div>
                <?php endif; ?>
                
                <?php if (isset($uploads_backup_error)): ?>
                    <div class="alert alert-danger"><?= $uploads_backup_error ?></div>
                <?php endif; ?>
                
                <?php if (isset($full_backup_success)): ?>
                    <div class="alert alert-success"><?= $full_backup_success ?></div>
                <?php endif; ?>
                
                <?php if (isset($full_backup_error)): ?>
                    <div class="alert alert-danger"><?= $full_backup_error ?></div>
                <?php endif; ?>

                <!-- Backup Section -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-database"></i> System Backup</h5>
                    </div>
                    <div class="card-body">
                        <div class="backup-section">
                            <h6 class="mb-4">Create System Backups</h6>
                            <p class="text-muted mb-4">Create backups of your database and uploads folder. Backups are stored in the <code>/backups/</code> directory.</p>
                            
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <form method="POST" id="dbBackupForm">
                                        <button type="submit" name="backup_db" class="btn btn-outline-primary btn-lg w-100 backup-btn" onclick="return confirmBackup('database')">
                                            <i class="fas fa-database fa-2x mb-2"></i><br>
                                            <strong>Database Backup</strong><br>
                                            <small class="text-muted">Export all database tables</small>
                                        </button>
                                    </form>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <form method="POST" id="uploadsBackupForm">
                                        <button type="submit" name="backup_uploads" class="btn btn-outline-success btn-lg w-100 backup-btn" onclick="return confirmBackup('uploads')">
                                            <i class="fas fa-folder fa-2x mb-2"></i><br>
                                            <strong>Uploads Backup</strong><br>
                                            <small class="text-muted">Backup all uploaded files</small>
                                        </button>
                                    </form>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <form method="POST" id="fullBackupForm">
                                        <button type="submit" name="backup_full" class="btn btn-outline-warning btn-lg w-100 backup-btn" onclick="return confirmBackup('full')">
                                            <i class="fas fa-server fa-2x mb-2"></i><br>
                                            <strong>Full System Backup</strong><br>
                                            <small class="text-muted">Database + Uploads folder</small>
                                        </button>
                                    </form>
                                </div>
                            </div>
                            
                            <div class="mt-4">
                                <h6>Recent Backups</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover">
                                        <thead>
                                            <tr>
                                                <th>File Name</th>
                                                <th>Type</th>
                                                <th>Size</th>
                                                <th>Date</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $backup_dir = __DIR__ . '/../backups/';
                                            if (is_dir($backup_dir)) {
                                                $files = scandir($backup_dir, SCANDIR_SORT_DESCENDING);
                                                $count = 0;
                                                foreach ($files as $file) {
                                                    if ($file !== '.' && $file !== '..' && (strpos($file, '.sql') !== false || strpos($file, '.zip') !== false)) {
                                                        $filepath = $backup_dir . $file;
                                                        $filesize = filesize($filepath);
                                                        $filetype = strpos($file, 'db_backup') === 0 ? 'Database' : 
                                                                   (strpos($file, 'uploads_backup') === 0 ? 'Uploads' : 'Full');
                                                        echo '
                                                        <tr>
                                                            <td><i class="fas ' . ($filetype === 'Database' ? 'fa-database' : ($filetype === 'Uploads' ? 'fa-folder' : 'fa-server')) . '"></i> ' . htmlspecialchars($file) . '</td>
                                                            <td><span class="badge bg-' . ($filetype === 'Database' ? 'primary' : ($filetype === 'Uploads' ? 'success' : 'warning')) . '">' . $filetype . '</span></td>
                                                            <td>' . formatBytes($filesize) . '</td>
                                                            <td>' . date('Y-m-d H:i:s', filemtime($filepath)) . '</td>
                                                            <td>
                                                                <a href="download_backup.php?file=' . urlencode($file) . '" class="btn btn-sm btn-outline-primary"><i class="fas fa-download"></i></a>
                                                                <button onclick="deleteBackup(\'' . htmlspecialchars($file) . '\')" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                                            </td>
                                                        </tr>';
                                                        $count++;
                                                        if ($count >= 5) break;
                                                    }
                                                }
                                                if ($count === 0) {
                                                    echo '<tr><td colspan="5" class="text-center">No backups found</td></tr>';
                                                }
                                            } else {
                                                echo '<tr><td colspan="5" class="text-center">Backup directory not found</td></tr>';
                                            }
                                            
                                            function formatBytes($bytes, $precision = 2) {
                                                $units = ['B', 'KB', 'MB', 'GB', 'TB'];
                                                $bytes = max($bytes, 0);
                                                $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
                                                $pow = min($pow, count($units) - 1);
                                                $bytes /= pow(1024, $pow);
                                                return round($bytes, $precision) . ' ' . $units[$pow];
                                            }
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Rest of your existing settings form (General Settings) -->
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
                                <p><strong>Backup Directory:</strong> 
                                    <?php 
                                    $backup_dir = __DIR__ . '/../backups/';
                                    echo is_dir($backup_dir) ? '<span class="text-success">Exists</span>' : '<span class="text-danger">Not found</span>';
                                    ?>
                                </p>
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
                                <p><strong>Uploads Directory:</strong>
                                    <?php
                                    $uploads_dir = __DIR__ . '/../uploads/';
                                    echo is_dir($uploads_dir) ? '<span class="text-success">Exists</span>' : '<span class="text-danger">Not found</span>';
                                    ?>
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
        
        // Backup confirmation functions
        function confirmBackup(type) {
            let message = '';
            switch(type) {
                case 'database':
                    message = 'This will create a backup of your entire database. This may take a few moments depending on the database size. Continue?';
                    break;
                case 'uploads':
                    message = 'This will create a ZIP backup of your uploads folder. This may take a while if you have many files. Continue?';
                    break;
                case 'full':
                    message = 'This will create a full system backup (database + uploads folder). This may take several minutes. Continue?';
                    break;
            }
            
            if (!confirm(message)) {
                return false;
            }
            
            // Show loading state
            const btn = event.target;
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating Backup...';
            btn.disabled = true;
            
            setTimeout(() => {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }, 3000);
            
            return true;
        }
        
        function deleteBackup(filename) {
            if (confirm('Are you sure you want to delete backup file: ' + filename + '?')) {
                window.location.href = 'delete_backup.php?file=' + encodeURIComponent(filename);
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