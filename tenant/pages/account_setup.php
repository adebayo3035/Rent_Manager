<?php
// tenant/pages/account_setup.php
// Public setup page — no auth required, no session gate.

if (session_status() === PHP_SESSION_NONE)
    session_start();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Set up your RentFlow Pro account in three quick steps">
    <meta name="robots" content="noindex, nofollow">
    <title>Set up your account — RentFlow Pro</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/account_setup.css">
</head>

<body>
    <div class="setup-bg" aria-hidden="true"></div>

    <main class="setup-wrapper" role="main">
        <div class="setup-card">

            <!-- Brand -->
            <header class="setup-brand">
                <div class="brand-mark">
                    <i class="fas fa-building-columns"></i>
                </div>
                <span class="brand-name">RentFlow Pro</span>
            </header>

            <!-- Title -->
            <div class="setup-header">
                <h1 id="setupTitle">Set up your account</h1>
                <p id="setupSubtitle">Three quick steps to get started</p>
            </div>

            <!-- Step indicator -->
            <nav class="step-indicator" aria-label="Setup progress">
                <div class="step active" data-step="1">
                    <span class="step-num">1</span>
                    <span class="step-label">Email</span>
                </div>
                <div class="step-line" data-line="1"></div>
                <div class="step" data-step="2">
                    <span class="step-num">2</span>
                    <span class="step-label">Verify</span>
                </div>
                <div class="step-line" data-line="2"></div>
                <div class="step" data-step="3">
                    <span class="step-num">3</span>
                    <span class="step-label">Password</span>
                </div>
            </nav>

            <!-- Alert -->
            <div id="setupAlert" class="setup-alert" role="alert" aria-live="polite"></div>

            <!-- Step 1: Email -->
            <section class="setup-step active" id="step1" aria-labelledby="step1Title">
                <div class="step-inner">
                    <h2 class="step-title" id="step1Title">Enter your email address</h2>
                    <p class="step-hint">Use the email your account was created with.</p>

                    <form id="formEmail" onsubmit="handleSendOtp(event)" novalidate>
                        <div class="form-group">
                            <label for="setupEmail">Email address</label>
                            <div class="input-wrap">
                                <i class="fas fa-envelope"></i>
                                <input type="email" id="setupEmail" name="email" class="form-control"
                                    placeholder="you@example.com" autocomplete="email" inputmode="email" required>
                            </div>
                        </div>

                        <button type="submit" class="btn-primary" id="sendOtpBtn">
                            <span class="btn-text">
                                <i class="fas fa-paper-plane"></i> Send code
                            </span>
                            <span class="btn-spinner" hidden>
                                <i class="fas fa-circle-notch fa-spin"></i> Sending...
                            </span>
                        </button>
                    </form>

                    <p class="step-footnote">
                        Already have a password?
                        <a href="index.php">Log in instead</a>
                    </p>
                </div>
            </section>

            <!-- Step 2: OTP -->
            <section class="setup-step" id="step2" aria-labelledby="step2Title">
                <div class="step-inner">
                    <h2 class="step-title" id="step2Title">Enter verification code</h2>
                    <p class="step-hint">
                        We sent a 6-digit code to <strong id="maskedEmail">your email</strong>.
                    </p>

                    <form id="formOtp" onsubmit="handleVerifyOtp(event)" novalidate>
                        <div class="form-group">
                            <label for="setupOtp">6-digit code</label>
                            <input type="text" id="setupOtp" name="code" class="form-control otp-input"
                                placeholder="••••••" maxlength="6" inputmode="numeric" pattern="\d{6}"
                                autocomplete="one-time-code" required>
                            <div class="otp-meta">
                                <span id="otpTimer" class="otp-timer"></span>
                                <button type="button" class="link-btn" id="resendBtn" onclick="handleResendOtp()">
                                    Resend code
                                </button>
                            </div>
                        </div>

                        <button type="submit" class="btn-primary" id="verifyOtpBtn">
                            <span class="btn-text">
                                <i class="fas fa-check"></i> Verify code
                            </span>
                            <span class="btn-spinner" hidden>
                                <i class="fas fa-circle-notch fa-spin"></i> Verifying...
                            </span>
                        </button>
                    </form>

                    <button type="button" class="link-btn back-link" onclick="goBackToEmail()">
                        <i class="fas fa-arrow-left"></i> Use a different email
                    </button>
                </div>
            </section>

            <!-- Step 3: Password + Secret -->
            <section class="setup-step" id="step3" aria-labelledby="step3Title">
                <div class="step-inner">
                    <h2 class="step-title" id="step3Title">Set your password</h2>
                    <p class="step-hint">Choose a strong password and set a recovery question.</p>

                    <form id="formPassword" onsubmit="handleCompleteSetup(event)" novalidate>

                        <!-- Password -->
                        <div class="form-group">
                            <label for="setupPassword">Password</label>
                            <div class="input-wrap">
                                <i class="fas fa-lock"></i>
                                <input type="password" id="setupPassword" class="form-control"
                                    placeholder="At least 8 characters" minlength="8" autocomplete="new-password"
                                    required>
                                <button type="button" class="input-toggle" aria-label="Show password"
                                    onclick="togglePassword('setupPassword', this)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>

                            <div class="password-strength" id="pwStrength" aria-hidden="true">
                                <div class="strength-bar"><span></span></div>
                                <span class="strength-label"></span>
                            </div>
                        </div>

                        <!-- Confirm Password -->
                        <div class="form-group">
                            <label for="setupPasswordConfirm">Confirm password</label>
                            <div class="input-wrap">
                                <i class="fas fa-lock"></i>
                                <input type="password" id="setupPasswordConfirm" class="form-control"
                                    placeholder="Re-enter password" minlength="8" autocomplete="new-password" required>
                                <button type="button" class="input-toggle" aria-label="Show password"
                                    onclick="togglePassword('setupPasswordConfirm', this)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="form-divider">
                            <span>Recovery question</span>
                        </div>

                        <!-- Predefined Security Question -->
                        <div class="form-group">
                            <label for="setupSecretQuestion">Security question</label>
                            <div class="input-wrap">
                                <i class="fas fa-question-circle"></i>
                                <select id="setupSecretQuestion" class="form-control" required>
                                    <option value="">Select a security question</option>
                                    <option value="mother_maiden_name">What is your mother's maiden name?</option>
                                    <option value="first_pet">What was the name of your first pet?</option>
                                    <option value="first_school">What was the name of your first school?</option>
                                    <option value="birth_city">In which city were you born?</option>
                                    <option value="favorite_teacher">What is the name of your favorite teacher?</option>
                                    <option value="childhood_friend">What is the name of your childhood best friend?
                                    </option>
                                    <option value="first_car">What was your first car?</option>
                                    <option value="favorite_food">What is your favorite food?</option>
                                    <option value="dream_job">What was your dream job as a child?</option>
                                    <option value="favorite_place">What is your favorite place to visit?</option>
                                </select>
                            </div>
                        </div>

                        <!-- Security Answer -->
                        <div class="form-group">
                            <label for="setupSecretAnswer">Answer</label>
                            <div class="input-wrap">
                                <i class="fas fa-key"></i>
                                <input type="password" id="setupSecretAnswer" class="form-control"
                                    placeholder="Enter your answer" minlength="8" maxlength="255" autocomplete="off"
                                    required>
                                <button type="button" class="input-toggle" aria-label="Show answer"
                                    onclick="togglePassword('setupSecretAnswer', this)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <p class="field-hint">
                                <i class="fas fa-info-circle"></i>
                                Your answer is case-insensitive. Use an answer you can remember,
                                as you may need it to recover your account.
                            </p>
                        </div>

                        <!-- Confirm Security Answer -->
                        <div class="form-group">
                            <label for="setupSecretAnswerConfirm">Confirm answer</label>
                            <div class="input-wrap">
                                <i class="fas fa-key"></i>
                                <input type="password" id="setupSecretAnswerConfirm" class="form-control"
                                    placeholder="Re-enter your answer" minlength="8" maxlength="255" autocomplete="off"
                                    required>
                                <button type="button" class="input-toggle" aria-label="Show answer"
                                    onclick="togglePassword('setupSecretAnswerConfirm', this)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Submit -->
                        <button type="submit" class="btn-primary" id="completeSetupBtn">
                            <span class="btn-text">
                                <i class="fas fa-check-circle"></i> Finish setup
                            </span>
                            <span class="btn-spinner" hidden>
                                <i class="fas fa-circle-notch fa-spin"></i> Saving...
                            </span>
                        </button>

                    </form>
                </div>
            </section>

            <!-- Step 4: Success -->
            <section class="setup-step" id="step4" aria-labelledby="step4Title">
                <div class="step-inner success-inner">
                    <div class="success-icon" aria-hidden="true">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h2 class="step-title" id="step4Title">You're all set!</h2>
                    <p class="step-hint">
                        Your account is ready. Log in with your email and new password.
                    </p>
                    <a href="index.php" class="btn-primary btn-block" id="loginBtn">
                        <i class="fas fa-sign-in-alt"></i> Go to login
                    </a>
                </div>
            </section>

            <!-- Footer -->
            <footer class="setup-footer">
                <p>
                    <i class="fas fa-shield-alt"></i>
                    Secured by RentFlow Pro. Need help?
                    <a href="mailto:support@rentflowpro.com">Contact support</a>
                </p>
            </footer>

        </div>
    </main>

    <script src="../scripts/account_setup.js"></script>
</body>

</html>