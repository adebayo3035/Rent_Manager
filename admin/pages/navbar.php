<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title></title>
    <link rel="stylesheet" href="../css/navbar.css">
    <!-- Font Awesome -->
    <script src="https://kit.fontawesome.com/7cab3097e7.js" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css" />
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
</head>
<body>
    <nav class="navbar">
        <div class="nav-container">
            <!-- Logo/Brand -->
            <div class="nav-brand">
                <a href="homepage.php" class="logo-link">
                    <span class="logo-icon">🏠</span>
                    <span class="logo-text">Easy Rent</span>
                </a>
            </div>

            <!-- Desktop Navigation -->
            <div class="nav-desktop">

                <!-- Dashboard Link -->
                <a href="dashboard.php" class="nav-link dashboard-link">
                    <i class="fas fa-chart-line"></i> Dashboard
                </a>

                <!-- Modules Dropdown -->
                <div class="modules-dropdown dropdown">
                    <button class="modules-btn">
                        <i class="fas fa-th-large"></i> Modules
                        <i class="fas fa-chevron-down dropdown-icon"></i>
                    </button>
                    <div class="dropdown-menu modules-menu">
                        <!-- ==================== COLUMN 1: PROPERTY MANAGEMENT ==================== -->
                        <div class="dropdown-column">
                            <span class="dropdown-title"><i class="fas fa-building"></i> Property Management</span>
                            <a href="property.php" class="dropdown-item">
                                <i class="fas fa-building"></i> Properties
                            </a>
                            <a href="apartment.php" class="dropdown-item">
                                <i class="fas fa-home"></i> Apartments
                            </a>
                            <a href="property_type.php" class="dropdown-item">
                                <i class="fas fa-tags"></i> Property Types
                            </a>
                            <a href="apartment_type.php" class="dropdown-item">
                                <i class="fas fa-layer-group"></i> Apartment Types
                            </a>
                            <a href="maintenance.php" class="dropdown-item">
                                <i class="fas fa-wrench"></i> Maintenance Requests
                            </a>
                        </div>
                        
                        <!-- ==================== COLUMN 2: TENANT & PAYMENTS ==================== -->
                        <div class="dropdown-column">
                            <span class="dropdown-title"><i class="fas fa-users"></i> Tenant & Payments</span>
                            <a href="tenant.php" class="dropdown-item">
                                <i class="fas fa-user-friends"></i> Tenants
                            </a>
                            <a href="rent_payment.php" class="dropdown-item">
                                <i class="fas fa-money-check-alt"></i> Rent Payments
                            </a>
                            <a href="payment.php" class="dropdown-item">
                                <i class="fas fa-credit-card"></i> Payment Manager
                            </a>
                            <a href="fee_management.php" class="dropdown-item">
                                <i class="fas fa-coins"></i> Manage Fees
                            </a>
                            <a href="evacuation_requests.php" class="dropdown-item">
                                <i class="fas fa-door-open"></i> Tenant Evacuation
                            </a>
                        </div>
                        
                        <!-- ==================== COLUMN 3: FINANCE & SETTLEMENTS ==================== -->
                        <div class="dropdown-column">
                            <span class="dropdown-title"><i class="fas fa-hand-holding-usd"></i> Finance & Settlements</span>
                            <a href="client.php" class="dropdown-item">
                                <i class="fas fa-user-tie"></i> Clients
                            </a>
                            <a href="agent.php" class="dropdown-item">
                                <i class="fas fa-user-tie"></i> Agents
                            </a>
                            <a href="manage_settlement.php" class="dropdown-item">
                                <i class="fas fa-sliders-h"></i> Settlement Settings
                            </a>
                            <a href="settlement.php" class="dropdown-item">
                                <i class="fas fa-money-bill-wave"></i> My Settlements
                            </a>
                            <a href="report.php" class="dropdown-item">
                                <i class="fas fa-file-alt"></i> Reports
                            </a>
                        </div>
                        
                        <!-- ==================== COLUMN 4: ADMINISTRATION ==================== -->
                        <div class="dropdown-column">
                            <span class="dropdown-title"><i class="fas fa-cogs"></i> Administration</span>
                            <a href="staff.php" class="dropdown-item">
                                <i class="fas fa-user-shield"></i> Staff Portal
                            </a>
                            <a href="account_management.php" class="dropdown-item">
                                <i class="fas fa-history"></i> Account Management
                            </a>
                            <a href="account_unlock.php" class="dropdown-item">
                                <i class="fas fa-unlock-alt"></i> Account Unlock
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="quick-actions">
                    <a href="account_management.php" class="quick-link" title="Account Management">
                        <i class="fas fa-history"></i>
                    </a>
                    <a href="notification.php" class="quick-link notification-link" title="Notifications">
                        <i class="fas fa-bell"></i>
                        <span class="badge" id="notification-badge">0</span>
                    </a>
                    <a href="account_unlock.php" class="quick-link" title="Settings">
                        <i class="fas fa-cog"></i>
                    </a>
                </div>

                <!-- User Profile Dropdown -->
                <div class="user-profile dropdown">
                    <button class="profile-btn" id="profileBtn">
                        <div class="avatar">
                            <i class="fas fa-user-circle"></i>
                        </div>
                        <div class="profile-info">
                            <span class="welcome-text" id="welcomeMessage">Welcome!</span>
                            <span class="user-role">Administrator</span>
                        </div>
                        <i class="fas fa-chevron-down dropdown-icon"></i>
                    </button>
                    <div class="dropdown-menu">
                        <a href="profile.php" class="dropdown-item">
                            <i class="fas fa-user"></i> My Profile
                        </a>
                        <a href="account_unlock.php" class="dropdown-item">
                            <i class="fas fa-cog"></i> Settings
                        </a>
                        <a href="notification.php" class="dropdown-item">
                            <i class="fas fa-bell"></i> Notifications
                            <span class="badge-menu">0</span>
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="javascript:void(0);" class="dropdown-item logout-btn" id="logoutButton">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>

            <!-- Mobile Menu Toggle -->
            <button class="mobile-toggle" id="mobileToggle">
                <i class="fas fa-bars"></i>
            </button>
        </div>

        <!-- Mobile Menu -->
        <div class="mobile-menu" id="mobileMenu">
            <div class="mobile-header">
                <div class="mobile-user">
                    <div class="mobile-avatar">
                        <i class="fas fa-user-circle"></i>
                    </div>
                    <div class="mobile-user-info">
                        <span class="mobile-welcome" id="mobileWelcomeMessage">Welcome!</span>
                        <span class="mobile-role">Administrator</span>
                    </div>
                </div>
                <button class="mobile-close" id="mobileClose">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="mobile-search">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search modules...">
            </div>

            <div class="mobile-nav">
                <a href="homepage.php" class="mobile-nav-link">
                    <i class="fas fa-home"></i> Home
                </a>
                <a href="dashboard.php" class="mobile-nav-link">
                    <i class="fas fa-chart-line"></i> Dashboard
                </a>

                <div class="mobile-accordion">
                    <!-- Modules Accordion -->
                    <div class="mobile-accordion-item">
                        <button class="mobile-accordion-btn">
                            <i class="fas fa-th-large"></i> Modules
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <div class="mobile-accordion-content">
                            <!-- ==================== PROPERTY MANAGEMENT ==================== -->
                            <div class="mobile-module-group">
                                <span class="mobile-group-title"><i class="fas fa-building"></i> Property Management</span>
                                <a href="property.php" class="mobile-nav-link sub">
                                    <i class="fas fa-building"></i> Properties
                                </a>
                                <a href="apartment.php" class="mobile-nav-link sub">
                                    <i class="fas fa-home"></i> Apartments
                                </a>
                                <a href="property_type.php" class="mobile-nav-link sub">
                                    <i class="fas fa-tags"></i> Property Types
                                </a>
                                <a href="apartment_type.php" class="mobile-nav-link sub">
                                    <i class="fas fa-layer-group"></i> Apartment Types
                                </a>
                                <a href="maintenance.php" class="mobile-nav-link sub">
                                    <i class="fas fa-wrench"></i> Maintenance Requests
                                </a>
                            </div>

                            <!-- ==================== TENANT & PAYMENTS ==================== -->
                            <div class="mobile-module-group">
                                <span class="mobile-group-title"><i class="fas fa-users"></i> Tenant & Payments</span>
                                <a href="tenant.php" class="mobile-nav-link sub">
                                    <i class="fas fa-user-friends"></i> Tenants
                                </a>
                                <a href="rent_payment.php" class="mobile-nav-link sub">
                                    <i class="fas fa-money-check-alt"></i> Rent Payments
                                </a>
                                <a href="payment.php" class="mobile-nav-link sub">
                                    <i class="fas fa-credit-card"></i> Payment Manager
                                </a>
                                <a href="fee_management.php" class="mobile-nav-link sub">
                                    <i class="fas fa-coins"></i> Manage Fees
                                </a>
                                <a href="evacuation_requests.php" class="mobile-nav-link sub">
                                    <i class="fas fa-door-open"></i> Tenant Evacuation
                                </a>
                            </div>

                            <!-- ==================== FINANCE & SETTLEMENTS ==================== -->
                            <div class="mobile-module-group">
                                <span class="mobile-group-title"><i class="fas fa-hand-holding-usd"></i> Finance & Settlements</span>
                                <a href="client.php" class="mobile-nav-link sub">
                                    <i class="fas fa-user-tie"></i> Clients
                                </a>
                                <a href="agent.php" class="mobile-nav-link sub">
                                    <i class="fas fa-user-tie"></i> Agents
                                </a>
                                <a href="manage_settlement.php" class="mobile-nav-link sub">
                                    <i class="fas fa-sliders-h"></i> Settlement Settings
                                </a>
                                <a href="settlement.php" class="mobile-nav-link sub">
                                    <i class="fas fa-money-bill-wave"></i> My Settlements
                                </a>
                                <a href="report.php" class="mobile-nav-link sub">
                                    <i class="fas fa-file-alt"></i> Reports
                                </a>
                            </div>

                            <!-- ==================== ADMINISTRATION ==================== -->
                            <div class="mobile-module-group">
                                <span class="mobile-group-title"><i class="fas fa-cogs"></i> Administration</span>
                                <a href="staff.php" class="mobile-nav-link sub">
                                    <i class="fas fa-user-shield"></i> Staff Portal
                                </a>
                                <a href="account_management.php" class="mobile-nav-link sub">
                                    <i class="fas fa-history"></i> Account Management
                                </a>
                                <a href="account_unlock.php" class="mobile-nav-link sub">
                                    <i class="fas fa-unlock-alt"></i> Account Unlock
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Links -->
                    <a href="account_management.php" class="mobile-nav-link">
                        <i class="fas fa-history"></i> Account Management
                    </a>
                    <a href="notification.php" class="mobile-nav-link">
                        <i class="fas fa-bell"></i> Notifications
                        <span class="mobile-notification-badge">0</span>
                    </a>
                    <a href="account_unlock.php" class="mobile-nav-link">
                        <i class="fas fa-cog"></i> Settings
                    </a>
                    <a href="profile.php" class="mobile-nav-link">
                        <i class="fas fa-user"></i> My Profile
                    </a>
                </div>

                <div class="mobile-footer">
                    <button class="mobile-logout-btn" id="mobileLogoutButton">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </button>
                </div>
            </div>
        </div>
    </nav>

    <script src="../scripts/navbar.js"></script>
    <!-- <script src="../../ui.js"></script>  -->
</body>
</html>