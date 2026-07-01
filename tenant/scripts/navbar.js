// navbar.js - Cleaned up version (removed force password change from navbar)

// ==================== GLOBAL VARIABLES ====================
let navbarCurrentUser = null;
let inactivityTimer = null;
let warningTimer = null;
let isWarningShowing = false;
let navbarUserPromise = null;

// Configuration
const INACTIVITY_TIMEOUT = 20 * 60 * 1000; // 20 minutes
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
    
    // Logout button
    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
        logoutBtn.removeEventListener('click', handleLogoutClick);
        logoutBtn.addEventListener('click', handleLogoutClick);
    }
    
    // Notifications button
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

// ==================== NOTIFICATION BADGE FUNCTIONS ====================

// Silent update - only updates badge count, NO toast
async function updateNotificationBadge() {
    try {
        if (!navbarCurrentUser && !window.currentUser) {
            return;
        }
        
        const response = await fetch('../backend/notification/fetch_notifications.php?limit=1');
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
        const response = await fetch('../backend/notification/fetch_notifications.php');
        const data = await response.json();
        
        if (data.success) {
            const count = data.data?.unread_count || data.data.unread_count || 0;
            const badge = document.getElementById('notificationBadge');
            
            if (badge) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.style.display = count > 0 ? 'flex' : 'none';
            }
            
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
    if (navbarCurrentUser || window.currentUser) {
        await updateNotificationBadge();
        startNotificationRefresh();
    } else {
        window.addEventListener('userDataLoaded', async function() {
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
    if (notificationRefreshInterval) {
        clearInterval(notificationRefreshInterval);
    }
    
    notificationRefreshInterval = setInterval(() => {
        if (navbarCurrentUser || window.currentUser) {
            updateNotificationBadge();
        }
    }, 30000); // Refresh every 30 seconds
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
        showToast('Please wait, loading user data...', 'warning');
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
                
                if (!(data.success && data.data)) {
                    throw new Error(data.message || 'Failed to fetch user data');
                }

                navbarCurrentUser = data.data;
                window.currentUser = navbarCurrentUser;
                
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
    await new Promise(resolve => setTimeout(resolve, 100));
    
    const user = window.currentUser || navbarCurrentUser;

    if (!user) {
        console.error('No currentUser available');
        return;
    }
    
    navbarCurrentUser = user;
    
    const nameElement = document.getElementById('tenantName');
    const apartmentElement = document.getElementById('tenantApartment');
    const photoElement = document.getElementById('photoElement');
    
    const fullName = `${user.firstname || ''} ${user.lastname || ''}`.trim();
    const apartmentNumber = user.apartment_number || user.apartment_code || 'No Apartment';
    
    if (nameElement) {
        nameElement.textContent = fullName || 'Tenant';
    }
    
    if (apartmentElement) {
        apartmentElement.textContent = `Apartment Number: ${apartmentNumber}`;
    }
    
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

            img.onerror = function() {
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

    container.innerHTML = `
        <div style="width: 120px; height: 120px; border-radius: 8px; border: 1px solid #ccc; margin-bottom: 10px; background: #f0f4f8; color: #1f2937; display: flex; align-items: center; justify-content: center; font-size: 32px; font-weight: 700;">
            ${escapeHtml(initials)}
        </div>
    `;
}

// ==================== LOGOUT ====================
async function handleLogout() {
    if (!navbarCurrentUser) {
        console.error('No currentUser available for logout');
        showToast('User session not found', 'error');
        return;
    }
    
    stopNotificationRefresh();
    
    // Remove any existing dialog
    const existingDialog = document.getElementById('customConfirmDialog');
    if (existingDialog) {
        existingDialog.remove();
    }
    
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
    
    confirmBtn.onclick = async () => {
        closeDialog();
        
        showToast('Logging out...', 'info');
        
        try {
            const logoutData = {
                logout_id: navbarCurrentUser.tenant_code
            };
            
            const response = await fetch('../backend/authentication/logout.php', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(logoutData)
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
            showToast(error.message || 'Logout failed. Please try again.', 'error');
        }
    };
    
    cancelBtn.onclick = () => {
        closeDialog();
    };
    
    closeBtn.onclick = () => {
        closeDialog();
    };
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
        'Session Timeout Warning',
        'You have been inactive for a while. Do you want to stay logged in?',
        () => {
            clearWarningTimer();
            isWarningShowing = false;
            resetInactivityTimer();
            showToast('Session extended', 'success');
        },
        () => {
            performAutoLogout();
        }
    );
}

async function performAutoLogout() {
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

// Make functions globally available
window.currentUser = window.currentUser || null;
window.fetchNavbarUserData = fetchNavbarUserData;
window.updateUserInfo = updateUserInfo;
window.showToast = showToast;
window.handleLogout = handleLogout;

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