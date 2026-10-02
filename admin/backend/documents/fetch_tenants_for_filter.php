<?php
// admin/backend/documents/fetch_tenants_for_filter.php
// Returns tenants the current admin can filter by

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';

if (!isset($_SESSION)) session_start();

try {
    // $auth = requireAuth([
    //     'method' => 'GET',
    //     'roles'  => ['Super Admin', 'Admin']
    // ]);
   $adminId = $_SESSION['unique_id'];
    $userRole = $_SESSION['role'];


    if ($userRole === 'Super Admin') {
        $stmt = $conn->prepare("
            SELECT t.tenant_code, t.firstname, t.lastname,
                   CONCAT(t.firstname, ' ', t.lastname) AS display
            FROM tenants t
            WHERE t.status = 1 AND t.deleted_at IS NULL
            ORDER BY t.firstname ASC
        ");
    } else {
        $stmt = $conn->prepare("
            SELECT t.tenant_code, t.firstname, t.lastname,
                   CONCAT(t.firstname, ' ', t.lastname) AS display
            FROM tenants t
            WHERE t.status = 1 AND t.deleted_at IS NULL AND t.created_by = ?
            ORDER BY t.firstname ASC
        ");
        $stmt->bind_param("i", $adminId);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $tenants = [];
    while ($row = $res->fetch_assoc()) {
        $tenants[] = $row;
    }
    $stmt->close();

    json_success(['tenants' => $tenants], "Tenants retrieved");

} catch (Exception $e) {
    logActivity("ERROR in fetch_tenants_for_filter: " . $e->getMessage());
    json_error("Failed to fetch tenants", 500);
}