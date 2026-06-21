// admin/scripts/maintenance.js - Complete Version

let currentPage = 1;
let currentStatus = "";
let currentPriority = "";
let currentAdmin = "";
let currentProperty = "";
let currentSearch = "";
let totalPages = 1;
let isSuperAdmin = false;
let showOnlyAssignedToMe = false;
let adminId = null;

document.addEventListener("DOMContentLoaded", function () {
  initializeMaintenance();
 
});

async function initializeMaintenance() {
  await getCurrentAdmin();
  await fetchMaintenanceRequests();
}

async function getCurrentAdmin() {
  try {
    const response = await fetch(
      "../backend/maintenance/get_current_admin.php",
    );
    const data = await response.json();
    // FIX: data is in data.message, not data.data
    if (data.success && data.message) {
      adminId = data.message.unique_id;
      isSuperAdmin = data.message.role === "Super Admin";
      console.log("Admin loaded:", adminId, "isSuperAdmin:", isSuperAdmin);
    }
  } catch (error) {
    console.error("Error getting admin:", error);
  }
}

async function fetchMaintenanceRequests() {
  try {
    let url = `../backend/maintenance/fetch_maintenance_requests.php?page=${currentPage}&limit=10`;
    if (currentStatus) url += `&status=${currentStatus}`;
    if (currentPriority) url += `&priority=${currentPriority}`;
    if (currentProperty) url += `&property_code=${currentProperty}`;
    if (currentAdmin) url += `&assigned_admin=${currentAdmin}`;
    if (currentSearch) url += `&search=${encodeURIComponent(currentSearch)}`;
    if (showOnlyAssignedToMe) url += `&assigned_to_me=true`;

    const response = await fetch(url);
    const data = await response.json();

    // FIX: data is in data.message, not data.data
    if (data.success && data.message) {
      const requests = data.message.requests || [];
      const pagination = data.message.pagination || {};
      const summary = data.message.summary || {};
      const properties = data.message.properties || [];
      const admins = data.message.admins || [];
      totalPages = pagination.total_pages || 1;
      renderMaintenanceRequests(
        requests,
        pagination,
        summary,
        properties,
        admins,
      );
    } else {
      throw new Error(data.message || "Failed to fetch maintenance requests");
    }
  } catch (error) {
    console.error("Error fetching maintenance requests:", error);

    showToast("Failed to load maintenance requests", "error");

    showToast("Failed to load maintenance requests", "error");
    showEmptyState();
  }
}

