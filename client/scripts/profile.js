// client/scripts/profile.js - Complete Client Profile Management

// ==================== STATE MANAGEMENT ====================
let currentUser = null;
let passwordValidation = {
    length: false,
    upper: false,
    lower: false,
    number: false,
    match: false
};

// ==================== INITIALIZATION ====================
document.addEventListener("DOMContentLoaded", () => {
    initializeProfile();
});

async function initializeProfile() {
    console.log("Initializing client profile...");

    if (window.currentUser?.client_code) {
        currentUser = window.currentUser;
        renderProfile();
        return;
    }

    window.addEventListener("userDataLoaded", (event) => {
        currentUser = event.detail || window.currentUser;
        renderProfile();
    }, { once: true });

    setTimeout(async () => {
        if (!currentUser?.client_code) {
            await fetchUserData();
            renderProfile();
        }
    }, 500);
}

async function fetchUserData() {
    try {
        const response = await fetch("../backend/client/fetch_profile.php", {
            credentials: "include",
            headers: { "Accept": "application/json" }
        });
        const data = await response.json();

        if (data.success && data.data) {
            currentUser = data.data;
            window.currentUser = currentUser;
            console.log("User data loaded:", currentUser);
        } else {
            throw new Error(data.message || "Failed to fetch profile data");
        }
    } catch (error) {
        console.error("Error fetching profile data:", error);
        displayMessage(error.message, "error");
    }
}

// ==================== RENDER PROFILE ====================
function renderProfile() {
    const contentArea = document.getElementById("contentArea");
    if (!contentArea) return;

    if (!currentUser?.client_code) {
        contentArea.innerHTML = `
            <div class="profile-container">
                <div class="loading-spinner">
                    <div class="spinner"></div>
                    <p>Loading profile data...</p>
                </div>
            </div>`;
        return;
    }

    const fullName = `${currentUser.firstname || ""} ${currentUser.lastname || ""}`.trim() || "Client";
    const photoUrl = currentUser.photo
        ? `../../admin/backend/clients/client_photos/${currentUser.photo}`
        : `https://ui-avatars.com/api/?name=${encodeURIComponent(fullName)}&background=2563eb&color=fff`;

    contentArea.innerHTML = `
        <div class="profile-container">
            <div class="page-header">
                <h1>My Profile</h1>
                <p>Manage your personal information and account security.</p>
            </div>

            <div class="profile-card">
                <div class="profile-header">
                    <div class="profile-avatar">
                        <img src="${photoUrl}" alt="Client photo"
                             onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(fullName)}&background=2563eb&color=fff'">
                    </div>
                    <div class="profile-name">${escapeHtml(fullName)}</div>
                    <div class="profile-role">Client ID: ${escapeHtml(currentUser.client_code)}</div>
                </div>

                <div class="tabs">
                    <button class="tab-btn active" data-tab="personal">Personal Information</button>
                    <button class="tab-btn" data-tab="security">Change Password</button>
                    <button class="tab-btn" data-tab="resetSecret">Reset Secret Question & Answer</button>
                </div>

                <div id="personalTab" class="tab-content active">
                    ${renderPersonalTab()}
                </div>

                <div id="securityTab" class="tab-content">
                    ${renderSecurityTab()}
                </div>

                <div id="resetSecretTab" class="tab-content">
                    ${renderResetSecretTab()}
                </div>
            </div>
        </div>`;

    attachTabListeners();
}

function renderPersonalTab() {
    return `
        <form class="profile-form" id="profileForm">
            <div class="form-row">
                <div class="form-group">
                    <label for="firstname">First Name *</label>
                    <input type="text" id="firstname" value="${escapeHtml(currentUser.firstname)}" required>
                </div>
                <div class="form-group">
                    <label for="lastname">Last Name *</label>
                    <input type="text" id="lastname" value="${escapeHtml(currentUser.lastname)}" required>
                </div>
            </div>
            <div class="form-group">
                <label for="email">Email Address *</label>
                <input type="email" id="email" value="${escapeHtml(currentUser.email)}" required>
            </div>
            <div class="form-group">
                <label for="phone">Phone Number *</label>
                <input type="tel" id="phone" value="${escapeHtml(currentUser.phone)}" required>
            </div>
            <div class="form-group">
                <label for="address">Address</label>
                <textarea id="address" rows="3">${escapeHtml(currentUser.address)}</textarea>
            </div>
            <div class="form-group">
                <label for="gender">Gender</label>
                <select id="gender">
                    <option value="">Select gender</option>
                    <option value="Male" ${currentUser.gender === "Male" ? "selected" : ""}>Male</option>
                    <option value="Female" ${currentUser.gender === "Female" ? "selected" : ""}>Female</option>
                    <option value="Other" ${currentUser.gender === "Other" ? "selected" : ""}>Other</option>
                </select>
            </div>
            <button type="button" class="btn-save" onclick="updateProfile()">
                <i class="fas fa-save"></i> Update Profile
            </button>
        </form>`;
}

