<?php
// update_property_type.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

// Enable mysqli exception mode
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    // ================= AUTH CHECK =================
    if (!isset($_SESSION['unique_id'])) {
        logActivity("SECURITY: Unauthorized access attempt to update_property_type");
        json_error("Not logged in.", 401);
    }

    $adminId = (int) $_SESSION['unique_id'];
    $adminRole = $_SESSION['role'] ?? null;

    // =============== VALIDATE JSON INPUT =================
    $input = json_decode(file_get_contents("php://input"), true);
    if (!$input || json_last_error() !== JSON_ERROR_NONE) {
        logActivity("Invalid JSON input while updating property type by user $adminId");
        json_error("Invalid JSON input.", 400);
    }

    // Normalize and trim inputs
    $property_code     = isset($input['property_id']) ? trim($input['property_id']) : ''; // property_code (string)
    $property_name     = isset($input['property_name']) ? trim($input['property_name']) : '';
    $agent_code        = isset($input['agent_code']) ? trim($input['agent_code']) : '';
    $property_type_id  = isset($input['property_type_id']) ? (int)$input['property_type_id'] : 0;
    $country           = isset($input['country']) ? trim($input['country']) : '';
    $state             = isset($input['state']) ? trim($input['state']) : '';
    $city              = isset($input['city']) ? trim($input['city']) : '';
    $address           = isset($input['address']) ? trim($input['address']) : '';
    $contact_name      = isset($input['contact_name']) ? trim($input['contact_name']) : '';
    $contact_phone     = isset($input['contact_phone']) ? trim($input['contact_phone']) : '';
    $status            = isset($input['status']) ? (int)$input['status'] : null; // expected 0 or 1
    $action_type       = isset($input['action_type']) ? trim($input['action_type']) : 'update_all';

    logActivity("REQUEST: Property update | Action={$action_type} | Code={$property_code} | User={$adminId} | Role={$adminRole}");

    // Basic required validations
    if (empty($property_code)) {
        logActivity("ERROR: Missing property_id in update request (User $adminId)");
        json_error("property_id is required.", 400);
    }

    // Validate action_type
    $allowedActions = ['update_all', 'delete', 'restore'];
    if (!in_array($action_type, $allowedActions, true)) {
        logActivity("ERROR: Invalid action type '$action_type' by user $adminId");
        json_error("Invalid action type.", 400);
    }

    // Validate status when provided
    if ($action_type === 'update_all') {
        if (!in_array($status, [0,1], true)) {
            logActivity("ERROR: Invalid status provided by user $adminId");
            json_error("Invalid status. Must be 0 or 1.", 400);
        }
    }

    // ================= START TRANSACTION =================
   $transactionStarted = false;

