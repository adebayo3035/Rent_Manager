<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications</title>
    <link rel="stylesheet" href="../css/notification.css">
    <link rel="stylesheet" href="../../styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include('navbar.php'); ?>
    
    <div class="notifications-container">
        <!-- Header -->
        <div class="notifications-header">
            <div class="header-left">
                <h1><i class="fas fa-bell"></i> Notifications</h1>
                <p>Manage your notifications</p>
            </div>
            <div class="notification-stats">
                <div class="stat-item">
                    <div class="stat-value" id="totalUnread">0</div>
                    <div class="stat-label">Unread</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value" id="totalNotifications">0</div>
                    <div class="stat-label">Total</div>
                </div>
            </div>
        </div>

        <!-- Controls -->
        <div class="notifications-controls">
            <div class="filter-buttons">
                <button class="filter-btn active" data-filter="all">All</button>
                <button class="filter-btn" data-filter="unread">Unread <span id="unreadCount" class="badge">0</span></button>
                <button class="filter-btn" data-filter="account_reactivation">Reactivation</button>
                <button class="filter-btn" data-filter="payment">Payments</button>
                <button class="filter-btn" data-filter="account_lock">Locks</button>
                <button class="filter-btn" data-filter="archived">Archived</button>
            </div>
            <div class="action-buttons">
                <button class="action-btn mark-all-read" id="markAllReadBtn">
                    <i class="fas fa-check-double"></i> Mark All Read
                </button>
            </div>
        </div>

        <!-- Notifications List -->
        <div class="notifications-list" id="notificationsList">
            <div class="loading-state">
                <div class="spinner"></div>
                <p>Loading notifications...</p>
            </div>
        </div>

        <!-- Pagination -->
        <div class="pagination" id="pagination"></div>
    </div>

     <!-- UI Library -->
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

    <script src="../scripts/notification.js"></script>
     <script src="../../ui.js"></script>
</body>
</html>