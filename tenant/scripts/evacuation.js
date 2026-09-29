// tenant/scripts/evacuation_status.js
// Track and view move-out requests for the tenant portal

let evacRequests = [];
let currentTab = "all";
let currentRequestId = null;

document.addEventListener("DOMContentLoaded", function () {
  loadRequests();
});

// ==================== LOAD ====================
async function loadRequests() {
  const contentArea = document.getElementById("contentArea");
  if (!contentArea) {
    console.error("contentArea not found");
    return;
  }

  contentArea.innerHTML = `
        <div class="evacuation-container">
            <div class="loading-spinner">
                <div class="spinner"></div>
                <p>Loading your move-out requests...</p>
            </div>
        </div>`;

  try {
    const res = await fetch(
      "../backend/evacuation/fetch_evacuation_request.php",
      {
        credentials: "include",
      },
    );
    const data = await res.json();

    if (!data.success) {
      contentArea.innerHTML = `
                <div class="evacuation-container">
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fas fa-exclamation-circle"></i></div>
                        <h3>Could not load requests</h3>
                        <p>${escapeHtml(data.message || "Something went wrong. Please try again.")}</p>
                    </div>
                </div>`;
      return;
    }

    evacRequests = data.data.requests || [];
    renderPage();
  } catch (err) {
    console.error("Error loading requests:", err);
    contentArea.innerHTML = `
            <div class="evacuation-container">
                <div class="empty-state">
                    <div class="empty-icon"><i class="fas fa-exclamation-circle"></i></div>
                    <h3>Network error</h3>
                    <p>Unable to load your requests. Please refresh the page.</p>
                </div>
            </div>`;
  }
}

