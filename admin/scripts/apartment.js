// apartment.js

function showEditLoader() {
  const loader = document.getElementById("editModalLoader");
  loader.style.display = "flex";

  // disable all inputs & buttons
  document.querySelector("#apartmentDetailsTable").style.pointerEvents = "none";
  document.querySelector("#apartmentDetailsTable").style.opacity = "0.4";
}

function hideEditLoader() {
  const loader = document.getElementById("editModalLoader");
  loader.style.display = "none";

  // re-enable inputs
  document.querySelector("#apartmentDetailsTable").style.pointerEvents = "auto";
  document.querySelector("#apartmentDetailsTable").style.opacity = "1";
}

// Function to format number with commas
function formatNumberWithCommas(value) {
    if (!value && value !== 0) return '';
    
    // Convert to string and remove existing commas
    let cleanValue = value.toString().replace(/,/g, '');
    
    // Check if it's a valid number
    if (cleanValue === '' || isNaN(cleanValue)) return '';
    
    // Split integer and decimal parts
    let parts = cleanValue.split('.');
    let integerPart = parts[0];
    let decimalPart = parts[1] !== undefined ? '.' + parts[1] : '';
    
    // Add commas to integer part
    integerPart = integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    
    return integerPart + decimalPart;
}

// Event handler for input fields
function handleNumberInput(event) {
    let input = event.target;
    
    // Store cursor position
    let cursorPosition = input.selectionStart;
    let originalValue = input.value;
    
    // Get raw value (remove commas and non-numeric except decimal)
    let rawValue = originalValue.replace(/,/g, '');
    
    // Allow only numbers and decimal point
    rawValue = rawValue.replace(/[^\d.]/g, '');
    
    // Handle multiple decimal points
    let decimalCount = (rawValue.match(/\./g) || []).length;
    if (decimalCount > 1) {
        let firstDecimalIndex = rawValue.indexOf('.');
        rawValue = rawValue.substring(0, firstDecimalIndex + 1) + 
                   rawValue.substring(firstDecimalIndex + 1).replace(/\./g, '');
    }
    
    // Format with commas
    let formattedValue = formatNumberWithCommas(rawValue);
    
    // Update input value if changed
    if (originalValue !== formattedValue) {
        input.value = formattedValue;
        
        // Store raw value in data attribute for form submission
        input.setAttribute('data-raw-value', rawValue);
        
        // Calculate new cursor position
        let newLength = formattedValue.length;
        let newCursorPosition = cursorPosition + (newLength - originalValue.length);
        if (newCursorPosition >= 0 && newCursorPosition <= formattedValue.length) {
            input.setSelectionRange(newCursorPosition, newCursorPosition);
        }
    }
}

// Function to initialize number formatting on all number inputs
function initializeNumberInputs() {
    document.querySelectorAll('.number-input').forEach(input => {
        // Remove existing event listener to avoid duplicates
        input.removeEventListener('input', handleNumberInput);
        
        // Add event listener
        input.addEventListener('input', handleNumberInput);
        
        // Initialize any existing values
        if (input.value && !isNaN(input.value.replace(/,/g, ''))) {
            let rawValue = input.value.toString().replace(/,/g, '');
            let formattedValue = formatNumberWithCommas(rawValue);
            
            if (input.value !== formattedValue) {
                input.value = formattedValue;
            }
            input.setAttribute('data-raw-value', rawValue);
        }
    });
}

