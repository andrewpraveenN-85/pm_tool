<?php
include 'config/database.php';
include 'includes/auth.php';
include 'EmailService.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireRole(['manager']);

// Initialize EmailService
$emailService = new EmailService($db);

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
        $name = $_POST['name'];
        $email = $_POST['email'];
        $plain_password = $_POST['password']; // Store plain password for email
        $hashed_password = password_hash($plain_password, PASSWORD_DEFAULT);
        $role = $_POST['role'];
        $status = $_POST['status'];
        
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
            // Send email notification with access details
            $emailSent = sendUserCreationEmail($name, $email, $plain_password, $role, $status);
            
            if ($emailSent) {
                $success = "User created successfully! Access details have been sent to the user's email.";
            } else {
                $success = "User created successfully! However, the email notification failed to send. Please notify the user manually with their credentials.";
            }
        } else {
            $error = "Failed to create user!";
        }
    }
    
    if (isset($_POST['update_user'])) {
        $id = $_POST['id'];
        $name = $_POST['name'];
        $email = $_POST['email'];
        $role = $_POST['role'];
        $status = $_POST['status'];
        
        // Build update query
        $query = "UPDATE users SET name = :name, email = :email, role = :role, status = :status";
        
        // Add password update if provided
        if (!empty($_POST['password'])) {
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $query .= ", password = :password";
        }
        
        $query .= " WHERE id = :id";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':role', $role);
        $stmt->bindParam(':status', $status);
        
        if (!empty($_POST['password'])) {
            $stmt->bindParam(':password', $password);
        }
        
        if ($stmt->execute()) {
            $success = "User updated successfully!";
        } else {
            $error = "Failed to update user!";
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

                <!-- User Statistics -->
                <div class="row mb-4">
                    <?php foreach ($user_stats as $stat): ?>
                    <div class="col-md-4">
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

                <!-- Users Table -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">All Users</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
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
                                    <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <img src="<?= $user['image'] ?: 'https://via.placeholder.com/40' ?>" 
                                                     class="rounded-circle me-3" width="40" height="40">
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
                                        <td><?= date('M j, Y', strtotime($user['created_at'])) ?></td>
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
                <form method="POST">
                    <div class="modal-body">
                        <?php if (!$emailService->isConfigured()): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i> 
                                SMTP is not configured. Email notifications will not be sent.
                            </div>
                        <?php endif; ?>
                        <div class="mb-3">
                            <label class="form-label">Full Name</label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email Address</label>
                            <input type="email" class="form-control" name="email" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" class="form-control" name="password" required minlength="6">
                            <small class="text-muted">This password will be sent to the user via email.</small>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Role</label>
                                <select class="form-select" name="role" required>
                                    <option value="manager">Manager</option>
                                    <option value="developer">Developer</option>
                                    <option value="qa">QA</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status" required>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
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
                <form method="POST">
                    <input type="hidden" name="id" id="edit_user_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Full Name</label>
                            <input type="text" class="form-control" name="name" id="edit_user_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email Address</label>
                            <input type="email" class="form-control" name="email" id="edit_user_email" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <input type="password" class="form-control" name="password" placeholder="Leave blank to keep current password">
                            <small class="text-muted">Minimum 6 characters</small>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Role</label>
                                <select class="form-select" name="role" id="edit_user_role" required>
                                    <option value="manager">Manager</option>
                                    <option value="developer">Developer</option>
                                    <option value="qa">QA</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status" id="edit_user_status" required>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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
                    
                    editModal.show();
                });
            });
        });
    </script>
</body>
<footer class="bg-dark text-light text-center py-3 mt-5">
    <div class="container">
        <p class="mb-0">Developed by APNLAB. 2025.</p>
    </div>
</footer>
</html>