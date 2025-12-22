<?php
// update_client.php

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php'; // json_success, json_error, sanitize_inputs, logActivity
session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {

    // ================= AUTH CHECK =================
    if (!isset($_SESSION['unique_id'])) {
        logActivity("SECURITY: Unauthorized access attempt to update_client");
        json_error("Not logged in.", 401);
    }

    $adminId   = $_SESSION['unique_id'];
    $adminRole = $_SESSION['role'] ?? "Staff";

    logActivity("UPDATE_AGENT API CALLED by Admin=$adminId Role=$adminRole");

    // ================= RATE LIMIT =================
    rateLimit("update_client", 20, 60);
    logActivity("Rate limit OK for admin $adminId");

    // ================= METHOD CHECK =================
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        logActivity("Invalid HTTP method for update_client: {$_SERVER['REQUEST_METHOD']}");
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
    if (empty($input['client_code'])) {
        logActivity("Validation failed: missing client_code");
        json_error("client_code is required", 400);
    }

    // Extract fields
    $client_code = $input['client_code'];
    $action_type = $input['action_type'] ?? 'update_all';

    logActivity("REQUEST RECEIVED | Action=$action_type | Client=$client_code | By Admin=$adminId");

    // ================= TRANSACTION START =================
    $conn->begin_transaction();
    logActivity("TRANSACTION START for client_code=$client_code");

    // ================= CHECK AGENT EXISTS =================
    $check = $conn->prepare("SELECT client_code, status FROM clients WHERE client_code = ?");
    $check->bind_param("s", $client_code);
    $check->execute();
    $check->bind_result($existing_code, $existing_status);

    if (!$check->fetch()) {
        logActivity("ERROR: Client not found (Client Code: $client_code)");
        $conn->rollback();
        json_error("Client not found.", 404);
    }
    $check->close();

    // ================= ACTION SWITCH =================
    $response = null;

    switch ($action_type) {

        case 'update_all':
            $response = fullUpdate($conn, $input, $client_code, $adminId, $adminRole);
            break;

        case 'delete':
            $response = statusUpdate($conn, $client_code, 0, $adminId, $adminRole, "delete");
            break;

        case 'restore':
            $response = statusUpdate($conn, $client_code, 1, $adminId, $adminRole, "restore");
            break;

        default:
            logActivity("ERROR: Invalid action_type '$action_type' provided by Admin=$adminId");
            $conn->rollback();
            json_error("Invalid action_type.", 400);
    }

    // ================= COMMIT =================
    $conn->commit();
    logActivity("COMMIT SUCCESS for client_code=$client_code Action=$action_type");

    json_success($response['message'], 200);

} catch (Throwable $e) {

    logActivity("EXCEPTION: {$e->getMessage()} | FILE={$e->getFile()} | LINE={$e->getLine()}");

    // Only rollback if transaction is active
    if ($conn->errno === 0) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
    }

    json_error("Internal server error.", 500);
}



/* ============================================================
   FULL UPDATE
   ============================================================ */
function fullUpdate($conn, $input, $client_code, $adminId, $adminRole)
{
    logActivity("FULL UPDATE START for Client=$client_code by Admin=$adminId");

    // Required fields
    $required = ['firstname', 'lastname', 'email', 'phone', 'gender', 'status'];
    foreach ($required as $f) {
        if (!isset($input[$f]) || trim($input[$f]) === '') {
            logActivity("Validation failed: '$f' missing during full update for $client_code");
            json_error("$f is required", 400);
        }
    }

    // Extract sanitized
    $firstname = trim($input['firstname']);
    $lastname = trim($input['lastname']);
    $email = $input['email'];
    $phone = $input['phone'];
    $address = $input['address'] ?? '';
    $gender = ucfirst(strtolower($input['gender']));
    $status = intval($input['status']);

    // Email validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        logActivity("Invalid email '$email' during update for $client_code");
        json_error("Invalid email.", 400);
    }

    // Phone validation
    if (!validate_phone($phone)) {
        logActivity("Invalid phone '$phone' during update for $client_code");
        json_error("Invalid phone number.", 400);
    }

    // Gender validation
    if (!in_array($gender, ['Male', 'Female'], true)) {
        logActivity("Invalid gender '$gender' for client=$client_code");
        json_error("Invalid gender.", 400);
    }

    // Status validation
    if (!in_array($status, [0, 1], true)) {
        logActivity("Invalid status '$status' for client=$client_code");
        json_error("Invalid status.", 400);
    }

    // Only Super Admin can deactivate
    if ($status == 0 && $adminRole !== "Super Admin") {
        logActivity("UNAUTHORIZED deactivate attempt by Admin=$adminId");
        json_error("You cannot deactivate clients.", 403);
    }

    // Duplicate check: phone or email belongs to another client
    $dup = $conn->prepare("
        SELECT client_code FROM clients
        WHERE (phone = ? OR email = ?) AND client_code != ?
        LIMIT 1
    ");
    $dup->bind_param("sss", $phone, $email, $client_code);
    $dup->execute();
    $dup->store_result();

    if ($dup->num_rows > 0) {
        logActivity("DUPLICATE FOUND during update for client=$client_code");
        json_error("Phone or Email belongs to another client.", 409);
    }
    $dup->close();

    // Perform update
    $stmt = $conn->prepare("
        UPDATE clients SET
            firstname = ?, lastname = ?, email = ?, phone = ?,
            address = ?, gender = ?, status = ?, 
            last_updated_by = ?, date_updated = NOW()
        WHERE client_code = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        "ssssssiss",
        $firstname, $lastname, $email, $phone,
        $address, $gender, $status,
        $adminId, $client_code
    );

    $stmt->execute();
    $stmt->close();

    logActivity("FULL UPDATE SUCCESS for Client=$client_code");

    return [
        "success" => true,
        "message" => "Client updated successfully."
    ];
}



/* ============================================================
   STATUS UPDATE (delete / restore)
   ============================================================ */
function statusUpdate($conn, $client_code, $new_status, $adminId, $adminRole, $action)
{
    logActivity("STATUS UPDATE START ($action) for Client=$client_code by Admin=$adminId");

    if ($adminRole !== "Super Admin") {
        logActivity("UNAUTHORIZED $action attempt by Admin=$adminId");
        json_error("You do not have permission to modify status.", 403);
    }

    $stmt = $conn->prepare("
        UPDATE clients
        SET status = ?, last_updated_by = ?, date_updated = NOW()
        WHERE client_code = ?
        LIMIT 1
    ");

    $stmt->bind_param("iss", $new_status, $adminId, $client_code);
    $stmt->execute();

    logActivity("STATUS UPDATE SUCCESS ($action) for Client=$client_code | NewStatus=$new_status");

    return [
        "success" => true,
        "message" => "Client " . ($action === "delete" ? "deactivated" : "restored") . " successfully."
    ];
}

