// rent_payment_history.js - Tenant Rent Payment History

let paymentHistory = [];
let currentPage = 1;
let totalPages = 1;
let currentLimit = 10;
let currentFilters = {
  status: "",
  period_number: "",
  search: "",
  date_from: "",
  date_to: "",
};
let currentSort = {
  column: "initiated_at",
  order: "DESC",
};

document.addEventListener("DOMContentLoaded", function () {
  initializePaymentHistory();
});

async function initializePaymentHistory() {
  try {
    await fetchPaymentHistory();
  } catch (error) {
    console.error("Error initializing payment history:", error);
    if (window.showToast) {
      window.showToast("Failed to load payment history", "error");
    }
    showEmptyState();
  }
}

async function fetchPaymentHistory() {
  try {
    const url = new URL(
      "../backend/payment/fetch_tenant_rent_payment_history.php",
      window.location.href,
    );
    url.searchParams.append("page", currentPage);
    url.searchParams.append("limit", currentLimit);

    if (currentFilters.status)
      url.searchParams.append("status", currentFilters.status);
    if (currentFilters.period_number)
      url.searchParams.append("period_number", currentFilters.period_number);
    if (currentFilters.search)
      url.searchParams.append("search", currentFilters.search);
    if (currentFilters.date_from)
      url.searchParams.append("date_from", currentFilters.date_from);
    if (currentFilters.date_to)
      url.searchParams.append("date_to", currentFilters.date_to);
    url.searchParams.append("sort_by", currentSort.column);
    url.searchParams.append("sort_order", currentSort.order);

    const response = await fetch(url);
    const data = await response.json();

    if (data.success && data.data) {
      paymentHistory = data.data.history || [];
      const pagination = data.data.pagination || {};
      totalPages = pagination.total_pages || 1;

      // FIRST: Render the page (creates the summaryCards element)
      renderPaymentHistoryPage();

      // SECOND: Then render the summary (element now exists)
      if (data.data.summary) {
        renderSummary(data.data.summary);
      }
    } else {
      throw new Error(data.message || "Failed to fetch payment history");
    }
  } catch (error) {
    console.error("Error fetching payment history:", error);
    if (window.showToast) {
      window.showToast("Failed to load payment history", "error");
    }
    showEmptyState();
  }
}

function renderPaymentHistoryPage() {
  const contentArea = document.getElementById("contentArea");
  if (!contentArea) return;

  const html = `
        <div class="rent-payment-history-container">
            <div class="page-header">
                <h1><i class="fas fa-history"></i> My Rent Payment History</h1>
                <p>View all your rent payment attempts and their status</p>
            </div>
            
            <!-- Summary Cards -->
            <div class="summary-cards" id="summaryCards"></div>
            
            <!-- Filters -->
            <div class="filters-card">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label>Status</label>
                        <select id="statusFilter" class="form-control">
                            <option value="">All</option>
                            <option value="initiated">Initiated</option>
                            <option value="pending_verification">Pending Verification</option>
                            <option value="paid">Approved</option>
                            <option value="rejected">Rejected</option>
                            <option value="failed">Failed</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Period #</label>
                        <input type="number" id="periodFilter" class="form-control" placeholder="Period number">
                    </div>
                    <div class="filter-group">
                        <label>Search</label>
                        <input type="text" id="searchInput" class="form-control" placeholder="Receipt # or Reference #">
                    </div>
                    <div class="filter-group">
                        <label>Date From</label>
                        <input type="date" id="dateFromFilter" class="form-control">
                    </div>
                    <div class="filter-group">
                        <label>Date To</label>
                        <input type="date" id="dateToFilter" class="form-control">
                    </div>
                    <div class="filter-actions">
                        <button class="btn-primary" onclick="applyFilters()">Apply</button>
                        <button class="btn-secondary" onclick="resetFilters()">Reset</button>
                    </div>
                </div>
            </div>
            
            <!-- Payment History Table -->
            <div class="payments-table">
                <h3>Payment History</h3>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th onclick="sortTable('initiated_at')">Date <i class="fas fa-sort"></i></th>
                                <th>Receipt #</th>
                                <th onclick="sortTable('period_number')">Period # <i class="fas fa-sort"></i></th>
                                <th onclick="sortTable('amount')">Amount <i class="fas fa-sort"></i></th>
                                <th onclick="sortTable('attempt_number')">Attempt <i class="fas fa-sort"></i></th>
                                <th>Initiated By</th>
                                <th onclick="sortTable('status')">Status <i class="fas fa-sort"></i></th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="historyTableBody">
                            ${renderTableRows()}
                        </tbody>
                    </table>
                </div>
                ${renderPagination()}
            </div>
        </div>
    `;

  contentArea.innerHTML = html;

  // Re-attach filter values after render
  document.getElementById("statusFilter").value = currentFilters.status;
  document.getElementById("periodFilter").value = currentFilters.period_number;
  document.getElementById("searchInput").value = currentFilters.search;
  document.getElementById("dateFromFilter").value = currentFilters.date_from;
  document.getElementById("dateToFilter").value = currentFilters.date_to;
}

