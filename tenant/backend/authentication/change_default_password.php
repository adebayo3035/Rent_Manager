<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notification_helper.php';

session_start();

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

    // Extract data from request
    $new_password = isset($input['new_password']) ? $input['new_password'] : '';
    $confirm_password = isset($input['confirm_password']) ? $input['confirm_password'] : '';
    $secret_question_key = isset($input['new_question']) ? trim($input['new_question']) : '';
    $secret_answer = isset($input['new_answer']) ? trim($input['new_answer']) : '';
    $confirm_answer = isset($input['confirm_answer']) ? trim($input['confirm_answer']) : '';

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

    // Validation array
    $validation_errors = [];

    // === PASSWORD VALIDATION ===
    if (empty($new_password)) {
        $validation_errors['new_password'] = "New password is required";
    } elseif (strlen($new_password) < 8) {
        $validation_errors['new_password'] = "Password must be at least 8 characters long";
    } elseif (!preg_match('/[A-Z]/', $new_password)) {
        $validation_errors['new_password'] = "Password must contain at least one uppercase letter";
    } elseif (!preg_match('/[a-z]/', $new_password)) {
        $validation_errors['new_password'] = "Password must contain at least one lowercase letter";
    } elseif (!preg_match('/[0-9]/', $new_password)) {
        $validation_errors['new_password'] = "Password must contain at least one number";
    }

    if (empty($confirm_password)) {
        $validation_errors['confirm_password'] = "Please confirm your password";
    } elseif ($new_password !== $confirm_password) {
        $validation_errors['confirm_password'] = "Passwords do not match";
    }

    // === SECRET QUESTION VALIDATION ===
    if (empty($secret_question_key)) {
        $validation_errors['secret_question'] = "Please select a secret question";
    } elseif (!array_key_exists($secret_question_key, $question_map)) {
        $validation_errors['secret_question'] = "Invalid secret question selected";
    }

    // === SECRET ANSWER VALIDATION ===
    if (empty($secret_answer)) {
        $validation_errors['secret_answer'] = "Secret answer is required";
    } elseif (strlen($secret_answer) < 8) {
        $validation_errors['secret_answer'] = "Secret answer must be at least 8 characters long";
    } elseif (trim($secret_answer) === '') {
        $validation_errors['secret_answer'] = "Secret answer cannot be empty or contain only spaces";
    } elseif (preg_match('/[<>"\'\\\\]/', $secret_answer)) {
        $validation_errors['secret_answer'] = "Secret answer contains invalid characters";
    }

    if (empty($confirm_answer)) {
        $validation_errors['confirm_answer'] = "Please confirm your secret answer";
    } elseif ($secret_answer !== $confirm_answer) {
        $validation_errors['confirm_answer'] = "Secret answers do not match";
    }

    // If validation errors exist, return them
    if (!empty($validation_errors)) {
        json_validation_error($validation_errors, "Validation failed");
    }

    // Check if this is a first-time login (force password change)
    // You should have a session variable or database flag for this
    $needs_force_change = isset($_SESSION['needs_password_change']) && $_SESSION['needs_password_change'] === true;
    
    if (!$needs_force_change) {
        // If not forced, check if secret question is already set
        $check_query = "SELECT secret_question, has_secret_set FROM tenants WHERE tenant_code = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("s", $tenant_code);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $tenant_data = $check_result->fetch_assoc();
        $check_stmt->close();

        if ($tenant_data && isset($tenant_data['has_secret_set']) && $tenant_data['has_secret_set'] == 1) {
            json_error("Security details already set", 403, null, 'ALREADY_SET');
        }
    }

    // Get the actual question text
    $secret_question = $question_map[$secret_question_key];

    // Normalize and encrypt the secret answer
    $normalized_answer = strtolower(trim($secret_answer));
    $encrypted_answer = hashSecretAnswer($normalized_answer);

    // Hash the new password
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

    // Start transaction
    $conn->begin_transaction();

    try {
        // Update tenants table with password, secret question and answer
        $update_query = "
            UPDATE tenants 
            SET password = ?,
                secret_question = ?, 
                secret_answer = ?,
                password_changed = 1,
                has_secret_set = 1,
                last_updated_at = NOW()
            WHERE tenant_code = ?
        ";
        
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("ssss", $hashed_password, $secret_question, $encrypted_answer, $tenant_code);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update security details: " . $update_stmt->error);
        }
        
        $affected_rows = $update_stmt->affected_rows;
        $update_stmt->close();

        if ($affected_rows === 0) {
            throw new Exception("No changes made. Tenant not found.");
        }

        // Commit transaction
        $conn->commit();

        // Clear the force password change flag from session
        unset($_SESSION['needs_password_change']);
        
        // Log the activity
        logActivity("Password and secret question set for tenant: $tenant_code (First login)");

        // Create notification for security settings change
        createSecurityNotification($conn, $tenant_code, 'security_details_updated');

        // Invalidate all existing sessions (optional but recommended)
        // You can implement session invalidation here if you have a session table

        // Return success response
        json_success([
            'has_secret_set' => true,
            'password_changed' => true,
            'secret_question' => $secret_question
        ], "Security details updated successfully. Please log in again.");

    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    logActivity("Error in change_default_password: " . $e->getMessage());
    json_error($e->getMessage(), 500, null, 'SERVER_ERROR');
}

function hashSecretAnswer($answer) {
    return password_hash(strtolower(trim($answer)), PASSWORD_DEFAULT);
}