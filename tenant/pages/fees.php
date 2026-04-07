<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Fees | Tenant Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/navbar.css">
    <link rel="stylesheet" href="../css/fees.css">
</head>
<body>
    <?php include('navbar.php'); ?>
    
    <!-- Payment Modal -->
    <div class="modal" id="paymentModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Pay Fee</h3>
                <button class="modal-close" onclick="closeModal('paymentModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="paymentForm">
                    <div class="form-group">
                        <label>Fee Type</label>
                        <input type="text" id="modalFeeName" readonly>
                    </div>
                    <div class="form-group">
                        <label>Amount</label>
                        <input type="text" id="modalFeeAmount" readonly>
                    </div>
                    <div class="form-group">
                        <label>Due Date</label>
                        <input type="text" id="modalDueDate" readonly>
                    </div>
                    <div class="form-group">
                        <label>Payment Method *</label>
                        <select id="paymentMethod" required>
                            <option value="">Select method</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="card">Card Payment</option>
                            <option value="cash">Cash (Office)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Reference Number *</label>
                        <input type="text" id="referenceNumber" placeholder="Enter transaction reference" readonly>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeModal('paymentModal')">Cancel</button>
                <button class="btn-primary" onclick="processFeePayment()">Confirm Payment</button>
            </div>
        </div>
    </div>

    <!-- Fee Details Modal -->
    <div class="modal" id="feeDetailsModal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3>Fee Details</h3>
                <button class="modal-close" onclick="closeModal('feeDetailsModal')">&times;</button>
            </div>
            <div class="modal-body" id="feeDetailsBody">
                <!-- Dynamic content -->
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeModal('feeDetailsModal')">Close</button>
            </div>
        </div>
    </div>

    <script src="../scripts/fees.js"></script>
</body>
</html>