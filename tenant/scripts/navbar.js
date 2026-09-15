// navbar.js - Refactored with real camera capture support

// ==================== GLOBAL VARIABLES ====================
let navbarCurrentUser = null;
let inactivityTimer = null;
let warningTimer = null;
let isWarningShowing = false;
let navbarUserPromise = null;

// Configuration
const INACTIVITY_TIMEOUT = 20 * 60 * 1000; // 20 minutes
const WARNING_TIMEOUT = 2 * 60 * 1000; // 2 minutes warning
const PHOTO_MAX_SIZE = 500000;
const PHOTO_ALLOWED_TYPES = ["image/jpeg", "image/png", "image/jpg"];
const PHOTO_ALLOWED_EXTENSIONS = ["jpg", "jpeg", "png"];

// ==================== CAMERA VARIABLES ====================
let cameraStream = null;
let currentFacingMode = "user"; // 'user' = front, 'environment' = back

// ==================== NOTIFICATION BADGE VARIABLES ====================
let notificationRefreshInterval = null;

// ==================== INITIALIZATION ====================
document.addEventListener("DOMContentLoaded", async function () {
  await initializeApp();
  initNotificationBadge();

  // Initialize photo actions
  setTimeout(() => {
    initializePhotoActions();
  }, 500);
});

function getAppBasePath() {
    const pathParts = window.location.pathname.split("/");
    const tenantIndex = pathParts.indexOf("tenant");
    if (tenantIndex > 0) {
        return pathParts.slice(0, tenantIndex).join("/");
    }
    return "";
}

async function initializeApp() {
  await fetchNavbarUserData();
  setupEventListeners();
  setupInactivityTimer();
}

function setupEventListeners() {
  // Mobile menu toggle
  const menuToggle = document.getElementById("menuToggle");
  const sidebarClose = document.getElementById("sidebarClose");
  const sidebarOverlay = document.getElementById("sidebarOverlay");
  const sidebar = document.getElementById("tenantSidebar");

  if (menuToggle) {
    menuToggle.addEventListener("click", function () {
      if (sidebar) sidebar.classList.add("active");
      if (sidebarOverlay) sidebarOverlay.classList.add("active");
    });
  }

  if (sidebarClose) {
    sidebarClose.addEventListener("click", function () {
      if (sidebar) sidebar.classList.remove("active");
      if (sidebarOverlay) sidebarOverlay.classList.remove("active");
    });
  }

  if (sidebarOverlay) {
    sidebarOverlay.addEventListener("click", function () {
      if (sidebar) sidebar.classList.remove("active");
      sidebarOverlay.classList.remove("active");
    });
  }

  // Logout button
  const logoutBtn = document.getElementById("logoutBtn");
  if (logoutBtn) {
    logoutBtn.removeEventListener("click", handleLogoutClick);
    logoutBtn.addEventListener("click", handleLogoutClick);
  }

  // Notifications button
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

/**
 * Initialize photo upload/camera buttons
 */
function initializePhotoActions() {
  const uploadBtn = document.getElementById("uploadPhotoBtn");
  const cameraBtn = document.getElementById("cameraPhotoBtn");
  const fileInput = document.getElementById("photoFileInput");

  // Upload from device (pencil icon)
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

  // Camera icon - use MediaDevices API
  if (cameraBtn) {
    cameraBtn.addEventListener("click", function (e) {
      e.preventDefault();
      e.stopPropagation();
      openCameraModal();
    });
  }

  // Initialize camera modal buttons
  initializeCameraModalButtons();
}

// ==================== CAMERA CAPTURE ====================

/**
 * Open camera modal
 */
async function openCameraModal() {
  // Check if MediaDevices is supported
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    showToast("Camera is not supported on this device/browser", "error");
    return;
  }

  // Create modal if it doesn't exist
  let modal = document.getElementById("cameraCaptureModal");
  if (!modal) {
    modal = document.createElement("div");
    modal.id = "cameraCaptureModal";
    modal.className = "camera-modal";
    modal.innerHTML = `
            <div class="camera-modal-content">
                <div class="camera-modal-header">
                    <h3><i class="fas fa-camera"></i> Take Photo</h3>
                    <button class="camera-modal-close" id="closeCameraBtn">&times;</button>
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
                    <button class="camera-btn-switch" id="switchCameraBtn" title="Switch Camera">
                        <i class="fas fa-sync-alt"></i> Switch
                    </button>
                    <button class="camera-btn-capture" id="capturePhotoBtn">
                        <i class="fas fa-circle"></i> Capture
                    </button>
                    <button class="camera-btn-cancel" id="cancelCameraBtn">Cancel</button>
                </div>
            </div>
        `;
    document.body.appendChild(modal);

    // Attach listeners only once when the modal is created
    initializeCameraModalButtons();
  }

  // Show modal
  modal.classList.add("active");
  document.body.style.overflow = "hidden";

  // Start camera
  await startCamera();
}

