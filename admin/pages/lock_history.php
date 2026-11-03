<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Lock History</title>
    <link rel="stylesheet" href="../css/account_unlock.css">
    <link rel="stylesheet" href="../../styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
</head>
<body>
    <?php include('navbar.php'); ?>

    <div class="container">
        <div class="page-header">
            <a href="account_unlock.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Locked Accounts
            </a>
            <h1><i class="fas fa-history"></i> Account Lock History</h1>
            <p class="subtitle">Complete audit trail of locks, unlocks, and manual interventions</p>
        </div>

        <div class="history-stats">
            <div class="stat-card border-blue">
                <h3 id="historyTotalLocks">0</h3>
                <p>Total Locks</p>
            </div>
            <div class="stat-card border-amber">
                <h3 id="historyLoginAttempts">0</h3>
                <p>Login Attempts</p>
            </div>
            <div class="stat-card border-purple">
                <h3 id="historyManualLocks">0</h3>
                <p>Manual Locks</p>
            </div>
            <div class="stat-card border-green">
                <h3 id="historyUnlocked">0</h3>
                <p>Unlocked</p>
            </div>
            <div class="stat-card border-red">
                <h3 id="historyStillLocked">0</h3>
                <p>Still Locked</p>
            </div>
        </div>

        <div class="filter-card">
            <button class="filter-toggle" id="filterToggle" type="button">
                <i class="fas fa-filter"></i> Filters
            </button>
            <div class="filter-body" id="filterBody">
                <select id="historyUserTypeFilter" class="filter-select">
                    <option value="all">All User Types</option>
                    <option value="admin">Administrators</option>
                    <option value="tenant">Tenants</option>
                    <option value="agent">Agents</option>
                    <option value="client">Clients</option>
                </select>

                <select id="historyActionTypeFilter" class="filter-select">
                    <option value="all">All Actions</option>
                    <option value="login_attempts">Failed Logins</option>
                    <option value="manual_lock">Manual Locks</option>
                </select>

                <select id="historyStatusFilter" class="filter-select">
                    <option value="all">All Status</option>
                    <option value="locked">Locked</option>
                    <option value="unlocked">Unlocked</option>
                </select>

                <input type="text" id="historySearchInput" class="filter-input" placeholder="Search name or email…">
                <input type="date" id="historyDateFrom" class="filter-input" title="From date">
                <input type="date" id="historyDateTo" class="filter-input" title="To date">

                <div class="filter-actions">
                    <button id="applyHistoryFilters" class="btn btn-primary btn-sm">
                        <i class="fas fa-filter"></i> Apply
                    </button>
                    <button id="resetHistoryFilters" class="btn btn-secondary btn-sm">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                </div>
            </div>
        </div>

        <div class="table-container">
            <div class="table-responsive">
                <table class="history-table history-table--summary">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Lock Type</th>
                            <th>Status</th>
                            <th width="40px"></th>
                        </tr>
                    </thead>
                    <tbody id="lockHistoryBody">
                        <tr id="historyLoadingRow">
                            <td colspan="6" class="loading-cell">
                                <div class="loading-spinner">
                                    <i class="fas fa-spinner fa-spin"></i>
                                    <span>Loading history…</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div id="historyEmptyState" class="empty-state" style="display: none;">
                <i class="fas fa-history"></i>
                <h3>No Lock History Found</h3>
                <p>No records match your current filters.</p>
            </div>

            <div id="lockHistoryPagination" class="pagination"></div>
        </div>
    </div>

    <!-- ===== Record Detail Modal ===== -->
    <div id="recordDetailModal" class="modal">
        <div class="modal-content detail-modal">
            <div class="modal-header">
                <h3>
                    <i class="fas fa-file-alt"></i>
                    Record Details
                </h3>
                <button class="close-modal" type="button">&times;</button>
            </div>

            <div class="modal-body">
                <div class="detail-header">
                    <div class="detail-avatar">
                        <i class="fas fa-user" id="detailAvatarIcon"></i>
                    </div>
                    <div class="detail-identity">
                        <h4 id="detailName">—</h4>
                        <p id="detailEmail">—</p>
                        <div class="detail-badges">
                            <span class="status-badge" id="detailStatusBadge">—</span>
                            <span class="lock-type-badge" id="detailLockTypeBadge">—</span>
                        </div>
                    </div>
                </div>

                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-label">User ID</span>
                        <span class="detail-value" id="detailUserId">—</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Role</span>
                        <span class="detail-value" id="detailRole">—</span>
                    </div>
                    <div class="detail-item detail-item--full">
                        <span class="detail-label">Lock Reason</span>
                        <span class="detail-value" id="detailLockReason">—</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Locked At</span>
                        <span class="detail-value" id="detailLockedAt">—</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Unlocked At</span>
                        <span class="detail-value" id="detailUnlockedAt">—</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Unlocked By</span>
                        <span class="detail-value" id="detailUnlockedBy">—</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Unlock Method</span>
                        <span class="detail-value" id="detailUnlockMethod">—</span>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button class="btn btn-secondary close-modal" type="button">Close</button>
            </div>
        </div>
    </div>

    <div id="toastContainer"></div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
    <script src="../scripts/lock_history.js"></script>
    <script src="../../ui.js"></script>
</body>
</html>