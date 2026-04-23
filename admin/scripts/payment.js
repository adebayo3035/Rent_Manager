// Global variables
let currentPage = 1;
let currentLimit = 10;
let currentFilters = {};


// Initialize
document.addEventListener("DOMContentLoaded", function () {
    setTodayDate();
    loadFilters();
    loadPayments();
    loadStatistics();
    loadQuickTenants();
    loadTenantsForModal();
    loadApartmentsForModal();
});

// Set today's date in date fields
function setTodayDate() {
    const today = new Date().toISOString().split("T")[0];
    const paymentDate = document.getElementById("payment_date");
    const dueDate = document.getElementById("due_date");
    if (paymentDate) paymentDate.value = today;
    if (dueDate) dueDate.value = today;
}

// Load filter dropdowns
async function loadFilters() {
    try {
        const response = await fetch(`../backend/payment/payment_manager.php?action=fetch&page=1&limit=1`);
        const data = await response.json();

        if (data.success) {
            // Build filter UI dynamically
            buildFilterUI();
        }
    } catch (error) {
        console.error("Error loading filters:", error);
    }
}

function buildFilterUI() {
    const container = document.getElementById("filtersContainer");
    if (!container) return;
    
    container.innerHTML = `
        <div class="form-group">
            <label>Search</label>
            <input type="text" class="form-control" id="filterSearch" 
                   placeholder="Search tenant, receipt #...">
        </div>
        <div class="form-group">
            <label>Status</label>
            <select class="form-control" id="filterStatus">
                <option value="">All Statuses</option>
                <option value="pending">Pending</option>
                <option value="completed">Completed</option>
                <option value="failed">Failed</option>
                <option value="refunded">Refunded</option>
            </select>
        </div>
        <div class="form-group">
            <label>Date From</label>
            <input type="date" class="form-control" id="filterDateFrom">
        </div>
        <div class="form-group">
            <label>Date To</label>
            <input type="date" class="form-control" id="filterDateTo">
        </div>
    `;
}

// Load payments
async function loadPayments() {
    const limitSelect = document.getElementById("limitSelect");
    if (limitSelect) {
        currentLimit = parseInt(limitSelect.value);
    }

    // Build query string
    let query = `action=fetch&page=${currentPage}&limit=${currentLimit}`;

    // Add filters
    if (currentFilters.search) query += `&search=${encodeURIComponent(currentFilters.search)}`;
    if (currentFilters.tenant_code) query += `&tenant_code=${currentFilters.tenant_code}`;
    if (currentFilters.property_code) query += `&property_code=${currentFilters.property_code}`;
    if (currentFilters.apartment_code) query += `&apartment_code=${currentFilters.apartment_code}`;
    if (currentFilters.payment_status) query += `&payment_status=${currentFilters.payment_status}`;
    if (currentFilters.payment_method) query += `&payment_method=${currentFilters.payment_method}`;
    if (currentFilters.date_from) query += `&date_from=${currentFilters.date_from}`;
    if (currentFilters.date_to) query += `&date_to=${currentFilters.date_to}`;

    try {
        const response = await fetch(`../backend/payment/payment_manager.php?${query}`);
        const data = await response.json();

        if (data.success) {
            renderPaymentsTable(data.payments || []);
            renderPagination(data.pagination);
        } else {
            showAlert(data.message || "Error loading payments", "error");
        }
    } catch (error) {
        console.error("Error loading payments:", error);
        showAlert("Error loading payments", "error");
    }
}

