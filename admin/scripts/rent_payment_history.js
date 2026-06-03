// admin/scripts/rent_payment_history.js

let currentPage = 1;
let currentLimit = 20;
let currentFilters = {
    search: '',
    status: '',
    initiated_by: '',
    period_number: '',
    date_from: '',
    date_to: ''
};
let currentSort = {
    column: 'initiated_at',
    order: 'DESC'
};
let totalPages = 1;
let currentHistory = [];

document.addEventListener('DOMContentLoaded', function() {
    loadPaymentHistory();
    
    // Enter key search
    document.getElementById('searchInput')?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') applyFilters();
    });
});

async function loadPaymentHistory(page = 1) {
    currentPage = page;
    
    const params = new URLSearchParams({
        page: currentPage,
        limit: currentLimit,
        search: currentFilters.search,
        status: currentFilters.status,
        initiated_by: currentFilters.initiated_by,
        period_number: currentFilters.period_number,
        date_from: currentFilters.date_from,
        date_to: currentFilters.date_to,
        sort_by: currentSort.column,
        sort_order: currentSort.order
    });
    
    showLoading(true);
    
    try {
        const response = await fetch(`../backend/payment/fetch_rent_payment_history.php?${params}`);
        const data = await response.json();
        
        if (data.success) {
            const payload = normalizeHistoryPayload(data);
            renderTable(payload.history);
            renderPagination(payload.pagination);
        } else {
            showError(data.message || 'Failed to load payment history');
        }
    } catch (error) {
        console.error('Error:', error);
        showError('Failed to load payment history');
    } finally {
        showLoading(false);
    }
}

function normalizeHistoryPayload(data) {
    const payload = data && typeof data.data === 'object' && data.data !== null ? data.data : {};
    const history = Array.isArray(payload.history) ? payload.history : [];
    const pagination = payload.pagination || {
        current_page: currentPage,
        per_page: currentLimit,
        total_records: history.length,
        total_pages: history.length > 0 ? 1 : 0,
        from: history.length > 0 ? 1 : 0,
        to: history.length,
        has_previous: false,
        has_next: false
    };

    return { history, pagination };
}

function renderTable(history) {
    const tbody = document.getElementById('tableBody');
    currentHistory = Array.isArray(history) ? history : [];
    
    if (currentHistory.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center">No payment history found</td></tr>';
        return;
    }
    
    tbody.innerHTML = currentHistory.map(item => `
        <tr>
            <td>${item.initiated_at_formatted}</td>
            <td><strong>${item.receipt_number || 'N/A'}</strong></td>
            <td>
                <strong>${escapeHtml(item.tenant_name)}</strong><br>
                <small>${item.tenant_code}</small>
            </td>
            <td>Period #${item.period_number}</td>
            <td><strong>${item.amount_formatted}</strong></td>
            <td>
                <span class="status-badge ${item.attempt_number > 1 ? 'status-warning' : 'status-secondary'}">
                    Attempt ${item.attempt_number}
                </span>
            </td>
            <td>
                <i class="fas ${item.initiated_by_type === 'tenant' ? 'fa-user' : 'fa-user-shield'}"></i>
                ${item.initiated_by_display}
            </td>
            <td>
                <span class="status-badge status-${item.status_class}">
                    ${formatStatus(item.status)}
                </span>
            </td>
            <td>
                <button class="btn-sm btn-outline" onclick="viewDetails(${item.id})">
                    <i class="fas fa-eye"></i> View
                </button>
            </td>
        </tr>
    `).join('');
}

function renderPagination(pagination) {
    const container = document.getElementById('pagination');
    if (!container) return;
    
    if (!pagination || !pagination.total_pages || pagination.total_pages <= 1) {
        const totalRecords = pagination?.total_records || 0;
        container.innerHTML = totalRecords > 0
            ? `<div class="pagination-info">Showing ${pagination.from || 1} to ${pagination.to || totalRecords} of ${totalRecords} records</div>`
            : '';
        return;
    }
    
    let html = `
        <div class="pagination-info">
            Showing ${pagination.from} to ${pagination.to} of ${pagination.total_records} records
        </div>
        <div class="pagination-controls">
            <button class="page-btn" onclick="loadPaymentHistory(1)" ${!pagination.has_previous ? 'disabled' : ''}>
                <i class="fas fa-angle-double-left"></i>
            </button>
            <button class="page-btn" onclick="loadPaymentHistory(${pagination.current_page - 1})" ${!pagination.has_previous ? 'disabled' : ''}>
                <i class="fas fa-angle-left"></i>
            </button>
    `;
    
    const startPage = Math.max(1, pagination.current_page - 2);
    const endPage = Math.min(pagination.total_pages, startPage + 4);
    
    for (let i = startPage; i <= endPage; i++) {
        html += `<button class="page-btn ${i === pagination.current_page ? 'active' : ''}" onclick="loadPaymentHistory(${i})">${i}</button>`;
    }
    
    html += `
            <button class="page-btn" onclick="loadPaymentHistory(${pagination.current_page + 1})" ${!pagination.has_next ? 'disabled' : ''}>
                <i class="fas fa-angle-right"></i>
            </button>
            <button class="page-btn" onclick="loadPaymentHistory(${pagination.total_pages})" ${!pagination.has_next ? 'disabled' : ''}>
                <i class="fas fa-angle-double-right"></i>
            </button>
        </div>
    `;
    
    container.innerHTML = html;
}

