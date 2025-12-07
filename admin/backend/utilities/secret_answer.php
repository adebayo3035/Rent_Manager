<?php
header('Content-Type: application/json');
include_once "config.php";
include 'auth_utils.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    logActivity("Invalid request method attempted.");
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

if (!isset($_SESSION['unique_id'])) {
    logActivity("Unauthorized access attempt. No session ID.");
    echo json_encode(['success' => false, 'message' => 'User not authenticated.']);
    exit;
}

$unique_id = $_SESSION['unique_id'];
logActivity("User with ID $unique_id initiated update for secret question and answer.");

// Read and sanitize input
$data = json_decode(file_get_contents("php://input"), true);

$email = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL);
$password = trim($data['password'] ?? '');
$question = filter_var($data['secret_question'] ?? '', FILTER_SANITIZE_STRING);
$secret_answer = trim($data['secret_answer'] ?? '');
$confirm_answer = trim($data['confirm_answer'] ?? '');

if (!$email || !$password || !$question || !$secret_answer || !$confirm_answer) {
    logActivity("Missing or invalid input fields from user ID $unique_id.");
    echo json_encode(['success' => false, 'message' => 'All input fields are required and must be valid.']);
    exit;
}

// Fetch user details
$stmt = $conn->prepare("SELECT email, password FROM admin_tbl WHERE unique_id = ?");
$stmt->bind_param("i", $unique_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    logActivity("No user found with ID $unique_id.");
    echo json_encode(['success' => false, 'message' => 'User record not found.']);
    exit;
}

$user = $result->fetch_assoc();
if ($user['email'] !== $email) {
    logActivity("Email mismatch for user ID $unique_id.");
    echo json_encode(['success' => false, 'message' => 'Provided email does not match registered email.']);
    exit;
}

// Validate password using helper function
if (!verifyAndUpgradePassword($conn, $unique_id, $password, $user['password'])) {
    logActivity("Password verification failed for user ID $unique_id.");
    echo json_encode(['success' => false, 'message' => 'Invalid password.']);
    exit;
}

// Validate secret answer using helper function
if ($secret_answer !== $confirm_answer) {
    logActivity("Secret answer mismatch for user ID $unique_id.");
    echo json_encode(['success' => false, 'message' => 'Secret answers do not match.']);
    exit;
}

// Proceed with update
$encrypted_answer = password_hash($secret_answer, PASSWORD_DEFAULT); // Assuming encryption handled here
$update = $conn->prepare("UPDATE admin_tbl SET secret_question = ?, secret_answer = ?, last_updated_by = ? WHERE unique_id = ?");
$update->bind_param("ssii", $question, $encrypted_answer, $unique_id, $unique_id);

if ($update->execute()) {
    logActivity("Secret question and answer updated for user ID $unique_id.");
    echo json_encode(['success' => true, 'message' => 'Secret Question and Answer updated successfully.']);
} else {
    logActivity("Failed to update secret info for user ID $unique_id: " . $conn->error);
    echo json_encode(['success' => false, 'message' => 'Database update failed.']);
}

