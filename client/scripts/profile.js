// client/scripts/profile.js - Complete Client Profile Management

// ==================== STATE MANAGEMENT ====================
let currentUser = null;
let passwordValidation = {
    length: false,
    upper:  false,
    lower:  false,
    number: false,
    match:  false,
};

// ==================== INITIALIZATION ====================
document.addEventListener("DOMContentLoaded", () => {
    initializeProfile();
});

async function initializeProfile() {
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
            headers: { "Accept": "application/json" },
        });
        const data = await response.json();

        if (data.success && data.data) {
            currentUser = data.data;
            window.currentUser = currentUser;
        } else {
            throw new Error(data.message || "Failed to fetch profile data");
        }
    } catch (error) {
        console.error("Error fetching profile data:", error);
        displayMessage(error.message, "error");
    }
}

// ============================================================
// SHARED HELPERS
// ============================================================

/**
 * Fetch a CSRF token for the given form.
 */
async function getCsrfToken(formName) {
    try {
        const res = await fetch(
            `../backend/utilities/get_token.php?form=${encodeURIComponent(formName)}`,
            { method: "POST", credentials: "include" }
        );
        const data = await res.json();
        return data.success ? data.token : null;
    } catch (err) {
        console.error("CSRF token fetch failed:", err);
        return null;
    }
}

/**
 * POST JSON to an authenticated API endpoint with CSRF token handling.
 *
 * Returns { ok, status, data }:
 *   - ok:     true when HTTP 2xx AND body.success === true
 *   - status: HTTP status code (for branching)
 *   - data:   parsed JSON body (or { message } on non-JSON response)
 *
 * Does NOT redirect, log out, or show toasts. Caller decides.
 */
async function apiRequest(endpoint, body = {}, options = {}) {
    const { csrfForm = null, method = "POST" } = options;

    // Attach CSRF if requested
    if (csrfForm) {
        const csrf = await getCsrfToken(csrfForm);
        if (csrf) {
            body.csrf_token = csrf;
            body.token_id   = csrfForm;
        }
    }

    let response;
    try {
        response = await fetch(endpoint, {
            method,
            headers: { "Content-Type": "application/json", "Accept": "application/json" },
            credentials: "include",
            body: JSON.stringify(body),
        });
    } catch (err) {
        // Network-level failure (offline, DNS, CORS, etc.)
        return {
            ok: false,
            status: 0,
            data: { success: false, message: "Network error. Check your connection." },
        };
    }

    // Non-JSON response = PHP fatal, unexpected redirect, etc.
    const contentType = response.headers.get("content-type") || "";
    if (!contentType.includes("application/json")) {
        const text = await response.text().catch(() => "");
        console.error("Non-JSON response:", {
            url: endpoint,
            status: response.status,
            bodySnippet: text.slice(0, 500),
        });
        return {
            ok: false,
            status: response.status,
            data: {
                success: false,
                message: "Server returned an unexpected response. Please try again.",
            },
        };
    }

    const data = await response.json().catch(() => ({
        success: false,
        message: "Malformed response from server.",
    }));

    return {
        ok:     response.ok && data.success === true,
        status: response.status,
        data,
    };
}

/**
 * Force-logout the current client and redirect to login with a reason.
 * Idempotent — safe to call even if the session is already dead.
 */
async function forceLogoutAndRedirect(reason = "account_locked", delayMs = 2000) {
    const clientCode = window.currentUser?.client_code || currentUser?.client_code;

    if (clientCode) {
        try {
            await callLogoutEndpoint(clientCode);
        } catch (err) {
            console.error("Forced logout failed:", err);
            clearLocalSession();
        }
    } else {
        clearLocalSession();
    }

    setTimeout(() => {
        window.location.href = `../login.php?reason=${encodeURIComponent(reason)}`;
    }, delayMs);
}

/**
 * Handle universal error cases shared across all credential endpoints.
 *
 * Returns true if the error was handled here (caller should return).
 * Returns false if the caller needs to handle it (e.g. field-specific).
 *
 * @param {number} status HTTP status code
 * @param {object} data   Response body
 * @param {object} opts   { button, originalText, errorCodeFallback? }
 */
