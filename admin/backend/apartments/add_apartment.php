<?php
// add_apartment.php — Production-grade implementation

header('Content-Type: application/json; charset=utf-8');

define('CSRF_FORM_NAME', 'add_apartment_form');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';   // added for centralized auth using requireAuth function

$auth = requireAuth([
    'method' => 'POST',
    'rate_key' => 'add_apartment',
    'rate_limit' => [10, 60],
    'csrf' => [
        'enabled' => true,
        'form_name' => 'add_apartment_form'
    ],
    'roles' => ['Super Admin', 'Admin']
]);

$userId = $auth['user_id'];
$userRole = $auth['role'];

logActivity("Authenticated user: {$userId} | Role: {$userRole}");


try {

    // ------------------------- INPUT SANITIZATION -------------------------
    $inputs = sanitize_inputs([
        'property_code'         => $_POST['apartment_property_code'] ?? '',
        'agent_code'            => $_POST['apartment_agent_code'] ?? '',
        'apartment_type_id'     => $_POST['apartment_type'] ?? '',
        'apartment_type_unit'   => $_POST['apartment_type_unit'] ?? '',
    ]);

    // ------------------------- REQUIRED FIELD VALIDATION -------------------------
    foreach ($inputs as $key => $value) {
        if ($value === '') {
            json_error("Missing required field: {$key}", 400);
        }
    }

    $propertyCode   = $inputs['property_code'];
    $agentCode      = $inputs['agent_code'];
    $typeId         = (int) $inputs['apartment_type_id'];
    $typeUnit       = (int) $inputs['apartment_type_unit'];

    // ------------------------- DB CONNECTION CHECK -------------------------
    if (!isset($conn) || $conn->connect_errno) {
        json_error("Database connection error", 500);
    }

    // ------------------------- BEGIN TRANSACTION -------------------------
    $conn->begin_transaction();
    logActivity("Transaction started for apartment onboarding");

    // ------------------------- DUPLICATE APARTMENT CHECK -------------------------
    $dupSql = "
        SELECT apartment_code
        FROM apartments
        WHERE property_code = ?
          AND apartment_type_id = ?
          AND apartment_type_unit = ?
        LIMIT 1
    ";

    $dupStmt = $conn->prepare($dupSql);
    $dupStmt->bind_param("sii", $propertyCode, $typeId, $typeUnit);
    $dupStmt->execute();
    $dupStmt->store_result();

    if ($dupStmt->num_rows > 0) {
        $dupStmt->close();
        $conn->rollback();

        json_error(
            "This apartment already exists under the selected property.",
            409
        );
    }
    $dupStmt->close();

    // ------------------------- APARTMENT CODE -------------------------
    $apartmentCode = $propertyCode . "APT-UNIT" . $typeUnit;


    // ------------------------- DB INSERT -------------------------
    $insertSql = "
        INSERT INTO apartments
        (
            apartment_code,
            property_code,
            agent_code,
            apartment_type_id,
            apartment_type_unit,
            created_by,
            created_at
        )
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ";

    $stmt = $conn->prepare($insertSql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    $stmt->bind_param(
        "sssiis",
        $apartmentCode,
        $propertyCode,
        $agentCode,
        $typeId,
        $typeUnit,
        $userId
    );

    if (!$stmt->execute()) {
        throw new Exception("Insert failed: " . $stmt->error);
    }

    $stmt->close();

    // ------------------------- COMMIT -------------------------
    $conn->commit();
    logActivity("Apartment created successfully | Code: {$apartmentCode}");

    // ------------------------- SUCCESS RESPONSE -------------------------
    json_success(
        "Apartment added successfully",
        [
            "apartment_code" => $apartmentCode
        ],
        201
    );

} catch (Throwable $e) {

    // ------------------------- ROLLBACK -------------------------
    if (isset($conn)) {
        $conn->rollback();
    }

    logActivity(
        "Apartment onboarding failed: {$e->getMessage()} |
         Line: {$e->getLine()}"
    );

    json_error("Failed to add apartment. Please try again.", 500);
}