function renderSecurityTab() {
    return `
        <form class="profile-form" id="passwordForm">
            <div class="form-group">
                <label for="currentPassword">Current Password *</label>
                <input type="password" id="currentPassword" required autocomplete="current-password">
            </div>
            <div class="form-group">
                <label for="newPassword">New Password *</label>
                <input type="password" id="newPassword" required autocomplete="new-password" oninput="validatePassword()">
            </div>
            <div class="form-group">
                <label for="confirmPassword">Confirm New Password *</label>
                <input type="password" id="confirmPassword" required autocomplete="new-password" oninput="validatePasswordMatch()">
            </div>
            <div class="form-group">
                <label for="secretAnswer">Enter your Secret Answer *</label>
                <input type="password" id="secretAnswer" required autocomplete="off">
                <small style="color: #666; font-size: 12px;">Required to verify your identity</small>
            </div>

            <div class="password-requirements">
                <strong>Password Requirements:</strong>
                <ul>
                    <li id="req-length">✗ At least 8 characters</li>
                    <li id="req-upper">✗ At least one uppercase letter</li>
                    <li id="req-lower">✗ At least one lowercase letter</li>
                    <li id="req-number">✗ At least one number</li>
                    <li id="req-match">✗ Passwords match</li>
                </ul>
            </div>

            <button type="button" class="btn-save" onclick="changePassword()">
                <i class="fas fa-key"></i> Change Password
            </button>
        </form>`;
}

function renderResetSecretTab() {
    return `
        <form class="profile-form" id="resetSecretForm">
            <div class="alert-warning" style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px; margin-bottom: 20px; border-radius: 8px;">
                <i class="fas fa-exclamation-triangle" style="color: #d97706;"></i>
                <span style="color: #92400e; margin-left: 8px;">Reset your secret question and answer. This will change your security credentials.</span>
            </div>
            <div class="form-group">
                <label for="resetSecretQuestion">New Secret Question *</label>
                <select id="resetSecretQuestion" required>
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
            <div class="form-group">
                <label for="resetAnswer">New Secret Answer *</label>
                <input type="password" id="resetAnswer" required autocomplete="off">
                <small style="color: #666; font-size: 12px;">Minimum 8 characters</small>
            </div>
            <div class="form-group">
                <label for="confirmResetAnswer">Confirm New Secret Answer *</label>
                <input type="password" id="confirmResetAnswer" required autocomplete="off">
            </div>
            <div class="form-group">
                <label for="resetSecretPassword">Confirm with your Password *</label>
                <input type="password" id="resetSecretPassword" required autocomplete="current-password">
                <small style="color: #666; font-size: 12px;">Enter your current password to confirm changes</small>
            </div>
            <button type="button" class="btn-save" onclick="resetSecretAnswer()">
                <i class="fas fa-sync-alt"></i> Reset Secret Question & Answer
            </button>
        </form>`;
}

function attachTabListeners() {
    document.querySelectorAll(".tab-btn").forEach((button) => {
        button.addEventListener("click", () => {
            const tab = button.dataset.tab;
            document.querySelectorAll(".tab-btn").forEach(item => item.classList.remove("active"));
            document.querySelectorAll(".tab-content").forEach(item => item.classList.remove("active"));
            button.classList.add("active");
            const tabContent = document.getElementById(`${tab}Tab`);
            if (tabContent) tabContent.classList.add("active");
        });
    });
}

