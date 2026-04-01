// profile.js
// Note: currentUser is already declared in navbar.js, so we don't redeclare it

document.addEventListener('DOMContentLoaded', function() {
    initializeProfile();
});

async function initializeProfile() {
    console.log('Initializing profile...');
    
    // Check if user data is already available
    if (window.currentUser && window.currentUser.firstname) {
        console.log('User data already available:', window.currentUser);
        renderProfile();
    } else {
        console.log('Waiting for user data...');
        
        // Listen for user data loaded event
        window.addEventListener('userDataLoaded', function(e) {
            console.log('UserDataLoaded event received:', e.detail);
            renderProfile();
        });
        
        // Also try to fetch directly if event doesn't fire
        setTimeout(async () => {
            if (!window.currentUser || !window.currentUser.firstname) {
                console.log('Fetching user data directly...');
                await fetchUserDataDirect();
                renderProfile();
            }
        }, 500);
    }
}

async function fetchUserDataDirect() {
    try {
        const response = await fetch('../backend/tenant/fetch_user_data.php');
        const data = await response.json();
        console.log('Direct fetch response:', data);
        
        if (data.success && data.data) {
            window.currentUser = data.data;
            console.log('User data set directly:', window.currentUser);
        } else {
            console.error('Failed to fetch user data:', data);
        }
    } catch (error) {
        console.error('Error fetching user data:', error);
    }
}

function renderProfile() {
    const contentArea = document.getElementById('contentArea');
    if (!contentArea) {
        console.error('contentArea not found');
        return;
    }
    
    console.log('Rendering profile, currentUser:', window.currentUser);
    
    if (!window.currentUser || !window.currentUser.firstname) {
        console.log('No user data available, showing loading state');
        contentArea.innerHTML = `
            <div class="profile-container">
                <div class="loading-spinner">
                    <div class="spinner"></div>
                    <p style="margin-top: 15px; color: #666;">Loading profile data...</p>
                </div>
            </div>
        `;
        return;
    }

    const currentUser = window.currentUser;
     const photoUrl = currentUser.photo
        ? `../../admin/backend/tenants/tenant_photos/${currentUser.photo}`
        : "";

    const html = `
        <div class="profile-container">
            <div class="page-header">
                <h1>My Profile</h1>
                <p>Manage your personal information and account settings</p>
            </div>
            
            <div class="profile-card">
                <div class="profile-header">
                    <div class="profile-avatar" id = "photoElement2">
                        
                        <img src="${
                          photoUrl || ""
                        }" alt="Tenant Photo" id="edit_tenant_photo_preview"
                             style="width:120px;height:120px;object-fit:cover;border-radius:8px;border:1px solid #ccc;margin-bottom:10px;">
                    </div>
                    <div class="profile-name">${escapeHtml(currentUser.firstname || '')} ${escapeHtml(currentUser.lastname || '')}</div>
                    <div class="profile-role">Tenant</div>
                </div>
                
                <div class="tabs">
                    <div class="tab active" onclick="switchTab('personal')">Personal Information</div>
                    <div class="tab" onclick="switchTab('security')">Security</div>
                </div>
                
                <div id="personalTab" class="tab-content active">
                    <form class="profile-form" id="profileForm">
                        <div class="form-row">
                            <div class="form-group">
                                <label>First Name *</label>
                                <input type="text" id="firstname" value="${escapeHtml(currentUser.firstname || '')}" required>
                            </div>
                            <div class="form-group">
                                <label>Last Name *</label>
                                <input type="text" id="lastname" value="${escapeHtml(currentUser.lastname || '')}" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Email Address *</label>
                            <input type="email" id="email" value="${escapeHtml(currentUser.email || '')}" required>
                        </div>
                        <div class="form-group">
                            <label>Phone Number *</label>
                            <input type="tel" id="phone" value="${escapeHtml(currentUser.phone || '')}" required>
                        </div>
                        <div class="form-group">
                            <label>Gender</label>
                            <select id="gender">
                                <option value="">Select gender</option>
                                <option value="Male" ${currentUser.gender === 'Male' ? 'selected' : ''}>Male</option>
                                <option value="Female" ${currentUser.gender === 'Female' ? 'selected' : ''}>Female</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Apartment Number (Read-only)</label>
                            <input type="text" value="${escapeHtml(currentUser.apartment_number || 'Not Assigned')}" readonly>
                        </div>
                        <button type="button" class="btn-save" onclick="updateProfile()">
                            <i class="fas fa-save"></i> Update Profile
                        </button>
                    </form>
                </div>
                
                <div id="securityTab" class="tab-content">
                    <form class="profile-form" id="passwordForm">
                        <div class="form-group">
                            <label>Current Password *</label>
                            <input type="password" id="currentPassword" required>
                        </div>
                        <div class="form-group">
                            <label>New Password *</label>
                            <input type="password" id="newPassword" required onkeyup="validatePassword()">
                        </div>
                        <div class="form-group">
                            <label>Confirm New Password *</label>
                            <input type="password" id="confirmPassword" required onkeyup="validatePasswordMatch()">
                        </div>
                        
                        <div class="password-requirements">
                            <strong>Password Requirements:</strong>
                            <ul>
                                <li id="req-length">At least 8 characters</li>
                                <li id="req-upper">At least one uppercase letter</li>
                                <li id="req-lower">At least one lowercase letter</li>
                                <li id="req-number">At least one number</li>
                                <li id="req-match">Passwords match</li>
                            </ul>
                        </div>
                        
                        <button type="button" class="btn-save" onclick="changePassword()">
                            <i class="fas fa-key"></i> Change Password
                        </button>
                    </form>
                </div>
            </div>
        </div>
    `;
    
    contentArea.innerHTML = html;
}