// ==================== RENDER PAGE ====================
function renderPage() {
  const contentArea = document.getElementById("contentArea");
  if (!contentArea) return;

  // Filter requests by current tab
  let filtered = [...evacRequests];
  if (currentTab !== "all") {
    filtered = evacRequests.filter((r) => r.status === currentTab);
  }

  // Counts
  const allCount = evacRequests.length;
  const pendingCount = evacRequests.filter(
    (r) => r.status === "pending_review",
  ).length;
  const approvedCount = evacRequests.filter(
    (r) => r.status === "approved",
  ).length;
  const completedCount = evacRequests.filter(
    (r) => r.status === "completed",
  ).length;
  const rejectedCount = evacRequests.filter(
    (r) => r.status === "rejected",
  ).length;
  const cancelledCount = evacRequests.filter(
    (r) => r.status === "cancelled",
  ).length;

  // Compute summary amounts
  const totalRequests = allCount;

  // Render page
  const html = `
        <div class="evacuation-container">
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-left">
                    <h1><i class="fas fa-sign-out-alt"></i> My Move-out Requests</h1>
                    <div class="breadcrumb">
                        <a href="dashboard.php">Dashboard</a> <span>/</span> Move-out Requests
                    </div>
                </div>
                <div class="page-header-actions">
                    <button class="btn-outline-primary" onclick="loadRequests()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="summary-cards">
                <div class="summary-card total">
                    <div class="card-top">
                        <div class="card-icon"><i class="fas fa-file-signature"></i></div>
                        <span class="card-sub">All time</span>
                    </div>
                    <div class="card-label">Total Requests</div>
                    <div class="card-amount">${totalRequests}</div>
                </div>
                <div class="summary-card pending">
                    <div class="card-top">
                        <div class="card-icon"><i class="fas fa-clock"></i></div>
                        <span class="card-sub">Awaiting</span>
                    </div>
                    <div class="card-label">Pending</div>
                    <div class="card-amount">${pendingCount}</div>
                </div>
                <div class="summary-card approved">
                    <div class="card-top">
                        <div class="card-icon"><i class="fas fa-check-circle"></i></div>
                        <span class="card-sub">Approved</span>
                    </div>
                    <div class="card-label">Approved</div>
                    <div class="card-amount">${approvedCount}</div>
                </div>
               <div class="summary-card rejected">
                    <div class="card-top">
                        <div class="card-icon"><i class="fas fa-times-circle"></i></div>
                        <span class="card-sub">Not approved</span>
                        </div>
                        <div class="card-label">Rejected</div>
                    <div class="card-amount">${rejectedCount}</div>
                </div>
                
                <div class="summary-card completed">
                    <div class="card-top">
                        <div class="card-icon"><i class="fas fa-flag-checkered"></i></div>
                        <span class="card-sub">Finished</span>
                    </div>
                    <div class="card-label">Completed</div>
                    <div class="card-amount">${completedCount}</div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="evacuation-tabs">
                <button class="evacuation-tab ${currentTab === "all" ? "active" : ""}" onclick="switchTab('all')">
                    All
                    <span class="tab-badge ${allCount === 0 ? "zero" : ""}">${allCount}</span>
                </button>
                <button class="evacuation-tab ${currentTab === "pending_review" ? "active" : ""}" onclick="switchTab('pending_review')">
                    Pending
                    <span class="tab-badge ${pendingCount === 0 ? "zero" : ""}">${pendingCount}</span>
                </button>
                <button class="evacuation-tab ${currentTab === "approved" ? "active" : ""}" onclick="switchTab('approved')">
                    Approved
                    <span class="tab-badge ${approvedCount === 0 ? "zero" : ""}">${approvedCount}</span>
                </button>
                <button class="evacuation-tab ${currentTab === "completed" ? "active" : ""}" onclick="switchTab('completed')">
                    Completed
                    <span class="tab-badge ${completedCount === 0 ? "zero" : ""}">${completedCount}</span>
                </button>
                ${
                  rejectedCount > 0
                    ? `
                    <button class="evacuation-tab ${currentTab === "rejected" ? "active" : ""}" onclick="switchTab('rejected')">
                        Rejected
                        <span class="tab-badge">${rejectedCount}</span>
                    </button>
                `
                    : ""
                }
                ${
                  cancelledCount > 0
                    ? `
                    <button class="evacuation-tab ${currentTab === "cancelled" ? "active" : ""}" onclick="switchTab('cancelled')">
                        Cancelled
                        <span class="tab-badge">${cancelledCount}</span>
                    </button>
                `
                    : ""
                }
            </div>

            <!-- Requests List -->
            ${
              filtered.length === 0
                ? renderEmptyState()
                : `
                <div class="requests-grid">
                    ${filtered.map((r) => renderRequestCard(r)).join("")}
                </div>
            `
            }
        </div>
    `;

  contentArea.innerHTML = html;
}

// ==================== EMPTY STATE ====================
function renderEmptyState() {
  if (evacRequests.length === 0) {
    return `
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-inbox"></i></div>
                <h3>No move-out requests yet</h3>
                <p>You haven't submitted any move-out requests. If you'd like to move out early, you can submit a request from your dashboard.</p>
                <a href="dashboard.php" class="btn-primary">
                    <i class="fas fa-arrow-left"></i> Go to Dashboard
                </a>
            </div>`;
  }
  return `
        <div class="empty-state">
            <div class="empty-icon"><i class="fas fa-filter"></i></div>
            <h3>No ${escapeHtml(currentTab.replace("_", " "))} requests</h3>
            <p>You don't have any requests with this status right now.</p>
            <button class="btn-primary" onclick="switchTab('all')">
                <i class="fas fa-undo"></i> Show All Requests
            </button>
        </div>`;
}

