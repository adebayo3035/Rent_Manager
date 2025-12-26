<?php
include_once "config.php";
include 'auth_utils.php';
header('Content-Type: application/json');

// Decode input
$data = json_decode(file_get_contents("php://input"), true);

// Helper function to return and log error
function respondWithError($message, $logMsg = '')
{
    if ($logMsg) logActivity($logMsg);
    echo json_encode(['success' => false, 'message' => $message]);
    exit();
}

// Password strength validation
function validatePassword($password)
{
    return strlen($password) >= 8 &&
        preg_match('/[!@#$%^&*(),.?":{}|<>_]/', $password) &&
        preg_match('/[A-Z]/', $password) &&
        preg_match('/\d/', $password);
}

// Check if user exceeded daily reset attempts
function checkResetAttempts($conn, $email)
{
    $stmt = $conn->prepare("SELECT reset_attempts FROM admin_password_reset_attempts WHERE email = ? AND DATE(last_attempt_date) = CURDATE()");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        if ($row['reset_attempts'] >= 3) {
            logActivity("Reset attempt limit reached for $email");
            return ['success' => false, 'message' => 'You have exceeded the reset attempts for today.'];
        }
    }

    return ['success' => true];
}

// Check if user account is temporarily locked
function checkLockStatus($conn, $unique_id)
{
    $stmt = $conn->prepare("SELECT attempts, locked_until FROM admin_login_attempts WHERE unique_id = ?");
    $stmt->bind_param("i", $unique_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $attempts = $row['attempts'];
        $locked_until = new DateTime($row['locked_until']);
        $now = new DateTime();

        if ($attempts >= 3 && $now < $locked_until) {
            $remaining = $locked_until->diff($now)->format('%i minutes %s seconds');
            logActivity("Account locked for ID $unique_id. Try again in $remaining");
            return ['success' => false, 'message' => "Your account is locked. Try again in $remaining."];
        }
    }

    return ['success' => true];
}

// Update/reset daily reset attempt tracker
function updateResetAttempts($conn, $email)
{
    $stmt = $conn->prepare("SELECT reset_attempts FROM admin_password_reset_attempts WHERE email = ? AND DATE(last_attempt_date) = CURDATE()");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $attempts = $row['reset_attempts'] + 1;
        $stmt = $conn->prepare("UPDATE admin_password_reset_attempts SET reset_attempts = ?, last_attempt_date = NOW() WHERE email = ?");
        $stmt->bind_param("is", $attempts, $email);
    } else {
        $attempts = 1;
        $stmt = $conn->prepare("INSERT INTO admin_password_reset_attempts (email, reset_attempts, last_attempt_date) VALUES (?, ?, NOW())");
        $stmt->bind_param("si", $email, $attempts);
    }

    $stmt->execute();
    $stmt->close();
    logActivity("Reset attempts updated to $attempts for $email");
}

// Perform password update
function updatePassword($conn, $email, $hashedPassword, $unique_id)
{
    $stmt = $conn->prepare("UPDATE admin_tbl SET password = ?, last_updated_by = ? WHERE email = ? AND unique_id = ?");
    $stmt->bind_param("sisi", $hashedPassword, $unique_id, $email, $unique_id);

    if ($stmt->execute()) {
        $stmtReset = $conn->prepare("UPDATE admin_password_reset_attempts SET reset_attempts = 0, last_attempt_date = NOW() WHERE email = ?");
        $stmtReset->bind_param("s", $email);
        $stmtReset->execute();
        $stmtReset->close();

        logActivity("Password reset successful for $email");
        return ['success' => true, 'message' => 'Password reset successfully.'];
    } else {
        logActivity("Database update failed during password reset for $email");
        return ['success' => false, 'message' => 'Failed to update the password.'];
    }
}

// === Begin Process ===

if (!isset($data['email'], $data['password'], $data['confirmPassword'], $data['secret_answer'])) {
    respondWithError('Missing required fields.', 'Password reset failed: Missing input fields');
}

$email = mysqli_real_escape_string($conn, $data['email']);
$password = $data['password'];
$confirmPassword = $data['confirmPassword'];
$secret_answer = $data['secret_answer'];

if (!validatePassword($password)) {
    respondWithError(
        'Password must be at least 8 characters long and include uppercase letters, digits, and special characters.',
        "Weak password attempt for $email"
    );
}

if ($password !== $confirmPassword) {
    respondWithError('Password and Confirm Password mismatch.', "Password mismatch for $email");
}

$resetCheck = checkResetAttempts($conn, $email);
if (!$resetCheck['success']) {
    respondWithError($resetCheck['message']);
}

// Check if user exists
$stmt = $conn->prepare("SELECT unique_id, secret_answer FROM admin_tbl WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 0) {
    respondWithError('Your record cannot be found.', "Email not found: $email");
}

$stmt->bind_result($unique_id, $db_secret_answer);
$stmt->fetch();

// Check if account is locked
$lockCheck = checkLockStatus($conn, $unique_id);
if (!$lockCheck['success']) {
    respondWithError($lockCheck['message']);
}

// Validate secret answer
if (!verifyAndUpgradeSecretAnswer($conn, $unique_id, $secret_answer, $db_secret_answer)) {
    updateResetAttempts($conn, $email);
    respondWithError('Staff validation failed.', "Invalid secret answer for $email");
}

// All checks passed, proceed to reset
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);
$response = updatePassword($conn, $email, $hashedPassword, $unique_id);

echo json_encode($response);
$conn->close();
