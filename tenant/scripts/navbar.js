// navbar.js
// ==================== GLOBAL VARIABLES ====================
let currentUser = null;
let inactivityTimer = null;
let warningTimer = null;
let isWarningShowing = false;

// Configuration
const INACTIVITY_TIMEOUT = 5 * 60 * 1000; // 2 minutes
const WARNING_TIMEOUT = 2 * 60 * 1000; // 1 minute warning

// ==================== INITIALIZATION ====================
document.addEventListener('DOMContentLoaded', function() {
    initializeApp();
    setupEventListeners();
    setupInactivityTimer();
});

async function initializeApp() {
    await fetchUserData();
    
    // Check if password needs to be changed (first login)
    if (sessionStorage.getItem('needs_password_change') === 'true') {
        // Small delay to ensure DOM is ready
        setTimeout(() => {
            showForcePasswordModal();
        }, 500);
    }
    
    setupEventListeners();
    setupInactivityTimer();
}

function setupEventListeners() {
    // Mobile menu toggle
    const menuToggle = document.getElementById('menuToggle');
    const sidebarClose = document.getElementById('sidebarClose');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const sidebar = document.getElementById('tenantSidebar');
    
    if (menuToggle) {
        menuToggle.addEventListener('click', function() {
            sidebar.classList.add('active');
            sidebarOverlay.classList.add('active');
        });
    }
    
    if (sidebarClose) {
        sidebarClose.addEventListener('click', function() {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
        });
    }
    
    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', function() {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
        });
    }
    
    // Logout button
    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function(e) {
            e.preventDefault();
            handleLogout();
        });
    }
    
    // Notifications button
    const notifBtn = document.getElementById('notificationsBtn');
    if (notifBtn) {
        notifBtn.addEventListener('click', function() {
            fetchNotifications();
        });
    }
    
    // Set active nav item based on current page
    const currentPage = window.location.pathname.split('/').pop().replace('.php', '');
    document.querySelectorAll('.nav-item[data-page]').forEach(item => {
        if (item.dataset.page === currentPage) {
            item.classList.add('active');
        } else {
            item.classList.remove('active');
        }
    });
}

// ==================== USER DATA ====================
async function fetchUserData() {
    try {
        const response = await fetch('../backend/tenant/fetch_user_data.php');
        const data = await response.json();
        
        console.log('User data response:', data);
        
        if (data.success && data.data) {
            currentUser = data.data;
            console.log('Current user set:', currentUser);
            
            // Update sidebar info
            updateUserInfo();
            
            // Make currentUser globally available
            window.currentUser = currentUser;
            
            // Dispatch custom event so other pages know user data is ready
            window.dispatchEvent(new CustomEvent('userDataLoaded', { detail: currentUser }));
        } else {
            throw new Error(data.message || 'Failed to fetch user data');
        }
    } catch (error) {
        console.error('Error fetching user data:', error);
        showToast('Failed to load user information', 'error');
        setTimeout(() => {
            window.location.href = '../pages/index.php';
        }, 2000);
    }
}

function updateUserInfo() {
    if (currentUser) {
        const nameElement = document.getElementById('tenantName');
        const apartmentElement = document.getElementById('tenantApartment');
        const photoElement = document.getElementById('photoElement');

         const photoUrl = currentUser.photo
        ? `../../admin/backend/tenants/tenant_photos/${currentUser.photo}`
        : "";
        
        console.log('Updating user info:', {
            name: `${currentUser.firstname || ''} ${currentUser.lastname || ''}`,
            apartment: currentUser.apartment_number || currentUser.apartment_code
        });
        
        if (nameElement) {
            nameElement.textContent = `${currentUser.firstname || ''} ${currentUser.lastname || ''}`.trim() || 'Tenant';
        }
        if (apartmentElement) {
            apartmentElement.textContent = `Apartment Number: ${currentUser.apartment_number || currentUser.apartment_code || 'No Apartment'}`;
        }
        if(photoElement){
            photoElement.innerHTML += `<img src="${
                          photoUrl || ""
                        }" alt="Tenant Photo" id="edit_tenant_photo_preview"
                             style="width:120px;height:120px;object-fit:cover;border-radius:8px;border:1px solid #ccc;margin-bottom:10px;">`
        }
    }
}

