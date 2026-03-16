<?php
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
session_start();

header('Content-Type: application/json');

// Helper function to get client IP and user agent for better logging
function getRequestDetails() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown IP';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown UA';
    return "IP: $ip | UA: " . substr($userAgent, 0, 100);
}

try {
    // Log the start of request with all parameters
    $requestDetails = getRequestDetails();
    $action = isset($_GET['action']) ? $_GET['action'] : 'fetch';
    logActivity("Payment API Request Started - Action: $action | Parameters: " . json_encode($_GET) . " | $requestDetails");

    // Authentication check
    if (!isset($_SESSION['unique_id'])) {
        logActivity("SECURITY ALERT: Unauthorized payment access attempt - No session found | $requestDetails");
        echo json_encode(["success" => false, "message" => "Not logged in."]);
        exit();
    }

    $adminId = $_SESSION['unique_id'];
    $userRole = $_SESSION['role'] ?? 'Admin';
    logActivity("Authenticated user - ID: $adminId | Role: $userRole | $requestDetails");

    if (!$conn) {
        logActivity("CRITICAL ERROR: Database connection failed for payments - Connection object is null | $requestDetails");
        echo json_encode(["success" => false, "message" => "Database connection error."]);
        exit();
    }

    // Log database connection success
    logActivity("Database connection established successfully - Host: " . ($conn->host_info ?? 'Unknown'));

    // Route to appropriate function with logging
    logActivity("Routing to action: $action");
    
    switch ($action) {
        case 'fetch':
            fetchPayments($conn, $adminId, $userRole);
            break;
        case 'fetch_single':
            fetchSinglePayment($conn);
            break;
        case 'create':
            logActivity("Initiating payment creation - User: $adminId | POST Data: " . json_encode($_POST));
            createPayment($conn, $adminId);
            break;
        case 'update':
            logActivity("Initiating payment update - User: $adminId | POST Data: " . json_encode($_POST));
            updatePayment($conn, $adminId);
            break;
        case 'delete':
            logActivity("Initiating payment deletion - User: $adminId | POST Data: " . json_encode($_POST));
            deletePayment($conn, $adminId);
            break;
        case 'record_payment':
            logActivity("Initiating quick payment recording - User: $adminId | POST Data: " . json_encode($_POST));
            recordPayment($conn, $adminId);
            break;
        case 'generate_invoice':
            logActivity("Initiating invoice generation - User: $adminId | GET Data: " . json_encode($_GET));
            generateInvoice($conn, $adminId);
            break;
        case 'get_statistics':
            logActivity("Initiating statistics fetch - User: $adminId");
            getPaymentStatistics($conn);
            break;
        default:
            logActivity("WARNING: Invalid action attempted - Action: $action | User: $adminId");
            echo json_encode(["success" => false, "message" => "Invalid action."]);
    }

    $conn->close();
    logActivity("Payment API Request Completed Successfully - Action: $action");

} catch (Exception $e) {
    // Log detailed error information
    $errorDetails = [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ];
    
    logActivity("CRITICAL ERROR in Payment API: " . json_encode($errorDetails));
    
    if (isset($conn) && $conn->connect_errno == 0) {
        $conn->close();
        logActivity("Database connection closed after error");
    }
    
    echo json_encode([
        "success" => false, 
        "message" => "An unexpected error occurred. Please try again later."
    ]);
    exit();
}

// ==================== FUNCTIONS ====================

