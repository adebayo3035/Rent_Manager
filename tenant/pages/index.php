<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Import crypto-js library (add this to your HTML head) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/crypto-js/4.1.1/crypto-js.min.js"></script>
    <link rel="stylesheet" href="../css/style.css">

    <title>RentFlow - Tenant Login</title>
</head>



<body>
    <div class="container">
        <header>
            <h1>Welcome To RentFlow</h1>
        </header>

        <div class="login">
            <h3 id="signInText">Tenant Login</h3>

            <form enctype="multipart/form-data" autocomplete="off" id="loginForm">
                <div class="errorContainer" id="errorContainer"></div>
                <div class="input-box">
                    <label for="username"> Username</label>
                    <input type="text" name="username" id="username" placeholder="barnadota@gmail.com" />
                </div>
                <div class="input-box">
                    <label for="password"> Password</label>
                    <input type="password" name="password" id="password" placeholder="........." />
                </div>
                <div class="checkbox remember_me">
                    <label>
                        <input type="checkbox" id="viewPassword" name="viewPassword"
                            onchange="togglePasswordVisibility()"> Show Password
                    </label>
                </div>
                <input type="submit" id="btnLogin" class="button" value="SIGN IN">
            </form>
            <!-- Trigger the modal -->
            <p style="text-align:center; margin-top: 16px; font-size: 13px; color: #64748b;">
                First time here?
                <a href="account_setup.php" style="color: #1e3c72; font-weight: 600; text-decoration: none;">Set up your
                    account</a>
            </p>
            <a href="#" id="resetPasswordBtn"> Forget your password?</a>
            <a href="#" id="getSecretQuestionBtn"> Forgot your Secret Question?</a>
            <a href="#" id="reactivateAccountBtn"> Re-activate your Account</a>

        </div>
    </div>

    <!-- Modal Structure to Reset Password -->
    <div id="passwordResetModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Reset Your Password</h2>
            <form id="resetPasswordForm" autocomplete="off">
                <div class="input-box">
                    <label for="resetEmail">Enter your email</label>
                    <input type="email" name="resetEmail" id="resetEmail" placeholder="your-email@example.com"
                        required />
                </div>
                <div class="input-box">
                    <label for="resetSecretAnswer">Enter your Secret Answer</label>
                    <input type="password" name="resetSecretAnswer" id="resetSecretAnswer"
                        placeholder="Enter Secret Answer" required />
                </div>
                <div class="input-box">
                    <label for="newPassword">New Password</label>
                    <input type="password" name="newPassword" id="newPassword" placeholder="Enter new password"
                        required />
                </div>
                <div class="input-box">
                    <label for="confirmPassword">Confirm Password</label>
                    <input type="password" name="confirmPassword" id="confirmPassword"
                        placeholder="Confirm new password" required />
                </div>
                <input type="submit" class="button" value="Reset Password">
            </form>
        </div>
    </div>

    <!-- Modal Structure to Get Secret Question -->
    <div id="getSecretQuestionModal" class="modal">
        <div class="modal-content" style="max-width: 450px;">
            <div class="modal-header">
                <h2>Retrieve Secret Question</h2>
                <span class="close">&times;</span>
            </div>
            <div class="modal-body">
                <form id="getSecretQuestionForm" autocomplete="off">
                    <div class="input-box">
                        <label for="emailSecretQuestion">Email or Phone Number</label>
                        <input type="text" name="emailSecretQuestion" id="emailSecretQuestion"
                            placeholder="your-email@example.com or 08012345678" required />
                        <small>Enter the email or phone number associated with your account</small>
                    </div>
                    <div class="input-box">
                        <label for="passwordSecretQuestion">Password</label>
                        <input type="password" name="passwordSecretQuestion" id="passwordSecretQuestion"
                            placeholder="Enter your password" required />
                    </div>
                    <input type="submit" class="button" value="Get Secret Question">
                </form>
                <div id="displayQuestion" class="displayQuestion"></div>
            </div>
        </div>
    </div>

    <!-- Modal Structure to Raise Account Reactivation Request -->
    <div id="accountActivationModal" class="modal">
        <div class="modal-content">
            <span class="close" id="accountActivationModalClose">&times;</span>
            <h2>Raise Account Re-activation Request</h2>

            <!-- Tab Navigation Pills -->
            <div class="tab-container">
                <button class="tab-button active" onclick="openTab(event, 'generateOTPTab')">Generate OTP</button>
                <button class="tab-button" onclick="openTab(event, 'validateOTPTab')">Validate & Submit</button>
            </div>

            <!-- Generate OTP Tab -->
            <div id="generateOTPTab" class="tab-content" style="display: block;">
                <form id="otpGenerationForm" autocomplete="off">
                    <div class="input-box">
                        <label for="emailActivateAccount">Enter your email address</label>
                        <input type="email" name="emailActivateAccount" id="emailActivateAccount"
                            placeholder="your-email@example.com" required />
                        <button type="submit" id="sendOTP" class="button">Generate & Send OTP</button>

                        <span id="OTPResponse"></span>
                    </div>
                </form>
            </div>

            <!-- Validate OTP and Submit Request Tab -->
            <div id="validateOTPTab" class="tab-content">
                <form id="accountActivationForm" autocomplete="off">
                    <div class="input-box">
                        <label for="validateEmail">Email-Address</label>
                        <input type="email" name="validateEmail" id="validateEmail"
                            placeholder="Enter your E-mail address" required />
                    </div>
                    <div class="input-box">
                        <label for="otp">Enter OTP</label>
                        <input type="text" name="otp" id="otpInput" placeholder="Enter OTP sent to your mail"
                            required />
                    </div>
                    <div class="input-box">
                        <label for="reason" class="form-label">Reason for Reactivation</label>
                        <textarea id="reactivationReason" name="reactivationReason" class="form-control" maxlength="150"
                            rows="5" cols="40" required></textarea>
                    </div>
                    <!-- <input type="submit" class="button" value="Submit Request"> -->
                    <button type="submit" id="reactivationButton" class="button">Submit Request</button>
                    <span id="ReactivationResponse"></span>
                </form>
            </div>

            <div class="displayQuestion" id="displayResponse"></div>
        </div>
    </div>

    <!-- UI Framework Containers -->
    <div id="toastContainer"></div>

    <div id="alertModal" class="ui-modal">
        <div class="ui-modal-content">
            <h3 id="alertTitle">Alert</h3>
            <p id="alertMessage"></p>
            <button id="alertOkBtn">OK</button>
        </div>
    </div>

    <div id="confirmModal" class="ui-modal">
        <div class="ui-modal-content">
            <h3 id="confirmTitle">Confirm Action</h3>
            <p id="confirmMessage"></p>
            <div class="ui-modal-buttons">
                <button id="confirmCancelBtn">Cancel</button>
                <button id="confirmOkBtn">Yes</button>
            </div>
        </div>
    </div>

    <div id="uiLoaderOverlay">
        <div class="ui-loader"></div>
    </div>
    <script src="../../ui.js" charset="UTF-8"></script>
    <script src="../scripts/index.js" charset="UTF-8"></script>
</body>


</html>


<!-- To do tomorrow tuesdat=y 27/05/2025 -- implement deactivation and reactivation history page for each staff, separate deactivation and reactivation history for all staffs -->