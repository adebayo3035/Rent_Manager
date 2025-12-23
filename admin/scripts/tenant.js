// tenant.js

// ===============================
// Preview uploaded tenant photo
// ===============================
document.getElementById("tenantPhoto")?.addEventListener("change", function (e) {
    const file = e.target.files[0];
    const preview = document.getElementById("photoPreview");

    if (!file) {
        preview.innerHTML = "<span style='font-size:12px;color:#777;'>No image</span>";
        return;
    }

    const reader = new FileReader();
    reader.onload = function (event) {
        preview.innerHTML = `<img src="${event.target.result}" 
                                style="width:100%;height:100%;object-fit:cover;">`;
    };
    reader.readAsDataURL(file);
});

document.getElementById("property_code").addEventListener("change", async function () {
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
            `../backend/tenants/fetch_apartments.php?property_code=${encodeURIComponent(propertyCode)}`,
            { credentials: "include" }
        );

        const data = await response.json();

        apartmentSelect.innerHTML = `<option value="">-- Select Apartment --</option>`;

        if (data.success && data.apartments.length > 0) {
            data.apartments.forEach(apartment => {
                const option = document.createElement("option");
                option.value = apartment.apartment_code;
                option.textContent =
                    `${apartment.apartment_number} (${apartment.apartment_type_unit})`;
                apartmentSelect.appendChild(option);
            });

            apartmentSelect.disabled = false;
        } else {
            apartmentSelect.innerHTML =
                `<option value="">No available apartments</option>`;
        }

    } catch (error) {
        console.error(error);
        apartmentSelect.innerHTML =
            `<option value="">Failed to load apartments</option>`;
    }
});


// -----------------------------
// Modal internal loader helpers
// -----------------------------
function ensureEditLoaderExists() {
    // if the loader element doesn't exist inside tenantModal, create it
    const modal = document.getElementById("tenantModal");
    if (!modal) return null;

    let loader = document.getElementById("editModalLoader");
    if (!loader) {
        loader = document.createElement("div");
        loader.id = "editModalLoader";
        loader.style.cssText = `
            position:absolute;
            inset:0;
            background:rgba(255,255,255,0.85);
            display:flex;
            justify-content:center;
            align-items:center;
            font-size:18px;
            font-weight:600;
            z-index:9999;
            display:none;
        `;

        const spinner = document.createElement("div");
        spinner.className = "ui-spinner";
        spinner.style.cssText = `
            border: 4px solid #f3f3f3;
            border-top: 4px solid #3498db;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            animation: spin 1s linear infinite;
        `;

        const label = document.createElement("span");
        label.innerText = " Loading...";
        label.style.marginLeft = "10px";

        loader.appendChild(spinner);
        loader.appendChild(label);
        modal.querySelector(".modal-content")?.appendChild(loader);

        // add keyframes if not present
        if (!document.getElementById("tenant-spinner-keyframes")) {
            const style = document.createElement("style");
            style.id = "tenant-spinner-keyframes";
            style.innerHTML = `
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
            `;
            document.head.appendChild(style);
        }
    }
    return loader;
}

function showEditLoader() {
    const loader = ensureEditLoaderExists();
    if (!loader) return;
    loader.style.display = "flex";

    const table = document.querySelector("#tenantDetailsTable");
    if (table) {
        table.style.pointerEvents = "none";
        table.style.opacity = "0.4";
    }
}

function hideEditLoader() {
    const loader = document.getElementById("editModalLoader");
    if (loader) loader.style.display = "none";

    const table = document.querySelector("#tenantDetailsTable");
    if (table) {
        table.style.pointerEvents = "auto";
        table.style.opacity = "1";
    }
}

// -----------------------------
// Support data storage & helpers
// -----------------------------
let supportData = {
    properties: [], // { property_id, property_name, ... }
    units: [],      // { unit_id, property_id, unit_name, ... }
    agents: [],     // optional if returned
    property_types: []
};

