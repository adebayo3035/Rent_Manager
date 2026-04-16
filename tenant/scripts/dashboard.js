// dashboard.js
let dashboardData = null;

document.addEventListener('DOMContentLoaded', function() {
    initializeDashboard();
});

async function initializeDashboard() {
    // Wait for user data to be loaded from navbar
    if (window.currentUser) {
        await fetchDashboardData();
        renderDashboard();
    } else {
        // Listen for user data loaded event
        window.addEventListener('userDataLoaded', async function(e) {
            await fetchDashboardData();
            renderDashboard();
        });
        
        // Also try to fetch if not available after a short delay
        setTimeout(async () => {
            if (!window.currentUser && !dashboardData) {
                await fetchDashboardData();
                renderDashboard();
            }
        }, 1000);
    }
}

async function fetchDashboardData() {
    try {
        const response = await fetch('../backend/tenant/fetch_dashboard_data.php');
        const data = await response.json();
        
        console.log('Dashboard data response:', data); // Debug log
        
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

function renderDashboard() {
    const contentArea = document.getElementById('contentArea');
    if (!contentArea) return;
    
    if (!dashboardData) {
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
        return;
    }

    const html = `
        <div class="dashboard-container">
            <!-- Welcome Section -->
            <div class="welcome-section">
                <h1>Welcome back, ${window.currentUser?.firstname || 'Tenant'}!</h1>
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
                        <h3>${dashboardData.apartment_number || 'Not Assigned'}</h3>
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

            <!-- Payment Section -->
            <div class="payment-section">
                <div class="section-header">
                    <h2> Current Rent Information</h2>
                    <span class="btn-link" onclick="navigateToPage('apartment')">View More →</span>
                </div>
                <div class="payment-card">
                    <div class="payment-details">
                        <div class="payment-amount">
                            <label>Rent Amount </label>
                            <span class="amount">₦${formatNumber(dashboardData.last_payment.amount || 0)}</span>
                        </div>
                        <div class="payment-date">
                            <label>Payment Frequency</label>
                            <span class="date">${currentUser.payment_frequency}</span>
                        </div>
                         
                    </div>

                    <div class="payment-details">
                        
                         <div class="payment-date">
                            <label>Start Date</label>
                            <span class="date">${dashboardData.current_period.start_formatted}</span>
                        </div>
                         <div class="payment-date">
                            <label>End Date</label>
                            <span class="date">${dashboardData.current_period.end_formatted}</span>
                        </div>
                    </div>

                   
                </div>
            </div>

            <!-- Payment Section -->
            <div class="payment-section">
                <div class="section-header">
                    <h2>Upcoming Payment</h2>
                    <span class="btn-link" onclick="navigateToPage('payments')">View All →</span>
                </div>
                <div class="payment-card">
                    <div class="payment-details">
                        <div class="payment-amount">
                            <label>Amount Due</label>
                            <span class="amount">₦${formatNumber(dashboardData.rent_amount || 0)}</span>
                        </div>
                        
                        <div class="payment-date">
                            <label> Current Rent Payment Due Date</label>
                            <span class="date">${formatDate(dashboardData.last_payment.due_date)}</span>
                        </div>
                    </div>
                    <button class="btn-primary" onclick="makePayment()">Make Payment</button>
                </div>
            </div>

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
        tenant_code: window.currentUser?.tenant_code
    };

    await createMaintenanceRequest(requestData);
}

function makePayment() {
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
        'settings': 'settings.php',
        'profile' : 'profile.php'
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

// ==================== AUTO REFRESH ====================
setInterval(async () => {
    if (document.querySelector('.dashboard-container')) {
        try {
            await fetchDashboardData();
            renderDashboard();
        } catch (error) {
            console.error('Auto-refresh error:', error);
        }
    }
}, 30000); // Refresh every 30 seconds