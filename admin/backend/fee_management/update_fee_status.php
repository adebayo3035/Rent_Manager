<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';

if (!isset($_SESSION))
    session_start();
rateLimiter();

try {
    // Check authentication
    $auth = requireAuth([
        'method' => 'POST',
        'roles' => ['Super Admin', 'Admin']
    ]);

    $input = json_decode(file_get_contents('php://input'), true);
    
    $propertyCode = $input['property_code'] ?? '';
    $apartmentTypeId = isset($input['apartment_type_id']) ? (int)$input['apartment_type_id'] : 0;
    $feeTypeId = isset($input['fee_type_id']) ? (int)$input['fee_type_id'] : 0;
    $action = $input['action'] ?? ''; // 'deactivate' or 'reactivate'

    if (empty($propertyCode) || !$apartmentTypeId || !$feeTypeId || empty($action)) {
        json_error('Missing required parameters', 400);
    }

    if (!in_array($action, ['deactivate', 'reactivate'])) {
        json_error('Invalid action. Must be "deactivate" or "reactivate"', 400);
    }

    // Set is_active based on action
    $isActive = ($action === 'reactivate') ? 1 : 0;
    $actionLabel = ($action === 'reactivate') ? 'reactivated' : 'deactivated';
    $userId = $_SESSION['unique_id'] ?? 0;

    // Update the fee status
    $query = "
        UPDATE property_apartment_type_fees 
        SET is_active = ?,
            updated_at = NOW(),
            updated_by = ?
        WHERE property_code = ? 
        AND apartment_type_id = ? 
        AND fee_type_id = ?
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        json_error('Database prepare error: ' . $conn->error, 500);
    }

    // FIXED: 5 placeholders -> 5 type characters: i i s i i
    $stmt->bind_param(
        "iisii",  // 5 characters
        $isActive,              // i - 1
        $userId,                // i - 2
        $propertyCode,          // s - 3
        $apartmentTypeId,       // i - 4
        $feeTypeId              // i - 5
    );
    
    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }

    if ($stmt->affected_rows > 0) {
        logActivity("Fee {$actionLabel} - Property: {$propertyCode}, Apartment Type: {$apartmentTypeId}, Fee Type: {$feeTypeId}");
        json_success(null, "Fee {$actionLabel} successfully");
    } else {
        // Check if the record exists
        $checkQuery = "
            SELECT fee_id, is_active FROM property_apartment_type_fees 
            WHERE property_code = ? AND apartment_type_id = ? AND fee_type_id = ?
        ";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bind_param("sii", $propertyCode, $apartmentTypeId, $feeTypeId);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        $existing = $checkResult->fetch_assoc();
        $checkStmt->close();

        if (!$existing) {
            json_error('Fee configuration not found', 404);
        } else {
            json_error('Fee is already ' . ($existing['is_active'] == 1 ? 'active' : 'inactive'), 400);
        }
    }

} catch (Exception $e) {
    logActivity("Error updating fee status: " . $e->getMessage());
    json_error('Failed to update fee status: ' . $e->getMessage(), 500);
}
?>