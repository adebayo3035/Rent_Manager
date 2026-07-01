const errorContainer = document.querySelector(".errorContainer");

// Password visibility toggle
const togglePasswordVisibility = () => {
  const passwordInput = document.getElementById("password");
  const showPasswordCheckbox = document.getElementById("viewPassword");
  passwordInput.type = showPasswordCheckbox.checked ? "text" : "password";
};

// Display error message
const displayError = (message) => {
  if (errorContainer) {
    errorContainer.style.display = "block";
    errorContainer.textContent = message;
    errorContainer.style.color = "red";
    errorContainer.style.backgroundColor = "#ffe6e6";
    errorContainer.style.padding = "10px";
    errorContainer.style.borderRadius = "4px";
    errorContainer.style.margin = "10px 0";
    errorContainer.style.border = "1px solid #ffcccc";
  }
};

// Clear error message
const clearError = () => {
  if (errorContainer) {
    errorContainer.style.display = "none";
    errorContainer.textContent = "";
  }
};

// Reusable modal toggle function
const toggleModal = (modalId, display = "none") => {
  const modal = document.getElementById(modalId);
  if (modal) {
    modal.style.display = display;
    if (display === "flex") {
      document.body.style.overflow = "hidden";
    } else {
      document.body.style.overflow = "auto";
    }
  }
};

// Form disabling utilities
const disableForm = (form) => {
  Array.from(form.elements).forEach((element) => {
    element.disabled = true;
  });
  form.style.opacity = "0.6";
  form.style.pointerEvents = "none";
};

const enableForm = (form) => {
  Array.from(form.elements).forEach((element) => {
    element.disabled = false;
  });
  form.style.opacity = "1";
  form.style.pointerEvents = "auto";
};

