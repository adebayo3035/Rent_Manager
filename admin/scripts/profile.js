// Global variables
let profileData = null;

// DOM Elements
const loadingOverlay = document.getElementById("loadingOverlay");
const errorContainer = document.getElementById("errorContainer");
const notification = document.getElementById("notification");

// Initialize on page load
document.addEventListener("DOMContentLoaded", function () {
  fetchProfile();
});

// Fetch profile data from API
async function fetchProfile() {
  showLoading();
  hideError();

  try {
    const response = await fetch("../backend/staffs/staff_profile.php", {
      method: "GET",
      headers: {
        "Content-Type": "application/json",
      },
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
      "Unable to connect to server. Please check your connection."
    );
  } finally {
    hideLoading();
  }
}

// Render profile data to the page
// In your profile.php JavaScript section, update the renderProfile function:

function renderProfile(data) {
  // Render avatar section
  const avatarSection = document.getElementById("avatarSection");
  if (data.has_photo && data.photo_url) {
    avatarSection.innerHTML = `
            <div class="avatar-container">
                <img src="${data.photo_url}" alt="${data.full_name}" class="avatar-image">
                <div class="avatar-badge">
                    <i class="fas fa-check"></i>
                </div>
            </div>
            <h2 class="profile-name">${data.full_name}</h2>
            <div class="profile-role">${data.role_display}</div>
            <div class="profile-email">
                <i class="fas fa-envelope"></i>
                ${data.email}
            </div>
        `;
  } else {
    avatarSection.innerHTML = `
            <div class="avatar-container">
                <div class="avatar-placeholder">
                    ${data.initials}
                </div>
                <div class="avatar-badge">
                    <i class="fas fa-check"></i>
                </div>
            </div>
            <h2 class="profile-name">${data.full_name}</h2>
            <div class="profile-role">${data.role_display}</div>
            <div class="profile-email">
                <i class="fas fa-envelope"></i>
                ${data.email}
            </div>
        `;
  }

  // Update personal information section with new fields
  const personalInfo = document.getElementById("personalInfo");
  personalInfo.innerHTML = `
        <div class="info-item">
            <div class="info-label">Full Name</div>
            <div class="info-value">${data.full_name}</div>
        </div>
        <div class="info-item">
            <div class="info-label">Email Address</div>
            <div class="info-value">${data.email}</div>
        </div>
        <div class="info-item">
            <div class="info-label">Phone Number</div>
            <div class="info-value">${data.phone || "Not specified"}</div>
        </div>
        <div class="info-item">
            <div class="info-label">Gender</div>
            <div class="info-value">${data.gender_display}</div>
        </div>
        <div class="info-item">
            <div class="info-label">Address</div>
            <div class="info-value">${data.address || "Not specified"}</div>
        </div>
        <div class="info-item">
            <div class="info-label">Role</div>
            <div class="info-value">${data.role_display}</div>
        </div>
        <div class="info-item">
            <div class="info-label">Account Status</div>
            <div class="info-value">
                <span class="status-badge ${data.status_class}">
                    ${data.status_display}
                </span>
            </div>
        </div>
        <div class="info-item">
            <div class="info-label">Member Since</div>
            <div class="info-value">${data.created_at_formatted || "--"}</div>
        </div>
        <div class="info-item">
            <div class="info-label">Account Age</div>
            <div class="info-value">${data.account_age || "--"}</div>
        </div>
        <div class="info-item">
            <div class="info-label">Last Updated</div>
            <div class="info-value">${data.updated_at_formatted || "--"}</div>
        </div>
    `;

  // Update stats section
  const statsSection = document.getElementById("statsSection");
  statsSection.innerHTML = `
        <div class="stat-item">
            <div class="stat-value">${data.stats?.total_logins || 0}</div>
            <div class="stat-label">Total Logins</div>
        </div>
        <div class="stat-item">
            <div class="stat-value">${data.member_since || "--"}</div>
            <div class="stat-label">Member Since</div>
        </div>
    `;

  // Update activity section with last login
  const activityList = document.getElementById("activityList");
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
      time: "1 month ago",
    },
  ];

  activityList.innerHTML = activities
    .map(
      (activity) => `
        <li class="activity-item">
            <div class="activity-icon">
                <i class="${activity.icon}"></i>
            </div>
            <div class="activity-content">
                <p class="activity-text">${activity.text}</p>
                <span class="activity-time">${activity.time}</span>
            </div>
        </li>
    `
    )
    .join("");
}

// Refresh profile
function refreshProfile() {
  fetchProfile();
}

// Edit profile (placeholder function)
function editProfile() {
  if (!profileData) {
    showNotification("Profile data not loaded yet", "error");
    return;
  }

  openEditModal(profileData);
}

// UI Helper Functions
function showLoading() {
  loadingOverlay.style.display = "flex";
}

function hideLoading() {
  loadingOverlay.style.display = "none";
}

function showError(title, message) {
  errorContainer.innerHTML = `
                <div class="error-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h3 class="error-title">${title}</h3>
                <p class="error-message">${message}</p>
                <button onclick="fetchProfile()" class="btn btn-primary">
                    <i class="fas fa-redo"></i> Try Again
                </button>
            `;
  errorContainer.style.display = "flex";
}

