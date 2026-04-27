// fee_management.js - Complete Optimized Version

// ==================== STATE MANAGEMENT ====================
const state = {
    currentPropertyFees: {},
    apartmentTypes: [],
    feeTypes: [],
    confirmResolve: null
};

// ==================== DOM ELEMENTS CACHE ====================
const dom = {
    get feeTypesGrid() { return document.getElementById('feeTypesGrid'); },
    get propertySelect() { return document.getElementById('propertySelect'); },
    get propertyFeesContent() { return document.getElementById('propertyFeesContent'); },
    get tenantFeesContent() { return document.getElementById('tenantFeesContent'); },
    get tenantFeeStatusFilter() { return document.getElementById('tenantFeeStatusFilter'); },
    get tenantSearch() { return document.getElementById('tenantSearch'); }
};

// ==================== INITIALIZATION ====================
document.addEventListener('DOMContentLoaded', () => {
    initTabs();
    loadFeeTypes();
});

function initTabs() {
    document.querySelectorAll(".tab-btn").forEach(btn => {
        btn.addEventListener("click", () => {
            const tab = btn.dataset.tab;
            switchTab(tab);
        });
    });
}

function switchTab(tab) {
    document.querySelectorAll(".tab-btn").forEach(b => b.classList.remove("active"));
    document.querySelector(`.tab-btn[data-tab="${tab}"]`).classList.add("active");
    document.querySelectorAll(".tab-content").forEach(c => c.classList.remove("active"));
    document.getElementById(`${tab}-tab`).classList.add("active");
    
    if (tab === "fee-types") loadFeeTypes();
    if (tab === "property-fees") loadProperties();
    if (tab === "tenant-fees") loadTenantFees();
}

// ==================== FEE TYPES CRUD ====================
async function loadFeeTypes() {
    try {
        const response = await fetch("../backend/fee_management/fetch_fee_types.php");
        const data = await response.json();
        if (data.success) {
            state.feeTypes = data.message.fee_types;
            renderFeeTypes(state.feeTypes);
        }
    } catch (error) {
        console.error("Error loading fee types:", error);
        showToast("Failed to load fee types", "error");
    }
}

function renderFeeTypes(types) {
    const container = dom.feeTypesGrid;
    if (!container) return;
    
    if (!types || types.length === 0) {
        container.innerHTML = '<div class="empty-state"><i class="fas fa-receipt"></i><p>No fee types found</p><button class="btn-primary" onclick="openFeeTypeModal()">Add Fee Type</button></div>';
        return;
    }
    
    container.innerHTML = types.map(type => `
        <div class="fee-type-card">
            <div class="fee-type-header">
                <span class="fee-type-name">${escapeHtml(type.fee_name)}</span>
                <span class="fee-type-code">${escapeHtml(type.fee_code)}</span>
            </div>
            <div class="fee-type-details">
                <div class="fee-detail-row">
                    <span class="fee-detail-label">Description:</span>
                    <span class="fee-detail-value">${escapeHtml(type.description || "-")}</span>
                </div>
                <div class="fee-detail-row">
                    <span class="fee-detail-label">Type:</span>
                    <span class="fee-detail-value">${type.is_mandatory ? '<span class="badge-mandatory">Mandatory</span>' : '<span class="badge-optional">Optional</span>'}</span>
                </div>
                <div class="fee-detail-row">
                    <span class="fee-detail-label">Calculation:</span>
                    <span class="fee-detail-value">${type.calculation_type}</span>
                </div>
                <div class="fee-detail-row">
                    <span class="fee-detail-label">Recurring:</span>
                    <span class="fee-detail-value">${type.is_recurring ? type.recurrence_period : "One-time"}</span>
                </div>
            </div>
            <div class="fee-actions">
                <button class="btn-icon btn-edit" onclick="editFeeType(${type.fee_type_id})" title="Edit">
                    <i class="fas fa-edit"></i>
                </button>
            </div>
        </div>
    `).join("");
}

function openFeeTypeModal() {
    document.getElementById("feeTypeModalTitle").textContent = "Add Fee Type";
    document.getElementById("feeTypeForm").reset();
    document.getElementById("editFeeTypeId").value = "";
    document.getElementById("feeTypeModal").classList.add("active");
}

