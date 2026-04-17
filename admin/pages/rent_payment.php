<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rent Payment Management | RentFlow Pro</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
                <input type="text" id="searchInput" placeholder="Search tenant..." class="btn-outline" style="padding: 8px 16px;">
                <select id="statusFilter" class="btn-outline">
                    <option value="">All Status</option>
                    <option value="paid">Paid</option>
                    <option value="failed">Failed</option>
                </select>
                <button class="btn btn-outline" onclick="applyFilters()">
                    <i class="fas fa-search"></i> Search
                </button>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-grid" id="statsContainer">
            <div class="loading"><div class="spinner"></div></div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab-btn active" onclick="switchTab('pending')">
                <i class="fas fa-clock"></i> Pending Verification
            </button>
            <button class="tab-btn" onclick="switchTab('history')">
                <i class="fas fa-history"></i> Payment History
            </button>
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
                            <tr><td colspan="8" class="loading"><div class="spinner"></div></td></tr>
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
                            <tr><td colspan="9" class="loading"><div class="spinner"></div></td</tr>
                        </tbody>
                    </table>
                </div>
                <div class="pagination" id="historyPagination"></div>
            </div>
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
                    <textarea id="verifyNotes" rows="3" placeholder="Add any notes about this verification..."></textarea>
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

    <script src = "../scripts/rent_payment.js">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
        
    </script>
</body>
</html>