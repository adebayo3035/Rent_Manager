// tenant/scripts/profile.js - Optimized Tenant Profile Management

// ==================== STATE MANAGEMENT ====================
let currentUser = null;
let passwordValidation = {
    length: false,
    upper:  false,
    lower:  false,
    number: false,
    match:  false,
};

// Lockout state (shared with wallet module via localStorage is optional;
// kept local here since profile page is a separate route)
let lockoutState = {
    active: false,
    lockedUntil: null, // Date
    countdownTimer: null,
    source: null, // 'password' | 'secret_answer' | null
};

// ==================== INITIALIZATION ====================
document.addEventListener("DOMContentLoaded", () => {
    initializeProfile();
});

async function initializeProfile() {
    if (window.currentUser?.firstname) {
        currentUser = window.currentUser;
        renderProfile();
        return;
    }

    window.addEventListener("userDataLoaded", (e) => {
        currentUser = e.detail || window.currentUser;
        renderProfile();
    }, { once: true });

    setTimeout(async () => {
        if (!currentUser?.firstname) {
            await fetchUserData();
            renderProfile();
        }
    }, 500);
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

// ============================================================
// SHARED HELPERS
// ============================================================

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
 * Returns { ok, status, data }. Caller decides what to do.
 */
async function apiRequest(endpoint, body = {}, options = {}) {
    const { csrfForm = null, method = "POST" } = options;

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
            credentials: "same-origin",
            body: JSON.stringify(body),
        });
    } catch (err) {
        return {
            ok: false,
            status: 0,
            data: { success: false, message: "Network error. Check your connection." },
        };
    }

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

async function forceLogoutAndRedirect(reason = "account_locked", delayMs = 2000) {
    const tenantCode = window.currentUser?.tenant_code || currentUser?.tenant_code;

    if (tenantCode) {
        try {
            await callLogoutEndpoint(tenantCode);
        } catch (err) {
            console.error("Forced logout failed:", err);
            clearLocalSession();
        }
    } else {
        clearLocalSession();
    }

    setTimeout(() => {
        window.location.href = `../pages/index.php?reason=${encodeURIComponent(reason)}`;
    }, delayMs);
}

function restoreButton(button, originalText) {
    if (!button) return;
    button.innerHTML = originalText;
    button.disabled = false;
}

function setButtonLoading(button, text = "Saving...") {
    if (!button) return "";
    const original = button.innerHTML;
    button.innerHTML = `<i class="fas fa-spinner fa-spin"></i> ${text}`;
    button.disabled = true;
    return original;
}

// ============================================================
// LOCKOUT MODAL (shared by password + secret answer)
// ============================================================

/**
 * Parse a lockout end-time from an API response.
 * Returns Date or null.
 */
function parseLockoutUntil(data) {
    const raw =
        data?.data?.locked_until ||
        data?.data?.next_allowed ||
        data?.locked_until ||
        null;

    if (!raw) return null;

    const d = new Date(raw);
    if (!isNaN(d.getTime())) return d;

    const msg = data?.message || "";
    const minMatch = msg.match(/(\d+)\s*min/i);
    const secMatch = msg.match(/(\d+)\s*sec/i);
    if (minMatch || secMatch) {
        const mins = parseInt(minMatch?.[1] || "0", 10);
        const secs = parseInt(secMatch?.[1] || "0", 10);
        return new Date(Date.now() + (mins * 60 + secs) * 1000);
    }

    // Fallback default (15 min for secret-answer, 60 min for password)
    const defaultMins = lockoutState.source === 'password' ? 60 : 15;
    return new Date(Date.now() + defaultMins * 60 * 1000);
}

function formatRemaining(ms) {
    if (ms <= 0) return "now";
    const totalSec = Math.ceil(ms / 1000);
    const mins = Math.floor(totalSec / 60);
    const secs = totalSec % 60;
    if (mins > 0) return `${mins}m ${secs.toString().padStart(2, "0")}s`;
    return `${secs}s`;
}