function renderMaintenanceRequests(
  requests,
  pagination,
  summary,
  properties,
  admins,
) {
  const contentArea = document.getElementById("contentArea");
  if (!contentArea) return;

  const html = `
        <div class="maintenance-container">
            <div class="page-header">
                <div>
                    <h1>Maintenance Requests</h1>
                    <p>View and manage maintenance requests from tenants</p>
                </div>
                <div class="header-actions">
                    ${
                      !isSuperAdmin
                        ? `
                    <div class="toggle-switch">
                        <label class="switch-label">
                            <input type="checkbox" id="assignedToMeToggle" ${showOnlyAssignedToMe ? "checked" : ""} onchange="toggleAssignedToMe()">
                            <span>Show only assigned to me</span>
                        </label>
                    </div>
                    `
                        : ""
                    }
                </div>
            </div>
            
            <div class="summary-cards">
                <div class="summary-card">
                    <div class="summary-icon pending"><i class="fas fa-clock"></i></div>
                    <div class="summary-info">
                        <div class="summary-value">${summary.pending || 0}</div>
                        <div class="summary-label">Pending</div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon in-progress"><i class="fas fa-spinner fa-pulse"></i></div>
                    <div class="summary-info">
                        <div class="summary-value">${summary.in_progress || 0}</div>
                        <div class="summary-label">In Progress</div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon resolved"><i class="fas fa-check-circle"></i></div>
                    <div class="summary-info">
                        <div class="summary-value">${summary.resolved || 0}</div>
                        <div class="summary-label">Resolved</div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon cancelled"><i class="fas fa-times-circle"></i></div>
                    <div class="summary-info">
                        <div class="summary-value">${summary.cancelled || 0}</div>
                        <div class="summary-label">Cancelled</div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon assigned"><i class="fas fa-user-check"></i></div>
                    <div class="summary-info">
                        <div class="summary-value">${summary.assigned_to_me || 0}</div>
                        <div class="summary-label">Assigned to Me</div>
                    </div>
                </div>
            </div>
            
            <div class="filters-section">
                <div class="filter-group">
                    <label>Property</label>
                    <select id="propertyFilter" class="filter-select" onchange="filterByProperty()">
                        <option value="">All Properties</option>
                        ${properties
                          .map(
                            (p) => `
                            <option value="${p.property_code}" ${currentProperty === p.property_code ? "selected" : ""}>${escapeHtml(p.name)}</option>
                        `,
                          )
                          .join("")}
                    </select>
                </div>
               <div class="filter-group">
    <label>Assigned Admin</label>
    <select id="adminFilter" class="filter-select" onchange="filterByAdmin()">
        <option value="">All Admins</option>
        ${admins
          .map(
            (a) => `
            <option value="${a.unique_id}" ${currentAdmin === a.unique_id ? "selected" : ""}>${escapeHtml(a.name)}</option>
        `,
          )
          .join("")}
    </select>
</div>
                <div class="filter-group">
                    <label>Status</label>
                    <select id="statusFilter" class="filter-select" onchange="filterByStatus()">
                        <option value="">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="pending_reassignment">Pending Reassignment</option>
                        <option value="in_progress">In Progress</option>
                        <option value="resolved">Resolved</option>
                        <option value="cancelled">Cancelled</option>
                        <option value="closed">Closed</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Priority</label>
                    <select id="priorityFilter" class="filter-select" onchange="filterByPriority()">
                        <option value="">All Priorities</option>
                        <option value="emergency">Emergency</option>
                        <option value="high">High</option>
                        <option value="medium">Medium</option>
                        <option value="low">Low</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Search</label>
                    <input type="text" id="searchInput" class="filter-input" placeholder="Search by issue type, description, tenant..." value="${escapeHtml(currentSearch)}" onkeyup="handleSearch(event)">
                </div>
            </div>
            
            ${
              requests.length === 0
                ? `
                <div class="empty-state">
                    <i class="fas fa-clipboard-list"></i>
                    <h3>No Maintenance Requests</h3>
                    <p>No maintenance requests found matching your criteria.</p>
                </div>
            `
                : `
                <div class="requests-grid">
                    ${requests
                      .map(
                        (request) => `
                        <div class="request-card priority-${request.priority}" onclick="viewRequestDetails(${request.request_id})">
                            <div class="request-header">
                                <div class="request-title"><i class="fas fa-tools"></i> ${escapeHtml(request.issue_type)}</div>
                                <div class="request-badges">
                                    <span class="priority-badge priority-${request.priority}">${request.priority_display}</span>
                                    <span class="status-badge status-${request.status}">${request.status_display}</span>
                                </div>
                            </div>
                            <div class="request-tenant"><i>Request ID: </i> # ${escapeHtml(request.request_id)}</div>
                            <div class="request-property"><i class="fas fa-building"></i> ${escapeHtml(request.property_info?.property_name || "N/A")} - Apt ${escapeHtml(request.property_info?.apartment_number || "N/A")}</div>
                            <div class="request-tenant"><i class="fas fa-user"></i> ${escapeHtml(request.tenant_info?.tenant_name || "Unknown Tenant")}</div>
                            <div class="request-tenant"><i>Assigned Admin: </i> ${escapeHtml(request.assigned_admin_name || "Unknown Admin")}</div>
                            <div class="request-description"><i>Request Description: </i> ${escapeHtml(request.description.substring(0, 120))}${request.description.length > 120 ? "..." : ""}</div>
                            <div class="request-meta">
                                <span><i class="fas fa-calendar"></i> ${formatDate(request.created_at)}</span>
                                ${request.days_pending > 0 ? `<span><i class="fas fa-hourglass-half"></i> ${request.days_pending} days</span>` : ""}
                            </div>
                        </div>
                    `,
                      )
                      .join("")}
                </div>
                ${renderPagination(pagination)}
            `
            }
        </div>
    `;

  contentArea.innerHTML = html;

  // ====== FIX: Restore filter values after rendering ======
  // This ensures the select dropdowns show the current filter value

  // Set property filter
  const propertyFilter = document.getElementById("propertyFilter");
  if (propertyFilter) {
    propertyFilter.value = currentProperty || "";
  }

  // Set status filter
  const statusFilter = document.getElementById("statusFilter");
  if (statusFilter) {
    statusFilter.value = currentStatus || "";
  }

  // Set priority filter
  const priorityFilter = document.getElementById("priorityFilter");
  if (priorityFilter) {
    priorityFilter.value = currentPriority || "";
  }
  // Set Admin filter
  const adminFilter = document.getElementById("adminFilter");
  if (adminFilter) {
    adminFilter.value = currentAdmin || "";
  }

  // Set search input
  const searchInput = document.getElementById("searchInput");
  if (searchInput) {
    searchInput.value = currentSearch || "";
  }

  // Set assigned to me toggle
  const assignedToggle = document.getElementById("assignedToMeToggle");
  if (assignedToggle) {
    assignedToggle.checked = showOnlyAssignedToMe || false;
  }
}

