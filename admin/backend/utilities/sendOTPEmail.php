<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../vendor/autoload.php';

function sendEmailWithMailtrap($to, $otp) {
    $mail = new PHPMailer(true);
    
    try {
        //Server settings
        $mail->isSMTP();
        $mail->Host       = 'sandbox.smtp.mailtrap.io';  // Mailtrap SMTP server
        $mail->SMTPAuth   = true;
        $mail->Username   = 'f206340a3672ab';  // Your Mailtrap username
        $mail->Password   = '47b2b079cc0861';  // Your Mailtrap password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;  // Enable TLS encryption
        $mail->Port       = 2525;  // Mailtrap port

        //Recipients
        $mail->setFrom('noreply@example.com', 'Transaction_Manager');  // Your from email
        $mail->addAddress($to);  // User's email address

        // Content
        $mail->isHTML(true);  
        $mail->Subject = 'Your OTP Code';
        $mail->Body    = "Your OTP is: $otp. It expires in 5 minutes.";  // OTP message

        // Send email
        $mail->send();
        return true;  // If email is sent successfully
    } catch (Exception $e) {
        // If an error occurs
        return false;
    }
}

// Example usage of the function
$email = "rahmondebayo@gmail.com";  // Replace with the user's email
$otp = rand(100000, 999999);  // Generate a random 6-digit OTP
$status = sendEmailWithMailtrap($email, $otp);

if ($status) {
    echo "OTP sent successfully!";
} else {
    echo "Failed to send OTP.";
}

