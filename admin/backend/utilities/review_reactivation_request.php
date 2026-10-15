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
            // Lock the request row
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
            
            // Ensure still pending
            if ($request['status'] !== 'pending') {
                throw new Exception("Request already processed - Status: {$request['status']}");
            }
            
            // ============================================================
            // ===== NEW: Pre-approval eligibility re-check =====
            // Even though the request was valid when submitted, the user's
            // state may have changed since (e.g., tenant evacuated). Reject
            // or auto-cancel if the state no longer supports reactivation.
            // ============================================================
            if ($action === 'approve') {
                $eligibility = $this->recheckEligibility(
                    $request['user_type'],
                    $request['user_id']
                );
                
                if (!$eligibility['eligible']) {
                    // Auto-cancel the stale request
                    $this->autoCancelStaleRequest(
                        $requestId,
                        $eligibility['message'],
                        $eligibility['error_code'] ?? 'STATE_CHANGED'
                    );
                    
                    $this->conn->commit();
                    
                    logActivity("[APPROVAL_BLOCKED] {$logPrefix} "
                        . "Request {$requestId} auto-cancelled — "
                        . $eligibility['message']);
                    
                    return [
                        'success'    => false,
                        'message'    => $eligibility['message'],
                        'code'       => 409,
                        'error_code' => $eligibility['error_code'] ?? 'STATE_CHANGED',
                        'auto_cancelled' => true,
                    ];
                }
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
            
            // Approve → activate the account
            if ($action === 'approve') {
                $this->activateUserAccount($request['user_type'], $request['user_id'], $request['email']);
                
                $this->sendUserNotification($request['email'], 'approved', [
                    'user_name'  => $this->getUserName($request['user_type'], $request['user_id']),
                    'request_id' => $requestId,
                ]);
            } else {
                $this->sendUserNotification($request['email'], 'rejected', [
                    'user_name'        => $this->getUserName($request['user_type'], $request['user_id']),
                    'request_id'       => $requestId,
                    'rejection_reason' => $rejectionReason,
                ]);
            }
            
            $this->conn->commit();
            
            logActivity("[REQUEST_REVIEWED] {$logPrefix} Request ID: {$requestId} {$newStatus}");
            
            return [
                'success'    => true,
                'message'    => "Request {$newStatus} successfully",
                'request_id' => $requestId,
                'status'     => $newStatus,
            ];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            throw $e;
        }
    }
    
    // ================================================================
    // ===== NEW: Re-check eligibility at approval time =====
    // ================================================================
    /**
     * Re-verify the user's state before approving.
     *
     * For tenants specifically, we must ensure they haven't evacuated,
     * been suspended, or become blocked since the request was submitted.
     *
     * @param string $userType
     * @param string $userId
     * @return array{eligible: bool, message?: string, error_code?: string}
     */
    private function recheckEligibility($userType, $userId)
    {
        // Only tenants need the deeper state check.
        // Admin/agent/client states are simpler (status only).
        if ($userType !== 'tenant') {
            // Optional: apply analogous checks for other user types if needed
            return ['eligible' => true];
        }
        
        $stmt = $this->conn->prepare("
            SELECT status, tenant_status, evacuation_status
            FROM tenants
            WHERE tenant_code = ?
            LIMIT 1
        ");
        
        if (!$stmt) {
            logActivity("[RECHECK_PREPARE_ERROR] Failed to prepare tenant state check");
            // Fail closed — safer to block than approve on uncertain data
            return [
                'eligible'   => false,
                'message'    => 'Unable to verify tenant state. Please try again.',
                'error_code' => 'RECHECK_FAILED',
            ];
        }
        
        $stmt->bind_param("s", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$row) {
            return [
                'eligible'   => false,
                'message'    => 'Tenant account no longer exists.',
                'error_code' => 'TENANT_NOT_FOUND',
            ];
        }
        
        $tenantStatus     = (string) ($row['tenant_status'] ?? '');
        $evacuationStatus = $row['evacuation_status'] ?? '';
        
        // --- Evacuated: cannot reactivate ---
        if ($evacuationStatus === 'evacuated' || $tenantStatus === '3') {
            return [
                'eligible'   => false,
                'message'    => 'Cannot approve: this tenant has already evacuated. '
                              . 'The request has been automatically cancelled. '
                              . 'The tenant must go through onboarding again to rent.',
                'error_code' => 'EVACUATED_NOT_REACTIVATABLE',
            ];
        }
        
        // --- Suspended ---
        if ($tenantStatus === '2') {
            return [
                'eligible'   => false,
                'message'    => 'Cannot approve: this tenant is suspended. '
                              . 'Lift the suspension first before approving.',
                'error_code' => 'TENANT_SUSPENDED',
            ];
        }
        
        // --- Blocked / Inactive ---
        if ($tenantStatus === '0') {
            return [
                'eligible'   => false,
                'message'    => 'Cannot approve: this tenant is blocked/inactive. '
                              . 'Unblock the account first before approving.',
                'error_code' => 'TENANT_BLOCKED',
            ];
        }
        
        // --- Good state ---
        return ['eligible' => true];
    }
    
    // ================================================================
    // ===== NEW: Auto-cancel a stale request =====
    // ================================================================
    private function autoCancelStaleRequest($requestId, $reason, $errorCode)
    {
        $newStatus       = 'cancelled';  // or 'auto_rejected' if your enum supports it
        $cancellationMsg = "[AUTO-CANCELLED] {$reason}";
        
        $stmt = $this->conn->prepare("
            UPDATE account_reactivation_requests
            SET status = ?,
                reviewed_by = ?,
                review_notes = CONCAT(COALESCE(review_notes, ''), '\n', ?),
                review_timestamp = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ");
        
        if (!$stmt) {
            logActivity("[AUTO_CANCEL_PREPARE_ERROR] Failed to prepare auto-cancel");
            return;
        }
        
        $stmt->bind_param("siss", $newStatus, $this->adminId, $cancellationMsg, $requestId);
        $stmt->execute();
        $stmt->close();
        
        logActivity("[AUTO_CANCEL] Request {$requestId} cancelled: {$errorCode} — {$reason}");
    }
    
    private function activateUserAccount($userType, $userId, $email) {
        $tables = [
            'admin'  => ['table' => 'admin_tbl', 'id_column' => 'unique_id'],
            'agent'  => ['table' => 'agents',    'id_column' => 'agent_code'],
            'client' => ['table' => 'clients',   'id_column' => 'client_code'],
            'tenant' => ['table' => 'tenants',   'id_column' => 'tenant_code'],
        ];
        
        if (!isset($tables[$userType])) {
            throw new Exception("Invalid user type for activation: {$userType}");
        }
        
        $table    = $tables[$userType]['table'];
        $idColumn = $tables[$userType]['id_column'];
        
        // For tenants, be extra careful: only flip status if the tenant
        // is still in a reactivatable state. The recheckEligibility already
        // validated this, but we re-verify inside the UPDATE for atomicity.
        if ($userType === 'tenant') {
            $query = "UPDATE {$table}
                      SET status = '1'
                      WHERE {$idColumn} = ?
                        AND email = ?
                        AND status = 0
                        AND tenant_status = '1'
                        AND (evacuation_status IS NULL 
                             OR evacuation_status NOT IN ('evacuated'))";
        } else {
            $query = "UPDATE {$table}
                      SET status = '1'
                      WHERE {$idColumn} = ? AND email = ?";
        }
        
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
            // Either the account was already active, doesn't exist,
            // or its state changed between the recheck and now.
            throw new Exception("No account found to activate (or state changed)");
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
            'admin'  => 'admin_tbl',
            'agent'  => 'agents',
            'client' => 'clients',
            'tenant' => 'tenants',
        ];
        
        return $tables[$userType] ?? 'admin_tbl';
    }
    
    private function getIdColumn($userType) {
        $columns = [
            'admin'  => 'unique_id',
            'agent'  => 'agent_code',
            'client' => 'client_code',
            'tenant' => 'tenant_code',
        ];
        
        return $columns[$userType] ?? 'unique_id';
    }
    
    private function sendUserNotification($email, $status, $data) {
        logActivity("Would send {$status} notification to: {$email}");
        // sendEmailWithTemplate($email, $status, $data);
    }
    
    private function logAdminActivity($requestId, $action, $request) {
        $activityType = $action === 'approve' ? 'reactivation_approved' : 'reactivation_rejected';
        $description  = "{$action} reactivation request #{$requestId} for {$request['email']}";
        
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
    if (!isset($_SESSION['unique_id'])) {
        logActivity("Unauthorized review attempt | No session | IP: " . getClientIP());
        http_response_code(401);
        echo json_encode([
            "success" => false, 
            "message" => "Not logged in. Please login again.",
            "code"    => 401,
        ]);
        exit();
    }

    $adminId = $_SESSION['unique_id'];
    $loggedInUserRole = $_SESSION['role'] ?? 'Unknown';

    $allowedRoles = ['super admin'];
    if (!in_array(strtolower($loggedInUserRole), $allowedRoles)) {
        logActivity("Unauthorized role review attempt | Role: {$loggedInUserRole} | AdminID: {$adminId}");
        http_response_code(403);
        echo json_encode([
            "success" => false, 
            "message" => "You don't have permission to review requests.",
            "code"    => 403,
        ]);
        exit();
    }

    if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
        http_response_code(503);
        echo json_encode([
            "success" => false, 
            "message" => "Database connection error. Please try again later.",
            "code"    => 503,
        ]);
        exit();
    }

    $input = json_decode(file_get_contents("php://input"), true);
    
    if (empty($input['request_id']) || empty($input['action'])) {
        http_response_code(400);
        echo json_encode([
            "success" => false, 
            "message" => "Missing required fields: request_id and action",
            "code"    => 400,
        ]);
        exit();
    }

    $requestId       = (int) $input['request_id'];
    $action          = trim($input['action']);
    $notes           = trim($input['notes'] ?? '');
    $rejectionReason = trim($input['rejection_reason'] ?? '');

    if ($action === 'reject' && empty($rejectionReason)) {
        http_response_code(400);
        echo json_encode([
            "success" => false, 
            "message" => "Rejection reason is required when rejecting a request",
            "code"    => 400,
        ]);
        exit();
    }

    logActivity("Review attempt | Request ID: {$requestId} | Action: {$action} | AdminID: {$adminId}");

    $service = new ReactivationReviewService($conn, $adminId);
    $result  = $service->reviewRequest($requestId, $action, $notes, $rejectionReason);

    // Mirror the service's code in the HTTP response
    http_response_code($result['code'] ?? 200);
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
        "code"    => 500,
    ]);
    exit();
}