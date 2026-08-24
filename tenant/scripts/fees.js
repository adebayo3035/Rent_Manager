// tenant-fees.js
let currentUser = null;
let tenantFees = [];
let currentFilter = "all"; // all, pending, paid, overdue
let currentTab = "all"; // all, recurring, one-time
let feeTypes = [];
let applicableFees = [];

document.addEventListener("DOMContentLoaded", function () {
    initializeFees();
});

async function initializeFees() {
    if (window.currentUser) {
        currentUser = window.currentUser;
        await loadFeeTypes();
        await loadApplicableFees();
        await loadTenantFees();
    } else {
        window.addEventListener("userDataLoaded", async function (e) {
            currentUser = e.detail;
            await loadFeeTypes();
            await loadApplicableFees();
            await loadTenantFees();
        });

        // Fallback if event doesn't fire
        setTimeout(async () => {
            if (!window.currentUser && !currentUser) {
                await loadFeeTypes();
                await loadApplicableFees();
                await loadTenantFees();
            }
        }, 1000);
    }
}

// ==================== LOAD APPLICABLE FEES ====================
async function loadApplicableFees() {
    try {
        const response = await fetch("../backend/fees/fetch_applicable_fees.php");
        const data = await response.json();

        if (data.success) {
            applicableFees = data.data?.applicable_fees || [];
            console.log("Applicable fees loaded:", applicableFees);
        }
    } catch (error) {
        console.error("Error loading applicable fees:", error);
    }
}

