<?php
// Include PHPMailer
require_once __DIR__ . '/../vendor/autoload.php'; // If using Composer
// Or if not using Composer, manually include PHPMailer files

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailService {
    private $conn;
    private $settings;

    public function __construct($db) {
        $this->conn = $db;
        $this->loadSmtpSettings();
    }

    private function loadSmtpSettings() {
        $query = "SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'smtp_%'";
        $stmt = $this->conn->query($query);
        $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($settings as $setting) {
            $this->settings[$setting['setting_key']] = $setting['setting_value'];
        }

        // Decrypt password
        if (!empty($this->settings['smtp_password'])) {
            $this->settings['smtp_password'] = base64_decode($this->settings['smtp_password']);
        }
    }

    public function sendEmail($to, $subject, $message, $isHtml = true) {
        // Check if SMTP is configured
        if (empty($this->settings['smtp_host']) || empty($this->settings['smtp_username'])) {
            error_log("SMTP not configured properly");
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
            $mail->SMTPSecure = $this->settings['smtp_encryption'];
            $mail->Port = $this->settings['smtp_port'];

            // Recipients
            $mail->setFrom(
                $this->settings['smtp_from_email'] ?: $this->settings['smtp_username'],
                $this->settings['smtp_from_name'] ?: 'Task Manager'
            );
            $mail->addAddress($to);

            // Content
            $mail->isHTML($isHtml);
            $mail->Subject = $subject;
            $mail->Body = $message;

            if (!$isHtml) {
                $mail->AltBody = strip_tags($message);
            }

            return $mail->send();
        } catch (Exception $e) {
            error_log("Email sending failed: " . $e->getMessage());
            return false;
        }
    }

    public function isConfigured() {
        return !empty($this->settings['smtp_host']) && 
               !empty($this->settings['smtp_username']) && 
               !empty($this->settings['smtp_password']);
    }
}
?>