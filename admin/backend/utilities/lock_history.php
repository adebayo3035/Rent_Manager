<?php
// backend/lock_history.php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

// Start logging
$requestId = uniqid('lock_history_', true);
logActivity("[LOCK_HISTORY_START] [ID:{$requestId}] Request started");
session_start();

// Check authentication
if (!isset($_SESSION['unique_id'])) {
    logActivity("[LOCK_HISTORY_ERROR] [ID:{$requestId}] No session found");
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Please login first']);
    exit;
}

// Check user role - Super Admin, Admin, or Manager can view history
$user_id = $_SESSION['unique_id'];
$user_role = $_SESSION['role'] ?? '';

if (!in_array($user_role, ['Super Admin', 'Admin', 'Manager'])) {
    logActivity("[LOCK_HISTORY_ERROR] [ID:{$requestId}] Insufficient permissions");
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit;
}

// Only allow GET requests for history
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    handleGetHistory($conn, $user_id, $user_role);
} catch (Exception $e) {
    logActivity("[LOCK_HISTORY_EXCEPTION] [ID:{$requestId}] Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}

/**
 * Get user type specific table configuration
 */
function getUserTypeConfig($userType) {
    $configs = [
        'admin' => [
            'table' => 'admin_tbl',
            'id_column' => 'unique_id',
            'name_columns' => ['firstname', 'lastname'],
            'email_column' => 'email',
            'role_column' => 'role',
            'attempts_table' => 'admin_login_attempts',
            'attempts_id_column' => 'unique_id',
            'lock_history_table' => 'admin_lock_history',
            'lock_history_id_column' => 'unique_id',
            'secret_attempts_table' => 'admin_secret_attempts',
            'default_role' => 'Admin',
            'icon' => 'user-shield'
        ],
        'tenant' => [
            'table' => 'tenants',
            'id_column' => 'tenant_code',
            'name_columns' => ['firstname', 'lastname'],
            'email_column' => 'email',
            'role_column' => null,
            'attempts_table' => 'tenant_login_attempts',
            'attempts_id_column' => 'tenant_code',
            'lock_history_table' => 'tenant_lock_history',
            'lock_history_id_column' => 'tenant_code',
            'secret_attempts_table' => 'tenant_secret_attempts',
            'default_role' => 'Tenant',
            'icon' => 'user'
        ],
        'agent' => [
            'table' => 'agents',
            'id_column' => 'agent_code',
            'name_columns' => ['firstname', 'lastname'],
            'email_column' => 'email',
            'role_column' => null,
            'attempts_table' => 'agent_login_attempts',
            'attempts_id_column' => 'agent_code',
            'lock_history_table' => 'agent_lock_history',
            'lock_history_id_column' => 'agent_code',
            'secret_attempts_table' => null,
            'default_role' => 'Agent',
            'icon' => 'user-tie'
        ],
        'client' => [
            'table' => 'clients',
            'id_column' => 'client_code',
            'name_columns' => ['firstname', 'lastname'],
            'email_column' => 'email',
            'role_column' => null,
            'attempts_table' => 'client_login_attempts',
            'attempts_id_column' => 'client_code',
            'lock_history_table' => 'client_lock_history',
            'lock_history_id_column' => 'client_code',
            'secret_attempts_table' => null,
            'default_role' => 'Client',
            'icon' => 'briefcase'
        ]
    ];
    
    return $configs[$userType] ?? $configs['admin'];
}

/**
 * Check if a table exists
 */
function tableExists($conn, $tableName) {
    $result = $conn->query("SHOW TABLES LIKE '{$tableName}'");
    return $result && $result->num_rows > 0;
}

function handleGetHistory($conn, $admin_id, $admin_role) {
    global $requestId;
    
    // Get parameters
    $userType = $_GET['user_type'] ?? 'all';
    $actionType = $_GET['action_type'] ?? 'all';
    $status = $_GET['status'] ?? 'all';
    $dateFrom = $_GET['date_from'] ?? null;
    $dateTo = $_GET['date_to'] ?? null;
    $search = $_GET['search'] ?? '';
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 20;
    $offset = ($page - 1) * $limit;
    $accountId = $_GET['account_id'] ?? null;
    
    logActivity("[LOCK_HISTORY_GET] [ID:{$requestId}] Fetching lock history - UserType: {$userType}, ActionType: {$actionType}, AccountID: {$accountId}");
    
    // Determine which tables to query based on user_type
    $userTypes = [];
    if ($userType === 'all') {
        $userTypes = ['admin', 'tenant', 'agent', 'client'];
    } else {
        $userTypes = [$userType];
    }
    
    $allResults = [];
    $totalCount = 0;
    
    foreach ($userTypes as $type) {
        $config = getUserTypeConfig($type);
        
        // Skip if lock history table doesn't exist
        if (!tableExists($conn, $config['lock_history_table'])) {
            continue;
        }
        
        // ✅ FIXED: Build query without duplicate column names
        $query = "SELECT 
                    lh.*,
                    u.{$config['email_column']} as email,
                    u." . implode(", u.", $config['name_columns']);
        
        // ✅ FIXED: Only select role if it exists in the table
        if ($config['role_column']) {
            $query .= ", u.{$config['role_column']} as user_role";
        }
        
        $query .= ", '" . $type . "' as user_type_name";
        $query .= " FROM {$config['lock_history_table']} lh
                    LEFT JOIN {$config['table']} u 
                    ON lh.{$config['lock_history_id_column']} = u.{$config['id_column']}
                    WHERE 1=1";
        
        $params = [];
        $paramTypes = "";
        
        // Filter by account ID if provided
        if ($accountId) {
            $query .= " AND lh.{$config['lock_history_id_column']} = ?";
            $params[] = $accountId;
            $paramTypes .= "s";
        }
        
        // Filter by action type
        if ($actionType !== 'all') {
            if ($actionType === 'login_attempts') {
                $query .= " AND lh.lock_reason LIKE '%login attempt%'";
            } elseif ($actionType === 'manual_lock') {
                $query .= " AND lh.lock_reason NOT LIKE '%login attempt%'";
            }
        }
        
        // Filter by status
        if ($status !== 'all') {
            $query .= " AND lh.status = ?";
            $params[] = $status;
            $paramTypes .= "s";
        }
        
        // Filter by date range
        if ($dateFrom) {
            $query .= " AND DATE(lh.locked_at) >= ?";
            $params[] = $dateFrom;
            $paramTypes .= "s";
        }
        
        if ($dateTo) {
            $query .= " AND DATE(lh.locked_at) <= ?";
            $params[] = $dateTo;
            $paramTypes .= "s";
        }
        
        // Search by email or name
        if ($search) {
            $query .= " AND (u.{$config['email_column']} LIKE ?";
            foreach ($config['name_columns'] as $nameCol) {
                $query .= " OR u.{$nameCol} LIKE ?";
                $params[] = "%{$search}%";
                $paramTypes .= "s";
            }
            $query .= ")";
            $searchParam = "%{$search}%";
            $params = array_merge([$searchParam], $params);
            $paramTypes = "s" . $paramTypes;
        }
        
        // Order by locked_at descending
        $query .= " ORDER BY lh.locked_at DESC";
        
        // Get total count for this user type
        $countQuery = "SELECT COUNT(*) as total FROM ({$query}) as subquery";
        $countStmt = $conn->prepare($countQuery);
        
        if (!empty($params)) {
            $countStmt->bind_param($paramTypes, ...$params);
        }
        
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $typeCount = $countResult->fetch_assoc()['total'] ?? 0;
        $countStmt->close();
        
        if ($typeCount === 0) {
            continue;
        }
        
        // Add pagination for this user type
        $query .= " LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $paramTypes .= "ii";
        
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            continue;
        }
        
        if (!empty($params)) {
            $stmt->bind_param($paramTypes, ...$params);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            // Build name
            $nameParts = [];
            foreach ($config['name_columns'] as $col) {
                $nameParts[] = $row[$col] ?? '';
            }
            $fullName = implode(' ', $nameParts);
            
            // ✅ FIXED: Get role from the correct column
            $role = $config['default_role'];
            if ($config['role_column'] && isset($row['user_role'])) {
                $role = $row['user_role'];
            }
            
            // Determine lock type
            $lockType = 'manual_lock';
            if (strpos(strtolower($row['lock_reason'] ?? ''), 'login attempt') !== false) {
                $lockType = 'login_attempts';
            }
            
            $allResults[] = [
                'id' => $row['id'] ?? null,
                'user_id' => $row[$config['lock_history_id_column']] ?? '',
                'user_type' => $type,
                'user_type_icon' => $config['icon'],
                'name' => $fullName ?: 'N/A',
                'email' => $row['email'] ?? 'N/A',
                'role' => $role,
                'locked_at' => $row['locked_at'] ?? null,
                'unlocked_at' => $row['unlocked_at'] ?? null,
                'lock_reason' => $row['lock_reason'] ?? 'No reason provided',
                'locked_by' => $row['locked_by'] ?? null,
                'unlocked_by' => $row['unlocked_by'] ?? null,
                'unlock_method' => $row['unlock_method'] ?? null,
                'unlock_reason' => $row['unlock_reason'] ?? null,
                'status' => $row['status'] ?? 'locked',
                'lock_type' => $lockType
            ];
        }
        
        $stmt->close();
        $totalCount += $typeCount;
    }
    
    // Sort all results by locked_at descending
    usort($allResults, function($a, $b) {
        return strtotime($b['locked_at'] ?? '') - strtotime($a['locked_at'] ?? '');
    });
    
    // Apply pagination to combined results
    $totalRecords = count($allResults);
    $totalPages = ceil($totalRecords / $limit);
    $paginatedResults = array_slice($allResults, $offset, $limit);
    
    // Get summary statistics
    $stats = [
        'total_locks' => $totalRecords,
        'login_attempt_locks' => count(array_filter($allResults, function($item) {
            return $item['lock_type'] === 'login_attempts';
        })),
        'manual_locks' => count(array_filter($allResults, function($item) {
            return $item['lock_type'] === 'manual_lock';
        })),
        'unlocked' => count(array_filter($allResults, function($item) {
            return $item['status'] === 'unlocked';
        })),
        'still_locked' => count(array_filter($allResults, function($item) {
            return $item['status'] === 'locked';
        }))
    ];
    
    echo json_encode([
        'success' => true,
        'history' => $paginatedResults,
        'pagination' => [
            'total' => $totalRecords,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => $totalPages
        ],
        'stats' => $stats,
        'filters' => [
            'user_type' => $userType,
            'action_type' => $actionType,
            'status' => $status,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'search' => $search
        ]
    ]);
}