<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notification_helper.php';

session_start();

// Define allowed secret questions mapping
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

try {
    // Log script start
    logActivity("RESET_SECRET: Script started - reset_secret_question_answer.php");
    
    // Check if user is logged in
    logActivity("RESET_SECRET: Checking authentication status");
    
    if (!isset($_SESSION['tenant_code'])) {
        logActivity("RESET_SECRET: Authentication failed - tenant_code not in session");
        json_error("Not logged in", 401, null, 'AUTH_REQUIRED');
    }
    
    logActivity("RESET_SECRET: User authenticated - tenant_code: " . $_SESSION['tenant_code']);

    // Check if user is a tenant
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        $role = $_SESSION['role'] ?? 'not set';
        logActivity("RESET_SECRET: Authorization failed - Invalid role: " . $role);
        json_error("Unauthorized access", 403, null, 'UNAUTHORIZED');
    }
    
    logActivity("RESET_SECRET: User role verified - Role: " . $_SESSION['role']);

    $tenant_code = $_SESSION['tenant_code'] ?? null;

    if (!$tenant_code) {
        logActivity("RESET_SECRET: Tenant code missing from session");
        json_error("Tenant code not found", 400, null, 'TENANT_CODE_MISSING');
    }
    
    logActivity("RESET_SECRET: Processing request for tenant: " . $tenant_code);

    // Get input data
    $raw_input = file_get_contents('php://input');
    logActivity("RESET_SECRET: Raw input received - Length: " . strlen($raw_input) . " bytes");
    
    $input = json_decode($raw_input, true);
    
    if (!$input) {
        logActivity("RESET_SECRET: Invalid JSON input received - Raw: " . substr($raw_input, 0, 200));
        json_error("Invalid input data", 400, null, 'INVALID_INPUT');
    }
    
    logActivity("RESET_SECRET: Input decoded successfully - Fields: " . implode(', ', array_keys($input)));

    $secret_question_key = isset($input['secret_question']) ? trim($input['secret_question']) : '';
    $secret_answer = isset($input['secret_answer']) ? trim($input['secret_answer']) : '';
    $confirm_answer = isset($input['confirm_answer']) ? trim($input['confirm_answer']) : '';
    $password = isset($input['password']) ? $input['password'] : '';
    
    logActivity("RESET_SECRET: Input parameters - Question: " . $secret_question_key . 
                ", Answer length: " . strlen($secret_answer) . 
                ", Confirm length: " . strlen($confirm_answer) . 
                ", Password provided: " . (empty($password) ? 'No' : 'Yes'));

    // Validation
    $validation_errors = [];

    // Validate password
    logActivity("RESET_SECRET: Starting password validation for tenant: " . $tenant_code);
    
    if (empty($password)) {
        logActivity("RESET_SECRET: Password validation failed - Password is empty");
        $validation_errors['password'] = "Password is required to reset secret question";
    } else {
        logActivity("RESET_SECRET: Verifying current password for tenant: " . $tenant_code);
        
        $password_query = "SELECT password FROM tenants WHERE tenant_code = ?";
        $password_stmt = $conn->prepare($password_query);
        
        if (!$password_stmt) {
            logActivity("RESET_SECRET: Failed to prepare password statement - Error: " . $conn->error);
            throw new Exception("Database prepare failed: " . $conn->error);
        }
        
        $password_stmt->bind_param("s", $tenant_code);
        
        if (!$password_stmt->execute()) {
            logActivity("RESET_SECRET: Failed to execute password query - Error: " . $password_stmt->error);
            throw new Exception("Database execute failed: " . $password_stmt->error);
        }
        
        $password_result = $password_stmt->get_result();
        
        if ($password_result->num_rows === 0) {
            logActivity("RESET_SECRET: Password validation failed - Tenant not found: " . $tenant_code);
            $validation_errors['password'] = "Tenant not found";
        } else {
            $tenant_data = $password_result->fetch_assoc();
            logActivity("RESET_SECRET: Tenant found, verifying password hash");
            
            if (!password_verify($password, $tenant_data['password'])) {
                logActivity("RESET_SECRET: Password verification failed - Incorrect password for tenant: " . $tenant_code);
                $validation_errors['password'] = "Current password is incorrect";
            } else {
                logActivity("RESET_SECRET: Password verification successful for tenant: " . $tenant_code);
            }
        }
        $password_stmt->close();
        logActivity("RESET_SECRET: Password statement closed");
    }

    // Validate secret question
    logActivity("RESET_SECRET: Starting secret question validation");
    
    if (empty($secret_question_key)) {
        logActivity("RESET_SECRET: Secret question validation failed - No question selected by tenant: " . $tenant_code);
        $validation_errors['secret_question'] = "Please select a secret question";
    } elseif (!array_key_exists($secret_question_key, $question_map)) {
        logActivity("RESET_SECRET: Secret question validation failed - Invalid question key: " . $secret_question_key);
        $validation_errors['secret_question'] = "Invalid secret question selected";
    } else {
        logActivity("RESET_SECRET: Secret question validated - Question: " . $secret_question_key);
    }

    // Validate secret answer
    logActivity("RESET_SECRET: Starting secret answer validation");
    
    if (empty($secret_answer)) {
        logActivity("RESET_SECRET: Secret answer validation failed - Answer is empty");
        $validation_errors['secret_answer'] = "Secret answer is required";
    } else {
        $answer_length = strlen($secret_answer);
        logActivity("RESET_SECRET: Secret answer length check - Length: " . $answer_length);
        
        if ($answer_length < 8) {
            logActivity("RESET_SECRET: Secret answer validation failed - Too short (Length: " . $answer_length . ")");
            $validation_errors['secret_answer'] = "Secret answer must be at least 8 characters long";
        } elseif (trim($secret_answer) === '') {
            logActivity("RESET_SECRET: Secret answer validation failed - Only spaces provided");
            $validation_errors['secret_answer'] = "Secret answer cannot be empty or contain only spaces";
        } elseif (preg_match('/[<>"\'\\\\]/', $secret_answer)) {
            logActivity("RESET_SECRET: Secret answer validation failed - Contains invalid characters");
            $validation_errors['secret_answer'] = "Secret answer contains invalid characters";
        } else {
            logActivity("RESET_SECRET: Secret answer validation passed");
        }
    }

    // Validate answer confirmation
    logActivity("RESET_SECRET: Starting answer confirmation validation");
    
    if (empty($confirm_answer)) {
        logActivity("RESET_SECRET: Confirmation validation failed - No confirmation provided");
        $validation_errors['confirm_answer'] = "Please confirm your secret answer";
    } elseif ($secret_answer !== $confirm_answer) {
        logActivity("RESET_SECRET: Confirmation validation failed - Answers do not match");
        $validation_errors['confirm_answer'] = "Secret answers do not match";
    } else {
        logActivity("RESET_SECRET: Answer confirmation validated successfully");
    }

    // If validation errors exist, return them
    if (!empty($validation_errors)) {
        logActivity("RESET_SECRET: Validation failed with " . count($validation_errors) . " errors - Tenant: " . $tenant_code);
        foreach ($validation_errors as $field => $error) {
            logActivity("RESET_SECRET: Validation error - Field: " . $field . ", Error: " . $error);
        }
        json_validation_error($validation_errors, "Validation failed");
    }
    
    logActivity("RESET_SECRET: All validations passed successfully for tenant: " . $tenant_code);

    // Check if tenant has secret question set (must exist to reset)
    logActivity("RESET_SECRET: Checking if tenant has existing secret question - Tenant: " . $tenant_code);
    
    $check_query = "SELECT secret_question, has_secret_set FROM tenants WHERE tenant_code = ?";
    $check_stmt = $conn->prepare($check_query);
    
    if (!$check_stmt) {
        logActivity("RESET_SECRET: Failed to prepare check statement - Error: " . $conn->error);
        throw new Exception("Database prepare failed: " . $conn->error);
    }
    
    $check_stmt->bind_param("s", $tenant_code);
    
    if (!$check_stmt->execute()) {
        logActivity("RESET_SECRET: Failed to execute check query - Error: " . $check_stmt->error);
        throw new Exception("Database execute failed: " . $check_stmt->error);
    }
    
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows === 0) {
        logActivity("RESET_SECRET: Tenant not found in database - Code: " . $tenant_code);
        json_error("Tenant not found", 404, null, 'TENANT_NOT_FOUND');
    }
    
    $tenant_data = $check_result->fetch_assoc();
    logActivity("RESET_SECRET: Tenant data retrieved - has_secret_set: " . ($tenant_data['has_secret_set'] ?? 'NULL') . 
                ", Current question: " . ($tenant_data['secret_question'] ?? 'NULL'));
    
    if (!$tenant_data['has_secret_set'] || empty($tenant_data['secret_question'])) {
        logActivity("RESET_SECRET: No secret question set to reset - has_secret_set: " . ($tenant_data['has_secret_set'] ?? 0) . 
                    ", has question: " . (empty($tenant_data['secret_question']) ? 'No' : 'Yes'));
        json_error("No secret question set to reset. Please set one first.", 400, null, 'NO_SECRET_SET');
    }
    
    logActivity("RESET_SECRET: Tenant has existing secret question - Proceeding with reset");
    $check_stmt->close();

    // Get the actual question text
    $secret_question = $question_map[$secret_question_key];
    logActivity("RESET_SECRET: Selected question text - " . $secret_question);

    // Normalize and encrypt the new secret answer
    logActivity("RESET_SECRET: Normalizing and encrypting new secret answer");
    $normalized_answer = strtolower(trim($secret_answer));
    $encrypted_answer = hashSecretAnswer($normalized_answer);
    logActivity("RESET_SECRET: Answer encrypted successfully - Length: " . strlen($encrypted_answer));

    // Start transaction
    logActivity("RESET_SECRET: Starting database transaction");
    $conn->begin_transaction();

    try {
        // Update tenants table with new secret question and answer
        $update_query = "
            UPDATE tenants 
            SET secret_question = ?, 
                secret_answer = ?,
                has_secret_set = 1,
                last_secret_reset = NOW(),
                last_updated_at = NOW()
            WHERE tenant_code = ?
        ";
        
        logActivity("RESET_SECRET: Preparing update query - SQL: " . $update_query);
        
        $update_stmt = $conn->prepare($update_query);
        
        if (!$update_stmt) {
            logActivity("RESET_SECRET: Failed to prepare update statement - Error: " . $conn->error);
            throw new Exception("Failed to prepare update: " . $conn->error);
        }
        
        $update_stmt->bind_param("sss", $secret_question, $encrypted_answer, $tenant_code);
        logActivity("RESET_SECRET: Update statement bound with parameters - Question: " . $secret_question . ", Tenant: " . $tenant_code);
        
        if (!$update_stmt->execute()) {
            logActivity("RESET_SECRET: Failed to execute update - Error: " . $update_stmt->error);
            throw new Exception("Failed to reset secret question and answer: " . $update_stmt->error);
        }
        
        $affected_rows = $update_stmt->affected_rows;
        logActivity("RESET_SECRET: Update executed successfully - Affected rows: " . $affected_rows);
        
        if ($affected_rows === 0) {
            logActivity("RESET_SECRET: No rows updated - Tenant: " . $tenant_code . " may not exist or no changes made");
            throw new Exception("No changes made. Tenant not found.");
        }
        
        $update_stmt->close();
        logActivity("RESET_SECRET: Update statement closed");

        // Log the activity with detailed information
        logActivity("Secret question and answer reset for tenant: $tenant_code - Old question: " . 
                   ($tenant_data['secret_question'] ?? 'NULL') . " -> New question: " . $secret_question);
        
        // Additional detailed log for debugging
        logActivity("RESET_SECRET_DETAILS: Tenant: $tenant_code, Reset timestamp: " . date('Y-m-d H:i:s') . 
                   ", New question key: $secret_question_key, Answer hash length: " . strlen($encrypted_answer));

        // Create notification for security settings update
        logActivity("RESET_SECRET: Creating security notification for tenant: " . $tenant_code);
        createSecurityNotification($conn, $tenant_code, 'secret_question_reset');
        logActivity("RESET_SECRET: Security notification created successfully");

        // Commit transaction
        $conn->commit();
        logActivity("RESET_SECRET: Transaction committed successfully");

        // Return success response
        logActivity("RESET_SECRET: Operation completed successfully for tenant: " . $tenant_code);
        json_success([
            'has_secret_set' => true,
            'secret_question' => $secret_question,
            'reset_date' => date('Y-m-d H:i:s')
        ], "Secret question and answer reset successfully");

    } catch (Exception $e) {
        // Rollback transaction on error
        logActivity("RESET_SECRET: Error occurred - Rolling back transaction: " . $e->getMessage());
        $conn->rollback();
        logActivity("RESET_SECRET: Transaction rolled back");
        throw $e;
    }

} catch (Exception $e) {
    logActivity("RESET_SECRET_ERROR: " . $e->getMessage() . " - Stack trace: " . $e->getTraceAsString());
    json_error($e->getMessage(), 500, null, 'SERVER_ERROR');
} finally {
    logActivity("RESET_SECRET: Script ended - reset_secret_question_answer.php");
    if (isset($conn) && !$conn->connect_error) {
        logActivity("RESET_SECRET: Database connection still open, will close on script end");
    }
}

function hashSecretAnswer($answer) {
    logActivity("RESET_SECRET: Hashing secret answer - Input length: " . strlen($answer));
    $hashed = password_hash(strtolower(trim($answer)), PASSWORD_DEFAULT);
    logActivity("RESET_SECRET: Answer hashed successfully - Hash length: " . strlen($hashed));
    return $hashed;
}