<?php
// tenant/backend/wallet/get_banks.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

// Only authenticated users can fetch banks (needed because we also return
// their identity for autofill). Role check is loose — we just need to know
// who's asking.
$auth = requireAuth([
    'method'   => 'GET',
    'rate_key' => 'get_banks',
    'roles'    => ['Tenant', 'Client'],
]);

// ---- Build user identity from session ----
// Tenant and client sessions store different keys; handle both.
$user = [
    'firstname' => '',
    'lastname'  => '',
    'full_name' => '',
];

try {
    if (isset($_SESSION['tenant_code']) && $_SESSION['tenant_code'] !== '') {
        $stmt = $conn->prepare("
            SELECT firstname, lastname FROM tenants WHERE tenant_code = ? LIMIT 1
        ");
        $stmt->bind_param("s", $_SESSION['tenant_code']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $user['firstname'] = $row['firstname'] ?? '';
            $user['lastname']  = $row['lastname']  ?? '';
        }
    } elseif (isset($_SESSION['client_code']) && $_SESSION['client_code'] !== '') {
        $stmt = $conn->prepare("
            SELECT firstname, lastname FROM clients WHERE client_code = ? LIMIT 1
        ");
        $stmt->bind_param("s", $_SESSION['client_code']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $user['firstname'] = $row['firstname'] ?? '';
            $user['lastname']  = $row['lastname']  ?? '';
        }
    }
} catch (Throwable $e) {
    logActivity("[GET_BANKS] User lookup failed (non-fatal): " . $e->getMessage(), 'WARN');
    // Fall through with empty user — banks are still useful
}

// Compose the uppercase full name (matches Nigerian bank statement convention)
$user['full_name'] = trim(strtoupper($user['firstname'] . ' ' . $user['lastname']));

logActivity("[GET_BANKS] user=" . ($_SESSION['tenant_code'] ?? $_SESSION['client_code'] ?? '?')
    . " | name='{$user['full_name']}'");

// ---- Banks list ----
$banks = [
    ['code' => '044',  'name' => 'Access Bank'],
    ['code' => '063',  'name' => 'Access Bank (Diamond)'],
    ['code' => '035A', 'name' => 'ALAT by Wema'],
    ['code' => '401',  'name' => 'ASO Savings and Loans'],
    ['code' => '050',  'name' => 'Ecobank Nigeria'],
    ['code' => '084',  'name' => 'Enterprise Bank'],
    ['code' => '070',  'name' => 'Fidelity Bank'],
    ['code' => '011',  'name' => 'First Bank of Nigeria'],
    ['code' => '214',  'name' => 'First City Monument Bank'],
    ['code' => '058',  'name' => 'Guaranty Trust Bank'],
    ['code' => '030',  'name' => 'Heritage Bank'],
    ['code' => '301',  'name' => 'Jaiz Bank'],
    ['code' => '082',  'name' => 'Keystone Bank'],
    ['code' => '526',  'name' => 'Parallex Bank'],
    ['code' => '076',  'name' => 'Polaris Bank'],
    ['code' => '101',  'name' => 'Providus Bank'],
    ['code' => '221',  'name' => 'Stanbic IBTC Bank'],
    ['code' => '068',  'name' => 'Standard Chartered Bank'],
    ['code' => '232',  'name' => 'Sterling Bank'],
    ['code' => '100',  'name' => 'Suntrust Bank'],
    ['code' => '032',  'name' => 'Union Bank of Nigeria'],
    ['code' => '033',  'name' => 'United Bank for Africa'],
    ['code' => '215',  'name' => 'Unity Bank'],
    ['code' => '035',  'name' => 'Wema Bank'],
    ['code' => '057',  'name' => 'Zenith Bank'],
];

json_success([
    'banks' => $banks,
    'user'  => [
        'firstname' => $user['firstname'],
        'lastname'  => $user['lastname'],
        'full_name' => $user['full_name'],   // ← pre-composed, uppercase
    ],
], 'Banks retrieved');