function switchTab(tab) {
    // Update tab active states
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    
    if (tab === 'personal') {
        document.querySelector('.tab').classList.add('active');
        document.getElementById('personalTab').classList.add('active');
    } else {
        const tabs = document.querySelectorAll('.tab');
        if (tabs[1]) tabs[1].classList.add('active');
        document.getElementById('securityTab').classList.add('active');
    }
}

async function updateProfile() {
    const firstname = document.getElementById('firstname')?.value;
    const lastname = document.getElementById('lastname')?.value;
    const email = document.getElementById('email')?.value;
    const phone = document.getElementById('phone')?.value;
    const gender = document.getElementById('gender')?.value;

    if (!firstname || !lastname || !email || !phone) {
        if (window.showToast) {
            window.showToast('Please fill in all required fields', 'error');
        }
        return;
    }

    try {
        const response = await fetch('../backend/tenant/update_profile.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ firstname, lastname, email, phone, gender })
        });
        const data = await response.json();
        
        if (data.success) {
            if (window.showToast) {
                window.showToast('Profile updated successfully', 'success');
            }
            // Update global current user data
            if (window.currentUser) {
                window.currentUser.firstname = firstname;
                window.currentUser.lastname = lastname;
                window.currentUser.email = email;
                window.currentUser.phone = phone;
                window.currentUser.gender = gender;
            }
            renderProfile();
        } else {
            throw new Error(data.message || 'Update failed');
        }
    } catch (error) {
        console.error('Error updating profile:', error);
        if (window.showToast) {
            window.showToast(error.message, 'error');
        }
    }
}

function validatePassword() {
    const password = document.getElementById('newPassword')?.value || '';
    
    const reqLength = document.getElementById('req-length');
    const reqUpper = document.getElementById('req-upper');
    const reqLower = document.getElementById('req-lower');
    const reqNumber = document.getElementById('req-number');
    
    if (reqLength) {
        reqLength.className = password.length >= 8 ? 'valid' : '';
        reqLength.textContent = password.length >= 8 ? '✓ At least 8 characters' : 'At least 8 characters';
    }
    if (reqUpper) {
        reqUpper.className = /[A-Z]/.test(password) ? 'valid' : '';
        reqUpper.textContent = /[A-Z]/.test(password) ? '✓ At least one uppercase letter' : 'At least one uppercase letter';
    }
    if (reqLower) {
        reqLower.className = /[a-z]/.test(password) ? 'valid' : '';
        reqLower.textContent = /[a-z]/.test(password) ? '✓ At least one lowercase letter' : 'At least one lowercase letter';
    }
    if (reqNumber) {
        reqNumber.className = /[0-9]/.test(password) ? 'valid' : '';
        reqNumber.textContent = /[0-9]/.test(password) ? '✓ At least one number' : 'At least one number';
    }
    
    validatePasswordMatch();
}

function validatePasswordMatch() {
    const password = document.getElementById('newPassword')?.value || '';
    const confirm = document.getElementById('confirmPassword')?.value || '';
    const reqMatch = document.getElementById('req-match');
    
    if (reqMatch) {
        reqMatch.className = (password === confirm && password !== '') ? 'valid' : '';
        reqMatch.textContent = (password === confirm && password !== '') ? '✓ Passwords match' : 'Passwords match';
    }
}

async function changePassword() {
    const currentPassword = document.getElementById('currentPassword')?.value;
    const newPassword = document.getElementById('newPassword')?.value;
    const confirmPassword = document.getElementById('confirmPassword')?.value;

    if (!currentPassword || !newPassword || !confirmPassword) {
        if (window.showToast) {
            window.showToast('Please fill in all fields', 'error');
        }
        return;
    }

    if (newPassword !== confirmPassword) {
        if (window.showToast) {
            window.showToast('New passwords do not match', 'error');
        }
        return;
    }

    if (newPassword.length < 8) {
        if (window.showToast) {
            window.showToast('Password must be at least 8 characters', 'error');
        }
        return;
    }

    try {
        const response = await fetch('../backend/tenant/change_password.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ current_password: currentPassword, new_password: newPassword })
        });
        const data = await response.json();
        
        if (data.success) {
            if (window.showToast) {
                window.showToast('Password changed successfully', 'success');
            }
            const form = document.getElementById('passwordForm');
            if (form) form.reset();
            validatePassword();
        } else {
            throw new Error(data.message || 'Password change failed');
        }
    } catch (error) {
        console.error('Error changing password:', error);
        if (window.showToast) {
            window.showToast(error.message, 'error');
        }
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}