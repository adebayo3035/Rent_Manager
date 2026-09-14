let currentPage = 1;
let currentTab = "pending";
let currentTrackerId = null;
let currentPeriodNumber = null;

// ==================== SAFE MODAL FALLBACKS ====================
// FIX #6: openModal/closeModal are used throughout this file but are not
// defined here. They are expected to come from a shared modal-helper script.
// If that script hasn't loaded (or isn't present), calling them would throw
// a ReferenceError and silently break the whole flow. These fallbacks make
// sure the details modal still opens/closes even if the shared helpers are
// missing, by toggling the same ".active" class pattern used elsewhere in
// this file (e.g. closeVerifyModal).
if (typeof window.openModal !== "function") {
  window.openModal = function (modalId) {
    const modal = document.getElementById(modalId);
    if (modal) modal.classList.add("active");
  };
}
if (typeof window.closeModal !== "function") {
  window.closeModal = function (modalId) {
    const modal = document.getElementById(modalId);
    if (modal) modal.classList.remove("active");
  };
}

// Initialize
document.addEventListener("DOMContentLoaded", function () {
  loadStatistics();
  loadPendingVerifications();
});

// Switch tabs
function switchTab(tab) {
  currentTab = tab;
  currentPage = 1;

  // Update tab buttons
  document
    .querySelectorAll(".tab-btn")
    .forEach((btn) => btn.classList.remove("active"));
  // Find the clicked button and add active class
  const clickedBtn = Array.from(document.querySelectorAll(".tab-btn")).find(
    (btn) =>
      btn.textContent.toLowerCase().includes(tab) ||
      btn.getAttribute("onclick")?.includes(tab),
  );
  if (clickedBtn) clickedBtn.classList.add("active");

  // Show/hide content
  document.getElementById("pendingTab").style.display =
    tab === "pending" ? "block" : "none";
  document.getElementById("historyTab").style.display =
    tab === "history" ? "block" : "none";
  document.getElementById("outstandingTab").style.display =
    tab === "outstanding" ? "block" : "none";

  // Show/hide search/filter controls
  const showControls = tab !== "pending";
  document.getElementById("searchInput").style.display = showControls
    ? "block"
    : "none";
  document.getElementById("statusFilter").style.display = showControls
    ? "block"
    : "none";
  document.getElementById("searchBtn").style.display = showControls
    ? "block"
    : "none";

  // Show outstanding filter when on outstanding tab
  const outstandingFilter = document.getElementById("outstandingFilter");
  if (outstandingFilter) {
    outstandingFilter.style.display = tab === "outstanding" ? "block" : "none";
  }

  // Load data
  if (tab === "pending") {
    loadPendingVerifications();
  } else if (tab === "history") {
    loadPaymentHistory();
  } else if (tab === "outstanding") {
    loadOutstandingPayment();
  }
}

// Load statistics
async function loadStatistics() {
  try {
    const response = await fetch(
      "../backend/payment/rent_payment_admin.php?action=get_statistics",
    );
    const data = await response.json();

    if (data.success) {
      renderStatistics(data.statistics);
    }
  } catch (error) {
    console.error("Error loading statistics:", error);
  }
}

function renderStatistics(stats) {
  const container = document.getElementById("statsContainer");
  const summary = stats.summary || {};

  container.innerHTML = `
                <div class="stat-card">
                    <div class="stat-icon" style="background: #f59e0b;">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Pending Verifications</h3>
                        <p class="stat-number">${summary.pending_verifications || 0}</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: #10b981;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Total Collected</h3>
                        <p class="stat-number">₦${formatNumber(summary.total_collected || 0)}</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: #ef4444;">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Outstanding Balance</h3>
                        <p class="stat-number">₦${formatNumber(summary.total_outstanding || 0)}</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: #3b82f6;">
                        <i class="fas fa-building"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Active Leases</h3>
                        <p class="stat-number">${summary.active_leases || 0}</p>
                    </div>
                </div>
            `;
}

// Load pending verifications
async function loadPendingVerifications() {
  try {
    const response = await fetch(
      "../backend/payment/rent_payment_admin.php?action=fetch_pending",
    );
    const data = await response.json();

    if (data.success) {
      document.getElementById("pendingCount").innerHTML =
        `${data.pending_count} pending`;
      renderPendingTable(data.payments);
    }
  } catch (error) {
    console.error("Error loading pending:", error);
    document.getElementById("pendingTableBody").innerHTML = `
                    <tr><td colspan="8" class="empty-state">
                        <i class="fas fa-exclamation-circle"></i>
                        <p>Error loading pending verifications</p>
                    </td></tr>
                `;
  }
}