function renderPaymentsTable(payments) {
    const tbody = document.getElementById("paymentsTableBody");
    if (!tbody) return;
    
    tbody.innerHTML = "";

    if (!payments || payments.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="8" style="text-align: center; padding: 40px; color: var(--gray);">
                    <i class="fas fa-search" style="font-size: 48px; margin-bottom: 15px; display: block;"></i>
                    <h3>No payments found</h3>
                    <p>Try adjusting your filters or record a new payment</p>
                </td>
            </tr>
        `;
        return;
    }

    payments.forEach((payment) => {
        const row = document.createElement("tr");
        row.innerHTML = `
            <td><strong>${payment.receipt_number || payment.id}</strong></td>
            <td>
                <div><strong>${payment.tenant_name || 'N/A'}</strong></div>
                <small>${payment.tenant_email || ''}</small>
            </td>
            <td>
                <div>${payment.property_name || 'N/A'}</div>
                <small>Apartment: ${payment.apartment_number || 'N/A'}</small>
            </td>
            <td><strong>₦${payment.amount_formatted || formatNumber(payment.amount)}</strong></td>
            <td>${payment.payment_date_formatted || formatDate(payment.payment_date)}</td>
            <td>${formatPaymentMethod(payment.payment_method)}</td>
            <td>
                <span class="status-badge status-${payment.payment_status}">
                    ${(payment.payment_status || 'pending').toUpperCase()}
                </span>
            </td>
            <td>
                <div class="action-btns">
                    <button class="action-btn" onclick="viewPayment(${payment.id})" title="View Details">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button class="action-btn" onclick="editPayment(${payment.id})" title="Edit">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="action-btn" onclick="deletePayment(${payment.id})" title="Delete" style="color: var(--danger);">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </td>
        `;
        tbody.appendChild(row);
    });
}

function renderPagination(pagination) {
    const container = document.getElementById("paginationContainer");
    if (!container) return;
    
    container.innerHTML = "";

    if (!pagination || pagination.total_pages <= 1) return;

    // Previous button
    const prevBtn = document.createElement("button");
    prevBtn.className = "page-btn";
    prevBtn.innerHTML = '<i class="fas fa-chevron-left"></i>';
    prevBtn.disabled = currentPage === 1;
    prevBtn.onclick = () => {
        if (currentPage > 1) {
            currentPage--;
            loadPayments();
        }
    };
    container.appendChild(prevBtn);

    // Page numbers
    const startPage = Math.max(1, currentPage - 2);
    const endPage = Math.min(pagination.total_pages, currentPage + 2);

    for (let i = startPage; i <= endPage; i++) {
        const pageBtn = document.createElement("button");
        pageBtn.className = `page-btn ${i === currentPage ? "active" : ""}`;
        pageBtn.textContent = i;
        pageBtn.onclick = () => {
            currentPage = i;
            loadPayments();
        };
        container.appendChild(pageBtn);
    }

    // Next button
    const nextBtn = document.createElement("button");
    nextBtn.className = "page-btn";
    nextBtn.innerHTML = '<i class="fas fa-chevron-right"></i>';
    nextBtn.disabled = currentPage === pagination.total_pages;
    nextBtn.onclick = () => {
        if (currentPage < pagination.total_pages) {
            currentPage++;
            loadPayments();
        }
    };
    container.appendChild(nextBtn);
}

// Filter functions
function applyFilters() {
    const searchInput = document.getElementById("filterSearch");
    const statusSelect = document.getElementById("filterStatus");
    const dateFrom = document.getElementById("filterDateFrom");
    const dateTo = document.getElementById("filterDateTo");
    
    currentFilters = {
        search: searchInput ? searchInput.value : '',
        payment_status: statusSelect ? statusSelect.value : '',
        date_from: dateFrom ? dateFrom.value : '',
        date_to: dateTo ? dateTo.value : '',
    };

    currentPage = 1;
    loadPayments();
}

function resetFilters() {
    currentFilters = {};

    // Reset form fields
    const filterIds = ["filterSearch", "filterStatus", "filterDateFrom", "filterDateTo"];
    filterIds.forEach((id) => {
        const element = document.getElementById(id);
        if (element) element.value = "";
    });

    currentPage = 1;
    loadPayments();
}

// Load statistics
async function loadStatistics() {
    try {
        const response = await fetch("../backend/payment/payment_manager.php?action=get_statistics");
        const data = await response.json();

        if (data.success && data.statistics) {
            renderStatistics(data.statistics);
        }
    } catch (error) {
        console.error("Error loading statistics:", error);
    }
}

function renderStatistics(stats) {
    const container = document.getElementById("statsContainer");
    if (!container) return;

    const summary = stats.summary || {};
    
    const statCards = [
        {
            icon: "fas fa-money-bill-wave",
            color: "#10b981",
            title: "Total Revenue",
            value: "₦" + formatNumber(summary.total_revenue || 0),
        },
        {
            icon: "fas fa-receipt",
            color: "#3b82f6",
            title: "Total Payments",
            value: summary.total_payments || 0,
        },
        {
            icon: "fas fa-check-circle",
            color: "#10b981",
            title: "Completed",
            value: summary.completed_payments || 0,
        },
        {
            icon: "fas fa-clock",
            color: "#f59e0b",
            title: "Pending",
            value: summary.pending_payments || 0,
        },
        {
            icon: "fas fa-warning",
            color: "rgb(255, 85, 85)",
            title: "Failed",
            value: summary.failed_payments || 0,
        },
        {
            icon: "fas fa-cancel",
            color: "rgb(255, 201, 85)",
            title: "Cancelled",
            value: summary.cancelled_payments || 0,
        },
        {
            icon: "fas fa-undo",
            color: "rgb(255, 85, 232)",
            title: "Refunded",
            value: summary.refunded_payments || 0,
        },
        {
            icon: "fas fa-chart-line",
            color: "#8b5cf6",
            title: "Average Payment",
            value: "₦" + formatNumber(summary.average_payment || 0),
        },
    ];

    container.innerHTML = statCards
        .map(
            (card) => `
            <div class="stat-card">
                <div class="stat-icon" style="background: ${card.color}">
                    <i class="${card.icon}"></i>
                </div>
                <div class="stat-info">
                    <h3>${card.title}</h3>
                    <p class="stat-number">${card.value}</p>
                </div>
            </div>
        `
        )
        .join("");

    // Render revenue chart if data exists
    // if (stats.revenue_trend && stats.revenue_trend.length > 0) {
    //     renderRevenueChart(stats.revenue_trend);
    // }
}
// Modal functions
function openRecordPaymentModal() {
    const modal = document.getElementById("recordPaymentModal");
    if (modal) modal.style.display = "flex";
}

function closeRecordPaymentModal() {
    const modal = document.getElementById("recordPaymentModal");
    if (modal) modal.style.display = "none";
    const form = document.getElementById("paymentForm");
    if (form) form.reset();
    setTodayDate();
}

function openViewPaymentModal() {
    const modal = document.getElementById("viewPaymentModal");
    if (modal) modal.style.display = "flex";
}

function closeViewPaymentModal() {
    const modal = document.getElementById("viewPaymentModal");
    if (modal) modal.style.display = "none";
}

function showConfirmModal({
    title = "Confirm Action",
    message = "Are you sure you want to continue?",
    confirmText = "Confirm",
    cancelText = "Cancel",
    variant = "warning"
}) {
    return new Promise((resolve) => {
        let modal = document.getElementById("customConfirmModal");

        if (!modal) {
            modal = document.createElement("div");
            modal.id = "customConfirmModal";
            modal.className = "custom-confirm-overlay";
            modal.innerHTML = `
                <div class="custom-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="customConfirmTitle">
                    <div class="custom-confirm-icon">
                        <i class="fas fa-circle-exclamation"></i>
                    </div>
                    <div class="custom-confirm-content">
                        <h3 id="customConfirmTitle"></h3>
                        <p id="customConfirmMessage"></p>
                    </div>
                    <div class="custom-confirm-actions">
                        <button type="button" class="btn btn-outline custom-confirm-cancel">Cancel</button>
                        <button type="button" class="btn custom-confirm-ok">Confirm</button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
        }

        const dialog = modal.querySelector(".custom-confirm-dialog");
        const icon = modal.querySelector(".custom-confirm-icon i");
        const titleElement = modal.querySelector("#customConfirmTitle");
        const messageElement = modal.querySelector("#customConfirmMessage");
        const confirmButton = modal.querySelector(".custom-confirm-ok");
        const cancelButton = modal.querySelector(".custom-confirm-cancel");

        const iconMap = {
            warning: "fa-circle-exclamation",
            success: "fa-circle-check",
            info: "fa-circle-info",
            danger: "fa-triangle-exclamation"
        };

        dialog.dataset.variant = variant;
        icon.className = `fas ${iconMap[variant] || iconMap.warning}`;
        titleElement.textContent = title;
        messageElement.textContent = message;
        confirmButton.textContent = confirmText;
        cancelButton.textContent = cancelText;

        const handleKeydown = (event) => {
            if (event.key === "Escape") {
                cleanup(false);
            }
        };

        const cleanup = (result) => {
            modal.classList.remove("active");
            confirmButton.onclick = null;
            cancelButton.onclick = null;
            modal.onclick = null;
            document.removeEventListener("keydown", handleKeydown);
            resolve(result);
        };

        confirmButton.onclick = () => cleanup(true);
        cancelButton.onclick = () => cleanup(false);
        modal.onclick = (event) => {
            if (event.target === modal) {
                cleanup(false);
            }
        };

        modal.classList.add("active");
        document.addEventListener("keydown", handleKeydown);
        confirmButton.focus();
    });
}

