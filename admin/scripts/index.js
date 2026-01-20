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
  responseMessageContainer
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
    "accountActivationForm"
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
  messageElement
) => {
  if (result.email_sent === false && result.otp_generated === true) {
    displayResponseMessage(
      messageElement,
      "We generated an OTP but couldn't send it via email. Please try again or contact support.",
      "warning"
    );
    resetOTPButton(button);
    resetOTPButton(emailInput);
    return;
  }

  displayResponseMessage(
    messageElement,
    result.message || "OTP sent successfully",
    "success"
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
      "success"
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
            }
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
    "getSecretQuestionForm"
  );
  if (getSecretQuestionForm) {
    getSecretQuestionForm.addEventListener("submit", async (e) => {
      e.preventDefault();

      const email = document.getElementById("emailSecretQuestion").value.trim();
      const password = document.getElementById("passwordSecretQuestion").value;

      if (!email || !password) {
        UI.toast("Email and password are required.", "danger");
        return;
      }

      UI.confirm("Proceed to retrieve your secret question?", async () => {
        try {
          const response = await fetch(
            "../backend/authentication/get_secret_question.php",
            {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              credentials: "include",
              body: JSON.stringify({ email, password }),
            }
          );

          const data = await response.json();

          if (data.success) {
            UI.toast("Secret question retrieved successfully.", "success");
            getSecretQuestionForm.reset();

            const displayElement = document.getElementById("displayQuestion");
            if (displayElement) {
              const secretQuestion = data.secret_question;
              let timeLeft = 10;

              displayElement.innerHTML = `
                <div style="color: green; font-weight: bold; padding: 10px; 
                          background-color: #f0f8ff; border: 1px solid #4CAF50; 
                          border-radius: 4px; margin-top: 5px;">
                  Your Secret Question is: "${secretQuestion}"
                </div>
                <div id="countdownTimer" style="margin-top: 4px; font-size: 0.8em; color: #ff9800;">
                  Clearing in ${timeLeft} seconds...
                </div>
              `;

              const countdown = setInterval(() => {
                timeLeft--;
                const timerElement = document.getElementById("countdownTimer");
                if (timerElement) {
                  timerElement.textContent = `Clearing in ${timeLeft} second${
                    timeLeft !== 1 ? "s" : ""
                  }...`;
                }

                if (timeLeft <= 0) {
                  clearInterval(countdown);
                  displayElement.innerHTML = "";
                  UI.toast("Secret question cleared for security.", "info");
                }
              }, 1000);
            }
          } else {
            UI.toast(
              data.message || "Failed to fetch secret question.",
              "danger"
            );
          }
        } catch (error) {
          console.error("Secret Question Error:", error);
          UI.toast("An unexpected error occurred. Please try again.", "danger");
        }
      });
    });
  }

  // Login Form
const loginForm = document.getElementById("loginForm");
if (loginForm) {
    loginForm.addEventListener("submit", async (e) => {
        e.preventDefault();

        const username = loginForm.querySelector('input[name="username"]').value.trim();
        const password = loginForm.querySelector('input[name="password"]').value;

        if (!username || !password) {
            displayError("Please enter both username and password.");
            return;
        }

        clearError();

        // Show loading state
        const submitBtn = loginForm.querySelector('input[type="submit"]');
        const originalBtnText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Logging in...';
        submitBtn.disabled = true;

        try {
            const response = await fetch("../backend/authentication/admin_login3.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ username, password }),
            });

            const data = await response.json();
            
            // Handle response based on status code
            if (response.status === 200 && data.success) {
                // Success - 200 OK with success=true
                disableForm(loginForm);

                // Disable other buttons
                const resetPasswordBtn = document.getElementById("resetPasswordBtn");
                const getSecretQuestionBtn = document.getElementById("getSecretQuestionBtn");
                if (resetPasswordBtn) {
                    resetPasswordBtn.style.cursor = "not-allowed";
                    resetPasswordBtn.style.opacity = "0.6";
                    resetPasswordBtn.disabled = true;
                }
                if (getSecretQuestionBtn) {
                    getSecretQuestionBtn.style.cursor = "not-allowed";
                    getSecretQuestionBtn.style.opacity = "0.6";
                    getSecretQuestionBtn.disabled = true;
                }

                UI.toast("Login successful! Redirecting...", "success");

                setTimeout(() => {
                    window.location.href = "splashscreen.php";
                }, 1500);
                
            } else {
                // Error - Check the error message
                let errorMessage = data.message || "Login failed. Please try again.";
                
                // Specific handling for locked accounts (423)
                if (response.status === 423) {
                    errorMessage = data.message;
                    // You might want to show a special UI for locked accounts
                    UI.toast(errorMessage, "warning", 10000); // Longer duration
                } 
                // Handling for blocked/deactivated (403)
                else if (response.status === 403) {
                    errorMessage = data.message;
                    UI.toast(errorMessage, "error", 10000);
                }
                // Handling for invalid credentials (401)
                else if (response.status === 401) {
                    errorMessage = data.message || "Invalid username or password.";
                    displayError(errorMessage);
                }
                // Handling for validation errors (400)
                else if (response.status === 400) {
                    errorMessage = data.message || "Please check your input.";
                    displayError(errorMessage);
                }
                // Server errors (500)
                else if (response.status === 500) {
                    errorMessage = "Server error. Please try again later.";
                    console.error("Server Error:", data);
                    UI.toast(errorMessage, "error");
                }
                // Other errors
                else {
                    displayError(errorMessage);
                }
                
                // Re-enable form on error
                submitBtn.innerHTML = originalBtnText;
                submitBtn.disabled = false;
            }
            
        } catch (error) {
            // Network errors or JSON parsing errors
            displayError("Network error. Please check your connection and try again.");
            console.error("Login Error:", error);
            
            // Re-enable form on error
            submitBtn.innerHTML = originalBtnText;
            submitBtn.disabled = false;
        }
    });
}

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
    inputs.forEach(input => {
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
      const user_type = "admin";
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
            responseMessage
          );
        } else {
          handleErrorResponse(
            result,
            sendOTPButton,
            sendOTPEmail,
            responseMessage
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
    "accountActivationForm"
  );
  if (accountActivationForm) {
    accountActivationForm.addEventListener("submit", async function (event) {
      event.preventDefault();

      const email = document.getElementById("validateEmail").value;
      const otp = document.getElementById("otpInput").value;
      const requestReason = document.getElementById("reactivationReason").value;
      const user_type = "admin";

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
          "error"
        );
        return;
      }

      const loaderOverlay = createFullPageLoader(
        "Submitting reactivation request..."
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
          }
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
            `Invalid server response: ${responseText.substring(0, 100)}...`
          );
        }

        // Now check HTTP status
        if (!response.ok) {
          // If we have a result object with a message, use it
          if (result && result.message) {
            throw new Error(
              `Server Error (${response.status}): ${result.message}`
            );
          } else {
            throw new Error(
              `Server Error (${response.status}): ${
                response.statusText || "Unknown error"
              }`
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
