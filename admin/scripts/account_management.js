// Account Reactivation Manager
class AccountReactivationManager {
    constructor() {
        this.currentPage = 1;
        this.limit = 10;
        this.filters = {
            user_type: '',
            status: '',
            search: '',
            date_from: '',
            date_to: ''
        };
        this.currentRequestId = null;
        this.statistics = {};
        
        this.init();
    }
    
    init() {
        this.bindEvents();
        this.initializeSelect2();
        this.loadData();
        this.loadStatistics();
    }
    
    bindEvents() {
        // Filter events
        document.getElementById('applyFilters').addEventListener('click', () => this.applyFilters());
        document.getElementById('resetFilters').addEventListener('click', () => this.resetFilters());
        
        // Review modal events
        document.getElementById('reviewAction').addEventListener('change', (e) => {
            this.toggleRejectionReason(e.target.value === 'reject');
        });
        
        document.getElementById('submitReviewBtn').addEventListener('click', () => this.submitReview());
        document.getElementById('cancelReviewBtn').addEventListener('click', () => this.closeReviewModal());
        
        // Close buttons
        document.getElementById('closeDetailsBtn').addEventListener('click', () => {
            this.closeModal('requestDetailsModal');
        });
        
        // Close modal when clicking X
        document.querySelectorAll('.modal .close').forEach(closeBtn => {
            closeBtn.addEventListener('click', () => {
                const modal = closeBtn.closest('.modal');
                if (modal) {
                    modal.style.display = 'none';
                }
            });
        });
        
        // Close modal when clicking outside
        window.addEventListener('click', (e) => {
            if (e.target.classList.contains('modal')) {
                e.target.style.display = 'none';
            }
        });
        
        // Search on enter key
        document.getElementById('filterSearch').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                this.applyFilters();
            }
        });
    }
    
    initializeSelect2() {
        $('.select2').select2({
            width: '100%',
            placeholder: 'Select an option',
            allowClear: true
        });
    }
    
    async loadData(page = 1) {
        this.currentPage = page;
        
        const params = new URLSearchParams({
            page: this.currentPage,
            limit: this.limit,
            ...this.filters
        });
        
        try {
            // UI.showLoader();
            
            const response = await fetch(`../backend/utilities/fetch_account_reactivation_requests.php?${params}`);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const data = await response.json();
            
            if (data.success) {
                this.renderTable(data.account_reactivation_requests);
                this.renderPagination(data.pagination);
                this.updateStatistics(data.statistics || {});
            } else {
                throw new Error(data.message || 'Failed to load data');
            }
        } catch (error) {
            console.error('Error loading data:', error);
            UI.toast(`Error: ${error.message}`, 'error');
        } finally {
            // UI.hideLoader();
        }
    }
    
    async loadStatistics() {
        try {
            const response = await fetch('../backend/utilities/get_reactivation_stats.php');
            if (response.ok) {
                const data = await response.json();
                if (data.success) {
                    this.updateStatistics(data.statistics || {});
                }
            }
        } catch (error) {
            console.error('Error loading statistics:', error);
        }
    }
    
    updateStatistics(stats) {
        this.statistics = stats;
        
        // Update stats cards
        const total = Object.values(stats).reduce((sum, count) => sum + parseInt(count), 0);
        
        const statElements = {
            'Total Requests': total,
            'Pending': stats.pending || 0,
            'Approved': stats.approved || 0,
            'Rejected': stats.rejected || 0
        };
        
        Object.entries(statElements).forEach(([label, value], index) => {
            const card = document.getElementById('reactivationStats').children[index];
            if (card) {
                card.querySelector('.stat-value').textContent = value;
                card.querySelector('.stat-label').textContent = label;
            }
        });
    }
    
    renderTable(requests) {
        const tbody = document.getElementById('reactivationSummaryBody');
        
        if (!requests || requests.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="9" class="text-center" style="padding: 40px;">
                        <div style="font-size: 16px; color: #6c757d;">
                            <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 20px; display: block;"></i>
                            No reactivation requests found
                        </div>
                    </td>
                </tr>
            `;
            return;
        }
        
        tbody.innerHTML = requests.map(request => this.renderTableRow(request)).join('');
        
        // Bind row click events
        this.bindRowEvents();
    }
    
    renderTableRow(request) {
        const userTypeClasses = {
            'admin': 'user-type-admin',
            'agent': 'user-type-agent',
            'client': 'user-type-client',
            'tenant': 'user-type-tenant'
        };
        
        const statusClasses = {
            'pending': 'status-pending',
            'approved': 'status-approved',
            'rejected': 'status-rejected',
            'expired': 'status-expired'
        };
        
        const userTypeDisplay = {
            'admin': 'Admin',
            'agent': 'Agent',
            'client': 'Client',
            'tenant': 'Tenant'
        };
        
        const statusDisplay = {
            'pending': 'Pending',
            'approved': 'Approved',
            'rejected': 'Rejected',
            'expired': 'Expired'
        };
        
        const formattedDate = request.created_at_formatted || 
            (request.created_at ? new Date(request.created_at).toLocaleDateString() : '-');
        
        const reviewDate = request.review_timestamp_formatted || 
            (request.review_timestamp ? new Date(request.review_timestamp).toLocaleDateString() : '-');
        
        const userTypeClass = userTypeClasses[request.user_type] || 'user-type-admin';
        const statusClass = statusClasses[request.status] || 'status-pending';
        
        return `
            <tr data-id="${request.id}">
                <td><strong>#${request.id}</strong></td>
                <td>
                    <div>${request.user_full_name || 'Unknown'}</div>
                    <small style="color: #6c757d; font-size: 11px;">ID: ${request.user_id}</small>
                </td>
                <td>
                    <span class="user-type-badge ${userTypeClass}">
                        ${userTypeDisplay[request.user_type] || request.user_type}
                    </span>
                </td>
                <td>
                    <div>${request.email}</div>
                    <small style="color: #6c757d; font-size: 11px;">${request.user_phone || 'No phone'}</small>
                </td>
                <td>${formattedDate}</td>
                <td>
                    <span class="status-badge ${statusClass}">
                        ${statusDisplay[request.status] || request.status}
                    </span>
                </td>
                <td>${reviewDate}</td>
                <td>${request.reviewed_by_name || 'Not reviewed'}</td>
                <td>
                    <div class="action-buttons">
                        <button class="action-btn action-view view-details" data-id="${request.id}">
                            <i class="fas fa-eye"></i> View
                        </button>
                        ${request.status === 'pending' ? `
                            <button class="action-btn action-approve review-request" data-id="${request.id}">
                                <i class="fas fa-check"></i> Review
                            </button>
                        ` : ''}
                    </div>
                </td>
            </tr>
        `;
    }
    
    bindRowEvents() {
        // View details
        document.querySelectorAll('.view-details').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const requestId = btn.dataset.id;
                this.showRequestDetails(requestId);
            });
        });
        
        // Review request
        document.querySelectorAll('.review-request').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const requestId = btn.dataset.id;
                this.openReviewModal(requestId);
            });
        });
        
        // Row click for details
        document.querySelectorAll('#reactivationSummaryBody tr').forEach(row => {
            row.addEventListener('click', (e) => {
                if (!e.target.closest('.action-btn')) {
                    const requestId = row.dataset.id;
                    this.showRequestDetails(requestId);
                }
            });
        });
    }
    
    async showRequestDetails(requestId) {
        try {
            // UI.showLoader();
            
            const response = await fetch(`../backend/utilities/fetch_account_reactivation_request_details.php?id=${requestId}`);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const data = await response.json();
            
            if (data.success) {
                this.populateDetailsModal(data.request);
                this.openModal('requestDetailsModal');
            } else {
                throw new Error(data.message || 'Failed to load request details');
            }
        } catch (error) {
            console.error('Error loading request details:', error);
            UI.toast(`Error: ${error.message}`, 'error');
        } finally {
            // UI.hideLoader();
        }
    }
    
    populateDetailsModal(request) {
        // Map user type to display class
        const userTypeClasses = {
            'admin': 'user-type-admin',
            'agent': 'user-type-agent',
            'client': 'user-type-client',
            'tenant': 'user-type-tenant'
        };
        
        const statusClasses = {
            'pending': 'status-pending',
            'approved': 'status-approved',
            'rejected': 'status-rejected',
            'expired': 'status-expired'
        };
        
        const userTypeDisplay = {
            'admin': 'Admin',
            'agent': 'Agent',
            'client': 'Client',
            'tenant': 'Tenant'
        };
        
        const statusDisplay = {
            'pending': 'Pending',
            'approved': 'Approved',
            'rejected': 'Rejected',
            'expired': 'Expired'
        };
        
        const currentStatus = request.user_current_status;
        const currentStatusDisplay = currentStatus === '0' ? 'Active' : 
                                   currentStatus === '1' ? 'Inactive' : 
                                   currentStatus === '2' ? 'Suspended' : 
                                   currentStatus || 'Unknown';
        
        const currentStatusClass = currentStatus === '0' ? 'status-approved' : 
                                 currentStatus === '1' ? 'status-rejected' : 
                                 currentStatus === '2' ? 'status-expired' : 
                                 'status-pending';
        
        // Populate all fields
        document.getElementById('detailRequestId').textContent = `#${request.id}`;
        document.getElementById('detailUserName').textContent = request.user_full_name || 'Unknown';
        document.getElementById('detailEmail').textContent = request.email;
        document.getElementById('detailPhone').textContent = request.user_phone || 'Not provided';
        
        // User type badge
        const userTypeBadge = document.getElementById('detailUserType');
        userTypeBadge.textContent = userTypeDisplay[request.user_type] || request.user_type;
        userTypeBadge.className = `user-type-badge ${userTypeClasses[request.user_type] || 'user-type-admin'}`;
        
        // Status badges
        document.getElementById('detailCurrentStatus').textContent = currentStatusDisplay;
        document.getElementById('detailCurrentStatus').className = `status-badge ${currentStatusClass}`;
        
        document.getElementById('detailRequestStatus').textContent = statusDisplay[request.status] || request.status;
        document.getElementById('detailRequestStatus').className = `status-badge ${statusClasses[request.status] || 'status-pending'}`;
        
        // Dates
        document.getElementById('detailRequestDate').textContent = 
            request.created_at_formatted || 
            (request.created_at ? new Date(request.created_at).toLocaleString() : '-');
        
        document.getElementById('detailReviewDate').textContent = 
            request.review_timestamp_formatted || 
            (request.review_timestamp ? new Date(request.review_timestamp).toLocaleString() : '-');
        
        document.getElementById('detailReviewedBy').textContent = request.reviewed_by_name || 'Not reviewed';
        document.getElementById('detailRejectionReason').textContent = request.rejection_reason || 'Not provided';
        document.getElementById('detailReason').textContent = request.request_reason || 'No reason provided';
        document.getElementById('detailReviewNotes').textContent = request.review_notes || 'No internal notes';
    }
    
    openReviewModal(requestId) {
        this.currentRequestId = requestId;
        document.getElementById('reviewRequestId').value = requestId;
        
        // Reset form
        document.getElementById('reviewAction').value = '';
        document.getElementById('reviewNotes').value = '';
        document.getElementById('rejectionReason').value = '';
        document.getElementById('reviewMessage').style.display = 'none';
        this.toggleRejectionReason(false);
        
        this.openModal('reviewModal');
    }
    
    closeReviewModal() {
        this.currentRequestId = null;
        this.closeModal('reviewModal');
    }
    
    toggleRejectionReason(show) {
        const group = document.getElementById('rejectionReasonGroup');
        const reasonField = document.getElementById('rejectionReason');
        
        if (show) {
            group.style.display = 'block';
            reasonField.required = true;
        } else {
            group.style.display = 'none';
            reasonField.required = false;
        }
    }
    
    async submitReview() {
        const requestId = this.currentRequestId;
        const action = document.getElementById('reviewAction').value;
        const notes = document.getElementById('reviewNotes').value.trim();
        const rejectionReason = document.getElementById('rejectionReason').value.trim();
        
        // Validation
        if (!action) {
            this.showReviewMessage('Please select an action (Approve or Reject)', 'error');
            return;
        }
        
        if (action === 'reject' && !rejectionReason) {
            this.showReviewMessage('Please provide a rejection reason for the user', 'error');
            return;
        }
        
        UI.confirm(`Are you sure you want to ${action} this reactivation request?`, async () => {
            try {
                // UI.showLoader();
                
                const response = await fetch('../backend/utilities/review_reactivation_request.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        request_id: requestId,
                        action: action,
                        notes: notes,
                        rejection_reason: rejectionReason
                    })
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                
                const data = await response.json();
                
                if (data.success) {
                    UI.toast(`Request ${action === 'approve' ? 'approved' : 'rejected'} successfully!`, 'success');
                    this.closeReviewModal();
                    this.loadData(this.currentPage);
                    this.loadStatistics();
                } else {
                    throw new Error(data.message || `Failed to ${action} request`);
                }
            } catch (error) {
                console.error('Error submitting review:', error);
                this.showReviewMessage(`Error: ${error.message}`, 'error');
            } finally {
                // UI.hideLoader();
            }
        });
    }
    
    showReviewMessage(message, type) {
        const messageDiv = document.getElementById('reviewMessage');
        messageDiv.textContent = message;
        messageDiv.className = `alert alert-${type}`;
        messageDiv.style.display = 'block';
        
        // Auto-hide after 5 seconds
        setTimeout(() => {
            messageDiv.style.display = 'none';
        }, 5000);
    }
    
    applyFilters() {
        this.filters = {
            user_type: document.getElementById('filterUserType').value,
            status: document.getElementById('filterStatus').value,
            search: document.getElementById('filterSearch').value.trim(),
            date_from: document.getElementById('filterDateFrom').value,
            date_to: document.getElementById('filterDateTo').value
        };
        
        this.loadData(1);
    }
    
    resetFilters() {
        document.getElementById('filterUserType').value = '';
        document.getElementById('filterStatus').value = '';
        document.getElementById('filterSearch').value = '';
        document.getElementById('filterDateFrom').value = '';
        document.getElementById('filterDateTo').value = '';
        
        // Trigger Select2 update
        $('#filterUserType').val(null).trigger('change');
        $('#filterStatus').val(null).trigger('change');
        
        this.filters = {
            user_type: '',
            status: '',
            search: '',
            date_from: '',
            date_to: ''
        };
        
        this.loadData(1);
    }
    
    renderPagination(pagination) {
        const container = document.getElementById('reactivationPagination');
        
        if (!pagination || pagination.total_pages <= 1) {
            container.innerHTML = '';
            return;
        }
        
        let html = '<div class="pagination-controls">';
        
        // Previous button
        if (pagination.has_prev_page) {
            html += `<button class="pagination-btn" data-page="${this.currentPage - 1}">
                        <i class="fas fa-chevron-left"></i> Previous
                     </button>`;
        }
        
        // Page numbers
        const startPage = Math.max(1, this.currentPage - 2);
        const endPage = Math.min(pagination.total_pages, startPage + 4);
        
        for (let i = startPage; i <= endPage; i++) {
            html += `<button class="pagination-btn ${i === this.currentPage ? 'active' : ''}" 
                            data-page="${i}">${i}</button>`;
        }
        
        // Next button
        if (pagination.has_next_page) {
            html += `<button class="pagination-btn" data-page="${this.currentPage + 1}">
                        Next <i class="fas fa-chevron-right"></i>
                     </button>`;
        }
        
        html += `</div>
                <div class="pagination-info">
                    Page ${this.currentPage} of ${pagination.total_pages} | 
                    Total: ${pagination.total} requests
                </div>`;
        
        container.innerHTML = html;
        
        // Bind pagination events
        container.querySelectorAll('.pagination-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const page = parseInt(btn.dataset.page);
                if (page && page !== this.currentPage) {
                    this.loadData(page);
                }
            });
        });
    }
    
    openModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.style.display = 'flex';
        }
    }
    
    closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.style.display = 'none';
        }
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    // Initialize manager
    const manager = new AccountReactivationManager();
    window.reactivationManager = manager;
    
    // Make manager accessible globally
    console.log('Account Reactivation Manager initialized:', manager);
});