<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KaraKata - Transaction Manager</title>
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
                        <div class="dropdown-column">
                            <span class="dropdown-title">Management</span>
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
                        </div>
                        
                        <div class="dropdown-column">
                            <span class="dropdown-title">Transactions</span>
                            <a href="tenant.php" class="dropdown-item">
                                <i class="fas fa-user-friends"></i> Tenants
                            </a>
                            <a href="tenant_new.php" class="dropdown-item new-badge">
                                <i class="fas fa-user-plus"></i> New Tenant
                            </a>
                            <a href="rent_payments.php" class="dropdown-item">
                                <i class="fas fa-money-check-alt"></i> Rent Payments
                            </a>
                            <a href="client.php" class="dropdown-item">
                                <i class="fas fa-users"></i> Clients
                            </a>
                        </div>
                        
                        <div class="dropdown-column">
                            <span class="dropdown-title">Administration</span>
                            <a href="admin.php" class="dropdown-item">
                                <i class="fas fa-user-shield"></i> Admin Portal
                            </a>
                            <a href="agent.php" class="dropdown-item">
                                <i class="fas fa-user-tie"></i> Agents
                            </a>
                            <a href="reports.php" class="dropdown-item">
                                <i class="fas fa-file-alt"></i> Reports
                            </a>
                            <a href="account_management.php" class="dropdown-item">
                                <i class="fas fa-history"></i> Account Management
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="quick-actions">
                    <a href="account_management.php" class="quick-link" title="Account Management">
                        <i class="fas fa-history"></i>
                    </a>
                    <a href="admin_notification.php" class="quick-link notification-link" title="Notifications">
                        <i class="fas fa-bell"></i>
                        <span class="badge" id="notification-badge">0</span>
                    </a>
                    <a href="settings.php" class="quick-link" title="Settings">
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
                        <a href="staff_profile.php" class="dropdown-item">
                            <i class="fas fa-user"></i> My Profile
                        </a>
                        <a href="settings.php" class="dropdown-item">
                            <i class="fas fa-cog"></i> Settings
                        </a>
                        <a href="admin_notification.php" class="dropdown-item">
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
                            <!-- Management -->
                            <div class="mobile-module-group">
                                <span class="mobile-group-title">Management</span>
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
                            </div>

                            <!-- Transactions -->
                            <div class="mobile-module-group">
                                <span class="mobile-group-title">Transactions</span>
                                <a href="tenant.php" class="mobile-nav-link sub">
                                    <i class="fas fa-user-friends"></i> Tenants
                                </a>
                                <a href="tenant_new.php" class="mobile-nav-link sub new-badge">
                                    <i class="fas fa-user-plus"></i> New Tenant
                                    <span class="mobile-badge">New</span>
                                </a>
                                <a href="rent_payments.php" class="mobile-nav-link sub">
                                    <i class="fas fa-money-check-alt"></i> Rent Payments
                                </a>
                                <a href="client.php" class="mobile-nav-link sub">
                                    <i class="fas fa-users"></i> Clients
                                </a>
                            </div>

                            <!-- Administration -->
                            <div class="mobile-module-group">
                                <span class="mobile-group-title">Administration</span>
                                <a href="admin.php" class="mobile-nav-link sub">
                                    <i class="fas fa-user-shield"></i> Admin Portal
                                </a>
                                <a href="agent.php" class="mobile-nav-link sub">
                                    <i class="fas fa-user-tie"></i> Agents
                                </a>
                                <a href="reports.php" class="mobile-nav-link sub">
                                    <i class="fas fa-file-alt"></i> Reports
                                </a>
                                <a href="account_management.php" class="mobile-nav-link sub">
                                    <i class="fas fa-history"></i> Account Management
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Links -->
                    <a href="account_management.php" class="mobile-nav-link">
                        <i class="fas fa-history"></i> Account Management
                    </a>
                    <a href="admin_notification.php" class="mobile-nav-link">
                        <i class="fas fa-bell"></i> Notifications
                        <span class="mobile-notification-badge">0</span>
                    </a>
                    <a href="settings.php" class="mobile-nav-link">
                        <i class="fas fa-cog"></i> Settings
                    </a>
                    <a href="staff_profile.php" class="mobile-nav-link">
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
    <!-- <script src="../../ui.js"></script> -->
</body>
</html>