function renderSummary(summary) {
  const container = document.getElementById("summaryCards");
  if (!container) return;

  container.innerHTML = `
        <div class="summary-card">
            <h4>Total Attempts</h4>
            <div class="amount">${summary.total_attempts || 0}</div>
            <div class="label">payment attempts</div>
        </div>
        <div class="summary-card">
            <h4>Successful Payments</h4>
            <div class="amount">${summary.successful_payments || 0}</div>
            <div class="label">${summary.total_paid || "₦0"} paid</div>
        </div>
        <div class="summary-card">
            <h4>Pending Verification</h4>
            <div class="amount">${summary.pending_payments || 0}</div>
            <div class="label">awaiting approval</div>
        </div>
        <div class="summary-card">
            <h4>Failed/Rejected</h4>
            <div class="amount">${summary.failed_payments || 0}</div>
            <div class="label">need attention</div>
        </div>
    `;
}

function renderTableRows() {
  if (paymentHistory.length === 0) {
    return `
            <tr>
                <td colspan="8" style="text-align: center; padding: 40px;">
                    <i class="fas fa-receipt" style="font-size: 48px; color: #ccc; margin-bottom: 10px; display: block;"></i>
                    No payment history found
                </td>
            </tr>
        `;
  }

  return paymentHistory
    .map((record) => {
      let statusClass = "";
      let statusText = record.status_text || record.status;

      if (record.status === "paid") {
        statusClass = "status-completed";
      } else if (record.status === "pending_verification") {
        statusClass = "status-pending";
      } else if (record.status === "failed" || record.status === "rejected") {
        statusClass = "status-failed";
      } else if (record.status === "initiated") {
        statusClass = "status-pending";
      }

      const attemptClass = record.attempt_number > 1 ? "retry" : "";

      return `
            <tr>
                <td>${record.initiated_at_formatted || formatDate(record.initiated_at)}</td>
                <td><strong>${record.receipt_number || "—"}</strong></td>
                <td style="font-weight: 600; font-family: monospace;">#${record.period_number}</td>
                <td style="font-weight: 600;">${record.amount_formatted}</td>
                <td><span class="attempt-badge ${attemptClass}">Attempt ${record.attempt_number}</span></td>
                <td><i class="fas ${record.initiated_by_type === "tenant" ? "fa-user" : "fa-user-shield"}"></i> ${record.initiated_by_display}</td>
                <td class="${statusClass}">${statusText}</td>
                <td>
                    <button class="btn-view" onclick="viewPaymentDetails(${record.id})">
                        <i class="fas fa-eye"></i> View
                    </button>
                </td>
            </tr>
        `;
    })
    .join("");
}

function renderPagination() {
  if (totalPages <= 1) return "";

  let html = '<div class="pagination">';
  for (let i = 1; i <= totalPages; i++) {
    html += `<button class="page-btn ${i === currentPage ? "active" : ""}" onclick="goToPage(${i})">${i}</button>`;
  }
  html += "</div>";
  return html;
}

function goToPage(page) {
  currentPage = page;
  fetchPaymentHistory();
}

function applyFilters() {
  currentFilters = {
    status: document.getElementById("statusFilter").value,
    period_number: document.getElementById("periodFilter").value,
    search: document.getElementById("searchInput").value,
    date_from: document.getElementById("dateFromFilter").value,
    date_to: document.getElementById("dateToFilter").value,
  };
  currentPage = 1;
  fetchPaymentHistory();
}

function resetFilters() {
  currentFilters = {
    status: "",
    period_number: "",
    search: "",
    date_from: "",
    date_to: "",
  };
  currentPage = 1;
  fetchPaymentHistory();
}

function sortTable(column) {
  if (currentSort.column === column) {
    currentSort.order = currentSort.order === "DESC" ? "ASC" : "DESC";
  } else {
    currentSort.column = column;
    currentSort.order = "DESC";
  }
  fetchPaymentHistory();
}

