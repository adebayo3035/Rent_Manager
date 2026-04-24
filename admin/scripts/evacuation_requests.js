// admin/js/evacuation_requests.js

const EvacuationApp = {
    currentStatus: 'pending_review',
    currentRequestId: null,
    currentRequest: null,
    deductions: [],
    isRejectMode: false,

    // Initialize the application
    init: function() {
        this.loadRequests(this.currentStatus);
        this.loadStatistics();
        this.bindEvents();
    },

    // Bind event listeners
    bindEvents: function() {
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                this.currentStatus = btn.dataset.status;
                this.loadRequests(this.currentStatus);
            });
        });
    },

    // Load statistics
    loadStatistics: async function() {
        try {
            const response = await fetch('../backend/tenants/get_evacuation_requests.php?action=stats');
            const data = await response.json();
            
            if (data.success) {
                document.getElementById('pendingCount').textContent = data.data.pending_review || 0;
                document.getElementById('approvedCount').textContent = data.data.approved || 0;
                document.getElementById('completedCount').textContent = data.data.completed || 0;
                document.getElementById('rejectedCount').textContent = data.data.rejected || 0;
            }
        } catch (error) {
            console.error('Error loading statistics:', error);
        }
    },

    // Load evacuation requests
    loadRequests: async function(status) {
        const container = document.getElementById('requestsContainer');
        container.innerHTML = '<div class="loading-state"><div class="spinner"></div><p>Loading requests...</p></div>';
        
        try {
            const response = await fetch(`../backend/tenants/get_evacuation_requests.php?status=${status}`);
            const data = await response.json();
            
            if (data.success) {
                this.renderRequests(data.data.requests);
            } else {
                container.innerHTML = `<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>${data.message}</p></div>`;
            }
        } catch (error) {
            console.error('Error loading requests:', error);
            container.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading requests. Please try again.</p></div>';
        }
    },

    // Render requests cards
    renderRequests: function(requests) {
        const container = document.getElementById('requestsContainer');
        
        if (!requests || requests.length === 0) {
            container.innerHTML = '<div class="empty-state"><i class="fas fa-inbox"></i><p>No evacuation requests found</p></div>';
            return;
        }
        
        container.innerHTML = requests.map(request => `
            <div class="evacuation-card" data-request-id="${request.request_id}">
                <div class="card-header">
                    <h3>${this.escapeHtml(request.tenant_name)}</h3>
                    <span class="status-badge status-${request.status}">${request.status.replace('_', ' ').toUpperCase()}</span>
                </div>
                <div class="card-body">
                    <div class="info-row">
                        <span class="info-label">Property</span>
                        <span class="info-value">${this.escapeHtml(request.property_name)} - Apt ${request.apartment_number}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Requested Move-out</span>
                        <span class="info-value">${request.requested_move_out_date_formatted}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Reason</span>
                        <span class="info-value">${this.escapeHtml(request.reason)}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Submitted</span>
                        <span class="info-value">${request.created_at_formatted}</span>
                    </div>
                    ${request.early_termination_fee > 0 ? `
                    <div class="info-row">
                        <span class="info-label">Early Termination Fee</span>
                        <span class="info-value" style="color: #dc2626;">₦${this.formatNumber(request.early_termination_fee)}</span>
                    </div>
                    ` : ''}
                    ${request.outstanding_amount > 0 ? `
                    <div class="info-row">
                        <span class="info-label">Outstanding Balance</span>
                        <span class="info-value" style="color: #dc2626;">₦${this.formatNumber(request.outstanding_amount)}</span>
                    </div>
                    ` : ''}
                </div>
                <div class="card-actions">
                    ${request.status === 'pending_review' ? `
                        <button class="btn btn-primary" onclick="EvacuationApp.openReviewModal('${request.request_id}')">Review Request</button>
                    ` : request.status === 'approved' ? `
                        <button class="btn btn-success" onclick="EvacuationApp.openProcessModal('${request.request_id}')">Process Move-out</button>
                    ` : ''}
                    <button class="btn btn-outline" onclick="EvacuationApp.viewDetails('${request.request_id}')">View Details</button>
                </div>
            </div>
        `).join('');
    },

    // Open review modal
    openReviewModal: function(requestId) {
        this.currentRequestId = requestId;
        this.isRejectMode = false;
        
        // Fetch request details
        fetch(`../backend/tenants/get_evacuation_requests.php?request_id=${requestId}`)
            .then(res => res.json())
            .then(data => {
                if (data.success && data.data.requests.length > 0) {
                    this.currentRequest = data.data.requests[0];
                    this.showReviewModal();
                }
            });
    },

    showReviewModal: function() {
        const request = this.currentRequest;
        const infoHtml = `
            <div class="info-row"><strong>Tenant:</strong> ${this.escapeHtml(request.tenant_name)}</div>
            <div class="info-row"><strong>Property:</strong> ${this.escapeHtml(request.property_name)} - Apt ${request.apartment_number}</div>
            <div class="info-row"><strong>Requested Date:</strong> ${request.requested_move_out_date_formatted}</div>
            <div class="info-row"><strong>Reason:</strong> ${this.escapeHtml(request.reason)}</div>
            ${request.early_termination_fee > 0 ? `<div class="info-row"><strong>Early Termination Fee:</strong> ₦${this.formatNumber(request.early_termination_fee)}</div>` : ''}
        `;
        
        document.getElementById('reviewRequestInfo').innerHTML = infoHtml;
        document.getElementById('approvedMoveOutDate').value = request.requested_move_out_date;
        document.getElementById('reviewNotes').value = '';
        document.getElementById('rejectionReason').value = '';
        document.getElementById('rejectionReasonGroup').style.display = 'none';
        document.getElementById('rejectBtn').textContent = 'Reject';
        
        const modal = document.getElementById('reviewModal');
        modal.classList.add('active');
    },

    closeReviewModal: function() {
        const modal = document.getElementById('reviewModal');
        modal.classList.remove('active');
        this.currentRequestId = null;
        this.currentRequest = null;
        this.isRejectMode = false;
    },

    toggleRejectForm: function() {
        this.isRejectMode = !this.isRejectMode;
        const rejectionGroup = document.getElementById('rejectionReasonGroup');
        const rejectBtn = document.getElementById('rejectBtn');
        const approveBtn = document.querySelector('#reviewModal .btn-primary');
        
        if (this.isRejectMode) {
            rejectionGroup.style.display = 'block';
            rejectBtn.textContent = 'Cancel';
            approveBtn.style.display = 'none';
        } else {
            rejectionGroup.style.display = 'none';
            rejectBtn.textContent = 'Reject';
            approveBtn.style.display = 'inline-flex';
        }
    },

    submitReview: async function(action) {
        const data = { request_id: this.currentRequestId, action: action };
        
        if (action === 'approve') {
            const approvedDate = document.getElementById('approvedMoveOutDate').value;
            if (!approvedDate) {
                alert('Please select an approved move-out date');
                return;
            }
            data.approved_move_out_date = approvedDate;
            data.notes = document.getElementById('reviewNotes').value;
        } else {
            const rejectionReason = document.getElementById('rejectionReason').value;
            if (!rejectionReason) {
                alert('Please provide a reason for rejection');
                return;
            }
            data.rejection_reason = rejectionReason;
        }
        
        const submitBtn = action === 'approve' ? 
            document.querySelector('#reviewModal .btn-primary') : 
            document.getElementById('rejectBtn');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        submitBtn.disabled = true;
        
        try {
            const response = await fetch('../backend/tenants/review_evacuation_requests.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            
            const result = await response.json();
            
            if (result.success) {
                alert(result.message);
                this.closeReviewModal();
                this.loadRequests(this.currentStatus);
                this.loadStatistics();
            } else {
                alert(result.message);
            }
        } catch (error) {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        } finally {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    },

    // Open process modal
    openProcessModal: function(requestId) {
        this.currentRequestId = requestId;
        this.deductions = [];
        
        fetch(`../backend/tenants/get_evacuation_requests.php?request_id=${requestId}`)
            .then(res => res.json())
            .then(data => {
                if (data.success && data.data.requests.length > 0) {
                    this.currentRequest = data.data.requests[0];
                    this.showProcessModal();
                }
            });
    },

    showProcessModal: function() {
        const request = this.currentRequest;
        const infoHtml = `
            <div class="info-row"><strong>Tenant:</strong> ${this.escapeHtml(request.tenant_name)}</div>
            <div class="info-row"><strong>Property:</strong> ${this.escapeHtml(request.property_name)} - Apt ${request.apartment_number}</div>
            <div class="info-row"><strong>Approved Move-out:</strong> ${request.approved_move_out_date ? this.formatDate(request.approved_move_out_date) : 'N/A'}</div>
        `;
        
        document.getElementById('processRequestInfo').innerHTML = infoHtml;
        document.getElementById('actualMoveOutDate').value = request.approved_move_out_date || new Date().toISOString().split('T')[0];
        document.getElementById('deductionsContainer').innerHTML = '';
        this.deductions = [];
        this.updateSummary();
        
        const modal = document.getElementById('processModal');
        modal.classList.add('active');
    },

    closeProcessModal: function() {
        const modal = document.getElementById('processModal');
        modal.classList.remove('active');
        this.currentRequestId = null;
        this.currentRequest = null;
        this.deductions = [];
    },

    addDeductionRow: function() {
        const container = document.getElementById('deductionsContainer');
        const index = this.deductions.length;
        
        const rowHtml = `
            <div class="deduction-row" data-index="${index}">
                <div class="row-fields">
                    <select class="form-select deduction-type" data-index="${index}">
                        <option value="">Select type...</option>
                        <option value="Wall Damage">Wall Damage</option>
                        <option value="Floor Damage">Floor Damage</option>
                        <option value="Cleaning Fee">Cleaning Fee</option>
                        <option value="Missing Items">Missing Items</option>
                        <option value="Utility Bills">Utility Bills</option>
                        <option value="Painting">Painting</option>
                        <option value="Lock Replacement">Lock Replacement</option>
                        <option value="Other">Other</option>
                    </select>
                    <input type="number" class="form-input deduction-amount" data-index="${index}" placeholder="Amount" step="0.01">
                    <button class="btn-danger" style="padding: 8px 12px;" onclick="EvacuationApp.removeDeductionRow(${index})">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
                <input type="text" class="form-input deduction-desc" data-index="${index}" placeholder="Description (optional)">
            </div>
        `;
        
        container.insertAdjacentHTML('beforeend', rowHtml);
        this.deductions.push({ type: '', amount: 0, description: '' });
        
        // Add event listeners
        document.querySelector(`.deduction-type[data-index="${index}"]`).addEventListener('change', (e) => {
            this.deductions[index].type = e.target.value;
            this.updateSummary();
        });
        document.querySelector(`.deduction-amount[data-index="${index}"]`).addEventListener('input', (e) => {
            this.deductions[index].amount = parseFloat(e.target.value) || 0;
            this.updateSummary();
        });
        document.querySelector(`.deduction-desc[data-index="${index}"]`).addEventListener('input', (e) => {
            this.deductions[index].description = e.target.value;
        });
    },

    removeDeductionRow: function(index) {
        this.deductions.splice(index, 1);
        this.renderDeductions();
        this.updateSummary();
    },

    renderDeductions: function() {
        const container = document.getElementById('deductionsContainer');
        container.innerHTML = '';
        this.deductions.forEach((_, idx) => {
            this.addDeductionRow();
        });
    },

    updateSummary: function() {
        const request = this.currentRequest;
        if (!request) return;
        
        const securityDeposit = parseFloat(request.security_deposit) || 0;
        const outstandingBalance = parseFloat(request.outstanding_amount) || 0;
        const earlyFee = parseFloat(request.early_termination_fee) || 0;
        const totalDeductions = this.deductions.reduce((sum, d) => sum + (d.amount || 0), 0);
        const finalSettlement = securityDeposit - outstandingBalance - earlyFee - totalDeductions;
        
        const summaryHtml = `
            <div class="summary-row">
                <span>Security Deposit:</span>
                <span>₦${this.formatNumber(securityDeposit)}</span>
            </div>
            <div class="summary-row">
                <span>Outstanding Balance:</span>
                <span class="text-danger">-₦${this.formatNumber(outstandingBalance)}</span>
            </div>
            <div class="summary-row">
                <span>Early Termination Fee:</span>
                <span class="text-danger">-₦${this.formatNumber(earlyFee)}</span>
            </div>
            <div class="summary-row">
                <span>Total Deductions:</span>
                <span class="text-danger">-₦${this.formatNumber(totalDeductions)}</span>
            </div>
            <div class="summary-row summary-total">
                <strong>Final Settlement:</strong>
                <strong class="${finalSettlement > 0 ? 'text-success' : (finalSettlement < 0 ? 'text-danger' : '')}">
                    ₦${this.formatNumber(Math.abs(finalSettlement))} 
                    ${finalSettlement > 0 ? '(Refund to tenant)' : (finalSettlement < 0 ? '(Tenant owes)' : '(Zero balance)')}
                </strong>
            </div>
        `;
        
        document.getElementById('settlementSummary').innerHTML = summaryHtml;
    },

    submitProcess: async function() {
        const actualMoveOutDate = document.getElementById('actualMoveOutDate').value;
        if (!actualMoveOutDate) {
            alert('Please select the actual move-out date');
            return;
        }
        
        const validDeductions = this.deductions.filter(d => d.type && d.amount > 0);
        
        const data = {
            request_id: this.currentRequestId,
            actual_move_out_date: actualMoveOutDate,
            deductions: validDeductions
        };
        
        const submitBtn = document.querySelector('#processModal .btn-success');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        submitBtn.disabled = true;
        
        try {
            const response = await fetch('../backend/tenants/process_evacuation.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            
            const result = await response.json();
            
            if (result.success) {
                alert(result.data.message);
                this.closeProcessModal();
                this.loadRequests(this.currentStatus);
                this.loadStatistics();
            } else {
                alert(result.message);
            }
        } catch (error) {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        } finally {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    },

    // View details modal
    viewDetails: async function(requestId) {
        try {
            const response = await fetch(`../backend/tenants/get_evacuation_requests.php?request_id=${requestId}`);
            const data = await response.json();
            
            if (data.success && data.data.requests.length > 0) {
                const request = data.data.requests[0];
                this.showDetailsModal(request);
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Error loading details');
        }
    },

    showDetailsModal: function(request) {
        const deductionRows = request.deductions ? request.deductions.map(d => `
            <div class="summary-row">
                <span>${this.escapeHtml(d.deduction_type)}:</span>
                <span class="text-danger">-₦${this.formatNumber(d.amount)}</span>
                <small>${this.escapeHtml(d.description)}</small>
            </div>
        `).join('') : '';
        
        const detailsHtml = `
            <div class="request-info">
                <div class="info-row"><strong>Request ID:</strong> ${request.request_id}</div>
                <div class="info-row"><strong>Status:</strong> ${request.status.replace('_', ' ').toUpperCase()}</div>
                <div class="info-row"><strong>Submitted:</strong> ${request.created_at_formatted}</div>
                <div class="info-row"><strong>Tenant:</strong> ${this.escapeHtml(request.tenant_name)}</div>
                <div class="info-row"><strong>Property:</strong> ${this.escapeHtml(request.property_name)} - Apt ${request.apartment_number}</div>
                <div class="info-row"><strong>Requested Move-out:</strong> ${request.requested_move_out_date_formatted}</div>
                <div class="info-row"><strong>Reason:</strong> ${this.escapeHtml(request.reason)}</div>
                ${request.notes ? `<div class="info-row"><strong>Notes:</strong> ${this.escapeHtml(request.notes)}</div>` : ''}
                ${request.approved_move_out_date ? `<div class="info-row"><strong>Approved Move-out:</strong> ${this.formatDate(request.approved_move_out_date)}</div>` : ''}
                ${request.rejection_reason ? `<div class="info-row"><strong>Rejection Reason:</strong> ${this.escapeHtml(request.rejection_reason)}</div>` : ''}
            </div>
            <div class="summary-box">
                <div class="summary-row"><strong>Security Deposit:</strong> ₦${this.formatNumber(request.security_deposit)}</div>
                <div class="summary-row"><strong>Outstanding Balance:</strong> ₦${this.formatNumber(request.outstanding_amount)}</div>
                <div class="summary-row"><strong>Early Termination Fee:</strong> ₦${this.formatNumber(request.early_termination_fee)}</div>
                ${deductionRows}
                <div class="summary-row summary-total">
                    <strong>Final Settlement:</strong>
                    <strong>₦${this.formatNumber(Math.abs(request.final_settlement_amount))} 
                    ${request.final_settlement_amount > 0 ? '(Refund)' : (request.final_settlement_amount < 0 ? '(Due)' : '(Zero)')}</strong>
                </div>
            </div>
        `;
        
        document.getElementById('detailsBody').innerHTML = detailsHtml;
        const modal = document.getElementById('detailsModal');
        modal.classList.add('active');
    },

    closeDetailsModal: function() {
        const modal = document.getElementById('detailsModal');
        modal.classList.remove('active');
    },

    // Utility functions
    formatNumber: function(value) {
        if (!value && value !== 0) return '0.00';
        return new Intl.NumberFormat('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value);
    },

    formatDate: function(dateString) {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    },

    escapeHtml: function(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
};

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    EvacuationApp.init();
});