/**
 * Show the lockout modal, with a live countdown.
 * Idempotent — repeated calls just refresh the countdown.
 *
 * @param {string} message
 * @param {Date|null} lockedUntilDate
 * @param {'password'|'secret_answer'|null} source
 */
function showLockoutModal(message, lockedUntilDate, source = null) {
    if (!lockedUntilDate) {
        const defaultMins = source === 'password' ? 60 : 15;
        lockedUntilDate = new Date(Date.now() + defaultMins * 60 * 1000);
    }

    lockoutState.active = true;
    lockoutState.lockedUntil = lockedUntilDate;
    lockoutState.source = source;

    const sourceLabel = source === 'password'
        ? "password"
        : source === 'secret_answer'
            ? "secret-answer"
            : "security";

    let overlay = document.getElementById("lockoutModal");
    if (!overlay) {
        overlay = document.createElement("div");
        overlay.id = "lockoutModal";
        overlay.className = "wallet-modal lockout-modal";
        overlay.style.cssText = `
            position: fixed; inset: 0; background: rgba(15, 23, 42, 0.55);
            z-index: 9999; display: none; align-items: center;
            justify-content: center; padding: 20px;
        `;
        overlay.innerHTML = `
          <div class="wallet-modal-content" style="
              background:#fff; border-radius:12px; width:100%; max-width:480px;
              box-shadow: 0 20px 60px rgba(0,0,0,0.25);
          ">
            <div class="wallet-modal-header" style="
                padding:20px 24px 0;
            ">
              <h3 style="color:#dc2626; margin:0; font-size:18px;">
                <i class="fas fa-lock"></i> Account Temporarily Locked
              </h3>
            </div>
            <div class="wallet-modal-body" style="padding:16px 24px;">
              <div style="
                  background:#fef2f2; border-left:4px solid #dc2626;
                  padding:14px 16px; border-radius:8px; margin-bottom:16px;
              ">
                <p id="lockoutMessage" style="
                    margin:0 0 8px; color:#7f1d1d; font-size:14px; line-height:1.5;
                "></p>
                <p style="margin:0; color:#991b1b; font-size:13px;">
                  <i class="fas fa-clock"></i>
                  Try again in <strong id="lockoutCountdown">--:--</strong>
                </p>
              </div>
              <p style="
                  font-size:13px; color:#64748b; margin:0 0 16px; line-height:1.6;
              ">
                For your security, your account has been locked after too many failed
                <span id="lockoutSourceLabel">security</span> attempts.
                You cannot perform this action until the lockout expires.
                Please log out and try again later.
              </p>
              <p style="font-size:12px; color:#94a3b8; margin:0;">
                If you believe this is a mistake, contact support.
                Do not share your secret answer or password with anyone.
              </p>
            </div>
            <div class="wallet-modal-footer" style="
                padding:0 24px 20px; display:flex; justify-content:flex-end; gap:10px;
            ">
              <button type="button" id="lockoutLogoutBtn" style="
                  background:#dc2626; border:1px solid #dc2626; color:#fff;
                  padding:10px 18px; border-radius:8px; font-weight:600;
                  cursor:pointer; display:inline-flex; align-items:center; gap:8px;
              ">
                <i class="fas fa-sign-out-alt"></i> Log Out Now
              </button>
            </div>
          </div>
        `;
        document.body.appendChild(overlay);

        document
            .getElementById("lockoutLogoutBtn")
            .addEventListener("click", () => performLogoutNow("account_locked"));
    }

    // Update source label
    const srcEl = document.getElementById("lockoutSourceLabel");
    if (srcEl) srcEl.textContent = sourceLabel;

    // Update message
    const msgEl = document.getElementById("lockoutMessage");
    if (msgEl) {
        msgEl.textContent =
            message ||
            "Your account has been temporarily locked due to too many failed attempts.";
    }

    overlay.style.display = "flex";

    // Restart the countdown
    if (lockoutState.countdownTimer) clearInterval(lockoutState.countdownTimer);
    updateLockoutCountdown();
    lockoutState.countdownTimer = setInterval(updateLockoutCountdown, 1000);
}

