// admin/scripts/profile.js
// Admin profile: load, tabbed edit modal, lockout handling, photo upload + camera.

// ============================================================
// GLOBALS
// ============================================================
let profileData = null;
let currentProfileData = null;

// Photo configuration
const PHOTO_MAX_SIZE = 1 * 1024 * 1024; // 1 MB
const PHOTO_ALLOWED_TYPES = ["image/jpeg", "image/png", "image/webp"];
const PHOTO_ALLOWED_EXTENSIONS = ["jpg", "jpeg", "png", "webp"];
const CSRF_FORM_NAME = "admin_profile_form";

// Camera state
let cameraStream = null;
let currentFacingMode = "user";

const $ = (id) => document.getElementById(id);

// ============================================================
// INITIALIZATION
// ============================================================
document.addEventListener("DOMContentLoaded", () => {
  fetchProfile();
  initializePhotoActions();
  wirePasswordMatchListener();
  wireModalCloseHandlers();
  wirePhotoRefreshListener();
});

// ============================================================
// FETCH PROFILE
// ============================================================
async function fetchProfile() {
  showLoading();
  hideError();

  try {
    const response = await fetch("../backend/staffs/staff_profile.php", {
      method: "GET",
      headers: { "Content-Type": "application/json" },
      credentials: "include",
    });

    const data = await response.json();

    if (data.success) {
      profileData = data.data;
      renderProfile(profileData);
      showNotification("Profile loaded successfully", "success");
    } else {
      showError("Failed to load profile", data.message || "Please try again.");
    }
  } catch (error) {
    console.error("Profile fetch error:", error);
    showError(
      "Network Error",
      "Unable to connect to server. Please check your connection.",
    );
  } finally {
    hideLoading();
  }
}

function refreshProfile() {
  fetchProfile();
}

// ============================================================
// RENDER PROFILE
// ============================================================
function renderProfile(data) {
  const avatarSection = $("avatarSection");
  if (avatarSection) {
    const avatarMarkup =
      data.has_photo && data.photo_url
        ? `<div class="avatar-container" id="avatarWrapper">
                   <img src="${escapeHtml(data.photo_url)}" alt="${escapeHtml(data.full_name)}" class="avatar-image">
                   <div class="avatar-badge"><i class="fas fa-check"></i></div>
               </div>`
        : `<div class="avatar-container" id="avatarWrapper">
                   <div class="avatar-placeholder">${escapeHtml(data.initials || "AD")}</div>
                   <div class="avatar-badge"><i class="fas fa-check"></i></div>
               </div>`;

    avatarSection.innerHTML = `
            ${avatarMarkup}
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
            <h2 class="profile-name">${escapeHtml(data.full_name || "—")}</h2>
            <div class="profile-role">${escapeHtml(data.role_display || "—")}</div>
            <div class="profile-email">
                <i class="fas fa-envelope"></i> ${escapeHtml(data.email || "—")}
            </div>
        `;

    // Re-wire after innerHTML replacement
    initializePhotoActions();
  }

  const personalInfo = $("personalInfo");
  if (personalInfo) {
    personalInfo.innerHTML = `
            <div class="info-item"><div class="info-label">Full Name</div><div class="info-value">${escapeHtml(data.full_name)}</div></div>
            <div class="info-item"><div class="info-label">Email Address</div><div class="info-value">${escapeHtml(data.email)}</div></div>
            <div class="info-item"><div class="info-label">Phone Number</div><div class="info-value">${escapeHtml(data.phone || "Not specified")}</div></div>
            <div class="info-item"><div class="info-label">Gender</div><div class="info-value">${escapeHtml(data.gender_display || "Not specified")}</div></div>
            <div class="info-item"><div class="info-label">Address</div><div class="info-value">${escapeHtml(data.address || "Not specified")}</div></div>
            <div class="info-item"><div class="info-label">Role</div><div class="info-value">${escapeHtml(data.role_display || "—")}</div></div>
            <div class="info-item"><div class="info-label">Account Status</div><div class="info-value"><span class="status-badge ${escapeHtml(data.status_class || "")}">${escapeHtml(data.status_display || "—")}</span></div></div>
            <div class="info-item"><div class="info-label">Member Since</div><div class="info-value">${escapeHtml(data.created_at_formatted || "--")}</div></div>
            <div class="info-item"><div class="info-label">Account Age</div><div class="info-value">${escapeHtml(data.account_age || "--")}</div></div>
            <div class="info-item"><div class="info-label">Last Updated</div><div class="info-value">${escapeHtml(data.updated_at_formatted || "--")}</div></div>
        `;
  }

  const lastUpdatedEl = $("lastUpdated");
  if (lastUpdatedEl)
    lastUpdatedEl.textContent = data.updated_at_formatted || "--";

  const statsSection = $("statsSection");
  if (statsSection) {
    statsSection.innerHTML = `
            <div class="stat-item">
                <div class="stat-label">Total Settlement Earned</div>
                <div class="stat-value">₦ ${formatNumber(data.total_settlement_earned)}</div>
                <div class="stat-label">Wallet Balance</div>
                <div class="stat-value">₦ ${formatNumber(data.wallet_balance)}</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">${data.stats?.total_logins || 0}</div>
                <div class="stat-label">Total Logins</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">${escapeHtml(data.member_since || "--")}</div>
                <div class="stat-label">Member Since</div>
            </div>
        `;
  }

  const activityList = $("activityList");
  if (activityList) {
    const activities = [
      {
        icon: "fas fa-sign-in-alt",
        text: "You logged in to your account",
        time: data.stats?.last_login_relative || "Recently",
      },
      {
        icon: "fas fa-user-check",
        text: "Profile information updated",
        time: data.last_updated_relative || "Never",
      },
      {
        icon: "fas fa-user-shield",
        text: "Security settings verified",
        time: data.has_secret_set ? "Secret question set" : "Not configured",
      },
    ];

    activityList.innerHTML = activities
      .map(
        (a) => `
            <li class="activity-item">
                <div class="activity-icon"><i class="${a.icon}"></i></div>
                <div class="activity-content">
                    <p class="activity-text">${escapeHtml(a.text)}</p>
                    <span class="activity-time">${escapeHtml(a.time)}</span>
                </div>
            </li>
        `,
      )
      .join("");
  }
}

