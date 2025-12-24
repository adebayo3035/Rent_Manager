// tenant.js - Cleaned and Debugged Version

// Global state
let tenantManager = null;
let supportData = {
  properties: [],
  apartments: {}, // Cached by property_code
};

// ===============================
// Helper Functions
// ===============================
document
  .getElementById("property_code")
  .addEventListener("change", async function () {
    const propertyCode = this.value;
    const apartmentSelect = document.getElementById("apartment_code");

    apartmentSelect.innerHTML = `<option value="">Loading apartments...</option>`;
    apartmentSelect.disabled = true;

    if (!propertyCode) {
      apartmentSelect.innerHTML = `<option value="">-- Select Apartment --</option>`;
      return;
    }

    try {
      const response = await fetch(
        `../backend/tenants/fetch_apartments.php?property_code=${encodeURIComponent(
          propertyCode
        )}`,
        { credentials: "include" }
      );

      const data = await response.json();

      apartmentSelect.innerHTML = `<option value="">-- Select Apartment --</option>`;

      if (data.success && data.apartments.length > 0) {
        data.apartments.forEach((apartment) => {
          const option = document.createElement("option");
          option.value = apartment.apartment_code;

          // Create display text
          let displayText = `Unit ${apartment.apartment_type_unit}`;
          if (apartment.apartment_number) {
            displayText += ` (Apt #${apartment.apartment_number})`;
          }

          option.textContent = displayText;
          apartmentSelect.appendChild(option);
        });

        apartmentSelect.disabled = false;
      } else {
        apartmentSelect.innerHTML = `<option value="">No available apartments</option>`;
      }
    } catch (error) {
      console.error(error);
      apartmentSelect.innerHTML = `<option value="">Failed to load apartments</option>`;
    }
  });

/**
 * Populate select dropdown with data
 */
function populateSelect(
  selector,
  list,
  valueKey,
  labelKey,
  placeholder = "Select..."
) {
  const select = document.querySelector(selector);
  if (!select) {
    console.error(`Select element not found: ${selector}`);
    return;
  }

  const currentValue = select.value;
  select.innerHTML = `<option value="">${placeholder}</option>`;

  if (!Array.isArray(list) || list.length === 0) {
    const option = document.createElement("option");
    option.value = "";
    option.textContent = "No options available";
    select.appendChild(option);
    select.disabled = true;
    return;
  }

  list.forEach((item) => {
    const option = document.createElement("option");
    option.value = item[valueKey];

    // Handle label as function or string
    let labelText;
    if (typeof labelKey === "function") {
      labelText = labelKey(item);
    } else if (Array.isArray(labelKey)) {
      // Multiple fields for label
      labelText = labelKey.map((field) => item[field] || "").join(" - ");
    } else {
      labelText = item[labelKey] || item[valueKey];
    }

    option.textContent = labelText;
    option.setAttribute("data-address", item.address || "");
    select.appendChild(option);
  });

  // Restore previous value if it exists in new list
  if (currentValue && list.some((item) => item[valueKey] === currentValue)) {
    select.value = currentValue;
  }

  select.disabled = false;
}

/**
 * Load apartments for a property and populate select
 */
async function loadApartmentsForProperty(
  propertyCode,
  apartmentSelectId,
  selectedApartment = ""
) {
  const apartmentSelect = document.querySelector(apartmentSelectId);
  if (!apartmentSelect) return;

  // Show loading
  apartmentSelect.innerHTML = `<option value="">Loading apartments...</option>`;
  apartmentSelect.disabled = true;

  if (!propertyCode) {
    apartmentSelect.innerHTML = `<option value="">-- Select Property First --</option>`;
    return;
  }

  try {
    // Check cache first
    if (supportData.apartments[propertyCode]) {
      populateApartmentSelect(
        apartmentSelectId,
        supportData.apartments[propertyCode],
        selectedApartment
      );
      return;
    }

    const response = await fetch(
      `../backend/tenants/fetch_apartments.php?property_code=${encodeURIComponent(
        propertyCode
      )}`,
      { credentials: "include" }
    );

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }

    const data = await response.json();

    if (!data.success) {
      throw new Error(data.message || "Failed to load apartments");
    }

    // Cache apartments
    supportData.apartments[propertyCode] = data.apartments || [];

    // Populate select
    populateApartmentSelect(
      apartmentSelectId,
      data.apartments,
      selectedApartment
    );

    // Update property info if available
    if (data.property_name && data.max_capacity) {
      const propertyInfo = document.getElementById("propertyInfo");
      if (propertyInfo) {
        propertyInfo.innerHTML = `
                    <small>
                        Property: ${data.property_name} | 
                        Capacity: ${data.max_capacity} units | 
                        Available: ${data.available_apartments_count || 0}
                    </small>
                `;
        propertyInfo.style.display = "block";
      }
    }
  } catch (error) {
    console.error("Error loading apartments:", error);
    apartmentSelect.innerHTML = `<option value="">Error: ${error.message}</option>`;
  }
}

