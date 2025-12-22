// property.js
document.getElementById("propertyPhoto").addEventListener("change", function (e) {
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
function showEditLoader() {
    const loader = document.getElementById("editModalLoader");
    loader.style.display = "flex";

    // disable all inputs & buttons
    document.querySelector("#propertyDetailsTable").style.pointerEvents = "none";
    document.querySelector("#propertyDetailsTable").style.opacity = "0.4";
}

function hideEditLoader() {
    const loader = document.getElementById("editModalLoader");
    loader.style.display = "none";

    // re-enable inputs
    document.querySelector("#propertyDetailsTable").style.pointerEvents = "auto";
    document.querySelector("#propertyDetailsTable").style.opacity = "1";
}

document.addEventListener("DOMContentLoaded", () => {
    // initFormValidation("addPropertyForm", "submitBtnAddProperty", "addPropertyMessage");
    initFormValidation('addPropertyForm', 'submitBtnAddProperty', 'addPropertyMessage', {
    maxFileSizeMB: 2, // Override default
    allowedFileTypes: ['jpg', 'jpeg', 'png'] // Override default
});
  loadCountries();
  loadSupportData();
  const propertyManager = new DataManager({
    // ============================
    // DOM ELEMENTS
    // ============================
    tableId: "propertySummary",
    tableBodyId: "propertySummaryBody",
    modalId: "propertyModal",
    addModalId: "addNewPropertyModal",
    formId: "addPropertyForm",
    addSubmitBtnId: 'submitBtnAddProperty',
    paginationId: "propertyPagination",
    searchInputId: "propertyLiveSearch",
    addButtonId: "addNewPropertyBtn",
    csrfTokenName: "add_property_form",

    // ============================
    // API ENDPOINTS
    // ============================
    fetchUrl: "../backend/properties/fetch_properties.php",
    addUrl: "../backend/properties/add_property.php",
    updateUrl: "../backend/properties/update_property.php",
    fetchDetailsUrl: "../backend/properties/fetch_property_details.php",

    // ============================
    // BUSINESS LOGIC
    // ============================
    itemName: "property",
    itemNamePlural: "properties",
    idField: "property_id",
    nameField: "property_name",
    statusField: "status",
    detailsKey: "property_details",

    // ============================
    // TABLE COLUMNS
    // Must match your HTML table
    // ============================
    columns: [
      {
        field: "property_code",
        label: "Property ID",
        render: (item) => `<strong>${item.property_code}</strong>`,
      },
      {
        field: "name",
        label: "Client Name",
        render: (item) => item.client_name,
      },
      {
        field: "property_type_name",
        label: "Type",
        render: (item) => item.property_type_name,
      },
      {
        field: "agent_fullname",
        label: "Agent",
        render: (item) => item.agent_fullname,
      },
      {
        field: "location",
        label: "Location",
        render: (item) => item.address,
      },
    ],

    // ============================
    // ROW RENDERING
    // ============================
    renderRow: function (item, userRole) {
      console.log("UserRole inside renderRow:", userRole);


    const isActive = Number(item.status) === 1;

    const statusLabel = isActive
        ? `<span style="color: green;">Active</span>`
        : `<span style="color: red;">Inactive</span>`;

    let html = `
        <td>${item.property_code}</td>
        <td>${item.client_name}</td>
        <td>${item.property_type_name}</td>
        <td>${item.agent_fullname}</td>
        <td>${item.address}</td>
        <td>${statusLabel}</td>
    `;

    if (isActive) {
        html += `<td><span class="edit-icon" data-id="${item.property_code}" title="Edit">✏️</span></td>`;

        if (userRole === "Super Admin") {
            html += `<td><span class="delete-icon" data-id="${item.property_code}" title="Delete">🗑️</span></td>`;
        } else {
            html += `<td></td>`;
        }

    } else {
        if (userRole === "Super Admin") {
            html += `
                <td colspan="2" style="text-align:center;">
                    <span class="restore-icon" data-id="${item.property_code}" title="Restore">↻ Restore</span>
                </td>
            `;
        } else {
            html += `<td></td><td></td>`;
        }
    }

    return html;
}
,

    // ============================
    // POPULATE DETAILS MODAL
    // ============================
   populateDetails: async function (property) {
    showEditLoader(); // 🔥 Disable modal + show spinner
    const body = document.querySelector("#propertyDetailsTable tbody");
const photoUrl = `../backend/properties/property_photos/${property.photo}`;
if (!body) return;

// Build the form UI
body.innerHTML = `
<tr>
    <td colspan="2" style="text-align:center;">
        <img src="${photoUrl}" style="width:120px;height:120px;object-fit:cover;border-radius:8px;border:1px solid #ccc;margin-bottom:10px;">
    </td>
</tr>

<tr>
    <td><strong>Property ID:</strong></td>
    <td><input type="text" id="edit_property_id" value="${property.property_code}" readonly></td>
</tr>

<tr>
    <td><strong>Property Name:</strong></td>
    <td><input type="text" id="edit_property_name" value="${property.name}"></td>
</tr>

<tr>
    <td><strong>Client Name:</strong></td>
    <td><select id="edit_client_code" class="select2"></select></td>
</tr>

<tr>
    <td><strong>Agent:</strong></td>
    <td><select id="edit_agent_code" class="select2"></select></td>
</tr>

<tr>
    <td><strong>Property Type:</strong></td>
    <td><select id="edit_property_type_id" class="select2"></select></td>
</tr>

<tr>
    <td><strong>No. Of Units:</strong></td>
    <td><input type="text" id="edit_property_type_unit" value="${property.property_type_unit}"></td>
</tr>

<tr>
    <td><strong>Country:</strong></td>
    <td><select id="edit_country" class="select2"></select></td>
</tr>

<tr>
    <td><strong>State:</strong></td>
    <td><select id="edit_state" class="select2" disabled></select></td>
</tr>

<tr>
    <td><strong>City:</strong></td>
    <td><select id="edit_city" class="select2" disabled></select></td>
</tr>

<tr>
    <td><strong>Address</strong></td>
    <td><input type="text" id="edit_location" value="${property.address}"></td>
</tr>

<tr>
    <td><strong>Contact Name</strong></td>
    <td><input type="text" id="edit_contact_name" value="${property.contact_name}"></td>
</tr>

<tr>
    <td><strong>Contact Phone Number</strong></td>
    <td><input type="number" id="edit_contact_phone" value="${property.contact_phone}"></td>
</tr>

<tr>
    <td><strong>Status:</strong></td>
    <td>
        <select id="edit_status" class="select2">
            <option value="1" ${property.status == 1 ? "selected" : ""}>Active</option>
            <option value="0" ${property.status == 0 ? "selected" : ""}>Inactive</option>
        </select>
    </td>
</tr>

<tr>
    <td colspan="2" style="text-align:center;">
        <button id="updatePropertyBtn" class="btn btn-primary">Update Property</button>
    </td>
</tr>
`;

// -------------------------------------------------------
// INIT SELECT2 ON ALL SELECTS
// -------------------------------------------------------
$(".select2").select2({
    width: "100%",
    placeholder: "Select an option",
    allowClear: true
});

// -------------------------------------------------------
// COUNTRY → STATE (auto-clear + disable + reload)
// -------------------------------------------------------
$("#edit_country").on("change", async function () {
    const country = this.value;

    $("#edit_state").prop("disabled", true).empty().trigger("change");
    $("#edit_city").prop("disabled", true).empty().trigger("change");

    if (!country) return;

    await loadStates(country, "#edit_state");

    $("#edit_state").prop("disabled", false).trigger("change");
});

// -------------------------------------------------------
// STATE → CITY (auto-clear + disable + reload)
// -------------------------------------------------------
$("#edit_state").on("change", async function () {
    const state = this.value;
    const country = $("#edit_country").val();

    $("#edit_city").prop("disabled", true).empty().trigger("change");

    if (!state) return;

    await loadCities(country, state, "#edit_city");

    $("#edit_city").prop("disabled", false).trigger("change");
});

// ----------------------------------------------------------
// After DOM is ready, load support data for selects
// ----------------------------------------------------------
await loadSupportData();
await loadCountries();

// Apply default values AFTER loading
$("#edit_agent_code").val(property.agent_code).trigger("change");
$("#edit_client_code").val(property.client_code).trigger("change");
$("#edit_property_type_id").val(property.property_type_id).trigger("change");

$("#edit_country").val(property.country).trigger("change");
await loadStates(property.country, "#edit_state");

$("#edit_state").val(property.state).trigger("change");
await loadCities(property.country, property.state, "#edit_city");

$("#edit_city").val(property.city).trigger("change");

 hideEditLoader(); // 🔥 All done — show form & enable modal

      // Attach update handler
      const btn = document.getElementById("updatePropertyBtn");
      btn.addEventListener("click", () => {
        UI.confirm("Are you sure you want to update this property?", () => {
          this.updateItem(property.property_code, {
            property_id: property.property_code,
            property_name: document.getElementById("edit_property_name").value,
            agent_code: document.getElementById("edit_agent_code").value,
            property_type_id: document.getElementById("edit_property_type_id").value,
            client_code: document.getElementById("edit_client_code").value,
            property_type_unit: document.getElementById("edit_property_type_unit").value,
            country: document.getElementById("edit_country").value,
            state: document.getElementById("edit_state").value,
            city: document.getElementById("edit_city").value,
            address: document.getElementById("edit_location").value,
            contact_name: document.getElementById("edit_contact_name").value,
            contact_phone: document.getElementById("edit_contact_phone").value,
            status: document.getElementById("edit_status").value,
            action_type: "update_all",
          });
        });
      });
    },

    // ============================
    // INIT
    // ============================
    onInit: function () {
      console.log("Property Manager Initialized");
      window.propertyManager = this;
    },
  });

  window.pm = propertyManager;

  async function loadSupportData() {
    try {
        const res = await fetch("../backend/properties/fetch_agent_property_type.php");
        const data = await res.json();

        if (data.response_code !== 200) {
            console.error("Failed loading support data");
            return;
        }

        populateSelect("#agent_code", data.data.agents, "agent_code", (item) => `${item.firstname} ${item.lastname}`);
        populateSelect("#client_code", data.data.clients, "client_code", (item) => `${item.firstname} ${item.lastname}`);
        populateSelect("#property_type_id", data.data.property_types, "type_id", "type_name");
        populateSelect("#edit_agent_code", data.data.agents, "agent_code", (item) => `${item.firstname} ${item.lastname}`);
         populateSelect("#edit_client_code", data.data.clients, "client_code", (item) => `${item.firstname} ${item.lastname}`);
        populateSelect("#edit_property_type_id", data.data.property_types, "type_id", "type_name");
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
        option.textContent = typeof labelKey === "function" ? labelKey(item) : item[labelKey];
        select.appendChild(option);
    });
}

async function loadCountries() {
    const res = await fetch("https://countriesnow.space/api/v0.1/countries/");
    const json = await res.json();

    const countries = json.data.map(c => ({
        code: c.country,
        name: c.country
    }));

    populateSelect("#property_country", countries, "name", "code");
    populateSelect("#edit_country", countries, "name", "code");
}


async function loadStates(countryName, selector) {
    try {
        const res = await fetch("https://countriesnow.space/api/v0.1/countries/states", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ country: countryName })
        });

        const json = await res.json();

        if (!json.data || !json.data.states) {
            console.error("No states found:", json);
            populateSelect(selector, [], "code", "name");
            return;
        }

        const states = json.data.states.map(s => ({
            code: s.name,
            name: s.name
        }));

        populateSelect(selector, states, "code", "name");
    } catch (err) {
        console.error("Load states error:", err);
    }
}


async function loadCities(countryName, stateName, selector) {
    try {
        const res = await fetch("https://countriesnow.space/api/v0.1/countries/state/cities", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ country: countryName, state: stateName })
        });

        const json = await res.json();

        if (!json.data) {
            console.error("No cities found:", json);
            populateSelect(selector, [], "id", "name");
            return;
        }

        const cities = json.data.map(city => ({
            id: city,
            name: city
        }));

        populateSelect(selector, cities, "id", "name");
    } catch (err) {
        console.error("Load cities error:", err);
    }
}



// ADD PROPERTY
document.querySelector("#property_country")?.addEventListener("change", function () {
    loadStates(this.value, "#property_state");
    document.querySelector("#property_city").innerHTML = "";
});

document.querySelector("#property_state")?.addEventListener("change", function () {
    const country = document.querySelector("#property_country").value;
    loadCities(country, this.value, "#property_city");
});

});
