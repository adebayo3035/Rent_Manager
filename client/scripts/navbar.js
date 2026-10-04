// ==================== GLOBAL VARIABLES ====================
let navbarCurrentUser = null;
let inactivityTimer = null;
let warningTimer = null;
let isWarningShowing = false;
let navbarUserPromise = null;

// Configuration
const INACTIVITY_TIMEOUT = 20 * 60 * 1000; // 20 minutes
const WARNING_TIMEOUT = 2 * 60 * 1000; // 2 minutes
const PHOTO_MAX_SIZE = 500000;
const PHOTO_ALLOWED_TYPES = ["image/jpeg", "image/png", "image/jpg"];
const PHOTO_ALLOWED_EXTENSIONS = ["jpg", "jpeg", "png"];
const NOTIFICATION_REFRESH_MS = 5* 60 * 1000; // 5 minutes

// Endpoints (client-only)
const API = {
  fetchProfile: "../backend/client/fetch_profile.php",
  updatePhoto: "../backend/client/update_profile_photo.php",
  logout: "../backend/authentication/logout.php",
  fetchNotifications: "../backend/notification/fetch_notifications.php",
};

// ==================== CAMERA VARIABLES ====================
let cameraStream = null;
let currentFacingMode = "user"; // 'user' = front, 'environment' = back

// ==================== NOTIFICATION BADGE VARIABLES ====================
let notificationRefreshInterval = null;

// ==================== INITIALIZATION ====================
document.addEventListener("DOMContentLoaded", async function () {
  await initializeApp();
  initNotificationBadge();

  setTimeout(() => {
    initializePhotoActions();
  }, 500);
});

async function initializeApp() {
  await fetchNavbarUserData();
  setupEventListeners();
  setupInactivityTimer();
}

function setupEventListeners() {
  const menuToggle = document.getElementById("menuToggle");
  const sidebarClose = document.getElementById("sidebarClose");
  const sidebarOverlay = document.getElementById("sidebarOverlay");
  const sidebar = document.getElementById("clientSidebar");

  if (menuToggle && sidebar) {
    menuToggle.addEventListener("click", function () {
      sidebar.classList.add("active");
      if (sidebarOverlay) sidebarOverlay.classList.add("active");
    });
  }

  if (sidebarClose && sidebar) {
    sidebarClose.addEventListener("click", function () {
      sidebar.classList.remove("active");
      if (sidebarOverlay) sidebarOverlay.classList.remove("active");
    });
  }

  if (sidebarOverlay && sidebar) {
    sidebarOverlay.addEventListener("click", function () {
      sidebar.classList.remove("active");
      sidebarOverlay.classList.remove("active");
    });
  }

  const logoutBtn = document.getElementById("logoutBtn");
  if (logoutBtn) {
    logoutBtn.removeEventListener("click", handleLogoutClick);
    logoutBtn.addEventListener("click", handleLogoutClick);
  }

  const notifBtn = document.getElementById("notificationsBtn");
  if (notifBtn) {
    notifBtn.removeEventListener("click", fetchNotificationsWithToast);
    notifBtn.addEventListener("click", fetchNotificationsWithToast);
  }

  // Set active nav item
  const currentPage = window.location.pathname
    .split("/")
    .pop()
    .replace(".php", "");
  document.querySelectorAll(".nav-item[data-page]").forEach((item) => {
    if (item.dataset.page === currentPage) {
      item.classList.add("active");
    } else {
      item.classList.remove("active");
    }
  });
}

// ==================== PROFILE PHOTO UPLOAD ====================
function initializePhotoActions() {
  const uploadBtn = document.getElementById("uploadPhotoBtn");
  const cameraBtn = document.getElementById("cameraPhotoBtn");
  const fileInput = document.getElementById("photoFileInput");

  if (uploadBtn && fileInput) {
    uploadBtn.addEventListener("click", function (e) {
      e.preventDefault();
      e.stopPropagation();
      fileInput.click();
    });

    fileInput.addEventListener("change", function (e) {
      if (e.target.files && e.target.files[0]) {
        handlePhotoSelection(e.target.files[0], "upload");
      }
      e.target.value = "";
    });
  }

  if (cameraBtn) {
    cameraBtn.addEventListener("click", function (e) {
      e.preventDefault();
      e.stopPropagation();
      openCameraModal();
    });
  }

  initializeCameraModalButtons();
}

