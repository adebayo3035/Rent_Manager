<?php
// change_default_password.php - First login password & secret question setup

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notification_helper.php';

// Start session for logging purposes
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Generate unique request ID for tracking
$requestId = uniqid('change_default_pw_', true);
logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] ========== START ==========");
logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Request Time: " . date('Y-m-d H:i:s'));

try {
    // ==================== STEP 1: GET INPUT DATA ====================
    logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Step 1: Getting input data");
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] ERROR: Invalid input data");
        json_error("Invalid input data", 400);
    }
    
    logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Input keys: " . implode(', ', array_keys($input)));

    // Extract data from request
    $user_id = isset($input['user_id']) ? trim($input['user_id']) : '';
    $temp_token = isset($input['temp_token']) ? trim($input['temp_token']) : '';
    $new_password = isset($input['new_password']) ? $input['new_password'] : '';
    $confirm_password = isset($input['confirm_password']) ? $input['confirm_password'] : '';
    $secret_question_key = isset($input['secret_question']) ? trim($input['secret_question']) : '';
    $secret_answer = isset($input['secret_answer']) ? trim($input['secret_answer']) : '';
    $confirm_answer = isset($input['confirm_answer']) ? trim($input['confirm_answer']) : '';
    $user_type = 'tenant';

    logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] User ID: {$user_id}");
    logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Temp Token: " . substr($temp_token, 0, 20) . "...");
    logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Secret Question Key: {$secret_question_key}");
    logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] New Password provided: " . (!empty($new_password) ? 'Yes' : 'No'));

    // ==================== STEP 2: VALIDATE TEMP TOKEN FROM DATABASE ====================
    logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Step 2: Validating temp token from database");
    
    if (empty($user_id)) {
        logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] ERROR: User ID is required");
        json_error("User ID is required", 400);
    }

    if (empty($temp_token)) {
        logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] ERROR: Temp token is required");
        json_error("Invalid session. Please log in again.", 401);
    }

    // Validate token from database
    $token_query = "
        SELECT id, user_id, token, expires_at, is_used 
        FROM temp_auth_tokens 
        WHERE user_id = ? AND user_type = ? AND token = ? AND is_used = 0 AND expires_at > NOW()
        LIMIT 1
    ";
    
    $token_stmt = $conn->prepare($token_query);
    $token_stmt->bind_param("sss", $user_id, $user_type, $temp_token);
    $token_stmt->execute();
    $token_result = $token_stmt->get_result();
    $token_data = $token_result->fetch_assoc();
    $token_stmt->close();

    if (!$token_data) {
        // Check if token exists but is expired or used
        $check_query = "
            SELECT id, is_used, expires_at 
            FROM temp_auth_tokens 
            WHERE user_id = ? AND token = ?
            LIMIT 1
        ";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("ss", $user_id, $temp_token);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $check_data = $check_result->fetch_assoc();
        $check_stmt->close();

        if ($check_data) {
            if ($check_data['is_used'] == 1) {
                logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] ERROR: Token already used");
                json_error("Token already used. Please log in again.", 401);
            } elseif (strtotime($check_data['expires_at']) < time()) {
                logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] ERROR: Token expired");
                json_error("Token expired. Please log in again.", 401);
            }
        }
        
        logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] ERROR: Invalid token for user: {$user_id}");
        json_error("Invalid or expired session. Please log in again.", 401);
    }

    logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Token validated successfully");
    logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Token ID: {$token_data['id']}, Expires: {$token_data['expires_at']}");

    // ==================== STEP 3: VALIDATE PASSWORD ====================
    logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Step 3: Validating password");
    
    $validation_errors = [];

    // Password validation
    if (empty($new_password)) {
        $validation_errors['new_password'] = "New password is required";
    } else {
        if (strlen($new_password) < 8) {
            $validation_errors['new_password'] = "Password must be at least 8 characters long";
        }
        if (!preg_match('/[A-Z]/', $new_password)) {
            $validation_errors['new_password'] = "Password must contain at least one uppercase letter";
        }
        if (!preg_match('/[a-z]/', $new_password)) {
            $validation_errors['new_password'] = "Password must contain at least one lowercase letter";
        }
        if (!preg_match('/[0-9]/', $new_password)) {
            $validation_errors['new_password'] = "Password must contain at least one number";
        }
        if (preg_match('/\s/', $new_password)) {
            $validation_errors['new_password'] = "Password cannot contain spaces";
        }
    }

    if (empty($confirm_password)) {
        $validation_errors['confirm_password'] = "Please confirm your password";
    } elseif ($new_password !== $confirm_password) {
        $validation_errors['confirm_password'] = "Passwords do not match";
    }

    // ==================== STEP 4: VALIDATE SECRET QUESTION ====================
    logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Step 4: Validating secret question");
    
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

    if (empty($secret_question_key)) {
        $validation_errors['secret_question'] = "Please select a secret question";
    } elseif (!array_key_exists($secret_question_key, $question_map)) {
        logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] ERROR: Invalid secret question key: {$secret_question_key}");
        $validation_errors['secret_question'] = "Invalid secret question selected";
    }

    // ==================== STEP 5: VALIDATE SECRET ANSWER ====================
    logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Step 5: Validating secret answer");
    
    if (empty($secret_answer)) {
        $validation_errors['secret_answer'] = "Secret answer is required";
    } else {
        if (strlen($secret_answer) < 8) {
            $validation_errors['secret_answer'] = "Secret answer must be at least 8 characters long";
        }
        if (trim($secret_answer) === '') {
            $validation_errors['secret_answer'] = "Secret answer cannot be empty or contain only spaces";
        }
        if (preg_match('/[<>"\'\\\\]/', $secret_answer)) {
            $validation_errors['secret_answer'] = "Secret answer contains invalid characters";
        }
    }

    if (empty($confirm_answer)) {
        $validation_errors['confirm_answer'] = "Please confirm your secret answer";
    } elseif ($secret_answer !== $confirm_answer) {
        $validation_errors['confirm_answer'] = "Secret answers do not match";
    }

    // If validation errors exist, return them
    if (!empty($validation_errors)) {
        logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Validation failed with " . count($validation_errors) . " errors");
        foreach ($validation_errors as $field => $error) {
            logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}]   - {$field}: {$error}");
        }
        json_validation_error($validation_errors, "Validation failed");
    }
    
    logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] All validations passed");

    // ==================== STEP 6: GET TENANT DATA ====================
    logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Step 6: Fetching tenant data");
    
    $tenant_query = "SELECT tenant_code, email, firstname, lastname FROM tenants WHERE tenant_code = ? AND deleted_at IS NULL LIMIT 1";
    $tenant_stmt = $conn->prepare($tenant_query);
    $tenant_stmt->bind_param("s", $user_id);
    $tenant_stmt->execute();
    $tenant_result = $tenant_stmt->get_result();
    $tenant = $tenant_result->fetch_assoc();
    $tenant_stmt->close();

    if (!$tenant) {
        logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] ERROR: Tenant not found: {$user_id}");
        json_error("Tenant not found", 404);
    }
    
    logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Tenant found: {$tenant['email']}");

    // ==================== STEP 7: GET ACTUAL QUESTION TEXT ====================
    $secret_question = $question_map[$secret_question_key];
    logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Secret question: {$secret_question}");

    // ==================== STEP 8: HASH DATA ====================
    logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Step 8: Hashing sensitive data");
    
    $normalized_answer = strtolower(trim($secret_answer));
    $encrypted_answer = password_hash($normalized_answer, PASSWORD_DEFAULT);
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

    logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Password and secret answer hashed successfully");

    // ==================== STEP 9: START TRANSACTION ====================
    logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Step 9: Starting database transaction");
    $conn->begin_transaction();

    try {
        // ==================== STEP 10: UPDATE TENANT ====================
        logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Step 10: Updating tenant record");
        
        $update_query = "
            UPDATE tenants 
            SET password = ?,
                secret_question = ?, 
                secret_answer = ?,
                password_changed = 1,
                has_secret_set = 1,
                last_updated_by = ?,
                last_updated_at = NOW()
            WHERE tenant_code = ?
        ";
        
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("sssss", $hashed_password, $secret_question, $encrypted_answer, $user_id, $user_id);
        
        if (!$update_stmt->execute()) {
            logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] ERROR: Failed to update tenant: " . $update_stmt->error);
            throw new Exception("Failed to update security details: " . $update_stmt->error);
        }
        
        $affected_rows = $update_stmt->affected_rows;
        $update_stmt->close();

        if ($affected_rows === 0) {
            logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] ERROR: No rows affected");
            throw new Exception("No changes made. Tenant not found.");
        }
        
        logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Tenant updated successfully. Affected rows: {$affected_rows}");

        // ==================== STEP 11: MARK TOKEN AS USED ====================
        logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Step 11: Marking token as used");
        
        $update_token_query = "
            UPDATE temp_auth_tokens 
            SET is_used = 1, used_at = NOW() 
            WHERE id = ?
        ";
        $update_token_stmt = $conn->prepare($update_token_query);
        $update_token_stmt->bind_param("i", $token_data['id']);
        $update_token_stmt->execute();
        $update_token_stmt->close();
        
        logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Token marked as used");

        // ==================== STEP 12: CLEAN UP OLD TOKENS ====================
        logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Step 12: Cleaning up old tokens");
        
        $cleanup_query = "DELETE FROM temp_auth_tokens WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)";
        $conn->query($cleanup_query);
        logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Old tokens cleaned up");

        // ==================== STEP 13: CREATE NOTIFICATION ====================
        logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Step 13: Creating notification");
        createSecurityNotification($conn, $user_id, 'security_details_updated');
        logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Notification created");

        // ==================== STEP 14: COMMIT ====================
        $conn->commit();
        logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Transaction committed successfully");

        // ==================== STEP 15: CLEAR SESSION TOKEN ====================
        if (isset($_SESSION['temp_auth_token'][$user_id])) {
            unset($_SESSION['temp_auth_token'][$user_id]);
            logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Session token cleared");
        }

        // ==================== STEP 16: LOG ACTIVITY ====================
        logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Password and secret question set for tenant: {$user_id} (First login)");
        logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Tenant: {$tenant['firstname']} {$tenant['lastname']} ({$tenant['email']})");

        // ==================== STEP 17: RETURN SUCCESS ====================
        logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] ========== SUCCESS ==========");
        
        json_success([
            'has_secret_set' => true,
            'password_changed' => true,
            'secret_question' => $secret_question,
            'user_id' => $user_id,
            'message' => 'Security details updated successfully. Please log in with your new credentials.'
        ], "Security details updated successfully. Please log in again.");

    } catch (Exception $e) {
        logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] ERROR in transaction: " . $e->getMessage());
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] ========== ERROR ==========");
    logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Error: " . $e->getMessage());
    logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Error Code: " . $e->getCode());
    logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Error File: " . $e->getFile());
    logActivity("[CHANGE_DEFAULT_PW] [ID:{$requestId}] Error Line: " . $e->getLine());
    
    json_error($e->getMessage(), 500);
}