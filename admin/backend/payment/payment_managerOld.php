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

    logActivity("Database connection established successfully");

    // Route to appropriate function
    logActivity("Routing to action: $action");
    
    switch ($action) {
        case 'fetch':
            fetchPayments($conn, $adminId, $userRole);
            break;
        case 'fetch_single':
            fetchSinglePayment($conn);
            break;
        case 'create':
            logActivity("Initiating payment creation - User: $adminId");
            createPayment($conn, $adminId);
            break;
        case 'update':
            logActivity("Initiating payment update - User: $adminId");
            updatePayment($conn, $adminId);
            break;
        case 'delete':
            logActivity("Initiating payment deletion - User: $adminId");
            deletePayment($conn, $adminId);
            break;
        case 'record_payment':
            logActivity("Initiating quick payment recording - User: $adminId");
            recordPayment($conn, $adminId);
            break;
        case 'generate_invoice':
            logActivity("Initiating invoice generation - User: $adminId");
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

    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
    logActivity("Payment API Request Completed Successfully - Action: $action");

} catch (Exception $e) {
    $errorDetails = [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ];
    
    logActivity("CRITICAL ERROR in Payment API: " . json_encode($errorDetails));
    
    if (isset($conn) && $conn instanceof mysqli && $conn->connect_errno == 0) {
        $conn->close();
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
    $tenantCode = isset($_GET['tenant_code']) ? trim($_GET['tenant_code']) : null;
    $propertyCode = isset($_GET['property_code']) ? trim($_GET['property_code']) : null;
    $apartmentCode = isset($_GET['apartment_code']) ? trim($_GET['apartment_code']) : null;
    $paymentStatus = isset($_GET['payment_status']) ? trim($_GET['payment_status']) : null;
    $paymentMethod = isset($_GET['payment_method']) ? trim($_GET['payment_method']) : null;
    $dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : null;
    $dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : null;
    $search = isset($_GET['search']) ? trim($_GET['search']) : null;

    logActivity("Filters applied - Tenant: $tenantCode | Property: $propertyCode | Apartment: $apartmentCode | Status: $paymentStatus | Method: $paymentMethod");

    $whereClauses = ["p.is_deleted = 0"];
    $params = [];
    $types = '';

    if ($tenantCode) {
        $whereClauses[] = "p.tenant_code = ?";
        $params[] = $tenantCode;
        $types .= 's';
        logActivity("Added tenant filter - Code: $tenantCode");
    }

    if ($propertyCode) {
        $whereClauses[] = "pr.property_code = ?";
        $params[] = $propertyCode;
        $types .= 's';
        logActivity("Added property filter - Code: $propertyCode");
    }

    if ($apartmentCode) {
        $whereClauses[] = "p.apartment_code = ?";
        $params[] = $apartmentCode;
        $types .= 's';
        logActivity("Added apartment filter - Code: $apartmentCode");
    }

    if ($paymentStatus && in_array($paymentStatus, ['pending', 'completed', 'failed', 'refunded'])) {
        $whereClauses[] = "p.payment_status = ?";
        $params[] = $paymentStatus;
        $types .= 's';
        logActivity("Added status filter - Status: $paymentStatus");
    }

    if ($paymentMethod && in_array($paymentMethod, ['cash', 'bank_transfer', 'card', 'cheque'])) {
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

    // --- TOTAL COUNT ---
    $countQuery = "SELECT COUNT(DISTINCT p.id) as total 
                   FROM payments p
                   LEFT JOIN tenants t ON p.tenant_code = t.tenant_code
                   LEFT JOIN apartments a ON p.apartment_code = a.apartment_code
                   LEFT JOIN properties pr ON a.property_code = pr.property_code
                   $whereSQL";
    
    logActivity("Executing count query");
    
    $countStmt = $conn->prepare($countQuery);
    if (!$countStmt) {
        logActivity("ERROR preparing count statement: " . $conn->error);
        throw new Exception("Failed to prepare count query");
    }
    
    if ($params) {
        $countStmt->bind_param($types, ...$params);
    }
    
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalPayments = $countResult->fetch_assoc()['total'] ?? 0;
    $countStmt->close();
    
    logActivity("Total payments found: $totalPayments");

    // --- DATA FETCH ---
    $query = "SELECT 
                p.*,
                CONCAT(t.firstname, ' ', t.lastname) as tenant_name,
                t.email as tenant_email,
                t.phone as tenant_phone,
                a.apartment_number,
                a.apartment_type_id,
                pr.name as property_name,
                pr.property_code,
                pr.address as property_address,
                CONCAT(u.firstname, ' ', u.lastname) as recorded_by_name,
                CASE 
                    WHEN p.payment_status = 'completed' THEN 'success'
                    WHEN p.payment_status = 'pending' THEN 'warning'
                    WHEN p.payment_status = 'failed' THEN 'danger'
                    ELSE 'secondary'
                END as status_color
              FROM payments p
              LEFT JOIN tenants t ON p.tenant_code = t.tenant_code
              LEFT JOIN apartments a ON p.apartment_code = a.apartment_code
              LEFT JOIN properties pr ON a.property_code = pr.property_code
              LEFT JOIN admin_tbl u ON p.recorded_by = u.unique_id
              $whereSQL
              ORDER BY p.payment_date DESC, p.id DESC
              LIMIT ? OFFSET ?";

    logActivity("Executing data fetch query");
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logActivity("ERROR preparing fetch statement: " . $conn->error);
        throw new Exception("Failed to prepare payments query");
    }

    $paramsWithPagination = $params;
    $paramsWithPagination[] = $limit;
    $paramsWithPagination[] = $offset;
    $stmtTypes = $types . 'ii';
    
    $stmt->bind_param($stmtTypes, ...$paramsWithPagination);
    $stmt->execute();
    $result = $stmt->get_result();
    $paymentsCount = $result->num_rows;
    logActivity("Query returned $paymentsCount payments");

    $payments = [];
    while ($row = $result->fetch_assoc()) {
        $row['amount_formatted'] = number_format($row['amount'], 2);
        $row['payment_date_formatted'] = date('M d, Y', strtotime($row['payment_date']));
        $row['due_date_formatted'] = $row['due_date'] ? date('M d, Y', strtotime($row['due_date'])) : 'N/A';
        $row['created_at_formatted'] = date('M d, Y H:i', strtotime($row['created_at']));
        $payments[] = $row;
    }

    $stmt->close();
    logActivity("Processed " . count($payments) . " payments for response");

    $response = [
        "success" => true,
        "payments" => $payments,
        "pagination" => [
            "total" => $totalPayments,
            "page" => $page,
            "limit" => $limit,
            "total_pages" => ceil($totalPayments / $limit)
        ],
        "user_role" => $userRole
    ];
    
    logActivity("fetchPayments() completed successfully");
    echo json_encode($response);
}

function fetchSinglePayment($conn) {
    logActivity("Starting fetchSinglePayment()");
    
    $paymentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    logActivity("Fetching payment with ID: $paymentId");
    
    if (!$paymentId) {
        logActivity("ERROR: Payment ID is required");
        echo json_encode(["success" => false, "message" => "Payment ID is required."]);
        return;
    }

    $query = "SELECT 
                p.*,
                CONCAT(t.firstname, ' ', t.lastname) as tenant_name,
                t.email as tenant_email,
                t.phone as tenant_phone,
                t.tenant_code,
                a.apartment_number,
                a.rent_amount as monthly_rent,
                a.security_deposit,
                pr.name as property_name,
                pr.address as property_address,
                CONCAT(u.firstname, ' ', u.lastname) as recorded_by_name
              FROM payments p
              LEFT JOIN tenants t ON p.tenant_code = t.tenant_code
              LEFT JOIN apartments a ON p.apartment_code = a.apartment_code
              LEFT JOIN properties pr ON a.property_code = pr.property_code
              LEFT JOIN admin_tbl u ON p.recorded_by = u.unique_id
              WHERE p.id = ? AND p.is_deleted = 0";
    
    logActivity("Executing single payment query");
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logActivity("ERROR preparing statement: " . $conn->error);
        throw new Exception("Failed to prepare query");
    }
    
    $stmt->bind_param("i", $paymentId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        logActivity("Payment with ID $paymentId not found");
        echo json_encode(["success" => false, "message" => "Payment not found."]);
        return;
    }

    $payment = $result->fetch_assoc();
    $stmt->close();
    
    $payment['amount_formatted'] = number_format($payment['amount'], 2);
    $payment['payment_date_formatted'] = date('M d, Y', strtotime($payment['payment_date']));
    
    logActivity("Payment found - Receipt: " . ($payment['receipt_number'] ?? 'N/A'));
    echo json_encode([
        "success" => true,
        "payment" => $payment
    ]);
}