function fetchPayments($conn, $adminId, $userRole) {
    logActivity("Starting fetchPayments() - User: $adminId | Role: $userRole");
    
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;
    
    logActivity("Fetch parameters - Page: $page | Limit: $limit | Offset: $offset");

    // --- FILTERS ---
    $tenantId = isset($_GET['tenant_id']) ? trim($_GET['tenant_id']) : null;
    $propertyId = isset($_GET['property_code']) ? trim($_GET['property_code']) : null;
    $apartmentId = isset($_GET['apartment_id']) ? trim($_GET['apartment_id']) : null;
    $paymentStatus = isset($_GET['payment_status']) ? trim($_GET['payment_status']) : null;
    $paymentMethod = isset($_GET['payment_method']) ? trim($_GET['payment_method']) : null;
    $dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : null;
    $dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : null;
    $search = isset($_GET['search']) ? trim($_GET['search']) : null;

    logActivity("Filters applied - Tenant: $tenantId | Property: $propertyId | Apartment: $apartmentId | Status: $paymentStatus | Method: $paymentMethod | Date From: $dateFrom | Date To: $dateTo | Search: $search");

    $whereClauses = ["p.is_deleted = 0"];
    $params = [];
    $types = '';

    if ($tenantId && is_numeric($tenantId)) {
        $whereClauses[] = "p.tenant_id = ?";
        $params[] = $tenantId;
        $types .= 'i';
        logActivity("Added tenant filter - ID: $tenantId");
    }

    if ($propertyId && is_numeric($propertyId)) {
        $whereClauses[] = "a.property_code = ?";
        $params[] = $propertyId;
        $types .= 'i';
        logActivity("Added property filter - Code: $propertyId");
    }

    if ($apartmentId && is_numeric($apartmentId)) {
        $whereClauses[] = "p.apartment_id = ?";
        $params[] = $apartmentId;
        $types .= 'i';
        logActivity("Added apartment filter - ID: $apartmentId");
    }

    if ($paymentStatus && in_array($paymentStatus, ['pending', 'completed', 'overdue', 'failed', 'refunded'])) {
        $whereClauses[] = "p.payment_status = ?";
        $params[] = $paymentStatus;
        $types .= 's';
        logActivity("Added status filter - Status: $paymentStatus");
    }

    if ($paymentMethod && in_array($paymentMethod, ['cash', 'bank_transfer', 'check', 'credit_card', 'mobile_money'])) {
        $whereClauses[] = "p.payment_method = ?";
        $params[] = $paymentMethod;
        $types .= 's';
        logActivity("Added method filter - Method: $paymentMethod");
    }

    if ($dateFrom) {
        $whereClauses[] = "p.payment_date >= ?";
        $params[] = $dateFrom;
        $types .= 's';
        logActivity("Added date from filter - Date: $dateFrom");
    }

    if ($dateTo) {
        $whereClauses[] = "p.payment_date <= ?";
        $params[] = $dateTo;
        $types .= 's';
        logActivity("Added date to filter - Date: $dateTo");
    }

    if ($search) {
        $whereClauses[] = "(t.firstname LIKE ? OR t.lastname LIKE ? OR CONCAT(t.firstname, ' ', t.lastname) LIKE ? OR p.receipt_number LIKE ? OR p.reference_number LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $types .= 'sssss';
        logActivity("Added search filter - Term: $search");
    }

    $whereSQL = count($whereClauses) > 0 ? "WHERE " . implode(" AND ", $whereClauses) : "";
    logActivity("Where clause constructed: $whereSQL");
    logActivity("Parameter types: $types | Parameter count: " . count($params));

    // --- TOTAL COUNT ---
    $countQuery = "SELECT COUNT(DISTINCT p.id) as total 
                   FROM payments p
                   LEFT JOIN tenants t ON p.tenant_id = t.id
                   LEFT JOIN apartments a ON p.apartment_id = a.id
                   $whereSQL";
    
    logActivity("Executing count query: $countQuery");
    
    $countStmt = $conn->prepare($countQuery);
    if (!$countStmt) {
        logActivity("ERROR preparing count statement: " . $conn->error);
        throw new Exception("Failed to prepare count query");
    }
    
    if ($params) {
        logActivity("Binding count parameters - Types: $types | Values: " . json_encode($params));
        $countStmt->bind_param($types, ...$params);
    }
    
    if (!$countStmt->execute()) {
        logActivity("ERROR executing count query: " . $countStmt->error);
        throw new Exception("Failed to execute count query");
    }
    
    $countResult = $countStmt->get_result();
    $totalPayments = $countResult->fetch_assoc()['total'] ?? 0;
    $countStmt->close();
    
    logActivity("Count query result - Total payments found: $totalPayments");

    // --- DATA FETCH ---
    $query = "SELECT 
                p.*,
                CONCAT(t.firstname, ' ', t.lastname) as tenant_name,
                t.email as tenant_email,
                t.phone as tenant_phone,
                a.apartment_number,
                a.apartment_type_id,
                pr.name as property_name,
                pr.address as property_address,
                CONCAT(u.firstname, ' ', u.lastname) as recorded_by_name,
                CASE 
                    WHEN p.payment_status = 'completed' THEN 'success'
                    WHEN p.payment_status = 'overdue' THEN 'danger'
                    WHEN p.payment_status = 'pending' THEN 'warning'
                    WHEN p.payment_status = 'failed' THEN 'error'
                    ELSE 'secondary'
                END as status_color
              FROM payments p
              LEFT JOIN tenants t ON p.tenant_id = t.id
              LEFT JOIN apartments a ON p.apartment_id = a.id
              LEFT JOIN properties pr ON a.property_code = pr.property_code
              LEFT JOIN admin_tbl u ON p.recorded_by = u.unique_id
              $whereSQL
              ORDER BY p.payment_date DESC, p.id DESC
              LIMIT ? OFFSET ?";

    logActivity("Executing data fetch query with pagination - Limit: $limit | Offset: $offset");
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logActivity("ERROR preparing fetch statement: " . $conn->error);
        throw new Exception("Failed to prepare payments query: " . $conn->error);
    }

    $paramsWithPagination = $params;
    $paramsWithPagination[] = $limit;
    $paramsWithPagination[] = $offset;
    $stmtTypes = $types . 'ii';
    
    logActivity("Binding fetch parameters - Types: $stmtTypes | Values: " . json_encode($paramsWithPagination));
    
    if ($paramsWithPagination) {
        $stmt->bind_param($stmtTypes, ...$paramsWithPagination);
    }
    
    if (!$stmt->execute()) {
        logActivity("ERROR executing fetch query: " . $stmt->error);
        throw new Exception("Failed to execute fetch query");
    }
    
    $result = $stmt->get_result();
    $paymentsCount = $result->num_rows;
    logActivity("Fetch query executed successfully - Rows returned: $paymentsCount");

    $payments = [];
    while ($row = $result->fetch_assoc()) {
        // Format amounts
        $row['amount_formatted'] = number_format($row['amount'], 2);
        $row['balance_formatted'] = $row['balance'] ? number_format($row['balance'], 2) : '0.00';
        
        // Format dates
        $row['payment_date_formatted'] = date('M d, Y', strtotime($row['payment_date']));
        $row['due_date_formatted'] = $row['due_date'] ? date('M d, Y', strtotime($row['due_date'])) : 'N/A';
        $row['created_at_formatted'] = date('M d, Y H:i', strtotime($row['created_at']));
        
        $payments[] = $row;
    }

    $stmt->close();
    logActivity("Processed " . count($payments) . " payments for response");

    // Get filters data for dropdowns
    logActivity("Fetching filter dropdown data");
    $filters = [
        'tenants' => getTenantsForFilter($conn),
        'properties' => getPropertiesForFilter($conn),
        'apartments' => getApartmentsForFilter($conn),
        'payment_methods' => ['cash', 'bank_transfer', 'check', 'credit_card', 'mobile_money'],
        'payment_statuses' => ['pending', 'completed', 'overdue', 'failed', 'refunded']
    ];
    
    logActivity("Filter data fetched successfully - Tenants: " . count($filters['tenants']) . " | Properties: " . count($filters['properties']) . " | Apartments: " . count($filters['apartments']));

    $response = [
        "success" => true,
        "payments" => $payments,
        "pagination" => [
            "total" => $totalPayments,
            "page" => $page,
            "limit" => $limit,
            "total_pages" => ceil($totalPayments / $limit)
        ],
        "filters" => $filters,
        "user_role" => $userRole
    ];
    
    logActivity("fetchPayments() completed successfully - Returning " . count($payments) . " payments");
    echo json_encode($response);
}

