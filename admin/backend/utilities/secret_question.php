<?php
include 'config.php';
include 'auth_utils.php';
header('Content-Type: application/json');

function logAndRespond($message, $response, $exit = true) {
    logActivity($message);
    echo json_encode($response);
    if ($exit) exit();
}

function getPostInput($key) {
    $input = json_decode(file_get_contents('php://input'), true);
    return $input[$key] ?? null;
}

function getAdminByEmail($conn, $email) {
    $stmt = $conn->prepare("SELECT unique_id, secret_question, password FROM admin_tbl WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function getLoginAttempts($conn, $unique_id) {
    $stmt = $conn->prepare("SELECT attempts, locked_until FROM admin_login_attempts WHERE unique_id = ? LIMIT 1");
    $stmt->bind_param("s", $unique_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function updateLoginAttempts($conn, $unique_id, $attempts, $locked_until = null) {
    $stmt = $conn->prepare("UPDATE admin_login_attempts SET attempts = ?, locked_until = ? WHERE unique_id = ?");
    $stmt->bind_param("iss", $attempts, $locked_until, $unique_id);
    $stmt->execute();
}

function insertLoginAttempt($conn, $unique_id) {
    $attempts = 1;
    $stmt = $conn->prepare("INSERT INTO admin_login_attempts (unique_id, attempts, locked_until) VALUES (?, ?, NULL)");
    $stmt->bind_param("si", $unique_id, $attempts);
    $stmt->execute();
}

function resetLoginAttempts($conn, $unique_id) {
    $stmt = $conn->prepare("DELETE FROM admin_login_attempts WHERE unique_id = ?");
    $stmt->bind_param("s", $unique_id);
    $stmt->execute();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    logAndRespond("Invalid request method used", ['success' => false, 'message' => 'Invalid request method']);
}

$email = getPostInput('email');
$password = getPostInput('password');

if (!$email || !$password) {
    logAndRespond("Missing Staff Credentials: email or password not provided", ['success' => false, 'message' => 'Email and password are required']);
}

$admin = getAdminByEmail($conn, $email);

if (!$admin) {
    logAndRespond("Action failed: No admin found with email $email", ['success' => false, 'message' => 'User not found']);
}

$unique_id = $admin['unique_id'];
$hashedPassword = $admin['password'];
$secret_question = $admin['secret_question'];

$attemptData = getLoginAttempts($conn, $unique_id);
$current_time = new DateTime();
$max_attempts = 3;
$lockout_duration = 15; // minutes

if ($attemptData) {
    $attempts = $attemptData['attempts'];
    $locked_until = new DateTime($attemptData['locked_until']);

    if ($attempts >= $max_attempts && $current_time < $locked_until) {
        $remaining = $current_time->diff($locked_until)->format('%i minutes %s seconds');
        logAndRespond("Secret Question Retrieval blocked: $email is currently locked out until {$locked_until->format('Y-m-d H:i:s')}", [
            'success' => false,
            'message' => "Your account is locked. Try again in $remaining."
        ]);
    }
}

// if (md5($password) === $hashedPassword) {
//     resetLoginAttempts($conn, $unique_id);
//     logAndRespond("Staff Credential Validation was successful for User: $email", [
//         'success' => true,
//         'secret_question' => $secret_question
//     ], false);
// } else {
//     if ($attemptData) {
//         $newAttempts = $attempts + 1;
//         $lockedUntilTime = null;
//         if ($newAttempts >= $max_attempts) {
//             $lockedUntilTime = $current_time->modify("+$lockout_duration minutes")->format('Y-m-d H:i:s');
//             logActivity("Account locked: $email exceeded max login Credentials Validation attempts");
//         }
//         updateLoginAttempts($conn, $unique_id, $newAttempts, $lockedUntilTime);
//     } else {
//         insertLoginAttempt($conn, $unique_id);
//     }

//     logAndRespond("Invalid password entered for user: $email", [
//         'success' => false,
//         'message' => 'Invalid password. Please try again.'
//     ]);
// }

if (!verifyAndUpgradePassword($conn, $unique_id, $password, $hashedPassword)) {
    if ($attemptData) {
        $newAttempts = $attempts + 1;
        $lockedUntilTime = null;
        if ($newAttempts >= $max_attempts) {
            $lockedUntilTime = $current_time->modify("+$lockout_duration minutes")->format('Y-m-d H:i:s');
            logActivity("Account locked: $email exceeded max login Credentials Validation attempts");
        }
        updateLoginAttempts($conn, $unique_id, $newAttempts, $lockedUntilTime);
    } else {
        insertLoginAttempt($conn, $unique_id);
    }

    logAndRespond("Invalid password entered for user: $email", [
        'success' => false,
        'message' => 'Invalid password. Please try again.'
    ]);
}
else{
    resetLoginAttempts($conn, $unique_id);
    logAndRespond("Staff Credential Validation was successful for User: $email", [
        'success' => true,
        'secret_question' => $secret_question
    ], false);
}

$conn->close();