const disableFormElements = (elements, buttonText = "") => {
  elements.forEach((element) => {
    if (!element) return;

    element.disabled = true;
    if (element.tagName === "BUTTON" && buttonText) {
      element.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ${buttonText}`;
    }
    element.classList.add("disabled");
  });
};

const enableFormElements = (elements, originalText = "") => {
  elements.forEach((element) => {
    if (!element) return;

    element.disabled = false;
    if (element.tagName === "BUTTON" && originalText) {
      element.innerHTML = originalText;
    }
    element.classList.remove("disabled");
  });
};

// Response message utilities
const clearResponseMessage = (element) => {
  if (element) {
    element.textContent = "";
    element.className = "";
  }
};

const displayResponseMessage = (element, message, type = "info") => {
  if (!element) return;

  element.textContent = message;
  element.className = "";

  switch (type) {
    case "success":
      element.classList.add("text-success");
      break;
    case "error":
      element.classList.add("text-danger");
      break;
    case "warning":
      element.classList.add("text-warning");
      break;
    case "info":
      element.classList.add("text-info");
      break;
  }
};

// Full page loader
const createFullPageLoader = (message = "Loading...") => {
  const loader = document.createElement("div");
  loader.className = "loader-overlay";
  loader.innerHTML = `
    <div class="loader-content">
      <div class="roller-loader"></div>
      <p>${message}</p>
    </div>
  `;
  return loader;
};

// Email validation
const validateEmail = (email) => {
  const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  return re.test(email);
};

// Tab switching functions
const switchToValidateTab = () => {
  const tabContents = document.getElementsByClassName("tab-content");
  const tabButtons = document.getElementsByClassName("tab-button");

  for (let i = 0; i < tabContents.length; i++) {
    tabContents[i].style.display = "none";
  }

  for (let i = 0; i < tabButtons.length; i++) {
    tabButtons[i].className = tabButtons[i].className.replace(" active", "");
  }

  const validateTab = document.getElementById("validateOTPTab");
  if (validateTab) {
    validateTab.style.display = "block";
    tabButtons[1].className += " active";

    const otpInput = document.getElementById("otpInput");
    if (otpInput) otpInput.focus();
  }
};

const openTab = (evt, tabName) => {
  const tabContents = document.getElementsByClassName("tab-content");
  const tabButtons = document.getElementsByClassName("tab-button");

  for (let i = 0; i < tabContents.length; i++) {
    tabContents[i].style.display = "none";
  }

  for (let i = 0; i < tabButtons.length; i++) {
    tabButtons[i].className = tabButtons[i].className.replace(" active", "");
  }

  const currentTab = document.getElementById(tabName);
  if (currentTab) {
    currentTab.style.display = "block";
    evt.currentTarget.className += " active";
  }
};

// OTP resend timer
let otpTimerInterval = null;

const startOTPResendTimer = (
  duration,
  button,
  inputElement,
  responseMessageContainer,
) => {
  if (otpTimerInterval) clearInterval(otpTimerInterval);

  let timer = duration;
  const originalButtonText = button.innerHTML;

  otpTimerInterval = setInterval(() => {
    const minutes = Math.floor(timer / 60);
    const seconds = timer % 60;

    button.innerHTML = `Resend OTP in ${minutes}:${
      seconds < 10 ? "0" : ""
    }${seconds}`;

    if (--timer < 0) {
      clearInterval(otpTimerInterval);
      otpTimerInterval = null;
      resetOTPButton(button, "Resend OTP");
      if (inputElement) inputElement.disabled = false;
      if (responseMessageContainer) responseMessageContainer.textContent = "";
    }
  }, 1000);
};

const resetOTPButton = (element, buttonText = "Send OTP") => {
  if (!element) return;

  element.disabled = false;
  if (element.tagName.toLowerCase() === "button") {
    element.innerHTML = buttonText;
    element.classList.remove("disabled");
  } else {
    element.value = "";
    element.classList.remove("disabled");
  }
};

// Reset account activation modal
const resetAccountActivationModal = () => {
  const otpGenerationForm = document.getElementById("otpGenerationForm");
  const accountActivationForm = document.getElementById(
    "accountActivationForm",
  );

  if (otpGenerationForm) otpGenerationForm.reset();
  if (accountActivationForm) accountActivationForm.reset();

  const responseElements = [
    "OTPResponse",
    "ReactivationResponse",
    "displayResponse",
  ];
  responseElements.forEach((id) => {
    const element = document.getElementById(id);
    if (element) {
      element.textContent = "";
      element.className = "";
    }
  });

  const sendOTPButton = document.getElementById("sendOTP");
  const sendOTPEmail = document.getElementById("emailActivateAccount");
  const reactivationButton = document.getElementById("reactivationButton");

  if (sendOTPButton) {
    sendOTPButton.disabled = false;
    sendOTPButton.innerHTML = "Generate & Send OTP";
    sendOTPButton.classList.remove("disabled");
  }

  if (sendOTPEmail) {
    sendOTPEmail.disabled = false;
    sendOTPEmail.classList.remove("disabled");
  }

  if (reactivationButton) {
    reactivationButton.disabled = false;
    reactivationButton.innerHTML = "Submit Request";
    reactivationButton.classList.remove("disabled");
  }

  const tabButtons = document.querySelectorAll(".tab-button");
  const tabContents = document.querySelectorAll(".tab-content");

  tabButtons.forEach((button, index) => {
    button.classList.remove("active");
    if (index === 0) button.classList.add("active");
  });

  tabContents.forEach((content, index) => {
    content.style.display = index === 0 ? "block" : "none";
  });

  const validateEmailInput = document.getElementById("validateEmail");
  if (validateEmailInput) validateEmailInput.value = "";

  if (otpTimerInterval) {
    clearInterval(otpTimerInterval);
    otpTimerInterval = null;
  }
};

// Handle OTP generation success
const handleSuccessResponse = (
  result,
  email,
  button,
  emailInput,
  messageElement,
) => {
  if (result.email_sent === false && result.otp_generated === true) {
    displayResponseMessage(
      messageElement,
      "We generated an OTP but couldn't send it via email. Please try again or contact support.",
      "warning",
    );
    resetOTPButton(button);
    resetOTPButton(emailInput);
    return;
  }

  displayResponseMessage(
    messageElement,
    result.message || "OTP sent successfully",
    "success",
  );

  setTimeout(() => {
    switchToValidateTab();
    const validateEmailInput = document.getElementById("validateEmail");
    if (validateEmailInput) validateEmailInput.value = email;
  }, 2000);

  startOTPResendTimer(120, button, emailInput, messageElement);
};

// Handle OTP generation error
const handleErrorResponse = (result, button, emailInput, messageElement) => {
  let errorMsg = result.message || "OTP Generation Failed.";
  let type = "error";

  if (result.otp_generated === true && result.email_sent === false) {
    type = "warning";
  } else if (result.code === 429) {
    type = "warning";
  }

  displayResponseMessage(messageElement, errorMsg, type);
  resetOTPButton(button);
  resetOTPButton(emailInput);
};

// Handle fetch errors
const handleFetchError = (error, button, emailInput, messageElement) => {
  const errorMsg =
    error.name === "AbortError"
      ? "Service timeout. Please try again."
      : "An error occurred. Please try again.";

  displayResponseMessage(messageElement, errorMsg, "error");
  resetOTPButton(button);
  resetOTPButton(emailInput);

  if (error.name !== "AbortError") {
    console.error("Fetch Error:", error);
  }
};

// Handle reactivation success
const handleReactivationSuccess = (result, messageElement, button) => {
  let message =
    result.message || "Reactivation request submitted successfully!";

  if (result.request_id) {
    message += `<br><small>Request ID: ${result.request_id}</small>`;
  }
  if (result.review_time) {
    message += `<br><small>Estimated review time: ${result.review_time}</small>`;
  }

  messageElement.innerHTML = message;
  messageElement.className = "alert alert-success mt-3";

  disableFormElements([button], "Submitted");

  setTimeout(() => {
    const modal = document.getElementById("accountActivationModal");
    if (modal) modal.style.display = "none";
    resetAccountActivationModal();

    UI.alert(
      "Reactivation request submitted successfully! Our team will review it shortly.",
      "success",
    );
  }, 3000);
};

// Handle reactivation error
const handleReactivationError = (result, messageElement, button) => {
  let errorClass = "alert-danger";
  let icon = "❌";
  let message = result.message || "Reactivation request failed.";

  if (result.code === 429) {
    errorClass = "alert-warning";
    icon = "⚠️";
  } else if (result.code === 400 && result.existing_request_id) {
    errorClass = "alert-info";
    icon = "ℹ️";
    message += `<br><a href="reactivation_status.php?request_id=${result.existing_request_id}" class="alert-link">View existing request status</a>`;
  }

  messageElement.innerHTML = `${icon} ${message}`;
  messageElement.className = `alert ${errorClass} mt-3`;

  enableFormElements([button], "Submit Request");
};

// Main DOMContentLoaded event handler
document.addEventListener("DOMContentLoaded", () => {
  // Initialize modals
  const resetPasswordBtn = document.getElementById("resetPasswordBtn");
  const getSecretQuestionBtn = document.getElementById("getSecretQuestionBtn");
  const reactivateAccountBtn = document.getElementById("reactivateAccountBtn");
  const modals = [
    "passwordResetModal",
    "getSecretQuestionModal",
    "accountActivationModal",
  ];

  modals.forEach((modalId) => {
    const modal = document.getElementById(modalId);
    if (modal) modal.style.display = "none";
  });

  // Parse URL parameters
  const urlParams = new URLSearchParams(window.location.search);
  const action = urlParams.get("action");

  if (action === "reset-password") {
    toggleModal("passwordResetModal", "flex");
  }

  // Event handlers for modal buttons
  if (resetPasswordBtn) {
    resetPasswordBtn.addEventListener("click", (e) => {
      e.preventDefault();
      toggleModal("passwordResetModal", "flex");
    });
  }

  if (getSecretQuestionBtn) {
    getSecretQuestionBtn.addEventListener("click", (e) => {
      e.preventDefault();
      toggleModal("getSecretQuestionModal", "flex");
    });
  }

  if (reactivateAccountBtn) {
    reactivateAccountBtn.addEventListener("click", (e) => {
      e.preventDefault();
      toggleModal("accountActivationModal", "flex");
    });
  }

  // Close modals
  const closeModalBtns = document.querySelectorAll(".close");
  closeModalBtns.forEach((closeBtn) => {
    closeBtn.addEventListener("click", () => {
      const modal = closeBtn.closest(".modal");
      if (modal) {
        modal.style.display = "none";
        document.body.style.overflow = "auto";

        if (modal.id === "accountActivationModal") {
          resetAccountActivationModal();
        }
      }
    });
  });

  // Close modal when clicking outside
  window.addEventListener("click", (e) => {
    modals.forEach((modalId) => {
      const modal = document.getElementById(modalId);
      if (e.target === modal) {
        modal.style.display = "none";
        document.body.style.overflow = "auto";

        if (modalId === "accountActivationModal") {
          resetAccountActivationModal();
        }
      }
    });
  });

  // Reset Password Form
  const resetPasswordForm = document.getElementById("resetPasswordForm");
  if (resetPasswordForm) {
    resetPasswordForm.addEventListener("submit", async (e) => {
      e.preventDefault();

      const resetEmail = document.getElementById("resetEmail").value.trim();
      const resetSecretAnswer = document
        .getElementById("resetSecretAnswer")
        .value.trim();
      const newPassword = document.getElementById("newPassword").value;
      const confirmPassword = document.getElementById("confirmPassword").value;

      if (
        !resetEmail ||
        !resetSecretAnswer ||
        !newPassword ||
        !confirmPassword
      ) {
        UI.toast("All fields are required.", "danger");
        return;
      }

      if (newPassword !== confirmPassword) {
        UI.toast("Passwords do not match.", "danger");
        return;
      }

      UI.confirm("Are you sure you want to reset your password?", async () => {
        try {
          const response = await fetch(
            "../backend/authentication/reset_password.php",
            {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              credentials: "include",
              body: JSON.stringify({
                email: resetEmail,
                password: newPassword,
                secret_answer: resetSecretAnswer,
                confirmPassword: confirmPassword,
              }),
            },
          );

          const data = await response.json();

          if (data.success) {
            UI.toast("Password reset successful!", "success");
            toggleModal("passwordResetModal");
            resetPasswordForm.reset();
          } else {
            UI.toast(data.message || "Password reset failed.", "danger");
          }
        } catch (error) {
          console.error("Reset Password Error:", error);
          UI.toast("An unexpected error occurred. Please try again.", "danger");
        }
      });
    });
  }

  // Get Secret Question Form
  const getSecretQuestionForm = document.getElementById(
    "getSecretQuestionForm",
  );
  if (getSecretQuestionForm) {
    getSecretQuestionForm.addEventListener("submit", async (e) => {
      e.preventDefault();

      const identifier = document
        .getElementById("emailSecretQuestion")
        .value.trim();
      const password = document.getElementById("passwordSecretQuestion").value;

      if (!identifier || !password) {
        UI.toast("Email/Phone and password are required.", "danger");
        return;
      }

      // Show loading state on button
      const submitBtn = getSecretQuestionForm.querySelector(".button");
      const originalText = submitBtn.value;
      submitBtn.value = "Processing...";
      submitBtn.disabled = true;

      UI.confirm("Proceed to retrieve your secret question?", async () => {
        try {
          const response = await fetch(
            "../backend/authentication/get_secret_question.php",
            {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              credentials: "include",
              body: JSON.stringify({
                identifier: identifier, // Changed from 'email' to 'identifier'
                password: password,
              }),
            },
          );

          const data = await response.json();

          if (data.success) {
            UI.toast("Secret question retrieved successfully.", "success");
            getSecretQuestionForm.reset();

            const displayElement = document.getElementById("displayQuestion");
            if (displayElement) {
              const secretQuestion = data.secret_question;
              let timeLeft = 15; // Increased to 15 seconds for better readability

              displayElement.innerHTML = `
              <div style="color: #155724; font-weight: 500; padding: 15px; 
                        background-color: #d4edda; border-left: 4px solid #28a745; 
                        border-radius: 4px; margin-top: 15px;">
                <strong>Your Security Question:</strong><br>
                "${escapeHtml(secretQuestion)}"
                <div id="countdownTimer" style="margin-top: 8px; font-size: 0.75em; color: #856404;">
                  This message will clear in ${timeLeft} seconds for security purposes.
                </div>
              </div>
            `;

              const countdown = setInterval(() => {
                timeLeft--;
                const timerElement = document.getElementById("countdownTimer");
                if (timerElement) {
                  timerElement.textContent = `This message will clear in ${timeLeft} second${timeLeft !== 1 ? "s" : ""} for security purposes.`;
                }

                if (timeLeft <= 0) {
                  clearInterval(countdown);
                  displayElement.innerHTML = "";
                  UI.toast("Secret question cleared for security.", "info");
                }
              }, 1000);
            }
          } else {
            // Handle specific error messages
            let errorMessage =
              data.message || "Failed to fetch secret question.";

            // Check for account lock message
            if (data.message && data.message.includes("locked")) {
              errorMessage = data.message;
            }

            UI.toast(errorMessage, "danger");

            // If account is locked, disable the form temporarily
            if (data.message && data.message.includes("locked")) {
              getSecretQuestionForm
                .querySelectorAll("input, .button")
                .forEach((el) => {
                  el.disabled = true;
                });

              // Re-enable after lockout period (assuming 15 minutes)
              setTimeout(
                () => {
                  getSecretQuestionForm
                    .querySelectorAll("input, .button")
                    .forEach((el) => {
                      el.disabled = false;
                    });
                  UI.toast("You can now try again.", "info");
                },
                15 * 60 * 1000,
              );
            }
          }
        } catch (error) {
          console.error("Secret Question Error:", error);
          UI.toast("An unexpected error occurred. Please try again.", "danger");
        } finally {
          // Reset button state
          submitBtn.value = originalText;
          submitBtn.disabled = false;
        }
      });
    });
  }

  // Helper function to escape HTML
  function escapeHtml(text) {
    if (!text) return "";
    const div = document.createElement("div");
    div.textContent = text;
    return div.innerHTML;
  }
  // Login Form
  const loginForm = document.getElementById("loginForm");
  if (loginForm) {
    loginForm.addEventListener("submit", handleLogin);
  }

  // Initialize force password form
  const forcePasswordForm = document.getElementById("forcePasswordForm");
  if (forcePasswordForm) {
    document
      .getElementById("forceNewPassword")
      ?.addEventListener("input", validateForcePassword);
    document
      .getElementById("forceConfirmPassword")
      ?.addEventListener("input", validateForcePasswordMatch);
  }

  let tempUserId = null;
  let tempAuthToken = null;

  async function handleLogin(e) {
    e.preventDefault();

    const loginForm = e.target;
    const username =
      loginForm.querySelector('input[name="username"]')?.value?.trim() || "";
    const password =
      loginForm.querySelector('input[name="password"]')?.value || "";

    if (!username || !password) {
      displayError("Please enter both username and password.");
      return;
    }

    clearError();

    const submitBtn = loginForm.querySelector('input[type="submit"]');
    const originalText = submitBtn?.value || submitBtn?.innerHTML || "Login";
    if (submitBtn) {
      submitBtn.innerHTML =
        '<i class="fas fa-spinner fa-spin"></i> Logging in...';
      submitBtn.disabled = true;
    }

    try {
      console.log("Attempting login...");
      const response = await fetch("../backend/authentication/login.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ username, password }),
      });

      const data = await response.json();
      console.log("Login response:", data);

      if (response.status === 200 && data.success) {
        console.log(
          "Login successful, checking for password change requirement...",
        );

        // Check if password change is required
        if (
          data.data &&
          (data.data.requires_action === true ||
            data.data.needs_password_change === true)
        ) {
          console.log("Password change required, showing modal...");
          tempUserId = data.data.user_id;
          tempAuthToken = data.data.temp_token;

          // Show the force password modal
          showForcePasswordModal(data.data);
          return;
        }

        // Normal login flow - redirect to dashboard
        console.log("Normal login, redirecting...");
        UI.toast("Login successful! Redirecting...", "success");
        setTimeout(() => {
          window.location.href = "splashscreen.php";
        }, 1500);
      } else {
        // Handle errors
        handleLoginError(response, data);
        if (submitBtn) {
          submitBtn.innerHTML = originalText;
          submitBtn.disabled = false;
        }
      }
    } catch (error) {
      console.error("Login Error:", error);
      displayError(
        "Network error. Please check your connection and try again.",
      );
      if (submitBtn) {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
      }
    }
  }

  function showForcePasswordModal(userData) {
    console.log("Showing force password modal for user:", userData);

    // Store temp user data
    tempUserId = userData.user_id;
    tempAuthToken = userData.temp_token;

    // Show the modal
    const modal = document.getElementById("forcePasswordModal");
    if (modal) {
      console.log("Modal found, displaying...");
      modal.style.display = "flex";
      modal.classList.add("active");
      document.body.style.overflow = "hidden";
    } else {
      console.error("Modal not found in DOM!");
      alert("Security update required. Please contact support.");
      return;
    }

    // Disable login form
    const loginForm = document.getElementById("loginForm");
    if (loginForm) {
      loginForm
        .querySelectorAll("input")
        .forEach((input) => (input.disabled = true));
      const submitBtn = loginForm.querySelector('input[type="submit"]');
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.style.cursor = "not-allowed";
        submitBtn.style.opacity = "0.6";
      }
    }

    // Pre-fill name if available
    if (userData.firstname) {
      const welcomeMsg = document.querySelector(
        "#forcePasswordModal .modal-header h3",
      );
      if (welcomeMsg) {
        welcomeMsg.textContent = `Update Your Security Details, ${userData.firstname}`;
      }
    }
  }

  function closeForcePasswordModal() {
    const modal = document.getElementById("forcePasswordModal");
    if (modal) {
      modal.style.display = "none";
      modal.classList.remove("active");
      document.body.style.overflow = "";
    }

    // Re-enable login form
    const loginForm = document.getElementById("loginForm");
    if (loginForm) {
      loginForm
        .querySelectorAll("input")
        .forEach((input) => (input.disabled = false));
      const submitBtn = loginForm.querySelector('input[type="submit"]');
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.style.cursor = "pointer";
        submitBtn.style.opacity = "1";
      }
    }
  }

  async function submitForcePasswordChange() {
    const newPassword =
      document.getElementById("forceNewPassword")?.value || "";
    const confirmPassword =
      document.getElementById("forceConfirmPassword")?.value || "";
    const secretQuestion =
      document.getElementById("newSecretQuestion")?.value || "";
    const secretAnswer = document.getElementById("newAnswer")?.value || "";
    const confirmSecretAnswer =
      document.getElementById("confirmNewAnswer")?.value || "";

    // Validate
    if (
      !newPassword ||
      !confirmPassword ||
      !secretQuestion ||
      !secretAnswer ||
      !confirmSecretAnswer
    ) {
      showToast("Please fill in all fields", "error");
      return;
    }

    if (newPassword !== confirmPassword) {
      showToast("Passwords do not match", "error");
      return;
    }

    if (secretAnswer !== confirmSecretAnswer) {
      showToast("Secret answers do not match", "error");
      return;
    }

    if (newPassword.length < 8) {
      showToast("Password must be at least 8 characters", "error");
      return;
    }

    if (!/[A-Z]/.test(newPassword)) {
      showToast("Password must contain at least one uppercase letter", "error");
      return;
    }

    if (!/[a-z]/.test(newPassword)) {
      showToast("Password must contain at least one lowercase letter", "error");
      return;
    }

    if (!/[0-9]/.test(newPassword)) {
      showToast("Password must contain at least one number", "error");
      return;
    }

    if (secretAnswer.length < 8) {
      showToast("Secret answer must be at least 8 characters", "error");
      return;
    }

    const submitBtn = document.querySelector(
      "#forcePasswordModal .btn-primary",
    );
    const originalText = submitBtn?.innerHTML || "Update";
    if (submitBtn) {
      submitBtn.innerHTML =
        '<i class="fas fa-spinner fa-spin"></i> Updating...';
      submitBtn.disabled = true;
    }

    try {
      console.log("Submitting password change for user:", tempUserId);

      const response = await fetch(
        "../backend/authentication/change_default_password.php",
        {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            user_id: tempUserId,
            temp_token: tempAuthToken,
            new_password: newPassword,
            confirm_password: confirmPassword,
            secret_question: secretQuestion,
            secret_answer: secretAnswer,
            confirm_answer: confirmSecretAnswer,
          }),
        },
      );

      const data = await response.json();
      console.log("Password change response:", data);

      if (data.success) {
        showToast(
          "Password and security details updated successfully! Please log in again.",
          "success",
        );

        // Close modal
        closeForcePasswordModal();

        // Reset form
        const form = document.getElementById("forcePasswordForm");
        if (form) form.reset();

        // Logout and redirect to login
        setTimeout(() => {
          performLogout();
        }, 2000);
      } else {
        throw new Error(data.message || "Failed to update security details");
      }
    } catch (error) {
      console.error("Error updating password:", error);
      showToast(error.message, "error");
      if (submitBtn) {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
      }
    }
  }

  async function performLogout() {
    try {
      const response = await fetch("../backend/authentication/logout.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ user_id: tempUserId }),
      });

      await response.json();

      // Clear temp data
      tempUserId = null;
      tempAuthToken = null;

      // Redirect to login page with message
      window.location.href = "index.php?message=password_updated";
    } catch (error) {
      console.error("Logout error:", error);
      // Force redirect anyway
      window.location.href = "index.php?message=password_updated";
    }
  }

  function validateForcePassword() {
    const password = document.getElementById("forceNewPassword")?.value || "";
    const requirements = [
      {
        id: "req-length",
        check: password.length >= 8,
        text: "At least 8 characters",
      },
      {
        id: "req-upper",
        check: /[A-Z]/.test(password),
        text: "At least one uppercase letter",
      },
      {
        id: "req-lower",
        check: /[a-z]/.test(password),
        text: "At least one lowercase letter",
      },
      {
        id: "req-number",
        check: /[0-9]/.test(password),
        text: "At least one number",
      },
    ];

    requirements.forEach((req) => {
      const element = document.getElementById(req.id);
      if (element) {
        if (req.check) {
          element.classList.add("valid");
          element.textContent = "✓ " + req.text;
        } else {
          element.classList.remove("valid");
          element.textContent = "✗ " + req.text;
        }
      }
    });

    // Update strength bar
    const strengthBar = document.getElementById("strengthBar");
    const strengthText = document.getElementById("strengthText");
    const score = requirements.filter((r) => r.check).length;

    if (strengthBar) {
      const percentage = (score / requirements.length) * 100;
      strengthBar.style.width = percentage + "%";

      if (percentage <= 25) {
        strengthBar.style.background = "#dc2626";
        if (strengthText) strengthText.textContent = "Password strength: Weak";
      } else if (percentage <= 50) {
        strengthBar.style.background = "#f59e0b";
        if (strengthText) strengthText.textContent = "Password strength: Fair";
      } else if (percentage <= 75) {
        strengthBar.style.background = "#3b82f6";
        if (strengthText) strengthText.textContent = "Password strength: Good";
      } else {
        strengthBar.style.background = "#10b981";
        if (strengthText)
          strengthText.textContent = "Password strength: Strong";
      }
    }

    validateForcePasswordMatch();
  }

  function validateForcePasswordMatch() {
    const password = document.getElementById("forceNewPassword")?.value || "";
    const confirm =
      document.getElementById("forceConfirmPassword")?.value || "";
    const matchElement = document.getElementById("req-match");

    if (!matchElement) return;

    if (password && confirm && password === confirm) {
      matchElement.classList.add("valid");
      matchElement.textContent = "✓ Passwords match";
    } else {
      matchElement.classList.remove("valid");
      matchElement.textContent = "✗ Passwords match";
    }
  }

  function displayError(message) {
    const errorDiv = document.getElementById("loginError");
    if (errorDiv) {
      errorDiv.textContent = message;
      errorDiv.style.display = "block";
    }
  }

  function clearError() {
    const errorDiv = document.getElementById("loginError");
    if (errorDiv) {
      errorDiv.textContent = "";
      errorDiv.style.display = "none";
    }
  }

  function handleLoginError(response, data) {
    let errorMessage = data.message || "Login failed. Please try again.";

    if (response.status === 423) {
      errorMessage = data.message || "Account locked. Please try again later.";
    } else if (response.status === 403) {
      errorMessage = data.message || "Account access restricted.";
    } else if (response.status === 401) {
      errorMessage = data.message || "Invalid username or password.";
    } else if (response.status === 500) {
      errorMessage = "Server error. Please try again later.";
      console.error("Server Error:", data);
    }

    displayError(errorMessage);
  }

  function showToast(message, type = "info") {
    // Use existing toast function if available
    if (typeof UI !== "undefined" && UI.toast) {
      UI.toast(message, type);
      return;
    }

    // Fallback toast
    const toast = document.createElement("div");
    toast.className = `toast-notification ${type}`;
    toast.innerHTML = `<span>${message}</span>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
  }

  // Make functions globally accessible
  window.showForcePasswordModal = showForcePasswordModal;
  window.closeForcePasswordModal = closeForcePasswordModal;
  window.submitForcePasswordChange = submitForcePasswordChange;
  window.validateForcePassword = validateForcePassword;
  window.validateForcePasswordMatch = validateForcePasswordMatch;

  // Helper functions
  function displayError(message) {
    // Your existing error display logic
    const errorElement = document.getElementById("loginError");
    if (errorElement) {
      errorElement.textContent = message;
      errorElement.style.display = "block";
    } else {
      UI.toast(message, "danger");
    }
  }

  function clearError() {
    const errorElement = document.getElementById("loginError");
    if (errorElement) {
      errorElement.textContent = "";
      errorElement.style.display = "none";
    }
  }

  function disableForm(form) {
    const inputs = form.querySelectorAll("input, button, select, textarea");
    inputs.forEach((input) => {
      input.disabled = true;
      input.style.cursor = "not-allowed";
      input.style.opacity = "0.6";
    });
  }
  // OTP Generation Form
  const otpGenerationForm = document.getElementById("otpGenerationForm");
  if (otpGenerationForm) {
    otpGenerationForm.addEventListener("submit", async function (event) {
      event.preventDefault();

      const email = document.getElementById("emailActivateAccount").value;
      const user_type = "tenant";
      const title = "Account Reactivation OTP";
      const sendOTPButton = document.getElementById("sendOTP");
      const sendOTPEmail = document.getElementById("emailActivateAccount");
      const responseMessage = document.getElementById("OTPResponse");

      if (!email || !validateEmail(email)) {
        UI.toast("Please enter a valid email address", "error");
        return;
      }

      const loaderOverlay = createFullPageLoader("Sending OTP...");
      document.body.appendChild(loaderOverlay);

      const requestData = { email, user_type, title };

      disableFormElements([sendOTPButton, sendOTPEmail], "Sending...");
      clearResponseMessage(responseMessage);

      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), 120000);

      try {
        const response = await fetch("../backend/utilities/send_otp.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(requestData),
          signal: controller.signal,
        });

        clearTimeout(timeoutId);
        const result = await response.json();

        if (result.success) {
          handleSuccessResponse(
            result,
            email,
            sendOTPButton,
            sendOTPEmail,
            responseMessage,
          );
        } else {
          handleErrorResponse(
            result,
            sendOTPButton,
            sendOTPEmail,
            responseMessage,
          );
        }
      } catch (error) {
        clearTimeout(timeoutId);
        handleFetchError(error, sendOTPButton, sendOTPEmail, responseMessage);
      } finally {
        loaderOverlay.remove();
      }
    });
  }

  // Account Activation Form
  const accountActivationForm = document.getElementById(
    "accountActivationForm",
  );
  if (accountActivationForm) {
    accountActivationForm.addEventListener("submit", async function (event) {
      event.preventDefault();

      const email = document.getElementById("validateEmail").value;
      const otp = document.getElementById("otpInput").value;
      const requestReason = document.getElementById("reactivationReason").value;
      const user_type = "tenant";

      const submitButton = document.getElementById("reactivationButton");
      const responseMessage = document.getElementById("ReactivationResponse");

      if (!email || !validateEmail(email)) {
        UI.toast("Please enter a valid email", "error");
        return;
      }

      if (!otp || otp.length !== 6) {
        UI.toast("Please enter a valid 6-digit OTP", "error");
        return;
      }

      if (!requestReason || requestReason.trim().length < 10) {
        UI.toast(
          "Please provide a detailed reason for reactivation (minimum 10 characters)",
          "error",
        );
        return;
      }

      const loaderOverlay = createFullPageLoader(
        "Submitting reactivation request...",
      );
      document.body.appendChild(loaderOverlay);

      disableFormElements([submitButton], "Submitting...");
      clearResponseMessage(responseMessage);

      const requestData = {
        email: email,
        user_type: user_type,
        otp: otp,
        request_reason: requestReason,
      };

      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), 30000);

      try {
        const response = await fetch(
          "../backend/utilities/submit_reactivation_request.php",
          {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(requestData),
            signal: controller.signal,
          },
        );

        clearTimeout(timeoutId);

        // Try to get the response text regardless of HTTP status
        const responseText = await response.text();

        // Try to parse as JSON
        let result;
        try {
          result = JSON.parse(responseText);
        } catch (jsonError) {
          // If it's not valid JSON, handle it as an error
          throw new Error(
            `Invalid server response: ${responseText.substring(0, 100)}...`,
          );
        }

        // Now check HTTP status
        if (!response.ok) {
          // If we have a result object with a message, use it
          if (result && result.message) {
            throw new Error(
              `Server Error (${response.status}): ${result.message}`,
            );
          } else {
            throw new Error(
              `Server Error (${response.status}): ${
                response.statusText || "Unknown error"
              }`,
            );
          }
        }

        // Check if result has success property
        if (result.success) {
          handleReactivationSuccess(result, responseMessage, submitButton);
        } else {
          handleReactivationError(result, responseMessage, submitButton);
        }
      } catch (error) {
        clearTimeout(timeoutId);

        // Extract error message
        let errorMsg;

        if (error.name === "AbortError") {
          errorMsg = "Request timeout. Please try again.";
        } else if (
          error.message.includes("NetworkError") ||
          error.message.includes("Failed to fetch")
        ) {
          errorMsg = "Network error. Please check your connection.";
        } else if (error.message.includes("Invalid server response")) {
          errorMsg = "Invalid response from server. Please try again.";
        } else {
          // Display the exact error message from the server
          errorMsg = error.message;

          // For HTTP errors, you might want to extract just the message part
          const match = error.message.match(/Server Error \(\d+\): (.+)/);
          if (match && match[1]) {
            errorMsg = match[1];
          }
        }

        displayResponseMessage(responseMessage, errorMsg, "error");
        enableFormElements([submitButton], "Submit Request");

        console.error("Reactivation Error Details:", {
          name: error.name,
          message: error.message,
          stack: error.stack,
        });
      } finally {
        loaderOverlay.remove();
      }
    });
  }

  // Initialize first tab as active
  const firstTabButton = document.querySelector(".tab-button");
  const firstTabContent = document.getElementById("generateOTPTab");

  if (firstTabButton && firstTabContent) {
    firstTabButton.classList.add("active");
    firstTabContent.style.display = "block";
  }
});