function createPayment($conn, $adminId) {
    logActivity("Starting createPayment() - Admin ID: $adminId");
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    // Validate required fields
    $required = ['tenant_code', 'apartment_code', 'amount', 'payment_date', 'payment_method'];
    $missingFields = [];
    
    foreach ($required as $field) {
        if (!isset($input[$field]) || empty($input[$field])) {
            $missingFields[] = $field;
        }
    }
    
    if (!empty($missingFields)) {
        logActivity("Missing required fields: " . implode(', ', $missingFields));
        echo json_encode(["success" => false, "message" => "Missing required fields: " . implode(', ', $missingFields)]);
        return;
    }

    $receiptNumber = 'RCP-' . date('Ymd') . '-' . strtoupper(uniqid());
    logActivity("Generated receipt number: $receiptNumber");
    
    $tenantCode = $input['tenant_code'];
    $apartmentCode = $input['apartment_code'];
    $amount = (float)$input['amount'];
    $paymentDate = $input['payment_date'];
    $paymentMethod = $input['payment_method'];
    $paymentStatus = $input['payment_status'] ?? 'completed';
    $referenceNumber = $input['reference_number'] ?? null;
    $description = $input['description'] ?? null;
    $dueDate = $input['due_date'] ?? null;
    $balance = isset($input['balance']) ? (float)$input['balance'] : 0;

    logActivity("Payment data - Tenant: $tenantCode | Apartment: $apartmentCode | Amount: $amount");

    $conn->begin_transaction();
    logActivity("Transaction started");

    try {
        $query = "INSERT INTO payments (
                    tenant_code, apartment_code, amount, balance, 
                    payment_date, due_date, payment_method, 
                    payment_status, receipt_number, reference_number, 
                    description, recorded_by, created_at
                  ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            throw new Exception("Failed to prepare insert query: " . $conn->error);
        }
        
        $stmt->bind_param(
            "ssddssssssss",
            $tenantCode, $apartmentCode, $amount, $balance,
            $paymentDate, $dueDate, $paymentMethod,
            $paymentStatus, $receiptNumber, $referenceNumber,
            $description, $adminId
        );
        
        $stmt->execute();
        $paymentId = $stmt->insert_id;
        $stmt->close();
        logActivity("Payment inserted - ID: $paymentId");

        $conn->commit();
        logActivity("Transaction committed");

        echo json_encode([
            "success" => true,
            "message" => "Payment recorded successfully!",
            "payment_id" => $paymentId,
            "receipt_number" => $receiptNumber
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        logActivity("ERROR in createPayment: " . $e->getMessage());
        throw $e;
    }
}

function updatePayment($conn, $adminId) {
    logActivity("Starting updatePayment() - Admin ID: $adminId");
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $paymentId = isset($input['id']) ? (int)$input['id'] : 0;
    
    if (!$paymentId) {
        echo json_encode(["success" => false, "message" => "Payment ID is required."]);
        return;
    }

    // Check if payment exists
    $checkStmt = $conn->prepare("SELECT id, receipt_number FROM payments WHERE id = ? AND is_deleted = 0");
    $checkStmt->bind_param("i", $paymentId);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows === 0) {
        echo json_encode(["success" => false, "message" => "Payment not found."]);
        return;
    }
    $checkStmt->close();

    $updateFields = [];
    $params = [];
    $types = '';

    $updatableFields = ['amount' => 'd', 'balance' => 'd', 'payment_date' => 's', 
                        'due_date' => 's', 'payment_method' => 's', 'payment_status' => 's',
                        'reference_number' => 's', 'description' => 's'];

    foreach ($updatableFields as $field => $type) {
        if (isset($input[$field])) {
            $updateFields[] = "$field = ?";
            $params[] = $input[$field];
            $types .= $type;
        }
    }

    if (empty($updateFields)) {
        echo json_encode(["success" => false, "message" => "No changes detected."]);
        return;
    }

    $params[] = $paymentId;
    $types .= 'i';

    $query = "UPDATE payments SET " . implode(", ", $updateFields) . ", updated_at = NOW() WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();

    logActivity("Payment updated - ID: $paymentId");
    echo json_encode(["success" => true, "message" => "Payment updated successfully!"]);
}

