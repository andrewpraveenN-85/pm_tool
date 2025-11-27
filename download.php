<?php
include 'config/database.php';
include 'includes/auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireAuth();

$file_id = $_GET['id'] ?? 0;

// Get file info and check permissions
$query = "
    SELECT a.*, 
           CASE 
               WHEN a.entity_type = 'task' THEN t.created_by
               WHEN a.entity_type = 'bug' THEN b.created_by 
               WHEN a.entity_type = 'comment' THEN c.user_id
           END as entity_owner,
           CASE 
               WHEN a.entity_type = 'task' THEN ta.user_id
               ELSE NULL
           END as task_assignee
    FROM attachments a
    LEFT JOIN tasks t ON a.entity_type = 'task' AND a.entity_id = t.id
    LEFT JOIN bugs b ON a.entity_type = 'bug' AND a.entity_id = b.id
    LEFT JOIN comments c ON a.entity_type = 'comment' AND a.entity_id = c.id
    LEFT JOIN task_assignments ta ON t.id = ta.task_id AND ta.user_id = :user_id
    WHERE a.id = :file_id
";

$stmt = $db->prepare($query);
$stmt->bindParam(':file_id', $file_id);
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->execute();
$file = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$file || !file_exists($file['file_path'])) {
    header("HTTP/1.0 404 Not Found");
    exit;
}

// Check permissions
$has_access = false;
if ($_SESSION['user_role'] == 'manager') {
    $has_access = true;
} elseif ($file['uploaded_by'] == $_SESSION['user_id']) {
    $has_access = true;
} elseif ($file['entity_owner'] == $_SESSION['user_id']) {
    $has_access = true;
} elseif ($file['task_assignee'] == $_SESSION['user_id']) {
    $has_access = true;
}

if (!$has_access) {
    header("Location: unauthorized.php");
    exit;
}

// Serve file
header('Content-Type: ' . $file['file_type']);
header('Content-Disposition: inline; filename="' . $file['original_name'] . '"');
header('Content-Length: ' . $file['file_size']);
readfile($file['file_path']);
exit;