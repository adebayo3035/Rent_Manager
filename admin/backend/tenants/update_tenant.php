<?php
// update_tenant.php

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php'; // json_success, json_error, sanitize_inputs, logActivity
session_start();
define('MAX_FILE_SIZE', 500000); // 500KB
define('MAX_FIELD_LENGTH', 255);
define('RATE_LIMIT_COUNT', 10);
define('RATE_LIMIT_SECONDS', 60);
define('MIN_LEASE_MONTHS', 1);
define('MAX_LEASE_MONTHS', 36);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {

    // ================= AUTH CHECK =================
    if (!isset($_SESSION['unique_id'])) {
        logActivity("SECURITY: Unauthorized access attempt to update_tenant");
        json_error("Not logged in.", 401);
    }

    $adminId = $_SESSION['unique_id'];
    $adminRole = $_SESSION['role'] ?? "Staff";

    logActivity("UPDATE_AGENT API CALLED by Admin=$adminId Role=$adminRole");

    // ================= RATE LIMIT =================
    rateLimit("update_tenant", 20, 60);
    logActivity("Rate limit OK for admin $adminId");

    // ================= METHOD CHECK =================
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        logActivity("Invalid HTTP method for update_tenant: {$_SERVER['REQUEST_METHOD']}");
        json_error("Invalid HTTP method. POST required.", 405);
    }

    // ================= READ INPUT =================
    $rawInput = file_get_contents("php://input");
    logActivity("RAW INPUT: " . $rawInput);

    $decoded = json_decode($rawInput, true);
    if (!$decoded || json_last_error() !== JSON_ERROR_NONE) {
        logActivity("Invalid JSON received by admin $adminId");
        json_error("Malformed JSON input.", 400);
    }

    $input = sanitize_inputs($decoded);
    logActivity("SANITIZED INPUT: " . json_encode($input));

    // Required field
    if (empty($input['tenant_code'])) {
        logActivity("Validation failed: missing tenant_code");
        json_error("Tenant Code is required", 400);
    }

    // Extract fields
    $tenant_code = $input['tenant_code'];
    $action_type = $input['action_type'] ?? 'update_all';

    logActivity("REQUEST RECEIVED | Action=$action_type | Tenant=$tenant_code | By Admin=$adminId");

    // ================= TRANSACTION START =================
    $conn->begin_transaction();
    logActivity("TRANSACTION START for tenant_code=$tenant_code");

    // ================= CHECK TENANT EXISTS =================
    $check = $conn->prepare("SELECT tenant_code, status, apartment_code, property_code FROM tenants WHERE tenant_code = ?");
    $check->bind_param("s", $tenant_code);
    $check->execute();
    $check->bind_result($existing_code, $existing_status, $current_apartment_code, $current_property_code);

    if (!$check->fetch()) {
        logActivity("ERROR: Tenant not found (Tenant Code: $tenant_code)");
        $conn->rollback();
        json_error("Tenant not found.", 404);
    }
    $check->close();

    // ================= ACTION SWITCH =================
    $response = null;

    switch ($action_type) {

        case 'update_all':
            $response = fullUpdate($conn, $input, $tenant_code, $adminId, $adminRole, $current_apartment_code, $current_property_code);
            break;

        case 'delete':
            $response = statusUpdate($conn, $tenant_code, 0, $adminId, $adminRole, "delete", $current_apartment_code);
            break;

        case 'restore':
            $response = statusUpdate($conn, $tenant_code, 1, $adminId, $adminRole, "restore", $current_apartment_code);
            break;

        default:
            logActivity("ERROR: Invalid action_type '$action_type' provided by Admin=$adminId");
            $conn->rollback();
            json_error("Invalid action_type.", 400);
    }

    // ================= COMMIT =================
    $conn->commit();
    logActivity("COMMIT SUCCESS for tenant_code=$tenant_code Action=$action_type");

    json_success($response['message'], 200);

} catch (Throwable $e) {

    logActivity("EXCEPTION: {$e->getMessage()} | FILE={$e->getFile()} | LINE={$e->getLine()}");

    // Only rollback if transaction is active
    if ($conn->errno === 0) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }
    }

    json_error("Internal server error.", 500);
}



