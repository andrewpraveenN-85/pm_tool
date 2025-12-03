<?php
include 'config/database.php';
include 'includes/auth.php';
include 'includes/EmailService.php';
include 'includes/activity_logger.php'; // Add ActivityLogger include

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireRole(['manager']);

// Initialize EmailService
$emailService = new EmailService($db);

// Initialize ActivityLogger
$activityLogger = new ActivityLogger($db);

// Get current user ID from session
$current_user_id = $_SESSION['user_id'] ?? null;

/**
 * Get base URL for the application
 */
function getBaseUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
    
    return $protocol . '://' . $host . $scriptPath;
}

/**
 * Send user creation email with access details
 */
function sendUserCreationEmail($name, $email, $password, $role, $status) {
    global $emailService;
    
    if (!$emailService->isConfigured()) {
        error_log("Email service not configured");
        return false;
    }
    
    $subject = "Your Task Manager Account Has Been Created";
    
    $message = "
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #007bff; color: white; padding: 20px; text-align: center; }
            .content { background: #f9f9f9; padding: 20px; border-radius: 5px; }
            .credentials { background: #fff; padding: 15px; border-left: 4px solid #007bff; margin: 15px 0; }
            .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #666; }
            .button { background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Task Manager Access</h1>
            </div>
            <div class='content'>
                <h2>Hello $name,</h2>
                <p>Your account has been successfully created in the Task Management System.</p>
                
                <div class='credentials'>
                    <h3>Your Login Credentials:</h3>
                    <p><strong>Email:</strong> $email</p>
                    <p><strong>Password:</strong> $password</p>
                    <p><strong>Role:</strong> " . ucfirst($role) . "</p>
                    <p><strong>Status:</strong> " . ucfirst($status) . "</p>
                </div>
                
                <p style=\"text-align: center;\">
                    <a href=\"" . getBaseUrl() . "\" class=\"button\">Access Task Manager</a>
                </p>
                
                <p><strong>Important Security Notes:</strong></p>
                <ul>
                    <li>Please change your password after first login for security</li>
                    <li>Keep your login credentials secure and do not share them</li>
                    <li>If you didn't request this account, please contact your manager immediately</li>
                </ul>
                
                <p>You can access the system at: <a href=\"" . getBaseUrl() . "\">" . getBaseUrl() . "</a></p>
            </div>
            <div class='footer'>
                <p>This is an automated message. Please do not reply to this email.</p>
                <p>&copy; " . date('Y') . " Task Manager. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // Plain text version for non-HTML email clients
    $plainMessage = "
    Task Manager Account Created
    
    Hello $name,
    
    Your account has been successfully created in the Task Management System.
    
    YOUR LOGIN CREDENTIALS:
    Email: $email
    Password: $password
    Role: " . ucfirst($role) . "
    Status: " . ucfirst($status) . "
    
    System URL: " . getBaseUrl() . "
    
    IMPORTANT SECURITY NOTES:
    - Please change your password after first login for security
    - Keep your login credentials secure and do not share them
    - If you didn't request this account, please contact your manager immediately
    
    This is an automated message. Please do not reply to this email.
    
    © " . date('Y') . " Task Manager. All rights reserved.
    ";
    
    try {
        return $emailService->sendEmail($email, $subject, $message, true);
    } catch (Exception $e) {
        error_log("Email sending failed: " . $e->getMessage());
        return false;
    }
}

// Handle form submissions
if ($_POST) {
    if (isset($_POST['create_user'])) {
        // Form validation
        $errors = [];
        
        // Required fields
        $required_fields = ['name', 'email', 'password', 'role', 'status'];
        foreach ($required_fields as $field) {
            if (empty(trim($_POST[$field]))) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required.";
            }
        }
        
        // Name validation
        $name = trim($_POST['name']);
        if (!empty($name)) {
            if (strlen($name) < 2 || strlen($name) > 100) {
                $errors[] = "Name must be between 2 and 100 characters.";
            }
            if (!preg_match("/^[a-zA-Z\s\.\-']+$/", $name)) {
                $errors[] = "Name can only contain letters, spaces, dots, hyphens, and apostrophes.";
            }
        }
        
        // Email validation
        $email = trim($_POST['email']);
        if (!empty($email)) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Please enter a valid email address.";
            }
            if (strlen($email) > 255) {
                $errors[] = "Email address is too long.";
            }
            
            // Check if email already exists
            $email_check = $db->prepare("SELECT id FROM users WHERE email = ?");
            $email_check->execute([$email]);
            if ($email_check->rowCount() > 0) {
                $errors[] = "This email address is already registered.";
            }
        }
        
        // Password validation
        $password = $_POST['password'] ?? '';
        if (!empty($password)) {
            if (strlen($password) < 6) {
                $errors[] = "Password must be at least 6 characters long.";
            }
            if (!preg_match('/[A-Z]/', $password)) {
                $errors[] = "Password must contain at least one uppercase letter.";
            }
            if (!preg_match('/[a-z]/', $password)) {
                $errors[] = "Password must contain at least one lowercase letter.";
            }
            if (!preg_match('/[0-9]/', $password)) {
                $errors[] = "Password must contain at least one number.";
            }
            if (!preg_match('/[^A-Za-z0-9]/', $password)) {
                $errors[] = "Password must contain at least one special character.";
            }
        }
        
        // Role validation
        $allowed_roles = ['manager', 'developer', 'qa'];
        $role = $_POST['role'] ?? '';
        if (!in_array($role, $allowed_roles)) {
            $errors[] = "Invalid role selected.";
        }
        
        // Status validation
        $allowed_statuses = ['active', 'inactive'];
        $status = $_POST['status'] ?? '';
        if (!in_array($status, $allowed_statuses)) {
            $errors[] = "Invalid status selected.";
        }
        
        if (empty($errors)) {
            $name = $_POST['name'];
            $email = $_POST['email'];
            $plain_password = $_POST['password']; // Store plain password for email
            $hashed_password = password_hash($plain_password, PASSWORD_DEFAULT);
            $role = $_POST['role'];
            $status = $_POST['status'];
            
            try {
                $db->beginTransaction();
                
                $query = "INSERT INTO users (name, email, password, role, status, created_by) 
                          VALUES (:name, :email, :password, :role, :status, :created_by)";
                
                $stmt = $db->prepare($query);
                $stmt->bindParam(':name', $name);
                $stmt->bindParam(':email', $email);
                $stmt->bindParam(':password', $hashed_password);
                $stmt->bindParam(':role', $role);
                $stmt->bindParam(':status', $status);
                $stmt->bindParam(':created_by', $_SESSION['user_id']);
                
                if ($stmt->execute()) {
                    $user_id = $db->lastInsertId(); // Get the ID of the created user
                    $db->commit();
                    
                    // Log the user creation activity
                    $activityLogger->logActivity(
                        $current_user_id,
                        'create',
                        'user',
                        $user_id,
                        json_encode([
                            'name' => $name,
                            'email' => $email,
                            'role' => $role,
                            'status' => $status,
                            'created_by' => $_SESSION['user_id']
                        ])
                    );
                    
                    // Send email notification with access details
                    $emailSent = sendUserCreationEmail($name, $email, $plain_password, $role, $status);
                    
                    if ($emailSent) {
                        $success = "User created successfully! Access details have been sent to the user's email.";
                    } else {
                        $success = "User created successfully! However, the email notification failed to send. Please notify the user manually with their credentials.";
                    }
                    
                    // Clear form data
                    $_POST = array();
                } else {
                    throw new Exception("Failed to create user!");
                }
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Failed to create user: " . $e->getMessage();
            }
        } else {
            $error = implode("<br>", $errors);
        }
    }
    
    if (isset($_POST['update_user'])) {
        // Form validation for update
        $errors = [];
        
        // Required fields
        $required_fields = ['id', 'name', 'email', 'role', 'status'];
        foreach ($required_fields as $field) {
            if (empty(trim($_POST[$field]))) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required.";
            }
        }
        
        // Name validation
        $name = trim($_POST['name']);
        if (!empty($name)) {
            if (strlen($name) < 2 || strlen($name) > 100) {
                $errors[] = "Name must be between 2 and 100 characters.";
            }
            if (!preg_match("/^[a-zA-Z\s\.\-']+$/", $name)) {
                $errors[] = "Name can only contain letters, spaces, dots, hyphens, and apostrophes.";
            }
        }
        
        // Email validation
        $email = trim($_POST['email']);
        $id = $_POST['id'];
        if (!empty($email)) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Please enter a valid email address.";
            }
            if (strlen($email) > 255) {
                $errors[] = "Email address is too long.";
            }
            
            // Check if email already exists (excluding current user)
            $email_check = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $email_check->execute([$email, $id]);
            if ($email_check->rowCount() > 0) {
                $errors[] = "This email address is already registered to another user.";
            }
        }
        
        // Password validation if provided
        $password = $_POST['password'] ?? '';
        if (!empty($password)) {
            if (strlen($password) < 6) {
                $errors[] = "Password must be at least 6 characters long.";
            }
            if (!preg_match('/[A-Z]/', $password)) {
                $errors[] = "Password must contain at least one uppercase letter.";
            }
            if (!preg_match('/[a-z]/', $password)) {
                $errors[] = "Password must contain at least one lowercase letter.";
            }
            if (!preg_match('/[0-9]/', $password)) {
                $errors[] = "Password must contain at least one number.";
            }
            if (!preg_match('/[^A-Za-z0-9]/', $password)) {
                $errors[] = "Password must contain at least one special character.";
            }
        }
        
        // Role validation
        $allowed_roles = ['manager', 'developer', 'qa'];
        $role = $_POST['role'] ?? '';
        if (!in_array($role, $allowed_roles)) {
            $errors[] = "Invalid role selected.";
        }
        
        // Status validation
        $allowed_statuses = ['active', 'inactive'];
        $status = $_POST['status'] ?? '';
        if (!in_array($status, $allowed_statuses)) {
            $errors[] = "Invalid status selected.";
        }
        
        if (empty($errors)) {
            $id = $_POST['id'];
            $name = $_POST['name'];
            $email = $_POST['email'];
            $role = $_POST['role'];
            $status = $_POST['status'];
            
            // Get old user data for logging
            $old_user_query = $db->prepare("SELECT name, email, role, status FROM users WHERE id = ?");
            $old_user_query->execute([$id]);
            $old_user_data = $old_user_query->fetch(PDO::FETCH_ASSOC);
            
            try {
                $db->beginTransaction();
                
                // Build update query
                $query = "UPDATE users SET name = :name, email = :email, role = :role, status = :status";
                
                // Add password update if provided
                if (!empty($password)) {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $query .= ", password = :password";
                }
                
                $query .= " WHERE id = :id";
                
                $stmt = $db->prepare($query);
                $stmt->bindParam(':id', $id);
                $stmt->bindParam(':name', $name);
                $stmt->bindParam(':email', $email);
                $stmt->bindParam(':role', $role);
                $stmt->bindParam(':status', $status);
                
                if (!empty($password)) {
                    $stmt->bindParam(':password', $hashed_password);
                }
                
                if ($stmt->execute()) {
                    $db->commit();
                    
                    // Log the user update activity
                    $activityLogger->logActivity(
                        $current_user_id,
                        'update',
                        'user',
                        $id,
                        json_encode([
                            'old_data' => $old_user_data,
                            'new_data' => [
                                'name' => $name,
                                'email' => $email,
                                'role' => $role,
                                'status' => $status,
                                'password_changed' => !empty($password)
                            ]
                        ])
                    );
                    
                    $success = "User updated successfully!";
                } else {
                    throw new Exception("Failed to update user!");
                }
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Failed to update user: " . $e->getMessage();
            }
        } else {
            $error = implode("<br>", $errors);
        }
    }
}

