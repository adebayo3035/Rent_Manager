<?php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notification_helper.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/wallet_security.php';   // verifyTenantPasswordWithLockout

if (!isset($_SESSION)) session_start();
rateLimiter();

$auth = requireAuth([
    'method'                 => 'POST',
    'rate_key'               => 'tenant_update_profile',
    'rate_limit'             => [10, 300],
    'csrf'                   => ['enabled' => true, 'form_name' => 'tenant_profile_form'],
    'roles'                  => ['Tenant'],
    'require_active_tenancy' => true,
]);

logActivity("[UPDATE_PROFILE] ========== START ==========");

try {
    $tenant_code = $_SESSION['tenant_code'];
    logActivity("[UPDATE_PROFILE] Tenant: {$tenant_code}");

    // ---- Parse input ----
    $raw_input = file_get_contents('php://input');
    $input = json_decode($raw_input, true);

    if (!$input) {
        json_error("Invalid input data", 400);
    }

    $firstname        = trim($input['firstname'] ?? '');
    $lastname         = trim($input['lastname'] ?? '');
    $email            = trim($input['email'] ?? '');
    $phone            = trim($input['phone'] ?? '');
    $gender           = trim($input['gender'] ?? '');
    $current_password = $input['current_password'] ?? '';   // only required if email/phone changes

    // ---- Required field validation ----
    if (empty($firstname) || empty($lastname) || empty($email) || empty($phone)) {
        json_error("First name, last name, email, and phone are required", 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error("Invalid email format", 400);
    }
    if (!validate_phone($phone)) {
        json_error("Invalid phone number format", 400);
    }
    if (!empty($gender) && !in_array($gender, ['Male', 'Female', 'Other'], true)) {
        json_error("Invalid gender value", 400);
    }

    // ---- Read the tenant's current row (need current email/phone/password hash) ----
    $stmt = $conn->prepare("
        SELECT email, phone, password
        FROM tenants
        WHERE tenant_code = ? AND status = 1
        LIMIT 1
    ");
    if (!$stmt) throw new Exception("Prepare failed (load tenant): " . $conn->error);
    $stmt->bind_param("s", $tenant_code);
    $stmt->execute();
    $current = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$current) {
        json_error("Tenant not found", 404);
    }

    // ---- Determine what's actually changing ----
    $emailChanging = strcasecmp($email, $current['email']) !== 0;
    $phoneChanging = $phone !== $current['phone'];

    logActivity("[UPDATE_PROFILE] Changes: email=" . ($emailChanging ? 'yes' : 'no')
        . ", phone=" . ($phoneChanging ? 'yes' : 'no'));

    // ---- If email or phone is changing, require password reauth ----
    if ($emailChanging || $phoneChanging) {
        if ($current_password === '') {
            json_error(
                "Please enter your password to change your email or phone number",
                400
            );
        }

        // Verify password — outside any transaction so the lockout counter persists
        $pwCheck = verifyTenantPasswordWithLockout(
            $conn, $tenant_code, $current_password, $current['password']
        );
        if (!$pwCheck['ok']) {
            logActivity("[UPDATE_PROFILE] Password reauth failed: " . ($pwCheck['message'] ?? ''), 'WARN');
            json_error(
                $pwCheck['message'] ?? 'Incorrect password',
                $pwCheck['locked'] ? 423 : 401
            );
        }
        logActivity("[UPDATE_PROFILE] Password reauth succeeded for sensitive change");
    }

    // ---- Transaction for the actual update ----
    $conn->begin_transaction();
    logActivity("[UPDATE_PROFILE] Transaction started");

    try {
        // Uniqueness pre-checks (fast rejection; UNIQUE constraints are the real defense)
        if ($emailChanging) {
            $chk = $conn->prepare("
                SELECT tenant_code FROM tenants
                WHERE email = ? AND tenant_code != ? AND status = 1
                LIMIT 1
            ");
            $chk->bind_param("ss", $email, $tenant_code);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $chk->close();
                throw new Exception("Email address is already used by another tenant", 409);
            }
            $chk->close();
        }

        if ($phoneChanging) {
            $chk = $conn->prepare("
                SELECT tenant_code FROM tenants
                WHERE phone = ? AND tenant_code != ? AND status = 1
                LIMIT 1
            ");
            $chk->bind_param("ss", $phone, $tenant_code);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $chk->close();
                throw new Exception("Phone number is already used by another tenant", 409);
            }
            $chk->close();
        }

        // ---- Apply update ----
        $upd = $conn->prepare("
            UPDATE tenants
            SET firstname = ?, lastname = ?, email = ?, phone = ?, gender = ?,
                last_updated_by = ?, last_updated_at = NOW()
            WHERE tenant_code = ? AND status = 1
        ");
        if (!$upd) throw new Exception("Prepare failed (update): " . $conn->error);
        $upd->bind_param(
            "sssssss",
            $firstname, $lastname, $email, $phone, $gender,
            $tenant_code, $tenant_code
        );

        if (!$upd->execute()) {
            // Handle UNIQUE constraint race (someone else claimed the email/phone
            // between our pre-check and this UPDATE)
            if ($upd->errno === 1062) {
                $field = strpos($upd->error, 'email') !== false ? 'Email' : 'Phone number';
                $upd->close();
                throw new Exception("{$field} was just claimed by another tenant. Please try again.", 409);
            }
            throw new Exception("Failed to update profile: " . $upd->error);
        }
        $affected = $upd->affected_rows;
        $upd->close();
        logActivity("[UPDATE_PROFILE] Update applied, affected_rows={$affected}");

        // ---- Notification (inside transaction, atomic with update) ----
        try {
            createSecurityNotification($conn, $tenant_code, 'profile_updated');
        } catch (Throwable $notifErr) {
            logActivity("[UPDATE_PROFILE] Notification failed (non-fatal): " . $notifErr->getMessage(), 'WARN');
            // Don't throw — notification failure shouldn't block the profile update
        }

        // ---- Clear login attempts (successful reauth, if we did one) ----
        if ($emailChanging || $phoneChanging) {
            $clr = $conn->prepare("DELETE FROM tenant_login_attempts WHERE tenant_code = ?");
            if ($clr) { $clr->bind_param("s", $tenant_code); $clr->execute(); $clr->close(); }
        }

        $conn->commit();
        logActivity("[UPDATE_PROFILE] Transaction committed");

    } catch (Throwable $e) {
        $conn->rollback();
        logActivity("[UPDATE_PROFILE] ROLLBACK: " . $e->getMessage(), 'ERROR');
        throw $e;
    }

    // ---- Fetch updated user data ----
    $fetch = $conn->prepare("
        SELECT tenant_code, firstname, lastname, email, phone, gender, photo,
               apartment_code, lease_start_date, lease_end_date, payment_frequency
        FROM tenants
        WHERE tenant_code = ? AND status = 1
        LIMIT 1
    ");
    $fetch->bind_param("s", $tenant_code);
    $fetch->execute();
    $userData = $fetch->get_result()->fetch_assoc();
    $fetch->close();

    logActivity("[UPDATE_PROFILE] ========== SUCCESS ==========");

    json_success($userData, "Profile updated successfully");

} catch (Throwable $e) {
    logActivity("[UPDATE_PROFILE] ERROR: " . $e->getMessage(), 'ERROR');
    $code = $e->getCode();
    if ($code < 400 || $code >= 600) $code = 500;
    json_error($e->getMessage(), $code);
}