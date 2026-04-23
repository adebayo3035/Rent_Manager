// dashboard.js
let dashboardData = null;
let currentUser = null;

document.addEventListener('DOMContentLoaded', function() {
    initializeDashboard();
});

async function initializeDashboard() {
    try {
        // Fetch user data first
        await fetchUserData();
        
        // Then fetch dashboard data
        await fetchDashboardData();
        
        // Render the dashboard
        renderDashboard();
    } catch (error) {
        console.error('Error initializing dashboard:', error);
        renderErrorState();
    }
}

async function fetchUserData() {
    try {
        const response = await fetch('../backend/tenant/fetch_user_data.php');
        const data = await response.json();
        
        if (data.success && data.data) {
            currentUser = data.data;
            window.currentUser = currentUser;
            
            // Dispatch event for other components
            window.dispatchEvent(new CustomEvent('userDataLoaded', { detail: currentUser }));
            console.log('User data loaded:', currentUser);
            return data;
        } else {
            throw new Error(data.message || 'Failed to fetch user data');
        }
    } catch (error) {
        console.error('Error fetching user data:', error);
        throw error;
    }
}

async function fetchDashboardData() {
    try {
        const response = await fetch('../backend/tenant/fetch_dashboard_data.php');
        const data = await response.json();
        
        console.log('Dashboard data response:', data);
        
        if (data.success && data.data) {
            dashboardData = data.data;
            console.log('Dashboard data loaded:', dashboardData);
            return data;
        } else {
            throw new Error(data.message || 'Failed to fetch dashboard data');
        }
    } catch (error) {
        console.error('Error fetching dashboard data:', error);
        if (window.showToast) {
            window.showToast('Failed to load dashboard statistics', 'error');
        }
        throw error;
    }
}

async function createMaintenanceRequest(requestData) {
    try {
        const response = await fetch('../backend/maintenance/create_maintenance_request.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(requestData)
        });
        const data = await response.json();
        
        if (data.success) {
            if (window.showToast) {
                window.showToast('Maintenance request submitted successfully', 'success');
            }
            closeModal('maintenanceModal');
            await fetchDashboardData(); // Refresh data
            renderDashboard();
            return data;
        } else {
            throw new Error(data.message || 'Failed to submit request');
        }
    } catch (error) {
        console.error('Error creating maintenance request:', error);
        if (window.showToast) {
            window.showToast(error.message, 'error');
        }
        throw error;
    }
}

function renderErrorState() {
    const contentArea = document.getElementById('contentArea');
    if (!contentArea) return;
    
    contentArea.innerHTML = `
        <div class="dashboard-container">
            <div class="empty-state">
                <i class="fas fa-chart-line"></i>
                <h3>Unable to Load Dashboard</h3>
                <p>Please refresh the page or contact support if the issue persists.</p>
                <button class="btn-primary" onclick="location.reload()">Refresh Page</button>
            </div>
        </div>
    `;
}

