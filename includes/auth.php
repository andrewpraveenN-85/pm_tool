<?php
session_start();

class Auth {
    private $conn;
    private $table_name = "users";

    public function __construct($db) {
        $this->conn = $db;
    }

    public function login($email, $password) {
        $query = "SELECT id, email, name, password, role, status, image FROM " . $this->table_name . " WHERE email = :email AND status = 'active'";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();

        if ($stmt->rowCount() == 1) {
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['user_image'] = $user['image'];
                return true;
            }
        }
        return false;
    }

    public function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }

    public function logout() {
        session_destroy();
        header("Location: login.php");
        exit;
    }

    public function requireAuth() {
        if (!$this->isLoggedIn()) {
            header("Location: login.php");
            exit;
        }
    }

    public function requireRole($allowed_roles) {
        $this->requireAuth();
        if (!in_array($_SESSION['user_role'], $allowed_roles)) {
            header("Location: unauthorized.php");
            exit;
        }
    }
}
?>