// ==================== REQUEST CARD ====================
function renderRequestCard(r) {
  const statusIcon = getStatusIcon(r.status);
  const primaryAmountHtml = renderPrimaryAmount(r);
  const rejectionHtml =
    r.status === "rejected" && r.rejection_reason
      ? `<div class="rejection-note">
                <i class="fas fa-info-circle"></i>
                <div><strong>Rejection reason:</strong> ${escapeHtml(r.rejection_reason)}</div>
           </div>`
      : "";

  return `
        <div class="request-card status-${r.status}" onclick="viewDetails('${r.request_id}')">
            <!-- Header -->
            <div class="request-header">
                <div class="request-header-left">
                    <div class="request-icon">
                        <i class="fas ${statusIcon}"></i>
                    </div>
                    <div class="request-title-group">
                        <span class="request-title">Move-out Request</span>
                        <span class="request-id">${escapeHtml(r.request_id)}</span>
                    </div>
                </div>
                <span class="request-status status-${r.status}">
                    <i class="fas ${statusIcon}"></i>
                    ${escapeHtml(r.status_label)}
                </span>
            </div>

            <!-- Details Grid -->
            <div class="request-details">
                <div class="request-detail-item">
                    <span class="request-detail-label">Requested</span>
                    <span class="request-detail-value">${escapeHtml(r.requested_move_out_date_formatted)}</span>
                </div>
                ${
                  r.approved_move_out_date_formatted
                    ? `
                    <div class="request-detail-item">
                        <span class="request-detail-label">Approved</span>
                        <span class="request-detail-value">${escapeHtml(r.approved_move_out_date_formatted)}</span>
                    </div>
                `
                    : ""
                }
                <div class="request-detail-item">
                    <span class="request-detail-label">Submitted</span>
                    <span class="request-detail-value">${escapeHtml(r.submitted_ago)}</span>
                </div>
                <div class="request-detail-item">
                    <span class="request-detail-label">Reason</span>
                    <span class="request-detail-value">${escapeHtml(r.reason)}</span>
                </div>
            </div>

            ${primaryAmountHtml}
            ${rejectionHtml}

            <!-- Actions -->
            <div class="request-actions" onclick="event.stopPropagation()">
                <button class="btn btn-view" onclick="viewDetails('${r.request_id}')">
                    <i class="fas fa-eye"></i> View Details
                </button>
                ${
                  r.can_cancel
                    ? `
                    <button class="btn btn-danger" onclick="openCancelModal('${r.request_id}')">
                        <i class="fas fa-times"></i> Cancel Request
                    </button>
                `
                    : ""
                }
            </div>
        </div>
    `;
}

// ==================== PRIMARY AMOUNT ====================
function renderPrimaryAmount(r) {
  if (r.primary_amount === null || r.primary_amount === undefined) return "";

  const isRefund = r.primary_amount >= 0;
  const cls = isRefund ? "refund" : "owed";
  const sign = isRefund ? "" : "-";

  return `
        <div class="primary-amount ${cls}">
            <span class="amount-label">${escapeHtml(r.primary_amount_label || "")}</span>
            <span class="amount-value">${sign}₦${formatNumber(Math.abs(r.primary_amount))}</span>
        </div>`;
}

// ==================== ICON PER STATUS ====================
function getStatusIcon(status) {
  const map = {
    pending_review: "fa-clock",
    approved: "fa-check-circle",
    rejected: "fa-times-circle",
    completed: "fa-flag-checkered",
    cancelled: "fa-ban",
  };
  return map[status] || "fa-info-circle";
}

// ==================== SWITCH TAB ====================
function switchTab(tab) {
  currentTab = tab;
  renderPage();
}

// ==================== VIEW DETAILS ====================
async function viewDetails(requestId) {
  currentRequestId = requestId;

  const modalBody = document.getElementById("requestDetailsBody");
  modalBody.innerHTML =
    '<div class="loading-spinner"><div class="spinner"></div></div>';
  openModal("requestDetailsModal");

  try {
    const res = await fetch(
      "../backend/evacuation/fetch_evacuation_request_details.php",
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "include",
        body: JSON.stringify({ request_id: requestId }),
      },
    );
    const data = await res.json();

    if (!data.success) {
      modalBody.innerHTML = `
                <div class="empty-state" style="padding: 40px 20px;">
                    <div class="empty-icon"><i class="fas fa-exclamation-circle"></i></div>
                    <h3>Could not load details</h3>
                    <p>${escapeHtml(data.message || "Please try again.")}</p>
                </div>`;
      return;
    }

    renderDetails(data.data);
  } catch (err) {
    console.error("Error:", err);
    modalBody.innerHTML = `
            <div class="empty-state" style="padding: 40px 20px;">
                <div class="empty-icon"><i class="fas fa-exclamation-circle"></i></div>
                <h3>Network error</h3>
                <p>Please try again.</p>
            </div>`;
  }
}

