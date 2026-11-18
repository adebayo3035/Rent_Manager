<?php
//mailer.php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// Correct path (vendor is in the same directory level)
require_once __DIR__ . '/vendor/autoload.php';

function sendEmailWithGmailSMTP(
    string $to,
    string $body,
    string $subject,
    array $attachments = [],
    string $toName = '',
    string $fromName = 'RentFlow Pro'
): bool {
    $mail = new PHPMailer(true);

    try {
        $config = include __DIR__ . '/secrets.php';

        // ---- SMTP ----
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['gmail_username'];
        $mail->Password   = $config['gmail_password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 20;

        // ---- Sender ----
        $mail->setFrom(
            $config['gmail_username'],
            $config['from_name'] ?? $fromName
        );
        $mail->addReplyTo($config['gmail_username'], $config['from_name'] ?? $fromName);

        // ---- Recipient ----
        if ($toName !== '') {
            $mail->addAddress($to, $toName);
        } else {
            $mail->addAddress($to);
        }

        // ---- Body ----
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        // Plain-text fallback (strip HTML, decode entities, normalize newlines)
        $plainText = html_entity_decode(strip_tags(
            preg_replace('/<br\s*\/?>/i', "\n", $body)
        ), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $mail->AltBody = trim(preg_replace("/\n{3,}/", "\n\n", $plainText));

        // ---- Attachments ----
        foreach ($attachments as $path) {
            if (is_string($path) && $path !== '' && file_exists($path)) {
                $mail->addAttachment($path);
            }
        }

        $mail->send();
        return true;

    } catch (Exception $e) {
        // Log but do NOT echo — this must never corrupt a JSON response
        $errInfo = $mail->ErrorInfo ?: $e->getMessage();
        error_log("[EMAIL] PHPMailer error to {$to}: {$errInfo}");
        logActivity("[EMAIL] Failed to send to {$to}: {$errInfo}", 'ERROR');
        return false;
    }
}