function fetchSinglePayment($conn) {
    logActivity("Starting fetchSinglePayment()");
    
    $paymentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    logActivity("Fetching payment with ID: $paymentId");
    
    if (!$paymentId) {
        logActivity("ERROR: Payment ID is required but not provided");
        echo json_encode(["success" => false, "message" => "Payment ID is required."]);
        return;
    }

    $query = "SELECT 
                p.*,
                CONCAT(t.firstname, ' ', t.lastname) as tenant_name,
                t.email as tenant_email,
                t.phone as tenant_phone,
                t.id_number,
                t.emergency_contact,
                a.apartment_number,
                a.rent_amount as monthly_rent,
                a.deposit_amount,
                pr.name as property_name,
                pr.address as property_address,
                CONCAT(u.firstname, ' ', u.lastname) as recorded_by_name,
                pm.method_name
              FROM payments p
              LEFT JOIN tenants t ON p.tenant_id = t.id
              LEFT JOIN apartments a ON p.apartment_id = a.id
              LEFT JOIN properties pr ON a.property_code = pr.property_code
              LEFT JOIN admin_tbl u ON p.recorded_by = u.unique_id
              LEFT JOIN payment_methods pm ON p.payment_method = pm.method_code
              WHERE p.id = ? AND p.is_deleted = 0";
    
    logActivity("Executing query for single payment: $query");
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logActivity("ERROR preparing statement for single payment: " . $conn->error);
        throw new Exception("Failed to prepare single payment query");
    }
    
    $stmt->bind_param("i", $paymentId);
    logActivity("Bound parameter - ID: $paymentId");
    
    if (!$stmt->execute()) {
        logActivity("ERROR executing single payment query: " . $stmt->error);
        throw new Exception("Failed to execute single payment query");
    }
    
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        logActivity("WARNING: Payment with ID $paymentId not found");
        echo json_encode(["success" => false, "message" => "Payment not found."]);
        return;
    }

    $payment = $result->fetch_assoc();
    logActivity("Payment found - Receipt: " . ($payment['receipt_number'] ?? 'N/A') . " | Amount: " . $payment['amount']);
    
    // Format data
    $payment['amount_formatted'] = number_format($payment['amount'], 2);
    $payment['balance_formatted'] = $payment['balance'] ? number_format($payment['balance'], 2) : '0.00';
    $payment['payment_date_formatted'] = date('M d, Y', strtotime($payment['payment_date']));
    
    // Get payment history for this tenant/apartment
    logActivity("Fetching payment history for tenant: " . $payment['tenant_id'] . " | apartment: " . $payment['apartment_id']);
    
    $historyQuery = "SELECT * FROM payment_history 
                     WHERE tenant_id = ? AND apartment_id = ?
                     ORDER BY payment_date DESC LIMIT 10";
    $historyStmt = $conn->prepare($historyQuery);
    if (!$historyStmt) {
        logActivity("ERROR preparing history query: " . $conn->error);
    } else {
        $historyStmt->bind_param("ii", $payment['tenant_id'], $payment['apartment_id']);
        $historyStmt->execute();
        $historyResult = $historyStmt->get_result();
        
        $payment['payment_history'] = [];
        while ($row = $historyResult->fetch_assoc()) {
            $row['amount_formatted'] = number_format($row['amount'], 2);
            $row['payment_date_formatted'] = date('M d, Y', strtotime($row['payment_date']));
            $payment['payment_history'][] = $row;
        }
        $historyCount = count($payment['payment_history']);
        $historyStmt->close();
        logActivity("Found $historyCount history records");
    }
    
    $stmt->close();

    logActivity("fetchSinglePayment() completed successfully for payment ID: $paymentId");
    echo json_encode([
        "success" => true,
        "payment" => $payment
    ]);
}

