// Report Module JavaScript - Debugged and Fixed Version
class QueryRunner {
    constructor() {
        console.log('QueryRunner: Initializing...');
        
        // DOM Elements - Add null checks
        this.sqlQuery = document.getElementById('sqlQuery');
        this.queryTitle = document.getElementById('queryTitle');
        this.runQueryBtn = document.getElementById('runQueryBtn');
        this.validateBtn = document.getElementById('validateBtn');
        this.clearBtn = document.getElementById('clearBtn');
        this.loading = document.getElementById('loading');
        this.resultsContainer = document.getElementById('resultsContainer');
        this.tableHeader = document.getElementById('tableHeader');
        this.tableBody = document.getElementById('tableBody');
        this.rowCount = document.getElementById('rowCount');
        this.errorAlert = document.getElementById('errorAlert');
        this.queryHistory = document.getElementById('queryHistory');
        this.historyList = document.getElementById('historyList');
        this.exportCsvBtn = document.getElementById('exportCsvBtn');
        this.exportExcelBtn = document.getElementById('exportExcelBtn');
        this.exportPdfBtn = document.getElementById('exportPdfBtn');
        this.printBtn = document.getElementById('printBtn');
        
        // Debug: Log which elements were found
        const elements = [
            { name: 'sqlQuery', element: this.sqlQuery },
            {name: 'queryTitle', element: this.queryTitle},
            { name: 'runQueryBtn', element: this.runQueryBtn },
            { name: 'validateBtn', element: this.validateBtn },
            { name: 'clearBtn', element: this.clearBtn },
            { name: 'loading', element: this.loading },
            { name: 'resultsContainer', element: this.resultsContainer },
            { name: 'tableHeader', element: this.tableHeader },
            { name: 'tableBody', element: this.tableBody },
            { name: 'rowCount', element: this.rowCount },
            { name: 'errorAlert', element: this.errorAlert },
            { name: 'exportCsvBtn', element: this.exportCsvBtn },
            { name: 'exportExcelBtn', element: this.exportExcelBtn },
            { name: 'exportPdfBtn', element: this.exportPdfBtn },
            { name: 'printBtn', element: this.printBtn }
        ];
        
        const missingElements = elements.filter(item => !item.element);
        if (missingElements.length > 0) {
            console.warn('QueryRunner: Missing elements:', missingElements.map(e => e.name));
        } else {
            console.log('QueryRunner: All elements found successfully');
        }
        
        // State
        this.currentResults = null;
        this.generated_by = null;
        
        // Initialize only if essential elements exist
        if (this.sqlQuery && this.runQueryBtn) {
            this.init();
        } else {
            console.error('QueryRunner: Essential elements missing. Cannot initialize.');
            if (this.errorAlert) {
                this.errorAlert.textContent = 'System initialization failed. Please refresh the page.';
                this.errorAlert.style.display = 'block';
            }
        }
    }
    
    init() {
        this.bindEvents();
        this.loadQueryHistory();
        this.setExampleQuery();
        console.log('QueryRunner: Initialized successfully');
    }
    
    bindEvents() {
        console.log('QueryRunner: Binding events...');
        
        // Button events with safety checks
        if (this.validateBtn) {
            this.validateBtn.addEventListener('click', () => this.validateSyntax());
            console.log('QueryRunner: validateBtn event bound');
        }
        
        if (this.runQueryBtn) {
            this.runQueryBtn.addEventListener('click', () => this.runQuery());
            console.log('QueryRunner: runQueryBtn event bound');
        }
        
        if (this.clearBtn) {
            this.clearBtn.addEventListener('click', () => this.clearQuery());
            console.log('QueryRunner: clearBtn event bound');
        }
        
        if (this.exportCsvBtn) {
            this.exportCsvBtn.addEventListener('click', () => this.exportToCSV());
            console.log('QueryRunner: exportCsvBtn event bound');
        }
        
        if (this.exportExcelBtn) {
            this.exportExcelBtn.addEventListener('click', () => this.exportToExcel());
            console.log('QueryRunner: exportExcelBtn event bound');
        }
        
        if (this.exportPdfBtn) {
            this.exportPdfBtn.addEventListener('click', () => this.exportToPDF());
            console.log('QueryRunner: exportPdfBtn event bound');
        }
        
        if (this.printBtn) {
            this.printBtn.addEventListener('click', () => this.printTable());
            console.log('QueryRunner: printBtn event bound');
        }
        
        // Keyboard shortcuts
        if (this.sqlQuery) {
            this.sqlQuery.addEventListener('keydown', (e) => {
                if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                    e.preventDefault();
                    this.runQuery();
                }
            });
        }
        
