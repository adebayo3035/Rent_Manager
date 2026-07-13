<?php
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../../../tenant/backend/utilities/notification_helper.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
if (!isset($_SESSION))
    session_start();
rateLimiter();

header('Content-Type: application/json');

logActivity("========== RENT PAYMENT ADMIN API - START ==========");

try {
    // Authentication check
    if (!isset($_SESSION['unique_id'])) {
        logActivity("SECURITY ALERT: Unauthorized rent payment access attempt");
        echo json_encode(["success" => false, "message" => "Not logged in."]);
        exit();
    }

    $adminId = $_SESSION['unique_id'];
    $userRole = $_SESSION['role'] ?? 'Admin';
    logActivity("Authenticated user - ID: $adminId | Role: $userRole");

    $action = isset($_GET['action']) ? $_GET['action'] : 'fetch_pending';
    logActivity("Action: $action");

    switch ($action) {
        case 'fetch_pending':
            fetchPendingVerifications($conn);
            break;
        case 'fetch_history':
            fetchPaymentHistory($conn);
            break;
        case 'verify':
            verifyPayment($conn, $adminId);
            break;
        case 'get_tracker_summary':
            getTrackerSummary($conn);
            break;
        case 'get_tenant_periods':
            getTenantPeriods($conn);
            break;
        case 'get_statistics':
            getRentStatistics($conn);
            break;
        default:
            echo json_encode(["success" => false, "message" => "Invalid action."]);
    }

} catch (Exception $e) {
    logActivity("CRITICAL ERROR: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "An error occurred: " . $e->getMessage()]);
}

logActivity("========== RENT PAYMENT ADMIN API - END ==========");

// ==================== FUNCTIONS ====================

function fetchPendingVerifications($conn)
{
    logActivity("Fetching pending verifications");

    $query = "
        SELECT 
            t.tracker_id,
            t.rent_payment_id,
            t.period_number,
            t.start_date,
            t.end_date,
            t.amount_paid,
            t.payment_date,
            t.payment_method,
            t.payment_reference,
            t.status,
            t.created_at,
            t.payment_id as payments_table_id,
            ten.tenant_code,
            ten.firstname,
            ten.lastname,
            ten.email,
            ten.phone,
            a.apartment_number,
            a.apartment_code,
            p.name as property_name,
            p.property_code,
            rp.amount as annual_rent,
            rp.amount_paid as total_paid,
            rp.balance as remaining_balance,
            rp.receipt_number
        FROM rent_payment_tracker t
        JOIN tenants ten ON t.tenant_code COLLATE utf8mb4_unicode_ci = ten.tenant_code COLLATE utf8mb4_unicode_ci
        JOIN apartments a ON t.apartment_code COLLATE utf8mb4_unicode_ci = a.apartment_code COLLATE utf8mb4_unicode_ci
        JOIN properties p ON a.property_code COLLATE utf8mb4_unicode_ci = p.property_code COLLATE utf8mb4_unicode_ci
        JOIN rent_payments rp ON t.rent_payment_id COLLATE utf8mb4_unicode_ci = rp.rent_payment_id COLLATE utf8mb4_unicode_ci
        WHERE t.status = 'pending_verification'
        ORDER BY t.created_at ASC
    ";

    $result = $conn->query($query);

    if (!$result) {
        logActivity("Query error: " . $conn->error);
        echo json_encode(["success" => false, "message" => "Database error: " . $conn->error]);
        return;
    }

    $payments = [];
    while ($row = $result->fetch_assoc()) {
        $row['tenant_name'] = $row['firstname'] . ' ' . $row['lastname'];
        $row['period_display'] = formatPeriodDisplay($row['start_date'], $row['end_date']);
        $row['amount_formatted'] = '₦' . number_format($row['amount_paid'], 2);
        $row['payment_date_formatted'] = $row['payment_date'] ? date('M d, Y H:i', strtotime($row['payment_date'])) : 'N/A';
        $row['created_at_formatted'] = date('M d, Y H:i', strtotime($row['created_at']));
        $receipt_number = $row['receipt_number'];
        $payments[] = $row;
    }

    echo json_encode([
        "success" => true,
        "pending_count" => count($payments),
        "payments" => $payments
    ]);
}

function fetchPaymentHistory($conn)
{
    logActivity("Fetching rent payment history");

    $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
    $offset = ($page - 1) * $limit;
    $status = isset($_GET['status']) ? $_GET['status'] : null;
    $search = isset($_GET['search']) ? trim($_GET['search']) : null;

    $whereClauses = ["t.status IN ('paid', 'failed')"];
    $params = [];
    $types = '';

    if ($status && in_array($status, ['paid', 'failed'])) {
        $whereClauses[] = "t.status = ?";
        $params[] = $status;
        $types .= 's';
    }

    if ($search) {
        $whereClauses[] = "(ten.firstname LIKE ? OR ten.lastname LIKE ? OR ten.email LIKE ? OR ten.tenant_code LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $types .= 'ssss';
    }

    $whereSQL = "WHERE " . implode(" AND ", $whereClauses);

    // Count query
    $countQuery = "
        SELECT COUNT(*) as total
        FROM rent_payment_tracker t
        JOIN tenants ten ON t.tenant_code COLLATE utf8mb4_unicode_ci = ten.tenant_code COLLATE utf8mb4_unicode_ci
        $whereSQL
    ";

    $countStmt = $conn->prepare($countQuery);
    if (!$countStmt) {
        logActivity("Count prepare error: " . $conn->error);
        echo json_encode(["success" => false, "message" => "Database error: " . $conn->error]);
        return;
    }

    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    // Data query
    $query = "
        SELECT 
            t.tracker_id,
            t.rent_payment_id,
            t.period_number,
            t.start_date,
            t.end_date,
            t.amount_paid,
            t.payment_date,
            t.payment_method,
            t.payment_reference,
            t.status,
            t.verified_by,
            t.verified_at,
            t.admin_notes,
            ten.tenant_code,
            ten.firstname,
            ten.lastname,
            ten.email,
            ten.phone,
            a.apartment_number,
            a.apartment_code,
            p.name as property_name,
            rp.amount as annual_rent,
            rp.balance as remaining_balance
        FROM rent_payment_tracker t
        JOIN tenants ten ON t.tenant_code COLLATE utf8mb4_unicode_ci = ten.tenant_code COLLATE utf8mb4_unicode_ci
        JOIN apartments a ON t.apartment_code COLLATE utf8mb4_unicode_ci = a.apartment_code COLLATE utf8mb4_unicode_ci
        JOIN properties p ON a.property_code COLLATE utf8mb4_unicode_ci = p.property_code COLLATE utf8mb4_unicode_ci
        JOIN rent_payments rp ON t.rent_payment_id COLLATE utf8mb4_unicode_ci = rp.rent_payment_id COLLATE utf8mb4_unicode_ci
        $whereSQL
        ORDER BY t.payment_date DESC, t.created_at DESC
        LIMIT ? OFFSET ?
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logActivity("Query prepare error: " . $conn->error);
        echo json_encode(["success" => false, "message" => "Database error: " . $conn->error]);
        return;
    }

    $paramsWithPagination = $params;
    $paramsWithPagination[] = $limit;
    $paramsWithPagination[] = $offset;
    $stmtTypes = $types . 'ii';

    if (!empty($paramsWithPagination)) {
        $stmt->bind_param($stmtTypes, ...$paramsWithPagination);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $payments = [];
    while ($row = $result->fetch_assoc()) {
        $row['tenant_name'] = $row['firstname'] . ' ' . $row['lastname'];
        $row['period_display'] = formatPeriodDisplay($row['start_date'], $row['end_date']);
        $row['amount_formatted'] = '₦' . number_format($row['amount_paid'], 2);
        $row['payment_date_formatted'] = $row['payment_date'] ? date('M d, Y', strtotime($row['payment_date'])) : 'N/A';
        $row['verified_at_formatted'] = $row['verified_at'] ? date('M d, Y H:i', strtotime($row['verified_at'])) : 'N/A';
        $row['status_badge'] = $row['status'] === 'paid' ? 'success' : 'danger';
        $row['status_text'] = $row['status'] === 'paid' ? 'Paid' : 'Failed';
        $payments[] = $row;
    }
    $stmt->close();

    echo json_encode([
        "success" => true,
        "payments" => $payments,
        "pagination" => [
            "total" => $total,
            "page" => $page,
            "limit" => $limit,
            "total_pages" => ceil($total / $limit)
        ]
    ]);
}
/**
 * Update rent payment attempt record (when admin verifies/approves/rejects)
 * 
 * @param mysqli $conn Database connection
 * @param int $tracker_id The rent_payment_tracker ID
 * @param string $status New status (paid/failed)
 * @param int $verified_by Admin ID who verified
 * @param string $verification_notes Admin notes
 * @param string $failure_reason Optional failure reason
 * @return bool Success status
 */
function updateRentPaymentAttempt($conn, $tracker_id, $status, $verified_by, $verification_notes = null, $failure_reason = null)
{
    // Find the latest pending attempt for this tracker
    $find_query = "SELECT id FROM rent_payment_history WHERE tracker_id = ? AND status IN ('initiated', 'pending_verification') ORDER BY id DESC LIMIT 1";
    $find_stmt = $conn->prepare($find_query);
    $find_stmt->bind_param("i", $tracker_id);
    $find_stmt->execute();
    $result = $find_stmt->get_result();

    if ($result->num_rows === 0) {
        logActivity("No pending attempt found for tracker_id: {$tracker_id}");
        return false;
    }

    $history = $result->fetch_assoc();
    $find_stmt->close();

    $query = "UPDATE rent_payment_history 
              SET status = ?, verified_by = ?, verified_at = NOW(), verification_notes = ?, failure_reason = ?
              WHERE id = ?";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("sissi", $status, $verified_by, $verification_notes, $failure_reason, $history['id']);

    $success = $stmt->execute();
    $stmt->close();

    if ($success) {
        logActivity("Rent payment attempt updated - History ID: {$history['id']}, New Status: {$status}");
    }

    return $success;
}
function verifyPayment($conn, $adminId)
{
    logActivity("Starting verifyPayment() - Admin ID: $adminId");

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $trackerId = isset($input['tracker_id']) ? (int) $input['tracker_id'] : 0;
    $action = isset($input['action']) ? trim($input['action']) : '';
    $notes = isset($input['notes']) ? trim($input['notes']) : '';

    logActivity("VerifyPayment input: " . json_encode([
        'tracker_id' => $trackerId,
        'action' => $action,
        'admin_id' => $adminId
    ]));

    if (!$trackerId || !in_array($action, ['approve', 'reject'])) {
        logActivity("Validation failed: Invalid tracker_id or action");
        json_error("Tracker ID and valid action (approve/reject) are required.", 400);
    }

    $conn->begin_transaction();

    try {
        // Get tracker details
        $trackerQuery = "
            SELECT 
                t.tracker_id,
                t.rent_payment_id,
                t.period_number,
                t.start_date,
                t.end_date,
                t.amount_paid,
                t.payment_date,
                t.status,
                t.payment_id AS tracker_payment_id,
                r.payment_id AS rent_payment_int_id,
                r.rent_payment_id AS rent_payment_code,
                r.balance as rent_payment_balance,
                r.amount as annual_rent,
                r.receipt_number,
                ten.id AS tenant_id,
                ten.tenant_code,
                ten.lease_end_date,
                ten.temp_lease_end_date,
                ten.property_code
            FROM rent_payment_tracker t
            INNER JOIN rent_payments r ON t.rent_payment_id = r.rent_payment_id
            INNER JOIN tenants ten ON t.tenant_code = ten.tenant_code
            WHERE t.tracker_id = ?
        ";

        logActivity("Fetching tracker data for ID: $trackerId");

        $trackerStmt = $conn->prepare($trackerQuery);
        if (!$trackerStmt) {
            logActivity("Failed to prepare tracker query: " . $conn->error);
            throw new Exception("Database error occurred");
        }

        $trackerStmt->bind_param("i", $trackerId);
        $trackerStmt->execute();
        $tracker = $trackerStmt->get_result()->fetch_assoc();
        $trackerStmt->close();

        if (!$tracker) {
            logActivity("Tracker record not found for ID: $trackerId");
            throw new Exception("Payment record not found");
        }

        logActivity("Tracker data retrieved: " . json_encode([
            'tracker_id' => $trackerId,
            'rent_payment_id' => $tracker['rent_payment_id'],
            'period_number' => $tracker['period_number'],
            'status' => $tracker['status'],
            'amount_paid' => $tracker['amount_paid']
        ]));

        if ($tracker['status'] !== 'pending_verification') {
            logActivity("Invalid status: {$tracker['status']}, expected: pending_verification");
            throw new Exception("Payment is not pending verification. Current status: " . $tracker['status']);
        }

        $periodAmount = (float) $tracker['amount_paid'];
        $periodEndDate = $tracker['end_date'];
        $receipt_number = $tracker['receipt_number'];
        $newStatus = ($action === 'approve') ? 'paid' : 'failed';
        $actionLabel = strtoupper($action);

        logActivity("Processing payment - Period: {$tracker['period_number']}, Action: $action, Amount: $periodAmount");

        // Update tracker record
        $updateTrackerQuery = "
            UPDATE rent_payment_tracker 
            SET status = ?,
                verified_by = ?,
                verified_at = NOW(),
                admin_notes = CONCAT(IFNULL(admin_notes, ''), '\n[', ?, '] ', NOW(), ' by Admin ID: ', ?, '\nNotes: ', ?),
                notes = ?
            WHERE tracker_id = ?
        ";

        $updateStmt = $conn->prepare($updateTrackerQuery);
        if (!$updateStmt) {
            logActivity("Failed to prepare tracker update: " . $conn->error);
            throw new Exception("Database error occurred");
        }

        $updateStmt->bind_param("sisissi", $newStatus, $adminId, $actionLabel, $adminId, $notes, $notes, $trackerId);
        $updateStmt->execute();
        $updateStmt->close();

        logActivity("Tracker updated to status: $newStatus");

        if ($action === 'approve') {
            // Update rent_payments balance
            $newRentBalance = $tracker['rent_payment_balance'] - $periodAmount;
            $updateRentQuery = "
                UPDATE rent_payments 
                SET amount_paid = amount_paid + ?,
                    balance = ?,
                    updated_at = NOW()
                WHERE rent_payment_id = ?
            ";

            $updateRentStmt = $conn->prepare($updateRentQuery);
            if (!$updateRentStmt) {
                logActivity("Failed to prepare rent payment update: " . $conn->error);
                throw new Exception("Database error occurred");
            }

            $updateRentStmt->bind_param("dds", $periodAmount, $newRentBalance, $tracker['rent_payment_id']);
            $updateRentStmt->execute();
            $updateRentStmt->close();

            logActivity("Rent balance updated - New balance: $newRentBalance");

            // Update tenant's rent_balance and temp_lease_end_date
            $updateTenantQuery = "
                UPDATE tenants 
                SET rent_balance = rent_balance - ?,
                    temp_lease_end_date = ?,
                    last_updated_at = NOW()
                WHERE tenant_code = ?
            ";

            $updateTenantStmt = $conn->prepare($updateTenantQuery);
            if (!$updateTenantStmt) {
                logActivity("Failed to prepare tenant update: " . $conn->error);
                throw new Exception("Database error occurred");
            }

            $updateTenantStmt->bind_param("dss", $periodAmount, $periodEndDate, $tracker['tenant_code']);
            $updateTenantStmt->execute();
            $updateTenantStmt->close();

            logActivity("Tenant balance updated - Period: {$tracker['period_number']}");

            // ==================== SETTLEMENT PROCESSING ====================
            logActivity("Starting settlement processing for tracker: $trackerId");

            try {
                // Get property details and settlement formula
                $propertyQuery = "
                    SELECT 
                        p.id AS property_id,
                        p.property_code,
                        p.client_code,
                        NULLIF(p.agent_code, '') AS agent_code,
                        COALESCE(s.admin_percentage, 10.00) AS admin_percentage,
                        COALESCE(s.agent_percentage, 5.00) AS agent_percentage,
                        COALESCE(s.client_percentage, 85.00) AS client_percentage
                    FROM properties p
                    LEFT JOIN property_settlement s ON p.id = s.property_id
                    WHERE p.property_code = ? AND p.status = '1'
                    LIMIT 1
                ";

                $propertyStmt = $conn->prepare($propertyQuery);
                if (!$propertyStmt) {
                    logActivity("Failed to prepare property query: " . $conn->error);
                    throw new Exception("Database error occurred");
                }

                $propertyStmt->bind_param("s", $tracker['property_code']);
                $propertyStmt->execute();
                $property = $propertyStmt->get_result()->fetch_assoc();
                $propertyStmt->close();

                if (!$property) {
                    logActivity("Property not found: {$tracker['property_code']}");
                    throw new Exception("Property not found for settlement");
                }

                logActivity("Property found: " . json_encode([
                    'property_id' => $property['property_id'],
                    'property_code' => $property['property_code'],
                    'admin_pct' => $property['admin_percentage'],
                    'agent_pct' => $property['agent_percentage'],
                    'client_pct' => $property['client_percentage']
                ]));

                // Calculate shares
                $adminShare = round($periodAmount * ($property['admin_percentage'] / 100), 2);
                $agentShare = round($periodAmount * ($property['agent_percentage'] / 100), 2);
                $clientShare = round($periodAmount * ($property['client_percentage'] / 100), 2);

                // Handle rounding differences
                $totalShares = $adminShare + $agentShare + $clientShare;
                if (abs($totalShares - $periodAmount) > 0.01) {
                    $difference = round($periodAmount - $totalShares, 2);
                    $clientShare += $difference;
                    logActivity("Rounding adjustment: $difference added to client share");
                }

                logActivity("Shares calculated: " . json_encode([
                    'admin' => $adminShare,
                    'agent' => $agentShare,
                    'client' => $clientShare
                ]));

                // Get the correct payment ID from rent_payments
                $paymentId = 0;

                if (isset($tracker['rent_payment_int_id']) && $tracker['rent_payment_int_id'] > 0) {
                    $paymentId = (int) $tracker['rent_payment_int_id'];
                    logActivity("Using payment_id from rent_payments: $paymentId");
                } else {
                    // Fallback query
                    $paymentIdQuery = "SELECT payment_id FROM rent_payments WHERE rent_payment_id = ? LIMIT 1";
                    $paymentIdStmt = $conn->prepare($paymentIdQuery);
                    if ($paymentIdStmt) {
                        $paymentIdStmt->bind_param("s", $tracker['rent_payment_id']);
                        $paymentIdStmt->execute();
                        $paymentIdResult = $paymentIdStmt->get_result();
                        $paymentIdRow = $paymentIdResult->fetch_assoc();
                        $paymentIdStmt->close();

                        if ($paymentIdRow) {
                            $paymentId = (int) $paymentIdRow['payment_id'];
                            logActivity("Retrieved payment_id from fallback: $paymentId");
                        }
                    }
                }

                if ($paymentId <= 0) {
                    logActivity("Invalid payment_id for tracker: $trackerId");
                    throw new Exception("Unable to find valid payment record");
                }

                // ==================== INSERT SETTLEMENT TRANSACTION ====================

                $settlementStatus = 'completed';
                $rentPaymentDate = $tracker['payment_date'] ?: date('Y-m-d H:i:s');
                $settlementNotes = "Auto-created from tracker #{$trackerId} (Period {$tracker['period_number']})";
                $tenantId = (int) $tracker['tenant_id'];
                $propertyId = (int) $property['property_id'];
                $agentCode = $property['agent_code'] ?: null;
                $processedBy = (string) $adminId;
                $rentPaymentId = $tracker['rent_payment_id'];

                // Agent is only "paid" if an agent actually exists on this property
                $agentPaid = !empty($agentCode) ? 1 : 0;

                $settlementQuery = "
    INSERT INTO settlement_transactions 
    (
        payment_id, tracker_id, rent_payment_id, property_id, tenant_id,
        agent_code, client_code, total_rent_amount, admin_share, agent_share,
        client_share, admin_percentage_used, agent_percentage_used, client_percentage_used,
        settlement_status, rent_payment_date, settlement_date, processed_by, notes,
        admin_paid, admin_payment_date,
        agent_paid, agent_payment_date,
        client_paid, client_payment_date,
        created_at, updated_at
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?,
            1, NOW(),
            ?, NOW(),
            1, NOW(),
            NOW(), NOW())
";

                $settlementStmt = $conn->prepare($settlementQuery);
                if (!$settlementStmt) {
                    logActivity("Failed to prepare settlement insert: " . $conn->error);
                    throw new Exception("Database error occurred");
                }

                // 19 placeholders -> 19 variables
// Type string: i i s i i s s d d d d d d d s s s s i
// Without spaces: iisiissdddddddssssi (20 characters)
                $settlementStmt->bind_param(
                    "iisiissdddddddssssi",
                    $paymentId,
                    $trackerId,
                    $rentPaymentId,
                    $propertyId,
                    $tenantId,
                    $agentCode,
                    $property['client_code'],
                    $periodAmount,
                    $adminShare,
                    $agentShare,
                    $clientShare,
                    $property['admin_percentage'],
                    $property['agent_percentage'],
                    $property['client_percentage'],
                    $settlementStatus,
                    $rentPaymentDate,
                    $processedBy,
                    $settlementNotes,
                    $agentPaid
                );

                if (!$settlementStmt->execute()) {
                    logActivity("Settlement insert failed: " . $settlementStmt->error);
                    throw new Exception("Failed to create settlement record");
                }

                $settlementId = $settlementStmt->insert_id;
                $settlementStmt->close();

                logActivity("Settlement created - ID: $settlementId, Tracker: $trackerId");

                // ==================== UPDATE BALANCES ====================

                // Update admin balance
                if ($adminShare > 0) {
                    $updateAdminQuery = "
                        UPDATE admin_tbl 
                        SET settlement_balance = COALESCE(settlement_balance, 0) + ?,
                            total_settlement_earned = COALESCE(total_settlement_earned, 0) + ?,
                            last_settlement_date = NOW()
                        WHERE unique_id = ?
                    ";
                    $updateAdminStmt = $conn->prepare($updateAdminQuery);
                    if ($updateAdminStmt) {
                        $updateAdminStmt->bind_param("dds", $adminShare, $adminShare, $adminId);
                        $updateAdminStmt->execute();
                        $updateAdminStmt->close();
                        logActivity("Admin balance updated: +$adminShare");
                    } else {
                        logActivity("Warning: Could not update admin balance");
                    }
                }

                // Update client balance
                if ($clientShare > 0) {
                    $updateClientQuery = "
                        UPDATE clients 
                        SET settlement_balance = COALESCE(settlement_balance, 0) + ?,
                            total_settlement_earned = COALESCE(total_settlement_earned, 0) + ?,
                            last_settlement_date = NOW()
                        WHERE client_code = ?
                    ";
                    $updateClientStmt = $conn->prepare($updateClientQuery);
                    if ($updateClientStmt) {
                        $updateClientStmt->bind_param("dds", $clientShare, $clientShare, $property['client_code']);
                        $updateClientStmt->execute();
                        $updateClientStmt->close();
                        logActivity("Client balance updated: +$clientShare");
                    } else {
                        logActivity("Warning: Could not update client balance");
                    }
                }

                // Update agent balance (if agent exists)
                if (!empty($property['agent_code']) && $agentShare > 0) {
                    $updateAgentQuery = "
                        UPDATE agents 
                        SET settlement_balance = COALESCE(settlement_balance, 0) + ?,
                            total_settlement_earned = COALESCE(total_settlement_earned, 0) + ?,
                            last_settlement_date = NOW()
                        WHERE agent_code = ?
                    ";
                    $updateAgentStmt = $conn->prepare($updateAgentQuery);
                    if ($updateAgentStmt) {
                        $updateAgentStmt->bind_param("dds", $agentShare, $agentShare, $property['agent_code']);
                        $updateAgentStmt->execute();
                        $updateAgentStmt->close();
                        logActivity("Agent balance updated: +$agentShare");
                    } else {
                        logActivity("Warning: Could not update agent balance");
                    }
                }

                logActivity("Settlement completed - ID: $settlementId");

            } catch (Exception $settlementError) {
                logActivity("SETTLEMENT ERROR: " . $settlementError->getMessage());
                throw new Exception("Payment approval failed: " . $settlementError->getMessage());
            }
            // ==================== END SETTLEMENT PROCESSING ====================

            // Check if all periods are paid
            $remainingQuery = "
                SELECT COUNT(*) as remaining_count 
                FROM rent_payment_tracker 
                WHERE rent_payment_id = ? AND status != 'paid'
            ";
            $remainingStmt = $conn->prepare($remainingQuery);
            if ($remainingStmt) {
                $remainingStmt->bind_param("s", $tracker['rent_payment_id']);
                $remainingStmt->execute();
                $remaining = $remainingStmt->get_result()->fetch_assoc();
                $remainingStmt->close();

                if ($remaining && $remaining['remaining_count'] == 0) {
                    $completeQuery = "
                        UPDATE rent_payments 
                        SET status = 'completed', updated_at = NOW()
                        WHERE rent_payment_id = ?
                    ";
                    $completeStmt = $conn->prepare($completeQuery);
                    if ($completeStmt) {
                        $completeStmt->bind_param("s", $tracker['rent_payment_id']);
                        $completeStmt->execute();
                        $completeStmt->close();
                        logActivity("All periods completed for: {$tracker['rent_payment_id']}");
                    }
                }
            }

            // Update rent payment history
            if (!updateRentPaymentAttempt($conn, $trackerId, 'paid', $adminId, $notes)) {
                logActivity("Warning: Failed to update rent payment history");
            }

        } else if ($action === "reject") {
            if (!updateRentPaymentAttempt($conn, $trackerId, 'failed', $adminId, $notes, $notes)) {
                logActivity("Warning: Failed to update rent payment history for rejected payment");
            }
        }

        $conn->commit();

        logActivity("verifyPayment completed - Tracker: $trackerId, Action: $action");

        $successMessage = $action === 'approve'
            ? "Payment for Period #{$tracker['period_number']} has been approved and marked as paid. Settlement processed."
            : "Payment for Period #{$tracker['period_number']} has been rejected and marked as failed.";

        echo json_encode([
            "success" => true,
            "message" => $successMessage,
            "tracker_id" => $trackerId,
            "period_number" => $tracker['period_number'],
            "new_status" => $newStatus,
            "settlement_processed" => ($action === 'approve') ? true : false
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        $errorMessage = $e->getMessage();

        logActivity("ERROR in verifyPayment: $errorMessage");

        // User-friendly error messages
        $userMessage = $errorMessage;
        if (strpos($errorMessage, 'Database error') !== false) {
            $userMessage = "An error occurred. Please try again.";
        } elseif (strpos($errorMessage, 'Property not found') !== false) {
            $userMessage = "Property configuration not found. Please contact support.";
        } elseif (strpos($errorMessage, 'Payment approval failed') !== false) {
            $userMessage = "Payment approval failed. Please contact support.";
        }

        echo json_encode([
            "success" => false,
            "message" => $userMessage,
            "tracker_id" => $trackerId,
            "period_number" => $tracker['period_number'] ?? null
        ]);
    }
}
// ==================== HELPER FUNCTIONS FOR SETTLEMENT ====================

/**
 * Create settlement notification
 */
function createSettlementNotification($conn, $recipientId, $recipientType, $message, $settlementId)
{
    try {
        $title = "Settlement Processed";
        $details = json_encode([
            'settlement_id' => $settlementId,
            'recipient_type' => $recipientType
        ]);

        if ($recipientType === 'client') {
            $insertQuery = "
                INSERT INTO client_notifications
                (client_code, notification_type, title, message, details, priority, action_url, action_text, created_at)
                VALUES (?, 'settlement', ?, ?, ?, 'medium', '../pages/settlement.php', 'View Settlement', NOW())
            ";

            $stmt = $conn->prepare($insertQuery);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmt->bind_param("ssss", $recipientId, $title, $message, $details);
            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }
            $stmt->close();

            logActivity("Settlement notification created for client: {$recipientId}");
            return true;
        }

        if ($recipientType === 'agent') {
            $agentStmt = $conn->prepare("SELECT agent_id FROM agents WHERE agent_code = ? LIMIT 1");
            if (!$agentStmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $agentStmt->bind_param("s", $recipientId);
            $agentStmt->execute();
            $agent = $agentStmt->get_result()->fetch_assoc();
            $agentStmt->close();

            if (!$agent) {
                logActivity("Settlement notification skipped: agent not found for code {$recipientId}");
                return false;
            }

            $recipientId = (int) $agent['agent_id'];
        }

        if ($recipientType === 'admin' || $recipientType === 'agent') {
            $userId = (int) $recipientId;
            $type = 'success';
            $category = 'payment_confirmation';

            $insertQuery = "
                INSERT INTO notifications
                (user_id, assigned_to, title, message, type, category, is_read, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 0, NOW())
            ";

            $stmt = $conn->prepare($insertQuery);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmt->bind_param("iissss", $userId, $userId, $title, $message, $type, $category);
            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }
            $stmt->close();

            logActivity("Settlement notification created for {$recipientType}: {$userId}");
            return true;
        }

        logActivity("Settlement notification skipped: unsupported recipient type {$recipientType}");
        return false;
    } catch (Throwable $e) {
        logActivity("Failed to create settlement notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Update agent balance (optional - implement if you have agent_balances table)
 */
function updateAgentBalance($conn, $agentCode, $amount)
{
    // Check if agent_balances table exists
    $checkQuery = "SHOW TABLES LIKE 'agent_balances'";
    $checkResult = $conn->query($checkQuery);

    if ($checkResult->num_rows > 0) {
        $updateQuery = "
            INSERT INTO agent_balances (agent_code, balance, updated_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                balance = balance + ?,
                updated_at = NOW()
        ";
        $stmt = $conn->prepare($updateQuery);
        $stmt->bind_param("sdd", $agentCode, $amount, $amount);
        $stmt->execute();
        $stmt->close();

        logActivity("Agent balance updated for {$agentCode}: +{$amount}");
    } else {
        logActivity("agent_balances table not found - skipping agent balance update");
    }
}

/**
 * Update client balance (optional - implement if you have client_balances table)
 */
function updateClientBalance($conn, $clientCode, $amount)
{
    // Check if client_balances table exists
    $checkQuery = "SHOW TABLES LIKE 'client_balances'";
    $checkResult = $conn->query($checkQuery);

    if ($checkResult->num_rows > 0) {
        $updateQuery = "
            INSERT INTO client_balances (client_code, balance, updated_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                balance = balance + ?,
                updated_at = NOW()
        ";
        $stmt = $conn->prepare($updateQuery);
        $stmt->bind_param("sdd", $clientCode, $amount, $amount);
        $stmt->execute();
        $stmt->close();

        logActivity("Client balance updated for {$clientCode}: +{$amount}");
    } else {
        logActivity("client_balances table not found - skipping client balance update");
    }
}
function getTrackerSummary($conn)
{
    logActivity("Getting tracker summary");

    $query = "
        SELECT 
            COUNT(CASE WHEN status = 'pending_verification' THEN 1 END) as pending_count,
            COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid_count,
            COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_count,
            COUNT(CASE WHEN status = 'available' THEN 1 END) as available_count,
            SUM(CASE WHEN status = 'paid' THEN amount_paid ELSE 0 END) as total_paid_amount,
            SUM(CASE WHEN status = 'pending_verification' THEN amount_paid ELSE 0 END) as pending_amount,
            COUNT(DISTINCT tenant_code) as active_tenants
        FROM rent_payment_tracker
    ";

    $result = $conn->query($query);
    $summary = $result->fetch_assoc();

    echo json_encode([
        "success" => true,
        "summary" => $summary
    ]);
}

function getTenantPeriods($conn)
{
    $tenantCode = isset($_GET['tenant_code']) ? $_GET['tenant_code'] : null;

    if (!$tenantCode) {
        echo json_encode(["success" => false, "message" => "Tenant code required"]);
        return;
    }

    $query = "
        SELECT 
            tracker_id,
            period_number,
            start_date,
            end_date,
            amount_paid,
            status,
            payment_date,
            verified_at,
            admin_notes
        FROM rent_payment_tracker
        WHERE tenant_code = ?
        ORDER BY period_number ASC
    ";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $tenantCode);
    $stmt->execute();
    $result = $stmt->get_result();

    $periods = [];
    while ($row = $result->fetch_assoc()) {
        $row['period_display'] = formatPeriodDisplay($row['start_date'], $row['end_date']);
        $row['amount_formatted'] = '₦' . number_format($row['amount_paid'], 2);
        $row['status_badge'] = getStatusBadgeClass($row['status']);
        $periods[] = $row;
    }
    $stmt->close();

    echo json_encode([
        "success" => true,
        "periods" => $periods
    ]);
}

function getRentStatistics($conn)
{
    // Simplified query without COLLATE issues
    $query = "
        SELECT 
            COUNT(DISTINCT rp.rent_payment_id) as active_leases,
            COALESCE(SUM(rp.balance), 0) as total_outstanding,
            COALESCE(SUM(rp.amount_paid), 0) as total_collected,
            (SELECT COUNT(*) FROM rent_payment_tracker WHERE status = 'pending_verification') as pending_verifications,
            (SELECT COUNT(*) FROM rent_payment_tracker WHERE status = 'failed') as failed_payments
        FROM rent_payments rp
        WHERE rp.payment_type = 'rent'
    ";

    $result = $conn->query($query);

    if (!$result) {
        logActivity("Statistics query error: " . $conn->error);
        echo json_encode([
            "success" => true,
            "statistics" => [
                "summary" => [
                    "active_leases" => 0,
                    "total_outstanding" => 0,
                    "total_collected" => 0,
                    "pending_verifications" => 0,
                    "failed_payments" => 0
                ],
                "monthly_trend" => []
            ]
        ]);
        return;
    }

    $stats = $result->fetch_assoc();

    // Monthly collection trend
    $trendQuery = "
        SELECT 
            DATE_FORMAT(payment_date, '%Y-%m') as month,
            DATE_FORMAT(payment_date, '%b %Y') as month_name,
            COALESCE(SUM(amount_paid), 0) as total_collected,
            COUNT(*) as payments_count
        FROM rent_payment_tracker
        WHERE status = 'paid' 
        AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(payment_date, '%Y-%m'), DATE_FORMAT(payment_date, '%b %Y')
        ORDER BY month ASC
    ";

    $trendResult = $conn->query($trendQuery);
    $trend = [];
    if ($trendResult) {
        while ($row = $trendResult->fetch_assoc()) {
            $trend[] = $row;
        }
    }

    echo json_encode([
        "success" => true,
        "statistics" => [
            "summary" => [
                "active_leases" => (int) ($stats['active_leases'] ?? 0),
                "total_outstanding" => (float) ($stats['total_outstanding'] ?? 0),
                "total_collected" => (float) ($stats['total_collected'] ?? 0),
                "pending_verifications" => (int) ($stats['pending_verifications'] ?? 0),
                "failed_payments" => (int) ($stats['failed_payments'] ?? 0)
            ],
            "monthly_trend" => $trend
        ]
    ]);
}
// Helper functions
function formatPeriodDisplay($start_date, $end_date)
{
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    return $start->format('M j, Y') . ' - ' . $end->format('M j, Y');
}

function getStatusBadgeClass($status)
{
    switch ($status) {
        case 'paid':
            return 'success';
        case 'pending_verification':
            return 'warning';
        case 'failed':
            return 'danger';
        case 'available':
            return 'info';
        default:
            return 'secondary';
    }
}
?>