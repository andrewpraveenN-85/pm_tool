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

// Handle logo upload
if ($_POST && isset($_POST['upload_logo'])) {
    try {
        $upload_dir = __DIR__ . '/../uploads/logo/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/svg+xml', 'image/gif'];
        $max_size = 2 * 1024 * 1024; // 2MB
        
        if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
            $file_info = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($file_info, $_FILES['logo_file']['tmp_name']);
            finfo_close($file_info);
            
            if (!in_array($mime_type, $allowed_types)) {
                $logo_error = "Invalid file type. Allowed: JPG, PNG, SVG, GIF";
            } elseif ($_FILES['logo_file']['size'] > $max_size) {
                $logo_error = "File too large. Maximum size: 2MB";
            } else {
                // Generate unique filename
                $extension = pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION);
                $filename = 'logo_' . time() . '.' . $extension;
                $filepath = $upload_dir . $filename;
                
                if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $filepath)) {
                    // Update database setting
                    $relative_path = 'uploads/logo/' . $filename;
                    $query = "UPDATE settings SET setting_value = :value, updated_at = NOW() WHERE setting_key = 'app_logo'";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':value', $relative_path);
                    $stmt->execute();
                    
                    $logo_success = "Logo uploaded successfully!";
                    
                    // Log activity
                    $activityLogger->logActivity(
                        $current_user_id,
                        'logo_upload',
                        'settings',
                        null,
                        json_encode([
                            'filename' => $filename,
                            'file_size' => $_FILES['logo_file']['size'],
                            'mime_type' => $mime_type
                        ])
                    );
                } else {
                    $logo_error = "Failed to upload logo. Please try again.";
                }
            }
        } else {
            $logo_error = "Please select a valid logo file.";
        }
    } catch (Exception $e) {
        $logo_error = "Error uploading logo: " . $e->getMessage();
    }
}