        console.log('QueryRunner: Events bound successfully');
    }
    
    // Validate SQL syntax
    validateSyntax() {
        const query = this.sqlQuery.value.trim().toLowerCase();
        const queryTitle = this.queryTitle.value.trim();

        if(!queryTitle){
          this.showErrorAlert("Please Enter a Title for your Report")
            this.showError('Please Enter a Title for your Report');
            return;
        }
        
        if (!query) {
            this.showError('Please enter a SQL query');
            return;
        }
        
        if (!query.startsWith('select')) {
            this.showError('Only SELECT queries are allowed');
            return;
        }
        
        const dangerous = ['drop', 'delete', 'truncate', 'insert', 'update', 'alter'];
        for (const keyword of dangerous) {
            if (query.includes(keyword)) {
                this.showError(`Query contains forbidden keyword: ${keyword}`);
                return;
            }
        }
        this.runQueryBtn.style.display = "block";
        this.showToast('✓ Syntax appears valid (proceed with caution)', 'info');
    }
    
    // Run SQL query
    async runQuery() {
        console.log('QueryRunner: runQuery called');
        
        const query = this.sqlQuery.value.trim();
        
        if (!query) {
            this.showError('Please enter a SQL query');
            return;
        }
        
        // Show loading
        if (this.loading) this.loading.style.display = 'block';
        if (this.resultsContainer) this.resultsContainer.style.display = 'none';
        if (this.errorAlert) this.errorAlert.style.display = 'none';
        if (this.runQueryBtn) this.runQueryBtn.disabled = true;
        
        try {
            console.log('QueryRunner: Sending request to server...');
            
            const response = await fetch('../backend/staffs/report.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    query: query,
                    format: 'json'
                })
            });
            
            const data = await response.json();
            console.log('QueryRunner: Server response:', data);
            
            if (data.success) {
                // Store results
                this.currentResults = data.data;
                this.generated_by = data.generated_by;
                console.log('QueryRunner: Results stored:', this.currentResults);
                
                // Display results
                this.displayResults(data.data, data.generated_by);
                
                // Save to history
                this.saveToHistory(query);
                
                this.showToast('Query executed successfully', 'success');
            } else {
                throw new Error(data.message || 'Unknown error');
            }
            
        } catch (error) {
            console.error('QueryRunner: Query error:', error);
            this.showError(`Query failed: ${error.message}`);
            this.currentResults = null;
        } finally {
            if (this.loading) this.loading.style.display = 'none';
            if (this.runQueryBtn) this.runQueryBtn.disabled = false;
        }
    }
    
    // Display results in table - FIXED VERSION
    displayResults(results, generated_by) {
        console.log('QueryRunner: displayResults called with:', results);
        
        // Check if results are valid
        if (!results || !results.columns || !results.data || !generated_by) {
            console.error('QueryRunner: Invalid results structure:', results);
            this.showError('Invalid data structure received from server');
            return;
        }
        
        // Update row count
        if (this.rowCount) {
            this.rowCount.textContent = `Results: ${results.row_count || results.data.length} rows`;
        }
        
        // Clear previous results
        if (this.tableHeader) this.tableHeader.innerHTML = '';
        if (this.tableBody) this.tableBody.innerHTML = '';
        
        // Create header row
        if (this.tableHeader) {
            const headerRow = document.createElement('tr');
            results.columns.forEach(column => {
                const th = document.createElement('th');
                th.textContent = column;
                th.title = column;
                th.style.padding = '8px';
                th.style.border = '1px solid #ddd';
                th.style.backgroundColor = '#f5f5f5';
                headerRow.appendChild(th);
            });
            this.tableHeader.appendChild(headerRow);
        }
        
        // Create data rows
        if (this.tableBody) {
            results.data.forEach(row => {
                const tr = document.createElement('tr');
                results.columns.forEach(column => {
                    const td = document.createElement('td');
                    let value = row[column];
                    
                    if (value === null || value === undefined) {
                        value = '';
                    } else if (typeof value === 'object') {
                        value = JSON.stringify(value);
                    }
                    
                    td.textContent = String(value);
                    td.title = String(value);
                    td.style.padding = '6px';
                    td.style.border = '1px solid #ddd';
                    tr.appendChild(td);
                });
                this.tableBody.appendChild(tr);
            });
        }
        
        // Store results with all necessary data
        this.currentResults = {
            columns: results.columns,
            data: results.data,
            row_count: results.row_count || results.data.length,
            generated_by : generated_by || "User Admin"
        };
        
        console.log('QueryRunner: Current results stored:', this.currentResults);
        
        // Show results container
        if (this.resultsContainer) {
            this.resultsContainer.style.display = 'block';
            this.resultsContainer.scrollIntoView({ behavior: 'smooth' });
        }
    }
    
    // Export to CSV - FIXED
    exportToCSV() {
        console.log('QueryRunner: exportToCSV called, currentResults:', this.currentResults);
        
        if (!this.currentResults || !this.currentResults.columns || !this.currentResults.data) {
            this.showError('No data to export. Please run a query first.');
            return;
        }
        
        const csv = this.convertToCSV(this.currentResults);
        this.downloadFile(csv, `query-results-${Date.now()}.csv`, 'text/csv');
        this.showToast('CSV exported successfully', 'success');
    }
    
    // Export to Excel - FIXED
    async exportToExcel() {
        console.log('QueryRunner: exportToExcel called');
        
        if (!this.currentResults || !this.currentResults.columns || !this.currentResults.data) {
            this.showError('No data to export. Please run a query first.');
            return;
        }
        
        try {
            const response = await fetch('../backend/utilities/generate_excel.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    data: this.currentResults,
                    query: this.sqlQuery.value.trim()
                })
            });
            
            if (!response.ok) {
                throw new Error(`Server returned ${response.status}: ${response.statusText}`);
            }
            
            const blob = await response.blob();
            this.downloadBlob(blob, `query-results-${Date.now()}.xlsx`);
            this.showToast('Excel exported successfully', 'success');
            
        } catch (error) {
            console.error('Excel export error:', error);
            this.showError('Excel export failed: ' + error.message);
            
            // Fallback to CSV
            this.showToast('Falling back to CSV export', 'info');
            setTimeout(() => this.exportToCSV(), 1000);
        }
    }
    
    // Export to PDF - FIXED
    exportToPDF() {
        console.log('QueryRunner: exportToPDF called');
        
        if (!this.currentResults || !this.currentResults.columns || !this.currentResults.data) {
            this.showError('No data to export. Please run a query first.');
            return;
        }
        
        try {
            this.showToast('Generating PDF...', 'info');
            
            // Check if jsPDF is loaded
            if (typeof jspdf === 'undefined') {
                throw new Error('PDF library not loaded');
            }
            
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF({
                orientation: 'landscape',
                unit: 'mm',
                format: 'a4'
            });
            
            // Add title
            const title = 'Query Report';
            doc.setFontSize(16);
            doc.setTextColor(40);
            doc.text(title, 14, 15);
            
            // Add metadata
            doc.setFontSize(10);
            doc.setTextColor(100);
            
            const metadata = [
                `Generated: ${new Date().toLocaleString()}`,
               
                `Generated By: ${this.currentResults.generated_by}`,
                `Total Rows: ${this.currentResults.row_count}`,
               
                `Title : ${this.queryTitle.value}`
            ];
            
            let yPos = 25;
            metadata.forEach(line => {
                if (line) {
                    doc.text(line, 14, yPos);
                    yPos += 5;
                }
            });
            
            yPos += 5;
            
            // Prepare table data
            const tableData = this.currentResults.data.map(row => {
                return this.currentResults.columns.map(col => {
                    let value = row[col];
                    if (value === null || value === undefined) return '';
                    if (typeof value === 'object') return JSON.stringify(value);
                    return String(value);
                });
            });
            
            // Add table using autoTable
            doc.autoTable({
                head: [this.currentResults.columns],
                body: tableData,
                startY: yPos,
                theme: 'grid',
                styles: {
                    fontSize: 7,
                    cellPadding: 1,
                    overflow: 'linebreak',
                    cellWidth: 'auto'
                },
                headStyles: {
                    fillColor: [41, 128, 185],
                    textColor: 255,
                    fontStyle: 'bold',
                    fontSize: 8
                },
                alternateRowStyles: {
                    fillColor: [245, 245, 245]
                },
                margin: { top: yPos },
                pageBreak: 'auto',
                tableWidth: 'wrap',
                didDrawPage: function(data) {
                    // Footer with page number
                    doc.setFontSize(8);
                    doc.setTextColor(150);
                    doc.text(
                        `Page ${data.pageNumber} of ${data.pageCount}`,
                        data.settings.margin.left,
                        doc.internal.pageSize.height - 10
                    );
                }
            });
            
            // Save PDF
            const filename = `query-report-${Date.now()}.pdf`;
            doc.save(filename);
            
            this.showToast('PDF downloaded successfully', 'success');
            
        } catch (error) {
            console.error('PDF generation error:', error);
            this.showError(`PDF generation failed: ${error.message}`);
            
            // Fallback: Open print dialog
            this.showToast('Falling back to print view', 'info');
            setTimeout(() => this.printTable(), 1000);
        }
    }
    
    // Print table - FIXED
    printTable() {
        console.log('QueryRunner: printTable called');
        
        if (!this.currentResults || !this.currentResults.columns || !this.currentResults.data) {
            this.showError('No data to print');
            return;
        }
        
        const printWindow = window.open('', '_blank');
        
        const html = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Query Report</title>
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
                <h1>Query Report</h1>
                <div class="meta">
                    <p><strong>Generated:</strong> ${new Date().toLocaleString()}</p>
                   
                    <p><strong>Generated By:</strong> ${this.generated_by}</p>
                    <p><strong>Total Rows:</strong> ${this.currentResults.row_count}</p>
                    
                    <p><strong>Title of Report:</strong> ${this.escapeHtml(this.truncateText(this.queryTitle.value.trim(), 120))}</p>
                </div>
                
                <table>
                    <thead>
                        <tr>
                            ${this.currentResults.columns.map(col => `<th>${this.escapeHtml(col)}</th>`).join('')}
                        </tr>
                    </thead>
                    <tbody>
                        ${this.currentResults.data.slice(0, 200).map(row => `
                            <tr>
                                ${this.currentResults.columns.map(col => {
                                    let value = row[col];
                                    if (value === null || value === undefined) value = '';
                                    if (typeof value === 'object') value = JSON.stringify(value);
                                    const strValue = String(value);
                                    return `<td>${this.escapeHtml(strValue.length > 50 ? 
                                        strValue.substring(0, 47) + '...' : strValue)}</td>`;
                                }).join('')}
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
                
                ${this.currentResults.row_count > 200 ? `
                <div style="margin-top: 15px; padding: 8px; background: #fff3cd; border: 1px solid #ffeaa7;">
                    <strong>Note:</strong> Showing first 200 rows of ${this.currentResults.row_count} total.
                </div>
                ` : ''}
                
                <div class="footer">
                    Generated by Property Management System • ${new Date().toLocaleString()}
                </div>
                
                <div class="print-actions" style="display: none;">
                    <button onclick="window.print()">🖨️ Print Now</button>
                    <button onclick="window.close()">✕ Close</button>
                </div>
            </div>
            
            <script>
                // Auto-print after 500ms
                setTimeout(() => {
                    window.print();
                }, 500);
                
                // Close window after print
                window.onafterprint = function() {
                    setTimeout(() => {
                        window.close();
                    }, 1000);
                };
            <\/script>
        </body>
        </html>
        `;
        
        printWindow.document.write(html);
        printWindow.document.close();
    }
    
    // Clear query and results
    clearQuery() {
        if (this.sqlQuery) this.sqlQuery.value = '';
        if (this.resultsContainer) this.resultsContainer.style.display = 'none';
        if (this.errorAlert) this.errorAlert.style.display = 'none';
        this.currentResults = null;
    }
    
    // Helper methods
    convertToCSV(results) {
        const { columns, data } = results;
        
        // Create CSV header
        let csv = columns.map(col => this.csvEscape(col)).join(',') + '\n';
        
        // Add data rows
        data.forEach(row => {
            const values = columns.map(col => {
                let value = row[col];
                
                if (value === null || value === undefined) {
                    return '';
                }
                
                return this.csvEscape(String(value));
            });
            
            csv += values.join(',') + '\n';
        });
        
        return csv;
    }
    
    csvEscape(value) {
        if (value === null || value === undefined) return '';
        
        const stringValue = String(value);
        
        // Escape quotes
        const escapedValue = stringValue.replace(/"/g, '""');
        
        // Wrap in quotes if contains comma, quotes, or newline
        if (stringValue.includes(',') || stringValue.includes('"') || stringValue.includes('\n') || stringValue.includes('\r')) {
            return `"${escapedValue}"`;
        }
        
        return escapedValue;
    }
    
    downloadFile(content, filename, mimeType) {
        const blob = new Blob([content], { type: mimeType });
        this.downloadBlob(blob, filename);
    }
    
    downloadBlob(blob, filename) {
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        a.style.display = 'none';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
    }
    
    showError(message) {
        console.error('QueryRunner Error:', message);
        if (this.errorAlert) {
            this.errorAlert.textContent = message;
            this.errorAlert.style.display = 'block';
            setTimeout(() => {
                if (this.errorAlert) this.errorAlert.style.display = 'none';
            }, 5000);
        } else {
            alert(message);
        }
    }

    showErrorAlert(message, type = "Error"){
        console.log(`QueryRunner Alert [${type}]:`, message)
         // Use your existing UI.toast or fallback to alert
        if (typeof UI !== 'undefined' && UI.alert) {
            UI.alert(message, type);
        } 
    }
    
    showToast(message, type = 'info') {
        console.log(`QueryRunner Toast [${type}]:`, message);
        // Use your existing UI.toast or fallback to alert
        if (typeof UI !== 'undefined' && UI.toast) {
            UI.toast(message, type);
        } else {
            // Simple toast implementation
            const toast = document.createElement('div');
            toast.textContent = message;
            toast.style.position = 'fixed';
            toast.style.top = '20px';
            toast.style.right = '20px';
            toast.style.padding = '12px 20px';
            toast.style.backgroundColor = type === 'success' ? '#28a745' : 
                                         type === 'error' ? '#dc3545' : '#007bff';
            toast.style.color = 'white';
            toast.style.borderRadius = '4px';
            toast.style.zIndex = '9999';
            toast.style.boxShadow = '0 2px 10px rgba(0,0,0,0.2)';
            document.body.appendChild(toast);
            
            setTimeout(() => {
                if (toast.parentNode) {
                    document.body.removeChild(toast);
                }
            }, 3000);
        }
    }
    
    getUserName() {
        // Get from PHP session (already embedded in HTML)
        return document.body.getAttribute('data-username') || 'Admin User';
    }
    
    truncateText(text, maxLength) {
        if (!text) return '';
        return text.length > maxLength ? text.substring(0, maxLength - 3) + '...' : text;
    }
    
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    saveToHistory(query) {
        let history = JSON.parse(localStorage.getItem('queryHistory') || '[]');
        // Remove if already exists
        history = history.filter(q => q !== query);
        // Add to beginning
        history.unshift(query);
        // Keep only last 10
        if (history.length > 10) {
            history = history.slice(0, 10);
        }
        localStorage.setItem('queryHistory', JSON.stringify(history));
        this.loadQueryHistory();
    }
    
    loadQueryHistory() {
        if (!this.historyList || !this.queryHistory) return;
        
        const history = JSON.parse(localStorage.getItem('queryHistory') || '[]');
        if (history.length > 0) {
            this.queryHistory.style.display = 'block';
            this.historyList.innerHTML = '';
            history.forEach(query => {
                const li = document.createElement('li');
                li.textContent = query.substring(0, 100) + (query.length > 100 ? '...' : '');
                li.title = query;
                li.style.cursor = 'pointer';
                li.style.padding = '5px';
                li.addEventListener('click', () => {
                    if (this.sqlQuery) this.sqlQuery.value = query;
                });
                this.historyList.appendChild(li);
            });
        } else {
            this.queryHistory.style.display = 'none';
        }
    }
    
    setExampleQuery() {
        if (this.sqlQuery && !localStorage.getItem('queryHistory')) {
            this.sqlQuery.value = "SELECT unique_id, firstname, lastname, email, role, status FROM admin_tbl WHERE status = '1' LIMIT 10";
        }
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, initializing QueryRunner...');
    
    try {
        // Create QueryRunner instance
        const queryRunner = new QueryRunner();
        
        // Make it available globally for debugging
        window.queryRunner = queryRunner;
        console.log('QueryRunner instance created:', queryRunner);
        
    } catch (error) {
        console.error('Failed to initialize QueryRunner:', error);
        alert('Failed to initialize SQL Query Runner. Please refresh the page.');
    }
});