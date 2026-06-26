<?php
// tenant/backend/fees/fetch_applicable_fees.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

// Generate unique request ID for tracking
$requestId = uniqid('fetch_applicable_fees_', true);
logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] ========== START ==========");
logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] Request Time: " . date('Y-m-d H:i:s'));
logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] Request Method: " . $_SERVER['REQUEST_METHOD']);

try {
    // ==================== STEP 1: CHECK AUTHENTICATION ====================
    logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] Step 1: Checking authentication");
    
    if (!isset($_SESSION['tenant_code'])) {
        logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] ERROR: Not logged in - tenant_code not in session");
        json_error("Not logged in", 401);
    }
    logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] Step 1: tenant_code found in session");

    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        $role = $_SESSION['role'] ?? 'not set';
        logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] ERROR: Unauthorized access - Role: {$role}");
        json_error("Unauthorized access", 403);
    }
    logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] Step 1: Role validated - Tenant");

    $tenant_code = $_SESSION['tenant_code'];
    logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] Tenant Code: {$tenant_code}");

    // ==================== STEP 2: GET TENANT APARTMENT ====================
    logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] Step 2: Fetching tenant apartment details");
    
    $apartmentQuery = "
        SELECT apartment_code 
        FROM tenants 
        WHERE tenant_code = ? AND status = 1
        LIMIT 1
    ";
    logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] Apartment Query: {$apartmentQuery}");
    
    $stmt = $conn->prepare($apartmentQuery);
    if (!$stmt) {
        logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] ERROR: Failed to prepare apartment query: " . $conn->error);
        json_error("Database prepare error", 500);
    }
    
    $stmt->bind_param("s", $tenant_code);
    logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] Apartment query bound with tenant_code: {$tenant_code}");
    
    if (!$stmt->execute()) {
        logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] ERROR: Failed to execute apartment query: " . $stmt->error);
        $stmt->close();
        json_error("Database execute error", 500);
    }
    
    $result = $stmt->get_result();
    $tenantData = $result->fetch_assoc();
    $stmt->close();
    
    logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] Tenant data retrieved: " . json_encode($tenantData));

    if (!$tenantData || !$tenantData['apartment_code']) {
        logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] ERROR: No apartment assigned to tenant");
        json_error("No apartment assigned to this tenant", 400);
    }

    $apartment_code = $tenantData['apartment_code'];
    logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] Apartment Code: {$apartment_code}");

    // ==================== STEP 3: FETCH APPLICABLE FEES ====================
    logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] Step 3: Fetching applicable fees");
    
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
            ft.amount as default_amount,
            ft.display_order,
            CASE 
                WHEN ft.amount IS NOT NULL AND ft.amount > 0 THEN ft.amount
                ELSE 0
            END as amount,
            CASE 
                WHEN ft.is_recurring = 1 THEN 'Recurring'
                ELSE 'One-time'
            END as fee_type_display
        FROM fee_types ft
        WHERE ft.status = 1
        AND (ft.is_mandatory = 1 OR ft.is_optional = 1)
        ORDER BY 
            ft.is_mandatory DESC,
            ft.display_order ASC,
            ft.fee_name ASC
    ";

    logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] Fee Types Query: {$query}");

    $result = $conn->query($query);
    
    if (!$result) {
        logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] ERROR: Fee types query failed: " . $conn->error);
        json_error("Failed to fetch fee types", 500);
    }
    
    logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] Fee types query returned " . $result->num_rows . " rows");

    // ==================== STEP 4: PROCESS RESULTS ====================
    logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] Step 4: Processing fee types results");
    
    $applicable_fees = [];
    $row_count = 0;
    
    while ($row = $result->fetch_assoc()) {
        $row_count++;
        
        logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] Processing row {$row_count} - Fee: {$row['fee_name']}");
        
        $fee_entry = [
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
            'fee_type_display' => $row['fee_type_display']
        ];
        
        $applicable_fees[] = $fee_entry;
        
        logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] Fee added: {$row['fee_name']} - Amount: {$row['amount']} - Mandatory: {$row['is_mandatory']}");
    }
    
    logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] Total applicable fees processed: " . count($applicable_fees));

    // ==================== STEP 5: BUILD RESPONSE ====================
    logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] Step 5: Building response");
    
    $mandatory_count = count(array_filter($applicable_fees, function($fee) { return $fee['is_mandatory']; }));
    $optional_count = count(array_filter($applicable_fees, function($fee) { return !$fee['is_mandatory']; }));
    
    logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] Mandatory fees: {$mandatory_count}, Optional fees: {$optional_count}");
    
    $response_data = [
        'applicable_fees' => $applicable_fees,
        'total_count' => count($applicable_fees),
        'mandatory_count' => $mandatory_count,
        'optional_count' => $optional_count
    ];

    logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] ========== SUCCESS ==========");
    json_success($response_data, "Applicable fees retrieved successfully");
    
} catch (Exception $e) {
    logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] ========== ERROR ==========");
    logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] Error Message: " . $e->getMessage());
    logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] Error Code: " . $e->getCode());
    logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] Error File: " . $e->getFile());
    logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] Error Line: " . $e->getLine());
    logActivity("[FETCH_APPLICABLE_FEES] [ID:{$requestId}] Stack Trace: " . $e->getTraceAsString());
    
    json_error("Failed to fetch applicable fees: " . $e->getMessage(), 500);
}
?>