// ============================================================
// EDIT MODAL — tabs
// ============================================================
function editProfile() {
  if (!profileData) {
    showNotification("Profile data not loaded yet", "error");
    return;
  }
  openEditModal(profileData);
}

function openEditModal(u) {
  currentProfileData = u;

  $("basicFirstname").value = u.firstname || "";
  $("basicLastname").value = u.lastname || "";
  $("basicEmail").value = u.email || "";
  $("basicPhone").value = u.phone || "";
  $("basicGender").value = u.gender || "";
  $("basicAddress").value = u.address || "";

  [
    "basicCurrentPassword",
    "pwCurrentPassword",
    "pwNewPassword",
    "pwConfirmPassword",
    "pwSecretAnswer",
    "secretAnswerInput",
    "secretConfirmInput",
    "secretCurrentPassword",
  ].forEach((id) => {
    const el = $(id);
    if (el) el.value = "";
  });

  $("secretQuestionInput").value = "";
  resetPwStrength();
  toggleBasicReauth();
  switchEditTab("basic");

  $("editProfileModal").style.display = "flex";
  document.body.style.overflow = "hidden";
}

function closeEditModal() {
  $("editProfileModal").style.display = "none";
  document.body.style.overflow = "auto";
  currentProfileData = null;
}

function switchEditTab(tab) {
  document
    .querySelectorAll(".modal-tab-btn")
    .forEach((b) => b.classList.toggle("active", b.dataset.tab === tab));
  document
    .querySelectorAll(".modal-tab-panel")
    .forEach((p) => p.classList.toggle("active", p.id === `editTab-${tab}`));
}

// ============================================================
// BASIC TAB
// ============================================================
function toggleBasicReauth() {
  if (!currentProfileData) return;
  const email = ($("basicEmail").value || "").trim().toLowerCase();
  const phone = ($("basicPhone").value || "").trim();
  const changed =
    email !== (currentProfileData.email || "").toLowerCase() ||
    phone !== (currentProfileData.phone || "");

  const block = $("basicReauthBlock");
  if (block) block.style.display = changed ? "block" : "none";
}