function renderDashboard() {
    const contentArea = document.getElementById('contentArea');
    if (!contentArea) return;
    
    if (!dashboardData || !currentUser) {
        renderErrorState();
        return;
    }

    const html = `
        <div class="dashboard-container">
            <!-- Welcome Section -->
            <div class="welcome-section">
                <h1>Welcome back, ${escapeHtml(currentUser.firstname || 'Tenant')} ${escapeHtml(currentUser.lastname || '')}!</h1>
                <p>Here's what's happening with your apartment today.</p>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-building"></i>
                    </div>
                    <div class="stat-info">
                        <h3>${escapeHtml(dashboardData.property_name || 'N/A')}</h3>
                        <p>Your Property</p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-door-open"></i>
                    </div>
                    <div class="stat-info">
                        <h3>${escapeHtml(dashboardData.apartment_number || 'Not Assigned')}</h3>
                        <p>Apartment Number</p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-tools"></i>
                    </div>
                    <div class="stat-info">
                        <h3>${dashboardData.active_requests || 0}</h3>
                        <p>Active Maintenance</p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="stat-info">
                        <h3>${dashboardData.days_remaining || 0} days</h3>
                        <p>Lease Remaining</p>
                    </div>
                </div>
            </div>

            <!-- Current Rent Information -->
            <div class="payment-section">
                <div class="section-header">
                    <h2>Current Rent Information</h2>
                    <span class="btn-link" onclick="navigateToPage('apartment')">View More →</span>
                </div>
                <div class="payment-card">
                    <div class="payment-details">
                        <div class="payment-amount">
                            <label>Annual Rent</label>
                            <span class="amount">₦${formatNumber(dashboardData.annual_rent || 0)}</span>
                        </div>
                        <div class="payment-amount">
                            <label>Payment per Period</label>
                            <span class="amount">₦${formatNumber(dashboardData.payment_amount_per_period || 0)}</span>
                        </div>
                        <div class="payment-date">
                            <label>Payment Frequency</label>
                            <span class="date">${escapeHtml(dashboardData.payment_frequency || 'Monthly')}</span>
                        </div>
                    </div>

                    <div class="payment-details">
                        <div class="payment-amount">
                            <label>Total Paid</label>
                            <span class="amount success">₦${formatNumber(dashboardData.total_paid || 0)}</span>
                        </div>
                        <div class="payment-amount">
                            <label>Remaining Balance</label>
                            <span class="amount ${dashboardData.rent_balance > 0 ? 'warning' : 'success'}">₦${formatNumber(dashboardData.rent_balance || 0)}</span>
                        </div>
                        <div class="payment-date">
                            <label>Lease Status</label>
                            <span class="badge ${dashboardData.is_lease_fully_paid ? 'badge-success' : 'badge-info'}">
                                ${dashboardData.is_lease_fully_paid ? 'Fully Paid' : 'Active'}
                            </span>
                        </div>
                    </div>

                    ${dashboardData.current_period ? `
                    <div class="payment-details">
                        <div class="payment-date">
                            <label>Current Period</label>
                            <span class="date">${escapeHtml(dashboardData.current_period.period || 'N/A')}</span>
                        </div>
                        <div class="payment-date">
                            <label>Period Start</label>
                            <span class="date">${formatDate(dashboardData.current_period.start_date)}</span>
                        </div>
                        <div class="payment-date">
                            <label>Period End</label>
                            <span class="date">${formatDate(dashboardData.current_period.end_date)}</span>
                        </div>
                        ${dashboardData.current_period.is_paid ? `
                        <div class="payment-status status-paid">
                            <i class="fas fa-check-circle"></i> Paid
                        </div>
                        ` : `
                        <div class="payment-status status-pending">
                            <i class="fas fa-clock"></i> Pending
                        </div>
                        `}
                    </div>
                    ` : ''}
                </div>
            </div>

            <!-- Upcoming Payment Section -->
            <div class="payment-section">
                <div class="section-header">
                    <h2>Next Payment</h2>
                    <span class="btn-link" onclick="navigateToPage('payments')">View All Payments →</span>
                </div>
                ${dashboardData.next_payment ? `
                <div class="payment-card">
                    <div class="payment-details">
                        <div class="payment-amount">
                            <label>Amount Due</label>
                            <span class="amount">₦${formatNumber(dashboardData.next_payment.amount || 0)}</span>
                        </div>
                        <div class="payment-date">
                            <label>Payment Period</label>
                            <span class="date">${escapeHtml(dashboardData.next_payment.period || 'N/A')}</span>
                        </div>
                        <div class="payment-date">
                            <label>Due Date</label>
                            <span class="date ${dashboardData.next_payment.is_overdue ? 'overdue' : ''}">${formatDate(dashboardData.next_payment.due_date)}</span>
                        </div>
                        ${dashboardData.next_payment.is_overdue ? `
                        <div class="payment-status status-overdue">
                            <i class="fas fa-exclamation-triangle"></i> Overdue
                        </div>
                        ` : ''}
                    </div>
                    <button class="btn-primary" onclick="makePayment()" ${dashboardData.is_lease_fully_paid ? 'disabled' : ''}>
                        ${dashboardData.is_lease_fully_paid ? 'Lease Fully Paid' : 'Make Payment'}
                    </button>
                </div>
                ` : dashboardData.is_lease_fully_paid ? `
                <div class="payment-card">
                    <div class="payment-details">
                        <div class="payment-amount">
                            <i class="fas fa-check-circle" style="color: #10b981; font-size: 48px;"></i>
                            <h3>Congratulations!</h3>
                            <p>Your lease has been fully paid.</p>
                        </div>
                    </div>
                </div>
                ` : `
                <div class="payment-card">
                    <div class="payment-details">
                        <div class="payment-amount">
                            <p>No upcoming payments scheduled.</p>
                        </div>
                    </div>
                </div>
                `}
            </div>

            <!-- Security Deposit Section -->
            ${dashboardData.security_deposit > 0 ? `
            <div class="payment-section">
                <div class="section-header">
                    <h2>Security Deposit</h2>
                </div>
                <div class="payment-card">
                    <div class="payment-details">
                        <div class="payment-amount">
                            <label>Deposit Amount</label>
                            <span class="amount">₦${formatNumber(dashboardData.security_deposit)}</span>
                        </div>
                        ${dashboardData.security_deposit_paid > 0 ? `
                        <div class="payment-amount">
                            <label>Paid Amount</label>
                            <span class="amount success">₦${formatNumber(dashboardData.security_deposit_paid)}</span>
                        </div>
                        <div class="payment-date">
                            <label>Payment Date</label>
                            <span class="date">${formatDate(dashboardData.security_deposit_payment_date)}</span>
                        </div>
                        <div class="payment-status status-paid">
                            <i class="fas fa-check-circle"></i> Paid
                        </div>
                        ` : `
                        <div class="payment-status status-pending">
                            <i class="fas fa-clock"></i> Pending
                        </div>
                        `}
                    </div>
                </div>
            </div>
            ` : ''}

            <!-- Payment Summary Section -->
            <div class="payment-section">
                <div class="section-header">
                    <h2>Payment Summary</h2>
                </div>
                <div class="payment-card">
                    <div class="payment-details">
                        <div class="payment-amount">
                            <label>Total Periods</label>
                            <span class="amount">${dashboardData.total_periods || 0}</span>
                        </div>
                        <div class="payment-amount">
                            <label>Paid Periods</label>
                            <span class="amount success">${dashboardData.paid_periods_count || 0}</span>
                        </div>
                        <div class="payment-amount">
                            <label>Pending Periods</label>
                            <span class="amount warning">${dashboardData.pending_periods_count || 0}</span>
                        </div>
                    </div>
                    
                    ${dashboardData.paid_periods && dashboardData.paid_periods.length > 0 ? `
                    <div class="payment-periods-list">
                        <label>Paid Periods:</label>
                        <div class="periods-grid">
                            ${dashboardData.paid_periods.slice(-3).map(period => `
                                <div class="period-badge">
                                    ${escapeHtml(period.period)}
                                </div>
                            `).join('')}
                            ${dashboardData.paid_periods_count > 3 ? `
                            <div class="period-badge more" onclick="navigateToPage('payments')">
                                +${dashboardData.paid_periods_count - 3} more
                            </div>
                            ` : ''}
                        </div>
                    </div>
                    ` : ''}
                </div>
            </div>

            <!-- ==================== NEW: RENEWAL SECTION ==================== -->
            ${dashboardData.can_renew ? `
            <div class="renewal-section">
                <div class="section-header">
                    <h2><i class="fas fa-sync-alt"></i> Lease Renewal</h2>
                </div>
                <div class="renewal-card">
                    <div class="renewal-icon">
                        <i class="fas fa-calendar-plus"></i>
                    </div>
                    <div class="renewal-content">
                        <h3>Ready for a New Lease Cycle!</h3>
                        <p>${escapeHtml(dashboardData.renewal_message || 'Your lease has ended. You can start a new lease cycle.')}</p>
                        
                        <div class="renewal-details">
                            <div class="detail-row">
                                <span class="detail-label">Previous Annual Rent:</span>
                                <span class="detail-value">₦${formatNumber(dashboardData.annual_rent)}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">New Annual Rent:</span>
                                <span class="detail-value ${dashboardData.new_cycle_rent_amount > dashboardData.annual_rent ? 'text-warning' : 'text-success'}">
                                    ₦${formatNumber(dashboardData.new_cycle_rent_amount)}
                                    ${dashboardData.new_cycle_rent_amount > dashboardData.annual_rent ? 
                                        `<small>(+₦${formatNumber(dashboardData.new_cycle_rent_amount - dashboardData.annual_rent)} increase)</small>` : 
                                        dashboardData.new_cycle_rent_amount < dashboardData.annual_rent ?
                                        `<small>(₦${formatNumber(dashboardData.annual_rent - dashboardData.new_cycle_rent_amount)} decrease)</small>` : ''}
                                </span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Payment Frequency:</span>
                                <span class="detail-value">${escapeHtml(dashboardData.payment_frequency)}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">New Payment per Period:</span>
                                <span class="detail-value">₦${formatNumber(dashboardData.new_cycle_rent_amount / getPeriodsPerYear(dashboardData.payment_frequency))}</span>
                            </div>
                            ${dashboardData.new_cycle_security_deposit ? `
                            <div class="detail-row">
                                <span class="detail-label">Security Deposit:</span>
                                <span class="detail-value">₦${formatNumber(dashboardData.new_cycle_security_deposit)}</span>
                            </div>
                            ` : ''}
                        </div>
                        
                        <button class="btn-renewal" onclick="startNewLeaseCycle()">
                            <i class="fas fa-check-circle"></i> Start New Lease Cycle
                        </button>
                        <p class="renewal-note">By starting a new lease cycle, you agree to the new rent amount shown above.</p>
                    </div>
                </div>
            </div>
            ` : dashboardData.is_lease_fully_paid && !dashboardData.lease_has_ended ? `
            <div class="renewal-section">
                <div class="renewal-card info-card">
                    <div class="renewal-icon">
                        <i class="fas fa-hourglass-half"></i>
                    </div>
                    <div class="renewal-content">
                        <h3>Lease Fully Paid!</h3>
                        <p>${escapeHtml(dashboardData.renewal_message || 'Your lease is fully paid but has not ended yet.')}</p>
                        <div class="renewal-details">
                            <div class="detail-row">
                                <span class="detail-label">Lease End Date:</span>
                                <span class="detail-value">${formatDate(dashboardData.lease_end_date)}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Days Remaining:</span>
                                <span class="detail-value">${dashboardData.days_remaining} days</span>
                            </div>
                        </div>
                        <p class="renewal-note">You can start a new lease cycle on or after ${formatDate(dashboardData.lease_end_date)}.</p>
                    </div>
                </div>
            </div>
            ` : ''}

            <!-- Quick Actions -->
            <div class="quick-actions">
                <div class="section-header">
                    <h2>Quick Actions</h2>
                </div>
                <div class="actions-grid">
                    <div class="action-card" onclick="openMaintenanceModal()">
                        <i class="fas fa-tools"></i>
                        <span>Report Issue</span>
                    </div>
                    <div class="action-card" onclick="navigateToPage('apartment')">
                        <i class="fas fa-home"></i>
                        <span>View Apartment</span>
                    </div>
                    <div class="action-card" onclick="navigateToPage('documents')">
                        <i class="fas fa-file-alt"></i>
                        <span>Documents</span>
                    </div>
                    <div class="action-card" onclick="contactAgent()">
                        <i class="fas fa-phone-alt"></i>
                        <span>Contact Agent</span>
                    </div>
                </div>
            </div>

            <!-- Recent Maintenance Requests -->
            <div class="recent-requests">
                <div class="section-header">
                    <h2>Recent Maintenance Requests</h2>
                    <span class="btn-link" onclick="navigateToPage('maintenance')">View All →</span>
                </div>
                ${renderRecentRequests(dashboardData.recent_requests || [])}
            </div>
        </div>
    `;

    contentArea.innerHTML = html;
}