// ==================== OPEN APPLICABLE FEES MODAL ====================
function openApplicableFeesModal() {
    const modalBody = document.getElementById("applicableFeesBody");
    if (!modalBody) return;

    if (applicableFees.length === 0) {
        modalBody.innerHTML = `
            <div class="empty-state" style="padding: 40px; text-align: center;">
                <i class="fas fa-check-circle" style="font-size: 48px; color: #10b981; margin-bottom: 15px;"></i>
                <h4>No Applicable Fees</h4>
                <p style="color: #666;">There are currently no fees applicable to your apartment.</p>
            </div>
        `;
    } else {
        // ✅ Group fees by type AND activity status
        const activeMandatoryFees = applicableFees.filter((f) => f.is_mandatory === true && f.is_active_in_property === true);
        const activeOptionalFees = applicableFees.filter((f) => f.is_mandatory === false && f.is_active_in_property === true);
        const inactiveMandatoryFees = applicableFees.filter((f) => f.is_mandatory === true && f.is_active_in_property === false);
        const inactiveOptionalFees = applicableFees.filter((f) => f.is_mandatory === false && f.is_active_in_property === false);
        
        const totalActive = activeMandatoryFees.length + activeOptionalFees.length;
        const totalInactive = inactiveMandatoryFees.length + inactiveOptionalFees.length;

        let html = `
            <div class="applicable-fees-summary" style="background: #f8fafc; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px;">
                    <div style="text-align: center;">
                        <div style="font-size: 24px; font-weight: 700; color: #1a1f36;">${applicableFees.length}</div>
                        <div style="font-size: 12px; color: #666;">Total Fees</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 24px; font-weight: 700; color: #dc2626;">${activeMandatoryFees.length + inactiveMandatoryFees.length}</div>
                        <div style="font-size: 12px; color: #666;">Mandatory</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 24px; font-weight: 700; color: #3b82f6;">${activeOptionalFees.length + inactiveOptionalFees.length}</div>
                        <div style="font-size: 12px; color: #666;">Optional</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 24px; font-weight: 700; color: #ef4444;">${totalInactive}</div>
                        <div style="font-size: 12px; color: #666;">Deactivated</div>
                    </div>
                </div>
            </div>
        `;

        // ==================== ACTIVE MANDATORY FEES ====================
        if (activeMandatoryFees.length > 0) {
            html += `
                <div class="fee-type-section" style="margin-bottom: 20px;">
                    <h4 style="color: #dc2626; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-exclamation-circle"></i> Mandatory Fees
                        <span style="font-size: 12px; font-weight: normal; color: #666; margin-left: 8px;">(${activeMandatoryFees.length} active)</span>
                    </h4>
                    ${activeMandatoryFees.map(fee => `
                        <div class="applicable-fee-item" style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px 16px; margin-bottom: 10px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                                <div>
                                    <div style="font-weight: 600; color: #1a1f36;">${escapeHtml(fee.fee_name)}</div>
                                    <div style="font-size: 12px; color: #666; margin-top: 2px;">
                                        <span>${escapeHtml(fee.fee_code || "")}</span>
                                        ${fee.is_recurring ? `<span style="margin-left: 10px; background: #dbeafe; padding: 2px 8px; border-radius: 12px; font-size: 10px; color: #3b82f6;">${escapeHtml(fee.recurrence_period || "Recurring")}</span>` : ""}
                                        <span style="margin-left: 10px; background: #dcfce7; padding: 2px 8px; border-radius: 12px; font-size: 10px; color: #16a34a;">Active</span>
                                    </div>
                                </div>
                                <div style="text-align: right;">
                                    <div style="font-weight: 700; color: #dc2626;">₦${formatNumber(fee.amount || 0)}</div>
                                </div>
                            </div>
                        </div>
                    `).join("")}
                </div>
            `;
        }

        // ==================== INACTIVE MANDATORY FEES ====================
        if (inactiveMandatoryFees.length > 0) {
            html += `
                <div class="fee-type-section" style="margin-bottom: 20px;">
                    <h4 style="color: #ef4444; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-exclamation-triangle"></i> Inactive Mandatory Fees
                        <span style="font-size: 12px; font-weight: normal; color: #666; margin-left: 8px;">(${inactiveMandatoryFees.length} deactivated)</span>
                    </h4>
                    ${inactiveMandatoryFees.map(fee => `
                        <div class="applicable-fee-item" style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px 16px; margin-bottom: 10px; opacity: 0.7;">
                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                                <div>
                                    <div style="font-weight: 600; color: #6b7280; text-decoration: line-through;">${escapeHtml(fee.fee_name)}</div>
                                    <div style="font-size: 12px; color: #6b7280; margin-top: 2px;">
                                        <span>${escapeHtml(fee.fee_code || "")}</span>
                                        ${fee.is_recurring ? `<span style="margin-left: 10px; background: #f3f4f6; padding: 2px 8px; border-radius: 12px; font-size: 10px; color: #6b7280;">${escapeHtml(fee.recurrence_period || "Recurring")}</span>` : ""}
                                        <span style="margin-left: 10px; background: #fee2e2; padding: 2px 8px; border-radius: 12px; font-size: 10px; color: #dc2626;">
                                            <i class="fas fa-ban"></i> Deactivated
                                        </span>
                                    </div>
                                </div>
                                <div style="text-align: right;">
                                    <div style="font-weight: 700; color: #9ca3af;">₦${formatNumber(fee.amount || 0)}</div>
                                </div>
                            </div>
                            ${fee.effective_to ? `
                                <div style="font-size: 11px; color: #ef4444; margin-top: 6px; padding-top: 6px; border-top: 1px solid #fecaca;">
                                    <i class="fas fa-calendar-times"></i> Deactivated since: ${formatDate(fee.effective_to)}
                                </div>
                            ` : ''}
                        </div>
                    `).join("")}
                </div>
            `;
        }

        // ==================== ACTIVE OPTIONAL FEES ====================
        if (activeOptionalFees.length > 0) {
            html += `
                <div class="fee-type-section" style="margin-bottom: 20px;">
                    <h4 style="color: #3b82f6; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-info-circle"></i> Optional Fees
                        <span style="font-size: 12px; font-weight: normal; color: #666; margin-left: 8px;">(${activeOptionalFees.length} active)</span>
                    </h4>
                    ${activeOptionalFees.map(fee => `
                        <div class="applicable-fee-item" style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 12px 16px; margin-bottom: 10px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                                <div>
                                    <div style="font-weight: 600; color: #1a1f36;">${escapeHtml(fee.fee_name)}</div>
                                    <div style="font-size: 12px; color: #666; margin-top: 2px;">
                                        <span>${escapeHtml(fee.fee_code || "")}</span>
                                        ${fee.is_recurring ? `<span style="margin-left: 10px; background: #dbeafe; padding: 2px 8px; border-radius: 12px; font-size: 10px; color: #3b82f6;">${escapeHtml(fee.recurrence_period || "Recurring")}</span>` : ""}
                                        <span style="margin-left: 10px; background: #dcfce7; padding: 2px 8px; border-radius: 12px; font-size: 10px; color: #16a34a;">Active</span>
                                    </div>
                                </div>
                                <div style="text-align: right;">
                                    <div style="font-weight: 700; color: #3b82f6;">₦${formatNumber(fee.amount || 0)}</div>
                                </div>
                            </div>
                        </div>
                    `).join("")}
                </div>
            `;
        }

        // ==================== INACTIVE OPTIONAL FEES ====================
        if (inactiveOptionalFees.length > 0) {
            html += `
                <div class="fee-type-section" style="margin-bottom: 20px;">
                    <h4 style="color: #ef4444; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-exclamation-triangle"></i> Inactive Optional Fees
                        <span style="font-size: 12px; font-weight: normal; color: #666; margin-left: 8px;">(${inactiveOptionalFees.length} deactivated)</span>
                    </h4>
                    ${inactiveOptionalFees.map(fee => `
                        <div class="applicable-fee-item" style="background: #f3f4f6; border: 1px solid #d1d5db; border-radius: 8px; padding: 12px 16px; margin-bottom: 10px; opacity: 0.7;">
                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                                <div>
                                    <div style="font-weight: 600; color: #6b7280; text-decoration: line-through;">${escapeHtml(fee.fee_name)}</div>
                                    <div style="font-size: 12px; color: #6b7280; margin-top: 2px;">
                                        <span>${escapeHtml(fee.fee_code || "")}</span>
                                        ${fee.is_recurring ? `<span style="margin-left: 10px; background: #f3f4f6; padding: 2px 8px; border-radius: 12px; font-size: 10px; color: #6b7280;">${escapeHtml(fee.recurrence_period || "Recurring")}</span>` : ""}
                                        <span style="margin-left: 10px; background: #fee2e2; padding: 2px 8px; border-radius: 12px; font-size: 10px; color: #dc2626;">
                                            <i class="fas fa-ban"></i> Deactivated
                                        </span>
                                    </div>
                                </div>
                                <div style="text-align: right;">
                                    <div style="font-weight: 700; color: #9ca3af;">₦${formatNumber(fee.amount || 0)}</div>
                                </div>
                            </div>
                            ${fee.effective_to ? `
                                <div style="font-size: 11px; color: #ef4444; margin-top: 6px; padding-top: 6px; border-top: 1px solid #d1d5db;">
                                    <i class="fas fa-calendar-times"></i> Deactivated since: ${formatDate(fee.effective_to)}
                                </div>
                            ` : ''}
                        </div>
                    `).join("")}
                </div>
            `;
        }

        // ==================== SHOW MESSAGE WHEN NO ACTIVE FEES ====================
        if (totalActive === 0 && applicableFees.length > 0) {
            html += `
                <div style="background: #fef3c7; border: 1px solid #f59e0b; border-radius: 8px; padding: 15px; text-align: center;">
                    <i class="fas fa-exclamation-triangle" style="color: #f59e0b; font-size: 20px; display: block; margin-bottom: 8px;"></i>
                    <p style="color: #92400e; margin: 0;">
                        <strong>All fees are currently deactivated.</strong><br>
                        No active fees are available for this apartment at this time.
                    </p>
                </div>
            `;
        }

        modalBody.innerHTML = html;
    }

    openModal("applicableFeesModal");
}