async function saveBasic() {
  const btn = $("basicSubmitBtn");
  const original = btn.innerHTML;

  const body = {
    action: "basic",
    firstname: $("basicFirstname").value.trim(),
    lastname: $("basicLastname").value.trim(),
    email: $("basicEmail").value.trim(),
    phone: $("basicPhone").value.trim(),
    gender: $("basicGender").value,
    address: $("basicAddress").value.trim(),
    current_password: $("basicCurrentPassword").value,
  };

  if (!body.firstname || !body.lastname || !body.email || !body.phone) {
    return showNotification("All fields marked * are required", "error");
  }

  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

  const result = await profileApiRequest("update_profile.php", body);

  if (result.status === 423) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-lock"></i> Account Locked';
    return;
  }
  if (!result.ok) {
    showNotification(result.data.message || "Save failed", "error");
    if (result.data.error_code === "INVALID_PASSWORD") {
      $("basicCurrentPassword").value = "";
    }
    btn.disabled = false;
    btn.innerHTML = original;
    return;
  }

  closeEditModal();
  showNotification("Personal information updated", "success");
  btn.disabled = false;
  btn.innerHTML = original;
  setTimeout(fetchProfile, 400);
}

// ============================================================
// PASSWORD TAB
// ============================================================
function checkPwStrength() {
  const pwd = $("pwNewPassword").value;
  const fill = $("pwStrengthFill");
  const text = $("pwStrengthText");

  let score = 0;
  if (pwd.length >= 8) score += 25;
  if (/[A-Z]/.test(pwd)) score += 25;
  if (/[a-z]/.test(pwd)) score += 25;
  if (/\d/.test(pwd) && /[!@#$%^&*(),.?":{}|<>_\-]/.test(pwd)) score += 25;

  fill.style.width = score + "%";
  if (!pwd) {
    fill.style.background = "";
    text.textContent = "Min 8 chars with upper, lower, number, special";
  } else if (score < 50) {
    fill.style.background = "#dc2626";
    text.textContent = "Weak";
  } else if (score < 75) {
    fill.style.background = "#f59e0b";
    text.textContent = "Medium";
  } else {
    fill.style.background = "#10b981";
    text.textContent = "Strong";
  }

  checkPwMatch();
}

function checkPwMatch() {
  const a = $("pwNewPassword").value;
  const b = $("pwConfirmPassword").value;
  const fb = $("pwMatchFeedback");
  if (!fb) return;
  if (!a || !b) {
    fb.textContent = "";
    fb.className = "form-feedback";
    return;
  }
  if (a === b) {
    fb.textContent = "✓ Passwords match";
    fb.className = "form-feedback success";
  } else {
    fb.textContent = "✗ Passwords do not match";
    fb.className = "form-feedback error";
  }
}

function resetPwStrength() {
  const fill = $("pwStrengthFill");
  const text = $("pwStrengthText");
  const fb = $("pwMatchFeedback");
  if (fill) fill.style.width = "0%";
  if (text) {
    text.textContent = "Min 8 chars with upper, lower, number, special";
    text.style.color = "";
  }
  if (fb) {
    fb.textContent = "";
    fb.className = "form-feedback";
  }
}

function wirePasswordMatchListener() {
  const pwConfirm = $("pwConfirmPassword");
  if (pwConfirm) pwConfirm.addEventListener("input", checkPwMatch);
}

async function savePassword() {
  const body = {
    action: "password",
    current_password: $("pwCurrentPassword").value,
    new_password: $("pwNewPassword").value,
    confirm_password: $("pwConfirmPassword").value,
    secret_answer: $("pwSecretAnswer").value,
  };

  if (
    !body.current_password ||
    !body.new_password ||
    !body.confirm_password ||
    !body.secret_answer
  ) {
    return showNotification("All fields are required", "error");
  }
  if (body.new_password !== body.confirm_password) {
    return showNotification("New passwords do not match", "error");
  }

  const btn = $("pwSubmitBtn");
  const original = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Changing…';

  const result = await profileApiRequest("update_profile.php", body);

  if (result.status === 423) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-lock"></i> Account Locked';
    return;
  }
  if (!result.ok) {
    showNotification(result.data.message || "Save failed", "error");
    if (result.data.error_code === "INVALID_PASSWORD") {
      $("pwCurrentPassword").value = "";
    } else if (result.data.error_code === "INVALID_SECRET_ANSWER") {
      $("pwSecretAnswer").value = "";
    }
    btn.disabled = false;
    btn.innerHTML = original;
    return;
  }

  closeEditModal();
  showNotification("Password changed successfully", "success");
  btn.disabled = false;
  btn.innerHTML = original;
  setTimeout(fetchProfile, 400);
}

// ============================================================
// SECRET TAB
// ============================================================
async function saveSecret() {
  const body = {
    action: "secret",
    secret_question: $("secretQuestionInput").value,
    secret_answer: $("secretAnswerInput").value,
    confirm_secret_answer: $("secretConfirmInput").value,
    current_password: $("secretCurrentPassword").value,
  };

  if (!body.secret_question)
    return showNotification("Select a secret question", "error");
  if (!body.secret_answer)
    return showNotification("Secret answer is required", "error");
  if (body.secret_answer.length < 8)
    return showNotification(
      "Secret answer must be at least 8 characters",
      "error",
    );
  if (body.secret_answer !== body.confirm_secret_answer)
    return showNotification("Secret answers do not match", "error");
  if (!body.current_password)
    return showNotification("Enter your current password", "error");

  const btn = $("secretSubmitBtn");
  const original = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

  const result = await profileApiRequest("update_profile.php", body);

  if (result.status === 423) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-lock"></i> Account Locked';
    return;
  }
  if (!result.ok) {
    showNotification(result.data.message || "Save failed", "error");
    if (result.data.error_code === "INVALID_PASSWORD") {
      $("secretCurrentPassword").value = "";
    }
    btn.disabled = false;
    btn.innerHTML = original;
    return;
  }

  closeEditModal();
  showNotification("Secret details updated", "success");
  btn.disabled = false;
  btn.innerHTML = original;
  setTimeout(fetchProfile, 400);
}

