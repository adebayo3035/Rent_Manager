<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rent Pilot</title>
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
    <div class="tenant-wrapper">
        <aside class="tenant-sidebar" id="tenantSidebar">
            <div class="sidebar-header">
                <h2>RentEase</h2>
                <button class="sidebar-close" id="sidebarClose">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="tenant-info" id="tenantInfo">
                <div class="tenant-avatar" id = "photoElement">
                    <!-- <i class="fas fa-user-circle"></i> -->
                </div>
                <div class="tenant-name" id="tenantName">Loading...</div>
                <div class="tenant-apartment" id="tenantApartment">-</div>
            </div>
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-item" data-page="dashboard">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
                <a href="apartment.php" class="nav-item" data-page="apartment">
                    <i class="fas fa-building"></i>
                    <span>My Apartment</span>
                </a>
                <a href="maintenance.php" class="nav-item" data-page="maintenance">
                    <i class="fas fa-tools"></i>
                    <span>Maintenance</span>
                </a>
                <a href="payments.php" class="nav-item" data-page="payments">
                    <i class="fas fa-credit-card"></i>
                    <span>Payments</span>
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
                <a href="#" class="nav-item" id="logoutBtn">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </nav>
        </aside>

        <!-- Overlay for mobile -->
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <main class="tenant-main">
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
                <!-- Page content will be loaded here -->
                <div class="loading-spinner">
                    <div class="spinner"></div>
                </div>
            </div>
        </main>
    </div>

    <!-- Force Password Change Modal (First Login) -->
<div class="modal" id="forcePasswordModal">
    <div class="modal-content" style="max-width: 450px;">
        <div class="modal-header">
            <h3>Change Your Password</h3>
            <button class="modal-close" onclick="closeForcePasswordModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="alert-warning" style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px; margin-bottom: 20px; border-radius: 8px;">
                <i class="fas fa-exclamation-triangle" style="color: #856404;"></i>
                <span style="color: #856404; margin-left: 8px;">This is your first login. Please change your default password to continue.</span>
            </div>
            <form id="forcePasswordForm">
                <div class="form-group">
                    <label>New Password *</label>
                    <input type="password" id="forceNewPassword" required>
                    <small style="color: #666; font-size: 12px; margin-top: 5px; display: block;">
                        Password must be at least 8 characters, contain uppercase, lowercase, and numbers
                    </small>
                </div>
                <div class="form-group">
                    <label>Confirm New Password *</label>
                    <input type="password" id="forceConfirmPassword" required>
                </div>
                
                <div class="password-strength" style="margin-top: 10px;">
                    <div class="strength-meter" style="height: 4px; background: #e5e7eb; border-radius: 2px; overflow: hidden;">
                        <div id="strengthBar" style="width: 0%; height: 100%; transition: width 0.3s; background: #dc2626;"></div>
                    </div>
                    <div id="strengthText" style="font-size: 12px; margin-top: 5px; color: #666;">Password strength: Weak</div>
                </div>
                
                <div class="password-requirements" style="background: #f9fafb; padding: 12px; border-radius: 8px; margin-top: 15px; font-size: 12px;">
                    <strong>Requirements:</strong>
                    <ul style="margin-top: 8px; margin-left: 20px; list-style: none; padding-left: 0;">
                        <li id="req-length" style="margin: 5px 0; color: #666;">✗ At least 8 characters</li>
                        <li id="req-upper" style="margin: 5px 0; color: #666;">✗ At least one uppercase letter</li>
                        <li id="req-lower" style="margin: 5px 0; color: #666;">✗ At least one lowercase letter</li>
                        <li id="req-number" style="margin: 5px 0; color: #666;">✗ At least one number</li>
                    </ul>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn-primary" onclick="submitForcePasswordChange()" style="width: 100%;">
                <i class="fas fa-key"></i> Change Password & Continue
            </button>
        </div>
    </div>
</div>

<style>
    .password-requirements li.valid {
        color: #10b981 !important;
    }
    .password-requirements li.valid::before {
        content: "✓ ";
        font-weight: bold;
    }
    .password-requirements li::before {
        content: "✗ ";
        font-weight: bold;
    }
    .modal .modal-close {
        background: none;
        border: none;
        font-size: 24px;
        cursor: pointer;
        color: #999;
        padding: 0;
        width: 30px;
        height: 30px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .modal .modal-close:hover {
        background: #f0f0f0;
        color: #666;
    }
</style>
<script src = "../scripts/navbar.js"></script>
</body>
</html>