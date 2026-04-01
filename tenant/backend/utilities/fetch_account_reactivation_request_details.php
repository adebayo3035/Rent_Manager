<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_utils.php';
require_once __DIR__ . '/utils.php';

session_start();

if (!isset($_SESSION['unique_id'])) {
    echo json_encode(["success" => false, "message" => "Not logged in"]);
    exit();
}

$requestId = $_GET['id'] ?? 0;

if (!$requestId) {
    echo json_encode(["success" => false, "message" => "Request ID required"]);
    exit();
}

try {
    $query = "SELECT 
                ar.*,
                CASE ar.user_type
                    WHEN 'admin' THEN (SELECT CONCAT_WS(' ', firstname, lastname) FROM admin_tbl WHERE unique_id = ar.user_id)
                    WHEN 'agent' THEN (SELECT CONCAT_WS(' ', firstname, lastname) FROM agents WHERE agent_code = ar.user_id)
                    WHEN 'client' THEN (SELECT CONCAT_WS(' ', firstname, lastname) FROM clients WHERE client_code = ar.user_id)
                    WHEN 'tenant' THEN (SELECT CONCAT_WS(' ', firstname, lastname) FROM tenants WHERE tenant_code = ar.user_id)
                END as user_full_name,
                CASE ar.user_type
                    WHEN 'admin' THEN (SELECT phone FROM admin_tbl WHERE unique_id = ar.user_id)
                    WHEN 'agent' THEN (SELECT phone FROM agents WHERE agent_code = ar.user_id)
                    WHEN 'client' THEN (SELECT phone FROM clients WHERE client_code = ar.user_id)
                    WHEN 'tenant' THEN (SELECT phone FROM tenants WHERE tenant_code = ar.user_id)
                END as user_phone,
                CASE ar.user_type
                    WHEN 'admin' THEN (SELECT status FROM admin_tbl WHERE unique_id = ar.user_id)
                    WHEN 'agent' THEN (SELECT status FROM agents WHERE agent_code = ar.user_id)
                    WHEN 'client' THEN (SELECT status FROM clients WHERE client_code = ar.user_id)
                    WHEN 'tenant' THEN (SELECT status FROM tenants WHERE tenant_code = ar.user_id)
                END as user_current_status,
                CONCAT_WS(' ', a.firstname, a.lastname) as reviewed_by_name
              FROM account_reactivation_requests ar
              LEFT JOIN admin_tbl a ON ar.reviewed_by = a.unique_id
              WHERE ar.id = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(["success" => false, "message" => "Request not found"]);
        exit();
    }
    
    $request = $result->fetch_assoc();
    
    // Format dates
    if ($request['created_at']) {
        $request['created_at_formatted'] = date('Y-m-d H:i', strtotime($request['created_at']));
    }
    if ($request['review_timestamp']) {
        $request['review_timestamp_formatted'] = date('Y-m-d H:i', strtotime($request['review_timestamp']));
    }
    
    echo json_encode([
        "success" => true,
        "request" => $request
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "An error occurred: " . $e->getMessage()
    ]);
}
