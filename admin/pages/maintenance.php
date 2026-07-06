<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance Requests | Admin Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
   <link rel="stylesheet" href="../../ui.css">
    <link rel="stylesheet" href="../css/maintenance.css">
</head>
<body>
    <?php include 'navbar.php'; ?>
    
    <div class="main-content">
        <div class="content-area" id="contentArea">
            <!-- Content will be rendered by JavaScript -->
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

    <!-- Custom Confirm Modal -->
    <div id="customConfirmModal" class="custom-modal">
        <div class="custom-modal-content">
            <div class="custom-modal-header">
                <h3 id="confirmTitle">Confirm Action</h3>
                <button class="custom-modal-close" onclick="closeConfirmModal()">&times;</button>
            </div>
            <div class="custom-modal-body">
                <p id="confirmMessage">Are you sure?</p>
            </div>
            <div class="custom-modal-footer">
                <button class="custom-btn-cancel" id="confirmCancelBtn">Cancel</button>
                <button class="custom-btn-confirm" id="confirmConfirmBtn">Confirm</button>
            </div>
        </div>
    </div>
      <script src="../scripts/main.js"></script>
    <script src="../../ui.js"></script>
    <script src="../../validator.js"></script>
    <script src="../scripts/maintenance.js"></script>
</body>
</html>