<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Wallet Requests - Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://kit.fontawesome.com/7cab3097e7.js" crossorigin="anonymous"></script>
   <link rel="stylesheet" href="../../styles.css">
    <link rel="stylesheet" href="../css/wallet_requests.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="admin-container">
    <div class="admin-page-header">
        <h1><i class="fas fa-exchange-alt"></i> Wallet Requests</h1>
        <p>Review and approve client top-ups and tenant withdrawals</p>
    </div>

    <div class="wallet-tabs">
        <button class="wallet-tab active" data-type="topup" onclick="switchType('topup')">
            <i class="fas fa-arrow-down"></i> Client Top-Ups
            <span class="tab-badge" id="topupBadge">0</span>
        </button>
        <button class="wallet-tab" data-type="withdrawal" onclick="switchType('withdrawal')">
            <i class="fas fa-arrow-up"></i> Tenant Withdrawals
            <span class="tab-badge" id="withdrawalBadge">0</span>
        </button>
    </div>

    <div class="wallet-status-filter">
        <button class="filter-btn active" data-status="pending"   onclick="switchStatus('pending')">Pending</button>
        <button class="filter-btn"        data-status="approved"  onclick="switchStatus('approved')">Approved</button>
        <button class="filter-btn"        data-status="rejected"  onclick="switchStatus('rejected')">Rejected</button>
    </div>

    <div id="requestsList">
        <div class="admin-empty-state"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
    </div>
</div>

<div id="adminWalletLoader" class="admin-loader-overlay" style="display:none;">
    <div class="admin-loader-spinner"></div>
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
        <div class="ui-loader-message">Processing your request...</div>
    </div>

    <script src="../../ui.js"></script>
<script src="../scripts/wallet_requests.js"></script>
</body>
</html>