async function handleAuthError(status, data, opts = {}) {
    const { button, originalText } = opts;

    // ---- 423 Locked: force logout + redirect ----
    if (status === 423) {
        displayMessage(data.message || "Account temporarily locked.", "error");
        if (button) {
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Logging out...';
            button.disabled = true;
        }
        await forceLogoutAndRedirect("account_locked", 2000);
        return true;
    }

    // ---- 429 Cooldown (reset_secret only, but harmless elsewhere) ----
    if (status === 429) {
        const nextAllowed = data.data?.next_allowed;
        displayMessage(
            nextAllowed
                ? `You can try again after ${nextAllowed}.`
                : (data.message || "Too many requests. Please wait."),
            "error"
        );
        restoreButton(button, originalText);
        return true;
    }

    // ---- 401: wrong credentials ----
    if (status === 401) {
        displayMessage(data.message || "Incorrect credentials.", "error");
        if (opts.clearFieldId) {
            const input = document.getElementById(opts.clearFieldId);
            if (input) { input.value = ""; input.focus(); }
        }
        restoreButton(button, originalText);
        return true;
    }

    // ---- 409: conflict (email/phone in use) ----
    if (status === 409) {
        displayMessage(data.message || "A conflict occurred. Please refresh and try again.", "error");
        restoreButton(button, originalText);
        return true;
    }

    // ---- Everything else ----
    displayMessage(data.message || "Something went wrong.", "error");
    restoreButton(button, originalText);
    return true;
}

/**
 * Restore a submit button to its original state.
 */
function restoreButton(button, originalText) {
    if (!button) return;
    button.innerHTML = originalText;
    button.disabled = false;
}

/**
 * Toggle a submit button into "in progress" state.
 * Returns the original innerHTML so callers can restore it later.
 */
function setButtonLoading(button, text = "Saving...") {
    if (!button) return "";
    const original = button.innerHTML;
    button.innerHTML = `<i class="fas fa-spinner fa-spin"></i> ${text}`;
    button.disabled = true;
    return original;
}

// ============================================================
// RENDER PROFILE
// ============================================================
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

                <div id="personalTab" class="tab-content active">${renderPersonalTab()}</div>
                <div id="securityTab" class="tab-content">${renderSecurityTab()}</div>
                <div id="resetSecretTab" class="tab-content">${renderResetSecretTab()}</div>
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
                <input type="email" id="email" value="${escapeHtml(currentUser.email)}"
                       required oninput="checkIfReauthNeeded()">
            </div>
            <div class="form-group">
                <label for="phone">Phone Number *</label>
                <input type="tel" id="phone" value="${escapeHtml(currentUser.phone)}"
                       required oninput="checkIfReauthNeeded()">
            </div>
            <div class="form-group">
                <label for="address">Address</label>
                <textarea id="address" rows="3">${escapeHtml(currentUser.address || '')}</textarea>
            </div>
            <div class="form-group">
                <label for="gender">Gender</label>
                <select id="gender">
                    <option value="">Select gender</option>
                    <option value="Male"   ${currentUser.gender === "Male"   ? "selected" : ""}>Male</option>
                    <option value="Female" ${currentUser.gender === "Female" ? "selected" : ""}>Female</option>
                    <option value="Other"  ${currentUser.gender === "Other"  ? "selected" : ""}>Other</option>
                </select>
            </div>

            <div class="form-group" id="reauthPasswordGroup" style="display:none;">
                <label for="profileCurrentPassword">Current Password *</label>
                <input type="password" id="profileCurrentPassword" autocomplete="current-password">
                <small style="color: #666; font-size: 12px;">
                    <i class="fas fa-lock" style="font-size: 10px;"></i>
                    Password required because you're changing your email or phone number.
                </small>
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