// Load tenants for quick payment (using tenant_code as string)
async function loadQuickTenants() {
    try {
        const response = await fetch("../backend/tenants/get_tenants.php?status=1&limit=100");
        const data = await response.json();

        if (data.success) {
            const select = document.getElementById("quickTenant");
            if (select) {
                const tenants = data.data || data.tenants || [];
                select.innerHTML = '<option value="">Select Tenant</option>' +
                    tenants.map((t) => `<option value="${t.tenant_code}">${t.firstname} ${t.lastname}</option>`).join("");
            }
        }
    } catch (error) {
        console.error("Error loading tenants:", error);
    }
}

async function loadTenantsForModal() {
    try {
        const response = await fetch("../backend/tenants/get_tenants.php?status=1&limit=100");
        const data = await response.json();

        if (data.success) {
            const select = document.getElementById("tenant_code");
            if (select) {
                const tenants = data.data || data.tenants || [];
                select.innerHTML = '<option value="">Select Tenant</option>' +
                    tenants.map((t) => `<option value="${t.tenant_code}">${t.firstname} ${t.lastname} (${t.email})</option>`).join("");
            }
        }
    } catch (error) {
        console.error("Error loading tenants for modal:", error);
    }
}

async function loadApartmentsForModal() {
    try {
        const response = await fetch("../backend/apartments/fetch_apartments.php?status=1&limit=100");
        const data = await response.json();

        if (data.success) {
            const select = document.getElementById("apartment_code");
            if (select) {
                const apartments = data.data || data.apartments || [];
                select.innerHTML = '<option value="">Select Apartment</option>' +
                    apartments.map((apt) => `<option value="${apt.apartment_code}">${apt.property_name || ''} - ${apt.apartment_number} (${apt.apartment_code})</option>`).join("");
            }
        }
    } catch (error) {
        console.error("Error loading apartments for modal:", error);
    }
}

