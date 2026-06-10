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
                <div class="modal-footer" id="modalFooterButtons">
                    <button class="btn-secondary" onclick="closeRequestDetailsModal()">Close</button>
                    ${request.can_cancel ? `<button class="btn-danger" onclick="cancelRequestFromModal(${request.request_id})">Cancel Request</button>` : ''}
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        
        // Add styles for timeline and rating
        const modalStyle = document.createElement('style');
        modalStyle.textContent = `
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
            .btn-success {
                background: #d1fae5;
                color: #10b981;
                border: 1px solid #a7f3d0;
                padding: 8px 16px;
                border-radius: 6px;
                cursor: pointer;
                transition: all 0.2s;
            }
            .btn-success:hover {
                background: #a7f3d0;
            }
            .btn-warning {
                background: #fed7aa;
                color: #f59e0b;
                border: 1px solid #fde68a;
                padding: 8px 16px;
                border-radius: 6px;
                cursor: pointer;
                transition: all 0.2s;
            }
            .btn-warning:hover {
                background: #fde68a;
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
            /* Rating Stars */
            .rating-stars {
                display: flex;
                gap: 8px;
                justify-content: center;
                margin: 15px 0;
            }
            .rating-stars i {
                font-size: 32px;
                cursor: pointer;
                transition: all 0.2s;
                color: #d1d5db;
            }
            .rating-stars i.active,
            .rating-stars i.hover {
                color: #fbbf24;
                transform: scale(1.1);
            }
            @media (max-width: 768px) {
                .detail-grid {
                    grid-template-columns: 1fr;
                }
            }
        `;
        document.head.appendChild(modalStyle);
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
    
    // Update footer buttons based on status
    const footer = document.getElementById('modalFooterButtons');
    if (footer) {
        let buttons = '<button class="btn-secondary" onclick="closeRequestDetailsModal()">Close</button>';
        
        if (request.can_cancel) {
            buttons += `<button class="btn-danger" onclick="cancelRequestFromModal(${request.request_id})">Cancel Request</button>`;
        }
        
        // If status is 'resolved' and not yet confirmed, show confirmation buttons
        if (request.status === 'resolved' && !request.tenant_confirmed) {
            buttons += `
                <button class="btn-success" onclick="openConfirmModal(${request.request_id})">
                    <i class="fas fa-check-circle"></i> Confirm & Rate
                </button>
                <button class="btn-warning" onclick="openEscalateModal(${request.request_id})">
                    <i class="fas fa-flag"></i> Re-open / Escalate
                </button>
            `;
        }
        
        footer.innerHTML = buttons;
    }
    
    // Store request ID in modal for actions
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

