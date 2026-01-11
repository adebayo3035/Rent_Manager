<?php
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_utils.php';
require_once __DIR__ . '/utils.php';

session_start();

class ReactivationReviewService {
    private $conn;
    private $adminId;
    
    public function __construct($conn, $adminId) {
        $this->conn = $conn;
        $this->adminId = $adminId;
    }
    
    public function reviewRequest($requestId, $action, $notes = '', $rejectionReason = '') {
        $logPrefix = "[REVIEW_REQUEST] AdminID: {$this->adminId}";
        
        logActivity("{$logPrefix} Starting review for request ID: {$requestId}, Action: {$action}");
        
        // Validate action
        $validActions = ['approve', 'reject'];
        if (!in_array($action, $validActions)) {
            return [
                'success' => false, 
                'message' => 'Invalid action. Must be "approve" or "reject"'
            ];
        }
        
        $this->conn->begin_transaction();
        
        try {
            // Get the request details with FOR UPDATE to lock the row
            $query = "SELECT * FROM account_reactivation_requests WHERE id = ? FOR UPDATE";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $requestId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                $stmt->close();
                throw new Exception("Request not found: {$requestId}");
            }
            
            $request = $result->fetch_assoc();
            $stmt->close();
            
            // Check if already processed
            if ($request['status'] !== 'pending') {
                throw new Exception("Request already processed - Status: {$request['status']}");
            }
            
            // Update the request
            $newStatus = ($action === 'approve') ? 'approved' : 'rejected';
            $updateQuery = "UPDATE account_reactivation_requests 
                           SET status = ?, 
                               reviewed_by = ?, 
                               review_notes = ?, 
                               rejection_reason = ?,
                               review_timestamp = NOW(),
                               updated_at = NOW()
                           WHERE id = ?";
            
            $stmt = $this->conn->prepare($updateQuery);
            $rejectionReason = ($action === 'reject') ? $rejectionReason : null;
            
            $stmt->bind_param("sissi", 
                $newStatus,
                $this->adminId,
                $notes,
                $rejectionReason,
                $requestId
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update request: " . $stmt->error);
            }
            $stmt->close();
            
            // If approved, activate the user account
            if ($action === 'approve') {
                $this->activateUserAccount($request['user_type'], $request['user_id'], $request['email']);
                
                // Send notification to user
                $this->sendUserNotification($request['email'], 'approved', [
                    'user_name' => $this->getUserName($request['user_type'], $request['user_id']),
                    'request_id' => $requestId
                ]);
            } else {
                // Send rejection notification
                $this->sendUserNotification($request['email'], 'rejected', [
                    'user_name' => $this->getUserName($request['user_type'], $request['user_id']),
                    'request_id' => $requestId,
                    'rejection_reason' => $rejectionReason
                ]);
            }
            
            // Log admin activity
            // $this->logAdminActivity($requestId, $action, $request);
            
            $this->conn->commit();
            
            logActivity("[REQUEST_REVIEWED] {$logPrefix} Request ID: {$requestId} {$newStatus}");
            
            return [
                'success' => true,
                'message' => "Request {$newStatus} successfully",
                'request_id' => $requestId,
                'status' => $newStatus
            ];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            throw $e;
        }
    }
    
    private function activateUserAccount($userType, $userId, $email) {
        // Map user types to tables and id columns
        $tables = [
            'admin' => ['table' => 'admin_tbl', 'id_column' => 'unique_id'],
            'agent' => ['table' => 'agents', 'id_column' => 'agent_code'],
            'client' => ['table' => 'clients', 'id_column' => 'client_code'],
            'tenant' => ['table' => 'tenants', 'id_column' => 'tenant_code']
        ];
        
        if (!isset($tables[$userType])) {
            throw new Exception("Invalid user type for activation: {$userType}");
        }
        
        $table = $tables[$userType]['table'];
        $idColumn = $tables[$userType]['id_column'];
        
        // Activate the account (set status to '1' for active)
        $query = "UPDATE {$table} SET status = '1', date_last_updated = NOW() 
                  WHERE {$idColumn} = ? AND email = ?";
        
        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            throw new Exception("Failed to prepare activation statement");
        }
        
        $stmt->bind_param("ss", $userId, $email);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to activate account: " . $stmt->error);
        }
        
        $affected = $stmt->affected_rows;
        $stmt->close();
        
        if ($affected === 0) {
            throw new Exception("No account found to activate");
        }
        
        logActivity("[ACCOUNT_ACTIVATED] User Type: {$userType}, User ID: {$userId}, Email: {$email}");
    }
    
    private function getUserName($userType, $userId) {
        $query = "SELECT CONCAT_WS(' ', firstname, lastname) as full_name 
                 FROM {$this->getUserTable($userType)} 
                 WHERE {$this->getIdColumn($userType)} = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("s", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            return $row['full_name'] ?: 'User';
        }
        
        return 'User';
    }
    
    private function getUserTable($userType) {
        $tables = [
            'admin' => 'admin_tbl',
            'agent' => 'agents',
            'client' => 'clients',
            'tenant' => 'tenants'
        ];
        
        return $tables[$userType] ?? 'admin_tbl';
    }
    
    private function getIdColumn($userType) {
        $columns = [
            'admin' => 'unique_id',
            'agent' => 'agent_code',
            'client' => 'client_code',
            'tenant' => 'tenant_code'
        ];
        
        return $columns[$userType] ?? 'unique_id';
    }
    
    private function sendUserNotification($email, $status, $data) {
        // This is a placeholder - implement your email sending logic
        logActivity("Would send {$status} notification to: {$email}");
        // sendEmailWithTemplate($email, $status, $data);
    }
    
    private function logAdminActivity($requestId, $action, $request) {
        $activityType = $action === 'approve' ? 'reactivation_approved' : 'reactivation_rejected';
        $description = "{$action} reactivation request #{$requestId} for {$request['email']}";
        
        $query = "INSERT INTO admin_activity_logs 
                 (admin_id, activity_type, description, affected_user_type, 
                  affected_user_id, affected_email, request_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $this->conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param("isssssi", 
                $this->adminId,
                $activityType,
                $description,
                $request['user_type'],
                $request['user_id'],
                $request['email'],
                $requestId
            );
            $stmt->execute();
            $stmt->close();
        }
    }
}

