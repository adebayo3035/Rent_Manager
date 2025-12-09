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

    $adminId = (int)$_SESSION['unique_id'];
    $adminRole = $_SESSION['role'] ?? null;

    // =============== VALIDATE JSON INPUT =================
    $input = json_decode(file_get_contents("php://input"), true);
    if (!$input || json_last_error() !== JSON_ERROR_NONE) {
        logActivity("Invalid JSON input while updating property type by user $adminId");
        json_error("Invalid JSON input.", 400);
    }

    $type_id = isset($input['type_id']) ? (int)$input['type_id'] : null;
    $type_name = isset($input['type_name']) ? trim($input['type_name']) : '';
    $description = isset($input['description']) ? trim($input['description']) : '';
    $status = isset($input['status']) ? (int)$input['status'] : null;
    $action_type = $input['action_type'] ?? 'update_all';

    logActivity("REQUEST: Property type update | Action=$action_type | ID=$type_id | User=$adminId | Role=$adminRole");

    // Validate type_id
    if (!$type_id) {
        logActivity("ERROR: Missing type_id in update request (User $adminId)");
        json_error("type_id is required.", 400);
    }

    // ================= START TRANSACTION =================
    $conn->begin_transaction();
    logActivity("TRANSACTION STARTED for property_type update (ID=$type_id)");

    // Check if type exists
    $check = $conn->prepare("SELECT type_id, status FROM property_type WHERE type_id = ? FOR UPDATE");
    if (!$check) {
        throw new Exception("DB prepare failed (check): " . $conn->error);
    }
    $check->bind_param("i", $type_id);
    $check->execute();
    $check->bind_result($existing_id, $existing_status);
    
    if (!$check->fetch()) {
        $check->close();
        $conn->rollback();
        logActivity("ERROR: Property type $type_id does not exist (User $adminId)");
        json_error("Property type not found.", 404);
    }
    $check->close();

    // Process actions
    $resultMessage = '';
    
    switch ($action_type) {
        case 'update_all':
            $resultMessage = handleFullUpdate($conn, $type_id, $type_name, $description, $status, $adminId, $adminRole);
            break;

        case 'delete':
            $resultMessage = handleStatusUpdate($conn, $type_id, 0, $adminId, $adminRole, "delete");
            break;

        case 'restore':
            $resultMessage = handleStatusUpdate($conn, $type_id, 1, $adminId, $adminRole, "restore");
            break;

        default:
            $conn->rollback();
            logActivity("ERROR: Invalid action type '$action_type' by user $adminId");
            json_error("Invalid action type.", 400);
    }

    // ================= COMMIT TRANSACTION =================
    if (!$conn->commit()) {
        throw new Exception("Transaction commit failed: " . $conn->error);
    }
    
    logActivity("TRANSACTION COMMITTED: Property type ID=$type_id updated by user $adminId");
    
    // Send success response
    json_success($resultMessage, null, 200);

} catch (Throwable $e) {
    // Rollback if transaction is active
    if (isset($conn) && $conn->errno !== 0) {
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
 */
function handleFullUpdate($conn, $type_id, $type_name, $description, $status, $adminId, $adminRole)
{
    // Validate fields
    if (empty($type_name)) {
        logActivity("ERROR: Missing type_name in full update (ID=$type_id)");
        json_error("type_name is required.", 400);
    }

    if (!in_array($status, [0, 1], true)) {
        logActivity("ERROR: Invalid status '$status' for ID=$type_id");
        json_error("Invalid status. Must be 0 or 1.", 400);
    }

    // Permission check
    if ($status == 0 && $adminRole !== "Super Admin") {
        logActivity("SECURITY: Unauthorized deactivate attempt by user $adminId");
        json_error("You do not have permission to deactivate property types.", 403);
    }

    // Duplicate check (exclude current record and soft-deleted records)
    $dup = $conn->prepare("
        SELECT type_id FROM property_type 
        WHERE type_name = ? AND type_id != ? AND deleted_at IS NULL
        LIMIT 1
    ");
    if (!$dup) {
        throw new Exception("DB prepare failed (dup check): " . $conn->error);
    }
    $dup->bind_param("si", $type_name, $type_id);
    $dup->execute();
    $dup->store_result();

    if ($dup->num_rows > 0) {
        $dup->close();
        logActivity("ERROR: Duplicate type_name '$type_name' (ID=$type_id)");
        json_error("Another property type already uses this name.", 409);
    }
    $dup->close();

    // Perform update
    $stmt = $conn->prepare("
        UPDATE property_type 
        SET type_name = ?, description = ?, status = ?, updated_by = ?, updated_at = NOW() 
        WHERE type_id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        throw new Exception("DB prepare failed (update): " . $conn->error);
    }

    $stmt->bind_param("ssiii", $type_name, $description, $status, $adminId, $type_id);
    
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new Exception("DB execute failed: " . $err);
    }

    $affected = $stmt->affected_rows;
    $stmt->close();

    logActivity("SUCCESS: Full update for property type ID=$type_id | Affected rows: $affected");

    // Log the actual update for debugging
    logActivity("UPDATE DETAILS: ID=$type_id, name='$type_name', status=$status, by user=$adminId");

    return "Property type updated successfully!";
}

/**
 * Status update handler (delete/restore)
 * Returns: success message string
 */
function handleStatusUpdate($conn, $type_id, $new_status, $adminId, $adminRole, $action)
{
    // Permission check
    if ($adminRole !== "Super Admin") {
        logActivity("SECURITY: Unauthorized $action attempt by user $adminId");
        json_error("You do not have permission to $action property types.", 403);
    }

    // Prepare appropriate query
    if ($action === "delete") {
        $query = "
            UPDATE property_type 
            SET status = 0, updated_by = ?, updated_at = NOW(), deleted_at = NOW() 
            WHERE type_id = ?
            LIMIT 1
        ";
    } else { // restore
        $query = "
            UPDATE property_type 
            SET status = 1, updated_by = ?, updated_at = NOW(), deleted_at = NULL 
            WHERE type_id = ?
            LIMIT 1
        ";
    }

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("DB prepare failed (status update): " . $conn->error);
    }

    $stmt->bind_param("ii", $adminId, $type_id);
    
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new Exception("DB execute failed (status update): " . $err);
    }

    $affected = $stmt->affected_rows;
    $stmt->close();

    logActivity("SUCCESS: Property type ID=$type_id $action by user $adminId | Affected rows: $affected");

    return "Property type " . ($action === "delete" ? "deactivated" : "restored") . " successfully!";
}