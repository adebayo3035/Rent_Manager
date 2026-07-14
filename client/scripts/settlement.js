// settlement.js - Client Settlement Management

// ==================== STATE ====================
const state = {
    currentTab: 'pending',
    currentPage: 1,
    totalPages: 0,
    totalRecords: 0,
    perPage: 20,
    settlements: [],
    pendingRequests: [],
    filters: {
        status: '',
        date_from: '',
        date_to: '',
        search: ''
    }
};

// Action modal state
let selectedRequestId = null;
let selectedAction = null;
let pendingRequestsCache = [];

// ==================== INITIALIZATION ====================
document.addEventListener('DOMContentLoaded', function() {
    // Load default tab
    loadPendingRequests();
    setInterval(loadPendingRequests, 60000); // Auto-refresh every 60 seconds
});

// ==================== TAB SWITCHING ====================
function switchTab(tab) {
    state.currentTab = tab;
    
    // Update tab buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        const isActive = btn.dataset.tab === tab;
        btn.classList.toggle('active', isActive);
        btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });
    
    document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
    
    if (tab === 'pending') {
        document.getElementById('pendingSection').classList.add('active');
        loadPendingRequests();
    } else if (tab === 'history') {
        document.getElementById('historySection').classList.add('active');
        loadHistory();
    } else if (tab === 'settlements') {
        document.getElementById('settlementSection').classList.add('active');
        loadSettlement();
    }
}

