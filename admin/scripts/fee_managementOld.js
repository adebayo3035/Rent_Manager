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

// function renderPropertyFees() {
//     const container = document.getElementById('propertyFeesContent');
//     if (!container) return;
    
//     console.log("Rendering property fees:", currentPropertyFees);
    
//     if (!currentPropertyFees || Object.keys(currentPropertyFees).length === 0) {
//         container.innerHTML = `
//             <div class="property-fees-section">
//                 <p>No fees configured for this property yet.</p>
//                 <button class="btn-primary" onclick="showConfigureFeesModal()">Configure Fees</button>
//             </div>
//         `;
//         return;
//     }
    
//     let html = '';
//     for (const [typeName, typeData] of Object.entries(currentPropertyFees)) {
//         html += `
//             <div class="property-fees-section">
//                 <h3>${escapeHtml(typeName)}</h3>
//                 <div class="fee-summary">
//                     <p><strong>Apartment Type ID:</strong> ${typeData.apartment_type_id}</p>
//                 </div>
//                 <table class="fee-table">
//                     <thead>
//                         <tr>
//                             <th>Fee Type</th>
//                             <th>Amount (₦)</th>
//                             <th>Mandatory</th>
//                             <th>Recurring</th>
//                             <th>Effective From</th>
//                         </tr>
//                     </thead>
//                     <tbody>
//                         ${typeData.fees.map(fee => `
//                             <tr>
//                                 <td>${escapeHtml(fee.fee_name)}</td>
//                                 <td>₦${formatNumber(fee.amount)}</td>
//                                 <td>${fee.is_mandatory ? '<span class="badge-mandatory">Yes</span>' : '<span class="badge-optional">No</span>'}</td>
//                                 <td>${fee.is_recurring ? fee.recurrence_period : 'One-time'}</td>
//                                 <td>${formatDate(fee.effective_from)}</td>
//                             </tr>
//                         `).join('')}
//                     </tbody>
//                 </table>
//             </div>
//         `;
//     }
    
//     html += `<div style="margin-top: 20px;">
//     <button class="btn-primary" onclick="showConfigureFeesModal()">Edit Fees</button>
//     </div>`;

     
//     container.innerHTML = html;

    
// }

