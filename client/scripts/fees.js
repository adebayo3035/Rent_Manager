// client/scripts/fees.js - Client Portal Fee Management

let clientFees = [];
let currentFilter = 'all'; // all, pending, paid, overdue
let currentTab = 'all'; // all, recurring, one-time
let currentProperty = '';
let feeTypes = [];
let properties = [];

document.addEventListener('DOMContentLoaded', function() {
    initializeFees();
});

async function initializeFees() {
    if (window.currentUser || window.currentClient) {
        await loadFeeTypes();
        await loadProperties();
        await loadClientFees();
    } else {
        window.addEventListener('userDataLoaded', async function(e) {
            await loadFeeTypes();
            await loadProperties();
            await loadClientFees();
        });
        
        setTimeout(async () => {
            if (!window.currentUser && !window.currentClient) {
                await loadFeeTypes();
                await loadProperties();
                await loadClientFees();
            }
        }, 1000);
    }
}

async function loadFeeTypes() {
    try {
        const response = await fetch('../backend/fees/fetch_fee_types.php');
        const data = await response.json();
        
        if (data.success) {
            feeTypes = data.data?.fee_types || data.message?.fee_types || [];
        }
    } catch (error) {
        console.error('Error loading fee types:', error);
    }
}

async function loadProperties() {
    try {
        const response = await fetch('../backend/fees/fetch_client_fees.php?limit=1');
        const data = await response.json();
        
        if (data.success && data.data.properties) {
            properties = data.data.properties;
        }
    } catch (error) {
        console.error('Error loading properties:', error);
    }
}

async function loadClientFees() {
    try {
        let url = `../backend/fees/fetch_client_fees.php?status=${currentFilter}`;
        if (currentProperty) {
            url += `&property_code=${currentProperty}`;
        }
        
        const response = await fetch(url);
        const data = await response.json();
        
        console.log('Client fees response:', data);
        
        if (data.success) {
            clientFees = data.data?.fees || [];
            properties = data.data?.properties || properties;
            renderFeesPage(data.data?.summary || {});
        } else {
            throw new Error(data.message || 'Failed to load fees');
        }
    } catch (error) {
        console.error('Error loading client fees:', error);
        if (window.showToast) {
            window.showToast('Failed to load fees', 'error');
        }
        showEmptyState();
    }
}