// ==================== CAMERA CAPTURE ====================
async function openCameraModal() {
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    showToast("Camera is not supported on this device/browser", "error");
    return;
  }

  const modal = document.getElementById("cameraCaptureModal");
  if (!modal) {
    console.error("Camera modal not found in DOM");
    return;
  }

  // Idempotent thanks to dataset.listenerAttached guard
  initializeCameraModalButtons();

  modal.classList.add("active");
  document.body.style.overflow = "hidden";

  await startCamera();
}

function initializeCameraModalButtons() {
  const closeBtn = document.getElementById("closeCameraBtn");
  const captureBtn = document.getElementById("capturePhotoBtn");
  const cancelBtn = document.getElementById("cancelCameraBtn");
  const switchBtn = document.getElementById("switchCameraBtn");
  const modal = document.getElementById("cameraCaptureModal");

  if (closeBtn && !closeBtn.dataset.listenerAttached) {
    closeBtn.addEventListener("click", closeCameraModal);
    closeBtn.dataset.listenerAttached = "true";
  }

  if (cancelBtn && !cancelBtn.dataset.listenerAttached) {
    cancelBtn.addEventListener("click", closeCameraModal);
    cancelBtn.dataset.listenerAttached = "true";
  }

  if (captureBtn && !captureBtn.dataset.listenerAttached) {
    captureBtn.addEventListener("click", capturePhoto);
    captureBtn.dataset.listenerAttached = "true";
  }

  if (switchBtn && !switchBtn.dataset.listenerAttached) {
    switchBtn.addEventListener("click", switchCamera);
    switchBtn.dataset.listenerAttached = "true";
  }

  if (modal && !modal.dataset.listenerAttached) {
    modal.addEventListener("click", function (e) {
      if (e.target === modal) closeCameraModal();
    });
    modal.dataset.listenerAttached = "true";
  }

  if (!window._cameraEscapeHandler) {
    window._cameraEscapeHandler = function (e) {
      if (e.key === "Escape") {
        const m = document.getElementById("cameraCaptureModal");
        if (m && m.classList.contains("active")) closeCameraModal();
      }
    };
    document.addEventListener("keydown", window._cameraEscapeHandler);
  }
}

async function startCamera() {
  const video = document.getElementById("cameraVideo");
  const errorDiv = document.getElementById("cameraError");
  const errorMsg = document.getElementById("cameraErrorMessage");

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
      if (currentFacingMode === "user") {
        video.classList.add("mirrored");
      } else {
        video.classList.remove("mirrored");
      }
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
    showToast(message, "error");
  }
}

function stopCamera() {
  if (cameraStream) {
    cameraStream.getTracks().forEach((track) => track.stop());
    cameraStream = null;
  }

  const video = document.getElementById("cameraVideo");
  if (video) {
    video.srcObject = null;
    video.style.display = "block";
  }
  currentFacingMode = "user";
}

function closeCameraModal() {
  const modal = document.getElementById("cameraCaptureModal");
  if (modal) modal.classList.remove("active");
  document.body.style.overflow = "";
  stopCamera();
}

