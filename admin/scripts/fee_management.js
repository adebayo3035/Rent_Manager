// fee_management.js - Complete Optimized Version

// ==================== STATE MANAGEMENT ====================
const state = {
  currentPropertyFees: {},
  apartmentTypes: [],
  feeTypes: [],
  confirmResolve: null,
};

// ==================== DOM ELEMENTS CACHE ====================
const dom = {
  get feeTypesGrid() {
    return document.getElementById("feeTypesGrid");
  },
  get deactivatedFeeTypesGrid(){
    return document.getElementById('deactivatedFeeTypesGrid');
  },
  get propertySelect() {
    return document.getElementById("propertySelect");
  },
  get propertyFeesContent() {
    return document.getElementById("propertyFeesContent");
  },
  get tenantFeesContent() {
    return document.getElementById("tenantFeesContent");
  },
  get tenantFeeStatusFilter() {
    return document.getElementById("tenantFeeStatusFilter");
  },
  get tenantSearch() {
    return document.getElementById("tenantSearch");
  },
};

// ==================== INITIALIZATION ====================
document.addEventListener("DOMContentLoaded", () => {
  initTabs();
  loadFeeTypes();
  loadDeactivatedFeeTypes();
});

function initTabs() {
  document.querySelectorAll(".tab-btn").forEach((btn) => {
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
    if (tab === "deactivated-fees") loadDeactivatedFeeTypes(); // Add this line
}

// ==================== FEE TYPES CRUD ====================
async function loadFeeTypes() {
  try {
    const response = await fetch(
      "../backend/fee_management/fetch_fee_types.php",
    );
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

async function loadDeactivatedFeeTypes() {
  try {
    const response = await fetch(
      "../backend/fee_management/fetch_deactivated_fee_types.php",
    );
    const data = await response.json();
    if (data.success) {
      state.deactivatedFeeTypes = data.message.fee_types;
      renderDeactivatedFeeTypes(state.deactivatedFeeTypes);
    }
  } catch (error) {
    console.error("Error loading Deactivated fee types:", error);
    showToast("Failed to load Deactivated fee types", "error");
  }
}

function renderFeeTypes(types) {
  const container = dom.feeTypesGrid;
  if (!container) return;

  if (!types || types.length === 0) {
    container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-receipt"></i>
                <p>No fee types found</p>
                <button class="btn-primary" onclick="openFeeTypeModal()">Add Fee Type</button>
            </div>`;
    return;
  }

  container.innerHTML = types
    .map(
      (type) => `
        <div class="fee-type-card ${type.status == 1 ? "" : "inactive"}">
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
    <span class="fee-detail-value">
        ${
          parseInt(type.is_mandatory) === 1
            ? '<span class="badge-mandatory">Mandatory</span>'
            : '<span class="badge-optional">Optional</span>'
        }
    </span>
</div>
                <div class="fee-detail-row">
                    <span class="fee-detail-label">Calculation:</span>
                    <span class="fee-detail-value">${escapeHtml(type.calculation_type || "fixed")}</span>
                </div>
                <div class="fee-detail-row">
                    <span class="fee-detail-label">Recurring:</span>
                    <span class="fee-detail-value">${type.is_recurring ? escapeHtml(type.recurrence_period || "Monthly") : "One-time"}</span>
                </div>
                <div class="fee-detail-row">
                    <span class="fee-detail-label">Status:</span>
                    <span class="fee-detail-value">
                        ${
                          type.status == 1
                            ? '<span class="badge-active">Active</span>'
                            : '<span class="badge-inactive">Inactive</span>'
                        }
                    </span>
                </div>
            </div>
            <div class="fee-actions">
                <button class="btn-icon btn-edit" onclick="editFeeType(${type.fee_type_id})" title="Edit">
                    <i class="fas fa-edit"></i>
                </button>
               <button class="btn-icon btn-toggle-status" onclick="toggleFeeTypeStatus(${type.fee_type_id})" title="${type.status == 1 ? "Deactivate" : "Activate"}">
    <i class="fas ${type.status == 1 ? "fa-toggle-on" : "fa-toggle-off"}"></i>
</button>
            </div>
        </div>
    `,
    )
    .join("");
}

function renderDeactivatedFeeTypes(types) {
    const container = dom.deactivatedFeeTypesGrid;
    if (!container) return;

    if (!types || types.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-check-circle"></i>
                <p>No deactivated fee types found</p>
            </div>`;
        return;
    }

    container.innerHTML = types.map(type => `
        <div class="fee-type-card inactive">
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
                    <span class="fee-detail-value">
                        ${parseInt(type.is_mandatory) === 1 ? 
                            '<span class="badge-mandatory">Mandatory</span>' : 
                            '<span class="badge-optional">Optional</span>'}
                    </span>
                </div>
                <div class="fee-detail-row">
                    <span class="fee-detail-label">Calculation:</span>
                    <span class="fee-detail-value">${escapeHtml(type.calculation_type || "fixed")}</span>
                </div>
                <div class="fee-detail-row">
                    <span class="fee-detail-label">Recurring:</span>
                    <span class="fee-detail-value">${parseInt(type.is_recurring) === 1 ? 
                        escapeHtml(type.recurrence_period || "Monthly") : 
                        "One-time"}</span>
                </div>
                <div class="fee-detail-row">
                    <span class="fee-detail-label">Status:</span>
                    <span class="fee-detail-value">
                        <span class="badge-inactive">Deactivated</span>
                    </span>
                </div>
            </div>
            <div class="fee-actions">
            <span class="fee-detail-label">Click on Toggle to Re-activate Fee:</span>
                <button class="btn-icon btn-toggle-status" onclick="toggleFeeTypeStatus(${type.fee_type_id})" title="Activate">
                    <i class="fas fa-toggle-off"></i>
                </button>
            </div>
        </div>
    `).join("");
}

async function toggleFeeTypeStatus(feeTypeId) {
    // Search in both active and deactivated fee types
    let type = state.feeTypes.find((t) => t.fee_type_id == feeTypeId);
    if (!type) {
        type = state.deactivatedFeeTypes.find((t) => t.fee_type_id == feeTypeId);
    }
    
    if (!type) {
        showToast("Fee type not found", "error");
        return;
    }

    const currentStatus = parseInt(type.status);
    const newStatus = currentStatus === 1 ? 0 : 1;
    const action = newStatus === 1 ? "activate" : "deactivate";

    const confirmed = await showCustomConfirm({
        title: `${action.charAt(0).toUpperCase() + action.slice(1)} Fee Type`,
        type: action === "deactivate" ? "warning" : "info",
        message: `Are you sure you want to ${action} "${type.fee_name}"?`,
        details: [
            { label: "Fee Code", value: type.fee_code },
            {
                label: "Current Status",
                value: currentStatus === 1 ? "Active" : "Inactive",
            },
        ],
        confirmText: `Yes, ${action.charAt(0).toUpperCase() + action.slice(1)}`,
        confirmClass: action === "deactivate" ? "btn-danger" : "btn-success",
        cancelText: "Cancel",
    });

    if (!confirmed) return;

    try {
        const response = await fetch(
            "../backend/fee_management/manage_fee_type.php",
            {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    action: "toggle_status",
                    fee_type_id: feeTypeId,
                    status: newStatus,
                }),
            }
        );
        const result = await response.json();

        if (result.success) {
            showToast(
                result.data || `Fee type ${action}d successfully`,
                "success"
            );
            // Refresh both lists
            await loadFeeTypes();
            await loadDeactivatedFeeTypes();
        } else {
            throw new Error(result.data || "Failed to update status");
        }
    } catch (error) {
        console.error("Error toggling fee type status:", error);
        showToast(error.message, "error");
    }
}

// ==================== FEE TYPES CRUD ====================
function openFeeTypeModal(feeTypeId = null) {
  const modal = document.getElementById("feeTypeModal");
  const title = document.getElementById("feeTypeModalTitle");
  const form = document.getElementById("feeTypeForm");
  const editId = document.getElementById("editFeeTypeId");

  if (feeTypeId) {
    // Edit mode
    const type = state.feeTypes.find(
      (t) => parseInt(t.fee_type_id) === feeTypeId,
    );
    console.log("Fee types found are : ", state.feeTypes);
    console.log("Type ID passed is", feeTypeId);
    console.log("Type filtered is", type);
    if (!type) {
      showToast("Fee type not found", "error");
      return;
    }

    title.textContent = "Edit Fee Type";
    editId.value = type.fee_type_id;
    document.getElementById("feeCode").value = type.fee_code;
    document.getElementById("feeName").value = type.fee_name;
    document.getElementById("feeDescription").value = type.description || "";
    document.getElementById("isMandatory").value = type.is_mandatory ? 1 : 0;
    document.getElementById("calculationType").value =
      type.calculation_type || "fixed";
    document.getElementById("isRecurring").value = type.is_recurring ? 1 : 0;
    document.getElementById("recurrencePeriod").value =
      type.recurrence_period || "monthly";
    document.getElementById("displayOrder").value = type.display_order || 0;

    // Enable/disable fields based on recurring status
    toggleRecurrenceFields(type.is_recurring ? 1 : 0);
  } else {
    // Add mode
    title.textContent = "Add Fee Type";
    form.reset();
    editId.value = "";
    document.getElementById("feeCode").value = "";
    document.getElementById("feeName").value = "";
    document.getElementById("feeDescription").value = "";
    document.getElementById("isMandatory").value = 1;
    document.getElementById("calculationType").value = "fixed";
    document.getElementById("isRecurring").value = 0;
    document.getElementById("recurrencePeriod").value = "monthly";
    document.getElementById("displayOrder").value = 0;

    // Enable recurrence period by default
    toggleRecurrenceFields(0);
  }

  modal.classList.add("active");
}

function toggleRecurrenceFields(isRecurring) {
  const recurrencePeriod = document.getElementById("recurrencePeriod");
  const recurrenceLabel = document.querySelector(
    'label[for="recurrencePeriod"]',
  );

  if (parseInt(isRecurring) === 1) {
    recurrencePeriod.disabled = false;
    recurrencePeriod.style.opacity = "1";
    if (recurrenceLabel) recurrenceLabel.style.opacity = "1";
  } else {
    recurrencePeriod.disabled = true;
    recurrencePeriod.style.opacity = "0.5";
    if (recurrenceLabel) recurrenceLabel.style.opacity = "0.5";
    recurrencePeriod.value = "one-time";
  }
}

function editFeeType(id) {
  openFeeTypeModal(id);
}

function closeFeeTypeModal() {
  document.getElementById("feeTypeModal").classList.remove("active");
}

async function saveFeeType() {
  const feeTypeId = document.getElementById("editFeeTypeId").value;
  const isEdit = feeTypeId ? true : false;

  const data = {
    fee_type_id: feeTypeId || null,
    fee_code: document.getElementById("feeCode").value.trim(),
    fee_name: document.getElementById("feeName").value.trim(),
    description: document.getElementById("feeDescription").value.trim(),
    is_mandatory: parseInt(document.getElementById("isMandatory").value),
    calculation_type: document.getElementById("calculationType").value,
    is_recurring: parseInt(document.getElementById("isRecurring").value),
    recurrence_period: document.getElementById("recurrencePeriod").value,
    display_order: parseInt(document.getElementById("displayOrder").value) || 0,
    action: isEdit ? "update" : "create",
  };

  // Validate required fields
  if (!data.fee_code) {
    showToast("Fee code is required", "error");
    return;
  }
  if (!data.fee_name) {
    showToast("Fee name is required", "error");
    return;
  }
  if (data.is_recurring === 1 && !data.recurrence_period) {
    showToast("Recurrence period is required for recurring fees", "error");
    return;
  }

  const saveBtn = document.querySelector("#feeTypeModal .btn-primary");
  const originalText = saveBtn.innerHTML;
  saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
  saveBtn.disabled = true;

  try {
    const response = await fetch(
      "../backend/fee_management/manage_fee_type.php",
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(data),
      },
    );
    const result = await response.json();

    if (result.success) {
      showToast(result.data || "Fee type saved successfully", "success");
      closeFeeTypeModal();
      loadFeeTypes();
    } else {
      throw new Error(result.message || "Failed to save fee type");
    }
  } catch (error) {
    console.error("Error saving fee type:", error);
    showToast(error.message, "error");
  } finally {
    saveBtn.innerHTML = originalText;
    saveBtn.disabled = false;
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
      select.innerHTML =
        '<option value="">Select a property</option>' +
        properties
          .map(
            (p) =>
              `<option value="${p.property_code}">${escapeHtml(p.name)}</option>`,
          )
          .join("");
      
      // Remove old event listener to avoid duplicates
      select.removeEventListener("change", handlePropertyChange);
      select.addEventListener("change", handlePropertyChange);
    }
  } catch (error) {
    console.error("Error loading properties:", error);
    showToast("Failed to load properties", "error");
  }
}

