<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rent Pilot</title>
    <link rel="stylesheet" href="../css/navbar.css">
    <script src="https://kit.fontawesome.com/7cab3097e7.js" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap"
        rel="stylesheet" />
</head>

<body>
    <div class="client-wrapper">
        <aside class="client-sidebar" id="clientSidebar">
            <div class="sidebar-header">
                <h2>RentEase</h2>
                <button class="sidebar-close" id="sidebarClose">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="client-info" id="clientInfo">
                <div class="client-avatar" id="photoElement"></div>
                <div class="client-name" id="clientName">Loading...</div>
                <div class="client-role" id="clientCode">Property Owner</div>
            </div>
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-item" data-page="dashboard">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
                <a href="apartment.php" class="nav-item" data-page="apartment">
                    <i class="fas fa-building"></i>
                    <span>My Properties</span>
                </a>
                <a href="agents.php" class="nav-item" data-page="agents">
                    <i class="fas fa-user-tie"></i>
                    <span>My Agents</span>
                </a>
                <a href="tenants.php" class="nav-item" data-page="tenants">
                    <i class="fas fa-user"></i>
                    <span>My Tenants</span>
                </a>
                <a href="maintenance.php" class="nav-item" data-page="maintenance">
                    <i class="fas fa-tools"></i>
                    <span>Maintenance</span>
                </a>
                <a href="payments.php" class="nav-item" data-page="payments">
                    <i class="fas fa-credit-card"></i>
                    <span>Rent Payments</span>
                </a>
                <a href="settlement.php" class="nav-item" data-page="settlement">
                    <i class="fas fa-handshake"></i>
                    <span>Settlements</span>
                </a>
                <a href="fees.php" class="nav-item" data-page="fees">
                    <i class="fas fa-money"></i>
                    <span>Manage Fees</span>
                </a>
                <a href="documents.php" class="nav-item" data-page="documents">
                    <i class="fas fa-file-alt"></i>
                    <span>Documents</span>
                </a>
                <a href="profile.php" class="nav-item" data-page="profile">
                    <i class="fas fa-user"></i>
                    <span>Profile</span>
                </a>
                <a href="settings.php" class="nav-item" data-page="settings">
                    <i class="fas fa-cog"></i>
                    <span>Settings</span>
                </a>
                <a href="notifications.php" class="nav-item" data-page="notifications">
                    <i class="fas fa-bell"></i>
                    <span>Notifications</span>
                </a>
                <a href="#" class="nav-item" id="logoutBtn">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </nav>
        </aside>

        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <main class="client-main">
            <div class="top-bar">
                <button class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="notifications" id="notificationsBtn">
                    <i class="fas fa-bell"></i>
                    <span class="notification-badge" id="notificationBadge">0</span>
                </div>
            </div>
            <div class="content-area" id="contentArea">
                <div class="loading-spinner">
                    <div class="spinner"></div>
                </div>
            </div>
        </main>
    </div>

    <script src="../scripts/navbar.js"></script>
</body>

</html>