// ==================== RENDER DETAILS ====================
function renderDetails(r) {
  const modalBody = document.getElementById("requestDetailsBody");
  const statusIcon = getStatusIcon(r.status);

  // Timeline
  const timelineHtml = renderTimeline(r);

  // Status block
  const statusBlockHtml = renderStatusBlock(r);

  // Settlement breakdown (only for completed)
  const settlementHtml =
    r.has_settlement && r.settlement ? renderSettlement(r) : "";

  // Deductions (only for completed)
  const deductionsHtml =
    r.has_settlement && r.deductions && r.deductions.length > 0
      ? renderDeductions(r.deductions)
      : "";

  modalBody.innerHTML = `
        <!-- Header info -->
        <div style="margin-bottom: 20px;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;">
                <div>
                    <div style="font-size: 15px; font-weight: 700; color: #0a0a1a; margin-bottom: 4px;">
                        ${escapeHtml(r.request_id)}
                    </div>
                    <div style="font-size: 12px; color: #9ca3af;">
                        Submitted ${escapeHtml(r.created_at_formatted)}
                    </div>
                </div>
                <span class="request-status status-${r.status}">
                    <i class="fas ${statusIcon}"></i>
                    ${escapeHtml(r.status_label)}
                </span>
            </div>
        </div>

        <!-- Timeline -->
        <div class="detail-section">
            <h4><i class="fas fa-stream"></i> Progress Timeline</h4>
            ${timelineHtml}
        </div>

        <!-- Request info -->
        <div class="detail-section">
            <h4><i class="fas fa-info-circle"></i> Request Information</h4>
            <div class="detail-row">
                <span class="detail-label"><i class="fas fa-building"></i> Property</span>
                <span class="detail-value">${escapeHtml(r.property_name)} — Apt ${escapeHtml(r.apartment_number)}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label"><i class="fas fa-calendar"></i> Requested Move-out</span>
                <span class="detail-value">${escapeHtml(r.requested_move_out_date_formatted)}</span>
            </div>
            ${
              r.approved_move_out_date_formatted
                ? `
                <div class="detail-row">
                    <span class="detail-label"><i class="fas fa-calendar-check"></i> Approved Move-out</span>
                    <span class="detail-value">${escapeHtml(r.approved_move_out_date_formatted)}</span>
                </div>
            `
                : ""
            }
            <div class="detail-row">
                <span class="detail-label"><i class="fas fa-question-circle"></i> Reason</span>
                <span class="detail-value">${escapeHtml(r.reason)}</span>
            </div>
            ${
              r.notes
                ? `
                <div class="detail-row">
                    <span class="detail-label"><i class="fas fa-sticky-note"></i> Your Notes</span>
                    <span class="detail-value">${escapeHtml(r.notes)}</span>
                </div>
            `
                : ""
            }
        </div>

        <!-- Status block -->
        ${statusBlockHtml}

        <!-- Settlement -->
        ${settlementHtml}

        <!-- Deductions -->
        ${deductionsHtml}
    `;
}