// Form submissions
async function quickRecordPayment(event) {
    if (event) event.preventDefault();

    const tenantSelect = document.getElementById("quickTenant");
    const amountInput = document.getElementById("quickAmount");
    const methodSelect = document.getElementById("quickMethod");

    const data = {
        tenant_code: tenantSelect ? tenantSelect.value : '',
        amount: amountInput ? parseFloat(amountInput.value) : 0,
        payment_method: methodSelect ? methodSelect.value : 'cash'
    };

    if (!data.tenant_code || data.amount <= 0) {
        showAlert("Please select a tenant and enter a valid amount", "error");
        return;
    }

    try {
        const response = await fetch("../backend/payment/payment_manager.php?action=record_payment", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
            },
            body: JSON.stringify(data),
        });

        const result = await response.json();

        if (result.success) {
            showAlert("Payment recorded successfully! Receipt #: " + result.receipt_number, "success");
            if (event) event.target.reset();
            loadPayments();
            loadStatistics();
        } else {
            showAlert(result.message, "error");
        }
    } catch (error) {
        console.error("Error:", error);
        showAlert("Error recording payment", "error");
    }
}

async function submitPaymentForm(event) {
    if (event) event.preventDefault();

    const form = document.getElementById("paymentForm");
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());

    // Convert numeric fields
    data.amount = parseFloat(data.amount) || 0;
    data.balance = parseFloat(data.balance) || 0;

    try {
        const response = await fetch("../backend/payment/payment_manager.php?action=create", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
            },
            body: JSON.stringify(data),
        });

        const result = await response.json();

        if (result.success) {
            showAlert("Payment created successfully! Receipt #: " + result.receipt_number, "success");
            closeRecordPaymentModal();
            loadPayments();
            loadStatistics();
        } else {
            showAlert(result.message, "error");
        }
    } catch (error) {
        console.error("Error:", error);
        showAlert("Error creating payment", "error");
    }
}