// ============================================================
// API WRAPPER
// ============================================================
async function profileApiRequest(endpoint, body) {
   const csrf = await getCsrfToken(CSRF_FORM_NAME);
    if (csrf) {
        body.csrf_token = csrf;
        body.token_id   = CSRF_FORM_NAME;
    }

  let response;
  try {
    response = await fetch(`../backend/staffs/${endpoint}`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
      },
      credentials: "include",
      body: JSON.stringify(body),
    });
  } catch {
    return { ok: false, status: 0, data: { message: "Network error" } };
  }

  const ct = response.headers.get("content-type") || "";
  if (!ct.includes("application/json")) {
    return {
      ok: false,
      status: response.status,
      data: { message: "Server error" },
    };
  }

  const data = await response
    .json()
    .catch(() => ({ message: "Malformed response" }));

  if (response.ok && data.success)
    return { ok: true, status: response.status, data };

  if (response.status === 423) {
    const l = data.data || {};
    showLockoutModal(
      data.message || "Account locked",
      l.locked_until ? new Date(l.locked_until) : null,
      l.source || null,
    );
    return { ok: false, status: 423, data };
  }

  return { ok: false, status: response.status, data };
}

// ============================================================
// LOCKOUT MODAL
// ============================================================
const lockoutState = { active: false, lockedUntil: null, countdownTimer: null };

function showLockoutModal(message, lockedUntilDate, source) {
  if (!lockedUntilDate) lockedUntilDate = new Date(Date.now() + 15 * 60 * 1000);

  lockoutState.active = true;
  lockoutState.lockedUntil = lockedUntilDate;

  const srcLabel =
    source === "password"
      ? "password"
      : source === "secret_answer"
        ? "security-answer"
        : "security";

  const overlay = $("lockoutModal");
  overlay.style.display = "flex";

  $("lockoutMessage").textContent =
    message || "Your account has been temporarily locked.";
  $("lockoutSourceLabel").textContent = srcLabel;
  $("lockoutLogoutBtn").onclick = () => {
    fetch("../backend/authentication/logout.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ logout_id: currentProfileData?.unique_id || "" }),
      credentials: "include",
    }).finally(() => {
      try {
        localStorage.clear();
        sessionStorage.clear();
      } catch {}
      window.location.href = "../pages/index.php?reason=account_locked";
    });
  };

  if (lockoutState.countdownTimer) clearInterval(lockoutState.countdownTimer);
  updateLockoutCountdown();
  lockoutState.countdownTimer = setInterval(updateLockoutCountdown, 1000);

  document.body.style.pointerEvents = "none";
  overlay.style.pointerEvents = "auto";
}

