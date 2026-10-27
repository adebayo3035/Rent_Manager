<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Wallet Reporting - Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://kit.fontawesome.com/7cab3097e7.js" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="../../styles.css">
    <link rel="stylesheet" href="../css/wallet_requests.css">
</head>

<body>
    <?php include 'navbar.php'; ?>

    <div class="admin-container">
        <div class="admin-page-header">
            <div>
                <h1><i class="fas fa-chart-line"></i> Wallet Reporting</h1>
                <p>Unified view of inflows, outflows, and complete ledger</p>
            </div>
            <div class="header-summary">
                <div class="summary-pill">
                    <span class="summary-label">Pending</span>
                    <span class="summary-value" id="pendingSummary">—</span>
                </div>
                <div class="summary-pill">
                    <span class="summary-label">Inflow (30d)</span>
                    <span class="summary-value inflow" id="inflowSummary">—</span>
                </div>
                <div class="summary-pill">
                    <span class="summary-label">Outflow (30d)</span>
                    <span class="summary-value outflow" id="outflowSummary">—</span>
                </div>
            </div>
        </div>

        <div class="report-tabs">
            <button class="report-tab active" data-tab="inflows" onclick="switchTab('inflows')">
                <i class="fas fa-arrow-down"></i> Inflows
                <span class="tab-badge" id="inflowsBadge" data-zero="true">0</span>
            </button>
            <button class="report-tab" data-tab="outflows" onclick="switchTab('outflows')">
                <i class="fas fa-arrow-up"></i> Outflows
                <span class="tab-badge" id="outflowsBadge" data-zero="true">0</span>
            </button>
            <button class="report-tab" data-tab="ledger" onclick="switchTab('ledger')">
                <i class="fas fa-list"></i> Full Ledger
            </button>
        </div>

        <div class="filter-bar">
            <div class="filter-group" id="statusFilterGroup">
                <label>Status</label>
                <select id="statusFilter" onchange="applyFilters()">
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                    <option value="all">All</option>
                </select>
            </div>

            <div class="filter-group" id="userTypeFilterGroup" style="display:none;">
                <label>User Type</label>
                <select id="userTypeFilter" onchange="applyFilters()">
                    <option value="all">All</option>
                    <option value="tenant">Tenants</option>
                    <option value="client">Clients</option>
                    <option value="agent">Agents</option>
                    <option value="admin">Admins</option>
                </select>
            </div>

            <div class="filter-group grow">
                <label>Search</label>
                <input type="text" id="searchInput" placeholder="Request code, name, or account…"
                    onkeyup="debounceSearch()" />
            </div>

            <div class="filter-group">
                <label>From</label>
                <input type="date" id="dateFrom" onchange="applyFilters()" />
            </div>

            <div class="filter-group">
                <label>To</label>
                <input type="date" id="dateTo" onchange="applyFilters()" />
            </div>

            <div class="filter-group">
                <label aria-hidden="true">&nbsp;</label>
                <button class="btn btn-view" onclick="clearFilters()">
                    <i class="fas fa-times"></i> Clear
                </button>
            </div>
        </div>

        <div id="reportContent">
            <div class="admin-empty-state"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
        </div>

        <div id="pagination" class="pagination"></div>
    </div>

    <div id="adminWalletLoader" class="admin-loader-overlay" style="display:none;">
        <div class="admin-loader-spinner"></div>
    </div>

    <div id="toastContainer"></div>

    <div id="alertModal" class="ui-modal">
        <div class="ui-modal-content">
            <h3 id="alertTitle">Alert</h3>
            <p id="alertMessage"></p>
            <button id="alertOkBtn">OK</button>
        </div>
    </div>

    <div id="confirmModal" class="ui-modal">
        <div class="ui-modal-content">
            <h3 id="confirmTitle">Confirm Action</h3>
            <p id="confirmMessage"></p>
            <div class="ui-modal-buttons">
                <button id="confirmCancelBtn">Cancel</button>
                <button id="confirmOkBtn">Yes</button>
            </div>
        </div>
    </div>

    <div id="uiLoaderOverlay">
        <div class="ui-loader"></div>
        <div class="ui-loader-message">Processing…</div>
    </div>

    <script src="../../ui.js"></script>
    <script src="../scripts/wallet_requests.js"></script>
</body>

</html>