function updateLockoutCountdown() {
    const el = document.getElementById("lockoutCountdown");
    if (!el || !lockoutState.lockedUntil) return;

    const remaining = lockoutState.lockedUntil.getTime() - Date.now();
    if (remaining <= 0) {
        el.textContent = "now";
        if (lockoutState.countdownTimer) {
            clearInterval(lockoutState.countdownTimer);
            lockoutState.countdownTimer = null;
        }

        lockoutState.active = false;

        const msgEl = document.getElementById("lockoutMessage");
        if (msgEl) {
            msgEl.textContent =
                "Your lockout period has expired. You may continue.";
        }

        const overlay = document.getElementById("lockoutModal");
        const btn = document.getElementById("lockoutLogoutBtn");
        if (overlay && btn) {
            btn.innerHTML = '<i class="fas fa-check"></i> Continue';
            btn.style.background = "#10b981";
            btn.style.borderColor = "#10b981";
            btn.onclick = () => {
                overlay.style.display = "none";
                lockoutState.active = false;
                lockoutState.lockedUntil = null;
                // Soft refresh so the form is re-enabled with fresh state
                if (typeof renderProfile === "function") renderProfile();
            };
        }
        return;
    }

    el.textContent = formatRemaining(remaining);
}

/**
 * Log out immediately (user clicked the button, or fallback).
 */
async function performLogoutNow(reason = "account_locked") {
    const tenantCode = window.currentUser?.tenant_code || currentUser?.tenant_code;

    if (tenantCode) {
        try {
            await fetch("../backend/authentication/logout.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ logout_id: tenantCode }),
                credentials: "same-origin",
            });
        } catch (err) {
            console.error("Logout request failed:", err);
        }
    }

    try {
        localStorage.removeItem("userData");
        localStorage.removeItem("userPreferences");
        sessionStorage.clear();
    } catch (_) {}

    window.location.href = `../pages/index.php?reason=${encodeURIComponent(reason)}`;
}

// ============================================================
// AUTH ERROR HANDLER — routes 423 to the lockout modal
// ============================================================

/**
 * @param {number} status
 * @param {object} data
 * @param {object} opts { button, originalText, clearFieldId, source: 'password'|'secret_answer' }
 * @returns {Promise<boolean>} true if handled
 */
async function handleAuthError(status, data, opts = {}) {
    const { button, originalText, source = null } = opts;

    if (status === 423) {
        const lockedUntil = parseLockoutUntil(data);
        const message =
            data.message ||
            "Account temporarily locked due to too many failed attempts.";

        // Show modal instead of logging out immediately
        lockoutState.source = source;
        showLockoutModal(message, lockedUntil, source);

        // Freeze the button visually — user will log out via modal
        if (button) {
            button.innerHTML = '<i class="fas fa-lock"></i> Account Locked';
            button.disabled = true;
        }
        return true;
    }

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

    if (status === 401) {
        displayMessage(data.message || "Incorrect credentials.", "error");
        if (opts.clearFieldId) {
            const input = document.getElementById(opts.clearFieldId);
            if (input) { input.value = ""; input.focus(); }
        }
        restoreButton(button, originalText);
        return true;
    }

    if (status === 409) {
        displayMessage(data.message || "A conflict occurred. Please refresh and try again.", "error");
        restoreButton(button, originalText);
        return true;
    }

    displayMessage(data.message || "Something went wrong.", "error");
    restoreButton(button, originalText);
    return true;
}