// Get all users
$users = $db->query("
    SELECT u.*, creator.name as created_by_name 
    FROM users u 
    LEFT JOIN users creator ON u.created_by = creator.id 
    ORDER BY u.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Get user statistics
$user_stats = $db->query("
    SELECT 
        role,
        COUNT(*) as count,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_count
    FROM users 
    GROUP BY role
")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Task Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.bootstrap5.min.css" rel="stylesheet">
    <style>
        .password-strength {
            height: 5px;
            margin-top: 5px;
            border-radius: 3px;
        }
        .strength-weak { background-color: #dc3545; width: 25%; }
        .strength-fair { background-color: #ffc107; width: 50%; }
        .strength-good { background-color: #28a745; width: 75%; }
        .strength-strong { background-color: #007bff; width: 100%; }
        .password-requirements {
            display: none;
        }
        .form-text ul {
            padding-left: 20px;
            margin-bottom: 0;
        }
        .form-text li {
            font-size: 0.875rem;
        }
        .activity-log {
            max-height: 300px;
            overflow-y: auto;
        }
        .activity-item {
            border-left: 4px solid;
            margin-bottom: 10px;
            padding-left: 10px;
        }
        .activity-create { border-color: #28a745; }
        .activity-update { border-color: #007bff; }
        .activity-delete { border-color: #dc3545; }
        .activity-user { background-color: #f8f9fa; }
        /* DataTables custom styling */
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter,
        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_processing,
        .dataTables_wrapper .dataTables_paginate {
            color: #333;
        }
        .dataTables_wrapper .dataTables_filter input {
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 4px 8px;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            padding: 4px 10px;
            margin: 0 2px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: #007bff;
            color: white !important;
            border-color: #007bff;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>User Management</h2>
                    <div>
                        <?php if (!$emailService->isConfigured()): ?>
                            <span class="badge bg-warning me-2" title="SMTP not configured - email notifications will not work">
                                <i class="fas fa-exclamation-triangle"></i> Email Not Configured
                            </span>
                        <?php endif; ?>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createUserModal">
                            <i class="fas fa-user-plus"></i> Create User
                        </button>
                    </div>
                </div>

                <?php if (isset($success)): ?>
                    <div class="alert alert-success"><?= $success ?></div>
                <?php endif; ?>
                
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?= $error ?></div>
                <?php endif; ?>

                <!-- User Statistics and Activity Log -->
                <div class="row mb-4">
                    <!-- User Statistics -->
                    <div class="col-md-12">
                        <div class="row">
                            <?php foreach ($user_stats as $stat): ?>
                            <div class="col-md-4 mb-3">
                                <div class="card text-center">
                                    <div class="card-body">
                                        <h3 class="text-<?= 
                                            $stat['role'] == 'manager' ? 'primary' : 
                                            ($stat['role'] == 'developer' ? 'success' : 'warning') 
                                        ?>"><?= $stat['count'] ?></h3>
                                        <p class="text-muted mb-1"><?= ucfirst($stat['role']) ?>s</p>
                                        <small class="text-success"><?= $stat['active_count'] ?> active</small>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Users Table -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">All Users</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="usersTable" class="table table-striped table-hover w-100">
                                <thead class="table-dark">
                                    <tr>
                                        <th>User</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>Created By</th>
                                        <th>Created At</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): 
                                        $profilePic = getProfilePicture($user['image'] ?? '', $user['name']);
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <img src="<?= $profilePic ?>" 
                                                     class="rounded-circle me-3" width="40" height="40"
                                                     alt="<?= htmlspecialchars($user['name']) ?>"
                                                     onerror="this.src='<?= getDefaultProfilePicture() ?>'">
                                                <div>
                                                    <strong><?= htmlspecialchars($user['name']) ?></strong>
                                                    <?php if ($user['id'] == $_SESSION['user_id']): ?>
                                                        <span class="badge bg-info">You</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?= htmlspecialchars($user['email']) ?></td>
                                        <td>
                                            <span class="badge bg-<?= 
                                                $user['role'] == 'manager' ? 'primary' : 
                                                ($user['role'] == 'developer' ? 'success' : 'warning') 
                                            ?>">
                                                <?= ucfirst($user['role']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $user['status'] == 'active' ? 'success' : 'secondary' ?>">
                                                <?= ucfirst($user['status']) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($user['created_by_name']) ?></td>
                                        <td data-order="<?= strtotime($user['created_at']) ?>">
                                            <?= date('M j, Y', strtotime($user['created_at'])) ?>
                                        </td>
                                        <td>
                                            <div class="btn-group">
                                                <button class="btn btn-sm btn-outline-primary edit-user" 
                                                        data-user='<?= htmlspecialchars(json_encode($user)) ?>'>
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                <a href="user_stats.php?id=<?= $user['id'] ?>" class="btn btn-sm btn-outline-info">
                                                    <i class="fas fa-chart-bar"></i> Stats
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
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

    <!-- Create User Modal -->
    <div class="modal fade" id="createUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="createUserForm" onsubmit="return validateCreateUserForm()">
                    <div class="modal-body">
                        <?php if (!$emailService->isConfigured()): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i> 
                                SMTP is not configured. Email notifications will not be sent.
                            </div>
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" id="create_name" required
                                   value="<?= isset($_POST['name']) ? htmlspecialchars($_POST['name']) : '' ?>"
                                   minlength="2" maxlength="100">
                            <div class="invalid-feedback">Please enter a valid name (2-100 characters).</div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Email Address <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" name="email" id="create_email" required
                                   value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>"
                                   maxlength="255">
                            <div class="invalid-feedback">Please enter a valid email address.</div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" name="password" id="create_password" required
                                   minlength="6" oninput="checkCreatePasswordStrength()">
                            <div class="password-strength" id="createPasswordStrength"></div>
                            <div class="password-requirements" id="createPasswordRequirements">
                                <small class="text-muted">Password must contain:</small>
                                <ul>
                                    <li id="create-req-length">At least 6 characters</li>
                                    <li id="create-req-uppercase">One uppercase letter</li>
                                    <li id="create-req-lowercase">One lowercase letter</li>
                                    <li id="create-req-number">One number</li>
                                    <li id="create-req-special">One special character</li>
                                </ul>
                            </div>
                            <div class="invalid-feedback" id="createPasswordError"></div>
                            <small class="text-muted">This password will be sent to the user via email.</small>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Role <span class="text-danger">*</span></label>
                                <select class="form-select" name="role" id="create_role" required>
                                    <option value="">Select Role</option>
                                    <option value="manager" <?= (isset($_POST['role']) && $_POST['role'] == 'manager') ? 'selected' : '' ?>>Manager</option>
                                    <option value="developer" <?= (isset($_POST['role']) && $_POST['role'] == 'developer') ? 'selected' : '' ?>>Developer</option>
                                    <option value="qa" <?= (isset($_POST['role']) && $_POST['role'] == 'qa') ? 'selected' : '' ?>>QA</option>
                                </select>
                                <div class="invalid-feedback">Please select a role.</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status <span class="text-danger">*</span></label>
                                <select class="form-select" name="status" id="create_status" required>
                                    <option value="">Select Status</option>
                                    <option value="active" <?= (isset($_POST['status']) && $_POST['status'] == 'active') ? 'selected' : '' ?>>Active</option>
                                    <option value="inactive" <?= (isset($_POST['status']) && $_POST['status'] == 'inactive') ? 'selected' : '' ?>>Inactive</option>
                                </select>
                                <div class="invalid-feedback">Please select a status.</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="create_user" class="btn btn-primary">
                            <i class="fas fa-user-plus"></i> Create User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editUserForm" onsubmit="return validateEditUserForm()">
                    <input type="hidden" name="id" id="edit_user_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" id="edit_user_name" required
                                   minlength="2" maxlength="100">
                            <div class="invalid-feedback">Please enter a valid name (2-100 characters).</div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Email Address <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" name="email" id="edit_user_email" required
                                   maxlength="255">
                            <div class="invalid-feedback">Please enter a valid email address.</div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <input type="password" class="form-control" name="password" id="edit_password"
                                   placeholder="Leave blank to keep current password"
                                   minlength="6" oninput="checkEditPasswordStrength()">
                            <div class="password-strength" id="editPasswordStrength"></div>
                            <div class="password-requirements" id="editPasswordRequirements">
                                <small class="text-muted">Password must contain:</small>
                                <ul>
                                    <li id="edit-req-length">At least 6 characters</li>
                                    <li id="edit-req-uppercase">One uppercase letter</li>
                                    <li id="edit-req-lowercase">One lowercase letter</li>
                                    <li id="edit-req-number">One number</li>
                                    <li id="edit-req-special">One special character</li>
                                </ul>
                            </div>
                            <div class="invalid-feedback" id="editPasswordError"></div>
                            <small class="text-muted">Minimum 6 characters</small>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Role <span class="text-danger">*</span></label>
                                <select class="form-select" name="role" id="edit_user_role" required>
                                    <option value="manager">Manager</option>
                                    <option value="developer">Developer</option>
                                    <option value="qa">QA</option>
                                </select>
                                <div class="invalid-feedback">Please select a role.</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status <span class="text-danger">*</span></label>
                                <select class="form-select" name="status" id="edit_user_status" required>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                                <div class="invalid-feedback">Please select a status.</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="update_user" class="btn btn-primary">Update User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- jQuery (required for DataTables) -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.4.1/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.4.1/js/responsive.bootstrap5.min.js"></script>
    
    <script>
        // Initialize DataTable
        $(document).ready(function() {
            $('#usersTable').DataTable({
                responsive: true,
                pageLength: 10,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                order: [[5, 'desc']], // Sort by Created At descending by default
                language: {
                    search: "Search users:",
                    lengthMenu: "Show _MENU_ users",
                    info: "Showing _START_ to _END_ of _TOTAL_ users",
                    paginate: {
                        first: "First",
                        last: "Last",
                        next: "Next",
                        previous: "Previous"
                    }
                },
                columnDefs: [
                    {
                        targets: [0, 1, 2, 3, 4, 5, 6],
                        orderable: true
                    },
                    {
                        targets: [6], // Actions column
                        orderable: false,
                        searchable: false
                    }
                ]
            });
        });
        
        // Edit user functionality
        document.addEventListener('DOMContentLoaded', function() {
            const editButtons = document.querySelectorAll('.edit-user');
            const editModal = new bootstrap.Modal(document.getElementById('editUserModal'));
            
            editButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const user = JSON.parse(this.dataset.user);
                    
                    document.getElementById('edit_user_id').value = user.id;
                    document.getElementById('edit_user_name').value = user.name;
                    document.getElementById('edit_user_email').value = user.email;
                    document.getElementById('edit_user_role').value = user.role;
                    document.getElementById('edit_user_status').value = user.status;
                    
                    // Clear password fields
                    document.getElementById('edit_password').value = '';
                    document.getElementById('editPasswordStrength').className = 'password-strength';
                    document.getElementById('editPasswordRequirements').style.display = 'none';
                    
                    editModal.show();
                });
            });
            
            // Auto-open modal if there was a form error
            <?php if (isset($error) && isset($_POST['create_user'])): ?>
                var createUserModal = new bootstrap.Modal(document.getElementById('createUserModal'));
                createUserModal.show();
            <?php endif; ?>
        });
        
        // Password strength checker for create form
        function checkCreatePasswordStrength() {
            const password = document.getElementById('create_password').value;
            const strengthBar = document.getElementById('createPasswordStrength');
            const requirements = document.getElementById('createPasswordRequirements');
            
            if (password.length === 0) {
                strengthBar.className = 'password-strength';
                requirements.style.display = 'none';
                return;
            }
            
            requirements.style.display = 'block';
            
            // Check requirements
            const hasLength = password.length >= 6;
            const hasUppercase = /[A-Z]/.test(password);
            const hasLowercase = /[a-z]/.test(password);
            const hasNumber = /[0-9]/.test(password);
            const hasSpecial = /[^A-Za-z0-9]/.test(password);
            
            // Update requirement indicators
            document.getElementById('create-req-length').style.color = hasLength ? 'green' : 'red';
            document.getElementById('create-req-uppercase').style.color = hasUppercase ? 'green' : 'red';
            document.getElementById('create-req-lowercase').style.color = hasLowercase ? 'green' : 'red';
            document.getElementById('create-req-number').style.color = hasNumber ? 'green' : 'red';
            document.getElementById('create-req-special').style.color = hasSpecial ? 'green' : 'red';
            
            // Calculate strength score
            let score = 0;
            if (hasLength) score++;
            if (hasUppercase) score++;
            if (hasLowercase) score++;
            if (hasNumber) score++;
            if (hasSpecial) score++;
            
            // Update strength bar
            let strengthClass = '';
            if (score <= 1) {
                strengthClass = 'strength-weak';
            } else if (score <= 2) {
                strengthClass = 'strength-fair';
            } else if (score <= 3) {
                strengthClass = 'strength-good';
            } else {
                strengthClass = 'strength-strong';
            }
            
            strengthBar.className = 'password-strength ' + strengthClass;
        }
        
        // Password strength checker for edit form
        function checkEditPasswordStrength() {
            const password = document.getElementById('edit_password').value;
            const strengthBar = document.getElementById('editPasswordStrength');
            const requirements = document.getElementById('editPasswordRequirements');
            
            if (password.length === 0) {
                strengthBar.className = 'password-strength';
                requirements.style.display = 'none';
                return;
            }
            
            requirements.style.display = 'block';
            
            // Check requirements
            const hasLength = password.length >= 6;
            const hasUppercase = /[A-Z]/.test(password);
            const hasLowercase = /[a-z]/.test(password);
            const hasNumber = /[0-9]/.test(password);
            const hasSpecial = /[^A-Za-z0-9]/.test(password);
            
            // Update requirement indicators
            document.getElementById('edit-req-length').style.color = hasLength ? 'green' : 'red';
            document.getElementById('edit-req-uppercase').style.color = hasUppercase ? 'green' : 'red';
            document.getElementById('edit-req-lowercase').style.color = hasLowercase ? 'green' : 'red';
            document.getElementById('edit-req-number').style.color = hasNumber ? 'green' : 'red';
            document.getElementById('edit-req-special').style.color = hasSpecial ? 'green' : 'red';
            
            // Calculate strength score
            let score = 0;
            if (hasLength) score++;
            if (hasUppercase) score++;
            if (hasLowercase) score++;
            if (hasNumber) score++;
            if (hasSpecial) score++;
            
            // Update strength bar
            let strengthClass = '';
            if (score <= 1) {
                strengthClass = 'strength-weak';
            } else if (score <= 2) {
                strengthClass = 'strength-fair';
            } else if (score <= 3) {
                strengthClass = 'strength-good';
            } else {
                strengthClass = 'strength-strong';
            }
            
            strengthBar.className = 'password-strength ' + strengthClass;
        }
        
        // Email validation
        function validateEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        }
        
        // Name validation
        function validateName(name) {
            const re = /^[a-zA-Z\s\.\-']{2,100}$/;
            return re.test(name);
        }
        
        // Create user form validation
        function validateCreateUserForm() {
            const form = document.getElementById('createUserForm');
            const name = document.getElementById('create_name').value.trim();
            const email = document.getElementById('create_email').value.trim();
            const password = document.getElementById('create_password').value;
            const role = document.getElementById('create_role').value;
            const status = document.getElementById('create_status').value;
            
            let isValid = true;
            
            // Validate name
            if (!name || !validateName(name)) {
                document.getElementById('create_name').classList.add('is-invalid');
                isValid = false;
            } else {
                document.getElementById('create_name').classList.remove('is-invalid');
            }
            
            // Validate email
            if (!email || !validateEmail(email)) {
                document.getElementById('create_email').classList.add('is-invalid');
                isValid = false;
            } else {
                document.getElementById('create_email').classList.remove('is-invalid');
            }
            
            // Validate password
            const hasUppercase = /[A-Z]/.test(password);
            const hasLowercase = /[a-z]/.test(password);
            const hasNumber = /[0-9]/.test(password);
            const hasSpecial = /[^A-Za-z0-9]/.test(password);
            
            if (!password || password.length < 6 || !hasUppercase || !hasLowercase || !hasNumber || !hasSpecial) {
                document.getElementById('create_password').classList.add('is-invalid');
                document.getElementById('createPasswordError').textContent = 'Password does not meet requirements.';
                isValid = false;
            } else {
                document.getElementById('create_password').classList.remove('is-invalid');
            }
            
            // Validate role
            if (!role) {
                document.getElementById('create_role').classList.add('is-invalid');
                isValid = false;
            } else {
                document.getElementById('create_role').classList.remove('is-invalid');
            }
            
            // Validate status
            if (!status) {
                document.getElementById('create_status').classList.add('is-invalid');
                isValid = false;
            } else {
                document.getElementById('create_status').classList.remove('is-invalid');
            }
            
            return isValid;
        }
        
        // Edit user form validation
        function validateEditUserForm() {
            const form = document.getElementById('editUserForm');
            const name = document.getElementById('edit_user_name').value.trim();
            const email = document.getElementById('edit_user_email').value.trim();
            const password = document.getElementById('edit_password').value;
            const role = document.getElementById('edit_user_role').value;
            const status = document.getElementById('edit_user_status').value;
            
            let isValid = true;
            
            // Validate name
            if (!name || !validateName(name)) {
                document.getElementById('edit_user_name').classList.add('is-invalid');
                isValid = false;
            } else {
                document.getElementById('edit_user_name').classList.remove('is-invalid');
            }
            
            // Validate email
            if (!email || !validateEmail(email)) {
                document.getElementById('edit_user_email').classList.add('is-invalid');
                isValid = false;
            } else {
                document.getElementById('edit_user_email').classList.remove('is-invalid');
            }
            
            // Validate password if provided
            if (password) {
                const hasUppercase = /[A-Z]/.test(password);
                const hasLowercase = /[a-z]/.test(password);
                const hasNumber = /[0-9]/.test(password);
                const hasSpecial = /[^A-Za-z0-9]/.test(password);
                
                if (password.length < 6 || !hasUppercase || !hasLowercase || !hasNumber || !hasSpecial) {
                    document.getElementById('edit_password').classList.add('is-invalid');
                    document.getElementById('editPasswordError').textContent = 'Password does not meet requirements.';
                    isValid = false;
                } else {
                    document.getElementById('edit_password').classList.remove('is-invalid');
                }
            }
            
            // Validate role
            if (!role) {
                document.getElementById('edit_user_role').classList.add('is-invalid');
                isValid = false;
            } else {
                document.getElementById('edit_user_role').classList.remove('is-invalid');
            }
            
            // Validate status
            if (!status) {
                document.getElementById('edit_user_status').classList.add('is-invalid');
                isValid = false;
            } else {
                document.getElementById('edit_user_status').classList.remove('is-invalid');
            }
            
            return isValid;
        }
        
        // Real-time validation for create form
        document.getElementById('create_name').addEventListener('input', function() {
            if (validateName(this.value.trim())) {
                this.classList.remove('is-invalid');
            }
        });
        
        document.getElementById('create_email').addEventListener('input', function() {
            if (validateEmail(this.value.trim())) {
                this.classList.remove('is-invalid');
            }
        });
        
        document.getElementById('create_role').addEventListener('change', function() {
            if (this.value) {
                this.classList.remove('is-invalid');
            }
        });
        
        document.getElementById('create_status').addEventListener('change', function() {
            if (this.value) {
                this.classList.remove('is-invalid');
            }
        });
        
        // Real-time validation for edit form
        document.getElementById('edit_user_name').addEventListener('input', function() {
            if (validateName(this.value.trim())) {
                this.classList.remove('is-invalid');
            }
        });
        
        document.getElementById('edit_user_email').addEventListener('input', function() {
            if (validateEmail(this.value.trim())) {
                this.classList.remove('is-invalid');
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