/**
 * Initialize camera modal button listeners
 */
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
      if (e.target === modal) {
        closeCameraModal();
      }
    });
    modal.dataset.listenerAttached = "true";
  }

  // Escape key handler (attach once to document)
  if (!window._cameraEscapeHandler) {
    window._cameraEscapeHandler = function (e) {
      if (e.key === "Escape") {
        const m = document.getElementById("cameraCaptureModal");
        if (m && m.classList.contains("active")) {
          closeCameraModal();
        }
      }
    };
    document.addEventListener("keydown", window._cameraEscapeHandler);
  }
}

/**
 * Start the camera stream
 */
async function startCamera() {
  const video = document.getElementById("cameraVideo");
  const errorDiv = document.getElementById("cameraError");
  const errorMsg = document.getElementById("cameraErrorMessage");

  // Stop any existing stream
  stopCamera();

  // Reset error state
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

      // Mirror preview if using front camera
      if (currentFacingMode === "user") {
        video.classList.add("mirrored");
      } else {
        video.classList.remove("mirrored");
      }

      await video.play();
    }

    console.log("Camera started:", currentFacingMode);
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

/**
 * Stop the camera stream
 */
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

/**
 * Close camera modal
 */
function closeCameraModal() {
  const modal = document.getElementById("cameraCaptureModal");
  if (modal) {
    modal.classList.remove("active");
  }
  document.body.style.overflow = "";
  stopCamera();
}

/**
 * Capture photo from video stream
 */
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

  // Set canvas dimensions to match video
  canvas.width = video.videoWidth;
  canvas.height = video.videoHeight;

  const ctx = canvas.getContext("2d");

  // Mirror captured image if front camera
  if (currentFacingMode === "user") {
    ctx.translate(canvas.width, 0);
    ctx.scale(-1, 1);
  }

  // Draw the video frame
  ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

  // Convert to blob
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

      // Close camera modal
      closeCameraModal();

      // Trigger the existing photo flow
      handlePhotoSelection(file, "camera");
    },
    "image/jpeg",
    0.9,
  );
}

/**
 * Switch between front and back cameras
 */
async function switchCamera() {
  currentFacingMode = currentFacingMode === "user" ? "environment" : "user";
  await startCamera();
}

// ==================== PHOTO HANDLING ====================

/**
 * Handle photo selection (from file input or camera)
 */
async function handlePhotoSelection(file, source = "upload") {
  // 1. Check file exists
  if (!file) {
    showToast("No file selected", "error");
    return;
  }

  // 2. Check file type
  if (!PHOTO_ALLOWED_TYPES.includes(file.type.toLowerCase())) {
    showToast("Invalid file type. Please upload JPG or PNG", "error");
    return;
  }

  // 3. Check extension
  const extension = file.name.split(".").pop().toLowerCase();
  if (!PHOTO_ALLOWED_EXTENSIONS.includes(extension)) {
    showToast("Invalid file extension. Allowed: JPG, JPEG, PNG", "error");
    return;
  }

  // 4. Check file size
  if (file.size > PHOTO_MAX_SIZE) {
    const sizeKB = (file.size / 1024).toFixed(2);
    showToast(`File too large (${sizeKB}KB). Maximum: 500KB`, "error");
    return;
  }

  // 5. Check minimum file size
  if (file.size < 1024) {
    showToast("File is too small. Please select a valid image.", "error");
    return;
  }

  // 6. Verify it's actually an image
  try {
    await verifyImageFile(file);
  } catch (error) {
    showToast(error.message || "The file is not a valid image", "error");
    return;
  }

  // Show confirmation
  const confirmed = await showPhotoConfirmDialog(file, source);
  if (!confirmed) return;

  // Upload
  await uploadProfilePhoto(file, source);
}

