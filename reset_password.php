<?php
include 'config/database.php';
include 'includes/activity_logger.php'; // Add ActivityLogger include

$database = new Database();
$db = $database->getConnection();

// Initialize ActivityLogger
$activityLogger = new ActivityLogger($db);

$message = '';
$message_type = '';
$valid_token = false;
$token = $_GET['token'] ?? '';

// Validate token
if ($token) {
    $query = "SELECT * FROM password_resets WHERE token = :token AND expires_at > NOW()";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':token', $token);
    $stmt->execute();
    
    if ($stmt->rowCount() == 1) {
        $reset_request = $stmt->fetch(PDO::FETCH_ASSOC);
        $valid_token = true;
        $email = $reset_request['email'];
        
        // Get user ID for logging
        $user_query = $db->prepare("SELECT id FROM users WHERE email = ?");
        $user_query->execute([$email]);
        $user = $user_query->fetch(PDO::FETCH_ASSOC);
        $user_id = $user['id'] ?? null;
        
        // Log token validation
        $activityLogger->logActivity(
            $user_id,
            'password_reset_token_validated',
            'user',
            $user_id,
            json_encode([
                'email' => $email,
                'ip_address' => $_SERVER['REMOTE_ADDR'],
                'token_valid' => true,
                'token_expires' => $reset_request['expires_at']
            ])
        );
    } else {
        // Log invalid token attempt
        $activityLogger->logActivity(
            null,
            'invalid_password_reset_token',
            'user',
            null,
            json_encode([
                'token' => $token,
                'ip_address' => $_SERVER['REMOTE_ADDR'],
                'user_agent' => $_SERVER['HTTP_USER_AGENT'],
                'reason' => 'invalid_or_expired'
            ])
        );
        
        $message = "Invalid or expired reset token.";
        $message_type = "danger";
    }
}

// Handle password reset
if ($_POST && isset($_POST['password']) && $valid_token) {
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    if ($password !== $confirm_password) {
        $message = "Passwords do not match!";
        $message_type = "danger";
        
        // Log password mismatch
        $activityLogger->logActivity(
            $user_id,
            'password_reset_mismatch',
            'user',
            $user_id,
            json_encode([
                'email' => $email,
                'ip_address' => $_SERVER['REMOTE_ADDR']
            ])
        );
    } else {
        // Update password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        $query = "UPDATE users SET password = :password WHERE email = :email";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':password', $hashed_password);
        $stmt->bindParam(':email', $email);
        
        if ($stmt->execute()) {
            // Delete used token
            $query = "DELETE FROM password_resets WHERE token = :token";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':token', $token);
            $stmt->execute();
            
            // Log successful password reset
            $activityLogger->logActivity(
                $user_id,
                'password_reset_success',
                'user',
                $user_id,
                json_encode([
                    'email' => $email,
                    'ip_address' => $_SERVER['REMOTE_ADDR'],
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'],
                    'reset_completed' => true
                ])
            );
            
            $message = "Password reset successfully! You can now login with your new password.";
            $message_type = "success";
            $valid_token = false; // Token is now used
        } else {
            $message = "Error resetting password. Please try again.";
            $message_type = "danger";
            
            // Log password reset failure
            $activityLogger->logActivity(
                $user_id,
                'password_reset_failed',
                'user',
                $user_id,
                json_encode([
                    'email' => $email,
                    'ip_address' => $_SERVER['REMOTE_ADDR'],
                    'error' => 'database_update_failed'
                ])
            );
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Task Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .reset-container {
            max-width: 400px;
            margin: 100px auto;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .reset-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .reset-header i {
            font-size: 3rem;
            color: #007bff;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="reset-container">
            <div class="reset-header">
                <i class="fas fa-lock"></i>
                <h2>Reset Password</h2>
                <p class="text-muted">Enter your new password</p>
            </div>
            
            <?php if ($message): ?>
                <div class="alert alert-<?= $message_type ?>"><?= $message ?></div>
            <?php endif; ?>
            
            <?php if ($valid_token): ?>
                <form method="POST">
                    <div class="mb-3">
                        <label for="password" class="form-label">New Password</label>
                        <input type="password" class="form-control" id="password" name="password" required minlength="6">
                    </div>
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="6">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Reset Password</button>
                </form>
            <?php elseif (!$message): ?>
                <div class="text-center">
                    <a href="forgot_password.php" class="btn btn-primary">Request New Reset Link</a>
                </div>
            <?php endif; ?>
            
            <div class="text-center mt-3">
                <a href="login.php" class="text-decoration-none">Back to Login</a>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/js/all.min.js"></script>
    <script>
        // Password confirmation validation
        document.querySelector('form')?.addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match!');
            }
        });
    </script>
</body>
</html>