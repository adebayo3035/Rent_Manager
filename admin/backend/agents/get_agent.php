<?php
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

try {
    // -----------------------------------------------------
    //  RATE LIMIT CHECK (Optional but recommended)
    // -----------------------------------------------------
    rateLimit("fetch_agents", 60, 60); // 60 requests per IP per minute


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

    logActivity("Fetch Agents Request | AdminID: {$adminId} | Role: {$loggedInUserRole} | IP: " . getClientIP());


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
        $whereClauses[] = "agents.gender = ?";
        $params[] = $gender;
        $types .= 's';
    }

    if ($status !== null && in_array($status, $allowedStatus, true)) {
        $whereClauses[] = "agents.status = ?";
        $params[] = $status;
        $types .= 's';
    }

    $whereSQL = count($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

    logActivity("Generated WHERE SQL | {$whereSQL} | Params: " . json_encode($params));


    // -----------------------------------------------------
    //  TOTAL COUNT QUERY
    // -----------------------------------------------------
    $totalQuery = "SELECT COUNT(*) AS total FROM agents {$whereSQL}";
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
    $totalAgents = $totalResult->fetch_assoc()['total'] ?? 0;
    $countStmt->close();

    logActivity("Total Agents Count Found: {$totalAgents}");


    // -----------------------------------------------------
    //  AGENTS QUERY
    // -----------------------------------------------------
    $query = "SELECT 
                agents.*
              FROM 
                agents
              {$whereSQL}
              ORDER BY 
                agents.status ASC, agents.agent_id DESC
              LIMIT ? OFFSET ?";

    logActivity("Preparing Agents Query: {$query}");

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Failed to prepare agents fetch query: " . $conn->error);
    }

    // Add pagination params
    $paramsWithPagination = $params;
    $paramsWithPagination[] = $limit;
    $paramsWithPagination[] = $offset;

    $stmtTypes = $types . 'ii';
    $stmt->bind_param($stmtTypes, ...$paramsWithPagination);

    logActivity("Executing Agents Query | Params: " . json_encode($paramsWithPagination));

    $stmt->execute();
    $result = $stmt->get_result();

    $agents = [];
    while ($row = $result->fetch_assoc()) {
        $row['status_display'] = ($row['status'] === '1') ? 'Deactivated' : 'Activated';
        $agents[] = $row;
    }

    $stmt->close();
    $conn->close();


    logActivity("Agents Fetch Success | Returned: " . count($agents) . " rows");


    echo json_encode([
        "success" => true,
        "agents" => $agents,
         'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $totalAgents,
            'total_pages' => ceil($totalAgents / $limit)
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
