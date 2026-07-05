<?php
// settlement_api.php - Client API endpoints for settlement management

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
// require_once __DIR__ . '/../../utilities/rate_limit.php';

if (!isset($_SESSION)) session_start();
// rateLimiter();

// ==================== LOGGING HELPER ====================
function logClientSettlement($message, $data = null) {
    $logMsg = "[CLIENT_SETTLEMENT_API] " . $message;
    if ($data !== null) {
        $logMsg .= " | " . json_encode($data);
    }
    logActivity($logMsg);
}

// ==================== INPUT VALIDATION HELPERS ====================
function validateRequestId($id) {
    $id = filter_var($id, FILTER_VALIDATE_INT);
    if ($id === false || $id === null || $id <= 0) {
        return ['valid' => false, 'message' => 'Request ID must be a positive integer'];
    }
    return ['valid' => true, 'value' => $id];
}

function sanitizeReason($reason) {
    if ($reason === null || $reason === '') {
        return '';
    }
    $reason = strip_tags($reason);
    $reason = preg_replace('/[^\w\s\-.,?!()\'\"]/', '', $reason);
    $reason = substr($reason, 0, 500);
    return trim($reason);
}

// ==================== AUTHENTICATION ====================
$clientCode = $_SESSION['client_code'] ?? '';
$userId = $_SESSION['unique_id'] ?? '';
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

logClientSettlement("Request started", [
    'client_code' => $clientCode,
    'user_id' => $userId,
    'action' => $_GET['action'] ?? 'unknown',
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    'ip' => $ipAddress
]);

if (!isset($_SESSION['client_logged_in']) || !isset($_SESSION['client_code'])) {
        json_error("Unauthorized", 401);
    }

// ==================== GET ACTION ====================
$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

// Log sanitized input
$logInput = $input;
if (isset($logInput['password'])) unset($logInput['password']);
logClientSettlement("Input received", [
    'action' => $action,
    'input' => $logInput
]);