// View payment details
async function viewPayment(paymentId) {
    try {
        const response = await fetch(`../backend/payment/payment_manager.php?action=fetch_single&id=${paymentId}`);
        const data = await response.json();

        if (data.success) {
            renderPaymentDetails(data.payment);
            openViewPaymentModal();
        } else {
            showAlert(data.message, "error");
        }
    } catch (error) {
        console.error("Error:", error);
        showAlert("Error loading payment details", "error");
    }
}

function renderPaymentDetails(payment) {
    const container = document.getElementById("paymentDetails");
    if (!container) return;

    // Store payment ID for status update
    window.currentViewingPaymentId = payment.id;
    window.currentViewingPaymentStatus = payment.payment_status;
    window.currentViewingPaymentType = payment.payment_type;

    const statusOptions = `
        <select id="paymentStatusSelect" class="status-select" onchange="confirmStatusChange()">
            <option value="pending" ${payment.payment_status === 'pending' ? 'selected' : ''}>Pending</option>
            <option value="completed" ${payment.payment_status === 'completed' ? 'selected' : ''}>Completed</option>
            <option value="failed" ${payment.payment_status === 'failed' ? 'selected' : ''}>Failed</option>
            <option value="refunded" ${payment.payment_status === 'refunded' ? 'selected' : ''}>Refunded</option>
        </select>
    `;

    const detailsHTML = `
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px;">
            <div>
                <h4>Payment Information</h4>
                <table class="data-table" style="width: 100%;">
                    <tr><td><strong>Receipt #:</strong></td><td>${payment.receipt_number || 'N/A'}</td></tr>
                    <tr><td><strong>Amount:</strong></td><td>₦${formatNumber(payment.amount)}</td></tr>
                    <tr><td><strong>Date:</strong></td><td>${formatDate(payment.payment_date)}</td></tr>
                    <tr><td><strong>Due Date:</strong></td><td>${formatDate(payment.due_date) || 'N/A'}</td></tr>
                    <tr>
                        <td><strong>Status:</strong></td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                <span class="status-badge status-${payment.payment_status}">
                                    ${(payment.payment_status || 'pending').toUpperCase()}
                                </span>
                                ${payment.payment_status === 'pending' ? `
                                    <button class="btn-edit-status" onclick="openStatusUpdateModal()" 
                                            style="background: #667eea; color: white; border: none; padding: 4px 12px; border-radius: 4px; cursor: pointer;">
                                        <i class="fas fa-edit"></i> Change Status
                                    </button>
                                ` : ''}
                            </div>
                        </td>
                    </tr>
                    <tr><td><strong>Method:</strong></td><td>${formatPaymentMethod(payment.payment_method)}</td></tr>
                    <tr><td><strong>Reference:</strong></td><td>${payment.reference_number || 'N/A'}</td></tr>
                    <tr><td><strong>Period:</strong></td><td>${payment.payment_period || 'N/A'}</td></tr>
                    ${payment.period_start_date ? `
                    <tr><td><strong>Period Start:</strong></td><td>${formatDate(payment.period_start_date)}</td></tr>
                    <tr><td><strong>Period End:</strong></td><td>${formatDate(payment.period_end_date)}</td></tr>
                    ` : ''}
                </table>
            </div>
            
            <div>
                <h4>Tenant Information</h4>
                <table class="data-table" style="width: 100%;">
                    <tr><td><strong>Name:</strong></td><td>${payment.tenant_name || 'N/A'}</td></tr>
                    <tr><td><strong>Email:</strong></td><td>${payment.tenant_email || 'N/A'}</td></tr>
                    <tr><td><strong>Phone:</strong></td><td>${payment.tenant_phone || 'N/A'}</td></tr>
                    <tr><td><strong>Tenant Code:</strong></td><td>${payment.tenant_code || 'N/A'}</td></tr>
                </table>
                
                <h4 style="margin-top: 20px;">Property Information</h4>
                <table class="data-table" style="width: 100%;">
                    <tr><td><strong>Property:</strong></td><td>${payment.property_name || 'N/A'}</td></tr>
                    <tr><td><strong>Apartment:</strong></td><td>${payment.apartment_number || 'N/A'}</td></tr>
                    <tr><td><strong>Property Code:</strong></td><td>${payment.property_code || 'N/A'}</td></tr>
                </table>
            </div>
        </div>
        
        ${payment.description ? `
        <div style="margin-bottom: 20px;">
            <h4>Description</h4>
            <p>${payment.description}</p>
        </div>
        ` : ''}
        
        ${payment.notes ? `
        <div style="margin-bottom: 20px;">
            <h4>Notes</h4>
            <p style="color: #666; font-style: italic;">${payment.notes}</p>
        </div>
        ` : ''}
    `;

    container.innerHTML = detailsHTML;
}

