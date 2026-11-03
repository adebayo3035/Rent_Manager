<?php
// regenerate_tenant_fees.php - Apply property fees to existing tenants

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

require_once __DIR__ . '/../utilities/rate_limit.php';

/**
 * Compute the due date for a fee given its billable start date.
 *
 * @param DateTime $billable_from  MAX(fee.effective_from, tenant.lease_start_date)
 * @param array    $fee            Fee configuration row (must have is_recurring, recurrence_period)
 * @return DateTime
 */
function computeDueDate(DateTime $billable_from, array $fee): DateTime
{
    $start = clone $billable_from;

    if (!empty($fee['is_recurring'])) {
        switch (strtolower($fee['recurrence_period'] ?? '')) {
            case 'monthly':
                return $start->modify('+1 month');
            case 'quarterly':
                return $start->modify('+3 months');
            case 'annually':
            case 'annually_':
            case 'yearly':
                return $start->modify('+1 year');
            default:
                return $start->modify('+1 month');
        }
    }

    // One-time fee: due 7 days after billable start
    return $start->modify('+7 days');
}

if (!isset($_SESSION)) session_start();
rateLimiter();

// Generate unique request ID for tracking
$requestId = uniqid('regenerate_fees_', true);
logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] ========== START ==========");
logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Request Time: " . date('Y-m-d H:i:s'));

