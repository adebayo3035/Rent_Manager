
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

    <script src="../scripts/notifications.js"></script>
</body>
</html>