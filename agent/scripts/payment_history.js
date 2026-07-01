// client/scripts/payment_history.js

let currentPage = 1;
let currentFilters = {
    status: 'all',
    year: '',
    property_code: '',
    search: '',
    sort_by: 'payment_date',
    sort_order: 'desc'
};
let totalPages = 1;
let paymentHistoryRows = [];

async function loadPaymentHistory(page = 1) {
    currentPage = page;
    
    // Build query string
    const params = new URLSearchParams({
        page: currentPage,
        limit: 20,
        status: currentFilters.status,
        year: currentFilters.year,
        property_code: currentFilters.property_code,
        search: currentFilters.search,
        sort_by: currentFilters.sort_by,
        sort_order: currentFilters.sort_order
    });
    
    const loadingDiv = document.getElementById('paymentsLoading');
    const container = document.getElementById('paymentsTableContainer');
    
    if (loadingDiv) loadingDiv.style.display = 'block';
    if (container) container.style.display = 'none';
    
    try {
        const response = await fetch(`../backend/payment/fetch_payment_history.php?${params}`);
        const data = await response.json();
        
        if (data.success) {
            renderPaymentHistory(data.data);
            renderPagination(data.data.pagination);
            renderSummary(data.data.summary);
            updateFilters(data.data.filters);
        } else {
            throw new Error(data.message);
        }
    } catch (error) {
        console.error('Error loading payment history:', error);
        if (window.showToast) {
            window.showToast('Failed to load payment history', 'error');
        }
    } finally {
        if (loadingDiv) loadingDiv.style.display = 'none';
        if (container) container.style.display = 'block';
    }
}

function renderPaymentHistory(data) {
    const container = document.getElementById('paymentsTableBody');
    const cardsContainer = document.getElementById('paymentsCardsContainer');
    if (!container) return;
    paymentHistoryRows = Array.isArray(data.payments) ? data.payments : [];
    
    if (!paymentHistoryRows.length) {
        container.innerHTML = `
            <tr>
                <td colspan="8" class="empty-state">
                    <i class="fas fa-receipt"></i>
                    <p>No payment records found</p>
                </td>
            </tr>
        `;
        if (cardsContainer) {
            cardsContainer.innerHTML = `
                <div class="history-empty-card">
                    <i class="fas fa-receipt"></i>
                    <p>No payment records found</p>
                </div>
            `;
        }
        return;
    }
    
    container.innerHTML = paymentHistoryRows.map(payment => `
        <tr>
            <td data-label="Date">${escapeHtml(payment.formatted_payment_date || 'N/A')}</td>
            <td data-label="Receipt">${escapeHtml(payment.receipt_number || 'N/A')}</td>
            <td data-label="Property">${escapeHtml(payment.property_name || 'N/A')}</td>
            <td data-label="Apartment">${escapeHtml(payment.apartment_number || 'N/A')}</td>
            <td data-label="Amount" class="amount">${escapeHtml(formatMoneyText(payment.formatted_amount || 'N/A'))}</td>
            <td data-label="Status">
                <span class="status-badge status-${escapeAttribute(payment.raw_status || '')}">
                    ${escapeHtml(String(payment.status || 'pending').toUpperCase())}
                </span>
            </td>
            <td data-label="Method">${escapeHtml(payment.payment_method || 'N/A')}</td>
            <td data-label="Actions">
                <button class="btn-view-details" onclick="viewPaymentDetails(${payment.id})">
                    <i class="fas fa-eye"></i> View
                </button>
                ${payment.receipt_number ? `
                    <button class="btn-download" data-receipt-number="${escapeAttribute(payment.receipt_number)}" data-tracker-id="${escapeAttribute(payment.id)}" onclick="downloadReceipt(this.dataset.receiptNumber, this.dataset.trackerId)" aria-label="Download receipt">
                        <i class="fas fa-download"></i>
                    </button>
                ` : ''}
            </td>
        </tr>
    `).join('');

    if (cardsContainer) {
        cardsContainer.innerHTML = paymentHistoryRows.map(payment => `
            <article class="history-payment-card">
                <div class="history-card-header">
                    <div>
                        <span class="history-date">${escapeHtml(payment.formatted_payment_date || 'N/A')}</span>
                        <strong>${escapeHtml(payment.receipt_number || 'No receipt')}</strong>
                    </div>
                    <span class="status-badge status-${escapeAttribute(payment.raw_status || '')}">
                        ${escapeHtml(String(payment.status || 'pending').toUpperCase())}
                    </span>
                </div>
                <div class="history-card-amount">${escapeHtml(formatMoneyText(payment.formatted_amount || 'N/A'))}</div>
                <div class="history-card-details">
                    <div>
                        <span>Property</span>
                        <strong>${escapeHtml(payment.property_name || 'N/A')}</strong>
                    </div>
                    <div>
                        <span>Apartment</span>
                        <strong>${escapeHtml(payment.apartment_number || 'N/A')}</strong>
                    </div>
                    <div>
                        <span>Method</span>
                        <strong>${escapeHtml(payment.payment_method || 'N/A')}</strong>
                    </div>
                </div>
                <div class="history-card-actions">
                    <button class="btn-view-details" onclick="viewPaymentDetails(${payment.id})">
                        <i class="fas fa-eye"></i> View Details
                    </button>
                    ${payment.receipt_number ? `
                        <button class="btn-download-card" data-receipt-number="${escapeAttribute(payment.receipt_number)}" data-tracker-id="${escapeAttribute(payment.id)}" onclick="downloadReceipt(this.dataset.receiptNumber, this.dataset.trackerId)">
                            <i class="fas fa-download"></i> Receipt
                        </button>
                    ` : ''}
                </div>
            </article>
        `).join('');
    }
}

