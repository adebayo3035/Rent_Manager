
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications | Tenant Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/navbar.css">
    <link rel="stylesheet" href="../css/notifications.css">
</head>
<body>
    <?php include('navbar.php'); ?>
    
    <!-- Notification Details Modal -->
    <div class="modal" id="notificationDetailsModal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3>Notification Details</h3>
                <button class="modal-close" onclick="closeModal('notificationDetailsModal')">&times;</button>
            </div>
            <div class="modal-body" id="notificationDetailsBody">
                <!-- Dynamic content -->
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeModal('notificationDetailsModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- Mark as read notification confirmation -->
     <!-- Custom Confirmation Modal -->
<div id="customConfirmModal" class="modal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <div class="confirm-icon">
                <i class="fas fa-bell-slash"></i>
            </div>
            <h3 id="confirmTitle">Confirm Action</h3>
            <button class="modal-close" onclick="closeCustomConfirmModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p id="confirmMessage">Are you sure you want to proceed?</p>
            <div id="confirmDetails" class="confirm-details" style="display: none;"></div>
        </div>
        <div class="modal-footer">
            <button class="btn-secondary" id="confirmCancelBtn">Cancel</button>
            <button class="btn-primary" id="confirmOkBtn">Confirm</button>
        </div>
    </div>
</div>

    <script src="../scripts/notifications.js"></script>
</body>
</html>