// ==================== CONFIRM RESOLUTION ====================
function openConfirmModal(requestId) {
    // Create rating modal
    let modal = document.getElementById('confirmResolutionModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'confirmResolutionModal';
        modal.className = 'modal';
        modal.innerHTML = `
            <div class="modal-content" style="max-width: 450px;">
                <div class="modal-header">
                    <h3>Confirm Resolution</h3>
                    <button class="modal-close" onclick="closeConfirmResolutionModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <p>Please rate the quality of the maintenance service:</p>
                    <div class="rating-stars" id="ratingStars">
                        <i class="fas fa-star" data-rating="1"></i>
                        <i class="fas fa-star" data-rating="2"></i>
                        <i class="fas fa-star" data-rating="3"></i>
                        <i class="fas fa-star" data-rating="4"></i>
                        <i class="fas fa-star" data-rating="5"></i>
                    </div>
                    <div class="form-group" style="margin-top: 15px;">
                        <label>Your Feedback (Optional)</label>
                        <textarea id="feedbackText" class="form-textarea" rows="3" placeholder="Share your experience..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="closeConfirmResolutionModal()">Cancel</button>
                    <button class="btn-primary" onclick="submitConfirmation(${requestId})">Submit</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        
        // Add rating star event listeners
        const stars = modal.querySelectorAll('#ratingStars i');
        stars.forEach(star => {
            star.addEventListener('mouseover', function() {
                const rating = parseInt(this.dataset.rating);
                highlightStars(rating);
            });
            star.addEventListener('mouseout', function() {
                const currentRating = parseInt(modal.dataset.selectedRating || 0);
                highlightStars(currentRating);
            });
            star.addEventListener('click', function() {
                const rating = parseInt(this.dataset.rating);
                modal.dataset.selectedRating = rating;
                highlightStars(rating);
            });
        });
    }
    
    modal.dataset.selectedRating = 0;
    highlightStars(0);
    document.getElementById('feedbackText').value = '';
    modal.classList.add('active');
}

function highlightStars(rating) {
    const stars = document.querySelectorAll('#ratingStars i');
    stars.forEach((star, index) => {
        if (index < rating) {
            star.classList.add('active');
        } else {
            star.classList.remove('active');
        }
    });
}

function closeConfirmResolutionModal() {
    const modal = document.getElementById('confirmResolutionModal');
    if (modal) modal.classList.remove('active');
}

async function submitConfirmation(requestId) {
    const modal = document.getElementById('confirmResolutionModal');
    const rating = parseInt(modal.dataset.selectedRating || 0);
    const feedback = document.getElementById('feedbackText')?.value || '';
    
    if (rating === 0) {
        if (window.showToast) window.showToast('Please select a rating', 'error');
        return;
    }
    
    const loader = createFullPageLoader('Submitting confirmation...');
    
    try {
        const response = await fetch('../backend/maintenance/confirm_resolution.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                request_id: requestId,
                rating: rating,
                feedback: feedback,
                satisfied: true
            })
        });
        const data = await response.json();
        
        removeFullPageLoader();
        closeConfirmResolutionModal();
        closeRequestDetailsModal();
        
        if (data.success) {
            if (window.showToast) window.showToast('Thank you for your feedback!', 'success');
            await fetchMaintenanceRequests();
        } else {
            throw new Error(data.message || 'Failed to submit confirmation');
        }
    } catch (error) {
        removeFullPageLoader();
        console.error('Error submitting confirmation:', error);
        if (window.showToast) window.showToast(error.message, 'error');
    }
}

// ==================== ESCALATE / RE-OPEN ====================
function openEscalateModal(requestId) {
    let modal = document.getElementById('escalateModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'escalateModal';
        modal.className = 'modal';
        modal.innerHTML = `
            <div class="modal-content" style="max-width: 450px;">
                <div class="modal-header">
                    <h3>Re-open / Escalate Issue</h3>
                    <button class="modal-close" onclick="closeEscalateModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <p>Please explain why you are not satisfied with the resolution:</p>
                    <div class="form-group">
                        <textarea id="escalateReason" class="form-textarea" rows="4" placeholder="Describe what still needs to be fixed..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="closeEscalateModal()">Cancel</button>
                    <button class="btn-warning" onclick="submitEscalation(${requestId})">Submit Escalation</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }
    
    document.getElementById('escalateReason').value = '';
    modal.classList.add('active');
}

function closeEscalateModal() {
    const modal = document.getElementById('escalateModal');
    if (modal) modal.classList.remove('active');
}

async function submitEscalation(requestId) {
    const reason = document.getElementById('escalateReason')?.value.trim();
    
    if (!reason) {
        if (window.showToast) window.showToast('Please provide a reason for escalation', 'error');
        return;
    }
    
    const loader = createFullPageLoader('Submitting escalation...');
    
    try {
        const response = await fetch('../backend/maintenance/confirm_resolution.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                request_id: requestId,
                satisfied: false,
                escalation_reason: reason
            })
        });
        const data = await response.json();
        
        removeFullPageLoader();
        closeEscalateModal();
        closeRequestDetailsModal();
        
        if (data.success) {
            if (window.showToast) window.showToast('Escalation submitted. Admin will review.', 'success');
            await fetchMaintenanceRequests();
        } else {
            throw new Error(data.message || 'Failed to submit escalation');
        }
    } catch (error) {
        removeFullPageLoader();
        console.error('Error submitting escalation:', error);
        if (window.showToast) window.showToast(error.message, 'error');
    }
}

