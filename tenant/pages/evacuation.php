<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Move-out Requests | Tenant Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/navbar.css">
    <link rel="stylesheet" href="../css/evacuation.css">
</head>
<body>
    <?php include('navbar.php'); ?>

    <!-- Cancel Confirmation Modal -->
    <div class="modal" id="cancelConfirmModal">
        <div class="modal-content" style="max-width: 460px;">
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle" style="color: #f59e0b;"></i> Cancel Request</h3>
                <button class="modal-close" onclick="closeModal('cancelConfirmModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p style="color: #4b5563; line-height: 1.6;">
                    Are you sure you want to cancel this move-out request?
                    You can submit a new one anytime.
                </p>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeModal('cancelConfirmModal')">No, Keep It</button>
                <button class="btn-primary" id="cancelConfirmBtn" onclick="submitCancel()">
                    <i class="fas fa-times"></i> Yes, Cancel Request
                </button>
            </div>
        </div>
    </div>

    <!-- Request Details Modal -->
    <div class="modal" id="requestDetailsModal">
        <div class="modal-content" style="max-width: 620px; max-height: 90vh; display: flex; flex-direction: column;">
            <div class="modal-header">
                <h3><i class="fas fa-file-alt"></i> Request Details</h3>
                <button class="modal-close" onclick="closeModal('requestDetailsModal')">&times;</button>
            </div>
            <div class="modal-body" id="requestDetailsBody" style="flex: 1; overflow-y: auto;">
                <!-- populated by JS -->
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeModal('requestDetailsModal')">Close</button>
            </div>
        </div>
    </div>

    <script src="../scripts/evacuation.js"></script>
</body>
</html>