/**
 * Populate apartment select with apartments
 */
function populateApartmentSelect(selectId, apartments, selectedValue = "") {
  const select = document.querySelector(selectId);
  if (!select) return;

  select.innerHTML = `<option value="">-- Select Apartment --</option>`;

  if (!Array.isArray(apartments) || apartments.length === 0) {
    const option = document.createElement("option");
    option.value = "";
    option.textContent = "No apartments available";
    select.appendChild(option);
    select.disabled = true;
    return;
  }

  apartments.forEach((apartment) => {
    const option = document.createElement("option");
    option.value = apartment.apartment_code;

    // Create display text
    let displayText = `Unit ${apartment.apartment_type_unit}`;
    if (apartment.apartment_number) {
      displayText += ` (Apt #${apartment.apartment_number})`;
    }

    option.textContent = displayText;
    select.appendChild(option);
  });

  // Set selected value
  if (selectedValue) {
    select.value = selectedValue;
  }

  select.disabled = false;
}

/**
 * Load support data (properties, agents, etc.)
 */
// async function loadSupportData() {
//     try {
//         console.log('Loading support data...');

//         const response = await fetch(
//             "../backend/apartments/fetch_agent_property_type.php",
//             { credentials: "include" }
//         );

//         if (!response.ok) {
//             throw new Error(`HTTP ${response.status}`);
//         }

//         const data = await response.json();

//         if (data.response_code !== 200) {
//             throw new Error(data.message || 'Failed to load support data');
//         }

//         // Store support data
//         supportData.properties = data.data?.properties || [];
//         console.log('Loaded properties:', supportData.properties.length);

//         // Populate property selects in both forms
//         populateSelect(
//             "#property_code", // Add form
//             supportData.properties,
//             "property_code",
//             ["name", "address"]
//         );

//         populateSelect(
//             "#edit_tenantProperty", // Edit form
//             supportData.properties,
//             "property_code",
//             ["name", "address"]
//         );

//         // Setup property change listeners
//         setupPropertyChangeListeners();

//         return true;

//     } catch (error) {
//         console.error("Error loading support data:", error);

//         // Show error to user
//         const errorDiv = document.getElementById('addTenantMessage') || document.createElement('div');
//         errorDiv.innerHTML = `<div class="alert alert-warning">Failed to load properties: ${error.message}</div>`;

//         return false;
//     }
// }

async function loadSupportData() {
  try {
    const response = await fetch(
      "../backend/apartments/fetch_agent_property_type.php",
      { credentials: "include" }
    );

    const data = await response.json();
    supportData.properties = data.data?.properties || [];

    console.log(
      "Support data loaded:",
      supportData.properties.length,
      "properties"
    );

    // Only populate add form select (it exists on page load)
    const addPropertySelect = document.getElementById("property_code");
    if (addPropertySelect) {
      populateSelect(
        "#property_code",
        supportData.properties,
        "property_code",
        ["name", "address"]
      );
    }

    return true;
  } catch (error) {
    console.error("Error loading support data:", error);
    // Show error to user
    const errorDiv =
      document.getElementById("addTenantMessage") ||
      document.createElement("div");
    errorDiv.innerHTML = `<div class="alert alert-warning">Failed to load properties: ${error.message}</div>`;
    return false;
  }
}

/**
 * Setup property change event listeners
 */
