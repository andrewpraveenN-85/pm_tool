<?php
include 'config/database.php';
include 'includes/auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireRole(['manager', 'qa']);

header('Content-Type: application/json');

$project_id = $_GET['project_id'] ?? '';

if ($project_id) {
    $query = "SELECT id, name FROM tasks WHERE project_id = ? AND status != 'closed' AND status != 'completed' ORDER BY name";
    $stmt = $db->prepare($query);
    $stmt->execute([$project_id]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($tasks);
} else {
    $query = "SELECT t.id, t.name FROM tasks t LEFT JOIN projects p ON t.project_id = p.id WHERE t.status != 'closed' AND t.status != 'completed' AND p.status = 'active' ORDER BY t.name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($tasks);
}