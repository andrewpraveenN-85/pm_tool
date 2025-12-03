<?php
include 'config/database.php';
include 'includes/auth.php';
include 'includes/activity_logger.php'; // Add ActivityLogger include

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireAuth();

// Initialize ActivityLogger
$activityLogger = new ActivityLogger($db);

// Get current user ID from session
$current_user_id = $_SESSION['user_id'];

// Get current user data
$query = "SELECT * FROM users WHERE id = :user_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $current_user_id);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle profile update
if ($_POST && isset($_POST['update_profile'])) {
    // Form validation
    $errors = [];
    
    // Required fields
    $required_fields = ['name', 'email'];
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
        
        // Check if email already exists (excluding current user)
        $email_check = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $email_check->execute([$email, $_SESSION['user_id']]);
        if ($email_check->rowCount() > 0) {
            $errors[] = "This email address is already registered.";
        }
    }
    
    // Password validation
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (!empty($password)) {
        if (strlen($password) < 6) {
            $errors[] = "Password must be at least 6 characters long.";
        }
        if ($password !== $confirm_password) {
            $errors[] = "Passwords do not match.";
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
    
    // Image validation
    if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Error uploading file. Please try again.";
        } else {
            // Check file size (max 5MB)
            $max_file_size = 5 * 1024 * 1024; // 5MB
            if ($_FILES['image']['size'] > $max_file_size) {
                $errors[] = "Image size must be less than 5MB.";
            }
            
            // Check file type
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $_FILES['image']['tmp_name']);
            finfo_close($finfo);
            
            if (!in_array($mime_type, $allowed_types)) {
                $errors[] = "Only JPG, JPEG, PNG, and GIF images are allowed.";
            }
            
            // Check file extension
            $file_extension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
            if (!in_array($file_extension, $allowed_extensions)) {
                $errors[] = "Invalid file extension. Only JPG, JPEG, PNG, and GIF files are allowed.";
            }
            
            // Check image dimensions
            $image_info = getimagesize($_FILES['image']['tmp_name']);
            if ($image_info) {
                $max_width = 2000;
                $max_height = 2000;
                if ($image_info[0] > $max_width || $image_info[1] > $max_height) {
                    $errors[] = "Image dimensions must be less than 2000x2000 pixels.";
                }
            }
        }
    }
    
    if (empty($errors)) {
        $name = $_POST['name'];
        $email = $_POST['email'];
        
        // Store old user data for logging
        $old_user_data = [
            'name' => $user['name'],
            'email' => $user['email'],
            'image' => $user['image']
        ];
        
        // Prepare changed fields array
        $changed_fields = [];
        if ($old_user_data['name'] !== $name) {
            $changed_fields[] = 'name';
        }
        if ($old_user_data['email'] !== $email) {
            $changed_fields[] = 'email';
        }
        
        // Handle password update if provided
        $password_update = '';
        $password_changed = false;
        if (!empty($_POST['password'])) {
            $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $password_update = ", password = :password";
            $password_changed = true;
            $changed_fields[] = 'password';
        }
        
        // Handle image upload
        $image = $user['image'];
        $image_changed = false;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/profiles/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $filename = 'profile_' . $_SESSION['user_id'] . '_' . time() . '.' . $file_extension;
            $target_file = $upload_dir . $filename;
            
            // Generate unique filename if exists
            $counter = 1;
            while (file_exists($target_file)) {
                $filename = 'profile_' . $_SESSION['user_id'] . '_' . time() . '_' . $counter . '.' . $file_extension;
                $target_file = $upload_dir . $filename;
                $counter++;
            }
            
            if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
                // Delete old image if exists and is not default
                if ($image && $image !== 'https://via.placeholder.com/150' && file_exists($image)) {
                    @unlink($image);
                }
                $image = $target_file;
                $image_changed = true;
                $changed_fields[] = 'profile_image';
                
                // Update session
                $_SESSION['user_image'] = $target_file;
            }
        }
        
        try {
            $db->beginTransaction();
            
            $query = "UPDATE users SET name = :name, email = :email, image = :image $password_update WHERE id = :user_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':image', $image);
            $stmt->bindParam(':user_id', $_SESSION['user_id']);
            
            if (!empty($_POST['password'])) {
                $stmt->bindParam(':password', $hashed_password);
            }
            
            if ($stmt->execute()) {
                $db->commit();
                
                // Update session
                $_SESSION['user_name'] = $name;
                $_SESSION['user_email'] = $email;
                
                // Log the profile update activity
                if (!empty($changed_fields)) {
                    $activityLogger->logActivity(
                        $current_user_id,
                        'update',
                        'profile',
                        $current_user_id,
                        json_encode([
                            'old_data' => [
                                'name' => $old_user_data['name'],
                                'email' => $old_user_data['email'],
                                'image_changed' => $image_changed
                            ],
                            'new_data' => [
                                'name' => $name,
                                'email' => $email,
                                'password_changed' => $password_changed,
                                'image_changed' => $image_changed
                            ],
                            'changed_fields' => $changed_fields,
                            'update_type' => 'self_update'
                        ])
                    );
                }
                
                $success = "Profile updated successfully!";
                
                // Refresh user data
                $stmt = $db->prepare("SELECT * FROM users WHERE id = :user_id");
                $stmt->bindParam(':user_id', $_SESSION['user_id']);
                $stmt->execute();
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Clear password fields
                $_POST['password'] = '';
                $_POST['confirm_password'] = '';
            } else {
                throw new Exception("Failed to update profile!");
            }
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Failed to update profile: " . $e->getMessage();
        }
    } else {
        $error = implode("<br>", $errors);
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
        .form-text ul {
            padding-left: 20px;
            margin-bottom: 0;
        }
        .form-text li {
            font-size: 0.875rem;
        }
        .password-requirements {
            display: none;
        }
        .activity-log {
            max-height: 300px;
            overflow-y: auto;
        }
        .activity-item {
            border-left: 4px solid;
            margin-bottom: 10px;
            padding-left: 10px;
            background-color: #f8f9fa;
        }
        .activity-update { border-color: #007bff; }
        .activity-item small {
            font-size: 0.8rem;
        }
        .changed-fields {
            font-size: 0.85rem;
            color: #666;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container mt-4">
        <div class="row">
            <div class="col-md-12">
                <div class="card mb-4">
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

                        <form method="POST" enctype="multipart/form-data" id="profileForm" onsubmit="return validateProfileForm()">
                            <div class="row">
                                <div class="col-md-4 text-center mb-4">
                                    <div class="mb-3">
                                        <img src="<?= $user['image'] ?: 'https://via.placeholder.com/150' ?>" 
                                             class="rounded-circle" width="150" height="150" id="profileImage">
                                    </div>
                                    <div class="mb-3">
                                        <label for="image" class="form-label">Change Profile Picture</label>
                                        <input type="file" class="form-control" name="image" id="image" accept="image/*">
                                        <small class="text-muted">Max 5MB. JPG, PNG, GIF allowed.</small>
                                        <div class="invalid-feedback" id="imageError"></div>
                                    </div>
                                </div>
                                
                                <div class="col-md-8">
                                    <div class="mb-3">
                                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="name" id="name" 
                                               value="<?= isset($_POST['name']) ? htmlspecialchars($_POST['name']) : htmlspecialchars($user['name']) ?>" 
                                               required minlength="2" maxlength="100">
                                        <div class="invalid-feedback">Please enter a valid name (2-100 characters).</div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Email Address <span class="text-danger">*</span></label>
                                        <input type="email" class="form-control" name="email" id="email" 
                                               value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : htmlspecialchars($user['email']) ?>" 
                                               required maxlength="255">
                                        <div class="invalid-feedback">Please enter a valid email address.</div>
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
                                        <input type="password" class="form-control" name="password" id="password" 
                                               placeholder="Leave blank to keep current password"
                                               minlength="6" oninput="checkPasswordStrength()">
                                        <div class="password-strength" id="passwordStrength"></div>
                                        <div class="password-requirements" id="passwordRequirements">
                                            <small class="text-muted">Password must contain:</small>
                                            <ul>
                                                <li id="req-length">At least 6 characters</li>
                                                <li id="req-uppercase">One uppercase letter</li>
                                                <li id="req-lowercase">One lowercase letter</li>
                                                <li id="req-number">One number</li>
                                                <li id="req-special">One special character</li>
                                            </ul>
                                        </div>
                                        <div class="invalid-feedback" id="passwordError"></div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Confirm New Password</label>
                                        <input type="password" class="form-control" name="confirm_password" id="confirm_password" 
                                               placeholder="Confirm new password">
                                        <div class="invalid-feedback" id="confirmPasswordError"></div>
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
            const imageError = document.getElementById('imageError');
            const imageInput = document.getElementById('image');
            
            if (file) {
                // Check file size (max 5MB)
                const maxSize = 5 * 1024 * 1024;
                if (file.size > maxSize) {
                    imageError.textContent = 'File size must be less than 5MB.';
                    imageInput.classList.add('is-invalid');
                    return;
                }
                
                // Check file type
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                if (!allowedTypes.includes(file.type)) {
                    imageError.textContent = 'Only JPG, PNG, and GIF images are allowed.';
                    imageInput.classList.add('is-invalid');
                    return;
                }
                
                // Check image dimensions
                const img = new Image();
                img.onload = function() {
                    const maxWidth = 2000;
                    const maxHeight = 2000;
                    
                    if (img.width > maxWidth || img.height > maxHeight) {
                        imageError.textContent = 'Image dimensions must be less than 2000x2000 pixels.';
                        imageInput.classList.add('is-invalid');
                    } else {
                        imageInput.classList.remove('is-invalid');
                        imageError.textContent = '';
                        
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            document.getElementById('profileImage').src = e.target.result;
                        }
                        reader.readAsDataURL(file);
                    }
                };
                img.src = URL.createObjectURL(file);
            }
        });
        
        // Password strength checker
        function checkPasswordStrength() {
            const password = document.getElementById('password').value;
            const strengthBar = document.getElementById('passwordStrength');
            const requirements = document.getElementById('passwordRequirements');
            
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
            document.getElementById('req-length').style.color = hasLength ? 'green' : 'red';
            document.getElementById('req-uppercase').style.color = hasUppercase ? 'green' : 'red';
            document.getElementById('req-lowercase').style.color = hasLowercase ? 'green' : 'red';
            document.getElementById('req-number').style.color = hasNumber ? 'green' : 'red';
            document.getElementById('req-special').style.color = hasSpecial ? 'green' : 'red';
            
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
        
        // Form validation
        function validateProfileForm() {
            const form = document.getElementById('profileForm');
            const name = document.getElementById('name').value.trim();
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const imageInput = document.getElementById('image');
            
            let isValid = true;
            
            // Validate name
            if (!name || !validateName(name)) {
                document.getElementById('name').classList.add('is-invalid');
                isValid = false;
            } else {
                document.getElementById('name').classList.remove('is-invalid');
            }
            
            // Validate email
            if (!email || !validateEmail(email)) {
                document.getElementById('email').classList.add('is-invalid');
                isValid = false;
            } else {
                document.getElementById('email').classList.remove('is-invalid');
            }
            
            // Validate password if provided
            if (password) {
                const hasUppercase = /[A-Z]/.test(password);
                const hasLowercase = /[a-z]/.test(password);
                const hasNumber = /[0-9]/.test(password);
                const hasSpecial = /[^A-Za-z0-9]/.test(password);
                
                if (password.length < 6 || !hasUppercase || !hasLowercase || !hasNumber || !hasSpecial) {
                    document.getElementById('password').classList.add('is-invalid');
                    document.getElementById('passwordError').textContent = 'Password does not meet requirements.';
                    isValid = false;
                } else {
                    document.getElementById('password').classList.remove('is-invalid');
                }
                
                // Validate password confirmation
                if (password !== confirmPassword) {
                    document.getElementById('confirm_password').classList.add('is-invalid');
                    document.getElementById('confirmPasswordError').textContent = 'Passwords do not match.';
                    isValid = false;
                } else {
                    document.getElementById('confirm_password').classList.remove('is-invalid');
                }
            }
            
            // Validate image if selected
            if (imageInput.files.length > 0) {
                const file = imageInput.files[0];
                const maxSize = 5 * 1024 * 1024;
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                
                if (file.size > maxSize) {
                    document.getElementById('image').classList.add('is-invalid');
                    document.getElementById('imageError').textContent = 'File size must be less than 5MB.';
                    isValid = false;
                } else if (!allowedTypes.includes(file.type)) {
                    document.getElementById('image').classList.add('is-invalid');
                    document.getElementById('imageError').textContent = 'Only JPG, PNG, and GIF images are allowed.';
                    isValid = false;
                } else {
                    document.getElementById('image').classList.remove('is-invalid');
                }
            }
            
            return isValid;
        }
        
        // Real-time validation
        document.getElementById('name').addEventListener('input', function() {
            if (validateName(this.value.trim())) {
                this.classList.remove('is-invalid');
            }
        });
        
        document.getElementById('email').addEventListener('input', function() {
            if (validateEmail(this.value.trim())) {
                this.classList.remove('is-invalid');
            }
        });
        
        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            if (this.value === password) {
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