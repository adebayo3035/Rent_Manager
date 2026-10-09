// admin/js/evacuation_requests.js

const EvacuationApp = {
  currentStatus: "pending_review",
  currentRequestId: null,
  currentRequest: null,
  deductions: [],
  isRejectMode: false,
  includeOutstandingFees: true,

  // ==================== INIT ====================
  init: function () {
    this.loadRequests(this.currentStatus);
    this.loadStatistics();
    this.bindEvents();
  },

  bindEvents: function () {
    document.querySelectorAll(".tab-btn").forEach((btn) => {
      btn.addEventListener("click", () => {
        document
          .querySelectorAll(".tab-btn")
          .forEach((b) => b.classList.remove("active"));
        btn.classList.add("active");
        this.currentStatus = btn.dataset.status;
        this.loadRequests(this.currentStatus);
      });
    });
  },

  // ==================== STATS ====================
  loadStatistics: async function () {
    try {
      const response = await fetch(
        "../backend/tenants/get_evacuation_requests.php?action=stats",
      );
      const data = await response.json();

      if (data.success) {
        document.getElementById("pendingCount").textContent =
          data.data.pending_review || 0;
        document.getElementById("approvedCount").textContent =
          data.data.approved || 0;
        document.getElementById("completedCount").textContent =
          data.data.completed || 0;
        document.getElementById("rejectedCount").textContent =
          data.data.rejected || 0;
      }
    } catch (error) {
      console.error("Error loading statistics:", error);
    }
  },

  // ==================== LOAD REQUESTS ====================
  loadRequests: async function (status) {
    const container = document.getElementById("requestsContainer");
    container.innerHTML =
      '<div class="loading-state"><div class="spinner"></div><p>Loading requests...</p></div>';

    try {
      const response = await fetch(
        `../backend/tenants/get_evacuation_requests.php?status=${status}`,
      );
      const data = await response.json();

      if (data.success) {
        this.renderRequests(data.data.requests);
      } else {
        container.innerHTML = `<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>${this.escapeHtml(data.message)}</p></div>`;
      }
    } catch (error) {
      console.error("Error loading requests:", error);
      container.innerHTML =
        '<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading requests. Please try again.</p></div>';
    }
  },

  // ==================== RENDER ====================
  renderRequests: function (requests) {
    const container = document.getElementById("requestsContainer");

    if (!requests || requests.length === 0) {
      container.innerHTML =
        '<div class="empty-state"><i class="fas fa-inbox"></i><p>No evacuation requests found</p></div>';
      return;
    }

    container.innerHTML = requests
      .map(
        (request) => `
            <div class="evacuation-card" data-request-id="${request.request_id}">
                <div class="card-header">
                    <h3>${this.escapeHtml(request.tenant_name)}</h3>
                    <span class="status-badge status-${request.status}">${request.status.replace("_", " ").toUpperCase()}</span>
                </div>
                <div class="card-body">
                    <div class="info-row">
                        <span class="info-label">Property</span>
                        <span class="info-value">${this.escapeHtml(request.property_name)} - Apt ${this.escapeHtml(request.apartment_number)}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Requested Move-out</span>
                        <span class="info-value">${request.requested_move_out_date_formatted || "N/A"}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Reason</span>
                        <span class="info-value">${this.escapeHtml(request.reason)}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Submitted</span>
                        <span class="info-value">${request.created_at_formatted || "N/A"}</span>
                    </div>
                    ${
                      request.outstanding_amount > 0
                        ? `
                    <div class="info-row">
                        <span class="info-label">Outstanding Balance</span>
                        <span class="info-value" style="color:#dc2626;">₦${this.formatNumber(request.outstanding_amount)}</span>
                    </div>`
                        : ""
                    }
                </div>
                <div class="card-actions">
                    ${
                      request.status === "pending_review"
                        ? `
                        <button class="btn btn-primary" onclick="EvacuationApp.openReviewModal('${request.request_id}')">
                            <i class="fas fa-clipboard-check"></i> Review Request
                        </button>
                    `
                        : request.status === "approved"
                          ? `
                        <button class="btn btn-success" onclick="EvacuationApp.openProcessModal('${request.request_id}')">
                            <i class="fas fa-check-circle"></i> Process Move-out
                        </button>
                    `
                          : ""
                    }
                    <button class="btn btn-outline" onclick="EvacuationApp.viewDetails('${request.request_id}')">
                        <i class="fas fa-eye"></i> View Details
                    </button>
                </div>
            </div>
        `,
      )
      .join("");
  },

  // ==================== REVIEW MODAL ====================
  openReviewModal: function (requestId) {
    this.currentRequestId = requestId;
    this.isRejectMode = false;

    UI.loader.show("Loading request...");
    fetch(
      `../backend/tenants/get_evacuation_requests.php?request_id=${requestId}`,
    )
      .then((res) => res.json())
      .then((data) => {
        if (data.success && data.data.requests.length > 0) {
          this.currentRequest = data.data.requests[0];
          this.showReviewModal();
        } else {
          UI.toast(data.message || "Request not found", "danger");
        }
      })
      .catch((err) => {
        console.error(err);
        UI.toast("Failed to load request", "danger");
      })
      .finally(() => UI.loader.hide());
  },

  showReviewModal: function () {
    const request = this.currentRequest;
    const infoHtml = `
    <div class="review-info-card">
        <div class="review-info-header">
            <i class="fas fa-file-signature"></i>
            <span>Request Summary</span>
        </div>

        <div class="review-info-grid">
            <div class="review-info-item">
                <div class="review-info-label">
                    <i class="fas fa-user"></i> Tenant
                </div>
                <div class="review-info-value">
                    ${this.escapeHtml(request.tenant_name)}
                </div>
            </div>

            <div class="review-info-item">
                <div class="review-info-label">
                    <i class="fas fa-building"></i> Property / Apartment
                </div>
                <div class="review-info-value">
                    ${this.escapeHtml(request.property_name)} · Apt ${this.escapeHtml(request.apartment_number)}
                </div>
            </div>

            <div class="review-info-item">
                <div class="review-info-label">
                    <i class="fas fa-calendar-alt"></i> Proposed Move-Out Date
                </div>
                <div class="review-info-value">
                    ${request.requested_move_out_date_formatted || "N/A"}
                </div>
            </div>

            <div class="review-info-item">
                <div class="review-info-label">
                    <i class="fas fa-comment-dots"></i> Reason
                </div>
                <div class="review-info-value">
                    ${this.escapeHtml(request.reason) || "—"}
                </div>
            </div>
            <div class="review-info-item">
                <div class="review-info-label">
                    <i class="fas fa-calendar-alt"></i> Requested on
                </div>
                <div class="review-info-value">
                    ${this.escapeHtml(request.created_at_formatted) || "—"}
                </div>
            </div>
            <div class="review-info-item">
                <div class="review-info-label">
                    <i class="fas fa-note-alt"></i> Note by Tenant
                </div>
                <div class="review-info-value">
                    ${this.escapeHtml(request.notes) || "—"}
                </div>
            </div>

        </div>

        ${request.outstanding_amount > 0 ? `
            <div class="review-info-alert">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <div class="review-info-alert-label">Outstanding Balance</div>
                    <div class="review-info-alert-value">₦${this.formatNumber(request.outstanding_amount)}</div>
                </div>
            </div>
        ` : ""}
    </div>
`;

document.getElementById("reviewRequestInfo").innerHTML = infoHtml;
    document.getElementById("approvedMoveOutDate").value =
      request.requested_move_out_date || "";
    document.getElementById("reviewNotes").value = "";
    document.getElementById("rejectionReason").value = "";

    // Reset reject mode
    this.isRejectMode = false;
    document.getElementById("rejectionReasonGroup").style.display = "none";

    const rejectBtn = document.getElementById("rejectBtn");
    rejectBtn.innerHTML = '<i class="fas fa-times"></i> Reject';
    rejectBtn.classList.remove("btn-outline");
    rejectBtn.classList.add("btn-danger");

    document.getElementById("confirmRejectBtn").style.display = "none";
    document.getElementById("approveBtn").style.display = "inline-flex";

    document.getElementById("reviewModal").classList.add("active");
  },

  closeReviewModal: function () {
    document.getElementById("reviewModal").classList.remove("active");
    this.currentRequestId = null;
    this.currentRequest = null;
    this.isRejectMode = false;
  },

  toggleRejectForm: function () {
    this.isRejectMode = !this.isRejectMode;

    const rejectionGroup = document.getElementById("rejectionReasonGroup");
    const rejectBtn = document.getElementById("rejectBtn");
    const confirmRejectBtn = document.getElementById("confirmRejectBtn");
    const approveBtn = document.getElementById("approveBtn");

    if (this.isRejectMode) {
      rejectionGroup.style.display = "block";
      rejectBtn.innerHTML = '<i class="fas fa-arrow-left"></i> Cancel';
      rejectBtn.classList.remove("btn-danger");
      rejectBtn.classList.add("btn-outline");
      confirmRejectBtn.style.display = "inline-flex";
      approveBtn.style.display = "none";
    } else {
      rejectionGroup.style.display = "none";
      rejectBtn.innerHTML = '<i class="fas fa-times"></i> Reject';
      rejectBtn.classList.remove("btn-outline");
      rejectBtn.classList.add("btn-danger");
      confirmRejectBtn.style.display = "none";
      approveBtn.style.display = "inline-flex";
    }
  },

  // ==================== DECLINE APPROVAL ====================
declineApproval: async function () {
    if (!this.currentRequestId) {
        UI.toast("No request selected", "warning");
        return;
    }

    // Confirm — this is destructive
    const confirmed = await this.confirmAction(
        "Decline Approval",
        "This will withdraw the approval and return the request to 'pending review'. "
        + "Any changes made during approval (move-out date, tenant evacuation status) "
        + "will be reverted. Continue?",
        "danger"
    );
    if (!confirmed) return;

    // Ask for a reason (optional but recommended)
    const reason = await this.promptForReason();
    if (reason === null) return;  // user cancelled the prompt

    // Get CSRF
    const csrf = await this.getCsrfToken("review_evacuation_form");
    if (!csrf) {
        UI.toast("Security token missing. Please refresh the page.", "danger");
        return;
    }

    const payload = {
        request_id: this.currentRequestId,
        action: "decline",
        decline_reason: reason || "Approval withdrawn by admin",
        csrf_token: csrf,
        token_id: "review_evacuation_form",
    };

    const declineBtn = document.querySelector("#processModal .btn-danger");
    const originalText = declineBtn.innerHTML;
    declineBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Withdrawing...';
    declineBtn.disabled = true;

    UI.loader.show("Withdrawing approval...");

    try {
        const response = await fetch(
            "../backend/tenants/review_evacuation_requests.php",
            {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                credentials: "include",
                body: JSON.stringify(payload),
            }
        );

        const contentType = response.headers.get("content-type");
        if (!contentType || !contentType.includes("application/json")) {
            const text = await response.text();
            console.error("Non-JSON response:", text);
            throw new Error("Server returned an unexpected response.");
        }

        const result = await response.json();

        if (response.ok && result.success) {
            UI.toast(
                result.data || "Approval withdrawn successfully",
                "success"
            );
            this.closeProcessModal();
            this.loadRequests(this.currentStatus);
            this.loadStatistics();
        } else {
            const errMsg =
                (typeof result.message === "string" && result.message) ||
                result.data?.message ||
                "Failed to withdraw approval";
            UI.toast(errMsg, "danger");
        }
    } catch (error) {
        console.error("Decline error:", error);
        UI.toast(
            error.message || "An error occurred. Please try again.",
            "danger"
        );
    } finally {
        declineBtn.innerHTML = originalText;
        declineBtn.disabled = false;
        UI.loader.hide();
    }
},

/**
 * Show a small prompt for the decline reason.
 * Returns the string, empty string for "no reason", or null if cancelled.
 */
promptForReason: function () {
    return new Promise((resolve) => {
        const html = `
            <div id="declineReasonDialog" class="modal active" style="z-index: 10001;">
                <div class="modal-content" style="max-width: 460px;">
                    <div class="modal-header">
                        <h3>Withdraw Approval</h3>
                        <button class="modal-close" id="declineDialogClose">&times;</button>
                    </div>
                    <div class="modal-body">
                        <p style="margin-bottom: 12px; color: #4b5563;">
                            Optional: Provide a reason for withdrawing this approval.
                            The tenant will see this message.
                        </p>
                        <textarea id="declineReasonInput"
                                  class="form-input"
                                  rows="3"
                                  placeholder="e.g. Approved in error, scheduling conflict, etc."
                                  style="width: 100%; resize: vertical;"></textarea>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline" id="declineDialogCancel">Cancel</button>
                        <button class="btn btn-danger" id="declineDialogConfirm">Withdraw Approval</button>
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML("beforeend", html);

        const dialog = document.getElementById("declineReasonDialog");
        const input = document.getElementById("declineReasonInput");
        const confirmBtn = document.getElementById("declineDialogConfirm");
        const cancelBtn = document.getElementById("declineDialogCancel");
        const closeBtn = document.getElementById("declineDialogClose");

        const cleanup = () => dialog.remove();

        confirmBtn.onclick = () => {
            const value = input.value.trim();
            cleanup();
            resolve(value);   // may be empty string
        };

        cancelBtn.onclick = () => {
            cleanup();
            resolve(null);    // cancelled
        };

        closeBtn.onclick = () => {
            cleanup();
            resolve(null);
        };

        // Click outside to cancel
        dialog.onclick = (e) => {
            if (e.target === dialog) {
                cleanup();
                resolve(null);
            }
        };

        input.focus();
    });
},

  // ==================== SUBMIT REVIEW ====================
  submitReview: async function (action) {
    const data = {
      request_id: this.currentRequestId,
      action: action,
    };

    // Client-side validation
    if (action === "approve") {
      const approvedDate = document.getElementById("approvedMoveOutDate").value;
      if (!approvedDate) {
        UI.toast("Please select an approved move-out date", "warning");
        return;
      }
      data.approved_move_out_date = approvedDate;
      data.notes = document.getElementById("reviewNotes").value.trim();
    } else {
      const rejectionReason = document
        .getElementById("rejectionReason")
        .value.trim();
      if (!rejectionReason) {
        UI.toast("Please provide a reason for rejection", "warning");
        return;
      }
      if (rejectionReason.length < 5) {
        UI.toast("Rejection reason must be at least 5 characters", "warning");
        return;
      }
      data.rejection_reason = rejectionReason;
    }

    // Confirm destructive action
    if (action === "reject") {
      const confirmed = await this.confirmAction(
        "Confirm Rejection",
        `Are you sure you want to reject this evacuation request? The tenant will be notified.`,
        "danger",
      );
      if (!confirmed) return;
    }

    // Get CSRF token
    const csrf = await this.getCsrfToken("review_evacuation_form");
    if (!csrf) {
      UI.toast("Security token missing. Please refresh the page.", "danger");
      return;
    }
    data.csrf_token = csrf;
    data.token_id = "review_evacuation_form";

    // Submit
    const submitBtn =
      action === "approve"
        ? document.getElementById("approveBtn")
        : document.getElementById("confirmRejectBtn");

    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML =
      '<i class="fas fa-spinner fa-spin"></i> Processing...';
    submitBtn.disabled = true;

    UI.loader.show(
      action === "approve" ? "Approving request..." : "Rejecting request...",
    );

    try {
      const response = await fetch(
        "../backend/tenants/review_evacuation_requests.php",
        {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          credentials: "include",
          body: JSON.stringify(data),
        },
      );

      // Handle non-JSON responses (e.g., 500 error HTML)
      const contentType = response.headers.get("content-type") || "";
      const responseText = await response.text();

      if (!responseText.trim()) {
        console.error("Empty response from review_evacuation_requests.php", {
          status: response.status,
          statusText: response.statusText,
        });
        throw new Error(
          "Server returned an empty response. Please check the PHP error log.",
        );
      }

      if (!contentType.includes("application/json")) {
        console.error("Non-JSON response:", responseText);
        throw new Error(
          "Server returned an unexpected response. Please try again.",
        );
      }

      let result;
      try {
        result = JSON.parse(responseText);
      } catch (parseError) {
        console.error("Invalid JSON response:", responseText, parseError);
        throw new Error("Server returned invalid JSON. Please try again.");
      }

      if (response.ok && result.success) {
        UI.toast(result.data || `Request ${action}d successfully`, "success");
        this.closeReviewModal();
        this.loadRequests(this.currentStatus);
        this.loadStatistics();
      } else {
        // Surface backend error message
        const errMsg =
          result.message.message ||
          result.data?.message ||
          `Failed to ${action} request`;
        UI.toast(errMsg, "danger");
      }
    } catch (error) {
      console.error("Error:", error);
      UI.toast(
        error.message || "An error occurred. Please try again.",
        "danger",
      );
    } finally {
      submitBtn.innerHTML = originalText;
      submitBtn.disabled = false;
      UI.loader.hide();
    }
  },

  // ==================== PROCESS MODAL ====================
  openProcessModal: function (requestId) {
    this.currentRequestId = requestId;
    this.deductions = [];
    this.includeOutstandingFees = true; // ← add

    UI.loader.show("Loading request...");
    fetch(
      `../backend/tenants/get_evacuation_requests.php?request_id=${requestId}`,
    )
      .then((res) => res.json())
      .then((data) => {
        if (data.success && data.data.requests.length > 0) {
          this.currentRequest = data.data.requests[0];
          this.showProcessModal();
        } else {
          UI.toast(data.message || "Request not found", "danger");
        }
      })
      .catch((err) => {
        console.error(err);
        UI.toast("Failed to load request", "danger");
      })
      .finally(() => UI.loader.hide());
  },

  showProcessModal: function () {
    const request = this.currentRequest;

    // ----- Request info -----
    const infoHtml = `
        <div class="info-row"><strong>Tenant:</strong> ${this.escapeHtml(request.tenant_name)}</div>
        <div class="info-row"><strong>Property:</strong> ${this.escapeHtml(request.property_name)} - Apt ${this.escapeHtml(request.apartment_number)}</div>
        <div class="info-row"><strong>Approved Move-out:</strong> ${request.approved_move_out_date ? this.formatDate(request.approved_move_out_date) : "N/A"}</div>
    `;
    document.getElementById("processRequestInfo").innerHTML = infoHtml;

    document.getElementById("actualMoveOutDate").value =
      request.approved_move_out_date || new Date().toISOString().split("T")[0];

    // ----- Outstanding fees -----
    this.renderOutstandingFees(request);

    // ----- Reset deductions -----
    document.getElementById("deductionsContainer").innerHTML = "";
    this.deductions = [];
    this.updateSummary();

     // Show/hide Decline button based on request state
    const declineBtn = document.querySelector("#processModal .btn-danger");
    if (declineBtn) {
        if (request.status === "approved") {
            declineBtn.style.display = "inline-flex";
        } else {
            declineBtn.style.display = "none";
        }
    }

    document.getElementById("processModal").classList.add("active");
  },

  /**
   * Render the outstanding fees block.
   * Shows the fee list and a checkbox to include/exclude from settlement.
   */
  renderOutstandingFees: function (request) {
    const section = document.getElementById("outstandingFeesSection");
    const list = document.getElementById("outstandingFeesList");
    const checkbox = document.getElementById("includeOutstandingFees");

    const fees = request.outstanding_fees || [];

    if (!fees.length) {
      section.style.display = "none";
      this.includeOutstandingFees = false;
      return;
    }

    section.style.display = "block";

    const rows = fees
      .map(
        (f) => `
        <div class="outstanding-fee-row">
            <div class="fee-name">
                ${this.escapeHtml(f.fee_type_name)}
                ${f.due_date_formatted ? `<small>Due: ${this.escapeHtml(f.due_date_formatted)}</small>` : ""}
            </div>
            <div>
                <span class="fee-amount">₦${this.formatNumber(f.amount)}</span>
                <span class="fee-status ${f.status}">${this.escapeHtml(f.status)}</span>
            </div>
        </div>
    `,
      )
      .join("");

    const total = request.outstanding_fees_total || 0;

    list.innerHTML = `
        ${rows}
        <div class="outstanding-fees-total">
            <span>Total Outstanding:</span>
            <span>₦${this.formatNumber(total)}</span>
        </div>
    `;

    // Default: include fees
    checkbox.checked = true;
    this.includeOutstandingFees = true;

    // Wire up the checkbox (remove any existing listener first)
    checkbox.onchange = (e) => {
      this.includeOutstandingFees = e.target.checked;
      this.updateSummary();
    };
  },
  closeProcessModal: function () {
    document.getElementById("processModal").classList.remove("active");
    this.currentRequestId = null;
    this.currentRequest = null;
    this.deductions = [];
    this.includeOutstandingFees = true; // ← reset
  },

  addDeductionRow: function () {
    const container = document.getElementById("deductionsContainer");
    const index = this.deductions.length;

    const rowHtml = `
            <div class="deduction-row" data-index="${index}">
                <div class="row-fields">
                    <select class="form-select deduction-type" data-index="${index}">
                        <option value="">Select type...</option>
                        <option value="Wall Damage">Wall Damage</option>
                        <option value="Floor Damage">Floor Damage</option>
                        <option value="Cleaning Fee">Cleaning Fee</option>
                        <option value="Missing Items">Missing Items</option>
                        <option value="Utility Bills">Utility Bills</option>
                        <option value="Painting">Painting</option>
                        <option value="Lock Replacement">Lock Replacement</option>
                        <option value="Other">Other</option>
                    </select>
                    <input type="number" class="form-input deduction-amount" data-index="${index}" placeholder="Amount" step="0.01" min="0">
                    <button class="btn-danger" style="padding: 8px 12px;" onclick="EvacuationApp.removeDeductionRow(${index})">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
                <input type="text" class="form-input deduction-desc" data-index="${index}" placeholder="Description (optional)">
            </div>
        `;

    container.insertAdjacentHTML("beforeend", rowHtml);
    this.deductions.push({ type: "", amount: 0, description: "" });

    document
      .querySelector(`.deduction-type[data-index="${index}"]`)
      .addEventListener("change", (e) => {
        this.deductions[index].type = e.target.value;
        this.updateSummary();
      });
    document
      .querySelector(`.deduction-amount[data-index="${index}"]`)
      .addEventListener("input", (e) => {
        this.deductions[index].amount = parseFloat(e.target.value) || 0;
        this.updateSummary();
      });
    document
      .querySelector(`.deduction-desc[data-index="${index}"]`)
      .addEventListener("input", (e) => {
        this.deductions[index].description = e.target.value;
      });
  },

  removeDeductionRow: function (index) {
    this.deductions.splice(index, 1);
    this.renderDeductions();
    this.updateSummary();
  },

  renderDeductions: function () {
    // Save current state before re-render
    const saved = JSON.parse(JSON.stringify(this.deductions));
    const container = document.getElementById("deductionsContainer");
    container.innerHTML = "";
    this.deductions = [];
    saved.forEach((d) => {
      this.addDeductionRow();
      const i = this.deductions.length - 1;
      document.querySelector(`.deduction-type[data-index="${i}"]`).value =
        d.type;
      document.querySelector(`.deduction-amount[data-index="${i}"]`).value =
        d.amount || "";
      document.querySelector(`.deduction-desc[data-index="${i}"]`).value =
        d.description || "";
      this.deductions[i] = d;
    });
  },

  updateSummary: function () {
    const request = this.currentRequest;
    if (!request) return;

    const securityDeposit = parseFloat(request.security_deposit) || 0;
    const tenantRentShare = parseFloat(request.tenant_rent_share) || 0;
    const landlordRentShare = parseFloat(request.landlord_rent_share) || 0;
    const outstandingBalance = parseFloat(request.outstanding_amount) || 0;

    // Manual deductions entered by the admin
    const manualDeductions = this.deductions.reduce(
      (sum, d) => sum + (d.amount || 0),
      0,
    );

    // Outstanding fees (only counted if the checkbox is checked)
    const outstandingFeesTotal = this.includeOutstandingFees
      ? parseFloat(request.outstanding_fees_total) || 0
      : 0;

    const totalDeductions = manualDeductions + outstandingFeesTotal;

    const finalSettlement =
      securityDeposit + tenantRentShare - outstandingBalance - totalDeductions;

    // ----- Build the summary -----
    const feesRow =
      outstandingFeesTotal > 0
        ? `<div class="summary-row">
              <span>Outstanding Fees:</span>
              <span class="text-danger">-₦${this.formatNumber(outstandingFeesTotal)}</span>
           </div>`
        : "";

    const summaryHtml = `
        <div class="summary-row">
            <span>Landlord Rent Share:</span>
            <span>₦${this.formatNumber(landlordRentShare)}</span>
        </div>
        <span> --------------------------------------------------------------------- </span>
        <div class="summary-row">
            <span>Tenant Rent Share:</span>
            <span>₦${this.formatNumber(tenantRentShare)}</span>
        </div>
        <div class="summary-row">
            <span>Security Deposit:</span>
            <span>₦${this.formatNumber(securityDeposit)}</span>
        </div>
        <span> --------------------------------------------------------------------- </span>
        <div class="summary-row">
            <span>Outstanding Balance:</span>
            <span class="text-danger">-₦${this.formatNumber(outstandingBalance)}</span>
        </div>
        ${feesRow}
        <div class="summary-row">
            <span>Manual Deductions:</span>
            <span class="text-danger">-₦${this.formatNumber(manualDeductions)}</span>
        </div>
        <div class="summary-row summary-total">
            <strong>Final Settlement:</strong>
            <strong class="${finalSettlement > 0 ? "text-success" : finalSettlement < 0 ? "text-danger" : ""}">
                ₦${this.formatNumber(Math.abs(finalSettlement))}
                ${finalSettlement > 0 ? "(Refund to tenant)" : finalSettlement < 0 ? "(Tenant owes)" : "(Zero balance)"}
            </strong>
        </div>
    `;

    document.getElementById("settlementSummary").innerHTML = summaryHtml;
  },
  // ==================== SUBMIT PROCESS ====================
  submitProcess: async function () {
    const actualMoveOutDate =
      document.getElementById("actualMoveOutDate").value;
    if (!actualMoveOutDate) {
      UI.toast("Please select the actual move-out date", "warning");
      return;
    }

    const validDeductions = this.deductions.filter(
      (d) => d.type && d.amount > 0,
    );

    const confirmed = await this.confirmAction(
      "Confirm Move-out",
      "This will finalize the evacuation and free the apartment. Continue?",
      "warning",
    );
    if (!confirmed) return;

    const data = {
      request_id: this.currentRequestId,
      actual_move_out_date: actualMoveOutDate,
      deductions: validDeductions,
      include_outstanding_fees: this.includeOutstandingFees === true, // ← add
    };

    const csrf = await this.getCsrfToken("process_evacuation_form");
    if (!csrf) {
      UI.toast("Security token missing. Please refresh the page.", "danger");
      return;
    }
    data.csrf_token = csrf;
    data.token_id = "process_evacuation_form";

    const submitBtn = document.querySelector("#processModal .btn-success");
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML =
      '<i class="fas fa-spinner fa-spin"></i> Processing...';
    submitBtn.disabled = true;

    UI.loader.show("Processing final settlement...");

    try {
      const response = await fetch(
        "../backend/tenants/process_evacuation.php",
        {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          credentials: "include",
          body: JSON.stringify(data),
        },
      );

      const contentType = response.headers.get("content-type") || "";
      const responseText = await response.text();

      if (!responseText.trim()) {
        console.error("Empty response from process_evacuation.php", {
          status: response.status,
          statusText: response.statusText,
        });
        throw new Error(
          "Server returned an empty response. Please check the PHP error log.",
        );
      }

      if (!contentType.includes("application/json")) {
        console.error("Non-JSON response:", responseText);
        throw new Error(
          "Server returned an unexpected response. Please try again.",
        );
      }

      let result;
      try {
        result = JSON.parse(responseText);
      } catch (parseError) {
        console.error("Invalid JSON response:", responseText, parseError);
        throw new Error("Server returned invalid JSON. Please try again.");
      }

      if (response.ok && result.success) {
        const msg =
          result.data?.message ||
          result.message ||
          "Evacuation processed successfully";
        UI.toast(msg, "success");
        this.closeProcessModal();
        this.loadRequests(this.currentStatus);
        this.loadStatistics();
      } else {
        const errMsg =
          result.message ||
          result.data?.message ||
          "Failed to process evacuation";
        UI.toast(errMsg, "danger");
      }
    } catch (error) {
      console.error("Error:", error);
      UI.toast(
        error.message || "An error occurred. Please try again.",
        "danger",
      );
    } finally {
      submitBtn.innerHTML = originalText;
      submitBtn.disabled = false;
      UI.loader.hide();
    }
  },
  // ==================== DETAILS MODAL ====================
  viewDetails: async function (requestId) {
    UI.loader.show("Loading details...");
    try {
      const response = await fetch(
        `../backend/tenants/get_evacuation_requests.php?request_id=${requestId}`,
      );
      const data = await response.json();

      if (data.success && data.data.requests.length > 0) {
        this.showDetailsModal(data.data.requests[0]);
      } else {
        UI.toast(data.message || "Request not found", "danger");
      }
    } catch (error) {
      console.error("Error:", error);
      UI.toast("Error loading details", "danger");
    } finally {
      UI.loader.hide();
    }
  },

  showDetailsModal: function (request) {
    // ================================================================
    // STATUS PILL
    // ================================================================
    const statusMap = {
        pending_review: { label: "Pending Review", cls: "status-pending" },
        approved:       { label: "Approved",       cls: "status-approved" },
        rejected:       { label: "Rejected",       cls: "status-rejected" },
        completed:      { label: "Completed",      cls: "status-completed" },
    };
    const statusInfo = statusMap[request.status] || {
        label: request.status.replace("_", " ").toUpperCase(),
        cls: "status-default",
    };

    // ================================================================
    // DEDUCTIONS
    // ================================================================
    const deductionRows = request.deductions
        ? request.deductions
              .map(
                  (d) => `
                <div class="summary-row">
                    <span>${this.escapeHtml(d.deduction_type)}:</span>
                    <span class="text-danger">-₦${this.formatNumber(d.amount)}</span>
                    ${d.description ? `<small>${this.escapeHtml(d.description)}</small>` : ""}
                </div>
            `,
              )
              .join("")
        : "";

    // ================================================================
    // OUTSTANDING FEES
    // ================================================================
    const fees = request.outstanding_fees || [];
    const feesSection = fees.length
        ? `
            <div class="fees-info-section">
                <h4><i class="fas fa-exclamation-triangle"></i> Outstanding Fees (₦${this.formatNumber(request.outstanding_fees_total)})</h4>
                ${fees
                    .map(
                        (f) => `
                        <div class="outstanding-fee-row">
                            <div class="fee-name">
                                ${this.escapeHtml(f.fee_type_name)}
                                ${f.due_date_formatted ? `<small>Due: ${this.escapeHtml(f.due_date_formatted)}</small>` : ""}
                            </div>
                            <div>
                                <span class="fee-amount">₦${this.formatNumber(f.amount)}</span>
                                <span class="fee-status ${f.status}">${this.escapeHtml(f.status)}</span>
                            </div>
                        </div>
                    `,
                    )
                    .join("")}
            </div>
        `
        : "";

    // ================================================================
    // NARRATIVE BLOCKS (reason, notes, admin notes, rejection)
    // ================================================================
    const reasonSection = request.reason
        ? `
            <div class="detail-block">
                <div class="detail-block-header">
                    <i class="fas fa-comment-dots"></i> Tenant's Reason
                </div>
                <div class="detail-block-body">${this.escapeHtml(request.reason)}</div>
            </div>
        `
        : "";

    const notesSection = request.notes
        ? `
            <div class="detail-block">
                <div class="detail-block-header">
                    <i class="fas fa-sticky-note"></i> Tenant Notes
                </div>
                <div class="detail-block-body">${this.escapeHtml(request.notes)}</div>
            </div>
        `
        : "";

    const rejectionSection = request.rejection_reason
        ? `
            <div class="detail-block detail-block-danger">
                <div class="detail-block-header">
                    <i class="fas fa-times-circle"></i>
                    ${request.status === "pending_review" ? "Approval Withdrawal Reason" : "Rejection Reason"}
                </div>
                <div class="detail-block-body">${this.escapeHtml(request.rejection_reason)}</div>
            </div>
        `
        : "";

    const adminNotesSection = request.admin_notes
        ? `
            <div class="detail-block detail-block-info">
                <div class="detail-block-header">
                    <i class="fas fa-user-shield"></i> Admin Notes
                </div>
                <div class="detail-block-body">${this.escapeHtml(request.admin_notes)}</div>
            </div>
        `
        : "";

    // ================================================================
    // DETAIL GRID ROWS
    // ================================================================
    const detailRows = `
        <div class="detail-item">
            <div class="detail-label"><i class="fas fa-hashtag"></i> Request ID</div>
            <div class="detail-value mono">${this.escapeHtml(request.request_id)}</div>
        </div>

        <div class="detail-item">
            <div class="detail-label"><i class="fas fa-clock"></i> Submitted</div>
            <div class="detail-value">${request.created_at_formatted || "N/A"}</div>
        </div>

        <div class="detail-item">
            <div class="detail-label"><i class="fas fa-user"></i> Tenant</div>
            <div class="detail-value">${this.escapeHtml(request.tenant_name)}</div>
        </div>

        <div class="detail-item">
            <div class="detail-label"><i class="fas fa-building"></i> Property / Apartment</div>
            <div class="detail-value">
                ${this.escapeHtml(request.property_name)} · Apt ${this.escapeHtml(request.apartment_number)}
            </div>
        </div>

        <div class="detail-item">
            <div class="detail-label"><i class="fas fa-calendar-alt"></i> Requested Move-Out</div>
            <div class="detail-value">${request.requested_move_out_date_formatted || "N/A"}</div>
        </div>

        ${
            request.approved_move_out_date
                ? `
            <div class="detail-item">
                <div class="detail-label"><i class="fas fa-check-circle"></i> Approved Move-Out</div>
                <div class="detail-value accent-green">${this.formatDate(request.approved_move_out_date)}</div>
            </div>
        `
                : ""
        }
    `;

    // ================================================================
    // ASSEMBLE
    // ================================================================
    const detailsHtml = `
        <div class="details-modal">

            <!-- Status pill -->
            <div class="details-status-banner ${statusInfo.cls}">
                <span class="details-status-dot"></span>
                <span class="details-status-label">${statusInfo.label}</span>
            </div>

            <!-- Key-value grid -->
            <div class="details-grid">
                ${detailRows}
            </div>

            <!-- Narrative blocks -->
            ${reasonSection}
            ${notesSection}
            ${rejectionSection}
            ${adminNotesSection}

            <!-- Outstanding fees -->
            ${feesSection}

            <!-- Settlement summary -->
            <div class="details-settlement">
                <div class="details-settlement-header">
                    <i class="fas fa-calculator"></i> Settlement Summary
                </div>

                <div class="summary-box">
                    <div class="summary-row">
                        <strong>Security Deposit:</strong>
                        <span>₦${this.formatNumber(request.security_deposit || 0)}</span>
                    </div>

                    <div class="summary-row">
                        <strong>Outstanding Balance:</strong>
                        <span class="text-danger">₦${this.formatNumber(request.outstanding_amount || 0)}</span>
                    </div>

                    ${deductionRows}

                    ${
                        request.final_settlement_amount !== null &&
                        request.final_settlement_amount !== undefined
                            ? `
                        <div class="summary-row summary-total">
                            <strong>Final Settlement:</strong>
                            <strong class="${request.final_settlement_amount > 0 ? "text-success" : request.final_settlement_amount < 0 ? "text-danger" : ""}">
                                ₦${this.formatNumber(Math.abs(request.final_settlement_amount))}
                                ${request.final_settlement_amount > 0 ? "(Refund)" : request.final_settlement_amount < 0 ? "(Due)" : "(Zero)"}
                            </strong>
                        </div>
                    `
                            : ""
                    }
                </div>
            </div>

        </div>
    `;

    document.getElementById("detailsBody").innerHTML = detailsHtml;
    document.getElementById("detailsModal").classList.add("active");
},
  closeDetailsModal: function () {
    document.getElementById("detailsModal").classList.remove("active");
  },

  // ==================== HELPERS ====================

  /**
   * Get CSRF token for a form
   */
  getCsrfToken: async function (formName) {
    try {
      const res = await fetch(
        `../backend/utilities/get_token.php?form=${formName}`,
        {
          credentials: "include",
        },
      );
      const data = await res.json();
      return data.success ? data.token : null;
    } catch (err) {
      console.error("CSRF token fetch failed:", err);
      return null;
    }
  },

  /**
   * Promise-based wrapper around UI.confirm
   * Returns true if user confirms, false otherwise
   */
  confirmAction: function (title, message, variant = "warning") {
    return new Promise((resolve) => {
      UI.confirm(message, (ok) => resolve(!!ok), title);
    });
  },

  formatNumber: function (value) {
    if (!value && value !== 0) return "0.00";
    return new Intl.NumberFormat("en-NG", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(value);
  },

  formatDate: function (dateString) {
    if (!dateString) return "N/A";
    const date = new Date(dateString);
    if (isNaN(date.getTime())) return dateString;
    return date.toLocaleDateString("en-US", {
      year: "numeric",
      month: "short",
      day: "numeric",
    });
  },

  escapeHtml: function (text) {
    if (!text) return "";
    const div = document.createElement("div");
    div.textContent = text;
    return div.innerHTML;
  },
};

document.addEventListener("DOMContentLoaded", () => {
  EvacuationApp.init();
});