function createPayment($conn, $adminId) {
    logActivity("Starting createPayment() - Admin ID: $adminId");
    
    // Validate required fields
    $required = ['tenant_id', 'apartment_id', 'amount', 'payment_date', 'payment_method'];
    $missingFields = [];
    
    foreach ($required as $field) {
        if (!isset($_POST[$field]) || empty($_POST[$field])) {
            $missingFields[] = $field;
            logActivity("Missing required field: $field");
        }
    }
    
    if (!empty($missingFields)) {
        logActivity("ERROR: Missing required fields: " . implode(', ', $missingFields));
        echo json_encode(["success" => false, "message" => "Missing required fields: " . implode(', ', $missingFields)]);
        return;
    }

    // Generate receipt number
    $receiptNumber = 'REC-' . date('Ymd') . '-' . strtoupper(uniqid());
    logActivity("Generated receipt number: $receiptNumber");
    
    // Prepare data
    $tenantId = (int)$_POST['tenant_id'];
    $apartmentId = (int)$_POST['apartment_id'];
    $amount = (float)$_POST['amount'];
    $paymentDate = $_POST['payment_date'];
    $paymentMethod = $_POST['payment_method'];
    $paymentStatus = isset($_POST['payment_status']) ? $_POST['payment_status'] : 'completed';
    $referenceNumber = isset($_POST['reference_number']) ? $_POST['reference_number'] : null;
    $description = isset($_POST['description']) ? $_POST['description'] : null;
    $dueDate = isset($_POST['due_date']) ? $_POST['due_date'] : null;
    $balance = isset($_POST['balance']) ? (float)$_POST['balance'] : 0;

    logActivity("Payment data - Tenant: $tenantId | Apartment: $apartmentId | Amount: $amount | Date: $paymentDate | Method: $paymentMethod | Status: $paymentStatus");

    // Start transaction
    $conn->begin_transaction();
    logActivity("Transaction started");

    try {
        // Insert payment
        $query = "INSERT INTO payments (
                    tenant_id, apartment_id, amount, balance, 
                    payment_date, due_date, payment_method, 
                    payment_status, receipt_number, reference_number, 
                    description, recorded_by, created_at
                  ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        logActivity("Executing insert query: $query");
        
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            logActivity("ERROR preparing insert statement: " . $conn->error);
            throw new Exception("Failed to prepare insert query");
        }
        
        $stmt->bind_param(
            "iiddssssssss",
            $tenantId, $apartmentId, $amount, $balance,
            $paymentDate, $dueDate, $paymentMethod,
            $paymentStatus, $receiptNumber, $referenceNumber,
            $description, $adminId
        );
        
        if (!$stmt->execute()) {
            logActivity("ERROR executing insert: " . $stmt->error);
            throw new Exception("Failed to create payment: " . $stmt->error);
        }
        
        $paymentId = $stmt->insert_id;
        $stmt->close();
        logActivity("Payment inserted successfully - ID: $paymentId");

        // Insert into payment history
        $historyQuery = "INSERT INTO payment_history (
                          payment_id, tenant_id, apartment_id, amount,
                          payment_date, payment_method, payment_status,
                          receipt_number, description, recorded_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        logActivity("Inserting into payment history");
        
        $historyStmt = $conn->prepare($historyQuery);
        if (!$historyStmt) {
            logActivity("ERROR preparing history insert: " . $conn->error);
            throw new Exception("Failed to prepare history insert");
        }
        
        $historyStmt->bind_param(
            "iiidssssss",
            $paymentId, $tenantId, $apartmentId, $amount,
            $paymentDate, $paymentMethod, $paymentStatus,
            $receiptNumber, $description, $adminId
        );
        
        if (!$historyStmt->execute()) {
            logActivity("ERROR executing history insert: " . $historyStmt->error);
            throw new Exception("Failed to create payment history: " . $historyStmt->error);
        }
        $historyStmt->close();
        logActivity("Payment history inserted successfully");

        // Update tenant's last payment date
        $updateTenantQuery = "UPDATE tenants SET last_payment_date = ? WHERE id = ?";
        logActivity("Updating tenant last payment date - Tenant: $tenantId | Date: $paymentDate");
        
        $updateStmt = $conn->prepare($updateTenantQuery);
        if (!$updateStmt) {
            logActivity("ERROR preparing tenant update: " . $conn->error);
            throw new Exception("Failed to prepare tenant update");
        }
        
        $updateStmt->bind_param("si", $paymentDate, $tenantId);
        
        if (!$updateStmt->execute()) {
            logActivity("ERROR executing tenant update: " . $updateStmt->error);
            throw new Exception("Failed to update tenant last payment date");
        }
        $updateStmt->close();
        logActivity("Tenant last payment date updated successfully");

        // Log activity
        $logMessage = "Payment created successfully - ID: $paymentId | Receipt: $receiptNumber | Amount: $$amount | Tenant: $tenantId | Admin: $adminId";
        logActivity($logMessage);

        $conn->commit();
        logActivity("Transaction committed successfully");

        echo json_encode([
            "success" => true,
            "message" => "Payment recorded successfully!",
            "payment_id" => $paymentId,
            "receipt_number" => $receiptNumber
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        logActivity("ERROR in createPayment transaction - Rolling back: " . $e->getMessage());
        throw $e;
    }
}

function updatePayment($conn, $adminId) {
    logActivity("Starting updatePayment() - Admin ID: $adminId");
    
    $paymentId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    logActivity("Updating payment ID: $paymentId");
    
    if (!$paymentId) {
        logActivity("ERROR: Payment ID is required for update");
        echo json_encode(["success" => false, "message" => "Payment ID is required."]);
        return;
    }

    // Check if payment exists
    $checkQuery = "SELECT id, receipt_number FROM payments WHERE id = ? AND is_deleted = 0";
    logActivity("Checking if payment exists - Query: $checkQuery | ID: $paymentId");
    
    $checkStmt = $conn->prepare($checkQuery);
    if (!$checkStmt) {
        logActivity("ERROR preparing check statement: " . $conn->error);
        throw new Exception("Failed to prepare check query");
    }
    
    $checkStmt->bind_param("i", $paymentId);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows === 0) {
        logActivity("WARNING: Payment ID $paymentId not found or already deleted");
        echo json_encode(["success" => false, "message" => "Payment not found."]);
        return;
    }
    
    $paymentData = $checkResult->fetch_assoc();
    $receiptNumber = $paymentData['receipt_number'];
    $checkStmt->close();
    logActivity("Payment exists - Receipt: $receiptNumber");

    // Get current payment data
    $currentQuery = "SELECT * FROM payments WHERE id = ?";
    $currentStmt = $conn->prepare($currentQuery);
    $currentStmt->bind_param("i", $paymentId);
    $currentStmt->execute();
    $currentData = $currentStmt->get_result()->fetch_assoc();
    $currentStmt->close();
    
    logActivity("Current payment data retrieved");

    // Prepare update data
    $updatableFields = [
        'amount' => 'float',
        'balance' => 'float',
        'payment_date' => 'string',
        'due_date' => 'string',
        'payment_method' => 'string',
        'payment_status' => 'string',
        'reference_number' => 'string',
        'description' => 'string'
    ];

    $updateClauses = [];
    $params = [];
    $types = '';
    $changedFields = [];

    foreach ($updatableFields as $field => $type) {
        if (isset($_POST[$field]) && $_POST[$field] != $currentData[$field]) {
            $updateClauses[] = "$field = ?";
            $changedFields[] = "$field: " . ($currentData[$field] ?? 'NULL') . " -> " . $_POST[$field];
            
            if ($type === 'float') {
                $params[] = (float)$_POST[$field];
                $types .= 'd';
            } else {
                $params[] = $_POST[$field];
                $types .= 's';
            }
        }
    }

    if (empty($updateClauses)) {
        logActivity("No changes detected for payment ID: $paymentId");
        echo json_encode(["success" => false, "message" => "No changes detected."]);
        return;
    }

    logActivity("Changes detected - Fields: " . implode(' | ', $changedFields));

    $params[] = $paymentId;
    $types .= 'i';

    // Update payment
    $query = "UPDATE payments SET " . implode(", ", $updateClauses) . ", updated_at = NOW() WHERE id = ?";
    logActivity("Executing update query: $query");
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logActivity("ERROR preparing update statement: " . $conn->error);
        throw new Exception("Failed to prepare update query");
    }
    
    $stmt->bind_param($types, ...$params);
    
    if (!$stmt->execute()) {
        logActivity("ERROR executing update: " . $stmt->error);
        echo json_encode(["success" => false, "message" => "Failed to update payment: " . $stmt->error]);
        return;
    }
    
    $affectedRows = $stmt->affected_rows;
    $stmt->close();
    
    logActivity("Update executed successfully - Affected rows: $affectedRows");

    // Log the change
    $changeDescription = "Payment #$receiptNumber (ID: $paymentId) updated by admin $adminId. Changes: " . implode(' | ', $changedFields);
    logActivity($changeDescription);

    echo json_encode([
        "success" => true,
        "message" => "Payment updated successfully!"
    ]);
}

