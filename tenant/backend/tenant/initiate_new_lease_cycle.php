<?php
// initiate_new_lease_cycle.php - Tenant starts new lease cycle after lease ends

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notification_helper.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/tenant_status.php';   // for TENANT_STATE_* + helpers

if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

$auth = requireAuth([
    'method'   => 'POST',
    'rate_key' => 'initiate_lease_cycle',     // ← unique to this endpoint
    'roles'    => ['Tenant'],
    // NOTE: do NOT rely on 'require_active_tenancy' — we check state explicitly below
    // because the shared guard has had bugs and the semantics differ per endpoint.
]);

logActivity("========== INITIATE NEW LEASE CYCLE ==========");

$tenant_code = $_SESSION['tenant_code'] ?? null;
if (!$tenant_code) {
    logActivity("ERROR: No tenant_code in session");
    json_error("Not logged in", 401);
}

if (($_SESSION['role'] ?? '') !== 'Tenant') {
    logActivity("ERROR: Unauthorized access - Role: " . ($_SESSION['role'] ?? 'none'));
    json_error("Unauthorized access", 403);
}

logActivity("Tenant Code: {$tenant_code}");

try {
    // ==================================================================
    // STEP 1 — Load & validate tenancy state (OUTSIDE transaction)
    // ==================================================================
    $tenantRow = loadTenantForWalletOp($conn, $tenant_code);
    if (!$tenantRow) {
        logActivity("Tenant not found: {$tenant_code}", 'WARN');
        json_error("Tenant not found", 404);
    }

    $accountStatus = (int) $tenantRow['status'];
    $tenancyStatus = (int) $tenantRow['tenant_status'];

    logActivity(
        "Tenant loaded: {$tenant_code} | account_status={$accountStatus} | tenancy_status={$tenancyStatus}"
    );

    // ONLY active tenants can initiate a new lease cycle.
    // Evacuated / suspended / inactive tenants must not renew — they need
    // a brand-new tenancy (which is an admin/client flow, not a renewal).
    if ($accountStatus !== TENANT_ACCOUNT_ENABLED || $tenancyStatus !== TENANT_STATE_ACTIVE) {
        $label = TENANT_STATE_LABELS[$tenancyStatus] ?? "Unknown ({$tenancyStatus})";
        logActivity(
            "Blocked: tenant {$tenant_code} not eligible to renew | " .
            "account_status={$accountStatus} | tenancy_status={$tenancyStatus}",
            'WARN'
        );
        json_error(
            "Your tenancy is currently {$label}. Lease renewal is only available for active tenants.",
            403,
            null,
            'TENANCY_RENEWAL_BLOCKED',
            [
                'tenant_code'    => $tenant_code,
                'account_status' => $accountStatus,
                'tenancy_status' => $tenancyStatus,
                'tenancy_label'  => $label,
            ]
        );
    }

    // ==================================================================
    // STEP 2 — Pull current lease + payment state (still outside tx)
    // ==================================================================
    $checkQuery = "
        SELECT
            t.tenant_code,
            t.lease_end_date,
            t.lease_start_date,
            t.apartment_code,
            t.property_code,
            t.agreed_payment_frequency,
            t.agreed_rent_amount AS old_rent,
            t.payment_amount_per_period AS old_payment_per_period,
            COUNT(CASE WHEN tr.status != 'paid' THEN 1 END) AS unpaid_periods,
            COUNT(CASE WHEN tr.status = 'pending_verification' THEN 1 END) AS pending_verification_count
        FROM tenants t
        LEFT JOIN rent_payment_tracker tr ON t.tenant_code = tr.tenant_code
        WHERE t.tenant_code = ?
        GROUP BY t.tenant_code
    ";
    $checkStmt = $conn->prepare($checkQuery);
    if (!$checkStmt) {
        throw new Exception("Prepare failed (lease state): " . $conn->error, 500);
    }
    $checkStmt->bind_param("s", $tenant_code);
    if (!$checkStmt->execute()) {
        throw new Exception("Execute failed (lease state): " . $checkStmt->error, 500);
    }
    $tenant = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if (!$tenant) {
        throw new Exception("Tenant record disappeared unexpectedly", 404);
    }

    $tz = new DateTimeZone('Africa/Lagos');
    $today = new DateTime('now', $tz);
    $today->setTime(0, 0, 0);
    $lease_end = new DateTime($tenant['lease_end_date'], $tz);
    $lease_end->setTime(0, 0, 0);

    logActivity("Today: " . $today->format('Y-m-d'));
    logActivity("Lease End Date: " . $lease_end->format('Y-m-d'));
    logActivity("Unpaid Periods: " . $tenant['unpaid_periods']);
    logActivity("Pending Verification Count: " . $tenant['pending_verification_count']);

    if ((int) $tenant['unpaid_periods'] > 0) {
        logActivity("Blocked: tenant {$tenant_code} has unpaid periods", 'WARN');
        json_error(
            "Cannot start new cycle. You have {$tenant['unpaid_periods']} unpaid period(s). "
            . "Please complete all payments first.",
            400
        );
    }

    if ((int) $tenant['pending_verification_count'] > 0) {
        logActivity("Blocked: tenant {$tenant_code} has pending verification", 'WARN');
        json_error(
            "Cannot start new cycle. You have a payment pending verification. "
            . "Please wait for admin approval.",
            400
        );
    }

    if ($today < $lease_end) {
        $days_left = $today->diff($lease_end)->days;
        logActivity("Blocked: lease still active, {$days_left} day(s) remaining", 'WARN');
        json_error(
            "Cannot start new cycle. Your current lease ends on "
            . $lease_end->format('F j, Y')
            . " ({$days_left} days remaining).",
            400
        );
    }

    logActivity("Eligibility check passed - tenant can start new lease cycle");

    // ==================================================================
    // STEP 3 — Resolve apartment rent/deposit (still outside tx)
    // ==================================================================
    $aptQuery = "
        SELECT rent_amount, security_deposit, apartment_number
        FROM apartments
        WHERE apartment_code = ?
        LIMIT 1
    ";
    $aptStmt = $conn->prepare($aptQuery);
    if (!$aptStmt) {
        throw new Exception("Prepare failed (apartment): " . $conn->error, 500);
    }
    $aptStmt->bind_param("s", $tenant['apartment_code']);
    if (!$aptStmt->execute()) {
        throw new Exception("Execute failed (apartment): " . $aptStmt->error, 500);
    }
    $apartment = $aptStmt->get_result()->fetch_assoc();
    $aptStmt->close();

    if (!$apartment) {
        logActivity("Apartment not found: " . $tenant['apartment_code'], 'WARN');
        json_error("Your apartment record could not be found. Please contact support.", 500);
    }

    $new_rent_amount      = (float) $apartment['rent_amount'];
    $new_security_deposit = (float) $apartment['security_deposit'];
    $payment_frequency    = $tenant['agreed_payment_frequency'] ?: 'Annually';

    logActivity(
        "Current apartment rent: ₦{$new_rent_amount} (old rent was ₦{$tenant['old_rent']})"
    );

    // ==================================================================
    // STEP 4 — Compute new cycle dates & amounts
    // ==================================================================
    $periodsMap = [
        'Monthly'       => 12,
        'Quarterly'     => 4,
        'Semi-Annually' => 2,
        'Annually'      => 1,
    ];
    $periodsPerYear = $periodsMap[$payment_frequency] ?? 1;
    $payment_per_period = round($new_rent_amount / $periodsPerYear, 2);

    $new_start = clone $lease_end;
    $new_start->modify('+1 day');

    $new_end = clone $new_start;
    $new_end->modify('+1 year')->modify('-1 day');

    logActivity("New lease period: {$new_start->format('Y-m-d')} to {$new_end->format('Y-m-d')}");
    logActivity("New payment per period: ₦{$payment_per_period} ({$payment_frequency})");

    // ==================================================================
    // STEP 5 — Transaction (locks + writes only from here on)
    // ==================================================================
    $conn->begin_transaction();
    logActivity("Transaction started");

    try {
        // 5a. Lock the tenant row and re-verify everything under the lock
        $lockStmt = $conn->prepare("
            SELECT tenant_code, status, tenant_status, lease_end_date
            FROM tenants
            WHERE tenant_code = ?
            FOR UPDATE
        ");
        if (!$lockStmt) {
            throw new Exception("Prepare failed (lock tenant): " . $conn->error, 500);
        }
        $lockStmt->bind_param("s", $tenant_code);
        if (!$lockStmt->execute()) {
            throw new Exception("Execute failed (lock tenant): " . $lockStmt->error, 500);
        }
        $locked = $lockStmt->get_result()->fetch_assoc();
        $lockStmt->close();

        if (!$locked) {
            throw new Exception("Tenant not found", 404);
        }

        $accRecheck = (int) $locked['status'];
        $tenRecheck = (int) $locked['tenant_status'];

        if ($accRecheck !== TENANT_ACCOUNT_ENABLED || $tenRecheck !== TENANT_STATE_ACTIVE) {
            logActivity(
                "Eligibility changed during request for {$tenant_code}: " .
                "account_status={$accRecheck} | tenancy_status={$tenRecheck}",
                'WARN'
            );
            throw new Exception(
                "Your tenancy state changed during this request. Please refresh and try again.",
                409
            );
        }

        $leaseEndRecheck = new DateTime($locked['lease_end_date'], $tz);
        $leaseEndRecheck->setTime(0, 0, 0);
        if ($today < $leaseEndRecheck) {
            throw new Exception("Your lease is still active. Please try again after it ends.", 409);
        }

        // 5b. Guard: no existing ongoing cycle (idempotency)
        $dupStmt = $conn->prepare("
            SELECT rent_payment_id, status
            FROM rent_payments
            WHERE tenant_code = ? AND status = 'ongoing'
            LIMIT 1
        ");
        if (!$dupStmt) {
            throw new Exception("Prepare failed (dup check): " . $conn->error, 500);
        }
        $dupStmt->bind_param("s", $tenant_code);
        if (!$dupStmt->execute()) {
            throw new Exception("Execute failed (dup check): " . $dupStmt->error, 500);
        }
        $existing = $dupStmt->get_result()->fetch_assoc();
        $dupStmt->close();

        if ($existing) {
            logActivity(
                "Blocked: tenant {$tenant_code} already has ongoing cycle {$existing['rent_payment_id']}",
                'WARN'
            );
            json_error(
                "You already have an ongoing lease cycle.",
                409,
                null,
                'CYCLE_ALREADY_STARTED',
                ['rent_payment_id' => $existing['rent_payment_id']]
            );
        }

        // 5c. Create the rent_payments row
        $new_rent_payment_id = 'RENT_' . strtoupper(bin2hex(random_bytes(10)));
        $receipt_number      = 'RCP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));
        $reference_number    = 'REF-' . date('Ymd') . '-' . substr($tenant_code, -6) . '-RENEW';
        $due_date            = calculateDueDate($new_end->format('Y-m-d'), $payment_frequency);

        $insertRent = $conn->prepare("
            INSERT INTO rent_payments (
                rent_payment_id, tenant_code, apartment_code,
                amount, amount_paid, balance,
                payment_date, payment_method, payment_period,
                period_start_date, period_end_date, due_date,
                reference_number, status, payment_type,
                receipt_number, notes,
                agreed_rent_amount, payment_amount_per_period, created_at
            ) VALUES (
                ?, ?, ?,
                ?, 0, ?,
                NOW(), 'pending', ?,
                ?, ?, ?,
                ?, 'ongoing', 'rent',
                ?, ?,
                ?, ?, NOW()
            )
        ");
        if (!$insertRent) {
            throw new Exception("Prepare failed (rent insert): " . $conn->error, 500);
        }

        $notes           = "New lease cycle initiated by tenant on " . date('Y-m-d H:i:s');
        $new_start_date  = $new_start->format('Y-m-d');
        $new_end_date    = $new_end->format('Y-m-d');
        $payment_period  = $payment_frequency . ' Cycle';
        $balanceToPay    = $new_rent_amount;

        // Types (18 placeholders):
        // sss d d  s sss   s sss  ss  dd
        // ^rent_id   ^amt  ^paid ^balance   ^period^start^end  ^due ^ref ^receipt ^notes ^agreed ^per_period
        $insertRent->bind_param(
             "sssddsssssssdd",
            $new_rent_payment_id,
            $tenant_code,
            $tenant['apartment_code'],
            $new_rent_amount,
            $balanceToPay,
            $payment_period,
            $new_start_date,
            $new_end_date,
            $due_date,
            $reference_number,
            $receipt_number,
            $notes,
            $new_rent_amount,
            $payment_per_period
        );

        if (!$insertRent->execute()) {
            throw new Exception("Failed to create rent payment record: " . $insertRent->error, 500);
        }
        $insertRent->close();
        logActivity("New rent_payments record created: {$new_rent_payment_id}");

        // 5d. Create tracker rows
        $intervalMap = [
            'Monthly'       => 1,
            'Quarterly'     => 3,
            'Semi-Annually' => 6,
            'Annually'      => 12,
        ];
        $interval_months = $intervalMap[$payment_frequency] ?? 12;

        $insertTracker = $conn->prepare("
            INSERT INTO rent_payment_tracker (
                rent_payment_id, tenant_code, apartment_code,
                period_number, start_date, end_date,
                remaining_balance, amount_paid, payment_date,
                status, payment_id, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 0, NULL, 'available', ?, NOW())
        ");
        if (!$insertTracker) {
            throw new Exception("Prepare failed (tracker insert): " . $conn->error, 500);
        }

        $period_number     = 1;
        $remaining_balance = $new_rent_amount;
        $trackers_created  = 0;
        $current_start     = clone $new_start;

        while ($current_start <= $new_end) {
            $current_end = clone $current_start;
            $current_end->modify("+{$interval_months} months")->modify('-1 day');
            if ($current_end > $new_end) {
                $current_end = clone $new_end;
            }

            $tracker_code = 'TRAK_' . strtoupper(bin2hex(random_bytes(8))) . '_' . $period_number;
            $cur_start_str = $current_start->format('Y-m-d');
            $cur_end_str   = $current_end->format('Y-m-d');

            $insertTracker->bind_param(
                "sssissds",
                $new_rent_payment_id,
                $tenant_code,
                $tenant['apartment_code'],
                $period_number,
                $cur_start_str,
                $cur_end_str,
                $remaining_balance,
                $tracker_code
            );

            if (!$insertTracker->execute()) {
                throw new Exception(
                    "Failed to create tracker #{$period_number}: " . $insertTracker->error,
                    500
                );
            }

            $trackers_created++;
            logActivity("Created tracker #{$period_number}: {$cur_start_str} to {$cur_end_str}");

            $remaining_balance -= $payment_per_period;
            $current_start = clone $current_end;
            $current_start->modify('+1 day');
            $period_number++;

            if ($period_number > 100) {
                throw new Exception("Tracker creation exceeded safety limit", 500);
            }
        }
        $insertTracker->close();
        logActivity("Created {$trackers_created} tracker record(s) for new cycle");

        // 5e. Update tenant record
        $updateTenant = $conn->prepare("
            UPDATE tenants
            SET lease_start_date = ?,
                lease_end_date = ?,
                agreed_rent_amount = ?,
                payment_amount_per_period = ?,
                rent_balance = ?,
                last_rent_update_date = NOW()
            WHERE tenant_code = ?
        ");
        if (!$updateTenant) {
            throw new Exception("Prepare failed (tenant update): " . $conn->error, 500);
        }
        $updateTenant->bind_param(
            "ssddds",
            $new_start_date,
            $new_end_date,
            $new_rent_amount,
            $payment_per_period,
            $new_rent_amount,
            $tenant_code
        );
        if (!$updateTenant->execute()) {
            throw new Exception("Failed to update tenant record: " . $updateTenant->error, 500);
        }
        $updateTenant->close();
        logActivity("Tenant record updated with new lease dates");

        // 5f. Notification (inside tx — if it throws, we roll back cleanly)
        if (function_exists('createLeaseNotification')) {
            createLeaseNotification($conn, $tenant_code, 'renewed', $new_end->format('Y-m-d'));
            logActivity("Lease renewal notification queued");
        } else {
            logActivity("createLeaseNotification() unavailable — skipping", 'WARN');
        }

        $conn->commit();
        logActivity("Transaction committed successfully");

    } catch (Throwable $e) {
        $conn->rollback();
        logActivity("Transaction rolled back");
        throw $e;
    }

    // ==================================================================
    // STEP 6 — Respond
    // ==================================================================
    $oldRent = (float) $tenant['old_rent'];
    $response_data = [
        'tenant_code'               => $tenant_code,
        'new_lease_start'           => $new_start->format('Y-m-d'),
        'new_lease_end'             => $new_end->format('Y-m-d'),
        'new_rent_amount'           => $new_rent_amount,
        'old_rent_amount'           => $oldRent,
        'rent_increased'            => $new_rent_amount > $oldRent,
        'rent_increase_percentage'  => $oldRent > 0
            ? round((($new_rent_amount - $oldRent) / $oldRent) * 100, 2)
            : 0,
        'payment_per_period'        => $payment_per_period,
        'payment_frequency'         => $payment_frequency,
        'total_periods'             => $trackers_created,
        'rent_payment_id'           => $new_rent_payment_id,
        'message'                   => "New lease cycle started successfully! "
            . "Your new rent is ₦" . number_format($new_rent_amount, 2) . " per year.",
    ];

    logActivity("========== INITIATE NEW LEASE CYCLE - SUCCESS ==========");
    json_success($response_data, $response_data['message']);

} catch (Throwable $e) {
    // Catches Exception, Error, TypeError — not just Exception
    $code = (int) $e->getCode();
    if ($code < 400 || $code > 599) {
        $code = 500;
    }
    logActivity("ERROR ({$code}): " . $e->getMessage(), 'ERROR');
    logActivity("Stack trace: " . $e->getTraceAsString(), 'ERROR');
    json_error($e->getMessage(), $code);
}

/**
 * Calculate due date based on period end date and payment frequency.
 */
function calculateDueDate($period_end_date, $payment_frequency)
{
    $gracePeriods = [
        'Monthly'       => 7,
        'Quarterly'     => 14,
        'Semi-Annually' => 30,
        'Annually'      => 90,
    ];
    $daysToAdd = $gracePeriods[$payment_frequency] ?? 7;
    $dueDate   = new DateTime($period_end_date);
    $dueDate->modify("+{$daysToAdd} days");
    return $dueDate->format('Y-m-d');
}