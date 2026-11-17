<?php
// --- Page guard bootstrap ---
require_once __DIR__ . '/../backend/utilities/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$clientCode = $_SESSION['client_code'] ?? null;
if (!$clientCode) {
    header('Location: index.php');
    exit;
}

guardPageIfClient($conn);

ob_start();
include 'navbar.php';
$page = ob_get_clean();

$page = str_replace('<title>Rent Pilot</title>', '<title>Evacuations | Client Portal</title>', $page);
$page = str_replace('</head>', '<link rel="stylesheet" href="../css/evacuations.css"></head>', $page);
$page = str_replace('</body>', '<script src="../scripts/evacuations.js"></script></body>', $page);

echo $page;