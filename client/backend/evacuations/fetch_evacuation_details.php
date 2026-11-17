<?php
// client/backend/evacuations/fetch_evacuation_details.php
// Full evacuation detail scoped to the logged-in client.
//
// Uses a three-tier settlement lookup because
// `evacuation_requests.settlement_transaction_id` stores an
// evacuation-settlement transaction code (e.g. "TXN-EVSET-..."),
// NOT a rent_payment_id. The reliable join is via tenant_id.

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';

if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

$requestStartTime = microtime(true);

try {
    // ================================================================
    // AUTH
    // ================================================================
    if (!isset($_SESSION['client_code'])) {
        logActivity("[EVAC_DETAIL] Unauthorized - no client session", 'WARN');
        json_error("Not logged in", 401);
    }
    $clientCode = $_SESSION['client_code'];

    $requestId  = isset($_GET['request_id'])  ? trim($_GET['request_id'])  : '';
    $tenantCode = isset($_GET['tenant_code']) ? trim($_GET['tenant_code']) : '';

    logActivity("[EVAC_DETAIL] ========== START ==========");
    logActivity("[EVAC_DETAIL] Client: {$clientCode} | request_id: "
        . ($requestId ?: '(none)') . " | tenant_code: " . ($tenantCode ?: '(none)'));

    if ($requestId === '' && $tenantCode === '') {
        logActivity("[EVAC_DETAIL] Validation failed - no request_id or tenant_code", 'WARN');
        json_error("Either request_id or tenant_code is required", 400);
    }

    // ================================================================
    // LOAD EVACUATION REQUEST (scoped to client)
    // ================================================================
    $where  = ["p.client_code = ?", "er.request_type = 'evacuation'"];
    $params = [$clientCode];
    $types  = 's';

    if ($requestId !== '') {
        $where[] = "er.request_id = ?";
        $params[] = $requestId;
        $types .= 's';
    } else {
        $where[] = "er.tenant_code = ?";
        $params[] = $tenantCode;
        $types .= 's';
    }

    $whereSQL = "WHERE " . implode(" AND ", $where);
    logActivity("[EVAC_DETAIL] Query WHERE: {$whereSQL} | params: " . json_encode($params));

    $stmt = $conn->prepare("
        SELECT
            er.*,
            t.id AS tenant_id,
            t.firstname,
            t.lastname,
            t.email,
            t.phone,
            t.photo,
            t.gender,
            t.lease_start_date,
            t.lease_end_date,
            t.payment_frequency,
            a.apartment_number,
            a.apartment_code AS apt_code,
            a.security_deposit,
            a.rent_amount AS apartment_rent,
            p.name AS property_name,
            p.property_code,
            CONCAT(adm.firstname, ' ', adm.lastname) AS reviewed_by_name,
            CONCAT(prc.firstname, ' ', prc.lastname)  AS processed_by_name
        FROM evacuation_requests er
        INNER JOIN tenants t    ON er.tenant_code    = t.tenant_code
        INNER JOIN apartments a ON er.apartment_code = a.apartment_code
        INNER JOIN properties p ON a.property_code   = p.property_code
        LEFT  JOIN admin_tbl adm ON er.reviewed_by   = adm.unique_id
        LEFT  JOIN admin_tbl prc ON er.processed_by  = prc.unique_id
        {$whereSQL}
        ORDER BY er.processed_at DESC, er.created_at DESC
        LIMIT 1
    ");
    if (!$stmt) {
        logActivity("[EVAC_DETAIL] Prepare failed (details): " . $conn->error, 'ERROR');
        throw new Exception("Database error occurred", 500);
    }
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        logActivity("[EVAC_DETAIL] Execute failed (details): " . $stmt->error, 'ERROR');
        $stmt->close();
        throw new Exception("Database error occurred", 500);
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        logActivity("[EVAC_DETAIL] No evacuation found for client {$clientCode} "
            . "with request_id={$requestId} / tenant_code={$tenantCode}", 'WARN');
        json_error("Evacuation not found under your account", 404);
    }

    logActivity("[EVAC_DETAIL] Evacuation loaded: {$row['request_id']} "
        . "| tenant: {$row['tenant_code']} (id: {$row['tenant_id']}) "
        . "| status: {$row['status']} "
        . "| txn_code: " . ($row['settlement_transaction_id'] ?: '(none)')
        . " | receipt: " . ($row['settlement_receipt_number'] ?: '(none)'));

    // ================================================================
    // DEDUCTIONS
    // ================================================================
    $deductions = [];
    $dedTotal = 0.0;
    $dedStmt = $conn->prepare("
        SELECT deduction_type, amount, description
        FROM evacuation_deductions
        WHERE request_id = ?
    ");
    if (!$dedStmt) {
        logActivity("[EVAC_DETAIL] Prepare failed (deductions): " . $conn->error, 'WARN');
    } else {
        $dedStmt->bind_param("s", $row['request_id']);
        if ($dedStmt->execute()) {
            $r = $dedStmt->get_result();
            while ($d = $r->fetch_assoc()) {
                $amt = (float) $d['amount'];
                $deductions[] = [
                    'deduction_type' => $d['deduction_type'],
                    'amount'         => $amt,
                    'description'    => $d['description'],
                ];
                $dedTotal += $amt;
            }
            $r->free();
        } else {
            logActivity("[EVAC_DETAIL] Execute failed (deductions): " . $dedStmt->error, 'WARN');
        }
        $dedStmt->close();
    }
    logActivity("[EVAC_DETAIL] Deductions: " . count($deductions)
        . " | total: ₦" . number_format($dedTotal, 2));

    // ================================================================
    // OUTSTANDING FEES
    // ================================================================
    $outstandingFees = [];
    $feesTotal = 0.0;
    $feesStmt = $conn->prepare("
        SELECT
            tf.tenant_fee_id,
            tf.fee_type_id,
            tf.amount,
            tf.due_date,
            tf.status,
            tf.notes,
            ft.fee_name AS fee_type_name
        FROM tenant_fees tf
        LEFT JOIN fee_types ft ON ft.fee_type_id = tf.fee_type_id
        WHERE tf.tenant_code = ?
          AND tf.status IN ('pending', 'overdue')
        ORDER BY tf.due_date ASC
    ");
    if (!$feesStmt) {
        logActivity("[EVAC_DETAIL] Prepare failed (fees): " . $conn->error, 'WARN');
    } else {
        $feesStmt->bind_param("s", $row['tenant_code']);
        if ($feesStmt->execute()) {
            $r = $feesStmt->get_result();
            while ($f = $r->fetch_assoc()) {
                $amt = (float) $f['amount'];
                $feesTotal += $amt;
                $outstandingFees[] = [
                    'tenant_fee_id' => (int) $f['tenant_fee_id'],
                    'fee_type_id'   => (int) $f['fee_type_id'],
                    'fee_type_name' => $f['fee_type_name'] ?: 'Unnamed Fee',
                    'amount'        => round($amt, 2),
                    'due_date'      => $f['due_date'],
                    'status'        => $f['status'],
                    'notes'         => $f['notes'],
                ];
            }
            $r->free();
        } else {
            logActivity("[EVAC_DETAIL] Execute failed (fees): " . $feesStmt->error, 'WARN');
        }
        $feesStmt->close();
    }
    logActivity("[EVAC_DETAIL] Outstanding fees: " . count($outstandingFees)
        . " | total: ₦" . number_format($feesTotal, 2));

    // ================================================================
    // SETTLEMENT LOOKUP (three-tier)
    //
    //   NOTE: evacuation_requests.settlement_transaction_id stores a
    //   settlement transaction code ("TXN-EVSET-..."), NOT a
    //   rent_payment_id. So we do NOT match on it directly.
    //
    //   Tier 1: st.tenant_id = tenants.id + client_code  (primary)
    //   Tier 2: rent_payments.tenant_code                 (fallback)
    // ================================================================
    $settlement = null;
    $settlementSource = null;

    $settlementBaseSelect = "
        SELECT
            st.id, st.payment_id, st.tracker_id, st.rent_payment_id,
            st.total_rent_amount, st.admin_share, st.agent_share, st.client_share,
            st.admin_percentage_used, st.agent_percentage_used, st.client_percentage_used,
            st.settlement_status, st.admin_paid, st.agent_paid, st.client_paid,
            st.rent_payment_date, st.settlement_date,
            st.admin_payment_date, st.agent_payment_date, st.client_payment_date,
            st.processed_by, st.notes, st.created_at
        FROM settlement_transactions st
    ";

    // ---- Tier 1: tenant_id
    logActivity("[EVAC_DETAIL] Settlement lookup tier 1 - tenant_id={$row['tenant_id']} + client_code={$clientCode}");
    $sStmt = $conn->prepare($settlementBaseSelect . "
        WHERE st.client_code = ?
          AND st.tenant_id = ?
        ORDER BY st.settlement_date DESC, st.id DESC
        LIMIT 1
    ");
    if (!$sStmt) {
        logActivity("[EVAC_DETAIL] Prepare failed (settlement tier 1): " . $conn->error, 'ERROR');
    } else {
        $sStmt->bind_param("si", $clientCode, $row['tenant_id']);
        if ($sStmt->execute()) {
            $s = $sStmt->get_result()->fetch_assoc();
            if ($s) {
                $settlement = $s;
                $settlementSource = 'tenant_id';
                logActivity("[EVAC_DETAIL] Tier 1 MATCH: settlement_id={$s['id']}, "
                    . "rent_payment_id={$s['rent_payment_id']}, "
                    . "client_share=₦" . number_format($s['client_share'], 2));
            } else {
                logActivity("[EVAC_DETAIL] Tier 1 no rows - trying tier 2");
            }
        } else {
            logActivity("[EVAC_DETAIL] Execute failed (settlement tier 1): " . $sStmt->error, 'WARN');
        }
        $sStmt->close();
    }

    // ---- Tier 2: rent_payments.tenant_code
    if (!$settlement) {
        logActivity("[EVAC_DETAIL] Settlement lookup tier 2 - rent_payments.tenant_code={$row['tenant_code']} + client_code={$clientCode}");
        $sStmt = $conn->prepare($settlementBaseSelect . "
            INNER JOIN rent_payments rp ON st.rent_payment_id = rp.rent_payment_id
            WHERE st.client_code = ?
              AND rp.tenant_code = ?
            ORDER BY st.settlement_date DESC, st.id DESC
            LIMIT 1
        ");
        if (!$sStmt) {
            logActivity("[EVAC_DETAIL] Prepare failed (settlement tier 2): " . $conn->error, 'ERROR');
        } else {
            $sStmt->bind_param("ss", $clientCode, $row['tenant_code']);
            if ($sStmt->execute()) {
                $s = $sStmt->get_result()->fetch_assoc();
                if ($s) {
                    $settlement = $s;
                    $settlementSource = 'rent_payments_tenant_code';
                    logActivity("[EVAC_DETAIL] Tier 2 MATCH: settlement_id={$s['id']}, "
                        . "rent_payment_id={$s['rent_payment_id']}");
                } else {
                    logActivity("[EVAC_DETAIL] Tier 2 no rows - no settlement available");
                }
            } else {
                logActivity("[EVAC_DETAIL] Execute failed (settlement tier 2): " . $sStmt->error, 'WARN');
            }
            $sStmt->close();
        }
    }

    logActivity("[EVAC_DETAIL] Settlement lookup result: "
        . ($settlement ? "FOUND via {$settlementSource} (id={$settlement['id']})" : "NOT FOUND"));

    // Normalize output
    $settlementOut = null;
    if ($settlement) {
        $settlementOut = [
            'id'                     => (int) $settlement['id'],
            'rent_payment_id'        => $settlement['rent_payment_id'],
            'total_rent_amount'      => (float) $settlement['total_rent_amount'],
            'admin_share'            => (float) $settlement['admin_share'],
            'agent_share'            => (float) $settlement['agent_share'],
            'client_share'           => (float) $settlement['client_share'],
            'admin_percentage_used'  => (float) $settlement['admin_percentage_used'],
            'agent_percentage_used'  => (float) $settlement['agent_percentage_used'],
            'client_percentage_used' => (float) $settlement['client_percentage_used'],
            'settlement_status'      => $settlement['settlement_status'],
            'admin_paid'             => (int) $settlement['admin_paid'],
            'agent_paid'             => (int) $settlement['agent_paid'],
            'client_paid'            => (int) $settlement['client_paid'],
            'settlement_date'        => $settlement['settlement_date'],
            'client_payment_date'    => $settlement['client_payment_date'],
            'notes'                  => $settlement['notes'],
            'found_via'              => $settlementSource,
        ];
    }

    // ================================================================
    // CLIENT WALLET ACTIVITY (last 10 for this tenant)
    // ================================================================
    $txns = [];
    $txnStmt = $conn->prepare("
        SELECT id, transaction_type, amount, running_balance, request_id, status, notes, created_at
        FROM client_wallet_transactions
        WHERE client_code = ?
          AND notes LIKE ?
        ORDER BY id DESC
        LIMIT 10
    ");
    if (!$txnStmt) {
        logActivity("[EVAC_DETAIL] Prepare failed (wallet txns): " . $conn->error, 'WARN');
    } else {
        $like = "%{$row['tenant_code']}%";
        $txnStmt->bind_param("ss", $clientCode, $like);
        if ($txnStmt->execute()) {
            $r = $txnStmt->get_result();
            while ($x = $r->fetch_assoc()) {
                $txns[] = [
                    'id'               => (int) $x['id'],
                    'transaction_type' => $x['transaction_type'],
                    'amount'           => (float) $x['amount'],
                    'running_balance'  => (float) $x['running_balance'],
                    'request_id'       => $x['request_id'],
                    'status'           => $x['status'],
                    'notes'            => $x['notes'],
                    'created_at'       => $x['created_at'],
                ];
            }
            $r->free();
        } else {
            logActivity("[EVAC_DETAIL] Execute failed (wallet txns): " . $txnStmt->error, 'WARN');
        }
        $txnStmt->close();
    }
    logActivity("[EVAC_DETAIL] Wallet transactions for tenant {$row['tenant_code']}: " . count($txns));

    // ================================================================
    // RESPONSE
    // ================================================================
    $responseData = [
        'request' => [
            'request_id'                 => $row['request_id'],
            'request_type'               => $row['request_type'],
            'status'                     => $row['status'],
            'reason'                     => $row['reason'],
            'notes'                      => $row['notes'],
            'requested_move_out_date'    => $row['requested_move_out_date'],
            'approved_move_out_date'     => $row['approved_move_out_date'],
            'created_at'                 => $row['created_at'],
            'reviewed_at'                => $row['reviewed_at'],
            'processed_at'               => $row['processed_at'],
            'reviewed_by_name'           => $row['reviewed_by_name'] ?? null,
            'processed_by_name'          => $row['processed_by_name'] ?? null,
            'admin_notes'                => $row['admin_notes'],
            'rejection_reason'           => $row['rejection_reason'],
            // NEW: surface these so the UI can show the transaction code
            // and enable the Download Receipt button.
            'settlement_transaction_id'  => $row['settlement_transaction_id'],
            'settlement_receipt_number'  => $row['settlement_receipt_number'],
        ],
        'tenant' => [
            'tenant_code'       => $row['tenant_code'],
            'full_name'         => trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')),
            'firstname'         => $row['firstname'],
            'lastname'          => $row['lastname'],
            'email'             => $row['email'],
            'phone'             => $row['phone'],
            'photo'             => $row['photo'],
            'gender'            => $row['gender'],
            'lease_start_date'  => $row['lease_start_date'],
            'lease_end_date'    => $row['lease_end_date'],
            'payment_frequency' => $row['payment_frequency'],
        ],
        'property' => [
            'property_code'    => $row['property_code'],
            'property_name'    => $row['property_name'],
            'apartment_code'   => $row['apt_code'],
            'apartment_number' => $row['apartment_number'],
            'security_deposit' => (float) $row['security_deposit'],
            'rent_amount'      => (float) $row['apartment_rent'],
        ],
        'financials' => [
            'cycle_rent_amount'                => (float) $row['cycle_rent_amount'],
            'cycle_total_days'                 => (int)   $row['cycle_total_days'],
            'cycle_days_used'                  => (int)   $row['cycle_days_used'],
            'total_paid_in_cycle'              => (float) $row['total_paid_in_cycle'],
            'rent_used'                        => (float) $row['rent_used'],
            'unused_rent'                      => (float) $row['unused_rent'],
            'tenant_rent_share'                => (float) $row['tenant_rent_share'],
            'landlord_rent_share'              => (float) $row['landlord_rent_share'],
            'damages_total'                    => (float) $row['damages_total'],
            'damages_from_deposit'             => (float) $row['damages_from_deposit'],
            'damages_from_share'               => (float) $row['damages_from_share'],
            'damages_owed_by_tenant'           => (float) $row['damages_owed_by_tenant'],
            'early_termination_fee_applicable' => (int) $row['early_termination_fee_applicable'] === 1,
            'early_termination_fee'            => (float) $row['early_termination_fee'],
            'has_outstanding_balance'          => (int) $row['has_outstanding_balance'] === 1,
            'outstanding_amount'               => (float) $row['outstanding_amount'],
            'final_settlement_amount'          => (float) $row['final_settlement_amount'],
            'security_deposit_refund'          => (float) $row['security_deposit_refund'],
            'settlement_receipt_number'        => $row['settlement_receipt_number'],
        ],
        'deductions'             => $deductions,
        'deductions_total'       => round($dedTotal, 2),
        'outstanding_fees'       => $outstandingFees,
        'outstanding_fees_total' => round($feesTotal, 2),
        'settlement'             => $settlementOut,
        'wallet_transactions'    => $txns,
    ];

    $duration = round((microtime(true) - $requestStartTime) * 1000, 2);
    logActivity("[EVAC_DETAIL] ========== SUCCESS ========== "
        . "| evac={$row['request_id']} "
        . "| settlement=" . ($settlementOut ? "id={$settlementOut['id']} ({$settlementSource})" : "none")
        . " | deductions=" . count($deductions)
        . " | fees=" . count($outstandingFees)
        . " | txns=" . count($txns)
        . " | {$duration}ms");

    json_success($responseData, "Evacuation details loaded");

} catch (Throwable $e) {
    $duration = round((microtime(true) - $requestStartTime) * 1000, 2);
    logActivity("[EVAC_DETAIL] ERROR: " . $e->getMessage() . " | {$duration}ms", 'ERROR');
    logActivity("[EVAC_DETAIL] Trace: " . $e->getTraceAsString(), 'ERROR');

    $code = (int) $e->getCode();
    if ($code < 400 || $code >= 600) $code = 500;
    json_error($e->getMessage(), $code);
}