// ==================== PROFILE FUNCTIONS ====================
async function updateProfile() {
    const firstname = document.getElementById("firstname")?.value.trim();
    const lastname = document.getElementById("lastname")?.value.trim();
    const email = document.getElementById("email")?.value.trim();
    const phone = document.getElementById("phone")?.value.trim();
    const gender = document.getElementById("gender")?.value;
    const address = document.getElementById("address")?.value.trim();

    if (!firstname || !lastname || !email || !phone) {
        displayMessage("Please fill in all required fields", "error");
        return;
    }

    const button = document.querySelector("#personalTab .btn-save");
    const originalText = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    button.disabled = true;

    try {
        const response = await fetch("../backend/client/update_profile.php", {
            method: "POST",
            headers: { "Content-Type": "application/json", "Accept": "application/json" },
            credentials: "include",
            body: JSON.stringify({ firstname, lastname, email, phone, gender, address })
        });
        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.message || "Profile update failed");
        }

        // Update local user data
        Object.assign(currentUser, { firstname, lastname, email, phone, gender, address });
        window.currentUser = currentUser;
        
        displayMessage("Profile updated successfully", "success");
        
        // Refresh the profile display
        setTimeout(() => renderProfile(), 1000);
    } catch (error) {
        console.error("Error updating profile:", error);
        displayMessage(error.message, "error");
        button.innerHTML = originalText;
        button.disabled = false;
    }
}

// ==================== PASSWORD FUNCTIONS ====================
function validatePassword() {
    const password = document.getElementById("newPassword")?.value || "";

    passwordValidation.length = password.length >= 8;
    passwordValidation.upper = /[A-Z]/.test(password);
    passwordValidation.lower = /[a-z]/.test(password);
    passwordValidation.number = /[0-9]/.test(password);

    updateRequirementUI("req-length", passwordValidation.length, "At least 8 characters");
    updateRequirementUI("req-upper", passwordValidation.upper, "At least one uppercase letter");
    updateRequirementUI("req-lower", passwordValidation.lower, "At least one lowercase letter");
    updateRequirementUI("req-number", passwordValidation.number, "At least one number");
    
    validatePasswordMatch();
}

function validatePasswordMatch() {
    const password = document.getElementById("newPassword")?.value || "";
    const confirm = document.getElementById("confirmPassword")?.value || "";

    passwordValidation.match = password === confirm && password !== "";
    updateRequirementUI("req-match", passwordValidation.match, "Passwords match");
}

function updateRequirementUI(elementId, isValid, text) {
    const element = document.getElementById(elementId);
    if (element) {
        element.className = isValid ? "valid" : "";
        element.textContent = isValid ? `✓ ${text}` : `✗ ${text}`;
    }
}

function isPasswordValid() {
    return Object.values(passwordValidation).every(Boolean);
}

async function changePassword() {
    const currentPassword = document.getElementById("currentPassword")?.value;
    const newPassword = document.getElementById("newPassword")?.value;
    const confirmPassword = document.getElementById("confirmPassword")?.value;
    const secretAnswer = document.getElementById("secretAnswer")?.value;

    if (!currentPassword || !newPassword || !confirmPassword || !secretAnswer) {
        displayMessage("Please fill in all password fields", "error");
        return;
    }

    if (newPassword !== confirmPassword) {
        displayMessage("New passwords do not match", "error");
        return;
    }

    if (!isPasswordValid()) {
        displayMessage("Please meet all password requirements", "error");
        return;
    }

    const button = document.querySelector("#securityTab .btn-save");
    const originalText = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Changing Password...';
    button.disabled = true;

    try {
        // Step 1: Change password
        const response = await fetch("../backend/authentication/change_password.php", {
            method: "POST",
            headers: { "Content-Type": "application/json", "Accept": "application/json" },
            credentials: "include",
            body: JSON.stringify({
                current_password: currentPassword,
                new_password: newPassword,
                confirm_password: confirmPassword,
                secret_answer: secretAnswer
            })
        });
        
        const data = await response.json();

        console.log("Change password response:", data); // Debug log

        if (data.success) {
            displayMessage(data.message || "Password changed successfully!", "success");

            // Step 2: Call logout endpoint with client_code from response
            const clientCode = data.data?.client_code || data.client_code;
            const requireLogout = data.data?.require_logout || data.require_logout;

            console.log("Client code for logout:", clientCode); // Debug log

            if (requireLogout && clientCode) {
                await callLogoutEndpoint(clientCode);
            } else {
                console.warn("Logout not required or client code missing");
                // Fallback: clear local session data
                clearLocalSession();
            }

            // Step 3: Redirect to login page
            const redirectUrl = data.data?.redirect_url || data.redirect_url || "../login.php";
            setTimeout(() => {
                window.location.href = redirectUrl;
            }, 2000);
        } else {
            throw new Error(data.message || "Password change failed");
        }
    } catch (error) {
        console.error("Error changing password:", error);
        displayMessage(error.message, "error");
        button.innerHTML = originalText;
        button.disabled = false;
    }
}