function renderRecentRequests(requests) {
    if (!requests || requests.length === 0) {
        return `
            <div class="empty-state">
                <i class="fas fa-clipboard-list"></i>
                <p>No maintenance requests yet</p>
                <button class="btn-secondary" onclick="openMaintenanceModal()">Create Request</button>
            </div>
        `;
    }

    return `
        <div class="requests-list">
            ${requests.map(request => `
                <div class="request-item">
                    <div class="request-info">
                        <span class="request-type">${escapeHtml(request.issue_type)}</span>
                        <span class="request-date">${formatDate(request.created_at)}</span>
                    </div>
                    <div class="request-status status-${request.status}">
                        ${formatStatus(request.status)}
                    </div>
                </div>
            `).join('')}
        </div>
    `;
}

// ==================== UTILITY FUNCTIONS ====================
function formatNumber(value) {
    if (!value || value === '0') return '0.00';
    return new Intl.NumberFormat('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value);
}

function formatDate(dateString) {
    if (!dateString) return 'N/A';
    try {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
    } catch (e) {
        return dateString;
    }
}

function formatStatus(status) {
    const statusMap = {
        'pending': 'Pending',
        'in_progress': 'In Progress',
        'resolved': 'Resolved',
        'cancelled': 'Cancelled'
    };
    return statusMap[status] || status;
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ==================== MODAL FUNCTIONS ====================
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('active');
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
        const form = document.getElementById('maintenanceForm');
        if (form) form.reset();
    }
}

