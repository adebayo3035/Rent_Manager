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
    <div class="modal-content" style="max-width: 550px; max-height: 90vh; display: flex; flex-direction: column;">
        <div class="modal-header">
            <h3>Make Rent Payment</h3>
            <button class="modal-close" onclick="closeModal('paymentModal')">&times;</button>
        </div>
        <div class="modal-body" style="flex: 1; overflow-y: auto; padding: 20px;">
            <form id="paymentForm">
                <!-- Payment Summary Section -->
                <div class="payment-summary">
                    <h4>Payment Summary</h4>
                    <div class="summary-row">
                        <span class="summary-label">Payment Period:</span>
                        <span class="summary-value" id="summaryPeriod">-</span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label">Amount Due:</span>
                        <span class="summary-value" id="summaryAmount">-</span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label">Payment Start Date:</span>
                        <span class="summary-value" id="summaryDueDate">-</span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label">Property:</span>
                        <span class="summary-value" id="summaryProperty">-</span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label">Apartment:</span>
                        <span class="summary-value" id="summaryApartment">-</span>
                    </div>
                </div>

                <!-- Payment Method Section -->
                <div class="form-section">
                    <h4>Select Payment Method</h4>
                    <div class="payment-methods">
                        <label class="payment-method-option">
                            <input type="radio" name="paymentMethodRadio" value="bank_transfer" data-method="bank_transfer">
                            <div class="method-card">
                                <i class="fas fa-university"></i>
                                <span>Bank Transfer</span>
                            </div>
                        </label>
                        <label class="payment-method-option">
                            <input type="radio" name="paymentMethodRadio" value="card" data-method="card">
                            <div class="method-card">
                                <i class="fas fa-credit-card"></i>
                                <span>Card Payment</span>
                            </div>
                        </label>
                        <label class="payment-method-option">
                            <input type="radio" name="paymentMethodRadio" value="cash" data-method="cash">
                            <div class="method-card">
                                <i class="fas fa-money-bill-wave"></i>
                                <span>Cash (Office)</span>
                            </div>
                        </label>
                        <label class="payment-method-option">
                            <input type="radio" name="paymentMethodRadio" value="cheque" data-method="cheque">
                            <div class="method-card">
                                <i class="fas fa-receipt"></i>
                                <span>Cheque</span>
                            </div>
                        </label>
                    </div>
                    <input type="hidden" id="paymentMethod" name="payment_method">
                </div>

                <!-- Bank Transfer Details -->
                <div id="bankTransferDetails" class="form-section payment-details-section" style="display: none;">
                    <h4>Bank Transfer Details</h4>
                    <div class="bank-info">
                        <div class="info-row">
                            <span class="info-label">Bank Name:</span>
                            <span class="info-value">First Bank of Nigeria</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Account Name:</span>
                            <span class="info-value">RentEase Property Management</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Account Number:</span>
                            <span class="info-value">2034567890</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Sort Code:</span>
                            <span class="info-value">011234567</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Transaction Reference *</label>
                        <input type="text" id="bankReference" placeholder="Enter bank transaction reference" class="form-input" readonly>
                        <small>Please enter the reference number from your bank transfer</small>
                    </div>
                </div>

                <!-- Card Payment Details -->
                <div id="cardPaymentDetails" class="form-section payment-details-section" style="display: none;">
                    <h4>Card Details</h4>
                    <div class="form-group">
                        <label>Card Number *</label>
                        <input type="text" id="cardNumber" placeholder="1234 5678 9012 3456" maxlength="19" class="form-input">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Expiry Date *</label>
                            <input type="text" id="cardExpiry" placeholder="MM/YY" maxlength="5" class="form-input">
                        </div>
                        <div class="form-group">
                            <label>CVV *</label>
                            <input type="password" id="cardCvv" placeholder="123" maxlength="4" class="form-input">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Card Holder Name *</label>
                        <input type="text" id="cardHolderName" placeholder="Name on card" class="form-input">
                    </div>
                </div>

                <!-- Cash Payment Details -->
                <div id="cashPaymentDetails" class="form-section payment-details-section" style="display: none;">
                    <h4>Cash Payment Instructions</h4>
                    <div class="cash-instructions">
                        <p><i class="fas fa-map-marker-alt"></i> Visit our office at:</p>
                        <p class="office-address">12, Property Management Building, Victoria Island, Lagos</p>
                        <p><i class="fas fa-clock"></i> Office Hours: Monday - Friday, 9:00 AM - 5:00 PM</p>
                        <p><i class="fas fa-phone"></i> Contact: +234 123 456 7890</p>
                    </div>
                </div>

                <!-- Cheque Payment Details -->
                <div id="chequePaymentDetails" class="form-section payment-details-section" style="display: none;">
                    <h4>Cheque Payment Details</h4>
                    <div class="form-group">
                        <label>Cheque Number *</label>
                        <input type="text" id="chequeNumber" placeholder="Enter cheque number" class="form-input">
                    </div>
                    <div class="form-group">
                        <label>Bank Name *</label>
                        <input type="text" id="chequeBank" placeholder="Bank name" class="form-input">
                    </div>
                    <div class="form-group">
                        <label>Cheque Date *</label>
                        <input type="date" id="chequeDate" class="form-input">
                    </div>
                    <div class="cheque-instructions">
                        <p><strong>Make cheque payable to:</strong> RentEase Property Management</p>
                    </div>
                </div>

                <!-- Additional Notes -->
                <div class="form-section">
                    <div class="form-group">
                        <label>Additional Notes (Optional)</label>
                        <textarea id="paymentNotes" rows="3" placeholder="Any additional information about this payment..." class="form-textarea"></textarea>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn-secondary" onclick="closeModal('paymentModal')">Cancel</button>
            <button class="btn-primary" onclick="processPayment()" id="processPaymentBtn">
                <i class="fas fa-credit-card"></i> Proceed to Payment
            </button>
        </div>
    </div>
</div>

<!-- Loading Modal (shown during processing) -->
<div class="modal" id="processingModal">
    <div class="modal-content" style="max-width: 400px; text-align: center;">
        <div class="modal-header">
            <h3>Processing Payment</h3>
        </div>
        <div class="modal-body">
            <div class="processing-spinner">
                <i class="fas fa-spinner fa-spin"></i>
            </div>
            <p id="processingMessage">Please wait while we process your payment...</p>
            <p class="processing-note">Do not close this window</p>
        </div>
    </div>
</div>

    <script src="../scripts/payment.js"></script>
</body>
</html>