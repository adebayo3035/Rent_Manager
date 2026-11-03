<?php

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notification_helper.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
require_once __DIR__ . '/../utilities/wallet_security.php';

if (session_status() === PHP_SESSION_NONE)
    session_start();
rateLimiter();

// ---- Auth ----
$auth = requireAuth([
    'method' => 'POST',
    'rate_key' => 'client_update_profile',
    'rate_limit' => [10, 300],
    'csrf' => ['enabled' => true, 'form_name' => 'client_profile_form'],
    'roles' => ['Client'],
]);

$client_code = $_SESSION['client_code'] ?? null;
if (!$client_code)
    json_error("Session expired", 401);

logActivity("[CLIENT_UPDATE_PROFILE] ========== START ==========");
logActivity("[CLIENT_UPDATE_PROFILE] Client: {$client_code}");

try {
    // ---- Parse input ----
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        json_error("Invalid input data", 400);
    }

    $firstname = trim($input['firstname'] ?? '');
    $lastname = trim($input['lastname'] ?? '');
    $email = trim($input['email'] ?? '');
    $phone = trim($input['phone'] ?? '');
    $gender = trim($input['gender'] ?? '');
    $address = trim($input['address'] ?? '');
    $current_password = $input['current_password'] ?? '';

    // ---- Field validation ----
    if ($firstname === '' || $lastname === '' || $email === '' || $phone === '') {
        json_error("First name, last name, email, and phone are required", 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error("Invalid email format", 400);
    }
    if (function_exists('validate_phone') && !validate_phone($phone)) {
        json_error("Invalid phone number format", 400);
    }
    if ($gender !== '' && !in_array($gender, ['Male', 'Female', 'Other'], true)) {
        json_error("Invalid gender value", 400);
    }

    // ---- Load current client row ----
    $stmt = $conn->prepare("
        SELECT email, phone, password
        FROM clients
        WHERE client_code = ? AND status = 1
        LIMIT 1
    ");
    if (!$stmt)
        throw new Exception("Prepare failed (load client): " . $conn->error);
    $stmt->bind_param("s", $client_code);
    $stmt->execute();
    $current = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$current) {
        json_error("Client not found", 404);
    }

    // ---- Determine what's changing ----
    $emailChanging = strcasecmp($email, $current['email']) !== 0;
    $phoneChanging = $phone !== $current['phone'];

    logActivity("[CLIENT_UPDATE_PROFILE] Changes: email=" . ($emailChanging ? 'yes' : 'no')
        . ", phone=" . ($phoneChanging ? 'yes' : 'no'));

    // ---- Conditional password reauth ----
    if ($emailChanging || $phoneChanging) {
        if ($current_password === '') {
            json_error("Please enter your password to change your email or phone number", 400);
        }

        $pwCheck = verifyClientPasswordWithLockout(
            $conn,
            $client_code,
            $current_password,
            $current['password'],
            session_id()
        );

        if (!$pwCheck['ok']) {
            $isLocked = !empty($pwCheck['locked']);
            logActivity(
                "[CLIENT_UPDATE_PROFILE] Password reauth rejected: " . ($pwCheck['message'] ?? ''),
                'WARN'
            );

            json_error(
                $pwCheck['message'] ?? 'Current password is incorrect',
                $isLocked ? 423 : 401,
                null,
                $isLocked ? 'ACCOUNT_LOCKED' : 'INVALID_PASSWORD',
                $isLocked ? ['client_code' => $client_code] : null
            );
        }
        logActivity("[CLIENT_UPDATE_PROFILE] Password reauth succeeded for sensitive change");
    }

    // ---- Transaction for the actual update ----
    $conn->begin_transaction();
    logActivity("[CLIENT_UPDATE_PROFILE] Transaction started");

    try {
        // Pre-checks
        if ($emailChanging) {
            $chk = $conn->prepare("
                SELECT client_code FROM clients
                WHERE email = ? AND client_code != ? AND status = 1
                LIMIT 1
            ");
            $chk->bind_param("ss", $email, $client_code);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $chk->close();
                throw new Exception("Email address is already used by another client", 409);
            }
            $chk->close();
        }

        if ($phoneChanging) {
            $chk = $conn->prepare("
                SELECT client_code FROM clients
                WHERE phone = ? AND client_code != ? AND status = 1
                LIMIT 1
            ");
            $chk->bind_param("ss", $phone, $client_code);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $chk->close();
                throw new Exception("Phone number is already used by another client", 409);
            }
            $chk->close();
        }

        // ---- Apply update ----
        $upd = $conn->prepare("
            UPDATE clients
            SET firstname = ?, lastname = ?, email = ?, phone = ?,
                gender = ?, address = ?, date_updated = NOW()
            WHERE client_code = ? AND status = 1
        ");
        if (!$upd)
            throw new Exception("Prepare failed (update): " . $conn->error);
        $upd->bind_param(
            "sssssss",
            $firstname,
            $lastname,
            $email,
            $phone,
            $gender,
            $address,
            $client_code
        );

        if (!$upd->execute()) {
            if ($upd->errno === 1062) {
                $field = strpos($upd->error, 'email') !== false ? 'Email' : 'Phone number';
                $upd->close();
                throw new Exception("{$field} was just claimed by another client. Please try again.", 409);
            }
            throw new Exception("Failed to update profile: " . $upd->error);
        }
        $affected = $upd->affected_rows;
        $upd->close();
        logActivity("[CLIENT_UPDATE_PROFILE] Update applied, affected_rows={$affected}");

        // ---- Notify the client (best-effort) ----
        try {
            if (function_exists('createSecurityNotification')) {
                createSecurityNotification($conn, $client_code, 'profile_updated');
            }
        } catch (Throwable $notifErr) {
            logActivity("[CLIENT_UPDATE_PROFILE] Notification failed (non-fatal): " . $notifErr->getMessage(), 'WARN');
        }

        $conn->commit();
        logActivity("[CLIENT_UPDATE_PROFILE] Transaction committed");

    } catch (Throwable $e) {
        $conn->rollback();
        logActivity("[CLIENT_UPDATE_PROFILE] ROLLBACK: " . $e->getMessage(), 'ERROR');
        throw $e;
    }

    // ---- Fetch updated user data ----
    $fetch = $conn->prepare("
        SELECT
            client_code, firstname, lastname, email, phone, address,
            photo, gender, status, bank_name, bank_account_name,
            bank_account_number, date_created
        FROM clients
        WHERE client_code = ? AND status = 1
        LIMIT 1
    ");
    $fetch->bind_param("s", $client_code);
    $fetch->execute();
    $userData = $fetch->get_result()->fetch_assoc();
    $fetch->close();

    logActivity("[CLIENT_UPDATE_PROFILE] ========== SUCCESS ==========");

    json_success($userData, "Profile updated successfully");

} catch (Throwable $e) {
    logActivity("[CLIENT_UPDATE_PROFILE] ERROR: " . $e->getMessage(), 'ERROR');
    $code = $e->getCode();
    if ($code < 400 || $code >= 600)
        $code = 500;
    json_error($e->getMessage(), $code);
} finally {
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
        $conn->close();
    }
}