// data-manager.js
class DataManager {
  constructor(config) {
    this.config = {
      // Required configurations
      tableId: "",
      tableBodyId: "",
      modalId: "",
      addModalId: "",
      formId: "",
      paginationId: "",
      searchInputId: "",

      // API endpoints
      fetchUrl: "",
      addUrl: "",
      updateUrl: "",
      fetchDetailsUrl: "",

      // Labels
      itemName: "Item",
      itemNamePlural: "Items",

      // Column configuration
      columns: [],
      statusField: "status",
      idField: "",
      nameField: "name",

      // User role
      userRole: "User",

      // Pagination
      limit: 10,

      // Callbacks for custom rendering
      renderRow: null,
      populateDetails: null,
      onInit: null,

      // Modal controls
      addButtonId: "",

      // Merge with provided config
      ...config,
    };

    this.currentPage = 1;
    this.init();
  }

  init() {
    this.initModalControls();
    this.setupAddForm();
    this.fetchData();
    this.setupSearchListener();

    // Custom initialization callback
    if (typeof this.config.onInit === "function") {
      this.config.onInit.call(this);
    }
  }

  /** ------------------------- Modal Controls ------------------------- **/
  initModalControls() {
    // Close buttons for modals
    const addModalClose = document.querySelector(
      `#${this.config.addModalId} .close`
    );
    if (addModalClose) {
      addModalClose.addEventListener("click", () => {
        document.getElementById(this.config.addModalId).style.display = "none";
      });
    }

    const viewModalClose = document.querySelector(
      `#${this.config.modalId} .close`
    );
    if (viewModalClose) {
      viewModalClose.addEventListener("click", () => {
        document.getElementById(this.config.modalId).style.display = "none";
        this.fetchData();
      });
    }

    // Add button
    const addBtn = document.getElementById(this.config.addButtonId);
    if (addBtn) {
      addBtn.addEventListener("click", () => {
        this.toggleModal(this.config.addModalId);
      });
    }

    // Close modal when clicking outside
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
  setupAddForm() {
    const form = document.getElementById(this.config.formId);
    if (!form) return;

    const messageDiv =
      form.querySelector(".message") || document.createElement("div");
    if (!form.querySelector(".message")) {
      form.appendChild(messageDiv);
    }

    form.addEventListener("submit", (event) => {
      event.preventDefault();
      UI.confirm(
        `Are you sure you want to add a new ${this.config.itemName}?`,
        async () => {
          try {
            const formData = new FormData(form);
            const response = await fetch(this.config.addUrl, {
              method: "POST",
              body: formData,
            });

            const data = await response.json();

            if (data.success) {
              this.showSuccessMessage(
                messageDiv,
                `New ${this.config.itemName} has been successfully added!`
              );
              UI.toast(
                `New ${this.config.itemName} added successfully!`,
                "success"
              );
              form.reset();
              this.fetchData();
            } else {
              UI.toast(
                `Failed to add ${this.config.itemName}: ${data.message}`,
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
  async fetchData(page = 1) {
    this.currentPage = page;
    const tableBody = document.getElementById(this.config.tableBodyId);
    this.showLoadingSpinner(tableBody);

    try {
      const response = await fetch(
        `${this.config.fetchUrl}?page=${page}&limit=${this.config.limit}`
      );
      const data = await response.json();

      if (data.success && data[this.config.itemNamePlural]?.length > 0) {
        const items = data[this.config.itemNamePlural];

        this.updateTable(items, data.user_role || this.config.userRole);
        this.updatePagination(
          data.pagination.total,
          data.pagination.page,
          data.pagination.limit
        );
        UI.toast(
          `${this.config.itemNamePlural} loaded successfully`,
          "success"
        );
      } else {
        UI.toast(`No ${this.config.itemNamePlural} Found`, "info");
        this.showNoDataMessage(tableBody);
      }
    } catch (error) {
      console.error("Error fetching data:", error);
      UI.toast(`Error loading ${this.config.itemNamePlural} data`, "error");
      this.showErrorMessage(
        tableBody,
        `Error loading ${this.config.itemNamePlural} data`
      );
    }
  }

  showLoadingSpinner(container) {
    if (!container) return;
    container.innerHTML = `
      <tr>
        <td colspan="${
          this.config.columns.length + 2
        }" style="text-align:center; padding: 20px;">
          <div class="spinner"
            style="border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; width: 30px; height: 30px; animation: spin 1s linear infinite; margin: auto;">
          </div>
        </td>
      </tr>`;
  }

  showNoDataMessage(container) {
    if (!container) return;
    container.innerHTML = `<tr><td colspan="${
      this.config.columns.length + 2
    }" style="text-align:center;">No ${
      this.config.itemNamePlural
    } Found</td></tr>`;
  }

  updateTable(items, userRole) {
    const tbody = document.querySelector(`#${this.config.tableId} tbody`);
    if (!tbody) return;

    tbody.innerHTML = "";

    items.forEach((item) => {
      const row = document.createElement("tr");

      // Use custom render function if provided
      if (typeof this.config.renderRow === "function") {
        row.innerHTML = this.config.renderRow(item, userRole, this);
        tbody.appendChild(row);
        return;
      }

      // Default rendering
      let rowHTML = "";

      // Add data columns
      this.config.columns.forEach((col) => {
        if (col.render) {
          rowHTML += `<td>${col.render(item)}</td>`;
        } else {
          rowHTML += `<td>${item[col.field] || ""}</td>`;
        }
      });

      // Add status column
      const status =
        item[this.config.statusField] === 1 ? "Active" : "Inactive";
      rowHTML += `<td>${status}</td>`;

      // Add action buttons
      if (item[this.config.statusField] === 1) {
        rowHTML += `<td><span class='edit-icon' data-id="${
          item[this.config.idField]
        }">&#9998;</span></td>`;
        if (userRole === "Super Admin") {
          rowHTML += `<td><span class='delete-icon' data-id="${
            item[this.config.idField]
          }">&#128465;</span></td>`;
        } else {
          rowHTML += `<td></td>`;
        }
      } else {
        if (userRole === "Super Admin") {
          rowHTML += `
            <td colspan="2" style="text-align:center;">
              <span class="restore-icon" data-id="${
                item[this.config.idField]
              }" title="Restore">&#9851;</span>
            </td>`;
        } else {
          rowHTML += `<td></td><td></td>`;
        }
      }

      row.innerHTML = rowHTML;
      tbody.appendChild(row);
    });

    this.setupActionListeners();
  }

  setupActionListeners() {
    document.querySelectorAll(".edit-icon").forEach((span) => {
      span.addEventListener("click", () =>
        this.fetchItemDetails(span.dataset.id)
      );
    });

    document.querySelectorAll(".delete-icon").forEach((span) => {
      span.addEventListener("click", () =>
        UI.confirm(
          `Are you sure you want to delete this ${this.config.itemName}?`,
          () => this.deleteItem(span.dataset.id)
        )
      );
    });

    document.querySelectorAll(".restore-icon").forEach((span) => {
      span.addEventListener("click", () => {
        UI.confirm(
          `Are you sure you want to restore this ${this.config.itemName}?`,
          () => this.restoreItem(span.dataset.id)
        );
      });
    });
  }

  /** ------------------------- Pagination ------------------------- **/
  updatePagination(totalItems, currentPage, itemsPerPage) {
    const paginationContainer = document.getElementById(
      this.config.paginationId
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
      btn.addEventListener("click", () => this.fetchData(i));
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
    btn.addEventListener("click", () => this.fetchData(page));
    container.appendChild(btn);
  }

  /** ------------------------- CRUD Operations ------------------------- **/
  async fetchItemDetails(itemId) {
    try {
      const id = this.config.idField;
      const response = await fetch(this.config.fetchDetailsUrl, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: itemId }),
      });
      const data = await response.json();

      if (data.success) {
        const detailsKey =
          this.config.detailsKey ||
          this.config.itemName.toLowerCase().replace(/\s+/g, "_") + "_details";

        const itemDetails = data[detailsKey];

        if (!itemDetails) {
          console.error("Expected details key not found. Full response:", data);
          throw new Error("Item details not found in API response");
        }

        if (typeof this.config.populateDetails === "function") {
          this.config.populateDetails.call(this, itemDetails);
        } else {
          this.populateDefaultDetails(itemDetails);
        }

        document.getElementById(this.config.modalId).style.display = "block";
      } else {
        console.error(
          `Failed to fetch ${this.config.itemName} details:`,
          data.message
        );
        alert(
          `Failed to fetch ${this.config.itemName} details: ${data.message}`
        );
      }
    } catch (error) {
      console.error(`Error fetching ${this.config.itemName} details:`, error);
      alert(`Error fetching ${this.config.itemName} details`);
    }
  }

  populateDefaultDetails(item) {
    // Override this in config or use custom populateDetails function
    console.warn("populateDefaultDetails should be overridden in config");
  }

  async updateItem(itemId, data = null) {
    const itemData = data || {
      [this.config.idField]: itemId,
      action_type: "update_all",
    };

    try {
      const response = await fetch(this.config.updateUrl, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(itemData),
      });
      const result = await response.json();

      if (result.success) {
        UI.alert(
          `${this.config.itemName} has been updated successfully.`,
          "Success"
        );
        document.getElementById(this.config.modalId).style.display = "none";
        this.fetchData(this.currentPage);
      } else {
        UI.alert(
          `Failed to update ${this.config.itemName}: ${result.message}`,
          "Error"
        );
      }
    } catch (error) {
      console.error(`Error updating ${this.config.itemName}:`, error);
      UI.alert(`Error updating ${this.config.itemName}`, "Error");
    }
  }

  async deleteItem(itemId) {
    try {
      const response = await fetch(this.config.updateUrl, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          [this.config.idField]: itemId,
          action_type: "delete",
        }),
      });
      const data = await response.json();

      if (data.success) {
        UI.alert(
          `${this.config.itemName} has been successfully deleted!`,
          "Success"
        );
        this.fetchData(this.currentPage);
      } else {
        UI.alert(
          `Failed to delete ${this.config.itemName}: ${data.message}`,
          "Error"
        );
      }
    } catch (error) {
      console.error(`Error deleting ${this.config.itemName}:`, error);
      UI.alert(`Error deleting ${this.config.itemName}`, "System Error");
    }
  }

  async restoreItem(itemId) {
    try {
      const response = await fetch(this.config.updateUrl, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          [this.config.idField]: itemId,
          action_type: "restore",
        }),
      });
      const data = await response.json();

      if (data.success) {
        UI.alert(
          `${this.config.itemName} has been successfully restored!`,
          "Success"
        );
        this.fetchData(this.currentPage);
      } else {
        UI.alert(
          `Failed to restore ${this.config.itemName}: ${data.message}`,
          "Error"
        );
      }
    } catch (error) {
      console.error(`Error restoring ${this.config.itemName}:`, error);
      UI.alert(`Error restoring ${this.config.itemName}`, "System Error");
    }
  }

  /** ------------------------- Search Filter ------------------------- **/
  setupSearchListener() {
    const searchInput = document.getElementById(this.config.searchInputId);
    if (searchInput) {
      searchInput.addEventListener("input", () => this.filterTable());
    }
  }

  filterTable() {
    const searchTerm = document
      .getElementById(this.config.searchInputId)
      .value.toLowerCase();
    const rows = document.querySelectorAll(`#${this.config.tableId} tbody tr`);

    rows.forEach((row) => {
      const cells = Array.from(row.getElementsByTagName("td"));
      const matchFound = cells.some((cell) =>
        cell.textContent.toLowerCase().includes(searchTerm)
      );
      row.style.display = matchFound ? "" : "none";
    });
  }
}

// Export for module usage
if (typeof module !== "undefined" && module.exports) {
  module.exports = DataManager;
}