function renderPendingTable(payments) {
  const tbody = document.getElementById("pendingTableBody");

  if (!payments || payments.length === 0) {
    tbody.innerHTML = `
                    <tr><td colspan="8" class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        <p>No pending verifications</p>
                        <small>All payments have been processed</small>
                    </td></tr>
                `;
    return;
  }

  tbody.innerHTML = payments
    .map(
      (payment) => `
                <tr>
                    <td>${payment.created_at_formatted}</td>
                    <td>
                        <strong>${escapeHtml(payment.tenant_name)}</strong><br>
                        <small>${escapeHtml(payment.tenant_code)}</small>
                    </td>
                    <td>
                        ${escapeHtml(payment.property_name)}<br>
                        <small>${escapeHtml(payment.apartment_number)}</small>
                    </td>
                    <td>
                        Period #${payment.period_number}<br>
                        <small>${payment.period_display}</small>
                    </td>
                    <td><strong>${payment.amount_formatted}</strong></td>
                    <td>${formatPaymentMethod(payment.payment_method)}</td>
                    <td><code>${escapeHtml(payment.payment_reference || "N/A")}</code></td>
                    <td>
                        <button class="btn btn-primary" onclick="openVerifyModal(${payment.tracker_id}, ${payment.period_number})">
                            <i class="fas fa-check-circle"></i> Verify
                        </button>
                    </td>
                </tr>
            `,
    )
    .join("");
}

// Load payment history
async function loadPaymentHistory() {
  try {
    const search = document.getElementById("searchInput")?.value || "";
    const status = document.getElementById("statusFilter")?.value || "";

    let url = `../backend/payment/rent_payment_admin.php?action=fetch_history&page=${currentPage}&limit=20`;
    if (search) url += `&search=${encodeURIComponent(search)}`;
    if (status) url += `&status=${status}`;

    const response = await fetch(url);
    const data = await response.json();

    if (data.success) {
      renderHistoryTable(data.payments);
      renderHistoryPagination(data.pagination);
    }
  } catch (error) {
    console.error("Error loading history:", error);
  }
}

function renderHistoryTable(payments) {
  const tbody = document.getElementById("historyTableBody");

  if (!payments || payments.length === 0) {
    tbody.innerHTML = `
                    <tr><td colspan="9" class="empty-state">
                        <i class="fas fa-receipt"></i>
                        <p>No payment history found</p>
                    </td></tr>
                `;
    return;
  }

  tbody.innerHTML = payments
    .map(
      (payment) => `
                <tr>
                    <td>${payment.payment_date_formatted}</td>
                    <td>
                        <strong>${escapeHtml(payment.tenant_name)}</strong><br>
                        <small>${escapeHtml(payment.tenant_code)}</small>
                    </td>
                    <td>${escapeHtml(payment.property_name)}</td>
                    <td>#${payment.period_number}</td>
                    <td><small>${payment.period_display}</small></td>
                    <td>${payment.amount_formatted}</td>
                    <td>${formatPaymentMethod(payment.payment_method)}</td>
                    <td><span class="badge badge-${payment.status_badge}">${payment.status_text}</span></td>
                    <td><small>${payment.verified_at_formatted}</small></td>
                </tr>
            `,
    )
    .join("");
}

// Load Outstanding Payment
// FIX #8: removed a duplicated "// Load Outstanding Payment" comment that
// used to appear twice in a row above this function.
async function loadOutstandingPayment() {
    try {
        const search = document.getElementById('searchInput')?.value || '';
        const status = document.getElementById('statusFilter')?.value || '';
        const filter = document.getElementById('outstandingFilter')?.value || 'all';

        let url = `../backend/payment/rent_payment_admin.php?action=fetch_outstanding&page=${currentPage}&limit=20&filter=${filter}`;
        if (search) url += `&search=${encodeURIComponent(search)}`;
        if (status) url += `&status=${status}`;

        const response = await fetch(url);
        const data = await response.json();

        if (data.success) {
            // Store the data for use in viewOutstandingPayment
            window.outstandingPaymentsData = data.payments || [];

            // Update summary if available
            if (data.summary) {
                updateOutstandingSummary(data.summary);
            }
            renderOutstandingTable(data.payments || []);
            renderOutstandingPagination(data.pagination);

            // Update count
            // FIX #3: this used to read data.payments.length, which is only
            // the number of rows on the CURRENT page (max 20), not the true
            // total outstanding count. Prefer the total from pagination when
            // the backend provides one, and fall back to the page length
            // only if it doesn't.
            const countEl = document.getElementById('outstandingCount');
            if (countEl) {
                const totalOutstanding =
                    data.pagination?.total_records ??
                    data.pagination?.total ??
                    data.pagination?.total_count ??
                    data.payments?.length ??
                    0;
                countEl.textContent = `${totalOutstanding} outstanding`;
            }
        } else {
            console.error('API returned error:', data.message);
            document.getElementById('outstandingTableBody').innerHTML = `
                <tr><td colspan="9" class="empty-state">
                    <i class="fas fa-exclamation-circle"></i>
                    <p>${data.message || 'Failed to load outstanding payments'}</p>
                </td></tr>
            `;
        }
    } catch (error) {
        console.error('Error loading outstanding payments:', error);
        document.getElementById('outstandingTableBody').innerHTML = `
            <tr><td colspan="9" class="empty-state">
                <i class="fas fa-exclamation-circle"></i>
                <p>Error loading outstanding payments</p>
                <small>${error.message}</small>
            </td></tr>
        `;
    }
}