// ==================== STATUS BLOCK ====================
function renderStatusBlock(r) {
  switch (r.status) {
    case "pending_review":
      return `
                <div class="info-block warning">
                    <h4><i class="fas fa-clock"></i> Awaiting Review</h4>
                    <p>Your request is being reviewed by the property manager. You'll be notified once a decision is made.</p>
                </div>`;

    case "approved":
      return `
                <div class="info-block success">
                    <h4><i class="fas fa-check-circle"></i> Approved</h4>
                    <p>Your move-out has been approved for <strong>${escapeHtml(r.approved_move_out_date_formatted || r.requested_move_out_date_formatted)}</strong>. Final settlement will be calculated on your move-out date.</p>
                </div>`;

    case "rejected":
      return `
                <div class="info-block danger">
                    <h4><i class="fas fa-times-circle"></i> Rejected</h4>
                    <p><strong>Reason:</strong> ${escapeHtml(r.rejection_reason || "Not specified")}</p>
                </div>`;

    case "cancelled":
      return `
                <div class="info-block">
                    <h4><i class="fas fa-ban"></i> Cancelled</h4>
                    <p>This request was cancelled${r.cancelled_at_formatted ? ` on ${escapeHtml(r.cancelled_at_formatted)}` : ""}. You can submit a new move-out request anytime.</p>
                </div>`;

    case "completed":
      return `
                <div class="info-block success">
                    <h4><i class="fas fa-flag-checkered"></i> Completed</h4>
                    <p>Your move-out has been finalized${r.processed_at_formatted ? ` on ${escapeHtml(r.processed_at_formatted)}` : ""}. Below is the final settlement breakdown.</p>
                </div>`;

    default:
      return "";
  }
}

// ==================== TIMELINE ====================
function renderTimeline(r) {
  const steps = [];

  // Step 1: Submitted
  steps.push({
    title: "Request Submitted",
    meta: r.created_at_formatted,
    state: "done",
  });

  // Step 2: Reviewed
  if (r.status === "pending_review") {
    steps.push({
      title: "Under Review",
      meta: "Awaiting decision",
      state: "active",
    });
  } else if (r.status === "approved") {
    steps.push({
      title: "Approved",
      meta: r.approved_move_out_date_formatted
        ? `Move-out set for ${r.approved_move_out_date_formatted}`
        : "",
      state: "done",
    });
  } else if (r.status === "rejected") {
    steps.push({
      title: "Rejected",
      meta: r.rejection_reason || "",
      state: "danger",
    });
  } else if (r.status === "completed") {
    steps.push({
      title: "Approved",
      meta: r.approved_move_out_date_formatted || "",
      state: "done",
    });
    steps.push({
      title: "Completed",
      meta: r.processed_at_formatted || "",
      state: "done",
    });
  } else if (r.status === "cancelled") {
    steps.push({
      title: "Cancelled by You",
      meta: r.cancelled_at_formatted || "",
      state: "danger",
    });
  }

  return `
        <div class="timeline">
            ${steps
              .map(
                (s) => `
                <div class="timeline-item ${s.state}">
                    <div class="timeline-title">${escapeHtml(s.title)}</div>
                    ${s.meta ? `<div class="timeline-meta">${escapeHtml(s.meta)}</div>` : ""}
                </div>
            `,
              )
              .join("")}
        </div>`;
}

// ==================== SETTLEMENT ====================
function renderSettlement(r) {
  const s = r.settlement;

  return `
        <div class="settlement-box">
            <h4><i class="fas fa-calculator"></i> Settlement Breakdown</h4>

            <div class="settlement-group">
                <div class="settlement-row">
                    <span>Cycle Rent</span>
                    <span>${s.cycle_rent_amount_formatted}</span>
                </div>
                <div class="settlement-row">
                    <span>Cycle Days</span>
                    <span>${s.cycle_total_days}</span>
                </div>
                <div class="settlement-row">
                    <span>Days Used</span>
                    <span>${s.cycle_days_used}</span>
                </div>
                <div class="settlement-row">
                    <span>Rent Used</span>
                    <span>${s.rent_used_formatted}</span>
                </div>
                <div class="settlement-row">
                    <span>Total Paid in Cycle</span>
                    <span>${s.total_paid_in_cycle_formatted}</span>
                </div>
                <div class="settlement-row">
                    <span>Unused Rent</span>
                    <span>${s.unused_rent_formatted}</span>
                </div>
            </div>

            <div class="settlement-group">
                <div class="settlement-row">
                    <span>Your Share (50%)</span>
                    <span class="text-success">+${s.tenant_rent_share_formatted}</span>
                </div>
                <div class="settlement-row">
                    <span>Landlord Share (50%)</span>
                    <span class="text-muted">${s.landlord_rent_share_formatted}</span>
                </div>
            </div>

            <div class="settlement-group">
                <div class="settlement-row">
                    <span>Security Deposit</span>
                    <span>${s.security_deposit_formatted}</span>
                </div>
                <div class="settlement-row">
                    <span>Total Deductions</span>
                    <span class="text-danger">-${s.damages_total_formatted}</span>
                </div>
            </div>

            <div class="settlement-total">
                <div class="settlement-row">
                    <span>${s.final_settlement_amount >= 0 ? "Refund Due to You" : "Amount You Owe"}</span>
                    <span class="${s.final_settlement_amount >= 0 ? "text-success" : "text-danger"}">
                        ${s.final_settlement_amount_formatted}
                    </span>
                </div>
            </div>
        </div>`;
}

