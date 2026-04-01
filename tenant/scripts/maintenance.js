// maintenance.js
let currentPage = 1;
let currentStatus = '';
let totalPages = 1;

document.addEventListener('DOMContentLoaded', function() {
    initializeMaintenance();
});

async function initializeMaintenance() {
    // Wait for user data to be loaded from navbar
    if (window.currentUser) {
        await fetchMaintenanceRequests();
    } else {
        // Listen for user data loaded event
        window.addEventListener('userDataLoaded', async function() {
            await fetchMaintenanceRequests();
        });
        
        // Also try to fetch if not available after a short delay
        setTimeout(async () => {
            if (!window.currentUser && !document.querySelector('.requests-grid')) {
                await fetchMaintenanceRequests();
            }
        }, 1000);
    }
}

async function fetchMaintenanceRequests() {
    try {
        const url = `../backend/tenant/fetch_maintenance_requests.php?page=${currentPage}&limit=10&status=${currentStatus}`;
        const response = await fetch(url);
        const data = await response.json();
        
        console.log('Maintenance requests response:', data); // Debug log
        
        if (data.success && data.data) {
            const requests = data.data.requests || [];
            const pagination = data.data.pagination || {};
            totalPages = pagination.total_pages || 1;
            renderMaintenanceRequests(requests, pagination);
        } else {
            throw new Error(data.message || 'Failed to fetch maintenance requests');
        }
    } catch (error) {
        console.error('Error fetching maintenance requests:', error);
        if (window.showToast) {
            window.showToast('Failed to load maintenance requests', 'error');
        }
        showEmptyState();
    }
}

function renderMaintenanceRequests(requests, pagination) {
    const contentArea = document.getElementById('contentArea');
    if (!contentArea) return;
    
    if (!requests || requests.length === 0) {
        showEmptyState();
        return;
    }

    const html = `
        <div class="maintenance-container">
            <div class="page-header">
                <div>
                    <h1>Maintenance Requests</h1>
                    <p>Track and manage your maintenance requests</p>
                </div>
                <button class="btn-create" onclick="openCreateModal()">
                    <i class="fas fa-plus"></i> New Request
                </button>
            </div>
            
            <div class="filters">
                <select class="filter-select" id="statusFilter" onchange="filterByStatus()">
                    <option value="">All Requests</option>
                    <option value="pending" ${currentStatus === 'pending' ? 'selected' : ''}>Pending</option>
                    <option value="in_progress" ${currentStatus === 'in_progress' ? 'selected' : ''}>In Progress</option>
                    <option value="resolved" ${currentStatus === 'resolved' ? 'selected' : ''}>Resolved</option>
                    <option value="cancelled" ${currentStatus === 'cancelled' ? 'selected' : ''}>Cancelled</option>
                </select>
            </div>
            
            <div class="requests-grid">
                ${requests.map(request => `
                    <div class="request-card">
                        <div class="request-header">
                            <div class="request-title">${escapeHtml(request.issue_type)}</div>
                            <div style="display: flex; gap: 8px;">
                                <span class="priority-badge priority-${request.priority}">${request.priority.toUpperCase()}</span>
                                <span class="request-status status-${request.status}">${formatStatus(request.status)}</span>
                            </div>
                        </div>
                        <div class="request-description">${escapeHtml(request.description)}</div>
                        <div class="request-meta">
                            <span><i class="fas fa-calendar"></i> Created: ${formatDate(request.created_at)}</span>
                            ${request.updated_at ? `<span><i class="fas fa-edit"></i> Updated: ${formatDate(request.updated_at)}</span>` : ''}
                            ${request.resolved_at ? `<span><i class="fas fa-check-circle"></i> Resolved: ${formatDate(request.resolved_at)}</span>` : ''}
                        </div>
                        ${request.admin_notes ? `
                            <div class="request-meta" style="margin-top: 10px; background: #f9f9f9; padding: 10px; border-radius: 6px;">
                                <i class="fas fa-comment"></i> Note: ${escapeHtml(request.admin_notes)}
                            </div>
                        ` : ''}
                    </div>
                `).join('')}
            </div>
            
            ${renderPagination(pagination)}
        </div>
    `;
    
    contentArea.innerHTML = html;
}

function showEmptyState() {
    const contentArea = document.getElementById('contentArea');
    if (!contentArea) return;
    
    contentArea.innerHTML = `
        <div class="maintenance-container">
            <div class="page-header">
                <div>
                    <h1>Maintenance Requests</h1>
                    <p>Track and manage your maintenance requests</p>
                </div>
                <button class="btn-create" onclick="openCreateModal()">
                    <i class="fas fa-plus"></i> New Request
                </button>
            </div>
            <div class="filters">
                <select class="filter-select" id="statusFilter" onchange="filterByStatus()">
                    <option value="">All Requests</option>
                    <option value="pending">Pending</option>
                    <option value="in_progress">In Progress</option>
                    <option value="resolved">Resolved</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
            <div class="empty-state">
                <i class="fas fa-clipboard-list"></i>
                <h3>No Maintenance Requests</h3>
                <p>You haven't created any maintenance requests yet.</p>
                <button class="btn-primary" onclick="openCreateModal()" style="margin-top: 15px;">
                    Create Your First Request
                </button>
            </div>
        </div>
    `;
}

function renderPagination(pagination) {
    if (!pagination || pagination.total_pages <= 1) return '';
    
    let html = '<div class="pagination">';
    for (let i = 1; i <= pagination.total_pages; i++) {
        html += `<button class="page-btn ${i === currentPage ? 'active' : ''}" onclick="goToPage(${i})">${i}</button>`;
    }
    html += '</div>';
    return html;
}

function filterByStatus() {
    const statusFilter = document.getElementById('statusFilter');
    if (statusFilter) {
        currentStatus = statusFilter.value;
    }
    currentPage = 1;
    fetchMaintenanceRequests();
}

function goToPage(page) {
    currentPage = page;
    fetchMaintenanceRequests();
}

function openCreateModal() {
    const modal = document.getElementById('createModal');
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

    try {
        const response = await fetch('../backend/tenant/create_maintenance_request.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                issue_type: issueType, 
                priority: priority, 
                description: description 
            })
        });
        const data = await response.json();
        
        if (data.success) {
            if (window.showToast) {
                window.showToast('Maintenance request submitted successfully', 'success');
            }
            closeModal('createModal');
            await fetchMaintenanceRequests();
        } else {
            throw new Error(data.message || 'Failed to submit request');
        }
    } catch (error) {
        console.error('Error:', error);
        if (window.showToast) {
            window.showToast(error.message, 'error');
        }
    }
}

// ==================== UTILITY FUNCTIONS ====================
function formatStatus(status) {
    const map = { 
        'pending': 'Pending', 
        'in_progress': 'In Progress', 
        'resolved': 'Resolved', 
        'cancelled': 'Cancelled' 
    };
    return map[status] || status;
}

function formatDate(dateString) {
    if (!dateString) return 'N/A';
    try {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    } catch (e) {
        return dateString;
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}