// ==================== LOAD FEE TYPES ====================
async function loadFeeTypes() {
    try {
        const response = await fetch("../backend/fees/fetch_fee_types.php");
        const data = await response.json();

        if (data.success) {
            feeTypes = data.data?.fee_types || [];
        }
    } catch (error) {
        console.error("Error loading fee types:", error);
    }
}

// ==================== LOAD TENANT FEES ====================
async function loadTenantFees() {
    try {
        // Build query parameters
        const params = new URLSearchParams();
        
        // Only add status filter if it's not 'all'
        if (currentFilter && currentFilter !== 'all') {
            params.append('status', currentFilter);
        }
        
        // Add tab filter (is_recurring)
        if (currentTab && currentTab !== 'all') {
            if (currentTab === 'recurring') {
                params.append('is_recurring', '1');
            } else if (currentTab === 'one-time') {
                params.append('is_recurring', '0');
            }
        }

        const url = `../backend/fees/fetch_tenant_fees.php${params.toString() ? '?' + params.toString() : ''}`;
        
        const response = await fetch(url);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        console.log("Tenant fees response:", data);

        if (data.success) {
            // Get fees from the response
            tenantFees = data.data?.fees || [];
            
            // Update summary if available
            if (data.data?.summary) {
                updateSummary(data.data.summary);
            }
            
            renderFeesPage();
        } else {
            throw new Error(data.message || "Failed to load fees");
        }
    } catch (error) {
        console.error("Error loading tenant fees:", error);
        if (window.showToast) {
            window.showToast(error.message || "Failed to load fees", "error");
        }
        showEmptyState();
    }
}