// ==================== INACTIVITY TIMER ====================
function setupInactivityTimer() {
    const activityEvents = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click'];
    
    activityEvents.forEach(event => {
        document.addEventListener(event, resetInactivityTimer);
    });
    
    resetInactivityTimer();
}

function resetInactivityTimer() {
    clearInactivityTimer();
    clearWarningTimer();
    isWarningShowing = false;
    
    inactivityTimer = setTimeout(() => {
        console.log('User inactive for', INACTIVITY_TIMEOUT / 1000, 'seconds');
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
        console.log('Warning timeout reached, logging out...');
        performAutoLogout();
    }, WARNING_TIMEOUT);
    
    showConfirmationDialog(
        'Session Timeout Warning',
        'You have been inactive for a while. Do you want to stay logged in?',
        () => {
            console.log('User chose to stay logged in');
            clearWarningTimer();
            isWarningShowing = false;
            resetInactivityTimer();
            showToast('Session extended', 'success');
        },
        () => {
            console.log('User chose to logout');
            performAutoLogout();
        }
    );
}

function showConfirmationDialog(title, message, onConfirm, onCancel) {
    const existingDialog = document.getElementById('customConfirmDialog');
    if (existingDialog) {
        existingDialog.remove();
    }
    
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
    
    document.body.insertAdjacentHTML('beforeend', dialogHtml);
    
    const dialog = document.getElementById('customConfirmDialog');
    const confirmBtn = dialog.querySelector('.confirm-btn-confirm');
    const cancelBtn = dialog.querySelector('.confirm-btn-cancel');
    const closeBtn = dialog.querySelector('.confirm-dialog-close');
    
    const closeDialog = () => {
        dialog.remove();
    };
    
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
    
    warningTimer = setTimeout(() => {
        if (dialog) {
            closeDialog();
            if (onCancel) onCancel();
        }
    }, WARNING_TIMEOUT);
}

async function performAutoLogout() {
    try {
        const response = await fetch('../backend/authentication/logout.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                logout_id: currentUser?.tenant_code,
                auto_logout: true 
            })
        });
        const data = await response.json();
        
        if (data.success) {
            window.location.href = '../pages/index.php?message=session_expired';
        } else {
            window.location.href = '../pages/index.php';
        }
    } catch (error) {
        console.error('Auto-logout error:', error);
        window.location.href = '../pages/index.php';
    } finally {
        clearInactivityTimer();
        clearWarningTimer();
    }
}

// ==================== LOGOUT ====================
async function handleLogout() {
    // Remove any existing dialog
    const existingDialog = document.getElementById('customConfirmDialog');
    if (existingDialog) {
        existingDialog.remove();
    }
    
    // Create logout confirmation dialog
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
    
    document.body.insertAdjacentHTML('beforeend', dialogHtml);
    
    const dialog = document.getElementById('customConfirmDialog');
    const confirmBtn = dialog.querySelector('.confirm-btn-confirm');
    const cancelBtn = dialog.querySelector('.confirm-btn-cancel');
    const closeBtn = dialog.querySelector('.confirm-dialog-close');
    
    const closeDialog = () => {
        dialog.remove();
    };
    
    // Logout button
    confirmBtn.onclick = async () => {
        closeDialog();
        try {
            const response = await fetch('../backend/authentication/logout.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ logout_id: currentUser?.tenant_code })
            });
            const data = await response.json();
            
            if (data.success) {
                showToast('Logged out successfully', 'success');
                setTimeout(() => {
                    window.location.href = '../pages/index.php';
                }, 1000);
            } else {
                throw new Error(data.message || 'Logout failed');
            }
        } catch (error) {
            console.error('Logout error:', error);
            showToast(error.message, 'error');
        }
    };
    
    // Cancel button
    cancelBtn.onclick = () => {
        closeDialog();
    };
    
    closeBtn.onclick = () => {
        closeDialog();
    };
}

