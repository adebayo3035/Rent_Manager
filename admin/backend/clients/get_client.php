<?php
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

try {
    // -----------------------------------------------------
    //  RATE LIMIT CHECK (Optional but recommended)
    // -----------------------------------------------------
    rateLimit("fetch_clients", 60, 60); // 60 requests per IP per minute


    // -----------------------------------------------------
    //  AUTHENTICATION CHECK
    // -----------------------------------------------------
    if (!isset($_SESSION['unique_id'])) {
        logActivity("Unauthorized access attempt | No session | IP: " . getClientIP());
        echo json_encode(["success" => false, "message" => "Not logged in."]);
        exit();
    }

    $adminId = $_SESSION['unique_id'];
    $loggedInUserRole = $_SESSION['role'] ?? 'Unknown';

    logActivity("Fetch Clients Request | AdminID: {$adminId} | Role: {$loggedInUserRole} | IP: " . getClientIP());


    // -----------------------------------------------------
    //  PAGINATION INPUTS
    // -----------------------------------------------------
    $page  = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    logActivity("Pagination Parsed | Page: {$page} | Limit: {$limit} | Offset: {$offset}");


    // -----------------------------------------------------
    //  DATABASE CONNECTION CHECK
    // -----------------------------------------------------
    if (!$conn) {
        logActivity("DB Connection Failure | IP: " . getClientIP());
        echo json_encode(["success" => false, "message" => "Database connection error."]);
        exit();
    }


    // -----------------------------------------------------
    //  FILTER INPUTS
    // -----------------------------------------------------
    $gender = isset($_GET['gender']) ? trim($_GET['gender']) : null;
    $status = isset($_GET['status']) ? trim($_GET['status']) : null;

    $allowedGender = ['Male', 'Female'];
    $allowedStatus = ['0', '1'];

    logActivity("Filter Inputs | Gender: {$gender} | Status: {$status} | AdminID: {$adminId}");


    // -----------------------------------------------------
    //  BUILD WHERE CLAUSE
    // -----------------------------------------------------
    $whereClauses = [];
    $params = [];
    $types = '';

    if ($gender !== null && in_array($gender, $allowedGender, true)) {
        $whereClauses[] = "clients.gender = ?";
        $params[] = $gender;
        $types .= 's';
    }

    if ($status !== null && in_array($status, $allowedStatus, true)) {
        $whereClauses[] = "clients.status = ?";
        $params[] = $status;
        $types .= 's';
    }

    $whereSQL = count($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

    logActivity("Generated WHERE SQL | {$whereSQL} | Params: " . json_encode($params));


    // -----------------------------------------------------
    //  TOTAL COUNT QUERY
    // -----------------------------------------------------
    $totalQuery = "SELECT COUNT(*) AS total FROM clients {$whereSQL}";
    logActivity("Preparing Total Count Query: {$totalQuery}");

    $countStmt = $conn->prepare($totalQuery);
    if (!$countStmt) {
        throw new Exception("Failed to prepare total count query: " . $conn->error);
    }

    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }

    $countStmt->execute();
    $totalResult = $countStmt->get_result();
    $totalClients = $totalResult->fetch_assoc()['total'] ?? 0;
    $countStmt->close();

    logActivity("Total Clients Count Found: {$totalClients}");


    // -----------------------------------------------------
    //  AGENTS QUERY
    // -----------------------------------------------------
    $query = "SELECT 
                clients.*
              FROM 
                clients
              {$whereSQL}
              ORDER BY 
                clients.status ASC, clients.client_id DESC
              LIMIT ? OFFSET ?";

    logActivity("Preparing Clients Query: {$query}");

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Failed to prepare clients fetch query: " . $conn->error);
    }

    // Add pagination params
    $paramsWithPagination = $params;
    $paramsWithPagination[] = $limit;
    $paramsWithPagination[] = $offset;

    $stmtTypes = $types . 'ii';
    $stmt->bind_param($stmtTypes, ...$paramsWithPagination);

    logActivity("Executing Clients Query | Params: " . json_encode($paramsWithPagination));

    $stmt->execute();
    $result = $stmt->get_result();

    $clients = [];
    while ($row = $result->fetch_assoc()) {
        $row['status_display'] = ($row['status'] === '1') ? 'Deactivated' : 'Activated';
        $clients[] = $row;
    }

    $stmt->close();
    $conn->close();


    logActivity("Clients Fetch Success | Returned: " . count($clients) . " rows");


    echo json_encode([
        "success" => true,
        "clients" => $clients,
         'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $totalClients,
            'total_pages' => ceil($totalClients / $limit)
        ],
        "logged_in_user_role" => $loggedInUserRole
    ]);

} catch (Exception $e) {

    logActivity("EXCEPTION | " . $e->getMessage() . " | IP: " . getClientIP());

    if (isset($conn) && $conn->connect_errno == 0) {
        $conn->close();
    }

    echo json_encode(["success" => false, "message" => "An unexpected error occurred."]);
    exit();
}
