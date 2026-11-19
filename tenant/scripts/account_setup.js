// tenant/scripts/account_setup.js
// First-time account setup flow.

(function () {
  "use strict";

  // ---------- State ----------
  const SetupState = {
    email: "",
    maskedEmail: "",
    setupToken: "",
    currentStep: 1,
    otpTimerHandle: null,
    otpSecondsLeft: 0,
  };

  // ---------- Element helpers ----------
  const $ = (id) => document.getElementById(id);
  const stepEls = () => document.querySelectorAll(".setup-step");

  // ---------- Alert ----------
  function showAlert(message, type = "error") {
    const el = $("setupAlert");
    if (!el) return;
    el.textContent = message;
    el.className = `setup-alert ${type}`;
    el.style.display = "block";
    // Auto-hide non-error alerts
    if (type !== "error") {
      setTimeout(() => {
        el.style.display = "none";
      }, 5000);
    }
    el.scrollIntoView({ behavior: "smooth", block: "nearest" });
  }

  function hideAlert() {
    const el = $("setupAlert");
    if (el) {
      el.style.display = "none";
      el.textContent = "";
    }
  }

  // ---------- Step navigation ----------
  function showStep(n) {
    SetupState.currentStep = n;
    stepEls().forEach((el) => el.classList.remove("active"));
    const target = $(`step${n}`);
    if (target) target.classList.add("active");

    // Update step indicator
    document.querySelectorAll(".step-indicator .step").forEach((el) => {
      const stepNum = parseInt(el.dataset.step, 10);
      el.classList.toggle("active", stepNum === n);
      el.classList.toggle("done", stepNum < n);
    });
    document.querySelectorAll(".step-line").forEach((el) => {
      const lineNum = parseInt(el.dataset.line, 10);
      el.classList.toggle("done", lineNum < n);
    });

    // Focus first input
    const firstInput = target?.querySelector("input:not([type=hidden])");
    if (firstInput) {
      setTimeout(() => firstInput.focus(), 150);
    }

    // Scroll card into view
    document.querySelector(".setup-card")?.scrollIntoView({
      behavior: "smooth",
      block: "start",
    });
  }

  // ---------- Button loading state ----------
  function setLoading(btnId, isLoading, loadingText) {
    const btn = $(btnId);
    if (!btn) return;
    const textEl = btn.querySelector(".btn-text");
    const spinEl = btn.querySelector(".btn-spinner");
    if (!textEl || !spinEl) return;

    if (isLoading) {
      textEl.hidden = true;
      spinEl.hidden = false;
      if (loadingText) {
        spinEl.innerHTML = `<i class="fas fa-circle-notch fa-spin"></i> ${loadingText}`;
      }
      btn.disabled = true;
    } else {
      textEl.hidden = false;
      spinEl.hidden = true;
      btn.disabled = false;
    }
  }

  // ---------- Password visibility toggle ----------
  window.togglePassword = function (inputId, btn) {
    const input = $(inputId);
    if (!input || !btn) return;
    const isPassword = input.type === "password";
    input.type = isPassword ? "text" : "password";
    btn.innerHTML = `<i class="fas fa-eye${isPassword ? "-slash" : ""}"></i>`;
    btn.setAttribute(
      "aria-label",
      isPassword ? "Hide password" : "Show password",
    );
  };

  // ---------- Password strength ----------
  function computeStrength(pw) {
    let score = 0;
    if (pw.length >= 8) score++;
    if (pw.length >= 12) score++;
    if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) score++;
    if (/\d/.test(pw)) score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;
    return Math.min(score, 5);
  }

  function updatePasswordStrength() {
    const pw = $("setupPassword")?.value || "";
    const container = $("pwStrength");
    if (!container) return;

    const score = computeStrength(pw);
    const bar = container.querySelector(".strength-bar span");
    const label = container.querySelector(".strength-label");
    const levels = ["", "Very weak", "Weak", "Fair", "Good", "Strong"];
    const classes = [
      "",
      "strength-1",
      "strength-2",
      "strength-3",
      "strength-4",
      "strength-5",
    ];

    container.classList.remove(
      "strength-1",
      "strength-2",
      "strength-3",
      "strength-4",
      "strength-5",
    );
    if (pw.length > 0) {
      container.classList.add(classes[score]);
      bar.style.width = `${(score / 5) * 100}%`;
      label.textContent = levels[score] || "";
    } else {
      bar.style.width = "0%";
      label.textContent = "";
    }
  }

  // ---------- OTP timer ----------
  function startOtpTimer(seconds = 120) {
    stopOtpTimer();
    SetupState.otpSecondsLeft = seconds;
    updateOtpTimerLabel();

    SetupState.otpTimerHandle = setInterval(() => {
      SetupState.otpSecondsLeft--;
      if (SetupState.otpSecondsLeft <= 0) {
        stopOtpTimer();
        const resend = $("resendBtn");
        if (resend) {
          resend.disabled = false;
          resend.textContent = "Resend code";
        }
        const timer = $("otpTimer");
        if (timer) timer.textContent = "";
        return;
      }
      updateOtpTimerLabel();
    }, 1000);

    const resend = $("resendBtn");
    if (resend) resend.disabled = true;
  }

  function updateOtpTimerLabel() {
    const timer = $("otpTimer");
    if (!timer) return;
    const m = Math.floor(SetupState.otpSecondsLeft / 60);
    const s = SetupState.otpSecondsLeft % 60;
    timer.textContent = `Code expires in ${m}:${String(s).padStart(2, "0")}`;
  }

  function stopOtpTimer() {
    if (SetupState.otpTimerHandle) {
      clearInterval(SetupState.otpTimerHandle);
      SetupState.otpTimerHandle = null;
    }
  }

  // ---------- API helper ----------
  async function apiCall(url, body = null) {
    const opts = {
      method: body ? "POST" : "GET",
      credentials: "include",
      headers: { Accept: "application/json" },
    };
    if (body) {
      opts.headers["Content-Type"] = "application/json";
      opts.body = JSON.stringify(body);
    }
    const res = await fetch(url, opts);
    let data;
    try {
      data = await res.json();
    } catch {
      throw new Error("Server returned an invalid response. Please try again.");
    }
    return { ok: res.ok, status: res.status, data };
  }

  // ---------- Step 1: Send OTP ----------
  window.handleSendOtp = async function (e) {
    e.preventDefault();
    hideAlert();

    const email = ($("setupEmail")?.value || "").trim().toLowerCase();
    if (!email) return showAlert("Please enter your email address");
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      return showAlert("Please enter a valid email address");
    }

    setLoading("sendOtpBtn", true, "Sending...");
    try {
      const { ok, data } = await apiCall(
        "../backend/authentication/send_setup_otp.php",
        { email },
      );

      if (!ok || !data.success) {
        // Special case: "not eligible" (already set up)
        if (data?.data?.requires_login) {
          showAlert(
            data.message || "Account already set up. Redirecting to login...",
            "info",
          );
          setTimeout(() => {
            window.location.href = "../pages/index.php";
          }, 2500);
          return;
        }
        throw new Error(data?.message || "Failed to send code");
      }

      SetupState.email = email;
      SetupState.maskedEmail = data.data?.email_masked || email;

      const maskedEl = $("maskedEmail");
      if (maskedEl) maskedEl.textContent = SetupState.maskedEmail;

      // Clear OTP field
      const otp = $("setupOtp");
      if (otp) otp.value = "";

      startOtpTimer(120);
      showStep(2);
      showAlert("Code sent. Check your inbox.", "success");
    } catch (err) {
      showAlert(err.message || "Could not send code. Please try again.");
    } finally {
      setLoading("sendOtpBtn", false);
    }
  };

  // ---------- Step 2: Verify OTP ----------
  window.handleVerifyOtp = async function (e) {
    e.preventDefault();
    hideAlert();

    const code = ($("setupOtp")?.value || "").trim();
    if (!/^\d{6}$/.test(code)) {
      return showAlert("Please enter the 6-digit code");
    }

    setLoading("verifyOtpBtn", true, "Verifying...");
    try {
      const { ok, data } = await apiCall(
        "../backend/authentication/verify_setup_otp.php",
        { email: SetupState.email, code },
      );

      if (!ok || !data.success) {
        throw new Error(data?.message || "Verification failed");
      }

      SetupState.setupToken = data.data?.setup_token || "";
      if (!SetupState.setupToken) {
        throw new Error("Missing setup token. Please restart.");
      }

      stopOtpTimer();
      showStep(3);
      showAlert("Verified! Now set your password.", "success");
    } catch (err) {
      showAlert(err.message || "Verification failed. Please try again.");
      // Clear the OTP so they can retry
      const otp = $("setupOtp");
      if (otp) {
        otp.value = "";
        otp.focus();
      }
    } finally {
      setLoading("verifyOtpBtn", false);
    }
  };

  // ---------- Resend OTP ----------
  window.handleResendOtp = async function () {
    hideAlert();
    if (!SetupState.email) {
      showAlert("Session expired. Please restart.");
      return goBackToEmail();
    }

    const resend = $("resendBtn");
    if (resend) resend.disabled = true;

    try {
      const { ok, data } = await apiCall(
        "../backend/authentication/send_setup_otp.php",
        { email: SetupState.email },
      );

      if (!ok || !data.success) {
        throw new Error(data?.message || "Could not resend code");
      }

      startOtpTimer(120);
      showAlert("New code sent. Check your inbox.", "success");
    } catch (err) {
      showAlert(err.message || "Could not resend code.");
      if (resend) resend.disabled = false;
    }
  };

  // ---------- Back to email ----------
  window.goBackToEmail = function () {
    stopOtpTimer();
    SetupState.email = "";
    SetupState.maskedEmail = "";
    SetupState.setupToken = "";
    hideAlert();
    showStep(1);
  };

  // ---------- Step 3: Complete setup ----------

  // ---------- Step 3: Complete setup ----------
  window.handleCompleteSetup = async function (e) {
    e.preventDefault();
    hideAlert();

    const password = $("setupPassword")?.value || "";
    const confirm = $("setupPasswordConfirm")?.value || "";

    const questionKey = ($("setupSecretQuestion")?.value || "").trim();

    // Preserve the original answer; do not trim before matching confirmation.
    const answer = $("setupSecretAnswer")?.value || "";
    const confirmAnswer = $("setupSecretAnswerConfirm")?.value || "";

    // ------------------------------------------
    // STEP 1: Validate password
    // ------------------------------------------

    if (password.length < 8) {
        return showAlert("Password must be at least 8 characters");
    }

    if (!/[A-Za-z]/.test(password) || !/\d/.test(password)) {
        return showAlert("Password must include letters and numbers");
    }

    if (password !== confirm) {
        return showAlert("Passwords do not match");
    }

    // ------------------------------------------
    // STEP 2: Validate selected security question
    // ------------------------------------------

    const validQuestions = [
        "mother_maiden_name",
        "first_pet",
        "first_school",
        "birth_city",
        "favorite_teacher",
        "childhood_friend",
        "first_car",
        "favorite_food",
        "dream_job",
        "favorite_place"
    ];

    if (!questionKey) {
        return showAlert("Please select a security question");
    }

    if (!validQuestions.includes(questionKey)) {
        return showAlert("Invalid security question selected");
    }

    // ------------------------------------------
    // STEP 3: Validate security answer
    // ------------------------------------------

    if (answer.trim() === "") {
        return showAlert("Please enter your security answer");
    }

    if (answer.length < 8) {
        return showAlert("Security answer must be at least 8 characters");
    }

    if (answer.length > 255) {
        return showAlert("Security answer cannot exceed 255 characters");
    }

    // Reject <, >, double quote, single quote and backslash.
    if (/[<>"'\\]/.test(answer)) {
        return showAlert("Security answer contains invalid characters");
    }

    // ------------------------------------------
    // STEP 4: Confirm security answer
    // ------------------------------------------

    if (confirmAnswer.trim() === "") {
        return showAlert("Please confirm your security answer");
    }

    if (answer !== confirmAnswer) {
        return showAlert("Security answers do not match");
    }

    // ------------------------------------------
    // STEP 5: Validate setup token
    // ------------------------------------------

    if (!SetupState.setupToken) {
        showAlert("Session expired. Please restart.");
        return goBackToEmail();
    }

    // ------------------------------------------
    // STEP 6: Submit account setup
    // ------------------------------------------

    setLoading("completeSetupBtn", true, "Saving...");

    try {
        const { ok, data } = await apiCall(
            "../backend/authentication/complete_account_setup.php",
            {
                setup_token: SetupState.setupToken,
                password: password,
                password_confirm: confirm,
                secret_question: questionKey,
                secret_answer: answer,
                confirm_answer: confirmAnswer
            }
        );

        if (!ok || !data.success) {
            throw new Error(data?.message || "Account setup failed");
        }

        // ------------------------------------------
        // STEP 7: Display success
        // ------------------------------------------

        SetupState.setupToken = "";

        const loginUrl = data.data?.redirect_url || "../pages/index.php";

        showStep(4);

        const loginBtn = $("loginBtn");

        if (loginBtn) {
            loginBtn.href = loginUrl;
        }

    } catch (err) {
        showAlert(err.message || "Account setup failed. Please try again.");

    } finally {
        setLoading("completeSetupBtn", false);
    }
};

  // ---------- Listeners ----------
  document.addEventListener("DOMContentLoaded", () => {
    // OTP input — numeric only, auto-advance not needed (single field)
    const otpInput = $("setupOtp");
    if (otpInput) {
      otpInput.addEventListener("input", (e) => {
        e.target.value = e.target.value.replace(/\D/g, "").slice(0, 6);
      });
    }

    // Password strength
    const pw = $("setupPassword");
    if (pw) {
      pw.addEventListener("input", updatePasswordStrength);
    }

    // Enter key on OTP → submit
    const otpForm = $("formOtp");
    if (otpForm) {
      otpForm.addEventListener("submit", () => {});
    }

    try {
        const prefillEmail = sessionStorage.getItem('setup_prefill_email');
        if (prefillEmail) {
            const emailInput = document.getElementById('setupEmail');
            if (emailInput && !emailInput.value) {
                emailInput.value = prefillEmail;
                // Optionally auto-focus the send button so they can hit Enter
                emailInput.focus();
            }
            sessionStorage.removeItem('setup_prefill_email');
        }
    } catch (_) { /* sessionStorage may be blocked — ignore */ }
  });
})();
