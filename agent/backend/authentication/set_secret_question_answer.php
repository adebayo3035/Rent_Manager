<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notification_helper.php';

session_start();

// Define allowed secret questions
$allowed_questions = [
    "What is your mother's maiden name?",
    "What was the name of your first pet?",
    "What was the name of your first school?",
    "In which city were you born?",
    "What is the name of your favorite teacher?",
    "What is the name of your childhood best friend?",
    "What was your first car?",
    "What is your favorite food?",
    "What was your dream job as a child?",
    "What is your favorite place to visit?"
];

try {
    // Check if user is logged in
    if (!isset($_SESSION['tenant_code'])) {
        json_error("Not logged in", 401, null, 'AUTH_REQUIRED');
    }

    // Check if user is a tenant
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        json_error("Unauthorized access", 403, null, 'UNAUTHORIZED');
    }

    $tenant_code = $_SESSION['tenant_code'] ?? null;

    if (!$tenant_code) {
        json_error("Tenant code not found", 400, null, 'TENANT_CODE_MISSING');
    }

    // Get input data
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        json_error("Invalid input data", 400, null, 'INVALID_INPUT');
    }

    $secret_question_key = isset($input['secret_question']) ? trim($input['secret_question']) : '';
    $secret_answer = isset($input['secret_answer']) ? trim($input['secret_answer']) : '';

    // Map the selected value to the actual question text
    $question_map = [
        'mother_maiden_name' => "What is your mother's maiden name?",
        'first_pet' => "What was the name of your first pet?",
        'first_school' => "What was the name of your first school?",
        'birth_city' => "In which city were you born?",
        'favorite_teacher' => "What is the name of your favorite teacher?",
        'childhood_friend' => "What is the name of your childhood best friend?",
        'first_car' => "What was your first car?",
        'favorite_food' => "What is your favorite food?",
        'dream_job' => "What was your dream job as a child?",
        'favorite_place' => "What is your favorite place to visit?"
    ];

    // Get the actual question text
    $secret_question = $question_map[$secret_question_key] ?? '';

    // Validation
    $validation_errors = [];

    // Check if secret question is provided and valid
    if (empty($secret_question_key)) {
        $validation_errors['secret_question'] = "Please select a secret question";
    } elseif (!array_key_exists($secret_question_key, $question_map)) {
        $validation_errors['secret_question'] = "Invalid secret question selected";
    }

    // Check if secret answer is provided
    if (empty($secret_answer)) {
        $validation_errors['secret_answer'] = "Secret answer is required";
    }

    // Validate secret answer length (minimum 8 characters)
    if (!empty($secret_answer) && strlen($secret_answer) < 8) {
        $validation_errors['secret_answer'] = "Secret answer must be at least 8 characters long";
    }

    // Validate secret answer doesn't contain only spaces
    if (!empty($secret_answer) && trim($secret_answer) === '') {
        $validation_errors['secret_answer'] = "Secret answer cannot be empty or contain only spaces";
    }

    // Validate secret answer doesn't contain special characters that could cause issues (optional)
    if (!empty($secret_answer) && preg_match('/[<>\"\'\\\\]/', $secret_answer)) {
        $validation_errors['secret_answer'] = "Secret answer contains invalid characters";
    }

    // If validation errors exist, return them
    if (!empty($validation_errors)) {
        json_validation_error($validation_errors, "Validation failed");
    }

    // Check if tenant already has secret question set (optional - prevent overwrite)
    $check_query = "SELECT secret_question FROM tenants WHERE tenant_code = ? AND secret_question IS NOT NULL AND secret_question != ''";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("s", $tenant_code);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $has_secret = $check_result->num_rows > 0;
    $check_stmt->close();

    if ($has_secret) {
       json_error("Secret Question already Set for Tenant: $tenant_code", 403, null, 'UNAUTHORIZED');
    }

    // Normalize the secret answer (lowercase, trim spaces) for consistent comparison
    $normalized_answer = strtolower(trim($secret_answer));
    
    // Encrypt the secret answer
    $encrypted_answer = hashSecretAnswer($normalized_answer);

    // Update tenants table with secret question and encrypted answer
    $update_query = "
        UPDATE tenants 
        SET secret_question = ?, 
            secret_answer = ?,
            has_secret_set = 1,
            last_updated_at = NOW()
        WHERE tenant_code = ?
    ";
    
    $update_stmt = $conn->prepare($update_query);
    $update_stmt->bind_param("sss", $secret_question, $encrypted_answer, $tenant_code);
    
    if (!$update_stmt->execute()) {
        throw new Exception("Failed to save secret question and answer: " . $update_stmt->error);
    }
    
    $affected_rows = $update_stmt->affected_rows;
    $update_stmt->close();

    // Log the activity
    logActivity("Secret question and answer set for tenant: $tenant_code");

    // Create notification
    createSecurityNotification($conn, $tenant_code, 'secret_question_set');
    // Return success response
    json_success([
        'has_secret_set' => true,
        'secret_question' => $secret_question
    ], "Secret question and answer set successfully");

} catch (Exception $e) {
    logActivity("Error in set_secret_question_answer: " . $e->getMessage());
    json_error($e->getMessage(), 500, null, 'SERVER_ERROR');
}

function hashSecretAnswer($answer) {
    return password_hash(strtolower(trim($answer)), PASSWORD_DEFAULT);
}