function renderPropertyFees() {
    const container = document.getElementById('propertyFeesContent');
    if (!container) return;
    
    const propertyCode = document.getElementById('propertySelect')?.value;
    const propertyName = document.getElementById('propertySelect')?.selectedOptions[0]?.text;
    
    if (!currentPropertyFees || Object.keys(currentPropertyFees).length === 0) {
        container.innerHTML = `
            <div class="empty-state-fees">
                <i class="fas fa-receipt"></i>
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
                <div class="table-responsive">
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
                                    <td>${fee.is_recurring ? (fee.recurrence_period || 'Monthly') : 'One-time'}</td>
                                    <td>${formatDate(fee.effective_from)}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    }
    
    // Add buttons at the bottom
    html += `
        <div class="button-group">
            <button class="btn-primary" onclick="showConfigureFeesModal()">
                <i class="fas fa-edit"></i> Edit Fees
            </button>
            <button class="btn-regenerate" onclick="regenerateTenantFees('${propertyCode}')">
                <i class="fas fa-sync-alt"></i> Apply to Existing Tenants
            </button>
        </div>
    `;
    
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

// Regenerate tenant fees for the property
async function regenerateTenantFees(propertyCode) {
    const regenerateBtn = document.querySelector('.btn-regenerate');
    if (!regenerateBtn) {
        console.error("Regenerate button not found");
        return;
    }
    
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
        
        console.log("Regenerate response:", data);
        
        if (data.success) {
            const message = data.message || data.data?.message;
            const feesCreated = data.data?.fees_created || data.message?.fees_created || 0;
            showToast(`Successfully applied ${feesCreated} new fee(s) to existing tenants`, 'success');
            
            // Remove the banner
            dismissRegenerateBanner();
            
            // Refresh tenant fees tab if it's visible
            if (document.getElementById('tenant-fees-tab').classList.contains('active')) {
                loadTenantFees();
            }
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
      const fees = data.message?.fees || [];
      renderTenantFees(fees);

      
    }
  } catch (error) {
    console.error("Error loading tenant fees:", error);
  }
}

function renderTenantFees(fees) {
  const container = document.getElementById("tenantFeesContent");
  if (!container) return;

  if (!fees || fees.length === 0) {
    container.innerHTML = `
      <div class="empty-state">
        <i class="fas fa-receipt"></i>
        <p>No tenant fees found</p>
      </div>
    `;
    return;
  }

  console.log("Rendering fees:", fees); // Debug log

  container.innerHTML = `
    <div class="table-responsive">
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
          ${fees.map(fee => `
            <tr>
              <td>${escapeHtml(fee.tenant_name || fee.tenant_code || 'N/A')}</td>
              <td>${escapeHtml(fee.fee_name)}</td>
              <td>₦${formatNumber(fee.amount)}</td>
              <td>${formatDate(fee.due_date)}</td>
              <td><span class="status-badge status-${fee.status}">${fee.status.toUpperCase()}</span></td>
              <td>
                <button class="btn-icon" onclick="viewTenantFee(${fee.tenant_fee_id})" title="View">
                  <i class="fas fa-eye"></i>
                </button>
                <button class="btn-icon" onclick="markFeeAsPaid(${fee.tenant_fee_id})" title="Mark as Paid">
                  <i class="fas fa-check-circle"></i>
                </button>
              </td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    </div>
  `;
}

// View Tenant Fee Details
// View Tenant Fee Details
async function viewTenantFee(tenantFeeId) {
    try {
        // Show loading state
        const modalBody = document.getElementById('tenantFeeDetails');
        if (!modalBody) {
            console.error("Modal body element not found");
            return;
        }
        
        modalBody.innerHTML = '<div class="loading-spinner"><div class="spinner"></div> Loading...</div>';
        
        // Open the modal
        const modal = document.getElementById('viewTenantFeeModal');
        if (modal) {
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        } else {
            console.error("Modal element not found");
            return;
        }
        
        // Fetch fee details
        const response = await fetch(`../backend/fee_management/fetch_single_tenant_fee.php?tenant_fee_id=${tenantFeeId}`);
        const data = await response.json();
        
        console.log("Fee details response:", data);
        
        if (data.success) {
            const fee = data.message?.fee || data.data?.fee;
            renderTenantFeeDetails(fee, tenantFeeId);
        } else {
            modalBody.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-exclamation-circle"></i>
                    <p>${data.message || 'Failed to load fee details'}</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error loading fee details:', error);
        const modalBody = document.getElementById('tenantFeeDetails');
        if (modalBody) {
            modalBody.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-exclamation-circle"></i>
                    <p>Error loading fee details. Please try again.</p>
                </div>
            `;
        }
    }
}

// Render Tenant Fee Details
function renderTenantFeeDetails(fee, tenantFeeId) {
    const container = document.getElementById('tenantFeeDetails');
    
    // Determine if fee can be marked as paid (only if status is pending or overdue)
    const canMarkPaid = fee.status === 'pending' || fee.status === 'overdue';
    
    // Show/hide mark as paid button
    const markPaidBtn = document.getElementById('markPaidBtn');
    if (markPaidBtn) {
        markPaidBtn.style.display = canMarkPaid ? 'inline-flex' : 'none';
        markPaidBtn.setAttribute('data-fee-id', tenantFeeId);
    }
    
    // Parse notes for payment information if status is paid
    let paymentInfo = '';
    if (fee.status === 'paid' && fee.notes) {
        const paymentMatch = fee.notes.match(/Paid on (.*?) via (.*?)\. Receipt: (.*?)(?:\n|$)/);
        if (paymentMatch) {
            paymentInfo = `
                <div class="payment-info-section">
                    <h4>Payment Information</h4>
                    <div class="detail-row">
                        <span class="detail-label">Payment Date:</span>
                        <span class="detail-value">${formatDateTime(paymentMatch[1])}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Payment Method:</span>
                        <span class="detail-value">${escapeHtml(paymentMatch[2])}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Receipt Number:</span>
                        <span class="detail-value">${escapeHtml(paymentMatch[3])}</span>
                    </div>
                </div>
            `;
        }
    }
    
    container.innerHTML = `
        <div class="fee-detail-section">
            <div class="detail-section">
                <h4>Fee Information</h4>
                <div class="detail-row">
                    <span class="detail-label">Fee Name:</span>
                    <span class="detail-value">${escapeHtml(fee.fee_name)}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Fee Code:</span>
                    <span class="detail-value">${escapeHtml(fee.fee_code)}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Amount:</span>
                    <span class="detail-value amount-value">₦${formatNumber(fee.amount)}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Due Date:</span>
                    <span class="detail-value">${formatDate(fee.due_date)}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Status:</span>
                    <span class="detail-value">
                        <span class="status-badge status-${fee.status}">${fee.status.toUpperCase()}</span>
                    </span>
                </div>
                ${fee.is_recurring ? `
                <div class="detail-row">
                    <span class="detail-label">Recurrence:</span>
                    <span class="detail-value">${fee.recurrence_period || 'Monthly'}</span>
                </div>
                ` : ''}
            </div>
            
            <div class="detail-section">
                <h4>Tenant Information</h4>
                <div class="detail-row">
                    <span class="detail-label">Tenant Name:</span>
                    <span class="detail-value">${escapeHtml(fee.tenant_name)}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Tenant Code:</span>
                    <span class="detail-value">${escapeHtml(fee.tenant_code)}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Apartment:</span>
                    <span class="detail-value">${escapeHtml(fee.apartment_number || 'N/A')}</span>
                </div>
            </div>
            
            ${paymentInfo}
            
            ${fee.notes && fee.status !== 'paid' ? `
            <div class="detail-section">
                <h4>Notes</h4>
                <div class="notes-content">
                    ${escapeHtml(fee.notes)}
                </div>
            </div>
            ` : ''}
        </div>
    `;
}

// Close View Tenant Fee Modal
// Close View Tenant Fee Modal
function closeViewTenantFeeModal() {
    const modal = document.getElementById('viewTenantFeeModal');
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}
// Mark Fee as Paid from Modal
async function markFeeAsPaidFromModal() {
    const btn = document.getElementById('markPaidBtn');
    const feeId = btn.getAttribute('data-fee-id');
    if (feeId) {
        await markFeeAsPaid(feeId);
    }
}

// Mark Fee as Paid
// ==================== CUSTOM CONFIRMATION MODAL ====================

let confirmResolve = null;

function showCustomConfirm(options) {
    return new Promise((resolve) => {
        confirmResolve = resolve;
        
        const modal = document.getElementById('customConfirmModal');
        const titleEl = document.getElementById('confirmTitle');
        const messageEl = document.getElementById('confirmMessage');
        const iconEl = document.getElementById('confirmIcon');
        const detailsEl = document.getElementById('confirmDetails');
        const cancelBtn = document.getElementById('confirmCancelBtn');
        const okBtn = document.getElementById('confirmOkBtn');
        
        // Set title
        titleEl.textContent = options.title || 'Confirm Action';
        
        // Set message
        messageEl.textContent = options.message || 'Are you sure you want to proceed?';
        
        // Set icon based on type
        const iconType = options.type || 'warning';
        iconEl.className = 'confirm-icon ' + iconType;
        
        let iconHtml = '';
        let iconClass = '';
        switch(iconType) {
            case 'danger':
                iconHtml = '<i class="fas fa-exclamation-triangle"></i>';
                break;
            case 'success':
                iconHtml = '<i class="fas fa-check-circle"></i>';
                break;
            case 'info':
                iconHtml = '<i class="fas fa-info-circle"></i>';
                break;
            default:
                iconHtml = '<i class="fas fa-exclamation-triangle"></i>';
        }
        iconEl.innerHTML = iconHtml;
        
        // Set confirm button text and style
        const confirmText = options.confirmText || 'Confirm';
        const confirmClass = options.confirmClass || 'btn-primary';
        okBtn.textContent = confirmText;
        okBtn.className = confirmClass;
        
        // Set cancel button text
        cancelBtn.textContent = options.cancelText || 'Cancel';
        
        // Build details if provided
        if (options.details && options.details.length > 0) {
            detailsEl.innerHTML = `
                <div class="confirm-details">
                    ${options.details.map(detail => `
                        <div class="confirm-detail-row">
                            <span class="confirm-detail-label">${escapeHtml(detail.label)}:</span>
                            <span class="confirm-detail-value">${escapeHtml(detail.value)}</span>
                        </div>
                    `).join('')}
                </div>
            `;
            detailsEl.style.display = 'block';
        } else {
            detailsEl.style.display = 'none';
        }
        
        // Show modal
        modal.style.display = 'flex';
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        
        // Setup event handlers (remove old ones first)
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
        
        // Close on escape key
        const handleEscape = (e) => {
            if (e.key === 'Escape') {
                closeCustomConfirmModal();
                resolve(false);
                document.removeEventListener('keydown', handleEscape);
            }
        };
        document.addEventListener('keydown', handleEscape);
        
        // Store cleanup function
        window._confirmCleanup = () => {
            document.removeEventListener('keydown', handleEscape);
        };
    });
}

function closeCustomConfirmModal() {
    const modal = document.getElementById('customConfirmModal');
    modal.style.display = 'none';
    modal.classList.remove('active');
    document.body.style.overflow = '';
    
    if (window._confirmCleanup) {
        window._confirmCleanup();
    }
}

// ==================== UPDATED MARK FEE AS PAID FUNCTION ====================

async function markFeeAsPaid(feeId) {
    // First, get fee details to show in confirmation
    try {
        const response = await fetch(`../backend/fee_management/fetch_single_tenant_fee.php?tenant_fee_id=${feeId}`);
        const data = await response.json();
        
        let feeName = '';
        let feeAmount = '';
        let tenantName = '';
        
        if (data.success) {
            const fee = data.message?.fee || data.data?.fee;
            feeName = fee.fee_name;
            feeAmount = formatNumber(fee.amount);
            tenantName = fee.tenant_name;
        }
        
        // Show custom confirmation modal
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
        
        if (!confirmed) {
            return;
        }
        
        // Show loading state on the button that was clicked
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
        
        console.log("Mark fee response:", markData); // Debug log
        
        if (markData.success) {
            // IMPORTANT: Data is in markData.message, not markData.data
            const responseData = markData.message || markData.data || {};
            
            // Show success confirmation
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
            loadTenantFees(); // Refresh the list
        } else {
            throw new Error(markData.message || 'Failed to mark fee as paid');
        }
    } catch (error) {
        console.error('Error marking fee as paid:', error);
        showToast(error.message, 'error');
        
        // Show error confirmation
        await showCustomConfirm({
            title: 'Error',
            type: 'danger',
            message: error.message || 'Failed to mark fee as paid. Please try again.',
            confirmText: 'OK',
            confirmClass: 'btn-danger',
            cancelText: ''
        });
    } finally {
        // Reset button state
        const btn = event?.target?.closest('.btn-icon') || document.getElementById('markPaidBtn');
        if (btn) {
            btn.innerHTML = '<i class="fas fa-check-circle"></i>';
            btn.disabled = false;
        }
    }
}
// Helper function to format date and time
function formatDateTime(dateString) {
    if (!dateString) return 'N/A';
    try {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    } catch (e) {
        return dateString;
    }
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

// Modal Functions
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('active');
        // Prevent body scroll when modal is open
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
        // Restore body scroll
        document.body.style.overflow = '';
    }
}

// Also add a function to close modals by clicking outside
function closeModalOnOutsideClick(event, modalId) {
    const modal = document.getElementById(modalId);
    if (event.target === modal) {
        closeModal(modalId);
    }
}

// Initial load
loadFeeTypes();