$conn->begin_transaction();
$transactionStarted = true;

    logActivity("TRANSACTION STARTED for property update (Code={$property_code})");

    // Lock the property row for update and verify it exists
    $check = $conn->prepare("SELECT property_code, status FROM properties WHERE property_code = ? FOR UPDATE");
    if (!$check) {
        throw new Exception("DB prepare failed (check): " . $conn->error);
    }
    $check->bind_param("s", $property_code);
    $check->execute();
    $check->bind_result($existing_code, $existing_status);

    if (!$check->fetch()) {
        $check->close();
        $conn->rollback();
        logActivity("ERROR: Property {$property_code} does not exist (User $adminId)");
        json_error("Property not found.", 404);
    }
    $check->close();

    // Permission check for delete/restore actions
    if (in_array($action_type, ['delete','restore'], true) && $adminRole !== "Super Admin") {
        $conn->rollback();
        logActivity("SECURITY: Unauthorized {$action_type} attempt by user $adminId");
        json_error("You do not have permission to {$action_type} properties.", 403);
    }

    // Route the action
    $resultMessage = '';
    switch ($action_type) {
        case 'update_all':
            $resultMessage = handleFullUpdate(
                $conn,
                $property_code,
                $property_name,
                $agent_code,
                $property_type_id,
                $address,
                $city,
                $state,
                $country,
                $contact_name,
                $contact_phone,
                $adminId,
                $status,
                $adminRole
            );
            break;

        case 'delete':
            $resultMessage = handleStatusUpdate($conn, $property_code, 0, $adminId, $adminRole, "delete");
            break;

        case 'restore':
            $resultMessage = handleStatusUpdate($conn, $property_code, 1, $adminId, $adminRole, "restore");
            break;
    }

    // ================= COMMIT TRANSACTION =================
    $conn->commit();
    logActivity("TRANSACTION COMMITTED: Property Code={$property_code} updated by user $adminId");

    json_success($resultMessage, null, 200);

} catch (Throwable $e) {

    if (!empty($transactionStarted)) {
        try {
            $conn->rollback();
            logActivity("TRANSACTION ROLLED BACK due to error");
        } catch (Throwable $rollbackError) {
            logActivity("ROLLBACK ERROR: " . $rollbackError->getMessage());
        }
    }

    logActivity("EXCEPTION: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
    json_error("An internal error occurred while processing the request.", 500);
}
/**
 * Full update handler
 * Returns: success message string
 *
 * @param mysqli $conn
 * @param string $property_code
 * @param string $property_name
 * @param string $agent_code
 * @param int    $property_type_id
 * @param string $address
 * @param string $city
 * @param string $state
 * @param string $country
 * @param string $contact_name
 * @param string $contact_phone
 * @param int    $adminId
 * @param int    $status
 * @param string $adminRole
 * @return string
 */
function handleFullUpdate($conn, $property_code, $property_name, $agent_code, $property_type_id, $address, $city, $state, $country, $contact_name, $contact_phone, $adminId, $status, $adminRole)
{
    // Validate fields
    if (empty($property_name)) {
        logActivity("ERROR: Missing property_name in full update (Code={$property_code})");
        json_error("Property Name is required.", 400);
    }

    if (!in_array($status, [0, 1], true)) {
        logActivity("ERROR: Invalid status '{$status}' for Code={$property_code}");
        json_error("Invalid status. Must be 0 or 1.", 400);
    }

    // Permission check (example: only super admin can deactivate)
    if ($status == 0 && $adminRole !== "Super Admin") {
        logActivity("SECURITY: Unauthorized deactivate attempt by user $adminId");
        json_error("You do not have permission to deactivate properties.", 403);
    }

    // Validate agent_code if provided (optional: check existence in agents table)
    if (!empty($agent_code)) {
        if (!preg_match('/^[A-Za-z0-9_\-]{4,64}$/', $agent_code)) {
            logActivity("ERROR: Invalid agent_code format '{$agent_code}' for Code={$property_code}");
            json_error("Invalid agent code.", 400);
        }
        // Optional: verify agent exists
        $stmt = $conn->prepare("SELECT agent_code FROM agents WHERE agent_code = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $agent_code);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows === 0) {
                $stmt->close();
                logActivity("ERROR: Agent '{$agent_code}' not found for Code={$property_code}");
                json_error("Agent not found.", 400);
            }
            $stmt->close();
        }
    }

    // Validate property_type_id if provided
    if ($property_type_id <= 0) {
        logActivity("ERROR: Invalid property_type_id '{$property_type_id}' for Code={$property_code}");
        json_error("Invalid property type selected.", 400);
    } else {
        // Optional: verify property type exists
        $stmt = $conn->prepare("SELECT type_id FROM property_type WHERE type_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $property_type_id);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows === 0) {
                $stmt->close();
                logActivity("ERROR: Property type '{$property_type_id}' not found for Code={$property_code}");
                json_error("Property type not found.", 400);
            }
            $stmt->close();
        }
    }

    // Validate contact phone (optional, but basic sanity)
    if (!empty($contact_phone)) {
        $normalizedPhone = preg_replace('/\D+/', '', $contact_phone);
        if (strlen($normalizedPhone) < 7 || strlen($normalizedPhone) > 15) {
            logActivity("ERROR: Invalid contact phone '{$contact_phone}' for Code={$property_code}");
            json_error("Contact phone must be between 7 and 15 digits.", 400);
        }
        $contact_phone = $normalizedPhone;
    }

    // Duplicate check (exclude current record and soft-deleted records)
    // $dup = $conn->prepare("
    //     SELECT property_code FROM properties
    //     WHERE name = ? AND property_code != ?
    //     LIMIT 1
    // ");
    // if (!$dup) {
    //     throw new Exception("DB prepare failed (dup check): " . $conn->error);
    // }
    // $dup->bind_param("ss", $property_name, $property_code);
    // $dup->execute();
    // $dup->store_result();

    // if ($dup->num_rows > 0) {
    //     $dup->close();
    //     logActivity("ERROR: Duplicate property_name '{$property_name}' (Code={$property_code})");
    //     json_error("Another property already uses this name.", 409);
    // }
    // $dup->close();

    // Perform update
    $stmt = $conn->prepare("
        UPDATE properties 
        SET agent_code = ?, property_type_id = ?, name = ?, address = ?, city = ?, state = ?, country = ?, contact_name = ?, contact_phone = ?, updated_at = NOW(), last_updated_by = ?, status = ?
        WHERE property_code = ?
        LIMIT 1
    ");
    if (!$stmt) {
        throw new Exception("DB prepare failed (update): " . $conn->error);
    }

    // Bind types: agent_code(s), property_type_id(i), name(s), address(s), city(s), state(s), country(s), contact_name(s), contact_phone(s), adminId(i), status(i), property_code(s)
    $types = "sisssssssiis";
    $stmt->bind_param(
        $types,
        $agent_code,
        $property_type_id,
        $property_name,
        $address,
        $city,
        $state,
        $country,
        $contact_name,
        $contact_phone,
        $adminId,
        $status,
        $property_code
    );

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new Exception("DB execute failed (update): " . $err);
    }

    $affected = $stmt->affected_rows;
    $stmt->close();

    logActivity("SUCCESS: Full update for property Code={$property_code} | Affected rows: {$affected}");
    logActivity("UPDATE DETAILS: Code={$property_code}, name='{$property_name}', status={$status}, by user={$adminId}");

    return "Property updated successfully!";
}