function deletePayment($conn, $adminId) {
    logActivity("Starting deletePayment() - Admin ID: $adminId");
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $paymentId = isset($input['id']) ? (int)$input['id'] : 0;
    
    if (!$paymentId) {
        echo json_encode(["success" => false, "message" => "Payment ID is required."]);
        return;
    }

    $query = "UPDATE payments SET is_deleted = 1, deleted_at = NOW(), deleted_by = ? WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $adminId, $paymentId);
    $stmt->execute();
    $stmt->close();

    logActivity("Payment deleted - ID: $paymentId");
    echo json_encode(["success" => true, "message" => "Payment deleted successfully!"]);
}

function recordPayment($conn, $adminId) {
    logActivity("Starting recordPayment() - Admin ID: $adminId");
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $tenantCode = $input['tenant_code'] ?? '';
    $amount = isset($input['amount']) ? (float)$input['amount'] : 0;
    $paymentMethod = $input['payment_method'] ?? 'cash';
    
    if (!$tenantCode || $amount <= 0) {
        echo json_encode(["success" => false, "message" => "Tenant code and amount are required."]);
        return;
    }

    // Get tenant's apartment
    $tenantStmt = $conn->prepare("SELECT apartment_code FROM tenants WHERE tenant_code = ? AND status = 1");
    $tenantStmt->bind_param("s", $tenantCode);
    $tenantStmt->execute();
    $tenantResult = $tenantStmt->get_result();
    
    if ($tenantResult->num_rows === 0) {
        echo json_encode(["success" => false, "message" => "Tenant not found."]);
        return;
    }
    
    $tenantData = $tenantResult->fetch_assoc();
    $apartmentCode = $tenantData['apartment_code'];
    $tenantStmt->close();

    $receiptNumber = 'QREC-' . date('Ymd') . '-' . strtoupper(uniqid());
    
    $conn->begin_transaction();

    try {
        $query = "INSERT INTO payments (
                    tenant_code, apartment_code, amount, 
                    payment_date, payment_method, payment_status,
                    receipt_number, recorded_by, created_at
                  ) VALUES (?, ?, ?, CURDATE(), ?, 'completed', ?, ?, NOW())";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ssdsss", $tenantCode, $apartmentCode, $amount, $paymentMethod, $receiptNumber, $adminId);
        $stmt->execute();
        $paymentId = $stmt->insert_id;
        $stmt->close();
        
        logActivity("Quick payment recorded - ID: $paymentId | Receipt: $receiptNumber");

        $conn->commit();

        echo json_encode([
            "success" => true,
            "message" => "Payment recorded successfully!",
            "receipt_number" => $receiptNumber,
            "payment_id" => $paymentId
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}

function generateInvoice($conn, $adminId) {
    logActivity("Starting generateInvoice() - Admin ID: $adminId");
    
    $paymentId = isset($_GET['payment_id']) ? (int)$_GET['payment_id'] : 0;
    
    if (!$paymentId) {
        echo json_encode(["success" => false, "message" => "Payment ID is required."]);
        return;
    }

    $query = "SELECT 
                p.*,
                CONCAT(t.firstname, ' ', t.lastname) as tenant_name,
                t.email as tenant_email,
                t.phone as tenant_phone,
                a.apartment_number,
                pr.name as property_name,
                pr.address as property_address
              FROM payments p
              LEFT JOIN tenants t ON p.tenant_code = t.tenant_code
              LEFT JOIN apartments a ON p.apartment_code = a.apartment_code
              LEFT JOIN properties pr ON a.property_code = pr.property_code
              WHERE p.id = ? AND p.is_deleted = 0";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $paymentId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(["success" => false, "message" => "Payment not found."]);
        return;
    }

    $invoiceData = $result->fetch_assoc();
    $stmt->close();
    
    $invoiceNumber = 'INV-' . date('Ymd') . '-' . str_pad($paymentId, 6, '0', STR_PAD_LEFT);
    
    echo json_encode([
        "success" => true,
        "invoice" => array_merge($invoiceData, [
            'invoice_number' => $invoiceNumber,
            'invoice_date' => date('F d, Y'),
            'due_date' => $invoiceData['due_date'] ? date('F d, Y', strtotime($invoiceData['due_date'])) : 'N/A'
        ])
    ]);
}

