<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documents | Admin</title>
    <link rel="stylesheet" href="../../styles.css">
    <link rel="stylesheet" href="../css/documents.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include('navbar.php'); ?>

    <div class="documents-container">
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-left">
                <h1><i class="fas fa-folder-open"></i> Documents</h1>
                <p>Manage documents across all tenants</p>
            </div>
            <div class="page-header-actions">
                <button class="btn-outline-primary" onclick="Documents.refresh()">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
        </div>

        <!-- Sector Tabs -->
        <div class="sector-tabs" id="sectorTabs">
            <button class="sector-tab active" data-sector="">
                <i class="fas fa-th-large"></i> All Documents
            </button>
            <!-- populated by JS -->
        </div>

        <!-- Summary Cards -->
        <div class="summary-cards" id="summaryCards">
            <div class="summary-card total">
                <div class="card-top">
                    <div class="card-icon"><i class="fas fa-folder"></i></div>
                    <span class="card-sub">All time</span>
                </div>
                <div class="card-label">Total</div>
                <div class="card-amount" id="summaryTotal">0</div>
            </div>
            <div class="summary-card invoices">
                <div class="card-top">
                    <div class="card-icon"><i class="fas fa-file-invoice-dollar"></i></div>
                    <span class="card-sub">Invoices</span>
                </div>
                <div class="card-label">Invoices</div>
                <div class="card-amount" id="summaryInvoices">0</div>
            </div>
            <div class="summary-card legal">
                <div class="card-top">
                    <div class="card-icon"><i class="fas fa-gavel"></i></div>
                    <span class="card-sub">Legal</span>
                </div>
                <div class="card-label">Lease Docs</div>
                <div class="card-amount" id="summaryLeases">0</div>
            </div>
            <div class="summary-card personal">
                <div class="card-top">
                    <div class="card-icon"><i class="fas fa-id-card"></i></div>
                    <span class="card-sub">Personal</span>
                </div>
                <div class="card-label">Identifications</div>
                <div class="card-amount" id="summaryIds">0</div>
            </div>
            <div class="summary-card recent">
                <div class="card-top">
                    <div class="card-icon"><i class="fas fa-clock"></i></div>
                    <span class="card-sub">Last 7 days</span>
                </div>
                <div class="card-label">Recent</div>
                <div class="card-amount" id="summaryRecent">0</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-bar">
            <div class="filter-group search-group">
                <label><i class="fas fa-search"></i> Search</label>
                <input type="text" id="filterSearch" placeholder="Search by tenant, document name, filename...">
            </div>
            <div class="filter-group">
                <label>Document Type</label>
                <select id="filterType">
                    <option value="">All Types</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Tenant</label>
                <select id="filterTenant">
                    <option value="">All Tenants</option>
                </select>
            </div>
            <div class="filter-group">
                <label>From</label>
                <input type="date" id="filterDateFrom">
            </div>
            <div class="filter-group">
                <label>To</label>
                <input type="date" id="filterDateTo">
            </div>
            <div class="filter-actions">
                <button class="btn-clear" onclick="Documents.clearFilters()">
                    <i class="fas fa-times"></i> Clear
                </button>
            </div>
        </div>

        <!-- Documents Table -->
        <div class="table-card">
            <div class="table-responsive">
                <table class="documents-table">
                    <thead>
                        <tr>
                            <th>Document</th>
                            <th>Sector</th>
                            <th>Tenant</th>
                            <th>Property</th>
                            <th>Uploaded By</th>
                            <th>Size</th>
                            <th>Date</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="documentsTableBody">
                        <tr><td colspan="8" class="loading-cell"><div class="spinner"></div></td></tr>
                    </tbody>
                </table>
            </div>
            <div class="pagination" id="documentsPagination"></div>
        </div>
    </div>

    <!-- Details Modal -->
    <div class="modal" id="documentDetailsModal">
        <div class="modal-content" style="max-width: 640px;">
            <div class="modal-header">
                <h3><i class="fas fa-file-alt"></i> Document Details</h3>
                <button class="modal-close" onclick="Documents.closeDetails()">&times;</button>
            </div>
            <div class="modal-body" id="documentDetailsBody"></div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="Documents.closeDetails()">Close</button>
                <button class="btn-primary" id="downloadBtn" onclick="Documents.downloadCurrent()">
                    <i class="fas fa-download"></i> Download
                </button>
            </div>
        </div>
    </div>

    <!-- Delete Confirm Modal -->
    <div class="modal" id="deleteConfirmModal">
        <div class="modal-content" style="max-width: 460px;">
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle" style="color:#ef4444;"></i> Delete Document</h3>
                <button class="modal-close" onclick="Documents.closeDeleteModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p style="color:#4b5563; line-height:1.6;">
                    Are you sure you want to delete this document? This action cannot be undone.
                </p>
                <p id="deleteDocName" style="font-weight:600; color:#0a0a1a; margin-top:12px;"></p>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="Documents.closeDeleteModal()">Cancel</button>
                <button class="btn-danger" id="deleteConfirmBtn" onclick="Documents.confirmDelete()">
                    <i class="fas fa-trash"></i> Yes, Delete
                </button>
            </div>
        </div>
    </div>

    <!-- Preview Modal (for PDFs / images) -->
    <div class="modal" id="previewModal">
        <div class="modal-content" style="max-width: 900px; height: 90vh;">
            <div class="modal-header">
                <h3><i class="fas fa-eye"></i> Preview</h3>
                <button class="modal-close" onclick="Documents.closePreview()">&times;</button>
            </div>
            <div class="modal-body" id="previewBody" style="padding:0; background:#1a1f36; overflow:hidden; flex:1;">
                <!-- populated by JS -->
            </div>
        </div>
    </div>

    <!-- UI Library (already in your navbar or included) -->
    <div id="toastContainer"></div>

    <div id="alertModal" class="ui-modal">
        <div class="ui-modal-content">
            <h3 id="alertTitle">Alert</h3>
            <p id="alertMessage"></p>
            <button id="alertOkBtn">OK</button>
        </div>
    </div>

    <div id="confirmModal" class="ui-modal">
        <div class="ui-modal-content">
            <h3 id="confirmTitle">Confirm Action</h3>
            <p id="confirmMessage"></p>
            <div class="ui-modal-buttons">
                <button id="confirmCancelBtn">Cancel</button>
                <button id="confirmOkBtn">Yes</button>
            </div>
        </div>
    </div>


    <div id="uiLoaderOverlay">
        <div class="ui-loader"></div>
        <div class="ui-loader-message">Processing...</div>
    </div>

    <script src="../../ui.js"></script>
    <script src="../scripts/documents.js"></script>
</body>
</html>