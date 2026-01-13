
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
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    
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
                    <!-- Avatar will be loaded here -->
                    <div class="skeleton" style="width: 140px; height: 140px; border-radius: 50%; margin: 0 auto 15px;"></div>
                    <div class="skeleton" style="width: 120px; height: 24px; margin: 0 auto 5px;"></div>
                    <div class="skeleton" style="width: 80px; height: 20px; margin: 0 auto 15px;"></div>
                    <div class="skeleton" style="width: 150px; height: 20px; margin: 0 auto 20px;"></div>
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
                    <div class="skeleton" style="width: 40px; height: 40px; border-radius: 50%; margin-right: 15px;"></div>
                    <div style="flex: 1;">
                        <div class="skeleton" style="height: 16px; width: 70%; margin-bottom: 5px;"></div>
                        <div class="skeleton" style="height: 12px; width: 40%;"></div>
                    </div>
                </li>
                <li class="activity-item">
                    <div class="skeleton" style="width: 40px; height: 40px; border-radius: 50%; margin-right: 15px;"></div>
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
    <div id="loadingOverlay" class="loading" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(255,255,255,0.9); z-index: 9999;">
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
        
        <div class="modal-body">
            <form id="editProfileForm" enctype="multipart/form-data">
                <!-- Current Password (Required for any changes) -->
                <div class="form-group">
                    <label for="currentPassword">
                        Current Password <span class="required">*</span>
                    </label>
                    <div class="input-with-icon">
                        <input type="password" 
                               id="currentPassword" 
                               name="current_password" 
                               required
                               placeholder="Enter your current password">
                        <i class="fas fa-lock input-icon"></i>
                        <button type="button" class="toggle-password" onclick="togglePassword('currentPassword')">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="form-help">
                        Required to verify your identity before making changes
                    </div>
                </div>
                
                <!-- Personal Information Section -->
                <div class="form-section">
                    <h3 class="section-title">Personal Information</h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="editFirstname">First Name</label>
                            <input type="text" 
                                   id="editFirstname" 
                                   name="firstname" 
                                   placeholder="Enter first name">
                        </div>
                        
                        <div class="form-group">
                            <label for="editLastname">Last Name</label>
                            <input type="text" 
                                   id="editLastname" 
                                   name="lastname" 
                                   placeholder="Enter last name">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="editPhone">Phone Number</label>
                        <input type="tel" 
                               id="editPhone" 
                               name="phone" 
                               placeholder="Enter phone number">
                    </div>
                    <div class="form-group">
                        <label for="editEmail">E-mail Address</label>
                        <input type="email" 
                               id="editEmail" 
                               name="email" 
                               placeholder="Enter E-mail address">
                    </div>
                    
                    <div class="form-group">
                        <label for="editAddress">Address</label>
                        <textarea id="editAddress" 
                                  name="address" 
                                  rows="3" 
                                  placeholder="Enter your address"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="editGender">Gender</label>
                        <select id="editGender" name="gender">
                            <option value="">Select Gender</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
                </div>
                
                <!-- Security Section -->
                <div class="form-section">
                    <h3 class="section-title">Security Settings</h3>
                    <div class="form-notice">
                        <i class="fas fa-exclamation-triangle"></i>
                        Changing security settings will log you out immediately
                    </div>
                    
                    <div class="form-group">
                        <label for="editPassword">New Password</label>
                        <div class="input-with-icon">
                            <input type="password" 
                                   id="editPassword" 
                                   name="password" 
                                   placeholder="Enter new password"
                                   >
                            <i class="fas fa-key input-icon"></i>
                            <button type="button" class="toggle-password" onclick="togglePassword('editPassword')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="form-help">
                            Minimum 8 characters with uppercase, lowercase, number, and special character
                        </div>
                        <div id="passwordStrength" class="password-strength">
                            <div class="strength-bar"></div>
                            <div class="strength-text"></div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="editConfirmPassword">Confirm New Password</label>
                        <div class="input-with-icon">
                            <input type="password" 
                                   id="editConfirmPassword" 
                                   name="confirm_password" 
                                   placeholder="Confirm new password">
                            <i class="fas fa-key input-icon"></i>
                            <button type="button" class="toggle-password" onclick="togglePassword('editConfirmPassword')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div id="passwordMatch" class="form-feedback"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="editSecretQuestion">Secret Question</label>
                        <select id="editSecretQuestion" name="secret_question">
                            <option value="">Select a secret question</option>
                            <option value="What was your first pet's name?">What was your first pet's name?</option>
                            <option value="What is your mother's maiden name?">What is your mother's maiden name?</option>
                            <option value="What city were you born in?">What city were you born in?</option>
                            <option value="What is your favorite book?">What is your favorite book?</option>
                            <option value="What was your first car?">What was your first car?</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="editSecretAnswer">Secret Answer</label>
                        <div class="input-with-icon">
                            <input type="password" 
                                   id="editSecretAnswer" 
                                   name="secret_answer" 
                                   placeholder="Enter secret answer">
                            <i class="fas fa-shield-alt input-icon"></i>
                            <button type="button" class="toggle-password" onclick="togglePassword('editSecretAnswer')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Profile Photo Section -->
                <div class="form-section">
                    <h3 class="section-title">Profile Photo</h3>
                    
                    <div class="photo-upload-container">
                        <div class="photo-preview" id="photoPreview">
                            <div class="photo-placeholder">
                                <i class="fas fa-user"></i>
                            </div>
                        </div>
                        
                        <div class="photo-upload-controls">
                            <label class="btn btn-outline btn-sm">
                                <i class="fas fa-camera"></i> Choose Photo
                                <input type="file" 
                                       id="editPhoto" 
                                       name="photo" 
                                       accept="image/*" 
                                       style="display: none;"
                                       onchange="previewPhoto(this)">
                            </label>
                            <button type="button" class="btn btn-outline btn-sm" onclick="removePhoto()">
                                <i class="fas fa-trash"></i> Remove
                            </button>
                        </div>
                        
                        <div class="form-help">
                            Recommended: Square image, max 2MB (JPG, PNG, GIF)
                        </div>
                    </div>
                </div>
                
                <!-- Form Actions -->
                <div class="form-actions">
                    <button type="button" class="btn btn-outline" onclick="closeEditModal()">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary" id="submitEditBtn">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
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
    </div>
    <script src="../../ui.js"></script>
    <script src="../../validator.js"></script>
    <script src = "../scripts/profile.js">
       
    </script>
</body>
</html>