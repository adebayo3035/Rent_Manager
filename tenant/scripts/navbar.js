// navbar.js
// ==================== GLOBAL VARIABLES ====================
let navbarCurrentUser = null;
let inactivityTimer = null;
let warningTimer = null;
let isWarningShowing = false;
let navbarUserPromise = null;

// Configuration
const INACTIVITY_TIMEOUT = 5 * 60 * 1000; // 5 minutes
const WARNING_TIMEOUT = 2 * 60 * 1000; // 2 minutes warning

// ==================== NOTIFICATION BADGE VARIABLES ====================
let notificationRefreshInterval = null;

// ==================== INITIALIZATION ====================
document.addEventListener('DOMContentLoaded', async function() {
    // Initialize in correct order
    await initializeApp();
    
    // Initialize notification badge (silent, no toast)
    initNotificationBadge();
});

async function initializeApp() {
    // First fetch user data
    await fetchNavbarUserData();
    
    // Then setup event listeners (only once)
    setupEventListeners();
    
    // Then setup inactivity timer
    setupInactivityTimer();
    
    // Check if password needs to be changed (first login)
    if (sessionStorage.getItem('needs_password_change') === 'true') {
        setTimeout(() => {
            showForcePasswordModal();
        }, 500);
    }
}

function setupEventListeners() {
    // Mobile menu toggle
    const menuToggle = document.getElementById('menuToggle');
    const sidebarClose = document.getElementById('sidebarClose');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const sidebar = document.getElementById('tenantSidebar');
    
    if (menuToggle) {
        menuToggle.addEventListener('click', function() {
            if (sidebar) {
                sidebar.classList.add('active');
            }
            if (sidebarOverlay) {
                sidebarOverlay.classList.add('active');
            }
        });
    }
    
    if (sidebarClose) {
        sidebarClose.addEventListener('click', function() {
            if (sidebar) {
                sidebar.classList.remove('active');
            }
            if (sidebarOverlay) {
                sidebarOverlay.classList.remove('active');
            }
        });
    }
    
    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', function() {
            if (sidebar) {
                sidebar.classList.remove('active');
            }
            sidebarOverlay.classList.remove('active');
        });
    }
    
    // Logout button - FIXED: Ensure currentUser is available
    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
        // Remove any existing listeners to avoid duplicates
        logoutBtn.removeEventListener('click', handleLogoutClick);
        logoutBtn.addEventListener('click', handleLogoutClick);
    }
    
    // Notifications button - NOW shows toast when clicked
    const notifBtn = document.getElementById('notificationsBtn');
    if (notifBtn) {
        notifBtn.removeEventListener('click', fetchNotificationsWithToast);
        notifBtn.addEventListener('click', fetchNotificationsWithToast);
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

// ==================== NOTIFICATION BADGE FUNCTIONS (NEW) ====================

// Silent update - only updates badge count, NO toast
async function updateNotificationBadge() {
    try {
        // Only fetch if user is logged in
        if (!navbarCurrentUser && !window.currentUser) {
            console.log("No user logged in, skipping notification badge update");
            return;
        }
        
        const response = await fetch('../backend/tenant/fetch_notifications.php?limit=1');
        const data = await response.json();
        
        if (data.success) {
            const count = data.data?.unread_count || data.data.unread_count || 0;
            const badge = document.getElementById('notificationBadge');
            
            if (badge) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.style.display = count > 0 ? 'flex' : 'none';
            }
        }
    } catch (error) {
        console.error('Error fetching notification count:', error);
    }
}

// Fetch notifications with toast - ONLY when bell is clicked
async function fetchNotificationsWithToast() {
    try {
        const response = await fetch('../backend/tenant/fetch_notifications.php');
        const data = await response.json();
        
        if (data.success) {
            const count = data.data?.unread_count || data.data.unread_count || 0;
            const badge = document.getElementById('notificationBadge');
            
            if (badge) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.style.display = count > 0 ? 'flex' : 'none';
            }
            
            // Show toast ONLY when bell is clicked
            if (count > 0) {
                showToast(`You have ${count} unread notification${count > 1 ? 's' : ''}`, 'info');
            } else {
                showToast('No new notifications', 'info');
            }
        }
    } catch (error) {
        console.error('Error fetching notifications:', error);
        showToast('Failed to load notifications', 'error');
    }
}

// Initialize notification badge on page load (silent, no toast)
async function initNotificationBadge() {
    // Wait for user data to be loaded
    if (navbarCurrentUser || window.currentUser) {
        await updateNotificationBadge();
        startNotificationRefresh();
    } else {
        // Listen for user data loaded event
        window.addEventListener('userDataLoaded', async function() {
            await updateNotificationBadge();
            startNotificationRefresh();
        });
        
        // Also try after a short delay
        setTimeout(async () => {
            if (navbarCurrentUser || window.currentUser) {
                await updateNotificationBadge();
                startNotificationRefresh();
            }
        }, 1000);
    }
}

