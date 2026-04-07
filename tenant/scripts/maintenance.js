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
        const url = `../backend/maintenance/fetch_maintenance_requests.php?page=${currentPage}&limit=10&status=${currentStatus}`;
        const response = await fetch(url);
        const data = await response.json();
        
        console.log('Maintenance requests response:', data);
        
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
                    <div class="request-card" onclick="viewRequestDetails(${request.request_id})">
                        <div class="request-header">
                            <div class="request-title">${escapeHtml(request.issue_type)}</div>
                            <div style="display: flex; gap: 8px;">
                                <span class="priority-badge priority-${request.priority}">${request.priority.toUpperCase()}</span>
                                <span class="request-status status-${request.status}">${formatStatus(request.status)}</span>
                            </div>
                        </div>
                        <div class="request-description">${escapeHtml(request.description.substring(0, 150))}${request.description.length > 150 ? '...' : ''}</div>
                        <div class="request-meta">
                            <span><i class="fas fa-calendar"></i> Created: ${formatDate(request.created_at)}</span>
                            ${request.updated_at && request.updated_at !== request.created_at ? `<span><i class="fas fa-edit"></i> Updated: ${formatDate(request.updated_at)}</span>` : ''}
                            ${request.resolved_at ? `<span><i class="fas fa-check-circle"></i> Resolved: ${formatDate(request.resolved_at)}</span>` : ''}
                            ${request.assigned_to_name ? `<span><i class="fas fa-user"></i> Assigned to: ${escapeHtml(request.assigned_to_name)}</span>` : ''}
                        </div>
                        ${request.can_cancel ? `
                            <div class="request-actions" style="margin-top: 10px; text-align: right;">
                                <button class="btn-cancel" onclick="event.stopPropagation(); cancelRequest(${request.request_id})">
                                    <i class="fas fa-times"></i> Cancel Request
                                </button>
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

// Add cancel button styles
const style = document.createElement('style');
style.textContent = `
    .request-card {
        cursor: pointer;
        transition: all 0.3s;
    }
    .request-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }
    .btn-cancel {
        background: #fee2e2;
        color: #dc2626;
        border: 1px solid #fecaca;
        padding: 6px 12px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 12px;
        transition: all 0.2s;
    }
    .btn-cancel:hover {
        background: #fecaca;
        transform: translateY(-1px);
    }
