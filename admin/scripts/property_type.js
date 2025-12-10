// property-type-manager.js
document.addEventListener("DOMContentLoaded", () => {
  initFormValidation("addPropertyTypeForm", "submitBtnAddPropertyType", "addPropertyTypeMessage");
  // Create and initialize the manager
  const propertyTypeManager = new DataManager({
    // === DOM Element IDs ===
    tableId: 'propertyTypeSummary',
    tableBodyId: 'propertyTypeSummaryBody',
    modalId: 'propertyTypeModal',
    addModalId: 'addNewPropertyTypeModal',
    formId: 'addPropertyTypeForm',
    addSubmitBtnId: 'submitBtnAddPropertyType',
    paginationId: 'propertyTypePagination',
    searchInputId: 'propertyTypeLiveSearch',
    addButtonId: 'addNewPropertyTypeBtn',
    
    // === API Endpoints ===
    fetchUrl: '../backend/property_types/fetch_property_types.php',
    addUrl: '../backend/property_types/add_property_type.php',
    updateUrl: '../backend/property_types/update_property_type.php',
    fetchDetailsUrl: '../backend/property_types/fetch_property_type_details.php',
    
    // === Business Logic ===
    itemName: 'propertyType',
    itemNamePlural: 'propertyTypes',
    idField: 'type_id',
    nameField: 'type_name',
    statusField: 'status',
    detailsKey: "property_type_details",

    
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
      console.log('Property Type Item:', item);
      console.log('User Role:', userRole);
      
      const status = item.status == 1 ? 
        '<span style="color: green;">Active</span>' : 
        '<span style="color: red;">Inactive</span>';
      
      let rowHTML = `
        <td>${item.type_id}</td>
        <td>${item.type_name}</td>
        
        <td>${status}</td>`;
      
      // Check if status is a number or string
      // const isActive = parseInt(item.status) === 1;
      
      // if (isActive) {
      //   rowHTML += `
      //     <td><span class="edit-icon" data-id="${item.type_id}" title="Edit">✏️</span></td>`;
        
      //   if (userRole === "Super Admin") {
      //     rowHTML += `
      //       <td><span class="delete-icon" data-id="${item.type_id}" title="Delete">🗑️</span></td>`;
      //   } else {
      //     rowHTML += `<td></td>`;
      //   }
      // } else {
      //   if (userRole === "Super Admin") {
      //     rowHTML += `
      //       <td colspan="2" style="text-align:center;">
      //         <span class="restore-icon" data-id="${item.type_id}" title="Restore">↻ Restore</span>
      //       </td>`;
      //   } else {
      //     rowHTML += `<td></td><td></td>`;
      //   }
      // }
      
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
    populateDetails: function(propertyType) {
      console.log('Populating details for:', propertyType);
      
      const tableBody = document.querySelector("#propertyTypeDetailsTable tbody");
      if (!tableBody) {
        console.error('Details table body not found!');
        return;
      }

      tableBody.innerHTML = `
        <tr>
          <td><strong>Type ID:</strong></td>
          <td><input type="text" id="edit_type_id" value="${propertyType.type_id || ''}" readonly></td>
        </tr>
        <tr>
          <td><strong>Property Type Name:</strong></td>
          <td><input type="text" id="edit_type_name" value="${propertyType.type_name || ''}"></td>
        </tr>
        <tr>
          <td><strong>Description:</strong></td>
          <td><textarea id="edit_description" rows="3">${propertyType.description || ''}</textarea></td>
        </tr>
        <tr>
          <td><strong>Status:</strong></td>
          <td>
            <select id="edit_status">
              <option value="1" ${propertyType.status == 1 ? 'selected' : ''}>Active</option>
              <option value="0" ${propertyType.status == 0 ? 'selected' : ''}>Inactive</option>
            </select>
          </td>
        </tr>
        <tr>
          <td colspan="2" style="text-align:center;">
            <button id="updatePropertyTypeBtn" class="btn btn-primary">Update Property Type</button>
          </td>
        </tr>`;

      // Remove any existing event listeners first
      const oldBtn = document.getElementById('updatePropertyTypeBtn');
      if (oldBtn) {
          oldBtn.replaceWith(oldBtn.cloneNode(true));
      }

      document.getElementById("updatePropertyTypeBtn").addEventListener("click", () => {
        UI.confirm(
          "Are you sure you want to update this property type?",
          () => {
            this.updateItem(propertyType.type_id, {
              type_id: propertyType.type_id,
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
      console.log('Property Type Manager initialized successfully');
      
      // You can access the manager instance using 'this'
      // For example, if you need to expose it globally:
      window.propertyTypeManager = this;
      
      // Add any custom initialization here
    }
  });
  
  // The variable IS being used - it holds the DataManager instance
  // You can use it later if needed:
  // propertyTypeManager.fetchData(); // To manually refresh
  
  // Or expose it to the window for debugging:
  window.ptm = propertyTypeManager;
  console.log('Property Type Manager instance created:', propertyTypeManager);
});