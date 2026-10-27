<?php
// admin/backend/wallet/request_detail.php
// Return a single wallet request with its full ledger audit trail.

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';

if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

$auth = requireAuth([
    'method'   => 'GET',
    'rate_key' => 'admin_wallet_detail',
    'roles'    => ['Super Admin', 'Admin'],
]);

$adminId = (int) $auth['user_id'];
$requestCode = trim($_GET['request_code'] ?? '');
if ($requestCode === '') json_error("Request code is required", 400);

logActivity("[REQUEST_DETAIL] ========== START ==========");
logActivity("[REQUEST_DETAIL] Admin: {$adminId} | Request: {$requestCode}");

try {
    // ---- 1) Load request ----
    $stmt = $conn->prepare("
        SELECT
            wr.id, wr.request_code, wr.user_type, wr.user_code,
            wr.request_type, wr.amount, wr.status,
            wr.bank_proof_path, wr.reference_number,
            wr.bank_name, wr.bank_account_name, wr.bank_account_number,
            wr.bank_proof_notes, wr.rejection_reason, wr.notes,
            wr.approved_by, wr.approved_at, wr.created_at,
            CASE
                WHEN wr.user_type = 'client' THEN CONCAT(c.firstname, ' ', c.lastname)
                WHEN wr.user_type = 'tenant' THEN CONCAT(t.firstname, ' ', t.lastname)
                ELSE NULL
            END AS full_name,
            CASE
                WHEN wr.user_type = 'client' THEN c.email
                WHEN wr.user_type = 'tenant' THEN t.email
                ELSE NULL
            END AS email,
            CASE
                WHEN wr.user_type = 'client' THEN c.phone
                WHEN wr.user_type = 'tenant' THEN t.phone
                ELSE NULL
            END AS phone
        FROM wallet_requests wr
        LEFT JOIN clients c ON wr.user_type = 'client' AND wr.user_code = c.client_code
        LEFT JOIN tenants t ON wr.user_type = 'tenant' AND wr.user_code = t.tenant_code
        WHERE wr.request_code = ?
        LIMIT 1
    ");
    if (!$stmt) throw new Exception("Prepare failed (request): " . $conn->error);
    $stmt->bind_param("s", $requestCode);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$req) throw new Exception("Request not found", 404);

    // ---- 2) Load ledger trail (all rows for this request_id) ----
    $ledger = [];
    if (in_array($req['user_type'], ['tenant', 'client'], true)) {
        $table = "{$req['user_type']}_wallet_transactions";
        $codeCol = "{$req['user_type']}_code";
        $lsql = "
            SELECT id, transaction_type, amount, running_balance,
                   status, notes, created_by, created_at
            FROM {$table}
            WHERE request_id = ?
            ORDER BY id ASC
        ";
        $lstmt = $conn->prepare($lsql);
        if ($lstmt) {
            $lstmt->bind_param("s", $requestCode);
            $lstmt->execute();
            $lres = $lstmt->get_result();
            while ($row = $lres->fetch_assoc()) {
                $ledger[] = [
                    'id'              => (int) $row['id'],
                    'transaction_type' => $row['transaction_type'],
                    'amount'          => (float) $row['amount'],
                    'amount_formatted' => ($row['amount'] >= 0 ? '+' : '') . '₦' . number_format(abs((float) $row['amount']), 2),
                    'running_balance' => (float) $row['running_balance'],
                    'status'          => $row['status'],
                    'notes'           => $row['notes'],
                    'created_by'      => $row['created_by'],
                    'created_at'      => $row['created_at'],
                ];
            }
            $lstmt->close();
        }
    }

    // ---- 3) Load approved_by admin name if available ----
    $approvedBy = null;
    if (!empty($req['approved_by'])) {
        $astmt = $conn->prepare("SELECT firstname, lastname FROM admin_tbl WHERE unique_id = ? LIMIT 1");
        if ($astmt) {
            $aid = (int) $req['approved_by'];
            $astmt->bind_param("i", $aid);
            $astmt->execute();
            $arow = $astmt->get_result()->fetch_assoc();
            $astmt->close();
            if ($arow) {
                $approvedBy = trim($arow['firstname'] . ' ' . $arow['lastname']);
            }
        }
    }

    logActivity("[REQUEST_DETAIL] ========== SUCCESS ==========");
    logActivity("[REQUEST_DETAIL] Request: {$requestCode}, ledger rows: " . count($ledger));

    json_success('Request detail retrieved',[
        'request' => [
            'id'                  => (int) $req['id'],
            'request_code'        => $req['request_code'],
            'user_type'           => $req['user_type'],
            'user_code'           => $req['user_code'],
            'full_name'           => $req['full_name'],
            'email'               => $req['email'],
            'phone'               => $req['phone'],
            'request_type'        => $req['request_type'],
            'amount'              => (float) $req['amount'],
            'amount_formatted'    => '₦' . number_format((float) $req['amount'], 2),
            'status'              => $req['status'],
            'bank_proof_path'     => $req['bank_proof_path'],
            'reference_number'    => $req['reference_number'],
            'bank_name'           => $req['bank_name'],
            'bank_account_name'   => $req['bank_account_name'],
            'bank_account_number' => $req['bank_account_number'],
            'bank_proof_notes'    => $req['bank_proof_notes'],
            'rejection_reason'    => $req['rejection_reason'],
            'notes'               => $req['notes'],
            'approved_by'         => $req['approved_by'],
            'approved_by_name'    => $approvedBy,
            'approved_at'         => $req['approved_at'],
            'created_at'          => $req['created_at'],
        ],
        'ledger' => $ledger,
    ]);

} catch (Throwable $e) {
    logActivity("[REQUEST_DETAIL] ERROR: " . $e->getMessage(), 'ERROR');
    json_error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
}