// ==================== UPDATE SUMMARY ====================
function updateSummary(apiSummary) {
    if (!apiSummary) return;
    
    console.log('Summary from API:', apiSummary);
    
    // Update any summary elements in the page
    // This will be called during renderFeesPage as well
}

// ==================== RENDER FEES PAGE ====================
function renderFeesPage() {
    const contentArea = document.getElementById("contentArea");
    if (!contentArea) return;

    // Calculate summary from the actual tenantFees data
    const totalPending = tenantFees
        .filter((f) => f.status === "pending")
        .reduce((sum, f) => sum + parseFloat(f.amount), 0);
    const totalPaid = tenantFees
        .filter((f) => f.status === "paid")
        .reduce((sum, f) => sum + parseFloat(f.amount), 0);
    const totalOverdue = tenantFees
        .filter((f) => f.status === "overdue")
        .reduce((sum, f) => sum + parseFloat(f.amount), 0);
    const totalFees = tenantFees.reduce((sum, f) => sum + parseFloat(f.amount), 0);

    // Filter fees based on current tab
    let filteredFees = [...tenantFees];
    
    if (currentTab === "recurring") {
        filteredFees = filteredFees.filter((f) => f.is_recurring === true);
    } else if (currentTab === "one-time") {
        filteredFees = filteredFees.filter((f) => f.is_recurring === false);
    }

    // Apply status filter (this overrides any API filtering for display)
    if (currentFilter !== "all") {
        filteredFees = filteredFees.filter((f) => f.status === currentFilter);
    }

    // Counts for tabs
    const allCount = tenantFees.length;
    const recurringCount = tenantFees.filter(f => f.is_recurring === true).length;
    const oneTimeCount = tenantFees.filter(f => f.is_recurring === false).length;
    const overdueCount = tenantFees.filter(f => f.status === "overdue").length;
    const pendingCount = tenantFees.filter(f => f.status === "pending").length;
    const paidCount = tenantFees.filter(f => f.status === "paid").length;

    const html = `
        <div class="fees-container">
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-left">
                    <h1><i class="fas fa-file-invoice-dollar"></i> My Fees</h1>
                    <div class="breadcrumb">
                        <a href="dashboard.php">Dashboard</a> <span>/</span> Fees
                    </div>
                </div>
                <div class="page-header-actions">
                    <button class="btn-outline-primary" onclick="openApplicableFeesModal()">
                        <i class="fas fa-list"></i> View Applicable Fees
                    </button>
                </div>
            </div>
            
            <!-- Summary Cards -->
            <div class="summary-cards">
                <div class="summary-card total">
                    <div class="card-top">
                        <div class="card-icon"><i class="fas fa-receipt"></i></div>
                        <span class="card-sub">Total Fees</span>
                    </div>
                    <div class="card-label">Total Fees</div>
                    <div class="card-amount">₦${formatNumber(totalFees)}</div>
                </div>
                <div class="summary-card pending">
                    <div class="card-top">
                        <div class="card-icon"><i class="fas fa-clock"></i></div>
                        <span class="card-sub">${pendingCount} items</span>
                    </div>
                    <div class="card-label">Pending</div>
                    <div class="card-amount">₦${formatNumber(totalPending)}</div>
                </div>
                <div class="summary-card paid">
                    <div class="card-top">
                        <div class="card-icon"><i class="fas fa-check-circle"></i></div>
                        <span class="card-sub">${paidCount} items</span>
                    </div>
                    <div class="card-label">Paid</div>
                    <div class="card-amount">₦${formatNumber(totalPaid)}</div>
                </div>
                <div class="summary-card overdue">
                    <div class="card-top">
                        <div class="card-icon"><i class="fas fa-exclamation-triangle"></i></div>
                        <span class="card-sub">${overdueCount} items</span>
                    </div>
                    <div class="card-label">Overdue</div>
                    <div class="card-amount">₦${formatNumber(totalOverdue)}</div>
                </div>
            </div>
            
            <!-- Tabs -->
            <div class="fee-tabs">
                <button class="fee-tab ${currentTab === "all" ? "active" : ""}" onclick="switchFeeTab('all')">
                    All Fees
                    <span class="tab-badge">${allCount}</span>
                </button>
                <button class="fee-tab ${currentTab === "recurring" ? "active" : ""}" onclick="switchFeeTab('recurring')">
                    Recurring
                    <span class="tab-badge ${recurringCount === 0 ? 'zero' : ''}">${recurringCount}</span>
                </button>
                <button class="fee-tab ${currentTab === "one-time" ? "active" : ""}" onclick="switchFeeTab('one-time')">
                    One-Time
                    <span class="tab-badge ${oneTimeCount === 0 ? 'zero' : ''}">${oneTimeCount}</span>
                </button>
            </div>
            
            <!-- Filters -->
            <div class="fee-filters">
                <div class="filter-group">
                    <label><i class="fas fa-filter"></i> Filter by Status</label>
                    <select class="filter-select" id="statusFilter" onchange="applyStatusFilter()">
                        <option value="all" ${currentFilter === "all" ? "selected" : ""}>All</option>
                        <option value="pending" ${currentFilter === "pending" ? "selected" : ""}>Pending</option>
                        <option value="paid" ${currentFilter === "paid" ? "selected" : ""}>Paid</option>
                        <option value="overdue" ${currentFilter === "overdue" ? "selected" : ""}>Overdue</option>
                        <option value="waived" ${currentFilter === "waived" ? "selected" : ""}>Waived</option>
                    </select>
                </div>
                <div class="filter-actions">
                    <button class="btn-clear" onclick="clearFilters()">
                        <i class="fas fa-times"></i> Clear Filters
                    </button>
                </div>
            </div>
            
            <!-- Fees Grid -->
            ${filteredFees.length === 0
            ? `
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fas fa-receipt"></i>
                    </div>
                    <h3>No Fees Found</h3>
                    <p>You don't have any fees matching the current filters.</p>
                    <button class="btn-primary" onclick="clearFilters()">
                        <i class="fas fa-undo"></i> Clear Filters
                    </button>
                </div>
            `
            : `
                <div class="fees-grid">
                    ${filteredFees.map((fee) => `
                        <div class="fee-card" onclick="viewFeeDetails(${fee.tenant_fee_id})">
                            <!-- Fee Header -->
                            <div class="fee-header">
                                <div class="fee-header-left">
                                    <div class="fee-icon">
                                        <i class="fas ${fee.is_recurring ? 'fa-sync-alt' : 'fa-file-invoice'}"></i>
                                    </div>
                                    <div class="fee-name-group">
                                        <span class="fee-name">${escapeHtml(fee.fee_name)}</span>
                                        <span class="fee-code">${escapeHtml(fee.fee_code || '')}</span>
                                    </div>
                                </div>
                                <span class="fee-status status-${fee.status}">
                                    <i class="fas ${fee.status === 'paid' ? 'fa-check-circle' : fee.status === 'overdue' ? 'fa-exclamation-circle' : 'fa-clock'}"></i>
                                    ${fee.status.toUpperCase()}
                                </span>
                            </div>
                            
                            <!-- Fee Details -->
                            <div class="fee-details">
                                <div class="fee-detail-item">
                                    <span class="fee-detail-label">Amount</span>
                                    <span class="fee-detail-value">₦${formatNumber(fee.amount)}</span>
                                </div>
                                <div class="fee-detail-item">
                                    <span class="fee-detail-label">Due Date</span>
                                    <span class="fee-detail-value">${formatDate(fee.due_date)}</span>
                                </div>
                                ${fee.is_recurring ? `
                                    <div class="fee-detail-item">
                                        <span class="fee-detail-label">Recurrence</span>
                                        <span class="fee-detail-value">${fee.recurrence_period || 'Monthly'}</span>
                                    </div>
                                ` : ''}
                                <div class="fee-detail-item">
                                    <span class="fee-detail-label">Type</span>
                                    <span class="fee-detail-value">${fee.is_recurring ? 'Recurring' : 'One-time'}</span>
                                </div>
                                ${fee.status === "paid" && fee.receipt_number ? `
                                    <div class="fee-detail-item">
                                        <span class="fee-detail-label">Receipt No.</span>
                                        <span class="fee-detail-value">${escapeHtml(fee.receipt_number)}</span>
                                    </div>
                                ` : ''}
                            </div>
                            
                            <!-- Fee Actions -->
                            <div class="fee-actions" onclick="event.stopPropagation()">
                                ${(fee.status === "pending" || fee.status === "overdue") && fee.is_active_in_property ? `
                                    <button class="btn btn-pay" onclick="openPaymentModal(${fee.tenant_fee_id})">
                                        <i class="fas fa-credit-card"></i> Pay Now
                                    </button>
                                ` : (fee.status === "pending" || fee.status === "overdue") && !fee.is_active_in_property ? `
                                    <span class="btn btn-inactive">
                                        <i class="fas fa-lock"></i> Fee Inactive
                                    </span>
                                ` : ''}
                                
                                ${fee.status === "paid" && fee.receipt_number ? `
                                    <button class="btn btn-download" onclick="downloadReceiptByFeeId(${fee.tenant_fee_id})">
                                        <i class="fas fa-download"></i> Receipt
                                    </button>
                                ` : ''}
                                
                                <button class="btn btn-view" onclick="viewFeeDetails(${fee.tenant_fee_id})">
                                    <i class="fas fa-info-circle"></i> Details
                                </button>
                            </div>
                        </div>
                    `).join("")}
                </div>
            `}
        </div>
    `;

    contentArea.innerHTML = html;
}

