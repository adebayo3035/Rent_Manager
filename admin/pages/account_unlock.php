<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Unlock Management</title>
    <link rel="stylesheet" href="../css/account_unlock.css">
    <link rel="stylesheet" href="../../styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
</head>
<body>
    <?php include('navbar.php'); ?>

    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1><i class="fas fa-unlock-alt"></i> Account Unlock Management</h1>
            <p class="subtitle">Manage locked user accounts due to failed login attempts</p>
        </div>

        <!-- Stats Cards -->
        <div class="stats-container">
            <div class="stat-card border-red">
                <h3 id="totalLocked">0</h3>
                <p>Total Locked</p>
            </div>
            <div class="stat-card border-amber">
                <h3 id="loginLocks">0</h3>
                <p>Login Attempt Locks</p>
            </div>
            <div class="stat-card border-blue">
                <h3 id="manualLocks">0</h3>
                <p>Manual Locks</p>
            </div>
            <div class="stat-card border-green">
                <h3 id="unlockedToday">0</h3>
                <p>Unlocked Today</p>
            </div>
        </div>

        <!-- Sticky Toolbar -->
        <div class="toolbar">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Search by email or name…">
            </div>
            <select id="userTypeSelect" class="user-type-select">
                <option value="admin">Administrators</option>
                <option value="tenant">Tenants</option>
                <option value="agent">Agents</option>
                <option value="client">Clients</option>
            </select>
            <button id="refreshBtn" class="btn-icon" title="Refresh">
                <i class="fas fa-sync-alt"></i>
            </button>
            <button id="exportBtn" class="btn-icon" title="Export CSV">
                <i class="fas fa-download"></i>
            </button>
            <a href="lock_history.php" class="btn-icon btn-history" title="View Lock History">
                <i class="fas fa-history"></i>
            </a>
        </div>

        <!-- Accounts Table -->
        <div class="table-container">
            <div class="table-header">
                <h3><i class="fas fa-list"></i> Locked Accounts</h3>
                <div class="table-actions">
                    <div class="select-all">
                        <input type="checkbox" id="selectAll">
                        <label for="selectAll">Select All</label>
                    </div>
                    <span id="selectedCount">0 selected</span>
                </div>
            </div>

            <div class="table-responsive">
                <table class="accounts-table" id="accountsTable">
                    <thead>
                        <tr>
                            <th width="48px">
                                <input type="checkbox" id="selectAllHeader">
                            </th>
                            <th>User</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Lock Type</th>
                            <th>Locked Until</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="accountsTableBody">
                        <tr id="loadingRow">
                            <td colspan="7" class="loading-cell">
                                <div class="loading-spinner">
                                    <i class="fas fa-spinner fa-spin"></i>
                                    <span>Loading locked accounts…</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Empty State -->
            <div id="emptyState" class="empty-state" style="display: none;">
                <i class="fas fa-unlock"></i>
                <h3>No Locked Accounts</h3>
                <p>All user accounts are currently active and accessible.</p>
            </div>

            <!-- Pagination -->
            <div class="pagination" id="pagination"></div>
        </div>

        <!-- Single Unlock Modal -->
        <div id="unlockModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3><i class="fas fa-unlock-alt"></i> Unlock Account</h3>
                    <button class="close-modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="account-info">
                        <div class="info-row">
                            <span class="info-label">User ID:</span>
                            <span id="modalUserId" class="info-value"></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Name:</span>
                            <span id="modalUserName" class="info-value"></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Email:</span>
                            <span id="modalUserEmail" class="info-value"></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Lock Reason:</span>
                            <span id="modalLockReason" class="info-value"></span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="unlockReason">
                            <i class="fas fa-comment-alt"></i> Unlock Reason (Optional)
                        </label>
                        <textarea id="unlockReason" placeholder="Enter reason for unlocking this account..." rows="3"></textarea>
                    </div>

                    <div class="warning-box">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>This will immediately allow the user to log in again.</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary cancel-unlock">Cancel</button>
                    <button class="btn btn-success confirm-unlock">
                        <i class="fas fa-unlock-alt"></i> Confirm Unlock
                    </button>
                </div>
            </div>
        </div>

        <!-- Bulk Unlock Modal -->
        <div id="bulkUnlockModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3><i class="fas fa-unlock-alt"></i> Unlock Multiple Accounts</h3>
                    <button class="close-modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="selected-count">
                        <i class="fas fa-users"></i>
                        <span id="bulkUnlockCount">0</span> accounts selected for unlocking
                    </div>

                    <div class="selected-accounts-list" id="selectedAccountsList"></div>

                    <div class="form-group">
                        <label for="bulkUnlockReason">
                            <i class="fas fa-comment-alt"></i> Unlock Reason (Optional)
                        </label>
                        <textarea id="bulkUnlockReason" placeholder="Enter reason for unlocking these accounts..." rows="3"></textarea>
                    </div>

                    <div class="warning-box">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>This will immediately allow all selected users to log in again.</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary cancel-bulk-unlock">Cancel</button>
                    <button class="btn btn-success confirm-bulk-unlock">
                        <i class="fas fa-unlock-alt"></i> Unlock All Selected
                    </button>
                </div>
            </div>
        </div>

        <!-- Floating bulk action bar -->
        <div id="bulkActionBar" class="bulk-action-bar" hidden>
            <span><strong id="bulkBarCount">0</strong> selected</span>
            <button id="bulkUnlockBtn" class="btn btn-success">
                <i class="fas fa-unlock-alt"></i> Unlock Selected
            </button>
            <button id="clearSelectionBtn" class="btn btn-secondary">Clear</button>
        </div>
    </div>

    <!-- UI Library -->
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
        <div class="ui-loader-message">Processing your request...</div>
    </div>

    <!-- Scripts -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
    <script src="../scripts/account_unlock.js"></script>
    <script src="../../ui.js"></script>
</body>
</html>