function renderPagination(pagination) {
  if (!pagination || pagination.total_pages <= 1) return "";

  let html = '<div class="pagination">';
  html += `<button class="page-btn ${currentPage === 1 ? "disabled" : ""}" onclick="goToPage(${currentPage - 1})" ${currentPage === 1 ? "disabled" : ""}><i class="fas fa-chevron-left"></i></button>`;

  for (let i = 1; i <= pagination.total_pages; i++) {
    if (
      i === 1 ||
      i === pagination.total_pages ||
      (i >= currentPage - 2 && i <= currentPage + 2)
    ) {
      html += `<button class="page-btn ${i === currentPage ? "active" : ""}" onclick="goToPage(${i})">${i}</button>`;
    } else if (i === currentPage - 3 || i === currentPage + 3) {
      html += '<span class="page-dots">...</span>';
    }
  }

  html += `<button class="page-btn ${currentPage === pagination.total_pages ? "disabled" : ""}" onclick="goToPage(${currentPage + 1})" ${currentPage === pagination.total_pages ? "disabled" : ""}><i class="fas fa-chevron-right"></i></button>`;
  html += "</div>";
  return html;
}

function filterByStatus() {
  currentStatus = document.getElementById("statusFilter")?.value || "";
  currentPage = 1;
  fetchMaintenanceRequests();
}

function filterByPriority() {
  currentPriority = document.getElementById("priorityFilter")?.value || "";
  currentPage = 1;
  fetchMaintenanceRequests();
}
function filterByAdmin() {
    const adminFilter = document.getElementById('adminFilter');
    currentAdmin = adminFilter?.value || '';
    currentPage = 1;
    fetchMaintenanceRequests();
}

function filterByProperty() {
  currentProperty = document.getElementById("propertyFilter")?.value || "";
  currentPage = 1;
  fetchMaintenanceRequests();
}

function handleSearch(event) {
  clearTimeout(window.searchTimeout);
  window.searchTimeout = setTimeout(() => {
    currentSearch = event.target.value;
    currentPage = 1;
    fetchMaintenanceRequests();
  }, 500);
}

function toggleAssignedToMe() {
  showOnlyAssignedToMe =
    document.getElementById("assignedToMeToggle")?.checked || false;
  currentPage = 1;
  fetchMaintenanceRequests();
}

function goToPage(page) {
  if (page < 1 || page > totalPages) return;
  currentPage = page;
  fetchMaintenanceRequests();
}

// ==================== ACTION FUNCTIONS ====================

async function acceptRequest(requestId) {
  const confirmed = await showConfirmModal({
    title: "Accept Maintenance Request",
    message:
      "Are you sure you want to accept this maintenance request? You will be responsible for resolving it.",
    confirmText: "Yes, Accept",
    cancelText: "Cancel",
    type: "info",
  });

  if (!confirmed) return;

  try {
    const response = await fetch(
      "../backend/maintenance/accept_maintenance_request.php",
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ request_id: requestId }),
      },
    );
    const data = await response.json();

    if (data.success) {
      showToast("Request accepted successfully", "success");
      fetchMaintenanceRequests();
      closeRequestDetailsModal();
    } else {
      throw new Error(data.message || "Failed to accept request");
    }
  } catch (error) {
    console.error("Error accepting request:", error);
    showToast(error.message, "error");
  }
}

async function rejectRequest(requestId) {
  const rejectionReason = await promptReason(
    "Rejection Reason",
    "Please provide a reason for rejecting this request:",
  );

  console.log("Rejection reason received:", rejectionReason);

  if (rejectionReason === null) return;
  if (!rejectionReason.trim()) {
    showToast("Rejection reason is required", "error");
    return;
  }

  const confirmed = await showConfirmModal({
    title: "Reject Maintenance Request",
    message:
      "Are you sure you want to reject this request? It will be sent to Super Admin for reassignment.",
    confirmText: "Yes, Reject",
    cancelText: "Cancel",
    type: "warning",
  });

  console.log("Confirmation received:", confirmed);

  if (!confirmed) return;

  try {
    const response = await fetch(
      "../backend/maintenance/reject_maintenance_request.php",
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          request_id: requestId,
          rejection_reason: rejectionReason,
        }),
      },
    );
    const data = await response.json();

    if (data.success) {
      showToast("Request rejected. Super Admin will reassign.", "success");
      fetchMaintenanceRequests();
      closeRequestDetailsModal();
    } else {
      throw new Error(data.message || "Failed to reject request");
    }
  } catch (error) {
    console.error("Error rejecting request:", error);
    showToast(error.message, "error");
  }
}

