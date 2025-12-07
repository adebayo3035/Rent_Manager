
const displayError = (message) => {
    errorText.style.display = "block";
    errorText.textContent = message;
};

// Password visibility toggle
const passwordInput = document.getElementById('password'),
    showPasswordCheckbox = document.getElementById('viewPassword');

showPasswordCheckbox.addEventListener('change', () => {
    passwordInput.type = showPasswordCheckbox.checked ? 'text' : 'password';
});

// Reusable modal toggle function
const toggleModal = (modalId, display = "none") => {
    const modal = document.getElementById(modalId);
    modal.style.display = display;
};

document.addEventListener("DOMContentLoaded", () => {
    const resetPasswordBtn = document.getElementById("resetPasswordBtn"),
        getSecretQuestionBtn = document.getElementById('getSecretQuestionBtn'),
        reactivateAccountBtn = document.getElementById('reactivateAccountBtn'),
        modal1 = document.getElementById("passwordResetModal"),
        modal2 = document.getElementById("getSecretQuestionModal"),
        modal3 = document.getElementById("accountActivationModal"),
        closeModalBtns = document.querySelectorAll(".close");

    // Initialize modals as hidden
    [modal1, modal2, modal3].forEach(modal => modal.style.display = "none");

    // Parse URL parameters
    const urlParams = new URLSearchParams(window.location.search);
    const action = urlParams.get('action');

    // If the action is reset-password, open the modal
    if (action === "reset-password") {
        toggleModal("passwordResetModal", "flex");
    }

    // Toggle modals for reset password and secret question
    resetPasswordBtn.onclick = (e) => {
        e.preventDefault();
        toggleModal("passwordResetModal", "flex");
    };

    getSecretQuestionBtn.onclick = (e) => {
        e.preventDefault();
        toggleModal("getSecretQuestionModal", "flex");
    };
    reactivateAccountBtn.onclick = (e) => {
        e.preventDefault();
        toggleModal("accountActivationModal", "flex");
    };

    // Close modals when 'x' is clicked
    closeModalBtns.forEach(closeBtn => {
        closeBtn.onclick = () => closeBtn.closest(".modal").style.display = "none";
    });

    // Close modal when clicking outside of it
    window.onclick = (e) => {
        if (e.target == modal1) toggleModal("passwordResetModal");
        if (e.target == modal2) toggleModal("getSecretQuestionModal");
        if (e.target == modal3) toggleModal("accountActivationModal");

    };

    // Reset Password Form Submission
    const resetPasswordForm = document.getElementById("resetPasswordForm");

    resetPasswordForm.onsubmit = async (e) => {
        e.preventDefault();

        const resetEmail = document.getElementById("resetEmail").value,
            resetSecretAnswer = document.getElementById("resetSecretAnswer").value,
            newPassword = document.getElementById("newPassword").value,
            confirmPassword = document.getElementById("confirmPassword").value;

        try {
            const response = await fetch('backend/authentication/reset_password.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email: resetEmail, password: newPassword, secret_answer: resetSecretAnswer, confirmPassword: confirmPassword })
            });

            const data = await response.json();
            if (data.success) {
                alert("Password reset successful!");
                toggleModal("passwordResetModal");
            } else {
                alert(`Error resetting password: ${data.message}`);
            }
        } catch (error) {
            console.error('Error:', error);
            alert("An error occurred. Please try again.");
        }
    };

    // Get Secret Question form
    const getSecretQuestionForm = document.getElementById("getSecretQuestionForm");
    getSecretQuestionForm.onsubmit = async (e) => {
        e.preventDefault();

        const email = document.getElementById("emailSecretQuestion").value,
            password = document.getElementById("passwordSecretQuestion").value

        try {
            const response = await fetch('backend/authentication/secret_question.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email: email, password: password })
            });

            const data = await response.json();
            if (data.success) {
                alert(`Your Secret Question is: " ${data.secret_question}`);
                document.getElementById('displayQuestion').textContent = `Your Secret Question is: " ${data.secret_question}`;
            } else {
                alert(`Error fetching secret question: ${data.message}`);
            }
        } catch (error) {
            console.error('Error:', error);
            alert("An error occurred. Please try again.");
        }
    };

    // Login form submission
    // Form submission logic
    const loginForm = document.getElementById("loginForm");
    const loginButton = document.getElementById('btnLogin');
    const errorContainer = document.querySelector(".errorContainer");

    // Event listener for form submission
    loginForm.addEventListener("submit", async (e) => {
        e.preventDefault(); // Prevent default form submission

        // Get values from input fields
        const username = loginForm.querySelector('input[name="username"]').value;
        const password = loginForm.querySelector('input[name="password"]').value;

        // Basic validation
        if (!username || !password) {
            displayError("Please enter both username and password.");
            return;
        }

        // Prepare data to send
        const postData = {
            username: username,
            password: password
        };

        try {
            // Send the data using fetch
            const response = await fetch("../backend/authentication/admin_login3.php", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json" // Send data as JSON
                },
                body: JSON.stringify(postData)
            });

            const data = await response.json();

            if (response.ok && data.success) {
                // Disable all form elements and indicate processing
                disableForm(loginForm);
                document.getElementById('resetPasswordBtn').style.cursor = "not-allowed";
                document.getElementById('resetPasswordBtn').style.opacity = "0.6";
                document.getElementById('getSecretQuestionBtn').style.cursor = "not-allowed";
                document.getElementById('getSecretQuestionBtn').style.opacity = "0.6";

                // Notify user of successful login
                displayMessage("Login successful! Redirecting...");

                // Wait for 3 seconds before redirecting
                await new Promise(resolve => setTimeout(resolve, 0));
                console.log("Login is now successful")
                // Redirect to splash screen
                window.location.href = "splashscreen.php";
            } else {
                // Display error message from the server
                displayMessage(data.message || "Login failed. Please try again.");
            }
        } catch (error) {
            // Handle network or unexpected errors
            displayMessage("An error occurred while processing your request. Please try again later.");
        }
    });

    function disableForm(form) {
        Array.from(form.elements).forEach(element => {
            element.disabled = true;
        });
        form.style.opacity = "0.6"; // Optional: Reduce opacity to indicate it's disabled
        form.style.pointerEvents = "none"; // Prevent interactions
    }

    document.getElementById('accountActivationModalClose').addEventListener('click', function () {
        resetAccountActivationModal();
        document.getElementById('accountActivationModal').style.display = 'none';
    });

    // Generate and send OTP - Now tied to the otpGenerationForm
    document.getElementById('otpGenerationForm').addEventListener('submit', async function (event) {
        event.preventDefault();

        const email = document.getElementById("emailActivateAccount").value;
        const user_type = 'admin';
        const title = "Account Reactivation OTP";
        const sendOTPButton = document.getElementById("sendOTP");
        const sendOTPEmail = document.getElementById("emailActivateAccount");
        const responseMessage = document.getElementById("OTPResponse");

        // Validate email
        if (!email || !validateEmail(email)) {
            displayMessage("Please enter a valid email address", 'error');
            return;
        }

        // Create and show full-page loader
        const loaderOverlay = createFullPageLoader();
        document.body.appendChild(loaderOverlay);

        const requestData = { email, user_type, title };

        // Disable inputs and show processing state
        disableFormElements([sendOTPButton, sendOTPEmail], 'Sending...');

        // Clear previous messages
        clearResponseMessage(responseMessage);

        // Implement timeout using AbortController
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 120000); // 2 minutes timeout

        try {
            const response = await fetch("backend/utilities/send_otp.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(requestData),
                signal: controller.signal
            });

            clearTimeout(timeoutId);
            const result = await response.json();

            if (result.success) {
                handleSuccessResponse(result, email, sendOTPButton, sendOTPEmail, responseMessage);
            } else {
                handleErrorResponse(result, sendOTPButton, sendOTPEmail, responseMessage);
            }
        } catch (error) {
            clearTimeout(timeoutId);
            handleFetchError(error, sendOTPButton, sendOTPEmail, responseMessage);
        } finally {
            // Remove loader
            loaderOverlay.remove();
        }
    });

    // Helper Functions
    function createFullPageLoader() {
        const loader = document.createElement('div');
        loader.className = 'loader-overlay';
        loader.innerHTML = `
        <div class="loader-content">
            <div class="roller-loader"></div>
            <p>Sending OTP...</p>
        </div>
    `;
        return loader;
    }

    function disableFormElements(elements, buttonText = '') {
        elements.forEach(element => {
            element.disabled = true;
            if (element.tagName === 'BUTTON' && buttonText) {
                element.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ${buttonText}`;
            }
            element.classList.add('disabled');
        });
    }

    function clearResponseMessage(element) {
        element.textContent = "";
        element.className = "";
    }

    function handleSuccessResponse(result, email, button, emailInput, messageElement) {
        displayMessage("OTP Generated Successfully...", 'success');
        messageElement.textContent = result.message;
        messageElement.classList.add('text-success');

        setTimeout(() => {
            switchToValidateTab();
            document.getElementById("validateEmail").value = email;
        }, 3000);

        startOTPResendTimer(120, button, emailInput, messageElement);
    }

    function handleErrorResponse(result, button, emailInput, messageElement) {
        const errorMsg = result.message || "OTP Generation Failed.";
        displayMessage(errorMsg, 'error');
        messageElement.textContent = errorMsg;
        messageElement.classList.add('text-danger');
        resetOTPButton(button);
        resetOTPButton(emailInput);
    }

    function handleFetchError(error, button, emailInput, messageElement) {
        const errorMsg = error.name === "AbortError"
            ? "Service timeout. Please try again."
            : "An error occurred. Please try again.";

        displayMessage(errorMsg, 'error');
        messageElement.textContent = errorMsg;
        messageElement.classList.add('text-danger');
        resetOTPButton(button);
        resetOTPButton(emailInput);

        if (error.name !== "AbortError") {
            console.error("Error submitting OTP request:", error);
        }
    }

    function validateEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }

    // Form submission for account reactivation - Now tied to accountActivationForm
    document.getElementById("accountActivationForm").addEventListener("submit", async function (event) {
        event.preventDefault();

        const email = document.getElementById("emailActivateAccount").value;
        const reason = document.getElementById("reason").value;
        const otp = document.getElementById('otp').value;

        const requestData = {
            email,
            reason,
            otp
        };

        const submitButton = event.target.querySelector('input[type="submit"]');
        const originalButtonText = submitButton.value;
        const ReactivationResponse = document.getElementById('ReactivationResponse')

        try {
            // Show loading state
            submitButton.disabled = true;
            submitButton.value = "Processing...";

            const response = await fetch("backend/authentication/account_reactivation.php", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json"
                },
                body: JSON.stringify(requestData)
            });

            const result = await response.json();

            // Show result in a more elegant way than alert()
            ReactivationResponse.textContent = result.message;
            ReactivationResponse.style.color = result.success ? "green" : "red";
            ReactivationResponse.style.display = "block";

            if (result.success) {
                // Notify user of successful login
                displayMessage(result.message);
                // Wait for 3 seconds before redirecting
                await new Promise(resolve => setTimeout(resolve, 0));
                document.getElementById("accountActivationForm").reset();
                // Optionally switch back to first tab
                // document.querySelector('.tab-button').click();
            }
        } catch (error) {
            console.error("Error submitting reactivation request:", error);
            displayMessage("An Error occured, Please try again later..");
            ReactivationResponse.textContent = "An error occurred. Please try again.";
            ReactivationResponse.style.color = "red";
            ReactivationResponse.style.display = "block";
        } finally {
            submitButton.disabled = false;
            submitButton.value = originalButtonText;
        }
    });

});

// Tab switching function
function switchToValidateTab() {
    // Hide all tab contents
    const tabContents = document.getElementsByClassName("tab-content");
    for (let i = 0; i < tabContents.length; i++) {
        tabContents[i].style.display = "none";
    }

    // Remove active class from all tab buttons
    const tabButtons = document.getElementsByClassName("tab-button");
    for (let i = 0; i < tabButtons.length; i++) {
        tabButtons[i].className = tabButtons[i].className.replace(" active", "");
    }

    // Show the validate tab and activate its button
    document.getElementById('validateOTPTab').style.display = "block";
    tabButtons[1].className += " active";

    // Focus on the OTP input field for better UX
    document.getElementById('otp')?.focus();
}

function openTab(evt, tabName) {
    // Hide all tab contents
    const tabContents = document.getElementsByClassName("tab-content");
    for (let i = 0; i < tabContents.length; i++) {
        tabContents[i].style.display = "none";
    }

    // Remove active class from all tab buttons
    const tabButtons = document.getElementsByClassName("tab-button");
    for (let i = 0; i < tabButtons.length; i++) {
        tabButtons[i].className = tabButtons[i].className.replace(" active", "");
    }

    // Show the current tab and add active class to the button
    document.getElementById(tabName).style.display = "block";
    evt.currentTarget.className += " active";
}
// Timer function for OTP resend cooldown
function startOTPResendTimer(duration, button, inputElement, responseMessageContainer) {
    let timer = duration;
    const interval = setInterval(() => {
        const minutes = Math.floor(timer / 60);
        const seconds = timer % 60;

        button.innerHTML = `Resend OTP in ${minutes}:${seconds < 10 ? '0' : ''}${seconds}`;

        if (--timer < 0) {
            clearInterval(interval);
            resetOTPButton(button);
            resetOTPButton(inputElement);
            responseMessageContainer.textContent = '';
        }
    }, 1000);
}

// Reset OTP button to initial state
function resetOTPButton(element) {
    element.disabled = false;
    if (element.tagName.toLowerCase() === 'button') {
        element.innerHTML = 'Send OTP';
        element.classList.remove('disabled');
    } else {
        element.value = ''; // optional: clear input value
        element.classList.remove('disabled');
    }
}

function displayMessage(message, type = 'info') {
    // Create modal
    const modal = document.createElement('div');
    modal.className = 'custom-alert-modal';

    const modalContent = document.createElement('div');
    modalContent.className = 'custom-alert-content';

    const messageElement = document.createElement('p');
    messageElement.className = 'custom-alert-message';
    messageElement.textContent = message;

    const okButton = document.createElement('button');
    okButton.className = 'custom-alert-button';
    okButton.textContent = 'OK';

    // Style based on message type
    if (type === 'success') {
        messageElement.style.color = 'green';
    } else if (type === 'error') {
        messageElement.style.color = 'red';
    } else {
        messageElement.style.color = '#333';
    }

    // Assemble modal
    modalContent.appendChild(messageElement);
    modalContent.appendChild(okButton);
    modal.appendChild(modalContent);
    document.body.appendChild(modal);

    let timeoutId = null;

    // Auto-close only for success/info
    if (type === 'success' || type === 'info') {
        timeoutId = setTimeout(() => {
            if (document.body.contains(modal)) {
                modal.remove();
            }
        }, 3000);
    }

    // Close manually
    okButton.addEventListener('click', () => {
        if (timeoutId) clearTimeout(timeoutId);
        modal.remove();
    });

    // Optional: update global error container for errors
    if (type === 'error') {
        if (typeof errorContainer !== 'undefined') {
            errorContainer.textContent = message;
            errorContainer.style.display = "block";
        }
    }
}

// Create this reset function (can be placed with your other utility functions)
function resetAccountActivationModal() {
    // Reset forms
    document.getElementById('otpGenerationForm').reset();
    document.getElementById('accountActivationForm').reset();

    // Clear all response messages
    document.getElementById('OTPResponse').textContent = '';
    document.getElementById('ReactivationResponse').textContent = '';
    document.getElementById('displayResponse').textContent = '';

    // Reset UI states
    const sendOTPButton = document.getElementById('sendOTP');
    const sendOTPEmail = document.getElementById('emailActivateAccount');

    // Reset OTP generation section
    if (sendOTPButton) {
        sendOTPButton.disabled = false;
        sendOTPButton.innerHTML = 'Generate & Send OTP';
        sendOTPButton.classList.remove('disabled');
    }

    if (sendOTPEmail) {
        sendOTPEmail.disabled = false;
        sendOTPEmail.classList.remove('disabled');
    }

    // Reset account activation form button
    const submitButton = document.getElementById('accountActivationForm')?.querySelector('input[type="submit"]');
    if (submitButton) {
        submitButton.disabled = false;
        submitButton.value = 'Submit Request';
    }

    // Reset tabs to initial state
    const tabButtons = document.querySelectorAll('.tab-button');
    const tabContents = document.querySelectorAll('.tab-content');

    tabButtons.forEach(button => button.classList.remove('active'));
    tabContents.forEach(content => content.style.display = 'none');

    // Activate first tab
    tabButtons[0]?.classList.add('active');
    document.getElementById('generateOTPTab').style.display = 'block';

    // Clear any prefilled email in validation tab
    document.getElementById('validateEmail').value = '';
}