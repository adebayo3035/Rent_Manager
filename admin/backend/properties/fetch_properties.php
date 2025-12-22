<?php
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

try {

    // -----------------------------------------------------
    //  RATE LIMIT
    // -----------------------------------------------------
    rateLimit("fetch_properties", 60, 60);


    // -----------------------------------------------------
    //  AUTH CHECK
    // -----------------------------------------------------
    if (!isset($_SESSION['unique_id'])) {
        logActivity("Unauthorized access | No session | IP: " . getClientIP());
        echo json_encode(["success" => false, "message" => "Not logged in"]);
        exit();
    }

    $adminId = $_SESSION['unique_id'];
    $loggedInUserRole = $_SESSION['role'] ?? 'Unknown';

    logActivity("Fetch Properties | AdminID: {$adminId} | Role: {$loggedInUserRole} | IP: " . getClientIP());


    // -----------------------------------------------------
    //  PAGINATION
    // -----------------------------------------------------
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = max(1, (int) ($_GET['limit'] ?? 10));
    $offset = ($page - 1) * $limit;

    logActivity("Pagination | Page={$page} | Limit={$limit} | Offset={$offset}");


    // -----------------------------------------------------
    //  DB CONNECTION CHECK
    // -----------------------------------------------------
    if (!$conn || $conn->connect_errno) {
        logActivity("DB Connection Failure | IP: " . getClientIP());
        echo json_encode(["success" => false, "message" => "Database connection error"]);
        exit();
    }


    // -----------------------------------------------------
    //  FILTER INPUTS
    // -----------------------------------------------------
    $property_type = isset($_GET['property_type']) ? trim($_GET['property_type']) : null;
    $status = isset($_GET['status']) ? trim($_GET['status']) : null;

    $allowedStatus = ['0', '1'];

    logActivity("Filters | property_type={$property_type}, status={$status}");


    // -----------------------------------------------------
    //  WHERE CLAUSE
    // -----------------------------------------------------
    $whereClauses = [];
    $params = [];
    $types = '';

    if ($property_type !== null && $property_type !== "") {
        $whereClauses[] = "p.property_type_id = ?";
        $params[] = $property_type;
        $types .= 's';
    }

    if ($status !== null && in_array($status, $allowedStatus, true)) {
        $whereClauses[] = "p.status = ?";
        $params[] = $status;
        $types .= 's';
    }

    $whereSQL = count($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

    logActivity("Generated WHERE | {$whereSQL} | Params=" . json_encode($params));


    // -----------------------------------------------------
    //  TOTAL COUNT
    // -----------------------------------------------------
    $totalQuery = "SELECT COUNT(*) AS total FROM properties p {$whereSQL}";
    logActivity("Total Count Query: {$totalQuery}");

    $countStmt = $conn->prepare($totalQuery);
    if (!$countStmt) {
        throw new Exception("Failed to prepare total count query: " . $conn->error);
    }

    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }

    $countStmt->execute();
    $totalResult = $countStmt->get_result();
    $totalProperties = $totalResult->fetch_assoc()['total'] ?? 0;
    $countStmt->close();

    logActivity("Total Properties: {$totalProperties}");


    // -----------------------------------------------------
    //  MAIN QUERY
    // -----------------------------------------------------
    $query = "
        SELECT 
            p.*,
            pt.type_name AS property_type_name,
            CONCAT(a.firstname, ' ', a.lastname) AS agent_fullname,
            CONCAT(c.firstname, ' ', c.lastname) AS client_name
        FROM 
            properties p
        LEFT JOIN 
            property_type pt ON p.property_type_id = pt.type_id
        LEFT JOIN 
            agents a ON p.agent_code = a.agent_code
        LEFT JOIN 
            clients c ON p.client_code = c.client_code
        {$whereSQL}
        ORDER BY 
            p.status ASC, p.id DESC
        LIMIT ? OFFSET ?
    ";

    logActivity("Properties Query: {$query}");

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Failed to prepare properties fetch query: " . $conn->error);
    }

    // Bind pagination
    $paramsWithPagination = $params;
    $paramsWithPagination[] = $limit;
    $paramsWithPagination[] = $offset;

    $stmtTypes = $types . "ii";
    $stmt->bind_param($stmtTypes, ...$paramsWithPagination);

    logActivity("Executing Query | Params=" . json_encode($paramsWithPagination));

    $stmt->execute();
    $result = $stmt->get_result();

    $properties = [];
    while ($row = $result->fetch_assoc()) {
        $row['status_display'] = ((int)$row['status'] === "1") ? 'Deactivated' : 'Activated';
        $properties[] = $row;
    }

    $stmt->close();
    $conn->close();

    logActivity("Fetch Success | Returned " . count($properties) . " properties");


    echo json_encode([
        "success" => true,
        "properties" => $properties,
        "pagination" => [
            "page" => $page,
            "limit" => $limit,
            "total" => $totalProperties,
            "total_pages" => ceil($totalProperties / $limit)
        ],
        "logged_in_user_role" => $loggedInUserRole
    ]);

} catch (Exception $e) {

    logActivity("EXCEPTION | " . $e->getMessage() . " | IP: " . getClientIP());

    echo json_encode(["success" => false, "message" => "An unexpected error occurred"]);
    exit();
}