<?php
// backend/unlock_account.php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notifications.php';
require_once __DIR__ . '/../utilities/auth_guard.php';

// Start logging
$requestId = uniqid('unlock_', true);
logActivity("[MULTI_USER_UNLOCK_START] [ID:{$requestId}] Request started");

// Check authentication
if (!isset($_SESSION['unique_id'])) {
    logActivity("[MULTI_USER_UNLOCK_ERROR] [ID:{$requestId}] No session found");
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Please login first']);
    exit;
}

// Check user role - only Super Admin or Admin can unlock accounts
$user_id = $_SESSION['unique_id'];
$user_role = $_SESSION['role'] ?? '';

if (!in_array($user_role, ['Super Admin', 'Admin'])) {
    logActivity("[MULTI_USER_UNLOCK_ERROR] [ID:{$requestId}] Insufficient permissions");
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGet($conn, $user_id, $user_role);
            break;
        case 'POST':
            handlePost($conn, $user_id, $user_role);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
} catch (Exception $e) {
    logActivity("[MULTI_USER_UNLOCK_EXCEPTION] [ID:{$requestId}] Error: " . $e->getMessage());
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

function handleGet($conn, $admin_id, $admin_role) {
    global $requestId;
    
    $userType = $_GET['user_type'] ?? 'admin';
    $config = getUserTypeConfig($userType);
    
    logActivity("[MULTI_USER_UNLOCK_GET] [ID:{$requestId}] Fetching locked {$userType} accounts");
    
    $search = $_GET['search'] ?? '';
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 20;
    $offset = ($page - 1) * $limit;
    
    // Check if attempts table exists
    $attemptsTableExists = tableExists($conn, $config['attempts_table']);
    $lockHistoryTableExists = tableExists($conn, $config['lock_history_table']);
    
    // Build query based on table existence
    $query = "SELECT 
                u.{$config['id_column']} as user_id,
                u." . implode(", u.", $config['name_columns']) . ",
                u.{$config['email_column']} as email";
    
    if ($config['role_column']) {
        $query .= ", u.{$config['role_column']} as role";
    } else {
        $query .= ", '{$config['default_role']}' as role";
    }
    
    $query .= ", u.status";
    
    if ($attemptsTableExists) {
        $query .= ", ala.attempts, ala.locked_until";
    } else {
        $query .= ", NULL as attempts, NULL as locked_until";
    }
    
    if ($lockHistoryTableExists) {
        $query .= ", alh.locked_at as last_locked_at, alh.lock_reason";
    } else {
        $query .= ", NULL as last_locked_at, NULL as lock_reason";
    }
    
    $query .= " FROM {$config['table']} u";
    
    if ($attemptsTableExists) {
        $query .= " LEFT JOIN {$config['attempts_table']} ala ON u.{$config['id_column']} = ala.{$config['attempts_id_column']}";
    }
    
    if ($lockHistoryTableExists) {
        $query .= " LEFT JOIN (
                        SELECT {$config['lock_history_id_column']}, MAX(locked_at) as locked_at, lock_reason 
                        FROM {$config['lock_history_table']} 
                        WHERE status = 'locked' 
                        AND unlocked_at IS NULL
                        GROUP BY {$config['lock_history_id_column']}
                    ) alh ON u.{$config['id_column']} = alh.{$config['lock_history_id_column']}";
    }
    
    $query .= " WHERE (";
    $conditions = [];
    
    if ($attemptsTableExists) {
        $conditions[] = "(ala.attempts >= 3 AND ala.locked_until > NOW())";
    }
    
    if ($lockHistoryTableExists) {
        $conditions[] = "alh.locked_at IS NOT NULL";
    }
    
    // If no lock tables exist, add condition that will always be false
    if (empty($conditions)) {
        $query .= " 1=0";
    } else {
        $query .= implode(" OR ", $conditions);
    }
    $query .= ")";
    
    $params = [];
    $paramTypes = "";
    
    if ($search) {
        $query .= " AND (u.{$config['email_column']} LIKE ?";
        foreach ($config['name_columns'] as $nameCol) {
            $query .= " OR u.{$nameCol} LIKE ?";
            $params[] = "%{$search}%";
            $paramTypes .= "s";
        }
        $query .= ")";
        $params = array_merge([$searchTerm = "%{$search}%"], $params);
        $paramTypes = "s" . str_repeat("s", count($config['name_columns']));
    }
    
    // Get total count
    $countQuery = "SELECT COUNT(*) as total FROM ({$query}) as subquery";
    $countStmt = $conn->prepare($countQuery);
    
    if ($search) {
        $countStmt->bind_param($paramTypes, ...$params);
    }
    
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalCount = $countResult->fetch_assoc()['total'] ?? 0;
    $countStmt->close();
    
    // Add pagination
    $query .= " ORDER BY ala.locked_until DESC, alh.locked_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $paramTypes .= "ii";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error);
    }
    
    if ($search) {
        $stmt->bind_param($paramTypes, ...$params);
    } else {
        $stmt->bind_param("ii", $limit, $offset);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $lockedAccounts = [];
    while ($row = $result->fetch_assoc()) {
        $isLocked = false;
        $lockType = '';
        $lockTimeRemaining = '';
        
        if ($attemptsTableExists && $row['locked_until']) {
            $lockedUntil = new DateTime($row['locked_until']);
            $currentTime = new DateTime();
            
            if ($currentTime < $lockedUntil) {
                $isLocked = true;
                $lockType = 'login_attempts';
                $remaining = $currentTime->diff($lockedUntil);
                $lockTimeRemaining = $remaining->format('%h hours %i minutes');
            }
        }
        
        if ($lockHistoryTableExists && $row['last_locked_at'] && !$isLocked) {
            $isLocked = true;
            $lockType = 'manual_lock';
            $lockTimeRemaining = 'Manually locked';
        }
        
        if ($isLocked) {
            $name = [];
            foreach ($config['name_columns'] as $col) {
                $name[] = $row[$col];
            }
            
            $lockedAccounts[] = [
                'user_id' => $row['user_id'],
                'name' => implode(' ', $name),
                'email' => $row['email'],
                'role' => $row['role'],
                'user_type' => $userType,
                'user_type_icon' => $config['icon'],
                'lock_type' => $lockType,
                'lock_reason' => $row['lock_reason'] ?? 'Too many failed login attempts',
                'attempts' => $row['attempts'] ?? 'N/A',
                'locked_until' => $row['locked_until'],
                'lock_time_remaining' => $lockTimeRemaining,
                'status' => 'locked'
            ];
        }
    }
    
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'user_type' => $userType,
        'accounts' => $lockedAccounts,
        'pagination' => [
            'total' => $totalCount,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => ceil($totalCount / $limit)
        ]
    ]);
}