function editFeeType(id) {
    const type = state.feeTypes.find(t => t.fee_type_id === id);
    if (!type) return;
    document.getElementById("feeTypeModalTitle").textContent = "Edit Fee Type";
    document.getElementById("editFeeTypeId").value = type.fee_type_id;
    document.getElementById("feeCode").value = type.fee_code;
    document.getElementById("feeName").value = type.fee_name;
    document.getElementById("feeDescription").value = type.description || "";
    document.getElementById("isMandatory").value = type.is_mandatory;
    document.getElementById("calculationType").value = type.calculation_type;
    document.getElementById("isRecurring").value = type.is_recurring;
    document.getElementById("recurrencePeriod").value = type.recurrence_period;
    document.getElementById("displayOrder").value = type.display_order;
    document.getElementById("feeTypeModal").classList.add("active");
}

function closeFeeTypeModal() {
    document.getElementById("feeTypeModal").classList.remove("active");
}

async function saveFeeType() {
    const feeTypeId = document.getElementById("editFeeTypeId").value;
    const action = feeTypeId ? "update" : "create";
    const data = {
        action: action,
        fee_type_id: feeTypeId || null,
        fee_code: document.getElementById("feeCode").value,
        fee_name: document.getElementById("feeName").value,
        description: document.getElementById("feeDescription").value,
        is_mandatory: parseInt(document.getElementById("isMandatory").value),
        calculation_type: document.getElementById("calculationType").value,
        is_recurring: parseInt(document.getElementById("isRecurring").value),
        recurrence_period: document.getElementById("recurrencePeriod").value,
        display_order: parseInt(document.getElementById("displayOrder").value),
    };
    
    try {
        const response = await fetch("../backend/fee_management/manage_fee_type.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(data),
        });
        const result = await response.json();
        if (result.success) {
            showToast(result.message, "success");
            closeFeeTypeModal();
            loadFeeTypes();
        } else {
            throw new Error(result.message);
        }
    } catch (error) {
        console.error("Error saving fee type:", error);
        showToast(error.message, "error");
    }
}

// ==================== PROPERTY FEES ====================
async function loadProperties() {
    try {
        const response = await fetch("../backend/properties/get_properties.php");
        const data = await response.json();
        if (data.success) {
            const properties = data.message?.properties || [];
            const select = dom.propertySelect;
            select.innerHTML = '<option value="">Select a property</option>' +
                properties.map(p => `<option value="${p.property_code}">${escapeHtml(p.name)}</option>`).join("");
            select.addEventListener("change", () => {
                if (select.value) {
                    loadPropertyFees(select.value);
                    loadApartmentTypes(select.value);
                }
            });
        }
    } catch (error) {
        console.error("Error loading properties:", error);
        showToast("Failed to load properties", "error");
    }
}

async function loadPropertyFees(propertyCode) {
    try {
        const response = await fetch(`../backend/fee_management/fetch_property_fees.php?property_code=${propertyCode}`);
        const data = await response.json();
        if (data.success) {
            state.currentPropertyFees = data.message?.property_fees || {};
            renderPropertyFees();
        }
    } catch (error) {
        console.error("Error loading property fees:", error);
        state.currentPropertyFees = {};
        renderPropertyFees();
    }
}

async function loadApartmentTypes(propertyCode) {
    try {
        const response = await fetch(`../backend/apartment_types/get_apartment_types.php?property_code=${propertyCode}`);
        const data = await response.json();
        if (data.success) {
            state.apartmentTypes = data.message?.apartment_types || [];
        }
    } catch (error) {
        console.error("Error loading apartment types:", error);
        state.apartmentTypes = [];
    }
}