try {
    switch ($action) {
        case 'get_pending':
            getPendingRequests($clientCode);
            break;
            
        case 'get_history':
            getSettlementHistory($clientCode);
            break;
            
        case 'approve':
            approveSettlement($input, $clientCode, $userId);
            break;
            
        case 'decline':
            declineSettlement($input, $clientCode, $userId);
            break;
            
        default:
            logClientSettlement("Invalid action requested", ['action' => $action]);
            json_error('Invalid action', 400);
    }
} catch (Exception $e) {
    logClientSettlement("FATAL ERROR", [
        'message' => $e->getMessage(),
        'code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    json_error($e->getMessage(), 500);
}

// ==================== GET PENDING REQUESTS ====================
function getPendingRequests($clientCode) {
    global $conn;
    
    logClientSettlement("Fetching pending requests", ['client_code' => $clientCode]);
    
    try {
        $query = "
            SELECT 
                scr.id AS request_id,
                scr.proposed_admin_percentage,
                scr.proposed_agent_percentage,
                scr.proposed_client_percentage,
                scr.current_admin_percentage,
                scr.current_agent_percentage,
                scr.current_client_percentage,
                scr.proposed_at,
                scr.notes,
                scr.status,
                p.id AS property_id,
                p.property_code,
                p.name AS property_name,
                p.agent_code,
                u.firstname AS proposed_by_name,
                u.lastname AS proposed_by_lastname
            FROM settlement_change_requests scr
            JOIN properties p ON scr.property_id = p.id
            LEFT JOIN admin_tbl u ON scr.proposed_by = u.unique_id
            WHERE p.client_code = ? AND scr.status = 'pending'
            ORDER BY scr.proposed_at DESC
        ";
        
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            logClientSettlement("Database prepare error", ['error' => $conn->error]);
            json_error('Database error', 500);
        }
        
        $stmt->bind_param("s", $clientCode);
        
        if (!$stmt->execute()) {
            logClientSettlement("Database execute error", ['error' => $stmt->error]);
            $stmt->close();
            json_error('Database error', 500);
        }
        
        $result = $stmt->get_result();
        $requests = [];
        $count = 0;
        
        while ($row = $result->fetch_assoc()) {
            // Format dates
            $row['proposed_at_formatted'] = date('M d, Y h:i A', strtotime($row['proposed_at']));
            $row['proposed_by_fullname'] = trim(($row['proposed_by_name'] ?? '') . ' ' . ($row['proposed_by_lastname'] ?? ''));
            $row['is_urgent'] = (strpos(strtolower($row['notes'] ?? ''), 'urgent') !== false);
            
            // Calculate differences
            $row['admin_diff'] = $row['proposed_admin_percentage'] - $row['current_admin_percentage'];
            $row['agent_diff'] = $row['proposed_agent_percentage'] - $row['current_agent_percentage'];
            $row['client_diff'] = $row['proposed_client_percentage'] - $row['current_client_percentage'];
            
            $requests[] = $row;
            $count++;
        }
        
        $stmt->close();
        
        logClientSettlement("Pending requests fetched", [
            'client_code' => $clientCode,
            'count' => $count
        ]);
        
        json_success($requests, 'Pending requests retrieved successfully');
        
    } catch (Exception $e) {
        logClientSettlement("Error fetching pending requests", [
            'client_code' => $clientCode,
            'error' => $e->getMessage()
        ]);
        throw $e;
    }
}

// ==================== GET SETTLEMENT HISTORY ====================
function getSettlementHistory($clientCode) {
    global $conn;
    
    $limit = isset($_GET['limit']) ? filter_var($_GET['limit'], FILTER_SANITIZE_NUMBER_INT) : 50;
    $limit = min(max($limit, 1), 100); // Between 1 and 100
    
    logClientSettlement("Fetching settlement history", [
        'client_code' => $clientCode,
        'limit' => $limit
    ]);
    
    try {
        $query = "
            SELECT 
                scr.id AS request_id,
                scr.proposed_admin_percentage,
                scr.proposed_agent_percentage,
                scr.proposed_client_percentage,
                scr.current_admin_percentage,
                scr.current_agent_percentage,
                scr.current_client_percentage,
                scr.proposed_at,
                scr.approved_at,
                scr.status,
                scr.rejection_reason,
                scr.notes,
                p.id AS property_id,
                p.property_code,
                p.name AS property_name,
                u.firstname AS proposed_by_name,
                u.lastname AS proposed_by_lastname,
                a.firstname AS approved_by_name,
                a.lastname AS approved_by_lastname
            FROM settlement_change_requests scr
            JOIN properties p ON scr.property_id = p.id
            LEFT JOIN admin_tbl u ON scr.proposed_by = u.unique_id
            LEFT JOIN admin_tbl a ON scr.approved_by = a.unique_id
            WHERE p.client_code = ? AND scr.status IN ('approved', 'rejected')
            ORDER BY scr.proposed_at DESC
            LIMIT ?
        ";
        
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            logClientSettlement("Database prepare error", ['error' => $conn->error]);
            json_error('Database error', 500);
        }
        
        $stmt->bind_param("si", $clientCode, $limit);
        
        if (!$stmt->execute()) {
            logClientSettlement("Database execute error", ['error' => $stmt->error]);
            $stmt->close();
            json_error('Database error', 500);
        }
        
        $result = $stmt->get_result();
        $history = [];
        $count = 0;
        
        while ($row = $result->fetch_assoc()) {
            $row['proposed_at_formatted'] = date('M d, Y h:i A', strtotime($row['proposed_at']));
            $row['approved_at_formatted'] = $row['approved_at'] ? date('M d, Y h:i A', strtotime($row['approved_at'])) : null;
            $row['proposed_by_fullname'] = trim(($row['proposed_by_name'] ?? '') . ' ' . ($row['proposed_by_lastname'] ?? ''));
            $row['approved_by_fullname'] = trim(($row['approved_by_name'] ?? '') . ' ' . ($row['approved_by_lastname'] ?? ''));
            $row['status_label'] = $row['status'] === 'approved' ? 'Approved' : 'Rejected';
            $row['status_class'] = $row['status'] === 'approved' ? 'approved' : 'rejected';
            
            $history[] = $row;
            $count++;
        }
        
        $stmt->close();
        
        logClientSettlement("Settlement history fetched", [
            'client_code' => $clientCode,
            'count' => $count
        ]);
        
        json_success($history, 'Settlement history retrieved successfully');
        
    } catch (Exception $e) {
        logClientSettlement("Error fetching settlement history", [
            'client_code' => $clientCode,
            'error' => $e->getMessage()
        ]);
        throw $e;
    }
}