document.addEventListener("DOMContentLoaded", () => {
  // initFormValidation("addApartmentForm", "submitBtnAddApartment", "addApartmentMessage");
  initFormValidation(
    "addApartmentForm",
    "submitBtnAddApartment",
    "addApartmentMessage",
    {},
  );

  // Apply to all number inputs with a specific class
  initializeNumberInputs();
  
  loadSupportData();
  const apartmentManager = new DataManager({
    // ============================
    // DOM ELEMENTS
    // ============================
    tableId: "apartmentSummary",
    tableBodyId: "apartmentSummaryBody",
    modalId: "apartmentModal",
    addModalId: "addNewApartmentModal",
    formId: "addApartmentForm",
    addSubmitBtnId: "submitBtnAddApartment",
    paginationId: "apartmentPagination",
    searchInputId: "apartmentLiveSearch",
    addButtonId: "addNewApartmentBtn",
    csrfTokenName: "add_apartment_form",

    // ============================
    // API ENDPOINTS
    // ============================
    fetchUrl: "../backend/apartments/fetch_apartments.php",
    addUrl: "../backend/apartments/add_apartment.php",
    updateUrl: "../backend/apartments/update_apartment.php",
    fetchDetailsUrl: "../backend/apartments/fetch_apartment_details.php",

    // ============================
    // BUSINESS LOGIC
    // ============================
    itemName: "apartment",
    itemNamePlural: "apartments",
    idField: "apartment_id",
    nameField: "apartment_name",
    statusField: "status",
    detailsKey: "apartment_details",

    // ============================
    // TABLE COLUMNS
    // Must match your HTML table
    // ============================
    columns: [
      {
        field: "apartment_code",
        label: "Apartment ID",
        render: (item) => `<strong>${item.apartment_code}</strong>`,
      },
      {
        field: "property_name",
        label: "Property Name",
        render: (item) => item.property_name,
      },
      {
        field: "apartment_type_name",
        label: "Type",
        render: (item) => item.apartment_type_name,
      },
      {
        field: "agent_fullname",
        label: "Agent",
        render: (item) => item.agent.agent_fullname,
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
        <td>${item.apartment_code}</td>
        <td>${item.property_name}</td>
        <td>${item.apartment_type_name}</td>
        <td>${item.agent.agent_fullname}</td>
        <td>${statusLabel}</td>
    `;

      if (isActive) {
        html += `<td><span class="edit-icon" data-id="${item.apartment_code}" title="Edit">✏️</span></td>`;

        if (userRole === "Super Admin") {
          html += `<td><span class="delete-icon" data-id="${item.apartment_code}" title="Delete">🗑️</span></td>`;
        } else {
          html += `<td><span class="not-allowed-icon">⛔</span></td>`;
        }
      } else {
        if (userRole === "Super Admin") {
          html += `
                <td colspan="2" style="text-align:center;">
                    <span class="restore-icon" data-id="${item.apartment_code}" title="Restore">↻ Restore</span>
                </td>
            `;
        } else {
          html += `<td><span class="not-allowed-icon">🚫</span></td>
          <td><span class="not-allowed-icon">⛔</span></td>`;
        }
      }

      return html;
    },
    // ============================
    // POPULATE DETAILS MODAL
    // ============================
    populateDetails: async function (apartment) {
      showEditLoader(); // 🔥 Disable modal + show spinner
      const body = document.querySelector("#apartmentDetailsTable tbody");
      if (!body) return;
      
      // Format numbers for display
      const formattedRent = formatNumberWithCommas(apartment.rent_amount);
      const formattedSecurityDeposit = formatNumberWithCommas(apartment.security_deposit);

      // Build the form UI
      body.innerHTML = `
        <tr>
          <td><strong>Apartment ID:</strong></td>
          <td><input type="text" id="edit_apartment_id" value="${apartment.apartment_code}" readonly></td>
        </tr>
        <tr>
          <td><strong>Property Name:</strong></td>
          <td><input type="text" id="edit_property_name" value="${apartment.property.name} - ${apartment.property_code}" 
              data-property-code="${apartment.property_code}" readonly></td>
        </tr>
        <tr>
          <td><strong>Agent :</strong></td>
          <td><input type="text" id="edit_agent_code" value="${apartment.agent.agent_name} - ${apartment.agent.agent_code}" 
              data-agent-code="${apartment.agent.agent_code || ""}" readonly></td>
        </tr>
        <tr>
          <td><strong>Apartment No. :</strong></td>
          <td><input type="text" id="edit_apartment_number" value="${apartment.apartment_number}" readonly></td>
        </tr>
        <tr>
          <td><strong>Apartment Unit:</strong></td>
          <td><input type="text" id="edit_apartment_type_unit" value="${apartment.apartment_type_unit}" readonly></td>
        </tr>
        <tr>
          <td><strong>Apartment Type:</strong></td>
          <td><select id="edit_apartment_type_id" class="select2"></select></td>
        </tr>
        <tr>
          <td><strong>Rent Amount :</strong></td>
          <td><input type="text" class="validate number-input" id="edit_apartment_rent_amount" value="${formattedRent}"></td>
        </tr>
        <tr>
          <td><strong>Security Deposit :</strong></td>
          <td><input type="text" class="validate number-input" id="edit_apartment_security_deposit" value="${formattedSecurityDeposit}"></td>
        </tr>
        <tr>
          <td><strong>Status:</strong></td>
          <td>
            <select id="edit_status" class="select2">
              <option value="1" ${apartment.status == 1 ? "selected" : ""}>Active</option>
              <option value="0" ${apartment.status == 0 ? "selected" : ""}>Inactive</option>
            </select>
          </td>
        </tr>
        <tr>
          <td colspan="2" style="text-align:center;">
            <button id="updateApartmentBtn" class="btn btn-primary">Update Apartment</button>
          </td>
        </tr>
      `;

      // -------------------------------------------------------
      // INIT SELECT2 ON ALL SELECTS
      // -------------------------------------------------------
      $(".select2").select2({
        width: "100%",
        placeholder: "Select an option",
        allowClear: true,
      });

      // ----------------------------------------------------------
      // After DOM is ready, load support data for selects
      // ----------------------------------------------------------
      await loadSupportData();

      // Apply default values AFTER loading
      $("#edit_apartment_type_id")
        .val(apartment.apartment_type_id)
        .trigger("change");

      // 🔥 IMPORTANT: Initialize number formatting on the newly added inputs
      initializeNumberInputs();

      hideEditLoader(); // 🔥 All done — show form & enable modal

      // Attach update handler
      const btn = document.getElementById("updateApartmentBtn");
      btn.addEventListener("click", () => {
        // Get raw values from data attributes (without commas)
        const rentAmountInput = document.getElementById("edit_apartment_rent_amount");
        const securityDepositInput = document.getElementById("edit_apartment_security_deposit");
        
        // Get raw values from data attributes or parse from input
        let rentAmount = rentAmountInput.getAttribute('data-raw-value');
        let securityDeposit = securityDepositInput.getAttribute('data-raw-value');
        
        // If data attribute doesn't exist, get value and remove commas
        if (!rentAmount && rentAmount !== '0') {
          rentAmount = rentAmountInput.value.replace(/,/g, '');
        }
        if (!securityDeposit && securityDeposit !== '0') {
          securityDeposit = securityDepositInput.value.replace(/,/g, '');
        }
        
        UI.confirm("Are you sure you want to update this apartment?", () => {
          this.updateItem(apartment.apartment_code, {
            apartment_id: apartment.apartment_code,
            property_code: document.getElementById("edit_property_name").dataset.propertyCode,
            apartment_type_unit: document.getElementById("edit_apartment_type_unit").value,
            agent_code: document.getElementById("edit_agent_code").dataset.agentCode,
            apartment_type_id: document.getElementById("edit_apartment_type_id").value,
            rent_amount: rentAmount, // Send raw value
            security_deposit: securityDeposit, // Send raw value
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
      console.log("Apartment Manager Initialized");
      window.apartmentManager = this;
    },
  });

  window.pm = apartmentManager;

  // Define fullApiData in a scope accessible to all functions
  let fullApiData = null;

  async function loadSupportData() {
    try {
      const res = await fetch(
        "../backend/apartments/fetch_agent_property_type.php",
      );
      const data = await res.json();

      if (data.response_code !== 200) {
        console.error("Failed loading support data");
        return;
      }

      // Store the data in the global variable
      fullApiData = data.data;

      populateSelect(
        "#agent_code",
        data.data.agents,
        "agent_code",
        (item) => `${item.firstname} ${item.lastname}`,
      );
      populateSelect(
        "#apartment_type_id",
        data.data.apartment_types,
        "type_id",
        "type_name",
      );
      populateSelect(
        "#property_code",
        data.data.properties,
        "property_code",
        "name",
      );

      // Add the change event listener specifically for property_code
      setupPropertyChangeHandler();
      
      populateSelect(
        "#edit_apartment_type_id",
        data.data.apartment_types,
        "type_id",
        "type_name",
      );
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

  // Function to set up property change handler
  function setupPropertyChangeHandler() {
    const propertySelect = document.getElementById("property_code");
    const agentInput = document.getElementById("agent_code");

    if (!propertySelect || !agentInput) return;

    // Remove any existing event listeners (to avoid duplicates)
    propertySelect.removeEventListener("change", handlePropertyChange);

    // Add the change event listener
    propertySelect.addEventListener("change", handlePropertyChange);
  }

  // Handler function for property change
  function handlePropertyChange() {
    const propertySelect = document.getElementById("property_code");
    const agentInput = document.getElementById("agent_code");

    // Get the selected property code
    const selectedPropertyCode = propertySelect.value;

    // Clear agent input if no property selected or no data
    if (!selectedPropertyCode || !fullApiData) {
      agentInput.value = "";
      return;
    }

    // Find the selected property from the stored data
    const selectedProperty = fullApiData.properties.find(
      (p) => p.property_code === selectedPropertyCode,
    );

    if (selectedProperty && selectedProperty.agent_code) {
      // Find the agent using the agent_code from the property
      const agent = fullApiData.agents.find(
        (a) => a.agent_code === selectedProperty.agent_code,
      );

      if (agent) {
        // Display full name
        agentInput.value = `${agent.firstname} ${agent.lastname}`;
        // Store agent code for form submission if needed
        agentInput.setAttribute("data-agent-code", agent.agent_code);
      } else {
        // If agent not found, just show the agent code
        agentInput.value = selectedProperty.agent_code;
        agentInput.setAttribute("data-agent-code", selectedProperty.agent_code);
      }
    } else {
      agentInput.value = "";
    }
  }
  
  // Initialize on page load
  document.addEventListener("DOMContentLoaded", loadSupportData);
});