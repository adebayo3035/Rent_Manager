<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Management | RentFlow Pro</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="../css/payment.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
     <?php include('navbar.php'); ?>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1><i class="fas fa-money-bill-wave"></i> Payment Management</h1>
            <div class="quick-actions">
                <button class="btn btn-primary" onclick="openRecordPaymentModal()">
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
                            <select id="limitSelect" class="form-control" style="width: auto;" onchange="loadPayments()">
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

            <div class="sidebar">
                <!-- Revenue Chart -->
                <div class="chart-card">
                    <h3>Revenue Overview</h3>
                    <canvas id="revenueChart"></canvas>
                </div>

                <!-- Quick Record -->
                <div class="chart-card">
                    <h3>Quick Record Payment</h3>
                    <form id="quickPaymentForm" onsubmit="quickRecordPayment(event)">
                        <div class="form-group">
                            <label>Tenant</label>
                            <select class="form-control" id="quickTenant" required>
                                <!-- Will be populated -->
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Amount ($)</label>
                            <input type="number" class="form-control" id="quickAmount" step="0.01" required>
                        </div>
                        <div class="form-group">
                            <label>Payment Method</label>
                            <select class="form-control" id="quickMethod" required>
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="check">Check</option>
                                <option value="mobile_money">Mobile Money</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 15px;">
                            <i class="fas fa-check"></i> Record Payment
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <div id="recordPaymentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Record New Payment</h2>
                <button class="action-btn" onclick="closeRecordPaymentModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="paymentForm" onsubmit="submitPaymentForm(event)">
                    <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="form-group">
                            <label>Tenant *</label>
                            <select class="form-control" id="tenant_id" name="tenant_id" required>
                                <!-- Populated by JS -->
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Apartment</label>
                            <select class="form-control" id="apartment_id" name="apartment_id">
                                <!-- Auto-populated based on tenant -->
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Amount ($) *</label>
                            <input type="number" class="form-control" id="amount" name="amount" step="0.01" required>
                        </div>
                        <div class="form-group">
                            <label>Payment Date *</label>
                            <input type="date" class="form-control" id="payment_date" name="payment_date" required>
                        </div>
                        <div class="form-group">
                            <label>Payment Method *</label>
                            <select class="form-control" id="payment_method" name="payment_method" required>
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="check">Check</option>
                                <option value="credit_card">Credit Card</option>
                                <option value="mobile_money">Mobile Money</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Payment Status</label>
                            <select class="form-control" id="payment_status" name="payment_status">
                                <option value="completed">Completed</option>
                                <option value="pending">Pending</option>
                                <option value="overdue">Overdue</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Reference Number</label>
                            <input type="text" class="form-control" id="reference_number" name="reference_number">
                        </div>
                        <div class="form-group">
                            <label>Due Date</label>
                            <input type="date" class="form-control" id="due_date" name="due_date">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Description/Notes</label>
                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeRecordPaymentModal()">Cancel</button>
                <button class="btn btn-primary" onclick="submitPaymentForm()">
                    <i class="fas fa-save"></i> Save Payment
                </button>
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
    <script src = "../scripts/payment.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
</body>
</html>