function updateOutstandingSummary(summary) {
  if (!summary) return;

  // FIX #4: guard every lookup with a null-check. Previously this function
  // called .textContent directly on the result of getElementById, so if a
  // single element (e.g. #tenantsAffected) was missing from the DOM, the
  // whole function would throw and none of the summary cards would update.
  const atRiskAmountEl = document.getElementById("atRiskAmount");
  if (atRiskAmountEl) atRiskAmountEl.textContent = `₦${formatNumber(summary.at_risk_amount || 0)}`;

  const atRiskCountEl = document.getElementById("atRiskCount");
  if (atRiskCountEl) atRiskCountEl.textContent = `${summary.at_risk_count || 0} payments`;

  const overdueAmountEl = document.getElementById("overdueAmount");
  if (overdueAmountEl) overdueAmountEl.textContent = `₦${formatNumber(summary.overdue_amount || 0)}`;

  const overdueCountEl = document.getElementById("overdueCount");
  if (overdueCountEl) overdueCountEl.textContent = `${summary.overdue_count || 0} payments`;

  const criticalAmountEl = document.getElementById("criticalAmount");
  if (criticalAmountEl) criticalAmountEl.textContent = `₦${formatNumber(summary.critical_amount || 0)}`;

  const criticalCountEl = document.getElementById("criticalCount");
  if (criticalCountEl) criticalCountEl.textContent = `${summary.critical_count || 0} payments`;

  const tenantsAffectedEl = document.getElementById("tenantsAffected");
  if (tenantsAffectedEl) tenantsAffectedEl.textContent = summary.tenants_affected || 0;
}

function renderOutstandingTable(payments) {
  const tbody = document.getElementById("outstandingTableBody");

  if (!payments || payments.length === 0) {
    tbody.innerHTML = `
            <tr><td colspan="9" class="empty-state">
                <i class="fas fa-check-circle"></i>
                <p>No outstanding payments found</p>
                <small>All payments are up to date</small>
            </td></tr>
        `;
    return;
  }

  tbody.innerHTML = payments
    .map((payment) => {
      const isOverdue = payment.is_overdue || false;
      const rowClass = isOverdue ? "overdue-row" : "";
      const defaultStatus = payment.default_status || "on_track";
      const statusColor = payment.default_color || "#10b981";
      const statusMessage =
        payment.default_message || payment.status_text || payment.status;
      // FIX #1: tenant_code is interpolated into an inline onclick string
      // below. If it ever contains a quote or backslash it would break the
      // generated HTML/JS (and is a minor injection risk). escapeJsString()
      // makes it safe to embed inside the single-quoted onclick argument.
      const safeTenantCode = escapeJsString(payment.tenant_code);

      // NOTE (issue #9, not auto-fixed): the "days until due" branch below
      // reuses `payment.days_overdue` for payments that are NOT overdue.
      // That only makes sense if the backend deliberately returns a
      // "days until due" style value in `days_overdue` for future-due
      // payments. Please confirm with the API - if `days_overdue` is only
      // ever populated for actually-overdue payments, this branch will
      // never render and a separate `days_until_due` field is needed.
      return `
            <tr class="${rowClass}">
                <td>
                    <strong>${escapeHtml(payment.tenant_name || payment.tenant_code)}</strong><br>
                    <small>${escapeHtml(payment.tenant_code)}</small>
                    ${payment.tenant_email ? `<br><small>${escapeHtml(payment.tenant_email)}</small>` : ""}
                </td>
                <td>
                    ${escapeHtml(payment.property_name || "N/A")}<br>
                    <small>Apt: ${escapeHtml(payment.apartment_number || "N/A")}</small>
                </td>
                <td>
                    ${escapeHtml(payment.period_display || payment.payment_period || "N/A")}
                    ${payment.remaining_periods > 0 ? `<br><small>${payment.remaining_periods} periods remaining</small>` : ""}
                </td>
                <td>
                    <strong>${formatCurrency(payment.amount)}</strong>
                    <br><small>${escapeHtml(payment.payment_period || "N/A")}</small>
                </td>
                <td>${formatCurrency(payment.amount_paid)}</td>
                <td>
                    <strong style="color: ${payment.balance > 0 ? "#ef4444" : "#10b981"}">
                        ${formatCurrency(payment.balance)}
                    </strong>
                    ${isOverdue ? `<br><span class="overdue-badge">${payment.days_overdue} days overdue</span>` : ""}
                </td>
                <td>
                    ${payment.due_date_formatted || "N/A"}
                    ${isOverdue ? `<br><span class="overdue-label">Past due</span>` : ""}
                    ${!isOverdue && payment.days_overdue > 0 ? `<br><span class="days-until">${payment.days_overdue} days until due</span>` : ""}
                </td>
                <td>
                    <span class="badge" style="background: ${statusColor}; color: white;">
                        ${statusMessage}
                    </span>
                    ${payment.pending_verifications > 0 ? `<br><span class="badge badge-warning">${payment.pending_verifications} pending</span>` : ""}
                    ${payment.settlement_issue ? `<br><span class="badge badge-danger">${payment.settlement_message}</span>` : ""}
                </td>
                <td>
                    <button class="btn btn-sm btn-outline" onclick="viewOutstandingPayment(${payment.payment_id})" title="View Details">
                        <i class="fas fa-eye"></i>
                    </button>
                    ${
                      isOverdue
                        ? `
                        <button class="btn btn-sm btn-warning" onclick="sendReminder('${safeTenantCode}', ${payment.payment_id})" title="Send Reminder">
                            <i class="fas fa-bell"></i>
                        </button>
                    `
                        : ""
                    }
                    ${
                      payment.balance > 0
                        ? `
                        <button class="btn btn-sm btn-success" onclick="recordOutstandingPayment(${payment.payment_id})" title="Record Payment">
                            <i class="fas fa-check-circle"></i>
                        </button>
                    `
                        : ""
                    }
                </td>
            </tr>
        `;
    })
    .join("");
}