// Helper function to call logout endpoint
async function callLogoutEndpoint(client_code) {
    console.log("Calling logout endpoint for client:", client_code);

    try {
        const logoutResponse = await fetch("../backend/authentication/logout.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ logout_id: client_code })
        });
        
        const logoutData = await logoutResponse.json();

        console.log("Logout response:", logoutData);

        if (logoutData.success) {
            console.log("Logout successful");
            // Clear any stored user data in localStorage/sessionStorage
            clearLocalSession();
            // Prevent back button access after logout
            preventBackButtonAccess();
        } else {
            console.warn("Logout failed:", logoutData.message);
            // Still try to clear local session even if logout API fails
            clearLocalSession();
        }
    } catch (error) {
        console.error("Error calling logout:", error);
        // Still try to clear local session
        clearLocalSession();
    }
}

// Helper function to clear local session data
function clearLocalSession() {
    // Clear any stored user data
    window.currentUser = null;
    currentUser = null;

    // Clear localStorage if you're using it
    localStorage.removeItem("userData");
    localStorage.removeItem("userPreferences");
    localStorage.removeItem("client_session");

    // Clear sessionStorage if you're using it
    sessionStorage.clear();

    console.log("Local session cleared");
}

// Prevent back button access after logout
function preventBackButtonAccess() {
    // Push a new state to history to prevent back button
    history.pushState(null, null, location.href);
    window.addEventListener("popstate", function () {
        history.pushState(null, null, location.href);
        window.location.href = "../login.php";
    });
}

// ==================== RESET SECRET QUESTION FUNCTION ====================
async function resetSecretAnswer() {
    const secretQuestion = document.getElementById("resetSecretQuestion")?.value;
    const secretAnswer = document.getElementById("resetAnswer")?.value;
    const confirmAnswer = document.getElementById("confirmResetAnswer")?.value;
    const password = document.getElementById("resetSecretPassword")?.value;

    // Validate all inputs
    if (!secretQuestion) {
        displayMessage("Please select a secret question", "error");
        return;
    }
    if (!secretAnswer) {
        displayMessage("Secret answer is required", "error");
        return;
    }
    if (!confirmAnswer) {
        displayMessage("Please confirm your secret answer", "error");
        return;
    }
    if (!password) {
        displayMessage("Your password is required to reset secret question", "error");
        return;
    }

    // Check if answers match
    if (secretAnswer !== confirmAnswer) {
        displayMessage("Secret answers do not match", "error");
        return;
    }

    // Check minimum length
    if (secretAnswer.length < 8) {
        displayMessage("Secret answer must be at least 8 characters", "error");
        return;
    }

    const button = document.querySelector("#resetSecretTab .btn-save");
    const originalText = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Resetting...';
    button.disabled = true;

    try {
        const response = await fetch("../backend/authentication/reset_secret_question_answer.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            credentials: "include",
            body: JSON.stringify({
                secret_question: secretQuestion,
                secret_answer: secretAnswer,
                confirm_answer: confirmAnswer,
                password: password
            })
        });
        
        const data = await response.json();

        if (data.success) {
            displayMessage(data.message || "Secret question reset successfully", "success");

            // Update user data
            if (currentUser) {
                currentUser.has_secret_set = 1;
                currentUser.secret_question = data.secret_question;
                window.currentUser = currentUser;
            }

            // Clear the form
            document.getElementById("resetSecretForm")?.reset();
            
            // Refresh profile
            setTimeout(() => renderProfile(), 1500);
        } else {
            throw new Error(data.message || "Failed to reset secret question");
        }
    } catch (error) {
        console.error("Error resetting secret question:", error);
        displayMessage(error.message, "error");
        button.innerHTML = originalText;
        button.disabled = false;
    }
}

// ==================== UTILITY FUNCTIONS ====================
function displayMessage(message, type = "info") {
    if (window.showToast && typeof window.showToast === "function") {
        window.showToast(message, type);
    } else {
        // Simple fallback toast
        const toast = document.createElement("div");
        toast.className = `toast-notification ${type}`;
        toast.innerHTML = `
            <div class="toast-content">
                <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i>
                <span>${message}</span>
            </div>
        `;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 3000);
    }
}

function escapeHtml(text) {
    if (text === null || text === undefined) return "";
    const div = document.createElement("div");
    div.textContent = String(text);
    return div.innerHTML;
}

// ==================== EXPOSE GLOBAL FUNCTIONS ====================
window.updateProfile = updateProfile;
window.validatePassword = validatePassword;
window.validatePasswordMatch = validatePasswordMatch;
window.changePassword = changePassword;
window.resetSecretAnswer = resetSecretAnswer;