async function reassignRequest(requestId) {
  if (!isSuperAdmin) {
    showToast("Only Super Admin can reassign requests", "error");
    return;
  }

  try {
    const response = await fetch(
      "../backend/maintenance/get_available_admins.php",
    );
    const data = await response.json();

    console.log("Available admins response:", data);

    // FIX: According to your response, admins are in data.message array
    let admins = [];
    if (data.success && data.message && Array.isArray(data.message)) {
      admins = data.message;
    } else if (data.success && data.data && Array.isArray(data.data)) {
      admins = data.data;
    }

    console.log("Parsed admins:", admins);

    if (admins.length === 0) {
      showToast("No available admins found", "error");
      return;
    }

    const adminOptions = admins
      .map(
        (admin) =>
          `<option value="${admin.unique_id}">${escapeHtml(admin.firstname)} ${escapeHtml(admin.lastname)} (${admin.active_requests || 0} active requests)</option>`,
      )
      .join("");

    console.log("Admin options:", adminOptions);

    const selectedAdminId = await selectAdmin(adminOptions);
    console.log("Selected admin ID:", selectedAdminId);

    if (!selectedAdminId) return;

    const reassignReason = await promptReason(
      "Reassignment Notes",
      "Reason for reassignment:",
      "textarea",
    );

    const confirmed = await showConfirmModal({
      title: "Reassign Request",
      message: `Are you sure you want to reassign this request?`,
      confirmText: "Yes, Reassign",
      cancelText: "Cancel",
      type: "info",
    });

    if (!confirmed) return;

    const reassignResponse = await fetch(
      "../backend/maintenance/superadmin_reassign.php",
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          request_id: requestId,
          new_admin_id: selectedAdminId,
          reassignment_notes: reassignReason || "Reassigned by Super Admin",
        }),
      },
    );
    const reassignData = await reassignResponse.json();

    if (reassignData.success) {
      showToast("Request reassigned successfully", "success");
      fetchMaintenanceRequests();
      closeRequestDetailsModal();
    } else {
      throw new Error(reassignData.message || "Failed to reassign request");
    }
  } catch (error) {
    console.error("Error reassigning request:", error);
    showToast(error.message, "error");
  }
}

async function updateRequestStatus(requestId, newStatus) {
  let resolutionNotes = null;
  if (newStatus === "resolved") {
    resolutionNotes = await promptReason(
      "Resolution Notes",
      "Please provide details about how the issue was resolved:",
      "textarea",
    );
    if (resolutionNotes === null) return;
    if (!resolutionNotes.trim()) {
      showToast("Resolution notes are required", "error");
      return;
    }
  }

  const confirmed = await showConfirmModal({
    title: `Mark as ${newStatus.replace("_", " ")}`,
    message: `Are you sure you want to mark this request as ${newStatus.replace("_", " ")}?`,
    confirmText: "Yes",
    cancelText: "Cancel",
    type: "info",
  });

  if (!confirmed) return;

  const payload = { request_id: requestId, status: newStatus };
  if (resolutionNotes) payload.resolution_notes = resolutionNotes;

  try {
    const response = await fetch(
      "../backend/maintenance/update_maintenance_status.php",
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      },
    );
    const data = await response.json();

    if (data.success) {
      showToast(`Request marked as ${newStatus.replace("_", " ")}`, "success");
      fetchMaintenanceRequests();
      closeRequestDetailsModal();
    } else {
      throw new Error(data.message || "Failed to update status");
    }
  } catch (error) {
    console.error("Error updating status:", error);
    showToast(error.message, "error");
  }
}

// ==================== VIEW REQUEST DETAILS ====================

async function viewRequestDetails(requestId) {
  try {
    showToast("Loading request details...", "info");

    const response = await fetch(
      `../backend/maintenance/fetch_maintenance_request_details.php?request_id=${requestId}`,
    );
    const data = await response.json();

    // FIX: The data is in data.message, not data.data
    if (data.success && data.message) {
      const requestData = data.message;
      showRequestDetailsModal(requestData);
    } else {
      throw new Error(data.message || "Failed to load request details");
    }
  } catch (error) {
    console.error("Error fetching request details:", error);
    showToast(error.message, "error");
  }
}