function renderFeesPage(summary) {
    const contentArea = document.getElementById('contentArea');
    if (!contentArea) return;
    
    // Calculate summary if not provided
    const totalPending = summary.total_pending || clientFees.filter(f => f.status === 'pending').reduce((sum, f) => sum + parseFloat(f.amount), 0);
    const totalPaid = summary.total_paid || clientFees.filter(f => f.status === 'paid').reduce((sum, f) => sum + parseFloat(f.amount), 0);
    const totalOverdue = summary.total_overdue || clientFees.filter(f => f.status === 'overdue').reduce((sum, f) => sum + parseFloat(f.amount), 0);
    
    // Filter fees based on current tab
    let filteredFees = clientFees;
    if (currentTab === 'recurring') {
        filteredFees = clientFees.filter(f => f.is_recurring === true);
    } else if (currentTab === 'one-time') {
        filteredFees = clientFees.filter(f => f.is_recurring === false);
    }
    
    // Apply status filter
    if (currentFilter !== 'all') {
        filteredFees = filteredFees.filter(f => f.status === currentFilter);
    }
    
    const html = `
        <div class="fees-container">
            <div class="page-header">
                <h1>Tenant Fees</h1>
                <p>View and manage fees charged to tenants across your properties</p>
            </div>
            
            <div class="summary-cards">
                <div class="summary-card total-pending">
                    <h4>Pending Payments</h4>
                    <div class="amount">₦${formatNumber(totalPending)}</div>
                    <div class="label">Fees awaiting payment</div>
                </div>
                <div class="summary-card total-paid">
                    <h4>Total Collected</h4>
                    <div class="amount">₦${formatNumber(totalPaid)}</div>
                    <div class="label">All time payments</div>
                </div>
                <div class="summary-card total-overdue">
                    <h4>Overdue</h4>
                    <div class="amount">₦${formatNumber(totalOverdue)}</div>
                    <div class="label">Past due date</div>
                </div>
            </div>
            
            <div class="fee-filters">
                <div class="filter-group">
                    <label>Property</label>
                    <select class="filter-select" id="propertyFilter" onchange="filterByProperty()">
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
                    <select class="filter-select" id="statusFilter" onchange="filterByStatus()">
                        <option value="all" ${currentFilter === 'all' ? 'selected' : ''}>All</option>
                        <option value="pending" ${currentFilter === 'pending' ? 'selected' : ''}>Pending</option>
                        <option value="paid" ${currentFilter === 'paid' ? 'selected' : ''}>Paid</option>
                        <option value="overdue" ${currentFilter === 'overdue' ? 'selected' : ''}>Overdue</option>
                    </select>
                </div>
            </div>
            
            <div class="fee-tabs">
                <button class="fee-tab ${currentTab === 'all' ? 'active' : ''}" onclick="switchFeeTab('all')">All Fees</button>
                <button class="fee-tab ${currentTab === 'recurring' ? 'active' : ''}" onclick="switchFeeTab('recurring')">Recurring Fees</button>
                <button class="fee-tab ${currentTab === 'one-time' ? 'active' : ''}" onclick="switchFeeTab('one-time')">One-Time Fees</button>
            </div>
            
            ${filteredFees.length === 0 ? `
                <div class="empty-state">
                    <i class="fas fa-receipt"></i>
                    <h3>No Fees Found</h3>
                    <p>No fees found for your properties.</p>
                </div>
            ` : `
                <div class="fees-grid">
                    ${filteredFees.map(fee => `
                        <div class="fee-card" onclick="viewFeeDetails(${fee.tenant_fee_id})">
                            <div class="fee-header">
                                <div class="fee-info">
                                    <span class="fee-name">${escapeHtml(fee.fee_name)}</span>
                                    <span class="fee-property">${escapeHtml(fee.property_name)} - Apt ${escapeHtml(fee.apartment_number)}</span>
                                </div>
                                <span class="fee-status status-${fee.status}">${fee.status.toUpperCase()}</span>
                            </div>
                            <div class="fee-details">
                                <div class="fee-detail-item">
                                    <span class="fee-detail-label">Tenant</span>
                                    <span class="fee-detail-value">${escapeHtml(fee.tenant_name)}</span>
                                </div>
                                <div class="fee-detail-item">
                                    <span class="fee-detail-label">Amount</span>
                                    <span class="fee-detail-value">₦${formatNumber(fee.amount)}</span>
                                </div>
                                <div class="fee-detail-item">
                                    <span class="fee-detail-label">Due Date</span>
                                    <span class="fee-detail-value">${formatDate(fee.due_date)}</span>
                                </div>
                                ${fee.is_recurring ? `
                                    <div class="fee-detail-item">
                                        <span class="fee-detail-label">Recurrence</span>
                                        <span class="fee-detail-value">${fee.recurrence_period || 'Monthly'}</span>
                                    </div>
                                ` : ''}
                                <div class="fee-detail-item">
                                    <span class="fee-detail-label">Type</span>
                                    <span class="fee-detail-value">${fee.is_recurring ? 'Recurring' : 'One-time'}</span>
                                </div>
                                ${fee.status === 'paid' && fee.receipt_number ? `
                                    <div class="fee-detail-item">
                                        <span class="fee-detail-label">Receipt No.</span>
                                        <span class="fee-detail-value">${escapeHtml(fee.receipt_number)}</span>
                                    </div>
                                ` : ''}
                            </div>
                            <div class="fee-actions" onclick="event.stopPropagation()">
                                ${fee.status === 'paid' && fee.receipt_number ? `
                                    <button class="btn-view-receipt" onclick="downloadReceiptByFeeId(${fee.tenant_fee_id})">
                                        <i class="fas fa-download"></i> Receipt
                                    </button>
                                ` : ''}
                                <button class="btn-view" onclick="viewFeeDetails(${fee.tenant_fee_id})">
                                    <i class="fas fa-info-circle"></i> Details
                                </button>
                            </div>
                        </div>
                    `).join('')}
                </div>
            `}
        </div>
    `;
    
    contentArea.innerHTML = html;
}

function showEmptyState() {
    const contentArea = document.getElementById('contentArea');
    if (!contentArea) return;
    
    contentArea.innerHTML = `
        <div class="fees-container">
            <div class="page-header">
                <h1>Tenant Fees</h1>
                <p>View and manage fees charged to tenants across your properties</p>
            </div>
            <div class="empty-state">
                <i class="fas fa-receipt"></i>
                <h3>No Fees Found</h3>
                <p>No fees found for your properties.</p>
            </div>
        </div>
    `;
}

function filterByProperty() {
    const propertyFilter = document.getElementById('propertyFilter');
    currentProperty = propertyFilter?.value || '';
    currentPage = 1;
    loadClientFees();
}

function filterByStatus() {
    const statusFilter = document.getElementById('statusFilter');
    if (statusFilter) {
        currentFilter = statusFilter.value;
        loadClientFees();
    }
}

function switchFeeTab(tab) {
    currentTab = tab;
    renderFeesPage();
}