// ==================== DEDUCTIONS ====================
function renderDeductions(deductions) {
  return `
        <div class="detail-section" style="margin-top: 16px;">
            <h4><i class="fas fa-receipt"></i> Itemized Deductions</h4>
            ${deductions
              .map(
                (d) => `
                <div class="detail-row">
                    <span class="detail-label">
                        <i class="fas fa-minus-circle" style="color:#dc2626;"></i>
                        ${escapeHtml(d.type)}
                        ${d.description ? `<br><small style="color:#94a3b8; font-weight:400;">${escapeHtml(d.description)}</small>` : ""}
                    </span>
                    <span class="detail-value text-danger">${d.amount_formatted}</span>
                </div>
            `,
              )
              .join("")}
        </div>`;
}

// ==================== CANCEL ====================
function openCancelModal(requestId) {
  currentRequestId = requestId;
  openModal("cancelConfirmModal");
}

async function submitCancel() {
  if (!currentRequestId) return;

  const btn = document.getElementById("cancelConfirmBtn");
  const originalText = btn.innerHTML;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cancelling...';
  btn.disabled = true;

  try {
    const res = await fetch(
      "../backend/evacuation/cancel_evacuation_request.php",
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "include",
        body: JSON.stringify({ request_id: currentRequestId }),
      },
    );
    const data = await res.json();

    if (data.success) {
      window.showToast("Request cancelled successfully", "success");
      closeModal("cancelConfirmModal");
      loadRequests();
    } else {
      window.showToast(data.message || "Failed to cancel request", "error");
    }
  } catch (err) {
    console.error("Error cancelling:", err);
    window.showToast("Network error. Please try again.", "error");
  } finally {
    btn.innerHTML = originalText;
    btn.disabled = false;
  }
}

// ==================== MODAL HELPERS ====================
function openModal(modalId) {
  const modal = document.getElementById(modalId);
  if (modal) modal.classList.add("active");
}

function closeModal(modalId) {
  const modal = document.getElementById(modalId);
  if (modal) modal.classList.remove("active");
}

// ==================== TOAST (falls back to UI lib if present) ====================
// function window.showToast(message, type = "info") {
//   if (window.UI && typeof window.UI.toast === "function") {
//     window.UI.toast(message, type);
//     return;
//   }
//   // Fallback
//   alert(message);
// }

// ==================== UTILITIES ====================
function formatNumber(value) {
  if (!value && value !== 0) return "0.00";
  return new Intl.NumberFormat("en-NG", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(value);
}

function escapeHtml(text) {
  if (!text) return "";
  const div = document.createElement("div");
  div.textContent = text;
  return div.innerHTML;
}

// ==================== GLOBAL EVENTS ====================
document.addEventListener("click", function (e) {
  const cancelModal = document.getElementById("cancelConfirmModal");
  const detailsModal = document.getElementById("requestDetailsModal");
  if (e.target === cancelModal) closeModal("cancelConfirmModal");
  if (e.target === detailsModal) closeModal("requestDetailsModal");
});

document.addEventListener("keydown", function (e) {
  if (e.key === "Escape") {
    ["cancelConfirmModal", "requestDetailsModal"].forEach((id) => {
      const m = document.getElementById(id);
      if (m && m.classList.contains("active")) closeModal(id);
    });
  }
});
