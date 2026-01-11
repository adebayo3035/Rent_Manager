<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// Correct path (vendor is in the same directory level)
require_once __DIR__ . '/vendor/autoload.php';

function sendEmailWithGmailSMTP($to, $body, $subject, $attachments = [])
{
    $mail = new PHPMailer(true);
    $config = include __DIR__ . '/secrets.php';

    try {
        // Enable debug but capture output
        $mail->SMTPDebug = SMTP::DEBUG_SERVER;
        
        // Create a custom debug output handler
        $debugOutput = '';
        $mail->Debugoutput = function($str, $level) use (&$debugOutput) {
            $debugOutput .= $str . "\n";
            // Also log it
            logActivity("SMTP Debug: " . trim($str));
        };
        
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['gmail_username'];
        $mail->Password   = $config['gmail_password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;
        $mail->Timeout    = 30;

        $mail->setFrom('no-reply@rentmanager.com', 'Rent Manager');
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        foreach ($attachments as $attachment) {
            if (file_exists($attachment)) {
                $mail->addAttachment($attachment);
            }
        }

        $result = $mail->send();
        
        // Log the full debug output
        logActivity("Email sending " . ($result ? "succeeded" : "failed") . " for: $to");
        logActivity("SMTP Debug Output:\n" . $debugOutput);
        
        return $result;

    } catch (Exception $e) {
        error_log("Mailer Error: " . $mail->ErrorInfo);
        logActivity("Mailer Error: " . $mail->ErrorInfo);
        return false;
    }
}