function updateLockoutCountdown() {
  const el = $("lockoutCountdown");
  if (!el || !lockoutState.lockedUntil) return;

  const remaining = lockoutState.lockedUntil.getTime() - Date.now();
  if (remaining <= 0) {
    el.textContent = "now";
    if (lockoutState.countdownTimer) clearInterval(lockoutState.countdownTimer);
    const btn = $("lockoutLogoutBtn");
    btn.innerHTML = '<i class="fas fa-check"></i> Continue';
    btn.style.background = "#10b981";
    btn.style.borderColor = "#10b981";
    btn.onclick = () => window.location.reload();
    return;
  }

  const s = Math.ceil(remaining / 1000);
  const m = Math.floor(s / 60);
  const sec = s % 60;
  el.textContent =
    m > 0 ? `${m}m ${sec.toString().padStart(2, "0")}s` : `${sec}s`;
}

// ============================================================
// PHOTO ACTIONS — upload + camera
// ============================================================
function initializePhotoActions() {
  const uploadBtn = $("uploadPhotoBtn");
  const cameraBtn = $("cameraPhotoBtn");
  const fileInput = $("photoFileInput");

  if (uploadBtn && fileInput) {
    uploadBtn.onclick = (e) => {
      e.preventDefault();
      e.stopPropagation();
      fileInput.click();
    };
    fileInput.onchange = (e) => {
      if (e.target.files && e.target.files[0]) {
        handlePhotoSelection(e.target.files[0], "upload");
      }
      e.target.value = "";
    };
  }

  if (cameraBtn) {
    cameraBtn.onclick = (e) => {
      e.preventDefault();
      e.stopPropagation();
      openCameraModal();
    };
  }
}

// ---- Camera ----
async function openCameraModal() {
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    showNotification("Camera is not supported on this device/browser", "error");
    return;
  }

  let modal = $("cameraCaptureModal");
  if (!modal) {
    modal = document.createElement("div");
    modal.id = "cameraCaptureModal";
    modal.className = "camera-modal";
    modal.innerHTML = `
            <div class="camera-modal-content">
                <div class="camera-modal-header">
                    <h3><i class="fas fa-camera"></i> Take Photo</h3>
                    <button class="camera-modal-close" id="closeCameraBtn" type="button">&times;</button>
                </div>
                <div class="camera-modal-body">
                    <video id="cameraVideo" autoplay playsinline muted></video>
                    <canvas id="cameraCanvas" style="display: none;"></canvas>
                    <div class="camera-error" id="cameraError" style="display: none;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p id="cameraErrorMessage">Unable to access camera</p>
                    </div>
                </div>
                <div class="camera-modal-footer">
                    <button type="button" class="camera-btn-switch" id="switchCameraBtn" title="Switch Camera">
                        <i class="fas fa-sync-alt"></i> Switch
                    </button>
                    <button type="button" class="camera-btn-capture" id="capturePhotoBtn">
                        <i class="fas fa-circle"></i> Capture
                    </button>
                    <button type="button" class="camera-btn-cancel" id="cancelCameraBtn">Cancel</button>
                </div>
            </div>
        `;
    document.body.appendChild(modal);
    wireCameraModalButtons();
  }

  modal.classList.add("active");
  document.body.style.overflow = "hidden";
  await startCamera();
}

function wireCameraModalButtons() {
  const closeBtn = $("closeCameraBtn");
  const captureBtn = $("capturePhotoBtn");
  const cancelBtn = $("cancelCameraBtn");
  const switchBtn = $("switchCameraBtn");
  const modal = $("cameraCaptureModal");

  if (closeBtn) closeBtn.onclick = closeCameraModal;
  if (cancelBtn) cancelBtn.onclick = closeCameraModal;
  if (captureBtn) captureBtn.onclick = capturePhoto;
  if (switchBtn) switchBtn.onclick = switchCamera;

  if (modal) {
    modal.onclick = (e) => {
      if (e.target === modal) closeCameraModal();
    };
  }

  if (!window._adminCameraEscapeHandler) {
    window._adminCameraEscapeHandler = (e) => {
      if (e.key === "Escape") {
        const m = $("cameraCaptureModal");
        if (m && m.classList.contains("active")) closeCameraModal();
      }
    };
    document.addEventListener("keydown", window._adminCameraEscapeHandler);
  }
}

