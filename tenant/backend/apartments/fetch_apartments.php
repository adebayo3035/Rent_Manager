<?php
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

try {

    // -----------------------------------------------------
    //  RATE LIMIT
    // -----------------------------------------------------
    rateLimit("fetch_apartments", 60, 60);


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

    logActivity("Fetch Apartments | AdminID: {$adminId} | Role: {$loggedInUserRole} | IP: " . getClientIP());


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
    $apartment_type = isset($_GET['apartment_type']) ? trim($_GET['apartment_type']) : null;
    $status = isset($_GET['status']) ? trim($_GET['status']) : null;

    $allowedStatus = ['0', '1'];

    logActivity("Filters | apartment_type={$apartment_type}, status={$status}");


    // -----------------------------------------------------
    //  WHERE CLAUSE
    // -----------------------------------------------------
    $whereClauses = [];
    $params = [];
    $types = '';

    if ($apartment_type !== null && $apartment_type !== "") {
        $whereClauses[] = "p.apartment_type_id = ?";
        $params[] = $apartment_type;
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
    $totalQuery = "SELECT COUNT(*) AS total FROM apartments p {$whereSQL}";
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
    $totalApartments = $totalResult->fetch_assoc()['total'] ?? 0;
    $countStmt->close();

    logActivity("Total Apartments: {$totalApartments}");


    // -----------------------------------------------------
    //  MAIN QUERY - Get apartments with property and agent info
    // -----------------------------------------------------
    // First, get apartments with property information
    $query = "
        SELECT 
            p.*,
            pt.type_name AS apartment_type_name,
            pr.name AS property_name,
            pr.property_code,
            pr.agent_code AS property_agent_code  -- Get agent_code from properties table
        FROM 
            apartments p
        LEFT JOIN 
            apartment_type pt ON p.apartment_type_id = pt.type_id
        LEFT JOIN
            properties pr ON p.property_code = pr.property_code
        {$whereSQL}
        ORDER BY 
            p.status ASC, p.id DESC
        LIMIT ? OFFSET ?
    ";

    logActivity("Apartments Query: {$query}");

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Failed to prepare apartments fetch query: " . $conn->error);
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

    $apartments = [];
    $agentCodes = [];
    
    // First pass: collect apartments and gather unique agent codes
    while ($row = $result->fetch_assoc()) {
        $row['status_display'] = ((int)$row['status'] === 1) ? 'Deactivated' : 'Activated';
        $apartments[] = $row;
        
        // Collect unique agent codes from properties
        if (!empty($row['property_agent_code'])) {
            $agentCodes[$row['property_agent_code']] = true;
        }
    }
    
    $stmt->close();
    
    logActivity("Collected " . count($apartments) . " apartments with " . count($agentCodes) . " unique agent codes");


    // -----------------------------------------------------
    //  FETCH AGENT NAMES FOR ALL COLLECTED AGENT CODES
    // -----------------------------------------------------
    $agentNames = [];
    if (!empty($agentCodes)) {
        $agentCodeList = array_keys($agentCodes);
        $placeholders = implode(',', array_fill(0, count($agentCodeList), '?'));
        
        $agentQuery = "
            SELECT 
                agent_code,
                firstname,
                lastname,
                CONCAT(firstname, ' ', lastname) AS agent_fullname
            FROM 
                agents 
            WHERE 
                agent_code IN ({$placeholders})
        ";
        
        logActivity("Agent Query: {$agentQuery} | Codes: " . json_encode($agentCodeList));
        
        $agentStmt = $conn->prepare($agentQuery);
        if ($agentStmt) {
            // Create types string for binding (all strings)
            $agentTypes = str_repeat('s', count($agentCodeList));
            $agentStmt->bind_param($agentTypes, ...$agentCodeList);
            
            $agentStmt->execute();
            $agentResult = $agentStmt->get_result();
            
            while ($agentRow = $agentResult->fetch_assoc()) {
                $agentNames[$agentRow['agent_code']] = [
                    'firstname' => $agentRow['firstname'],
                    'lastname' => $agentRow['lastname'],
                    'agent_fullname' => $agentRow['agent_fullname']
                ];
            }
            
            $agentStmt->close();
            logActivity("Fetched " . count($agentNames) . " agent names");
        }
    }


    // -----------------------------------------------------
    //  ENRICH APARTMENTS WITH AGENT INFORMATION
    // -----------------------------------------------------
    foreach ($apartments as &$apartment) {
        $agentCode = $apartment['property_agent_code'] ?? null;
        
        if ($agentCode && isset($agentNames[$agentCode])) {
            // Add agent details to the apartment
            $apartment['agent'] = [
                'agent_code' => $agentCode,
                'firstname' => $agentNames[$agentCode]['firstname'],
                'lastname' => $agentNames[$agentCode]['lastname'],
                'agent_fullname' => $agentNames[$agentCode]['agent_fullname']
            ];
        } else {
            // No agent found
            $apartment['agent'] = [
                'agent_code' => $agentCode,
                'firstname' => null,
                'lastname' => null,
                'agent_fullname' => $agentCode ? 'Agent not found' : 'No agent assigned'
            ];
        }
        
        // Also keep the original property_agent_code for backward compatibility
        $apartment['agent_code_from_property'] = $agentCode;
    }

    $conn->close();

    logActivity("Fetch Success | Returned " . count($apartments) . " apartments with agent names");


    echo json_encode([
        "success" => true,
        "apartments" => $apartments,
        "pagination" => [
            "page" => $page,
            "limit" => $limit,
            "total" => $totalApartments,
            "total_pages" => ceil($totalApartments / $limit)
        ],
        "logged_in_user_role" => $loggedInUserRole
    ]);

} catch (Exception $e) {

    logActivity("EXCEPTION | " . $e->getMessage() . " | IP: " . getClientIP());
    
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }

    echo json_encode(["success" => false, "message" => "An unexpected error occurred"]);
    exit();
}