<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settlement Tracking - Rent Pilot</title>
     <link rel="stylesheet" href="../../ui.css">
    <link rel="stylesheet" href="../css/settlement.css">
    <script src="https://kit.fontawesome.com/7cab3097e7.js" crossorigin="anonymous"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <?php include_once __DIR__ . '/navbar.php'; ?>
    
    <div class="settlements-container">
        <!-- Page Header -->
        <div class="page-header">
            <div class="header-left">
                <h1><i class="fas fa-hand-holding-usd"></i> Settlement Tracking</h1>
                <p class="subtitle">Track and manage revenue settlements across all properties</p>
            </div>
            <div class="header-right">
                <button class="btn btn-outline" onclick="exportReport()">
                    <i class="fas fa-file-download"></i> Export
                </button>
                <button class="btn btn-primary" onclick="refreshData()">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
        </div>
        
        <!-- Summary Cards -->
        <div class="summary-cards" id="summaryCards">
            <div class="summary-card total">
                <div class="card-icon"><i class="fas fa-receipt"></i></div>
                <div class="card-info">
                    <span class="card-label">Total Settlements</span>
                    <span class="card-value" id="totalCount">-</span>
                    <span class="card-sub" id="totalAmount">₦0</span>
                </div>
            </div>
            <div class="summary-card completed">
                <div class="card-icon"><i class="fas fa-check-circle"></i></div>
                <div class="card-info">
                    <span class="card-label">Completed</span>
                    <span class="card-value" id="completedCount">-</span>
                    <span class="card-sub" id="completedAmount">₦0</span>
                </div>
            </div>
            <div class="summary-card pending">
                <div class="card-icon"><i class="fas fa-clock"></i></div>
                <div class="card-info">
                    <span class="card-label">Pending</span>
                    <span class="card-value" id="pendingCount">-</span>
                    <span class="card-sub" id="pendingAmount">₦0</span>
                </div>
            </div>
            <div class="summary-card user-share">
                <div class="card-icon"><i class="fas fa-user-check"></i></div>
                <div class="card-info">
                    <span class="card-label">Your Total Share</span>
                    <span class="card-value" id="userShareTotal">₦0</span>
                    <span class="card-sub" id="userTotal">Total: ₦0</span>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="filters-bar">
            <div class="filter-group">
                <label>Status</label>
                <select id="filterStatus" class="filter-select">
                    <option value="">All Statuses</option>
                    <option value="pending">Pending</option>
                    <option value="completed">Completed</option>
                    <option value="failed">Failed</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Payable To</label>
                <select id="filterPayable" class="filter-select">
                    <option value="">All Parties</option>
                    <option value="admin">Admin</option>
                    <option value="client">Client</option>
                    <option value="agent">Agent</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Date From</label>
                <input type="date" id="filterDateFrom" class="filter-input">
            </div>
            <div class="filter-group">
                <label>Date To</label>
                <input type="date" id="filterDateTo" class="filter-input">
            </div>
            <div class="filter-group search-group">
                <label>Search</label>
                <div class="search-wrapper">
                    <i class="fas fa-search"></i>
                    <input type="text" id="filterSearch" placeholder="Property, tenant..." class="filter-input">
                </div>
            </div>
            <div class="filter-actions">
                <button class="btn btn-secondary" onclick="applyFilters()">Apply</button>
                <button class="btn btn-outline" onclick="clearFilters()">Clear</button>
            </div>
        </div>
        
        <!-- Table -->
        <div class="table-wrapper">
            <div class="table-header">
                <span class="table-title">Settlement Records</span>
                <span class="table-count" id="recordCount">0 records</span>
            </div>
            <div class="table-responsive">
                <table class="settlements-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Property</th>
                            <th>Tenant</th>
                            <th>Period</th>
                            <th>Total Amount</th>
                            <th>Your Share</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th style="width: 80px;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="settlementsBody">
                        <tr>
                            <td colspan="9" class="loading-cell">
                                <div class="spinner"></div>
                                Loading settlements...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <!-- Pagination -->
            <div class="table-footer">
                <div class="pagination-info">
                    Showing <span id="showingStart">0</span> to <span id="showingEnd">0</span> of <span id="totalRecords">0</span> records
                </div>
                <div class="pagination-controls" id="paginationControls">
                    <button class="btn-page" id="prevPage" onclick="changePage('prev')" disabled>
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <span class="page-info" id="pageInfo">Page 1 of 1</span>
                    <button class="btn-page" id="nextPage" onclick="changePage('next')" disabled>
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Settlement Details Modal -->
    <div id="settlementModal" class="modal" style="display:none;">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h3><i class="fas fa-info-circle"></i> Settlement Details</h3>
                <button class="modal-close" onclick="closeSettlementModal()">&times;</button>
            </div>
            <div class="modal-body" id="settlementDetails">
                <div class="loading-spinner">
                    <div class="spinner"></div>
                    Loading details...
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeSettlementModal()">Close</button>
                <button class="btn btn-primary" onclick="printSettlement()">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>
    </div>
    
    <!-- Toast Container -->
    <div id="toastContainer"></div>

    <!-- UI Framework Containers -->
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
    <script src="../scripts/settlement.js"></script>
</body>
</html>