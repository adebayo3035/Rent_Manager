<?php
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
session_start();

try {
    if (!isset($_SESSION['unique_id'])) {
        logActivity("Unauthorized access attempt: No session found.");
        echo json_encode(["success" => false, "message" => "Not logged in."]);
        exit();
    }

    $adminId = $_SESSION['unique_id'];
    $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;
    $loggedInUserRole = $_SESSION['role'] ?? 'Unknown';

    if (!$conn) {
        logActivity("Database connection failed.");
        echo json_encode(["success" => false, "message" => "Database connection error."]);
        exit();
    }

    // --- FILTERS ---
    $gender = isset($_GET['gender']) ? trim($_GET['gender']) : null;
    $role = isset($_GET['role']) ? trim($_GET['role']) : null;
    $restriction_id = isset($_GET['restriction_id']) ? trim($_GET['restriction_id']) : null;
    $block_id = isset($_GET['block_id']) ? trim($_GET['block_id']) : null;
    $delete_status = isset($_GET['delete_status']) ? trim($_GET['delete_status']) : null;

    // Allowed values
    $allowedGender = ['Male', 'Female'];
    $allowedRole = ['Admin', 'Super Admin'];
    $allowedBinary = ['0', '1'];
    $allowedDeleteStatus = ['Yes', 'NULL'];

    $whereClauses = [];
    $params = [];
    $types = '';

    if ($gender !== null && in_array($gender, $allowedGender, true)) {
        $whereClauses[] = "admin_tbl.gender = ?";
        $params[] = $gender;
        $types .= 's';
    }

    if ($role !== null && in_array($role, $allowedRole, true)) {
        $whereClauses[] = "admin_tbl.role = ?";
        $params[] = $role;
        $types .= 's';
    }

    if ($restriction_id !== null && in_array($restriction_id, $allowedBinary, true)) {
        $whereClauses[] = "admin_tbl.restriction_id = ?";
        $params[] = (int) $restriction_id;
        $types .= 'i';
    }

    if ($block_id !== null && in_array($block_id, $allowedBinary, true)) {
        $whereClauses[] = "admin_tbl.block_id = ?";
        $params[] = (int) $block_id;
        $types .= 'i';
    }

    if ($delete_status !== null && $delete_status !== '') {
        if ($delete_status === 'NULL') {
            $whereClauses[] = "admin_tbl.delete_status IS NULL";
        } elseif ($delete_status === 'Yes') {
            $whereClauses[] = "admin_tbl.delete_status = ?";
            $params[] = $delete_status;
            $types .= 's';
        } else {
            echo json_encode(["success" => false, "message" => "Invalid delete_status value"]);
            exit();
        }
    }


    $whereSQL = '';
    if (count($whereClauses) > 0) {
        $whereSQL = "WHERE " . implode(" AND ", $whereClauses);
    }

    // --- TOTAL COUNT ---
    $totalQuery = "SELECT COUNT(*) as total FROM admin_tbl $whereSQL";
    $countStmt = $conn->prepare($totalQuery);
    if (!$countStmt) {
        throw new Exception("Failed to prepare total count query: " . $conn->error);
    }

    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $totalResult = $countStmt->get_result();
    $totalStaffs = $totalResult->fetch_assoc()['total'] ?? 0;
    $countStmt->close();

    // --- DATA FETCH ---
    $query = "SELECT 
                admin_tbl.*, 
                admin_active_sessions.status AS admin_status
              FROM 
                admin_tbl
              LEFT JOIN 
                admin_active_sessions 
              ON 
                admin_tbl.unique_id = admin_active_sessions.unique_id
              $whereSQL
              ORDER BY 
                admin_tbl.role ASC, admin_tbl.id DESC
              LIMIT ? OFFSET ?";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Failed to prepare staff fetch query: " . $conn->error);
    }

    // Add pagination params
    $paramsWithPagination = $params;
    $paramsWithPagination[] = $limit;
    $paramsWithPagination[] = $offset;

    $stmtTypes = $types . 'ii';
    $stmt->bind_param($stmtTypes, ...$paramsWithPagination);
    $stmt->execute();
    $result = $stmt->get_result();

    $staffs = [];
    while ($row = $result->fetch_assoc()) {
        // Optional: map NULL delete_status to 'Activated' for clarity
        $row['delete_status_display'] = $row['delete_status'] === 'Yes' ? 'Deactivated' : 'Activated';
        $staffs[] = $row;
    }

    $stmt->close();
    $conn->close();

    echo json_encode([
        "success" => true,
        "staffs" => $staffs,
        "total" => $totalStaffs,
        "page" => $page,
        "limit" => $limit,
        "logged_in_user_role" => $loggedInUserRole
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->connect_errno == 0) {
        $conn->close();
    }
    logActivity("Error occurred: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "An unexpected error occurred."]);
    exit();
}