// Handle favicon upload
if ($_POST && isset($_POST['upload_favicon'])) {
    try {
        $upload_dir = __DIR__ . '/../uploads/favicon/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $allowed_types = ['image/x-icon', 'image/vnd.microsoft.icon', 'image/png', 'image/jpeg'];
        $max_size = 512 * 1024; // 512KB
        
        if (isset($_FILES['favicon_file']) && $_FILES['favicon_file']['error'] === UPLOAD_ERR_OK) {
            $file_info = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($file_info, $_FILES['favicon_file']['tmp_name']);
            finfo_close($file_info);
            
            if (!in_array($mime_type, $allowed_types)) {
                $favicon_error = "Invalid file type. Allowed: ICO, PNG, JPG";
            } elseif ($_FILES['favicon_file']['size'] > $max_size) {
                $favicon_error = "File too large. Maximum size: 512KB";
            } else {
                // Generate unique filename
                $extension = pathinfo($_FILES['favicon_file']['name'], PATHINFO_EXTENSION);
                $filename = 'favicon_' . time() . '.' . $extension;
                $filepath = $upload_dir . $filename;
                
                if (move_uploaded_file($_FILES['favicon_file']['tmp_name'], $filepath)) {
                    // Update database setting
                    $relative_path = 'uploads/favicon/' . $filename;
                    $query = "UPDATE settings SET setting_value = :value, updated_at = NOW() WHERE setting_key = 'app_favicon'";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':value', $relative_path);
                    $stmt->execute();
                    
                    $favicon_success = "Favicon uploaded successfully!";
                    
                    // Log activity
                    $activityLogger->logActivity(
                        $current_user_id,
                        'favicon_upload',
                        'settings',
                        null,
                        json_encode([
                            'filename' => $filename,
                            'file_size' => $_FILES['favicon_file']['size'],
                            'mime_type' => $mime_type
                        ])
                    );
                } else {
                    $favicon_error = "Failed to upload favicon. Please try again.";
                }
            }
        } else {
            $favicon_error = "Please select a valid favicon file.";
        }
    } catch (Exception $e) {
        $favicon_error = "Error uploading favicon: " . $e->getMessage();
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
        $uploads_dir = __DIR__ . '/uploads/';
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
        $uploads_dir = __DIR__ . '/uploads/';
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
            deleteDirectory($temp_dir);
            
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
$settings_query = "SELECT * FROM settings ORDER BY category, setting_key";
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
        .logo-preview {
            max-width: 200px;
            max-height: 80px;
            object-fit: contain;
            border: 1px solid #dee2e6;
            padding: 5px;
            background: white;
        }
        .favicon-preview {
            width: 32px;
            height: 32px;
            object-fit: contain;
            border: 1px solid #dee2e6;
            padding: 2px;
            background: white;
        }
        .upload-section {
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            background: #f8f9fa;
            margin-bottom: 20px;
        }
        .current-logo {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
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
                
                <?php if (isset($logo_success)): ?>
                    <div class="alert alert-success"><?= $logo_success ?></div>
                <?php endif; ?>
                
                <?php if (isset($logo_error)): ?>
                    <div class="alert alert-danger"><?= $logo_error ?></div>
                <?php endif; ?>
                
                <?php if (isset($favicon_success)): ?>
                    <div class="alert alert-success"><?= $favicon_success ?></div>
                <?php endif; ?>
                
                <?php if (isset($favicon_error)): ?>
                    <div class="alert alert-danger"><?= $favicon_error ?></div>
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

                <!-- Logo & Favicon Settings -->
                <div class="card mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-image"></i> Branding & Appearance</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <!-- Logo Upload -->
                            <div class="col-md-6 mb-4">
                                <h6>Application Logo</h6>
                                <div class="upload-section">
                                    <?php 
                                    $current_logo = $settings_array['app_logo']['setting_value'] ?? '';
                                    $logo_path = !empty($current_logo) && file_exists(__DIR__ . '/../' . $current_logo) ? '../' . $current_logo : '';
                                    ?>
                                    
                                    <?php if ($logo_path): ?>
                                        <div class="current-logo">
                                            <img src="<?= $logo_path ?>" alt="Current Logo" class="logo-preview">
                                            <div>
                                                <strong>Current Logo:</strong><br>
                                                <small><?= basename($current_logo) ?></small>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <p class="text-muted">No logo uploaded. Default will be used.</p>
                                    <?php endif; ?>
                                    
                                    <form method="POST" enctype="multipart/form-data" class="mt-3">
                                        <div class="mb-3">
                                            <label class="form-label">Upload New Logo</label>
                                            <input type="file" class="form-control" name="logo_file" accept=".jpg,.jpeg,.png,.svg,.gif" required>
                                            <small class="text-muted">Allowed: JPG, PNG, SVG, GIF (Max: 2MB)</small>
                                        </div>
                                        <button type="submit" name="upload_logo" class="btn btn-primary">
                                            <i class="fas fa-upload"></i> Upload Logo
                                        </button>
                                    </form>
                                </div>
                            </div>
                            
                            <!-- Favicon Upload -->
                            <div class="col-md-6 mb-4">
                                <h6>Favicon</h6>
                                <div class="upload-section">
                                    <?php 
                                    $current_favicon = $settings_array['app_favicon']['setting_value'] ?? '';
                                    $favicon_path = !empty($current_favicon) && file_exists(__DIR__ . '/../' . $current_favicon) ? '../' . $current_favicon : '';
                                    ?>
                                    
                                    <?php if ($favicon_path): ?>
                                        <div class="current-logo">
                                            <img src="<?= $favicon_path ?>" alt="Current Favicon" class="favicon-preview">
                                            <div>
                                                <strong>Current Favicon:</strong><br>
                                                <small><?= basename($current_favicon) ?></small>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <p class="text-muted">No favicon uploaded. Default will be used.</p>
                                    <?php endif; ?>
                                    
                                    <form method="POST" enctype="multipart/form-data" class="mt-3">
                                        <div class="mb-3">
                                            <label class="form-label">Upload New Favicon</label>
                                            <input type="file" class="form-control" name="favicon_file" accept=".ico,.png,.jpg,.jpeg" required>
                                            <small class="text-muted">Allowed: ICO, PNG, JPG (Max: 512KB)</small>
                                        </div>
                                        <button type="submit" name="upload_favicon" class="btn btn-primary">
                                            <i class="fas fa-upload"></i> Upload Favicon
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Logo Dimensions -->
                        <div class="row mt-4">
                            <div class="col-md-6">
                                <label class="form-label">Logo Width (px)</label>
                                <input type="number" class="form-control" name="settings[logo_width]"
                                    value="<?= htmlspecialchars($settings_array['logo_width']['setting_value'] ?? '150') ?>"
                                    min="50" max="500">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Logo Height (px)</label>
                                <input type="number" class="form-control" name="settings[logo_height]"
                                    value="<?= htmlspecialchars($settings_array['logo_height']['setting_value'] ?? '50') ?>"
                                    min="20" max="200">
                            </div>
                        </div>
                    </div>
                </div>

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

                <!-- General Settings -->
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
                                <p><strong>Logo Directory:</strong> 
                                    <?php 
                                    $logo_dir = __DIR__ . '/../uploads/logo/';
                                    echo is_dir($logo_dir) ? '<span class="text-success">Exists</span>' : '<span class="text-danger">Not found</span>';
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
                                    $uploads_dir = __DIR__ . '/uploads/';
                                    echo is_dir($uploads_dir) ? '<span class="text-success">Exists</span>' : '<span class="text-danger">Not found</span>';
                                    ?>
                                </p>
                                <p><strong>Favicon Directory:</strong> 
                                    <?php 
                                    $favicon_dir = __DIR__ . '/../uploads/favicon/';
                                    echo is_dir($favicon_dir) ? '<span class="text-success">Exists</span>' : '<span class="text-danger">Not found</span>';
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
        
        // Preview uploaded files
        document.querySelector('input[name="logo_file"]')?.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.querySelector('.logo-preview');
                    if (preview) {
                        preview.src = e.target.result;
                    }
                }
                reader.readAsDataURL(file);
            }
        });
        
        document.querySelector('input[name="favicon_file"]')?.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.querySelector('.favicon-preview');
                    if (preview) {
                        preview.src = e.target.result;
                    }
                }
                reader.readAsDataURL(file);
            }
        });
    </script>
</body>
<footer class="bg-dark text-light text-center py-3 mt-5">
    <div class="container">
        <p class="mb-0">Developed by APNLAB. 2025.</p>
    </div>
</footer>
</html>