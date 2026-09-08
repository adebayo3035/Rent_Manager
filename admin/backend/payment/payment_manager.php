<?php
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../../../tenant/backend/utilities/notification_helper.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
if (!isset($_SESSION))
    session_start();
rateLimiter();

header('Content-Type: application/json');

// Helper function to get client IP and user agent for better logging
function getRequestDetails()
{
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

    // Helper function to get client IP and user agent for better logging

    switch ($action) {
        case 'fetch':
            fetchPayments($conn, $adminId, $userRole);
            break;
        case 'export':
            exportPayments($conn);
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
        case 'update_status':
            logActivity("Initiating payment status update - User: $adminId");
            updatePaymentStatus($conn, $adminId);
            break;
        case 'delete':
            logActivity("Initiating payment deletion - User: $adminId");
            deletePayment($conn, $adminId);
            break;
        case 'restore':
            logActivity("Initiating payment deletion - User: $adminId");
            restorePayment($conn, $adminId);
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

function fetchPayments($conn, $adminId, $userRole)
{
    logActivity("Starting fetchPayments() - User: $adminId | Role: $userRole");

    $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
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

    // --- Build WHERE clause using a more structured approach ---
    $filters = [];
    $params = [];
    $types = '';

    // Handle deleted filter - THIS IS THE KEY FIX
    if ($paymentStatus === 'is_deleted') {
        $filters[] = ['p.is_deleted = 1', null];
        logActivity("Filter: Show only deleted payments");
    } else {
        $filters[] = ['p.is_deleted = 0', null];
        logActivity("Filter: Exclude deleted payments");
    }

    // Add other filters
    if ($tenantCode) {
        $filters[] = ['p.tenant_code = ?', 's'];
        $params[] = $tenantCode;
        logActivity("Added tenant filter - Code: $tenantCode");
    }

    if ($propertyCode) {
        $filters[] = ['pr.property_code = ?', 's'];
        $params[] = $propertyCode;
        logActivity("Added property filter - Code: $propertyCode");
    }

    if ($apartmentCode) {
        $filters[] = ['p.apartment_code = ?', 's'];
        $params[] = $apartmentCode;
        logActivity("Added apartment filter - Code: $apartmentCode");
    }

    // Only add payment_status if it's not 'is_deleted' and it's valid
    $validStatuses = ['pending', 'completed', 'failed', 'refunded'];
    if ($paymentStatus && $paymentStatus !== 'is_deleted' && in_array($paymentStatus, $validStatuses)) {
        $filters[] = ['p.payment_status = ?', 's'];
        $params[] = $paymentStatus;
        logActivity("Added status filter - Status: $paymentStatus");
    }

    if ($paymentMethod && in_array($paymentMethod, ['cash', 'bank_transfer', 'card', 'cheque'])) {
        $filters[] = ['p.payment_method = ?', 's'];
        $params[] = $paymentMethod;
        logActivity("Added method filter - Method: $paymentMethod");
    }

    if ($dateFrom) {
        $filters[] = ['p.payment_date >= ?', 's'];
        $params[] = $dateFrom;
        logActivity("Added date from filter - Date: $dateFrom");
    }

    if ($dateTo) {
        $filters[] = ['p.payment_date <= ?', 's'];
        $params[] = $dateTo;
        logActivity("Added date to filter - Date: $dateTo");
    }

    if ($search) {
        $filters[] = [
            '(t.firstname LIKE ? OR t.lastname LIKE ? OR CONCAT(t.firstname, " ", t.lastname) LIKE ? OR p.receipt_number LIKE ? OR p.reference_number LIKE ?)',
            'sssss'
        ];
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        logActivity("Added search filter - Term: $search");
    }

    // Build WHERE clause
    $whereClauses = [];
    foreach ($filters as $filter) {
        $whereClauses[] = $filter[0];
        if ($filter[1]) {
            $types .= $filter[1];
        }
    }

    $whereSQL = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";
    logActivity("Where clause: $whereSQL");
    logActivity("Parameter types: $types");
    logActivity("Parameters: " . json_encode($params));

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

    if (!empty($params)) {
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

    if (!empty($paramsWithPagination)) {
        $stmt->bind_param($stmtTypes, ...$paramsWithPagination);
    }

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
        "user_role" => $userRole,
        "filters_applied" => [
            "payment_status" => $paymentStatus,
            "is_deleted_filter" => $paymentStatus === 'is_deleted' ? 'show_deleted' : 'hide_deleted'
        ]
    ];

    logActivity("fetchPayments() completed successfully");
    echo json_encode($response);
}

function fetchSinglePayment($conn)
{
    logActivity("Starting fetchSinglePayment()");

    $paymentId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
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
                pr.property_code as property_code,
                CONCAT(u.firstname, ' ', u.lastname) as recorded_by_name
              FROM payments p
              LEFT JOIN tenants t ON p.tenant_code = t.tenant_code
              LEFT JOIN apartments a ON p.apartment_code = a.apartment_code
              LEFT JOIN properties pr ON a.property_code = pr.property_code
              LEFT JOIN admin_tbl u ON p.recorded_by = u.unique_id
              WHERE p.id = ?";

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

function exportPayments($conn)
{
    logActivity("Starting exportPayments()");

    $tenantCode = isset($_GET['tenant_code']) ? trim($_GET['tenant_code']) : null;
    $propertyCode = isset($_GET['property_code']) ? trim($_GET['property_code']) : null;
    $apartmentCode = isset($_GET['apartment_code']) ? trim($_GET['apartment_code']) : null;
    $paymentStatus = isset($_GET['payment_status']) ? trim($_GET['payment_status']) : null;
    $paymentMethod = isset($_GET['payment_method']) ? trim($_GET['payment_method']) : null;
    $dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : null;
    $dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : null;
    $search = isset($_GET['search']) ? trim($_GET['search']) : null;

    $whereClauses = ["p.is_deleted = 0"];
    $params = [];
    $types = '';

    if ($tenantCode) {
        $whereClauses[] = "p.tenant_code = ?";
        $params[] = $tenantCode;
        $types .= 's';
    }

    if ($propertyCode) {
        $whereClauses[] = "pr.property_code = ?";
        $params[] = $propertyCode;
        $types .= 's';
    }

    if ($apartmentCode) {
        $whereClauses[] = "p.apartment_code = ?";
        $params[] = $apartmentCode;
        $types .= 's';
    }

    if ($paymentStatus && in_array($paymentStatus, ['pending', 'completed', 'failed', 'refunded'])) {
        $whereClauses[] = "p.payment_status = ?";
        $params[] = $paymentStatus;
        $types .= 's';
    }

    if ($paymentMethod && in_array($paymentMethod, ['cash', 'bank_transfer', 'card', 'cheque', 'admin_initiated'])) {
        $whereClauses[] = "p.payment_method = ?";
        $params[] = $paymentMethod;
        $types .= 's';
    }

    if ($dateFrom) {
        $whereClauses[] = "p.payment_date >= ?";
        $params[] = $dateFrom;
        $types .= 's';
    }

    if ($dateTo) {
        $whereClauses[] = "p.payment_date <= ?";
        $params[] = $dateTo;
        $types .= 's';
    }

    if ($search) {
        $whereClauses[] = "(t.firstname LIKE ? OR t.lastname LIKE ? OR CONCAT(t.firstname, ' ', t.lastname) LIKE ? OR p.receipt_number LIKE ? OR p.reference_number LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $types .= 'sssss';
    }

    $whereSQL = "WHERE " . implode(" AND ", $whereClauses);
    $query = "SELECT
                p.receipt_number,
                p.reference_number,
                p.amount,
                p.balance,
                p.payment_date,
                p.due_date,
                p.payment_method,
                p.payment_status,
                p.description,
                p.notes,
                p.created_at,
                CONCAT(t.firstname, ' ', t.lastname) as tenant_name,
                t.email as tenant_email,
                t.phone as tenant_phone,
                t.tenant_code,
                a.apartment_number,
                pr.name as property_name,
                pr.property_code,
                CONCAT(u.firstname, ' ', u.lastname) as recorded_by_name
              FROM payments p
              LEFT JOIN tenants t ON p.tenant_code = t.tenant_code
              LEFT JOIN apartments a ON p.apartment_code = a.apartment_code
              LEFT JOIN properties pr ON a.property_code = pr.property_code
              LEFT JOIN admin_tbl u ON p.recorded_by = u.unique_id
              $whereSQL
              ORDER BY p.payment_date DESC, p.id DESC";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logActivity("ERROR preparing export statement: " . $conn->error);
        echo json_encode(["success" => false, "message" => "Failed to prepare export query"]);
        return;
    }

    if ($params) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $filename = "payments_export_" . date('Ymd_His') . ".xls";
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    echo "<table border=\"1\">";
    echo "<thead><tr>";
    $headers = [
        'Receipt #',
        'Reference #',
        'Tenant',
        'Tenant Code',
        'Email',
        'Phone',
        'Property',
        'Property Code',
        'Apartment',
        'Amount',
        'Balance',
        'Payment Date',
        'Due Date',
        'Method',
        'Status',
        'Type',
        'Recorded By',
        'Description',
        'Notes',
        'Created At'
    ];

    foreach ($headers as $header) {
        echo "<th>" . htmlspecialchars($header, ENT_QUOTES, 'UTF-8') . "</th>";
    }
    echo "</tr></thead><tbody>";

    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        $values = [
            $row['receipt_number'] ?? '',
            $row['reference_number'] ?? '',
            $row['tenant_name'] ?? '',
            $row['tenant_code'] ?? '',
            $row['tenant_email'] ?? '',
            $row['tenant_phone'] ?? '',
            $row['property_name'] ?? '',
            $row['property_code'] ?? '',
            $row['apartment_number'] ?? '',
            number_format((float) ($row['amount'] ?? 0), 2),
            number_format((float) ($row['balance'] ?? 0), 2),
            $row['payment_date'] ?? '',
            $row['due_date'] ?? '',
            ucwords(str_replace('_', ' ', $row['payment_method'] ?? '')),
            ucwords(str_replace('_', ' ', $row['payment_status'] ?? '')),
            ucwords(str_replace('_', ' ', $row['payment_type'] ?? '')),
            $row['recorded_by_name'] ?? '',
            $row['description'] ?? '',
            $row['notes'] ?? '',
            $row['created_at'] ?? ''
        ];

        foreach ($values as $value) {
            echo "<td>" . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . "</td>";
        }
        echo "</tr>";
    }

    echo "</tbody></table>";
    $stmt->close();
    logActivity("exportPayments() completed successfully");
    exit();
}

function createPayment($conn, $adminId)
{
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
    $amount = (float) $input['amount'];
    $paymentDate = $input['payment_date'];
    $paymentMethod = $input['payment_method'];
    $paymentStatus = $input['payment_status'] ?? 'completed';
    $referenceNumber = $input['reference_number'] ?? null;
    $description = $input['description'] ?? null;
    $dueDate = $input['due_date'] ?? null;
    $balance = isset($input['balance']) ? (float) $input['balance'] : 0;

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
            $tenantCode,
            $apartmentCode,
            $amount,
            $balance,
            $paymentDate,
            $dueDate,
            $paymentMethod,
            $paymentStatus,
            $receiptNumber,
            $referenceNumber,
            $description,
            $adminId
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

function updatePayment($conn, $adminId)
{
    logActivity("Starting updatePayment() - Admin ID: $adminId");

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $paymentId = isset($input['id']) ? (int) $input['id'] : 0;

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

    $updatableFields = [
        'amount' => 'd',
        'balance' => 'd',
        'payment_date' => 's',
        'due_date' => 's',
        'payment_method' => 's',
        'payment_status' => 's',
        'reference_number' => 's',
        'description' => 's'
    ];

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

function deletePayment($conn, $adminId)
{
    logActivity("Starting deletePayment() - Admin ID: $adminId");

    // Get input from both JSON and form data
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    // Support both 'id' and 'payment_id' keys
    $paymentId = isset($input['id']) ? (int) $input['id'] : 0;
    if (!$paymentId) {
        $paymentId = isset($input['payment_id']) ? (int) $input['payment_id'] : 0;
    }

    if (!$paymentId) {
        logActivity("ERROR: Payment ID is missing or invalid");
        echo json_encode([
            "success" => false,
            "message" => "Payment ID is required."
        ]);
        return;
    }

    logActivity("Attempting to delete payment - ID: $paymentId");

    // First, verify the payment exists and is active
    $checkQuery = "SELECT id, receipt_number, is_deleted FROM payments WHERE id = ?";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bind_param("i", $paymentId);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    $payment = $result->fetch_assoc();
    $checkStmt->close();

    if (!$payment) {
        logActivity("ERROR: Payment not found - ID: $paymentId");
        echo json_encode([
            "success" => false,
            "message" => "Payment not found."
        ]);
        return;
    }

    // Check if payment is already deleted
    if ($payment['is_deleted'] == 1) {
        logActivity("WARNING: Payment is already deleted - ID: $paymentId");
        echo json_encode([
            "success" => false,
            "message" => "Payment is already deleted."
        ]);
        return;
    }

    // Soft delete the payment
    $query = "UPDATE payments 
              SET is_deleted = 1, 
                  deleted_at = NOW(), 
                  deleted_by = ? 
              WHERE id = ?";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $adminId, $paymentId);

    if ($stmt->execute()) {
        $affectedRows = $stmt->affected_rows;
        $stmt->close();

        logActivity("Payment deleted successfully - ID: $paymentId, Receipt: {$payment['receipt_number']}, Deleted By: $adminId");

        echo json_encode([
            "success" => true,
            "message" => "Payment deleted successfully!",
            "data" => [
                "payment_id" => $paymentId,
                "receipt_number" => $payment['receipt_number'],
                "deleted_at" => date('Y-m-d H:i:s'),
                "deleted_by" => $adminId
            ]
        ]);
    } else {
        logActivity("ERROR: Failed to delete payment - ID: $paymentId, Error: " . $stmt->error);
        echo json_encode([
            "success" => false,
            "message" => "Failed to delete payment: " . $stmt->error
        ]);
        $stmt->close();
    }
}

function restorePayment($conn, $adminId)
{
    logActivity("Starting restorePayment() - Admin ID: $adminId");

    // Get input from both JSON and form data
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    // Support both 'id' and 'payment_id' keys
    $paymentId = isset($input['id']) ? (int) $input['id'] : 0;
    if (!$paymentId) {
        $paymentId = isset($input['payment_id']) ? (int) $input['payment_id'] : 0;
    }

    if (!$paymentId) {
        logActivity("ERROR: Payment ID is missing or invalid");
        echo json_encode([
            "success" => false,
            "message" => "Payment ID is required."
        ]);
        return;
    }

    logActivity("Attempting to restore payment - ID: $paymentId");

    // First, verify the payment exists and is deleted
    $checkQuery = "SELECT id, receipt_number, is_deleted FROM payments WHERE id = ?";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bind_param("i", $paymentId);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    $payment = $result->fetch_assoc();
    $checkStmt->close();

    if (!$payment) {
        logActivity("ERROR: Payment not found - ID: $paymentId");
        echo json_encode([
            "success" => false,
            "message" => "Payment not found."
        ]);
        return;
    }

    // Check if payment is already active (not deleted)
    if ($payment['is_deleted'] == 0 || $payment['is_deleted'] === null) {
        logActivity("WARNING: Payment is already active - ID: $paymentId");
        echo json_encode([
            "success" => false,
            "message" => "Payment is already active and not deleted."
        ]);
        return;
    }

    // Restore the payment
    $query = "UPDATE payments 
              SET is_deleted = 0, 
                  deleted_at = NULL, 
                  restored_at = NOW(), 
                  restored_by = ? 
              WHERE id = ?";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $adminId, $paymentId);

    if ($stmt->execute()) {
        $affectedRows = $stmt->affected_rows;
        $stmt->close();

        logActivity("Payment restored successfully - ID: $paymentId, Receipt: {$payment['receipt_number']}, Restored By: $adminId");

        echo json_encode([
            "success" => true,
            "message" => "Payment restored successfully!",
            "data" => [
                "payment_id" => $paymentId,
                "receipt_number" => $payment['receipt_number'],
                "restored_at" => date('Y-m-d H:i:s'),
                "restored_by" => $adminId
            ]
        ]);
    } else {
        logActivity("ERROR: Failed to restore payment - ID: $paymentId, Error: " . $stmt->error);
        echo json_encode([
            "success" => false,
            "message" => "Failed to restore payment: " . $stmt->error
        ]);
        $stmt->close();
    }
}

function recordPayment($conn, $adminId)
{
    // Generate unique request ID for tracking
    $requestId = uniqid('admin_fee_payment_', true);

    logActivity("[ADMIN_FEE_PAYMENT] [ID:{$requestId}] ========== START ==========");
    logActivity("[ADMIN_FEE_PAYMENT] [ID:{$requestId}] Admin ID: {$adminId}");

    // ==================== GET INPUT ====================

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        $input = $_POST;
    }

    // Extract input fields
    $tenantCode = $input['tenant_code'] ?? '';
    $tenantFeeId = isset($input['tenant_fee_id']) ? (int) $input['tenant_fee_id'] : 0;
    $amount = isset($input['amount']) ? (float) $input['amount'] : 0;
    $paymentMethod = $input['payment_method'] ?? 'cash';
    $referenceNumber = $input['reference_number'] ?? null;
    $dueDate = $input['due_date'] ?? '';
    $notes = $input['notes'] ?? '';

    logActivity(
        "[ADMIN_FEE_PAYMENT] [ID:{$requestId}] Data: " .
        "tenant_code={$tenantCode}, " .
        "tenant_fee_id={$tenantFeeId}, " .
        "amount={$amount}, " .
        "due_date={$dueDate}"
    );

    // ==================== VALIDATION ====================

    // Validate tenant code
    if (!$tenantCode) {
        logActivity("[ADMIN_FEE_PAYMENT] [ID:{$requestId}] ERROR: Tenant code required");

        echo json_encode([
            "success" => false,
            "message" => "Tenant code is required."
        ]);

        return;
    }

    // Validate fee ID
    if (!$tenantFeeId) {
        logActivity("[ADMIN_FEE_PAYMENT] [ID:{$requestId}] ERROR: Fee type required");

        echo json_encode([
            "success" => false,
            "message" => "Fee type is required."
        ]);

        return;
    }

    // Validate amount
    if ($amount <= 0) {
        logActivity("[ADMIN_FEE_PAYMENT] [ID:{$requestId}] ERROR: Invalid amount");

        echo json_encode([
            "success" => false,
            "message" => "Valid amount is required."
        ]);

        return;
    }

    // Validate due date
    if (!$dueDate) {
        logActivity("[ADMIN_FEE_PAYMENT] [ID:{$requestId}] ERROR: Due date required");

        echo json_encode([
            "success" => false,
            "message" => "Due date is required."
        ]);

        return;
    }

    // Validate payment method
    $allowed_methods = [
        'bank_transfer',
        'card',
        'cash',
        'cheque',
        'mobile_money'
    ];

    if (!in_array($paymentMethod, $allowed_methods, true)) {
        logActivity("[ADMIN_FEE_PAYMENT] [ID:{$requestId}] ERROR: Invalid payment method");

        echo json_encode([
            "success" => false,
            "message" => "Invalid payment method."
        ]);

        return;
    }

    // ==================== START TRANSACTION ====================

    $conn->begin_transaction();

    logActivity("[ADMIN_FEE_PAYMENT] [ID:{$requestId}] Transaction started");

    try {

        // =========================================================
        // STEP 1: FETCH FEE DETAILS AND LOCK RECORD
        // =========================================================

        logActivity(
            "[ADMIN_FEE_PAYMENT] [ID:{$requestId}] Fetching fee details with lock"
        );

        $fee_query = "
            SELECT 
                tf.*,
                ft.fee_name,
                ft.fee_code,
                ft.is_recurring,
                ft.recurrence_period,
                a.apartment_number,
                a.apartment_code,
                a.property_code,
                p.name AS property_name,
                CONCAT(t.firstname, ' ', t.lastname) AS tenant_name,
                t.email AS tenant_email,
                t.phone AS tenant_phone
            FROM tenant_fees tf
            JOIN fee_types ft 
                ON tf.fee_type_id = ft.fee_type_id
            JOIN apartments a 
                ON tf.apartment_code = a.apartment_code
            JOIN properties p 
                ON a.property_code = p.property_code
            JOIN tenants t 
                ON tf.tenant_code = t.tenant_code
            WHERE tf.tenant_fee_id = ?
              AND tf.tenant_code = ?
            FOR UPDATE
        ";

        $fee_stmt = $conn->prepare($fee_query);

        if (!$fee_stmt) {
            throw new Exception(
                "Failed to prepare fee query: " . $conn->error,
                500
            );
        }

        $fee_stmt->bind_param(
            "is",
            $tenantFeeId,
            $tenantCode
        );

        if (!$fee_stmt->execute()) {
            throw new Exception(
                "Failed to fetch fee details: " . $fee_stmt->error,
                500
            );
        }

        $fee_result = $fee_stmt->get_result();
        $fee = $fee_result->fetch_assoc();

        $fee_stmt->close();

        if (!$fee) {
            logActivity(
                "[ADMIN_FEE_PAYMENT] [ID:{$requestId}] ERROR: Fee not found"
            );

            throw new Exception(
                "Fee not found or does not belong to this tenant",
                404
            );
        }

        logActivity(
            "[ADMIN_FEE_PAYMENT] [ID:{$requestId}] " .
            "Fee found: {$fee['fee_name']} - " .
            "Status: {$fee['status']} - " .
            "Amount: {$fee['amount']}"
        );

        // =========================================================
        // STEP 2: VALIDATE FEE STATUS
        // =========================================================

        if ($fee['status'] === 'paid') {

            logActivity(
                "[ADMIN_FEE_PAYMENT] [ID:{$requestId}] ERROR: Fee already paid"
            );

            throw new Exception(
                "This fee has already been paid",
                400
            );
        }

        if ($fee['status'] === 'cancelled') {

            logActivity(
                "[ADMIN_FEE_PAYMENT] [ID:{$requestId}] ERROR: Fee has been cancelled"
            );

            throw new Exception(
                "This fee has been cancelled and cannot be paid",
                400
            );
        }

        // =========================================================
        // STEP 3: VALIDATE PAYMENT AMOUNT
        // =========================================================

        $feeAmount = (float) $fee['amount'];
        $paymentAmount = (float) $amount;

        $amountDiff = abs($paymentAmount - $feeAmount);

        $amountMatches = ($amountDiff < 0.01);

        logActivity(
            "[ADMIN_FEE_PAYMENT] [ID:{$requestId}] " .
            "Amount validation - " .
            "Fee: {$feeAmount}, " .
            "Payment: {$paymentAmount}, " .
            "Diff: {$amountDiff}"
        );

        if (!$amountMatches) {

            logActivity(
                "[ADMIN_FEE_PAYMENT] [ID:{$requestId}] " .
                "ERROR: Amount mismatch - " .
                "Fee: {$feeAmount}, " .
                "Payment: {$paymentAmount}"
            );

            throw new Exception(
                "Payment amount ({$paymentAmount}) does not match " .
                "the fee amount ({$feeAmount}). Please use the correct amount.",
                400
            );
        }

        // =========================================================
        // STEP 4: VALIDATE DUE DATE
        // =========================================================

        $feeDueDate = $fee['due_date'];
        $inputDueDate = $dueDate;

        logActivity(
            "[ADMIN_FEE_PAYMENT] [ID:{$requestId}] " .
            "Due date validation - " .
            "Fee: {$feeDueDate}, " .
            "Input: {$inputDueDate}"
        );

        $dueDateMatches = ($feeDueDate === $inputDueDate);

        $isOverdue = (
            strtotime($feeDueDate) < strtotime(date('Y-m-d'))
        );

        if (!$dueDateMatches && !$isOverdue) {

            logActivity(
                "[ADMIN_FEE_PAYMENT] [ID:{$requestId}] " .
                "WARNING: Due date mismatch - Fee: {$feeDueDate}, " .
                "Input: {$inputDueDate}"
            );

            // Use original fee due date
            $dueDate = $feeDueDate;

            logActivity(
                "[ADMIN_FEE_PAYMENT] [ID:{$requestId}] " .
                "Using fee due date: {$feeDueDate}"
            );

        } elseif ($isOverdue && !$dueDateMatches) {

            logActivity(
                "[ADMIN_FEE_PAYMENT] [ID:{$requestId}] " .
                "Fee is overdue, allowing payment with original due date"
            );

            $dueDate = $feeDueDate;
        }

        // =========================================================
        // STEP 5: GENERATE IDENTIFIERS
        // =========================================================

        $receipt_number =
            'RCT-' .
            date('Ymd') .
            '-' .
            strtoupper(substr(uniqid(), -6));

        $transaction_id =
            'TXN-' .
            date('Ymd') .
            '-' .
            time() .
            '-' .
            rand(1000, 9999);

        logActivity(
            "[ADMIN_FEE_PAYMENT] [ID:{$requestId}] " .
            "Receipt: {$receipt_number}, " .
            "Transaction: {$transaction_id}"
        );

        // =========================================================
        // STEP 6: UPDATE TENANT FEES TABLE
        // =========================================================

        $update_fee_query = "
            UPDATE tenant_fees
            SET 
                status = 'paid',
                payment_date = NOW(),
                payment_method = ?,
                receipt_number = ?,
                payment_id = ?,
                notes = CONCAT(
                    IFNULL(notes, ''),
                    '\n',
                    'Paid on ',
                    NOW(),
                    ' via ',
                    ?,
                    ' by Admin. Receipt: ',
                    ?
                )
            WHERE tenant_fee_id = ?
              AND tenant_code = ?
        ";

        $update_stmt = $conn->prepare($update_fee_query);

        if (!$update_stmt) {
            throw new Exception(
                "Failed to prepare tenant fee update: " . $conn->error,
                500
            );
        }

        $update_stmt->bind_param(
            "sssssis",
            $paymentMethod,
            $receipt_number,
            $transaction_id,
            $paymentMethod,
            $receipt_number,
            $tenantFeeId,
            $tenantCode
        );

        if (!$update_stmt->execute()) {

            logActivity(
                "[ADMIN_FEE_PAYMENT] [ID:{$requestId}] " .
                "ERROR: Failed to update tenant_fees: " .
                $update_stmt->error
            );

            throw new Exception(
                "Failed to update fee status",
                500
            );
        }

        $affectedRows = $update_stmt->affected_rows;

        // Verify that the update actually happened
        if ($affectedRows === 0) {

            logActivity(
                "[ADMIN_FEE_PAYMENT] [ID:{$requestId}] " .
                "WARNING: No rows affected"
            );

            $check_query = "
                SELECT status
                FROM tenant_fees
                WHERE tenant_fee_id = ?
            ";

            $check_stmt = $conn->prepare($check_query);

            if (!$check_stmt) {
                throw new Exception(
                    "Failed to prepare fee status check: " . $conn->error,
                    500
                );
            }

            $check_stmt->bind_param(
                "i",
                $tenantFeeId
            );

            $check_stmt->execute();

            $check_result = $check_stmt->get_result();
            $check_row = $check_result->fetch_assoc();

            $check_stmt->close();

            if ($check_row && $check_row['status'] === 'paid') {

                throw new Exception(
                    "This fee was already paid by another process",
                    409
                );
            }
        }

        $update_stmt->close();

        logActivity(
            "[ADMIN_FEE_PAYMENT] [ID:{$requestId}] " .
            "tenant_fees updated. Affected rows: {$affectedRows}"
        );

        // =========================================================
        // STEP 7: BUILD PAYMENT NOTES
        // =========================================================

        $payment_notes =
            "Paid on " .
            date('Y-m-d H:i:s') .
            " via " .
            $paymentMethod .
            " by Admin ID: " .
            $adminId .
            ". Receipt Number: " .
            $receipt_number .
            " (Fee ID: " .
            $tenantFeeId .
            ")";

        if ($notes) {
            $payment_notes .=
                "\nAdmin Notes: " .
                $notes;
        }

        // =========================================================
        // STEP 8: RECORD PAYMENT
        // =========================================================

        $payment_query = "
            INSERT INTO payments (
                tenant_code,
                apartment_code,
                amount,
                balance,
                payment_date,
                due_date,
                payment_method,
                payment_status,
                receipt_number,
                reference_number,
                description,
                recorded_by,
                created_at,
                payment_category,
                notes
            )
            VALUES (
                ?,
                ?,
                ?,
                0,
                NOW(),
                ?,
                ?,
                'completed',
                ?,
                ?,
                ?,
                ?,
                NOW(),
                'fee',
                ?
            )
        ";

        $description =
            "Fee payment: " .
            $fee['fee_name'] .
            " (" .
            $fee['fee_code'] .
            ") - Recorded by Admin";

        $payment_stmt = $conn->prepare($payment_query);

        if (!$payment_stmt) {

            logActivity(
                "[ADMIN_FEE_PAYMENT] [ID:{$requestId}] " .
                "ERROR: Failed to prepare payment query: " .
                $conn->error
            );

            throw new Exception(
                "Failed to prepare payment query",
                500
            );
        }

        $payment_stmt->bind_param(
            "ssdsssssss",
            $tenantCode,
            $fee['apartment_code'],
            $feeAmount,
            $dueDate,
            $paymentMethod,
            $receipt_number,
            $referenceNumber,
            $description,
            $adminId,
            $payment_notes
        );

        if (!$payment_stmt->execute()) {

            logActivity(
                "[ADMIN_FEE_PAYMENT] [ID:{$requestId}] " .
                "ERROR: Failed to insert into payments: " .
                $payment_stmt->error
            );

            throw new Exception(
                "Failed to record payment",
                500
            );
        }

        $payment_id = $payment_stmt->insert_id;

        $payment_stmt->close();

        logActivity(
            "[ADMIN_FEE_PAYMENT] [ID:{$requestId}] " .
            "Payment record created. ID: {$payment_id}"
        );

        // =========================================================
        // STEP 9: GENERATE NEXT RECURRING FEE
        // =========================================================

        $next_fee_created = false;
        $next_due_date = null;

        if (
            $fee['is_recurring'] == 1 &&
            $fee['recurrence_period'] !== 'one-time'
        ) {

            logActivity(
                "[ADMIN_FEE_PAYMENT] [ID:{$requestId}] " .
                "Processing recurring fee"
            );

            switch (strtolower($fee['recurrence_period'])) {

                case 'monthly':
                    $next_due_date = date(
                        'Y-m-d',
                        strtotime($fee['due_date'] . ' +1 month')
                    );
                    break;

                case 'quarterly':
                    $next_due_date = date(
                        'Y-m-d',
                        strtotime($fee['due_date'] . ' +3 months')
                    );
                    break;

                case 'semi-annually':
                case 'semi_annually':
                    $next_due_date = date(
                        'Y-m-d',
                        strtotime($fee['due_date'] . ' +6 months')
                    );
                    break;

                case 'annually':
                    $next_due_date = date(
                        'Y-m-d',
                        strtotime($fee['due_date'] . ' +1 year')
                    );
                    break;

                default:
                    $next_due_date = date(
                        'Y-m-d',
                        strtotime($fee['due_date'] . ' +1 month')
                    );
                    break;
            }

            // =====================================================
            // CHECK IF NEXT FEE ALREADY EXISTS
            // =====================================================

            $check_next_query = "
                SELECT tenant_fee_id, status
                FROM tenant_fees
                WHERE tenant_code = ?
                  AND fee_type_id = ?
                  AND due_date = ?
            ";

            $check_stmt = $conn->prepare($check_next_query);

            if (!$check_stmt) {
                throw new Exception(
                    "Failed to prepare recurring fee check: " . $conn->error,
                    500
                );
            }

            $check_stmt->bind_param(
                "sis",
                $tenantCode,
                $fee['fee_type_id'],
                $next_due_date
            );

            if (!$check_stmt->execute()) {
                throw new Exception(
                    "Failed to check existing recurring fee: " .
                    $check_stmt->error,
                    500
                );
            }

            $check_result = $check_stmt->get_result();
            $existing_next = $check_result->fetch_assoc();

            $check_stmt->close();

            // =====================================================
            // NEXT FEE ALREADY EXISTS
            // =====================================================

            if ($existing_next) {

                logActivity(
                    "[ADMIN_FEE_PAYMENT] [ID:{$requestId}] " .
                    "Next fee already exists. " .
                    "Status: {$existing_next['status']}"
                );

                if ($existing_next['status'] === 'pending') {
                    $next_fee_created = true;
                }

            } else {

                // =================================================
                // CREATE NEXT RECURRING FEE
                // =================================================

                $next_fee_query = "
                    INSERT INTO tenant_fees (
                        tenant_code,
                        apartment_code,
                        fee_type_id,
                        amount,
                        due_date,
                        status,
                        created_at,
                        notes
                    )
                    VALUES (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        'pending',
                        NOW(),
                        ?
                    )
                ";

                $next_stmt = $conn->prepare($next_fee_query);

                if (!$next_stmt) {

                    throw new Exception(
                        "Failed to prepare next recurring fee query: " .
                        $conn->error,
                        500
                    );
                }

                // Build notes for the new recurring fee
                $next_fee_notes =
                    "Auto-generated recurring fee. " .
                    "Previous fee ID: " .
                    $tenantFeeId .
                    ". Previous receipt: " .
                    $receipt_number;

                $next_stmt->bind_param(
                    "ssidss",
                    $tenantCode,
                    $fee['apartment_code'],
                    $fee['fee_type_id'],
                    $fee['amount'],
                    $next_due_date,
                    $next_fee_notes
                );

                if ($next_stmt->execute()) {

                    $next_fee_created = true;

                    logActivity(
                        "[ADMIN_FEE_PAYMENT] [ID:{$requestId}] " .
                        "Next recurring fee created with ID: " .
                        $next_stmt->insert_id
                    );

                } else {

                    logActivity(
                        "[ADMIN_FEE_PAYMENT] [ID:{$requestId}] " .
                        "WARNING: Failed to create next recurring fee: " .
                        $next_stmt->error
                    );
                }

                $next_stmt->close();
            }
        }

        // =========================================================
        // STEP 10: CREATE NOTIFICATION
        // =========================================================

        $notification_title = "Fee Payment Received";

        $notification_message =
            "Your payment of ₦" .
            number_format($feeAmount, 2) .
            " for " .
            $fee['fee_name'] .
            " has been recorded. Receipt No: " .
            $receipt_number;

        createNotification(
            $conn,
            $tenantCode,
            'payment',
            $notification_title,
            $notification_message,
            [
                'fee_id' => $tenantFeeId,
                'fee_name' => $fee['fee_name'],
                'amount' => $feeAmount,
                'receipt_number' => $receipt_number,
                'recorded_by_admin' => true,
                'admin_id' => $adminId
            ],
            'high'
        );

        logActivity(
            "[ADMIN_FEE_PAYMENT] [ID:{$requestId}] Notification created"
        );

        // =========================================================
        // STEP 11: COMMIT TRANSACTION
        // =========================================================

        $conn->commit();

        logActivity(
            "[ADMIN_FEE_PAYMENT] [ID:{$requestId}] " .
            "Transaction committed"
        );

        // =========================================================
        // STEP 12: RETURN RESPONSE
        // =========================================================

        echo json_encode([
            "success" => true,
            "message" => "Payment recorded successfully!",
            "receipt_number" => $receipt_number,
            "transaction_id" => $transaction_id,
            "payment_id" => $payment_id,
            "tenant_fee_id" => $tenantFeeId,
            "fee_type_id" => $fee['fee_type_id'],
            "amount" => $feeAmount,
            "fee_name" => $fee['fee_name'],
            "fee_code" => $fee['fee_code'],
            "tenant_name" => $fee['tenant_name'],
            "payment_date" => date('Y-m-d H:i:s'),
            "due_date" => $dueDate,
            "next_fee_created" => $next_fee_created,
            "next_due_date" => $next_fee_created
                ? $next_due_date
                : null,
            "receipt_url" =>
                "../admin/backend/payments/download_receipt.php" .
                "?receipt_number={$receipt_number}&type=fee"
        ]);

    } catch (Exception $e) {

        // Rollback entire transaction
        $conn->rollback();

        logActivity(
            "[ADMIN_FEE_PAYMENT] [ID:{$requestId}] " .
            "ERROR: " .
            $e->getMessage()
        );

        logActivity(
            "[ADMIN_FEE_PAYMENT] [ID:{$requestId}] " .
            "Trace: " .
            $e->getTraceAsString()
        );

        echo json_encode([
            "success" => false,
            "message" => $e->getMessage(),
            "error_code" => $e->getCode()
        ]);
    }
}
function generateInvoice($conn, $adminId)
{
    logActivity("Starting generateInvoice() - Admin ID: $adminId");

    $paymentId = isset($_GET['payment_id']) ? (int) $_GET['payment_id'] : 0;

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

function getPaymentStatistics($conn)
{
    logActivity("Starting getPaymentStatistics()");

    // Option 1: Remove the WHERE clause and handle all payments
    $statsQuery = "SELECT 
                    COUNT(*) as total_payments,
                    SUM(amount) as total_revenue,
                    AVG(amount) as average_payment,
                    COUNT(CASE WHEN payment_status = 'completed' AND is_deleted = 0 THEN 1 END) as completed_payments,
                    COUNT(CASE WHEN payment_status = 'pending' AND is_deleted = 0 THEN 1 END) as pending_payments,
                    COUNT(CASE WHEN payment_status = 'failed' AND is_deleted = 0 THEN 1 END) as failed_payments,
                    COUNT(CASE WHEN payment_status = 'cancelled' AND is_deleted = 0 THEN 1 END) as cancelled_payments,
                    COUNT(CASE WHEN payment_status = 'refunded' AND is_deleted = 0 THEN 1 END) as refunded_payments,
                    COUNT(CASE WHEN is_deleted = 1 THEN 1 END) as deleted_payments,
                    SUM(CASE WHEN payment_status = 'completed' AND is_deleted = 0 THEN amount ELSE 0 END) as completed_revenue,
                    SUM(CASE WHEN is_deleted = 1 THEN amount ELSE 0 END) as deleted_revenue
                   FROM payments";

    $statsStmt = $conn->prepare($statsQuery);
    $statsStmt->execute();
    $stats = $statsStmt->get_result()->fetch_assoc();
    $statsStmt->close();

    // Monthly revenue trend (excluding deleted payments)
    $trendQuery = "SELECT 
                    DATE_FORMAT(payment_date, '%Y-%m') as month,
                    DATE_FORMAT(payment_date, '%b') as month_name,
                    SUM(CASE WHEN is_deleted = 0 THEN amount ELSE 0 END) as revenue,
                    SUM(CASE WHEN is_deleted = 1 THEN amount ELSE 0 END) as deleted_revenue,
                    COUNT(CASE WHEN is_deleted = 0 THEN 1 END) as payment_count,
                    COUNT(CASE WHEN is_deleted = 1 THEN 1 END) as deleted_count
                   FROM payments 
                   WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
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

    // Payment method distribution (excluding deleted payments)
    $methodQuery = "SELECT 
                    payment_method,
                    COUNT(CASE WHEN is_deleted = 0 THEN 1 END) as count,
                    SUM(CASE WHEN is_deleted = 0 THEN amount ELSE 0 END) as total_amount,
                    COUNT(CASE WHEN is_deleted = 1 THEN 1 END) as deleted_count,
                    SUM(CASE WHEN is_deleted = 1 THEN amount ELSE 0 END) as deleted_amount
                   FROM payments 
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
function updatePaymentStatus($conn, $adminId)
{
    logActivity("Starting updatePaymentStatus() - Admin ID: $adminId");

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $trackerId = isset($input['tracker_id']) ? (int) $input['tracker_id'] : 0;
    $paymentId = isset($input['payment_id']) ? (int) $input['payment_id'] : 0;
    $newStatus = isset($input['status']) ? trim($input['status']) : '';
    $notes = isset($input['notes']) ? trim($input['notes']) : '';

    // If tracker_id is provided, use it; otherwise fall back to payment_id
    if (!$trackerId && !$paymentId) {
        echo json_encode(["success" => false, "message" => "Tracker ID or Payment ID is required."]);
        return;
    }

    $allowedStatuses = ['paid', 'failed'];
    if (!in_array($newStatus, $allowedStatuses)) {
        echo json_encode(["success" => false, "message" => "Invalid status. Allowed: " . implode(', ', $allowedStatuses)]);
        return;
    }

    $conn->begin_transaction();
    logActivity("Transaction started for status update");

    try {
        // 1. Get tracker record details
        if ($trackerId) {
            $trackerQuery = "
                SELECT 
                    t.*,
                    r.rent_payment_id,
                    r.amount as total_annual_rent,
                    r.balance as rent_payment_balance,
                    t.amount_paid as period_amount,
                    p.tenant_code,
                    p.apartment_code,
                    p.lease_end_date,
                    p.temp_lease_end_date
                FROM rent_payment_tracker t
                JOIN rent_payments r ON t.rent_payment_id = r.rent_payment_id
                JOIN tenants p ON t.tenant_code = p.tenant_code
                WHERE t.tracker_id = ?
                LIMIT 1
            ";
            $trackerStmt = $conn->prepare($trackerQuery);
            $trackerStmt->bind_param("i", $trackerId);
            $trackerStmt->execute();
            $trackerResult = $trackerStmt->get_result();

            if ($trackerResult->num_rows === 0) {
                throw new Exception("Tracker record not found");
            }

            $tracker = $trackerResult->fetch_assoc();
            $trackerStmt->close();

            logActivity("Found tracker record - Period #{$tracker['period_number']}, Current Status: {$tracker['status']}");

            // Verify the tracker is in pending_verification status
            if ($tracker['status'] !== 'pending_verification') {
                throw new Exception("Payment is not in pending verification status. Current status: {$tracker['status']}");
            }

        } else {
            // Fallback: Get via payment_id from payments table
            $fallbackQuery = "
                SELECT 
                    t.tracker_id,
                    t.*,
                    r.rent_payment_id,
                    p.tenant_code
                FROM payments pay
                JOIN rent_payment_tracker t ON pay.id = t.payment_id
                JOIN rent_payments r ON t.rent_payment_id = r.rent_payment_id
                JOIN tenants p ON t.tenant_code = p.tenant_code
                WHERE pay.id = ?
                LIMIT 1
            ";
            $fallbackStmt = $conn->prepare($fallbackQuery);
            $fallbackStmt->bind_param("i", $paymentId);
            $fallbackStmt->execute();
            $fallbackResult = $fallbackStmt->get_result();

            if ($fallbackResult->num_rows === 0) {
                throw new Exception("Payment record not found");
            }

            $tracker = $fallbackResult->fetch_assoc();
            $fallbackStmt->close();
            $trackerId = $tracker['tracker_id'];
        }

        $oldStatus = $tracker['status'];
        $periodAmount = (float) $tracker['period_amount'];
        $periodEndDate = $tracker['end_date'];

        logActivity("Processing payment - Period #{$tracker['period_number']}, Amount: {$periodAmount}, New Status: {$newStatus}");

        // 2. Update tracker record
        if ($newStatus === 'paid') {
            // APPROVE payment
            $updateTrackerQuery = "
                UPDATE rent_payment_tracker 
                SET status = 'paid',
                    verified_by = ?,
                    verified_at = NOW(),
                    admin_notes = CONCAT(IFNULL(admin_notes, ''), '\n[VERIFIED] Status changed from {$oldStatus} to {$newStatus} on ', NOW(), ' by Admin ID: {$adminId}\nNotes: {$notes}'),
                    payment_date = IFNULL(payment_date, NOW())
                WHERE tracker_id = ?
            ";
            $updateStmt = $conn->prepare($updateTrackerQuery);
            $updateStmt->bind_param("ii", $adminId, $trackerId);
            $updateStmt->execute();
            $updateStmt->close();

            // Update rent_payments balance
            $newRentBalance = $tracker['rent_payment_balance'] - $periodAmount;
            $updateRentQuery = "
                UPDATE rent_payments 
                SET amount_paid = amount_paid + ?,
                    balance = ?,
                    updated_at = NOW()
                WHERE rent_payment_id = ?
            ";
            $updateRentStmt = $conn->prepare($updateRentQuery);
            $updateRentStmt->bind_param("dds", $periodAmount, $newRentBalance, $tracker['rent_payment_id']);
            $updateRentStmt->execute();
            $updateRentStmt->close();

            // Update tenant's rent_balance
            $updateTenantQuery = "
                UPDATE tenants 
                SET rent_balance = rent_balance - ?,
                    last_updated_at = NOW()
                WHERE tenant_code = ?
            ";
            $updateTenantStmt = $conn->prepare($updateTenantQuery);
            $updateTenantStmt->bind_param("ds", $periodAmount, $tracker['tenant_code']);
            $updateTenantStmt->execute();
            $updateTenantStmt->close();

            // Update temp_lease_end_date to this period's end date
            $updateLeaseQuery = "
                UPDATE tenants 
                SET temp_lease_end_date = ?
                WHERE tenant_code = ?
            ";
            $updateLeaseStmt = $conn->prepare($updateLeaseQuery);
            $updateLeaseStmt->bind_param("ss", $periodEndDate, $tracker['tenant_code']);
            $updateLeaseStmt->execute();
            $updateLeaseStmt->close();
            logActivity("Temp lease end date updated to: {$periodEndDate} for tenant: {$tracker['tenant_code']}");

            logActivity("Payment approved - Period #{$tracker['period_number']}, New rent balance: {$newRentBalance}");

        } elseif ($newStatus === 'failed') {
            // REJECT payment
            $updateTrackerQuery = "
                UPDATE rent_payment_tracker 
                SET status = 'failed',
                    verified_by = ?,
                    verified_at = NOW(),
                    admin_notes = CONCAT(IFNULL(admin_notes, ''), '\n[REJECTED] Status changed from {$oldStatus} to {$newStatus} on ', NOW(), ' by Admin ID: {$adminId}\nReason: {$notes}')
                WHERE tracker_id = ?
            ";
            $updateStmt = $conn->prepare($updateTrackerQuery);
            $updateStmt->bind_param("ii", $adminId, $trackerId);
            $updateStmt->execute();
            $updateStmt->close();

            logActivity("Payment rejected - Period #{$tracker['period_number']}, Reason: {$notes}");
        }

        // 3. Update payments table
        if ($tracker['payment_id']) {
            $paymentStatus = ($newStatus === 'paid') ? 'completed' : 'failed';
            $updatePaymentQuery = "
                UPDATE payments 
                SET payment_status = ?,
                    updated_at = NOW(),
                    notes = CONCAT(IFNULL(notes, ''), '\n[Admin Update] Status changed to {$paymentStatus} on ', NOW(), ' by Admin ID: {$adminId}\nNotes: {$notes}')
                WHERE id = ?
            ";
            $updatePaymentStmt = $conn->prepare($updatePaymentQuery);
            $updatePaymentStmt->bind_param("si", $paymentStatus, $tracker['payment_id']);
            $updatePaymentStmt->execute();
            $updatePaymentStmt->close();
        }

        // 4. Check if all periods are now paid
        $remainingQuery = "
            SELECT COUNT(*) as remaining_count 
            FROM rent_payment_tracker 
            WHERE rent_payment_id = ? 
            AND status != 'paid'
        ";
        $remainingStmt = $conn->prepare($remainingQuery);
        $remainingStmt->bind_param("s", $tracker['rent_payment_id']);
        $remainingStmt->execute();
        $remainingResult = $remainingStmt->get_result();
        $remainingData = $remainingResult->fetch_assoc();
        $remainingStmt->close();

        if ($remainingData['remaining_count'] == 0 && $newStatus === 'paid') {
            // All periods are paid - update rent_payments status to completed
            $completeRentQuery = "
                UPDATE rent_payments 
                SET status = 'completed',
                    updated_at = NOW()
                WHERE rent_payment_id = ?
            ";
            $completeStmt = $conn->prepare($completeRentQuery);
            $completeStmt->bind_param("s", $tracker['rent_payment_id']);
            $completeStmt->execute();
            $completeStmt->close();

            // Update tenant payment status
            $completeTenantQuery = "
                UPDATE tenants 
                SET payment_status = 'completed',
                    last_updated_at = NOW()
                WHERE tenant_code = ?
            ";
            $completeTenantStmt = $conn->prepare($completeTenantQuery);
            $completeTenantStmt->bind_param("s", $tracker['tenant_code']);
            $completeTenantStmt->execute();
            $completeTenantStmt->close();

            logActivity("All periods completed! Lease marked as fully paid for tenant: {$tracker['tenant_code']}");
        }

        $conn->commit();
        logActivity("Payment verification completed successfully - Tracker ID: $trackerId, New Status: $newStatus");

        echo json_encode([
            "success" => true,
            "message" => $newStatus === 'paid'
                ? "Payment approved successfully! Period #{$tracker['period_number']} marked as paid."
                : "Payment rejected. Period #{$tracker['period_number']} marked as failed.",
            "tracker_id" => $trackerId,
            "period_number" => $tracker['period_number'],
            "old_status" => $oldStatus,
            "new_status" => $newStatus
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        logActivity("ERROR in updatePaymentStatus: " . $e->getMessage());
        echo json_encode(["success" => false, "message" => "Failed to update payment status: " . $e->getMessage()]);
    }
}

?>