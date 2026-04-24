
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Evacuation Requests | RentFlow Pro</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/evacuation_requests.css">
</head>
<body>
    <?php include('navbar.php'); ?>
    
    <div class="container">
        <div class="page-header">
            <h1><i class="fas fa-sign-out-alt"></i> Evacuation Requests</h1>
            <div class="stats-row" id="statsContainer">
                <div class="stat-card">
                    <div class="stat-value" id="pendingCount">0</div>
                    <div class="stat-label">Pending Review</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" id="approvedCount">0</div>
                    <div class="stat-label">Approved</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" id="completedCount">0</div>
                    <div class="stat-label">Completed</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" id="rejectedCount">0</div>
                    <div class="stat-label">Rejected</div>
                </div>
            </div>
            
            <div class="tabs">
                <button class="tab-btn active" data-status="pending_review">Pending Review</button>
                <button class="tab-btn" data-status="approved">Approved</button>
                <button class="tab-btn" data-status="rejected">Rejected</button>
                <button class="tab-btn" data-status="completed">Completed</button>
            </div>
        </div>
        
        <div id="requestsContainer" class="requests-container">
            <div class="loading-state">
                <div class="spinner"></div>
                <p>Loading requests...</p>
            </div>
        </div>
    </div>

    <!-- Review Modal -->
    <div id="reviewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-clipboard-list"></i> Review Evacuation Request</h3>
                <button class="modal-close" onclick="EvacuationApp.closeReviewModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="request-info" id="reviewRequestInfo"></div>
                <div class="form-group">
                    <label>Approved Move-out Date *</label>
                    <input type="date" class="form-input" id="approvedMoveOutDate" required>
                </div>
                <div class="form-group">
                    <label>Admin Notes (Optional)</label>
                    <textarea class="form-textarea" id="reviewNotes" rows="3" placeholder="Add any notes about this decision..."></textarea>
                </div>
                <div class="form-group" id="rejectionReasonGroup" style="display: none;">
                    <label>Rejection Reason *</label>
                    <textarea class="form-textarea" id="rejectionReason" rows="3" placeholder="Please provide a reason for rejection..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-danger" id="rejectBtn" onclick="EvacuationApp.toggleRejectForm()">Reject</button>
                <button class="btn btn-primary" onclick="EvacuationApp.submitReview('approve')">Approve Request</button>
            </div>
        </div>
    </div>

    <!-- Process Modal -->
    <div id="processModal" class="modal">
        <div class="modal-content" style="max-width: 650px;">
            <div class="modal-header">
                <h3><i class="fas fa-check-circle"></i> Process Move-out</h3>
                <button class="modal-close" onclick="EvacuationApp.closeProcessModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="request-info" id="processRequestInfo"></div>
                
                <div class="form-group">
                    <label>Actual Move-out Date *</label>
                    <input type="date" class="form-input" id="actualMoveOutDate" required>
                </div>
                
                <div class="form-group">
                    <label>Security Deposit Deductions</label>
                    <div id="deductionsContainer"></div>
                    <button type="button" class="btn-add-deduction" onclick="EvacuationApp.addDeductionRow()">
                        <i class="fas fa-plus"></i> Add Deduction
                    </button>
                </div>
                
                <div class="summary-box" id="settlementSummary">
                    <!-- Settlement summary will be populated by JS -->
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="EvacuationApp.closeProcessModal()">Cancel</button>
                <button class="btn btn-success" onclick="EvacuationApp.submitProcess()">Complete Evacuation</button>
            </div>
        </div>
    </div>

    <!-- View Details Modal -->
    <div id="detailsModal" class="modal">
        <div class="modal-content" style="max-width: 550px;">
            <div class="modal-header">
                <h3><i class="fas fa-info-circle"></i> Evacuation Request Details</h3>
                <button class="modal-close" onclick="EvacuationApp.closeDetailsModal()">&times;</button>
            </div>
            <div class="modal-body" id="detailsBody">
                <!-- Details will be populated by JS -->
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="EvacuationApp.closeDetailsModal()">Close</button>
            </div>
        </div>
    </div>

    <script src="../scripts/evacuation_requests.js"></script>
</body>
</html>