async function startCamera() {
  const video = $("cameraVideo");
  const errorDiv = $("cameraError");
  const errorMsg = $("cameraErrorMessage");

  stopCamera();
  if (errorDiv) errorDiv.style.display = "none";
  if (video) video.style.display = "block";

  try {
    const constraints = {
      video: {
        facingMode: currentFacingMode,
        width: { ideal: 1280 },
        height: { ideal: 720 },
      },
      audio: false,
    };

    cameraStream = await navigator.mediaDevices.getUserMedia(constraints);

    if (video) {
      video.srcObject = cameraStream;
      video.classList.toggle("mirrored", currentFacingMode === "user");
      await video.play();
    }
  } catch (error) {
    console.error("Camera access error:", error);

    let message = "Unable to access camera";
    if (
      error.name === "NotAllowedError" ||
      error.name === "PermissionDeniedError"
    ) {
      message =
        "Camera permission denied. Please allow camera access in your browser settings.";
    } else if (
      error.name === "NotFoundError" ||
      error.name === "DevicesNotFoundError"
    ) {
      message = "No camera found on this device.";
    } else if (
      error.name === "NotReadableError" ||
      error.name === "TrackStartError"
    ) {
      message = "Camera is already in use by another application.";
    } else if (error.name === "OverconstrainedError") {
      message = "Camera does not meet the required constraints.";
    } else if (error.name === "SecurityError") {
      message =
        "Camera access requires a secure connection (HTTPS or localhost).";
    }

    if (errorDiv && errorMsg) {
      errorMsg.textContent = message;
      errorDiv.style.display = "block";
    }
    if (video) video.style.display = "none";
    showNotification(message, "error");
  }
}

function stopCamera() {
  if (cameraStream) {
    cameraStream.getTracks().forEach((t) => t.stop());
    cameraStream = null;
  }
  const video = $("cameraVideo");
  if (video) {
    video.srcObject = null;
    video.style.display = "block";
  }
  currentFacingMode = "user";
}

function closeCameraModal() {
  const modal = $("cameraCaptureModal");
  if (modal) modal.classList.remove("active");
  document.body.style.overflow = "";
  stopCamera();
}

function capturePhoto() {
  const video = $("cameraVideo");
  const canvas = $("cameraCanvas");

  if (!video || !canvas || !cameraStream) {
    showNotification("Camera is not ready", "error");
    return;
  }
  if (!video.videoWidth || !video.videoHeight) {
    showNotification("Video is not ready. Please wait...", "warning");
    return;
  }

  canvas.width = video.videoWidth;
  canvas.height = video.videoHeight;
  const ctx = canvas.getContext("2d");

  if (currentFacingMode === "user") {
    ctx.translate(canvas.width, 0);
    ctx.scale(-1, 1);
  }
  ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

  canvas.toBlob(
    (blob) => {
      if (!blob) {
        showNotification("Failed to capture photo", "error");
        return;
      }
      const timestamp = Date.now();
      const file = new File([blob], `camera_${timestamp}.jpg`, {
        type: "image/jpeg",
        lastModified: timestamp,
      });
      closeCameraModal();
      handlePhotoSelection(file, "camera");
    },
    "image/jpeg",
    0.9,
  );
}

async function switchCamera() {
  currentFacingMode = currentFacingMode === "user" ? "environment" : "user";
  await startCamera();
}

// ============================================================
// PHOTO SELECTION — validate, confirm, upload
// ============================================================
async function handlePhotoSelection(file, source) {
  if (!file) return showNotification("No file selected", "error");

  if (!PHOTO_ALLOWED_TYPES.includes(file.type.toLowerCase())) {
    return showNotification(
      "Invalid file type. Please upload JPG or PNG",
      "error",
    );
  }

  const extension = file.name.split(".").pop().toLowerCase();
  if (!PHOTO_ALLOWED_EXTENSIONS.includes(extension)) {
    return showNotification(
      "Invalid extension. Allowed: JPG, JPEG, PNG",
      "error",
    );
  }

  if (file.size > PHOTO_MAX_SIZE) {
    const sizeKB = (file.size / 1024).toFixed(2);
    return showNotification(
      `File too large (${sizeKB} KB). Maximum: 1 MB`,
      "error",
    );
  }

  if (file.size < 1024) {
    return showNotification(
      "File is too small. Please select a valid image.",
      "error",
    );
  }

  try {
    await verifyImageFile(file);
  } catch (err) {
    return showNotification(
      err.message || "The file is not a valid image",
      "error",
    );
  }

  const confirmed = await showPhotoConfirmDialog(file, source);
  if (!confirmed) return;

  await uploadProfilePhoto(file, source);
}

