<?php
// --- Page guard bootstrap ---
require_once __DIR__ . '/../backend/utilities/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$clientCode = $_SESSION['client_code'] ?? null;   // or tenant_code
if (!$clientCode) {
    header('Location: index.php');
    exit;
}

guardPageIfClient($conn);   // or guardPageIfTenant($conn)

ob_start();
include 'navbar.php';
$page = ob_get_clean();

$page = str_replace('<title>Rent Pilot</title>', '<title>Rent Payments | Client Portal</title>', $page);
$page = str_replace('</head>', '<link rel="stylesheet" href="../css/payment.css"></head>', $page);
$page = str_replace('</body>', '<script src="../scripts/payment.js"></script></body>', $page);

echo $page;
?>
