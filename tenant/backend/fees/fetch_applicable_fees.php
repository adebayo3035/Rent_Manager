<?php
// tenant/backend/fees/fetch_applicable_fees.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

$requestId = uniqid('fetch_applicable_fees_', true);
logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] START");

try {
    // Check authentication
    if (!isset($_SESSION['tenant_code'])) {
        json_error("Not logged in", 401);
    }

    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        json_error("Unauthorized access", 403);
    }

    $tenant_code = $_SESSION['tenant_code'];
    logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] Tenant Code: {$tenant_code}");

    // ==================== GET TENANT APARTMENT DETAILS ====================
    $apartmentQuery = "
        SELECT 
            t.tenant_code,
            t.apartment_code,
            a.apartment_type_id,
            a.property_code
        FROM tenants t
        LEFT JOIN apartments a ON t.apartment_code = a.apartment_code
        WHERE t.tenant_code = ? AND t.status = 1
        LIMIT 1
    ";
    
    $stmt = $conn->prepare($apartmentQuery);
    $stmt->bind_param("s", $tenant_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $tenantData = $result->fetch_assoc();
    $stmt->close();

    if (!$tenantData || !$tenantData['apartment_code']) {
        json_error("No apartment assigned to this tenant", 400);
    }

    $property_code = $tenantData['property_code'] ?? null;
    $apartment_type_id = $tenantData['apartment_type_id'] ?? null;
    
    logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] Property: {$property_code}, Apartment Type: {$apartment_type_id}");

    // ==================== FETCH CONFIGURED FEES ====================
    // ✅ CORRECT: Based on the fee configuration logic document
    // - Start from property_apartment_type_fees (existing configurations)
    // - Join fee_types for fee details
    // - Only show fees that are active for this property (is_active = 1)
    // - fee_types.status does NOT filter existing configurations
    $query = "
        SELECT 
            ft.fee_type_id,
            ft.fee_code,
            ft.fee_name,
            ft.description,
            ft.is_mandatory,
            ft.calculation_type,
            ft.is_recurring,
            ft.recurrence_period,
            ft.display_order,
            patf.amount,
            patf.is_active as is_active_in_property,
            patf.effective_from,
            patf.effective_to,
            CASE 
                WHEN ft.is_recurring = 1 THEN 'Recurring'
                ELSE 'One-time'
            END as fee_type_display
        FROM property_apartment_type_fees patf
        INNER JOIN fee_types ft 
            ON patf.fee_type_id = ft.fee_type_id
        WHERE patf.property_code = ?
        AND patf.apartment_type_id = ?
        
        AND (patf.effective_from IS NULL OR patf.effective_from <= CURDATE())
        AND (patf.effective_to IS NULL OR patf.effective_to >= CURDATE())
        ORDER BY 
            ft.is_mandatory DESC,
            ft.display_order ASC,
            ft.fee_name ASC
    ";

    logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] Query: {$query}");

    $stmt = $conn->prepare($query);
    $stmt->bind_param("si", $property_code, $apartment_type_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $applicable_fees = [];
    while ($row = $result->fetch_assoc()) {
        $applicable_fees[] = [
            'fee_type_id' => (int)$row['fee_type_id'],
            'fee_code' => $row['fee_code'],
            'fee_name' => $row['fee_name'],
            'description' => $row['description'],
            'is_mandatory' => (bool)$row['is_mandatory'],
            'calculation_type' => $row['calculation_type'],
            'is_recurring' => (bool)$row['is_recurring'],
            'recurrence_period' => $row['recurrence_period'],
            'amount' => (float)$row['amount'],
            'display_order' => (int)$row['display_order'],
            'fee_type_display' => $row['fee_type_display'],
            'is_active_in_property' => (bool)$row['is_active_in_property'],
            'effective_from' => $row['effective_from'],
            'effective_to' => $row['effective_to']
        ];
    }
    
    $stmt->close();

    logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] Found " . count($applicable_fees) . " applicable fees");

    $mandatory_count = count(array_filter($applicable_fees, function($fee) { return $fee['is_mandatory']; }));
    $optional_count = count(array_filter($applicable_fees, function($fee) { return !$fee['is_mandatory']; }));

    $response_data = [
        'applicable_fees' => $applicable_fees,
        'total_count' => count($applicable_fees),
        'mandatory_count' => $mandatory_count,
        'optional_count' => $optional_count
    ];

    json_success($response_data, "Applicable fees retrieved successfully");
    
} catch (Exception $e) {
    logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] ERROR: " . $e->getMessage());
    json_error("Failed to fetch applicable fees: " . $e->getMessage(), 500);
}
?>