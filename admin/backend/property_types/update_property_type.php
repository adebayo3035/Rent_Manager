<?php
// update_property_type.php

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php'; // json_success, json_error, logActivity()

session_start();

// Enable mysqli exception mode
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {

    // ================= AUTH CHECK =================
    if (!isset($_SESSION['unique_id'])) {
        logActivity("SECURITY: Unauthorized access attempt to update_property_type");
        json_error("Not logged in.", 401);
    }

    $adminId  = $_SESSION['unique_id'];
    $adminRole = $_SESSION['role'] ?? null;

    // =============== VALIDATE JSON INPUT =================
    $input = json_decode(file_get_contents("php://input"), true);
    if (!$input || json_last_error() !== JSON_ERROR_NONE) {
        logActivity("Invalid JSON input while updating property type by user $adminId");
        json_error("Invalid JSON input.", 400);
    }

    $type_id = $input['id'] ?? null;
    $type_name = trim($input['type_name'] ?? '');
    $description = trim($input['description'] ?? '');
    $status = $input['status'] ?? null;
    $action_type = $input['action_type'] ?? 'update_all';
    $class_type = $input['class_type'] ?? null;

    logActivity("REQUEST RECEIVED: Property type update | Action=$action_type | ID=$type_id | User=$adminId (Role=$adminRole)");

    // Validate type_id
    if (!$type_id) {
        logActivity("ERROR: Missing type_id in update request (User $adminId)");
        json_error("type_id is required.", 400);
    }

    // ================= START TRANSACTION =================
    $conn->begin_transaction();
    logActivity("TRANSACTION STARTED for property_type update (ID=$type_id) by user $adminId");

    // Check if type exists
    $check = $conn->prepare("SELECT type_id, status FROM property_type WHERE type_id = ?");
    $check->bind_param("i", $type_id);
    $check->execute();
    $check->bind_result($existing_id, $existing_status);

    if (!$check->fetch()) {
        logActivity("ERROR: Property type $type_id does not exist (User $adminId)");
        $conn->rollback();
        json_error("Property type not found.", 404);
    }
    $check->close();

    // Process actions
    switch ($action_type) {

        case 'update_all':
            fullUpdate($conn, $type_id, $type_name, $description, $status, $adminId, $adminRole);
            break;

        case 'delete':
            statusUpdate($conn, $type_id, 0, $adminId, $adminRole, "delete");
            break;

        case 'restore':
            statusUpdate($conn, $type_id, 1, $adminId, $adminRole, "restore");
            break;

        default:
            logActivity("ERROR: Invalid action type '$action_type' by user $adminId");
            $conn->rollback();
            json_error("Invalid action type.", 400);
    }

    // ================= COMMIT TRANSACTION =================
    $conn->commit();
    logActivity("TRANSACTION COMMITTED successfully for property_type ID=$type_id by user $adminId");

} catch (Throwable $e) {

    // Roll back anything pending
    if ($conn->errno !== 0) {
        $conn->rollback();
    }

    logActivity("EXCEPTION CAUGHT: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
    json_error("An internal error occurred while processing the request.", 500);
}


/** ============================================================
 *  FULL UPDATE HANDLER
 * ============================================================
*/
function fullUpdate($conn, $type_id, $type_name, $description, $status, $adminId, $adminRole)
{
    // Validate fields
    if (empty($type_name)) {
        logActivity("ERROR: Missing type_name in full update (ID=$type_id, User $adminId)");
        json_error("type_name is required.", 400);
    }

    if (!in_array($status, [0, 1], true)) {
        logActivity("ERROR: Invalid status '$status' for ID=$type_id (User $adminId)");
        json_error("Invalid status. Must be 0 or 1.", 400);
    }

    // Permission check
    if ($status == 0 && $adminRole !== "Super Admin") {
        logActivity("SECURITY: Unauthorized deactivate attempt by user $adminId");
        json_error("You do not have permission to deactivate property types.", 403);
    }

    // Duplicate check
    $dup = $conn->prepare("
        SELECT type_id FROM property_type
        WHERE type_name = ? AND type_id != ? AND deleted_at IS NULL
    ");
    $dup->bind_param("si", $type_name, $type_id);
    $dup->execute();
    $dup->store_result();

    if ($dup->num_rows > 0) {
        logActivity("ERROR: Duplicate type_name '$type_name' during update (ID=$type_id)");
        json_error("Another property type already uses this name.", 409);
    }
    $dup->close();

    // Update
    $stmt = $conn->prepare("
        UPDATE property_type
        SET type_name = ?, description = ?, status = ?, updated_by = ?, updated_at = NOW()
        WHERE type_id = ?
    ");
    $stmt->bind_param("ssisi", $type_name, $description, $status, $adminId, $type_id);
    $stmt->execute();
    $stmt->close();

    logActivity("SUCCESS: Full update completed for property type ID=$type_id by User $adminId");

    json_success("Property type updated successfully!", 200);
}


/** ============================================================
 *  STATUS UPDATE HANDLER  (delete / restore)
 * ============================================================
*/
function statusUpdate($conn, $type_id, $new_status, $adminId, $adminRole, $action)
{
    // Permission check
    if ($adminRole !== "Super Admin") {
        logActivity("SECURITY: Unauthorized $action attempt by user $adminId");
        json_error("You do not have permission to $action property types.", 403);
    }

    // Prepare query
    if ($action === "delete") {
        $stmt = $conn->prepare("
            UPDATE property_type
            SET status = 0, updated_by = ?, updated_at = NOW(), deleted_at = NOW()
            WHERE type_id = ?
        ");
    } else {
        $stmt = $conn->prepare("
            UPDATE property_type
            SET status = 1, updated_by = ?, updated_at = NOW(), deleted_at = NULL
            WHERE type_id = ?
        ");
    }

    $stmt->bind_param("ii", $adminId, $type_id);
    $stmt->execute();
    $stmt->close();

    logActivity("SUCCESS: Property type ID=$type_id $action successfully by user $adminId");

    json_success("Property type " . ($action === "delete" ? "deactivated" : "restored") . " successfully!", 200);
}