/**
 * Status update handler (delete/restore)
 * Returns: success message string
 *
 * @param mysqli $conn
 * @param string $property_code
 * @param int    $new_status
 * @param int    $adminId
 * @param string $adminRole
 * @param string $action
 * @return string
 */
function handleStatusUpdate($conn, $property_code, $new_status, $adminId, $adminRole, $action)
{
    // Permission check already done earlier, but double-check
    if ($adminRole !== "Super Admin") {
        logActivity("SECURITY: Unauthorized {$action} attempt by user {$adminId}");
        json_error("You do not have permission to {$action} properties.", 403);
    }

    if ($action === "delete") {
        $query = "
            UPDATE properties 
            SET status = 0, last_updated_by = ?, updated_at = NOW()
            WHERE property_code = ?
            LIMIT 1
        ";
    } else { // restore
        $query = "
            UPDATE properties 
            SET status = 1, last_updated_by = ?, updated_at = NOW()
            WHERE property_code = ?
            LIMIT 1
        ";
    }

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("DB prepare failed (status update): " . $conn->error);
    }

    // adminId (i), property_code (s)
    $stmt->bind_param("is", $adminId, $property_code);

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new Exception("DB execute failed (status update): " . $err);
    }

    $affected = $stmt->affected_rows;
    $stmt->close();

    logActivity("SUCCESS: Property Code={$property_code} {$action} by user {$adminId} | Affected rows: {$affected}");

    return "Property " . ($action === "delete" ? "deactivated" : "restored") . " successfully!";
}
