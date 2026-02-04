<?php
// backend/unlock_account.php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';

// Start logging
$requestId = uniqid('unlock_', true);
logActivity("[ACCOUNT_UNLOCK_START] [ID:{$requestId}] Account unlock request started");

// Check authentication
if (!isset($_SESSION['unique_id'])) {
    logActivity("[ACCOUNT_UNLOCK_ERROR] [ID:{$requestId}] No session found");
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Please login first']);
    exit;
}

// Check user role - only Super Admin or Admin can unlock accounts
$user_id = $_SESSION['unique_id'];
$user_role = $_SESSION['role'] ?? '';

if (!in_array($user_role, ['Super Admin', 'Admin'])) {
    logActivity("[ACCOUNT_UNLOCK_ERROR] [ID:{$requestId}] Insufficient permissions for user: {$user_id}, role: {$user_role}");
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

logActivity("[ACCOUNT_UNLOCK] [ID:{$requestId}] User ID: {$user_id}, Role: {$user_role}, Method: {$method}");

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
    logActivity("[ACCOUNT_UNLOCK_EXCEPTION] [ID:{$requestId}] Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error'
    ]);
}

function handleGet($conn, $user_id, $user_role) {
    global $requestId;
    logActivity("[ACCOUNT_UNLOCK_GET] [ID:{$requestId}] Fetching locked accounts");
    
    // Get query parameters
    $search = $_GET['search'] ?? '';
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 20;
    $offset = ($page - 1) * $limit;
    
    logActivity("[ACCOUNT_UNLOCK_GET] [ID:{$requestId}] Params - search: {$search}, page: {$page}, limit: {$limit}");
    
    // Build query to get locked accounts with user details
    $query = "SELECT 
                a.unique_id,
                a.email,
                a.firstname,
                a.lastname,
                a.role,
                a.status,
               
                ala.attempts,
                ala.locked_until,
                alh.locked_at as last_locked_at,
                alh.lock_reason
              FROM admin_tbl a
              LEFT JOIN admin_login_attempts ala ON a.unique_id = ala.unique_id
              LEFT JOIN (
                  SELECT unique_id, MAX(locked_at) as locked_at, lock_reason 
                  FROM admin_lock_history 
                  WHERE status = 'locked' 
                  AND unlocked_at IS NULL
                  GROUP BY unique_id
              ) alh ON a.unique_id = alh.unique_id
              WHERE (ala.attempts >= 3 AND ala.locked_until > NOW()) 
                 OR alh.locked_at IS NOT NULL";
    
    $params = [];
    $paramTypes = "";
    
    if ($search) {
        $query .= " AND (a.email LIKE ? OR a.firstname LIKE ? OR a.lastname LIKE ?)";
        $searchTerm = "%{$search}%";
        $params = [$searchTerm, $searchTerm, $searchTerm];
        $paramTypes = "sss";
    }
    
    // Get total count
    $countQuery = "SELECT COUNT(*) as total FROM ($query) as subquery";
    $countStmt = $conn->prepare($countQuery);
    
    if ($search) {
        $countStmt->bind_param($paramTypes, ...$params);
    }
    
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalCount = $countResult->fetch_assoc()['total'] ?? 0;
    $countStmt->close();
    
    // Add pagination to main query
    $query .= " ORDER BY ala.locked_until DESC, alh.locked_at DESC 
                LIMIT ? OFFSET ?";
    
    $params[] = $limit;
    $params[] = $offset;
    $paramTypes .= "ii";
    
    // Execute main query
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error);
    }
    
    $stmt->bind_param($paramTypes, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $lockedAccounts = [];
    while ($row = $result->fetch_assoc()) {
        // Determine lock status
        $isLocked = false;
        $lockType = '';
        $lockTimeRemaining = '';
        
        // Check for login attempt lock
        if ($row['locked_until']) {
            $lockedUntil = new DateTime($row['locked_until']);
            $currentTime = new DateTime();
            
            if ($currentTime < $lockedUntil) {
                $isLocked = true;
                $lockType = 'login_attempts';
                $remaining = $currentTime->diff($lockedUntil);
                $lockTimeRemaining = $remaining->format('%h hours %i minutes');
            }
        }
        
        // Check for manual lock
        if ($row['last_locked_at'] && !$isLocked) {
            $isLocked = true;
            $lockType = 'manual_lock';
            $lockTimeRemaining = 'Manually locked';
        }
        
        if ($isLocked) {
            $row['lock_type'] = $lockType;
            $row['lock_time_remaining'] = $lockTimeRemaining;
            $row['is_locked'] = true;
            $lockedAccounts[] = $row;
        }
    }
    
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'accounts' => $lockedAccounts,
        'pagination' => [
            'total' => $totalCount,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => ceil($totalCount / $limit)
        ]
    ]);
}