function deletePayment($conn, $adminId) {
    logActivity("Starting deletePayment() - Admin ID: $adminId");
    
    $paymentId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    logActivity("Deleting payment ID: $paymentId");
    
    if (!$paymentId) {
        logActivity("ERROR: Payment ID is required for deletion");
        echo json_encode(["success" => false, "message" => "Payment ID is required."]);
        return;
    }

    // First get payment details for logging
    $selectQuery = "SELECT receipt_number, amount FROM payments WHERE id = ? AND is_deleted = 0";
    $selectStmt = $conn->prepare($selectQuery);
    $selectStmt->bind_param("i", $paymentId);
    $selectStmt->execute();
    $paymentData = $selectStmt->get_result()->fetch_assoc();
    $selectStmt->close();
    
    if ($paymentData) {
        logActivity("Payment to delete - Receipt: " . $paymentData['receipt_number'] . " | Amount: " . $paymentData['amount']);
    }

    // Soft delete (mark as deleted)
    $query = "UPDATE payments SET is_deleted = 1, deleted_at = NOW(), deleted_by = ? WHERE id = ?";
    logActivity("Executing soft delete query: $query");
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logActivity("ERROR preparing delete statement: " . $conn->error);
        throw new Exception("Failed to prepare delete query");
    }
    
    $stmt->bind_param("si", $adminId, $paymentId);
    
    if (!$stmt->execute()) {
        logActivity("ERROR executing delete: " . $stmt->error);
        echo json_encode(["success" => false, "message" => "Failed to delete payment."]);
        return;
    }
    
    $affectedRows = $stmt->affected_rows;
    $stmt->close();
    
    logActivity("Delete executed successfully - Affected rows: $affectedRows");

    $logMessage = "Payment ID: $paymentId" . ($paymentData ? " (Receipt: " . $paymentData['receipt_number'] . ")" : "") . " deleted by admin $adminId";
    logActivity($logMessage);

    echo json_encode([
        "success" => true,
        "message" => "Payment deleted successfully!"
    ]);
}

