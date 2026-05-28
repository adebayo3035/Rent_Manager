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

    <div class="force-password-modal" id="forcePasswordModal" aria-hidden="true">
        <div class="force-password-dialog" role="dialog" aria-modal="true" aria-labelledby="forcePasswordTitle">
            <div class="force-password-header">
                <div class="force-password-icon">
                    <i class="fas fa-key"></i>
                </div>
                <div>
                    <p class="force-password-kicker">Account Security</p>
                    <h3 id="forcePasswordTitle">Change Your Default Security Details</h3>
                </div>
            </div>

            <div class="force-password-notice">
                <i class="fas fa-exclamation-triangle"></i>
                <span>This is your first login. Create a private password before continuing.</span>
            </div>

            <form id="forcePasswordForm" class="force-password-form">
                <div class="force-field">
                    <label for="forceNewPassword">New Password</label>
                    <div class="force-input-wrap">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="forceNewPassword" autocomplete="new-password" required>
                    </div>
                </div>

                <div class="force-field">
                    <label for="forceConfirmPassword">Confirm New Password</label>
                    <div class="force-input-wrap">
                        <i class="fas fa-shield-alt"></i>
                        <input type="password" id="forceConfirmPassword" autocomplete="new-password" required>
                    </div>
                </div>
                <div class="force-field">
                    <label>Secret Question *</label>
                    <div class="force-input-wrap">
                        <i class="fas fa-question"></i>
                        <select id="newSecretQuestion" required>
                            <option value="">-- Select a Secret Question --</option>
                            <option value="mother_maiden_name">What is your mother's maiden name?</option>
                            <option value="first_pet">What was the name of your first pet?</option>
                            <option value="first_school">What was the name of your first school?</option>
                            <option value="birth_city">In which city were you born?</option>
                            <option value="favorite_teacher">What is the name of your favorite teacher?</option>
                            <option value="childhood_friend">What is the name of your childhood best friend?</option>
                            <option value="first_car">What was your first car?</option>
                            <option value="favorite_food">What is your favorite food?</option>
                            <option value="dream_job">What was your dream job as a child?</option>
                            <option value="favorite_place">What is your favorite place to visit?</option>
                        </select>
                    </div>

                </div>
                <div class="force-field">
                    <label>Secret Answer *</label>
                    <div class="force-input-wrap">
                        <i class="fas fa-reply"></i>
                        <input type="password" id="newAnswer" required autocomplete="off">
                    </div>
                </div>
                <div class="force-field">
                    <label>Confirm Secret Answer *</label>
                    <div class="force-input-wrap">
                        <i class="fas fa-reply"></i>
                        <input type="password" id="confirmNewAnswer" required autocomplete="off">
                    </div>
                </div>


                <div class="password-strength">
                    <div class="strength-meter">
                        <div id="strengthBar"></div>
                    </div>
                    <div id="strengthText">Password strength: Weak</div>
                </div>

                <div class="password-requirements">
                    <strong>Password Requirements</strong>
                    <ul>
                        <li id="req-length">At least 8 characters</li>
                        <li id="req-upper">At least one uppercase letter</li>
                        <li id="req-lower">At least one lowercase letter</li>
                        <li id="req-number">At least one number</li>
                    </ul>
                </div>
            </form>

            <div class="force-password-footer">
                <button class="force-password-submit" id="forcePasswordSubmit" type="button"
                    onclick="submitForcePasswordChange()">
                    <i class="fas fa-check-circle"></i>
                    <span>Change Password & Continue</span>
                </button>
            </div>
        </div>
    </div>

    <style>
        #forcePasswordModal {
            display: none;
        }

        .force-password-modal {
            position: fixed;
            inset: 0;
            z-index: 10050;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(15, 23, 42, 0.58);
            backdrop-filter: blur(4px);
        }

        .force-password-modal.active {
            display: flex !important;
        }

        .force-password-dialog {
            width: min(100%, 480px);
            max-height: calc(100vh - 40px);
            overflow-y: auto;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.26);
        }

        .force-password-header {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 22px 24px 18px;
            border-bottom: 1px solid #eef2f7;
        }

        .force-password-icon {
            width: 46px;
            height: 46px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            background: #2563eb;
            flex: 0 0 auto;
        }

        .force-password-kicker {
            margin: 0 0 4px;
            color: #2563eb;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .force-password-header h3 {
            margin: 0;
            color: #1a1f36;
            font-size: 20px;
            line-height: 1.2;
        }

        .force-password-notice {
            display: flex;
            gap: 10px;
            margin: 18px 24px 0;
            padding: 12px 14px;
            color: #92400e;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 8px;
            font-size: 13px;
            line-height: 1.45;
        }

        .force-password-form {
            padding: 18px 24px 0;
        }

        .force-field {
            margin-bottom: 15px;
        }

        .force-field label {
            display: block;
            margin-bottom: 7px;
            color: #334155;
            font-size: 13px;
            font-weight: 700;
        }

        .force-input-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
            height: 44px;
            padding: 0 12px;
            border: 1px solid #dbe3ef;
            border-radius: 8px;
            background: #ffffff;
        }

        .force-input-wrap:focus-within {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }

        .force-input-wrap i {
            color: #64748b;
            font-size: 14px;
        }

        .force-input-wrap input,  .force-input-wrap select {
            width: 100%;
            min-width: 0;
            border: 0;
            outline: 0;
            color: #1a1f36;
            font-size: 14px;
            background: transparent;
        }

        .password-strength {
            margin: 4px 0 16px;
        }

        .strength-meter {
            height: 6px;
            overflow: hidden;
            background: #e5e7eb;
            border-radius: 999px;
        }

        #strengthBar {
            width: 0%;
            height: 100%;
            background: #dc2626;
            transition: width 0.25s ease, background 0.25s ease;
        }

        #strengthText {
            margin-top: 7px;
            color: #64748b;
            font-size: 12px;
        }

        .password-requirements {
            padding: 14px;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
        }

        .password-requirements strong {
            display: block;
            margin-bottom: 9px;
            color: #1a1f36;
            font-size: 13px;
        }

        .password-requirements ul {
            display: grid;
            gap: 7px;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .password-requirements li {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #64748b;
            font-size: 12px;
        }

        .password-requirements li::before {
            content: "x";
            width: 16px;
            height: 16px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            background: #94a3b8;
            font-size: 10px;
            font-weight: 800;
            line-height: 1;
        }

        .password-requirements li.valid {
            color: #047857;
        }

        .password-requirements li.valid::before {
            content: "ok";
            background: #10b981;
            font-size: 8px;
        }

        .force-password-footer {
            padding: 18px 24px 24px;
        }

        .force-password-submit {
            width: 100%;
            min-height: 44px;
            border: 0;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            color: #ffffff;
            background: #2563eb;
            font-size: 14px;
            font-weight: 800;
            cursor: pointer;
            transition: background 0.2s, transform 0.2s;
        }

        .force-password-submit:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
        }

        .force-password-submit:disabled {
            cursor: not-allowed;
            opacity: 0.65;
            transform: none;
        }

        @media (max-width: 520px) {
            .force-password-modal {
                align-items: flex-start;
                padding: 14px;
            }

            .force-password-header,
            .force-password-form,
            .force-password-footer {
                padding-left: 18px;
                padding-right: 18px;
            }

            .force-password-notice {
                margin-left: 18px;
                margin-right: 18px;
            }
        }
    </style>
    <script src="../scripts/navbar.js"></script>
</body>

</html>