function renderPagination(pagination) {
    const container = document.getElementById('pagination');
    if (!container) return;
    
    totalPages = pagination.total_pages;
    
    let paginationHtml = `
        <div class="pagination-info">
            Showing ${pagination.from} to ${pagination.to} of ${pagination.total_records} entries
        </div>
        <div class="pagination-controls">
            <button class="page-btn" onclick="loadPaymentHistory(1)" ${!pagination.has_previous ? 'disabled' : ''}>
                <i class="fas fa-angle-double-left"></i>
            </button>
            <button class="page-btn" onclick="loadPaymentHistory(${pagination.current_page - 1})" ${!pagination.has_previous ? 'disabled' : ''}>
                <i class="fas fa-angle-left"></i>
            </button>
    `;
    
    // Generate page numbers
    const startPage = Math.max(1, pagination.current_page - 2);
    const endPage = Math.min(pagination.total_pages, startPage + 4);
    
    for (let i = startPage; i <= endPage; i++) {
        paginationHtml += `
            <button class="page-btn ${i === pagination.current_page ? 'active' : ''}" 
                    onclick="loadPaymentHistory(${i})">
                ${i}
            </button>
        `;
    }
    
    paginationHtml += `
            <button class="page-btn" onclick="loadPaymentHistory(${pagination.current_page + 1})" ${!pagination.has_next ? 'disabled' : ''}>
                <i class="fas fa-angle-right"></i>
            </button>
            <button class="page-btn" onclick="loadPaymentHistory(${pagination.total_pages})" ${!pagination.has_next ? 'disabled' : ''}>
                <i class="fas fa-angle-double-right"></i>
            </button>
        </div>
    `;
    
    container.innerHTML = paginationHtml;
}

