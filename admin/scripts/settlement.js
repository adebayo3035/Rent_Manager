// settlements.js - Settlement tracking module

// ==================== STATE ====================
const state = {
    currentPage: 1,
    totalPages: 0,
    totalRecords: 0,
    perPage: 20,
    settlements: [],
    filters: {
        status: '',
        payable: '',
        date_from: '',
        date_to: '',
        search: ''
    }
};

// ==================== INITIALIZATION ====================
document.addEventListener('DOMContentLoaded', function() {
    loadSettlements();
    
    // Enter key search
    document.getElementById('filterSearch').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            applyFilters();
        }
    });
});

// ==================== LOAD SETTLEMENTS ====================
async function loadSettlements() {
    const tbody = document.getElementById('settlementsBody');
    tbody.innerHTML = `
        <tr>
            <td colspan="9" class="loading-cell">
                <div class="spinner"></div>
                Loading settlements...
            </td>
        </tr>
    `;

    try {
        const params = new URLSearchParams({
            page: state.currentPage,
            limit: state.perPage,
            status: state.filters.status,
            date_from: state.filters.date_from,
            date_to: state.filters.date_to,
            search: state.filters.search,
            payable_type: state.filters.payable
        });

        const response = await fetch(`../backend/settlement/get_settlement.php?${params}`);
        const data = await response.json();

        console.log('API Response:', data); // Debug log

        if (data.success) {
            // FIX: Data is in data.message, not data.data
            const result = data.message;
            
            if (result) {
                state.settlements = result.settlements || [];
                state.totalRecords = result.pagination?.total_records || 0;
                state.totalPages = result.pagination?.total_pages || 0;
                
                console.log('Settlements loaded:', state.settlements.length);
                console.log('Total records:', state.totalRecords);
                
                renderTable();
                renderSummary(result.summary);
                renderPagination();
            } else {
                showToast('No data received from server', 'warning');
                tbody.innerHTML = `
                    <tr>
                        <td colspan="9" class="empty-cell">
                            <i class="fas fa-exclamation-circle"></i>
                            No data received
                        </td>
                    </tr>
                `;
            }
        } else {
            showToast(data.message || 'Failed to load settlements', 'error');
            tbody.innerHTML = `
                <tr>
                    <td colspan="9" class="empty-cell">
                        <i class="fas fa-exclamation-circle"></i>
                        ${data.message || 'Failed to load data'}
                    </td>
                </tr>
            `;
        }
    } catch (error) {
        console.error('Error loading settlements:', error);
        showToast('Network error. Please try again.', 'error');
        tbody.innerHTML = `
            <tr>
                <td colspan="9" class="empty-cell">
                    <i class="fas fa-exclamation-circle"></i>
                    Failed to load data. Please refresh the page.
                </td>
            </tr>
        `;
    }
}

