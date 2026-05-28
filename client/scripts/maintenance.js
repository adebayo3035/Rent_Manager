// client/scripts/maintenance.js - Client Portal Maintenance Management

let currentPage = 1;
let currentStatus = '';
let currentPriority = '';
let currentProperty = '';
let totalPages = 1;

document.addEventListener('DOMContentLoaded', function() {
    initializeMaintenance();
});

async function initializeMaintenance() {
    // Wait for client data to be loaded from navbar
    if (window.currentUser || window.currentClient) {
        await fetchMaintenanceRequests();
    } else {
        window.addEventListener('userDataLoaded', async function() {
            await fetchMaintenanceRequests();
        });
        
        setTimeout(async () => {
            if (!window.currentUser && !document.querySelector('.requests-grid')) {
                await fetchMaintenanceRequests();
            }
        }, 1000);
    }
}

async function fetchMaintenanceRequests() {
    try {
        let url = `../backend/maintenance/fetch_maintenance_requests.php?page=${currentPage}&limit=10`;
        if (currentStatus) url += `&status=${currentStatus}`;
        if (currentPriority) url += `&priority=${currentPriority}`;
        if (currentProperty) url += `&property_code=${currentProperty}`;
        
        const response = await fetch(url);
        const data = await response.json();
        
        console.log('Maintenance requests response:', data);
        
        if (data.success && data.data) {
            const requests = data.data.requests || [];
            const pagination = data.data.pagination || {};
            const summary = data.data.summary || {};
            const properties = data.data.properties || [];
            totalPages = pagination.total_pages || 1;
            renderMaintenanceRequests(requests, pagination, summary, properties);
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

function renderMaintenanceRequests(requests, pagination, summary, properties) {
    const contentArea = document.getElementById('contentArea');
    if (!contentArea) return;

    const html = `
        <div class="maintenance-container">
            <div class="page-header">
                <div>
                    <h1>Maintenance Requests</h1>
                    <p>View and manage maintenance requests from your tenants</p>
                </div>
            </div>
            
            <!-- Summary Cards -->
            <div class="summary-cards">
                <div class="summary-card">
                    <div class="summary-icon pending">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="summary-info">
                        <div class="summary-value">${summary.pending || 0}</div>
                        <div class="summary-label">Pending</div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon in-progress">
                        <i class="fas fa-spinner fa-pulse"></i>
                    </div>
                    <div class="summary-info">
                        <div class="summary-value">${summary.in_progress || 0}</div>
                        <div class="summary-label">In Progress</div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon resolved">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="summary-info">
                        <div class="summary-value">${summary.resolved || 0}</div>
                        <div class="summary-label">Resolved</div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon total">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div class="summary-info">
                        <div class="summary-value">${summary.total_requests || 0}</div>
                        <div class="summary-label">Total Requests</div>
                    </div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="filters-section">
                <div class="filter-group">
                    <label>Property</label>
                    <select id="propertyFilter" class="filter-select" onchange="filterByProperty()">
                        <option value="">All Properties</option>
                        ${properties.map(p => `
                            <option value="${p.property_code}" ${currentProperty === p.property_code ? 'selected' : ''}>
                                ${escapeHtml(p.name)}
                            </option>
                        `).join('')}
                    </select>
                </div>
                <div class="filter-group">
                    <label>Status</label>
                    <select id="statusFilter" class="filter-select" onchange="filterByStatus()">
                        <option value="">All Status</option>
                        <option value="pending" ${currentStatus === 'pending' ? 'selected' : ''}>Pending</option>
                        <option value="in_progress" ${currentStatus === 'in_progress' ? 'selected' : ''}>In Progress</option>
                        <option value="resolved" ${currentStatus === 'resolved' ? 'selected' : ''}>Resolved</option>
                        <option value="cancelled" ${currentStatus === 'cancelled' ? 'selected' : ''}>Cancelled</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Priority</label>
                    <select id="priorityFilter" class="filter-select" onchange="filterByPriority()">
                        <option value="">All Priorities</option>
                        <option value="emergency" ${currentPriority === 'emergency' ? 'selected' : ''}>Emergency</option>
                        <option value="high" ${currentPriority === 'high' ? 'selected' : ''}>High</option>
                        <option value="medium" ${currentPriority === 'medium' ? 'selected' : ''}>Medium</option>
                        <option value="low" ${currentPriority === 'low' ? 'selected' : ''}>Low</option>
                    </select>
                </div>
            </div>
            
            ${requests.length === 0 ? `
                <div class="empty-state">
                    <i class="fas fa-clipboard-list"></i>
                    <h3>No Maintenance Requests</h3>
                    <p>No maintenance requests found for your properties.</p>
                </div>
            ` : `
                <div class="requests-grid">
                    ${requests.map(request => `
                        <div class="request-card priority-${request.priority}" onclick="viewRequestDetails(${request.request_id})">
                            <div class="request-header">
                                <div class="request-title">
                                    <i class="fas fa-tools"></i>
                                    ${escapeHtml(request.issue_type)}
                                </div>
                                <div class="request-badges">
                                    <span class="priority-badge priority-${request.priority}">
                                        ${request.priority_display}
                                    </span>
                                    <span class="status-badge status-${request.status}">
                                        ${request.status_display}
                                    </span>
                                </div>
                            </div>
                            <div class="request-property">
                                <i class="fas fa-building"></i>
                                ${escapeHtml(request.property_info?.property_name || 'N/A')} - Apt ${escapeHtml(request.property_info?.apartment_number || 'N/A')}
                            </div>
                            <div class="request-tenant">
                                <i class="fas fa-user"></i>
                                ${escapeHtml(request.tenant_info?.tenant_name || 'Unknown Tenant')}
                            </div>
                            <div class="request-description">
                                ${escapeHtml(request.description.substring(0, 120))}${request.description.length > 120 ? '...' : ''}
                            </div>
                            <div class="request-meta">
                                <span><i class="fas fa-calendar"></i> ${formatDate(request.created_at)}</span>
                                ${request.days_pending > 0 ? `<span><i class="fas fa-hourglass-half"></i> ${request.days_pending} days pending</span>` : ''}
                            </div>
                        </div>
                    `).join('')}
                </div>
                ${renderPagination(pagination)}
            `}
        </div>
    `;
    
    contentArea.innerHTML = html;
}

function renderPagination(pagination) {
    if (!pagination || pagination.total_pages <= 1) return '';
    
    let html = '<div class="pagination">';
    html += `<button class="page-btn ${currentPage === 1 ? 'disabled' : ''}" onclick="goToPage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''}>
        <i class="fas fa-chevron-left"></i>
    </button>`;
    
    for (let i = 1; i <= pagination.total_pages; i++) {
        if (i === 1 || i === pagination.total_pages || (i >= currentPage - 2 && i <= currentPage + 2)) {
            html += `<button class="page-btn ${i === currentPage ? 'active' : ''}" onclick="goToPage(${i})">${i}</button>`;
        } else if (i === currentPage - 3 || i === currentPage + 3) {
            html += '<span class="page-dots">...</span>';
        }
    }
    
    html += `<button class="page-btn ${currentPage === pagination.total_pages ? 'disabled' : ''}" onclick="goToPage(${currentPage + 1})" ${currentPage === pagination.total_pages ? 'disabled' : ''}>
        <i class="fas fa-chevron-right"></i>
    </button>`;
    html += '</div>';
    return html;
}

function filterByStatus() {
    const statusFilter = document.getElementById('statusFilter');
    currentStatus = statusFilter?.value || '';
    currentPage = 1;
    fetchMaintenanceRequests();
}

function filterByPriority() {
    const priorityFilter = document.getElementById('priorityFilter');
    currentPriority = priorityFilter?.value || '';
    currentPage = 1;
    fetchMaintenanceRequests();
}

function filterByProperty() {
    const propertyFilter = document.getElementById('propertyFilter');
    currentProperty = propertyFilter?.value || '';
    currentPage = 1;
    fetchMaintenanceRequests();
}

function goToPage(page) {
    if (page < 1 || page > totalPages) return;
    currentPage = page;
    fetchMaintenanceRequests();
}

function showEmptyState() {
    const contentArea = document.getElementById('contentArea');
    if (!contentArea) return;
    
    contentArea.innerHTML = `
        <div class="maintenance-container">
            <div class="page-header">
                <div>
                    <h1>Maintenance Requests</h1>
                    <p>View and manage maintenance requests from your tenants</p>
                </div>
            </div>
            <div class="empty-state">
                <i class="fas fa-clipboard-list"></i>
                <h3>No Maintenance Requests</h3>
                <p>No maintenance requests found for your properties.</p>
            </div>
        </div>
    `;
}

// ==================== VIEW REQUEST DETAILS ====================
async function viewRequestDetails(requestId) {
    try {
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
    
    let modal = document.getElementById('requestDetailsModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'requestDetailsModal';
        modal.className = 'modal';
        modal.innerHTML = `
            <div class="modal-content" style="max-width: 800px;">
                <div class="modal-header">
                    <h3><i class="fas fa-clipboard-list"></i> Maintenance Request Details</h3>
                    <button class="modal-close" onclick="closeRequestDetailsModal()">&times;</button>
                </div>
                <div class="modal-body" id="requestDetailsBody"></div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="closeRequestDetailsModal()">Close</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }
    
    const body = document.getElementById('requestDetailsBody');
    body.innerHTML = `
        <!-- Request Info Section -->
        <div class="detail-section">
            <h4><i class="fas fa-info-circle"></i> Request Information</h4>
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
                        <span class="status-badge status-${request.status}">${request.status_display}</span>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Created:</span>
                    <span class="detail-value">${formatDateTime(request.created_at)}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Days Pending:</span>
                    <span class="detail-value">${request.days_pending || 0} days</span>
                </div>
            </div>
        </div>
        
        <!-- Tenant Information -->
        <div class="detail-section">
            <h4><i class="fas fa-user"></i> Tenant Information</h4>
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="detail-label">Tenant Name:</span>
                    <span class="detail-value">${escapeHtml(request.tenant_info?.tenant_name || 'N/A')}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Email:</span>
                    <span class="detail-value">${escapeHtml(request.tenant_info?.email || 'N/A')}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Phone:</span>
                    <span class="detail-value">${escapeHtml(request.tenant_info?.phone || 'N/A')}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Lease Period:</span>
                    <span class="detail-value">${formatDate(request.tenant_info?.lease_start_date)} - ${formatDate(request.tenant_info?.lease_end_date)}</span>
                </div>
            </div>
        </div>
        
        <!-- Property Information -->
        <div class="detail-section">
            <h4><i class="fas fa-building"></i> Property Information</h4>
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="detail-label">Property:</span>
                    <span class="detail-value">${escapeHtml(request.property_info?.property_name || 'N/A')}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Apartment:</span>
                    <span class="detail-value">${escapeHtml(request.property_info?.apartment_number || 'N/A')}</span>
                </div>
            </div>
        </div>
        
        <!-- Description -->
        <div class="detail-section">
            <h4><i class="fas fa-align-left"></i> Description</h4>
            <div class="description-content">${escapeHtml(request.description)}</div>
        </div>
        
        ${request.assigned_to_name ? `
        <div class="detail-section">
            <h4><i class="fas fa-user-cog"></i> Assignment</h4>
            <div class="detail-item">
                <span class="detail-label">Assigned To:</span>
                <span class="detail-value">${escapeHtml(request.assigned_to_name)}</span>
            </div>
        </div>
        ` : ''}
        
        ${request.resolution_notes ? `
        <div class="detail-section">
            <h4><i class="fas fa-sticky-note"></i> Resolution Notes</h4>
            <div class="description-content">${escapeHtml(request.resolution_notes)}</div>
        </div>
        ` : ''}
        
        ${timeline.length > 0 ? `
        <div class="detail-section">
            <h4><i class="fas fa-history"></i> Timeline</h4>
            <div class="timeline">
                ${timeline.map(item => `
                    <div class="timeline-item">
                        <div class="timeline-date">${formatDateTime(item.date)}</div>
                        <div class="timeline-title">${escapeHtml(item.action)}</div>
                        <div class="timeline-description">${escapeHtml(item.description)}</div>
                        <div class="timeline-user">By: ${escapeHtml(item.user)}</div>
                    </div>
                `).join('')}
            </div>
        </div>
        ` : ''}
        
        ${request.images && request.images.length > 0 ? `
        <div class="detail-section">
            <h4><i class="fas fa-image"></i> Attached Images</h4>
            <div class="images-grid">
                ${request.images.map(img => `
                    <img src="${img}" alt="Maintenance Image" class="attached-image" onclick="window.open('${img}', '_blank')">
                `).join('')}
            </div>
        </div>
        ` : ''}
    `;
    
    modal.classList.add('active');
}

function closeRequestDetailsModal() {
    const modal = document.getElementById('requestDetailsModal');
    if (modal) {
        modal.classList.remove('active');
    }
}

// ==================== UTILITY FUNCTIONS ====================
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