// ==================== CLEAR FILTERS ====================
function clearFilters() {
    currentFilter = "all";
    const statusFilter = document.getElementById("statusFilter");
    if (statusFilter) statusFilter.value = "all";
    loadTenantFees();
}

// ==================== APPLY STATUS FILTER ====================
function applyStatusFilter() {
    const statusFilter = document.getElementById("statusFilter");
    if (statusFilter) {
        currentFilter = statusFilter.value;
        loadTenantFees();
    }
}

// ==================== SWITCH FEE TAB ====================
function switchFeeTab(tab) {
    currentTab = tab;
    loadTenantFees();
}

// ==================== SHOW EMPTY STATE ====================
function showEmptyState() {
    const contentArea = document.getElementById("contentArea");
    if (!contentArea) return;

    contentArea.innerHTML = `
        <div class="fees-container">
            <div class="page-header">
                <div>
                    <h1>My Fees</h1>
                    <p>View and manage your apartment fees</p>
                </div>
                <button class="btn-primary" onclick="openApplicableFeesModal()" style="display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-list"></i> View Applicable Fees
                </button>
            </div>
            <div class="empty-state">
                <i class="fas fa-receipt"></i>
                <h3>No Fees Found</h3>
                <p>You don't have any fees at the moment.</p>
            </div>
        </div>
    `;
}