// ==================== RENDER TABLE ====================
function renderTable() {
    const tbody = document.getElementById('settlementsBody');
    
    if (!state.settlements || state.settlements.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="9" class="empty-cell">
                    <i class="fas fa-inbox"></i>
                    No settlements found
                </td>
            </tr>
        `;
        document.getElementById('recordCount').textContent = '0 records';
        return;
    }

    let html = '';
    state.settlements.forEach((settlement, index) => {
        const statusColors = {
            'pending': 'warning',
            'processing': 'info',
            'completed': 'success',
            'failed': 'danger',
            'reversed': 'secondary'
        };
        const statusClass = statusColors[settlement.settlement_status] || 'secondary';
        
        // Admin view - show all shares
        const shareDisplay = `
            Admin: ₦${formatNumber(settlement.admin_share)}<br>
            Agent: ₦${formatNumber(settlement.agent_share)}<br>
            Client: ₦${formatNumber(settlement.client_share)}
        `;
        const percentageDisplay = `
            ${settlement.admin_percentage_used || 0}% / 
            ${settlement.agent_percentage_used || 0}% / 
            ${settlement.client_percentage_used || 0}%
        `;
        
        const rowNumber = (state.currentPage - 1) * state.perPage + index + 1;
        
        // Get property name safely
        const propertyName = settlement.property?.name || 'N/A';
        const propertyCode = settlement.property?.code || '';
        
        // Get tenant name safely
        const tenantName = settlement.tenant?.name || 'N/A';
        const tenantCode = settlement.tenant?.code || '';
        
        html += `
            <tr>
                <td>${rowNumber}</td>
                <td>
                    <div class="property-cell">
                        <span class="property-name">${escapeHtml(propertyName)}</span>
                        <span class="property-code">${escapeHtml(propertyCode)}</span>
                    </div>
                </td>
                <td>
                    <div class="tenant-cell">
                        <span class="tenant-name">${escapeHtml(tenantName)}</span>
                        <span class="tenant-code">${escapeHtml(tenantCode)}</span>
                    </div>
                </td>
                <td>
                    <span class="period-badge">Tracker #${escapeHtml(settlement.tracker_id || 'N/A')}</span>
                </td>
                <td class="amount">₦${formatNumber(settlement.total_amount)}</td>
                <td class="amount">
                    <div class="share-display">
                        <span class="share-amount">${shareDisplay}</span>
                        <span class="share-percentage">(${percentageDisplay})</span>
                    </div>
                </td>
                <td>
                    <span class="status-badge status-${statusClass}">
                        ${(settlement.settlement_status || 'pending').toUpperCase()}
                    </span>
                </td>
                <td>${formatDate(settlement.settlement_date)}</td>
                <td>
                    <button class="btn-icon btn-view" onclick="viewSettlement(${settlement.id})" title="View Details">
                        <i class="fas fa-eye"></i>
                    </button>
                </td>
            </tr>
        `;
    });

    tbody.innerHTML = html;
    document.getElementById('recordCount').textContent = `${state.settlements.length} records`;
}

// ==================== RENDER SUMMARY ====================
function renderSummary(summary) {
    if (!summary) {
        console.log('No summary data');
        return;
    }
    
    console.log('Summary:', summary);
    
    document.getElementById('totalCount').textContent = summary.total_count || 0;
    document.getElementById('totalAmount').textContent = `₦${formatNumber(summary.total_amount)}`;
    
    // For admin view, show completed/pending/failed counts
    document.getElementById('completedCount').textContent = summary.total_count || 0;
    document.getElementById('completedAmount').textContent = `₦${formatNumber(summary.total_amount)}`;
    document.getElementById('pendingCount').textContent = 0;
    document.getElementById('pendingAmount').textContent = '₦0.00';
    document.getElementById('userShareTotal').textContent = `₦${formatNumber(summary.total_admin_share || 0)}`;
    document.getElementById('userTotal').textContent = `₦${formatNumber(summary.total_amount || 0)}`;
}

// ==================== RENDER PAGINATION ====================
function renderPagination() {
    const start = (state.currentPage - 1) * state.perPage + 1;
    const end = Math.min(state.currentPage * state.perPage, state.totalRecords);
    
    document.getElementById('showingStart').textContent = state.totalRecords > 0 ? start : 0;
    document.getElementById('showingEnd').textContent = end;
    document.getElementById('totalRecords').textContent = state.totalRecords;
    document.getElementById('pageInfo').textContent = `Page ${state.currentPage} of ${state.totalPages || 1}`;
    
    document.getElementById('prevPage').disabled = state.currentPage <= 1;
    document.getElementById('nextPage').disabled = state.currentPage >= state.totalPages;
}

// ==================== VIEW SETTLEMENT DETAILS ====================
async function viewSettlement(settlementId) {
    const modal = document.getElementById('settlementModal');
    const details = document.getElementById('settlementDetails');
    
    details.innerHTML = `
        <div class="loading-spinner">
            <div class="spinner"></div>
            Loading details...
        </div>
    `;
    
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    
    try {
        const response = await fetch(`../backend/settlement/get_settlement_details.php?id=${settlementId}`);
        const data = await response.json();
        
        console.log('Details response:', data);
        
        if (data.success) {
            // The settlement details are in data.data (from the API response)
            // data.message contains the success message string, not the data
            const settlementData = data.message;
            
            if (settlementData) {
                renderSettlementDetails(settlementData);
            } else {
                details.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-exclamation-circle"></i>
                        <p>No settlement details found</p>
                    </div>
                `;
            }
        } else {
            details.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-exclamation-circle"></i>
                    <p>${data.message || 'Failed to load details'}</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error loading settlement details:', error);
        details.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-exclamation-circle"></i>
                <p>Failed to load details. Please try again.</p>
            </div>
        `;
    }
}

function renderSettlementDetails(settlement) {
    const container = document.getElementById('settlementDetails');
    
    if (!settlement) {
        container.innerHTML = `<div class="empty-state"><p>No details available</p></div>`;
        return;
    }
    
    console.log('Rendering settlement details:', settlement);
    
    container.innerHTML = `
        <div class="details-grid">
            <div class="detail-section">
                <h4>Property Information</h4>
                <div class="detail-row">
                    <span class="label">Property:</span>
                    <span class="value">${escapeHtml(settlement.property?.name || 'N/A')} (${escapeHtml(settlement.property?.code || 'N/A')})</span>
                </div>
                <div class="detail-row">
                    <span class="label">Client:</span>
                    <span class="value">${escapeHtml(settlement.client?.name || 'N/A')}</span>
                </div>
                <div class="detail-row">
                    <span class="label">Agent:</span>
                    <span class="value">${escapeHtml(settlement.agent?.name || 'N/A')}</span>
                </div>
                <div class="detail-row">
                    <span class="label">Processed By:</span>
                    <span class="value">${escapeHtml(settlement.processed_by_name || settlement.processed_by || 'N/A')}</span>
                </div>
            </div>
            
            <div class="detail-section">
                <h4>Tenant Information</h4>
                <div class="detail-row">
                    <span class="label">Tenant:</span>
                    <span class="value">${escapeHtml(settlement.tenant?.name || 'N/A')}</span>
                </div>
                <div class="detail-row">
                    <span class="label">Apartment:</span>
                    <span class="value">${escapeHtml(settlement.tenant?.apartment_number || settlement.tenant?.apartment || 'N/A')}</span>
                </div>
                <div class="detail-row">
                    <span class="label">Period:</span>
                    <span class="value">Tracker #${settlement.tracker_id}</span>
                </div>
            </div>
            
            <div class="detail-section">
                <h4>Payment Breakdown</h4>
                <div class="detail-row">
                    <span class="label">Total Amount:</span>
                    <span class="value amount">₦${formatNumber(settlement.total_amount)}</span>
                </div>
                <div class="detail-row">
                    <span class="label">Admin Share (${settlement.admin_percentage_used || 0}%):</span>
                    <span class="value">₦${formatNumber(settlement.admin_share)}</span>
                </div>
                <div class="detail-row">
                    <span class="label">Agent Share (${settlement.agent_percentage_used || 0}%):</span>
                    <span class="value">₦${formatNumber(settlement.agent_share)}</span>
                </div>
                <div class="detail-row">
                    <span class="label">Client Share (${settlement.client_percentage_used || 0}%):</span>
                    <span class="value">₦${formatNumber(settlement.client_share)}</span>
                </div>
            </div>
            
            <div class="detail-section">
                <h4>Payment Status</h4>
                <div class="detail-row">
                    <span class="label">Settlement Status:</span>
                    <span class="value status-badge status-${settlement.settlement_status || 'pending'}">${(settlement.settlement_status || 'PENDING').toUpperCase()}</span>
                </div>
                <div class="detail-row">
                    <span class="label">Admin:</span>
                    <span class="value">${settlement.admin_paid ? '✅ Paid' : '⏳ Pending'}</span>
                </div>
                <div class="detail-row">
                    <span class="label">Agent:</span>
                    <span class="value">${settlement.agent_paid ? '✅ Paid' : '⏳ Pending'}</span>
                </div>
                <div class="detail-row">
                    <span class="label">Client:</span>
                    <span class="value">${settlement.client_paid ? '✅ Paid' : '⏳ Pending'}</span>
                </div>
            </div>
            
            <div class="detail-section full-width">
                <h4>Timeline</h4>
                <div class="detail-row">
                    <span class="label">Settlement Date:</span>
                    <span class="value">${formatDateTime(settlement.settlement_date)}</span>
                </div>
                ${settlement.admin_payment_date ? `
                    <div class="detail-row">
                        <span class="label">Admin Payment:</span>
                        <span class="value">${formatDateTime(settlement.admin_payment_date)}</span>
                    </div>
                ` : ''}
                ${settlement.agent_payment_date ? `
                    <div class="detail-row">
                        <span class="label">Agent Payment:</span>
                        <span class="value">${formatDateTime(settlement.agent_payment_date)}</span>
                    </div>
                ` : ''}
                ${settlement.client_payment_date ? `
                    <div class="detail-row">
                        <span class="label">Client Payment:</span>
                        <span class="value">${formatDateTime(settlement.client_payment_date)}</span>
                    </div>
                ` : ''}
                ${settlement.notes ? `
                    <div class="detail-row">
                        <span class="label">Notes:</span>
                        <span class="value">${escapeHtml(settlement.notes)}</span>
                    </div>
                ` : ''}
            </div>
        </div>
    `;
}
// ==================== FILTERS ====================
function applyFilters() {
    state.filters.status = document.getElementById('filterStatus').value;
    state.filters.payable = document.getElementById('filterPayable').value;
    state.filters.date_from = document.getElementById('filterDateFrom').value;
    state.filters.date_to = document.getElementById('filterDateTo').value;
    state.filters.search = document.getElementById('filterSearch').value;
    state.currentPage = 1;
    loadSettlements();
}

function clearFilters() {
    document.getElementById('filterStatus').value = '';
    document.getElementById('filterPayable').value = '';
    document.getElementById('filterDateFrom').value = '';
    document.getElementById('filterDateTo').value = '';
    document.getElementById('filterSearch').value = '';
    state.filters = { status: '', payable: '', date_from: '', date_to: '', search: '' };
    state.currentPage = 1;
    loadSettlements();
}

// ==================== PAGINATION ====================
function changePage(direction) {
    if (direction === 'prev' && state.currentPage > 1) {
        state.currentPage--;
    } else if (direction === 'next' && state.currentPage < state.totalPages) {
        state.currentPage++;
    }
    loadSettlements();
}

// ==================== MODAL FUNCTIONS ====================
function closeSettlementModal() {
    document.getElementById('settlementModal').style.display = 'none';
    document.body.style.overflow = '';
}

function printSettlement() {
    const content = document.getElementById('settlementDetails').innerHTML;
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

// ==================== OTHER FUNCTIONS ====================
function refreshData() {
    loadSettlements();
    showToast('Data refreshed', 'success');
}

function exportReport() {
    showToast('Export functionality coming soon', 'info');
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

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showToast(message, type = 'info') {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `<span>${escapeHtml(message)}</span>`;
    container.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(100%)';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Close modal on outside click
document.addEventListener('click', function(e) {
    const modal = document.getElementById('settlementModal');
    if (e.target === modal) {
        closeSettlementModal();
    }
});

// Close modal on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('settlementModal');
        if (modal.style.display === 'flex') {
            closeSettlementModal();
        }
    }
});