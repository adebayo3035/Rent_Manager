<?php
// --- Page guard bootstrap ---
require_once __DIR__ . '/../backend/utilities/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$adminCode = $_SESSION['unique_id'] ?? null;   // or tenant_code
if (!$adminCode) {
    header('Location: index.php');
    exit;
}
guardPageIfAdmin($conn);   // or guardPageIfTenant($conn)
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Admin Panel</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/profile.css">
    <link rel="stylesheet" href="../../styles.css">

    <!-- Google Fonts -->
    <link
        href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Inter:wght@300;400;500;600&display=swap"
        rel="stylesheet">

</head>

<body>
    <?php include('navbar.php'); ?>
    <div class="profile-container">
        <!-- Header -->
        <div class="profile-header">
            <div class="header-left">
                <h1>My Profile</h1>
                <p>View and manage your account information</p>
            </div>
            <div class="header-actions">
                <a href="homepage.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <button onclick="refreshProfile()" class="btn btn-primary">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
        </div>

        <!-- Main Content -->
        <div class="profile-content">
            <!-- Profile Card (Left Sidebar) -->
            <div class="profile-card">
                <div class="profile-avatar" id="avatarSection">
                    <div class="avatar-container" id="avatarWrapper">
                        <div class="skeleton" style="width:140px; height:140px; border-radius:50%; margin:0 auto 15px;">
                        </div>
                    </div>
                    <div class="photo-action-buttons" id="photoActionButtons">
                        <button type="button" class="photo-action-btn" id="uploadPhotoBtn" title="Upload Photo">
                            <i class="fas fa-pencil-alt"></i>
                        </button>
                        <button type="button" class="photo-action-btn" id="cameraPhotoBtn" title="Take Photo">
                            <i class="fas fa-camera"></i>
                        </button>
                    </div>
                    <input type="file" id="photoFileInput" accept="image/jpeg,image/jpg,image/png" style="display: none;">
                    <input type="file" id="cameraFileInput" accept="image/*" capture="user" style="display: none;">
                    <div class="skeleton" style="width:120px; height:24px; margin:0 auto 5px;"></div>
                    <div class="skeleton" style="width:80px; height:20px; margin:0 auto 15px;"></div>
                    <div class="skeleton" style="width:150px; height:20px; margin:0 auto 20px;"></div>
                </div>

                <div class="profile-stats" id="statsSection">
                    <!-- Stats will be loaded here -->
                    <div class="skeleton" style="height: 80px;"></div>
                    <div class="skeleton" style="height: 80px;"></div>
                </div>

                <div style="margin-top: 25px;">
                    <button onclick="editProfile()" class="btn btn-primary" style="width: 100%;">
                        <i class="fas fa-edit"></i> Edit Profile
                    </button>
                </div>
            </div>

            <!-- Details Card (Right Content) -->
            <div class="details-card">
                <div class="section-header">
                    <h2 class="section-title">Personal Information</h2>
                    <span class="section-edit" onclick="editProfile()">
                        <i class="fas fa-edit"></i> Edit
                    </span>
                </div>

                <div class="info-grid" id="personalInfo">
                    <!-- Personal info will be loaded here -->
                    <div class="skeleton" style="height: 60px;"></div>
                    <div class="skeleton" style="height: 60px;"></div>
                    <div class="skeleton" style="height: 60px;"></div>
                    <div class="skeleton" style="height: 60px;"></div>
                    <div class="skeleton" style="height: 60px;"></div>
                    <div class="skeleton" style="height: 60px;"></div>
                </div>
            </div>
        </div>

        <!-- Activity Card -->
        <div class="activity-card">
            <div class="section-header">
                <h2 class="section-title">Recent Activity</h2>
            </div>

            <ul class="activity-list" id="activityList">
                <!-- Activity will be loaded here -->
                <li class="activity-item">
                    <div class="skeleton" style="width: 40px; height: 40px; border-radius: 50%; margin-right: 15px;">
                    </div>
                    <div style="flex: 1;">
                        <div class="skeleton" style="height: 16px; width: 70%; margin-bottom: 5px;"></div>
                        <div class="skeleton" style="height: 12px; width: 40%;"></div>
                    </div>
                </li>
                <li class="activity-item">
                    <div class="skeleton" style="width: 40px; height: 40px; border-radius: 50%; margin-right: 15px;">
                    </div>
                    <div style="flex: 1;">
                        <div class="skeleton" style="height: 16px; width: 70%; margin-bottom: 5px;"></div>
                        <div class="skeleton" style="height: 12px; width: 40%;"></div>
                    </div>
                </li>
            </ul>
        </div>

        <!-- Footer -->
        <div class="profile-footer">
            <p>Profile last updated: <span id="lastUpdated">--</span></p>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="loading"
        style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(255,255,255,0.9); z-index: 9999;">
        <div class="loading-spinner"></div>
        <p class="loading-text">Loading profile...</p>
    </div>

    <!-- Error Container -->
    <div id="errorContainer" class="error-container" style="display: none;"></div>

    <!-- Notification -->
    <div id="notification" class="notification">
        <i class="fas fa-check-circle notification-icon"></i>
        <div>
            <strong class="notification-title">Success</strong>
            <p class="notification-message"></p>
        </div>
    </div>

    <!-- edit profile modal -->
    <div id="editProfileModal" class="modal-overlay" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Edit Profile</h2>
                <button class="modal-close" onclick="closeEditModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="modal-tabs">
                <button class="modal-tab-btn active" data-tab="basic" onclick="switchEditTab('basic')">
                    <i class="fas fa-user"></i> Basic Info
                </button>
                <button class="modal-tab-btn" data-tab="password" onclick="switchEditTab('password')">
                    <i class="fas fa-key"></i> Change Password
                </button>
                <button class="modal-tab-btn" data-tab="secret" onclick="switchEditTab('secret')">
                    <i class="fas fa-shield-alt"></i> Secret Details
                </button>
            </div>

            <div class="modal-body">
                <!-- TAB: BASIC -->
                <div class="modal-tab-panel active" id="editTab-basic">
                    <form id="basicForm" onsubmit="event.preventDefault(); saveBasic();">
                        <div class="form-row">
                            <div class="form-group">
                                <label>First Name <span class="required">*</span></label>
                                <input type="text" id="basicFirstname" required>
                            </div>
                            <div class="form-group">
                                <label>Last Name <span class="required">*</span></label>
                                <input type="text" id="basicLastname" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Email <span class="required">*</span></label>
                            <input type="email" id="basicEmail" required oninput="toggleBasicReauth()">
                        </div>
                        <div class="form-group">
                            <label>Phone <span class="required">*</span></label>
                            <input type="tel" id="basicPhone" required oninput="toggleBasicReauth()">
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Gender</label>
                                <select id="basicGender">
                                    <option value="">— Select —</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Address</label>
                            <textarea id="basicAddress" rows="2"></textarea>
                        </div>

                        <div class="reauth-block" id="basicReauthBlock" style="display:none;">
                            <div class="form-notice">
                                <i class="fas fa-lock"></i>
                                You're changing your email or phone. Enter your password to confirm.
                            </div>
                            <div class="form-group">
                                <label>Current Password <span class="required">*</span></label>
                                <div class="input-with-icon">
                                    <input type="password" id="basicCurrentPassword" autocomplete="current-password">
                                    <i class="fas fa-lock input-icon"></i>
                                    <button type="button" class="toggle-password"
                                        onclick="togglePassword('basicCurrentPassword')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="button" class="btn btn-outline" onclick="closeEditModal()">Cancel</button>
                            <button type="submit" class="btn btn-primary" id="basicSubmitBtn">
                                <i class="fas fa-save"></i> Save
                            </button>
                        </div>
                    </form>
                </div>

                <!-- TAB: PASSWORD -->
                <div class="modal-tab-panel" id="editTab-password">
                    <form id="passwordForm" onsubmit="event.preventDefault(); savePassword();">
                        <div class="form-group">
                            <label>Current Password <span class="required">*</span></label>
                            <div class="input-with-icon">
                                <input type="password" id="pwCurrentPassword" required autocomplete="current-password">
                                <i class="fas fa-lock input-icon"></i>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>New Password <span class="required">*</span></label>
                            <div class="input-with-icon">
                                <input type="password" id="pwNewPassword" required autocomplete="new-password"
                                    oninput="checkPwStrength()">
                                <i class="fas fa-key input-icon"></i>
                            </div>
                            <div class="pw-strength-bar">
                                <div class="pw-strength-fill" id="pwStrengthFill"></div>
                            </div>
                            <div class="form-help" id="pwStrengthText">Min 8 chars with upper, lower, number, special
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Confirm New Password <span class="required">*</span></label>
                            <div class="input-with-icon">
                                <input type="password" id="pwConfirmPassword" required autocomplete="new-password">
                                <i class="fas fa-key input-icon"></i>
                            </div>
                            <div class="form-feedback" id="pwMatchFeedback"></div>
                        </div>
                        <div class="form-group">
                            <label>Secret Answer <span class="required">*</span></label>
                            <div class="input-with-icon">
                                <input type="password" id="pwSecretAnswer" required autocomplete="off">
                                <i class="fas fa-shield-alt input-icon"></i>
                            </div>
                            <div class="form-help">Your current secret answer, used to authorize this change</div>
                        </div>

                        <div class="form-actions">
                            <button type="button" class="btn btn-outline" onclick="closeEditModal()">Cancel</button>
                            <button type="submit" class="btn btn-primary" id="pwSubmitBtn">
                                <i class="fas fa-save"></i> Change Password
                            </button>
                        </div>
                    </form>
                </div>

                <!-- TAB: SECRET -->
                <div class="modal-tab-panel" id="editTab-secret">
                    <form id="secretForm" onsubmit="event.preventDefault(); saveSecret();">
                        <div class="form-group">
                            <label>New Secret Question <span class="required">*</span></label>
                            <select id="secretQuestionInput" required>
                                <option value="">— Select —</option>
                                <option value="What was your first pet's name?">What was your first pet's name?</option>
                                <option value="What is your mother's maiden name?">What is your mother's maiden name?
                                </option>
                                <option value="What city were you born in?">What city were you born in?</option>
                                <option value="What is your favorite book?">What is your favorite book?</option>
                                <option value="What was your first car?">What was your first car?</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>New Secret Answer <span class="required">*</span></label>
                            <div class="input-with-icon">
                                <input type="password" id="secretAnswerInput" required autocomplete="off">
                                <i class="fas fa-shield-alt input-icon"></i>
                            </div>
                            <div class="form-help">Minimum 8 characters</div>
                        </div>
                        <div class="form-group">
                            <label>Confirm Secret Answer <span class="required">*</span></label>
                            <div class="input-with-icon">
                                <input type="password" id="secretConfirmInput" required autocomplete="off">
                                <i class="fas fa-shield-alt input-icon"></i>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Current Password <span class="required">*</span></label>
                            <div class="input-with-icon">
                                <input type="password" id="secretCurrentPassword" required
                                    autocomplete="current-password">
                                <i class="fas fa-lock input-icon"></i>
                            </div>
                            <div class="form-help">Confirms it's really you</div>
                        </div>

                        <div class="form-actions">
                            <button type="button" class="btn btn-outline" onclick="closeEditModal()">Cancel</button>
                            <button type="submit" class="btn btn-primary" id="secretSubmitBtn">
                                <i class="fas fa-save"></i> Update Secret Details
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
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
        <div class="ui-loader-message">Processing your request...</div>
    </div>

    <div id="lockoutModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.75);
     z-index:999999; align-items:center; justify-content:center; padding:20px;">
        <div style="background:#fff; border-radius:12px; width:100%; max-width:480px;
                box-shadow:0 20px 60px rgba(0,0,0,0.35);">
            <div style="padding:20px 24px 0;">
                <h3 style="color:#dc2626; margin:0; font-size:18px;">
                    <i class="fas fa-lock"></i> Account Temporarily Locked
                </h3>
            </div>
            <div style="padding:16px 24px;">
                <div style="background:#fef2f2; border-left:4px solid #dc2626;
                        padding:14px 16px; border-radius:8px; margin-bottom:16px;">
                    <p id="lockoutMessage" style="margin:0 0 8px; color:#7f1d1d; font-size:14px;"></p>
                    <p style="margin:0; color:#991b1b; font-size:13px;">
                        <i class="fas fa-clock"></i>
                        Try again in <strong id="lockoutCountdown">—</strong>
                    </p>
                </div>
                <p style="font-size:13px; color:#64748b; margin:0 0 16px;">
                    Your account has been locked after too many failed
                    <span id="lockoutSourceLabel">security</span> attempts.
                    Please log out and try again later.
                </p>
            </div>
            <div style="padding:0 24px 20px; display:flex; justify-content:flex-end;">
                <button type="button" id="lockoutLogoutBtn" style="
                background:#dc2626; border:1px solid #dc2626; color:#fff;
                padding:10px 18px; border-radius:8px; font-weight:600; cursor:pointer;">
                    <i class="fas fa-sign-out-alt"></i> Log Out Now
                </button>
            </div>
        </div>
    </div>
    <script src="../../ui.js"></script>
    <script src="../../validator.js"></script>
    <script src="../scripts/profile.js">

    </script>
</body>

</html>