<?php
include 'config/database.php';
include 'includes/auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireAuth();

$bug_id = $_GET['id'] ?? 0;

// Get bug details
$query = "
    SELECT b.*, t.name as task_name, t.id as task_id, p.name as project_name,
           u_created.name as created_by_name, u_created.role as created_by_role
    FROM bugs b
    LEFT JOIN tasks t ON b.task_id = t.id
    LEFT JOIN projects p ON t.project_id = p.id
    LEFT JOIN users u_created ON b.created_by = u_created.id
    WHERE b.id = :bug_id
";
$stmt = $db->prepare($query);
$stmt->bindParam(':bug_id', $bug_id);
$stmt->execute();
$bug = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$bug) {
    header("Location: bugs.php");
    exit;
}

// Handle comment submission
if ($_POST && isset($_POST['add_comment'])) {
    $comment = $_POST['comment'];
    
    try {
        $db->beginTransaction();
        
        $query = "INSERT INTO comments (entity_type, entity_id, user_id, comment) 
                  VALUES ('bug', :bug_id, :user_id, :comment)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':bug_id', $bug_id);
        $stmt->bindParam(':user_id', $_SESSION['user_id']);
        $stmt->bindParam(':comment', $comment);
        $stmt->execute();
        
        $comment_id = $db->lastInsertId();
        
        // Handle file uploads for comment
        if (!empty($_FILES['attachments']['name'][0])) {
            $upload_dir = 'uploads/bugs/' . $bug_id . '/comments/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                    $original_name = $_FILES['attachments']['name'][$key];
                    $file_extension = pathinfo($original_name, PATHINFO_EXTENSION);
                    $filename = 'comment_' . $comment_id . '_' . uniqid() . '.' . $file_extension;
                    $target_file = $upload_dir . $filename;
                    
                    if (move_uploaded_file($tmp_name, $target_file)) {
                        $query = "INSERT INTO attachments (entity_type, entity_id, filename, original_name, file_path, file_size, file_type, uploaded_by) 
                                  VALUES ('comment', :entity_id, :filename, :original_name, :file_path, :file_size, :file_type, :uploaded_by)";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':entity_id', $comment_id);
                        $stmt->bindParam(':filename', $filename);
                        $stmt->bindParam(':original_name', $original_name);
                        $stmt->bindParam(':file_path', $target_file);
                        $stmt->bindParam(':file_size', $_FILES['attachments']['size'][$key]);
                        $stmt->bindParam(':file_type', $_FILES['attachments']['type'][$key]);
                        $stmt->bindParam(':uploaded_by', $_SESSION['user_id']);
                        $stmt->execute();
                    }
                }
            }
        }
        
        $db->commit();
        $success = "Comment added successfully!";
    } catch (Exception $e) {
        $db->rollBack();
        $error = "Failed to add comment: " . $e->getMessage();
    }
}

// Handle status update
if ($_POST && isset($_POST['update_status'])) {
    $status = $_POST['status'];
    
    $query = "UPDATE bugs SET status = :status, updated_at = NOW() WHERE id = :bug_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':status', $status);
    $stmt->bindParam(':bug_id', $bug_id);
    
    if ($stmt->execute()) {
        $success = "Bug status updated successfully!";
        // Refresh bug data
        $stmt = $db->prepare("SELECT * FROM bugs WHERE id = :bug_id");
        $stmt->bindParam(':bug_id', $bug_id);
        $stmt->execute();
        $bug = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $error = "Failed to update bug status!";
    }
}

// Get bug comments with attachments
$comments_query = "
    SELECT c.*, u.name as user_name, u.image as user_image 
    FROM comments c 
    LEFT JOIN users u ON c.user_id = u.id 
    WHERE c.entity_type = 'bug' AND c.entity_id = :bug_id 
    ORDER BY c.created_at DESC
";
$comments_stmt = $db->prepare($comments_query);
$comments_stmt->bindParam(':bug_id', $bug_id);
$comments_stmt->execute();
$comments = $comments_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get attachments for comments
$comment_attachments = [];
if (!empty($comments)) {
    $comment_ids = array_column($comments, 'id');
    $placeholders = str_repeat('?,', count($comment_ids) - 1) . '?';
    
    $attachments_query = "
        SELECT a.* 
        FROM attachments a 
        WHERE a.entity_type = 'comment' AND a.entity_id IN ($placeholders)
        ORDER BY a.created_at DESC
    ";
    $attachments_stmt = $db->prepare($attachments_query);
    $attachments_stmt->execute($comment_ids);
    $attachments = $attachments_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group attachments by comment_id
    foreach ($attachments as $attachment) {
        $comment_attachments[$attachment['entity_id']][] = $attachment;
    }
}

// Get bug attachments
$bug_attachments_query = "
    SELECT a.*, u.name as uploaded_by_name 
    FROM attachments a 
    LEFT JOIN users u ON a.uploaded_by = u.id 
    WHERE a.entity_type = 'bug' AND a.entity_id = :bug_id 
    ORDER BY a.created_at DESC
