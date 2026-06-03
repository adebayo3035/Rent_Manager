
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rent Payment History | RentFlow Pro</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
     <link rel="stylesheet" href="../../styles.css">
    <link rel="stylesheet" href="../css/rent_payment_history.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
     <?php include('navbar.php'); ?>

<div class="main-content">
    <div class="page-header">
        <h1><i class="fas fa-history"></i> Rent Payment History</h1>
        <p>View and track all rent payment attempts</p>
        <a href="rent_payment.php" class="btn-outline page-back-link">
            <i class="fas fa-arrow-left"></i> Back to Rent Payments
        </a>
    </div>
    
    <!-- Filters -->
    <div class="filters-card">
        <div class="filters-grid">
            <div class="filter-group">
                <label>Search</label>
                <input type="text" id="searchInput" class="form-control" placeholder="Receipt #, Reference, Tenant...">
            </div>
            <div class="filter-group">
                <label>Status</label>
                <select id="statusFilter" class="form-control">
                    <option value="">All Status</option>
                    <option value="initiated">Initiated</option>
                    <option value="pending_verification">Pending Verification</option>
                    <option value="paid">Paid</option>
                    <option value="failed">Failed</option>
                    <option value="approved">Approved (Legacy)</option>
                    <option value="rejected">Rejected (Legacy)</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Initiated By</label>
                <select id="initiatedByFilter" class="form-control">
                    <option value="">All</option>
                    <option value="tenant">Tenant</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Period #</label>
                <input type="number" id="periodFilter" class="form-control" placeholder="Period number">
            </div>
            <div class="filter-group">
                <label>Date From</label>
                <input type="date" id="dateFromFilter" class="form-control">
            </div>
            <div class="filter-group">
                <label>Date To</label>
                <input type="date" id="dateToFilter" class="form-control">
            </div>
            <div class="filter-actions">
                <button class="btn-primary" onclick="applyFilters()">
                    <i class="fas fa-search"></i> Apply
                </button>
                <button class="btn-secondary" onclick="resetFilters()">
                    <i class="fas fa-undo"></i> Reset
                </button>
            </div>
        </div>
    </div>
    
    <!-- Table -->
    <div class="table-container">
        <div class="table-header">
            <div class="table-title">Payment History</div>
            <div class="table-actions">
                <select id="limitSelect" class="form-control" onchange="changeLimit()">
                    <option value="20">20 per page</option>
                    <option value="50">50 per page</option>
                    <option value="100">100 per page</option>
                </select>
                <button class="btn-outline" onclick="exportData()">
                    <i class="fas fa-download"></i> Export
                </button>
            </div>
        </div>
        
        <div id="loadingIndicator" class="loading-overlay" style="display: none;">
            <div class="spinner"></div>
            <p>Loading...</p>
        </div>
        
        <div class="table-responsive">
            <table class="data-table" id="historyTable">
                <thead>
                    <tr>
                        <th onclick="sortTable('initiated_at')">Date <i class="fas fa-sort"></i></th>
                        <th>Receipt #</th>
                        <th>Tenant</th>
                        <th onclick="sortTable('period_number')">Period <i class="fas fa-sort"></i></th>
                        <th onclick="sortTable('amount')">Amount <i class="fas fa-sort"></i></th>
                        <th onclick="sortTable('attempt_number')">Attempt <i class="fas fa-sort"></i></th>
                        <th>Initiated By</th>
                        <th onclick="sortTable('status')">Status <i class="fas fa-sort"></i></th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <tr><td colspan="9" class="text-center">Loading...</td></tr>
                </tbody>
            </table>
        </div>
        
        <div id="pagination" class="pagination-container"></div>
    </div>
</div>

<!-- View Details Modal -->
<div id="detailsModal" class="modal">
    <div class="modal-content" style="max-width: 800px;">
        <div class="modal-header">
            <h3><i class="fas fa-info-circle"></i> Payment Details</h3>
            <button class="modal-close" onclick="closeModal('detailsModal')">&times;</button>
        </div>
        <div class="modal-body" id="detailsBody">
            <!-- Dynamic content -->
        </div>
        <div class="modal-footer">
            <button class="btn-secondary" onclick="closeModal('detailsModal')">Close</button>
        </div>
    </div>
</div>

<script src="../scripts/rent_payment_history.js"></script>
</body>
</html>
