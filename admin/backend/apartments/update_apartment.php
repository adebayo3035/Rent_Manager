<?php
// update_apartment_type.php
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
        logActivity("SECURITY: Unauthorized access attempt to update_apartment_type");
        json_error("Not logged in.", 401);
    }

    $adminId = (int) $_SESSION['unique_id'];
    $adminRole = $_SESSION['role'] ?? null;

    // =============== VALIDATE JSON INPUT =================
    $input = json_decode(file_get_contents("php://input"), true);
    if (!$input || json_last_error() !== JSON_ERROR_NONE) {
        logActivity("Invalid JSON input while updating apartment by user $adminId");
        json_error("Invalid JSON input.", 400);
    }

    // Normalize and trim inputs
    $apartment_code = isset($input['apartment_id']) ? trim($input['apartment_id']) : ''; // apartment_code (string)
    $property_code = isset($input['property_code']) ? trim($input['property_code']) : '';
    $agent_code = isset($input['agent_code']) ? trim($input['agent_code']) : '';
    $apartment_type_id = isset($input['apartment_type_id']) ? (int) $input['apartment_type_id'] : 0;
    $apartment_type_unit = isset($input['apartment_type_unit']) ? (int) $input['apartment_type_unit'] : 0;
    $status = isset($input['status']) ? trim($input['status']) : '';
    $action_type = isset($input['action_type']) ? trim($input['action_type']) : 'update_all';

    logActivity("REQUEST: Apartment update | Action={$action_type} | Code={$apartment_code} | User={$adminId} | Role={$adminRole}");

    // Basic required validations
    if (empty($apartment_code)) {
        logActivity("ERROR: Missing apartment_id in update request (User $adminId)");
        json_error("apartment_id is required.", 400);
    }

    // Validate action_type
    $allowedActions = ['update_all', 'delete', 'restore'];
    if (!in_array($action_type, $allowedActions, true)) {
        logActivity("ERROR: Invalid action type '$action_type' by user $adminId");
        json_error("Invalid action type.", 400);
    }

    // Validate status when provided
    if ($action_type === 'update_all') {
        if (!in_array($status, ['0', '1'], true)) {
            logActivity("ERROR: Invalid status provided by user $adminId");
            json_error("Invalid status. Must be Active or Inactive.", 400);
        }
    }

    // ================= START TRANSACTION =================
    $transactionStarted = false;

    $conn->begin_transaction();
    $transactionStarted = true;

    logActivity("TRANSACTION STARTED for apartment update (Code={$apartment_code})");

    // Lock the apartment row for update and verify it exists
    if ($action_type === 'update_all') {
        $check = $conn->prepare("
        SELECT apartment_code, status 
        FROM apartments 
        WHERE apartment_code = ? AND property_code = ?
        FOR UPDATE
    ");
        $check->bind_param("ss", $apartment_code, $property_code);
    } else {
        // delete / restore
        $check = $conn->prepare("
        SELECT apartment_code, status 
        FROM apartments 
        WHERE apartment_code = ?
        FOR UPDATE
    ");
        $check->bind_param("s", $apartment_code);
    }

    $check->execute();
    $check->bind_result($existing_code, $existing_status);

    if (!$check->fetch()) {
        $check->close();
        $conn->rollback();
        json_error("Apartment not found.", 404);
    }
    $check->close();


    // Permission check for delete/restore actions
    if (in_array($action_type, ['delete', 'restore'], true) && $adminRole !== "Super Admin") {
        $conn->rollback();
        logActivity("SECURITY: Unauthorized {$action_type} attempt by user $adminId");
        json_error("You do not have permission to {$action_type} apartments.", 403);
    }

    // Route the action
    $resultMessage = '';
    switch ($action_type) {
        case 'update_all':
            $resultMessage = handleFullUpdate(
                $conn,
                $apartment_code,
                $property_code,
                $agent_code,
                $apartment_type_id,
                $apartment_type_unit,
                $adminId,
                $status,
                $adminRole
            );
            break;

        case 'delete':
            $resultMessage = handleStatusUpdate($conn, $apartment_code, 0, $adminId, $adminRole, "delete");
            break;

        case 'restore':
            $resultMessage = handleStatusUpdate($conn, $apartment_code, 1, $adminId, $adminRole, "restore");
            break;
    }

    // ================= COMMIT TRANSACTION =================
    $conn->commit();
    logActivity("TRANSACTION COMMITTED: Apartment Code={$apartment_code} updated by user $adminId");

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
 * @param string $apartment_code
 * @param string $property_code
 * @param string $agent_code
 * @param int    $apartment_type_id
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
function handleFullUpdate($conn, $apartment_code, $property_code, $agent_code, $apartment_type_id, $apartment_type_unit, $adminId, $status, $adminRole)
{
    // Validate fields
    if (empty($property_code)) {
        logActivity("ERROR: Missing property Code in full update (Code={$apartment_code})");
        json_error("Property Code or Name is required.", 400);
    }

    if (!in_array($status, ['0', '1'], true)) {
        logActivity("ERROR: Invalid status '{$status}' for Code={$apartment_code}");
        json_error("Invalid status. Must be 0 or 1.", 400);
    }

    // Permission check (example: only super admin can deactivate)
    if ($status == 0 && $adminRole !== "Super Admin") {
        logActivity("SECURITY: Unauthorized deactivate attempt by user $adminId");
        json_error("You do not have permission to deactivate apartments.", 403);
    }

    // Validate agent_code if provided (optional: check existence in agents table)
    if (!empty($agent_code)) {
        if (!preg_match('/^[A-Za-z0-9_\-]{4,64}$/', $agent_code)) {
            logActivity("ERROR: Invalid agent_code format '{$agent_code}' for Code={$apartment_code}");
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
                logActivity("ERROR: Agent '{$agent_code}' not found for Code={$apartment_code}");
                json_error("Agent not found.", 400);
            }
            $stmt->close();
        }
    }

    // Validate apartment_type_id if provided
    if ($apartment_type_id <= 0) {
        logActivity("ERROR: Invalid apartment_type_id '{$apartment_type_id}' for Code={$apartment_code}");
        json_error("Invalid apartment type selected.", 400);
    } else {
        // Optional: verify apartment type exists
        $stmt = $conn->prepare("SELECT type_id FROM property_type WHERE type_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $apartment_type_id);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows === 0) {
                $stmt->close();
                logActivity("ERROR: Apartment type '{$apartment_type_id}' not found for Code={$apartment_code}");
                json_error("Apartment type not found.", 400);
            }
            $stmt->close();
        }
    }

    // Perform update
    $stmt = $conn->prepare("
        UPDATE apartments 
        SET agent_code = ?, apartment_type_id = ?, apartment_type_unit = ?, updated_at = NOW(), last_updated_by = ?, status = ?
        WHERE apartment_code = ?
        LIMIT 1
    ");
    if (!$stmt) {
        throw new Exception("DB prepare failed (update): " . $conn->error);
    }

    // Bind types: agent_code(s), apartment_type_id(i), name(s), address(s), city(s), state(s), country(s), contact_name(s), contact_phone(s), adminId(i), status(i), apartment_code(s)
    $types = "siisss";
    $stmt->bind_param(
        $types,
        $agent_code,
        $apartment_type_id,
        $apartment_type_unit,
        $adminId,
        $status,
        $apartment_code
    );

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new Exception("DB execute failed (update): " . $err);
    }

    $affected = $stmt->affected_rows;
    $stmt->close();

    logActivity("SUCCESS: Full update for apartment Code={$apartment_code} | Affected rows: {$affected}");
    logActivity("UPDATE DETAILS: Code={$apartment_code}, Property Code='{$property_code}', status={$status}, by user={$adminId}");

    return "Apartment updated successfully!";
}