function renderHistoryPagination(pagination) {
  const container = document.getElementById("historyPagination");
  if (!pagination || pagination.total_pages <= 1) {
    container.innerHTML = "";
    return;
  }

  let html = "";
  for (let i = 1; i <= pagination.total_pages; i++) {
    html += `<button class="page-btn ${i === currentPage ? "active" : ""}" onclick="goToPage(${i})">${i}</button>`;
  }
  container.innerHTML = html;
}

function goToPage(page) {
  currentPage = page;
  loadPaymentHistory();
}

function applyFilters() {
  currentPage = 1;
  loadPaymentHistory();
}

function renderOutstandingPagination(pagination) {
  const container = document.getElementById("outstandingPagination");
  if (!pagination || pagination.total_pages <= 1) {
    container.innerHTML = "";
    return;
  }

  let html = "";

  // Previous button
  if (currentPage > 1) {
    html += `<button class="page-btn" onclick="goToOutstandingPage(${currentPage - 1})">‹</button>`;
  }

  // Page numbers
  const totalPages = pagination.total_pages || 1;
  for (let i = 1; i <= totalPages; i++) {
    if (i === currentPage) {
      html += `<button class="page-btn active">${i}</button>`;
    } else if (i <= 3 || i > totalPages - 3 || Math.abs(i - currentPage) <= 1) {
      html += `<button class="page-btn" onclick="goToOutstandingPage(${i})">${i}</button>`;
    } else if (i === 4 && currentPage > 5) {
      html += `<span class="page-dots">...</span>`;
    } else if (i === totalPages - 3 && currentPage < totalPages - 4) {
      html += `<span class="page-dots">...</span>`;
    }
  }

  // Next button
  if (currentPage < totalPages) {
    html += `<button class="page-btn" onclick="goToOutstandingPage(${currentPage + 1})">›</button>`;
  }

  container.innerHTML = html;
}

function goToOutstandingPage(page) {
  currentPage = page;
  loadOutstandingPayment();
}

function applyOutstandingFilters() {
  currentPage = 1;
  loadOutstandingPayment();
}

// ==================== OUTSTANDING ACTION FUNCTIONS ====================

// ==================== VIEW OUTSTANDING PAYMENT DETAILS ====================

/**
 * View outstanding payment details from existing data
 * Uses the data already loaded from fetch_outstanding API
 */
function viewOutstandingPayment(paymentId) {
    // FIX #2: use a loose/numeric comparison instead of strict ===.
    // payment_id coming back from JSON can be a string (e.g. "42") while
    // paymentId here is always a JS number literal (it's written directly
    // into the onclick as ${payment.payment_id}). Strict === between a
    // string and a number is always false, so the lookup used to silently
    // fail and show "Payment Not Found" even for valid rows.
    const payment = window.outstandingPaymentsData?.find(
        (p) => Number(p.payment_id) === Number(paymentId)
    );

    if (!payment) {
        showAlertModal({
            title: 'Payment Not Found',
            message: 'The requested payment could not be found in the current data.',
            variant: 'warning'
        });
        return;
    }

    // Build and show the payment details modal
    showOutstandingPaymentDetailsModal(payment);
}

/**
 * Show payment details in a modal
 */