async function loadSupportData() {
    try {
        const res = await fetch("../backend/tenant/fetch_unit_property_type.php");
        const data = await res.json();

        if (data.response_code !== 200) {
            console.error("Failed loading support data", data);
            return;
        }

        // store data
        // attempt to support multiple possible keys: properties, units, agents, property_types
        supportData = {
            properties: data.data.properties || data.data.properties_list || data.data.property_list || data.data.properties || [],
            units: data.data.units || data.data.property_units || data.data.units_list || [],
            agents: data.data.agents || [],
            property_types: data.data.property_types || []
        };

        // Populate add-form selects
        populateSelect("#tenantProperty", supportData.properties, "property_id", "property_name");
        // units left empty until a property is chosen
        populateSelect("#tenantPropertyUnit", [], "unit_id", "unit_name");

        // if the endpoint returned agents/property_types (like sample), populate them too (for compatibility)
        if (supportData.agents && supportData.agents.length) {
            populateSelect("#tenantAgent", supportData.agents, "agent_code", (i) => `${i.firstname} ${i.lastname}`);
        }
        if (supportData.property_types && supportData.property_types.length) {
            populateSelect("#tenantPropertyType", supportData.property_types, "type_id", "type_name");
        }

        // Also populate edit-selects if they already exist
        populateSelect("#edit_tenantProperty", supportData.properties, "property_id", "property_name");
        // edit unit select kept empty until tenant property preselection triggers population
        populateSelect("#edit_tenantPropertyUnit", [], "unit_id", "unit_name");

    } catch (err) {
        console.error("Error fetching support data:", err);
    }
}

function populateSelect(selector, list, valueKey, labelKey) {
    const select = document.querySelector(selector);
    if (!select) return;

    select.innerHTML = `<option value="">Select...</option>`;

    list.forEach(item => {
        const option = document.createElement("option");
        option.value = item[valueKey];
        option.textContent = (typeof labelKey === "function") ? labelKey(item) : item[labelKey];
        select.appendChild(option);
    });
}

function populateUnitsForProperty(propertyId, selector) {
    const units = Array.isArray(supportData.units)
        ? supportData.units.filter(u => String(u.property_id) === String(propertyId))
        : [];

    populateSelect(selector, units, "unit_id", "unit_name");
}