function showRequestDetailsModal(requestData) {
    let modal = document.getElementById("requestDetailsModal");
    if (!modal) {
        modal = document.createElement("div");
        modal.id = "requestDetailsModal";
        modal.className = "modal";
        modal.innerHTML = `
            <div class="modal-content" style="max-width: 800px;">
                <div class="modal-header">
                    <h3><i class="fas fa-clipboard-list"></i> Maintenance Request Details</h3>
                    <button class="modal-close" onclick="closeRequestDetailsModal()">&times;</button>
                </div>
                <div class="modal-body" id="requestDetailsBody"></div>
                <div class="modal-footer" id="requestDetailsFooter">
                    <button class="btn-secondary" onclick="closeRequestDetailsModal()">Close</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }

    const request = requestData.request;
    const timeline = requestData.timeline || [];

    // FIX: Use request.assigned_admin_id to check assignment
    const isAssignedToMe = request.assigned_admin_id == adminId;
    const isPendingReassignment = request.status === "pending_reassignment";
    const isPending = request.status === "pending";
    const isInProgress = request.status === "in_progress";
    const hasAccepted = request.assigned_to !== null;
    const slaBreached = request.sla_breached === 1 || request.sla_breached === true;

    console.log("Action Check:", {
        isAssignedToMe,
        isPending,
        isInProgress,
        hasAccepted,
        adminId,
        assigned_admin_id: request.assigned_admin_id,
        assigned_to: request.assigned_to,
        slaBreached: slaBreached,
    });

    let actionButtons = "";

    if (isPendingReassignment && isSuperAdmin) {
        actionButtons = `<button class="btn-primary" onclick="reassignRequest(${request.request_id})"><i class="fas fa-user-plus"></i> Reassign Request</button>`;
    } else if (isPending && isAssignedToMe && !hasAccepted) {
        actionButtons = `
            <button class="btn-success" onclick="acceptRequest(${request.request_id})"><i class="fas fa-check-circle"></i> Accept</button>
            <button class="btn-danger" onclick="rejectRequest(${request.request_id})"><i class="fas fa-times-circle"></i> Reject</button>
        `;
    } else if (isInProgress && isAssignedToMe && request.assigned_to == adminId) {
        actionButtons = `<button class="btn-primary" onclick="updateRequestStatus(${request.request_id}, 'resolved')"><i class="fas fa-check-circle"></i> Mark as Resolved</button>`;
    } else if (isPending && isSuperAdmin && !request.assigned_to) {
        actionButtons = `<button class="btn-primary" onclick="reassignRequest(${request.request_id})"><i class="fas fa-user-plus"></i> Assign to Admin</button>`;
    }

    if (isSuperAdmin && (isPending || isInProgress)) {
        actionButtons += `<button class="btn-warning" onclick="reassignRequest(${request.request_id})"><i class="fas fa-exchange-alt"></i> Reassign</button>`;
    }

    // ==================== SLA BREACH NOTIFIER ====================
    if (isSuperAdmin && slaBreached) {
        actionButtons += `<button class="btn-danger" onclick="queryAdmin(${request.request_id})" style="background: #dc2626; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer;">
            <i class="fas fa-question-circle"></i> Query Admin
        </button>`;
    }

    const footer = document.getElementById("requestDetailsFooter");
    if (footer) {
        footer.innerHTML = `<button class="btn-secondary" onclick="closeRequestDetailsModal()">Close</button>${actionButtons}`;
    }

    const body = document.getElementById("requestDetailsBody");
    body.innerHTML = `
        <div class="detail-section">
            <h4><i class="fas fa-info-circle"></i> Request Information</h4>
            <div class="detail-grid">
                <div class="detail-item"><span class="detail-label">Request ID:</span><span class="detail-value">#${request.request_id}</span></div>
                <div class="detail-item"><span class="detail-label">Issue Type:</span><span class="detail-value">${escapeHtml(request.issue_type)}</span></div>
                <div class="detail-item"><span class="detail-label">Priority:</span><span class="detail-value"><span class="priority-badge priority-${request.priority}">${request.priority_display}</span></span></div>
                <div class="detail-item"><span class="detail-label">Status:</span><span class="detail-value"><span class="status-badge status-${request.status}">${request.status_display}</span></span></div>
                <div class="detail-item"><span class="detail-label">Created:</span><span class="detail-value">${formatDateTime(request.created_at)}</span></div>
                <div class="detail-item"><span class="detail-label">Days Pending:</span><span class="detail-value">${request.days_pending || 0} days</span></div>
                <div class="detail-item"><span class="detail-label">SLA Breached:</span><span class="detail-value">
                    ${slaBreached 
                        ? `<span style="color: #dc2626; font-weight: 600;"><i class="fas fa-exclamation-triangle"></i> YES</span>` 
                        : `<span style="color: #10b981; font-weight: 600;"><i class="fas fa-check-circle"></i> NO</span>`}
                </span></div>
                ${
                  request.assigned_agent_name &&
                  request.assigned_agent_name !== "Not assigned"
                    ? `
                <div class="detail-item"><span class="detail-label">Assigned Agent:</span><span class="detail-value">${escapeHtml(request.assigned_agent_name)}</span></div>
                `
                    : ""
                }
                ${
                  request.assigned_admin_name &&
                  request.assigned_admin_name !== "Not assigned"
                    ? `
                <div class="detail-item"><span class="detail-label">Assigned Admin:</span><span class="detail-value">${escapeHtml(request.assigned_admin_name)}</span></div>
                `
                    : ""
                }
            </div>
        </div>
        
        ${
          request.rejection_reason
            ? `
        <div class="detail-section rejected">
            <h4><i class="fas fa-times-circle"></i> Rejection Information</h4>
            <div class="detail-item"><span class="detail-label">Reason:</span><span class="detail-value">${escapeHtml(request.rejection_reason)}</span></div>
            <div class="detail-item"><span class="detail-label">Rejected At:</span><span class="detail-value">${formatDateTime(request.rejected_at)}</span></div>
        </div>
        `
            : ""
        }

        
        <div class="detail-section">
            <h4><i class="fas fa-user"></i> Tenant Information</h4>
            <div class="detail-grid">
                <div class="detail-item"><span class="detail-label">Tenant Name:</span><span class="detail-value">${escapeHtml(request.tenant_info?.tenant_name || "N/A")}</span></div>
                <div class="detail-item"><span class="detail-label">Email:</span><span class="detail-value">${escapeHtml(request.tenant_info?.email || "N/A")}</span></div>
                <div class="detail-item"><span class="detail-label">Phone:</span><span class="detail-value">${escapeHtml(request.tenant_info?.phone || "N/A")}</span></div>
            </div>
        </div>
        
        <div class="detail-section">
            <h4><i class="fas fa-building"></i> Property Information</h4>
            <div class="detail-grid">
                <div class="detail-item"><span class="detail-label">Property:</span><span class="detail-value">${escapeHtml(request.property_info?.property_name || "N/A")}</span></div>
                <div class="detail-item"><span class="detail-label">Apartment:</span><span class="detail-value">${escapeHtml(request.property_info?.apartment_number || "N/A")}</span></div>
            </div>
        </div>
        
        <div class="detail-section">
            <h4><i class="fas fa-align-left"></i> Description</h4>
            <div class="description-content">${escapeHtml(request.description)}</div>
        </div>
        
        <div class="detail-section">
            <h4><i class="fas fa-history"></i> Activity History</h4>
            <div class="timeline">
                ${renderTimeline(timeline)}
            </div>
        </div>
    `;

    modal.classList.add("active");
}

// ==================== QUERY ADMIN FUNCTION ====================
async function queryAdmin(requestId) {
    const queryMessage = await promptReason('Query Admin', 'Enter your question/reason for querying the assigned admin about the SLA breach:');
    
    if (!queryMessage || !queryMessage.trim()) {
        showToast('Query message is required', 'error');
        return;
    }
    
    const confirmed = await showConfirmModal({
        title: 'Send Query to Admin',
        message: `Are you sure you want to send this query to the assigned admin?\n\n"${queryMessage}"`,
        confirmText: 'Yes, Send',
        cancelText: 'Cancel',
        type: 'info'
    });
    
    if (!confirmed) return;
    
    try {
        const response = await fetch('../backend/maintenance/query_admin.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                request_id: requestId, 
                query_message: queryMessage 
            })
        });
        const data = await response.json();
        
        if (data.success) {
            showToast('Query sent to admin successfully', 'success');
            // Refresh the request details to show the query in timeline
            await viewRequestDetails(requestId);
        } else {
            throw new Error(data.message || 'Failed to send query');
        }
    } catch (error) {
        console.error('Error sending query:', error);
        showToast(error.message, 'error');
    }
}
function renderTimeline(timeline) {
  if (!timeline || timeline.length === 0) {
    return '<p class="text-muted">No activity history available</p>';
  }

  return timeline
    .map(
      (item) => `
        <div class="timeline-item">
            <div class="timeline-date">${item.formatted_date || formatDateTime(item.date)}</div>
            <div class="timeline-title">${escapeHtml(item.action)}</div>
            ${item.description ? `<div class="timeline-description">${escapeHtml(item.description)}</div>` : ""}
            <div class="timeline-user">By: ${escapeHtml(item.user)}</div>
        </div>
    `,
    )
    .join("");
}

function closeRequestDetailsModal() {
  const modal = document.getElementById("requestDetailsModal");
  if (modal) modal.classList.remove("active");
}

// ==================== HELPER FUNCTIONS ====================

function promptReason(title, message, type = "text") {
  return new Promise((resolve) => {
    // Remove existing modal if any
    const existingModal = document.getElementById("reasonModal");
    if (existingModal) existingModal.remove();

    const modalHtml = `
            <div id="reasonModal" class="custom-modal active" style="z-index: 10070;">
                <div class="custom-modal-content" style="max-width: 450px;">
                    <div class="custom-modal-header">
                        <h3>${title}</h3>
                        <button class="custom-modal-close" id="closeReasonModalBtn">&times;</button>
                    </div>
                    <div class="custom-modal-body">
                        <p>${message}</p>
                        ${
                          type === "textarea"
                            ? `<textarea id="reasonInput" class="form-textarea" rows="3" placeholder="Enter reason here..."></textarea>`
                            : `<input type="text" id="reasonInput" class="form-input" placeholder="Enter reason here...">`
                        }
                    </div>
                    <div class="custom-modal-footer">
                        <button class="custom-btn-cancel" id="reasonCancelBtn">Cancel</button>
                        <button class="custom-btn-confirm" id="reasonSubmitBtn">Submit</button>
                    </div>
                </div>
            </div>
        `;

    document.body.insertAdjacentHTML("beforeend", modalHtml);

    const modal = document.getElementById("reasonModal");
    const reasonInput = document.getElementById("reasonInput");
    const submitBtn = document.getElementById("reasonSubmitBtn");
    const cancelBtn = document.getElementById("reasonCancelBtn");
    const closeBtn = document.getElementById("closeReasonModalBtn");

    const cleanup = () => {
      if (modal) modal.remove();
    };

    const handleSubmit = () => {
      const value = reasonInput?.value || "";
      cleanup();
      resolve(value);
    };

    const handleCancel = () => {
      cleanup();
      resolve(null);
    };

    submitBtn.onclick = handleSubmit;
    cancelBtn.onclick = handleCancel;
    closeBtn.onclick = handleCancel;
    modal.onclick = (e) => {
      if (e.target === modal) handleCancel();
    };

    // Focus on input
    reasonInput?.focus();
  });
}

function selectAdmin(adminOptions) {
  return new Promise((resolve) => {
    // Remove existing modal if any
    const existingModal = document.getElementById("adminSelectModal");
    if (existingModal) existingModal.remove();

    console.log("Creating admin select modal with options:", adminOptions);

    const modalHtml = `
            <div id="adminSelectModal" class="custom-modal active" style="z-index: 10070;">
                <div class="custom-modal-content" style="max-width: 450px;">
                    <div class="custom-modal-header">
                        <h3>Select Admin</h3>
                        <button class="custom-modal-close" id="closeAdminSelectBtn">&times;</button>
                    </div>
                    <div class="custom-modal-body">
                        <p>Choose an admin to reassign this request to:</p>
                        <select id="adminSelect" class="form-select" style="width: 100%; padding: 10px; margin-top: 10px;">
                            <option value="">-- Select Admin --</option>
                            ${adminOptions}
                        </select>
                    </div>
                    <div class="custom-modal-footer">
                        <button class="custom-btn-cancel" id="adminSelectCancelBtn">Cancel</button>
                        <button class="custom-btn-confirm" id="adminSelectConfirmBtn">Confirm</button>
                    </div>
                </div>
            </div>
        `;

    document.body.insertAdjacentHTML("beforeend", modalHtml);

    const modal = document.getElementById("adminSelectModal");
    const adminSelect = document.getElementById("adminSelect");
    const confirmBtn = document.getElementById("adminSelectConfirmBtn");
    const cancelBtn = document.getElementById("adminSelectCancelBtn");
    const closeBtn = document.getElementById("closeAdminSelectBtn");

    // Verify modal elements exist
    if (!modal) console.error("Modal not found");
    if (!adminSelect) console.error("adminSelect element not found");
    if (!confirmBtn) console.error("confirmBtn element not found");

    const cleanup = () => {
      if (modal) modal.remove();
    };

    const handleConfirm = () => {
      const value = adminSelect?.value || "";
      console.log("Confirm clicked, selected value:", value);
      cleanup();
      resolve(value);
    };

    const handleCancel = () => {
      console.log("Cancel clicked");
      cleanup();
      resolve(null);
    };

    confirmBtn.onclick = handleConfirm;
    cancelBtn.onclick = handleCancel;
    closeBtn.onclick = handleCancel;
    modal.onclick = (e) => {
      if (e.target === modal) handleCancel();
    };
  });
}

function showEmptyState() {
  const contentArea = document.getElementById("contentArea");
  if (!contentArea) return;

  contentArea.innerHTML = `
        <div class="maintenance-container">
            <div class="page-header"><div><h1>Maintenance Requests</h1><p>View and manage maintenance requests from tenants</p></div></div>
            <div class="empty-state"><i class="fas fa-clipboard-list"></i><h3>No Maintenance Requests</h3><p>No maintenance requests found for your properties.</p></div>
        </div>
    `;
}

function showConfirmModal(options) {
  return new Promise((resolve) => {
    // Remove any existing confirm modal first
    const existingModal = document.getElementById("customConfirmModal");
    if (existingModal) {
      existingModal.remove();
    }

    // Create new modal
    const modal = document.createElement("div");
    modal.id = "customConfirmModal";
    modal.className = "custom-modal";
    modal.style.zIndex = "10060";
    modal.innerHTML = `
            <div class="custom-modal-content">
                <div class="custom-modal-header">
                    <h3 id="confirmTitle">${options.title || "Confirm Action"}</h3>
                    <button class="custom-modal-close" onclick="closeConfirmModal()">&times;</button>
                </div>
                <div class="custom-modal-body">
                    <p id="confirmMessage">${options.message || "Are you sure?"}</p>
                </div>
                <div class="custom-modal-footer">
                    <button class="custom-btn-cancel" id="confirmCancelBtn">${options.cancelText || "Cancel"}</button>
                    <button class="custom-btn-confirm" id="confirmConfirmBtn">${options.confirmText || "Confirm"}</button>
                </div>
            </div>
        `;

    document.body.appendChild(modal);

    const confirmBtn = document.getElementById("confirmConfirmBtn");
    const cancelBtn = document.getElementById("confirmCancelBtn");
    const closeBtn = modal.querySelector(".custom-modal-close");

    const cleanup = () => {
      modal.remove();
      resolve(false);
    };

    const handleConfirm = () => {
      modal.remove();
      resolve(true);
    };

    const handleCancel = () => {
      modal.remove();
      resolve(false);
    };

    confirmBtn.onclick = handleConfirm;
    cancelBtn.onclick = handleCancel;
    closeBtn.onclick = handleCancel;
    modal.onclick = (e) => {
      if (e.target === modal) handleCancel();
    };

    modal.classList.add("active");
  });
}

function closeConfirmModal() {
  const modal = document.getElementById("customConfirmModal");
  if (modal) modal.remove();
}

function closeConfirmModal() {
  const modal = document.getElementById("customConfirmModal");
  if (modal) modal.classList.remove("active");
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

function formatDateTime(dateString) {
  if (!dateString) return "N/A";
  try {
    const date = new Date(dateString);
    return date.toLocaleDateString("en-US", {
      year: "numeric",
      month: "short",
      day: "numeric",
      hour: "2-digit",
      minute: "2-digit",
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
// ==================== CUSTOM TOAST NOTIFICATION ====================
function showToast(message, type = "info") {
  // Remove existing toast if any
  const existingToast = document.getElementById("customToast");
  if (existingToast) {
    existingToast.remove();
  }

  // Create toast element
  const toast = document.createElement("div");
  toast.id = "customToast";
  toast.style.cssText = `
        position: fixed;
        bottom: 20px;
        right: 20px;
        min-width: 250px;
        max-width: 350px;
        padding: 12px 20px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        z-index: 10080;
        display: flex;
        align-items: center;
        gap: 10px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        animation: slideInRight 0.3s ease;
        font-family: 'Inter', sans-serif;
    `;

  // Set styles based on type
  let icon, bgColor, textColor;
  switch (type) {
    case "success":
      icon = '<i class="fas fa-check-circle"></i>';
      bgColor = "#10b981";
      textColor = "#ffffff";
      break;
    case "error":
      icon = '<i class="fas fa-exclamation-circle"></i>';
      bgColor = "#ef4444";
      textColor = "#ffffff";
      break;
    case "warning":
      icon = '<i class="fas fa-exclamation-triangle"></i>';
      bgColor = "#f59e0b";
      textColor = "#ffffff";
      break;
    default:
      icon = '<i class="fas fa-info-circle"></i>';
      bgColor = "#3b82f6";
      textColor = "#ffffff";
  }

  toast.style.backgroundColor = bgColor;
  toast.style.color = textColor;
  toast.innerHTML = `${icon} <span>${message}</span>`;

  document.body.appendChild(toast);

  // Remove after 3 seconds
  setTimeout(() => {
    toast.style.animation = "slideOutRight 0.3s ease";
    setTimeout(() => {
      if (toast && toast.remove) toast.remove();
    }, 300);
  }, 3000);
}

// Add CSS animations if not already present
const style = document.createElement("style");
style.textContent = `
    @keyframes slideInRight {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    @keyframes slideOutRight {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);