// ==================== APPROVE SETTLEMENT ====================
function approveSettlement($input, $clientCode, $userId) {
    global $conn;
    
    logClientSettlement("Settlement approval requested", [
        'client_code' => $clientCode,
        'user_id' => $userId
    ]);
    
    // ==================== INPUT SANITIZATION ====================
    $requestId = isset($input['request_id']) ? filter_var($input['request_id'], FILTER_SANITIZE_NUMBER_INT) : 0;
    
    logClientSettlement("Sanitized approval input", [
        'request_id' => $requestId
    ]);
    
    // ==================== INPUT VALIDATION ====================
    $requestValidation = validateRequestId($requestId);
    if (!$requestValidation['valid']) {
        logClientSettlement("Validation failed: " . $requestValidation['message']);
        json_error($requestValidation['message'], 400);
    }
    $requestId = $requestValidation['value'];
    
    // ==================== VERIFY REQUEST BELONGS TO CLIENT ====================
    $verifyQuery = "
        SELECT scr.*, p.client_code, p.id AS property_id, p.property_code
        FROM settlement_change_requests scr
        JOIN properties p ON scr.property_id = p.id
        WHERE scr.id = ? AND scr.status = 'pending'
    ";
    
    $verifyStmt = $conn->prepare($verifyQuery);
    $verifyStmt->bind_param("i", $requestId);
    $verifyStmt->execute();
    $result = $verifyStmt->get_result();
    $request = $result->fetch_assoc();
    $verifyStmt->close();
    
    if (!$request) {
        logClientSettlement("Request not found or already processed", [
            'request_id' => $requestId,
            'client_code' => $clientCode
        ]);
        json_error('Request not found or already processed', 404);
    }
    
    if ($request['client_code'] !== $clientCode) {
        logClientSettlement("Authorization failed - request belongs to different client", [
            'request_id' => $requestId,
            'client_code' => $clientCode,
            'request_client_code' => $request['client_code']
        ]);
        json_error('You do not have permission to process this request', 403);
    }
    
    logClientSettlement("Request verified", [
        'request_id' => $requestId,
        'property_id' => $request['property_id'],
        'property_code' => $request['property_code']
    ]);
    
    // ==================== START TRANSACTION ====================
    $conn->begin_transaction();
    logClientSettlement("Transaction started for approval", [
        'request_id' => $requestId
    ]);
    
    try {
        // Update settlement values
        $updateSql = "
            UPDATE property_settlement 
            SET admin_percentage = ?, 
                agent_percentage = ?, 
                client_percentage = ?,
                status = 'active',
                updated_by = ?,
                updated_at = NOW()
            WHERE property_id = ?
        ";
        
        $updateStmt = $conn->prepare($updateSql);
        if (!$updateStmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $updatedBy = $userId ?: $clientCode;
        $updateStmt->bind_param(
            "dddsi",
            $request['proposed_admin_percentage'],
            $request['proposed_agent_percentage'],
            $request['proposed_client_percentage'],
            $updatedBy,
            $request['property_id']
        );
        
        if (!$updateStmt->execute()) {
            throw new Exception("Failed to update settlement: " . $updateStmt->error);
        }
        $updateStmt->close();
        
        logClientSettlement("Settlement values updated", [
            'property_id' => $request['property_id'],
            'admin' => $request['proposed_admin_percentage'],
            'agent' => $request['proposed_agent_percentage'],
            'client' => $request['proposed_client_percentage']
        ]);
        
        // Update request status
        $requestSql = "
            UPDATE settlement_change_requests 
            SET status = 'approved', 
                approved_by = ?, 
                approved_at = NOW()
            WHERE id = ?
        ";
        
        $requestStmt = $conn->prepare($requestSql);
        if (!$requestStmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $requestStmt->bind_param("si", $userId, $requestId);
        
        if (!$requestStmt->execute()) {
            throw new Exception("Failed to update request: " . $requestStmt->error);
        }
        $requestStmt->close();
        
        // Commit transaction
        $conn->commit();
        logClientSettlement("Transaction committed", [
            'request_id' => $requestId,
            'property_id' => $request['property_id']
        ]);
        
        logActivity("Client {$clientCode} approved settlement change for property ID: {$request['property_id']} (Request ID: {$requestId})");
        
        json_success([
            'request_id' => $requestId,
            'property_id' => $request['property_id'],
            'property_code' => $request['property_code'],
            'status' => 'approved'
        ], 'Settlement change approved successfully!');
        
    } catch (Exception $e) {
        $conn->rollback();
        logClientSettlement("Transaction failed", [
            'request_id' => $requestId,
            'error' => $e->getMessage()
        ]);
        throw $e;
    }
}

// ==================== DECLINE SETTLEMENT ====================
function declineSettlement($input, $clientCode, $userId) {
    global $conn;
    
    logClientSettlement("Settlement decline requested", [
        'client_code' => $clientCode,
        'user_id' => $userId
    ]);
    
    // ==================== INPUT SANITIZATION ====================
    $requestId = isset($input['request_id']) ? filter_var($input['request_id'], FILTER_SANITIZE_NUMBER_INT) : 0;
    $reason = isset($input['reason']) ? sanitizeReason($input['reason']) : '';
    
    logClientSettlement("Sanitized decline input", [
        'request_id' => $requestId,
        'reason_length' => strlen($reason)
    ]);
    
    // ==================== INPUT VALIDATION ====================
    $requestValidation = validateRequestId($requestId);
    if (!$requestValidation['valid']) {
        logClientSettlement("Validation failed: " . $requestValidation['message']);
        json_error($requestValidation['message'], 400);
    }
    $requestId = $requestValidation['value'];
    
    if (empty($reason)) {
        logClientSettlement("Validation failed: Reason is required");
        json_error('Please provide a reason for declining', 400);
    }
    
    // ==================== VERIFY REQUEST BELONGS TO CLIENT ====================
    $verifyQuery = "
        SELECT scr.*, p.client_code, p.id AS property_id, p.property_code
        FROM settlement_change_requests scr
        JOIN properties p ON scr.property_id = p.id
        WHERE scr.id = ? AND scr.status = 'pending'
    ";
    
    $verifyStmt = $conn->prepare($verifyQuery);
    $verifyStmt->bind_param("i", $requestId);
    $verifyStmt->execute();
    $result = $verifyStmt->get_result();
    $request = $result->fetch_assoc();
    $verifyStmt->close();
    
    if (!$request) {
        logClientSettlement("Request not found or already processed", [
            'request_id' => $requestId,
            'client_code' => $clientCode
        ]);
        json_error('Request not found or already processed', 404);
    }
    
    if ($request['client_code'] !== $clientCode) {
        logClientSettlement("Authorization failed - request belongs to different client", [
            'request_id' => $requestId,
            'client_code' => $clientCode,
            'request_client_code' => $request['client_code']
        ]);
        json_error('You do not have permission to process this request', 403);
    }
    
    logClientSettlement("Request verified", [
        'request_id' => $requestId,
        'property_id' => $request['property_id'],
        'property_code' => $request['property_code']
    ]);
    
    // ==================== START TRANSACTION ====================
    $conn->begin_transaction();
    logClientSettlement("Transaction started for decline", [
        'request_id' => $requestId
    ]);
    
    try {
        // Update request status
        $requestSql = "
            UPDATE settlement_change_requests 
            SET status = 'rejected', 
                approved_by = ?, 
                approved_at = NOW(),
                rejection_reason = ?
            WHERE id = ?
        ";
        
        $requestStmt = $conn->prepare($requestSql);
        if (!$requestStmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $requestStmt->bind_param("ssi", $userId, $reason, $requestId);
        
        if (!$requestStmt->execute()) {
            throw new Exception("Failed to update request: " . $requestStmt->error);
        }
        $requestStmt->close();
        
        // Reset property_settlement status to 'active'
        $resetSql = "UPDATE property_settlement SET status = 'active' WHERE property_id = ?";
        $resetStmt = $conn->prepare($resetSql);
        if (!$resetStmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $resetStmt->bind_param("i", $request['property_id']);
        
        if (!$resetStmt->execute()) {
            throw new Exception("Failed to reset status: " . $resetStmt->error);
        }
        $resetStmt->close();
        
        // Commit transaction
        $conn->commit();
        logClientSettlement("Transaction committed", [
            'request_id' => $requestId,
            'property_id' => $request['property_id']
        ]);
        
        logActivity("Client {$clientCode} declined settlement change for property ID: {$request['property_id']} (Request ID: {$requestId}). Reason: " . substr($reason, 0, 100));
        
        json_success([
            'request_id' => $requestId,
            'property_id' => $request['property_id'],
            'property_code' => $request['property_code'],
            'status' => 'rejected'
        ], 'Settlement change declined.');
        
    } catch (Exception $e) {
        $conn->rollback();
        logClientSettlement("Transaction failed", [
            'request_id' => $requestId,
            'error' => $e->getMessage()
        ]);
        throw $e;
    }
}
