<?php
include 'config/database.php';
include 'includes/auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireRole(['manager']);

// Handle form submissions
if ($_POST) {
    if (isset($_POST['create_project'])) {
        // Form validation
        $errors = [];
        
        // Required field validation
        $required_fields = ['name', 'duration_type', 'status'];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required.";
            }
        }
        
        // Validate project name uniqueness (optional)
        $name = trim($_POST['name']);
        if (!empty($name)) {
            $check_query = "SELECT id FROM projects WHERE name = ?";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->execute([$name]);
            if ($check_stmt->rowCount() > 0) {
                $errors[] = "Project name already exists. Please choose a different name.";
            }
        }
        
        // Validate Git URL format if provided
        if (!empty($_POST['git_url'])) {
            $git_url = filter_var($_POST['git_url'], FILTER_VALIDATE_URL);
            if (!$git_url) {
                $errors[] = "Please enter a valid Git repository URL.";
            }
        }
        
        // Calculate duration in days
        $duration_days = 0;
        if (!empty($_POST['duration_type']) && !empty($_POST['duration_value'])) {
            $duration_type = $_POST['duration_type'];
            $duration_value = intval($_POST['duration_value']);
            
            switch ($duration_type) {
                case 'days':
                    $duration_days = $duration_value;
                    break;
                case 'weeks':
                    $duration_days = $duration_value * 7;
                    break;
                case 'months':
                    $duration_days = $duration_value * 30; // Approximate month as 30 days
                    break;
                default:
                    $errors[] = "Invalid duration type selected.";
            }
            
            if ($duration_value <= 0) {
                $errors[] = "Duration value must be greater than zero.";
            }
        } else {
            $errors[] = "Please specify project duration.";
        }
        
        if (empty($errors)) {
            $name = $_POST['name'];
            $icon = $_POST['icon'] ?? '';
            $description = $_POST['description'] ?? '';
            $git_url = $_POST['git_url'] ?? '';
            $status = $_POST['status'];
            
            try {
                $db->beginTransaction();
                
                $query = "INSERT INTO projects (name, icon, description, git_url, status, duration_days, created_by) 
                          VALUES (:name, :icon, :description, :git_url, :status, :duration_days, :created_by)";
                
                $stmt = $db->prepare($query);
                $stmt->bindParam(':name', $name);
                $stmt->bindParam(':icon', $icon);
                $stmt->bindParam(':description', $description);
                $stmt->bindParam(':git_url', $git_url);
                $stmt->bindParam(':status', $status);
                $stmt->bindParam(':duration_days', $duration_days, PDO::PARAM_INT);
                $stmt->bindParam(':created_by', $_SESSION['user_id']);
                
                if ($stmt->execute()) {
                    $db->commit();
                    $success = "Project created successfully!";
                    // Clear form data
                    $_POST = array();
                } else {
                    throw new Exception("Failed to create project!");
                }
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Failed to create project: " . $e->getMessage();
            }
        } else {
            $error = implode("<br>", $errors);
        }
    }
    
    if (isset($_POST['update_project'])) {
        // Form validation for update
        $errors = [];
        
        // Required field validation
        $required_fields = ['id', 'name', 'duration_type', 'status'];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required.";
            }
        }
        
        // Validate project name uniqueness (excluding current project)
        $name = trim($_POST['name']);
        $id = $_POST['id'];
        if (!empty($name)) {
            $check_query = "SELECT id FROM projects WHERE name = ? AND id != ?";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->execute([$name, $id]);
            if ($check_stmt->rowCount() > 0) {
                $errors[] = "Project name already exists. Please choose a different name.";
            }
        }
        
        // Validate Git URL format if provided
        if (!empty($_POST['git_url'])) {
            $git_url = filter_var($_POST['git_url'], FILTER_VALIDATE_URL);
            if (!$git_url) {
                $errors[] = "Please enter a valid Git repository URL.";
            }
        }
        
        // Calculate duration in days
        $duration_days = 0;
        if (!empty($_POST['duration_type']) && !empty($_POST['duration_value'])) {
            $duration_type = $_POST['duration_type'];
            $duration_value = intval($_POST['duration_value']);
            
            switch ($duration_type) {
                case 'days':
                    $duration_days = $duration_value;
                    break;
                case 'weeks':
                    $duration_days = $duration_value * 7;
                    break;
                case 'months':
                    $duration_days = $duration_value * 30; // Approximate month as 30 days
                    break;
                default:
                    $errors[] = "Invalid duration type selected.";
            }
            
            if ($duration_value <= 0) {
                $errors[] = "Duration value must be greater than zero.";
            }
        } else {
            $errors[] = "Please specify project duration.";
        }
        
        if (empty($errors)) {
            $id = $_POST['id'];
            $name = $_POST['name'];
            $icon = $_POST['icon'] ?? '';
            $description = $_POST['description'] ?? '';
            $git_url = $_POST['git_url'] ?? '';
            $status = $_POST['status'];
            
            try {
                $db->beginTransaction();
                
                $query = "UPDATE projects SET name = :name, icon = :icon, description = :description, 
                          git_url = :git_url, status = :status, duration_days = :duration_days, updated_at = NOW() 
                          WHERE id = :id";
                
                $stmt = $db->prepare($query);
                $stmt->bindParam(':id', $id);
                $stmt->bindParam(':name', $name);
                $stmt->bindParam(':icon', $icon);
                $stmt->bindParam(':description', $description);
                $stmt->bindParam(':git_url', $git_url);
                $stmt->bindParam(':status', $status);
                $stmt->bindParam(':duration_days', $duration_days, PDO::PARAM_INT);
                
                if ($stmt->execute()) {
                    $db->commit();
                    $success = "Project updated successfully!";
                } else {
                    throw new Exception("Failed to update project!");
                }
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Failed to update project: " . $e->getMessage();
            }
        } else {
            $error = implode("<br>", $errors);
        }
    }
}