/* ============================================================
   FULL UPDATE
   ============================================================ */
function fullUpdate($conn, $input, $tenant_code, $adminId, $adminRole, $current_apartment_code, $current_property_code)
{
    logActivity("FULL UPDATE START for Tenant=$tenant_code by Admin=$adminId");

    // Required fields
    $required = ['tenant_code', 'firstname', 'lastname', 'email', 'phone', 'gender', 'property_code', 'apartment_code', 'lease_start_date', 'lease_end_date', 'rent_payment_frequency', 'status'];
    foreach ($required as $f) {
        if (!isset($input[$f]) || trim($input[$f]) === '') {
            logActivity("Validation failed: '$f' missing during full update for $tenant_code");
            json_error("$f is required", 400);
        }
    }

    // Extract sanitized
    $tenant_code = trim($input['tenant_code']);
    $firstname = trim($input['firstname']);
    $lastname = trim($input['lastname']);
    $email = $input['email'];
    $phone = $input['phone'];
    $gender = ucfirst(strtolower($input['gender']));
    $property_code = trim($input['property_code']);
    $apartment_code = trim($input['apartment_code']);
    $lease_start_date = $input['lease_start_date'];
    $lease_end_date = $input['lease_end_date'];
    $rent_payment_frequency = $input['rent_payment_frequency'];
    $status = intval($input['status']);

    $inputs = sanitize_inputs($input);
    // Log non-sensitive input summary (exclude sensitive data)
    $logCopy = $inputs;

    logActivity('Inputs received: ' . json_encode($logCopy));
    // 1. Field length validation
    foreach ($inputs as $key => $value) {
        if (strlen($value) > MAX_FIELD_LENGTH) {
            logActivity("Field {$key} exceeds maximum length");
            json_error("Field '{$key}' is too long. Maximum " . MAX_FIELD_LENGTH . " characters allowed.", 400);
        }
    }

    // Email validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        logActivity("Invalid email '$email' during update for $tenant_code");
        json_error("Invalid email.", 400);
    }

    // Phone validation
    if (!validate_phone($phone)) {
        logActivity("Invalid phone '$phone' during update for $tenant_code");
        json_error("Invalid phone number.", 400);
    }

    // Gender validation
    if (!in_array($gender, ['Male', 'Female'], true)) {
        logActivity("Invalid gender '$gender' for tenant=$tenant_code");
        json_error("Invalid gender.", 400);
    }

    // Status validation
    if (!in_array($status, [0, 1], true)) {
        logActivity("Invalid status '$status' for tenant=$tenant_code");
        json_error("Invalid status.", 400);
    }

    // Only Super Admin can deactivate
    if ($status == 0 && $adminRole !== "Super Admin") {
        logActivity("UNAUTHORIZED deactivate attempt by Admin=$adminId");
        json_error("You cannot deactivate tenants.", 403);
    }
    // 4. Date validation
    $date_format = 'Y-m-d';
    $start_date = DateTime::createFromFormat($date_format, $lease_start_date);
    $end_date = DateTime::createFromFormat($date_format, $lease_end_date);

    if (!$start_date || !$end_date) {
        logActivity("Invalid date format. Start: {$lease_start_date}, End: {$lease_end_date}");
        json_error('Invalid date format. Use YYYY-MM-DD format.', 400);
    }

    $today = new DateTime();
    $today->setTime(0, 0, 0);

    if ($start_date < $today) {
        logActivity("Lease start date is in the past: {$lease_start_date}");
        json_error('Lease start date cannot be in the past.', 400);
    }

    // 5. Lease duration validation based on payment frequency
    $frequency_to_months = [
        'Monthly' => 1,
        'Quarterly' => 3,
        'Semi-Annually' => 6,
        'Annually' => 12
    ];

    if (!isset($frequency_to_months[$rent_payment_frequency])) {
        logActivity("Invalid payment frequency: {$rent_payment_frequency} for tenant=$tenant_code");
        json_error('Invalid payment frequency selected.', 400);
    }

    $expected_months = $frequency_to_months[$rent_payment_frequency];
    $interval = $start_date->diff($end_date);
    $actual_months = ($interval->y * 12) + $interval->m;

    if ($actual_months != $expected_months) {
        logActivity("Lease duration mismatch. Expected {$expected_months} month(s), got {$actual_months} month(s)");
        json_error("For {$rent_payment_frequency} payment frequency, lease must be exactly {$expected_months} month(s).", 400);
    }

    // Check maximum lease duration
    if ($actual_months > MAX_LEASE_MONTHS) {
        logActivity("Lease duration too long: {$actual_months} months exceeds maximum of " . MAX_LEASE_MONTHS);
        json_error("Maximum lease duration is " . MAX_LEASE_MONTHS . " months.", 400);
    }

    // Duplicate check: phone or email belongs to another tenant
    $dup = $conn->prepare("
        SELECT tenant_code FROM tenants
        WHERE (phone = ? OR email = ?) AND tenant_code != ?
        LIMIT 1
    ");
    $dup->bind_param("sss", $phone, $email, $tenant_code);
    $dup->execute();
    $dup->store_result();

    if ($dup->num_rows > 0) {
        logActivity("DUPLICATE FOUND during update for tenant=$tenant_code");
        json_error("Phone or Email belongs to another tenant.", 409);
    }
    $dup->close();

    // Confirm property and apartment exist and get apartment details
    $aptCheck = $conn->prepare("
        SELECT a.apartment_code, a.occupied_by, a.occupancy_status, p.property_code
        FROM apartments a
        JOIN properties p ON a.property_code = p.property_code
        WHERE p.property_code = ? AND a.apartment_code = ?
        LIMIT 1
    ");
    $aptCheck->bind_param("ss", $property_code, $apartment_code);
    $aptCheck->execute();
    $aptCheck->store_result();
    
    if ($aptCheck->num_rows === 0) {
        logActivity("ERROR: Property or Apartment not found during update for tenant=$tenant_code");
        json_error("Property or Apartment not found.", 404);
    }
    $apt_code = ""; $occupied_by = ""; $occupancy_status = ""; $prop_code = "";
    $aptCheck->bind_result($apt_code, $occupied_by, $occupancy_status, $prop_code);
    $aptCheck->fetch();
    $aptCheck->close();

    // ================= APARTMENT OCCUPANCY HANDLING =================
    
    // Check if apartment is being changed
    $is_apartment_changed = ($current_apartment_code !== $apartment_code);
    
    if ($is_apartment_changed) {
        logActivity("APARTMENT CHANGE DETECTED | From: {$current_apartment_code} To: {$apartment_code}");
        
        // 1. Check if the new apartment is already occupied
        if ($occupied_by !== null && $occupancy_status === 'OCCUPIED') {
            logActivity("ERROR: New apartment {$apartment_code} is already occupied by tenant: {$occupied_by}");
            json_error("Selected apartment is already occupied by another tenant.", 409);
        }
        
        // 2. Clear the old apartment (if it exists and was occupied by this tenant)
        if (!empty($current_apartment_code)) {
            $clearOldStmt = $conn->prepare("
                UPDATE apartments 
                SET occupied_by = NULL, 
                    occupancy_status = 'NOT OCCUPIED',
                    updated_at = NOW(),
                    last_updated_by = ?
                WHERE apartment_code = ? AND occupied_by = ?
            ");
            $clearOldStmt->bind_param("iss", $adminId, $current_apartment_code, $tenant_code);
            $clearOldStmt->execute();
            
            if ($clearOldStmt->affected_rows > 0) {
                logActivity("SUCCESS: Cleared old apartment {$current_apartment_code}");
            } else {
                logActivity("WARNING: Old apartment {$current_apartment_code} was not occupied by this tenant or not found");
            }
            $clearOldStmt->close();
        }
        
        // 3. Update the new apartment
        $updateNewStmt = $conn->prepare("
            UPDATE apartments 
            SET occupied_by = ?, 
                occupancy_status = 'OCCUPIED',
                last_updated_by = ?,
                updated_at = NOW()
            WHERE apartment_code = ?
        ");
        $updateNewStmt->bind_param("sis", $tenant_code, $adminId, $apartment_code);
        $updateNewStmt->execute();
        
        if ($updateNewStmt->affected_rows > 0) {
            logActivity("SUCCESS: New apartment {$apartment_code} assigned to tenant {$tenant_code}");
        } else {
            logActivity("WARNING: New apartment {$apartment_code} could not be updated");
        }
        $updateNewStmt->close();
        
    } else {
        logActivity("Apartment unchanged: {$apartment_code}");
        // Still verify that the current apartment is properly assigned
        if ($occupied_by !== $tenant_code && $occupied_by !== null) {
            logActivity("WARNING: Apartment {$apartment_code} is assigned to different tenant: {$occupied_by}");
            // Fix the mismatch
            $fixStmt = $conn->prepare("
                UPDATE apartments 
                SET occupied_by = ?, 
                    occupancy_status = 'OCCUPIED',
                    last_updated_by = ?,
                    updated_at = NOW()
                WHERE apartment_code = ?
            ");
            $fixStmt->bind_param("sis", $tenant_code, $adminId, $apartment_code);
            $fixStmt->execute();
            $fixStmt->close();
            logActivity("FIXED: Apartment {$apartment_code} now correctly assigned to tenant {$tenant_code}");
        }
    }

    // Perform tenant update
    $stmt = $conn->prepare("
        UPDATE tenants SET
            property_code = ?, apartment_code = ?, firstname = ?, lastname = ?, gender = ?, email = ?, phone = ?,
            lease_start_date = ?, lease_end_date = ?, payment_frequency = ?, status = ?, 
            last_updated_by = ?, last_updated_at = NOW()
        WHERE tenant_code = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        "ssssssssssiss",
        $property_code,
        $apartment_code,
        $firstname,
        $lastname,
        $gender,
        $email,
        $phone,
        $lease_start_date,
        $lease_end_date,
        $rent_payment_frequency,
        $status,
        $adminId,
        $tenant_code
    );

    $stmt->execute();
    $stmt->close();

    logActivity("FULL UPDATE SUCCESS for Tenant=$tenant_code");

    return [
        "success" => true,
        "message" => "Tenant updated successfully." . ($is_apartment_changed ? " Apartment assignment updated." : "")
    ];
}



/* ============================================================
   STATUS UPDATE (delete / restore)
   ============================================================ */
function statusUpdate($conn, $tenant_code, $new_status, $adminId, $adminRole, $action, $current_apartment_code)
{
    logActivity("STATUS UPDATE START ($action) for Tenant=$tenant_code by Admin=$adminId");

    if ($adminRole !== "Super Admin") {
        logActivity("UNAUTHORIZED $action attempt by Admin=$adminId");
        json_error("You do not have permission to modify status.", 403);
    }

    // If deactivating tenant (delete), clear the apartment
    if ($action === "delete" && !empty($current_apartment_code)) {
        logActivity("Deactivating tenant - clearing apartment {$current_apartment_code}");
        
        $clearApartmentStmt = $conn->prepare("
            UPDATE apartments 
            SET occupied_by = NULL, 
                occupancy_status = 'NOT OCCUPIED',
                last_updated_by = ?,
                updated_at = NOW()
            WHERE apartment_code = ? AND occupied_by = ?
        ");
        $clearApartmentStmt->bind_param("iss", $adminId, $current_apartment_code, $tenant_code);
        $clearApartmentStmt->execute();
        
        if ($clearApartmentStmt->affected_rows > 0) {
            logActivity("SUCCESS: Apartment {$current_apartment_code} cleared for deactivated tenant");
        }
        $clearApartmentStmt->close();
    }

    // Update tenant status
    $stmt = $conn->prepare("
        UPDATE tenants
        SET status = ?, last_updated_by = ?, last_updated_at = NOW()
        WHERE tenant_code = ?
        LIMIT 1
    ");

    $stmt->bind_param("iss", $new_status, $adminId, $tenant_code);
    $stmt->execute();

    logActivity("STATUS UPDATE SUCCESS ($action) for Tenant=$tenant_code | NewStatus=$new_status");

    return [
        "success" => true,
        "message" => "Tenant " . ($action === "delete" ? "deactivated" : "restored") . " successfully."
    ];
}