async function viewPaymentDetails(id) {
  try {
    const response = await fetch(
      `../backend/payment/fetch_tenant_rent_payment_details.php?id=${id}`,
    );
    const data = await response.json();

    if (data.success) {
      showDetailsModal(data.data);
    } else {
      throw new Error(data.message);
    }
  } catch (error) {
    console.error("Error fetching details:", error);
    if (window.showToast) {
      window.showToast("Failed to load payment details", "error");
    }
  }
}

function showDetailsModal(record) {
  const modalHtml = `
        <div class="modal active" id="paymentDetailsModal">
            <div class="modal-content" style="max-width: 600px;">
                <div class="modal-header">
                    <h3>Payment Details</h3>
                    <button class="modal-close" onclick="closeModal('paymentDetailsModal')">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="payment-summary">
                        <h4>Payment Information</h4>
                        <div class="summary-row">
                            <span class="summary-label">Receipt Number:</span>
                            <span class="summary-value">${record.receipt_number || "N/A"}</span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Reference Number:</span>
                            <span class="summary-value">${record.reference_number || "N/A"}</span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Amount:</span>
                            <span class="summary-value"><strong>${record.amount_formatted}</strong></span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Period Number:</span>
                            <span class="summary-value">#${record.period_number}</span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Attempt Number:</span>
                            <span class="summary-value">${record.attempt_number}</span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Payment Method:</span>
                            <span class="summary-value">${record.payment_method || "N/A"}</span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Status:</span>
                            <span class="summary-value"><span class="${record.status === "paid" ? "status-completed" : record.status === "pending_verification" ? "status-pending" : "status-failed"}">${record.status_text}</span></span>
                        </div>
                    </div>
                    
                    <div class="payment-summary">
                        <h4>Property Information</h4>
                        <div class="summary-row">
                            <span class="summary-label">Property:</span>
                            <span class="summary-value">${record.property_name || "N/A"}</span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Apartment:</span>
                            <span class="summary-value">${record.apartment_number || "N/A"}</span>
                        </div>
                    </div>
                    
                    <div class="payment-summary">
                        <h4>Timeline</h4>
                        <div class="summary-row">
                            <span class="summary-label">Initiated By:</span>
                            <span class="summary-value">${record.initiated_by_display}</span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Initiated At:</span>
                            <span class="summary-value">${record.initiated_at_formatted}</span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Verified At:</span>
                            <span class="summary-value">${record.verified_at_formatted}</span>
                        </div>
                        ${
                          record.verification_notes
                            ? `
                        <div class="summary-row">
                            <span class="summary-label">Notes:</span>
                            <span class="summary-value">${escapeHtml(record.verification_notes)}</span>
                        </div>
                        `
                            : ""
                        }
                        ${
                          record.failure_reason
                            ? `
                        <div class="summary-row">
                            <span class="summary-label">Failure Reason:</span>
                            <span class="summary-value" style="color: #dc3545;">${escapeHtml(record.failure_reason)}</span>
                        </div>
                        `
                            : ""
                        }
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="closeModal('paymentDetailsModal')">Close</button>
                </div>
            </div>
        </div>
    `;

  const existingModal = document.getElementById("paymentDetailsModal");
  if (existingModal) existingModal.remove();
  document.body.insertAdjacentHTML("beforeend", modalHtml);
}

function closeModal(modalId) {
  const modal = document.getElementById(modalId);
  if (modal) modal.remove();
}

function showEmptyState() {
  const contentArea = document.getElementById("contentArea");
  if (!contentArea) return;

  contentArea.innerHTML = `
        <div class="rent-payment-history-container">
            <div class="page-header">
                <h1><i class="fas fa-history"></i> My Rent Payment History</h1>
                <p>View all your rent payment attempts and their status</p>
            </div>
            <div class="empty-state">
                <i class="fas fa-receipt"></i>
                <h3>No Payment Data</h3>
                <p>Unable to load payment history. Please try again later.</p>
                <button class="btn-primary" onclick="location.reload()" style="margin-top: 15px;">
                    Refresh Page
                </button>
            </div>
        </div>
    `;
}

function formatDate(dateString) {
  if (!dateString) return "N/A";
  try {
    const date = new Date(dateString);
    return date.toLocaleDateString("en-US", {
      year: "numeric",
      month: "short",
      day: "numeric",
    });
  } catch (e) {
    return dateString;
  }
}

function escapeHtml(text) {
  if (!text) return "";
  const div = document.createElement("div");
  div.textContent = text;
  return div.innerHTML;
}
