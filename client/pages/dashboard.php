<?php
$dashboardContent = <<<'HTML'
<section class="dashboard-container" aria-label="Client dashboard">
    <div class="dashboard-header">
        <div>
            <p class="dashboard-eyebrow">Client Dashboard</p>
            <h1>Welcome back, <span id="dashboardClientName">Loading...</span></h1>
            <p>Track properties, occupancy, revenue, and recent rent payments from one place.</p>
        </div>
        <a href="payments.php" class="dashboard-action">
            <i class="fas fa-credit-card"></i>
            <span>View Payments</span>
        </a>
    </div>

    <!-- ==================== STATS GRID ==================== -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon stat-icon-blue">
                <i class="fas fa-building"></i>
            </div>
            <div class="stat-info">
                <span>Total Properties</span>
                <strong id="totalProperties">0</strong>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon stat-icon-green">
                <i class="fas fa-door-open"></i>
            </div>
            <div class="stat-info">
                <span>Total Units</span>
                <strong id="totalUnits">0</strong>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon stat-icon-amber">
                <i class="fas fa-users"></i>
            </div>
            <div class="stat-info">
                <span>Occupied Units</span>
                <strong id="occupiedUnits">0</strong>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon stat-icon-red">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="stat-info">
                <span>Occupancy Rate</span>
                <strong id="occupancyRate">0%</strong>
            </div>
        </div>
    </div>

    <!-- ==================== REVENUE OVERVIEW ==================== -->
    <section class="dashboard-panel revenue-section">
        <div class="section-header">
            <div>
                <h2>Revenue Overview</h2>
                <p>Rent payment summary for the selected period.</p>
            </div>
            <select id="revenuePeriod" class="period-select" aria-label="Revenue period">
                <option value="monthly">This Month</option>
                <option value="quarterly">This Quarter</option>
                <option value="yearly">This Year</option>
                <option value="all">All Time</option>
            </select>
        </div>

        <!-- Rent Revenue Cards -->
        <div class="revenue-cards">
            <div class="revenue-card">
                <span>Total Collected</span>
                <strong class="collected" id="totalCollected">₦0.00</strong>
            </div>
            <div class="revenue-card">
                <span>Pending Payments</span>
                <strong class="pending" id="pendingPayments">₦0.00</strong>
            </div>
            <div class="revenue-card">
                <span>Overdue Payments</span>
                <strong class="overdue" id="overduePayments">₦0.00</strong>
            </div>
            <div class="revenue-card">
                <span>Expected Revenue</span>
                <strong class="expected" id="expectedRevenue">₦0.00</strong>
            </div>
        </div>

        <!-- Section Divider -->
        <div class="section-label">
            <i class="fas fa-hand-holding-usd"></i>
            <span>Your Settlement Earnings</span>
            <span class="label-count">Net after deductions</span>
        </div>

        <!-- Settlement Revenue Cards -->
        <div class="settlement-cards">
            <div class="settlement-card">
                <span>Total Earned</span>
                <strong class="earned" id="settlementTotalEarned">₦0.00</strong>
            </div>
            <div class="settlement-card">
                <span>Paid to You</span>
                <strong class="paid" id="settlementTotalPaid">₦0.00</strong>
                <span class="stat-badge success" id="settlementRate">0% of rent</span>
            </div>
            <div class="settlement-card">
                <span>Pending Payout</span>
                <strong class="pending" id="settlementTotalPending">₦0.00</strong>
                <span class="stat-badge warning" id="settlementPending">0 settlements</span>
            </div>
            <div class="settlement-card">
                <span>Settlement Rate</span>
                <strong class="rate" id="settlementRateDisplay">0%</strong>
                <span class="stat-badge info" id="settlementCompleted">0 completed</span>
            </div>
        </div>

        <!-- Deduction Summary -->
        <div class="section-label">
            <i class="fas fa-calculator"></i>
            <span>Deductions & Net Earnings</span>
        </div>

        <div class="deduction-summary">
            <div class="deduction-card">
                <div class="deduction-icon admin">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div class="deduction-content">
                    <span class="deduction-label">Admin Fees</span>
                    <span class="deduction-value" id="deductionAdminFees">₦0.00</span>
                </div>
            </div>
            <div class="deduction-card">
                <div class="deduction-icon agent">
                    <i class="fas fa-user-tie"></i>
                </div>
                <div class="deduction-content">
                    <span class="deduction-label">Agent Commissions</span>
                    <span class="deduction-value" id="deductionAgentCommissions">₦0.00</span>
                </div>
            </div>
            <div class="deduction-card">
                <div class="deduction-icon total">
                    <i class="fas fa-calculator"></i>
                </div>
                <div class="deduction-content">
                    <span class="deduction-label">Total Deductions</span>
                    <span class="deduction-value" id="deductionTotal">₦0.00</span>
                </div>
            </div>
            <div class="deduction-card net">
                <div class="deduction-icon net">
                    <i class="fas fa-wallet"></i>
                </div>
                <div class="deduction-content">
                    <span class="deduction-label">Net Received</span>
                    <span class="deduction-value" id="summaryNetReceived">₦0.00</span>
                </div>
            </div>
        </div>
    </section>

    <!-- ==================== PROPERTIES & PAYMENTS ==================== -->
    <div class="dashboard-grid">
        <section class="dashboard-panel">
            <div class="section-header">
                <div>
                    <h2>Your Properties</h2>
                    <p>Recently listed properties under your account.</p>
                </div>
                <a href="apartment.php" class="view-link">View All <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="properties-grid" id="propertiesGrid">
                <div class="loading">Loading properties...</div>
            </div>
        </section>

        <section class="dashboard-panel">
            <div class="section-header">
                <div>
                    <h2>Recent Payments</h2>
                    <p>Latest rent transactions across your properties.</p>
                </div>
                <a href="payments.php" class="view-link">View All <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="recent-table" id="recentPayments">
                <div class="loading">Loading payments...</div>
            </div>
        </section>
    </div>
</section>
HTML;

ob_start();
include 'navbar.php';
$page = ob_get_clean();

$page = preg_replace(
    '/<div class="loading-spinner">\s*<div class="spinner"><\/div>\s*<\/div>/',
    $dashboardContent,
    $page,
    1
);

$page = str_replace('</head>', '<link rel="stylesheet" href="../css/dashboard.css"></head>', $page);
$page = str_replace('</body>', '<script src="../scripts/dashboard.js"></script></body>', $page);

echo $page;
?>