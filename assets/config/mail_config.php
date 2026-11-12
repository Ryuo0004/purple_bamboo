<?php
// config/mail_config.php
// Credentials are loaded from config/smtp.php (keep that file out of version control)

$phpmailerDir = __DIR__ . '/../phpmailer';
require_once $phpmailerDir . '/PHPMailer.php';
require_once $phpmailerDir . '/SMTP.php';
require_once $phpmailerDir . '/Exception.php';

if (!defined('SMTP_HOST')) {
    $smtpCandidates = [
        __DIR__ . '/smtp.php',
        dirname(__DIR__, 2) . '/config/smtp.php',
    ];
    foreach ($smtpCandidates as $smtpFile) {
        if (is_file($smtpFile)) {
            require_once $smtpFile;
            break;
        }
    }
}

if (!defined('SMTP_HOST')) {
    throw new RuntimeException('SMTP is not configured. Create config/smtp.php with SMTP_HOST, SMTP_PORT, SMTP_USERNAME, SMTP_PASSWORD, SMTP_FROM_EMAIL, and SMTP_FROM_NAME.');
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

if (class_exists('MailConfig')) {
    return;
}

class MailConfig {
    private static $instance = null;
    private $mailer;
    private $lastError = '';

    private function __construct() {
        $this->mailer = new PHPMailer(true);

        $this->mailer->SMTPDebug  = SMTP::DEBUG_OFF;
        $this->mailer->isSMTP();
        $this->mailer->Host       = SMTP_HOST;
        $this->mailer->SMTPAuth   = true;
        $this->mailer->AuthType   = 'LOGIN';
        $this->mailer->Username   = SMTP_USERNAME;
        $this->mailer->Password   = preg_replace('/\s+/', '', (string) SMTP_PASSWORD);
        $this->mailer->SMTPSecure = defined('SMTP_ENCRYPTION') && SMTP_ENCRYPTION === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $this->mailer->Port       = SMTP_PORT;
        $this->mailer->Timeout    = 30;

        $this->mailer->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ];

        $this->mailer->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $this->mailer->isHTML(true);
        $this->mailer->CharSet = 'UTF-8';
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getMailer() {
        return $this->mailer;
    }

    public function getLastError() {
        return $this->lastError;
    }

    /**
     * Send an HTML email through the shared PHPMailer instance.
     */
    public function send($toEmail, $toName, $subject, $htmlBody, $plainText = '', $replyToEmail = null, $replyToName = '') {
        $this->lastError = '';
        try {
            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            $this->mailer->clearReplyTos();
            $this->mailer->addAddress($toEmail, $toName ?: $toEmail);
            if (!empty($replyToEmail)) {
                $this->mailer->addReplyTo($replyToEmail, $replyToName ?: $replyToEmail);
            }
            $this->mailer->Subject = $subject;
            $this->mailer->Body    = $htmlBody;
            $this->mailer->AltBody = $plainText !== '' ? $plainText : strip_tags($htmlBody);
            $sent = $this->mailer->send();
            if (!$sent) {
                $this->lastError = $this->mailer->ErrorInfo ?: 'PHPMailer failed to send the message.';
            }
            return $sent;
        } catch (\Throwable $e) {
            $this->lastError = $this->mailer->ErrorInfo ?: $e->getMessage();
            error_log('Mailer send error: ' . $this->lastError);
            return false;
        }
    }
}