// ============================================================
// RENDER PROFILE
// ============================================================
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
                    <div class="profile-role">Tenant ID: ${escapeHtml(currentUser.tenant_code)}</div>
                </div>

                ${tabs}

                <div id="personalTab" class="tab-content active">${renderPersonalTab()}</div>
                <div id="securityTab" class="tab-content">${renderSecurityTab()}</div>
                <div id="resetSecretTab" class="tab-content">${renderResetSecretTab()}</div>
                <div id="tenantRatingsTab" class="tab-content">${renderRatingsTab()}</div>

                ${showSecretTab ? renderSecretTab() : renderSecretAlreadySetTab()}
            </div>
        </div>`;

    attachTabListeners();
}

function getTabsHtml(showSecretTab) {
    let tabsHtml = `
        <div class="tabs">
            <button class="tab-btn active" data-tab="personal">Personal Information</button>
            <button class="tab-btn" data-tab="security">Security</button>
            <button class="tab-btn" data-tab="resetSecret">Reset Secret Details</button>
            <button class="tab-btn" data-tab="tenantRatings">View Your Ratings</button>`;

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
                <input type="email" id="email" value="${escapeHtml(currentUser.email)}" required
                       oninput="checkIfReauthNeeded()">
            </div>
            <div class="form-group">
                <label>Phone Number *</label>
                <input type="tel" id="phone" value="${escapeHtml(currentUser.phone)}" required
                       oninput="checkIfReauthNeeded()">
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

            <div class="form-group" id="reauthPasswordGroup" style="display:none;">
                <label>Current Password *</label>
                <input type="password" id="profileCurrentPassword" autocomplete="off">
                <div class="hint" style="font-size:12px;color:#64748b;margin-top:4px;">
                    <i class="fas fa-lock" style="font-size:10px;"></i>
                    Password required because you're changing your email or phone number.
                </div>
            </div>

            <button type="button" class="btn-save" onclick="updateProfile()">
                <i class="fas fa-save"></i> Update Profile
            </button>
        </form>`;
}

