<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unauthorized Access - Rent Pilot</title>
    <link rel="stylesheet" href="../../styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/unauthorized.css">
</head>

<body>
    <!-- Navbar -->
    <?php include "navbar.php"; ?>

    <!-- Main Content -->
    <div class="unauthorized-wrapper">
        <div class="unauthorized-container">
            <!-- Icon -->
            <div class="unauthorized-icon">
                <i class="fas fa-lock"></i>
            </div>

            <!-- Title -->
            <h1 class="unauthorized-title">Access Denied</h1>
            <p class="unauthorized-subtitle">You don't have the necessary permissions to access this page.</p>
            <span class="unauthorized-code">Error 403 · Forbidden</span>

            <div class="unauthorized-divider"></div>

            <!-- Suggestions -->
            <div class="unauthorized-suggestions">
                <p>Here's what you can do:</p>
                <ul>
                    <li><i class="fas fa-user-check"></i> Ensure you're logged in with the correct account</li>
                    <li><i class="fas fa-arrow-left"></i> Return to the previous page</li>
                    <li><i class="fas fa-headset"></i> Contact your administrator for access</li>
                </ul>
            </div>

            <!-- Primary Action -->
            <a href="homepage.php" class="unauthorized-btn">
                <i class="fas fa-home"></i> Return to Homepage
            </a>

            <!-- Secondary Action -->
            <div class="unauthorized-secondary">
                Need help? <a href="#" onclick="history.back(); return false;">Go back</a> or
                <a href="mailto:support@rentpilot.com">contact support</a>
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

    <!-- JavaScript -->
    <script src="../scripts/main.js"></script>
    <script src="../../ui.js"></script>
    <script src="../../validator.js"></script>
</body>

</html>