function setupPropertyChangeListeners() {
  // Add form property change
  const addPropertySelect = document.getElementById("property_code");
  if (addPropertySelect) {
    // Remove existing listeners
    const newAddSelect = addPropertySelect.cloneNode(true);
    addPropertySelect.parentNode.replaceChild(newAddSelect, addPropertySelect);

    newAddSelect.addEventListener("change", async function () {
      const propertyCode = this.value;
      await loadApartmentsForProperty(propertyCode, "#apartment_code");
    });
  }

  // Edit form property change
  const editPropertySelect = document.getElementById("edit_tenantProperty");
  if (editPropertySelect) {
    // Remove existing listeners
    const newEditSelect = editPropertySelect.cloneNode(true);
    editPropertySelect.parentNode.replaceChild(
      newEditSelect,
      editPropertySelect
    );

    newEditSelect.addEventListener("change", async function () {
      const propertyCode = this.value;
      await loadApartmentsForProperty(propertyCode, "#edit_tenantPropertyUnit");
    });
  }
}

/**
 * Preview uploaded photo
 */
function setupPhotoPreview() {
  const photoInput = document.getElementById("tenantPhoto");
  const preview = document.getElementById("photoPreview");

  if (!photoInput || !preview) return;

  photoInput.addEventListener("change", function (e) {
    const file = e.target.files[0];

    if (!file) {
      preview.innerHTML = `<span style='font-size:12px;color:#777;'>No image selected</span>`;
      return;
    }

    // Validate file type
    const validTypes = ["image/jpeg", "image/jpg", "image/png"];
    if (!validTypes.includes(file.type)) {
      preview.innerHTML = `<span style='font-size:12px;color:red;'>Only JPG, JPEG & PNG allowed</span>`;
      photoInput.value = "";
      return;
    }

    // Validate file size (2MB)
    if (file.size > 2 * 1024 * 1024) {
      preview.innerHTML = `<span style='font-size:12px;color:red;'>File too large (max 2MB)</span>`;
      photoInput.value = "";
      return;
    }

    const reader = new FileReader();
    reader.onload = function (event) {
      preview.innerHTML = `
                <img src="${event.target.result}" 
                     style="width:100%;max-height:200px;object-fit:cover;border-radius:8px;">
                <div style="font-size:11px;color:#666;margin-top:5px;">
                    ${file.name} (${(file.size / 1024).toFixed(1)} KB)
                </div>
            `;
    };
    reader.onerror = function () {
      preview.innerHTML = `<span style='font-size:12px;color:red;'>Failed to load image</span>`;
    };
    reader.readAsDataURL(file);
  });
}

// ===============================
// Modal Loading Helpers
// ===============================

function showEditLoader() {
  const loader = document.getElementById("editModalLoader");
  if (loader) {
    loader.style.display = "flex";
  }

  const table = document.querySelector("#tenantDetailsTable");
  if (table) {
    table.style.pointerEvents = "none";
    table.style.opacity = "0.5";
  }
}

function hideEditLoader() {
  const loader = document.getElementById("editModalLoader");
  if (loader) {
    loader.style.display = "none";
  }

  const table = document.querySelector("#tenantDetailsTable");
  if (table) {
    table.style.pointerEvents = "auto";
    table.style.opacity = "1";
  }
}

// ===============================
// Main Initialization
// ===============================

document.addEventListener("DOMContentLoaded", async () => {
  console.log("Initializing Tenant Management System...");

  // Setup photo preview
  setupPhotoPreview();

  // Load support data first
  await loadSupportData();

  // Initialize DataManager
  initializeTenantManager();

  console.log("Tenant Management System initialized");
});