function getPaymentStatistics($conn) {
    logActivity("Starting getPaymentStatistics()");
    
    $statsQuery = "SELECT 
                    COUNT(*) as total_payments,
                    SUM(amount) as total_revenue,
                    AVG(amount) as average_payment,
                    COUNT(CASE WHEN payment_status = 'completed' THEN 1 END) as completed_payments,
                    COUNT(CASE WHEN payment_status = 'pending' THEN 1 END) as pending_payments,
                    SUM(CASE WHEN payment_status = 'completed' THEN amount ELSE 0 END) as completed_revenue
                   FROM payments 
                   WHERE is_deleted = 0";
    
    $statsStmt = $conn->prepare($statsQuery);
    $statsStmt->execute();
    $stats = $statsStmt->get_result()->fetch_assoc();
    $statsStmt->close();

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
    
    $trendStmt = $conn->prepare($trendQuery);
    $trendStmt->execute();
    $trendResult = $trendStmt->get_result();
    
    $revenueTrend = [];
    while ($row = $trendResult->fetch_assoc()) {
        $revenueTrend[] = $row;
    }
    $trendStmt->close();

    // Payment method distribution
    $methodQuery = "SELECT 
                    payment_method,
                    COUNT(*) as count,
                    SUM(amount) as total_amount
                   FROM payments 
                   WHERE is_deleted = 0
                   GROUP BY payment_method";
    
    $methodStmt = $conn->prepare($methodQuery);
    $methodStmt->execute();
    $methodResult = $methodStmt->get_result();
    
    $methodDistribution = [];
    while ($row = $methodResult->fetch_assoc()) {
        $methodDistribution[] = $row;
    }
    $methodStmt->close();

    echo json_encode([
        "success" => true,
        "statistics" => [
            "summary" => $stats,
            "revenue_trend" => $revenueTrend,
            "method_distribution" => $methodDistribution
        ]
    ]);
}
?>