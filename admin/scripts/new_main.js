// data-manager.js - UPDATED FOR FLEXIBILITY
class DataManager {
  constructor(config) {
    // Default configuration that works for ANY module
    const defaults = {
      // Required: Unique identifiers
      moduleName: 'default',
      
      // DOM Elements IDs (can be overridden)
      tableId: '',
      tableBodyId: '',
      modalId: '',
      addModalId: '',
      formId: '',
      paginationId: '',
      searchInputId: '',
      addButtonId: '',
      
      // API Endpoints (REQUIRED for each module)
      fetchUrl: '',
      addUrl: '',
      updateUrl: '',
      fetchDetailsUrl: '',
      
      // Business Logic
      itemName: 'Item',
      itemNamePlural: 'Items',
      idField: 'id',
      nameField: 'name',
      statusField: 'status',
      userRole: 'User',
      limit: 10,
      
      // Display Configuration (Flexible)
      tableStructure: {
        showStatus: true,
        showActions: true,
        actionColumns: 2, // Number of columns for actions
        customHeaders: [] // Override default headers
      },
      
      // Data Mapping (REQUIRED - different per module)
      dataMapper: (item) => item, // Default: return as-is
      
      // Custom Functions (optional overrides)
      renderRow: null,     // Override COMPLETE row rendering
      renderCell: null,    // Custom cell rendering per column
      populateDetails: null,
      onInit: null,
      onFetchSuccess: null,
      
      // Column Definitions (Flexible)
      columns: [], // Define columns with: {field, label, render, width, className}
      
      // Pagination
      enablePagination: true,
      
      // Search
      enableSearch: true,
      searchFields: [] // Fields to search in
    };
    
    this.config = { ...defaults, ...config };
    this.currentPage = 1;
    
    // Validate required config
    this.validateConfig();
    
    // Auto-initialize
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', () => this.init());
    } else {
      this.init();
    }
  }

  validateConfig() {
    const required = ['fetchUrl', 'tableId', 'tableBodyId', 'moduleName'];
    const missing = required.filter(field => !this.config[field]);
    
    if (missing.length > 0) {
      console.error(`DataManager Error: Missing required config fields: ${missing.join(', ')}`);
      throw new Error(`Missing required configuration for ${this.config.moduleName}`);
    }
  }

  init() {
    console.log(`Initializing DataManager for ${this.config.moduleName}`);
    
    this.initModalControls();
    this.setupAddForm();
    this.fetchData();
    if (this.config.enableSearch) this.setupSearchListener();
    
    if (typeof this.config.onInit === 'function') {
      this.config.onInit.call(this);
    }
  }

  /** ------------------------- Modal Controls ------------------------- **/
  initModalControls() {
    // Close buttons for modals
    const addModalClose = document.querySelector(`#${this.config.addModalId} .close`);
    if (addModalClose) {
      addModalClose.addEventListener("click", () => {
        document.getElementById(this.config.addModalId).style.display = "none";
      });
    }

    const viewModalClose = document.querySelector(`#${this.config.modalId} .close`);
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
      modal.style.display = modal.style.display === "none" || modal.style.display === "" ? "block" : "none";
    }
  }

  /** ------------------------- Form Submission ------------------------- **/
  setupAddForm() {
    const form = document.getElementById(this.config.formId);
    if (!form) return;

    const messageDiv = form.querySelector(".message") || document.createElement("div");
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
              UI.toast(`New ${this.config.itemName} added successfully!`, "success");
              form.reset();
              this.fetchData();
            } else {
              UI.toast(`Failed to add ${this.config.itemName}: ${data.message}`, "danger");
              this.showErrorMessage(messageDiv, data.message);
            }
          } catch (error) {
            console.error("Error:", error);
            UI.toast("An error occurred. Please try again later.", "danger");
            this.showErrorMessage(messageDiv, "An error occurred. Please try again later.");
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

  /** ------------------------- Table Rendering (Flexible) ------------------------- **/
  async fetchData(page = 1) {
    this.currentPage = page;
    const tableBody = document.getElementById(this.config.tableBodyId);
    if (!tableBody) {
      console.error(`Table body not found: ${this.config.tableBodyId}`);
      return;
    }
    
    this.showLoadingSpinner(tableBody);

    try {
      const response = await fetch(
        `${this.config.fetchUrl}?page=${page}&limit=${this.config.limit}`
      );
      
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      
      const data = await response.json();
      console.log(`${this.config.moduleName} API Response:`, data);
      
      if (data.success) {
        // Find items in response (flexible key detection)
        const items = this.findItemsInResponse(data);
        
        if (items && items.length > 0) {
          const userRole = data.user_role || this.config.userRole;
          this.updateTable(items, userRole);
          
          if (this.config.enablePagination && data.pagination) {
            this.updatePagination(
              data.pagination.total,
              data.pagination.page || page,
              data.pagination.limit || this.config.limit
            );
          }
          
          if (typeof this.config.onFetchSuccess === 'function') {
            this.config.onFetchSuccess.call(this, items, data);
          }
          
          UI.toast(`${this.config.itemNamePlural} loaded successfully`, "success");
        } else {
          this.showNoDataMessage(tableBody);
          UI.toast(`No ${this.config.itemNamePlural} found`, "info");
        }
      } else {
        throw new Error(data.message || 'API request failed');
      }
    } catch (error) {
      console.error(`Error fetching ${this.config.itemNamePlural}:`, error);
      this.showErrorMessage(tableBody, `Error: ${error.message}`);
      UI.toast(`Failed to load ${this.config.itemNamePlural}`, "error");
    }
  }

  showLoadingSpinner(container) {
    if (!container) return;
    container.innerHTML = `
      <tr>
        <td colspan="${this.config.columns.length + 2}" style="text-align:center; padding: 20px;">
          <div class="spinner"
            style="border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; width: 30px; height: 30px; animation: spin 1s linear infinite; margin: auto;">
          </div>
        </td>
      </tr>`;
  }

  showNoDataMessage(container) {
    if (!container) return;
    container.innerHTML = `<tr><td colspan="${this.config.columns.length + 2}" style="text-align:center;">No ${this.config.itemNamePlural} Found</td></tr>`;
  }

  findItemsInResponse(data) {
    // Try common keys
    const possibleKeys = [
      this.config.itemNamePlural.toLowerCase(),
      this.config.itemNamePlural.toLowerCase().replace(/\s+/g, '_'),
      'data',
      'items',
      'results',
      'list'
    ];
    
    for (const key of possibleKeys) {
      if (data[key] && Array.isArray(data[key])) {
        // Apply data mapper if provided
        return typeof this.config.dataMapper === 'function' 
          ? data[key].map(item => this.config.dataMapper(item))
          : data[key];
      }
    }
    
    // If data itself is an array
    if (Array.isArray(data)) {
      return typeof this.config.dataMapper === 'function' 
        ? data.map(item => this.config.dataMapper(item))
        : data;
    }
    
    return null;
  }

  updateTable(items, userRole) {
    const tbody = document.querySelector(`#${this.config.tableId} tbody`);
    if (!tbody) {
      console.error(`Table not found: #${this.config.tableId}`);
      return;
    }
    
    tbody.innerHTML = '';
    
    // Get table headers to determine column count
    const table = document.getElementById(this.config.tableId);
    const headerCount = table ? table.querySelector('thead tr').cells.length : 0;
    
    items.forEach((item, index) => {
      const row = document.createElement('tr');
      
      // Use custom renderRow if provided
      if (typeof this.config.renderRow === 'function') {
        row.innerHTML = this.config.renderRow(item, userRole, this);
      } else {
        // Dynamic rendering based on columns config
        row.innerHTML = this.renderDynamicRow(item, userRole, headerCount);
      }
      
      tbody.appendChild(row);
    });
    
    this.setupActionListeners();
  }

  renderDynamicRow(item, userRole, headerCount) {
    let rowHTML = '';
    
    // 1. Render data columns
    if (this.config.columns && this.config.columns.length > 0) {
      this.config.columns.forEach(col => {
        if (col.render) {
          rowHTML += `<td class="${col.className || ''}">${col.render(item)}</td>`;
        } else {
          rowHTML += `<td class="${col.className || ''}">${item[col.field] || ''}</td>`;
        }
      });
    } else {
      // Fallback: render all object properties except id and status
      Object.keys(item).forEach(key => {
        if (!['id', 'status', 'action'].includes(key)) {
          rowHTML += `<td>${item[key] || ''}</td>`;
        }
      });
    }
    
    // 2. Render status column if enabled
    if (this.config.tableStructure.showStatus && item[this.config.statusField] !== undefined) {
      const status = parseInt(item[this.config.statusField]) === 1 ? 
        '<span class="status-active">Active</span>' : 
        '<span class="status-inactive">Inactive</span>';
      rowHTML += `<td class="status-cell">${status}</td>`;
    }
    
    // 3. Render action columns
    if (this.config.tableStructure.showActions) {
      rowHTML += this.renderActionCells(item, userRole);
    }
    
    return rowHTML;
  }

  renderActionCells(item, userRole) {
    const isActive = parseInt(item[this.config.statusField]) === 1;
    const actionCols = this.config.tableStructure.actionColumns || 2;
    
    if (isActive) {
      // Active item - show edit + delete
      if (actionCols === 2) {
        return `
          <td><span class="action-btn edit-btn" data-id="${item[this.config.idField]}" title="Edit">✏️</span></td>
          <td>${userRole === 'Super Admin' ? 
            `<span class="action-btn delete-btn" data-id="${item[this.config.idField]}" title="Delete">🗑️</span>` : 
            ''}</td>`;
      } else {
        // Single action column
        return `<td colspan="${actionCols}">
          <span class="action-btn edit-btn" data-id="${item[this.config.idField]}" title="Edit">✏️</span>
          ${userRole === 'Super Admin' ? 
            `<span class="action-btn delete-btn" data-id="${item[this.config.idField]}" title="Delete">🗑️</span>` : 
            ''}
        </td>`;
      }
    } else {
      // Inactive item - show restore for Super Admin
      if (userRole === 'Super Admin') {
        return `<td colspan="${actionCols}">
          <span class="action-btn restore-btn" data-id="${item[this.config.idField]}" title="Restore">↻ Restore</span>
        </td>`;
      } else {
        return `<td></td>`.repeat(actionCols);
      }
    }
  }

  setupActionListeners() {
    // Edit buttons
    document.querySelectorAll('.edit-btn').forEach(btn => {
      btn.addEventListener('click', () => this.fetchItemDetails(btn.dataset.id));
    });
    
    // Delete buttons
    document.querySelectorAll('.delete-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        UI.confirm(`Delete this ${this.config.itemName}?`, () => 
          this.deleteItem(btn.dataset.id)
        );
      });
    });
    
    // Restore buttons
    document.querySelectorAll('.restore-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        UI.confirm(`Restore this ${this.config.itemName}?`, () => 
          this.restoreItem(btn.dataset.id)
        );
      });
    });
  }

  // ... [Keep other methods like initModalControls, setupAddForm, etc.] ...

  /** ------------------------- Pagination ------------------------- **/
  updatePagination(totalItems, currentPage, itemsPerPage) {
    const paginationContainer = document.getElementById(this.config.paginationId);
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
      const response = await fetch(this.config.fetchDetailsUrl, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: itemId }),
      });
      const data = await response.json();

      if (data.success) {
        const itemKey = this.config.itemName.toLowerCase().replace(/\s+/g, '_');
        const itemDetails = data[itemKey + '_details'] || data.details;
        
        if (typeof this.config.populateDetails === 'function') {
          this.config.populateDetails.call(this, itemDetails);
        } else {
          this.populateDetails(itemDetails);
        }
        
        document.getElementById(this.config.modalId).style.display = "block";
      } else {
        console.error(`Failed to fetch ${this.config.itemName} details:`, data.message);
        alert(`Failed to fetch ${this.config.itemName} details: ${data.message}`);
      }
    } catch (error) {
      console.error(`Error fetching ${this.config.itemName} details:`, error);
      alert(`Error fetching ${this.config.itemName} details`);
    }
  }

  populateDetails(item) {
    // Override this in config or use custom populateDetails function
    console.warn('populateDefaultDetails should be overridden in config');
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
        UI.alert(`${this.config.itemName} has been successfully deleted!`, "Success");
        this.fetchData(this.currentPage);
      } else {
        UI.alert(`Failed to delete ${this.config.itemName}: ${data.message}`, "Error");
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
        UI.alert(`${this.config.itemName} has been successfully restored!`, "Success");
        this.fetchData(this.currentPage);
      } else {
        UI.alert(`Failed to restore ${this.config.itemName}: ${data.message}`, "Error");
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
    const searchTerm = document.getElementById(this.config.searchInputId).value.toLowerCase();
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
if (typeof module !== 'undefined' && module.exports) {
  module.exports = DataManager;
}