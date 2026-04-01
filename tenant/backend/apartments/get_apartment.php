<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
session_start();

// Initialize variables for cleanup
$stmt = null;
$countStmt = null;

// Initialize logging
logActivity("Tenant listing fetch process started");

try {
    // Check authentication
    if (!isset($_SESSION['unique_id'])) {
        logActivity("Unauthenticated access attempt to tenant listing");
        echo json_encode(["success" => false, "message" => "Not logged in."]);
        exit();
    }

    $adminId = $_SESSION['unique_id'];
    $userRole = $_SESSION['role'] ?? null;
    logActivity("Request initiated by admin ID: $adminId (Role: $userRole)");

    // Validate and sanitize pagination parameters
    $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;

    if ($page < 1 || $limit < 1 || $limit > 100) {
        logActivity("Invalid pagination parameters - Page: $page, Limit: $limit");
        echo json_encode([
            'success' => false,
            'message' => 'Invalid pagination parameters. Page must be ≥ 1 and limit between 1-100.'
        ]);
        exit();
    }

    $offset = ($page - 1) * $limit;

    // Fetch filter parameters
    $gender = isset($_GET['gender']) ? trim($_GET['gender']) : null;
    $restriction = isset($_GET['restriction']) ? trim($_GET['restriction']) : null;
    $delete_status = isset($_GET['delete_status']) ? trim($_GET['delete_status']) : null;

    // Separate regular conditions (with parameters) and special conditions
    $regularWhereClauses = [];
    $specialWhereClauses = [];
    $params = [];
    $types = '';

    // Validate gender filter
    if ($gender !== null && $gender !== '') {
        if (!in_array($gender, ['Male', 'Female'])) {
            echo json_encode(["success" => false, "message" => "Invalid gender value"]);
            exit();
        }
        $regularWhereClauses[] = "gender = ?";
        $params[] = $gender;
        $types .= 's';
    }

    // Validate restriction filter
    if ($restriction !== null && $restriction !== '') {
        if (!in_array($restriction, ['0', '1'], true)) {
            echo json_encode(["success" => false, "message" => "Invalid restriction value"]);
            exit();
        }
        $regularWhereClauses[] = "restriction = ?";
        $params[] = $restriction;
        $types .= 'i';
    }

    // Validate delete_status filter
    if ($delete_status !== null && $delete_status !== '') {
        if ($delete_status === 'NULL') {
            $specialWhereClauses[] = "delete_status IS NULL";
        } elseif ($delete_status === 'Yes') {
            $regularWhereClauses[] = "delete_status = ?";
            $params[] = $delete_status;
            $types .= 's';
        } else {
            echo json_encode(["success" => false, "message" => "Invalid delete_status value"]);
            exit();
        }
    }

    // Combine all WHERE clauses
    $whereClauses = array_merge($regularWhereClauses, $specialWhereClauses);
    $whereSQL = '';
    if (!empty($whereClauses)) {
        $whereSQL = "WHERE " . implode(' AND ', $whereClauses);
    }

    try {
        $conn->begin_transaction();
        logActivity("Database transaction started");

        // --------------------
        // Fetch total count
        // --------------------
        $totalQuery = "SELECT COUNT(*) as total FROM tenant_customers $whereSQL";
        $countStmt = $conn->prepare($totalQuery);
        if (!$countStmt) {
            throw new Exception("Prepare failed for count query: " . $conn->error);
        }

        if (!empty($params)) {
            $allParams = $params; // only filters
            $allTypes = $types;
            $countStmt->bind_param($allTypes, ...$allParams);
        }

        if (!$countStmt->execute()) {
            throw new Exception("Execute failed for count query: " . $countStmt->error);
        }

        $totalResult = $countStmt->get_result();
        $totalTenants = $totalResult->fetch_assoc()['total'];
        $totalPages = ceil($totalTenants / $limit);

        // --------------------
        // Fetch paginated tenants
        // --------------------
        $query = "SELECT 
                    id,
                    tenant_id,
                    firstname, 
                    lastname, 
                    gender, 
                    restriction, 
                    delete_status, 
                    date_updated,
                    email,
                    mobile_number,
                    address,
                    photo,
                    property_id,
                    unit_id,
                    tenant_status,
                    lease_start,
                    lease_end,
                    rent_amount,
                    security_deposit,
                    next_rent_due,
                    date_created
                  FROM tenant_customers
                  $whereSQL
                  ORDER BY date_updated DESC
                  LIMIT ? OFFSET ?";

        $stmt = $conn->prepare($query);
        if (!$stmt) {
            throw new Exception("Prepare failed for data query: " . $conn->error);
        }

        if (!empty($params)) {
            // Merge filters with limit & offset
            $allParams = array_merge($params, [$limit, $offset]);
            $allTypes = $types . 'ii';
            $stmt->bind_param($allTypes, ...$allParams);
        } else {
            // Only limit & offset
            $stmt->bind_param('ii', $limit, $offset);
        }

        if (!$stmt->execute()) {
            throw new Exception("Execute failed for data query: " . $stmt->error);
        }

        $result = $stmt->get_result();
        $tenants = [];
        while ($row = $result->fetch_assoc()) {
            // Format the data to match your frontend expectations
            $tenants[] = [
                'tenant_id' => $row['tenant_id'],
                'firstname' => $row['firstname'],
                'lastname' => $row['lastname'],
                'gender' => $row['gender'],
                'restriction' => $row['restriction'],
                'delete_status' => $row['delete_status'],
                'date_created' => $row['date_created'],
                'date_updated' => $row['date_updated'],
                'email' => $row['email'],
                'mobile_number' => $row['mobile_number'],
                'address' => $row['address'],
                'photo' => $row['photo'],
                'property_id' => $row['property_id'],
                'unit_id' => $row['unit_id'],
                'tenant_status' => $row['tenant_status'],
                'lease_start' => $row['lease_start'],
                'lease_end' => $row['lease_end'],
                'rent_amount' => $row['rent_amount'],
                'security_deposit' => $row['security_deposit'],
                'next_rent_due' => $row['next_rent_due']
            ];
        }

        $conn->commit();

        echo json_encode([
            'success' => true,
            'tenants' => $tenants,
            'pagination' => [
                'total' => $totalTenants,
                'page' => $page,
                'limit' => $limit,
                'totalPages' => $totalPages,
                'hasNext' => $page < $totalPages,
                'hasPrev' => $page > 1
            ],
            'requested_by' => $adminId,
            'user_role' => $userRole,
            'timestamp' => date('c')
        ]);

        logActivity("Tenant listing fetch completed successfully");

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    http_response_code(500);
    logActivity("Error fetching tenant listing: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch tenants',
        'error' => $e->getMessage()
    ]);
} finally {
    if ($stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    if ($countStmt instanceof mysqli_stmt) {
        $countStmt->close();
    }
    if ($conn) {
        $conn->close();
    }
    logActivity("Tenant listing fetch process completed");
}