";
$bug_attachments_stmt = $db->prepare($bug_attachments_query);
$bug_attachments_stmt->bindParam(':bug_id', $bug_id);
$bug_attachments_stmt->execute();
$bug_attachments = $bug_attachments_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($bug['name']) ?> - Task Manager</title>
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
    <style>
        .bug-header {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }
        .comment-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
        }
        .attachment-item {
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 8px;
            margin: 2px;
            background: #f8f9fa;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <!-- Bug Header -->
                <div class="bug-header">
                    <div class="row">
                        <div class="col-md-8">
                            <h1><i class="fas fa-bug me-2"></i><?= htmlspecialchars($bug['name']) ?></h1>
                            <div class="lead mb-0"><?= $bug['description'] ?></div>
                        </div>
                        <div class="col-md-4 text-end">
                            <span class="badge bg-light text-dark fs-6">
                                Task: <?= htmlspecialchars($bug['task_name']) ?>
                            </span>
                            <br>
                            <span class="badge bg-light text-dark fs-6 mt-2">
                                Project: <?= htmlspecialchars($bug['project_name']) ?>
                            </span>
                        </div>
                    </div>
                </div>

                <?php if (isset($success)): ?>
                    <div class="alert alert-success"><?= $success ?></div>
                <?php endif; ?>
                
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?= $error ?></div>
                <?php endif; ?>

                <div class="row">
                    <!-- Bug Details -->
                    <div class="col-md-8">
                        <!-- Bug Information -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0">Bug Information</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <p><strong>Status:</strong> 
                                            <span class="badge bg-<?= 
                                                $bug['status'] == 'open' ? 'danger' : 
                                                ($bug['status'] == 'in_progress' ? 'warning' : 
                                                ($bug['status'] == 'resolved' ? 'info' : 'success')) 
                                            ?>">
                                                <?= ucfirst(str_replace('_', ' ', $bug['status'])) ?>
                                            </span>
                                        </p>
                                        <p><strong>Priority:</strong> 
                                            <span class="badge bg-<?= 
                                                $bug['priority'] == 'critical' ? 'danger' : 
                                                ($bug['priority'] == 'high' ? 'warning' : 
                                                ($bug['priority'] == 'medium' ? 'info' : 'success')) 
                                            ?>">
                                                <?= ucfirst($bug['priority']) ?>
                                            </span>
                                        </p>
                                        <p><strong>Start Date:</strong> 
                                            <?= $bug['start_datetime'] ? date('F j, Y H:i', strtotime($bug['start_datetime'])) : 'Not set' ?>
                                        </p>
                                    </div>
                                    <div class="col-md-6">
                                        <p><strong>Reported By:</strong> <?= htmlspecialchars($bug['created_by_name']) ?> (<?= ucfirst($bug['created_by_role']) ?>)</p>
                                        <p><strong>Created:</strong> <?= date('F j, Y H:i', strtotime($bug['created_at'])) ?></p>
                                        <p><strong>End Date:</strong> 
                                            <?php if ($bug['end_datetime']): ?>
                                                <?php 
                                                $end_date = strtotime($bug['end_datetime']);
                                                $now = time();
                                                $diff = $end_date - $now;
                                                $days = floor($diff / (60 * 60 * 24));
                                                
                                                $class = 'text-muted';
                                                if ($days < 0) {
                                                    $class = 'text-danger fw-bold';
                                                } elseif ($days <= 2) {
                                                    $class = 'text-warning fw-bold';
                                                }
                                                ?>
                                                <span class="<?= $class ?>">
                                                    <?= date('F j, Y H:i', $end_date) ?>
                                                    <?php if ($days < 0): ?>
                                                        (Overdue by <?= abs($days) ?> days)
                                                    <?php elseif ($days <= 2): ?>
                                                        (Due in <?= $days ?> days)
                                                    <?php endif; ?>
                                                </span>
                                            <?php else: ?>
                                                Not set
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                                
                                <!-- Status Update Form -->
                                <?php if ($_SESSION['user_role'] == 'manager' || $_SESSION['user_role'] == 'qa'): ?>
                                <div class="mt-4">
                                    <form method="POST" class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Update Status</label>
                                            <select name="status" class="form-select" required>
                                                <option value="open" <?= $bug['status'] == 'open' ? 'selected' : '' ?>>Open</option>
                                                <option value="in_progress" <?= $bug['status'] == 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                                                <option value="resolved" <?= $bug['status'] == 'resolved' ? 'selected' : '' ?>>Resolved</option>
                                                <option value="closed" <?= $bug['status'] == 'closed' ? 'selected' : '' ?>>Closed</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6 d-flex align-items-end">
                                            <button type="submit" name="update_status" class="btn btn-primary">Update Status</button>
                                        </div>
                                    </form>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Bug Attachments -->
                        <?php if (!empty($bug_attachments)): ?>
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0">Bug Attachments</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <?php foreach ($bug_attachments as $attachment): ?>
                                    <div class="col-md-6 mb-3">
                                        <div class="attachment-item">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <h6 class="mb-1">
                                                        <i class="fas fa-paperclip"></i>
                                                        <?= htmlspecialchars($attachment['original_name']) ?>
                                                    </h6>
                                                    <small class="text-muted">
                                                        <?= round($attachment['file_size'] / 1024, 1) ?> KB • 
                                                        <?= $attachment['uploaded_by_name'] ?>
                                                    </small>
                                                </div>
                                                <a href="download.php?id=<?= $attachment['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Comments Section -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0">Comments</h5>
                            </div>
                            <div class="card-body">
                                <!-- Add Comment Form -->
                                <form method="POST" class="mb-4" enctype="multipart/form-data">
                                    <div class="mb-3">
                                        <label class="form-label">Add Comment</label>
                                        <textarea class="form-control wysiwyg" name="comment" required placeholder="Enter your comment..."></textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Attachments</label>
                                        <input type="file" class="form-control" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt,.zip">
                                        <small class="text-muted">You can select multiple files. Maximum 10MB per file.</small>
                                    </div>
                                    <button type="submit" name="add_comment" class="btn btn-primary">Post Comment</button>
                                </form>

                                <!-- Comments List -->
                                <div class="comments-list">
                                    <?php if (empty($comments)): ?>
                                        <p class="text-muted text-center">No comments yet.</p>
                                    <?php else: ?>
                                        <?php foreach ($comments as $comment): ?>
                                        <div class="d-flex mb-3">
                                            <img src="<?= $comment['user_image'] ?: 'https://via.placeholder.com/40' ?>" 
                                                 class="comment-avatar me-3" alt="<?= htmlspecialchars($comment['user_name']) ?>">
                                            <div class="flex-grow-1">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <h6 class="mb-1"><?= htmlspecialchars($comment['user_name']) ?></h6>
                                                    <small class="text-muted"><?= date('M j, Y H:i', strtotime($comment['created_at'])) ?></small>
                                                </div>
                                                <div class="mb-1"><?= $comment['comment'] ?></div>
                                                
                                                <!-- Display attachments for this comment -->
                                                <?php if (isset($comment_attachments[$comment['id']])): ?>
                                                <div class="mt-2">
                                                    <small class="text-muted">Attachments:</small>
                                                    <div class="d-flex flex-wrap gap-2 mt-1">
                                                        <?php foreach ($comment_attachments[$comment['id']] as $attachment): ?>
                                                        <div class="attachment-item">
                                                            <a href="download.php?id=<?= $attachment['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                                                                <i class="fas fa-paperclip"></i> 
                                                                <?= htmlspecialchars($attachment['original_name']) ?>
                                                                <small>(<?= round($attachment['file_size'] / 1024, 1) ?> KB)</small>
                                                            </a>
                                                        </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar -->
                    <div class="col-md-4">
                        <!-- Quick Actions -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0">Quick Actions</h5>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <a href="task_details.php?id=<?= $bug['task_id'] ?>" class="btn btn-outline-primary">
                                        <i class="fas fa-tasks"></i> View Task
                                    </a>
                                    <a href="project_details.php?id=<?= $bug['project_id'] ?>" class="btn btn-outline-info">
                                        <i class="fas fa-project-diagram"></i> View Project
                                    </a>
                                    <a href="bugs.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-arrow-left"></i> Back to Bugs
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Bug Statistics -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Bug Details</h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <small class="text-muted">Bug ID</small>
                                    <p class="mb-0">#<?= $bug['id'] ?></p>
                                </div>
                                <div class="mb-3">
                                    <small class="text-muted">Last Updated</small>
                                    <p class="mb-0"><?= date('F j, Y H:i', strtotime($bug['updated_at'])) ?></p>
                                </div>
                                <div class="mb-3">
                                    <small class="text-muted">Time Open</small>
                                    <p class="mb-0">
                                        <?php
                                        $created = strtotime($bug['created_at']);
                                        $now = time();
                                        $diff = $now - $created;
                                        $days = floor($diff / (60 * 60 * 24));
                                        $hours = floor(($diff % (60 * 60 * 24)) / (60 * 60));
                                        echo $days . " days, " . $hours . " hours";
                                        ?>
                                    </p>
                                </div>
                                <div class="mb-3">
                                    <small class="text-muted">Total Attachments</small>
                                    <p class="mb-0"><?= count($bug_attachments) + array_reduce($comment_attachments, function($carry, $item) { return $carry + count($item); }, 0) ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
<footer class="bg-dark text-light text-center py-3 mt-5">
    <div class="container">
        <p class="mb-0">Developed by APNLAB. 2025.</p>
    </div>
</footer>
</html>