function initializeTenantManager() {
  tenantManager = new DataManager({
    // DOM Elements
    tableId: "tenantSummary",
    tableBodyId: "tenantSummaryBody",
    modalId: "tenantModal",
    addModalId: "addTenantModal",
    formId: "addTenantForm",
    addSubmitBtnId: "saveTenantBtn",
    paginationId: "tenantPagination",
    searchInputId: "tenantLiveSearch",
    addButtonId: "addNewTenantBtn",
    csrfTokenName: "add_tenant_form",

    // API Endpoints
    fetchUrl: "../backend/tenants/get_tenants.php",
    addUrl: "../backend/tenants/add_tenant.php",
    updateUrl: "../backend/tenants/update_tenant.php",
    fetchDetailsUrl: "../backend/tenants/fetch_tenant_details.php",

    // Business Logic
    itemName: "tenant",
    itemNamePlural: "tenants",
    idField: "tenant_code", // Changed from tenant_id to tenant_code
    statusField: "status",
    detailsKey: "tenant_details",

    // Table Columns
    columns: [
      {
        field: "tenant_code",
        label: "Tenant ID",
        render: (it) => `<strong>${it.tenant_code}</strong>`,
      },
      {
        field: "fullname",
        label: "Tenant Name",
        render: (it) => `${it.firstname} ${it.lastname}`,
      },
      {
        field: "email",
        label: "Email",
        render: (it) => it.email,
      },
      {
        field: "phone",
        label: "Phone",
        render: (it) => it.phone,
      },
      {
        field: "property_name",
        label: "Property",
        render: (it) => it.property_name || "N/A",
      },
      {
        field: "status",
        label: "Status",
        render: (it) =>
          it.status == 1
            ? '<span class="badge bg-success">Active</span>'
            : '<span class="badge bg-danger">Inactive</span>',
      },
    ],

    // Custom Row Renderer
    renderRow: function (tenant, userRole) {
      const isActive = Number(tenant.status) === 1;
      const statusBadge = isActive
        ? '<span class="badge bg-success">Active</span>'
        : '<span class="badge bg-danger">Inactive</span>';

      let row = `
                <td>${tenant.tenant_code}</td>
                <td>${tenant.firstname} ${tenant.lastname}</td>
                <td>${tenant.email}</td>
                <td>${tenant.phone}</td>
                <td>${tenant.property_code || "N/A"}</td>
                <td>${statusBadge}</td>
            `;

      if (isActive) {
        row += `<td><span class="edit-icon" data-id="${tenant.tenant_code}" title="Edit">✏️</span></td>`;
        if (userRole === "Super Admin") {
          row += `<td><span class="delete-icon" data-id="${tenant.tenant_code}" title="Delete">🗑️</span></td>`;
        } else {
          row += `<td></td>`;
        }
      } else {
        if (userRole === "Super Admin") {
          row += `<td colspan="2" style="text-align:center;">
                        <span class="restore-icon" data-id="${tenant.tenant_code}" title="Restore">↻ Restore</span>
                    </td>`;
        } else {
          row += `<td></td><td></td>`;
        }
      }

      return row;
    },

    // Populate Edit Modal Details
    populateDetails: async function (tenant) {
      showEditLoader();

      try {
        const body = document.querySelector("#tenantDetailsTable tbody");
        if (!body) {
          throw new Error("Details table not found");
        }

        // Build HTML for tenant details
        const photoUrl = tenant.photo
          ? `../backend/tenants/tenant_photos/${tenant.photo}`
          : "../assets/default-avatar.png";

        body.innerHTML = `
                    <tr>
                        <td colspan="2" style="text-align:center;padding:20px;">
                            <img src="${photoUrl}" alt="Tenant Photo" id="edit_tenant_photo_preview"
                                 style="width:150px;height:150px;object-fit:cover;border-radius:50%;border:3px solid #dee2e6;">
                            <div style="margin-top:10px;color:#666;font-size:12px;">
                                ${tenant.photo || "Default avatar"}
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Tenant ID</strong></td>
                        <td><input type="text" class="form-control" id="edit_tenant_code" value="${
                          tenant.tenant_code
                        }" readonly></td>
                    </tr>
                    <tr>
                        <td><strong>First Name *</strong></td>
                        <td><input type="text" class="form-control" id="edit_firstname" value="${
                          tenant.firstname
                        }" required></td>
                    </tr>
                    <tr>
                        <td><strong>Last Name *</strong></td>
                        <td><input type="text" class="form-control" id="edit_lastname" value="${
                          tenant.lastname
                        }" required></td>
                    </tr>
                    <tr>
                        <td><strong>Email *</strong></td>
                        <td><input type="email" class="form-control" id="edit_email" value="${
                          tenant.email
                        }" required></td>
                    </tr>
                    <tr>
                        <td><strong>Phone *</strong></td>
                        <td><input type="tel" class="form-control" id="edit_phone" value="${
                          tenant.phone
                        }" required></td>
                    </tr>
                    <tr>
                        <td><strong>Gender</strong></td>
                        <td>
                            <select class="form-control" id="edit_gender">
                                <option value="">Select Gender</option>
                                <option value="Male" ${
                                  tenant.gender === "Male" ? "selected" : ""
                                }>Male</option>
                                <option value="Female" ${
                                  tenant.gender === "Female" ? "selected" : ""
                                }>Female</option>
                                <option value="Other" ${
                                  tenant.gender === "Other" ? "selected" : ""
                                }>Other</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Allocated Property *</strong></td>
                        <td>
                            <select class="form-control" id="edit_tenantProperty"></select>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Allocated Apartment *</strong></td>
                        <td>
                            <select class="form-control" id="edit_tenantPropertyUnit" disabled></select>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Lease Start *</strong></td>
                        <td><input type="date" class="form-control" id="edit_lease_start" value="${
                          tenant.lease_start_date || ""
                        }" required></td>
                    </tr>
                    <tr>
                        <td><strong>Lease End *</strong></td>
                        <td><input type="date" class="form-control" id="edit_lease_end" value="${
                          tenant.lease_end_date || ""
                        }" required></td>
                    </tr>
                    <tr>
                        <td><strong>Payment Frequency *</strong></td>
                        <td>
                            <select class="form-control" id="edit_rent_frequency">
                                <option value="">Select Frequency</option>
                                <option value="Monthly" ${
                                  tenant.payment_frequency === "Monthly"
                                    ? "selected"
                                    : ""
                                }>Monthly</option>
                                <option value="Quarterly" ${
                                  tenant.payment_frequency === "Quarterly"
                                    ? "selected"
                                    : ""
                                }>Quarterly</option>
                                <option value="Semi-Annually" ${
                                  tenant.payment_frequency === "Semi-Annually"
                                    ? "selected"
                                    : ""
                                }>Semi-Annually</option>
                                <option value="Annually" ${
                                  tenant.payment_frequency === "Annually"
                                    ? "selected"
                                    : ""
                                }>Annually</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Status</strong></td>
                        <td>
                            <select class="form-control" id="edit_status">
                                <option value="1" ${
                                  tenant.status == 1 ? "selected" : ""
                                }>Active</option>
                                <option value="0" ${
                                  tenant.status == 0 ? "selected" : ""
                                }>Inactive</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2" style="text-align:center;padding:20px;">
                            <button id="updateTenantBtn" class="btn btn-primary btn-lg">Update Tenant</button>
                            <button id="cancelEditBtn" class="btn btn-secondary btn-lg" style="margin-left:10px;">Cancel</button>
                        </td>
                    </tr>
                `;

        populateSelect(
          "#edit_tenantProperty",
          supportData.properties,
          "property_code",
          ["name", "address"]
        );

        // Set property value and load apartments
        const propertySelect = document.getElementById("edit_tenantProperty");
        if (propertySelect && tenant.property_code) {
          propertySelect.value = tenant.property_code;

          await loadApartmentsForProperty(
            tenant.property_code,
            "#edit_tenantPropertyUnit",
            tenant.apartment_code
          );
        }

        // Setup event listeners for edit form
        setupEditFormListeners(tenant);

        // Set property and load apartments
        await setPropertyAndApartment(tenant);
      } catch (error) {
        console.error("Error populating tenant details:", error);
        const body = document.querySelector("#tenantDetailsTable tbody");
        if (body) {
          body.innerHTML = `
                        <tr>
                            <td colspan="2" style="text-align:center;color:red;padding:20px;">
                                Failed to load tenant details: ${error.message}
                            </td>
                        </tr>
                    `;
        }
      } finally {
        hideEditLoader();
      }
    },

    // Custom initialization
    onInit: function () {
      console.log("Tenant Manager initialized");
      window.tenantManager = this;

      // Setup add form submit handler
      const addForm = document.getElementById("addTenantForm");
      if (addForm) {
        addForm.addEventListener("submit", async (e) => {
          e.preventDefault();

          // Validate required fields
          const requiredFields = [
            "firstname",
            "lastname",
            "email",
            "phone",
            "property_code",
            "apartment_code",
            "lease_start_date",
            "lease_end_date",
            "payment_frequency",
          ];

          const missingFields = [];
          requiredFields.forEach((field) => {
            const element = document.getElementById(field);
            if (element && !element.value.trim()) {
              missingFields.push(field);
            }
          });

          if (missingFields.length > 0) {
            alert(
              `Please fill in all required fields: ${missingFields.join(", ")}`
            );
            return;
          }

          // Prepare FormData
          const formData = new FormData();
          formData.append(
            "firstname",
            document.getElementById("firstname").value
          );
          formData.append(
            "lastname",
            document.getElementById("lastname").value
          );
          formData.append("gender", document.getElementById("gender").value);
          formData.append("email", document.getElementById("email").value);
          formData.append("phone", document.getElementById("phone").value);
          formData.append(
            "property_code",
            document.getElementById("property_code").value
          );
          formData.append(
            "apartment_code",
            document.getElementById("apartment_code").value
          );
          formData.append(
            "occupation",
            document.getElementById("occupation").value || ""
          );
          formData.append(
            "lease_start_date",
            document.getElementById("lease_start_date").value
          );
          formData.append(
            "lease_end_date",
            document.getElementById("lease_end_date").value
          );
          formData.append(
            "payment_frequency",
            document.getElementById("payment_frequency").value
          );

          // Optional fields
          const optionalFields = [
            "name_of_employer",
            "employer_address",
            "employer_contact",
            "referee_name",
            "referee_phone",
            "emergency_contact_name",
            "emergency_contact_phone",
          ];

          optionalFields.forEach((field) => {
            const element = document.getElementById(field);
            if (element && element.value.trim()) {
              formData.append(field, element.value);
            }
          });

          // Add photo if selected
          const photoInput = document.getElementById("tenantPhoto");
          if (photoInput && photoInput.files[0]) {
            formData.append("photo", photoInput.files[0]);
          }

          // Add CSRF token
          formData.append("token_id", "add_tenant_form");
          formData.append("csrf_token", this.csrfToken || "");

          // Submit using DataManager
          this.addItem.call(this, formData, true);
        });
      }
    },
  });

  // Store globally for debugging
  window.tm = tenantManager;
}

