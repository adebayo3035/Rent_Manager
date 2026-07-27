// report.js - Complete SQL Query Runner with Full Features

class QueryRunner {
  constructor() {
    console.log("QueryRunner: Initializing...");

    // DOM Elements
    this.sqlQuery = document.getElementById("sqlQuery");
    this.queryTitle = document.getElementById("queryTitle");
    this.runQueryBtn = document.getElementById("runQueryBtn");
    this.validateBtn = document.getElementById("validateBtn");
    this.clearBtn = document.getElementById("clearBtn");
    this.loading = document.getElementById("loading");
    this.resultsContainer = document.getElementById("resultsContainer");
    this.tableHeader = document.getElementById("tableHeader");
    this.tableBody = document.getElementById("tableBody");
    this.rowCount = document.getElementById("rowCount");
    this.errorAlert = document.getElementById("errorAlert");
    this.queryHistory = document.getElementById("queryHistory");
    this.historyList = document.getElementById("historyList");
    this.exportCsvBtn = document.getElementById("exportCsvBtn");
    this.exportExcelBtn = document.getElementById("exportExcelBtn");
    this.exportPdfBtn = document.getElementById("exportPdfBtn");
    this.printBtn = document.getElementById("printBtn");

    // State
    this.currentResults = null;
    this.generated_by = null;
    this.csrfToken = null;
    this.csrfTokenName = 'generic_reporting_form';
    this.csrfTokenEndpoint = "../backend/utilities/get_token.php";
    this.isQueryRunning = false;
    this.isQueryValidated = false;
    this.userId = null;
    this.userProfileFetched = false; // ✅ Added missing property

    // Check essential elements
    if (this.sqlQuery && this.runQueryBtn) {
      this.init();
      this.fetchCsrfToken();
      this.fetchUserProfile(); // ✅ Call this here
    } else {
      console.error("QueryRunner: Essential elements missing.");
      if (this.errorAlert) {
        this.errorAlert.textContent = "System initialization failed. Please refresh the page.";
        this.errorAlert.style.display = "block";
      }
    }
  }