// Status Update Modal Functions
function openStatusUpdateModal() {
    const modalHtml = `
        <div id="statusUpdateModal" class="modal" style="display: flex;">
            <div class="modal-content" style="max-width: 450px;">
                <div class="modal-header">
                    <h3>Update Payment Status</h3>
                    <button class="modal-close action-btn" onclick="closeStatusUpdateModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Current Status</label>
                        <input type="text" class = "form-control" value="${window.currentViewingPaymentStatus.toUpperCase()}" readonly style="background: #f5f5f5;">
                    </div>
                    <div class="form-group">
                        <label>New Status *</label>
                        <select id="newPaymentStatus" class="form-control" required>
                            <option value="">Select Status</option>
                            <option value="pending">Pending</option>
                            <option value="completed">Completed</option>
                            <option value="failed">Failed</option>
                            <option value="refunded">Refunded</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Notes (Optional)</label>
                        <textarea id="statusUpdateNotes" rows="3" class="form-control" 
                                  placeholder="Enter reason for status change..."></textarea>
                    </div>
                    ${window.currentViewingPaymentType === 'rent' ? `
                    <div class="alert-info" style="background: #e8f0fe; padding: 10px; border-radius: 6px; margin-top: 10px;">
                        <i class="fas fa-info-circle"></i> 
                        <small>Changing status to "Completed" will update the tenant's lease end date.</small>
                    </div>
                    ` : ''}
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" onclick="closeStatusUpdateModal()">Cancel</button>
                    <button class="btn btn-primary" onclick="confirmStatusUpdate()">Update Status</button>
                </div>
            </div>
        </div>
    `;
    
    // Remove existing modal if any
    const existingModal = document.getElementById('statusUpdateModal');
    if (existingModal) existingModal.remove();
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
}

function closeStatusUpdateModal() {
    const modal = document.getElementById('statusUpdateModal');
    if (modal) modal.remove();
}

