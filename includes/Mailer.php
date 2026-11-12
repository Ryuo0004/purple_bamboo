<?php
// includes/Mailer.php
// Shared PHPMailer wrapper used by password reset, booking confirmation, and other notices.

require_once __DIR__ . '/../config/mail_config.php';

if (class_exists('Mailer')) {
    return;
}

class Mailer {
    /**
     * Send an HTML email.
     *
     * @return bool
     */
    public static function send($toEmail, $toName, $subject, $htmlBody, $plainText = '', $replyToEmail = null, $replyToName = '') {
        return MailConfig::getInstance()->send(
            $toEmail,
            $toName,
            $subject,
            $htmlBody,
            $plainText,
            $replyToEmail,
            $replyToName
        );
    }

    /**
     * Access the underlying PHPMailer instance (attachments, CC, etc.).
     */
    public static function instance() {
        return MailConfig::getInstance()->getMailer();
    }

    public static function lastError() {
        return MailConfig::getInstance()->getLastError();
    }
}