function hideError() {
  errorContainer.style.display = "none";
}

function showNotification(message, type = "success") {
  const title = notification.querySelector(".notification-title");
  const msg = notification.querySelector(".notification-message");
  const icon = notification.querySelector(".notification-icon");

  title.textContent = type === "success" ? "Success" : "Error";
  msg.textContent = message;

  notification.className = `notification notification-${type}`;
  icon.className = `fas fa-${
    type === "success" ? "check-circle" : "exclamation-circle"
  } notification-icon`;

  notification.classList.add("show");

  setTimeout(() => {
    notification.classList.remove("show");
  }, 5000);
}

// Auto-refresh every 5 minutes
setInterval(() => {
  if (document.visibilityState === "visible") {
    fetchProfile();
  }
}, 5 * 60 * 1000);

// Handle offline/online events
window.addEventListener("online", () => {
  showNotification("You are back online", "success");
  fetchProfile();
});

window.addEventListener("offline", () => {
  showNotification("You are offline. Some features may not work.", "error");
});

// Current profile data
let currentProfileData = null;
let currentPhoto = null;

// Open edit modal with current data
function openEditModal(profileData) {
  currentProfileData = profileData;

  // Populate form fields
  if (profileData) {
    document.getElementById("editFirstname").value =
      profileData.firstname || "";
    document.getElementById("editLastname").value = profileData.lastname || "";
    document.getElementById("editPhone").value = profileData.phone || "";
    document.getElementById("editEmail").value = profileData.email || "";
    document.getElementById("editAddress").value = profileData.address || "";
    document.getElementById("editGender").value = profileData.gender || "";
    document.getElementById("editSecretQuestion").value =
      profileData.secret_question || "";

    // Store current photo
    if (profileData.has_photo && profileData.photo_url) {
      currentPhoto = profileData.photo_url;
      updatePhotoPreview(profileData.photo_url);
    } else {
      currentPhoto = null;
      resetPhotoPreview();
    }
  }

  // Clear sensitive fields
  document.getElementById("currentPassword").value = "";
  document.getElementById("editPassword").value = "";
  document.getElementById("editConfirmPassword").value = "";
  document.getElementById("editSecretAnswer").value = "";

  // Show modal
  document.getElementById("editProfileModal").style.display = "flex";
  document.body.style.overflow = "hidden";

  // Focus on first field
  setTimeout(() => {
    document.getElementById("currentPassword").focus();
  }, 300);
}

// Close edit modal
function closeEditModal() {
  document.getElementById("editProfileModal").style.display = "none";
  document.body.style.overflow = "auto";

  // Reset form
  document.getElementById("editProfileForm").reset();
  resetPhotoPreview();
  resetPasswordStrength();

  // Clear validation messages
  document.getElementById("passwordMatch").textContent = "";
  document.getElementById("passwordMatch").className = "form-feedback";
}

// Toggle password visibility
function togglePassword(fieldId) {
  const field = document.getElementById(fieldId);
  const toggleBtn = field.parentNode.querySelector(".toggle-password i");

  if (field.type === "password") {
    field.type = "text";
    toggleBtn.className = "fas fa-eye-slash";
  } else {
    field.type = "password";
    toggleBtn.className = "fas fa-eye";
  }
}

// Preview photo before upload
function previewPhoto(input) {
  const preview = document.getElementById("photoPreview");
  const file = input.files[0];

  if (file) {
    // Validate file size (max 2MB)
    if (file.size > 2 * 1024 * 1024) {
      showNotification("File size must be less than 2MB", "error");
      input.value = "";
      return;
    }

    // Validate file type
    const validTypes = ["image/jpeg", "image/png", "image/gif", "image/jpg"];
    if (!validTypes.includes(file.type)) {
      showNotification(
        "Please select a valid image file (JPG, PNG, GIF)",
        "error"
      );
      input.value = "";
      return;
    }

    const reader = new FileReader();

    reader.onload = function (e) {
      preview.innerHTML = `<img src="${e.target.result}" alt="Preview">`;
    };

    reader.readAsDataURL(file);
  }
}

// Remove photo
function removePhoto() {
  document.getElementById("editPhoto").value = "";
  resetPhotoPreview();
  currentPhoto = null;
}

// Update photo preview
function updatePhotoPreview(imageUrl) {
  const preview = document.getElementById("photoPreview");
  preview.innerHTML = `<img src="${imageUrl}" alt="Profile Photo">`;
}

// Reset photo preview
function resetPhotoPreview() {
  const preview = document.getElementById("photoPreview");
  preview.innerHTML = `
            <div class="photo-placeholder">
                <i class="fas fa-user"></i>
            </div>
        `;
}