async function viewDetails(id) {
    try {
        const response = await fetch(`../backend/payment/fetch_rent_payment_history_details.php?id=${id}`);
        const data = await response.json();
        
        if (data.success) {
            if (data.data && typeof data.data === 'object') {
                showDetailsModal(data.data);
            } else {
                showError('Invalid details response');
            }
        } else {
            showError(data.message || 'Failed to load details');
        }
    } catch (error) {
        console.error('Error:', error);
        showError('Failed to load details');
    }
}

function showDetailsModal(item) {
    const modalBody = document.getElementById('detailsBody');
    
    modalBody.innerHTML = `
        <div class="details-grid">
            <div class="details-section">
                <h4><i class="fas fa-info-circle"></i> Payment Information</h4>
                <div class="detail-row"><span class="detail-label">Receipt Number:</span><span class="detail-value">${item.receipt_number || 'N/A'}</span></div>
                <div class="detail-row"><span class="detail-label">Reference Number:</span><span class="detail-value">${item.reference_number || 'N/A'}</span></div>
                <div class="detail-row"><span class="detail-label">Transaction ID:</span><span class="detail-value">${item.transaction_id || 'N/A'}</span></div>
                <div class="detail-row"><span class="detail-label">Amount:</span><span class="detail-value"><strong>${item.amount_formatted}</strong></span></div>
                <div class="detail-row"><span class="detail-label">Attempt Number:</span><span class="detail-value">${item.attempt_number}</span></div>
                
                <div class="detail-row"><span class="detail-label">Payment Method:</span><span class="detail-value">${item.payment_method || 'N/A'}</span></div>
                <div class="detail-row"><span class="detail-label">Status:</span><span class="detail-value"><span class="status-badge status-${item.status_badge?.class || item.status_class || 'secondary'}">${item.status_badge?.text || formatStatus(item.status)}</span></span></div>
            </div>

            <div class="details-section">
                <h4><i class="fas fa-info-circle"></i> Rent Period Information</h4>
                
                <div class="detail-row"><span class="detail-label">Period Number:</span><span class="detail-value">#${item.period_number}</span></div>
                 <div class="detail-row"><span class="detail-label">Payment Frequency:</span><span class="detail-value">${item.payment_frequency}</span></div>
                 <div class="detail-row"><span class="detail-label">Period Start Date:</span><span class="detail-value">${item.start_date}</span></div>
                <div class="detail-row"><span class="detail-label">Period End Date:</span><span class="detail-value">${item.end_date}</span></div>
                 <div class="detail-row"><span class="detail-label">Rent Lease Start Date:</span><span class="detail-value">${item.lease_start_date}</span></div>
                <div class="detail-row"><span class="detail-label">Rent Lease End Date:</span><span class="detail-value">${item.lease_end_date}</span></div>
               
            </div>
            
            <div class="details-section">
                <h4><i class="fas fa-user"></i> Tenant Information</h4>
                <div class="detail-row"><span class="detail-label">Name:</span><span class="detail-value">${escapeHtml(item.tenant_name)}</span></div>
                <div class="detail-row"><span class="detail-label">Email:</span><span class="detail-value">${escapeHtml(item.tenant_email)}</span></div>
                <div class="detail-row"><span class="detail-label">Phone:</span><span class="detail-value">${escapeHtml(item.tenant_phone)}</span></div>
                <div class="detail-row"><span class="detail-label">Tenant Code:</span><span class="detail-value">${item.tenant_code}</span></div>
                <div class="detail-row"><span class="detail-label">Property:</span><span class="detail-value">${escapeHtml(item.property_name)}</span></div>
                <div class="detail-row"><span class="detail-label">Apartment:</span><span class="detail-value">${item.apartment_code}</span></div>
               
            </div>
            
            <div class="details-section">
                <h4><i class="fas fa-clock"></i> Initiation Details</h4>
                <div class="detail-row"><span class="detail-label">Initiated By:</span><span class="detail-value">${item.initiated_by_name} (${item.initiated_by_type === 'tenant' ? 'Tenant' : 'Admin'})</span></div>
                <div class="detail-row"><span class="detail-label">Initiated At:</span><span class="detail-value">${item.initiated_at_formatted}</span></div>
                <div class="detail-row"><span class="detail-label">IP Address:</span><span class="detail-value">${item.ip_address || 'N/A'}</span></div>
            </div>
            
            <div class="details-section">
                <h4><i class="fas fa-check-circle"></i> Verification Details</h4>
                <div class="detail-row"><span class="detail-label">Verified By:</span><span class="detail-value">${item.verified_by_name || 'Not verified'}</span></div>
                <div class="detail-row"><span class="detail-label">Verified At:</span><span class="detail-value">${item.verified_at_formatted}</span></div>
                <div class="detail-row"><span class="detail-label">Verification Notes:</span><span class="detail-value">${item.verification_notes || 'No notes'}</span></div>
                ${item.failure_reason ? `
                <div class="detail-row"><span class="detail-label">Failure Reason:</span><span class="detail-value" style="color: #dc2626;">${escapeHtml(item.failure_reason)}</span></div>
                ` : ''}
            </div>
        </div>
        ${item.notes ? `
        <div class="details-section" style="margin-top: 15px;">
            <h4><i class="fas fa-sticky-note"></i> Additional Notes</h4>
            <div class="detail-row"><span class="detail-value">${escapeHtml(item.notes)}</span></div>
        </div>
        ` : ''}
    `;
    
    openModal('detailsModal');
}