function recordPayment($conn, $adminId) {
    logActivity("Starting recordPayment() - Quick payment recording by Admin: $adminId");
    
    // Get POST data (assuming JSON input)
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_POST; // Fallback to POST if not JSON
    }
    
    logActivity("Quick payment input data: " . json_encode($input));
    
    $tenantId = isset($input['tenant_id']) ? (int)$input['tenant_id'] : 0;
    $amount = isset($input['amount']) ? (float)$input['amount'] : 0;
    $paymentMethod = isset($input['payment_method']) ? $input['payment_method'] : 'cash';
    
    logActivity("Quick payment - Tenant: $tenantId | Amount: $amount | Method: $paymentMethod");
    
    if (!$tenantId || $amount <= 0) {
        logActivity("ERROR: Invalid tenant ID or amount - Tenant: $tenantId | Amount: $amount");
        echo json_encode(["success" => false, "message" => "Tenant ID and amount are required."]);
        return;
    }

    // Get tenant's current apartment
    $tenantQuery = "SELECT apartment_id, CONCAT(firstname, ' ', lastname) as tenant_name FROM tenants WHERE id = ? AND status = 1";
    logActivity("Fetching tenant details - Query: $tenantQuery | ID: $tenantId");
    
    $tenantStmt = $conn->prepare($tenantQuery);
    if (!$tenantStmt) {
        logActivity("ERROR preparing tenant query: " . $conn->error);
        throw new Exception("Failed to prepare tenant query");
    }
    
    $tenantStmt->bind_param("i", $tenantId);
    $tenantStmt->execute();
    $tenantResult = $tenantStmt->get_result();
    
    if ($tenantResult->num_rows === 0) {
        logActivity("ERROR: Tenant not found - ID: $tenantId");
        echo json_encode(["success" => false, "message" => "Tenant not found."]);
        return;
    }
    
    $tenantData = $tenantResult->fetch_assoc();
    $apartmentId = $tenantData['apartment_id'];
    $tenantName = $tenantData['tenant_name'];
    $tenantStmt->close();
    
    logActivity("Tenant found - Name: $tenantName | Apartment ID: $apartmentId");

    // Generate receipt number
    $receiptNumber = 'QREC-' . date('Ymd') . '-' . strtoupper(uniqid());
    logActivity("Generated quick receipt number: $receiptNumber");
    
    // Start transaction
    $conn->begin_transaction();
    logActivity("Transaction started for quick payment");

    try {
        // Record payment
        $query = "INSERT INTO payments (
                    tenant_id, apartment_id, amount, 
                    payment_date, payment_method, payment_status,
                    receipt_number, recorded_by, created_at
                  ) VALUES (?, ?, ?, CURDATE(), ?, 'completed', ?, ?, NOW())";
        
        logActivity("Executing quick payment insert: $query");
        
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            logActivity("ERROR preparing quick payment insert: " . $conn->error);
            throw new Exception("Failed to prepare quick payment insert");
        }
        
        $stmt->bind_param("iidsss", $tenantId, $apartmentId, $amount, $paymentMethod, $receiptNumber, $adminId);
        
        if (!$stmt->execute()) {
            logActivity("ERROR executing quick payment insert: " . $stmt->error);
            throw new Exception("Failed to record payment: " . $stmt->error);
        }
        
        $paymentId = $stmt->insert_id;
        $stmt->close();
        
        logActivity("Quick payment inserted successfully - Payment ID: $paymentId");

        // Insert into payment history
        $historyQuery = "INSERT INTO payment_history (
                          payment_id, tenant_id, apartment_id, amount,
                          payment_date, payment_method, payment_status,
                          receipt_number, recorded_by
                        ) VALUES (?, ?, ?, ?, CURDATE(), ?, 'completed', ?, ?)";
        
        $historyStmt = $conn->prepare($historyQuery);
        if (!$historyStmt) {
            logActivity("ERROR preparing history insert for quick payment: " . $conn->error);
            throw new Exception("Failed to prepare history insert");
        }
        
        $historyStmt->bind_param("iiidsss", $paymentId, $tenantId, $apartmentId, $amount, $paymentMethod, $receiptNumber, $adminId);
        
        if (!$historyStmt->execute()) {
            logActivity("ERROR executing history insert for quick payment: " . $historyStmt->error);
            throw new Exception("Failed to create payment history");
        }
        $historyStmt->close();
        
        logActivity("Payment history created for quick payment");

        // Update tenant's last payment date
        $updateTenantQuery = "UPDATE tenants SET last_payment_date = CURDATE() WHERE id = ?";
        $updateStmt = $conn->prepare($updateTenantQuery);
        $updateStmt->bind_param("i", $tenantId);
        $updateStmt->execute();
        $updateStmt->close();
        
        logActivity("Tenant last payment date updated");

        $conn->commit();
        logActivity("Transaction committed for quick payment");

        $logMessage = "Quick payment recorded - ID: $paymentId | Receipt: $receiptNumber | Amount: $$amount | Tenant: $tenantName (ID: $tenantId) | Admin: $adminId";
        logActivity($logMessage);

        echo json_encode([
            "success" => true,
            "message" => "Payment recorded successfully!",
            "receipt_number" => $receiptNumber,
            "payment_id" => $paymentId
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        logActivity("ERROR in quick payment transaction - Rolling back: " . $e->getMessage());
        throw $e;
    }
}