  // ==================== FETCH USER PROFILE ====================
  async fetchUserProfile() {
    // Check if user ID is already cached
    const cachedUserId = sessionStorage.getItem('user_id');
    if (cachedUserId) {
      this.userId = cachedUserId;
      this.userProfileFetched = true;
      console.log('User ID from cache:', this.userId);
      this.loadQueryHistory(); // ✅ Reload history with user ID
      return;
    }

    try {
      console.log('Fetching user profile...');
      const response = await fetch('../backend/staffs/staff_profile.php', {
        method: 'GET',
        credentials: 'include',
        headers: { 'Accept': 'application/json' }
      });
      
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }
      
      const data = await response.json();
      
      if (data.success && data.data) {
        this.userId = data.data.unique_id;
        // Cache the user ID
        sessionStorage.setItem('user_id', this.userId);
        this.userProfileFetched = true;
        console.log('User profile fetched successfully. User ID:', this.userId);
        this.loadQueryHistory(); // ✅ Reload history with user ID
      } else {
        console.warn('Failed to fetch user profile:', data.message);
        this.userId = 'guest';
        this.userProfileFetched = true;
        this.loadQueryHistory();
      }
    } catch (error) {
      console.warn('Error fetching user profile:', error);
      this.userId = 'guest';
      this.userProfileFetched = true;
      this.loadQueryHistory();
    }
  }

  // ==================== GET STORAGE KEY ====================
  getStorageKey() {
    // Use user ID if available, otherwise fallback to 'guest'
    const userId = this.userId || 'guest';
    return `queryHistory_${userId}`;
  }

  init() {
    this.bindEvents();
    // ✅ Don't load history here - it will be loaded after user profile is fetched
    // this.loadQueryHistory(); // REMOVED - will be called after user profile fetch
    this.setExampleQuery();
    console.log("QueryRunner: Initialized successfully");
  }

  bindEvents() {
    console.log("QueryRunner: Binding events...");

    // Button events
    if (this.validateBtn) {
      this.validateBtn.addEventListener("click", () => this.validateQuery());
    }

    if (this.runQueryBtn) {
      this.runQueryBtn.addEventListener("click", () => this.runQuery());
    }

    if (this.clearBtn) {
      this.clearBtn.addEventListener("click", () => this.clearQuery());
    }

    if (this.exportCsvBtn) {
      this.exportCsvBtn.addEventListener("click", () => this.exportToCSV());
    }

    if (this.exportExcelBtn) {
      this.exportExcelBtn.addEventListener("click", () => this.exportToExcel());
    }

    if (this.exportPdfBtn) {
      this.exportPdfBtn.addEventListener("click", () => this.exportToPDF());
    }

    if (this.printBtn) {
      this.printBtn.addEventListener("click", () => this.printTable());
    }

    // Keyboard shortcuts
    if (this.sqlQuery) {
      this.sqlQuery.addEventListener("keydown", (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key === "Enter") {
          e.preventDefault();
          if (this.isQueryValidated) {
            this.runQuery();
          } else {
            this.showToast("Please validate the query first", "warning");
          }
        }
      });
    }

    // Run button initial state
    this.runQueryBtn.disabled = true;
    this.runQueryBtn.title = "Validate query first before running";
  }

  // ==================== CSRF TOKEN ====================
  async fetchCsrfToken() {
    try {
      console.log('Fetching CSRF token...');
      const response = await fetch(
        `${this.csrfTokenEndpoint}?form=${this.csrfTokenName}`,
        {
          method: 'GET',
          credentials: 'include',
          headers: { 'Content-Type': 'application/json' }
        }
      );
      
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }
      
      const data = await response.json();
      
      if (data.success) {
        this.csrfToken = data.token;
        console.log('CSRF token fetched successfully');
      } else {
        console.error('Failed to fetch CSRF token:', data.message);
        this.showError('Failed to get security token. Please refresh the page.');
      }
    } catch (error) {
      console.error('Error fetching CSRF token:', error);
      this.showError('Security token error. Please try again.');
      
      setTimeout(() => {
        if (!this.csrfToken) {
          console.log('Retrying CSRF token fetch...');
          this.fetchCsrfToken();
        }
      }, 3000);
    }
  }

  // ==================== VALIDATE SYNTAX ====================
  validateQuery() {
    const query = this.sqlQuery.value.trim();
    const title = this.queryTitle.value.trim();

    if (!title) {
      this.showError("Please enter a title for your report");
      return;
    }

    if (!query) {
      this.showError("Please enter a SQL query");
      return;
    }

    const queryUpper = query.toUpperCase().trim();
    if (!queryUpper.startsWith("SELECT")) {
      this.showError("Only SELECT queries are allowed");
      this.runQueryBtn.disabled = true;
      this.isQueryValidated = false;
      return;
    }

    // Client-side dangerous patterns
    const dangerousPatterns = [
      /\bDROP\b/i,
      /\bDELETE\b/i,
      /\bTRUNCATE\b/i,
      /\bINSERT\b/i,
      /\bUPDATE\b/i,
      /\bALTER\b/i,
      /\bCREATE\b/i,
      /\bUNION\b/i,
      /\bINTO\s+(OUTFILE|DUMPFILE)/i,
      /\bLOAD\s+DATA\b/i,
      /\bINFORMATION_SCHEMA\b/i,
      /\bSLEEP\b/i,
      /\bBENCHMARK\b/i,
      /\bSELECT\s+\*/i,
    ];

    for (const pattern of dangerousPatterns) {
      if (pattern.test(query)) {
        this.showError(`Query contains disallowed SQL pattern`);
        this.runQueryBtn.disabled = true;
        this.isQueryValidated = false;
        return;
      }
    }

    // Basic validation passed - backend will do the heavy lifting
    this.runQueryBtn.disabled = false;
    this.runQueryBtn.style.display = "block";
    this.isQueryValidated = true;
    this.runQueryBtn.title = "Run Query";
    this.showToast("✓ Query validated. Click 'Run Query' to execute.", "success");
  }

  // ==================== RUN QUERY ====================
  async runQuery() {
    if (this.isQueryRunning) {
      this.showToast("A query is already running. Please wait.", "warning");
      return;
    }

    if (!this.isQueryValidated) {
      this.showError("Please validate the query first");
      return;
    }

    const query = this.sqlQuery.value.trim();
    const title = this.queryTitle.value.trim();

    if (!query) {
      this.showError("Please enter a SQL query");
      return;
    }

    if (!this.csrfToken) {
      this.showError("Loading security token...");
      await this.fetchCsrfToken();
      if (!this.csrfToken) {
        this.showError("Unable to obtain security token. Please refresh the page.");
        return;
      }
    }

    // Disable UI
    this.isQueryRunning = true;
    this.runQueryBtn.disabled = true;
    this.runQueryBtn.textContent = "Running...";
    this.validateBtn.disabled = true;
    if (this.loading) this.loading.style.display = "block";
    if (this.resultsContainer) this.resultsContainer.style.display = "none";
    if (this.errorAlert) this.errorAlert.style.display = "none";

    try {
      const response = await fetch("../backend/staffs/report.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          query: query,
          query_title: title,
          csrf_token: this.csrfToken,
          token_id: this.csrfTokenName,
          format: "json",
        }),
      });

      const data = await response.json();

      if (data.success) {
        this.currentResults = data.data;
        this.generated_by = data.generated_by || "Admin";
        this.displayResults(data.data);
        this.saveToHistory(query);
        this.showToast(`Query executed successfully (${data.data.row_count || 0} rows)`, "success");
        
        // Disable run button after success (must validate again)
        this.isQueryValidated = false;
        this.runQueryBtn.disabled = true;
        this.runQueryBtn.textContent = "Run Query";
        this.runQueryBtn.title = "Validate query first before running";
        this.validateBtn.disabled = false;
      } else {
        if (data.message && data.message.includes('Security token')) {
          await this.fetchCsrfToken();
          this.showError('Security token expired. Please validate and try again.');
          this.isQueryValidated = false;
          this.runQueryBtn.disabled = true;
        } else {
          throw new Error(data.message || "Unknown error");
        }
      }
    } catch (error) {
      console.error("Query error:", error);
      this.showError(`Query failed: ${error.message}`);
      this.currentResults = null;
      this.validateBtn.disabled = false;
    } finally {
      this.isQueryRunning = false;
      if (this.loading) this.loading.style.display = "none";
    }
  }

  // ==================== DISPLAY RESULTS ====================
  displayResults(results) {
    console.log("QueryRunner: displayResults called with:", results);

    if (!results || !results.columns || !results.data) {
      console.error("QueryRunner: Invalid results structure:", results);
      this.showError("Invalid data structure received from server");
      return;
    }

    // Update row count
    if (this.rowCount) {
      const rowMsg = `Results: ${results.row_count || results.data.length} rows`;
      const timeMsg = results.execution_time ? ` (${results.execution_time}s)` : "";
      this.rowCount.textContent = rowMsg + timeMsg;
    }

    // Clear previous results
    if (this.tableHeader) this.tableHeader.innerHTML = "";
    if (this.tableBody) this.tableBody.innerHTML = "";

    // Create header row
    if (this.tableHeader) {
      const headerRow = document.createElement("tr");
      results.columns.forEach((column) => {
        const th = document.createElement("th");
        th.textContent = column;
        th.title = column;
        th.style.padding = "8px";
        th.style.border = "1px solid #ddd";
        th.style.backgroundColor = "#f5f5f5";
        headerRow.appendChild(th);
      });
      this.tableHeader.appendChild(headerRow);
    }

    // Create data rows
    if (this.tableBody) {
      const maxDisplayRows = 500;
      const displayData = results.data.slice(0, maxDisplayRows);

      displayData.forEach((row) => {
        const tr = document.createElement("tr");
        results.columns.forEach((column) => {
          const td = document.createElement("td");
          let value = row[column];

          if (value === null || value === undefined) {
            value = "";
          } else if (typeof value === "object") {
            value = JSON.stringify(value);
          }

          td.textContent = String(value);
          td.title = String(value);
          td.style.padding = "6px";
          td.style.border = "1px solid #ddd";
          tr.appendChild(td);
        });
        this.tableBody.appendChild(tr);
      });

      // Show warning if truncated
      if (results.data.length > maxDisplayRows) {
        const tr = document.createElement("tr");
        const td = document.createElement("td");
        td.colSpan = results.columns.length;
        td.textContent = `⚠️ Showing first ${maxDisplayRows} of ${results.data.length} rows. Export full data via CSV/Excel.`;
        td.style.padding = "10px";
        td.style.backgroundColor = "#fff3cd";
        td.style.textAlign = "center";
        tr.appendChild(td);
        this.tableBody.appendChild(tr);
      }
    }

    // Store results with all necessary data
    this.currentResults = {
      columns: results.columns,
      data: results.data,
      row_count: results.row_count || results.data.length,
      generated_by: results.generated_by || "User Admin",
    };

    console.log("QueryRunner: Current results stored:", this.currentResults);

    // Show results container
    if (this.resultsContainer) {
      this.resultsContainer.style.display = "block";
      this.resultsContainer.scrollIntoView({ behavior: "smooth" });
    }
  }

  // ==================== EXPORT FUNCTIONS ====================
  exportToCSV() {
    console.log("QueryRunner: exportToCSV called");

    if (!this.currentResults || !this.currentResults.columns || !this.currentResults.data) {
      this.showError("No data to export. Please run a query first.");
      return;
    }

    const csv = this.convertToCSV(this.currentResults);
    this.downloadFile(csv, `query-results-${Date.now()}.csv`, "text/csv");
    this.showToast("CSV exported successfully", "success");
  }

  async exportToExcel() {
    console.log("QueryRunner: exportToExcel called");

    if (!this.currentResults || !this.currentResults.columns || !this.currentResults.data) {
      this.showError("No data to export. Please run a query first.");
      return;
    }

    try {
      const response = await fetch("../backend/utilities/generate_excel.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          data: this.currentResults,
          query_title: this.queryTitle.value.trim(),
        }),
      });

      if (!response.ok) {
        throw new Error(`Server returned ${response.status}: ${response.statusText}`);
      }

      const blob = await response.blob();
      this.downloadBlob(blob, `query-results-${Date.now()}.xlsx`);
      this.showToast("Excel exported successfully", "success");
    } catch (error) {
      console.error("Excel export error:", error);
      this.showError("Excel export failed: " + error.message);

      // Fallback to CSV
      this.showToast("Falling back to CSV export", "info");
      setTimeout(() => this.exportToCSV(), 1000);
    }
  }

  exportToPDF() {
    console.log("QueryRunner: exportToPDF called");

    if (!this.currentResults || !this.currentResults.columns || !this.currentResults.data) {
      this.showError("No data to export. Please run a query first.");
      return;
    }

    try {
      this.showToast("Generating PDF...", "info");

      if (typeof window.jspdf === "undefined") {
        throw new Error("PDF library not loaded");
      }

      const { jsPDF } = window.jspdf;
      const doc = new jsPDF({
        orientation: "landscape",
        unit: "mm",
        format: "a4",
      });

      // Add title
      const title = this.queryTitle.value.trim() || "Query Report";
      doc.setFontSize(16);
      doc.setTextColor(40);
      doc.text(title, 14, 15);

      // Add metadata
      doc.setFontSize(10);
      doc.setTextColor(100);

      const metadata = [
        `Generated: ${new Date().toLocaleString()}`,
        `Generated By: ${this.currentResults.generated_by || "Admin"}`,
        `Total Rows: ${this.currentResults.row_count}`,
        `Title: ${title}`,
      ];

      let yPos = 25;
      metadata.forEach((line) => {
        if (line) {
          doc.text(line, 14, yPos);
          yPos += 5;
        }
      });

      yPos += 5;

      // Prepare table data
      const tableData = this.currentResults.data.map((row) => {
        return this.currentResults.columns.map((col) => {
          let value = row[col];
          if (value === null || value === undefined) return "";
          if (typeof value === "object") return JSON.stringify(value);
          return String(value);
        });
      });

      // Add table using autoTable
      doc.autoTable({
        head: [this.currentResults.columns],
        body: tableData.slice(0, 500),
        startY: yPos,
        theme: "grid",
        styles: {
          fontSize: 7,
          cellPadding: 1,
          overflow: "linebreak",
          cellWidth: "auto",
        },
        headStyles: {
          fillColor: [41, 128, 185],
          textColor: 255,
          fontStyle: "bold",
          fontSize: 8,
        },
        alternateRowStyles: {
          fillColor: [245, 245, 245],
        },
        margin: { top: yPos },
        pageBreak: "auto",
        tableWidth: "wrap",
        didDrawPage: function (data) {
          doc.setFontSize(8);
          doc.setTextColor(150);
          doc.text(
            `Page ${data.pageNumber} of ${data.pageCount}`,
            data.settings.margin.left,
            doc.internal.pageSize.height - 10,
          );
        },
      });

      // Save PDF
      const filename = `query-report-${Date.now()}.pdf`;
      doc.save(filename);

      this.showToast("PDF downloaded successfully", "success");
    } catch (error) {
      console.error("PDF generation error:", error);
      this.showError(`PDF generation failed: ${error.message}`);

      // Fallback: Open print dialog
      this.showToast("Falling back to print view", "info");
      setTimeout(() => this.printTable(), 1000);
    }
  }

  // ==================== PRINT TABLE ====================
  printTable() {
    console.log("QueryRunner: printTable called");

    if (!this.currentResults || !this.currentResults.columns || !this.currentResults.data) {
      this.showError("No data to print");
      return;
    }

    const printWindow = window.open("", "_blank");

    const html = this.generatePrintHTML();
    printWindow.document.write(html);
    printWindow.document.close();
  }

  generatePrintHTML() {
    const columns = this.currentResults.columns || [];
    const data = this.currentResults.data || [];
    const title = this.queryTitle.value.trim() || "Query Report";
    const generatedBy = this.currentResults.generated_by || "Admin";

    return `
      <!DOCTYPE html>
      <html>
      <head>
        <title>${this.escapeHtml(title)}</title>
        <style>
          @media print {
            body { font-family: Arial, sans-serif; margin: 15mm; }
            h1 { color: #333; text-align: center; margin-bottom: 10px; }
            .meta { margin-bottom: 15px; color: #666; font-size: 12px; }
            table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 10px; }
            th { background-color: #f5f5f5; padding: 6px; text-align: left; 
                 border: 1px solid #ddd; font-weight: bold; }
            td { padding: 5px; border: 1px solid #ddd; }
            tr:nth-child(even) { background-color: #f9f9f9; }
            .footer { margin-top: 20px; text-align: center; color: #888; 
                      font-size: 10px; border-top: 1px solid #eee; padding-top: 8px; }
            @page { margin: 15mm; size: A4 landscape; }
          }
          @media screen {
            body { padding: 20px; background: #f5f5f5; }
            .print-container { max-width: 1000px; margin: 0 auto; background: white; padding: 20px; }
            .print-actions { margin: 20px 0; text-align: center; }
            .print-actions button { margin: 0 10px; padding: 8px 16px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
          }
        </style>
      </head>
      <body>
        <div class="print-container">
          <h1>${this.escapeHtml(title)}</h1>
          <div class="meta">
            <p><strong>Generated:</strong> ${new Date().toLocaleString()}</p>
            <p><strong>Generated By:</strong> ${this.escapeHtml(generatedBy)}</p>
            <p><strong>Total Rows:</strong> ${data.length}</p>
          </div>
          
          <table>
            <thead>
              <tr>
                ${columns.map((col) => `<th>${this.escapeHtml(col)}</th>`).join("")}
              </tr>
            </thead>
            <tbody>
              ${data.slice(0, 200).map((row) => `
                <tr>
                  ${columns.map((col) => {
                    let value = row[col];
                    if (value === null || value === undefined) value = "";
                    if (typeof value === "object") value = JSON.stringify(value);
                    const strValue = String(value);
                    return `<td>${this.escapeHtml(
                      strValue.length > 50 ? strValue.substring(0, 47) + "..." : strValue
                    )}</td>`;
                  }).join("")}
                </tr>
              `).join("")}
            </tbody>
          </table>
          
          ${data.length > 200 ? `
            <div style="margin-top: 15px; padding: 8px; background: #fff3cd; border: 1px solid #ffeaa7;">
              <strong>Note:</strong> Showing first 200 rows of ${data.length} total.
            </div>
          ` : ""}
          
          <div class="footer">
            Generated by Property Management System • ${new Date().toLocaleString()}
          </div>
          
          <div class="print-actions" style="display: none;">
            <button onclick="window.print()">🖨️ Print Now</button>
            <button onclick="window.close()">✕ Close</button>
          </div>
        </div>
        
        <script>
          setTimeout(() => {
            window.print();
          }, 500);
          
          window.onafterprint = function() {
            setTimeout(() => {
              window.close();
            }, 1000);
          };
        <\/script>
      </body>
      </html>
    `;
  }

  // ==================== CLEAR QUERY ====================
  clearQuery() {
    if (this.sqlQuery) this.sqlQuery.value = "";
    if (this.resultsContainer) this.resultsContainer.style.display = "none";
    if (this.errorAlert) this.errorAlert.style.display = "none";
    this.currentResults = null;
    this.isQueryValidated = false;
    this.runQueryBtn.disabled = true;
    this.runQueryBtn.title = "Validate query first before running";
  }

  // ==================== UTILITY FUNCTIONS ====================
  convertToCSV(results) {
    const { columns, data } = results;

    let csv = columns.map((col) => this.csvEscape(col)).join(",") + "\n";

    data.forEach((row) => {
      const values = columns.map((col) => {
        let value = row[col];
        if (value === null || value === undefined) {
          return "";
        }
        return this.csvEscape(String(value));
      });
      csv += values.join(",") + "\n";
    });

    return csv;
  }

  csvEscape(value) {
    if (value === null || value === undefined) return "";
    const stringValue = String(value);

    if (
      stringValue.includes(",") ||
      stringValue.includes('"') ||
      stringValue.includes("\n") ||
      stringValue.includes("\r")
    ) {
      return `"${stringValue.replace(/"/g, '""')}"`;
    }

    return stringValue;
  }

  downloadFile(content, filename, mimeType) {
    const blob = new Blob([content], { type: mimeType });
    this.downloadBlob(blob, filename);
  }

  downloadBlob(blob, filename) {
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = filename;
    a.style.display = "none";
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
  }

  showError(message) {
    console.error("QueryRunner Error:", message);
    if (this.errorAlert) {
      this.errorAlert.textContent = message;
      this.errorAlert.style.display = "block";
      setTimeout(() => {
        if (this.errorAlert) this.errorAlert.style.display = "none";
      }, 8000);
    } else {
      alert(message);
    }
  }

  showToast(message, type = "info") {
    console.log(`QueryRunner Toast [${type}]:`, message);
    
    if (typeof UI !== "undefined" && UI.toast) {
      UI.toast(message, type);
    } else {
      const toast = document.createElement("div");
      toast.textContent = message;
      toast.style.position = "fixed";
      toast.style.bottom = "20px";
      toast.style.right = "20px";
      toast.style.padding = "12px 20px";
      toast.style.backgroundColor =
        type === "success" ? "#28a745" :
        type === "error" ? "#dc3545" :
        type === "warning" ? "#ffc107" : "#007bff";
      toast.style.color = type === "warning" ? "#000" : "#fff";
      toast.style.borderRadius = "4px";
      toast.style.zIndex = "9999";
      toast.style.boxShadow = "0 2px 10px rgba(0,0,0,0.2)";
      document.body.appendChild(toast);

      setTimeout(() => {
        if (toast.parentNode) {
          document.body.removeChild(toast);
        }
      }, 3000);
    }
  }

  escapeHtml(text) {
    if (!text) return "";
    const div = document.createElement("div");
    div.textContent = text;
    return div.innerHTML;
  }

  // ==================== QUERY HISTORY (USER-SPECIFIC) ====================
  saveToHistory(query) {
    const storageKey = this.getStorageKey();
    let history = JSON.parse(localStorage.getItem(storageKey) || "[]");
    // Remove duplicate if exists
    history = history.filter((q) => q !== query);
    // Add to beginning
    history.unshift(query);
    // Keep only last 10
    if (history.length > 10) {
      history = history.slice(0, 10);
    }
    localStorage.setItem(storageKey, JSON.stringify(history));
    this.loadQueryHistory();
  }

  loadQueryHistory() {
    if (!this.historyList || !this.queryHistory) return;

    const storageKey = this.getStorageKey();
    const history = JSON.parse(localStorage.getItem(storageKey) || "[]");
    
    if (history.length > 0) {
      this.queryHistory.style.display = "block";
      this.historyList.innerHTML = "";
      history.forEach((query) => {
        const li = document.createElement("li");
        li.textContent = query.substring(0, 100) + (query.length > 100 ? "..." : "");
        li.title = query;
        li.style.cursor = "pointer";
        li.style.padding = "5px";
        li.style.borderBottom = "1px solid #eee";
        li.addEventListener("click", () => {
          if (this.sqlQuery) {
            this.sqlQuery.value = query;
            this.isQueryValidated = false;
            this.runQueryBtn.disabled = true;
            this.runQueryBtn.title = "Validate query first before running";
          }
        });
        this.historyList.appendChild(li);
      });
    } else {
      this.queryHistory.style.display = "none";
    }
  }

  setExampleQuery() {
    // Only set example if no history exists for this user
    const storageKey = this.getStorageKey();
    const history = JSON.parse(localStorage.getItem(storageKey) || "[]");
    
    if (this.sqlQuery && history.length === 0) {
      this.sqlQuery.value =
        "SELECT unique_id, firstname, lastname, email, role, status FROM admin_tbl WHERE status = '1' LIMIT 10";
    }
  }
}

// Initialize when DOM is loaded
document.addEventListener("DOMContentLoaded", function () {
  console.log("DOM loaded, initializing QueryRunner...");

  try {
    const queryRunner = new QueryRunner();
    window.queryRunner = queryRunner;
    console.log("QueryRunner instance created:", queryRunner);
  } catch (error) {
    console.error("Failed to initialize QueryRunner:", error);
    alert("Failed to initialize SQL Query Runner. Please refresh the page.");
  }
});