function applyFilters() {
    currentFilters = {
        search: document.getElementById('searchInput').value,
        status: document.getElementById('statusFilter').value,
        initiated_by: document.getElementById('initiatedByFilter').value,
        period_number: document.getElementById('periodFilter').value,
        date_from: document.getElementById('dateFromFilter').value,
        date_to: document.getElementById('dateToFilter').value
    };
    currentPage = 1;
    loadPaymentHistory();
}

function resetFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('statusFilter').value = '';
    document.getElementById('initiatedByFilter').value = '';
    document.getElementById('periodFilter').value = '';
    document.getElementById('dateFromFilter').value = '';
    document.getElementById('dateToFilter').value = '';
    applyFilters();
}

function sortTable(column) {
    if (currentSort.column === column) {
        currentSort.order = currentSort.order === 'DESC' ? 'ASC' : 'DESC';
    } else {
        currentSort.column = column;
        currentSort.order = 'DESC';
    }
    loadPaymentHistory();
}

function changeLimit() {
    currentLimit = parseInt(document.getElementById('limitSelect').value);
    currentPage = 1;
    loadPaymentHistory();
}

function exportData() {
    if (!currentHistory.length) {
        showError('No payment history available to export');
        return;
    }

    const headers = ['Date', 'Receipt Number', 'Tenant', 'Tenant Code', 'Period', 'Amount', 'Attempt', 'Initiated By', 'Status'];
    const rows = currentHistory.map(item => [
        item.initiated_at_formatted || '',
        item.receipt_number || '',
        item.tenant_name || '',
        item.tenant_code || '',
        item.period_number || '',
        item.amount_formatted || '',
        item.attempt_number || '',
        item.initiated_by_display || '',
        formatStatus(item.status)
    ]);

    const csv = [headers, ...rows]
        .map(row => row.map(value => `"${String(value).replace(/"/g, '""')}"`).join(','))
        .join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `rent_payment_history_page_${currentPage}.csv`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}

function showLoading(show) {
    const loader = document.getElementById('loadingIndicator');
    const table = document.querySelector('.table-responsive');
    if (loader) loader.style.display = show ? 'flex' : 'none';
    if (table) table.style.display = show ? 'none' : 'block';
}

function showError(message) {
    const tbody = document.getElementById('tableBody');
    tbody.innerHTML = `<tr><td colspan="9" class="text-center" style="color: #dc2626;">${message}</td></tr>`;
}

function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) modal.style.display = 'flex';
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) modal.style.display = 'none';
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatStatus(status) {
    if (!status) return 'N/A';
    return String(status)
        .replace(/_/g, ' ')
        .replace(/\b\w/g, letter => letter.toUpperCase());
}
