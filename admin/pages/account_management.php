<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Reactivation Management</title>
    <link rel="stylesheet" href="../../styles.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style>
        /* Additional styles for reactivation management */
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }
        
        .status-pending {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        
        .status-approved {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .status-rejected {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .status-expired {
            background-color: #e2e3e5;
            color: #383d41;
            border: 1px solid #d6d8db;
        }
        
        .user-type-badge {
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .user-type-admin {
            background-color: #d1ecf1;
            color: #0c5460;
        }
        
        .user-type-agent {
            background-color: #cce5ff;
            color: #004085;
        }
        
        .user-type-client {
            background-color: #d4edda;
            color: #155724;
        }
        
        .user-type-tenant {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
            justify-content: center;
        }
        
        .action-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .action-approve {
            background-color: #28a745;
            color: white;
        }
        
        .action-approve:hover {
            background-color: #218838;
        }
        
        .action-reject {
            background-color: #dc3545;
            color: white;
        }
        
        .action-reject:hover {
            background-color: #c82333;
        }
        
        .action-view {
            background-color: #17a2b8;
            color: white;
        }
        
        .action-view:hover {
            background-color: #138496;
        }
        
        .filters-container {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #dee2e6;
        }
        
        .filters-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-left: 4px solid #007bff;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #007bff;
        }
        
        .stat-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            margin-top: 5px;
        }
        
        .request-details-modal .modal-content {
            max-width: 800px;
            height: fit-content;
            overflow-y:scroll;
        }
        
        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        
        .detail-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            border-left: 4px solid #007bff;
        }
        
        .detail-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 5px;
            font-size: 13px;
        }
        
        .detail-value {
            color: #212529;
            font-size: 14px;
        }
        
        .review-section {
            background: #e9ecef;
            padding: 20px;
            border-radius: 6px;
            margin-top: 20px;
        }
        
        .reason-text {
            white-space: pre-wrap;
            background: white;
            padding: 15px;
            border-radius: 6px;
            border: 1px solid #dee2e6;
            max-height: 200px;
            overflow-y: auto;
        }
        
        @media (max-width: 768px) {
            .filters-row {
                grid-template-columns: 1fr;
            }
            
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>

<body>
    <?php include('navbar.php'); ?>

    <div class="container">
        <h1><i class="fas fa-user-check"></i> Account Reactivation Management</h1>
        
        <!-- Statistics Cards -->
        <div class="stats-container" id="reactivationStats">
            <!-- Stats will be loaded dynamically -->
            <div class="stat-card">
                <div class="stat-value">0</div>
                <div class="stat-label">Total Requests</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">0</div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">0</div>
                <div class="stat-label">Approved</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">0</div>
                <div class="stat-label">Rejected</div>
            </div>
        </div>

        <!-- Filters Section -->
        <div class="filters-container">
            <h3><i class="fas fa-filter"></i> Filters</h3>
            <div class="filters-row">
                <div class="form-group">
                    <label for="filterUserType">User Type</label>
                    <select id="filterUserType" class="select2">
                        <option value="">All Types</option>
                        <option value="admin">Admin</option>
                        <option value="agent">Agent</option>
                        <option value="client">Client</option>
                        <option value="tenant">Tenant</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="filterStatus">Status</label>
                    <select id="filterStatus" class="select2">
                        <option value="">All Statuses</option>
                        <option value="pending">Pending</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                        <option value="expired">Expired</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="filterDateFrom">Date From</label>
                    <input type="date" id="filterDateFrom" class="form-control">
                </div>
                
                <div class="form-group">
                    <label for="filterDateTo">Date To</label>
                    <input type="date" id="filterDateTo" class="form-control">
                </div>
            </div>
            
            <div class="filters-row">
                <div class="form-group" style="grid-column: span 2;">
                    <label for="filterSearch">Search (Email or User ID)</label>
                    <input type="text" id="filterSearch" class="form-control" placeholder="Search by email or user ID...">
                </div>
                
                <div class="form-group" style="display: flex; flex-direction: column; align-content: space-between; justify-content: space-between; ">
                    <button id="applyFilters" class="btn btn-primary">
                        <i class="fas fa-search"></i> Apply Filters
                    </button>
                    <span> </span>
                    <button id="resetFilters" class="btn btn-secondary" style="margin-left: 10px;">
                        <i class="fas fa-redo"></i> Reset
                    </button>
                </div>
            </div>
        </div>

        <!-- Table Section -->
        <div class="table-responsive">
            <table id="reactivationSummary" class="summaryTable">
                <thead>
                    <tr>
                        <th>Request ID</th>
                        <th>User</th>
                        <th>User Type</th>
                        <th>Email</th>
                        <th>Request Date</th>
                        <th>Status</th>
                        <th>Review Date</th>
                        <th>Reviewed By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="reactivationSummaryBody" class="summaryTableBody">
                    <!-- Data loads dynamically -->
                </tbody>
            </table>
        </div>

        <div id="reactivationPagination" class="pagination"></div>
    </div>

    <!-- View/Edit Modal -->
    <div id="requestDetailsModal" class="modal request-details-modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Reactivation Request Details</h2>
            
            <div class="details-grid">
                <div class="detail-item">
                    <div class="detail-label">Request ID</div>
                    <div class="detail-value" id="detailRequestId">-</div>
                </div>
                
                <div class="detail-item">
                    <div class="detail-label">User Type</div>
                    <div class="detail-value">
                        <span id="detailUserType" class="user-type-badge user-type-admin">-</span>
                    </div>
                </div>
                
                <div class="detail-item">
                    <div class="detail-label">User Name</div>
                    <div class="detail-value" id="detailUserName">-</div>
                </div>
                
                <div class="detail-item">
                    <div class="detail-label">Email</div>
                    <div class="detail-value" id="detailEmail">-</div>
                </div>
                
                <div class="detail-item">
                    <div class="detail-label">Phone</div>
                    <div class="detail-value" id="detailPhone">-</div>
                </div>
                
                <div class="detail-item">
                    <div class="detail-label">Current Account Status</div>
                    <div class="detail-value">
                        <span id="detailCurrentStatus" class="status-badge">-</span>
                    </div>
                </div>
                
                <div class="detail-item">
                    <div class="detail-label">Request Status</div>
                    <div class="detail-value">
                        <span id="detailRequestStatus" class="status-badge">-</span>
                    </div>
                </div>
                
                <div class="detail-item">
                    <div class="detail-label">Request Date</div>
                    <div class="detail-value" id="detailRequestDate">-</div>
                </div>
                
                <div class="detail-item">
                    <div class="detail-label">Review Date</div>
                    <div class="detail-value" id="detailReviewDate">-</div>
                </div>
                
                <div class="detail-item">
                    <div class="detail-label">Reviewed By</div>
                    <div class="detail-value" id="detailReviewedBy">-</div>
                </div>
                
                <div class="detail-item" style="grid-column: span 2;">
                    <div class="detail-label">Rejection Reason</div>
                    <div class="detail-value" id="detailRejectionReason">-</div>
                </div>
            </div>
            
            <div style="margin: 20px 0;">
                <div class="detail-label">Reason for Reactivation</div>
                <div class="reason-text" id="detailReason"></div>
            </div>
            
            <div style="margin: 20px 0;">
                <div class="detail-label">Review Notes (Internal)</div>
                <div class="reason-text" id="detailReviewNotes">-</div>
            </div>
            
            <div class="action-buttons" style="justify-content: flex-end; margin-top: 20px;">
                <button id="closeDetailsBtn" class="action-btn action-view">Close</button>
            </div>
        </div>
    </div>

    <!-- Review Modal -->
    <div id="reviewModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Review Reactivation Request</h2>
            
            <div class="form-group">
                <label for="reviewAction">Action</label>
                <select id="reviewAction" class="form-control">
                    <option value="">Select Action</option>
                    <option value="approve">Approve</option>
                    <option value="reject">Reject</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="reviewNotes">Internal Notes (Optional)</label>
                <textarea id="reviewNotes" class="form-control" rows="3" 
                         placeholder="Add internal notes for reference..."></textarea>
            </div>
            
            <div class="form-group" id="rejectionReasonGroup" style="display: none;">
                <label for="rejectionReason">Rejection Reason (Visible to User)</label>
                <textarea id="rejectionReason" class="form-control" rows="3" 
                         placeholder="Please provide a clear reason for rejection..."></textarea>
                <small class="form-text text-muted">This message will be sent to the user.</small>
            </div>
            
            <div class="form-group">
                <div id="reviewMessage" class="alert" style="display: none;"></div>
            </div>
            
            <div class="action-buttons" style="justify-content: flex-end; margin-top: 20px;">
                <button id="cancelReviewBtn" class="action-btn action-reject">Cancel</button>
                <button id="submitReviewBtn" class="action-btn action-approve">Submit Review</button>
            </div>
            
            <input type="hidden" id="reviewRequestId">
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

    <script src="../scripts/account_management.js"></script>
    <!-- <script src="../scripts/main.js"></script> -->
    <script src="../../ui.js"></script>

</body>

</html>