// ==================== NOTIFICATIONS ====================
async function fetchNotifications() {
    try {
        const response = await fetch('../backend/tenant/fetch_notifications.php');
        const data = await response.json();
        if (data.success) {
            const count = data.data?.unread_count || data.unread_count || 0;
            const badge = document.getElementById('notificationBadge');
            if (badge) {
                badge.textContent = count;
                badge.style.display = count > 0 ? 'flex' : 'none';
            }
            showToast(`You have ${count} unread notifications`, 'info');
        }
    } catch (error) {
        console.error('Error fetching notifications:', error);
    }
}

// ==================== UTILITY FUNCTIONS ====================
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type}`;
    toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i><span>${escapeHtml(message)}</span>`;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.remove();
    }, 3000);
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Force password change functions
function showForcePasswordModal() {
    const modal = document.getElementById('forcePasswordModal');
    if (modal) {
        modal.classList.add('active');
        
        // Add event listeners for password strength
        const newPasswordInput = document.getElementById('forceNewPassword');
        const confirmPasswordInput = document.getElementById('forceConfirmPassword');
        
        if (newPasswordInput) {
            newPasswordInput.addEventListener('input', validateForcePasswordStrength);
        }
        if (confirmPasswordInput) {
            confirmPasswordInput.addEventListener('input', validateForcePasswordStrength);
        }
        
        // Prevent closing by clicking outside
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                showToast('Please change your password to continue', 'warning');
            }
        });
        
        // Prevent escape key from closing
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.classList.contains('active')) {
                e.preventDefault();
                showToast('Please change your password to continue', 'warning');
            }
        });
    }
}

function closeForcePasswordModal() {
    const modal = document.getElementById('forcePasswordModal');
    if (modal) {
        modal.classList.remove('active');
    }
}

function validateForcePasswordStrength() {
    const password = document.getElementById('forceNewPassword')?.value || '';
    
    const hasLength = password.length >= 8;
    const hasUpper = /[A-Z]/.test(password);
    const hasLower = /[a-z]/.test(password);
    const hasNumber = /[0-9]/.test(password);
    
    // Update requirement list
    updateRequirement('req-length', hasLength);
    updateRequirement('req-upper', hasUpper);
    updateRequirement('req-lower', hasLower);
    updateRequirement('req-number', hasNumber);
    
    // Calculate strength
    let strength = 0;
    if (hasLength) strength++;
    if (hasUpper) strength++;
    if (hasLower) strength++;
    if (hasNumber) strength++;
    
    const strengthBar = document.getElementById('strengthBar');
    const strengthText = document.getElementById('strengthText');
    
    if (strengthBar) {
        let width = (strength / 4) * 100;
        strengthBar.style.width = width + '%';
        
        if (strength <= 1) {
            strengthBar.style.background = '#dc2626';
            if (strengthText) strengthText.textContent = 'Password strength: Weak';
        } else if (strength <= 2) {
            strengthBar.style.background = '#f59e0b';
            if (strengthText) strengthText.textContent = 'Password strength: Fair';
        } else if (strength <= 3) {
            strengthBar.style.background = '#3b82f6';
            if (strengthText) strengthText.textContent = 'Password strength: Good';
        } else {
            strengthBar.style.background = '#10b981';
            if (strengthText) strengthText.textContent = 'Password strength: Strong';
        }
    }
    
    return strength === 4;
}

function updateRequirement(elementId, isValid) {
    const element = document.getElementById(elementId);
    if (element) {
        if (isValid) {
            element.classList.add('valid');
        } else {
            element.classList.remove('valid');
        }
    }
}