function handlePropertyChange() {
  const select = dom.propertySelect;
  const propertyCode = select.value;
  
  if (propertyCode) {
    // Show loading state
    const container = dom.propertyFeesContent;
    if (container) {
      container.innerHTML = `
        <div class="loading-spinner">
          <div class="spinner"></div>
          <p>Loading property fees...</p>
        </div>
      `;
    }
    
    // Load data for selected property
    loadPropertyFees(propertyCode);
    loadApartmentTypes(propertyCode);
  } else {
    // Reset to empty state when "Select a property" is chosen
    state.currentPropertyFees = {};
    state.apartmentTypes = [];
    
    const container = dom.propertyFeesContent;
    if (container) {
      container.innerHTML = `
        <div class="empty-state-fees">
          <i class="fas fa-building"></i>
          <p>Please select a property to view its fee configuration.</p>
        </div>`;
    }
  }
}

function resetPropertyFeesView() {
  state.currentPropertyFees = {};
  state.apartmentTypes = [];
  
  const container = dom.propertyFeesContent;
  if (container) {
    container.innerHTML = `
      <div class="loading-spinner">
        <div class="spinner"></div>
        <p>Select a property to view fees</p>
      </div>
    `;
  }
}

async function loadPropertyFees(propertyCode) {
  try {
    const response = await fetch(
      `../backend/fee_management/fetch_property_fees.php?property_code=${propertyCode}`,
    );
    const data = await response.json();
    if (data.success) {
      state.currentPropertyFees = data.message?.property_fees || {};
      console.log("Property fees structure:", state.currentPropertyFees);
      console.log("Keys:", Object.keys(state.currentPropertyFees));
      console.log("Sample data:", Object.values(state.currentPropertyFees)[0]);
      renderPropertyFees();
    } else {
      state.currentPropertyFees = {};
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
    const response = await fetch(
      `../backend/apartment_types/get_apartment_types.php?property_code=${propertyCode}`,
    );
    const data = await response.json();
    if (data.success) {
      state.apartmentTypes = data.message?.apartment_types || [];
    }
  } catch (error) {
    console.error("Error loading apartment types:", error);
    state.apartmentTypes = [];
  }
}

// ==================== RENDER PROPERTY FEES (UPDATED) ====================
function renderPropertyFees() {
  const container = dom.propertyFeesContent;
  if (!container) return;

  const propertyCode = dom.propertySelect?.value;
  const propertyName = dom.propertySelect?.selectedOptions[0]?.text;

  // If no property selected, show empty state
  if (!propertyCode) {
    state.currentPropertyFees = {};
    container.innerHTML = `
      <div class="empty-state-fees">
        <i class="fas fa-building"></i>
        <p>Please select a property to view its fee configuration.</p>
      </div>`;
    return;
  }

  // If no fees configured for this property
  if (
    !state.currentPropertyFees ||
    Object.keys(state.currentPropertyFees).length === 0
  ) {
    container.innerHTML = `
      <div class="empty-state-fees">
        <i class="fas fa-receipt"></i>
        <p>No fees configured for this property yet.</p>
        <button class="btn-primary" onclick="showAddFeeModal()">
          <i class="fas fa-plus"></i> Add First Fee
        </button>
      </div>`;
    return;
  }

  let html = `
    <div class="property-fees-header">
      <div class="header-left">
        <h3>Fee Configuration</h3>
        <span class="property-name">${escapeHtml(propertyName)}</span>
      </div>
      <div class="header-right">
        <button class="btn-primary" onclick="showAddFeeModal()">
          <i class="fas fa-plus"></i> Add Fee Type
        </button>
      </div>
    </div>
  `;

  // Sort apartment types for consistent display
  const sortedTypes = Object.entries(state.currentPropertyFees).sort((a, b) =>
    a[0].localeCompare(b[0]),
  );

  for (const [typeName, typeData] of sortedTypes) {
    html += `
      <div class="property-fees-section">
        <div class="section-header">
          <div class="section-title">
            <i class="fas fa-door-open"></i>
            <h4>${escapeHtml(typeName)}</h4>
            <span class="apartment-count">${typeData.fees.length} fees</span>
          </div>
        </div>
        <div class="table-responsive">
          <table class="fee-config-table">
            <thead>
              <tr>
                <th>Fee Type</th>
                <th>Amount (₦)</th>
                <th>Mandatory</th>
                <th>Recurrence</th>
                <th>Effective From</th>
                <th style="width: 100px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              ${typeData.fees.map(fee => `
                <tr data-fee-id="${fee.fee_id || fee.tenant_fee_id || ""}" data-fee-type="${fee.fee_type_id}" data-apartment-type="${typeData.apartment_type_id}">
                  <td>
                    <div class="fee-name-cell">
                      <strong>${escapeHtml(fee.fee_name)}</strong>
                      <span class="fee-code">${escapeHtml(fee.fee_code || "")}</span>
                    </div>
                  </td>
                  <td class="amount-cell">₦${formatNumber(fee.amount)}</td>
                  <td>
                    ${parseInt(fee.is_mandatory) === 1 ? 
                      '<span class="badge-mandatory">Mandatory</span>' : 
                      '<span class="badge-optional">Optional</span>'}
                  </td>
                  <td>${fee.is_recurring ? fee.recurrence_period || "Monthly" : "One-time"}</td>
                  <td>${formatDate(fee.effective_from)}</td>
                  <td>
                    <div class="action-buttons">
                      <button class="btn-icon btn-edit" onclick="openEditFeeModal('${propertyName}', '${propertyCode}', ${typeData.apartment_type_id}, ${fee.fee_type_id}, '${typeName}')" title="Edit Fee">
                        <i class="fas fa-edit"></i>
                      </button>
                      <button class="btn-icon btn-delete" onclick="deletePropertyFee('${propertyCode}', ${typeData.apartment_type_id}, ${fee.fee_type_id})" title="Remove Fee">
                        <i class="fas fa-trash-alt"></i>
                      </button>
                    </div>
                  </td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>
      </div>
    `;
  }

  html += `
    <div class="property-fees-footer">
      <button class="btn-regenerate" onclick="regenerateTenantFees('${propertyCode}')">
        <i class="fas fa-sync-alt"></i> Apply to Existing Tenants
      </button>
    </div>`;

  container.innerHTML = html;
}

// ==================== ADD FEE MODAL ====================
async function showAddFeeModal() {
  const propertyCode = dom.propertySelect.value;
  if (!propertyCode) {
    showToast("Please select a property first", "warning");
    return;
  }

  if (state.feeTypes.length === 0) await loadFeeTypes();
  if (state.apartmentTypes.length === 0) await loadApartmentTypes(propertyCode);

  // ===== FIX: Show ALL apartment types, not just ones with existing fees =====
  const availableApartmentTypes = state.apartmentTypes;

  if (availableApartmentTypes.length === 0) {
    showToast(
      "No apartment types found for this property. Please add apartment types first.",
      "error",
    );
    return;
  }

  const modalHtml = `
        <div class="modal active" id="addFeeModal">
            <div class="modal-content" style="max-width: 550px;">
                <div class="modal-header">
                    <h3><i class="fas fa-plus-circle"></i> Add Fee to Property</h3>
                    <button class="modal-close" onclick="closeAddFeeModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Property</label>
                        <input type="text" value="${escapeHtml(dom.propertySelect.selectedOptions[0]?.text)}" disabled>
                    </div>
                    
                    <div class="form-group">
                        <label>Apartment Type *</label>
                        <select id="addFeeApartmentType" class="form-control" onchange="onApartmentTypeChange()">
                            <option value="">-- Select Apartment Type --</option>
                            ${availableApartmentTypes
                              .map(
                                (t) => `
                                <option value="${t.type_id}">${escapeHtml(t.type_name)}</option>
                            `,
                              )
                              .join("")}
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Fee Type *</label>
                        <select id="addFeeType" class="form-control" disabled>
                            <option value="">-- Select Apartment First --</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Amount (₦) *</label>
                        <input type="number" id="addFeeAmount" class="form-control" placeholder="0.00" step="0.01" min="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="closeAddFeeModal()">Cancel</button>
                    <button class="btn-primary" onclick="saveNewFee()">Add Fee</button>
                </div>
            </div>
        </div>`;

  const existingModal = document.getElementById("addFeeModal");
  if (existingModal) existingModal.remove();
  document.body.insertAdjacentHTML("beforeend", modalHtml);
}

function onApartmentTypeChange() {
  const apartmentTypeId = document.getElementById("addFeeApartmentType").value;
  const feeSelect = document.getElementById("addFeeType");

  if (!apartmentTypeId) {
    feeSelect.innerHTML =
      '<option value="">-- Select Apartment First --</option>';
    feeSelect.disabled = true;
    return;
  }

  console.log("Selected apartment type ID:", apartmentTypeId);

  // Get existing fee type IDs for this apartment type
  const existingFeeIds = [];
  const targetId = parseInt(apartmentTypeId);

  // Loop through each apartment type in currentPropertyFees
  for (const [typeName, typeData] of Object.entries(
    state.currentPropertyFees,
  )) {
    console.log("Checking type:", typeName, "Data:", typeData);

    // Check if this matches the selected apartment type
    if (typeData.apartment_type_id === targetId) {
      console.log("Found matching apartment type:", typeName);

      // Get the fees array from this apartment type
      if (typeData.fees && Array.isArray(typeData.fees)) {
        typeData.fees.forEach((fee) => {
          if (fee.fee_type_id) {
            existingFeeIds.push(fee.fee_type_id);
          }
        });
      }
    }
  }

  console.log("Existing fee IDs for this apartment:", existingFeeIds);
  console.log(
    "All fee types:",
    state.feeTypes.map((f) => f.fee_type_id),
  );

  // Filter fee types that are not already assigned
  const availableFeeTypes = state.feeTypes.filter((f) => {
    const feeId = parseInt(f.fee_type_id);
    return !existingFeeIds.includes(feeId);
  });

  console.log("Available fee types:", availableFeeTypes);

  if (availableFeeTypes.length === 0) {
    feeSelect.innerHTML =
      '<option value="">-- No available fee types --</option>';
    feeSelect.disabled = true;
    return;
  }

  feeSelect.innerHTML = `
    <option value="">-- Select Fee Type --</option>
    ${availableFeeTypes
      .map(
        (f) => `
        <option value="${f.fee_type_id}">${escapeHtml(f.fee_name)}${parseInt(f.is_mandatory) === 1 ? " (Mandatory)" : " (Optional)"}</option>
      `,
      )
      .join("")}
`;
  feeSelect.disabled = false;
}

async function saveNewFee() {
  const propertyCode = dom.propertySelect.value;
  const apartmentTypeId = document.getElementById("addFeeApartmentType").value;
  const feeTypeId = document.getElementById("addFeeType").value;
  const amount = parseFloat(document.getElementById("addFeeAmount").value);

  if (!apartmentTypeId) {
    showToast("Please select an apartment type", "error");
    return;
  }

  if (!feeTypeId) {
    showToast("Please select a fee type", "error");
    return;
  }

  if (!amount || amount <= 0) {
    showToast("Please enter a valid amount", "error");
    return;
  }

  const saveBtn = document.querySelector("#addFeeModal .btn-primary");
  const originalText = saveBtn.innerHTML;
  saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
  saveBtn.disabled = true;

  try {
    const response = await fetch(
      "../backend/fee_management/set_property_fees.php",
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          property_code: propertyCode,
          fees: {
            [apartmentTypeId]: {
              [feeTypeId]: amount,
            },
          },
          effective_from: new Date().toISOString().split("T")[0],
        }),
      },
    );
    const result = await response.json();

    if (result.success) {
      showToast("Fee added successfully", "success");
      closeAddFeeModal();
      await loadPropertyFees(propertyCode);

      // Check if tenants exist to apply fees
      const hasTenants = await checkExistingTenants(propertyCode);
      if (hasTenants) {
        showRegeneratePrompt(
          propertyCode,
          dom.propertySelect.selectedOptions[0]?.text,
        );
      }
    } else {
      throw new Error(result.message);
    }
  } catch (error) {
    console.error("Error adding fee:", error);
    showToast(error.message, "error");
  } finally {
    saveBtn.innerHTML = originalText;
    saveBtn.disabled = false;
  }
}

function closeAddFeeModal() {
  const modal = document.getElementById("addFeeModal");
  if (modal) modal.remove();
}

// ==================== EDIT FEE MODAL ====================
async function openEditFeeModal(
  propertyName,
  propertyCode,
  apartmentTypeId,
  feeTypeId,
  apartmentTypeName,
) {
  // Find the fee data
  let feeData = null;
  let feeName = "";
  for (const [typeName, typeData] of Object.entries(
    state.currentPropertyFees,
  )) {
    if (typeData.apartment_type_id === apartmentTypeId) {
      const fee = typeData.fees.find((f) => f.fee_type_id === feeTypeId);
      if (fee) {
        feeData = fee;
        feeName = fee.fee_name;
        break;
      }
    }
  }

  if (!feeData) {
    showToast("Fee data not found", "error");
    return;
  }

  const modalHtml = `
        <div class="modal active" id="editFeeModal">
            <div class="modal-content" style="max-width: 500px;">
                <div class="modal-header">
                    <h3><i class="fas fa-edit"></i> Edit Fee</h3>
                    <button class="modal-close" onclick="closeEditFeeModal()">&times;</button>
                </div>
                <div class="modal-body">
                <div class="form-group">
                        <label>Property Name</label>
                        <input type="text" value="${escapeHtml(propertyName)}" disabled>
                    </div>
                    <div class="form-group">
                        <label>Apartment Type</label>
                        <input type="text" value="${escapeHtml(apartmentTypeName)}" disabled>
                    </div>
                    <div class="form-group">
                        <label>Fee Type</label>
                        <input type="text" value="${escapeHtml(feeName)}" disabled>
                    </div>
                    <div class="form-group">
                        <label>Amount (₦) *</label>
                        <input type="number" id="editFeeAmount" class="form-control" value="${feeData.amount}" step="0.01" min="0">
                    </div>
                    <div class="form-group">
                        <label>Effective From</label>
                        <input type="date" id="editEffectiveFrom" class="form-control" value="${feeData.effective_from || new Date().toISOString().split("T")[0]}">
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="closeEditFeeModal()">Cancel</button>
                    <button class="btn-primary" onclick="saveEditFee('${propertyCode}', ${apartmentTypeId}, ${feeTypeId})">Save Changes</button>
                </div>
            </div>
        </div>`;

  const existingModal = document.getElementById("editFeeModal");
  if (existingModal) existingModal.remove();
  document.body.insertAdjacentHTML("beforeend", modalHtml);
}

async function saveEditFee(propertyCode, apartmentTypeId, feeTypeId) {
  const amount = parseFloat(document.getElementById("editFeeAmount").value);
  const effectiveFrom = document.getElementById("editEffectiveFrom").value;

  if (!amount || amount <= 0) {
    showToast("Please enter a valid amount", "error");
    return;
  }

  const saveBtn = document.querySelector("#editFeeModal .btn-primary");
  const originalText = saveBtn.innerHTML;
  saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
  saveBtn.disabled = true;

  try {
    const response = await fetch(
      "../backend/fee_management/set_property_fees.php",
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          property_code: propertyCode,
          fees: {
            [apartmentTypeId]: {
              [feeTypeId]: amount,
            },
          },
          effective_from:
            effectiveFrom || new Date().toISOString().split("T")[0],
        }),
      },
    );
    const result = await response.json();

    if (result.success) {
      showToast("Fee updated successfully", "success");
      closeEditFeeModal();
      await loadPropertyFees(propertyCode);

      // Check if tenants exist to apply updated fees
      const hasTenants = await checkExistingTenants(propertyCode);
      if (hasTenants) {
        showRegeneratePrompt(
          propertyCode,
          dom.propertySelect.selectedOptions[0]?.text,
        );
      }
    } else {
      throw new Error(result.message);
    }
  } catch (error) {
    console.error("Error updating fee:", error);
    showToast(error.message, "error");
  } finally {
    saveBtn.innerHTML = originalText;
    saveBtn.disabled = false;
  }
}

function closeEditFeeModal() {
  const modal = document.getElementById("editFeeModal");
  if (modal) modal.remove();
}

// ==================== DELETE PROPERTY FEE ====================
async function deletePropertyFee(propertyCode, apartmentTypeId, feeTypeId) {
  // Find fee name for confirmation
  let feeName = "";
  for (const [typeName, typeData] of Object.entries(
    state.currentPropertyFees,
  )) {
    if (typeData.apartment_type_id === apartmentTypeId) {
      const fee = typeData.fees.find((f) => f.fee_type_id === feeTypeId);
      if (fee) {
        feeName = fee.fee_name;
        break;
      }
    }
  }

  const confirmed = await showCustomConfirm({
    title: "Remove Fee",
    type: "danger",
    message: `Are you sure you want to remove "${feeName}" from this property?`,
    details: [
      {
        label: "Apartment Type",
        value:
          Object.keys(state.currentPropertyFees).find(
            (k) =>
              state.currentPropertyFees[k].apartment_type_id ===
              apartmentTypeId,
          ) || "N/A",
      },
      { label: "Fee Type", value: feeName },
    ],
    confirmText: "Yes, Remove",
    confirmClass: "btn-danger",
    cancelText: "Cancel",
  });

  if (!confirmed) return;

  try {
    const response = await fetch(
      "../backend/fee_management/delete_property_fee.php",
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          property_code: propertyCode,
          apartment_type_id: apartmentTypeId,
          fee_type_id: feeTypeId,
        }),
      },
    );
    const result = await response.json();

    if (result.success) {
      showToast("Fee removed successfully", "success");
      await loadPropertyFees(propertyCode);

      // Check if tenants exist to apply updated fees
      const hasTenants = await checkExistingTenants(propertyCode);
      if (hasTenants) {
        showRegeneratePrompt(
          propertyCode,
          dom.propertySelect.selectedOptions[0]?.text,
        );
      }
    } else {
      throw new Error(result.message);
    }
  } catch (error) {
    console.error("Error removing fee:", error);
    showToast(error.message, "error");
  }
}

// ==================== CONFIGURE FEES (Bulk Edit) ====================
async function showConfigureFeesModal() {
  const propertyCode = dom.propertySelect.value;
  if (!propertyCode) {
    showToast("Please select a property first", "warning");
    return;
  }

  if (state.feeTypes.length === 0) await loadFeeTypes();
  if (Object.keys(state.currentPropertyFees).length === 0)
    await loadPropertyFees(propertyCode);
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
                        ${state.apartmentTypes
                          .map(
                            (type) => `
                            <div class="apartment-type-fees">
                                <h4>${escapeHtml(type.type_name)} ${type.apartment_count > 0 ? `<span class="badge-info">${type.apartment_count} apartments</span>` : ""}</h4>
                                <div class="fee-config-grid">
                                    ${state.feeTypes
                                      .map(
                                        (fee) => `
                                        <div class="fee-config-item">
                                            <label>${escapeHtml(fee.fee_name)}${fee.is_mandatory ? '<span class="mandatory-star">*</span>' : ""}</label>
                                            <div class="fee-input-wrapper">
                                                <span class="currency-symbol">₦</span>
                                                <input type="number" class="amount-input" data-apartment-type="${type.type_id}" data-fee-type="${fee.fee_type_id}" placeholder="0.00" step="0.01" value="${getExistingFeeAmount(type.type_id, fee.fee_type_id)}">
                                            </div>
                                            <small class="fee-description">${fee.is_recurring ? `Recurs: ${fee.recurrence_period}` : "One-time fee"}</small>
                                        </div>
                                    `,
                                      )
                                      .join("")}
                                </div>
                            </div>
                        `,
                          )
                          .join("")}
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="closeConfigModal()">Cancel</button>
                    <button class="btn-primary" onclick="savePropertyFees()">Save Fees</button>
                </div>
            </div>
        </div>`;

  const existingModal = document.getElementById("configFeesModal");
  if (existingModal) existingModal.remove();
  document.body.insertAdjacentHTML("beforeend", modalHtml);
}

function getExistingFeeAmount(apartmentTypeId, feeTypeId) {
  for (const [typeName, typeData] of Object.entries(
    state.currentPropertyFees,
  )) {
    if (typeData.apartment_type_id === apartmentTypeId) {
      const existingFee = typeData.fees.find(
        (f) => f.fee_type_id === feeTypeId,
      );
      if (existingFee) return existingFee.amount;
    }
  }
  return "";
}

function closeConfigModal() {
  const modal = document.getElementById("configFeesModal");
  if (modal) modal.remove();
}

async function savePropertyFees() {
  const propertyCode = dom.propertySelect.value;
  const propertyName = dom.propertySelect.selectedOptions[0]?.text;
  const feeInputs = document.querySelectorAll("#feeConfigForm .amount-input");
  const fees = {};

  feeInputs.forEach((input) => {
    const apartmentTypeId = parseInt(input.dataset.apartmentType);
    const feeTypeId = parseInt(input.dataset.feeType);
    const amount = parseFloat(input.value) || 0;
    if (!fees[apartmentTypeId]) fees[apartmentTypeId] = {};
    fees[apartmentTypeId][feeTypeId] = amount;
  });

  const saveBtn = document.querySelector("#configFeesModal .btn-primary");
  const originalText = saveBtn.innerHTML;
  saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
  saveBtn.disabled = true;

  try {
    const response = await fetch(
      "../backend/fee_management/set_property_fees.php",
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          property_code: propertyCode,
          fees: fees,
          effective_from: new Date().toISOString().split("T")[0],
        }),
      },
    );
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

// ==================== TENANT FEES ====================
async function loadTenantFees() {
  const status = dom.tenantFeeStatusFilter?.value || "";
  const search = dom.tenantSearch?.value || "";

  const params = new URLSearchParams();
  if (status) params.append("status", status);
  if (search) params.append("search", search);

  try {
    const response = await fetch(
      `../backend/fee_management/fetch_tenant_fees.php?${params}`,
    );
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
                    ${fees
                      .map(
                        (fee) => `
                        <tr>
                            <td>${escapeHtml(fee.tenant_name || fee.tenant_code || "N/A")}</td>
                            <td>${escapeHtml(fee.fee_name)}</td>
                            <td>₦${formatNumber(fee.amount)}</td>
                            <td>${formatDate(fee.due_date)}</td>
                            <td><span class="status-badge status-${fee.status}">${fee.status.toUpperCase()}</span></td>
                            <td>
                                <button class="btn-icon" onclick="viewTenantFee(${fee.tenant_fee_id})" title="View"><i class="fas fa-eye"></i></button>
                                <button class="btn-icon" onclick="markFeeAsPaid(${fee.tenant_fee_id})" title="Mark as Paid"><i class="fas fa-check-circle"></i></button>
                            </td>
                        </tr>
                    `,
                      )
                      .join("")}
                </tbody>
            </table>
        </div>`;
}

// ==================== VIEW FEE DETAILS ====================
async function viewTenantFee(tenantFeeId) {
  try {
    const modalBody = document.getElementById("tenantFeeDetails");
    if (!modalBody) return;

    modalBody.innerHTML =
      '<div class="loading-spinner"><div class="spinner"></div> Loading...</div>';
    openModal("viewTenantFeeModal");

    const response = await fetch(
      `../backend/fee_management/fetch_single_tenant_fee.php?tenant_fee_id=${tenantFeeId}`,
    );
    const data = await response.json();

    if (data.success) {
      const fee = data.message?.fee || data.data?.fee;
      renderTenantFeeDetails(fee, tenantFeeId);
    } else {
      modalBody.innerHTML = `<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>${data.message || "Failed to load fee details"}</p></div>`;
    }
  } catch (error) {
    console.error("Error loading fee details:", error);
    document.getElementById("tenantFeeDetails").innerHTML =
      `<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading fee details. Please try again.</p></div>`;
  }
}

function renderTenantFeeDetails(fee, tenantFeeId) {
  const container = document.getElementById("tenantFeeDetails");
  const canMarkPaid = fee.status === "pending" || fee.status === "overdue";

  const markPaidBtn = document.getElementById("markPaidBtn");
  if (markPaidBtn) {
    markPaidBtn.style.display = canMarkPaid ? "inline-flex" : "none";
    markPaidBtn.setAttribute("data-fee-id", tenantFeeId);
  }

  let paymentInfo = "";
  if (fee.status === "paid" && fee.notes) {
    const paymentMatch = fee.notes.match(
      /Paid on (.*?) via (.*?)\. Receipt: (.*?)(?:\n|$)/,
    );
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
                ${fee.is_recurring ? `<div class="detail-row"><span class="detail-label">Recurrence:</span><span class="detail-value">${fee.recurrence_period || "Monthly"}</span></div>` : ""}
            </div>
            <div class="detail-section">
                <h4>Tenant Information</h4>
                <div class="detail-row"><span class="detail-label">Tenant Name:</span><span class="detail-value">${escapeHtml(fee.tenant_name)}</span></div>
                <div class="detail-row"><span class="detail-label">Tenant Code:</span><span class="detail-value">${escapeHtml(fee.tenant_code)}</span></div>
                <div class="detail-row"><span class="detail-label">Apartment:</span><span class="detail-value">${escapeHtml(fee.apartment_number || "N/A")}</span></div>
            </div>
            ${paymentInfo}
            ${fee.notes && fee.status !== "paid" ? `<div class="detail-section"><h4>Notes</h4><div class="notes-content">${escapeHtml(fee.notes)}</div></div>` : ""}
        </div>`;
}

function closeViewTenantFeeModal() {
  closeModal("viewTenantFeeModal");
}

// ==================== MARK FEE AS PAID ====================
async function markFeeAsPaidFromModal() {
  const btn = document.getElementById("markPaidBtn");
  const feeId = btn?.getAttribute("data-fee-id");
  if (feeId) await markFeeAsPaid(feeId);
}

async function markFeeAsPaid(feeId) {
  try {
    // Get fee details for confirmation
    const response = await fetch(
      `../backend/fee_management/fetch_single_tenant_fee.php?tenant_fee_id=${feeId}`,
    );
    const data = await response.json();

    let feeName = "",
      feeAmount = "",
      tenantName = "";
    if (data.success) {
      const fee = data.message?.fee || data.data?.fee;
      feeName = fee.fee_name;
      feeAmount = formatNumber(fee.amount);
      tenantName = fee.tenant_name;
    }

    const confirmed = await showCustomConfirm({
      title: "Mark Fee as Paid",
      type: "warning",
      message: "Are you sure you want to mark this fee as paid?",
      details: [
        { label: "Fee Name", value: feeName },
        { label: "Amount", value: `₦${feeAmount}` },
        { label: "Tenant", value: tenantName },
      ],
      confirmText: "Yes, Mark as Paid",
      confirmClass: "btn-primary",
      cancelText: "Cancel",
    });

    if (!confirmed) return;

    const btn =
      event?.target?.closest(".btn-icon") ||
      document.getElementById("markPaidBtn");
    const originalText = btn?.innerHTML || "Mark as Paid";
    if (btn) {
      btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
      btn.disabled = true;
    }

    const markResponse = await fetch(
      "../backend/fee_management/mark_fee_paid.php",
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ tenant_fee_id: feeId }),
      },
    );

    const markData = await markResponse.json();

    if (markData.success) {
      const responseData = markData.message || markData.data || {};
      await showCustomConfirm({
        title: "Success!",
        type: "success",
        message: responseData.message || "Fee marked as paid successfully!",
        details: [
          {
            label: "Receipt Number",
            value: responseData.receipt_number || "N/A",
          },
          {
            label: "Payment Date",
            value:
              formatDateTime(responseData.payment_date) ||
              new Date().toLocaleString(),
          },
        ],
        confirmText: "OK",
        confirmClass: "btn-success",
        cancelText: "",
      });
      showToast("Fee marked as paid successfully", "success");
      closeViewTenantFeeModal();
      loadTenantFees();
    } else {
      throw new Error(markData.message || "Failed to mark fee as paid");
    }
  } catch (error) {
    console.error("Error marking fee as paid:", error);
    showToast(error.message, "error");
    await showCustomConfirm({
      title: "Error",
      type: "danger",
      message: error.message || "Failed to mark fee as paid. Please try again.",
      confirmText: "OK",
      confirmClass: "btn-danger",
      cancelText: "",
    });
  } finally {
    const btn =
      event?.target?.closest(".btn-icon") ||
      document.getElementById("markPaidBtn");
    if (btn) {
      btn.innerHTML = '<i class="fas fa-check-circle"></i>';
      btn.disabled = false;
    }
  }
}