// ==================== DOWNLOAD RECEIPT ====================
async function downloadReceiptByFeeId(tenantFeeId) {
    try {
        const response = await fetch(`../backend/fees/get_payment_by_fee_id.php?tenant_fee_id=${tenantFeeId}`);
        const data = await response.json();

        if (data.success && data.data?.payment_id) {
            window.open(`../backend/fees/download_fee_receipt.php?payment_id=${data.data.payment_id}`, "_blank");
        } else {
            throw new Error("Receipt not found");
        }
    } catch (error) {
        console.error("Error downloading receipt:", error);
        if (window.showToast) {
            window.showToast("Receipt not available", "error");
        }
    }
}

// ==================== VIEW FEE DETAILS ====================
function viewFeeDetails(feeId) {
    const fee = tenantFees.find((f) => f.tenant_fee_id === feeId);
    if (!fee) return;

    const modalBody = document.getElementById("feeDetailsBody");
    if (!modalBody) return;

    modalBody.innerHTML = `
    <div class="fee-detail-section">
        <!-- Fee Header -->
        <div class="fee-name-header">
            <div class="fee-icon">
                <i class="fas ${fee.is_recurring ? 'fa-sync-alt' : 'fa-file-invoice'}"></i>
            </div>
            <div class="fee-title">
                <h4>${escapeHtml(fee.fee_name)}</h4>
                <span class="fee-code">${escapeHtml(fee.fee_code || '')}</span>
            </div>
            <span class="fee-status-badge status-${fee.status}">
                <i class="fas ${fee.status === 'paid' ? 'fa-check-circle' : fee.status === 'overdue' ? 'fa-exclamation-circle' : 'fa-clock'}"></i>
                ${fee.status.toUpperCase()}
            </span>
        </div>

        <!-- Amount -->
        <div class="detail-row">
            <span class="detail-label"><i class="fas fa-money-bill-wave"></i> Amount</span>
            <span class="detail-value amount ${fee.status}">₦${formatNumber(fee.amount)}</span>
        </div>

        <!-- Due Date -->
        <div class="detail-row">
            <span class="detail-label"><i class="fas fa-calendar-alt"></i> Due Date</span>
            <span class="detail-value">${formatDate(fee.due_date)}</span>
        </div>

        <!-- Divider -->
        <div class="detail-divider"></div>

        <!-- Recurrence -->
        ${fee.is_recurring ? `
            <div class="detail-row">
                <span class="detail-label"><i class="fas fa-clock"></i> Recurrence</span>
                <span class="detail-value">
                    <span class="recurring-badge">
                        <i class="fas fa-sync-alt"></i> ${fee.recurrence_period || "Monthly"}
                    </span>
                </span>
            </div>
        ` : `
            <div class="detail-row">
                <span class="detail-label"><i class="fas fa-clock"></i> Fee Type</span>
                <span class="detail-value">
                    <span class="one-time-badge">
                        <i class="fas fa-file-invoice"></i> One-time
                    </span>
                </span>
            </div>
        `}

        <!-- Fee Type (Recurring/One-time) -->
        <div class="detail-row">
            <span class="detail-label"><i class="fas ${fee.is_recurring ? 'fa-infinity' : 'fa-stopwatch'}"></i> Type</span>
            <span class="detail-value">${fee.is_recurring ? "Recurring Fee" : "One-time Fee"}</span>
        </div>

        <!-- Fee Status on property_apartment_type_fee table (Active/Inactive) -->
        <div class="detail-row">
            <span class="detail-label"><i class="fas ${fee.is_active_in_property ? 'fa-lock-open' : 'fa-lock'}"></i> Fee Status</span>
            <span class="detail-value">${fee.is_active_in_property ? "Active-Can Pay" : "Inactive - Unavailable"}</span>
        </div>

        <!-- Notes -->
        ${fee.notes ? `
            <div class="detail-divider"></div>
            <div class="detail-row notes">
                <span class="detail-label"><i class="fas fa-sticky-note"></i> Notes</span>
                <span class="detail-value">${escapeHtml(fee.notes)}</span>
            </div>
        ` : ''}
    </div>
`;

    openModal("feeDetailsModal");
}

