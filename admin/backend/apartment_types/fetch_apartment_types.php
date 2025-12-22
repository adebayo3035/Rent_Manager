<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php'; // contains json_success, json_error, logActivity()
session_start();

logActivity("Apartment Types listing fetch process started");

try {
    if (!isset($_SESSION['unique_id'])) {
        logActivity("Unauthenticated access attempt to Apartment Types listing");
        echo json_encode(["success" => false, "message" => "Not logged in."]);
        exit();
    }

    $userId = $_SESSION['unique_id'];
    $userRole = $_SESSION['role'] ?? null;
    logActivity("Request initiated by user ID: $userId (Role: $userRole)");

    if (!in_array($userRole, ['Admin', 'Super Admin'])) {
        logActivity("Unauthorized access attempt by user ID: $userId (Role: $userRole)");
        echo json_encode(["success" => false, "message" => "Unauthorized access"]);
        exit();
    }

    $conn->begin_transaction();
    logActivity("Database transaction started");

    $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? max(1, (int) $_GET['limit']) : 10;
    $offset = ($page - 1) * $limit;

    // Always include delete_status
    $selectClause = "SELECT *";
    $fromClause = "FROM apartment_type";
    $orderClause = "ORDER BY type_name ASC";

    if ($userRole === 'Admin') {
        $whereClause = "WHERE status = 1";
        $countQuery = "SELECT COUNT(*) as total FROM apartment_type WHERE status = 1";
    } else {
        $whereClause = "";
        $countQuery = "SELECT COUNT(*) as total FROM apartment_type";
    }

    // Get total count for pagination
    $countResult = $conn->query($countQuery);
    $totalCount = (int) $countResult->fetch_assoc()['total'];

    $query = "$selectClause $fromClause $whereClause $orderClause LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Prepare failed for apartment types query: " . $conn->error);
    }

    $stmt->bind_param("ii", $limit, $offset);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed for Apartment Typess query: " . $stmt->error);
    }

    $result = $stmt->get_result();
    $apartmentTypes = [];

    while ($row = $result->fetch_assoc()) {
        $row['status'] = ($row['status']);
        $apartmentTypes[] = $row;
    }

    $conn->commit();
    logActivity("Successfully retrieved " . count($apartmentTypes) . " paginated Apartment Types");

    echo json_encode([
        'success' => true,
        'apartmentTypes' => $apartmentTypes,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $totalCount,
            'total_pages' => ceil($totalCount / $limit)
        ],
        'requested_by' => $userId,
        'logged_in_user_role' => $userRole,
        'timestamp' => date('c'),
        'data_scope' => ($userRole === 'Admin') ? 'active_only' : 'all_records'
    ]);

    logActivity("Apartment Types listing fetch completed successfully");

} catch (Exception $e) {
    $conn->rollback();
    logActivity("Error fetching Apartment Types listing: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch Apartment Typess',
        'error' => $e->getMessage()
    ]);
} finally {
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    $conn->close();
    logActivity("Apartment Types listing fetch process completed");
}