// ================================
// MAIN EXECUTION
// ================================
try {
    // Authentication check
    if (!isset($_SESSION['unique_id'])) {
        logActivity("Unauthorized review attempt | No session | IP: " . getClientIP());
        http_response_code(401);
        echo json_encode([
            "success" => false, 
            "message" => "Not logged in. Please login again.",
            "code" => 401
        ]);
        exit();
    }

    $adminId = $_SESSION['unique_id'];
    $loggedInUserRole = $_SESSION['role'] ?? 'Unknown';

    // Authorization check
    $allowedRoles = ['super admin'];
    if (!in_array(strtolower($loggedInUserRole), $allowedRoles)) {
        logActivity("Unauthorized role review attempt | Role: {$loggedInUserRole} | AdminID: {$adminId}");
        http_response_code(403);
        echo json_encode([
            "success" => false, 
            "message" => "You don't have permission to review requests.",
            "code" => 403
        ]);
        exit();
    }

    // Database connection check
    if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
        http_response_code(503);
        echo json_encode([
            "success" => false, 
            "message" => "Database connection error. Please try again later.",
            "code" => 503
        ]);
        exit();
    }

    // Get and validate input
    $input = json_decode(file_get_contents("php://input"), true);
    
    if (empty($input['request_id']) || empty($input['action'])) {
        http_response_code(400);
        echo json_encode([
            "success" => false, 
            "message" => "Missing required fields: request_id and action",
            "code" => 400
        ]);
        exit();
    }

    $requestId = (int)$input['request_id'];
    $action = trim($input['action']);
    $notes = trim($input['notes'] ?? '');
    $rejectionReason = trim($input['rejection_reason'] ?? '');

    // Validate rejection reason if rejecting
    if ($action === 'reject' && empty($rejectionReason)) {
        http_response_code(400);
        echo json_encode([
            "success" => false, 
            "message" => "Rejection reason is required when rejecting a request",
            "code" => 400
        ]);
        exit();
    }

    logActivity("Review attempt | Request ID: {$requestId} | Action: {$action} | AdminID: {$adminId}");

    // Process review
    $service = new ReactivationReviewService($conn, $adminId);
    $result = $service->reviewRequest($requestId, $action, $notes, $rejectionReason);

    http_response_code(200);
    echo json_encode($result);

} catch (Exception $e) {
    logActivity("REVIEW EXCEPTION | " . $e->getMessage() . " | IP: " . getClientIP());
    
    if (isset($conn) && $conn instanceof mysqli && $conn->connect_errno == 0) {
        $conn->close();
    }

    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "An unexpected error occurred: " . $e->getMessage(),
        "code" => 500
    ]);
    exit();
}