// Start periodic refresh of notification badge (every 30 seconds, silent)
function startNotificationRefresh() {
    if (notificationRefreshInterval) {
        clearInterval(notificationRefreshInterval);
    }
    
    notificationRefreshInterval = setInterval(() => {
        if (navbarCurrentUser || window.currentUser) {
            updateNotificationBadge(); // Silent update, no toast
        }
    }, 30000); // Refresh every 30 seconds
}

function stopNotificationRefresh() {
    if (notificationRefreshInterval) {
        clearInterval(notificationRefreshInterval);
        notificationRefreshInterval = null;
    }
}

// Wrapper function for logout to ensure currentUser is available
function handleLogoutClick(e) {
    e.preventDefault();
    if (navbarCurrentUser) {
        handleLogout();
    } else {
        console.error('Current user not loaded yet');
        showToast('Please wait, loading user data...', 'warning');
        // Retry after a short delay
        setTimeout(() => {
            if (navbarCurrentUser) {
                handleLogout();
            } else {
                showToast('Unable to logout. Please refresh the page.', 'error');
            }
        }, 1000);
    }
}

// ==================== USER DATA ====================
async function fetchNavbarUserData() {
    if (navbarCurrentUser) {
        return navbarCurrentUser;
    }

    if (window.currentUser && window.currentUser.tenant_code) {
        navbarCurrentUser = window.currentUser;
        await updateUserInfo();
        return navbarCurrentUser;
    }

    if (!navbarUserPromise) {
        navbarUserPromise = (async () => {
            try {
                const response = await fetch('../backend/tenant/fetch_user_data.php');
                const data = await response.json();
                
                console.log('User data response:', data);
                
                if (!(data.success && data.data)) {
                    throw new Error(data.message || 'Failed to fetch user data');
                }

                navbarCurrentUser = data.data;
                window.currentUser = navbarCurrentUser;
                console.log('Current user set:', navbarCurrentUser);
                
                await updateUserInfo();
                window.dispatchEvent(new CustomEvent('userDataLoaded', { detail: navbarCurrentUser }));
                return navbarCurrentUser;
            } catch (error) {
                console.error('Error fetching user data:', error);
                showToast('Failed to load user information', 'error');
                setTimeout(() => {
                    window.location.href = '../pages/index.php';
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
    // Small delay to ensure DOM is ready
    await new Promise(resolve => setTimeout(resolve, 100));
    
    const user = window.currentUser || navbarCurrentUser;

    if (!user) {
        console.error('No currentUser available');
        return;
    }
    
    navbarCurrentUser = user;
    console.log('Updating user info with data:', user);
    
    const nameElement = document.getElementById('tenantName');
    const apartmentElement = document.getElementById('tenantApartment');
    const photoElement = document.getElementById('photoElement');
    
    // Debug: Log if elements exist
    console.log('DOM Elements found:', {
        nameElement: !!nameElement,
        apartmentElement: !!apartmentElement,
        photoElement: !!photoElement
    });
    
    // Get the full name
    const fullName = `${user.firstname || ''} ${user.lastname || ''}`.trim();
    const apartmentNumber = user.apartment_number || user.apartment_code || 'No Apartment';
    
    console.log('Values to display:', {
        fullName,
        apartmentNumber,
        photo: user.photo
    });
    
    // Update name
    if (nameElement) {
        nameElement.textContent = fullName || 'Tenant';
        console.log('Name element updated to:', nameElement.textContent);
    } else {
        console.warn('tenantName element not found in DOM');
    }
    
    // Update apartment number
    if (apartmentElement) {
        apartmentElement.textContent = `Apartment Number: ${apartmentNumber}`;
        console.log('Apartment element updated to:', apartmentElement.textContent);
    } else {
        console.warn('tenantApartment element not found in DOM');
    }
    
    // Update photo
    if (photoElement) {
        photoElement.innerHTML = '';
        
        if (user.photo) {
            const img = document.createElement('img');
            img.alt = `${fullName}'s photo`;
            img.style.width = "120px";
            img.style.height = "120px";
            img.style.objectFit = "cover";
            img.style.borderRadius = "8px";
            img.style.border = "1px solid #ccc";
            img.style.marginBottom = "10px";

            const appBasePath = window.location.pathname.includes('/tenant/')
                ? window.location.pathname.split('/tenant/')[0]
                : '';
            img.src = `${appBasePath}/admin/backend/tenants/tenant_photos/${user.photo}`;
            console.log('Attempting to load photo from:', img.src);

            img.onerror = function() {
                console.error('Failed to load image from:', img.src);
                img.remove();
                renderUserInitials(photoElement, fullName);
            };
            
            img.onload = function() {
                console.log('Image loaded successfully from:', img.src);
            };
            
            photoElement.appendChild(img);
        } else {
            console.log('No photo available for user');
            renderUserInitials(photoElement, fullName);
        }
    } else {
        console.warn('photoElement element not found in DOM');
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

    container.innerHTML = `
        <div style="width: 120px; height: 120px; border-radius: 8px; border: 1px solid #ccc; margin-bottom: 10px; background: #f0f4f8; color: #1f2937; display: flex; align-items: center; justify-content: center; font-size: 32px; font-weight: 700;">
            ${escapeHtml(initials)}
        </div>
    `;
}

// ==================== LOGOUT - FIXED VERSION ====================
async function handleLogout() {
    // Double-check currentUser exists
    if (!navbarCurrentUser) {
        console.error('No currentUser available for logout');
        showToast('User session not found', 'error');
        return;
    }
    
    console.log('Logging out user:', navbarCurrentUser.tenant_code);
    
    // Stop notification refresh on logout
    stopNotificationRefresh();
    
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
        if (dialog && dialog.remove) {
            dialog.remove();
        }
    };
    
    // Logout button - FIXED: Pass the correct logout_id
    confirmBtn.onclick = async () => {
        closeDialog();
        
        // Show loading state
        showToast('Logging out...', 'info');
        
        try {
            const logoutData = {
                logout_id: navbarCurrentUser.tenant_code  // This should match what your backend expects
            };
            
            console.log('Sending logout request with data:', logoutData);
            
            const response = await fetch('../backend/authentication/logout.php', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(logoutData)
            });
            
            const data = await response.json();
            console.log('Logout response:', data);
            
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
            showToast(error.message || 'Logout failed. Please try again.', 'error');
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

// ==================== INACTIVITY TIMER (Fixed) ====================
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

async function performAutoLogout() {
    // Stop notification refresh on auto-logout
    stopNotificationRefresh();
    
    if (!navbarCurrentUser) {
        window.location.href = '../pages/index.php';
        return;
    }
    
    try {
        const response = await fetch('../backend/authentication/logout.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                logout_id: navbarCurrentUser.tenant_code,
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
}

// ==================== UTILITY FUNCTIONS ====================
function showToast(message, type = 'info') {
    // Remove existing toasts
    const existingToasts = document.querySelectorAll('.toast-notification');
    existingToasts.forEach(toast => toast.remove());
    
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type}`;
    toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i><span>${escapeHtml(message)}</span>`;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        if (toast && toast.remove) {
            toast.remove();
        }
    }, 3000);
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Force password change functions (keep your existing implementation)
function showForcePasswordModal() {
    const modal = document.getElementById('forcePasswordModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
        
        const newPasswordInput = document.getElementById('forceNewPassword');
        const confirmPasswordInput = document.getElementById('forceConfirmPassword');
        
        if (newPasswordInput) {
            newPasswordInput.addEventListener('input', validateForcePasswordStrength);
        }
        if (confirmPasswordInput) {
            confirmPasswordInput.addEventListener('input', validateForcePasswordStrength);
        }
        
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                showToast('Please change your password to continue', 'warning');
            }
        });
        
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
        modal.style.display = 'none';
    }
}

function validateForcePasswordStrength() {
    const password = document.getElementById('forceNewPassword')?.value || '';
    
    const hasLength = password.length >= 8;
    const hasUpper = /[A-Z]/.test(password);
    const hasLower = /[a-z]/.test(password);
    const hasNumber = /[0-9]/.test(password);
    
    updateRequirement('req-length', hasLength);
    updateRequirement('req-upper', hasUpper);
    updateRequirement('req-lower', hasLower);
    updateRequirement('req-number', hasNumber);
    
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
            sessionStorage.removeItem('needs_password_change');
            sessionStorage.removeItem('temp_user_id');
            closeForcePasswordModal();
            
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

// Make functions globally available
window.currentUser = window.currentUser || null;
window.fetchNavbarUserData = fetchNavbarUserData;
window.updateUserInfo = updateUserInfo;
window.showToast = showToast;
window.handleLogout = handleLogout;
window.validateForcePasswordStrength = validateForcePasswordStrength;
window.submitForcePasswordChange = submitForcePasswordChange;
window.closeForcePasswordModal = closeForcePasswordModal;

// Add styles
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
    
    .toast-notification {
        position: fixed;
        bottom: 20px;
        right: 20px;
        background: white;
        padding: 12px 20px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        display: flex;
        align-items: center;
        gap: 10px;
        z-index: 10001;
        animation: slideInRight 0.3s ease;
    }
    
    .toast-notification.success {
        border-left: 4px solid #10b981;
    }
    
    .toast-notification.error {
        border-left: 4px solid #ef4444;
    }
    
    .toast-notification.warning {
        border-left: 4px solid #f59e0b;
    }
    
    .toast-notification.info {
        border-left: 4px solid #3b82f6;
    }
    
    @keyframes slideInRight {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
`;
document.head.appendChild(dialogStyles);