function renderSummary(summary) {
    const container = document.getElementById('paymentsSummary');
    if (!container) return;
    
    container.innerHTML = `
        <div class="summary-card total-paid">
            <div class="summary-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="summary-details">
                <div class="summary-label">Total Paid</div>
                <div class="summary-amount">${escapeHtml(formatMoneyText(summary.total_paid))}</div>
                <div class="summary-count">${summary.total_paid_count} transactions</div>
            </div>
        </div>
        <div class="summary-card total-pending">
            <div class="summary-icon">
                <i class="fas fa-clock"></i>
            </div>
            <div class="summary-details">
                <div class="summary-label">Pending</div>
                <div class="summary-amount">${escapeHtml(formatMoneyText(summary.total_pending))}</div>
                <div class="summary-count">${summary.total_pending_count} transactions</div>
            </div>
        </div>
        <div class="summary-card total-failed">
            <div class="summary-icon">
                <i class="fas fa-times-circle"></i>
            </div>
            <div class="summary-details">
                <div class="summary-label">Failed</div>
                <div class="summary-amount">${escapeHtml(formatMoneyText(summary.total_failed))}</div>
                <div class="summary-count">${summary.total_failed_count} transactions</div>
            </div>
        </div>
        <div class="summary-card total-amount">
            <div class="summary-icon">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="summary-details">
                <div class="summary-label">Total Amount</div>
                <div class="summary-amount">${escapeHtml(formatMoneyText(summary.total_amount))}</div>
                <div class="summary-count">${summary.total_records} total records</div>
            </div>
        </div>
    `;
}

function updateFilters(filtersData) {
    // Update year filter dropdown
    const yearSelect = document.getElementById('filterYear');
    if (yearSelect && filtersData.available_years) {
        yearSelect.innerHTML = '<option value="">All Years</option>' + 
            filtersData.available_years.map(year => `
                <option value="${year}" ${filtersData.current_filters.year == year ? 'selected' : ''}>
                    ${year}
                </option>
            `).join('');
    }
    
    // Update property filter dropdown
    const propertySelect = document.getElementById('filterProperty');
    if (propertySelect && filtersData.properties) {
        propertySelect.innerHTML = '<option value="">All Properties</option>' + 
            filtersData.properties.map(prop => `
                <option value="${prop.property_code}" ${filtersData.current_filters.property_code === prop.property_code ? 'selected' : ''}>
                    ${escapeHtml(prop.name)}
                </option>
            `).join('');
    }
    
    // Update status filter
    const statusSelect = document.getElementById('filterStatus');
    if (statusSelect && filtersData.available_statuses) {
        statusSelect.value = filtersData.current_filters.status;
    }
    
    // Update search input
    const searchInput = document.getElementById('searchPayments');
    if (searchInput) {
        searchInput.value = String(filtersData.current_filters.search || '').replace(/^%|%$/g, '');
    }
}

function applyFilters() {
    currentFilters.status = document.getElementById('filterStatus')?.value || 'all';
    currentFilters.year = document.getElementById('filterYear')?.value || '';
    currentFilters.property_code = document.getElementById('filterProperty')?.value || '';
    currentFilters.search = document.getElementById('searchPayments')?.value || '';
    
    loadPaymentHistory(1);
}

function resetFilters() {
    currentFilters = {
        status: 'all',
        year: '',
        property_code: '',
        search: '',
        sort_by: 'payment_date',
        sort_order: 'desc'
    };
    
    // Reset form inputs
    const statusSelect = document.getElementById('filterStatus');
    const yearSelect = document.getElementById('filterYear');
    const propertySelect = document.getElementById('filterProperty');
    const searchInput = document.getElementById('searchPayments');
    
    if (statusSelect) statusSelect.value = 'all';
    if (yearSelect) yearSelect.value = '';
    if (propertySelect) propertySelect.value = '';
    if (searchInput) searchInput.value = '';
    
    loadPaymentHistory(1);
}

function sortTable(column) {
    if (currentFilters.sort_by === column) {
        currentFilters.sort_order = currentFilters.sort_order === 'desc' ? 'asc' : 'desc';
    } else {
        currentFilters.sort_by = column;
        currentFilters.sort_order = 'desc';
    }
    loadPaymentHistory(1);
}