// ==================== PAYMENT MODAL ====================
let currentPaymentFeeId = null;

function openPaymentModal(feeId) {
    const fee = tenantFees.find((f) => f.tenant_fee_id === feeId);
    if (!fee) return;

    currentPaymentFeeId = feeId;

    document.getElementById("modalFeeName").value = fee.fee_name;
    document.getElementById("modalFeeAmount").value = `₦${formatNumber(fee.amount)}`;
    document.getElementById("modalDueDate").value = formatDate(fee.due_date);
    document.getElementById("paymentMethod").value = "";
    document.getElementById("referenceNumber").value = "";

    const paymentMethodSelect = document.getElementById("paymentMethod");
    const referenceInput = document.getElementById("referenceNumber");

    paymentMethodSelect.onchange = function () {
        if (this.value) {
            const refNumber = generateReferenceNumber(this.value);
            referenceInput.value = refNumber;
            referenceInput.readOnly = true;
            referenceInput.style.background = "#f0f0f0";
        } else {
            referenceInput.value = "";
            referenceInput.readOnly = false;
            referenceInput.style.background = "white";
        }
    };

    referenceInput.value = "";
    referenceInput.readOnly = false;
    referenceInput.style.background = "white";

    openModal("paymentModal");
}

// ==================== GENERATE REFERENCE NUMBER ====================
function generateReferenceNumber(paymentMethod) {
    const prefix = paymentMethod === "card" ? "CARD" 
        : paymentMethod === "bank_transfer" ? "BNK" 
        : paymentMethod === "cash" ? "CSH" 
        : "REF";

    const date = new Date();
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, "0");
    const day = String(date.getDate()).padStart(2, "0");
    const random = Math.random().toString(36).substring(2, 8).toUpperCase();
    const timestamp = Date.now().toString().slice(-6);

    return `${prefix}-${year}${month}${day}-${random}-${timestamp}`;
}

