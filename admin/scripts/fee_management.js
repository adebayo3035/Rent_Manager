let currentPropertyFees = {};
let apartmentTypes = [];
let feeTypes = [];

// Tab switching
document.querySelectorAll(".tab-btn").forEach((btn) => {
  btn.addEventListener("click", () => {
    const tab = btn.dataset.tab;
    document
      .querySelectorAll(".tab-btn")
      .forEach((b) => b.classList.remove("active"));
    document
      .querySelectorAll(".tab-content")
      .forEach((c) => c.classList.remove("active"));
    btn.classList.add("active");
    document.getElementById(`${tab}-tab`).classList.add("active");

    if (tab === "fee-types") loadFeeTypes();
    if (tab === "property-fees") loadProperties();
    if (tab === "tenant-fees") loadTenantFees();
  });
});

// Load Fee Types
async function loadFeeTypes() {
  try {
    const response = await fetch(
      "../backend/fee_management/fetch_fee_types.php",
    );
    const data = await response.json();

    if (data.success) {
      feeTypes = data.message.fee_types;
      renderFeeTypes(feeTypes);
    }
  } catch (error) {
    console.error("Error loading fee types:", error);
    showToast("Failed to load fee types", "error");
  }
}

function renderFeeTypes(types) {
  const container = document.getElementById("feeTypesGrid");
  if (!types || types.length === 0) {
    container.innerHTML =
      '<div class="empty-state"><i class="fas fa-receipt"></i><p>No fee types found</p><button class="btn-primary" onclick="openFeeTypeModal()">Add Fee Type</button></div>';
    return;
  }

  container.innerHTML = types
    .map(
      (type) => `
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
                            <span class="fee-detail-value">
                                ${type.is_mandatory ? '<span class="badge-mandatory">Mandatory</span>' : '<span class="badge-optional">Optional</span>'}
                            </span>
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
            `,
    )
    .join("");
}

// Fee Type Modal
function openFeeTypeModal() {
  document.getElementById("feeTypeModalTitle").textContent = "Add Fee Type";
  document.getElementById("feeTypeForm").reset();
  document.getElementById("editFeeTypeId").value = "";
  document.getElementById("feeTypeModal").classList.add("active");
}

