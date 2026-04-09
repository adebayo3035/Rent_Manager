// tenant-fees.js
// let currentUser = null;
let tenantFees = [];
let currentFilter = 'all'; // all, pending, paid, overdue
let currentTab = 'all'; // all, recurring, one-time
let feeTypes = [];

document.addEventListener('DOMContentLoaded', function() {
    initializeFees();
});

async function initializeFees() {
    if (window.currentUser) {
        currentUser = window.currentUser;
        await loadFeeTypes();
        await loadTenantFees();
    } else {
        window.addEventListener('userDataLoaded', async function(e) {
            currentUser = e.detail;
            await loadFeeTypes();
            await loadTenantFees();
        });
        
        setTimeout(async () => {
            if (!window.currentUser && !currentUser) {
                await loadFeeTypes();
                await loadTenantFees();
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

async function loadTenantFees() {
    try {
        const response = await fetch(`../backend/fees/fetch_tenant_fees.php?status=${currentFilter}`);
        const data = await response.json();
        
        console.log('Tenant fees response:', data);
        
        if (data.success) {
            tenantFees = data.data?.fees || data.message?.fees || [];
            renderFeesPage();
        } else {
            throw new Error(data.message || 'Failed to load fees');
        }
    } catch (error) {
        console.error('Error loading tenant fees:', error);
        if (window.showToast) {
            window.showToast('Failed to load fees', 'error');
        }
        showEmptyState();
    }
}

function renderFeesPage() {
    const contentArea = document.getElementById('contentArea');
    if (!contentArea) return;
    
    // Calculate summary
    const totalPending = tenantFees.filter(f => f.status === 'pending').reduce((sum, f) => sum + parseFloat(f.amount), 0);
    const totalPaid = tenantFees.filter(f => f.status === 'paid').reduce((sum, f) => sum + parseFloat(f.amount), 0);
    const totalOverdue = tenantFees.filter(f => f.status === 'overdue').reduce((sum, f) => sum + parseFloat(f.amount), 0);
    
    // Filter fees based on current tab
    let filteredFees = tenantFees;
    if (currentTab === 'recurring') {
        filteredFees = tenantFees.filter(f => f.is_recurring === true);
    } else if (currentTab === 'one-time') {
        filteredFees = tenantFees.filter(f => f.is_recurring === false);
    }
    
    // Apply status filter
    if (currentFilter !== 'all') {
        filteredFees = filteredFees.filter(f => f.status === currentFilter);
    }
    
    const html = `
        <div class="fees-container">
            <div class="page-header">
                <h1>My Fees</h1>
                <p>View and manage your apartment fees</p>
            </div>
            
            <div class="summary-cards">
                <div class="summary-card total-pending">
                    <h4>Pending Payments</h4>
                    <div class="amount">₦${formatNumber(totalPending)}</div>
                    <div class="label">Fees awaiting payment</div>
                </div>
                <div class="summary-card total-paid">
                    <h4>Total Paid</h4>
                    <div class="amount">₦${formatNumber(totalPaid)}</div>
                    <div class="label">All time payments</div>
                </div>
                <div class="summary-card total-overdue">
                    <h4>Overdue</h4>
                    <div class="amount">₦${formatNumber(totalOverdue)}</div>
                    <div class="label">Past due date</div>
                </div>
            </div>
            
            <div class="fee-tabs">
                <button class="fee-tab ${currentTab === 'all' ? 'active' : ''}" onclick="switchFeeTab('all')">All Fees</button>
                <button class="fee-tab ${currentTab === 'recurring' ? 'active' : ''}" onclick="switchFeeTab('recurring')">Recurring Fees</button>
                <button class="fee-tab ${currentTab === 'one-time' ? 'active' : ''}" onclick="switchFeeTab('one-time')">One-Time Fees</button>
            </div>
            
            <div class="fee-filters">
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
            
            ${filteredFees.length === 0 ? `
                <div class="empty-state">
                    <i class="fas fa-receipt"></i>
                    <h3>No Fees Found</h3>
                    <p>You don't have any fees at the moment.</p>
                </div>
            ` : `
                <div class="fees-grid">
                    ${filteredFees.map(fee => `
                        <div class="fee-card" onclick="viewFeeDetails(${fee.tenant_fee_id})">
                            <div class="fee-header">
                                <span class="fee-name">${escapeHtml(fee.fee_name)}</span>
                                <span class="fee-status status-${fee.status}">${fee.status.toUpperCase()}</span>
                            </div>
                            <div class="fee-details">
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
                                ${fee.status === 'pending' || fee.status === 'overdue' ? `
                                    <button class="btn-pay" onclick="openPaymentModal(${fee.tenant_fee_id})">
                                        <i class="fas fa-credit-card"></i> Pay Now
                                    </button>
                                ` : ''}
                                ${fee.status === 'paid' && fee.receipt_number ? `
                                    <button class="btn-download-receipt" onclick="downloadReceiptByFeeId(${fee.tenant_fee_id})">
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
                <h1>My Fees</h1>
                <p>View and manage your apartment fees</p>
            </div>
            <div class="empty-state">
                <i class="fas fa-receipt"></i>
                <h3>No Fees Found</h3>
                <p>You don't have any fees at the moment.</p>
            </div>
        </div>
    `;
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

function switchFeeTab(tab) {
    currentTab = tab;
    renderFeesPage();
}

function filterByStatus() {
    const statusFilter = document.getElementById('statusFilter');
    if (statusFilter) {
        currentFilter = statusFilter.value;
        loadTenantFees();
    }
}

async function viewFeeDetails(feeId) {
    const fee = tenantFees.find(f => f.tenant_fee_id === feeId);
    if (!fee) return;
    
    const modalBody = document.getElementById('feeDetailsBody');
    modalBody.innerHTML = `
        <div class="fee-detail-section">
            <div class="detail-row">
                <span class="detail-label">Fee Name:</span>
                <span class="detail-value">${escapeHtml(fee.fee_name)}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Amount:</span>
                <span class="detail-value">₦${formatNumber(fee.amount)}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Due Date:</span>
                <span class="detail-value">${formatDate(fee.due_date)}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Status:</span>
                <span class="detail-value">
                    <span class="fee-status status-${fee.status}">${fee.status.toUpperCase()}</span>
                </span>
            </div>
            ${fee.is_recurring ? `
                <div class="detail-row">
                    <span class="detail-label">Recurrence:</span>
                    <span class="detail-value">${fee.recurrence_period || 'Monthly'}</span>
                </div>
            ` : ''}
            <div class="detail-row">
                <span class="detail-label">Fee Type:</span>
                <span class="detail-value">${fee.is_recurring ? 'Recurring Fee' : 'One-time Fee'}</span>
            </div>
            ${fee.notes ? `
                <div class="detail-row">
                    <span class="detail-label">Notes:</span>
                    <span class="detail-value">${escapeHtml(fee.notes)}</span>
                </div>
            ` : ''}
        </div>
    `;
    
    // Add styles for detail rows
    const style = document.createElement('style');
    style.textContent = `
        .fee-detail-section {
            margin-top: 10px;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: 500;
            color: #666;
        }
        .detail-value {
            color: #1a1f36;
            font-weight: 500;
        }
    `;
    document.head.appendChild(style);
    
    openModal('feeDetailsModal');
}

let currentPaymentFeeId = null;

function openPaymentModal(feeId) {
    const fee = tenantFees.find(f => f.tenant_fee_id === feeId);
    if (!fee) return;
    
    currentPaymentFeeId = feeId;
    
    document.getElementById('modalFeeName').value = fee.fee_name;
    document.getElementById('modalFeeAmount').value = `₦${formatNumber(fee.amount)}`;
    document.getElementById('modalDueDate').value = formatDate(fee.due_date);
    document.getElementById('paymentMethod').value = '';
    document.getElementById('referenceNumber').value = '';

    const paymentMethodSelect = document.getElementById('paymentMethod');
    const referenceInput = document.getElementById('referenceNumber');
    
    paymentMethodSelect.onchange = function() {
        if (this.value) {
            const refNumber = generateReferenceNumber(this.value);
            referenceInput.value = refNumber;
            referenceInput.readOnly = true; // Make it read-only to prevent changes
            referenceInput.style.background = '#f0f0f0';
        } else {
            referenceInput.value = '';
            referenceInput.readOnly = true;
            referenceInput.style.background = 'white';
        }
    };
    
    // Clear previous values
    referenceInput.value = '';
    referenceInput.readOnly = true;
    referenceInput.style.background = 'white';
    
    openModal('paymentModal');
}
// Generate reference number based on payment method
function generateReferenceNumber(paymentMethod) {
    const prefix = paymentMethod === 'card' ? 'CARD' : 
                   paymentMethod === 'bank_transfer' ? 'BNK' : 
                   paymentMethod === 'cash' ? 'CSH' : 'REF';
    
    const date = new Date();
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const random = Math.random().toString(36).substring(2, 8).toUpperCase();
    const timestamp = Date.now().toString().slice(-6);
    
    return `${prefix}-${year}${month}${day}-${random}-${timestamp}`;
}


async function processFeePayment() {
    const paymentMethod = document.getElementById('paymentMethod')?.value;
    const referenceNumber = document.getElementById('referenceNumber')?.value;
    
    if (!paymentMethod) {
        if (window.showToast) {
            window.showToast('Please select a payment method', 'error');
        }
        return;
    }
    
    // Show loading state
    const payBtn = document.querySelector('#paymentModal .btn-primary');
    const originalText = payBtn.innerHTML;
    payBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    payBtn.disabled = true;
    
    try {
        const response = await fetch('../backend/fees/pay_fees.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                tenant_fee_id: currentPaymentFeeId,
                payment_method: paymentMethod,
                reference_number: referenceNumber || null
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Show success message
            if (window.showToast) {
                window.showToast('Payment successful!', 'success');
            }
            
            // Show receipt info
            const paymentData = data.data;
            showPaymentSuccessModal(paymentData);
            
            // Close payment modal
            closeModal('paymentModal');
            
            // Refresh fees list
            await loadTenantFees();
        } else {
            throw new Error(data.message || 'Payment failed');
        }
    } catch (error) {
        console.error('Payment error:', error);
        if (window.showToast) {
            window.showToast(error.message, 'error');
        }
    } finally {
        payBtn.innerHTML = originalText;
        payBtn.disabled = false;
    }
}

// Show payment success modal with receipt
function showPaymentSuccessModal(paymentData) {
    const modalHtml = `
        <div class="modal active" id="paymentSuccessModal">
            <div class="modal-content" style="max-width: 450px;">
                <div class="modal-header">
                    <h3>Payment Successful!</h3>
                    <button class="modal-close" onclick="closeModal('paymentSuccessModal')">&times;</button>
                </div>
                <div class="modal-body">
                    <div style="text-align: center; margin-bottom: 20px;">
                        <i class="fas fa-check-circle" style="font-size: 64px; color: #10b981;"></i>
                    </div>
                    <div class="payment-details">
                        <div class="detail-row">
                            <span class="detail-label">Fee Type:</span>
                            <span class="detail-value">${escapeHtml(paymentData.fee_name)}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Amount Paid:</span>
                            <span class="detail-value">₦${formatNumber(paymentData.amount)}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Receipt Number:</span>
                            <span class="detail-value">${escapeHtml(paymentData.receipt_number)}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Payment Date:</span>
                            <span class="detail-value">${formatDateTime(paymentData.payment_date)}</span>
                        </div>
                        ${paymentData.next_fee_created ? `
                            <div class="detail-row">
                                <span class="detail-label">Next Due Date:</span>
                                <span class="detail-value">${formatDate(paymentData.next_due_date)}</span>
                            </div>
                        ` : ''}
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="closeModal('paymentSuccessModal')">Close</button>
                    <button class="btn-primary" onclick="downloadReceipt('${paymentData.receipt_number}', ${paymentData.payment_id})">
                        <i class="fas fa-download"></i> Download Receipt
                    </button>
                </div>
            </div>
        </div>
    `;
    
    // Remove existing modal if any
    const existingModal = document.getElementById('paymentSuccessModal');
    if (existingModal) existingModal.remove();
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
}

// Download receipt function
async function downloadReceipt(receiptNumber, paymentId) {
    try {
        window.open(`../backend/fees/download_fee_receipt.php?payment_id=${paymentId}`, '_blank');
    } catch (error) {
        console.error('Error downloading receipt:', error);
        showToast('Failed to download receipt', 'error');
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

function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) modal.classList.add('active');
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) modal.classList.remove('active');
}

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

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}