function generateInvoice($conn, $adminId) {
    logActivity("Starting generateInvoice() - Admin ID: $adminId");
    
    $paymentId = isset($_GET['payment_id']) ? (int)$_GET['payment_id'] : 0;
    logActivity("Generating invoice for payment ID: $paymentId");
    
    if (!$paymentId) {
        logActivity("ERROR: Payment ID is required for invoice generation");
        echo json_encode(["success" => false, "message" => "Payment ID is required."]);
        return;
    }

    // Fetch payment details
    $query = "SELECT 
                p.*,
                CONCAT(t.firstname, ' ', t.lastname) as tenant_name,
                t.email as tenant_email,
                t.phone as tenant_phone,
                t.address as tenant_address,
                a.apartment_number,
                a.rent_amount as monthly_rent,
                pr.name as property_name,
                pr.address as property_address,
                pr.phone as property_phone,
                pr.email as property_email,
                pm.method_name as payment_method_name
              FROM payments p
              LEFT JOIN tenants t ON p.tenant_id = t.id
              LEFT JOIN apartments a ON p.apartment_id = a.id
              LEFT JOIN properties pr ON a.property_code = pr.property_code
              LEFT JOIN payment_methods pm ON p.payment_method = pm.method_code
              WHERE p.id = ? AND p.is_deleted = 0";
    
    logActivity("Executing invoice data query: $query");
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logActivity("ERROR preparing invoice query: " . $conn->error);
        throw new Exception("Failed to prepare invoice query");
    }
    
    $stmt->bind_param("i", $paymentId);
    
    if (!$stmt->execute()) {
        logActivity("ERROR executing invoice query: " . $stmt->error);
        throw new Exception("Failed to execute invoice query");
    }
    
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        logActivity("WARNING: Payment ID $paymentId not found for invoice generation");
        echo json_encode(["success" => false, "message" => "Payment not found."]);
        return;
    }

    $invoiceData = $result->fetch_assoc();
    $stmt->close();
    
    logActivity("Invoice data retrieved - Tenant: " . ($invoiceData['tenant_name'] ?? 'N/A') . " | Amount: " . $invoiceData['amount']);

    // Generate invoice number
    $invoiceNumber = 'INV-' . date('Ymd') . '-' . str_pad($paymentId, 6, '0', STR_PAD_LEFT);
    logActivity("Generated invoice number: $invoiceNumber");
    
    // Return invoice data (frontend will format it)
    $response = [
        "success" => true,
        "invoice" => array_merge($invoiceData, [
            'invoice_number' => $invoiceNumber,
            'invoice_date' => date('F d, Y'),
            'due_date' => $invoiceData['due_date'] ? date('F d, Y', strtotime($invoiceData['due_date'])) : 'N/A'
        ])
    ];
    
    logActivity("Invoice generated successfully for payment ID: $paymentId");
    echo json_encode($response);
}

