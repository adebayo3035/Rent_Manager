<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Management | RentFlow Pro</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
     <link rel="stylesheet" href="../../ui.css">
    <link rel="stylesheet" href="../css/payment.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <?php include ('navbar.php'); ?>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1><i class="fas fa-money-bill-wave"></i> Payment Management</h1>
            <div class="quick-actions">
                <button class="btn btn-primary" onclick="openQuickFeePaymentModal()">
                    <i class="fas fa-bolt"></i> Quick Fee Payment
                </button>
                <button class="btn btn-primary" onclick="openInitiatePaymentModal()">
                    <i class="fas fa-plus"></i> Record Payment
                </button>
                <button class="btn btn-success" onclick="openCreatePaymentModal()">
                    <i class="fas fa-file-invoice-dollar"></i> Create Invoice
                </button>
                <button class="btn btn-outline" onclick="exportPayments()">
                    <i class="fas fa-download"></i> Export
                </button>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters">
            <div class="filter-grid" id="filtersContainer">
                <!-- Will be populated by JavaScript -->
            </div>
            <div class="filter-actions">
                <button class="btn btn-primary" onclick="applyFilters()">
                    <i class="fas fa-filter"></i> Apply Filters
                </button>
                <button class="btn btn-outline" onclick="resetFilters()">
                    <i class="fas fa-redo"></i> Reset
                </button>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-grid" id="statsContainer">
            <!-- Will be populated by JavaScript -->
        </div>

        <!-- Main Content -->
        <div class="content-grid">
            <div class="main-content">
                <!-- Payments Table -->
                <div class="table-container">
                    <div class="table-header">
                        <h3>Recent Payments</h3>
                        <div>
                            <select id="limitSelect" class="form-control" onchange="loadPayments()">
                                <option value="10">10 per page</option>
                                <option value="25">25 per page</option>
                                <option value="50">50 per page</option>
                                <option value="100">100 per page</option>
                            </select>
                        </div>
                    </div>
                        <div class="table-wrapper">
                            <table class="data-table" id="paymentsTable">
                                <thead>
                                    <tr>
                                        <th>Receipt #</th>
                                        <th>Tenant</th>
                                        <th>Property/Apartment</th>
                                        <th>Amount</th>
                                        <th>Date</th>
                                        <th>Method</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="paymentsTableBody">
                                    <!-- Will be populated by JavaScript -->
                                </tbody>
                            </table>
                        </div>
                        <div class="pagination" id="paginationContainer">
                            <!-- Will be populated by JavaScript -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <div id="quickFeePaymentModal" class="modal">
        <div class="modal-content modal-content-sm">
            <div class="modal-header">
                <h2>Quick Fee Payment</h2>
                <button class="action-btn" onclick="closeQuickFeePaymentModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="quickPaymentForm" onsubmit="quickRecordPayment(event)">
                    <div class="quick-payment-grid">
                        <div class="form-group form-group-wide">
                            <label>Tenant</label>
                            <select class="form-control" id="quickTenant" required>
                                <!-- Will be populated -->
                            </select>
                        </div>
                        <div class="form-group form-group-wide">
                            <label>Fee Type</label>
                            <select class="form-control" id="quickFeeType" required>
                                <!-- Will be populated -->
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Amount (NGN)</label>
                            <input type="number" class="form-control" id="quickAmount" step="0.01" required disabled>
                        </div>
                        <div class="form-group">
                            <label>Due Date</label>
                            <input type="date" class="form-control" id="quickDueDate" required disabled>
                        </div>
                        <div class="form-group">
                            <label>Payment Method</label>
                            <select class="form-control" id="quickMethod" required>
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cheque">Cheque Book</option>
                                <option value="mobile_money">Mobile Money</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Transaction Reference</label>
                            <input type="text" class="form-control" id="quickTransactionReference" required disabled>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeQuickFeePaymentModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" form="quickPaymentForm">
                    <i class="fas fa-check"></i> Record Payment
                </button>
            </div>
        </div>
    </div>

    <!-- Initiate Rent Payment Modal -->
