// apartment-type-manager.js
document.addEventListener("DOMContentLoaded", () => {
  initFormValidation("addApartmentTypeForm", "submitBtnAddApartmentType", "addApartmentTypeMessage");
  // Create and initialize the manager
  const apartmentTypeManager = new DataManager({
    // === DOM Element IDs ===
    tableId: 'apartmentTypeSummary',
    tableBodyId: 'apartmentTypeSummaryBody',
    modalId: 'apartmentTypeModal',
    addModalId: 'addNewApartmentTypeModal',
    formId: 'addApartmentTypeForm',
    addSubmitBtnId: 'submitBtnAddApartmentType',
    paginationId: 'apartmentTypePagination',
    searchInputId: 'apartmentTypeLiveSearch',
    addButtonId: 'addNewApartmentTypeBtn',
    csrfTokenName: "add_apartment_type_form",
    
    // === API Endpoints ===
    fetchUrl: '../backend/apartment_types/fetch_apartment_types.php',
    addUrl: '../backend/apartment_types/add_apartment_type.php',
    updateUrl: '../backend/apartment_types/update_apartment_type.php',
    fetchDetailsUrl: '../backend/apartment_types/fetch_apartment_type_details.php',
    
    // === Business Logic ===
    itemName: 'apartmentType',
    itemNamePlural: 'apartmentTypes',
    idField: 'type_id',
    nameField: 'type_name',
    statusField: 'status',
    detailsKey: "apartment_type_details",

    
    // === Columns Configuration ===
    // IMPORTANT: These columns should match what's in your HTML table header
    columns: [
      { 
        field: 'type_id', 
        label: 'Type ID',
        render: (item) => `<strong>${item.type_id}</strong>`
      },
      { 
        field: 'type_name', 
        label: 'Type Name',
        render: (item) => item.type_name
      },
    // ,
    //   { 
    //     field: 'description', 
    //     label: 'Description',
    //     render: (item) => item.description || '<em>No description</em>'
    //   }
    ],
    
    // === Custom Row Rendering ===
    renderRow: function(item, userRole) {
      // Debug: Check what data you're receiving
      console.log('Apartment Type Item:', item);
      console.log('User Role:', userRole);
      
      const status = item.status == 1 ? 
        '<span style="color: green;">Active</span>' : 
        '<span style="color: red;">Inactive</span>';
      
      let rowHTML = `
        <td>${item.type_id}</td>
        <td>${item.type_name}</td>
        
        <td>${status}</td>`;
      
      // return rowHTML;
      if (item.status == 1) {
                rowHTML += `
                    <td><span class="edit-icon" data-id="${item.type_id}">✏️</span></td>
                    <td><span class="delete-icon" data-id="${item.type_id}">🗑️</span></td>`;
            } else {
                rowHTML += `
                    <td colspan="2" style="text-align:center;">
                        <span class="restore-icon" data-id="${item.type_id}">↻ Restore</span>
                    </td>`;
            }

            return rowHTML;
    },
    
    // === Custom Details Population ===
    populateDetails: function(apartmentType) {
      console.log('Populating details for:', apartmentType);
      
      const tableBody = document.querySelector("#apartmentTypeDetailsTable tbody");
      if (!tableBody) {
        console.error('Details table body not found!');
        return;
      }

      tableBody.innerHTML = `
        <tr>
          <td><strong>Type ID:</strong></td>
          <td><input type="text" id="edit_type_id" value="${apartmentType.type_id || ''}" readonly></td>
        </tr>
        <tr>
          <td><strong>Apartment Type Name:</strong></td>
          <td><input type="text" id="edit_type_name" value="${apartmentType.type_name || ''}"></td>
        </tr>
        <tr>
          <td><strong>Description:</strong></td>
          <td><textarea id="edit_description" rows="3">${apartmentType.description || ''}</textarea></td>
        </tr>
        <tr>
          <td><strong>Status:</strong></td>
          <td>
            <select id="edit_status">
              <option value="1" ${apartmentType.status == 1 ? 'selected' : ''}>Active</option>
              <option value="0" ${apartmentType.status == 0 ? 'selected' : ''}>Inactive</option>
            </select>
          </td>
        </tr>
        <tr>
          <td colspan="2" style="text-align:center;">
            <button id="updateApartmentTypeBtn" class="btn btn-primary">Update Apartment Type</button>
          </td>
        </tr>`;

      // Remove any existing event listeners first
      const oldBtn = document.getElementById('updateApartmentTypeBtn');
      if (oldBtn) {
          oldBtn.replaceWith(oldBtn.cloneNode(true));
      }

      document.getElementById("updateApartmentTypeBtn").addEventListener("click", () => {
        UI.confirm(
          "Are you sure you want to update this apartment type?",
          () => {
            this.updateItem(apartmentType.type_id, {
              type_id: apartmentType.type_id,
              type_name: document.getElementById("edit_type_name").value,
              description: document.getElementById("edit_description").value,
              status: document.getElementById("edit_status").value,
              action_type: "update_all"
            });
          }
        );
      });
    },
    
    // === Custom Initialization ===
    onInit: function() {
      console.log('Apartment Type Manager initialized successfully');
      
      // You can access the manager instance using 'this'
      // For example, if you need to expose it globally:
      window.apartmentTypeManager = this;
      
      // Add any custom initialization here
    }
  });
  
  // The variable IS being used - it holds the DataManager instance
  // You can use it later if needed:
  // apartmentTypeManager.fetchData(); // To manually refresh
  
  // Or expose it to the window for debugging:
  window.ptm = apartmentTypeManager;
  console.log('Apartment Type Manager instance created:', apartmentTypeManager);
});