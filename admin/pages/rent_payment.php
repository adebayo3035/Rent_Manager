<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rent Payment Management | RentFlow Pro</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../styles.css">
    <link rel="stylesheet" href="../css/rent_payment.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body>
    <?php include('navbar.php'); ?>

    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1><i class="fas fa-money-bill-wave"></i> Rent Payment Management</h1>
            <div class="filter-group">
                <input type="text" id="searchInput" placeholder="Search tenant..." class="btn-outline"
                    style="padding: 8px 16px;">
                <select id="statusFilter" class="btn-outline">
                    <option value="">All Status</option>
                    <option value="paid">Paid</option>
                    <option value="failed">Failed</option>
                </select>
                <button class="btn btn-outline" id="searchBtn" onclick="applyFilters()">
                    <i class="fas fa-search"></i> Search
                </button>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-grid" id="statsContainer">
            <div class="loading">
                <div class="spinner"></div>
            </div>
        </div>

        <!-- Tabs -->
        <!-- Tabs -->
        <div class="tabs">
            <button class="tab-btn active" data-tab="pending" onclick="switchTab('pending')">
                <i class="fas fa-clock"></i> Pending Verification
            </button>
            <button class="tab-btn" data-tab="history" onclick="switchTab('history')">
                <i class="fas fa-history"></i> Payment History
            </button>
            <button class="tab-btn" data-tab="outstanding" onclick="switchTab('outstanding')">
                <i class="fas fa-exclamation-triangle"></i> Outstanding Rent Payment
            </button>
            <a href="rent_payment_history.php" class="tab-btn tab-link">
                <i class="fas fa-history"></i> Detailed Rent History
            </a>
        </div>

        <!-- Pending Verifications Table -->
        <div id="pendingTab" class="tab-content">
            <div class="table-container">
                <div class="table-header">
                    <h3><i class="fas fa-hourglass-half"></i> Pending Rent Payments</h3>
                    <span class="badge badge-warning" id="pendingCount">0 pending</span>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Tenant</th>
                                <th>Property</th>
                                <th>Period</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Reference</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="pendingTableBody">
                            <tr>
                                <td colspan="8" class="loading">
                                    <div class="spinner"></div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Payment History Table -->
        <div id="historyTab" class="tab-content" style="display: none;">
            <div class="table-container">
                <div class="table-header">
                    <h3><i class="fas fa-receipt"></i> Rent Payment History</h3>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Payment Date</th>
                                <th>Tenant</th>
                                <th>Property</th>
                                <th>Period #</th>
                                <th>Period Range</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Status</th>
                                <th>Verified</th>
                            </tr>
                        </thead>
                        <tbody id="historyTableBody">
                            <tr>
                                <td colspan="9" class="loading">
                                    <div class="spinner"></div>
                                    </td< /tr>
                        </tbody>
                    </table>
                </div>
                <div class="pagination" id="historyPagination"></div>
            </div>
        </div>
    </div>

    <!-- Outstanding Payment History Table -->
    <div id="outstandingTab" class="tab-content" style="display: none;">
        <div class="table-container">
            <div class="table-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Outstanding Rent Payments</h3>
                <div class="header-controls" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                    <span class="badge badge-warning" id="outstandingCount">0 outstanding</span>
                    <select id="outstandingFilter" class="btn-outline" onchange="applyOutstandingFilters()"
                        style="padding: 6px 12px;">
                        <option value="all">All Outstanding</option>
                        <option value="overdue">Overdue Only</option>
                        <option value="upcoming">Upcoming</option>
                    </select>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="summary-cards" id="outstandingSummary"
                style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 20px;">
                <div class="stat-card" style="background: #fef3c7;">
                    <div class="stat-icon" style="background: #f59e0b;"><i class="fas fa-clock"></i></div>
                    <div class="stat-info">
                        <h3>At Risk (1-7 days)</h3>
                        <p class="stat-number" id="atRiskAmount">₦0.00</p>
                        <small id="atRiskCount">0 payments</small>
                    </div>
                </div>
                <div class="stat-card" style="background: #fee2e2;">
                    <div class="stat-icon" style="background: #ef4444;"><i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Overdue (8-30 days)</h3>
                        <p class="stat-number" id="overdueAmount">₦0.00</p>
                        <small id="overdueCount">0 payments</small>
                    </div>
                </div>
                <div class="stat-card" style="background: #fecaca;">
                    <div class="stat-icon" style="background: #dc2626;"><i class="fas fa-exclamation-circle"></i></div>
                    <div class="stat-info">
                        <h3>Critical (30+ days)</h3>
                        <p class="stat-number" id="criticalAmount">₦0.00</p>
                        <small id="criticalCount">0 payments</small>
                    </div>
                </div>
                <div class="stat-card" style="background: #dbeafe;">
                    <div class="stat-icon" style="background: #3b82f6;"><i class="fas fa-users"></i></div>
                    <div class="stat-info">
                        <h3>Tenants Affected</h3>
                        <p class="stat-number" id="tenantsAffected">0</p>
                        <small>with outstanding balance</small>
                    </div>
                </div>
            </div>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Tenant</th>
                            <th>Property / Apartment</th>
                            <th>Period</th>
                            <th>Rent Amount</th>
                            <th>Amount Paid</th>
                            <th>Outstanding</th>
                            <th>Due Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="outstandingTableBody">
                        <tr>
                            <td colspan="9" class="loading">
                                <div class="spinner"></div> Loading...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="pagination" id="outstandingPagination"></div>
        </div>
    </div>

    <!-- Verify Modal -->
    <div id="verifyModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Verify Rent Payment</h3>
                <button class="modal-close" onclick="closeVerifyModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="verifyDetails"></div>
                <div class="form-group">
                    <label>Admin Notes (Optional)</label>
                    <textarea id="verifyNotes" rows="3"
                        placeholder="Add any notes about this verification..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-danger" onclick="processVerification('reject')">
                    <i class="fas fa-times"></i> Reject
                </button>
                <button class="btn btn-success" onclick="processVerification('approve')">
                    <i class="fas fa-check"></i> Approve Payment
                </button>
            </div>
        </div>
    </div>

    <!-- UI Library -->
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
    </div>
    <script src="../scripts/main.js"></script>
    <script src="../../ui.js"></script>
    <script src="../../validator.js"></script>

    <script src="../scripts/rent_payment.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
</body>

</html>