async function viewPaymentDetails(paymentId) {
    const payment = paymentHistoryRows.find(item => Number(item.id) === Number(paymentId));

    if (payment) {
        showPaymentDetailsModal(payment);
        return;
    }

    if (window.showToast) {
        window.showToast('Payment details are not available for this row', 'error');
    }
}

function formatDisplayStatus(status) {
    return String(status || 'pending').replace(/_/g, ' ').toUpperCase();
}

function formatDisplayPeriod(payment) {
    if (payment.formatted_period) return payment.formatted_period;
    if (payment.period_number) return `Period ${payment.period_number}`;
    return 'N/A';
}

function showPaymentDetailsModal(payment) {
    // Create or get modal
    let modal = document.getElementById('paymentDetailsModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'paymentDetailsModal';
        modal.className = 'modal';
        document.body.appendChild(modal);
    }
    
    modal.innerHTML = `
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3>Payment Details</h3>
                <button class="modal-close" onclick="closeModal('paymentDetailsModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="payment-details">
                    <div class="detail-row">
                        <label>Receipt Number:</label>
                        <span>${escapeHtml(payment.receipt_number || 'N/A')}</span>
                    </div>
                    <div class="detail-row">
                        <label>Reference Number:</label>
                        <span>${escapeHtml(payment.reference_number || 'N/A')}</span>
                    </div>
                    <div class="detail-row">
                        <label>Amount:</label>
                        <span class="amount">${escapeHtml(formatMoneyText(payment.formatted_amount || 'N/A'))}</span>
                    </div>
                    <div class="detail-row">
                        <label>Payment Date:</label>
                        <span>${escapeHtml(payment.payment_date_full || 'N/A')}</span>
                    </div>
                    <div class="detail-row">
                        <label>Status:</label>
                        <span class="status-badge status-${escapeAttribute(payment.raw_status || '')}">${formatDisplayStatus(payment.status)}</span>
                    </div>
                    <div class="detail-row">
                        <label>Payment Method:</label>
                        <span>${escapeHtml(payment.payment_method || 'N/A')}</span>
                    </div>
                    <div class="detail-row">
                        <label>Property:</label>
                        <span>${escapeHtml(payment.property_name || 'N/A')}</span>
                    </div>
                    <div class="detail-row">
                        <label>Apartment:</label>
                        <span>${escapeHtml(payment.apartment_number || 'N/A')}</span>
                    </div>
                    <div class="detail-row">
                        <label>Period:</label>
                        <span>${escapeHtml(formatDisplayPeriod(payment))}</span>
                    </div>
                    ${payment.notes ? `
                        <div class="detail-row">
                            <label>Notes:</label>
                            <span>${escapeHtml(payment.notes)}</span>
                        </div>
                    ` : ''}
                </div>
            </div>
            <div class="modal-footer">
                ${payment.receipt_number ? `
                    <button class="btn-primary" data-receipt-number="${escapeAttribute(payment.receipt_number)}" data-tracker-id="${escapeAttribute(payment.id)}" onclick="downloadReceipt(this.dataset.receiptNumber, this.dataset.trackerId)">
                        <i class="fas fa-download"></i> Download Receipt
                    </button>
                ` : ''}
                <button class="btn-secondary" onclick="closeModal('paymentDetailsModal')">Close</button>
            </div>
        </div>
    `;
    
    modal.style.display = 'flex';
}

function downloadReceipt(receiptNumber, trackerId = '') {
    const params = new URLSearchParams();

    if (receiptNumber) {
        params.set('receipt_number', receiptNumber);
    }

    if (trackerId) {
        params.set('tracker_id', trackerId);
    }

    window.open(`../backend/payment/download_receipt.php?${params.toString()}`, '_blank');
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'none';
    }
}

function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}

function escapeAttribute(text) {
    return escapeHtml(String(text ?? ''));
}

function formatMoneyText(value) {
    return String(value ?? 'N/A').replace(/â‚¦/g, '₦');
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    loadPaymentHistory();
});