// ==================== PROCESS FEE PAYMENT ====================
async function processFeePayment() {
    const paymentMethod = document.getElementById("paymentMethod")?.value;
    const referenceNumber = document.getElementById("referenceNumber")?.value;

    if (!paymentMethod) {
        if (window.showToast) {
            window.showToast("Please select a payment method", "error");
        }
        return;
    }

    const payBtn = document.querySelector("#paymentModal .btn-primary");
    const originalText = payBtn.innerHTML;
    payBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    payBtn.disabled = true;

    try {
        const response = await fetch("../backend/fees/pay_fees.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                tenant_fee_id: currentPaymentFeeId,
                payment_method: paymentMethod,
                reference_number: referenceNumber || null,
            }),
        });

        const data = await response.json();

        if (data.success) {
            if (window.showToast) {
                window.showToast("Payment successful!", "success");
            }

            const paymentData = data.data;
            showPaymentSuccessModal(paymentData);
            closeModal("paymentModal");
            await loadTenantFees();
        } else {
            throw new Error(data.message || "Payment failed");
        }
    } catch (error) {
        console.error("Payment error:", error);
        if (window.showToast) {
            window.showToast(error.message, "error");
        }
    } finally {
        payBtn.innerHTML = originalText;
        payBtn.disabled = false;
    }
}

// ==================== SHOW PAYMENT SUCCESS MODAL ====================
function showPaymentSuccessModal(paymentData) {
    const modalHtml = `
        <div class="modal active" id="paymentSuccessModal">
            <div class="modal-content" style="max-width: 450px;">
                <div class="modal-header">
                    <h3>Payment Successful!</h3>
                    <button class="modal-close" onclick="closeModal('paymentSuccessModal')">&times;</button>
                </div>
                <div class="modal-body">
                    <div style="text-align: center; margin-bottom: 20px;">
                        <i class="fas fa-check-circle" style="font-size: 64px; color: #10b981;"></i>
                    </div>
                    <div class="payment-details">
                        <div class="detail-row">
                            <span class="detail-label">Fee Type:</span>
                            <span class="detail-value">${escapeHtml(paymentData.fee_name)}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Amount Paid:</span>
                            <span class="detail-value">₦${formatNumber(paymentData.amount)}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Receipt Number:</span>
                            <span class="detail-value">${escapeHtml(paymentData.receipt_number)}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Payment Date:</span>
                            <span class="detail-value">${formatDateTime(paymentData.payment_date)}</span>
                        </div>
                        ${paymentData.next_fee_created ? `
                            <div class="detail-row">
                                <span class="detail-label">Next Due Date:</span>
                                <span class="detail-value">${formatDate(paymentData.next_due_date)}</span>
                            </div>
                        ` : ""}
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="closeModal('paymentSuccessModal')">Close</button>
                    <button class="btn-primary" onclick="downloadReceipt('${paymentData.receipt_number}', ${paymentData.payment_id})">
                        <i class="fas fa-download"></i> Download Receipt
                    </button>
                </div>
            </div>
        </div>
    `;

    const existingModal = document.getElementById("paymentSuccessModal");
    if (existingModal) existingModal.remove();
    document.body.insertAdjacentHTML("beforeend", modalHtml);
}

// ==================== DOWNLOAD RECEIPT ====================
async function downloadReceipt(receiptNumber, paymentId) {
    try {
        window.open(`../backend/fees/download_fee_receipt.php?payment_id=${paymentId}`, "_blank");
    } catch (error) {
        console.error("Error downloading receipt:", error);
        if (window.showToast) {
            window.showToast("Failed to download receipt", "error");
        }
    }
}

// ==================== MODAL CONTROLS ====================
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) modal.classList.add("active");
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) modal.classList.remove("active");
}

// ==================== UTILITY FUNCTIONS ====================
function formatNumber(value) {
    if (!value || value === "0") return "0.00";
    return new Intl.NumberFormat("en-NG", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(value);
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