<div id="initiateRentPaymentModal" class="modal">
    <div class="modal-content" style="max-width: 550px; max-height: 90vh; display: flex; flex-direction: column;">
        <div class="modal-header">
            <h2><i class="fas fa-money-bill-wave"></i> Initiate Rent Payment for Tenant</h2>
            <button class="modal-close action-btn" onclick="closeInitiatePaymentModal()">&times;</button>
        </div>
        
        <div class="modal-body" style="flex: 1; overflow-y: auto; padding: 20px;">
            <!-- Step 1: Select Tenant -->
            <div id="adminStep1">
                <div class="form-section">
                    <label class="form-label">Select Tenant *</label>
                    <select id="adminTenantSelect" class="form-control" style="width: 100%; padding: 10px; border-radius: 8px;">
                        <option value="">-- Select Tenant --</option>
                    </select>
                </div>
                
                <!-- Payment Summary (hidden until tenant selected) -->
                <div id="adminPaymentSummary" style="display: none; margin-top: 20px;">
                    <div class="payment-summary" style="background: #f8fafc; padding: 15px; border-radius: 8px;">
                        <h4 style="margin: 0 0 10px 0; font-size: 14px;">Payment Summary</h4>
                        <div class="summary-row" style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                            <span>Tenant:</span>
                            <span id="adminSummaryTenant" style="font-weight: 600;">-</span>
                        </div>
                        <div class="summary-row" style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                            <span>Property:</span>
                            <span id="adminSummaryProperty" style="font-weight: 600;">-</span>
                        </div>
                        <div class="summary-row" style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                            <span>Apartment:</span>
                            <span id="adminSummaryApartment" style="font-weight: 600;">-</span>
                        </div>
                        <div class="summary-row" style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                            <span>Payment Status:</span>
                            <span id="adminSummaryStatus" style="font-weight: 600;">-</span>
                        </div>
                        <div class="summary-row" style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                            <span>Payment Period:</span>
                            <span id="adminSummaryPeriod" style="font-weight: 600;">-</span>
                        </div>
                        <div class="summary-row" style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                            <span>Start/End Date:</span>
                            <span id="adminSummaryDate" style="font-weight: 600;">-</span>
                        </div>
                        <div class="summary-row" style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                            <span>Amount Due:</span>
                            <span id="adminSummaryAmount" style="font-weight: 600; color: #1e3c72;">-</span>
                        </div>
                        <div class="summary-row" style="display: flex; justify-content: space-between;">
                            <span>Due Date:</span>
                            <span id="adminSummaryDueDate" style="font-weight: 600;">-</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 2: OTP Authorization -->
            <div id="adminStep2" style="display: none; margin-top: 20px;">
                <div class="form-section">
                    <div class="alert-info" style="background: #dbeafe; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
                        <i class="fas fa-info-circle"></i>
                        <span id ="OTPNotifier">Click the Button below to send an OTP to tenant's email. Please ask the tenant for the code.</span>
                    </div>
                    
                    <button type="button" id="sendOtpBtn" class="btn-primary" onclick="sendPaymentOtp()">
                        <i class="fas fa-envelope"></i> Send OTP to Tenant
                    </button>
                    
                    <div id="otpSection" style="display: none;">
                        <div class="form-group">
                            <label class="form-label">Enter OTP Code *</label>
                            <input type="text" id="otpCode" class="form-control" placeholder="Enter 6-digit code" maxlength="6" autocomplete="off" style="width: 100%; padding: 10px; border-radius: 8px; text-align: center; font-size: 18px; letter-spacing: 4px;">
                            <small style="color: #666; display: block; margin-top: 5px;">OTP expires in 2 minutes</small>
                        </div>
                        
                        <button type="button" id="verifyOtpBtn" class="btn-primary" style="width: 100%;" onclick="verifyPaymentOtp()">
                            <i class="fas fa-check-circle"></i> Verify OTP
                        </button>
                    </div>
                </div>
            </div>

            <!-- Step 3: Process Payment -->
            <div id="adminStep3" style="display: none; margin-top: 20px;">
                <div class="form-section">
                    <div class="alert-success" style="background: #d1fae5; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
                        <i class="fas fa-check-circle"></i>
                        <span>OTP verified successfully! You can now process the payment.</span>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Additional Notes (Optional)</label>
                        <textarea id="adminPaymentNotes" class="form-control" rows="3" placeholder="Any notes about this payment..." style="width: 100%; padding: 10px; border-radius: 8px;"></textarea>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px; padding: 15px 20px; border-top: 1px solid #eef2f7;">
            <button class="btn-secondary" onclick="closeInitiatePaymentModal()">Cancel</button>
            <button id="processPaymentBtn" class="btn-primary" style="display: none;" onclick="processAdminRentPayment()">
                <i class="fas fa-check"></i> Process Payment
            </button>
        </div>
    </div>
</div>

<!-- Processing Modal -->
<div id="adminProcessingModal" class="modal">
    <div class="modal-content" style="max-width: 400px; text-align: center;">
        <div class="modal-header">
            <h3>Processing Payment</h3>
        </div>
        <div class="modal-body" style="padding: 30px;">
            <div style="margin-bottom: 20px;">
                <i class="fas fa-spinner fa-spin" style="font-size: 48px; color: #1e3c72;"></i>
            </div>
            <p id="adminProcessingMessage">Please wait while we process the payment...</p>
            <p style="font-size: 12px; color: #666; margin-top: 15px;">Do not close this window</p>
        </div>
    </div>
</div>
    <!-- View Payment Modal -->
    <div id="viewPaymentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Payment Details</h2>
                <button class="action-btn" onclick="closeViewPaymentModal()">&times;</button>
            </div>
            <div class="modal-body" id="paymentDetails">
                <!-- Populated by JS -->
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary" onclick="printInvoice()">
                    <i class="fas fa-print"></i> Print Invoice
                </button>
                <button class="btn btn-outline" onclick="closeViewPaymentModal()">Close</button>
            </div>
        </div>
    </div>

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
    <script src="../../validator.js"></script>
    <script src = "../scripts/payment.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
</body>
</html>
