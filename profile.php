<?php
include 'config/database.php';
include 'includes/auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireAuth();

// Get current user data
$query = "SELECT * FROM users WHERE id = :user_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle profile update
if ($_POST && isset($_POST['update_profile'])) {
    $name = $_POST['name'];
    $email = $_POST['email'];
    
    // Handle password update if provided
    $password_update = '';
    if (!empty($_POST['password'])) {
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $password_update = ", password = :password";
    }
    
    // Handle image upload
    $image = $user['image'];
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/profiles/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $filename = 'profile_' . $_SESSION['user_id'] . '_' . time() . '.' . $file_extension;
        $target_file = $upload_dir . $filename;
        
        if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
            $image = $target_file;
            
            // Update session
            $_SESSION['user_image'] = $target_file;
        }
    }
    
    $query = "UPDATE users SET name = :name, email = :email, image = :image $password_update WHERE id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':name', $name);
    $stmt->bindParam(':email', $email);
    $stmt->bindParam(':image', $image);
    $stmt->bindParam(':user_id', $_SESSION['user_id']);
    
    if (!empty($_POST['password'])) {
        $stmt->bindParam(':password', $password);
    }
    
    if ($stmt->execute()) {
        // Update session
        $_SESSION['user_name'] = $name;
        $_SESSION['user_email'] = $email;
        
        $success = "Profile updated successfully!";
        
        // Refresh user data
        $stmt = $db->prepare("SELECT * FROM users WHERE id = :user_id");
        $stmt->bindParam(':user_id', $_SESSION['user_id']);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $error = "Failed to update profile!";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Task Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0">User Profile</h4>
                    </div>
                    <div class="card-body">
                        <?php if (isset($success)): ?>
                            <div class="alert alert-success"><?= $success ?></div>
                        <?php endif; ?>
                        
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger"><?= $error ?></div>
                        <?php endif; ?>

                        <form method="POST" enctype="multipart/form-data">
                            <div class="row">
                                <div class="col-md-4 text-center mb-4">
                                    <div class="mb-3">
                                        <img src="<?= $user['image'] ?: 'https://via.placeholder.com/150' ?>" 
                                             class="rounded-circle" width="150" height="150" id="profileImage">
                                    </div>
                                    <div class="mb-3">
                                        <label for="image" class="form-label">Change Profile Picture</label>
                                        <input type="file" class="form-control" name="image" id="image" accept="image/*">
                                    </div>
                                </div>
                                
                                <div class="col-md-8">
                                    <div class="mb-3">
                                        <label class="form-label">Full Name</label>
                                        <input type="text" class="form-control" name="name" value="<?= htmlspecialchars($user['name']) ?>" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Email Address</label>
                                        <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Role</label>
                                        <input type="text" class="form-control" value="<?= ucfirst($user['role']) ?>" disabled>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Status</label>
                                        <input type="text" class="form-control" value="<?= ucfirst($user['status']) ?>" disabled>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">New Password</label>
                                        <input type="password" class="form-control" name="password" placeholder="Leave blank to keep current password">
                                        <small class="text-muted">Minimum 6 characters</small>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Confirm New Password</label>
                                        <input type="password" class="form-control" name="confirm_password" placeholder="Confirm new password">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Member Since</label>
                                        <input type="text" class="form-control" value="<?= date('F j, Y', strtotime($user['created_at'])) ?>" disabled>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" name="update_profile" class="btn btn-primary">Update Profile</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Preview image before upload
        document.getElementById('image').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('profileImage').src = e.target.result;
                }
                reader.readAsDataURL(file);
            }
        });

        // Password confirmation validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const password = document.querySelector('input[name="password"]').value;
            const confirmPassword = document.querySelector('input[name="confirm_password"]').value;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match!');
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