// ============================================================
// REAUTH TOGGLE
// ============================================================
function checkIfReauthNeeded() {
    const email = (document.getElementById("email")?.value || "").trim();
    const phone = (document.getElementById("phone")?.value || "").trim();

    const originalEmail = (currentUser?.email || "").toLowerCase();
    const originalPhone = (currentUser?.phone || "");

    const needsPassword =
        email.toLowerCase() !== originalEmail ||
        phone !== originalPhone;

    const group = document.getElementById("reauthPasswordGroup");
    if (!group) return;

    if (needsPassword) {
        group.style.display = "block";
    } else {
        group.style.display = "none";
        const input = document.getElementById("profileCurrentPassword");
        if (input) input.value = "";
    }
}

// ============================================================
// PROFILE UPDATE
// ============================================================
async function updateProfile() {
    const firstname = document.getElementById("firstname")?.value.trim();
    const lastname  = document.getElementById("lastname")?.value.trim();
    const email     = document.getElementById("email")?.value.trim();
    const phone     = document.getElementById("phone")?.value.trim();
    const gender    = document.getElementById("gender")?.value;
    const address   = document.getElementById("address")?.value.trim();

    if (!firstname || !lastname || !email || !phone) {
        displayMessage("Please fill in all required fields", "error");
        return;
    }

    const emailChanged = (currentUser?.email || "").toLowerCase() !== email.toLowerCase();
    const phoneChanged = (currentUser?.phone || "") !== phone;
    const needsReauth  = emailChanged || phoneChanged;
    const currentPassword = document.getElementById("profileCurrentPassword")?.value || "";

    if (needsReauth && !currentPassword) {
        displayMessage("Please enter your current password to change your email or phone", "error");
        return;
    }

    const button = document.querySelector("#personalTab .btn-save");
    const originalText = setButtonLoading(button, "Saving...");

    const body = { firstname, lastname, email, phone, gender, address };
    if (needsReauth) body.current_password = currentPassword;

    const { ok, status, data } = await apiRequest(
        "../backend/client/update_profile.php",
        body,
        { csrfForm: "client_profile_form" }
    );

    if (ok) {
        Object.assign(currentUser, { firstname, lastname, email, phone, gender, address });
        window.currentUser = currentUser;
        displayMessage("Profile updated successfully", "success");
        setTimeout(() => renderProfile(), 1000);
        return;
    }

    await handleAuthError(status, data, {
        button,
        originalText,
        clearFieldId: status === 401 ? "profileCurrentPassword" : null,
    });
}

// ============================================================
// PASSWORD CHANGE
// ============================================================
function validatePassword() {
    const password = document.getElementById("newPassword")?.value || "";

    passwordValidation.length = password.length >= 8;
    passwordValidation.upper  = /[A-Z]/.test(password);
    passwordValidation.lower  = /[a-z]/.test(password);
    passwordValidation.number = /[0-9]/.test(password);

    updateRequirementUI("req-length", passwordValidation.length, "At least 8 characters");
    updateRequirementUI("req-upper",  passwordValidation.upper,  "At least one uppercase letter");
    updateRequirementUI("req-lower",  passwordValidation.lower,  "At least one lowercase letter");
    updateRequirementUI("req-number", passwordValidation.number, "At least one number");

    validatePasswordMatch();
}

function validatePasswordMatch() {
    const password = document.getElementById("newPassword")?.value || "";
    const confirm  = document.getElementById("confirmPassword")?.value || "";

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
    const currentPassword  = document.getElementById("currentPassword")?.value;
    const newPassword      = document.getElementById("newPassword")?.value;
    const confirmPassword  = document.getElementById("confirmPassword")?.value;
    const secretAnswer     = document.getElementById("secretAnswer")?.value;

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
    const originalText = setButtonLoading(button, "Changing Password...");

    const { ok, status, data } = await apiRequest(
        "../backend/authentication/change_password.php",
        {
            current_password: currentPassword,
            new_password:     newPassword,
            confirm_password: confirmPassword,
            secret_answer:    secretAnswer,
        },
        { csrfForm: "client_change_password" }
    );

    if (ok) {
        displayMessage(data.message || "Password changed successfully!", "success");

        const clientCode    = data.data?.client_code    || data.client_code;
        const requireLogout = data.data?.require_logout || data.require_logout;

        if (requireLogout && clientCode) {
            await callLogoutEndpoint(clientCode);
        } else {
            clearLocalSession();
        }

        const redirectUrl = data.data?.redirect_url || data.redirect_url || "../pages/index.php";
        setTimeout(() => { window.location.href = redirectUrl; }, 2000);
        return;
    }

    await handleAuthError(status, data, {
        button,
        originalText,
        clearFieldId: status === 401 ? "currentPassword" : null,
    });
}