function showOutstandingPaymentDetailsModal(payment) {
    // Create modal if it doesn't exist
    let modal = document.getElementById('outstandingPaymentDetailsModal');
    // FIX #1 (cont.): escape tenant_code before embedding it in the
    // reminder button's onclick attribute here too.
    const safeTenantCode = escapeJsString(payment.tenant_code);
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'outstandingPaymentDetailsModal';
        modal.className = 'modal';
        modal.innerHTML = `
            <div class="modal-content" style="max-width: 650px;">
                <div class="modal-header">
                    <h3><i class="fas fa-receipt"></i> Payment Details</h3>
                    <button class="modal-close" onclick="closeOutstandingPaymentDetailsModal()">&times;</button>
                </div>
                <div class="modal-body" id="outstandingPaymentDetailsBody">
                    <!-- Content will be rendered here -->
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline" onclick="closeOutstandingPaymentDetailsModal()">Close</button>
                    ${payment.is_overdue ? `
                        <button class="btn btn-warning" onclick="sendReminder('${safeTenantCode}', ${payment.payment_id})">
                            <i class="fas fa-bell"></i> Send Reminder
                        </button>
                    ` : ''}
                    ${payment.balance > 0 ? `
                        <button class="btn btn-success" onclick="recordOutstandingPayment(${payment.payment_id})">
                            <i class="fas fa-check-circle"></i> Record Payment
                        </button>
                    ` : ''}
                    ${payment.receipt_number ? `
                        <button class="btn btn-info" onclick="downloadReceipt('${payment.receipt_number}')">
                            <i class="fas fa-download"></i> Download Receipt
                        </button>
                    ` : ''}
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }

    // Update modal body with payment details
    const body = document.getElementById('outstandingPaymentDetailsBody');
    if (!body) return;

    const isOverdue = payment.is_overdue || false;
    const statusColor = payment.default_color || '#10b981';
    const statusMessage = payment.default_message || payment.status_text || payment.status;

    body.innerHTML = `
        <div class="payment-details-grid">
            <!-- Tenant Information -->
            <div class="detail-section">
                <h4><i class="fas fa-user"></i> Tenant Information</h4>
                <div class="detail-row">
                    <span class="detail-label">Name:</span>
                    <span class="detail-value">${escapeHtml(payment.tenant_name || payment.tenant_code)}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Tenant Code:</span>
                    <span class="detail-value">${escapeHtml(payment.tenant_code)}</span>
                </div>
                ${payment.tenant_email ? `
                    <div class="detail-row">
                        <span class="detail-label">Email:</span>
                        <span class="detail-value">${escapeHtml(payment.tenant_email)}</span>
                    </div>
                ` : ''}
                ${payment.tenant_phone ? `
                    <div class="detail-row">
                        <span class="detail-label">Phone:</span>
                        <span class="detail-value">${escapeHtml(payment.tenant_phone)}</span>
                    </div>
                ` : ''}
            </div>

            <!-- Property Information -->
            <div class="detail-section">
                <h4><i class="fas fa-building"></i> Property Information</h4>
                <div class="detail-row">
                    <span class="detail-label">Property:</span>
                    <span class="detail-value">${escapeHtml(payment.property_name || 'N/A')}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Apartment:</span>
                    <span class="detail-value">${escapeHtml(payment.apartment_number || 'N/A')}</span>
                </div>
                ${payment.property_code ? `
                    <div class="detail-row">
                        <span class="detail-label">Property Code:</span>
                        <span class="detail-value">${escapeHtml(payment.property_code)}</span>
                    </div>
                ` : ''}
            </div>

            <!-- Payment Information -->
            <div class="detail-section">
                <h4><i class="fas fa-money-bill-wave"></i> Payment Information</h4>
                <div class="detail-row">
                    <span class="detail-label">Payment ID:</span>
                    <span class="detail-value">#${payment.payment_id}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Rent Payment ID:</span>
                    <span class="detail-value">${escapeHtml(payment.rent_payment_id || 'N/A')}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Period:</span>
                    <span class="detail-value">${escapeHtml(payment.period_display || payment.payment_period || 'N/A')}</span>
                </div>
                ${payment.remaining_periods > 0 ? `
                    <div class="detail-row">
                        <span class="detail-label">Remaining Periods:</span>
                        <span class="detail-value">${payment.remaining_periods}</span>
                    </div>
                ` : ''}
                ${payment.pending_verifications > 0 ? `
                    <div class="detail-row">
                        <span class="detail-label">Pending Verifications:</span>
                        <span class="detail-value">${payment.pending_verifications}</span>
                    </div>
                ` : ''}
            </div>

            <!-- Financial Details -->
            <div class="detail-section">
                <h4><i class="fas fa-calculator"></i> Financial Details</h4>
                <div class="detail-row">
                    <span class="detail-label">Total Rent Amount:</span>
                    <span class="detail-value amount-value">${formatCurrency(payment.amount)}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Amount Paid:</span>
                    <span class="detail-value">${formatCurrency(payment.amount_paid)}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Outstanding Balance:</span>
                    <span class="detail-value" style="color: ${payment.balance > 0 ? '#ef4444' : '#10b981'}; font-weight: 700;">
                        ${formatCurrency(payment.balance)}
                    </span>
                </div>
                ${isOverdue ? `
                    <div class="detail-row">
                        <span class="detail-label">Overdue Amount:</span>
                        <span class="detail-value" style="color: #ef4444; font-weight: 700;">
                            ${formatCurrency(payment.overdue_amount || payment.balance)}
                        </span>
                    </div>
                ` : ''}
            </div>

            <!-- Dates -->
            <div class="detail-section">
                <h4><i class="fas fa-calendar-alt"></i> Dates</h4>
                <div class="detail-row">
                    <span class="detail-label">Payment Date:</span>
                    <span class="detail-value">${payment.payment_date_formatted || 'N/A'}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Due Date:</span>
                    <span class="detail-value">
                        ${payment.due_date_formatted || 'N/A'}
                        ${isOverdue ? `<span class="overdue-badge" style="margin-left: 8px;">${payment.days_overdue} days overdue</span>` : ''}
                    </span>
                </div>
                ${payment.period_start_date && payment.period_end_date ? `
                    <div class="detail-row">
                        <span class="detail-label">Period Start:</span>
                        <span class="detail-value">${formatDate(payment.period_start_date)}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Period End:</span>
                        <span class="detail-value">${formatDate(payment.period_end_date)}</span>
                    </div>
                ` : ''}
            </div>

            <!-- Payment Method & Reference -->
            <div class="detail-section">
                <h4><i class="fas fa-credit-card"></i> Payment Method</h4>
                <div class="detail-row">
                    <span class="detail-label">Method:</span>
                    <span class="detail-value">${formatPaymentMethod(payment.payment_method)}</span>
                </div>
                ${payment.reference_number ? `
                    <div class="detail-row">
                        <span class="detail-label">Reference:</span>
                        <span class="detail-value">${escapeHtml(payment.reference_number)}</span>
                    </div>
                ` : ''}
                ${payment.receipt_number ? `
                    <div class="detail-row">
                        <span class="detail-label">Receipt:</span>
                        <span class="detail-value">${escapeHtml(payment.receipt_number)}</span>
                    </div>
                ` : ''}
            </div>

            <!-- Status -->
            <div class="detail-section">
                <h4><i class="fas fa-info-circle"></i> Status</h4>
                <div class="detail-row">
                    <span class="detail-label">Payment Status:</span>
                    <span class="detail-value">
                        <span class="badge" style="background: ${statusColor}; color: white;">
                            ${escapeHtml(statusMessage)}
                        </span>
                    </span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Default Status:</span>
                    <span class="detail-value">
                        <span class="badge" style="background: ${payment.default_color || '#6b7280'}; color: white;">
                            ${escapeHtml(payment.default_status || 'on_track')}
                        </span>
                    </span>
                </div>
                ${payment.settlement_issue ? `
                    <div class="detail-row">
                        <span class="detail-label">Settlement:</span>
                        <span class="detail-value">
                            <span class="badge badge-danger">${escapeHtml(payment.settlement_message)}</span>
                        </span>
                    </div>
                ` : ''}
                ${payment.settlement_status ? `
                    <div class="detail-row">
                        <span class="detail-label">Settlement Status:</span>
                        <span class="detail-value">${escapeHtml(payment.settlement_status)}</span>
                    </div>
                ` : ''}
            </div>

            <!-- Notes -->
            ${payment.notes || payment.admin_notes ? `
                <div class="detail-section" style="grid-column: 1 / -1;">
                    <h4><i class="fas fa-sticky-note"></i> Notes</h4>
                    <div class="notes-content" style="background: var(--gray-100); padding: var(--spacing-md); border-radius: var(--radius-md);">
                        ${escapeHtml(payment.notes || payment.admin_notes)}
                    </div>
                </div>
            ` : ''}

            ${isOverdue ? `
                <div class="detail-section overdue-warning" style="grid-column: 1 / -1; background: var(--danger-light); padding: var(--spacing-md); border-radius: var(--radius-md); border-left: 4px solid var(--danger);">
                    <div style="display: flex; align-items: center; gap: var(--spacing-sm); color: #991b1b;">
                        <i class="fas fa-exclamation-triangle" style="font-size: 20px;"></i>
                        <div>
                            <strong>⚠️ Payment Overdue!</strong>
                            <span style="margin-left: var(--spacing-sm); font-weight: normal;">
                                This payment is ${payment.days_overdue} days past the due date.
                                ${payment.default_message ? ` (${payment.default_message})` : ''}
                            </span>
                        </div>
                    </div>
                </div>
            ` : ''}
        </div>
    `;

    // Open modal
    openModal('outstandingPaymentDetailsModal');
}

/**
 * Close the outstanding payment details modal
 */
function closeOutstandingPaymentDetailsModal() {
    closeModal('outstandingPaymentDetailsModal');
}

/**
 * Store outstanding payments data globally
 * Call this after loading the API response
 */
function storeOutstandingPaymentsData(payments) {
    window.outstandingPaymentsData = payments;
}

function sendReminder(tenantCode, paymentId) {
  showConfirmModal({
    title: "Send Payment Reminder",
    message: `Send payment reminder to tenant ${tenantCode}?`,
    confirmText: "Send",
    cancelText: "Cancel",
    variant: "warning",
  }).then(async (confirmed) => {
    if (!confirmed) return;

    try {
      const response = await fetch("../backend/payment/send_reminder.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          tenant_code: tenantCode,
          payment_id: paymentId,
        }),
      });

      const data = await response.json();

      if (data.success) {
        showAlertModal({
          title: "Reminder Sent",
          message: "Payment reminder sent successfully!",
          variant: "success",
        });
      } else {
        showAlertModal({
          title: "Failed",
          message: data.message || "Failed to send reminder",
          variant: "danger",
        });
      }
    } catch (error) {
      console.error("Error sending reminder:", error);
      showAlertModal({
        title: "Error",
        message: "An error occurred. Please try again.",
        variant: "danger",
      });
    }
  });
}

function recordOutstandingPayment(paymentId) {
  // This would open a payment recording modal
  showAlertModal({
    title: "Record Payment",
    message: `Opening payment recording for payment ID: ${paymentId}. This feature is coming soon.`,
    variant: "info",
  });
}

// Modal functions
function openVerifyModal(trackerId, periodNumber) {
  currentTrackerId = trackerId;
  currentPeriodNumber = periodNumber;

  const modal = document.getElementById("verifyModal");
  const detailsDiv = document.getElementById("verifyDetails");

  detailsDiv.innerHTML = `
                <div class="detail-row">
                    <span class="detail-label">Period #:</span>
                    <span class="detail-value">${periodNumber}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Action:</span>
                    <span class="detail-value">Please verify this payment</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Status will be:</span>
                    <span class="detail-value"><strong>Approve</strong> = Paid | <strong>Reject</strong> = Failed</span>
                </div>
            `;

  document.getElementById("verifyNotes").value = "";
  modal.classList.add("active");
}

function closeVerifyModal() {
  document.getElementById("verifyModal").classList.remove("active");
  currentTrackerId = null;
}

function showConfirmModal({
  title = "Confirm Action",
  message = "Are you sure you want to continue?",
  confirmText = "Confirm",
  cancelText = "Cancel",
  variant = "warning",
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
      danger: "fa-triangle-exclamation",
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

function showAlertModal({
  title = "Alert",
  message = "",
  buttonText = "OK",
  variant = "info",
}) {
  return new Promise((resolve) => {
    let modal = document.getElementById("customAlertModal");

    if (!modal) {
      modal = document.createElement("div");
      modal.id = "customAlertModal";
      modal.className = "custom-alert-overlay";
      modal.innerHTML = `
                        <div class="custom-alert-dialog" role="alertdialog" aria-modal="true" aria-labelledby="customAlertTitle">
                            <div class="custom-alert-icon">
                                <i class="fas fa-circle-info"></i>
                            </div>
                            <div class="custom-alert-content">
                                <h3 id="customAlertTitle"></h3>
                                <p id="customAlertMessage"></p>
                            </div>
                            <div class="custom-alert-actions">
                                <button type="button" class="btn custom-alert-ok">OK</button>
                            </div>
                        </div>
                    `;
      document.body.appendChild(modal);
    }

    const dialog = modal.querySelector(".custom-alert-dialog");
    const icon = modal.querySelector(".custom-alert-icon i");
    const titleElement = modal.querySelector("#customAlertTitle");
    const messageElement = modal.querySelector("#customAlertMessage");
    const okButton = modal.querySelector(".custom-alert-ok");

    const iconMap = {
      success: "fa-circle-check",
      info: "fa-circle-info",
      warning: "fa-circle-exclamation",
      danger: "fa-triangle-exclamation",
      error: "fa-triangle-exclamation",
    };

    const displayVariant = variant === "error" ? "danger" : variant;

    dialog.dataset.variant = displayVariant;
    icon.className = `fas ${iconMap[variant] || iconMap.info}`;
    titleElement.textContent = title;
    messageElement.textContent = message;
    okButton.textContent = buttonText;

    const handleKeydown = (event) => {
      if (event.key === "Escape" || event.key === "Enter") {
        cleanup();
      }
    };

    const cleanup = () => {
      modal.classList.remove("active");
      okButton.onclick = null;
      modal.onclick = null;
      document.removeEventListener("keydown", handleKeydown);
      resolve();
    };

    okButton.onclick = cleanup;
    modal.onclick = (event) => {
      if (event.target === modal) {
        cleanup();
      }
    };

    modal.classList.add("active");
    document.addEventListener("keydown", handleKeydown);
    okButton.focus();
  });
}

// FIX #5: processVerification used to rely on the implicit global `event`
// object (const btn = event.target). That's deprecated (window.event),
// doesn't exist in all call contexts, and would throw if the function was
// ever invoked without a live click event in scope. It now accepts an
// optional explicit event parameter, falls back to window.event for
// backward compatibility with existing onclick="processVerification('approve')"
// markup, and no longer crashes if no event/button can be resolved.
async function processVerification(action, evt) {
  if (!currentTrackerId) {
    await showAlertModal({
      title: "No Payment Selected",
      message: "Please select a payment before continuing.",
      variant: "warning",
    });
    return;
  }

  const notes = document.getElementById("verifyNotes").value;
  const actionText = action === "approve" ? "approve" : "reject";

  const confirmed = await showConfirmModal({
    title: `${action === "approve" ? "Approve" : "Reject"} Payment?`,
    message: `Are you sure you want to ${actionText} this payment? This action cannot be undone.`,
    confirmText: action === "approve" ? "Yes, Approve" : "Yes, Reject",
    cancelText: "Cancel",
    variant: action === "approve" ? "success" : "danger",
  });

  if (!confirmed) {
    return;
  }

  const resolvedEvent = evt || window.event || null;
  const btn = resolvedEvent?.target || null;
  const originalText = btn ? btn.innerHTML : null;
  if (btn) {
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    btn.disabled = true;
  }

  try {
    const response = await fetch(
      "../backend/payment/rent_payment_admin.php?action=verify",
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          tracker_id: currentTrackerId,
          action: action,
          notes: notes,
        }),
      },
    );

    const data = await response.json();

    if (data.success) {
      await showAlertModal({
        title: "Verification Complete",
        message: data.message,
        variant: "success",
      });
      closeVerifyModal();
      loadStatistics();
      loadPendingVerifications();
      if (currentTab === "history") loadPaymentHistory();
    } else {
      await showAlertModal({
        title: "Verification Failed",
        message: data.message || "Error processing verification",
        variant: "danger",
      });
    }
  } catch (error) {
    console.error("Error:", error);
    await showAlertModal({
      title: "Request Error",
      message: "An error occurred. Please try again.",
      variant: "danger",
    });
  } finally {
    if (btn) {
      btn.innerHTML = originalText;
      btn.disabled = false;
    }
  }
}

// Utility functions
function formatNumber(value) {
  return new Intl.NumberFormat("en-NG", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(value);
}
// Add this alongside your existing formatNumber function
function formatCurrency(amount) {
    if (!amount && amount !== 0) return '₦0.00';
    const numAmount = typeof amount === 'string' ? parseFloat(amount) : amount;
    if (isNaN(numAmount)) return '₦0.00';
    return '₦' + numAmount.toLocaleString('en-NG', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function formatPaymentMethod(method) {
  const methods = {
    bank_transfer: "Bank Transfer",
    card: "Card",
    cash: "Cash",
    cheque: "Cheque",
  };
  return methods[method] || method || "N/A";
}
/**
 * Format a date string into a readable format
 * @param {string|Date} dateString - The date to format
 * @param {boolean} includeTime - Whether to include time (default: false)
 * @returns {string} Formatted date string
 */
function formatDate(dateString, includeTime = false) {
    if (!dateString) return 'N/A';

    try {
        const date = typeof dateString === 'string' ? new Date(dateString) : dateString;

        if (isNaN(date.getTime())) return 'N/A';

        const options = {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        };

        if (includeTime) {
            options.hour = '2-digit';
            options.minute = '2-digit';
        }

        // FIX #7: was 'en-US', inconsistent with the 'en-NG' locale used by
        // formatNumber/formatCurrency elsewhere in this file. Aligned to
        // 'en-NG' so date and currency formatting are consistent.
        return date.toLocaleDateString('en-NG', options);

    } catch (error) {
        return 'N/A';
    }
}

// Alias for date with time
function formatDateTime(dateString) {
    return formatDate(dateString, true);
}

function escapeHtml(text) {
  if (!text) return "";
  const div = document.createElement("div");
  div.textContent = text;
  return div.innerHTML;
}

// FIX #1 (helper): safely escape a string for embedding inside a
// single-quoted argument of an inline onclick="..." handler, e.g.
// onclick="sendReminder('${escapeJsString(tenantCode)}', 123)".
// Escapes backslashes and single quotes so values containing them can't
// break out of the string literal or otherwise corrupt the generated HTML.
function escapeJsString(text) {
  if (text === null || text === undefined) return "";
  return String(text).replace(/\\/g, "\\\\").replace(/'/g, "\\'");
}

// Add some CSS for the payment details modal
const paymentDetailsStyles = `
    .payment-details-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: var(--spacing-md);
    }

    .payment-details-grid .detail-section {
        background: var(--gray-100);
        border-radius: var(--radius-md);
        padding: var(--spacing-md);
    }

    .payment-details-grid .detail-section h4 {
        font-size: var(--font-sm);
        font-weight: 600;
        color: var(--gray-700);
        margin-bottom: var(--spacing-sm);
        display: flex;
        align-items: center;
        gap: var(--spacing-xs);
    }

    .payment-details-grid .detail-section h4 i {
        color: var(--primary);
        font-size: var(--font-base);
    }

    .payment-details-grid .detail-row {
        display: flex;
        justify-content: space-between;
        padding: var(--spacing-xs) 0;
        border-bottom: 1px solid var(--gray-200);
        font-size: var(--font-sm);
    }

    .payment-details-grid .detail-row:last-child {
        border-bottom: none;
    }

    .payment-details-grid .detail-label {
        color: var(--gray-600);
        font-weight: 500;
    }

    .payment-details-grid .detail-value {
        color: var(--gray-900);
        text-align: right;
        font-weight: 500;
    }

    .payment-details-grid .detail-value.amount-value {
        font-weight: 700;
        font-size: var(--font-md);
    }

    .payment-details-grid .overdue-badge {
        background: var(--danger);
        color: white;
        font-size: var(--font-xs);
        padding: 2px var(--spacing-sm);
        border-radius: 12px;
        display: inline-block;
    }

    @media (max-width: 768px) {
        .payment-details-grid {
            grid-template-columns: 1fr;
        }

        .payment-details-grid .detail-section {
            padding: var(--spacing-sm);
        }

        .payment-details-grid .detail-row {
            font-size: var(--font-xs);
        }
    }
`;

// Add styles if not already present
if (!document.getElementById('paymentDetailsStyles')) {
    const styleEl = document.createElement('style');
    styleEl.id = 'paymentDetailsStyles';
    styleEl.textContent = paymentDetailsStyles;
    document.head.appendChild(styleEl);
}