async function viewFeeDetails(feeId) {
    const fee = clientFees.find(f => f.tenant_fee_id === feeId);
    if (!fee) return;
    
    // Check if modal exists, if not create it
    let modal = document.getElementById('feeDetailsModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'feeDetailsModal';
        modal.className = 'modal';
        modal.innerHTML = `
            <div class="modal-content" style="max-width: 500px;">
                <div class="modal-header">
                    <h3><i class="fas fa-receipt"></i> Fee Details</h3>
                    <button class="modal-close" onclick="closeModal('feeDetailsModal')">&times;</button>
                </div>
                <div class="modal-body" id="feeDetailsBody">
                    <!-- Content will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="closeModal('feeDetailsModal')">Close</button>
                    <button class="btn-primary" id="downloadReceiptBtn" style="display: none;" onclick="downloadReceiptFromModal()">
                        <i class="fas fa-download"></i> Download Receipt
                    </button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }
    
    const modalBody = document.getElementById('feeDetailsBody');
    modalBody.innerHTML = `
        <div class="compact-fee-details">
            <!-- Fee Information -->
            <div class="compact-section">
                <div class="compact-row">
                    <span class="compact-label">Fee:</span>
                    <span class="compact-value">${escapeHtml(fee.fee_name)}</span>
                </div>
                <div class="compact-row">
                    <span class="compact-label">Amount:</span>
                    <span class="compact-value">₦${formatNumber(fee.amount)}</span>
                </div>
                <div class="compact-row">
                    <span class="compact-label">Due Date:</span>
                    <span class="compact-value">${formatDate(fee.due_date)}</span>
                </div>
                <div class="compact-row">
                    <span class="compact-label">Status:</span>
                    <span class="compact-value">
                        <span class="fee-status status-${fee.status}">${fee.status.toUpperCase()}</span>
                    </span>
                </div>
                ${fee.is_recurring ? `
                <div class="compact-row">
                    <span class="compact-label">Recurrence:</span>
                    <span class="compact-value">${fee.recurrence_period || 'Monthly'}</span>
                </div>
                ` : ''}
            </div>
            
            <!-- Tenant Information -->
            <div class="compact-section">
                <div class="compact-section-title">Tenant</div>
                <div class="compact-row">
                    <span class="compact-label">Name:</span>
                    <span class="compact-value">${escapeHtml(fee.tenant_name)}</span>
                </div>
                <div class="compact-row">
                    <span class="compact-label">Email:</span>
                    <span class="compact-value">${escapeHtml(fee.tenant_email)}</span>
                </div>
                <div class="compact-row">
                    <span class="compact-label">Phone:</span>
                    <span class="compact-value">${escapeHtml(fee.tenant_phone)}</span>
                </div>
            </div>
            
            <!-- Property Information -->
            <div class="compact-section">
                <div class="compact-section-title">Property</div>
                <div class="compact-row">
                    <span class="compact-label">Property:</span>
                    <span class="compact-value">${escapeHtml(fee.property_name)}</span>
                </div>
                <div class="compact-row">
                    <span class="compact-label">Apartment:</span>
                    <span class="compact-value">${escapeHtml(fee.apartment_number)}</span>
                </div>
            </div>
            
            ${fee.status === 'paid' && fee.payment_date ? `
            <div class="compact-section">
                <div class="compact-section-title">Payment</div>
                <div class="compact-row">
                    <span class="compact-label">Date:</span>
                    <span class="compact-value">${formatDateTime(fee.payment_date)}</span>
                </div>
                <div class="compact-row">
                    <span class="compact-label">Method:</span>
                    <span class="compact-value">${formatPaymentMethod(fee.payment_method)}</span>
                </div>
                <div class="compact-row">
                    <span class="compact-label">Receipt:</span>
                    <span class="compact-value">${escapeHtml(fee.receipt_number)}</span>
                </div>
            </div>
            ` : ''}
            
            ${fee.notes ? `
            <div class="compact-section">
                <div class="compact-row">
                    <span class="compact-label">Notes:</span>
                    <span class="compact-value note-text">${escapeHtml(fee.notes)}</span>
                </div>
            </div>
            ` : ''}
        </div>
    `;
    
    // Show/hide download receipt button
    const downloadBtn = document.getElementById('downloadReceiptBtn');
    if (downloadBtn) {
        if (fee.status === 'paid' && fee.receipt_number) {
            downloadBtn.style.display = 'inline-flex';
            downloadBtn.setAttribute('data-fee-id', fee.tenant_fee_id);
        } else {
            downloadBtn.style.display = 'none';
        }
    }
    
    openModal('feeDetailsModal');
}

// Function to download receipt from modal
async function downloadReceiptFromModal() {
    const downloadBtn = document.getElementById('downloadReceiptBtn');
    const feeId = downloadBtn?.getAttribute('data-fee-id');
    if (feeId) {
        await downloadReceiptByFeeId(feeId);
    }
}

// Download receipt by tenant_fee_id (for paid fees)
async function downloadReceiptByFeeId(tenantFeeId) {
    try {
        // First, get the payment details for this fee
        const response = await fetch(`../backend/fees/get_payment_by_fee_id.php?tenant_fee_id=${tenantFeeId}`);
        const data = await response.json();
        
        if (data.success && data.data.payment_id) {
            window.open(`../backend/fees/download_fee_receipt.php?payment_id=${data.data.payment_id}`, '_blank');
        } else {
            throw new Error('Receipt not found');
        }
    } catch (error) {
        console.error('Error downloading receipt:', error);
        if (window.showToast) {
            window.showToast('Receipt not available', 'error');
        }
    }
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

function formatPaymentMethod(method) {
    const methods = {
        'bank_transfer': 'Bank Transfer',
        'card': 'Card',
        'cash': 'Cash',
        'cheque': 'Cheque'
    };
    return methods[method] || method || 'N/A';
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) modal.classList.add('active');
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) modal.classList.remove('active');
}