/**
 * Status update handler (delete/restore)
 * Returns: success message string
 *
 * @param mysqli $conn
 * @param string $apartment_code
 * @param int    $new_status
 * @param int    $adminId
 * @param string $adminRole
 * @param string $action
 * @return string
 */
function handleStatusUpdate($conn, $apartment_code, $new_status, $adminId, $adminRole, $action)
{
    // Permission check already done earlier, but double-check
    if ($adminRole !== "Super Admin") {
        logActivity("SECURITY: Unauthorized {$action} attempt by user {$adminId}");
        json_error("You do not have permission to {$action} apartments.", 403);
    }

    if ($action === "delete") {
        $query = "
            UPDATE apartments 
            SET status = 0, last_updated_by = ?, updated_at = NOW()
            WHERE apartment_code = ?
            LIMIT 1
        ";
    } else { // restore
        $query = "
            UPDATE apartments 
            SET status = 1, last_updated_by = ?, updated_at = NOW()
            WHERE apartment_code = ?
            LIMIT 1
        ";
    }

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("DB prepare failed (status update): " . $conn->error);
    }

    // adminId (i), apartment_code (s)
    $stmt->bind_param("is", $adminId, $apartment_code);

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new Exception("DB execute failed (status update): " . $err);
    }

    $affected = $stmt->affected_rows;
    $stmt->close();

    logActivity("SUCCESS: Apartment Code={$apartment_code} {$action} by user {$adminId} | Affected rows: {$affected}");

    return "Apartment " . ($action === "delete" ? "deactivated" : "restored") . " successfully!";
}
