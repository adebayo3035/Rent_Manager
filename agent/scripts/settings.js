// settings.js
let settings = {
    notifications: {
        email: true,
        push: true,
        maintenance: true,
        payment: true,
        newsletter: false
    },
    preferences: {
        language: 'en',
        date_format: 'MM/DD/YYYY',
        theme: 'light'
    },
    privacy: {
        profile_visible: true,
        share_contact: false
    }
};

document.addEventListener('DOMContentLoaded', function() {
    initializeSettings();
});

async function initializeSettings() {
    // Wait for user data to be loaded from navbar
    if (window.currentUser) {
        await loadSettings();
        renderSettings();
    } else {
        window.addEventListener('userDataLoaded', async function() {
            await loadSettings();
            renderSettings();
        });
        
        setTimeout(async () => {
            if (!window.currentUser) {
                await loadSettings();
                renderSettings();
            }
        }, 1000);
    }
}

async function loadSettings() {
    try {
        const response = await fetch('../backend/tenant/fetch_settings.php');
        const data = await response.json();
        
        if (data.success && data.data) {
            settings = { ...settings, ...data.data };
            console.log('Settings loaded:', settings);
        }
    } catch (error) {
        console.error('Error loading settings:', error);
        if (window.showToast) {
            window.showToast('Failed to load settings', 'error');
        }
    }
}