// ==================== FULL PAGE LOADER ====================
function createFullPageLoader(message = "Processing...") {
    let loader = document.getElementById('fullPageLoader');
    if (loader) loader.remove();
    
    loader = document.createElement('div');
    loader.id = 'fullPageLoader';
    loader.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.7);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 99999;
        backdrop-filter: blur(4px);
    `;
    
    loader.innerHTML = `
        <div style="background: white; padding: 30px 40px; border-radius: 12px; text-align: center; min-width: 250px; box-shadow: 0 10px 40px rgba(0,0,0,0.2);">
            <div style="margin-bottom: 20px;">
                <i class="fas fa-spinner fa-spin" style="font-size: 40px; color: #1e3c72;"></i>
            </div>
            <p style="margin: 0; font-size: 16px; color: #333;">${message}</p>
            <p style="margin: 10px 0 0 0; font-size: 12px; color: #666;">Please do not close this window</p>
        </div>
    `;
    
    document.body.appendChild(loader);
    return loader;
}

function removeFullPageLoader() {
    const loader = document.getElementById('fullPageLoader');
    if (loader) loader.remove();
}


// Custom Confirm Modal Promise
function showConfirmModal(options) {
    return new Promise((resolve) => {
        const modal = document.getElementById('customConfirmModal');
        const titleEl = document.getElementById('confirmModalTitle');
        const messageEl = document.getElementById('confirmModalMessage');
        const iconDiv = document.getElementById('confirmModalIcon');
        const confirmBtn = document.getElementById('confirmModalConfirmBtn');
        const cancelBtn = document.getElementById('confirmModalCancelBtn');
        
        // Set content
        titleEl.textContent = options.title || 'Confirm Action';
        messageEl.textContent = options.message || 'Are you sure you want to proceed?';
        
        // Set icon style
        iconDiv.className = 'custom-modal-icon';
        const iconType = options.type || 'warning';
        if (iconType === 'warning') {
            iconDiv.classList.add('warning');
            iconDiv.innerHTML = '<i class="fas fa-exclamation-triangle"></i>';
            confirmBtn.className = 'custom-btn-confirm';
        } else if (iconType === 'danger') {
            iconDiv.classList.add('warning');
            iconDiv.innerHTML = '<i class="fas fa-trash-alt"></i>';
            confirmBtn.className = 'custom-btn-confirm';
        } else if (iconType === 'success') {
            iconDiv.classList.add('success');
            iconDiv.innerHTML = '<i class="fas fa-check-circle"></i>';
            confirmBtn.className = 'custom-btn-confirm success';
        } else if (iconType === 'info') {
            iconDiv.classList.add('info');
            iconDiv.innerHTML = '<i class="fas fa-info-circle"></i>';
            confirmBtn.className = 'custom-btn-confirm';
        }
        
        // Set confirm button text
        confirmBtn.textContent = options.confirmText || 'Confirm';
        cancelBtn.textContent = options.cancelText || 'Cancel';
        
        // Show modal
        modal.classList.add('show');
        
        // Handle confirm
        const onConfirm = () => {
            cleanup();
            resolve(true);
        };
        
        // Handle cancel
        const onCancel = () => {
            cleanup();
            resolve(false);
        };
        
        // Handle close button
        const onClose = () => {
            cleanup();
            resolve(false);
        };
        
        // Handle click outside
        const onOutsideClick = (e) => {
            if (e.target === modal) {
                cleanup();
                resolve(false);
            }
        };
        
        // Cleanup function
        const cleanup = () => {
            confirmBtn.removeEventListener('click', onConfirm);
            cancelBtn.removeEventListener('click', onCancel);
            modal.querySelector('.custom-modal-close').removeEventListener('click', onClose);
            modal.removeEventListener('click', onOutsideClick);
            modal.classList.remove('show');
        };
        
        // Attach events
        confirmBtn.addEventListener('click', onConfirm);
        cancelBtn.addEventListener('click', onCancel);
        modal.querySelector('.custom-modal-close').addEventListener('click', onClose);
        modal.addEventListener('click', onOutsideClick);
    });
}

// Close modal (fallback)
function closeConfirmModal() {
    const modal = document.getElementById('customConfirmModal');
    if (modal) {
        modal.classList.remove('show');
    }
}

// Updated cancelRequest function
async function cancelRequest(requestId) {
    const confirmed = await showConfirmModal({
        title: 'Cancel Maintenance Request',
        message: 'Are you sure you want to cancel this maintenance request? This action cannot be undone.',
        confirmText: 'Yes, Cancel',
        cancelText: 'No, Go Back',
        type: 'danger'
    });
    
    if (!confirmed) {
        return;
    }
    
    // Show loading state on button
    const cancelBtn = document.querySelector(`button[onclick="cancelRequest(${requestId})"]`);
    const originalText = cancelBtn ? cancelBtn.innerHTML : 'Cancel';
    if (cancelBtn) {
        cancelBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cancelling...';
        cancelBtn.disabled = true;
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
            // Show success confirmation
            await showConfirmModal({
                title: 'Request Cancelled',
                message: 'Maintenance request has been cancelled successfully.',
                confirmText: 'OK',
                type: 'success'
            });
            
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
        
        // Show error confirmation
        await showConfirmModal({
            title: 'Error',
            message: error.message || 'Failed to cancel maintenance request. Please try again.',
            confirmText: 'OK',
            type: 'info'
        });
        
        if (window.showToast) {
            window.showToast(error.message, 'error');
        }
    } finally {
        if (cancelBtn) {
            cancelBtn.innerHTML = originalText;
            cancelBtn.disabled = false;
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