function checkIfReauthNeeded() {
    const email = document.getElementById("email")?.value.trim() || "";
    const phone = document.getElementById("phone")?.value.trim() || "";
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
            <div class="form-group">
                <label>Secret Answer *</label>
                <input type="password" id="secretAnswer" required>
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

function renderResetSecretTab() {
    return `
        <form class="profile-form" id="resetSecretForm">
            <div class="form-group">
                <label>Secret Question *</label>
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
                <label>Secret Answer *</label>
                <input type="password" id="resetAnswer" required autocomplete="off">
            </div>
            <div class="form-group">
                <label>Confirm Secret Answer *</label>
                <input type="password" id="confirmResetAnswer" required autocomplete="off">
            </div>
            <div class="form-group">
                <label>Enter your Password *</label>
                <input type="password" id="resetSecretPassword" required autocomplete="off">
            </div>
            <button type="button" class="btn-save" onclick="resetSecretAnswer()">
                <i class="fas fa-shield-alt"></i> Reset Secret Question
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
                    <input type="password" id="setAnswer" required autocomplete="off">
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

function renderRatingsTab() {
    const ratings = currentUser.ratings || { summary: { total_ratings: 0, average_rating: 0 }, recent: [] };
    const summary = ratings.summary || {};
    const recent = ratings.recent || [];

    const totalRatings = summary.total_ratings || 0;
    const averageRating = summary.average_rating || 0;

    function getStarRating(rating) {
        const fullStars = Math.floor(rating);
        const hasHalfStar = (rating - fullStars) >= 0.5;
        let starsHtml = "";
        for (let i = 1; i <= 5; i++) {
            if (i <= fullStars) starsHtml += '<i class="fas fa-star"></i>';
            else if (i === fullStars + 1 && hasHalfStar) starsHtml += '<i class="fas fa-star-half-alt"></i>';
            else starsHtml += '<i class="far fa-star"></i>';
        }
        return starsHtml;
    }

    function getRatingPercentage(category) {
        if (!totalRatings) return 0;
        const count = summary.rating_distribution?.[category] || 0;
        return (count / totalRatings) * 100;
    }

    const categoryIcons = {
        payment: "fa-money-bill-wave",
        behavior: "fa-user-check",
        cleanliness: "fa-broom",
        maintenance: "fa-tools",
        overall: "fa-star",
    };

    const categoryLabels = {
        payment: "Payment",
        behavior: "Behavior",
        cleanliness: "Cleanliness",
        maintenance: "Maintenance",
        overall: "Overall",
    };

    function getRatingColor(rating) {
        if (rating >= 4.5) return "#28a745";
        if (rating >= 3.5) return "#ffc107";
        if (rating >= 2.5) return "#fd7e14";
        if (rating >= 1.5) return "#dc3545";
        return "#6c757d";
    }

    function formatDate(dateString) {
        if (!dateString) return "N/A";
        return new Date(dateString).toLocaleDateString("en-US", {
            year: "numeric", month: "short", day: "numeric",
            hour: "2-digit", minute: "2-digit",
        });
    }

    return `
        <div class="ratings-container">
            <div class="ratings-overview">
                <div class="rating-summary-card">
                    <div class="rating-score">
                        <div class="rating-number">${averageRating.toFixed(1)}</div>
                        <div class="rating-stars">${getStarRating(averageRating)}</div>
                        <div class="rating-total">Based on ${totalRatings} ${totalRatings === 1 ? "rating" : "ratings"}</div>
                    </div>
                    <div class="rating-distribution">
                        ${[5, 4, 3, 2, 1].map(star => `
                            <div class="rating-bar-row">
                                <div class="rating-label">
                                    <span>${star}</span>
                                    <i class="fas fa-star" style="color: #ffc107; font-size: 14px;"></i>
                                </div>
                                <div class="rating-bar-track">
                                    <div class="rating-bar-fill" style="width: ${getRatingPercentage(star + "_star")}%; background: ${getRatingColor(star)};"></div>
                                </div>
                                <div class="rating-count">${summary.rating_distribution?.[star + "_star"] || 0}</div>
                            </div>
                        `).join("")}
                    </div>
                </div>

                <div class="category-ratings">
                    <h3 class="section-title"><i class="fas fa-chart-pie"></i> Rating Breakdown</h3>
                    <div class="category-grid">
                        ${["payment", "behavior", "cleanliness", "maintenance", "overall"].map(category => {
                            const rating = summary[category + "_rating"] || 0;
                            return `
                                <div class="category-item" style="border-left: 4px solid ${getRatingColor(rating)};">
                                    <div class="category-header">
                                        <div class="category-icon"><i class="fas ${categoryIcons[category]}"></i></div>
                                        <div class="category-info">
                                            <div class="category-name">${categoryLabels[category]}</div>
                                            <div class="category-rating">
                                                ${rating > 0 ? rating.toFixed(1) : "N/A"}
                                                ${rating > 0 ? getStarRating(rating) : ""}
                                            </div>
                                        </div>
                                    </div>
                                </div>`;
                        }).join("")}
                    </div>
                </div>
            </div>

            <div class="recent-ratings">
                <div class="recent-header">
                    <h3 class="section-title">
                        <i class="fas fa-clock"></i> Recent Reviews
                        <span class="badge">${recent.length}</span>
                    </h3>
                </div>

                ${recent.length > 0 ? `
                    <div class="ratings-list">
                        ${recent.map(rating => `
                            <div class="rating-item">
                                <div class="rating-item-header">
                                    <div class="rating-user">
                                        <div class="user-avatar"><i class="fas fa-user-circle"></i></div>
                                        <div class="user-info">
                                            <div class="user-name">${escapeHtml(rating.client_name || "Anonymous")}</div>
                                            <div class="user-category">
                                                <span class="category-tag ${rating.category}">
                                                    <i class="fas ${categoryIcons[rating.category] || "fa-tag"}"></i>
                                                    ${categoryLabels[rating.category] || rating.category}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="rating-meta">
                                        <div class="rating-stars-small">${getStarRating(rating.rating)}</div>
                                        <div class="rating-date">${formatDate(rating.created_at)}</div>
                                    </div>
                                </div>
                                ${rating.comment ? `
                                    <div class="rating-comment">
                                        <i class="fas fa-quote-left quote-icon"></i>
                                        <p>${escapeHtml(rating.comment)}</p>
                                    </div>` : `
                                    <div class="rating-comment empty"><em>No comment provided</em></div>`}
                            </div>`).join("")}
                    </div>` : `
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i>
                        </div>
                        <h4>No Ratings Yet</h4>
                        <p>You haven't received any ratings yet. Continue to be a great tenant!</p>
                    </div>`}
            </div>
        </div>`;
}

function attachTabListeners() {
    document.querySelectorAll(".tab-btn").forEach((btn) => {
        btn.addEventListener("click", () => {
            const tab = btn.dataset.tab;

            document.querySelectorAll(".tab-btn").forEach((b) => b.classList.remove("active"));
            document.querySelectorAll(".tab-content").forEach((c) => c.classList.remove("active"));

            btn.classList.add("active");

            if (tab === "secret") {
                document.getElementById("secretTab")?.classList.add("active");
            } else {
                document.getElementById(`${tab}Tab`)?.classList.add("active");
            }
        });
    });
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

    const body = { firstname, lastname, email, phone, gender };
    if (needsReauth) body.current_password = currentPassword;

    const { ok, status, data } = await apiRequest(
        "../backend/tenant/update_profile.php",
        body,
        { csrfForm: "tenant_profile_form" }
    );

    if (ok) {
        Object.assign(currentUser, { firstname, lastname, email, phone, gender });
        window.currentUser = currentUser;
        displayMessage("Profile updated successfully", "success");
        renderProfile();
        return;
    }

    // Password lockout → 423, so source = 'password'
    await handleAuthError(status, data, {
        button,
        originalText,
        clearFieldId: status === 401 ? "profileCurrentPassword" : null,
        source: "password",
    });
}

// ============================================================
// PASSWORD
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
    const currentPassword = document.getElementById("currentPassword")?.value;
    const newPassword     = document.getElementById("newPassword")?.value;
    const confirmPassword = document.getElementById("confirmPassword")?.value;
    const secretAnswer    = document.getElementById("secretAnswer")?.value;

    if (!currentPassword || !newPassword || !confirmPassword || !secretAnswer) {
        displayMessage("Please fill in all fields", "error");
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
    const originalText = setButtonLoading(button, "Changing...");

    const { ok, status, data } = await apiRequest(
        "../backend/authentication/change_password.php",
        {
            current_password: currentPassword,
            new_password:     newPassword,
            confirm_password: confirmPassword,
            secret_answer:    secretAnswer,
        },
        { csrfForm: "tenant_change_password" }
    );

    if (ok) {
        displayMessage(data.message || "Password changed successfully!", "success");

        const tenantCode    = data.data?.tenant_code    || data.tenant_code;
        const requireLogout = data.data?.require_logout || data.require_logout;

        if (requireLogout && tenantCode) {
            await callLogoutEndpoint(tenantCode);
        } else {
            clearLocalSession();
        }

        const redirectUrl = data.data?.redirect_url || data.redirect_url || "../pages/index.php";
        setTimeout(() => { window.location.href = redirectUrl; }, 2000);
        return;
    }

    // Password lockout → 423, source = 'password'
    await handleAuthError(status, data, {
        button,
        originalText,
        clearFieldId: status === 401 ? "currentPassword" : null,
        source: "password",
    });
}

// ============================================================
// LOGOUT
// ============================================================
async function callLogoutEndpoint(tenant_code) {
    try {
        const logoutResponse = await fetch("../backend/authentication/logout.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ logout_id: tenant_code }),
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
// SECRET QUESTION (first-time set)
// ============================================================
async function setSecretAnswer() {
    const secretQuestion = document.getElementById("secretQuestion")?.value;
    const secretAnswer   = document.getElementById("setAnswer")?.value;
    const confirmAnswer  = document.getElementById("confirmAnswer")?.value;

    if (!secretQuestion) return displayMessage("Secret Question is Required", "error");
    if (!secretAnswer)   return displayMessage("Secret Answer is Required", "error");
    if (!confirmAnswer)  return displayMessage("Confirm Secret Answer is Required", "error");
    if (secretAnswer !== confirmAnswer) return displayMessage("Secret answers do not match", "error");
    if (secretAnswer.length < 8)        return displayMessage("Secret answer must be at least 8 characters", "error");

    const button = document.querySelector("#secretTab .btn-save");
    const originalText = setButtonLoading(button, "Saving...");

    const { ok, status, data } = await apiRequest(
        "../backend/authentication/set_secret_question_answer.php",
        {
            secret_question: secretQuestion,
            secret_answer:   secretAnswer,
        }
    );

    if (ok) {
        displayMessage(data.message || "Secret question set successfully", "success");
        if (currentUser) {
            currentUser.has_secret_set = 1;
            window.currentUser = currentUser;
        }
        setTimeout(() => window.location.reload(), 2000);
        return;
    }

    await handleAuthError(status, data, {
        button,
        originalText,
        source: "secret_answer",
    });
}

// ============================================================
// RESET SECRET QUESTION
// ============================================================
async function resetSecretAnswer() {
    const resecretQuestionInput    = document.getElementById("resetSecretQuestion")?.value;
    const resetAnswerInput         = document.getElementById("resetAnswer")?.value;
    const confirmResetAnswerInput  = document.getElementById("confirmResetAnswer")?.value;
    const resetSecretPasswordInput = document.getElementById("resetSecretPassword")?.value;

    if (!resecretQuestionInput)     return displayMessage("Secret Question is Required", "error");
    if (!resetAnswerInput)          return displayMessage("Secret Answer is Required", "error");
    if (!confirmResetAnswerInput)   return displayMessage("Confirm Secret Answer is Required", "error");
    if (!resetSecretPasswordInput)  return displayMessage("Your Password is Required", "error");
    if (resetAnswerInput !== confirmResetAnswerInput) return displayMessage("Secret answers do not match", "error");
    if (resetAnswerInput.length < 8) return displayMessage("Secret answer must be at least 8 characters", "error");
    if (currentUser && Number(currentUser.has_secret_set) !== 1) {
        return displayMessage("Please set your Secret Question and answer first", "error");
    }

    const button = document.querySelector("#resetSecretTab .btn-save");
    const originalText = setButtonLoading(button, "Resetting...");

    const { ok, status, data } = await apiRequest(
        "../backend/authentication/reset_secret_question_answer.php",
        {
            secret_question: resecretQuestionInput,
            secret_answer:   resetAnswerInput,
            confirm_answer:  confirmResetAnswerInput,
            password:        resetSecretPasswordInput,
        },
        { csrfForm: "reset_secret_form" }
    );

    if (ok) {
        displayMessage(data.message || "Secret Details Reset Successfully", "success");
        if (currentUser) {
            currentUser.has_secret_set  = 1;
            currentUser.secret_question = data.secret_question;
            window.currentUser = currentUser;
        }
        setTimeout(() => window.location.reload(), 2000);
        return;
    }

    // This endpoint can lock on either password or secret answer.
    // Infer from the error_code if provided; else default to secret_answer.
    const inferredSource =
        data?.error_code === "ACCOUNT_LOCKED"
            ? (data?.data?.source === "password" ? "password" : "secret_answer")
            : "secret_answer";

    await handleAuthError(status, data, {
        button,
        originalText,
        clearFieldId: status === 401 ? "resetSecretPassword" : null,
        source: inferredSource,
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
    const icons = { success: "✅", error: "❌", warning: "⚠️", info: "ℹ️" };
    alert(`${icons[type] || "ℹ️"} ${message}`);
}

function escapeHtml(text) {
    if (!text) return "";
    const div = document.createElement("div");
    div.textContent = text;
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
window.setSecretAnswer       = setSecretAnswer;
window.checkIfReauthNeeded   = checkIfReauthNeeded;