// ==================== REGENERATE PROMPT ====================
async function showRegeneratePrompt(propertyCode, propertyName) {
  try {
    const hasTenants = await checkExistingTenants(propertyCode);
    if (hasTenants) {
      const existingBanner = document.getElementById("regenerateBanner");
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
        container.insertAdjacentHTML("afterbegin", bannerHtml);
        document
          .getElementById("regenerateBanner")
          .scrollIntoView({ behavior: "smooth", block: "center" });
      }
    }
  } catch (error) {
    console.error("Error checking tenants:", error);
  }
}

async function checkExistingTenants(propertyCode) {
  try {
    const response = await fetch(
      `../backend/properties/check_property_tenants.php?property_code=${propertyCode}`,
    );
    const data = await response.json();
    return data.success && (data.message?.has_tenants || false);
  } catch (error) {
    console.error("Error checking tenants:", error);
    return false;
  }
}

async function regenerateTenantFees(propertyCode) {
  const regenerateBtn = document.querySelector(".btn-regenerate");
  if (!regenerateBtn) return;

  const originalText = regenerateBtn.innerHTML;
  regenerateBtn.innerHTML =
    '<i class="fas fa-spinner fa-spin"></i> Processing...';
  regenerateBtn.disabled = true;

  try {
    const response = await fetch(
      `../backend/fee_management/regenerate_tenant_fees.php`,
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ property_code: propertyCode }),
      },
    );
    const data = await response.json();

    if (data.success) {
      const feesCreated =
        data.data?.fees_created || data.message?.fees_created || 0;
      showToast(
        `Successfully applied ${feesCreated} new fee(s) to existing tenants`,
        "success",
      );
      dismissRegenerateBanner();
      if (
        document.getElementById("tenant-fees-tab").classList.contains("active")
      )
        loadTenantFees();
    } else {
      throw new Error(data.message || "Failed to apply fees");
    }
  } catch (error) {
    console.error("Error regenerating tenant fees:", error);
    showToast(
      error.message || "Failed to apply fees to existing tenants",
      "error",
    );
  } finally {
    regenerateBtn.innerHTML = originalText;
    regenerateBtn.disabled = false;
  }
}

