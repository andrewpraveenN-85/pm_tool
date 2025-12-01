<?php
include 'config/database.php';
include 'includes/EmailService.php';

$database = new Database();
$db = $database->getConnection();
$emailService = new EmailService($db);

$message = '';
$message_type = '';

if ($_POST && isset($_POST['email'])) {
    $email = $_POST['email'];
    
    // Check if user exists
    $query = "SELECT id, name, email FROM users WHERE email = :email AND status = 'active'";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':email', $email);
    $stmt->execute();
    
    if ($stmt->rowCount() == 1) {
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Generate reset token
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + (60 * 60)); // 1 hour
        
        // Store token in database
        $query = "INSERT INTO password_resets (email, token, expires_at) VALUES (:email, :token, :expires_at)
                  ON DUPLICATE KEY UPDATE token = :token, expires_at = :expires_at, created_at = NOW()";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':token', $token);
        $stmt->bindParam(':expires_at', $expires);
        
        if ($stmt->execute()) {
            // Send email
            $reset_link = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/reset_password.php?token=" . $token;
            
            $subject = "Password Reset Request - Task Manager";
            $message = "
                <html>
                <head>
                    <title>Password Reset Request</title>
                </head>
                <body>
                    <h2>Password Reset Request</h2>
                    <p>Hello " . htmlspecialchars($user['name']) . ",</p>
                    <p>You have requested to reset your password for the Task Manager account.</p>
                    <p>Please click the link below to reset your password:</p>
                    <p><a href='" . $reset_link . "' style='background-color: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Reset Password</a></p>
                    <p>This link will expire in 1 hour.</p>
                    <p>If you did not request this reset, please ignore this email.</p>
                    <br>
                    <p>Best regards,<br>Task Manager Team</p>
                </body>
                </html>
            ";
            
            if ($emailService->sendEmail($email, $subject, $message)) {
                $message = "Password reset link has been sent to your email!";
                $message_type = "success";
            } else {
                $message = "Failed to send email. Please try again later.";
                $message_type = "danger";
            }
        } else {
            $message = "Error generating reset token. Please try again.";
            $message_type = "danger";
        }
    } else {
        $message = "No account found with that email address.";
        $message_type = "danger";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Task Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .forgot-container {
            max-width: 400px;
            margin: 100px auto;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .forgot-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .forgot-header i {
            font-size: 3rem;
            color: #007bff;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="forgot-container">
            <div class="forgot-header">
                <i class="fas fa-key"></i>
                <h2>Forgot Password</h2>
                <p class="text-muted">Enter your email to reset your password</p>
            </div>
            
            <?php if ($message): ?>
                <div class="alert alert-<?= $message_type ?>"><?= $message ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="mb-3">
                    <label for="email" class="form-label">Email Address</label>
                    <input type="email" class="form-control" id="email" name="email" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Send Reset Link</button>
            </form>
            
            <div class="text-center mt-3">
                <a href="login.php" class="text-decoration-none">Back to Login</a>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/js/all.min.js"></script>
</body>
</html>