function renderPropertyFees() {
    const container = dom.propertyFeesContent;
    if (!container) return;
    
    const propertyCode = dom.propertySelect?.value;
    const propertyName = dom.propertySelect?.selectedOptions[0]?.text;
    
    if (!state.currentPropertyFees || Object.keys(state.currentPropertyFees).length === 0) {
        container.innerHTML = `
            <div class="empty-state-fees">
                <i class="fas fa-receipt"></i>
                <p>No fees configured for this property yet.</p>
                <button class="btn-primary" onclick="showConfigureFeesModal()">Configure Fees</button>
            </div>`;
        return;
    }
    
    let html = '';
    for (const [typeName, typeData] of Object.entries(state.currentPropertyFees)) {
        html += `
            <div class="property-fees-section">
                <h3>${escapeHtml(typeName)}</h3>
                <div class="table-responsive">
                    <table class="fee-table">
                        <thead><tr><th>Fee Type</th><th>Amount (₦)</th><th>Mandatory</th><th>Recurring</th><th>Effective From</th></tr></thead>
                        <tbody>
                            ${typeData.fees.map(fee => `
                                <tr>
                                    <td>${escapeHtml(fee.fee_name)}</td>
                                    <td>₦${formatNumber(fee.amount)}</td>
                                    <td>${fee.is_mandatory ? '<span class="badge-mandatory">Yes</span>' : '<span class="badge-optional">No</span>'}</td>
                                    <td>${fee.is_recurring ? (fee.recurrence_period || 'Monthly') : 'One-time'}</td>
                                    <td>${formatDate(fee.effective_from)}</td
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            </div>`;
    }
    
    html += `
        <div class="button-group">
            <button class="btn-primary" onclick="showConfigureFeesModal()"><i class="fas fa-edit"></i> Edit Fees</button>
            <button class="btn-regenerate" onclick="regenerateTenantFees('${propertyCode}')"><i class="fas fa-sync-alt"></i> Apply to Existing Tenants</button>
        </div>`;
    
    container.innerHTML = html;
}

async function showConfigureFeesModal() {
    const propertyCode = dom.propertySelect.value;
    if (!propertyCode) {
        showToast('Please select a property first', 'warning');
        return;
    }
    
    if (state.feeTypes.length === 0) await loadFeeTypes();
    if (state.apartmentTypes.length === 0) await loadApartmentTypes(propertyCode);
    
    const modalHtml = `
        <div class="modal active" id="configFeesModal">
            <div class="modal-content" style="max-width: 900px;">
                <div class="modal-header">
                    <h3>Configure Property Fees</h3>
                    <button class="modal-close" onclick="closeConfigModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Property</label>
                        <input type="text" id="configPropertyName" value="${dom.propertySelect.selectedOptions[0]?.text}" disabled>
                    </div>
                    <div id="feeConfigForm">
                        ${state.apartmentTypes.map(type => `
                            <div class="apartment-type-fees">
                                <h4>${escapeHtml(type.type_name)} ${type.apartment_count > 0 ? `<span class="badge-info">${type.apartment_count} apartments</span>` : ''}</h4>
                                <div class="fee-config-grid">
                                    ${state.feeTypes.map(fee => `
                                        <div class="fee-config-item">
                                            <label>${escapeHtml(fee.fee_name)}${fee.is_mandatory ? '<span class="mandatory-star">*</span>' : ''}</label>
                                            <div class="fee-input-wrapper">
                                                <span class="currency-symbol">₦</span>
                                                <input type="number" class="amount-input" data-apartment-type="${type.type_id}" data-fee-type="${fee.fee_type_id}" placeholder="0.00" step="0.01" value="${getExistingFeeAmount(type.type_id, fee.fee_type_id)}">
                                            </div>
                                            <small class="fee-description">${fee.is_recurring ? `Recurs: ${fee.recurrence_period}` : 'One-time fee'}</small>
                                        </div>
                                    `).join('')}
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="closeConfigModal()">Cancel</button>
                    <button class="btn-primary" onclick="savePropertyFees()">Save Fees</button>
                </div>
            </div>
        </div>`;
    
    const existingModal = document.getElementById('configFeesModal');
    if (existingModal) existingModal.remove();
    document.body.insertAdjacentHTML('beforeend', modalHtml);
}

function getExistingFeeAmount(apartmentTypeId, feeTypeId) {
    for (const [typeName, typeData] of Object.entries(state.currentPropertyFees)) {
        if (typeData.apartment_type_id === apartmentTypeId) {
            const existingFee = typeData.fees.find(f => f.fee_type_id === feeTypeId);
            if (existingFee) return existingFee.amount;
        }
    }
    return '';
}

function closeConfigModal() {
    const modal = document.getElementById('configFeesModal');
    if (modal) modal.remove();
}

async function savePropertyFees() {
    const propertyCode = dom.propertySelect.value;
    const propertyName = dom.propertySelect.selectedOptions[0]?.text;
    const feeInputs = document.querySelectorAll("#feeConfigForm .amount-input");
    const fees = {};
    
    feeInputs.forEach(input => {
        const apartmentTypeId = input.dataset.apartmentType;
        const feeTypeId = input.dataset.feeType;
        const amount = parseFloat(input.value) || 0;
        if (!fees[apartmentTypeId]) fees[apartmentTypeId] = {};
        fees[apartmentTypeId][feeTypeId] = amount;
    });
    
    const saveBtn = document.querySelector('#configFeesModal .btn-primary');
    const originalText = saveBtn.innerHTML;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    saveBtn.disabled = true;
    
    try {
        const response = await fetch("../backend/fee_management/set_property_fees.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ property_code: propertyCode, fees: fees, effective_from: new Date().toISOString().split("T")[0] }),
        });
        const result = await response.json();
        if (result.success) {
            showToast("Property fees saved successfully", "success");
            closeConfigModal();
            await loadPropertyFees(propertyCode);
            showRegeneratePrompt(propertyCode, propertyName);
        } else {
            throw new Error(result.message);
        }
    } catch (error) {
        console.error("Error saving property fees:", error);
        showToast(error.message, "error");
    } finally {
        saveBtn.innerHTML = originalText;
        saveBtn.disabled = false;
    }
}

async function showRegeneratePrompt(propertyCode, propertyName) {
    try {
        const hasTenants = await checkExistingTenants(propertyCode);
        if (hasTenants) {
            const existingBanner = document.getElementById('regenerateBanner');
            if (existingBanner) existingBanner.remove();
            
            const bannerHtml = `
                <div id="regenerateBanner" class="regenerate-banner">
                    <div class="banner-content">
                        <div class="banner-icon"><i class="fas fa-users"></i></div>
                        <div class="banner-message">
                            <strong>Existing Tenants Detected</strong>
                            <p>You have existing tenants in property "${escapeHtml(propertyName)}". Would you like to apply these new fees to them?</p>
                        </div>
                        <div class="banner-actions">
                            <button class="btn-regenerate" onclick="regenerateTenantFees('${propertyCode}')"><i class="fas fa-sync-alt"></i> Apply to Existing Tenants</button>
                            <button class="btn-dismiss" onclick="dismissRegenerateBanner()"><i class="fas fa-times"></i> Later</button>
                        </div>
                    </div>
                </div>`;
            
            const container = dom.propertyFeesContent;
            if (container) {
                container.insertAdjacentHTML('afterbegin', bannerHtml);
                document.getElementById('regenerateBanner').scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }
    } catch (error) {
        console.error('Error checking tenants:', error);
    }
}

async function checkExistingTenants(propertyCode) {
    try {
        const response = await fetch(`../backend/properties/check_property_tenants.php?property_code=${propertyCode}`);
        const data = await response.json();
        return data.success && (data.message?.has_tenants || false);
    } catch (error) {
        console.error('Error checking tenants:', error);
        return false;
    }
}

async function regenerateTenantFees(propertyCode) {
    const regenerateBtn = document.querySelector('.btn-regenerate');
    if (!regenerateBtn) return;
    
    const originalText = regenerateBtn.innerHTML;
    regenerateBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    regenerateBtn.disabled = true;
    
    try {
        const response = await fetch(`../backend/fee_management/regenerate_tenant_fees.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ property_code: propertyCode })
        });
        const data = await response.json();
        
        if (data.success) {
            const feesCreated = data.data?.fees_created || data.message?.fees_created || 0;
            showToast(`Successfully applied ${feesCreated} new fee(s) to existing tenants`, 'success');
            dismissRegenerateBanner();
            if (document.getElementById('tenant-fees-tab').classList.contains('active')) loadTenantFees();
        } else {
            throw new Error(data.message || 'Failed to apply fees');
        }
    } catch (error) {
        console.error('Error regenerating tenant fees:', error);
        showToast(error.message || 'Failed to apply fees to existing tenants', 'error');
    } finally {
        regenerateBtn.innerHTML = originalText;
        regenerateBtn.disabled = false;
    }
}

function dismissRegenerateBanner() {
    const banner = document.getElementById('regenerateBanner');
    if (banner) banner.remove();
}

// ==================== TENANT FEES ====================
async function loadTenantFees() {
    const status = dom.tenantFeeStatusFilter?.value || '';
    const search = dom.tenantSearch?.value || '';
    
    const params = new URLSearchParams();
    if (status) params.append("status", status);
    if (search) params.append("search", search);
    
    try {
        const response = await fetch(`../backend/fee_management/fetch_tenant_fees.php?${params}`);
        const data = await response.json();
        if (data.success) {
            renderTenantFees(data.message?.fees || []);
        }
    } catch (error) {
        console.error("Error loading tenant fees:", error);
    }
}

function renderTenantFees(fees) {
    const container = dom.tenantFeesContent;
    if (!container) return;
    
    if (!fees || fees.length === 0) {
        container.innerHTML = `<div class="empty-state"><i class="fas fa-receipt"></i><p>No tenant fees found</p></div>`;
        return;
    }
    
    container.innerHTML = `
        <div class="table-responsive">
            <table class="data-table">
                <thead><tr><th>Tenant</th><th>Fee Type</th><th>Amount</th><th>Due Date</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    ${fees.map(fee => `
                        <tr>
                            <td>${escapeHtml(fee.tenant_name || fee.tenant_code || 'N/A')}</td>
                            <td>${escapeHtml(fee.fee_name)}</td>
                            <td>₦${formatNumber(fee.amount)}</td>
                            <td>${formatDate(fee.due_date)}</td>
                            <td><span class="status-badge status-${fee.status}">${fee.status.toUpperCase()}</span></td>
                            <td>
                                <button class="btn-icon" onclick="viewTenantFee(${fee.tenant_fee_id})" title="View"><i class="fas fa-eye"></i></button>
                                <button class="btn-icon" onclick="markFeeAsPaid(${fee.tenant_fee_id})" title="Mark as Paid"><i class="fas fa-check-circle"></i></button>
                            </td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        </div>`;
}

// ==================== VIEW FEE DETAILS ====================
async function viewTenantFee(tenantFeeId) {
    try {
        const modalBody = document.getElementById('tenantFeeDetails');
        if (!modalBody) return;
        
        modalBody.innerHTML = '<div class="loading-spinner"><div class="spinner"></div> Loading...</div>';
        openModal('viewTenantFeeModal');
        
        const response = await fetch(`../backend/fee_management/fetch_single_tenant_fee.php?tenant_fee_id=${tenantFeeId}`);
        const data = await response.json();
        
        if (data.success) {
            const fee = data.message?.fee || data.data?.fee;
            renderTenantFeeDetails(fee, tenantFeeId);
        } else {
            modalBody.innerHTML = `<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>${data.message || 'Failed to load fee details'}</p></div>`;
        }
    } catch (error) {
        console.error('Error loading fee details:', error);
        document.getElementById('tenantFeeDetails').innerHTML = `<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading fee details. Please try again.</p></div>`;
    }
}

function renderTenantFeeDetails(fee, tenantFeeId) {
    const container = document.getElementById('tenantFeeDetails');
    const canMarkPaid = fee.status === 'pending' || fee.status === 'overdue';
    
    const markPaidBtn = document.getElementById('markPaidBtn');
    if (markPaidBtn) {
        markPaidBtn.style.display = canMarkPaid ? 'inline-flex' : 'none';
        markPaidBtn.setAttribute('data-fee-id', tenantFeeId);
    }
    
    let paymentInfo = '';
    if (fee.status === 'paid' && fee.notes) {
        const paymentMatch = fee.notes.match(/Paid on (.*?) via (.*?)\. Receipt: (.*?)(?:\n|$)/);
        if (paymentMatch) {
            paymentInfo = `
                <div class="payment-info-section">
                    <h4>Payment Information</h4>
                    <div class="detail-row"><span class="detail-label">Payment Date:</span><span class="detail-value">${formatDateTime(paymentMatch[1])}</span></div>
                    <div class="detail-row"><span class="detail-label">Payment Method:</span><span class="detail-value">${escapeHtml(paymentMatch[2])}</span></div>
                    <div class="detail-row"><span class="detail-label">Receipt Number:</span><span class="detail-value">${escapeHtml(paymentMatch[3])}</span></div>
                </div>`;
        }
    }
    
    container.innerHTML = `
        <div class="fee-detail-section">
            <div class="detail-section">
                <h4>Fee Information</h4>
                <div class="detail-row"><span class="detail-label">Fee Name:</span><span class="detail-value">${escapeHtml(fee.fee_name)}</span></div>
                <div class="detail-row"><span class="detail-label">Fee Code:</span><span class="detail-value">${escapeHtml(fee.fee_code)}</span></div>
                <div class="detail-row"><span class="detail-label">Amount:</span><span class="detail-value amount-value">₦${formatNumber(fee.amount)}</span></div>
                <div class="detail-row"><span class="detail-label">Due Date:</span><span class="detail-value">${formatDate(fee.due_date)}</span></div>
                <div class="detail-row"><span class="detail-label">Status:</span><span class="detail-value"><span class="status-badge status-${fee.status}">${fee.status.toUpperCase()}</span></span></div>
                ${fee.is_recurring ? `<div class="detail-row"><span class="detail-label">Recurrence:</span><span class="detail-value">${fee.recurrence_period || 'Monthly'}</span></div>` : ''}
            </div>
            <div class="detail-section">
                <h4>Tenant Information</h4>
                <div class="detail-row"><span class="detail-label">Tenant Name:</span><span class="detail-value">${escapeHtml(fee.tenant_name)}</span></div>
                <div class="detail-row"><span class="detail-label">Tenant Code:</span><span class="detail-value">${escapeHtml(fee.tenant_code)}</span></div>
                <div class="detail-row"><span class="detail-label">Apartment:</span><span class="detail-value">${escapeHtml(fee.apartment_number || 'N/A')}</span></div>
            </div>
            ${paymentInfo}
            ${fee.notes && fee.status !== 'paid' ? `<div class="detail-section"><h4>Notes</h4><div class="notes-content">${escapeHtml(fee.notes)}</div></div>` : ''}
        </div>`;
}

function closeViewTenantFeeModal() {
    closeModal('viewTenantFeeModal');
}

// ==================== MARK FEE AS PAID ====================
async function markFeeAsPaidFromModal() {
    const btn = document.getElementById('markPaidBtn');
    const feeId = btn?.getAttribute('data-fee-id');
    if (feeId) await markFeeAsPaid(feeId);
}

async function markFeeAsPaid(feeId) {
    try {
        // Get fee details for confirmation
        const response = await fetch(`../backend/fee_management/fetch_single_tenant_fee.php?tenant_fee_id=${feeId}`);
        const data = await response.json();
        
        let feeName = '', feeAmount = '', tenantName = '';
        if (data.success) {
            const fee = data.message?.fee || data.data?.fee;
            feeName = fee.fee_name;
            feeAmount = formatNumber(fee.amount);
            tenantName = fee.tenant_name;
        }
        
        const confirmed = await showCustomConfirm({
            title: 'Mark Fee as Paid',
            type: 'warning',
            message: 'Are you sure you want to mark this fee as paid?',
            details: [
                { label: 'Fee Name', value: feeName },
                { label: 'Amount', value: `₦${feeAmount}` },
                { label: 'Tenant', value: tenantName }
            ],
            confirmText: 'Yes, Mark as Paid',
            confirmClass: 'btn-primary',
            cancelText: 'Cancel'
        });
        
        if (!confirmed) return;
        
        const btn = event?.target?.closest('.btn-icon') || document.getElementById('markPaidBtn');
        const originalText = btn?.innerHTML || 'Mark as Paid';
        if (btn) {
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            btn.disabled = true;
        }
        
        const markResponse = await fetch('../backend/fee_management/mark_fee_paid.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ tenant_fee_id: feeId })
        });
        
        const markData = await markResponse.json();
        
        if (markData.success) {
            const responseData = markData.message || markData.data || {};
            await showCustomConfirm({
                title: 'Success!',
                type: 'success',
                message: responseData.message || 'Fee marked as paid successfully!',
                details: [
                    { label: 'Receipt Number', value: responseData.receipt_number || 'N/A' },
                    { label: 'Payment Date', value: formatDateTime(responseData.payment_date) || new Date().toLocaleString() }
                ],
                confirmText: 'OK',
                confirmClass: 'btn-success',
                cancelText: ''
            });
            showToast('Fee marked as paid successfully', 'success');
            closeViewTenantFeeModal();
            loadTenantFees();
        } else {
            throw new Error(markData.message || 'Failed to mark fee as paid');
        }
    } catch (error) {
        console.error('Error marking fee as paid:', error);
        showToast(error.message, 'error');
        await showCustomConfirm({
            title: 'Error',
            type: 'danger',
            message: error.message || 'Failed to mark fee as paid. Please try again.',
            confirmText: 'OK',
            confirmClass: 'btn-danger',
            cancelText: ''
        });
    } finally {
        const btn = event?.target?.closest('.btn-icon') || document.getElementById('markPaidBtn');
        if (btn) {
            btn.innerHTML = '<i class="fas fa-check-circle"></i>';
            btn.disabled = false;
        }
    }
}

// ==================== CUSTOM CONFIRMATION MODAL ====================
function showCustomConfirm(options) {
    return new Promise((resolve) => {
        state.confirmResolve = resolve;
        
        const modal = document.getElementById('customConfirmModal');
        const titleEl = document.getElementById('confirmTitle');
        const messageEl = document.getElementById('confirmMessage');
        const iconEl = document.getElementById('confirmIcon');
        const detailsEl = document.getElementById('confirmDetails');
        const cancelBtn = document.getElementById('confirmCancelBtn');
        const okBtn = document.getElementById('confirmOkBtn');
        
        titleEl.textContent = options.title || 'Confirm Action';
        messageEl.textContent = options.message || 'Are you sure you want to proceed?';
        
        const iconType = options.type || 'warning';
        iconEl.className = 'confirm-icon ' + iconType;
        const icons = { danger: 'fa-exclamation-triangle', success: 'fa-check-circle', info: 'fa-info-circle', warning: 'fa-exclamation-triangle' };
        iconEl.innerHTML = `<i class="fas ${icons[iconType] || icons.warning}"></i>`;
        
        okBtn.textContent = options.confirmText || 'Confirm';
        okBtn.className = options.confirmClass || 'btn-primary';
        cancelBtn.textContent = options.cancelText || 'Cancel';
        
        if (options.details?.length) {
            detailsEl.innerHTML = `<div class="confirm-details">${options.details.map(d => `<div class="confirm-detail-row"><span class="confirm-detail-label">${escapeHtml(d.label)}:</span><span class="confirm-detail-value">${escapeHtml(d.value)}</span></div>`).join('')}</div>`;
            detailsEl.style.display = 'block';
        } else {
            detailsEl.style.display = 'none';
        }
        
        modal.style.display = 'flex';
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        
        const oldOkBtn = okBtn.cloneNode(true);
        const oldCancelBtn = cancelBtn.cloneNode(true);
        okBtn.parentNode.replaceChild(oldOkBtn, okBtn);
        cancelBtn.parentNode.replaceChild(oldCancelBtn, cancelBtn);
        
        oldOkBtn.onclick = () => { closeCustomConfirmModal(); resolve(true); };
        oldCancelBtn.onclick = () => { closeCustomConfirmModal(); resolve(false); };
        
        const handleEscape = (e) => {
            if (e.key === 'Escape') {
                closeCustomConfirmModal();
                resolve(false);
                document.removeEventListener('keydown', handleEscape);
            }
        };
        document.addEventListener('keydown', handleEscape);
        
        window._confirmCleanup = () => document.removeEventListener('keydown', handleEscape);
    });
}

function closeCustomConfirmModal() {
    const modal = document.getElementById('customConfirmModal');
    modal.style.display = 'none';
    modal.classList.remove('active');
    document.body.style.overflow = '';
    if (window._confirmCleanup) window._confirmCleanup();
}

function searchTenantFees() { loadTenantFees(); }

// ==================== MODAL FUNCTIONS ====================
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

function closeModalOnOutsideClick(event, modalId) {
    const modal = document.getElementById(modalId);
    if (event.target === modal) closeModal(modalId);
}

// ==================== UTILITY FUNCTIONS ====================
function formatNumber(value) {
    return new Intl.NumberFormat("en-NG").format(value || 0);
}

function formatDate(dateString) {
    if (!dateString) return "N/A";
    return new Date(dateString).toLocaleDateString("en-US");
}

function formatDateTime(dateString) {
    if (!dateString) return 'N/A';
    try {
        return new Date(dateString).toLocaleDateString('en-US', { 
            year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
        });
    } catch { return dateString; }
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showToast(message, type) {
    const toast = document.createElement("div");
    toast.className = `toast-notification ${type}`;
    toast.innerHTML = `<span>${escapeHtml(message)}</span>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

// Initial load
loadFeeTypes();