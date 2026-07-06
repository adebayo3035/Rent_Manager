<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Property Settlement Management - Rent Pilot</title>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../ui.css">
    <link rel="stylesheet" href="../css/settlement.css">

    <!-- Font Awesome -->
    <script src="https://kit.fontawesome.com/7cab3097e7.js" crossorigin="anonymous"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>

<body>
    <!-- Include Navbar -->
    <?php include('navbar.php'); ?>

    <!-- Main Content -->
    <div class="settlement-container">
        <div class="settlement-header">
            <div>
                <h1>Property Settlement Management</h1>
                <p class="subtitle">Manage revenue sharing formulas for each property</p>
            </div>
            <button class="btn btn-outline" onclick="resetAllToDefault()">
                <i class="fas fa-undo"></i> Reset All to Default
            </button>
        </div>

        <!-- Alert Messages -->
        <div id="alertContainer"></div>

        <!-- Settlement Table -->
        <div class="table-wrapper">
            <div class="table-toolbar">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" placeholder="Search property..." onkeyup="filterTable()">
                </div>
                <div class="table-info">
                    <span id="rowCount">Loading...</span>
                </div>
            </div>

            <div class="table-responsive">
                <table id="settlementTable" class="settlement-table">
                    <thead>
                        <tr>
                            <th>Property</th>
                            <th>Client</th>
                            <th>Agent</th>
                            <th>Admin %</th>
                            <th>Agent %</th>
                            <th>Client %</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Last Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="settlementTableBody">
                        <tr>
                            <td colspan="9" class="loading-cell">
                                <div class="spinner"></div>
                                Loading properties...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Update Modal -->
        <div id="updateModal" class="modal" style="display:none;">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Update Settlement Formula</h3>
                    <button class="modal-close" onclick="closeModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="property-info" id="modalPropertyInfo"></div>

                    <form id="updateForm">
                        <input type="hidden" id="modalPropertyId">

                        <div class="form-group">
                            <label>Admin Percentage</label>
                            <input type="number" id="modalAdminPct" step="0.01" min="0" max="100" required>
                        </div>
                        <div class="form-group">
                            <label>Agent Percentage</label>
                            <input type="number" id="modalAgentPct" step="0.01" min="0" max="100" required>
                        </div>
                        <div class="form-group">
                            <label>Client Percentage</label>
                            <input type="number" id="modalClientPct" step="0.01" min="0" max="100" required>
                        </div>
                        <div class="form-group">
                            <label>Notes (Optional)</label>
                            <textarea id="modalNotes" rows="3"
                                placeholder="Add notes explaining why you're proposing this change..."
                                style="width:100%; padding:8px 12px; border:1px solid #e5e7eb; border-radius:6px; font-family:inherit; resize:vertical;"></textarea>
                        </div>

                        <div id="modalTotalDisplay" class="total-display">
                            Total: <span id="modalTotal">0</span>%
                            <span id="modalTotalStatus"></span>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button class="btn btn-primary" onclick="updateSettlement()">
                        <i class="fas fa-save"></i> Update
                    </button>
                </div>
            </div>
        </div>

        <!-- Reset Confirm Modal -->
        <div id="resetModal" class="modal" style="display:none;">
            <div class="modal-content modal-sm">
                <div class="modal-header">
                    <h3>Reset to Default</h3>
                    <button class="modal-close" onclick="closeResetModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to reset the settlement formula for <strong
                            id="resetPropertyName"></strong> to default (10%, 5%, 85%)?</p>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" onclick="closeResetModal()">Cancel</button>
                    <button class="btn btn-warning" onclick="confirmReset()">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                </div>
            </div>
        </div>
    </div>

     <!-- UI Framework Containers -->
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
    </div>
    <!-- Toast Container -->
    <div id="toastContainer"></div>

    <!-- JavaScript -->
      <script src="../scripts/main.js"></script>
    <script src="../../ui.js"></script>
    <script src="../../validator.js"></script>
    <script src="../scripts/settlement.js"></script>
</body>

</html>