// Check password strength
function checkPasswordStrength(password) {
  let strength = 0;
  const bar = document.querySelector(".strength-bar");
  const text = document.querySelector(".strength-text");

  if (password.length >= 8) strength += 25;
  if (/[a-z]/.test(password)) strength += 25;
  if (/[A-Z]/.test(password)) strength += 25;
  if (/[0-9!@#$%^&*(),.?":{}|<>_\-]/.test(password)) strength += 25;

  bar.style.width = strength + "%";

  if(strength == 0){
    text.textContent = "";
  }
  else if (strength < 50) {
    bar.style.backgroundColor = "#dc3545";
    text.textContent = "Weak password";
    text.style.color = "#dc3545";
  } else if (strength < 75) {
    bar.style.backgroundColor = "#ffc107";
    text.textContent = "Medium strength";
    text.style.color = "#ffc107";
  } else {
    bar.style.backgroundColor = "#28a745";
    text.textContent = "Strong password";
    text.style.color = "#28a745";
  }
}

// Reset password strength indicator
function resetPasswordStrength() {
  const bar = document.querySelector(".strength-bar");
  const text = document.querySelector(".strength-text");
  bar.style.width = "0%";
  text.textContent = "";
}

// Check if passwords match
function checkPasswordMatch() {
  const password = document.getElementById("editPassword").value;
  const confirm = document.getElementById("editConfirmPassword").value;
  const matchDiv = document.getElementById("passwordMatch");

  if (!password || !confirm) {
    matchDiv.textContent = "";
    matchDiv.className = "form-feedback";
    return;
  }

  if (password === confirm) {
    matchDiv.textContent = "✓ Passwords match";
    matchDiv.className = "form-feedback success";
  } else {
    matchDiv.textContent = "✗ Passwords do not match";
    matchDiv.className = "form-feedback error";
  }
}

// Handle form submission - Updated for POST logout
document
  .getElementById("editProfileForm")
  .addEventListener("submit", async function (e) {
    e.preventDefault();

    const submitBtn = document.getElementById("submitEditBtn");
    const originalText = submitBtn.innerHTML;

    try {
      // Validate passwords match
      const newPassword = document.getElementById("editPassword").value;
      const confirmPassword = document.getElementById(
        "editConfirmPassword"
      ).value;

      if (newPassword && newPassword !== confirmPassword) {
        throw new Error("New passwords do not match");
      }

      // Check password strength if new password is provided
      if (newPassword) {
        const passwordRegex =
          /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/;
        if (!passwordRegex.test(newPassword)) {
          throw new Error(
            "Password must be at least 8 characters with uppercase, lowercase, number, and special character"
          );
        }
      }

      // Prepare form data
      const formData = new FormData(this);

      // Show confirmation dialog
      UI.confirm("Are you sure you want to update your profile?", async () => {
        try {
          // Disable submit button
          submitBtn.disabled = true;
          submitBtn.innerHTML =
            '<i class="fas fa-spinner fa-spin"></i> Saving...';

          // Send update request
          const response = await fetch("../backend/staffs/update_profile.php", {
            method: "POST",
            body: formData,
            credentials: "include",
          });

          const data = await response.json();

          if (data.success) {
            UI.toast("Profile updated successfully!", "success");

            // Check if logout is required
            if (data.should_logout) {
              // Auto-logout without confirmation
              UI.toast(data.logout_reason + " Logging out...", "warning");

              // Get logout_id from response
              const logoutId = data.logout_id || "";

              // Call logout endpoint
              fetch("../backend/authentication/logout.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ logout_id: logoutId }),
                credentials: "include",
              })
                .then(() => {
                  // Redirect to login page after successful logout call
                  setTimeout(() => {
                    window.location.href = "../pages/index.php";
                  }, 1000);
                })
                .catch(() => {
                  // Even if API fails, redirect to login
                  setTimeout(() => {
                    window.location.href = "../../login.php";
                  }, 1000);
                });
            } else {
              // No logout required - close modal and refresh profile
              closeEditModal();
              setTimeout(() => {
                fetchProfile();
              }, 1000);
            }
          } else {
            throw new Error(data.message || "Failed to update profile");
          }
        } catch (error) {
          console.error("Edit profile error:", error);
          UI.toast(error.message, "danger");

          // Re-enable submit button on error
          submitBtn.disabled = false;
          submitBtn.innerHTML = originalText;
        }
      });
    } catch (error) {
      console.error("Edit profile error:", error);
      UI.toast(error.message, "danger");
    }
  });
// Event listeners for real-time validation
document.getElementById("editPassword").addEventListener("input", function () {
  checkPasswordStrength(this.value);
  checkPasswordMatch();
});

document
  .getElementById("editConfirmPassword")
  .addEventListener("input", checkPasswordMatch);

// Close modal when clicking outside
document
  .getElementById("editProfileModal")
  .addEventListener("click", function (e) {
    if (e.target === this) {
      closeEditModal();
    }
  });

// Close modal with Escape key
document.addEventListener("keydown", function (e) {
  if (
    e.key === "Escape" &&
    document.getElementById("editProfileModal").style.display === "flex"
  ) {
    closeEditModal();
  }
});

// Notification function
// function showNotification(message, type = 'info') {
//     // You can use your existing UI.toast function or create a simple alert
//     if (typeof UI !== 'undefined' && typeof UI.toast === 'function') {
//         UI.toast(message, type);
//     } else {
//         alert(message);
//     }
// }
