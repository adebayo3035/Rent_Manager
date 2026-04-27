// profile.js - Optimized Tenant Profile Management

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
    console.log("Initializing profile...");

    if (window.currentUser?.firstname) {
        currentUser = window.currentUser;
        renderProfile();
    } else {
        // Listen for user data loaded event
        window.addEventListener("userDataLoaded", (e) => {
            currentUser = e.detail || window.currentUser;
            renderProfile();
        });

        // Fallback: fetch directly if event doesn't fire within 500ms
        setTimeout(async () => {
            if (!currentUser?.firstname) {
                await fetchUserData();
                renderProfile();
            }
        }, 500);
    }
}

async function fetchUserData() {
    try {
        const response = await fetch("../backend/tenant/fetch_user_data.php");
        const data = await response.json();

        if (data.success && data.data) {
            currentUser = data.data;
            window.currentUser = currentUser;
        } else {
            console.error("Failed to fetch user data:", data);
        }
    } catch (error) {
        console.error("Error fetching user data:", error);
    }
}

// ==================== RENDER PROFILE ====================
function renderProfile() {
    const contentArea = document.getElementById("contentArea");
    if (!contentArea) return;

    if (!currentUser?.firstname) {
        contentArea.innerHTML = `
            <div class="profile-container">
                <div class="loading-spinner">
                    <div class="spinner"></div>
                    <p>Loading profile data...</p>
                </div>
            </div>`;
        return;
    }

    const photoUrl = currentUser.photo 
        ? `../../admin/backend/tenants/tenant_photos/${currentUser.photo}` 
        : "";
    
    const showSecretTab = !currentUser.has_secret_set;
    const tabs = getTabsHtml(showSecretTab);

    contentArea.innerHTML = `
        <div class="profile-container">
            <div class="page-header">
                <h1>My Profile</h1>
                <p>Manage your personal information and account settings</p>
            </div>
            
            <div class="profile-card">
                <div class="profile-header">
                    <div class="profile-avatar">
                        <img src="${photoUrl}" alt="Tenant Photo" 
                             onerror="this.src='https://ui-avatars.com/api/?name=${currentUser.firstname}+${currentUser.lastname}&background=667eea&color=fff'"
                             style="width:120px;height:120px;object-fit:cover;border-radius:60px;border:3px solid #667eea;">
                    </div>
                    <div class="profile-name">${escapeHtml(currentUser.firstname)} ${escapeHtml(currentUser.lastname)}</div>
                    <div class="profile-role">Tenant</div>
                </div>
                
                ${tabs}
                
                <div id="personalTab" class="tab-content active">
                    ${renderPersonalTab()}
                </div>
                
                <div id="securityTab" class="tab-content">
                    ${renderSecurityTab()}
                </div>

                ${showSecretTab ? renderSecretTab() : renderSecretAlreadySetTab()}
            </div>
        </div>`;

    attachTabListeners();
}

function getTabsHtml(showSecretTab) {
    let tabsHtml = `
        <div class="tabs">
            <button class="tab-btn active" data-tab="personal">Personal Information</button>
            <button class="tab-btn" data-tab="security">Security</button>`;
    
    if (showSecretTab) {
        tabsHtml += ` <button class="tab-btn" data-tab="secret">Secret Question</button>`;
    }
    
    tabsHtml += `</div>`;
    return tabsHtml;
}

function renderPersonalTab() {
    return `
        <form class="profile-form" id="profileForm">
            <div class="form-row">
                <div class="form-group">
                    <label>First Name *</label>
                    <input type="text" id="firstname" value="${escapeHtml(currentUser.firstname)}" required>
                </div>
                <div class="form-group">
                    <label>Last Name *</label>
                    <input type="text" id="lastname" value="${escapeHtml(currentUser.lastname)}" required>
                </div>
            </div>
            <div class="form-group">
                <label>Email Address *</label>
                <input type="email" id="email" value="${escapeHtml(currentUser.email)}" required>
            </div>
            <div class="form-group">
                <label>Phone Number *</label>
                <input type="tel" id="phone" value="${escapeHtml(currentUser.phone)}" required>
            </div>
            <div class="form-group">
                <label>Gender</label>
                <select id="gender">
                    <option value="">Select gender</option>
                    <option value="Male" ${currentUser.gender === "Male" ? "selected" : ""}>Male</option>
                    <option value="Female" ${currentUser.gender === "Female" ? "selected" : ""}>Female</option>
                </select>
            </div>
            <div class="form-group">
                <label>Apartment Number</label>
                <input type="text" value="${escapeHtml(currentUser.apartment_number || "Not Assigned")}" readonly disabled>
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
                <label>Current Password *</label>
                <input type="password" id="currentPassword" required>
            </div>
            <div class="form-group">
                <label>New Password *</label>
                <input type="password" id="newPassword" required oninput="validatePassword()">
            </div>
            <div class="form-group">
                <label>Confirm New Password *</label>
                <input type="password" id="confirmPassword" required oninput="validatePasswordMatch()">
            </div>
            
            <div class="password-requirements">
                <strong>Password Requirements:</strong>
                <ul>
                    <li id="req-length">At least 8 characters</li>
                    <li id="req-upper">At least one uppercase letter</li>
                    <li id="req-lower">At least one lowercase letter</li>
                    <li id="req-number">At least one number</li>
                    <li id="req-match">Passwords match</li>
                </ul>
            </div>
            
            <button type="button" class="btn-save" onclick="changePassword()">
                <i class="fas fa-key"></i> Change Password
            </button>
        </form>`;
}

