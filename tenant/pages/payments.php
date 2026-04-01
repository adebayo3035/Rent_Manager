<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments | Tenant Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/navbar.css">
    <link rel="stylesheet" href="../css/payment.css">
</head>
<body>
    <?php include('navbar.php'); ?>

    <!-- Payment Modal -->
    <div class="modal" id="paymentModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Make Payment</h3>
                <button class="modal-close" onclick="closeModal('paymentModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="paymentForm">
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
                        <label>Reference Number (Optional)</label>
                        <input type="text" id="referenceNumber" placeholder="Enter transaction reference">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeModal('paymentModal')">Cancel</button>
                <button class="btn-primary" onclick="processPayment()">Proceed to Payment</button>
            </div>
        </div>
    </div>

    <script src="../scripts/payment.js"></script>
</body>
</html>