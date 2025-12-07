class PropertyTypeManager {
  constructor() {
    this.limit = 10;
    this.currentPage = 1;
    this.init();
  }

  init() {
    this.initModalControls();
    this.setupAddPropertyTypeForm(); // Fixed method name
    this.fetchPropertyTypes();
    this.setupSearchListener();
  }

  /** ------------------------- Modal Controls ------------------------- **/
  initModalControls() {
    // Fix: Use proper selectors
    const addModalClose = document.querySelector(
      "#addNewPropertyTypeModal .close"
    );
    if (addModalClose) {
      addModalClose.addEventListener("click", () => {
        document.getElementById("addNewPropertyTypeModal").style.display =
          "none";
      });
    }

    const viewModalClose = document.querySelector("#propertyTypeModal .close");
    if (viewModalClose) {
      viewModalClose.addEventListener("click", () => {
        document.getElementById("propertyTypeModal").style.display = "none";
        this.fetchPropertyTypes(); // Fixed: Should refresh property types, not groups
      });
    }
    const addNewPropertyTypeBtn = document.getElementById(
      "addNewPropertyTypeBtn"
    );
    if (addNewPropertyTypeBtn) {
      addNewPropertyTypeBtn.addEventListener("click", () => {
        this.toggleModal("addNewPropertyTypeModal");
      });
    }

    window.addEventListener("click", (event) => {
      if (event.target.classList.contains("modal")) {
        event.target.style.display = "none";
      }
    });
  }

  toggleModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
      modal.style.display =
        modal.style.display === "none" || modal.style.display === ""
          ? "block"
          : "none";
    }
  }

  /** ------------------------- Form Submission ------------------------- **/
  setupAddPropertyTypeForm() {
    const form = document.getElementById("addPropertyTypeForm");
    if (!form) return;

    const messageDiv = document.getElementById("addPropertyTypeMessage");

    form.addEventListener("submit", (event) => {
      event.preventDefault();

      // Show custom confirm modal
      UI.confirm(
        "Are you sure you want to add a new Property Type?",
        async () => {
          try {
            const formData = new FormData(form);
            const response = await fetch(
              "../backend/property_types/add_property_type.php",
              {
                method: "POST",
                body: formData,
              }
            );

            const data = await response.json();

            if (data.success) {
              this.showSuccessMessage(
                messageDiv,
                "New Property Type has been successfully added!"
              );

              UI.toast("New Property Type added successfully!", "success");
              form.reset();

              this.fetchPropertyTypes(); // reload list
            } else {
              UI.toast(
                "Failed to add Property Type: " + data.message,
                "danger"
              );
              this.showErrorMessage(messageDiv, data.message);
            }
          } catch (error) {
            console.error("Error:", error);
            UI.toast("An error occurred. Please try again later.", "danger");
            this.showErrorMessage(
              messageDiv,
              "An error occurred. Please try again later."
            );
          }
        }
      );
    });
  }

  showSuccessMessage(element, message) {
    element.textContent = message;
    element.style.color = "green";
    UI.alert(message, "Success");
  }

  showErrorMessage(element, message) {
    element.textContent = message;
    element.style.color = "red";
    UI.alert(message, "Error");
  }

  /** ------------------------- Fetch & Display ------------------------- **/
  async fetchPropertyTypes(page = 1) {
    this.currentPage = page;
    const propertyTypesTableBody = document.getElementById(
      "propertyTypeSummaryBody"
    );
    this.showLoadingSpinner(propertyTypesTableBody); // Fixed parameter

    try {
      const [data] = await Promise.all([
        fetch(
          `../backend/property_types/fetch_property_types.php?page=${page}&limit=${this.limit}`
        ).then((res) => res.json()),
        new Promise((resolve) => setTimeout(resolve, 500)), // Reduced to 500ms for better UX
      ]);

      if (data.success && data.propertyTypes && data.propertyTypes.length > 0) {
        // Fixed property name
        this.updateTable(data.propertyTypes, data.user_role);
        this.updatePagination(
          data.pagination.total,
          data.pagination.page,
          data.pagination.limit
        );
        UI.toast("Property Types loaded successfully", "success");
      } else {
        UI.toast("No Property Types Found", "info");
        this.showNoDataMessage(propertyTypesTableBody);
      }
    } catch (error) {
      console.error("Error fetching data:", error);
      UI.toast("Error loading Property Types data", "error");
      this.showErrorMessage(
        propertyTypesTableBody,
        "Error loading Property Types data"
      );
    }
  }

  showLoadingSpinner(container) {
    if (!container) return;
    container.innerHTML = `
            <tr>
                <td colspan="5" style="text-align:center; padding: 20px;">
                    <div class="spinner"
                        style="border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; width: 30px; height: 30px; animation: spin 1s linear infinite; margin: auto;">
                    </div>
                </td>
            </tr>`;
  }

  showNoDataMessage(container) {
    if (!container) return;
    container.innerHTML = `<tr><td colspan="5" style="text-align:center;">No Property Types Found</td></tr>`;
  }

  updateTable(propertyTypes, userRole) {
    const tbody = document.querySelector("#propertyTypeSummary tbody");
    if (!tbody) return;

    tbody.innerHTML = "";

    propertyTypes.forEach((propertyType) => {
      const row = document.createElement("tr");
      const status = propertyType.status === 1 ? "Active" : "Inactive";
      let rowHTML = `
                <td>${propertyType.type_id}</td>
                <td>${propertyType.type_name}</td>
                <td>${status}</td>
            `;

      if (propertyType.status === 1) {
        // Active record
        rowHTML += `<td><span class='edit-icon' data-type-id="${propertyType.type_id}">&#9998;</span></td>`;
        if (userRole === "Super Admin") {
          rowHTML += `<td><span class='delete-icon' data-type-id="${propertyType.type_id}">&#128465;</span></td>`;
        } else {
          rowHTML += `<td></td>`;
        }
      } else {
        // Soft-deleted record
        if (userRole === "Super Admin") {
          rowHTML += `
                    <td colspan="2" style="text-align:center;">
                        <span class="restore-icon" data-class-type="property_type" data-class-id="${propertyType.type_id}" title="Restore">&#9851;</span>
                    </td>`;
        } else {
          rowHTML += `<td></td><td></td>`;
        }
      }

      row.innerHTML = rowHTML;
      tbody.appendChild(row);
    });

    this.setupEditDeleteListeners();
    this.setupRestoreListeners();
  }

  setupEditDeleteListeners() {
    document.querySelectorAll(".edit-icon").forEach((span) => {
      span.addEventListener("click", () =>
        this.fetchPropertyTypeDetails(span.dataset.typeId)
      );
    });

    document.querySelectorAll(".delete-icon").forEach((span) => {
      span.addEventListener("click", () =>
        UI.confirm("Are you sure you want to delete this Property Type?", () =>
          this.deletePropertyType(span.dataset.typeId)
        )
      );
    });
  }

  updatePagination(totalItems, currentPage, itemsPerPage) {
    const paginationContainer = document.getElementById(
      "propertyTypePagination"
    );
    if (!paginationContainer) return;

    paginationContainer.innerHTML = "";
    const totalPages = Math.ceil(totalItems / itemsPerPage);

    this.createPaginationButton(
      "« First",
      1,
      currentPage === 1,
      paginationContainer
    );
    this.createPaginationButton(
      "‹ Prev",
      currentPage - 1,
      currentPage === 1,
      paginationContainer
    );

    const maxVisible = 2;
    const start = Math.max(1, currentPage - maxVisible);
    const end = Math.min(totalPages, currentPage + maxVisible);

    for (let i = start; i <= end; i++) {
      const btn = document.createElement("button");
      btn.textContent = i;
      if (i === currentPage) btn.classList.add("active");
      btn.addEventListener("click", () => this.fetchPropertyTypes(i)); // Fixed method name
      paginationContainer.appendChild(btn);
    }

    this.createPaginationButton(
      "Next ›",
      currentPage + 1,
      currentPage === totalPages,
      paginationContainer
    );
    this.createPaginationButton(
      "Last »",
      totalPages,
      currentPage === totalPages,
      paginationContainer
    );
  }

  createPaginationButton(label, page, disabled, container) {
    const btn = document.createElement("button");
    btn.textContent = label;
    if (disabled) btn.disabled = true;
    btn.addEventListener("click", () => this.fetchPropertyTypes(page)); // Fixed method name
    container.appendChild(btn);
  }

  /** ------------------------- CRUD Operations ------------------------- **/
  async fetchPropertyTypeDetails(propertyTypeId) {
    try {
      const response = await fetch(
        `../backend/property_types/fetch_property_type_details.php`,
        {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ property_type_id: propertyTypeId }),
        }
      );
      const data = await response.json();

      if (data.success) {
        this.populatePropertyTypeDetails(data.property_type_details);
        document.getElementById("propertyTypeModal").style.display = "block";
      } else {
        console.error("Failed to fetch Property Type details:", data.message);
        alert("Failed to fetch Property Type details: " + data.message);
      }
    } catch (error) {
      console.error("Error fetching Property Type details:", error);
      alert("Error fetching Property Type details");
    }
  }

  populatePropertyTypeDetails(propertyType) {
    const tableBody = document.querySelector("#propertyTypeDetailsTable tbody");
    if (!tableBody) return;

    tableBody.innerHTML = `
            <tr>
                <td>Type ID</td>
                <td><input type="text" id="type_id" value="${
                  propertyType.type_id
                }" disabled></td>
            </tr>
            <tr>
                <td>Property Type Name</td>
                <td><input type="text" id="type_name" value="${
                  propertyType.type_name || ""
                }"></td>
            </tr>
            <tr>
                <td>Property Type Description</td>
                <td><input type="text" id="type_description" value="${
                  propertyType.description || ""
                }"></td>
            </tr>
            <tr>
                <td>Property Type Status</td>
                <td>
                    <select id="status">
                        <option value="1" ${
                          propertyType.status === "1" ? "selected" : ""
                        }>Active</option>
                        <option value="0" ${
                          propertyType.status === "0" ? "selected" : ""
                        }>Inactive</option>
                    </select>
                </td>
            </tr>
            <tr>
                <td colspan="2" style="text-align:center;">
                    <button id="updatePropertyTypeBtn" class = "updateBtn">Update</button>
                </td>
            </tr>`;

    document
      .getElementById("updatePropertyTypeBtn")
      .addEventListener("click", () => {
        UI.confirm(
          "Are you sure you want to Update Property Type Information?",
          () => {
            this.updatePropertyType(propertyType.type_id);
          }
        );
      });
  }

  async updatePropertyType(propertyTypeId) {
    const propertyTypeData = {
      type_id: propertyTypeId,
      type_name: document.getElementById("type_name").value,
      description: document.getElementById("type_description").value,
      status: document.getElementById("status").value,
      action_type: "update_all", // ✅ Indicates full update
    };

    try {
      const response = await fetch(
        "../backend/property_types/update_property_type.php",
        {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(propertyTypeData),
        }
      );
      const data = await response.json();

      if (data.success) {
        UI.alert(
          "Property Type Details has been updated successfully.",
          "Success"
        );
        document.getElementById("propertyTypeModal").style.display = "none";
        this.fetchPropertyTypes(this.currentPage);
      } else {
        UI.alert(
          "Failed to update Property Type Data: " + data.message,
          "Error"
        );
      }
    } catch (error) {
      console.error("Error updating Property Type Details:", error);
      UI.alert("Error updating Property Type Details", "Error");
    }
  }

  async deletePropertyType(propertyTypeId) {
    if (!confirm("Are you sure you want to delete this Property Type?")) return;

    try {
      const response = await fetch(
        "../backend/property_types/update_property_type.php",
        {
          // ✅ FIXED: Same endpoint
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            type_id: propertyTypeId,
            action_type: "delete", // ✅ No $ symbol
          }),
        }
      );
      const data = await response.json();

      if (data.success) {
        UI.alert("Property Type has been successfully deleted!", " Success");
        this.fetchPropertyTypes(this.currentPage);
      } else {
        UI.alert("Failed to delete Property Type: " + data.message, " Error");
      }
    } catch (error) {
      console.error("Error deleting Property Type:", error);
      UI.alert("Error deleting Property Type", " System Error");
    }
  }

  setupRestoreListeners() {
    const restoreIcons = document.querySelectorAll(".restore-icon");

    restoreIcons.forEach((icon) => {
      icon.addEventListener("click", async () => {
        const classType = icon.getAttribute("data-class-type");
        const classId = icon.getAttribute("data-class-id");

        if (classType !== "property_type") return;

        const confirmRestore = confirm(
          `Are you sure you want to restore this property type?`
        );

        if (confirmRestore) {
          try {
            const response = await fetch(
              "../backend/property_types/update_property_type.php",
              {
                // ✅ Same endpoint
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                  type_id: classId,
                  action_type: "restore",
                }),
              }
            );

            const data = await response.json();

            if (data.success) {
              UI.alert(
                data.message || "Property Type restored successfully!",
                " Success"
              );
              this.fetchPropertyTypes(this.currentPage);
            } else {
              UI.alert(
                data.message || "Failed to restore property type",
                " Error"
              );
            }
          } catch (error) {
            console.error("Restore failed:", error);
            UI.alert(
              "An error occurred while restoring the record.",
              " System Error"
            );
          }
        }
      });
    });
  }

  /** ------------------------- Search Filter ------------------------- **/
  setupSearchListener() {
    const searchInput = document.getElementById("propertyTypeLiveSearch");
    if (searchInput) {
      searchInput.addEventListener("input", () => this.filterTable());
    }
  }

  filterTable() {
    const searchTerm = document
      .getElementById("propertyTypeLiveSearch")
      .value.toLowerCase();
    const rows = document.querySelectorAll("#propertyTypeSummary tbody tr");

    rows.forEach((row) => {
      const cells = Array.from(row.getElementsByTagName("td"));
      const matchFound = cells.some((cell) =>
        cell.textContent.toLowerCase().includes(searchTerm)
      );
      row.style.display = matchFound ? "" : "none";
    });
  }
}

// Initialize when DOM is loaded
document.addEventListener("DOMContentLoaded", () => {
  new PropertyTypeManager();
  const addNewPropertyTypeBtn = document.getElementById(
    "addNewPropertyTypeBtn"
  );
  if (addNewPropertyTypeBtn) {
    addNewPropertyTypeBtn.addEventListener("click", () => {
      const modal = document.getElementById("addNewPropertyTypeModal");
      if (modal) {
        modal.style.display = "block";
      }
    });
  }
});