async function submitForcePasswordChange() {
    const newPassword = document.getElementById('forceNewPassword')?.value;
    const confirmPassword = document.getElementById('forceConfirmPassword')?.value;
    
    // Validate
    if (!newPassword || !confirmPassword) {
        showToast('Please fill in all fields', 'error');
        return;
    }
    
    if (newPassword !== confirmPassword) {
        showToast('Passwords do not match', 'error');
        return;
    }
    
    if (newPassword.length < 8) {
        showToast('Password must be at least 8 characters', 'error');
        return;
    }
    
    if (!/[A-Z]/.test(newPassword)) {
        showToast('Password must contain at least one uppercase letter', 'error');
        return;
    }
    
    if (!/[a-z]/.test(newPassword)) {
        showToast('Password must contain at least one lowercase letter', 'error');
        return;
    }
    
    if (!/[0-9]/.test(newPassword)) {
        showToast('Password must contain at least one number', 'error');
        return;
    }
    
    try {
        const response = await fetch('../backend/authentication/change_default_password.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                new_password: newPassword,
                confirm_password: confirmPassword
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            showToast('Password changed successfully! Please log in again.', 'success');
            
            // Clear session storage flag
            sessionStorage.removeItem('needs_password_change');
            sessionStorage.removeItem('temp_user_id');
            
            // Close modal
            closeForcePasswordModal();
            
            // Logout and redirect to login page
            setTimeout(() => {
                window.location.href = '../pages/index.php?message=password_changed';
            }, 2000);
        } else {
            throw new Error(data.message || 'Failed to change password');
        }
    } catch (error) {
        console.error('Error changing password:', error);
        showToast(error.message, 'error');
    }
}

// ==================== MAKE FUNCTIONS GLOBAL ====================
window.currentUser = currentUser;
window.showToast = showToast;
window.fetchNotifications = fetchNotifications;
window.handleLogout = handleLogout;

// ==================== ADD CUSTOM DIALOG STYLES ====================
const dialogStyles = document.createElement('style');
dialogStyles.textContent = `
    .confirm-dialog {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10000;
        animation: fadeIn 0.3s ease;
    }
    
    .confirm-dialog-content {
        background: white;
        border-radius: 12px;
        width: 90%;
        max-width: 400px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        animation: slideUp 0.3s ease;
    }
    
    .confirm-dialog-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 20px;
        border-bottom: 1px solid #f0f0f0;
    }
    
    .confirm-dialog-header h3 {
        margin: 0;
        font-size: 18px;
        color: #1a1f36;
    }
    
    .confirm-dialog-close {
        background: none;
        border: none;
        font-size: 24px;
        cursor: pointer;
        color: #999;
        transition: color 0.3s;
    }
    
    .confirm-dialog-close:hover {
        color: #666;
    }
    
    .confirm-dialog-body {
        padding: 20px;
    }
    
    .confirm-dialog-body p {
        margin: 0;
        color: #666;
        line-height: 1.5;
    }
    
    .confirm-dialog-footer {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        padding: 20px;
        border-top: 1px solid #f0f0f0;
    }
    
    .confirm-btn-confirm {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border: none;
        padding: 8px 20px;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 500;
        transition: transform 0.2s;
    }
    
    .confirm-btn-confirm:hover {
        transform: translateY(-1px);
    }
    
    .confirm-btn-cancel {
        background: #f3f4f6;
        color: #1a1f36;
        border: 1px solid #e5e7eb;
        padding: 8px 20px;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 500;
        transition: all 0.2s;
    }
    
    .confirm-btn-cancel:hover {
        background: #e5e7eb;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    
    @keyframes slideUp {
        from {
            transform: translateY(20px);
            opacity: 0;
        }
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }
    
    .loading-spinner {
        text-align: center;
        padding: 40px;
    }
    
    .spinner {
        width: 40px;
        height: 40px;
        border: 3px solid #f3f3f3;
        border-top: 3px solid #667eea;
        border-radius: 50%;
        animation: spin 1s linear infinite;
        margin: 0 auto;
    }
    
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
`;
document.head.appendChild(dialogStyles);
// At the end of navbar.js, after currentUser is set
window.currentUser = currentUser;

// Dispatch event when user data is loaded
if (currentUser) {
    window.dispatchEvent(new CustomEvent('userDataLoaded', { detail: currentUser }));
}