try {
    // ==================== STEP 1: CHECK AUTHENTICATION ====================
    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Step 1: Checking authentication");

    if (!isset($_SESSION['unique_id'])) {
        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] ERROR: Not logged in");
        json_error("Not logged in", 401);
    }

    $userRole = $_SESSION['role'] ?? '';
    $admin_id = $_SESSION['unique_id'];

    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Admin ID: {$admin_id}, Role: {$userRole}");

    if (!in_array($userRole, ['Super Admin', 'Admin'])) {
        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] ERROR: Unauthorized access");
        json_error("Unauthorized access", 403);
    }

    // ==================== STEP 2: GET INPUT ====================
    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Step 2: Getting input");

    $input = json_decode(file_get_contents('php://input'), true);
    $property_code = $_POST['property_code'] ?? $_GET['property_code'] ?? ($input['property_code'] ?? '');
    $action_type = $input['action_type'] ?? 'full_sync';

    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Property Code: {$property_code}, Action Type: {$action_type}");

    if (empty($property_code)) {
        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] ERROR: Property code is required");
        json_error("Property code is required", 400);
    }

    // ==================== STEP 3: GET PROPERTY NAME ====================
    $property_query = "SELECT name FROM properties WHERE property_code = ?";
    $prop_stmt = $conn->prepare($property_query);
    $prop_stmt->bind_param("s", $property_code);
    $prop_stmt->execute();
    $prop_result = $prop_stmt->get_result();
    $property_name = $prop_result->fetch_assoc()['name'] ?? $property_code;
    $prop_stmt->close();

    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Property Name: {$property_name}");

    // ==================== STEP 4: GET TENANTS ====================
    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Step 3: Fetching tenants for property");

    $tenants_query = "
        SELECT
            t.tenant_code,
            CONCAT(t.firstname, ' ', t.lastname) as tenant_name,
            t.apartment_code,
            t.lease_start_date,
            t.lease_end_date,
            a.apartment_type_id
        FROM tenants t
        JOIN apartments a ON t.apartment_code = a.apartment_code
        WHERE a.property_code = ? AND t.status = 1
    ";
    $tenants_stmt = $conn->prepare($tenants_query);
    $tenants_stmt->bind_param("s", $property_code);
    $tenants_stmt->execute();
    $tenants_result = $tenants_stmt->get_result();

    if ($tenants_result->num_rows === 0) {
        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] No active tenants found");
        json_success([
            'property_code' => $property_code,
            'tenants_processed' => 0,
            'fees_created' => 0,
            'fees_updated' => 0,
            'fees_skipped_paid' => 0,
            'fees_skipped_not_started' => 0,
            'fees_skipped_outside_lease' => 0,
            'message' => 'No active tenants found for this property'
        ], "No tenants to process");
        exit();
    }

    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Found " . $tenants_result->num_rows . " tenants");

    // ==================== STEP 5: GET FEE CONFIGURATIONS ====================
    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Step 4: Fetching fee configurations");

    $fees_query = "
        SELECT
            pf.*,
            ft.fee_name,
            ft.fee_code,
            ft.is_mandatory,
            ft.is_recurring,
            ft.recurrence_period,
            ft.calculation_type
        FROM property_apartment_type_fees pf
        JOIN fee_types ft ON pf.fee_type_id = ft.fee_type_id
        WHERE pf.property_code = ?
        AND pf.is_active = 1
    ";
    $fees_stmt = $conn->prepare($fees_query);
    $fees_stmt->bind_param("s", $property_code);
    $fees_stmt->execute();
    $fees_result = $fees_stmt->get_result();

    if ($fees_result->num_rows === 0) {
        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] No active fee configurations found");
        json_success([
            'property_code' => $property_code,
            'tenants_processed' => 0,
            'fees_created' => 0,
            'fees_updated' => 0,
            'fees_skipped_paid' => 0,
            'fees_skipped_not_started' => 0,
            'fees_skipped_outside_lease' => 0,
            'message' => 'No fee configurations found for this property'
        ], "No fees to apply");
        exit();
    }

    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Found " . $fees_result->num_rows . " fee configurations");

    // Collect fee names for summary
    $fee_names = [];
    $fees_result->data_seek(0);
    while ($fee = $fees_result->fetch_assoc()) {
        $fee_names[] = $fee['fee_name'] . " (₦" . number_format($fee['amount'], 2) . ")";
    }
    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Fee configurations: " . implode(", ", $fee_names));

    // ==================== STEP 6: START TRANSACTION ====================
    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Step 5: Starting transaction");
    $conn->begin_transaction();

    try {
        $total_fees_created = 0;
        $total_fees_updated = 0;
        $total_fees_skipped_paid = 0;
        $total_fees_skipped_not_started = 0;
        $total_fees_skipped_outside_lease = 0;
        $tenants_processed = 0;
        $tenant_summaries = [];

        // Statement to insert a new fee
        $insert_stmt = $conn->prepare("
            INSERT INTO tenant_fees (
                tenant_code,
                apartment_code,
                fee_type_id,
                amount,
                due_date,
                status,
                created_at
            ) VALUES (?, ?, ?, ?, ?, 'pending', NOW())
        ");

        // Statement to update ONLY the due_date of an existing pending fee.
        // Amount changes are NOT applied to existing pending fees — instead
        // a new fee is inserted from the new effective_from so the audit
        // trail preserves both amounts.
        $update_stmt = $conn->prepare("
            UPDATE tenant_fees
            SET amount = ?,
                updated_at = NOW()
            WHERE tenant_fee_id = ?
        ");

        // ==================== STEP 7: PROCESS EACH TENANT ====================
        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Step 6: Processing tenants");

        $today = new DateTime();
        $today->setTime(0, 0, 0);

        while ($tenant = $tenants_result->fetch_assoc()) {
            $tenants_processed++;
            $tenant_fees_created = 0;
            $tenant_fees_updated = 0;
            $tenant_fees_skipped_paid = 0;
            $tenant_fees_skipped_not_started = 0;
            $tenant_fees_skipped_outside_lease = 0;
            $created_fees_list = [];
            $updated_fees_list = [];
            $skipped_fees_list = [];
            $not_started_fees_list = [];
            $outside_lease_fees_list = [];

            // -------- Per-tenant lease window --------
            $tenant_start = new DateTime($tenant['lease_start_date']);
            $tenant_start->setTime(0, 0, 0);
            $tenant_end = $tenant['lease_end_date']
                ? (new DateTime($tenant['lease_end_date']))->setTime(23, 59, 59)
                : null;

            logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Processing tenant: {$tenant['tenant_code']} ({$tenant['tenant_name']}), Apartment Type: {$tenant['apartment_type_id']}, Lease: {$tenant['lease_start_date']} → " . ($tenant['lease_end_date'] ?? 'open'));

            // Reset fee results pointer for each tenant
            $fees_result->data_seek(0);

            while ($fee = $fees_result->fetch_assoc()) {
                // Skip fees that don't apply to this apartment type
                if ($fee['apartment_type_id'] != $tenant['apartment_type_id']) {
                    continue;
                }

                logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Processing fee: {$fee['fee_name']} (ID: {$fee['fee_type_id']})");

                $amount = (float)$fee['amount'];

                // -------- Fee effective window --------
                $fee_effective = new DateTime($fee['effective_from']);
                $fee_effective->setTime(0, 0, 0);

                // GUARD 1: Fee effective after lease end → tenant never benefits
                if ($tenant_end && $fee_effective > $tenant_end) {
                    $total_fees_skipped_outside_lease++;
                    $tenant_fees_skipped_outside_lease++;
                    $outside_lease_fees_list[] = $fee['fee_name'];
                    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] SKIPPED (fee effective after lease end): {$fee['fee_name']} for {$tenant['tenant_name']} — fee effective {$fee['effective_from']}, lease ends {$tenant['lease_end_date']}");
                    continue;
                }

                // -------- Billable start: later of fee effective_from and tenant move-in --------
                // This is the earliest date from which the tenant can legitimately
                // be billed. A tenant is never charged for the period before
                // they moved in.
                $billable_from = $fee_effective > $tenant_start
                    ? clone $fee_effective
                    : clone $tenant_start;

                // ==================== CHECK EXISTING PENDING/OVERDUE FEE ====================
                $check_query = "
                    SELECT tenant_fee_id, amount, due_date, status
                    FROM tenant_fees
                    WHERE tenant_code = ? AND fee_type_id = ?
                    AND status IN ('pending', 'overdue')
                    ORDER BY due_date ASC
                ";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("si", $tenant['tenant_code'], $fee['fee_type_id']);
                $check_stmt->execute();
                $existing_result = $check_stmt->get_result();
                $existing_pending = [];
                while ($row = $existing_result->fetch_assoc()) {
                    $existing_pending[] = $row;
                }
                $check_stmt->close();

                // ==================== CHECK EXISTING PAID FEE ====================
                $paid_check_query = "
                    SELECT tenant_fee_id, status, due_date
                    FROM tenant_fees
                    WHERE tenant_code = ? AND fee_type_id = ?
                    AND status = 'paid'
                    ORDER BY due_date DESC
                    LIMIT 1
                ";
                $paid_stmt = $conn->prepare($paid_check_query);
                $paid_stmt->bind_param("si", $tenant['tenant_code'], $fee['fee_type_id']);
                $paid_stmt->execute();
                $paid_fee = $paid_stmt->get_result()->fetch_assoc();
                $paid_stmt->close();

                // ==================== DECISION TREE ====================

                // CASE 1: A paid fee already exists for this fee type.
                // Do NOT touch paid history. But — if the fee amount has
                // changed since the paid fee was issued, the NEXT cycle
                // should reflect the new amount. So insert a new fee for the
                // upcoming cycle ONLY if no pending/overdue already exists.
                if ($paid_fee) {
                    if (!empty($existing_pending)) {
                        // There's already a pending fee for this type — decide
                        // whether its amount matches the current config.
                        $already_up_to_date = false;
                        foreach ($existing_pending as $p) {
                            if ((float)$p['amount'] === $amount) {
                                $already_up_to_date = true;
                                break;
                            }
                        }

                        if ($already_up_to_date) {
                            logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] SKIPPED (already pending with current amount): {$fee['fee_name']} for {$tenant['tenant_name']}");
                            continue;
                        }

                        // Existing pending fee has an old amount. Do NOT rewrite
                        // it (that would retroactively overcharge for the cycle
                        // already on the tenant's bill). Insert a new fee for
                        // the next cycle from the new effective_from, IF that
                        // date is not already covered by the pending fee's due_date.
                        $pending_due_dates = array_map(
                            fn($p) => (new DateTime($p['due_date']))->setTime(0, 0, 0),
                            $existing_pending
                        );
                        $new_due_date = computeDueDate($billable_from, $fee);

                        $collision = false;
                        foreach ($pending_due_dates as $pd) {
                            if ($pd == $new_due_date) { $collision = true; break; }
                        }
                        if ($collision) {
                            logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] SKIPPED (next cycle already exists): {$fee['fee_name']} for {$tenant['tenant_name']}");
                            continue;
                        }
                        // Fall through to INSERT below
                    } else {
                        // Paid fee exists, no pending — insert the upcoming cycle
                        // only if the billable_from is not in the past beyond
                        // the last paid cycle. To keep this simple and safe:
                        // insert if billable_from is today or in the future.
                        // (Historic periods already paid for are covered by
                        // the paid row; we don't want to bill the same period twice.)
                        $last_paid_due = (new DateTime($paid_fee['due_date']))->setTime(0, 0, 0);
                        $next_due = computeDueDate($billable_from, $fee);

                        if ($next_due <= $last_paid_due) {
                            logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] SKIPPED (next cycle already covered by paid fee): {$fee['fee_name']} for {$tenant['tenant_name']}");
                            continue;
                        }
                        // Fall through to INSERT below
                    }
                } else {
                    // CASE 2: No paid fee exists.
                    if (!empty($existing_pending)) {
                        // There is a pending/overdue fee. Two sub-cases:
                        // (a) amount is already current → skip
                        // (b) amount differs → insert a new row for the NEXT
                        //     cycle (do not rewrite the existing pending row).
                        $already_up_to_date = false;
                        foreach ($existing_pending as $p) {
                            if ((float)$p['amount'] === $amount) {
                                $already_up_to_date = true;
                                break;
                            }
                        }
                        if ($already_up_to_date) {
                            logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] SKIPPED (already pending with current amount): {$fee['fee_name']} for {$tenant['tenant_name']}");
                            continue;
                        }

                        $new_due_date = computeDueDate($billable_from, $fee);
                        $collision = false;
                        foreach ($existing_pending as $p) {
                            $pd = (new DateTime($p['due_date']))->setTime(0, 0, 0);
                            if ($pd == $new_due_date) { $collision = true; break; }
                        }
                        if ($collision) {
                            logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] SKIPPED (next cycle already exists with old amount): {$fee['fee_name']} for {$tenant['tenant_name']}");
                            continue;
                        }
                        // Fall through to INSERT below
                    }
                    // else: no paid, no pending — plain insert
                }

                // ==================== INSERT NEW FEE ====================
                // We reach here when we need to create a new row:
                //  - first bill (no paid, no pending)
                //  - upcoming cycle after a paid fee
                //  - upcoming cycle after an amount change (old pending stays)
                $due_date = computeDueDate($billable_from, $fee);
                $due_date_str = $due_date->format('Y-m-d');

                $insert_stmt->bind_param(
                    "ssids",
                    $tenant['tenant_code'],
                    $tenant['apartment_code'],
                    $fee['fee_type_id'],
                    $amount,
                    $due_date_str
                );

                if ($insert_stmt->execute()) {
                    $total_fees_created++;
                    $tenant_fees_created++;
                    $created_fees_list[] = $fee['fee_name']
                        . " (₦" . number_format($amount, 2) . ", due " . $due_date_str . ")";
                    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] CREATED: {$fee['fee_name']} for {$tenant['tenant_name']} — Amount: ₦" . number_format($amount, 2) . ", Due: " . date('M d, Y', strtotime($due_date_str)) . " (billable_from: " . $billable_from->format('Y-m-d') . ", fee.effective_from: " . $fee['effective_from'] . ", lease_start: " . $tenant['lease_start_date'] . ")");
                } else {
                    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] ERROR creating {$fee['fee_name']} for {$tenant['tenant_name']}: " . $insert_stmt->error);
                }
            }

            // ==================== LOG TENANT SUMMARY ====================
            $tenant_summary = "Tenant: {$tenant['tenant_name']} ({$tenant['tenant_code']})";
            $parts = [];
            if (!empty($created_fees_list)) {
                $parts[] = "CREATED: " . implode(", ", $created_fees_list);
            }
            if (!empty($updated_fees_list)) {
                $parts[] = "UPDATED: " . implode(", ", $updated_fees_list);
            }
            if (!empty($skipped_fees_list)) {
                $parts[] = "SKIPPED (Paid): " . implode(", ", $skipped_fees_list);
            }
            if (!empty($not_started_fees_list)) {
                $parts[] = "SKIPPED (not started): " . implode(", ", $not_started_fees_list);
            }
            if (!empty($outside_lease_fees_list)) {
                $parts[] = "SKIPPED (outside lease): " . implode(", ", $outside_lease_fees_list);
            }

            if (!empty($parts)) {
                logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] {$tenant_summary} - " . implode(" | ", $parts));
            } else {
                logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] {$tenant_summary} - No changes");
            }

            $tenant_summaries[] = [
                'tenant_code' => $tenant['tenant_code'],
                'tenant_name' => $tenant['tenant_name'],
                'fees_created' => $tenant_fees_created,
                'fees_updated' => $tenant_fees_updated,
                'fees_skipped_paid' => $tenant_fees_skipped_paid,
                'fees_skipped_not_started' => $tenant_fees_skipped_not_started,
                'fees_skipped_outside_lease' => $tenant_fees_skipped_outside_lease,
                'created_fees' => $created_fees_list,
                'updated_fees' => $updated_fees_list,
                'skipped_fees' => $skipped_fees_list,
                'not_started_fees' => $not_started_fees_list,
                'outside_lease_fees' => $outside_lease_fees_list
            ];
        }

        // ==================== STEP 8: LOG SUMMARY ====================
        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] ========== SUMMARY ==========");
        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Property: {$property_name} ({$property_code})");
        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Tenants Processed: {$tenants_processed}");
        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Fees Created: {$total_fees_created}");
        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Fees Updated: {$total_fees_updated}");
        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Fees Skipped (Paid): {$total_fees_skipped_paid}");
        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Fees Skipped (Not Started): {$total_fees_skipped_not_started}");
        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Fees Skipped (Outside Lease): {$total_fees_skipped_outside_lease}");

        // ==================== STEP 9: COMMIT ====================
        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Step 8: Committing transaction");
        $conn->commit();

        // ==================== STEP 10: RETURN SUCCESS ====================
        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] ========== SUCCESS ==========");

        $message = "Successfully processed {$tenants_processed} tenant(s): "
            . "{$total_fees_created} fee(s) created, "
            . "{$total_fees_updated} fee(s) updated, "
            . "{$total_fees_skipped_paid} paid fee(s) skipped, "
            . "{$total_fees_skipped_not_started} fee(s) skipped (tenant not yet moved in), "
            . "{$total_fees_skipped_outside_lease} fee(s) skipped (outside lease period).";

        json_success([
            'property_code' => $property_code,
            'property_name' => $property_name,
            'tenants_processed' => $tenants_processed,
            'fees_created' => $total_fees_created,
            'fees_updated' => $total_fees_updated,
            'fees_skipped_paid' => $total_fees_skipped_paid,
            'fees_skipped_not_started' => $total_fees_skipped_not_started,
            'fees_skipped_outside_lease' => $total_fees_skipped_outside_lease,
            'details' => $tenant_summaries,
            'message' => $message
        ], $message);

    } catch (Exception $e) {
        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] ERROR in transaction: " . $e->getMessage());
        $conn->rollback();
        throw $e;
    }

    // Clean up statements
    if (isset($insert_stmt)) $insert_stmt->close();
    if (isset($update_stmt)) $update_stmt->close();
    if (isset($tenants_stmt)) $tenants_stmt->close();
    if (isset($fees_stmt)) $fees_stmt->close();

} catch (Exception $e) {
    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] ========== ERROR ==========");
    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Error Message: " . $e->getMessage());
    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Error Code: " . $e->getCode());
    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Error File: " . $e->getFile());
    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Error Line: " . $e->getLine());

    json_error($e->getMessage(), 500);
}
?>