function verifyImageFile(file) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    const url = URL.createObjectURL(file);
    const timeout = setTimeout(() => {
      URL.revokeObjectURL(url);
      reject(new Error("Image load timeout"));
    }, 5000);

    img.onload = () => {
      clearTimeout(timeout);
      URL.revokeObjectURL(url);
      if (img.width < 100 || img.height < 100) {
        return reject(new Error("Image too small (min 100x100 px)"));
      }
      if (img.width > 8000 || img.height > 8000) {
        return reject(new Error("Image too large (max 8000x8000 px)"));
      }
      resolve(true);
    };
    img.onerror = () => {
      clearTimeout(timeout);
      URL.revokeObjectURL(url);
      reject(new Error("Invalid image file"));
    };
    img.src = url;
  });
}

function showPhotoConfirmDialog(file, source) {
  return new Promise((resolve) => {
    const previewUrl = URL.createObjectURL(file);

    const dialogHtml = `
            <div id="photoConfirmDialog" class="confirm-dialog">
                <div class="confirm-dialog-content" style="max-width: 450px;">
                    <div class="confirm-dialog-header">
                        <h3>${source === "camera" ? "Confirm Photo" : "Confirm Upload"}</h3>
                        <button class="confirm-dialog-close" type="button">&times;</button>
                    </div>
                    <div class="confirm-dialog-body" style="text-align: center;">
                        <div class="photo-preview-container">
                            <img src="${previewUrl}" alt="Preview" class="photo-preview-img">
                        </div>
                        <p class="photo-preview-info">
                            <i class="fas fa-info-circle"></i>
                            ${escapeHtml(file.name)} (${(file.size / 1024).toFixed(1)} KB)
                        </p>
                        <p class="photo-preview-note">Use this as your profile picture?</p>
                    </div>
                    <div class="confirm-dialog-footer">
                        <button class="confirm-btn-cancel" type="button">Cancel</button>
                        <button class="confirm-btn-confirm" type="button">Yes, Update Photo</button>
                    </div>
                </div>
            </div>
        `;

    document.body.insertAdjacentHTML("beforeend", dialogHtml);

    const dialog = $("photoConfirmDialog");
    const confirmBtn = dialog.querySelector(".confirm-btn-confirm");
    const cancelBtn = dialog.querySelector(".confirm-btn-cancel");
    const closeBtn = dialog.querySelector(".confirm-dialog-close");

    const cleanup = () => {
      URL.revokeObjectURL(previewUrl);
      dialog?.remove();
    };

    confirmBtn.onclick = () => {
      cleanup();
      resolve(true);
    };
    cancelBtn.onclick = () => {
      cleanup();
      resolve(false);
    };
    closeBtn.onclick = () => {
      cleanup();
      resolve(false);
    };
    dialog.onclick = (e) => {
      if (e.target === dialog) {
        cleanup();
        resolve(false);
      }
    };
  });
}

async function uploadProfilePhoto(file, source) {
  const photoActionButtons = $("photoActionButtons");
  if (photoActionButtons) photoActionButtons.classList.add("loading");

  const wrapper = $("avatarWrapper");
  const overlay = document.createElement("div");
  overlay.className = "photo-upload-overlay";
  overlay.innerHTML = `
        <div class="photo-upload-spinner">
            <div class="spinner"></div>
            <p>Uploading...</p>
        </div>
    `;
  if (wrapper) {
    wrapper.style.position = "relative";
    wrapper.appendChild(overlay);
  }

  try {
    const csrf = await getCsrfToken("admin_profile_form");

    const formData = new FormData();
    formData.append("photo", file);
    formData.append("source", source);

    // CSRF fields go in as form fields — same as any other $_POST key
    if (csrf) {
      formData.append("csrf_token", csrf);
      formData.append("token_id", "admin_profile_form");
    }

    const response = await fetch("../backend/staffs/update_photo.php", {
      method: "POST",
      body: formData,
      credentials: "same-origin",
      // DO NOT set Content-Type header — the browser sets it with the boundary
    });

    const data = await response.json().catch(() => null);

    if (data && data.success) {
      showNotification("Profile photo updated successfully!", "success");

      if (profileData) profileData.photo = data.data?.photo || null;
      if (window.currentUser)
        window.currentUser.photo = data.data?.photo || null;

      setTimeout(fetchProfile, 300);

      window.dispatchEvent(
        new CustomEvent("userPhotoUpdated", {
          detail: {
            photo: data.data?.photo,
            url: data.data?.photo_url,
          },
        }),
      );
    } else {
      showNotification(data?.message || "Failed to update photo", "error");
    }
  } catch (err) {
    console.error("Photo upload error:", err);
    showNotification("Failed to upload photo. Please try again.", "error");
  } finally {
    overlay.remove();
    if (photoActionButtons) photoActionButtons.classList.remove("loading");
  }
}

