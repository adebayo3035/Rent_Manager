<?php
// tenant/backend/utilities/tenant_status.php
// Shared tenancy-state helpers for wallet operations.

/**
 * The two status columns on the tenants table:
 *   status        : 0 = account disabled, 1 = account enabled
 *   tenant_status : 0 = Inactive, 1 = Active, 2 = Suspended, 3 = Evacuated
 *
 * Business rules for wallet operations (withdrawal, bank changes):
 *   - ACTIVE tenants    (status=1, tenant_status=1) may move money.
 *   - EVACUATED tenants (status=0, tenant_status=3) may move money out
 *     to settle their remaining wallet balance.
 *   - INACTIVE, SUSPENDED, and DISABLED-ACTIVE tenants are blocked.
 */

const TENANT_ACCOUNT_DISABLED = 0;
const TENANT_ACCOUNT_ENABLED  = 1;

const TENANT_STATE_INACTIVE  = 0;
const TENANT_STATE_ACTIVE    = 1;
const TENANT_STATE_SUSPENDED = 2;
const TENANT_STATE_EVACUATED = 3;

const TENANT_STATE_LABELS = [
    TENANT_STATE_INACTIVE  => 'Inactive',
    TENANT_STATE_ACTIVE    => 'Active',
    TENANT_STATE_SUSPENDED => 'Suspended',
    TENANT_STATE_EVACUATED => 'Evacuated',
];

/**
 * Returns true if the tenant is permitted to perform wallet money-out
 * operations (withdrawals, bank changes).
 */
function tenantCanPerformWalletOps(int $accountStatus, int $tenancyStatus): bool
{
    // Active tenant, account enabled
    if ($accountStatus === TENANT_ACCOUNT_ENABLED && $tenancyStatus === TENANT_STATE_ACTIVE) {
        return true;
    }
    // Evacuated tenant: account is disabled after evacuation, but they
    // still need to withdraw their settlement balance.
    if ($accountStatus === TENANT_ACCOUNT_DISABLED && $tenancyStatus === TENANT_STATE_EVACUATED) {
        return true;
    }
    return false;
}

/**
 * Loads a tenant row by code with the columns wallet operations need.
 * Returns null if the row does not exist.
 *
 * @param mysqli $conn
 * @param string $tenantCode
 * @return array|null
 * @throws Exception on prepare/execute failure
 */
function loadTenantForWalletOp(mysqli $conn, string $tenantCode): ?array
{
    $sql = "
        SELECT
            tenant_code,
            firstname,
            lastname,
            status,
            tenant_status,
            wallet_balance,
            secret_answer,
            has_secret_set,
            bank_name,
            bank_code,
            bank_account_name,
            bank_account_number,
            bank_changed_at
        FROM tenants
        WHERE tenant_code = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed (loadTenantForWalletOp): " . $conn->error);
    }
    $stmt->bind_param("s", $tenantCode);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed (loadTenantForWalletOp): " . $stmt->error);
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Same as loadTenantForWalletOp, but locks the row inside the current
 * transaction (SELECT ... FOR UPDATE). Must be called inside a transaction.
 *
 * @param mysqli $conn
 * @param string $tenantCode
 * @return array|null
 * @throws Exception on prepare/execute failure
 */
function lockTenantForWalletOp(mysqli $conn, string $tenantCode): ?array
{
    $sql = "
        SELECT
            tenant_code,
            status,
            tenant_status,
            wallet_balance,
            bank_name,
            bank_code,
            bank_account_name,
            bank_account_number,
            bank_changed_at
        FROM tenants
        WHERE tenant_code = ?
        FOR UPDATE
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed (lockTenantForWalletOp): " . $conn->error);
    }
    $stmt->bind_param("s", $tenantCode);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed (lockTenantForWalletOp): " . $stmt->error);
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Produces a user-facing message explaining why a tenant is blocked.
 */
function tenantWalletBlockedMessage(int $tenancyStatus): string
{
    $label = TENANT_STATE_LABELS[$tenancyStatus] ?? "Unknown ({$tenancyStatus})";
    return "This action is not available for your current tenancy state ({$label}). Please contact support.";
}