function dismissRegenerateBanner() {
  const banner = document.getElementById("regenerateBanner");
  if (banner) banner.remove();
}

// ==================== CUSTOM CONFIRMATION MODAL ====================
function showCustomConfirm(options) {
  return new Promise((resolve) => {
    state.confirmResolve = resolve;

    const modal = document.getElementById("customConfirmModal");
    const titleEl = document.getElementById("confirmTitle");
    const messageEl = document.getElementById("confirmMessage");
    const iconEl = document.getElementById("confirmIcon");
    const detailsEl = document.getElementById("confirmDetails");
    const cancelBtn = document.getElementById("confirmCancelBtn");
    const okBtn = document.getElementById("confirmOkBtn");

    titleEl.textContent = options.title || "Confirm Action";
    messageEl.textContent =
      options.message || "Are you sure you want to proceed?";

    const iconType = options.type || "warning";
    iconEl.className = "confirm-icon " + iconType;
    const icons = {
      danger: "fa-exclamation-triangle",
      success: "fa-check-circle",
      info: "fa-info-circle",
      warning: "fa-exclamation-triangle",
    };
    iconEl.innerHTML = `<i class="fas ${icons[iconType] || icons.warning}"></i>`;

    okBtn.textContent = options.confirmText || "Confirm";
    okBtn.className = options.confirmClass || "btn-primary";
    cancelBtn.textContent = options.cancelText || "Cancel";

    if (options.details?.length) {
      detailsEl.innerHTML = `<div class="confirm-details">${options.details.map((d) => `<div class="confirm-detail-row"><span class="confirm-detail-label">${escapeHtml(d.label)}:</span><span class="confirm-detail-value">${escapeHtml(d.value)}</span></div>`).join("")}</div>`;
      detailsEl.style.display = "block";
    } else {
      detailsEl.style.display = "none";
    }

    modal.style.display = "flex";
    modal.classList.add("active");
    document.body.style.overflow = "hidden";

    const oldOkBtn = okBtn.cloneNode(true);
    const oldCancelBtn = cancelBtn.cloneNode(true);
    okBtn.parentNode.replaceChild(oldOkBtn, okBtn);
    cancelBtn.parentNode.replaceChild(oldCancelBtn, cancelBtn);

    oldOkBtn.onclick = () => {
      closeCustomConfirmModal();
      resolve(true);
    };
    oldCancelBtn.onclick = () => {
      closeCustomConfirmModal();
      resolve(false);
    };

    const handleEscape = (e) => {
      if (e.key === "Escape") {
        closeCustomConfirmModal();
        resolve(false);
        document.removeEventListener("keydown", handleEscape);
      }
    };
    document.addEventListener("keydown", handleEscape);

    window._confirmCleanup = () =>
      document.removeEventListener("keydown", handleEscape);
  });
}

function closeCustomConfirmModal() {
  const modal = document.getElementById("customConfirmModal");
  modal.style.display = "none";
  modal.classList.remove("active");
  document.body.style.overflow = "";
  if (window._confirmCleanup) window._confirmCleanup();
}

function searchTenantFees() {
  loadTenantFees();
}

// ==================== MODAL FUNCTIONS ====================
function openModal(modalId) {
  const modal = document.getElementById(modalId);
  if (modal) {
    modal.classList.add("active");
    document.body.style.overflow = "hidden";
  }
}

function closeModal(modalId) {
  const modal = document.getElementById(modalId);
  if (modal) {
    modal.classList.remove("active");
    document.body.style.overflow = "";
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
  if (!dateString) return "N/A";
  try {
    return new Date(dateString).toLocaleDateString("en-US", {
      year: "numeric",
      month: "short",
      day: "numeric",
      hour: "2-digit",
      minute: "2-digit",
    });
  } catch {
    return dateString;
  }
}

function escapeHtml(text) {
  if (!text) return "";
  const div = document.createElement("div");
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