// -----------------------------
// Init once DOM is ready
// -----------------------------
document.addEventListener("DOMContentLoaded", () => {
    // initialize form validator with same signature as your template
    initFormValidation('addTenantForm', 'saveTenantBtn', 'addTenantMessage', {
        maxFileSizeMB: 2,
        allowedFileTypes: ['jpg', 'jpeg', 'png']
    });

    // pre-load support data for add form selects
    loadSupportData();

    // DataManager instantiation (adapted from property template)
    const tenantManager = new DataManager({
        // DOM
        tableId: "tenantSummary",
        tableBodyId: "tenantSummaryBody",
        modalId: "tenantModal",
        addModalId: "addTenantModal",
        formId: "addTenantForm",
        addSubmitBtnId: 'saveTenantBtn',
        paginationId: "tenantPagination",
        searchInputId: "tenantLiveSearch",
        addButtonId: "addNewTenantBtn",
        csrfTokenName: "add_tenant_form",

        // API endpoints (adjust to your backend paths)
        fetchUrl: "../backend/tenants/get_tenants.php",
        addUrl: "../backend/tenants/add_tenant.php",
        updateUrl: "../backend/tenants/update_tenant.php",
        fetchDetailsUrl: "../backend/tenants/fetch_tenant_details.php",

        // business logic
        itemName: "tenant",
        itemNamePlural: "tenants",
        idField: "tenant_id",
        statusField: "status",
        detailsKey: "tenant_details",

        // table columns (match your HTML)
        columns: [
            { field: "tenant_id", label: "Tenant ID", render: (it) => `<strong>${it.tenant_id}</strong>` },
            { field: "fullname", label: "Tenant Name", render: (it) => `${it.firstname} ${it.lastname}` },
            { field: "email", label: "Email", render: (it) => it.email },
            { field: "phone_number", label: "Phone Number", render: (it) => it.phone_number },
            { field: "status", label: "Status", render: (it) => (it.status == 1 ? `<span style="color:green">Active</span>` : `<span style="color:red">Inactive</span>`) }
        ],

        // render rows
        renderRow: function (tenant, userRole) {
            const isActive = Number(tenant.status) === 1;
            const statusHTML = isActive ? `<span style="color:green">Active</span>` : `<span style="color:red">Inactive</span>`;

            let row = `
                <td>${tenant.tenant_code}</td>
                <td>${tenant.firstname} ${tenant.lastname}</td>
                <td>${tenant.email}</td>
                <td>${tenant.phone}</td>
                <td>${statusHTML}</td>
            `;

            if (isActive) {
                row += `<td><span class="edit-icon" data-id="${tenant.tenant_code}">✏️</span></td>`;
                row += `<td><span class="delete-icon" data-id="${tenant.tenant_code}">🗑️</span></td>`;
            } else {
                row += `<td colspan="2" style="text-align:center;"><span class="restore-icon" data-id="${tenant.tenant_code}">↻ Restore</span></td>`;
            }

            return row;
        },

        // populate details: show table + editable inputs, load support data, set values
        populateDetails: async function (tenant) {
            showEditLoader();
            const body = document.querySelector("#tenantDetailsTable tbody");
            if (!body) {
                hideEditLoader();
                return;
            }

            const photoUrl = tenant.photo ? `../backend/tenants/tenant_photos/${tenant.photo}` : "";

            body.innerHTML = `
                <tr>
                    <td colspan="2" style="text-align:center;">
                        <img src="${photoUrl || ''}" alt="Tenant Photo" id="edit_tenant_photo_preview"
                             style="width:120px;height:120px;object-fit:cover;border-radius:8px;border:1px solid #ccc;margin-bottom:10px;">
                    </td>
                </tr>

                <tr><td><strong>Tenant ID</strong></td><td><input type="text" id="edit_tenant_id" value="${tenant.tenant_code}" readonly></td></tr>

                <tr><td><strong>First Name</strong></td><td><input type="text" id="edit_firstname" value="${tenant.firstname}"></td></tr>

                <tr><td><strong>Last Name</strong></td><td><input type="text" id="edit_lastname" value="${tenant.lastname}"></td></tr>

                <tr><td><strong>Email</strong></td><td><input type="email" id="edit_email" value="${tenant.email}"></td></tr>

                <tr><td><strong>Phone</strong></td><td><input type="text" id="edit_phone" value="${tenant.phone}"></td></tr>

                <tr><td><strong>Gender</strong></td>
                    <td>
                        <select id="edit_gender">
                            <option value="">Select</option>
                            <option value="male" ${tenant.gender === "male" ? "selected" : ""}>Male</option>
                            <option value="female" ${tenant.gender === "female" ? "selected" : ""}>Female</option>
                        </select>
                    </td>
                </tr>

                <tr><td><strong>Allocated Property</strong></td>
                    <td>
                        <select id="edit_tenantProperty"></select>
                    </td>
                </tr>

                <tr><td><strong>Allocated Unit</strong></td>
                    <td>
                        <select id="edit_tenantPropertyUnit" disabled></select>
                    </td>
                </tr>

                <tr><td><strong>Lease Start</strong></td><td><input type="date" id="edit_lease_start" value="${tenant.lease_start_date || ''}"></td></tr>
                <tr><td><strong>Lease End Date</strong></td><td><input type="date" id="edit_lease_end" value="${tenant.lease_end_date || ''}"></td></tr>

                <tr><td><strong>Payment Frequency</strong></td>
                    <td>
                        <select id="edit_rent_frequency">
                            <option value="">Select</option>
                            <option value="monthly" ${tenant.payment_frequency === 'Monthly' ? 'selected' : ''}>Monthly</option>
                            <option value="quarterly" ${tenant.payment_frequency === 'Quarterly' ? 'selected' : ''}>Quarterly</option>
                            <option value="quarterly" ${tenant.payment_frequency === 'Semi-Annually' ? 'selected' : ''}>Semi-Annually</option>
                            <option value="yearly" ${tenant.payment_frequency === 'Annually' ? 'selected' : ''}>Yearly</option>
                        </select>
                    </td>
                </tr>


                <tr><td><strong>Status</strong></td>
                    <td>
                        <select id="edit_status">
                            <option value="1" ${tenant.status == 1 ? "selected" : ""}>Active</option>
                            <option value="0" ${tenant.status == 0 ? "selected" : ""}>Inactive</option>
                        </select>
                    </td>
                </tr>

                <tr><td colspan="2" style="text-align:center;">
                    <button id="updateTenantBtn" class="btn btn-primary">Update Tenant</button>
                </td></tr>
            `;

            // Attach event: change property -> populate units
            document.querySelector("#edit_tenantProperty")?.addEventListener("change", function () {
                const pid = this.value;
                // clear units then populate
                const unitSelect = document.querySelector("#edit_tenantPropertyUnit");
                if (unitSelect) {
                    unitSelect.innerHTML = "";
                    unitSelect.disabled = true;
                }
                if (pid) {
                    populateUnitsForProperty(pid, "#edit_tenantPropertyUnit");
                    const s = document.querySelector("#edit_tenantPropertyUnit");
                    if (s) s.disabled = false;
                }
            });

            // Reset event listener (prevent duplicates) and attach update handler
            const updateBtn = document.getElementById("updateTenantBtn");
            updateBtn.replaceWith(updateBtn.cloneNode(true));
            document.getElementById("updateTenantBtn").addEventListener("click", () => {
                UI.confirm("Are you sure you want to update this tenant?", () => {
                    this.updateItem(tenant.tenant_id, {
                        tenant_id: tenant.tenant_id,
                        firstname: document.getElementById("edit_firstname").value,
                        lastname: document.getElementById("edit_lastname").value,
                        email: document.getElementById("edit_email").value,
                        phone_number: document.getElementById("edit_phone").value,
                        address: document.getElementById("edit_address").value,
                        gender: document.getElementById("edit_gender").value,
                        property_id: document.getElementById("edit_tenantProperty").value,
                        unit_id: document.getElementById("edit_tenantPropertyUnit").value,
                        lease_start_date: document.getElementById("edit_lease_start").value,
                        rent_amount: document.getElementById("edit_rent_amount").value,
                        security_deposit: document.getElementById("edit_security_deposit").value,
                        rent_payment_frequency: document.getElementById("edit_rent_frequency").value,
                        next_payment: document.getElementById("edit_next_payment").value,
                        status: document.getElementById("edit_status").value,
                        action_type: "update_all"
                    });
                });
            });

            // Ensure support data is loaded & then set default values for property/unit
            if (!supportData.properties.length) {
                await loadSupportData();
            }

            // populate edit property select then set value
            populateSelect("#edit_tenantProperty", supportData.properties, "property_id", "property_name");
            document.querySelector("#edit_tenantProperty").value = tenant.property_id || "";

            // populate units for selected property
            if (tenant.property_id) {
                populateUnitsForProperty(tenant.property_id, "#edit_tenantPropertyUnit");
                document.querySelector("#edit_tenantPropertyUnit").value = tenant.unit_id || "";
                document.querySelector("#edit_tenantPropertyUnit").disabled = false;
            }

            // set photo preview if present
            if (tenant.photo) {
                const p = document.getElementById("edit_tenant_photo_preview");
                if (p) p.src = `../backend/tenants/tenant_photos/${tenant.photo}`;
            }

            hideEditLoader();
        },

        // init
        onInit: function () {
            console.log("Tenant Manager Initialized");
            window.tenantManager = this;

            // Wire up add-form behavior: when property changes, populate units
            document.querySelector("#tenantProperty")?.addEventListener("change", function () {
                const pid = this.value;
                const unitSelect = document.querySelector("#tenantPropertyUnit");
                unitSelect.innerHTML = "";
                unitSelect.disabled = true;
                if (pid) {
                    populateUnitsForProperty(pid, "#tenantPropertyUnit");
                    unitSelect.disabled = false;
                }
            });

            // Handle Save Tenant submit (FormData + file)
            const form = document.getElementById("addTenantForm");
            form?.addEventListener("submit", (ev) => {
                ev.preventDefault();

                const formData = new FormData();
                formData.append("firstname", document.getElementById("tenantFirstName").value);
                formData.append("lastname", document.getElementById("tenantLastName").value);
                formData.append("gender", document.getElementById("tenantGender").value);
                formData.append("email", document.getElementById("tenantEmail").value);
                formData.append("phone_number", document.getElementById("tenantPhone").value);
                formData.append("address", document.getElementById("tenantAddress").value);
                formData.append("property_id", document.getElementById("tenantProperty").value);
                formData.append("unit_id", document.getElementById("tenantPropertyUnit").value);
                formData.append("lease_start_date", document.getElementById("leaseStartDate").value);
                formData.append("rent_amount", document.getElementById("rentAmount").value);
                formData.append("security_deposit", document.getElementById("securityDeposit").value);
                formData.append("rent_payment_frequency", document.getElementById("rentPaymentFrequency").value);
                formData.append("next_payment", document.getElementById("nextPayment").value);

                const fileInput = document.getElementById("tenantPhoto");
                if (fileInput && fileInput.files[0]) {
                    formData.append("photo", fileInput.files[0]);
                }

                // Use DataManager.addItem (assumes it accepts FormData + multipart flag)
                this.addItem.call(this, formData, true);
            });
        }
    });

    window.tm = tenantManager;
    console.log("Tenant Manager instance created:", tenantManager);

    async function loadSupportData() {
    try {
      const res = await fetch(
        "../backend/apartments/fetch_agent_property_type.php"
      );
      const data = await res.json();

      if (data.response_code !== 200) {
        console.error("Failed loading support data");
        return;
      }
      populateSelect(
        "#property_code",
        data.data.properties,
        "property_code",
        "name"
      );
    //   populateSelect(
    //     "#edit_property_code",
    //     data.data.properties,
    //     "property_code",
    //     "name"
    //   );
    } catch (err) {
      console.error("Error fetching support data:", err);
    }
  }

  function populateSelect(selector, list, valueKey, labelKey) {
    const select = document.querySelector(selector);
    if (!select) return;

    select.innerHTML = `<option value="">Select...</option>`;

    list.forEach((item) => {
      const option = document.createElement("option");
      option.value = item[valueKey];
      option.textContent =
        typeof labelKey === "function" ? labelKey(item) : item[labelKey];
      select.appendChild(option);
    });
  }
});