function showConfirmModal({
    title = 'Confirm Action',
    message = 'Are you sure you want to continue?',
    confirmText = 'Confirm',
    cancelText = 'Cancel',
    variant = 'warning'
}) {
    return new Promise((resolve) => {
        let modal = document.getElementById('customConfirmModal');

        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'customConfirmModal';
            modal.className = 'custom-confirm-overlay';
            modal.innerHTML = `
                <div class="custom-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="customConfirmTitle">
                    <div class="custom-confirm-icon">
                        <i class="fas fa-circle-exclamation"></i>
                    </div>
                    <div class="custom-confirm-content">
                        <h3 id="customConfirmTitle"></h3>
                        <p id="customConfirmMessage"></p>
                    </div>
                    <div class="custom-confirm-actions">
                        <button type="button" class="btn-secondary custom-confirm-cancel">Cancel</button>
                        <button type="button" class="custom-confirm-ok">Confirm</button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
        }

        const dialog = modal.querySelector('.custom-confirm-dialog');
        const icon = modal.querySelector('.custom-confirm-icon i');
        const titleElement = modal.querySelector('#customConfirmTitle');
        const messageElement = modal.querySelector('#customConfirmMessage');
        const confirmButton = modal.querySelector('.custom-confirm-ok');
        const cancelButton = modal.querySelector('.custom-confirm-cancel');

        const iconMap = {
            warning: 'fa-circle-exclamation',
            success: 'fa-circle-check',
            info: 'fa-circle-info',
            danger: 'fa-triangle-exclamation'
        };

        dialog.dataset.variant = variant;
        icon.className = `fas ${iconMap[variant] || iconMap.warning}`;
        titleElement.textContent = title;
        messageElement.innerHTML = escapeHtml(message).replace(/\n/g, '<br>');
        confirmButton.textContent = confirmText;
        cancelButton.textContent = cancelText;

        const handleKeydown = (event) => {
            if (event.key === 'Escape') {
                cleanup(false);
            }
        };

        const cleanup = (result) => {
            modal.classList.remove('active');
            confirmButton.onclick = null;
            cancelButton.onclick = null;
            modal.onclick = null;
            document.removeEventListener('keydown', handleKeydown);
            resolve(result);
        };

        confirmButton.onclick = () => cleanup(true);
        cancelButton.onclick = () => cleanup(false);
        modal.onclick = (event) => {
            if (event.target === modal) {
                cleanup(false);
            }
        };

        modal.classList.add('active');
        document.addEventListener('keydown', handleKeydown);
        confirmButton.focus();
    });
}

// ==================== ACTION FUNCTIONS ====================
function openMaintenanceModal() {
    openModal('maintenanceModal');
}

async function submitMaintenanceRequest() {
    const issueType = document.getElementById('issueType')?.value;
    const priority = document.getElementById('priority')?.value;
    const description = document.getElementById('description')?.value;

    if (!issueType || !priority || !description) {
        if (window.showToast) {
            window.showToast('Please fill in all fields', 'error');
        }
        return;
    }

    const requestData = {
        issue_type: issueType,
        priority: priority,
        description: description,
        tenant_code: currentUser?.tenant_code
    };

    await createMaintenanceRequest(requestData);
}

function makePayment() {
    if (dashboardData?.is_lease_fully_paid) {
        if (window.showToast) {
            window.showToast('Your lease is already fully paid!', 'info');
        }
        return;
    }
    
    if (window.showToast) {
        window.showToast('Redirecting to payment gateway...', 'info');
    }
    navigateToPage('payments');
}

function contactAgent() {
    if (dashboardData?.agent_phone) {
        window.location.href = `tel:${dashboardData.agent_phone}`;
    } else if (dashboardData?.agent_email) {
        window.location.href = `mailto:${dashboardData.agent_email}`;
    } else {
        if (window.showToast) {
            window.showToast('Agent contact information not available', 'warning');
        }
    }
}

function navigateToPage(page) {
    // Map page names to actual URLs
    const pageUrls = {
        'dashboard': 'dashboard.php',
        'apartment': 'apartment.php',
        'maintenance': 'maintenance.php',
        'payments': 'payments.php',
        'documents': 'documents.php',
        'profile': 'profile.php',
        'settings': 'settings.php'
    };
    
    const url = pageUrls[page];
    if (url) {
        window.location.href = url;
    } else {
        if (window.showToast) {
            window.showToast(`Page "${page}" not found`, 'error');
        }
    }
}

// ==================== RENEWAL FUNCTIONS ====================

function getPeriodsPerYear(frequency) {
    switch(frequency) {
        case 'Monthly': return 12;
        case 'Quarterly': return 4;
        case 'Semi-Annually': return 2;
        case 'Annually': return 1;
        default: return 12;
    }
}

async function startNewLeaseCycle() {
    // Show confirmation dialog
    const confirmed = confirm(
        "⚠️ IMPORTANT: Starting a new lease cycle will:\n\n" +
        "• End your current lease\n" +
        "• Start a new 12-month lease cycle\n" +
        "• Apply the new rent amount shown above\n" +
        "• Create a new payment schedule\n\n" +
        "Do you want to proceed?"
    );
    
    if (!confirmed) {
        return;
    }
    
    // Show loading state
    const renewalBtn = document.querySelector('.btn-renewal');
    const originalText = renewalBtn?.innerHTML || 'Start New Lease Cycle';
    if (renewalBtn) {
        renewalBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        renewalBtn.disabled = true;
    }
    
    try {
        const response = await fetch('../backend/tenant/initiate_new_lease_cycle.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' }
        });
        
        const data = await response.json();
        
        if (data.success) {
            if (window.showToast) {
                window.showToast(data.data.message, 'success');
            }
            
            // Refresh dashboard to show new cycle
            await fetchDashboardData();
            renderDashboard();
            
            // Optional: Redirect to payments page to make first payment
            setTimeout(() => {
                if (confirm("New lease cycle created! Would you like to make your first payment now?")) {
                    navigateToPage('payments');
                }
            }, 1000);
        } else {
            throw new Error(data.message);
        }
    } catch (error) {
        console.error('Error starting new lease cycle:', error);
        if (window.showToast) {
            window.showToast(error.message, 'error');
        }
    } finally {
        if (renewalBtn) {
            renewalBtn.innerHTML = originalText;
            renewalBtn.disabled = false;
        }
    }
}

async function startNewLeaseCycle() {
    const confirmed = await showConfirmModal({
        title: 'Start New Lease Cycle?',
        message:
            "IMPORTANT: Starting a new lease cycle will:\n\n" +
            "• End your current lease\n" +
            "• Start a new 12-month lease cycle\n" +
            "• Apply the new rent amount shown above\n" +
            "• Create a new payment schedule\n\n" +
            "Do you want to proceed?",
        confirmText: 'Yes, Proceed',
        cancelText: 'Not Now',
        variant: 'warning'
    });
    
    if (!confirmed) {
        return;
    }
    
    const renewalBtn = document.querySelector('.btn-renewal');
    const originalText = renewalBtn?.innerHTML || 'Start New Lease Cycle';
    if (renewalBtn) {
        renewalBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        renewalBtn.disabled = true;
    }
    
    try {
        const response = await fetch('../backend/tenant/initiate_new_lease_cycle.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' }
        });
        
        const data = await response.json();
        
        if (data.success) {
            if (window.showToast) {
                window.showToast(data.data.message, 'success');
            }
            
            await fetchDashboardData();
            renderDashboard();
            
            setTimeout(async () => {
                const goToPayments = await showConfirmModal({
                    title: 'Lease Cycle Created',
                    message: 'Your new lease cycle has been created successfully. Would you like to make your first payment now?',
                    confirmText: 'Go to Payments',
                    cancelText: 'Later',
                    variant: 'success'
                });
                
                if (goToPayments) {
                    navigateToPage('payments');
                }
            }, 1000);
        } else {
            throw new Error(data.message);
        }
    } catch (error) {
        console.error('Error starting new lease cycle:', error);
        if (window.showToast) {
            window.showToast(error.message, 'error');
        }
    } finally {
        if (renewalBtn) {
            renewalBtn.innerHTML = originalText;
            renewalBtn.disabled = false;
        }
    }
}

// ==================== AUTO REFRESH ====================
let refreshInterval = null;

function startAutoRefresh() {
    if (refreshInterval) {
        clearInterval(refreshInterval);
    }
    
    refreshInterval = setInterval(async () => {
        if (document.querySelector('.dashboard-container')) {
            try {
                await fetchDashboardData();
                renderDashboard();
            } catch (error) {
                console.error('Auto-refresh error:', error);
            }
        }
    }, 30000); // Refresh every 30 seconds
}

function stopAutoRefresh() {
    if (refreshInterval) {
        clearInterval(refreshInterval);
        refreshInterval = null;
    }
}

// Start auto-refresh when page is visible
document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        stopAutoRefresh();
    } else {
        startAutoRefresh();
    }
});

// Initialize auto-refresh
startAutoRefresh();

// Cleanup on page unload
window.addEventListener('beforeunload', function() {
    stopAutoRefresh();
});