// Get all projects
$projects = $db->query("
    SELECT p.*, u.name as created_by_name 
    FROM projects p 
    LEFT JOIN users u ON p.created_by = u.id 
    ORDER BY p.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Projects - Task Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- TinyMCE WYSIWYG Editor -->
    <script src="https://cdn.jsdelivr.net/npm/tinymce@6.8.2/tinymce.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        tinymce.init({
            selector: 'textarea.wysiwyg',
            plugins: 'anchor autolink charmap codesample emoticons image link lists media searchreplace table visualblocks wordcount',
            toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | link image media table | align lineheight | numlist bullist indent outdent | emoticons charmap | removeformat',
            menubar: false,
            height: 300,
            promotion: false,
            branding: false
        });
    });
    </script>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>Projects Management</h2>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createProjectModal">
                        <i class="fas fa-plus"></i> Create Project
                    </button>
                </div>

                <?php if (isset($success)): ?>
                    <div class="alert alert-success"><?= $success ?></div>
                <?php endif; ?>
                
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?= $error ?></div>
                <?php endif; ?>

                <div class="row">
                    <?php foreach ($projects as $project): 
                        // Format duration for display
                        $duration_text = '';
                        if ($project['duration_days']) {
                            if ($project['duration_days'] % 30 === 0) {
                                $months = $project['duration_days'] / 30;
                                $duration_text = $months . ($months == 1 ? ' month' : ' months');
                            } elseif ($project['duration_days'] % 7 === 0) {
                                $weeks = $project['duration_days'] / 7;
                                $duration_text = $weeks . ($weeks == 1 ? ' week' : ' weeks');
                            } else {
                                $duration_text = $project['duration_days'] . ($project['duration_days'] == 1 ? ' day' : ' days');
                            }
                        }
                    ?>
                    <div class="col-md-4 mb-4">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <h5 class="card-title">
                                        <?php if ($project['icon']): ?>
                                            <i class="<?= $project['icon'] ?> me-2"></i>
                                        <?php endif; ?>
                                        <?= htmlspecialchars($project['name']) ?>
                                    </h5>
                                    <span class="badge bg-<?= $project['status'] == 'active' ? 'success' : ($project['status'] == 'completed' ? 'info' : 'secondary') ?>">
                                        <?= ucfirst($project['status']) ?>
                                    </span>
                                </div>
                                
                                <div class="card-text"><?= $project['description'] ?></div>
                                
                                <?php if ($project['duration_days']): ?>
                                <p class="card-text mt-2">
                                    <small class="text-muted">
                                        <i class="fas fa-calendar-alt"></i> 
                                        Duration: <?= $duration_text ?>
                                    </small>
                                </p>
                                <?php endif; ?>
                                
                                <?php if ($project['git_url']): ?>
                                <p class="card-text">
                                    <small class="text-muted">
                                        <i class="fab fa-github"></i> 
                                        <a href="<?= $project['git_url'] ?>" target="_blank">Repository</a>
                                    </small>
                                </p>
                                <?php endif; ?>
                                
                                <div class="mt-auto">
                                    <small class="text-muted">
                                        Created by: <?= htmlspecialchars($project['created_by_name']) ?><br>
                                        Created: <?= date('M j, Y', strtotime($project['created_at'])) ?>
                                    </small>
                                </div>
                            </div>
                            <div class="card-footer">
                                <button class="btn btn-sm btn-outline-primary edit-project" 
                                        data-project='<?= htmlspecialchars(json_encode($project)) ?>'>
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <a href="project_details.php?id=<?= $project['id'] ?>" class="btn btn-sm btn-outline-info">
                                    <i class="fas fa-eye"></i> View Details
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Project Modal -->
    <div class="modal fade" id="createProjectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Project</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="createProjectForm" onsubmit="return validateProjectForm()">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Project Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" id="project_name" required 
                                   value="<?= isset($_POST['name']) ? htmlspecialchars($_POST['name']) : '' ?>"
                                   maxlength="255">
                            <div class="invalid-feedback">Please enter a project name.</div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Duration Type <span class="text-danger">*</span></label>
                                <select class="form-select" name="duration_type" id="duration_type" required>
                                    <option value="">Select Type</option>
                                    <option value="days" <?= (isset($_POST['duration_type']) && $_POST['duration_type'] == 'days') ? 'selected' : '' ?>>Days</option>
                                    <option value="weeks" <?= (isset($_POST['duration_type']) && $_POST['duration_type'] == 'weeks') ? 'selected' : '' ?>>Weeks</option>
                                    <option value="months" <?= (isset($_POST['duration_type']) && $_POST['duration_type'] == 'months') ? 'selected' : '' ?>>Months</option>
                                </select>
                                <div class="invalid-feedback">Please select duration type.</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Duration Value <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="duration_value" id="duration_value" required 
                                       value="<?= isset($_POST['duration_value']) ? htmlspecialchars($_POST['duration_value']) : '' ?>"
                                       min="1" max="365" placeholder="e.g., 1, 2, 3...">
                                <div class="invalid-feedback">Please enter a valid duration (1-365).</div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Icon (Font Awesome class)</label>
                            <input type="text" class="form-control" name="icon" 
                                   value="<?= isset($_POST['icon']) ? htmlspecialchars($_POST['icon']) : '' ?>"
                                   placeholder="fas fa-project">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control wysiwyg" name="description" id="project_description"><?= isset($_POST['description']) ? htmlspecialchars($_POST['description']) : '' ?></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Git Repository URL</label>
                            <input type="url" class="form-control" name="git_url" id="git_url"
                                   value="<?= isset($_POST['git_url']) ? htmlspecialchars($_POST['git_url']) : '' ?>"
                                   placeholder="https://github.com/username/repository">
                            <div class="invalid-feedback" id="gitUrlError"></div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Status <span class="text-danger">*</span></label>
                            <select class="form-select" name="status" id="project_status" required>
                                <option value="active" <?= (!isset($_POST['status']) || $_POST['status'] == 'active') ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= (isset($_POST['status']) && $_POST['status'] == 'inactive') ? 'selected' : '' ?>>Inactive</option>
                                <option value="completed" <?= (isset($_POST['status']) && $_POST['status'] == 'completed') ? 'selected' : '' ?>>Completed</option>
                            </select>
                            <div class="invalid-feedback">Please select project status.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="create_project" class="btn btn-primary">Create Project</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Project Modal -->
    <div class="modal fade" id="editProjectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Project</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editProjectForm" onsubmit="return validateEditProjectForm()">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="edit_project_id">
                        
                        <div class="mb-3">
                            <label class="form-label">Project Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" id="edit_project_name" required maxlength="255">
                            <div class="invalid-feedback">Please enter a project name.</div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Duration Type <span class="text-danger">*</span></label>
                                <select class="form-select" name="duration_type" id="edit_duration_type" required>
                                    <option value="">Select Type</option>
                                    <option value="days">Days</option>
                                    <option value="weeks">Weeks</option>
                                    <option value="months">Months</option>
                                </select>
                                <div class="invalid-feedback">Please select duration type.</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Duration Value <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="duration_value" id="edit_duration_value" required 
                                       min="1" max="365">
                                <div class="invalid-feedback">Please enter a valid duration (1-365).</div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Icon (Font Awesome class)</label>
                            <input type="text" class="form-control" name="icon" id="edit_project_icon">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control wysiwyg" name="description" id="edit_project_description"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Git Repository URL</label>
                            <input type="url" class="form-control" name="git_url" id="edit_project_git_url">
                            <div class="invalid-feedback" id="editGitUrlError"></div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Status <span class="text-danger">*</span></label>
                            <select class="form-select" name="status" id="edit_project_status" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="completed">Completed</option>
                            </select>
                            <div class="invalid-feedback">Please select project status.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="update_project" class="btn btn-primary">Update Project</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Edit project functionality
        document.addEventListener('DOMContentLoaded', function() {
            const editButtons = document.querySelectorAll('.edit-project');
            const editModal = new bootstrap.Modal(document.getElementById('editProjectModal'));
            
            editButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const project = JSON.parse(this.dataset.project);
                    
                    document.getElementById('edit_project_id').value = project.id;
                    document.getElementById('edit_project_name').value = project.name;
                    document.getElementById('edit_project_icon').value = project.icon || '';
                    document.getElementById('edit_project_description').value = project.description || '';
                    document.getElementById('edit_project_git_url').value = project.git_url || '';
                    document.getElementById('edit_project_status').value = project.status;
                    
                    // Set duration fields based on stored days
                    const durationDays = project.duration_days || 0;
                    let durationType = 'days';
                    let durationValue = durationDays;
                    
                    if (durationDays % 30 === 0) {
                        durationType = 'months';
                        durationValue = durationDays / 30;
                    } else if (durationDays % 7 === 0) {
                        durationType = 'weeks';
                        durationValue = durationDays / 7;
                    }
                    
                    document.getElementById('edit_duration_type').value = durationType;
                    document.getElementById('edit_duration_value').value = durationValue;
                    
                    editModal.show();
                });
            });
        });
        
        function validateGitUrl(url) {
            if (!url) return true; // Empty is allowed
            const pattern = /^(https?:\/\/)?(www\.)?github\.com\/[a-zA-Z0-9_-]+\/[a-zA-Z0-9_-]+(\/)?$/;
            return pattern.test(url);
        }
        
        function validateProjectForm() {
            const form = document.getElementById('createProjectForm');
            const gitUrl = document.getElementById('git_url').value;
            const gitUrlError = document.getElementById('gitUrlError');
            
            if (gitUrl && !validateGitUrl(gitUrl)) {
                gitUrlError.textContent = "Please enter a valid GitHub repository URL (e.g., https://github.com/username/repository)";
                document.getElementById('git_url').classList.add('is-invalid');
                return false;
            } else {
                document.getElementById('git_url').classList.remove('is-invalid');
                gitUrlError.textContent = '';
            }
            
            return true;
        }
        
        function validateEditProjectForm() {
            const form = document.getElementById('editProjectForm');
            const gitUrl = document.getElementById('edit_project_git_url').value;
            const gitUrlError = document.getElementById('editGitUrlError');
            
            if (gitUrl && !validateGitUrl(gitUrl)) {
                gitUrlError.textContent = "Please enter a valid GitHub repository URL (e.g., https://github.com/username/repository)";
                document.getElementById('edit_project_git_url').classList.add('is-invalid');
                return false;
            } else {
                document.getElementById('edit_project_git_url').classList.remove('is-invalid');
                gitUrlError.textContent = '';
            }
            
            return true;
        }
        
        // Auto-open modal if there was a form error
        <?php if (isset($error) && isset($_POST['create_project'])): ?>
            document.addEventListener('DOMContentLoaded', function() {
                var createProjectModal = new bootstrap.Modal(document.getElementById('createProjectModal'));
                createProjectModal.show();
            });
        <?php endif; ?>
    </script>
</body>
<footer class="bg-dark text-light text-center py-3 mt-5">
    <div class="container">
        <p class="mb-0">Developed by APNLAB. 2025.</p>
    </div>
</footer>
</html>