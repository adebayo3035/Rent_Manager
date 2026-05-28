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
        <div class="revenue-cards">
            <div class="revenue-card">
                <span>Total Collected</span>
                <strong id="totalCollected">&#8358;0</strong>
            </div>
            <div class="revenue-card">
                <span>Pending Payments</span>
                <strong class="pending" id="pendingPayments">&#8358;0</strong>
            </div>
            <div class="revenue-card">
                <span>Overdue Payments</span>
                <strong class="overdue" id="overduePayments">&#8358;0</strong>
            </div>
            <div class="revenue-card">
                <span>Expected Revenue</span>
                <strong id="expectedRevenue">&#8358;0</strong>
            </div>
        </div>
    </section>

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