`;
document.head.appendChild(style);

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

// ==================== VIEW REQUEST DETAILS ====================
async function viewRequestDetails(requestId) {
    try {
        // Show loading state
        if (window.showToast) {
            window.showToast('Loading request details...', 'info');
        }
        
        const response = await fetch(`../backend/maintenance/fetch_maintenance_request_details.php?request_id=${requestId}`);
        const data = await response.json();
        
        if (data.success && data.data) {
            showRequestDetailsModal(data.data);
        } else {
            throw new Error(data.message || 'Failed to load request details');
        }
    } catch (error) {
        console.error('Error fetching request details:', error);
        if (window.showToast) {
            window.showToast(error.message, 'error');
        }
    }
}

function showRequestDetailsModal(details) {
    const request = details.request;
    const timeline = details.timeline || [];
    
    // Create modal if it doesn't exist
    let modal = document.getElementById('requestDetailsModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'requestDetailsModal';
        modal.className = 'modal';
        modal.innerHTML = `
            <div class="modal-content" style="max-width: 700px;">
                <div class="modal-header">
                    <h3>Maintenance Request Details</h3>
                    <button class="modal-close" onclick="closeRequestDetailsModal()">&times;</button>
                </div>
                <div class="modal-body" id="requestDetailsBody">
                    <!-- Content will be populated here -->
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="closeRequestDetailsModal()">Close</button>
                    ${request.can_cancel ? `<button class="btn-danger" onclick="cancelRequestFromModal(${request.request_id})">Cancel Request</button>` : ''}
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        
        // Add styles for timeline
        const timelineStyle = document.createElement('style');
        timelineStyle.textContent = `
            .timeline {
                margin: 20px 0;
                position: relative;
                padding-left: 30px;
            }
            .timeline-item {
                position: relative;
                padding-bottom: 20px;
                border-left: 2px solid #e5e7eb;
                padding-left: 20px;
                margin-left: 10px;
            }
            .timeline-item:last-child {
                border-left: 2px solid transparent;
            }
            .timeline-item::before {
                content: "";
                position: absolute;
                left: -8px;
                top: 0;
                width: 12px;
                height: 12px;
                border-radius: 50%;
                background: #667eea;
                border: 2px solid white;
            }
            .timeline-date {
                font-size: 12px;
                color: #666;
                margin-bottom: 5px;
            }
            .timeline-title {
                font-weight: 600;
                color: #1a1f36;
                margin-bottom: 5px;
            }
            .timeline-description {
                font-size: 13px;
                color: #666;
            }
            .btn-danger {
                background: #fee2e2;
                color: #dc2626;
                border: 1px solid #fecaca;
                padding: 8px 16px;
                border-radius: 6px;
                cursor: pointer;
                transition: all 0.2s;
            }
            .btn-danger:hover {
                background: #fecaca;
            }
            .detail-section {
                margin-bottom: 20px;
            }
            .detail-section h4 {
                font-size: 16px;
                color: #1a1f36;
                margin-bottom: 10px;
                padding-bottom: 5px;
                border-bottom: 2px solid #f0f0f0;
            }
            .detail-grid {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }
            .detail-item {
                display: flex;
                justify-content: space-between;
                padding: 8px 0;
                border-bottom: 1px solid #f5f5f5;
            }
            .detail-label {
                font-weight: 500;
                color: #666;
            }
            .detail-value {
                color: #1a1f36;
            }
            @media (max-width: 768px) {
                .detail-grid {
                    grid-template-columns: 1fr;
                }
            }
        `;
        document.head.appendChild(timelineStyle);
    }
    
    // Populate modal content
    const body = document.getElementById('requestDetailsBody');
    body.innerHTML = `
        <div class="detail-section">
            <h4>Request Information</h4>
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="detail-label">Request ID:</span>
                    <span class="detail-value">#${request.request_id}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Issue Type:</span>
                    <span class="detail-value">${escapeHtml(request.issue_type)}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Priority:</span>
                    <span class="detail-value">
                        <span class="priority-badge priority-${request.priority}">${request.priority_display}</span>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Status:</span>
                    <span class="detail-value">
                        <span class="request-status status-${request.status}">${request.status_display}</span>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Created:</span>
                    <span class="detail-value">${formatDateTime(request.created_at)}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Apartment:</span>
                    <span class="detail-value">${escapeHtml(request.apartment_info?.apartment_number || 'N/A')}</span>
                </div>
                ${request.assigned_to_name ? `
                <div class="detail-item">
                    <span class="detail-label">Assigned To:</span>
                    <span class="detail-value">${escapeHtml(request.assigned_to_name)}</span>
                </div>
                ` : ''}
                ${request.resolved_at ? `
                <div class="detail-item">
                    <span class="detail-label">Resolved:</span>
                    <span class="detail-value">${formatDateTime(request.resolved_at)}</span>
                </div>
                ` : ''}
                ${request.resolution_days ? `
                <div class="detail-item">
                    <span class="detail-label">Resolution Time:</span>
                    <span class="detail-value">${request.resolution_days} days</span>
                </div>
                ` : ''}
            </div>
        </div>
        
        <div class="detail-section">
            <h4>Description</h4>
            <p style="color: #666; line-height: 1.6;">${escapeHtml(request.description)}</p>
        </div>
        
        ${request.resolution_notes ? `
        <div class="detail-section">
            <h4>Resolution Notes</h4>
            <p style="color: #666; line-height: 1.6;">${escapeHtml(request.resolution_notes)}</p>
        </div>
        ` : ''}
        
        ${timeline.length > 0 ? `
        <div class="detail-section">
            <h4>Timeline</h4>
            <div class="timeline">
                ${timeline.map(item => `
                    <div class="timeline-item">
                        <div class="timeline-date">${formatDateTime(item.date)}</div>
                        <div class="timeline-title">${escapeHtml(item.action)}</div>
                        <div class="timeline-description">${escapeHtml(item.description)}</div>
                        <div class="timeline-user" style="font-size: 11px; color: #999; margin-top: 4px;">By: ${escapeHtml(item.user)}</div>
                    </div>
                `).join('')}
            </div>
        </div>
        ` : ''}
    `;
    
    // Store request ID in modal for cancel action
    modal.dataset.requestId = request.request_id;
    modal.dataset.canCancel = request.can_cancel;
    
    modal.classList.add('active');
}

function closeRequestDetailsModal() {
    const modal = document.getElementById('requestDetailsModal');
    if (modal) {
        modal.classList.remove('active');
    }
}

// ==================== CANCEL REQUEST ====================
async function cancelRequest(requestId) {
    if (!confirm('Are you sure you want to cancel this maintenance request? This action cannot be undone.')) {
        return;
    }
    
    try {
        const response = await fetch('../backend/maintenance/cancel_maintenance_request.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                request_id: requestId,
                cancel_reason: 'Cancelled by tenant'
            })
        });
        const data = await response.json();
        
        if (data.success) {
            if (window.showToast) {
                window.showToast('Maintenance request cancelled successfully', 'success');
            }
            // Close modal if open
            closeRequestDetailsModal();
            // Refresh the list
            await fetchMaintenanceRequests();
        } else {
            throw new Error(data.message || 'Failed to cancel request');
        }
    } catch (error) {
        console.error('Error cancelling request:', error);
        if (window.showToast) {
            window.showToast(error.message, 'error');
        }
    }
}

async function cancelRequestFromModal(requestId) {
    closeRequestDetailsModal();
    await cancelRequest(requestId);
}

// ==================== CREATE REQUEST ====================
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
        const response = await fetch('../backend/maintenance/create_maintenance_request.php', {
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

function formatDateTime(dateString) {
    if (!dateString) return 'N/A';
    try {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
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