/**
 * Verify the file is actually an image
 */
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

/**
 * Show photo confirmation dialog with preview
 */
function showPhotoConfirmDialog(file, source) {
  return new Promise((resolve) => {
    const previewUrl = URL.createObjectURL(file);

    const dialogHtml = `
            <div id="photoConfirmDialog" class="confirm-dialog">
                <div class="confirm-dialog-content" style="max-width: 450px;">
                    <div class="confirm-dialog-header">
                        <h3>${source === "camera" ? "Confirm Photo" : "Confirm Upload"}</h3>
                        <button class="confirm-dialog-close">&times;</button>
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

/**
 * Upload the photo to the server
 */
async function uploadProfilePhoto(file, source) {
  const photoElement = document.getElementById("photoElement");
  const actionButtons = document.getElementById("photoActionButtons");

  if (actionButtons) actionButtons.classList.add("loading");

  // Create overlay
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

    const response = await fetch("../backend/tenant/update_profile_photo.php", {
      method: "POST",
      body: formData,
      credentials: "same-origin",
    });

    const data = await response.json();

    if (data.success) {
      showToast("Profile photo updated successfully!", "success");

      if (data.data && data.data.photo_filename) {
        if (navbarCurrentUser)
          navbarCurrentUser.photo = data.data.photo_filename;
        if (window.currentUser)
          window.currentUser.photo = data.data.photo_filename;

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

// Cleanup camera on page unload
window.addEventListener("beforeunload", function () {
  stopCamera();
});

// ==================== NOTIFICATION BADGE FUNCTIONS ====================

async function updateNotificationBadge() {
  try {
    if (!navbarCurrentUser && !window.currentUser) return;

    const response = await fetch(
      "../backend/notification/fetch_notifications.php?limit=1",
    );
    const data = await response.json();

    if (data.success) {
      const count = data.data?.unread_count || data.unread_count || 0;
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
    const response = await fetch(
      "../backend/notification/fetch_notifications.php",
    );
    const data = await response.json();

    if (data.success) {
      const count = data.data?.unread_count || data.unread_count || 0;
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
  }, 600000);
}

function stopNotificationRefresh() {
  if (notificationRefreshInterval) {
    clearInterval(notificationRefreshInterval);
    notificationRefreshInterval = null;
  }
}

function handleLogoutClick(e) {
  e.preventDefault();
  if (navbarCurrentUser) {
    handleLogout();
  } else {
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

// ==================== USER DATA ====================
async function fetchNavbarUserData() {
    if (navbarCurrentUser) return navbarCurrentUser;

    if (window.currentUser && window.currentUser.tenant_code) {
        navbarCurrentUser = window.currentUser;
        await updateUserInfo();
        return navbarCurrentUser;
    }

    if (!navbarUserPromise) {
        navbarUserPromise = (async () => {
            try {
                const response = await fetch("../backend/tenant/fetch_user_data.php");

                // Handle non-OK HTTP responses
                if (!response.ok) {
                    const error = new Error(`HTTP ${response.status}: ${response.statusText}`);
                    error.statusCode = response.status;
                    throw error;
                }

                const data = await response.json();

                if (!(data.success && data.data)) {
                    const error = new Error(data.message || "Failed to fetch user data");
                    error.statusCode = data.status_code || 500;
                    throw error;
                }

                navbarCurrentUser = data.data;
                window.currentUser = navbarCurrentUser;

                await updateUserInfo();
                window.dispatchEvent(
                    new CustomEvent("userDataLoaded", { detail: navbarCurrentUser })
                );
                return navbarCurrentUser;

            } catch (error) {
                console.error("Error fetching user data:", error);

                // ✅ Only redirect on AUTH errors — never on JS bugs
                const statusCode = error.statusCode || error.status;
                const isAuthError =
                    statusCode === 401 ||
                    statusCode === 403 ||
                    (error.message && (
                        error.message.toLowerCase().includes("unauthorized") ||
                        error.message.toLowerCase().includes("not logged in") ||
                        error.message.toLowerCase().includes("session")
                    ));

                if (isAuthError) {
                    // Real auth problem — redirect to login
                    showToast("Session expired. Please login again.", "warning");
                    setTimeout(() => {
                        window.location.href = "../pages/index.php";
                    }, 2000);
                } else {
                    // Non-auth error (network, JS, server bug) — don't log out!
                    showToast("Failed to load user information", "error");
                    // Optional: retry once after 3 seconds
                    setTimeout(() => { fetchNavbarUserData(); }, 3000);
                }

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

    const nameElement = document.getElementById("tenantName");
    const apartmentElement = document.getElementById("tenantApartment");
    const photoElement = document.getElementById("photoElement");

    const fullName = `${user.firstname || ""} ${user.lastname || ""}`.trim();
    const apartmentNumber =
        user.apartment_number || user.apartment_code || "No Apartment";

    if (nameElement) nameElement.textContent = fullName || "Tenant";
    if (apartmentElement)
        apartmentElement.textContent = `Apartment Number: ${apartmentNumber}`;

    if (photoElement) {
        photoElement.innerHTML = "";

        if (user.photo) {
            // ✅ Correctly resolve base path
            const appBasePath = getAppBasePath();
            const photoUrl = `${appBasePath}/admin/backend/tenants/tenant_photos/${user.photo}`;

            const img = document.createElement("img");
            img.alt = `${fullName}'s photo`;
            img.src = photoUrl;

            img.onerror = function () {
                console.warn("Failed to load photo:", img.src);
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

    const initials = (fullName || 'Tenant')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map(part => part.charAt(0).toUpperCase())
        .join('') || 'T';

    // No inline styles — use CSS classes
    container.innerHTML = `<span class="tenant-initials">${escapeHtml(initials)}</span>`;
}

// ==================== LOGOUT ====================
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
      const response = await fetch("../backend/authentication/logout.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify({ logout_id: navbarCurrentUser.tenant_code }),
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
      clearWarningTimer();
      isWarningShowing = false;
      resetInactivityTimer();
      showToast("Session extended", "success");
    },
    () => {
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
    const response = await fetch("../backend/authentication/logout.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        logout_id: navbarCurrentUser.tenant_code,
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

function showConfirmationDialog(title, message, onConfirm, onCancel) {
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
    if (onConfirm) onConfirm();
  };
  cancelBtn.onclick = () => {
    closeDialog();
    if (onCancel) onCancel();
  };
  closeBtn.onclick = () => {
    closeDialog();
    if (onCancel) onCancel();
  };
}

// ==================== UTILITY FUNCTIONS ====================
function showToast(message, type = "info") {
  const existingToasts = document.querySelectorAll(".toast-notification");
  existingToasts.forEach((toast) => toast.remove());

  const toast = document.createElement("div");
  toast.className = `toast-notification ${type}`;
  toast.innerHTML = `<i class="fas ${type === "success" ? "fa-check-circle" : type === "error" ? "fa-exclamation-circle" : "fa-info-circle"}"></i><span>${escapeHtml(message)}</span>`;
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

// Make functions globally available
window.currentUser = window.currentUser || null;
window.fetchNavbarUserData = fetchNavbarUserData;
window.updateUserInfo = updateUserInfo;
window.showToast = showToast;
window.handleLogout = handleLogout;