function handlePost($conn, $admin_id, $admin_role) {
    global $requestId;
    
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        logActivity("[MULTI_USER_UNLOCK_POST_ERROR] [ID:{$requestId}] Invalid JSON");
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
        return;
    }
    
    if (!isset($data['action'])) {
        logActivity("[MULTI_USER_UNLOCK_POST_ERROR] [ID:{$requestId}] No action specified");
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Action is required']);
        return;
    }
    
    $action = $data['action'];
    $userType = $data['user_type'] ?? 'admin';
    $config = getUserTypeConfig($userType);
    
    switch ($action) {
        case 'unlock_account':
            if (!isset($data['account_id'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Account ID is required']);
                return;
            }
            unlockAccount($conn, $admin_id, $admin_role, $config, $data['account_id'], $data['reason'] ?? '');
            break;
            
        case 'bulk_unlock':
            if (!isset($data['account_ids']) || !is_array($data['account_ids'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Account IDs array is required']);
                return;
            }
            bulkUnlockAccounts($conn, $admin_id, $admin_role, $config, $data['account_ids'], $data['reason'] ?? '');
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}

function unlockAccount($conn, $admin_id, $admin_role, $config, $account_id, $reason = '') {
    global $requestId;
    
    logActivity("[MULTI_USER_UNLOCK_SINGLE] [ID:{$requestId}] Admin {$admin_id} unlocking {$config['table']} account {$account_id}");
    
    $conn->begin_transaction();
    
    try {
        // Get account details
        $nameCols = implode(", ", $config['name_columns']);
        $stmt = $conn->prepare("SELECT {$config['email_column']} as email, {$nameCols} FROM {$config['table']} WHERE {$config['id_column']} = ?");
        $stmt->bind_param("s", $account_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $account = $result->fetch_assoc();
        $stmt->close();
        
        if (!$account) {
            throw new Exception("Account not found");
        }
        
        // Build name
        $nameParts = [];
        foreach ($config['name_columns'] as $col) {
            $nameParts[] = $account[$col] ?? '';
        }
        $fullName = implode(' ', $nameParts);
        
        // 1. Clear login attempts if table exists
        if (tableExists($conn, $config['attempts_table'])) {
            $stmt = $conn->prepare("DELETE FROM {$config['attempts_table']} WHERE {$config['attempts_id_column']} = ?");
            $stmt->bind_param("s", $account_id);
            $stmt->execute();
            $stmt->close();
        }
        
        // 2. Clear secret answer attempts if table exists
        if ($config['secret_attempts_table'] && tableExists($conn, $config['secret_attempts_table'])) {
            $stmt = $conn->prepare("DELETE FROM {$config['secret_attempts_table']} WHERE {$config['attempts_id_column']} = ?");
            $stmt->bind_param("s", $account_id);
            $stmt->execute();
            $stmt->close();
        }
        
        // 3. Update lock history if table exists
        if (tableExists($conn, $config['lock_history_table'])) {
            $stmt = $conn->prepare("
                UPDATE {$config['lock_history_table']} 
                SET status = 'unlocked', 
                    unlocked_by = ?, 
                    unlock_method = 'Manual unlock by admin',
                    unlock_reason = ?,
                    unlocked_at = NOW() 
                WHERE {$config['lock_history_id_column']} = ? 
                AND status = 'locked'
                AND unlocked_at IS NULL
            ");
            $unlockReason = $reason ?: 'Unlocked by administrator';
            $stmt->bind_param("iss", $admin_id, $unlockReason, $account_id);
            $stmt->execute();
            $stmt->close();
        }
        
        $conn->commit();
        
        logActivity("[MULTI_USER_UNLOCK_SUCCESS] [ID:{$requestId}] {$config['table']} account {$account_id} unlocked");
        
        echo json_encode([
            'success' => true,
            'message' => "Account unlocked successfully",
            'account' => [
                'id' => $account_id,
                'email' => $account['email'],
                'name' => $fullName,
                'user_type' => $config['default_role']
            ]
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        logActivity("[MULTI_USER_UNLOCK_ERROR] [ID:{$requestId}] Failed to unlock: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to unlock account: ' . $e->getMessage()]);
    }
}

function bulkUnlockAccounts($conn, $admin_id, $admin_role, $config, $account_ids, $reason = '') {
    global $requestId;
    
    logActivity("[MULTI_USER_UNLOCK_BULK] [ID:{$requestId}] Admin {$admin_id} bulk unlocking " . count($account_ids) . " {$config['table']} accounts");
    
    $conn->begin_transaction();
    
    try {
        $results = [];
        $failed = [];
        
        foreach ($account_ids as $account_id) {
            try {
                // Clear login attempts
                if (tableExists($conn, $config['attempts_table'])) {
                    $stmt = $conn->prepare("DELETE FROM {$config['attempts_table']} WHERE {$config['attempts_id_column']} = ?");
                    $stmt->bind_param("s", $account_id);
                    $stmt->execute();
                    $stmt->close();
                }
                
                // Update lock history
                if (tableExists($conn, $config['lock_history_table'])) {
                    $stmt = $conn->prepare("
                        UPDATE {$config['lock_history_table']} 
                        SET unlocked_by = ?, 
                            unlock_method = 'Bulk unlock by admin',
                            unlock_reason = ?,
                            status = 'unlocked',
                            unlocked_at = NOW() 
                        WHERE {$config['lock_history_id_column']} = ? 
                        AND status = 'locked'
                        AND unlocked_at IS NULL
                    ");
                    $unlockReason = $reason ?: 'Bulk unlock by administrator';
                    $stmt->bind_param("iss", $admin_id, $unlockReason, $account_id);
                    $stmt->execute();
                    $stmt->close();
                }
                
                $results[] = ['id' => $account_id, 'success' => true];
                
            } catch (Exception $e) {
                $failed[] = ['id' => $account_id, 'error' => $e->getMessage()];
            }
        }
        
        $conn->commit();
        
        logActivity("[MULTI_USER_UNLOCK_BULK_SUCCESS] [ID:{$requestId}] Bulk unlock completed: " . count($results) . " successful");
        
        echo json_encode([
            'success' => true,
            'message' => 'Bulk unlock completed',
            'results' => $results,
            'failed' => $failed,
            'summary' => [
                'total' => count($account_ids),
                'successful' => count($results),
                'failed' => count($failed)
            ]
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        logActivity("[MULTI_USER_UNLOCK_BULK_ERROR] [ID:{$requestId}] Bulk unlock failed: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Bulk unlock failed: ' . $e->getMessage()]);
    }
}
?>