async function confirmStatusUpdate() {
    const newStatus = document.getElementById('newPaymentStatus')?.value;
    const notes = document.getElementById('statusUpdateNotes')?.value;
    
    if (!newStatus) {
        alert('Please select a new status');
        return;
    }
    
    if (newStatus === window.currentViewingPaymentStatus) {
        alert('New status is the same as current status');
        return;
    }
    
    const confirmMessage = `Are you sure you want to change the payment status from ${window.currentViewingPaymentStatus.toUpperCase()} to ${newStatus.toUpperCase()}?`;
    const confirmed = await showConfirmModal({
        title: "Update Payment Status?",
        message: confirmMessage,
        confirmText: "Yes, Update",
        cancelText: "Cancel",
        variant: "warning"
    });
    if (!confirmed) return;
    
    // Show loading state
    const confirmBtn = document.querySelector('#statusUpdateModal .btn-primary');
    const originalText = confirmBtn.innerHTML;
    confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
    confirmBtn.disabled = true;
    
    try {
        const response = await fetch('../backend/payment/payment_manager.php?action=update_status', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                payment_id: window.currentViewingPaymentId,
                status: newStatus,
                notes: notes
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert(data.message);
            closeStatusUpdateModal();
            // Refresh the payment details and the table
            await viewPayment(window.currentViewingPaymentId);
            await loadPayments();
        } else {
            alert(data.message || 'Failed to update payment status');
        }
    } catch (error) {
        console.error('Error updating payment status:', error);
        alert('An error occurred while updating the payment status');
    } finally {
        confirmBtn.innerHTML = originalText;
        confirmBtn.disabled = false;
    }
}
// Delete payment
async function deletePayment(paymentId) {
    const confirmed = await showConfirmModal({
        title: "Delete Payment?",
        message: "Are you sure you want to delete this payment? This action cannot be undone.",
        confirmText: "Yes, Delete",
        cancelText: "Keep Payment",
        variant: "danger"
    });

    if (!confirmed) {
        return;
    }

    try {
        const response = await fetch("../backend/payment/payment_manager.php?action=delete", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
            },
            body: JSON.stringify({ id: paymentId }),
        });

        const result = await response.json();

        if (result.success) {
            showAlert("Payment deleted successfully", "success");
            loadPayments();
            loadStatistics();
        } else {
            showAlert(result.message, "error");
        }
    } catch (error) {
        console.error("Error:", error);
        showAlert("Error deleting payment", "error");
    }
}

// Edit payment (placeholder)
function editPayment(paymentId) {
    showAlert("Edit functionality to be implemented", "info");
}

// Export payments
function exportPayments() {
    showAlert("Export functionality to be implemented", "info");
}

// Utility functions
function formatNumber(value) {
    if (!value) return "0.00";
    return parseFloat(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatDate(dateString) {
    if (!dateString) return "N/A";
    try {
        const date = new Date(dateString);
        return date.toLocaleDateString("en-US", { year: "numeric", month: "short", day: "numeric" });
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

function showAlert(message, type = "info") {
    // Create alert element
    const alert = document.createElement("div");
    alert.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 15px 20px;
        background: ${type === "success" ? "#10b981" : type === "error" ? "#ef4444" : "#3b82f6"};
        color: white;
        border-radius: 6px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        z-index: 10000;
        display: flex;
        align-items: center;
        gap: 10px;
        animation: slideIn 0.3s ease;
    `;

    alert.innerHTML = `
        <i class="fas ${type === "success" ? "fa-check-circle" : type === "error" ? "fa-exclamation-circle" : "fa-info-circle"}"></i>
        <span>${message}</span>
    `;

    document.body.appendChild(alert);

    // Remove after 3 seconds
    setTimeout(() => {
        alert.style.animation = "slideOut 0.3s ease";
        setTimeout(() => alert.remove(), 300);
    }, 3000);
}

// Auto-refresh every 5 minutes
setInterval(() => {
    if (document.visibilityState === "visible") {
        loadPayments();
        loadStatistics();
    }
}, 5 * 60 * 1000);