/**
 * Setup edit form event listeners
 */
function setupEditFormListeners(tenant) {
  // Property change listener
  const propertySelect = document.getElementById("edit_tenantProperty");
  if (propertySelect) {
    propertySelect.addEventListener("change", async function () {
      const propertyCode = this.value;
      await loadApartmentsForProperty(
        propertyCode,
        "#edit_tenantPropertyUnit",
        tenant.apartment_code
      );
    });
  }

  // Update button
  const updateBtn = document.getElementById("updateTenantBtn");
  if (updateBtn) {
    // Remove existing listeners
    const newBtn = updateBtn.cloneNode(true);
    updateBtn.parentNode.replaceChild(newBtn, updateBtn);

    newBtn.addEventListener("click", () => {
      UI.confirm("Are you sure you want to update this tenant?", () => {
        const updateData = {
          tenant_id: tenant.id,
          tenant_code: tenant.tenant_code,
          firstname: document.getElementById("edit_firstname").value,
          lastname: document.getElementById("edit_lastname").value,
          email: document.getElementById("edit_email").value,
          phone: document.getElementById("edit_phone").value,
          gender: document.getElementById("edit_gender").value,
          property_code: document.getElementById("edit_tenantProperty").value,
          apartment_code: document.getElementById("edit_tenantPropertyUnit")
            .value,
          lease_start_date: document.getElementById("edit_lease_start").value,
          lease_end_date: document.getElementById("edit_lease_end").value,
          payment_frequency: document.getElementById("edit_rent_frequency")
            .value,
          status: document.getElementById("edit_status").value,
          action_type: "update_all",
        };

        tenantManager.updateItem(tenant.tenant_code, updateData);
      });
    });
  }

  // Cancel button
  const cancelBtn = document.getElementById("cancelEditBtn");
  if (cancelBtn) {
    cancelBtn.addEventListener("click", () => {
      document.getElementById("tenantModal").style.display = "none";
    });
  }
}

/**
 * Set property and load apartments for edit form
 */
async function setPropertyAndApartment(tenant) {
  // Set property
  const propertySelect = document.getElementById("edit_tenantProperty");
  if (propertySelect && tenant.property_code) {
    propertySelect.value = tenant.property_code;

    // Load apartments for this property
    await loadApartmentsForProperty(
      tenant.property_code,
      "#edit_tenantPropertyUnit",
      tenant.apartment_code
    );
  }
}