function capturePhoto() {
  const video = document.getElementById("cameraVideo");
  const canvas = document.getElementById("cameraCanvas");

  if (!video || !canvas || !cameraStream) {
    showToast("Camera is not ready", "error");
    return;
  }

  if (!video.videoWidth || !video.videoHeight) {
    showToast("Video is not ready. Please wait...", "warning");
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
    function (blob) {
      if (!blob) {
        showToast("Failed to capture photo", "error");
        return;
      }

      const timestamp = Date.now();
      const filename = `camera_${timestamp}.jpg`;
      const file = new File([blob], filename, {
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

// ==================== PHOTO HANDLING ====================
async function handlePhotoSelection(file, source = "upload") {
  if (!file) {
    showToast("No file selected", "error");
    return;
  }

  if (!PHOTO_ALLOWED_TYPES.includes(file.type.toLowerCase())) {
    showToast("Invalid file type. Please upload JPG or PNG", "error");
    return;
  }

  const extension = file.name.split(".").pop().toLowerCase();
  if (!PHOTO_ALLOWED_EXTENSIONS.includes(extension)) {
    showToast("Invalid file extension. Allowed: JPG, JPEG, PNG", "error");
    return;
  }

  if (file.size > PHOTO_MAX_SIZE) {
    const sizeKB = (file.size / 1024).toFixed(2);
    showToast(`File too large (${sizeKB}KB). Maximum: 500KB`, "error");
    return;
  }

  if (file.size < 1024) {
    showToast("File is too small. Please select a valid image.", "error");
    return;
  }

  try {
    await verifyImageFile(file);
  } catch (error) {
    showToast(error.message || "The file is not a valid image", "error");
    return;
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

    img.onload = function () {
      clearTimeout(timeout);
      URL.revokeObjectURL(url);

      if (img.width < 100 || img.height < 100) {
        reject(new Error("Image too small (minimum 100x100 pixels)"));
        return;
      }
      if (img.width > 8000 || img.height > 8000) {
        reject(new Error("Image too large (maximum 8000x8000 pixels)"));
        return;
      }
      resolve(true);
    };

    img.onerror = function () {
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
        <div class="confirm-dialog-content photo-dialog">
          <div class="confirm-dialog-header">
            <h3>${source === "camera" ? "Confirm Photo" : "Confirm Upload"}</h3>
            <button class="confirm-dialog-close">&times;</button>
          </div>
          <div class="confirm-dialog-body photo-dialog-body">
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
            <button class="confirm-btn-cancel">Cancel</button>
            <button class="confirm-btn-confirm">Yes, Update Photo</button>
          </div>
        </div>
      </div>
    `;

    document.body.insertAdjacentHTML("beforeend", dialogHtml);

    const dialog = document.getElementById("photoConfirmDialog");
    const confirmBtn = dialog.querySelector(".confirm-btn-confirm");
    const cancelBtn = dialog.querySelector(".confirm-btn-cancel");
    const closeBtn = dialog.querySelector(".confirm-dialog-close");

    const cleanup = () => {
      URL.revokeObjectURL(previewUrl);
      if (dialog && dialog.remove) dialog.remove();
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
  const photoElement = document.getElementById("photoElement");
  const actionButtons = document.getElementById("photoActionButtons");

  if (actionButtons) actionButtons.classList.add("loading");

  const overlay = document.createElement("div");
  overlay.className = "photo-upload-overlay";
  overlay.innerHTML = `
    <div class="photo-upload-spinner">
      <div class="spinner"></div>
      <p>Uploading...</p>
    </div>
  `;
  if (photoElement) {
    photoElement.style.position = "relative";
    photoElement.appendChild(overlay);
  }

  try {
    const formData = new FormData();
    formData.append("photo", file);
    formData.append("source", source);

    const response = await fetch(API.updatePhoto, {
      method: "POST",
      body: formData,
      credentials: "same-origin",
    });

    const data = await response.json();

    if (data.success) {
      showToast("Profile photo updated successfully!", "success");

      if (data.data && data.data.photo_filename) {
        if (navbarCurrentUser) {
          navbarCurrentUser.photo = data.data.photo_filename;
          navbarCurrentUser.photo_url = data.data.photo_url; // ← add
        }
        if (window.currentUser) {
          window.currentUser.photo = data.data.photo_filename;
          window.currentUser.photo_url = data.data.photo_url; // ← add
        }

        await updateUserInfo();

        window.dispatchEvent(
          new CustomEvent("userPhotoUpdated", {
            detail: {
              photo: data.data.photo_filename,
              url: data.data.photo_url,
            },
          }),
        );
      }
    } else {
      showToast(data.message || "Failed to update photo", "error");
    }
  } catch (error) {
    console.error("Photo upload error:", error);
    showToast("Failed to upload photo. Please try again.", "error");
  } finally {
    if (overlay && overlay.remove) overlay.remove();
    if (actionButtons) actionButtons.classList.remove("loading");
  }
}

window.addEventListener("beforeunload", function () {
  stopCamera();
});

// ==================== NOTIFICATION BADGE ====================
async function updateNotificationBadge() {
  try {
    if (!navbarCurrentUser && !window.currentUser) return;

    const response = await fetch(`${API.fetchNotifications}?limit=1`);
    const data = await response.json();

    if (data.success) {
      const count = data.data?.unread_count || data.data.unread_count || 0;
      const badge = document.getElementById("notificationBadge");

      if (badge) {
        badge.textContent = count > 99 ? "99+" : count;
        badge.style.display = count > 0 ? "flex" : "none";
      }
    }
  } catch (error) {
    console.error("Error fetching notification count:", error);
  }
}

async function fetchNotificationsWithToast() {
  try {
    const response = await fetch(API.fetchNotifications);
    const data = await response.json();

    if (data.success) {
      const count = data.data?.unread_count || data.data.unread_count || 0;
      const badge = document.getElementById("notificationBadge");

      if (badge) {
        badge.textContent = count > 99 ? "99+" : count;
        badge.style.display = count > 0 ? "flex" : "none";
      }

      if (count > 0) {
        showToast(
          `You have ${count} unread notification${count > 1 ? "s" : ""}`,
          "info",
        );
      } else {
        showToast("No new notifications", "info");
      }
    }
  } catch (error) {
    console.error("Error fetching notifications:", error);
    showToast("Failed to load notifications", "error");
  }
}

async function initNotificationBadge() {
  if (navbarCurrentUser || window.currentUser) {
    await updateNotificationBadge();
    startNotificationRefresh();
  } else {
    window.addEventListener("userDataLoaded", async function () {
      await updateNotificationBadge();
      startNotificationRefresh();
    });

    setTimeout(async () => {
      if (navbarCurrentUser || window.currentUser) {
        await updateNotificationBadge();
        startNotificationRefresh();
      }
    }, 1000);
  }
}

function startNotificationRefresh() {
  if (notificationRefreshInterval) clearInterval(notificationRefreshInterval);

  notificationRefreshInterval = setInterval(() => {
    if (navbarCurrentUser || window.currentUser) {
      updateNotificationBadge();
    }
  }, NOTIFICATION_REFRESH_MS);
}

function stopNotificationRefresh() {
  if (notificationRefreshInterval) {
    clearInterval(notificationRefreshInterval);
    notificationRefreshInterval = null;
  }
}

// ==================== LOGOUT ====================
function handleLogoutClick(e) {
  e.preventDefault();
  if (navbarCurrentUser) {
    handleLogout();
  } else {
    console.error("Current user not loaded yet");
    showToast("Please wait, loading user data...", "warning");
    setTimeout(() => {
      if (navbarCurrentUser) {
        handleLogout();
      } else {
        showToast("Unable to logout. Please refresh the page.", "error");
      }
    }, 1000);
  }
}

async function handleLogout() {
  if (!navbarCurrentUser) {
    console.error("No currentUser available for logout");
    showToast("User session not found", "error");
    return;
  }

  stopNotificationRefresh();

  const existingDialog = document.getElementById("customConfirmDialog");
  if (existingDialog) existingDialog.remove();

  const dialogHtml = `
    <div id="customConfirmDialog" class="confirm-dialog">
      <div class="confirm-dialog-content">
        <div class="confirm-dialog-header">
          <h3>Logout</h3>
          <button class="confirm-dialog-close">&times;</button>
        </div>
        <div class="confirm-dialog-body">
          <p>Are you sure you want to logout?</p>
        </div>
        <div class="confirm-dialog-footer">
          <button class="confirm-btn-cancel">Cancel</button>
          <button class="confirm-btn-confirm">Logout</button>
        </div>
      </div>
    </div>
  `;

  document.body.insertAdjacentHTML("beforeend", dialogHtml);

  const dialog = document.getElementById("customConfirmDialog");
  const confirmBtn = dialog.querySelector(".confirm-btn-confirm");
  const cancelBtn = dialog.querySelector(".confirm-btn-cancel");
  const closeBtn = dialog.querySelector(".confirm-dialog-close");

  const closeDialog = () => {
    if (dialog && dialog.remove) dialog.remove();
  };

  confirmBtn.onclick = async () => {
    closeDialog();
    showToast("Logging out...", "info");

    try {
      const response = await fetch(API.logout, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify({
          logout_id: navbarCurrentUser.client_code,
        }),
      });

      const data = await response.json();

      if (data.success) {
        showToast("Logged out successfully", "success");
        setTimeout(() => {
          window.location.href = "../pages/index.php";
        }, 1000);
      } else {
        throw new Error(data.message || "Logout failed");
      }
    } catch (error) {
      console.error("Logout error:", error);
      showToast(error.message || "Logout failed. Please try again.", "error");
    }
  };

  cancelBtn.onclick = closeDialog;
  closeBtn.onclick = closeDialog;
}

// ==================== INACTIVITY TIMER ====================
function setupInactivityTimer() {
  const activityEvents = [
    "mousedown",
    "mousemove",
    "keypress",
    "scroll",
    "touchstart",
    "click",
  ];

  activityEvents.forEach((event) => {
    document.addEventListener(event, resetInactivityTimer);
  });

  resetInactivityTimer();
}

function resetInactivityTimer() {
  clearInactivityTimer();
  clearWarningTimer();
  isWarningShowing = false;

  inactivityTimer = setTimeout(() => {
    showInactivityWarning();
  }, INACTIVITY_TIMEOUT);
}

function clearInactivityTimer() {
  if (inactivityTimer) {
    clearTimeout(inactivityTimer);
    inactivityTimer = null;
  }
}

function clearWarningTimer() {
  if (warningTimer) {
    clearTimeout(warningTimer);
    warningTimer = null;
  }
}

function showInactivityWarning() {
  if (isWarningShowing) return;
  isWarningShowing = true;

  warningTimer = setTimeout(() => {
    performAutoLogout();
  }, WARNING_TIMEOUT);

  showConfirmationDialog(
    "Session Timeout Warning",
    "You have been inactive for a while. Do you want to stay logged in?",
    () => {
      // Stay logged in
      clearWarningTimer();
      isWarningShowing = false;
      resetInactivityTimer();
      showToast("Session extended", "success");
    },
    () => {
      // Logout
      performAutoLogout();
    },
  );
}

async function performAutoLogout() {
  stopNotificationRefresh();

  if (!navbarCurrentUser) {
    window.location.href = "../pages/index.php";
    return;
  }

  try {
    const response = await fetch(API.logout, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        logout_id: navbarCurrentUser.client_code,
        auto_logout: true,
      }),
    });
    const data = await response.json();

    if (data.success) {
      window.location.href = "../pages/index.php?message=session_expired";
    } else {
      window.location.href = "../pages/index.php";
    }
  } catch (error) {
    console.error("Auto-logout error:", error);
    window.location.href = "../pages/index.php";
  } finally {
    clearInactivityTimer();
    clearWarningTimer();
  }
}

function showConfirmationDialog(title, message, onStay, onLogout) {
  const existingDialog = document.getElementById("customConfirmDialog");
  if (existingDialog) existingDialog.remove();

  const dialogHtml = `
    <div id="customConfirmDialog" class="confirm-dialog">
      <div class="confirm-dialog-content">
        <div class="confirm-dialog-header">
          <h3>${escapeHtml(title)}</h3>
          <button class="confirm-dialog-close">&times;</button>
        </div>
        <div class="confirm-dialog-body">
          <p>${escapeHtml(message)}</p>
        </div>
        <div class="confirm-dialog-footer">
          <button class="confirm-btn-cancel">Logout</button>
          <button class="confirm-btn-confirm">Stay Logged In</button>
        </div>
      </div>
    </div>
  `;

  document.body.insertAdjacentHTML("beforeend", dialogHtml);

  const dialog = document.getElementById("customConfirmDialog");
  const confirmBtn = dialog.querySelector(".confirm-btn-confirm");
  const cancelBtn = dialog.querySelector(".confirm-btn-cancel");
  const closeBtn = dialog.querySelector(".confirm-dialog-close");

  const closeDialog = () => dialog.remove();

  confirmBtn.onclick = () => {
    closeDialog();
    if (onStay) onStay();
  };
  cancelBtn.onclick = () => {
    closeDialog();
    if (onLogout) onLogout();
  };
  closeBtn.onclick = () => {
    closeDialog();
    if (onLogout) onLogout();
  };
}

// ==================== USER DATA ====================
async function fetchNavbarUserData() {
  if (navbarCurrentUser) return navbarCurrentUser;

  if (window.currentUser && window.currentUser.client_code) {
    navbarCurrentUser = window.currentUser;
    await updateUserInfo();
    return navbarCurrentUser;
  }

  if (!navbarUserPromise) {
    navbarUserPromise = (async () => {
      try {
        const response = await fetch(API.fetchProfile);
        const data = await response.json();

        if (!(data.success && data.data)) {
          throw new Error(data.message || "Failed to fetch user data");
        }

        navbarCurrentUser = data.data;
        window.currentUser = navbarCurrentUser;

        await updateUserInfo();
        window.dispatchEvent(
          new CustomEvent("userDataLoaded", { detail: navbarCurrentUser }),
        );
        return navbarCurrentUser;
      } catch (error) {
        console.error("Error fetching user data:", error);
        showToast("Failed to load user information", "error");
        setTimeout(() => {
          window.location.href = "../pages/index.php";
        }, 2000);
        throw error;
      } finally {
        navbarUserPromise = null;
      }
    })();
  }

  return navbarUserPromise;
}

async function updateUserInfo() {
  await new Promise((resolve) => setTimeout(resolve, 100));

  const user = window.currentUser || navbarCurrentUser;
  if (!user) {
    console.error("No currentUser available");
    return;
  }

  navbarCurrentUser = user;

  const nameElement = document.getElementById("clientName");
  const idElement = document.getElementById("clientCode");
  const photoElement = document.getElementById("photoElement");

  const fullName = `${user.firstname || ""} ${user.lastname || ""}`.trim();
  const clientCode = user.client_code || user.id || "Client ID Not Found";

  if (nameElement) {
    nameElement.textContent = fullName || "Client";
  }

  if (idElement) {
    idElement.textContent = `Client ID: ${clientCode}`;
  }

  if (photoElement) {
    photoElement.innerHTML = "";

    if (user.photo) {
      const img = document.createElement("img");
      img.alt = `${fullName}'s photo`;
      img.className = "client-photo-img";

      if (user.photo_url) {
        // Backend already returned the full path → use verbatim
        img.src = user.photo_url;
      } else {
        // Fallback: build from appBasePath + relative path
        const appBasePath = window.location.pathname.includes("/client/")
          ? window.location.pathname.split("/client/")[0]
          : "";
        img.src = `${appBasePath}/admin/backend/clients/client_photos/${user.photo}`;
      }

      img.onerror = function () {
        console.error("Failed to load image from:", img.src);
        img.remove();
        renderUserInitials(photoElement, fullName);
      };

      photoElement.appendChild(img);
    } else {
      renderUserInitials(photoElement, fullName);
    }
  }
}

function renderUserInitials(container, fullName) {
  if (!container) return;

  const initials =
    (fullName || "Client")
      .split(" ")
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part.charAt(0).toUpperCase())
      .join("") || "C";

  container.innerHTML = `
    <div class="client-initials">
      ${escapeHtml(initials)}
    </div>
  `;
}

// ==================== UTILITY FUNCTIONS ====================
function showToast(message, type = "info") {
  const existingToasts = document.querySelectorAll(".toast-notification");
  existingToasts.forEach((toast) => toast.remove());

  const icon =
    type === "success"
      ? "fa-check-circle"
      : type === "error"
        ? "fa-exclamation-circle"
        : "fa-info-circle";

  const toast = document.createElement("div");
  toast.className = `toast-notification ${type}`;
  toast.innerHTML = `<i class="fas ${icon}"></i><span>${escapeHtml(message)}</span>`;
  document.body.appendChild(toast);

  setTimeout(() => {
    if (toast && toast.remove) toast.remove();
  }, 3000);
}

function escapeHtml(text) {
  if (!text) return "";
  const div = document.createElement("div");
  div.textContent = text;
  return div.innerHTML;
}

// Expose globally for other scripts
window.currentUser = window.currentUser || null;
window.fetchNavbarUserData = fetchNavbarUserData;
window.updateUserInfo = updateUserInfo;
window.showToast = showToast;
window.handleLogout = handleLogout;