function getPaymentStatistics($conn) {
    logActivity("Starting getPaymentStatistics()");
    
    $period = isset($_GET['period']) ? $_GET['period'] : 'monthly';
    logActivity("Statistics period requested: $period");
    
    // Total statistics
    $statsQuery = "SELECT 
                    COUNT(*) as total_payments,
                    SUM(amount) as total_revenue,
                    AVG(amount) as average_payment,
                    COUNT(CASE WHEN payment_status = 'completed' THEN 1 END) as completed_payments,
                    COUNT(CASE WHEN payment_status = 'pending' THEN 1 END) as pending_payments,
                    COUNT(CASE WHEN payment_status = 'overdue' THEN 1 END) as overdue_payments,
                    SUM(CASE WHEN payment_status = 'completed' THEN amount ELSE 0 END) as completed_revenue,
                    SUM(CASE WHEN payment_status = 'overdue' THEN amount ELSE 0 END) as overdue_amount
                   FROM payments 
                   WHERE is_deleted = 0";
    
    logActivity("Executing statistics summary query");
    
    $statsStmt = $conn->prepare($statsQuery);
    if (!$statsStmt) {
        logActivity("ERROR preparing statistics query: " . $conn->error);
        throw new Exception("Failed to prepare statistics query");
    }
    
    $statsStmt->execute();
    $stats = $statsStmt->get_result()->fetch_assoc();
    $statsStmt->close();
    
    logActivity("Statistics summary - Total Payments: " . ($stats['total_payments'] ?? 0) . " | Revenue: $" . ($stats['total_revenue'] ?? 0));

    // Monthly revenue trend
    $trendQuery = "SELECT 
                    DATE_FORMAT(payment_date, '%Y-%m') as month,
                    DATE_FORMAT(payment_date, '%b') as month_name,
                    SUM(amount) as revenue,
                    COUNT(*) as payment_count
                   FROM payments 
                   WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                     AND is_deleted = 0
                   GROUP BY DATE_FORMAT(payment_date, '%Y-%m'), DATE_FORMAT(payment_date, '%b')
                   ORDER BY month";
    
    logActivity("Executing revenue trend query");
    
    $trendStmt = $conn->prepare($trendQuery);
    if (!$trendStmt) {
        logActivity("ERROR preparing trend query: " . $conn->error);
    } else {
        $trendStmt->execute();
        $trendResult = $trendStmt->get_result();
        
        $revenueTrend = [];
        while ($row = $trendResult->fetch_assoc()) {
            $revenueTrend[] = $row;
        }
        $trendCount = count($revenueTrend);
        $trendStmt->close();
        logActivity("Revenue trend data points: $trendCount");
    }

    // Payment method distribution
    $methodQuery = "SELECT 
                    payment_method,
                    COUNT(*) as count,
                    SUM(amount) as total_amount
                   FROM payments 
                   WHERE is_deleted = 0
                   GROUP BY payment_method";
    
    logActivity("Executing payment method distribution query");
    
    $methodStmt = $conn->prepare($methodQuery);
    if (!$methodStmt) {
        logActivity("ERROR preparing method distribution query: " . $conn->error);
    } else {
        $methodStmt->execute();
        $methodResult = $methodStmt->get_result();
        
        $methodDistribution = [];
        while ($row = $methodResult->fetch_assoc()) {
            $methodDistribution[] = $row;
        }
        $methodStmt->close();
        logActivity("Method distribution data points: " . count($methodDistribution));
    }

    // Top paying tenants
    $topTenantsQuery = "SELECT 
                        CONCAT(t.firstname, ' ', t.lastname) as tenant_name,
                        COUNT(p.id) as payment_count,
                        SUM(p.amount) as total_paid
                       FROM payments p
                       LEFT JOIN tenants t ON p.tenant_id = t.id
                       WHERE p.is_deleted = 0 AND p.payment_status = 'completed'
                       GROUP BY p.tenant_id, t.firstname, t.lastname
                       ORDER BY total_paid DESC
                       LIMIT 10";
    
    logActivity("Executing top tenants query");
    
    $topTenantsStmt = $conn->prepare($topTenantsQuery);
    if (!$topTenantsStmt) {
        logActivity("ERROR preparing top tenants query: " . $conn->error);
    } else {
        $topTenantsStmt->execute();
        $topTenantsResult = $topTenantsStmt->get_result();
        
        $topTenants = [];
        while ($row = $topTenantsResult->fetch_assoc()) {
            $row['total_paid_formatted'] = number_format($row['total_paid'], 2);
            $topTenants[] = $row;
        }
        $topTenantsStmt->close();
        logActivity("Top tenants data points: " . count($topTenants));
    }

    $response = [
        "success" => true,
        "statistics" => [
            "summary" => $stats,
            "revenue_trend" => $revenueTrend ?? [],
            "method_distribution" => $methodDistribution ?? [],
            "top_tenants" => $topTenants ?? []
        ]
    ];
    
    logActivity("getPaymentStatistics() completed successfully");
    echo json_encode($response);
}

// Helper functions for filters
function getTenantsForFilter($conn) {
    logActivity("Fetching tenants for filter dropdown");
    
    $query = "SELECT id, firstname, lastname, email FROM tenants WHERE status = 1 ORDER BY firstname";
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        logActivity("ERROR preparing tenants filter query: " . $conn->error);
        return [];
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $tenants = [];
    while ($row = $result->fetch_assoc()) {
        $row['fullname'] = trim($row['firstname'] . ' ' . $row['lastname']);
        $tenants[] = $row;
    }
    $count = count($tenants);
    $stmt->close();
    
    logActivity("Fetched $count tenants for filter dropdown");
    return $tenants;
}

function getPropertiesForFilter($conn) {
    logActivity("Fetching properties for filter dropdown");
    
    $query = "SELECT property_code, name FROM properties WHERE status = 1 ORDER BY name";
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        logActivity("ERROR preparing properties filter query: " . $conn->error);
        return [];
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $properties = [];
    while ($row = $result->fetch_assoc()) {
        $properties[] = $row;
    }
    $count = count($properties);
    $stmt->close();
    
    logActivity("Fetched $count properties for filter dropdown");
    return $properties;
}

function getApartmentsForFilter($conn) {
    logActivity("Fetching apartments for filter dropdown");
    
    $query = "SELECT a.id, a.apartment_number, p.name as property_name 
              FROM apartments a
              LEFT JOIN properties p ON a.property_code = p.property_code
              WHERE a.status = 1
              ORDER BY p.name, a.apartment_number";
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        logActivity("ERROR preparing apartments filter query: " . $conn->error);
        return [];
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $apartments = [];
    while ($row = $result->fetch_assoc()) {
        $row['display_name'] = ($row['property_name'] ? $row['property_name'] . ' - ' : '') . $row['apartment_number'];
        $apartments[] = $row;
    }
    $count = count($apartments);
    $stmt->close();
    
    logActivity("Fetched $count apartments for filter dropdown");
    return $apartments;
}