function renderSettings() {
    const contentArea = document.getElementById('contentArea');
    if (!contentArea) return;

    const html = `
        <div class="settings-container">
            <div class="page-header">
                <h1>Settings</h1>
                <p>Manage your account preferences and notification settings</p>
            </div>
            
            <div class="settings-grid">
                <!-- Notification Settings -->
                <div class="settings-card">
                    <div class="card-header">
                        <i class="fas fa-bell"></i>
                        <h2>Notification Preferences</h2>
                    </div>
                    <div class="card-content">
                        <div class="toggle-group">
                            <div class="toggle-info">
                                <h4>Email Notifications</h4>
                                <p>Receive notifications via email</p>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" id="notifEmail" ${settings.notifications.email ? 'checked' : ''}>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        
                        <div class="toggle-group">
                            <div class="toggle-info">
                                <h4>Push Notifications</h4>
                                <p>Receive push notifications in browser</p>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" id="notifPush" ${settings.notifications.push ? 'checked' : ''}>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        
                        <div class="toggle-group">
                            <div class="toggle-info">
                                <h4>Maintenance Updates</h4>
                                <p>Get notified about maintenance requests</p>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" id="notifMaintenance" ${settings.notifications.maintenance ? 'checked' : ''}>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        
                        <div class="toggle-group">
                            <div class="toggle-info">
                                <h4>Payment Reminders</h4>
                                <p>Receive payment due reminders</p>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" id="notifPayment" ${settings.notifications.payment ? 'checked' : ''}>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        
                        <div class="toggle-group">
                            <div class="toggle-info">
                                <h4>Newsletter</h4>
                                <p>Receive property news and updates</p>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" id="notifNewsletter" ${settings.notifications.newsletter ? 'checked' : ''}>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        
                        <div class="form-actions">
                            <button class="btn-save" onclick="saveNotificationSettings()">
                                <i class="fas fa-save"></i> Save Notification Settings
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Preferences -->
                <div class="settings-card">
                    <div class="card-header">
                        <i class="fas fa-globe"></i>
                        <h2>Preferences</h2>
                    </div>
                    <div class="card-content">
                        <div class="form-group">
                            <label>Language</label>
                            <select id="prefLanguage">
                                <option value="en" ${settings.preferences.language === 'en' ? 'selected' : ''}>English</option>
                                <option value="es" ${settings.preferences.language === 'es' ? 'selected' : ''}>Spanish</option>
                                <option value="fr" ${settings.preferences.language === 'fr' ? 'selected' : ''}>French</option>
                                <option value="pt" ${settings.preferences.language === 'pt' ? 'selected' : ''}>Portuguese</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Date Format</label>
                            <select id="prefDateFormat">
                                <option value="MM/DD/YYYY" ${settings.preferences.date_format === 'MM/DD/YYYY' ? 'selected' : ''}>MM/DD/YYYY</option>
                                <option value="DD/MM/YYYY" ${settings.preferences.date_format === 'DD/MM/YYYY' ? 'selected' : ''}>DD/MM/YYYY</option>
                                <option value="YYYY-MM-DD" ${settings.preferences.date_format === 'YYYY-MM-DD' ? 'selected' : ''}>YYYY-MM-DD</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Theme</label>
                            <div class="radio-group">
                                <label class="radio-option">
                                    <input type="radio" name="theme" value="light" ${settings.preferences.theme === 'light' ? 'checked' : ''}>
                                    <span>Light</span>
                                </label>
                                <label class="radio-option">
                                    <input type="radio" name="theme" value="dark" ${settings.preferences.theme === 'dark' ? 'checked' : ''}>
                                    <span>Dark</span>
                                </label>
                                <label class="radio-option">
                                    <input type="radio" name="theme" value="auto" ${settings.preferences.theme === 'auto' ? 'checked' : ''}>
                                    <span>Auto (Follow System)</span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button class="btn-save" onclick="savePreferences()">
                                <i class="fas fa-save"></i> Save Preferences
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Privacy Settings -->
                <div class="settings-card">
                    <div class="card-header">
                        <i class="fas fa-lock"></i>
                        <h2>Privacy Settings</h2>
                    </div>
                    <div class="card-content">
                        <div class="toggle-group">
                            <div class="toggle-info">
                                <h4>Profile Visibility</h4>
                                <p>Allow other tenants to see your profile</p>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" id="privacyProfile" ${settings.privacy.profile_visible ? 'checked' : ''}>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        
                        <div class="toggle-group">
                            <div class="toggle-info">
                                <h4>Share Contact Information</h4>
                                <p>Allow property management to share your contact with service providers</p>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" id="privacyContact" ${settings.privacy.share_contact ? 'checked' : ''}>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        
                        <div class="form-actions">
                            <button class="btn-save" onclick="savePrivacySettings()">
                                <i class="fas fa-save"></i> Save Privacy Settings
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Account Management -->
                <div class="settings-card">
                    <div class="card-header">
                        <i class="fas fa-user-cog"></i>
                        <h2>Account Management</h2>
                    </div>
                    <div class="card-content">
                        <div class="form-group">
                            <label>Session Timeout</label>
                            <select id="sessionTimeout">
                                <option value="15">15 minutes</option>
                                <option value="30">30 minutes</option>
                                <option value="60">1 hour</option>
                                <option value="120">2 hours</option>
                            </select>
                            <p style="font-size: 12px; color: #666; margin-top: 5px;">
                                Automatically log out after period of inactivity
                            </p>
                        </div>
                        
                        <div class="form-actions" style="gap: 10px;">
                            <button class="btn-secondary" onclick="downloadData()">
                                <i class="fas fa-download"></i> Download My Data
                            </button>
                            <button class="btn-danger" onclick="deactivateAccount()">
                                <i class="fas fa-trash-alt"></i> Deactivate Account
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    contentArea.innerHTML = html;
}

// Save Notification Settings
async function saveNotificationSettings() {
    const notifications = {
        email: document.getElementById('notifEmail')?.checked || false,
        push: document.getElementById('notifPush')?.checked || false,
        maintenance: document.getElementById('notifMaintenance')?.checked || false,
        payment: document.getElementById('notifPayment')?.checked || false,
        newsletter: document.getElementById('notifNewsletter')?.checked || false
    };
    
    try {
        const response = await fetch('../backend/tenant/update_settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                section: 'notifications',
                data: notifications 
            })
        });
        const data = await response.json();
        
        if (data.success) {
            settings.notifications = notifications;
            if (window.showToast) {
                window.showToast('Notification settings saved successfully', 'success');
            }
        } else {
            throw new Error(data.message || 'Failed to save settings');
        }
    } catch (error) {
        console.error('Error saving notification settings:', error);
        if (window.showToast) {
            window.showToast(error.message, 'error');
        }
    }
}

// Save Preferences
async function savePreferences() {
    const preferences = {
        language: document.getElementById('prefLanguage')?.value || 'en',
        date_format: document.getElementById('prefDateFormat')?.value || 'MM/DD/YYYY',
        theme: document.querySelector('input[name="theme"]:checked')?.value || 'light'
    };
    
    try {
        const response = await fetch('../backend/tenant/update_settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                section: 'preferences',
                data: preferences 
            })
        });
        const data = await response.json();
        
        if (data.success) {
            settings.preferences = preferences;
            applyTheme(preferences.theme);
            if (window.showToast) {
                window.showToast('Preferences saved successfully', 'success');
            }
        } else {
            throw new Error(data.message || 'Failed to save preferences');
        }
    } catch (error) {
        console.error('Error saving preferences:', error);
        if (window.showToast) {
            window.showToast(error.message, 'error');
        }
    }
}

// Save Privacy Settings
async function savePrivacySettings() {
    const privacy = {
        profile_visible: document.getElementById('privacyProfile')?.checked || false,
        share_contact: document.getElementById('privacyContact')?.checked || false
    };
    
    try {
        const response = await fetch('../backend/tenant/update_settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                section: 'privacy',
                data: privacy 
            })
        });
        const data = await response.json();
        
        if (data.success) {
            settings.privacy = privacy;
            if (window.showToast) {
                window.showToast('Privacy settings saved successfully', 'success');
            }
        } else {
            throw new Error(data.message || 'Failed to save privacy settings');
        }
    } catch (error) {
        console.error('Error saving privacy settings:', error);
        if (window.showToast) {
            window.showToast(error.message, 'error');
        }
    }
}

// Apply Theme
function applyTheme(theme) {
    if (theme === 'dark') {
        document.body.classList.add('dark-theme');
    } else if (theme === 'light') {
        document.body.classList.remove('dark-theme');
    } else if (theme === 'auto') {
        const isDarkMode = window.matchMedia('(prefers-color-scheme: dark)').matches;
        if (isDarkMode) {
            document.body.classList.add('dark-theme');
        } else {
            document.body.classList.remove('dark-theme');
        }
    }
}

// Download User Data
async function downloadData() {
    if (window.showToast) {
        window.showToast('Preparing your data for download...', 'info');
    }
    
    try {
        const response = await fetch('../backend/tenant/export_data.php');
        const blob = await response.blob();
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `tenant_data_${window.currentUser?.tenant_code}.json`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
        
        if (window.showToast) {
            window.showToast('Data downloaded successfully', 'success');
        }
    } catch (error) {
        console.error('Error downloading data:', error);
        if (window.showToast) {
            window.showToast('Failed to download data', 'error');
        }
    }
}

// Deactivate Account
async function deactivateAccount() {
    if (!confirm('Are you sure you want to deactivate your account? This action can be reversed by contacting support.')) {
        return;
    }
    
    try {
        const response = await fetch('../backend/tenant/deactivate_account.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                tenant_code: window.currentUser?.tenant_code 
            })
        });
        const data = await response.json();
        
        if (data.success) {
            if (window.showToast) {
                window.showToast('Account deactivated successfully', 'success');
            }
            setTimeout(() => {
                window.location.href = '../pages/index.php';
            }, 2000);
        } else {
            throw new Error(data.message || 'Failed to deactivate account');
        }
    } catch (error) {
        console.error('Error deactivating account:', error);
        if (window.showToast) {
            window.showToast(error.message, 'error');
        }
    }
}