// ============================================================
// RESET SECRET QUESTION
// ============================================================
async function resetSecretAnswer() {
    const secretQuestion = document.getElementById("resetSecretQuestion")?.value;
    const secretAnswer   = document.getElementById("resetAnswer")?.value;
    const confirmAnswer  = document.getElementById("confirmResetAnswer")?.value;
    const password       = document.getElementById("resetSecretPassword")?.value;

    if (!secretQuestion) return displayMessage("Please select a secret question", "error");
    if (!secretAnswer)   return displayMessage("Secret answer is required", "error");
    if (!confirmAnswer)  return displayMessage("Please confirm your secret answer", "error");
    if (!password)       return displayMessage("Your password is required to reset secret question", "error");
    if (secretAnswer !== confirmAnswer) return displayMessage("Secret answers do not match", "error");
    if (secretAnswer.length < 8)       return displayMessage("Secret answer must be at least 8 characters", "error");

    const button = document.querySelector("#resetSecretTab .btn-save");
    const originalText = setButtonLoading(button, "Resetting...");

    const { ok, status, data } = await apiRequest(
        "../backend/authentication/reset_secret_question_answer.php",
        {
            secret_question: secretQuestion,
            secret_answer:   secretAnswer,
            confirm_answer:  confirmAnswer,
            password:        password,
        },
        { csrfForm: "client_reset_secret" }
    );

    if (ok) {
        displayMessage(data.message || "Secret question reset successfully", "success");

        if (currentUser) {
            currentUser.has_secret_set  = 1;
            currentUser.secret_question = data.secret_question;
            window.currentUser = currentUser;
        }

        document.getElementById("resetSecretForm")?.reset();
        setTimeout(() => renderProfile(), 1500);
        return;
    }

    await handleAuthError(status, data, {
        button,
        originalText,
        clearFieldId: status === 401 ? "resetSecretPassword" : null,
    });
}

// ============================================================
// LOGOUT HELPERS
// ============================================================
async function callLogoutEndpoint(client_code) {
    try {
        const logoutResponse = await fetch("../backend/authentication/logout.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ logout_id: client_code }),
        });
        const logoutData = await logoutResponse.json();

        if (logoutData.success) {
            clearLocalSession();
            preventBackButtonAccess();
        } else {
            clearLocalSession();
        }
    } catch (error) {
        console.error("Error calling logout:", error);
        clearLocalSession();
    }
}

function clearLocalSession() {
    window.currentUser = null;
    currentUser = null;

    localStorage.removeItem("userData");
    localStorage.removeItem("userPreferences");
    localStorage.removeItem("client_session");

    sessionStorage.clear();
}

function preventBackButtonAccess() {
    history.pushState(null, null, location.href);
    window.addEventListener("popstate", function () {
        history.pushState(null, null, location.href);
        window.location.href = "../pages/index.php";
    });
}

// ============================================================
// UTILITY
// ============================================================
function displayMessage(message, type = "info") {
    if (window.showToast && typeof window.showToast === "function") {
        window.showToast(message, type);
        return;
    }
    const toast = document.createElement("div");
    toast.className = `toast-notification ${type}`;
    toast.innerHTML = `
        <div class="toast-content">
            <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i>
            <span>${escapeHtml(message)}</span>
        </div>
    `;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

function escapeHtml(text) {
    if (text === null || text === undefined) return "";
    const div = document.createElement("div");
    div.textContent = String(text);
    return div.innerHTML;
}

// ============================================================
// EXPOSE GLOBALS
// ============================================================
window.updateProfile         = updateProfile;
window.validatePassword      = validatePassword;
window.validatePasswordMatch = validatePasswordMatch;
window.changePassword        = changePassword;
window.resetSecretAnswer     = resetSecretAnswer;
window.checkIfReauthNeeded   = checkIfReauthNeeded;