function renderSecretTab() {
    return `
        <div id="secretTab" class="tab-content">
            <form class="profile-form" id="secretForm">
                <div class="form-group">
                    <label>Secret Question *</label>
                    <select id="secretQuestion" required>
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
                    <label>Secret Answer *</label>
                    <input type="password" id="secretAnswer" required autocomplete="off">
                </div>
                <div class="form-group">
                    <label>Confirm Secret Answer *</label>
                    <input type="password" id="confirmAnswer" required autocomplete="off">
                </div>
                <button type="button" class="btn-save" onclick="setSecretAnswer()">
                    <i class="fas fa-shield-alt"></i> Set Secret Question
                </button>
            </form>
        </div>`;
}

function renderSecretAlreadySetTab() {
    return `
        <div id="secretTab" class="tab-content">
            <div class="profile-form" style="text-align: center; padding: 40px;">
                <i class="fas fa-check-circle" style="font-size: 64px; color: #10b981; margin-bottom: 20px;"></i>
                <h3>Security Question Already Set</h3>
                <p>Your security question has already been configured.</p>
                <p style="color: #666; font-size: 14px; margin-top: 10px;">
                    If you need to change your security question, please contact support.
                </p>
            </div>
        </div>`;
}

function attachTabListeners() {
    document.querySelectorAll(".tab-btn").forEach(btn => {
        btn.addEventListener("click", () => {
            const tab = btn.dataset.tab;
            document.querySelectorAll(".tab-btn").forEach(b => b.classList.remove("active"));
            document.querySelectorAll(".tab-content").forEach(c => c.classList.remove("active"));
            btn.classList.add("active");
            document.getElementById(`${tab}Tab`).classList.add("active");
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

    if (!firstname || !lastname || !email || !phone) {
        showToast("Please fill in all required fields", "error");
        return;
    }

    const btn = document.querySelector('#personalTab .btn-save');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    btn.disabled = true;

    try {
        const response = await fetch("../backend/tenant/update_profile.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ firstname, lastname, email, phone, gender }),
        });
        const data = await response.json();

        if (data.success) {
            showToast("Profile updated successfully", "success");
            
            if (currentUser) {
                currentUser.firstname = firstname;
                currentUser.lastname = lastname;
                currentUser.email = email;
                currentUser.phone = phone;
                currentUser.gender = gender;
                window.currentUser = currentUser;
            }
            
            renderProfile();
        } else {
            throw new Error(data.message || "Update failed");
        }
    } catch (error) {
        console.error("Error updating profile:", error);
        showToast(error.message, "error");
        btn.innerHTML = originalText;
        btn.disabled = false;
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
        element.textContent = isValid ? `✓ ${text}` : text;
    }
}

function isPasswordValid() {
    return Object.values(passwordValidation).every(v => v === true);
}

async function changePassword() {
    const currentPassword = document.getElementById("currentPassword")?.value;
    const newPassword = document.getElementById("newPassword")?.value;
    const confirmPassword = document.getElementById("confirmPassword")?.value;

    if (!currentPassword || !newPassword || !confirmPassword) {
        showToast("Please fill in all fields", "error");
        return;
    }

    if (newPassword !== confirmPassword) {
        showToast("New passwords do not match", "error");
        return;
    }

    if (!isPasswordValid()) {
        showToast("Please meet all password requirements", "error");
        return;
    }

    const btn = document.querySelector('#securityTab .btn-save');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Changing...';
    btn.disabled = true;

    try {
        const response = await fetch("../backend/tenant/change_password.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                current_password: currentPassword,
                new_password: newPassword,
            }),
        });
        const data = await response.json();

        if (data.success) {
            showToast("Password changed successfully", "success");
            document.getElementById("passwordForm")?.reset();
            resetPasswordValidation();
        } else {
            throw new Error(data.message || "Password change failed");
        }
    } catch (error) {
        console.error("Error changing password:", error);
        showToast(error.message, "error");
    } finally {
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
}

function resetPasswordValidation() {
    passwordValidation = { length: false, upper: false, lower: false, number: false, match: false };
    validatePassword();
}

// ==================== SECRET QUESTION FUNCTIONS ====================
async function setSecretAnswer() {
    const secretQuestion = document.getElementById("secretQuestion")?.value;
    const secretAnswer = document.getElementById("secretAnswer")?.value;
    const confirmAnswer = document.getElementById("confirmAnswer")?.value;

    if (!secretQuestion || !secretAnswer || !confirmAnswer) {
        showToast("Please fill in all fields", "error");
        return;
    }

    if (secretAnswer !== confirmAnswer) {
        showToast("Secret answers do not match", "error");
        return;
    }

    if (secretAnswer.length < 8) {
        showToast("Secret answer must be at least 8 characters", "error");
        return;
    }

    const btn = document.querySelector('#secretTab .btn-save');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    btn.disabled = true;

    try {
        const response = await fetch("../backend/tenant/set_secret_question.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                secret_question: secretQuestion,
                secret_answer: secretAnswer,
            }),
        });
        const data = await response.json();

        if (data.success) {
            showToast(data.message || "Secret question set successfully", "success");
            
            if (currentUser) {
                currentUser.has_secret_set = 1;
                window.currentUser = currentUser;
            }
            
            setTimeout(() => window.location.reload(), 2000);
        } else {
            throw new Error(data.message || "Failed to set secret question");
        }
    } catch (error) {
        console.error("Error setting secret question:", error);
        showToast(error.message, "error");
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
}

// ==================== UTILITY FUNCTIONS ====================
function showToast(message, type = "info") {
    if (window.showToast) {
        window.showToast(message, type);
    } else {
        alert(message);
    }
}

function escapeHtml(text) {
    if (!text) return "";
    const div = document.createElement("div");
    div.textContent = text;
    return div.innerHTML;
}