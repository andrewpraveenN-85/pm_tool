<?php
session_start();

class Auth {
    private $conn;
    private $table_name = "users";

    public function __construct($db) {
        $this->conn = $db;
    }

    public function login($email, $password, $remember_me = false) {
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
                
                // Handle remember me
                if ($remember_me) {
                    $this->setRememberMeToken($user['id']);
                }
                
                return true;
            }
        }
        return false;
    }

    public function loginWithToken($token) {
        // Extract user ID and token
        $parts = explode(':', $token);
        if (count($parts) !== 2) {
            return false;
        }
        
        $user_id = $parts[0];
        $selector = $parts[1];
        
        // Look for the token in database
        $query = "SELECT * FROM remember_me_tokens WHERE selector = :selector AND user_id = :user_id AND expires_at > NOW()";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':selector', $selector);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        
        if ($stmt->rowCount() == 1) {
            $token_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (hash_equals($token_data['validator_hash'], hash('sha256', $parts[1]))) {
                // Token is valid, log the user in
                $query = "SELECT id, email, name, role, status, image FROM " . $this->table_name . " WHERE id = :user_id AND status = 'active'";
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':user_id', $user_id);
                $stmt->execute();
                
                if ($stmt->rowCount() == 1) {
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['user_image'] = $user['image'];
                    
                    // Update token (renew expiration)
                    $this->updateRememberMeToken($selector);
                    
                    return true;
                }
            }
        }
        
        // Token is invalid, clear the cookie
        $this->clearRememberMeCookie();
        return false;
    }

    private function setRememberMeToken($user_id) {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $validator_hash = hash('sha256', $validator);
        $expires = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60)); // 30 days
        
        // Store in database
        $query = "INSERT INTO remember_me_tokens (selector, validator_hash, user_id, expires_at) 
                  VALUES (:selector, :validator_hash, :user_id, :expires_at)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':selector', $selector);
        $stmt->bindParam(':validator_hash', $validator_hash);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':expires_at', $expires);
        $stmt->execute();
        
        // Set cookie
        $token = $user_id . ':' . $selector . $validator;
        setcookie('remember_me', $token, time() + (30 * 24 * 60 * 60), '/', '', false, true);
    }

    private function updateRememberMeToken($selector) {
        $expires = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60)); // 30 days
        
        $query = "UPDATE remember_me_tokens SET expires_at = :expires_at WHERE selector = :selector";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':expires_at', $expires);
        $stmt->bindParam(':selector', $selector);
        $stmt->execute();
    }

    private function clearRememberMeCookie() {
        setcookie('remember_me', '', time() - 3600, '/');
    }

    public function logout() {
        // Clear remember me token if exists
        if (isset($_COOKIE['remember_me'])) {
            $token = $_COOKIE['remember_me'];
            $parts = explode(':', $token);
            if (count($parts) === 2) {
                $selector = substr($parts[1], 0, 24); // Selector is 24 chars (12 bytes in hex)
                
                $query = "DELETE FROM remember_me_tokens WHERE selector = :selector";
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':selector', $selector);
                $stmt->execute();
            }
            $this->clearRememberMeCookie();
        }
        
        session_destroy();
        header("Location: login.php");
        exit;
    }

    public function isLoggedIn() {
        return isset($_SESSION['user_id']);
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