function wirePhotoRefreshListener() {
  window.addEventListener("userPhotoUpdated", () => {
    // If something else changes the photo, refresh the profile card
    if (document.visibilityState === "visible") fetchProfile();
  });
}

// Cleanup camera on page unload
window.addEventListener("beforeunload", () => {
  stopCamera();
});

// ============================================================
// UI HELPERS
// ============================================================
function showLoading() {
  const el = $("loadingOverlay");
  if (el) el.style.display = "flex";
}

function hideLoading() {
  const el = $("loadingOverlay");
  if (el) el.style.display = "none";
}

function showError(title, message) {
  const el = $("errorContainer");
  if (!el) return;
  el.innerHTML = `
        <div class="error-icon"><i class="fas fa-exclamation-triangle"></i></div>
        <h3 class="error-title">${escapeHtml(title)}</h3>
        <p class="error-message">${escapeHtml(message)}</p>
        <button onclick="fetchProfile()" class="btn btn-primary">
            <i class="fas fa-redo"></i> Try Again
        </button>
    `;
  el.style.display = "flex";
}

function hideError() {
  const el = $("errorContainer");
  if (el) el.style.display = "none";
}

function showNotification(message, type = "success") {
  const notification = $("notification");
  if (!notification) return;

  const title = notification.querySelector(".notification-title");
  const msg = notification.querySelector(".notification-message");
  const icon = notification.querySelector(".notification-icon");

  if (title) title.textContent = type === "success" ? "Success" : "Error";
  if (msg) msg.textContent = message;

  notification.className = `notification notification-${type}`;
  if (icon) {
    icon.className = `fas fa-${type === "success" ? "check-circle" : "exclamation-circle"} notification-icon`;
  }

  notification.classList.add("show");
  setTimeout(() => notification.classList.remove("show"), 5000);
}

function formatNumber(value) {
  if (!value) return "0.00";
  return new Intl.NumberFormat("en-NG", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(value);
}

function escapeHtml(text) {
  if (text === null || text === undefined) return "";
  const div = document.createElement("div");
  div.textContent = String(text);
  return div.innerHTML;
}

// ============================================================
// MISC
// ============================================================
function togglePassword(fieldId) {
  const field = $(fieldId);
  if (!field) return;
  const toggleBtn = field.parentNode.querySelector(".toggle-password i");
  if (!toggleBtn) return;

  if (field.type === "password") {
    field.type = "text";
    toggleBtn.className = "fas fa-eye-slash";
  } else {
    field.type = "password";
    toggleBtn.className = "fas fa-eye";
  }
}

function wireModalCloseHandlers() {
  const modal = $("editProfileModal");
  if (!modal) return;

  modal.addEventListener("click", (e) => {
    if (e.target === modal) closeEditModal();
  });

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && modal.style.display === "flex") {
      closeEditModal();
    }
  });
}

async function getCsrfToken(formName) {
  try {
    const res = await fetch(
      `../backend/utilities/get_token.php?form=${encodeURIComponent(formName)}`,
      {
        method: "POST",
        credentials: "include",
      },
    );
    const data = await res.json();
    return data.success ? data.token : null;
  } catch (err) {
    console.error("CSRF token fetch failed:", err);
    return null;
  }
}

// Auto-refresh / network events
setInterval(
  () => {
    if (document.visibilityState === "visible") fetchProfile();
  },
  5 * 60 * 1000,
);

window.addEventListener("online", () => {
  showNotification("You are back online", "success");
  fetchProfile();
});

window.addEventListener("offline", () => {
  showNotification("You are offline. Some features may not work.", "error");
});