function editFeeType(id) {
  const type = feeTypes.find((t) => t.fee_type_id === id);
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

// Load Properties for Property Fees
// Load Properties for Property Fees
async function loadProperties() {
  try {
    const response = await fetch("../backend/properties/get_properties.php");
    const data = await response.json();

    console.log("Properties response:", data); // Debug log

    if (data.success) {
      // Fix: Access data.data.properties instead of data.message.properties
      const properties = data.message?.properties || [];
      const select = document.getElementById("propertySelect");
      select.innerHTML =
        '<option value="">Select a property</option>' +
        properties
          .map((p) => `<option value="${p.property_code}">${p.name}</option>`)
          .join("");

      select.addEventListener("change", () => {
        if (select.value) {
          loadPropertyFees(select.value);
          loadApartmentTypes(select.value);
        }
      });
    } else {
      throw new Error(data.message || "Failed to load properties");
    }
  } catch (error) {
    console.error("Error loading properties:", error);
    showToast("Failed to load properties", "error");
  }
}

async function loadPropertyFees(propertyCode) {
    try {
        const response = await fetch(
            `../backend/fee_management/fetch_property_fees.php?property_code=${propertyCode}`
        );
        const data = await response.json();

        console.log("Property fees response:", data); // Debug log

        if (data.success) {
            // Your API structure: data.message contains the property_fees object
            // Access data.message.property_fees (not data.data.property_fees)
            currentPropertyFees = data.message?.property_fees || {};
            console.log("Parsed property fees:", currentPropertyFees);
            renderPropertyFees();
        } else {
            throw new Error(data.message || "Failed to load property fees");
        }
    } catch (error) {
        console.error("Error loading property fees:", error);
        currentPropertyFees = {};
        renderPropertyFees();
    }
}

async function loadApartmentTypes(propertyCode) {
  try {
    const response = await fetch(
      `../backend/apartment_types/get_apartment_types.php?property_code=${propertyCode}`,
    );
    const data = await response.json();

    console.log("Apartment types response:", data); // Debug log

    if (data.success) {
      // Fix: Access data.data.apartment_types
      apartmentTypes = data.message?.apartment_types || [];
      console.log("Loaded apartment types:", apartmentTypes);
    } else {
      throw new Error(data.message || "Failed to load apartment types");
    }
  } catch (error) {
    console.error("Error loading apartment types:", error);
    apartmentTypes = [];
  }
}

function renderPropertyFees() {
    const container = document.getElementById('propertyFeesContent');
    if (!container) return;
    
    console.log("Rendering property fees:", currentPropertyFees);
    
    if (!currentPropertyFees || Object.keys(currentPropertyFees).length === 0) {
        container.innerHTML = `
            <div class="property-fees-section">
                <p>No fees configured for this property yet.</p>
                <button class="btn-primary" onclick="showConfigureFeesModal()">Configure Fees</button>
            </div>
        `;
        return;
    }
    
    let html = '';
    for (const [typeName, typeData] of Object.entries(currentPropertyFees)) {
        html += `
            <div class="property-fees-section">
                <h3>${escapeHtml(typeName)}</h3>
                <div class="fee-summary">
                    <p><strong>Apartment Type ID:</strong> ${typeData.apartment_type_id}</p>
                </div>
                <table class="fee-table">
                    <thead>
                        <tr>
                            <th>Fee Type</th>
                            <th>Amount (₦)</th>
                            <th>Mandatory</th>
                            <th>Recurring</th>
                            <th>Effective From</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${typeData.fees.map(fee => `
                            <tr>
                                <td>${escapeHtml(fee.fee_name)}</td>
                                <td>₦${formatNumber(fee.amount)}</td>
                                <td>${fee.is_mandatory ? '<span class="badge-mandatory">Yes</span>' : '<span class="badge-optional">No</span>'}</td>
                                <td>${fee.is_recurring ? fee.recurrence_period : 'One-time'}</td>
                                <td>${formatDate(fee.effective_from)}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
    }
    
    html += `<div style="margin-top: 20px;"><button class="btn-primary" onclick="showConfigureFeesModal()">Edit Fees</button></div>`;
    container.innerHTML = html;
}

// Update showConfigureFeesModal to use loaded apartment types
async function showConfigureFeesModal() {
    const propertyCode = document.getElementById('propertySelect').value;
    if (!propertyCode) {
        showToast('Please select a property first', 'warning');
        return;
    }
    
    // Make sure fee types are loaded
    if (feeTypes.length === 0) {
        await loadFeeTypes();
    }
    
    // Make sure apartment types are loaded
    if (apartmentTypes.length === 0) {
        await loadApartmentTypes(propertyCode);
    }
    
    // Create configuration modal
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
                        <input type="text" id="configPropertyName" value="${document.getElementById('propertySelect').selectedOptions[0]?.text}" disabled>
                    </div>
                    <div id="feeConfigForm">
                        ${apartmentTypes.map(type => `
                            <div class="apartment-type-fees">
                                <h4>
                                    ${escapeHtml(type.type_name)} 
                                    ${type.apartment_count > 0 ? `<span class="badge-info">${type.apartment_count} apartments</span>` : ''}
                                </h4>
                                <div class="fee-config-grid">
                                    ${feeTypes.map(fee => `
                                        <div class="fee-config-item">
                                            <label>
                                                ${escapeHtml(fee.fee_name)}
                                                ${fee.is_mandatory ? '<span class="mandatory-star">*</span>' : ''}
                                            </label>
                                            <div class="fee-input-wrapper">
                                                <span class="currency-symbol">₦</span>
                                                <input type="number" 
                                                       class="amount-input" 
                                                       data-apartment-type="${type.type_id}"
                                                       data-fee-type="${fee.fee_type_id}"
                                                       placeholder="0.00"
                                                       step="0.01"
                                                       value="${getExistingFeeAmount(type.type_id, fee.fee_type_id)}">
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
        </div>
    `;
    
    // Remove existing modal if any
    const existingModal = document.getElementById('configFeesModal');
    if (existingModal) existingModal.remove();
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
}

// Helper function to get existing fee amount
function getExistingFeeAmount(apartmentTypeId, feeTypeId) {
    // currentPropertyFees structure: { "Apartment Type Name": { apartment_type_id: x, fees: [...] } }
    for (const [typeName, typeData] of Object.entries(currentPropertyFees)) {
        if (typeData.apartment_type_id === apartmentTypeId) {
            const existingFee = typeData.fees.find(f => f.fee_type_id === feeTypeId);
            if (existingFee) {
                return existingFee.amount;
            }
        }
    }
    return '';
}

function closeConfigModal() {
  const modal = document.getElementById("configFeesModal");
  if (modal) modal.remove();
}

async function savePropertyFees() {
    const propertyCode = document.getElementById("propertySelect").value;
    const propertyName = document.getElementById("propertySelect").selectedOptions[0]?.text;
    const feeInputs = document.querySelectorAll("#feeConfigForm .amount-input");
    const fees = {};

    feeInputs.forEach((input) => {
        const apartmentTypeId = input.dataset.apartmentType;
        const feeTypeId = input.dataset.feeType;
        const amount = parseFloat(input.value) || 0;

        if (!fees[apartmentTypeId]) fees[apartmentTypeId] = {};
        fees[apartmentTypeId][feeTypeId] = amount;
    });

    // Show loading state
    const saveBtn = document.querySelector('#configFeesModal .btn-primary');
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
            }
        );
        const result = await response.json();

        if (result.success) {
            showToast("Property fees saved successfully", "success");
            closeConfigModal();
            
            // Reload property fees to show updated values
            await loadPropertyFees(propertyCode);
            
            // Show regenerate button for existing tenants
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

// Function to show regenerate prompt
// Function to show regenerate prompt
async function showRegeneratePrompt(propertyCode, propertyName) {
    console.log("Checking for existing tenants...", propertyCode);
    
    try {
        // Check if there are existing tenants for this property
        const hasTenants = await checkExistingTenants(propertyCode);
        console.log("Has tenants:", hasTenants);
        
        if (hasTenants) {
            // Remove existing banner if any
            const existingBanner = document.getElementById('regenerateBanner');
            if (existingBanner) existingBanner.remove();
            
            // Create the banner HTML
            const bannerHtml = `
                <div id="regenerateBanner" class="regenerate-banner">
                    <div class="banner-content">
                        <div class="banner-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="banner-message">
                            <strong>Existing Tenants Detected</strong>
                            <p>You have existing tenants in property "${escapeHtml(propertyName)}". Would you like to apply these new fees to them?</p>
                        </div>
                        <div class="banner-actions">
                            <button class="btn-regenerate" onclick="regenerateTenantFees('${propertyCode}')">
                                <i class="fas fa-sync-alt"></i> Apply to Existing Tenants
                            </button>
                            <button class="btn-dismiss" onclick="dismissRegenerateBanner()">
                                <i class="fas fa-times"></i> Later
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            // Insert banner after the property fees content
            const container = document.getElementById('propertyFeesContent');
            if (container) {
                // Insert at the top of the container
                container.insertAdjacentHTML('afterbegin', bannerHtml);
                
                // Scroll to banner
                document.getElementById('regenerateBanner').scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }
    } catch (error) {
        console.error('Error checking tenants:', error);
        // Don't show banner if check fails
    }
}

// Check if property has existing tenants
// Check if property has existing tenants
async function checkExistingTenants(propertyCode) {
    try {
        const response = await fetch(`../backend/properties/check_property_tenants.php?property_code=${propertyCode}`);
        const data = await response.json();
        
        console.log("Check tenants response:", data);
        
        if (data.success) {
            // Fix: Access data.message.has_tenants (not data.data.has_tenants)
            return data.message?.has_tenants || false;
        }
        return false;
    } catch (error) {
        console.error('Error checking tenants:', error);
        return false;
    }
}

// Check if property has existing tenants
// async function checkExistingTenants(propertyCode) {
//     try {
//         const response = await fetch(`../backend/properties/check_property_tenants.php?property_code=${propertyCode}`);
//         const data = await response.json();
//         return data.success && data.data.has_tenants;
//     } catch (error) {
//         console.error('Error checking tenants:', error);
//         return false;
//     }
// }

// Regenerate tenant fees for the property
// Regenerate tenant fees for the property
async function regenerateTenantFees(propertyCode) {
    const regenerateBtn = document.querySelector('.btn-regenerate');
    const originalText = regenerateBtn.innerHTML;
    regenerateBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    regenerateBtn.disabled = true;
    
    try {
        const response = await fetch(`../backend/fee_management/regenerate_tenant_fees.php?property_code=${propertyCode}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' }
        });
        const data = await response.json();
        
        console.log("Regenerate response:", data);
        
        if (data.success) {
            // Fix: Access data.message.fees_created (not data.data.fees_created)
            const feesCreated = data.message?.fees_created || 0;
            showToast(`Successfully generated ${feesCreated} new tenant fees`, 'success');
            
            // Remove the banner
            dismissRegenerateBanner();
            
            // Show detailed results if available
            if (data.message?.details && data.message.details.length > 0) {
                showRegenerateResults(data.message.details);
            }
        } else {
            throw new Error(data.message);
        }
    } catch (error) {
        console.error('Error regenerating tenant fees:', error);
        showToast(error.message, 'error');
    } finally {
        regenerateBtn.innerHTML = originalText;
        regenerateBtn.disabled = false;
    }
}
// Show detailed regeneration results
function showRegenerateResults(details) {
    const modalHtml = `
        <div class="modal active" id="regenerateResultsModal">
            <div class="modal-content" style="max-width: 600px;">
                <div class="modal-header">
                    <h3>Fee Generation Results</h3>
                    <button class="modal-close" onclick="closeModal('regenerateResultsModal')">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="results-summary">
                        <div class="summary-stat">
                            <span class="stat-label">Tenants Processed:</span>
                            <span class="stat-value">${details.length}</span>
                        </div>
                        <div class="summary-stat">
                            <span class="stat-label">Total Fees Generated:</span>
                            <span class="stat-value">${details.reduce((sum, t) => sum + t.fees_generated.length, 0)}</span>
                        </div>
                    </div>
                    <div class="results-details">
                        <h4>Details by Tenant</h4>
                        ${details.map(tenant => `
                            <div class="tenant-result">
                                <div class="tenant-header">
                                    <strong>${escapeHtml(tenant.tenant_name)}</strong> (${tenant.tenant_code})
                                    <span class="fee-count">${tenant.fees_generated.length} fees added</span>
                                </div>
                                ${tenant.fees_generated.length > 0 ? `
                                    <ul class="fee-list">
                                        ${tenant.fees_generated.map(f => `
                                            <li>${escapeHtml(f.fee_name)} - ₦${formatNumber(f.amount)} (Due: ${formatDate(f.due_date)})</li>
                                        `).join('')}
                                    </ul>
                                ` : ''}
                                ${tenant.fees_skipped.length > 0 ? `
                                    <div class="skipped-fees">
                                        <small>Skipped (already existed): ${tenant.fees_skipped.join(', ')}</small>
                                    </div>
                                ` : ''}
                            </div>
                        `).join('')}
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-primary" onclick="closeModal('regenerateResultsModal')">Close</button>
                </div>
            </div>
        </div>
    `;
    
    // Remove existing modal if any
    const existingModal = document.getElementById('regenerateResultsModal');
    if (existingModal) existingModal.remove();
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
}

function dismissRegenerateBanner() {
    const banner = document.getElementById('regenerateBanner');
    if (banner) banner.remove();
}

// Load Tenant Fees
async function loadTenantFees() {
  const status = document.getElementById("tenantFeeStatusFilter").value;
  const search = document.getElementById("tenantSearch").value;

  let url = "../backend/fee_management/fetch_tenant_fees.php";
  const params = new URLSearchParams();
  if (status) params.append("status", status);
  if (search) params.append("search", search);
  if (params.toString()) url += "?" + params.toString();

  try {
    const response = await fetch(url);
    const data = await response.json();

    if (data.success) {
      renderTenantFees(data.data.fees);
    }
  } catch (error) {
    console.error("Error loading tenant fees:", error);
  }
}

function renderTenantFees(fees) {
  const container = document.getElementById("tenantFeesContent");

  if (!fees || fees.length === 0) {
    container.innerHTML =
      '<div class="empty-state"><i class="fas fa-receipt"></i><p>No tenant fees found</p></div>';
    return;
  }

  container.innerHTML = `
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Tenant</th>
                            <th>Fee Type</th>
                            <th>Amount</th>
                            <th>Due Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${fees
                          .map(
                            (fee) => `
                            <tr>
                                <td>${escapeHtml(fee.tenant_name)}</td>
                                <td>${escapeHtml(fee.fee_name)}</td>
                                <td>₦${formatNumber(fee.amount)}</td>
                                <td>${formatDate(fee.due_date)}</td>
                                <td><span class="status-badge status-${fee.status}">${fee.status}</span></td>
                                <td>
                                    <button class="btn-icon" onclick="viewTenantFee(${fee.tenant_fee_id})" title="View">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn-icon" onclick="markFeeAsPaid(${fee.tenant_fee_id})" title="Mark as Paid">
                                        <i class="fas fa-check-circle"></i>
                                    </button>
                                 </td>
                            </tr>
                        `,
                          )
                          .join("")}
                    </tbody>
                </table>
            `;
}

function searchTenantFees() {
  loadTenantFees();
}

// Utility Functions
function formatNumber(value) {
  return new Intl.NumberFormat("en-NG").format(value);
}

function formatDate(dateString) {
  if (!dateString) return "N/A";
  return new Date(dateString).toLocaleDateString("en-US");
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
  toast.innerHTML = `<span>${message}</span>`;
  document.body.appendChild(toast);
  setTimeout(() => toast.remove(), 3000);
}

// Initial load
loadFeeTypes();
