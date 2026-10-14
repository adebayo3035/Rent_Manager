<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Tenant Debts - Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://kit.fontawesome.com/7cab3097e7.js" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="../../styles.css">
    <link rel="stylesheet" href="../css/tenant_debts.css">
</head>

<body>
    <?php include 'navbar.php'; ?>

    <div class="admin-container">
        <div class="admin-page-header">
            <h1><i class="fas fa-hand-holding-usd"></i> Tenant Debts</h1>
            <p>Track and recover outstanding tenant balances</p>
        </div>

        <!-- Stats row -->
        <div class="debt-stats-row">
            <div class="stat-card">
                <div class="stat-value" id="statOutstanding">₦0.00</div>
                <div class="stat-label">Outstanding Total</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" id="statUnpaid">0</div>
                <div class="stat-label">Unpaid</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" id="statPartial">0</div>
                <div class="stat-label">Partially Paid</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" id="statResolved">0</div>
                <div class="stat-label">Fully Recovered</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="debt-filters">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="debtSearch" placeholder="Search by tenant name, code, or debt code..."
                    oninput="debounceSearch()">
            </div>
            <select id="debtStatus" onchange="loadDebts()">
                <option value="all">All Statuses</option>
                <option value="unpaid" selected>Unpaid</option>
                <option value="partial">Partially Paid</option>
                <option value="resolved">Resolved</option>
                <option value="written_off">Written Off</option>
                <option value="disputed">Disputed</option>
            </select>
        </div>

        <!-- List -->
        <div id="debtsList">
            <div class="admin-empty-state">
                <i class="fas fa-spinner fa-spin"></i>
                <p>Loading debts...</p>
            </div>
        </div>

        <div id="debtsPagination"></div>
    </div>

    <!-- Payment Modal (dynamic) -->
    <div id="paymentModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3><i class="fas fa-money-bill-wave"></i> Record Debt Payment</h3>
                <button class="modal-close" onclick="closeModal('paymentModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="debt-summary-box" id="paymentDebtSummary"></div>
                <div class="form-group">
                    <label>Amount (₦) *</label>
                    <input type="text" id="payAmount" class="form-input" placeholder="e.g. 50,000">
                    <div class="hint" id="payAmountHint"></div>
                </div>
                <div class="form-group">
                    <label>Payment Method *</label>
                    <select id="payMethod" class="form-select">
                        <option value="">---Select Payment Method---</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="cash">Cash</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Reference Number *</label>
                    <input type="text" id="payReference" class="form-input" placeholder="Auto-generated" maxlength="25">
                    <div class="hint" id="payReferenceHint">
                        Auto-generated based on payment method. Edit if you have the bank's actual reference.
                    </div>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea id="payNotes" class="form-textarea" rows="2" placeholder="Optional notes"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('paymentModal')">Cancel</button>
                <button class="btn btn-success" id="paySubmitBtn" onclick="submitPayment()">
                    <i class="fas fa-check"></i> Record Payment
                </button>
            </div>
        </div>
    </div>

    <!-- Write-off Modal -->
    <div id="writeOffModal" class="modal">
        <div class="modal-content" style="max-width: 480px;">
            <div class="modal-header">
                <h3><i class="fas fa-ban"></i> Write Off Debt</h3>
                <button class="modal-close" onclick="closeModal('writeOffModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p class="modal-subtext">This will mark the debt as forgiven. This action cannot be undone.</p>
                <div class="debt-summary-box" id="writeOffDebtSummary"></div>
                <div class="form-group">
                    <label>Reason *</label>
                    <textarea id="writeOffReason" class="form-textarea" rows="3"
                        placeholder="e.g. Tenant relocated abroad, amount too small to pursue"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('writeOffModal')">Cancel</button>
                <button class="btn btn-danger" id="writeOffSubmitBtn" onclick="submitWriteOff()">
                    <i class="fas fa-ban"></i> Write Off
                </button>
            </div>
        </div>
    </div>

    <!-- Details Modal (dynamic content) -->
    <div id="detailsModal" class="modal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h3><i class="fas fa-info-circle"></i> Debt Details</h3>
                <button class="modal-close" onclick="closeModal('detailsModal')">&times;</button>
            </div>
            <div class="modal-body" id="detailsBody"></div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('detailsModal')">Close</button>
            </div>
        </div>
    </div>

    <div id="adminWalletLoader" class="admin-loader-overlay" style="display:none;">
        <div class="admin-loader-spinner"></div>
        <div class="admin-loader-message">Loading...</div>
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
        <div class="ui-loader-message">Processing...</div>
    </div>

    <script src="../../ui.js"></script>
    <script src="../../payment_refs.js"></script>
    <script src="../scripts/tenant_debts.js"></script>
</body>

</html>