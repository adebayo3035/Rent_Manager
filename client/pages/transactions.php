<?php
// --- Page guard bootstrap ---
require_once __DIR__ . '/../backend/utilities/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$clientCode = $_SESSION['client_code'] ?? null;   // or tenant_code
if (!$clientCode) {
    header('Location: index.php');
    exit;
}

guardPageIfClient($conn);   // or guardPageIfClient($conn)
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction History | RentFlow Pro</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/navbar.css">
    <link rel="stylesheet" href="../css/transactions.css">
</head>
<body class="transactions-page">
    <?php include 'navbar.php'; ?>

    <div class="tenant-wrapper">
        <div class="tenant-main">
            <div class="content-area">
                <main class="tx-page">

                    <header class="tx-header">
                        <div>
                            <p class="tx-eyebrow">Client Portal</p>
                            <h1>Transaction History</h1>
                            <p class="tx-subtitle">Every payment, refund, deposit, and wallet movement on your account.</p>
                        </div>
                        <div class="tx-header-actions">
                            <button class="tx-btn tx-btn-outline" onclick="loadTransactions()">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                            <button class="tx-btn tx-btn-outline" onclick="exportExcel()">
                                <i class="fas fa-file-excel"></i> Excel
                            </button>
                            <button class="tx-btn tx-btn-primary" onclick="exportPdf()">
                                <i class="fas fa-file-pdf"></i> PDF
                            </button>
                        </div>
                    </header>

                    <section class="tx-stats" id="txStats"></section>

                    <section class="tx-filters">
                        <div class="tx-filter-grid" id="txFilterGrid"></div>
                    </section>

                    <section class="tx-table-card">
                        <div class="tx-table-header">
                            <h2>All Transactions</h2>
                            <div class="tx-table-actions">
                                <select id="txLimit" class="tx-select" onchange="loadTransactions()">
                                    <option value="25">25 per page</option>
                                    <option value="50">50 per page</option>
                                    <option value="100">100 per page</option>
                                </select>
                            </div>
                        </div>

                        <div id="txLoading" class="tx-loading">
                            <div class="tx-spinner"></div>
                            <p>Loading transactions...</p>
                        </div>

                        <div id="txEmpty" class="tx-empty" style="display:none;">
                            <i class="fas fa-receipt"></i>
                            <h3>No transactions found</h3>
                            <p>Try adjusting your filters or check back later.</p>
                        </div>

                        <div id="txTableWrap" class="tx-table-wrap" style="display:none;">
                            <table class="tx-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th>Description</th>
                                        <th>Reference</th>
                                        <th class="num">Amount</th>
                                        <th>Status</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="txTableBody"></tbody>
                            </table>
                        </div>

                        <div id="txPagination" class="tx-pagination"></div>
                    </section>

                </main>
            </div>
        </div>
    </div>

    <!-- Slide-over stays OUTSIDE .tenant-main so it can overlay the whole viewport -->
    <div id="txSlideOver" class="tx-slide" role="dialog" aria-modal="true" aria-labelledby="txSlideTitle">
        <div class="tx-slide-backdrop" onclick="closeTxSlide()"></div>
        <aside class="tx-slide-panel" onclick="event.stopPropagation()">
            <div class="tx-slide-header">
                <div>
                    <h2 id="txSlideTitle">Transaction Details</h2>
                    <p id="txSlideSubtitle"></p>
                </div>
                <button class="tx-slide-close" onclick="closeTxSlide()" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="tx-slide-body" id="txSlideBody"></div>
            <div class="tx-slide-footer" id="txSlideFooter"></div>
        </aside>
    </div>

    <script src="../scripts/transactions.js"></script>
</body>
</html>
