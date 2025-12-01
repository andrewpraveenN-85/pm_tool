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
        $name = $_POST['name'];
        $icon = $_POST['icon'];
        $description = $_POST['description'];
        $git_url = $_POST['git_url'];
        $status = $_POST['status'];
        
        $query = "INSERT INTO projects (name, icon, description, git_url, status, created_by) 
                  VALUES (:name, :icon, :description, :git_url, :status, :created_by)";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':icon', $icon);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':git_url', $git_url);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':created_by', $_SESSION['user_id']);
        
        if ($stmt->execute()) {
            $success = "Project created successfully!";
        } else {
            $error = "Failed to create project!";
        }
    }
    
    if (isset($_POST['update_project'])) {
        $id = $_POST['id'];
        $name = $_POST['name'];
        $icon = $_POST['icon'];
        $description = $_POST['description'];
        $git_url = $_POST['git_url'];
        $status = $_POST['status'];
        
        $query = "UPDATE projects SET name = :name, icon = :icon, description = :description, 
                  git_url = :git_url, status = :status, updated_at = NOW() 
                  WHERE id = :id";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':icon', $icon);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':git_url', $git_url);
        $stmt->bindParam(':status', $status);
        
        if ($stmt->execute()) {
            $success = "Project updated successfully!";
        } else {
            $error = "Failed to update project!";
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
                    <?php foreach ($projects as $project): ?>
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
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Project Name</label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Icon (Font Awesome class)</label>
                            <input type="text" class="form-control" name="icon" placeholder="fas fa-project">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control wysiwyg" name="description"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Git Repository URL</label>
                            <input type="url" class="form-control" name="git_url">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="completed">Completed</option>
                            </select>
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
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="edit_project_id">
                        <div class="mb-3">
                            <label class="form-label">Project Name</label>
                            <input type="text" class="form-control" name="name" id="edit_project_name" required>
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
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="edit_project_status" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="completed">Completed</option>
                            </select>
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