// ==================== LOAD PENDING REQUESTS ====================
async function loadPendingRequests() {
    const container = document.getElementById('pendingRequests');
    container.innerHTML = `
        <div class="loading-state">
            <div class="spinner"></div>
            <p>Loading pending requests...</p>
        </div>
    `;

    try {
        const response = await fetch('../backend/client/settlement_api.php?action=get_pending', {
            credentials: 'include',
            headers: { 'Accept': 'application/json' }
        });
        const data = await response.json();

        if (response.ok && data.success && Array.isArray(data.data)) {
            const requests = data.data;
            state.pendingRequests = requests;
            pendingRequestsCache = requests;
            updatePendingCount(requests.length);
            
            if (requests.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        <p>No pending settlement changes.</p>
                        <p style="font-size: 13px; color: #94a3b8;">All settlement formulas are up to date.</p>
                    </div>
                `;
            } else {
                renderPendingRequests(requests);
            }
        } else {
            const message = data.message || 'Failed to load pending requests';
            showToast(message, 'error');
            container.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-exclamation-circle" style="color: #ef4444;"></i>
                    <p>${escapeHtml(message)}</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error loading pending requests:', error);
        showToast('Network error. Please try again.', 'error');
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-exclamation-circle" style="color: #ef4444;"></i>
                <p>Failed to load pending requests. Please refresh the page.</p>
            </div>
        `;
    }
}

function updatePendingCount(count) {
    const badge = document.getElementById('pendingCount');
    if (badge) {
        badge.textContent = count;
        badge.style.display = count > 0 ? 'inline' : 'none';
    }
}

function renderPendingRequests(requests) {
    const container = document.getElementById('pendingRequests');
    let html = '';

    requests.forEach(request => {
        const adminDiff = toNumber(request.admin_diff);
        const agentDiff = toNumber(request.agent_diff);
        const clientDiff = toNumber(request.client_diff);
        
        const adminDiffClass = adminDiff > 0 ? 'increase' : (adminDiff < 0 ? 'decrease' : '');
        const agentDiffClass = agentDiff > 0 ? 'increase' : (agentDiff < 0 ? 'decrease' : '');
        const clientDiffClass = clientDiff > 0 ? 'increase' : (clientDiff < 0 ? 'decrease' : '');
        
        const adminDiffText = adminDiff > 0 ? `+${adminDiff.toFixed(2)}%` : (adminDiff < 0 ? `${adminDiff.toFixed(2)}%` : '');
        const agentDiffText = agentDiff > 0 ? `+${agentDiff.toFixed(2)}%` : (agentDiff < 0 ? `${agentDiff.toFixed(2)}%` : '');
        const clientDiffText = clientDiff > 0 ? `+${clientDiff.toFixed(2)}%` : (clientDiff < 0 ? `${clientDiff.toFixed(2)}%` : '');

        html += `
            <div class="settlement-card" data-request-id="${escapeAttribute(request.request_id)}">
                <div class="settlement-card-header">
                    <h4>
                        ${escapeHtml(request.property_code)}
                        <span class="property-code">- ${escapeHtml(request.property_name)}</span>
                    </h4>
                    <span class="badge badge-pending">Pending Approval</span>
                </div>
                
                <div class="settlement-comparison">
                    <div class="current-values">
                        <strong>Current Formula</strong>
                        <ul>
                            <li>Admin: ${formatPercent(request.current_admin_percentage)}</li>
                            <li>Agent: ${formatPercent(request.current_agent_percentage)}</li>
                            <li>Client: ${formatPercent(request.current_client_percentage)}</li>
                        </ul>
                    </div>
                    <div class="arrow">
                        <i class="fas fa-arrow-right"></i>
                    </div>
                    <div class="proposed-values">
                        <strong>Proposed Formula</strong>
                        <ul>
                            <li>Admin: ${formatPercent(request.proposed_admin_percentage)} <span class="${adminDiffClass}">${adminDiffText}</span></li>
                            <li>Agent: ${formatPercent(request.proposed_agent_percentage)} <span class="${agentDiffClass}">${agentDiffText}</span></li>
                            <li>Client: ${formatPercent(request.proposed_client_percentage)} <span class="${clientDiffClass}">${clientDiffText}</span></li>
                        </ul>
                    </div>
                </div>
                
                <div class="settlement-meta">
                    <span><i class="far fa-user"></i> Proposed by: ${escapeHtml(request.proposed_by_fullname || 'Unknown')}</span>
                    <span><i class="far fa-clock"></i> ${request.proposed_at_formatted}</span>
                    ${request.agent_code ? `<span><i class="fas fa-user-tie"></i> Agent: ${escapeHtml(request.agent_code)}</span>` : ''}
                </div>
                
                ${request.notes ? `
                    <div class="settlement-notes">
                        <strong>Note:</strong> ${escapeHtml(request.notes)}
                    </div>
                ` : ''}
                
                <div class="settlement-actions">
                    <button class="btn btn-success" onclick="openActionModal(${request.request_id}, 'approve')">
                        <i class="fas fa-check"></i> Approve
                    </button>
                    <button class="btn btn-danger" onclick="openActionModal(${request.request_id}, 'decline')">
                        <i class="fas fa-times"></i> Decline
                    </button>
                </div>
            </div>
        `;
    });

    container.innerHTML = html;
}

// ==================== LOAD HISTORY ====================
async function loadHistory() {
    const container = document.getElementById('historyRequests');
    container.innerHTML = `
        <div class="loading-state">
            <div class="spinner"></div>
            <p>Loading history...</p>
        </div>
    `;

    try {
        const response = await fetch('../backend/client/settlement_api.php?action=get_history', {
            credentials: 'include',
            headers: { 'Accept': 'application/json' }
        });
        const data = await response.json();

        if (response.ok && data.success && Array.isArray(data.data)) {
            const history = data.data;
            
            if (history.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <p>No settlement history available.</p>
                    </div>
                `;
            } else {
                renderHistory(history);
            }
        } else {
            const message = data.message || 'Failed to load history';
            container.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-exclamation-circle" style="color: #ef4444;"></i>
                    <p>${escapeHtml(message)}</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error loading history:', error);
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-exclamation-circle" style="color: #ef4444;"></i>
                <p>Failed to load history. Please refresh the page.</p>
            </div>
        `;
    }
}

function renderHistory(history) {
    const container = document.getElementById('historyRequests');
    let html = '';

    history.forEach(item => {
        html += `
            <div class="settlement-card">
                <div class="settlement-card-header">
                    <h4>
                        ${escapeHtml(item.property_code)}
                        <span class="property-code">- ${escapeHtml(item.property_name)}</span>
                    </h4>
                    <span class="badge badge-${item.status_class}">${item.status_label}</span>
                </div>
                
                <div class="settlement-comparison">
                    <div class="current-values">
                        <strong>Previous Formula</strong>
                        <ul>
                            <li>Admin: ${formatPercent(item.current_admin_percentage)}</li>
                            <li>Agent: ${formatPercent(item.current_agent_percentage)}</li>
                            <li>Client: ${formatPercent(item.current_client_percentage)}</li>
                        </ul>
                    </div>
                    <div class="arrow">
                        <i class="fas fa-arrow-right"></i>
                    </div>
                    <div class="proposed-values">
                        <strong>${item.status_label} Formula</strong>
                        <ul>
                            <li>Admin: ${formatPercent(item.proposed_admin_percentage)}</li>
                            <li>Agent: ${formatPercent(item.proposed_agent_percentage)}</li>
                            <li>Client: ${formatPercent(item.proposed_client_percentage)}</li>
                        </ul>
                    </div>
                </div>
                
                <div class="settlement-meta">
                    <span><i class="far fa-user"></i> Proposed by: ${escapeHtml(item.proposed_by_fullname || 'Unknown')}</span>
                    <span><i class="far fa-clock"></i> ${item.proposed_at_formatted}</span>
                    ${item.approved_by_fullname ? `<span><i class="fas fa-check-circle"></i> ${item.status_label} by: ${escapeHtml(item.approved_by_fullname)}</span>` : ''}
                    ${item.approved_at_formatted ? `<span><i class="far fa-calendar-check"></i> ${item.approved_at_formatted}</span>` : ''}
                </div>
                
                ${item.rejection_reason ? `
                    <div class="settlement-notes" style="border-left-color: #ef4444; background: #fef2f2;">
                        <strong>Reason:</strong> ${escapeHtml(item.rejection_reason)}
                    </div>
                ` : ''}
                
                ${item.notes ? `
                    <div class="settlement-notes" style="margin-top: 8px;">
                        <strong>Note:</strong> ${escapeHtml(item.notes)}
                    </div>
                ` : ''}
            </div>
        `;
    });

    container.innerHTML = html;
}

// ==================== LOAD SETTLEMENTS ====================
async function loadSettlement() {
    const container = document.getElementById('settlementRecords');
    container.innerHTML = `
        <div class="loading-state">
            <div class="spinner"></div>
            <p>Loading settlements...</p>
        </div>
    `;

    try {
        const params = new URLSearchParams({
            action: 'get_client_settlements',
            page: state.currentPage,
            limit: state.perPage,
            status: state.filters.status,
            date_from: state.filters.date_from,
            date_to: state.filters.date_to,
            search: state.filters.search
        });

        const response = await fetch(`../backend/client/settlement_api.php?${params}`, {
            credentials: 'include',
            headers: { 'Accept': 'application/json' }
        });
        const data = await response.json();

        if (response.ok && data.success) {
            const result = data.data;
            const settlements = result.settlements || [];
            
            state.settlements = settlements;
            state.totalRecords = result.pagination?.total_records || 0;
            state.totalPages = result.pagination?.total_pages || 0;
            
            if (settlements.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <p>No settlement records available for you.</p>
                    </div>
                `;
            } else {
                renderSettlement(settlements);
                renderPagination();
                renderSummary(result.summary);
            }
        } else {
            const message = data.message || 'Failed to load settlement records';
            container.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-exclamation-circle" style="color: #ef4444;"></i>
                    <p>${escapeHtml(message)}</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error loading settlement records:', error);
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-exclamation-circle" style="color: #ef4444;"></i>
                <p>Failed to load settlement records. Please refresh the page.</p>
            </div>
        `;
    }
}

// ==================== RENDER SETTLEMENT ====================
// ==================== RENDER SETTLEMENT (TABLE VIEW) ====================
function renderSettlement(settlements) {
    const container = document.getElementById('settlementRecords');
    
    if (!settlements || settlements.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-history"></i>
                <p>No settlement records available for you.</p>
            </div>
        `;
        return;
    }

    let html = `
        <div class="table-responsive">
            <table class="settlement-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Property</th>
                        <th>Tenant</th>
                        <th>Amount</th>
                        <th>Your Share</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
    `;

    const startIndex = (state.currentPage - 1) * state.perPage + 1;

    settlements.forEach((item, index) => {
        const rowNumber = startIndex + index;
        const statusColors = {
            'pending': 'warning',
            'processing': 'info',
            'completed': 'success',
            'failed': 'danger',
            'reversed': 'secondary'
        };
        const statusClass = statusColors[item.settlement_status] || 'secondary';
        const isPaid = item.user_paid || false;
        const paidStatus = isPaid ? 'Paid' : 'Unpaid';
        const paidClass = isPaid ? 'paid' : 'unpaid';

        html += `
            <tr>
                <td>${rowNumber}</td>
                <td>
                    <div class="property-cell">
                        <span class="property-name">${escapeHtml(item.property?.name || 'N/A')}</span>
                        <span class="property-code">${escapeHtml(item.property?.code || 'N/A')}</span>
                    </div>
                </td>
                <td>
                    <div class="tenant-cell">
                        <span class="tenant-name">${escapeHtml(item.tenant?.name || 'N/A')}</span>
                        <span class="tenant-code">${escapeHtml(item.tenant?.code || 'N/A')}</span>
                    </div>
                </td>
                <td class="amount">₦${formatNumber(item.total_amount)}</td>
                <td class="amount share">
                    ₦${formatNumber(item.user_share)}
                    <span class="share-percentage">(${item.user_percentage || 0}%)</span>
                </td>
                <td>
                    <div class="status-group">
                        <span class="badge badge-${statusClass}">${(item.settlement_status || 'PENDING').toUpperCase()}</span>
                        <span class="badge badge-${paidClass}">${paidStatus}</span>
                    </div>
                </td>
                <td>${formatDate(item.settlement_date)}</td>
                <td>
                    <button class="btn-icon btn-view" onclick="viewSettlementDetails(${item.id})" title="View Details">
                        <i class="fas fa-eye"></i>
                    </button>
                </td>
            </tr>
        `;
    });

    html += `
                </tbody>
            </table>
        </div>
    `;

    container.innerHTML = html;
}

// ==================== RENDER PAGINATION ====================
function renderPagination() {
    const paginationContainer = document.getElementById('paginationControls');
    if (!paginationContainer) return;

    const start = (state.currentPage - 1) * state.perPage + 1;
    const end = Math.min(state.currentPage * state.perPage, state.totalRecords);

    paginationContainer.innerHTML = `
        <div class="pagination-info">
            Showing <span id="showingStart">${state.totalRecords > 0 ? start : 0}</span> 
            to <span id="showingEnd">${end}</span> 
            of <span id="totalRecords">${state.totalRecords}</span> records
        </div>
        <div class="pagination-buttons">
            <button class="btn-page" onclick="changePage('prev')" ${state.currentPage <= 1 ? 'disabled' : ''}>
                <i class="fas fa-chevron-left"></i> Previous
            </button>
            <span class="page-info">Page ${state.currentPage} of ${state.totalPages || 1}</span>
            <button class="btn-page" onclick="changePage('next')" ${state.currentPage >= state.totalPages ? 'disabled' : ''}>
                Next <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    `;
}

// ==================== RENDER SUMMARY ====================
function renderSummary(summary) {
    const summaryContainer = document.getElementById('summaryCards');
    if (!summaryContainer || !summary) return;

    summaryContainer.innerHTML = `
        <div class="summary-card">
            <div class="card-icon total">
                <i class="fas fa-receipt"></i>
            </div>
            <div class="card-info">
                <span class="card-label">Total Settlements</span>
                <span class="card-value">${summary.total_count || 0}</span>
                <span class="card-sub">₦${formatNumber(summary.total_amount)}</span>
            </div>
        </div>
        <div class="summary-card completed">
            <div class="card-icon completed">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="card-info">
                <span class="card-label">Completed</span>
                <span class="card-value">${summary.completed_count || 0}</span>
                <span class="card-sub">₦${formatNumber(summary.completed_amount)}</span>
            </div>
        </div>
        <div class="summary-card pending">
            <div class="card-icon pending">
                <i class="fas fa-clock"></i>
            </div>
            <div class="card-info">
                <span class="card-label">Pending</span>
                <span class="card-value">${summary.pending_count || 0}</span>
                <span class="card-sub">₦${formatNumber(summary.pending_amount)}</span>
            </div>
        </div>
        <div class="summary-card user-share">
            <div class="card-icon share">
                <i class="fas fa-user-check"></i>
            </div>
            <div class="card-info">
                <span class="card-label">Your Total Share</span>
                <span class="card-value">₦${formatNumber(summary.total_user_share)}</span>
                <span class="card-sub">Received: ₦${formatNumber(summary.total_received)}</span>
            </div>
        </div>
    `;
}

// ==================== VIEW SETTLEMENT DETAILS ====================
async function viewSettlementDetails(settlementId) {
    // Get modal elements
    const modal = document.getElementById('settlementDetailsModal');
    const body = document.getElementById('settlementDetailsBody');
    
    // Check if modal exists
    if (!modal) {
        console.error('Modal element not found: settlementDetailsModal');
        showToast('Modal not found. Please refresh the page.', 'error');
        return;
    }
    
    if (!body) {
        console.error('Modal body element not found: settlementDetailsBody');
        showToast('Modal body not found. Please refresh the page.', 'error');
        return;
    }
    
    // Show loading state
    body.innerHTML = `
        <div class="loading-state">
            <div class="spinner"></div>
            <p>Loading settlement details...</p>
        </div>
    `;
    
    // Show modal
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';

    try {
        const response = await fetch(`../backend/client/settlement_api.php?action=get_settlement_details&id=${settlementId}`, {
            credentials: 'include',
            headers: { 'Accept': 'application/json' }
        });
        const data = await response.json();

        if (response.ok && data.success) {
            renderSettlementDetails(data.data);
        } else {
            body.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-exclamation-circle"></i>
                    <p>${data.message || 'Failed to load settlement details'}</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error loading settlement details:', error);
        body.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-exclamation-circle"></i>
                <p>Failed to load settlement details. Please try again.</p>
            </div>
        `;
    }
}

// ==================== RENDER SETTLEMENT DETAILS ====================
function renderSettlementDetails(settlement) {
    const container = document.getElementById('settlementDetailsBody');
    
    if (!settlement) {
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-exclamation-circle"></i>
                <p>No details available</p>
            </div>
        `;
        return;
    }

    const statusClass = settlement.settlement_status || 'pending';

    container.innerHTML = `
        <div class="details-grid">
            <!-- Property Information -->
            <div class="detail-section">
                <h4><i class="fas fa-building"></i> Property Information</h4>
                <div class="detail-row">
                    <span class="label">Property</span>
                    <span class="value">${escapeHtml(settlement.property?.name || 'N/A')}</span>
                </div>
                <div class="detail-row">
                    <span class="label">Property Code</span>
                    <span class="value">${escapeHtml(settlement.property?.code || 'N/A')}</span>
                </div>
                <div class="detail-row">
                    <span class="label">Agent</span>
                    <span class="value">${escapeHtml(settlement.agent?.name || 'N/A')}</span>
                </div>
            </div>
            
            <!-- Tenant Information -->
            <div class="detail-section">
                <h4><i class="fas fa-user"></i> Tenant Information</h4>
                <div class="detail-row">
                    <span class="label">Tenant</span>
                    <span class="value">${escapeHtml(settlement.tenant?.name || 'N/A')}</span>
                </div>
                <div class="detail-row">
                    <span class="label">Apartment</span>
                    <span class="value">${escapeHtml(settlement.tenant?.apartment || 'N/A')}</span>
                </div>
                <div class="detail-row">
                    <span class="label">Period</span>
                    <span class="value">Tracker #${settlement.tracker_id || 'N/A'}</span>
                </div>
            </div>
            
            <!-- Payment Breakdown -->
            <div class="detail-section">
                <h4><i class="fas fa-money-bill-wave"></i> Payment Breakdown</h4>
                <div class="detail-row">
                    <span class="label">Total Amount</span>
                    <span class="value amount">₦${formatNumber(settlement.total_amount)}</span>
                </div>
                <div class="detail-row">
                    <span class="label">Admin Share (${settlement.admin_percentage_used || 0}%)</span>
                    <span class="value">₦${formatNumber(settlement.admin_share)}</span>
                </div>
                <div class="detail-row">
                    <span class="label">Agent Share (${settlement.agent_percentage_used || 0}%)</span>
                    <span class="value">₦${formatNumber(settlement.agent_share)}</span>
                </div>
                <div class="detail-row">
                    <span class="label">Your Share (${settlement.client_percentage_used || 0}%)</span>
                    <span class="value share">₦${formatNumber(settlement.client_share)}</span>
                </div>
            </div>
            
            <!-- Payment Status -->
            <div class="detail-section">
                <h4><i class="fas fa-check-circle"></i> Payment Status</h4>
                <div class="detail-row">
                    <span class="label">Settlement Status</span>
                    <span class="value status-badge status-${statusClass}">
                        ${(settlement.settlement_status || 'PENDING').toUpperCase()}
                    </span>
                </div>
                <div class="detail-row">
                    <span class="label">Your Payment</span>
                    <span class="value">${settlement.user_paid ? '✅ Paid' : '⏳ Pending'}</span>
                </div>
                ${settlement.user_payment_date ? `
                    <div class="detail-row">
                        <span class="label">Your Payment Date</span>
                        <span class="value">${formatDateTime(settlement.user_payment_date)}</span>
                    </div>
                ` : ''}
            </div>
            
            <!-- Timeline -->
            <div class="detail-section full-width">
                <h4><i class="fas fa-clock"></i> Timeline</h4>
                <div class="detail-row">
                    <span class="label">Settlement Date</span>
                    <span class="value">${formatDateTime(settlement.settlement_date)}</span>
                </div>
                ${settlement.notes ? `
                    <div class="detail-row">
                        <span class="label">Notes</span>
                        <span class="value">${escapeHtml(settlement.notes)}</span>
                    </div>
                ` : ''}
            </div>
        </div>
    `;
}
function printSettlement() {
    const content = document.getElementById('settlementDetailsBody').innerHTML;
    const printWindow = window.open('', '_blank', 'width=800,height=600');
    printWindow.document.write(`
        <html><head><title>Settlement Details</title>
        <style>
            body { font-family: 'Inter', sans-serif; padding: 40px; }
            .details-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
            .detail-section { margin-bottom: 20px; }
            .detail-row { display: flex; justify-content: space-between; padding: 4px 0; border-bottom: 1px solid #eee; }
            .label { color: #666; }
            .value { font-weight: 500; }
            .full-width { grid-column: 1 / -1; }
            h4 { margin: 0 0 10px 0; color: #1a1f36; border-bottom: 2px solid #4f46e5; padding-bottom: 6px; }
        </style>
        </head><body>
        <h1>Settlement Details</h1>
        ${content}
        <script>window.print();</script>
        </body></html>
    `);
    printWindow.document.close();
}

// ==================== PAGINATION CONTROLS ====================
function changePage(direction) {
    if (direction === 'prev' && state.currentPage > 1) {
        state.currentPage--;
    } else if (direction === 'next' && state.currentPage < state.totalPages) {
        state.currentPage++;
    }
    loadSettlement();
}

// ==================== FILTERS ====================
function applyFilters() {
    state.filters.status = document.getElementById('filterStatus')?.value || '';
    state.filters.date_from = document.getElementById('filterDateFrom')?.value || '';
    state.filters.date_to = document.getElementById('filterDateTo')?.value || '';
    state.filters.search = document.getElementById('filterSearch')?.value || '';
    state.currentPage = 1;
    loadSettlement();
}

function clearFilters() {
    const statusInput = document.getElementById('filterStatus');
    const dateFromInput = document.getElementById('filterDateFrom');
    const dateToInput = document.getElementById('filterDateTo');
    const searchInput = document.getElementById('filterSearch');
    
    if (statusInput) statusInput.value = '';
    if (dateFromInput) dateFromInput.value = '';
    if (dateToInput) dateToInput.value = '';
    if (searchInput) searchInput.value = '';
    
    state.filters = { status: '', date_from: '', date_to: '', search: '' };
    state.currentPage = 1;
    loadSettlement();
}

// ==================== MARK SETTLEMENT AS PAID ====================
async function markSettlementAsPaid(settlementId) {
    if (!confirm('Are you sure you want to mark this settlement as paid?')) return;
    
    try {
        const response = await fetch('../backend/client/mark_settlement_paid.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ settlement_id: settlementId })
        });
        const data = await response.json();
        
        if (data.success) {
            showToast('Settlement marked as paid successfully', 'success');
            loadSettlement();
        } else {
            showToast(data.message || 'Failed to mark settlement as paid', 'error');
        }
    } catch (error) {
        console.error('Error marking settlement as paid:', error);
        showToast('An error occurred. Please try again.', 'error');
    }
}

// ==================== CLOSE MODAL ====================
function closeSettlementDetailsModal() {
    document.getElementById('settlementDetailsModal').style.display = 'none';
    document.body.style.overflow = '';
}

// Close modal on outside click
document.addEventListener('click', function(e) {
    const modal = document.getElementById('settlementDetailsModal');
    if (e.target === modal) {
        closeSettlementDetailsModal();
    }
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('settlementDetailsModal');
        if (modal.style.display === 'flex') {
            closeSettlementDetailsModal();
        }
    }
});

// ==================== ACTION MODAL ====================
function openActionModal(requestId, action) {
    selectedRequestId = requestId;
    selectedAction = action;
    
    const modal = document.getElementById('actionModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalContent = document.getElementById('modalContent');
    const approveBtn = document.getElementById('approveBtn');
    const declineBtn = document.getElementById('declineBtn');
    const declineReasonContainer = document.getElementById('declineReasonContainer');
    const declineReason = document.getElementById('declineReason');

    const card = document.querySelector(`.settlement-card[data-request-id="${escapeCssIdentifier(requestId)}"]`);
    if (!card) {
        showToast('Request not found', 'error');
        return;
    }

    const propertyCode = card.querySelector('.settlement-card-header h4')?.textContent?.trim() || 'Property';
    
    if (action === 'approve') {
        modalTitle.textContent = `Approve Settlement Change - ${propertyCode}`;
        approveBtn.style.display = 'inline-flex';
        declineBtn.style.display = 'none';
        declineReasonContainer.style.display = 'none';
        
        modalContent.innerHTML = `
            <p style="margin-bottom: 12px;">Are you sure you want to approve this settlement change?</p>
            <p style="color: #64748b; font-size: 14px;">The new formula will take effect immediately.</p>
        `;
    } else {
        modalTitle.textContent = `Decline Settlement Change - ${propertyCode}`;
        approveBtn.style.display = 'none';
        declineBtn.style.display = 'inline-flex';
        declineReasonContainer.style.display = 'block';
        declineReason.value = '';
        
        modalContent.innerHTML = `
            <p style="margin-bottom: 12px;">Are you sure you want to decline this settlement change?</p>
            <p style="color: #64748b; font-size: 14px;">Please provide a reason for declining.</p>
        `;
    }
    
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}

function closeActionModal() {
    const modal = document.getElementById('actionModal');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    selectedRequestId = null;
    selectedAction = null;
}

// ==================== CONFIRM APPROVE ====================
async function confirmApprove() {
    if (!selectedRequestId) return;

    const approveBtn = document.getElementById('approveBtn');
    const originalText = approveBtn.innerHTML;
    approveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    approveBtn.disabled = true;

    try {
        const response = await fetch('../backend/client/settlement_api.php?action=approve', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify({
                request_id: selectedRequestId
            })
        });

        const data = await response.json();

        if (data.success) {
            showToast('Settlement change approved successfully!', 'success');
            closeActionModal();
            loadPendingRequests();
        } else {
            showToast(data.message || 'Failed to approve', 'error');
        }
    } catch (error) {
        console.error('Error approving:', error);
        showToast('Network error. Please try again.', 'error');
    } finally {
        approveBtn.innerHTML = originalText;
        approveBtn.disabled = false;
    }
}

// ==================== CONFIRM DECLINE ====================
async function confirmDecline() {
    if (!selectedRequestId) return;

    const reason = document.getElementById('declineReason').value.trim();
    if (!reason) {
        showToast('Please provide a reason for declining', 'warning');
        document.getElementById('declineReason').focus();
        return;
    }

    const declineBtn = document.getElementById('declineBtn');
    const originalText = declineBtn.innerHTML;
    declineBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    declineBtn.disabled = true;

    try {
        const response = await fetch('../backend/client/settlement_api.php?action=decline', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify({
                request_id: selectedRequestId,
                reason: reason
            })
        });

        const data = await response.json();

        if (data.success) {
            showToast('Settlement change declined.', 'info');
            closeActionModal();
            loadPendingRequests();
        } else {
            showToast(data.message || 'Failed to decline', 'error');
        }
    } catch (error) {
        console.error('Error declining:', error);
        showToast('Network error. Please try again.', 'error');
    } finally {
        declineBtn.innerHTML = originalText;
        declineBtn.disabled = false;
    }
}

// ==================== UTILITY FUNCTIONS ====================
function formatNumber(value) {
    if (!value) return '0.00';
    return new Intl.NumberFormat('en-NG').format(value);
}

function formatDate(dateString) {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

function formatDateTime(dateString) {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function formatPercent(value) {
    if (!value && value !== 0) return '0%';
    return value + '%';
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function escapeAttribute(text) {
    return escapeHtml(text).replace(/"/g, '&quot;');
}

function escapeCssIdentifier(value) {
    return String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
}

function toNumber(value) {
    const number = Number(value);
    return Number.isFinite(number) ? number : 0;
}

function showToast(message, type = 'info') {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    
    const icons = {
        success: 'fa-check-circle',
        error: 'fa-exclamation-circle',
        warning: 'fa-exclamation-triangle',
        info: 'fa-info-circle'
    };
    
    toast.innerHTML = `
        <i class="fas ${icons[type] || icons.info}"></i>
        <span>${escapeHtml(message)}</span>
    `;
    
    container.appendChild(toast);
    
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(100%)';
        toast.style.transition = 'all 0.3s ease';
        setTimeout(() => {
            if (toast && toast.remove) {
                toast.remove();
            }
        }, 300);
    }, 5000);
}