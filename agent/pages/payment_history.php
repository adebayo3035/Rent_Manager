<?php
$paymentHistoryContent = <<<'HTML'
<section class="payment-history-container" aria-label="Payment history">
    <div class="page-header">
        <div>
            <h1><i class="fas fa-receipt"></i> Payment History</h1>
            <p>Review rent payment records, statuses, receipts, and transaction details.</p>
        </div>
    </div>

    <div class="payments-summary" id="paymentsSummary" aria-label="Payment summary">
        <div class="summary-card">
            <div class="summary-icon"><i class="fas fa-spinner fa-pulse"></i></div>
            <div class="summary-details">
                <div class="summary-label">Loading</div>
                <div class="summary-amount">Please wait</div>
                <div class="summary-count">Fetching payment summary</div>
            </div>
        </div>
    </div>

    <div class="filters-bar" aria-label="Payment filters">
        <div class="filters-row">
            <div class="filter-group">
                <label for="searchPayments">Search</label>
                <input type="search" id="searchPayments" placeholder="Receipt, property, apartment">
            </div>
            <div class="filter-group">
                <label for="filterStatus">Status</label>
                <select id="filterStatus">
                    <option value="all">All Status</option>
                    <option value="paid">Paid</option>
                    <option value="completed">Completed</option>
                    <option value="pending">Pending</option>
                    <option value="pending_verification">Pending Verification</option>
                    <option value="failed">Failed</option>
                </select>
            </div>
            <div class="filter-group">
                <label for="filterYear">Year</label>
                <select id="filterYear">
                    <option value="">All Years</option>
                </select>
            </div>
            <div class="filter-group">
                <label for="filterProperty">Property</label>
                <select id="filterProperty">
                    <option value="">All Properties</option>
                </select>
            </div>
            <div class="filter-actions">
                <button class="btn-apply" type="button" onclick="applyFilters()">
                    <i class="fas fa-filter"></i>
                    <span>Apply</span>
                </button>
                <button class="btn-reset" type="button" onclick="resetFilters()">
                    <i class="fas fa-rotate-left"></i>
                    <span>Reset</span>
                </button>
            </div>
        </div>
    </div>

    <div class="loading-overlay" id="paymentsLoading">
        <div class="loading-spinner"></div>
        <p>Loading payment history...</p>
    </div>

    <div class="payments-table-container" id="paymentsTableContainer" style="display: none;">
        <table class="payments-table">
            <thead>
                <tr>
                    <th onclick="sortTable('payment_date')">Date <i class="fas fa-sort"></i></th>
                    <th onclick="sortTable('receipt_number')">Receipt <i class="fas fa-sort"></i></th>
                    <th onclick="sortTable('property_name')">Property <i class="fas fa-sort"></i></th>
                    <th>Apartment</th>
                    <th onclick="sortTable('amount')">Amount <i class="fas fa-sort"></i></th>
                    <th onclick="sortTable('status')">Status <i class="fas fa-sort"></i></th>
                    <th>Method</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="paymentsTableBody"></tbody>
        </table>
    </div>

    <div class="payment-history-cards" id="paymentsCardsContainer" aria-label="Payment history cards"></div>

    <div class="pagination" id="pagination"></div>
</section>
HTML;

ob_start();
include 'navbar.php';
$page = ob_get_clean();

$page = preg_replace(
    '/<div class="loading-spinner">\s*<div class="spinner"><\/div>\s*<\/div>/',
    $paymentHistoryContent,
    $page,
    1
);

$page = str_replace('<title>Rent Pilot</title>', '<title>Payment History | Client Portal</title>', $page);
$page = str_replace('</head>', '<link rel="stylesheet" href="../css/payment_history.css"></head>', $page);
$page = str_replace('</body>', '<script src="../scripts/payment_history.js"></script></body>', $page);

echo $page;
?>
