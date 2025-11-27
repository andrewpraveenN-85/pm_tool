<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

class EmailService {
    private $conn;
    private $settings;
    private $lastError;

    public function __construct($db) {
        $this->conn = $db;
        $this->lastError = '';
        $this->loadSmtpSettings();
    }

    private function loadSmtpSettings() {
        $query = "SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'smtp_%'";
        $stmt = $this->conn->query($query);
        $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->settings = [];
        foreach ($settings as $setting) {
            $this->settings[$setting['setting_key']] = $setting['setting_value'];
        }

        // Decrypt password
        if (!empty($this->settings['smtp_password'])) {
            $this->settings['smtp_password'] = base64_decode($this->settings['smtp_password']);
        }
    }

    public function sendEmail($to, $subject, $message, $isHtml = true) {
        $this->lastError = '';

        // Check if SMTP is configured
        if (empty($this->settings['smtp_host']) || empty($this->settings['smtp_username'])) {
            $this->lastError = "SMTP not configured properly";
            error_log("SMTP not configured: Host or username missing");
            return false;
        }

        // Validate email format
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->lastError = "Invalid recipient email address: " . $to;
            return false;
        }

        // Create PHPMailer instance
        $mail = new PHPMailer(true);

        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host = $this->settings['smtp_host'];
            $mail->SMTPAuth = true;
            $mail->Username = $this->settings['smtp_username'];
            $mail->Password = $this->settings['smtp_password'];
            $mail->SMTPSecure = $this->getEncryptionMethod();
            $mail->Port = $this->settings['smtp_port'] ?: 587;
            
            // Timeout settings
            $mail->Timeout = 30;
            $mail->SMTPKeepAlive = true;

            // Recipients
            $fromEmail = $this->settings['smtp_from_email'] ?: $this->settings['smtp_username'];
            $fromName = $this->settings['smtp_from_name'] ?: 'Task Manager';
            
            if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid from email address: " . $fromEmail);
            }
            
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($to);

            // Content
            $mail->isHTML($isHtml);
            $mail->Subject = $subject;
            $mail->Body = $message;

            if (!$isHtml) {
                $mail->AltBody = strip_tags($message);
            }

            // Add custom headers
            $mail->addCustomHeader('X-Mailer', 'TaskManager PHP');
            
            $result = $mail->send();
            
            if (!$result) {
                $this->lastError = $mail->ErrorInfo;
            }
            
            return $result;
            
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            error_log("Email sending failed: " . $this->lastError);
            return false;
        }
    }

    private function getEncryptionMethod() {
        $encryption = strtolower($this->settings['smtp_encryption'] ?? 'tls');
        
        switch ($encryption) {
            case 'ssl':
                return PHPMailer::ENCRYPTION_SMTPS;
            case 'tls':
                return PHPMailer::ENCRYPTION_STARTTLS;
            case 'none':
                return '';
            default:
                return PHPMailer::ENCRYPTION_STARTTLS;
        }
    }

    public function isConfigured() {
        return !empty($this->settings['smtp_host']) && 
               !empty($this->settings['smtp_username']) && 
               !empty($this->settings['smtp_password']);
    }

    public function getLastError() {
        return $this->lastError;
    }

    public function testConnection() {
        if (!$this->isConfigured()) {
            return false;
        }

        $mail = new PHPMailer(true);
        
        try {
            $mail->isSMTP();
            $mail->Host = $this->settings['smtp_host'];
            $mail->SMTPAuth = true;
            $mail->Username = $this->settings['smtp_username'];
            $mail->Password = $this->settings['smtp_password'];
            $mail->SMTPSecure = $this->getEncryptionMethod();
            $mail->Port = $this->settings['smtp_port'] ?: 587;
            $mail->Timeout = 10;
            
            return $mail->smtpConnect();
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            return false;
        } finally {
            if ($mail) {
                $mail->smtpClose();
            }
        }
    }
}
?>