<?php
// update_profile.php - Update tenant profile information with detailed logging

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notification_helper.php';



session_start();

logActivity("========== UPDATE PROFILE - START ==========");
logActivity("Request Time: " . date('Y-m-d H:i:s'));
logActivity("Request Method: " . $_SERVER['REQUEST_METHOD']);
logActivity("Session ID: " . session_id());

try {
    // Step 1: Check authentication
    logActivity("Step 1: Checking authentication");
    
    if (!isset($_SESSION['tenant_code'])) {
        logActivity("ERROR: Authentication failed - No tenant code in session");
        json_error("Not logged in", 401);
    }
    logActivity("Step 1 - Authentication passed: tenant_code found in session");

    // Step 2: Check user role
    logActivity("Step 2: Checking user role");
    
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        logActivity("ERROR: Unauthorized access - Role: " . ($_SESSION['role'] ?? 'none'));
        json_error("Unauthorized access", 403);
    }
    logActivity("Step 2 - Role validation passed: User is Tenant");

    // Step 3: Get tenant code
    $tenant_code = $_SESSION['tenant_code'];
    logActivity("Step 3 - Tenant code retrieved: {$tenant_code}");

    // Step 4: Get and parse input data
    logActivity("Step 4: Processing input data");
    $raw_input = file_get_contents('php://input');
    logActivity("Step 4.1 - Raw input received: " . ($raw_input ? "Yes (" . strlen($raw_input) . " bytes)" : "No input received"));
    
    $input = json_decode($raw_input, true);
    
    if (!$input) {
        logActivity("ERROR: Invalid JSON input - Raw input: " . ($raw_input ?: 'empty'));
        json_error("Invalid input data", 400);
    }
    logActivity("Step 4.2 - JSON decoded successfully");

    // Step 5: Extract and sanitize input fields
    logActivity("Step 5: Extracting and sanitizing input fields");
    
    $firstname = trim($input['firstname'] ?? '');
    $lastname = trim($input['lastname'] ?? '');
    $email = trim($input['email'] ?? '');
    $phone = trim($input['phone'] ?? '');
    $gender = trim($input['gender'] ?? '');
    
    logActivity("Step 5.1 - Extracted values:");
    logActivity("  - firstname: " . ($firstname ?: '[empty]'));
    logActivity("  - lastname: " . ($lastname ?: '[empty]'));
    logActivity("  - email: " . ($email ?: '[empty]'));
    logActivity("  - phone: " . ($phone ?: '[empty]'));
    logActivity("  - gender: " . ($gender ?: '[empty]'));

    // Step 6: Validate required fields
    logActivity("Step 6: Validating required fields");
    
    if (empty($firstname) || empty($lastname) || empty($email) || empty($phone)) {
        logActivity("ERROR: Missing required fields - firstname: " . (empty($firstname) ? 'missing' : 'ok') . 
                    ", lastname: " . (empty($lastname) ? 'missing' : 'ok') . 
                    ", email: " . (empty($email) ? 'missing' : 'ok') . 
                    ", phone: " . (empty($phone) ? 'missing' : 'ok'));
        json_error("First name, last name, email, and phone are required", 400);
    }
    logActivity("Step 6 - Required fields validation passed");

    // Step 7: Validate email format
    logActivity("Step 7: Validating email format");
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        logActivity("ERROR: Invalid email format - Email: {$email}");
        json_error("Invalid email format", 400);
    }
    logActivity("Step 7 - Email format validation passed: {$email}");

    // Step 8: Validate phone format
    logActivity("Step 8: Validating phone format");
    
    if (!validate_phone($phone)) {
        logActivity("ERROR: Invalid phone format - Phone: {$phone}");
        json_error("Invalid phone number format", 400);
    }
    logActivity("Step 8 - Phone format validation passed: {$phone}");

    // Step 9: Validate gender if provided
    logActivity("Step 9: Validating gender value");
    
    if (!empty($gender) && !in_array($gender, ['Male', 'Female', 'Other'])) {
        logActivity("ERROR: Invalid gender value - Gender: {$gender}");
        json_error("Invalid gender value", 400);
    }
    logActivity("Step 9 - Gender validation passed" . ($gender ? ": {$gender}" : " (not provided)"));

    // Step 10: Check if email is already used by another tenant
    logActivity("Step 10: Checking email uniqueness");
    
    $checkEmailStmt = $conn->prepare("
        SELECT tenant_code FROM tenants 
        WHERE email = ? AND tenant_code != ? AND status = 1
        LIMIT 1
    ");
    $checkEmailStmt->bind_param("ss", $email, $tenant_code);
    $checkEmailStmt->execute();
    $emailResult = $checkEmailStmt->get_result();
    
    if ($emailResult->num_rows > 0) {
        $existingTenant = $emailResult->fetch_assoc();
        logActivity("ERROR: Email already used by another tenant - Email: {$email}, Existing tenant: " . $existingTenant['tenant_code']);
        $checkEmailStmt->close();
        json_error("Email address is already used by another tenant", 400);
    }
    $checkEmailStmt->close();
    logActivity("Step 10 - Email uniqueness check passed");

    // Step 11: Check if phone is already used by another tenant
    logActivity("Step 11: Checking phone uniqueness");
    
    $checkPhoneStmt = $conn->prepare("
        SELECT tenant_code FROM tenants 
        WHERE phone = ? AND tenant_code != ? AND status = 1
        LIMIT 1
    ");
    $checkPhoneStmt->bind_param("ss", $phone, $tenant_code);
    $checkPhoneStmt->execute();
    $phoneResult = $checkPhoneStmt->get_result();
    
    if ($phoneResult->num_rows > 0) {
        $existingTenant = $phoneResult->fetch_assoc();
        logActivity("ERROR: Phone already used by another tenant - Phone: {$phone}, Existing tenant: " . $existingTenant['tenant_code']);
        $checkPhoneStmt->close();
        json_error("Phone number is already used by another tenant", 400);
    }
    $checkPhoneStmt->close();
    logActivity("Step 11 - Phone uniqueness check passed");

    // Step 12: Prepare update query
    logActivity("Step 12: Preparing profile update query");
    
    $updateQuery = "
        UPDATE tenants 
        SET firstname = ?,
            lastname = ?,
            email = ?,
            phone = ?,
            gender = ?,
            last_updated_by = ?,
            last_updated_at = NOW()
        WHERE tenant_code = ? AND status = 1
    ";
    logActivity("Step 12.1 - Update query prepared");

    // Step 13: Execute update
    logActivity("Step 13: Executing profile update");
    
    $updateStmt = $conn->prepare($updateQuery);
    $updateStmt->bind_param("sssssss", $firstname, $lastname, $email, $phone, $gender, $tenant_code, $tenant_code);
    
    logActivity("Step 13.1 - Parameters bound: firstname={$firstname}, lastname={$lastname}, email={$email}, phone={$phone}, gender={$gender}, tenant_code={$tenant_code}");
    
    if (!$updateStmt->execute()) {
        logActivity("ERROR: Update execution failed - " . $updateStmt->error);
        throw new Exception("Failed to update profile: " . $updateStmt->error);
    }

    $affectedRows = $updateStmt->affected_rows;
    $updateStmt->close();
    
    logActivity("Step 13.2 - Update executed successfully. Affected rows: {$affectedRows}");
    // Create Notification After successful profile update
    createSecurityNotification($conn, $tenant_code, 'profile_updated');

    // Step 14: Fetch updated user data
    logActivity("Step 14: Fetching updated user data");
    
    $fetchQuery = "
        SELECT 
            tenant_code,
            firstname,
            lastname,
            email,
            phone,
            gender,
            photo,
            apartment_code,
            lease_start_date,
            lease_end_date,
            payment_frequency
        FROM tenants 
        WHERE tenant_code = ? AND status = 1
        LIMIT 1
    ";
    
    $fetchStmt = $conn->prepare($fetchQuery);
    $fetchStmt->bind_param("s", $tenant_code);
    $fetchStmt->execute();
    $result = $fetchStmt->get_result();
    $userData = $result->fetch_assoc();
    $fetchStmt->close();
    
    if ($userData) {
        logActivity("Step 14.1 - User data fetched successfully");
        logActivity("  - Updated firstname: " . $userData['firstname']);
        logActivity("  - Updated lastname: " . $userData['lastname']);
        logActivity("  - Updated email: " . $userData['email']);
        logActivity("  - Updated phone: " . $userData['phone']);
    } else {
        logActivity("WARNING: Could not fetch updated user data for tenant: {$tenant_code}");
    }

    // Step 15: Log completion and return response
    logActivity("Step 15: Profile update completed successfully");
    logActivity("========== UPDATE PROFILE - SUCCESS ==========");
    
    json_success($userData, "Profile updated successfully");
 

} catch (Exception $e) {
    logActivity("========== UPDATE PROFILE - ERROR ==========");
    logActivity("ERROR Type: " . get_class($e));
    logActivity("ERROR Code: " . $e->getCode());
    logActivity("ERROR Message: " . $e->getMessage());
    logActivity("ERROR File: " . $e->getFile());
    logActivity("ERROR Line: " . $e->getLine());
    logActivity("ERROR Trace: " . $e->getTraceAsString());
    
    $error_code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    json_error($e->getMessage(), $error_code);
} finally {
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
        $conn->close();
        logActivity("Database connection closed");
    }
}
?>