function handlePost($conn, $user_id, $user_role, $data = null) {
    global $requestId;
    
    if ($data === null) {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
    }
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        logActivity("[ACCOUNT_UNLOCK_POST_ERROR] [ID:{$requestId}] Invalid JSON");
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
        return;
    }
    
    if (!isset($data['action'])) {
        logActivity("[ACCOUNT_UNLOCK_POST_ERROR] [ID:{$requestId}] No action specified");
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Action is required']);
        return;
    }
    
    $action = $data['action'];
    
    switch ($action) {
        case 'unlock_account':
            if (!isset($data['account_id'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Account ID is required']);
                return;
            }
            unlockAccount($conn, $user_id, $user_role, $data['account_id'], $data['reason'] ?? '');
            break;
            
        case 'bulk_unlock':
            if (!isset($data['account_ids']) || !is_array($data['account_ids'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Account IDs array is required']);
                return;
            }
            bulkUnlockAccounts($conn, $user_id, $user_role, $data['account_ids'], $data['reason'] ?? '');
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}

function unlockAccount($conn, $admin_id, $admin_role, $account_id, $reason = '') {
    global $requestId;
    
    logActivity("[ACCOUNT_UNLOCK_SINGLE] [ID:{$requestId}] Admin {$admin_id} unlocking account {$account_id}");
    
    $conn->begin_transaction();
    
    try {
        // Get account details first
        $stmt = $conn->prepare("SELECT email, firstname, lastname FROM admin_tbl WHERE unique_id = ?");
        $stmt->bind_param("i", $account_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $account = $result->fetch_assoc();
        $stmt->close();
        
        if (!$account) {
            throw new Exception("Account not found");
        }
        
        // 1. Clear login attempts
        $stmt = $conn->prepare("DELETE FROM admin_login_attempts WHERE unique_id = ?");
        $stmt->bind_param("i", $account_id);
        $stmt->execute();
        $stmt->close();
        
        // 2. Clear secret answer attempts (if table exists)
        $secretTableExists = false;
        $checkTable = $conn->query("SHOW TABLES LIKE 'admin_secret_attempts'");
        if ($checkTable && $checkTable->num_rows > 0) {
            $secretTableExists = true;
            $stmt = $conn->prepare("DELETE FROM admin_secret_attempts WHERE unique_id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $account_id);
                $stmt->execute();
                $stmt->close();
            }
        }
        
        // 3. Update lock history - set unlocked_by and unlocked_at
        $stmt = $conn->prepare("
            UPDATE admin_lock_history 
            SET status = 'unlocked', unlocked_by = ?, 
                unlock_method = 'Manual unlock by admin',
                unlocked_at = NOW() 
            WHERE unique_id = ? 
            AND status = 'locked'
            AND unlocked_at IS NULL
        ");
        $stmt->bind_param("ii", $admin_id, $account_id);
        $stmt->execute();
        $updatedRows = $stmt->affected_rows;
        $stmt->close();
        
        // 4. If no rows were updated in lock history, create an unlock record
        if ($updatedRows === 0) {
            $status = 'locked';
            // First check if there's any lock history for this user
            $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM admin_lock_history WHERE unique_id = ? AND status = ?");
            $checkStmt->bind_param("is", $account_id, $status);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            $checkRow = $checkResult->fetch_assoc();
            $checkStmt->close();
            
            if ($checkRow['count'] > 0) {
                // Update existing record (even if it's not currently locked)
                $stmt = $conn->prepare("
                    UPDATE admin_lock_history 
                    SET status = 'unlocked', unlocked_by = ?, 
                        unlock_method = 'Manual unlock by admin',
                        unlocked_at = NOW() 
                    WHERE unique_id = ? 
                    AND unlocked_at IS NULL
                    ORDER BY locked_at DESC 
                    LIMIT 1
                ");
                $stmt->bind_param("ii", $admin_id, $account_id);
                $stmt->execute();
                $stmt->close();
            } else {
                // Create a new unlock record
                $stmt = $conn->prepare("
                    INSERT INTO admin_lock_history 
                    (unique_id, status, locked_by, unlocked_by, lock_reason, unlock_method, lock_method, locked_at, unlocked_at)
                    VALUES (?, 'unlocked', '0', ?, 'System lock', 'Manual unlock by admin', 'System', NOW() - INTERVAL 1 HOUR, NOW())
                ");
                $stmt->bind_param("ii", $account_id, $admin_id);
                $stmt->execute();
                $stmt->close();
            }
        }
        
        $conn->commit();
        
        // Create notification for the unlocked user
        try {
            createNotification($conn, [
                'user_id' => $account_id,
                'title' => 'Account Unlocked',
                'message' => "Your account has been unlocked by an administrator. You can now log in again.",
                'type' => 'SUCCESS',
                'category' => 'account_lock'
            ]);
        } catch (Exception $e) {
            // Log but don't fail
            logActivity("[ACCOUNT_UNLOCK_NOTIFICATION_ERROR] [ID:{$requestId}] " . $e->getMessage());
        }
        
        // Log the action
        logActivity("[ACCOUNT_UNLOCK_SUCCESS] [ID:{$requestId}] Account {$account_id} ({$account['email']}) unlocked by admin {$admin_id}");
        
        echo json_encode([
            'success' => true,
            'message' => "Account unlocked successfully",
            'account' => [
                'id' => $account_id,
                'email' => $account['email'],
                'name' => $account['firstname'] . ' ' . $account['lastname']
            ]
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        logActivity("[ACCOUNT_UNLOCK_ERROR] [ID:{$requestId}] Failed to unlock account {$account_id}: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to unlock account: ' . $e->getMessage()
        ]);
    }
}

function bulkUnlockAccounts($conn, $admin_id, $admin_role, $account_ids, $reason = '') {
    global $requestId;
    
    logActivity("[ACCOUNT_UNLOCK_BULK] [ID:{$requestId}] Admin {$admin_id} bulk unlocking " . count($account_ids) . " accounts");
    
    $conn->begin_transaction();
    
    try {
        $results = [];
        $failed = [];
        
        foreach ($account_ids as $account_id) {
            try {
                // Clear login attempts
                $stmt = $conn->prepare("DELETE FROM admin_login_attempts WHERE unique_id = ?");
                $stmt->bind_param("i", $account_id);
                $stmt->execute();
                $stmt->close();
                
                // Update lock history
                $stmt = $conn->prepare("
                    UPDATE admin_lock_history 
                    SET unlocked_by = ?, 
                        unlock_method = 'Bulk unlock by admin',
                        status = 'unlocked',
                        unlocked_at = NOW() 
                    WHERE unique_id = ? 
                    AND status = 'locked'
                    AND unlocked_at IS NULL
                ");
                $stmt->bind_param("ii", $admin_id, $account_id);
                $stmt->execute();
                $stmt->close();
                
                // Get account email for result
                $stmt = $conn->prepare("SELECT email FROM admin_tbl WHERE unique_id = ?");
                $stmt->bind_param("i", $account_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $account = $result->fetch_assoc();
                $stmt->close();
                
                $results[] = [
                    'id' => $account_id,
                    'email' => $account['email'] ?? 'Unknown',
                    'success' => true
                ];
                
            } catch (Exception $e) {
                $failed[] = [
                    'id' => $account_id,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        $conn->commit();
        
        logActivity("[ACCOUNT_UNLOCK_BULK_SUCCESS] [ID:{$requestId}] Bulk unlock completed: " . count($results) . " successful, " . count($failed) . " failed");
        
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
        logActivity("[ACCOUNT_UNLOCK_BULK_ERROR] [ID:{$requestId}] Bulk unlock failed: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Bulk unlock failed: ' . $e->getMessage()
        ]);
    }
}

// Handle direct POST from form if needed
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['direct_action'])) {
    $data = [
        'action' => $_POST['direct_action'],
        'account_id' => $_POST['account_id'] ?? null,
        'account_ids' => isset($_POST['account_ids']) ? json_decode($_POST['account_ids'], true) : [],
        'reason' => $_POST['reason'] ?? ''
    ];
    handlePost($conn, $user_id, $user_role, $data);
}
?>