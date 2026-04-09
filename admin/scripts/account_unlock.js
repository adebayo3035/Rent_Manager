class AccountUnlockManager {
    constructor() {
        this.currentPage = 1;
        this.limit = 20;
        this.searchTerm = '';
        this.selectedAccounts = new Set();
        this.currentUserType = 'admin'; // admin, tenant, agent, client
        this.apiUrl = '../backend/utilities/unlock_account.php';
        this.init();
    }

    init() {
        this.loadAccounts();
        this.setupEventListeners();
        this.setupModals();
        this.setupUserTypeSelector();
        this.setupToasts();
    }

    setupUserTypeSelector() {
        // Create user type filter dropdown
        const controlsDiv = document.querySelector('.controls');
        if (controlsDiv) {
            const userTypeHtml = `
                <div class="user-type-filter">
                    <label><i class="fas fa-users"></i> User Type:</label>
                    <select id="userTypeSelect" class="user-type-select">
                        <option value="admin">Administrators</option>
                        <option value="tenant">Tenants</option>
                        <option value="agent">Agents</option>
                        <option value="client">Clients</option>
                    </select>
                </div>
            `;
            
            const searchBox = document.querySelector('.search-box');
            if (searchBox) {
                searchBox.insertAdjacentHTML('afterend', userTypeHtml);
            }
        }
        
        const userTypeSelect = document.getElementById('userTypeSelect');
        if (userTypeSelect) {
            userTypeSelect.addEventListener('change', (e) => {
                this.currentUserType = e.target.value;
                this.currentPage = 1;
                this.selectedAccounts.clear();
                this.loadAccounts();
            });
        }
    }

    async loadAccounts() {
        const loadingRow = document.getElementById('loadingRow');
        const emptyState = document.getElementById('emptyState');
        const tableBody = document.getElementById('accountsTableBody');
        
        if (loadingRow) loadingRow.style.display = '';
        if (emptyState) emptyState.style.display = 'none';
        
        // Clear existing rows except loading row
        if (tableBody) {
            Array.from(tableBody.children).forEach(row => {
                if (row.id !== 'loadingRow') {
                    row.remove();
                }
            });
        }

        try {
            const params = new URLSearchParams({
                page: this.currentPage,
                limit: this.limit,
                user_type: this.currentUserType
            });

            if (this.searchTerm) {
                params.append('search', this.searchTerm);
            }

            const response = await fetch(`${this.apiUrl}?${params}`);
            const data = await response.json();

            if (data.success) {
                this.renderAccounts(data.accounts);
                this.updatePagination(data.pagination);
                this.updateStats(data.accounts);
                this.updateSelectedCount();
                
                if (data.accounts.length === 0) {
                    if (loadingRow) loadingRow.style.display = 'none';
                    if (emptyState) emptyState.style.display = 'block';
                } else {
                    if (loadingRow) loadingRow.style.display = 'none';
                }
            } else {
                this.showError('Failed to load accounts: ' + (data.message || 'Unknown error'));
                if (loadingRow) loadingRow.style.display = 'none';
                if (emptyState) emptyState.style.display = 'block';
            }
        } catch (error) {
            console.error('Error loading accounts:', error);
            this.showError('Failed to load accounts');
            if (loadingRow) loadingRow.style.display = 'none';
            if (emptyState) emptyState.style.display = 'block';
        }
    }

    renderAccounts(accounts) {
        const tableBody = document.getElementById('accountsTableBody');
        if (!tableBody) return;
        
        accounts.forEach(account => {
            const row = document.createElement('tr');
            row.dataset.accountId = account.user_id;
            
            const lockTypeText = account.lock_type === 'login_attempts' ? 'Failed Logins' : 'Manual Lock';
            const lockTypeClass = account.lock_type === 'login_attempts' ? 'lock-type-login' : 'lock-type-manual';
            const statusClass = 'status-locked';
            
            // Get user type icon
            const userIcon = this.getUserTypeIcon(account.user_type);
            
            row.innerHTML = `
                <td>
                    <input type="checkbox" class="account-checkbox" 
                           data-id="${account.user_id}"
                           data-user-type="${account.user_type}">
                </td>
                <td><i class="fas ${userIcon}"></i> ${this.escapeHtml(account.user_id)}</td>
                <td><strong>${this.escapeHtml(account.name)}</strong></td>
                <td>${this.escapeHtml(account.email)}</td>
                <td>${account.role}</td>
                <td><span class="lock-type-badge ${lockTypeClass}">${lockTypeText}</span></td>
                <td>${this.escapeHtml(account.lock_reason)}</td>
                <td>${account.attempts || 'N/A'}</td>
                <td>${this.formatDateTime(account.locked_until) || 'N/A'}</td>
                <td><span class="status-badge ${statusClass}">LOCKED</span></td>
                <td>
                    <button class="btn-unlock" 
                            data-id="${account.user_id}"
                            data-user-type="${account.user_type}"
                            data-email="${this.escapeHtml(account.email)}" 
                            data-name="${this.escapeHtml(account.name)}"
                            data-reason="${this.escapeHtml(account.lock_reason || '')}">
                        <i class="fas fa-unlock-alt"></i> Unlock
                    </button>
                </td>
            `;
            
            tableBody.appendChild(row);
        });

        this.setupRowEventListeners();
    }

    getUserTypeIcon(userType) {
        const icons = {
            'admin': 'fa-user-shield',
            'tenant': 'fa-user',
            'agent': 'fa-user-tie',
            'client': 'fa-briefcase'
        };
        return icons[userType] || 'fa-user';
    }

    updatePagination(pagination) {
        const paginationContainer = document.getElementById('pagination');
        if (!paginationContainer) return;
        
        if (pagination.total_pages <= 1) {
            paginationContainer.innerHTML = '';
            return;
        }

        let buttons = '';
        
        // Previous button
        if (this.currentPage > 1) {
            buttons += `
                <button class="page-btn" data-page="${this.currentPage - 1}">
                    <i class="fas fa-chevron-left"></i>
                </button>
            `;
        }

        // Page numbers
        const maxVisible = 5;
        let startPage = Math.max(1, this.currentPage - Math.floor(maxVisible / 2));
        let endPage = Math.min(pagination.total_pages, startPage + maxVisible - 1);
        
        if (endPage - startPage + 1 < maxVisible) {
            startPage = Math.max(1, endPage - maxVisible + 1);
        }

        for (let i = startPage; i <= endPage; i++) {
            buttons += `
                <button class="page-btn ${i === this.currentPage ? 'active' : ''}" 
                        data-page="${i}">
                    ${i}
                </button>
            `;
        }

        // Next button
        if (this.currentPage < pagination.total_pages) {
            buttons += `
                <button class="page-btn" data-page="${this.currentPage + 1}">
                    <i class="fas fa-chevron-right"></i>
                </button>
            `;
        }

        // Page info
        const pageInfo = `
            <div class="page-info">
                Page ${this.currentPage} of ${pagination.total_pages}
                (${pagination.total} total accounts)
            </div>
        `;

        paginationContainer.innerHTML = buttons + pageInfo;
        this.setupPaginationEventListeners();
    }

    updateStats(accounts) {
        const totalLocked = accounts.length;
        const loginLocks = accounts.filter(a => a.lock_type === 'login_attempts').length;
        const manualLocks = accounts.filter(a => a.lock_type === 'manual_lock').length;
        const unlockedToday = 0;

        const totalLockedEl = document.getElementById('totalLocked');
        const loginLocksEl = document.getElementById('loginLocks');
        const manualLocksEl = document.getElementById('manualLocks');
        const unlockedTodayEl = document.getElementById('unlockedToday');
        
        if (totalLockedEl) totalLockedEl.textContent = totalLocked;
        if (loginLocksEl) loginLocksEl.textContent = loginLocks;
        if (manualLocksEl) manualLocksEl.textContent = manualLocks;
        if (unlockedTodayEl) unlockedTodayEl.textContent = unlockedToday;
    }

    updateSelectedCount() {
        const selectedCount = this.selectedAccounts.size;
        const selectedCountSpan = document.getElementById('selectedCount');
        const bulkUnlockBtn = document.getElementById('bulkUnlockBtn');
        const selectAllHeader = document.getElementById('selectAllHeader');
        const selectAll = document.getElementById('selectAll');
        
        if (selectedCountSpan) {
            selectedCountSpan.textContent = `${selectedCount} selected`;
        }
        
        if (bulkUnlockBtn) {
            bulkUnlockBtn.disabled = selectedCount === 0;
        }
        
        // Update select all checkboxes
        const totalCheckboxes = document.querySelectorAll('.account-checkbox:not(:disabled)').length;
        const checkedCheckboxes = document.querySelectorAll('.account-checkbox:checked').length;
        const allChecked = totalCheckboxes > 0 && checkedCheckboxes === totalCheckboxes;
        
        if (selectAllHeader) selectAllHeader.checked = allChecked;
        if (selectAll) selectAll.checked = allChecked;
    }

    setupEventListeners() {
        // Search
        const searchBtn = document.getElementById('searchBtn');
        const searchInput = document.getElementById('searchInput');
        const refreshBtn = document.getElementById('refreshBtn');
        const bulkUnlockBtn = document.getElementById('bulkUnlockBtn');
        const exportBtn = document.getElementById('exportBtn');
        const selectAllHeader = document.getElementById('selectAllHeader');
        const selectAll = document.getElementById('selectAll');
        
        if (searchBtn) {
            searchBtn.addEventListener('click', () => {
                if (searchInput) {
                    this.searchTerm = searchInput.value.trim();
                    this.currentPage = 1;
                    this.selectedAccounts.clear();
                    this.loadAccounts();
                }
            });
        }

        if (searchInput) {
            searchInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    this.searchTerm = e.target.value.trim();
                    this.currentPage = 1;
                    this.selectedAccounts.clear();
                    this.loadAccounts();
                }
            });
        }

        // Refresh
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => {
                this.searchTerm = '';
                if (searchInput) searchInput.value = '';
                this.currentPage = 1;
                this.selectedAccounts.clear();
                this.loadAccounts();
                this.showSuccess('Accounts list refreshed');
            });
        }

        // Bulk unlock
        if (bulkUnlockBtn) {
            bulkUnlockBtn.addEventListener('click', () => {
                this.showBulkUnlockModal();
            });
        }

        // Export CSV
        if (exportBtn) {
            exportBtn.addEventListener('click', () => {
                this.exportToCSV();
            });
        }

        // Select all checkboxes
        if (selectAllHeader) {
            selectAllHeader.addEventListener('change', (e) => {
                const isChecked = e.target.checked;
                document.querySelectorAll('.account-checkbox:not(:disabled)').forEach(checkbox => {
                    checkbox.checked = isChecked;
                    const accountId = checkbox.dataset.id;
                    const userType = checkbox.dataset.userType;
                    const accountKey = `${userType}|${accountId}`;
                    if (isChecked) {
                        this.selectedAccounts.add(accountKey);
                    } else {
                        this.selectedAccounts.delete(accountKey);
                    }
                });
                this.updateSelectedCount();
            });
        }

        if (selectAll) {
            selectAll.addEventListener('change', (e) => {
                const isChecked = e.target.checked;
                document.querySelectorAll('.account-checkbox:not(:disabled)').forEach(checkbox => {
                    checkbox.checked = isChecked;
                    const accountId = checkbox.dataset.id;
                    const userType = checkbox.dataset.userType;
                    const accountKey = `${userType}|${accountId}`;
                    if (isChecked) {
                        this.selectedAccounts.add(accountKey);
                    } else {
                        this.selectedAccounts.delete(accountKey);
                    }
                });
                this.updateSelectedCount();
            });
        }
    }

    setupRowEventListeners() {
        // Checkbox events
        document.querySelectorAll('.account-checkbox').forEach(checkbox => {
            checkbox.removeEventListener('change', this.handleCheckboxChange);
            this.handleCheckboxChange = (e) => {
                const accountId = e.target.dataset.id;
                const userType = e.target.dataset.userType;
                const accountKey = `${userType}|${accountId}`;
                if (e.target.checked) {
                    this.selectedAccounts.add(accountKey);
                } else {
                    this.selectedAccounts.delete(accountKey);
                }
                this.updateSelectedCount();
            };
            checkbox.addEventListener('change', this.handleCheckboxChange.bind(this));
        });

        // Unlock button events
        document.querySelectorAll('.btn-unlock').forEach(button => {
            button.removeEventListener('click', this.handleUnlockClick);
            this.handleUnlockClick = (e) => {
                e.stopPropagation();
                const accountId = button.dataset.id;
                const userType = button.dataset.userType;
                const email = button.dataset.email;
                const name = button.dataset.name;
                const reason = button.dataset.reason;
                
                this.showUnlockModal(accountId, userType, email, name, reason);
            };
            button.addEventListener('click', this.handleUnlockClick.bind(this));
        });
    }

    setupPaginationEventListeners() {
        document.querySelectorAll('.page-btn').forEach(button => {
            button.removeEventListener('click', this.handlePageClick);
            this.handlePageClick = (e) => {
                const page = parseInt(e.target.dataset.page);
                if (page && page !== this.currentPage) {
                    this.currentPage = page;
                    this.loadAccounts();
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            };
            button.addEventListener('click', this.handlePageClick.bind(this));
        });
    }

    setupModals() {
        // Single unlock modal
        const unlockModal = document.getElementById('unlockModal');
        if (!unlockModal) return;
        
        const closeUnlockModal = unlockModal.querySelector('.close-modal');
        const cancelUnlock = unlockModal.querySelector('.cancel-unlock');
        const confirmUnlock = unlockModal.querySelector('.confirm-unlock');

        const showModal = (modal) => {
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        };

        const hideModal = (modal) => {
            modal.classList.remove('show');
            document.body.style.overflow = '';
        };

        if (closeUnlockModal) {
            closeUnlockModal.addEventListener('click', () => hideModal(unlockModal));
        }
        
        if (cancelUnlock) {
            cancelUnlock.addEventListener('click', () => hideModal(unlockModal));
        }

        if (confirmUnlock) {
            confirmUnlock.addEventListener('click', () => {
                const accountId = unlockModal.dataset.accountId;
                const userType = unlockModal.dataset.userType;
                const reason = document.getElementById('unlockReason')?.value.trim() || '';
                
                if (accountId && userType) {
                    this.unlockSingleAccount(accountId, userType, reason);
                } else {
                    console.error('Missing account ID or user type');
                    this.showError('Unable to unlock account: Missing information');
                }
                hideModal(unlockModal);
            });
        }

        // Bulk unlock modal
        const bulkModal = document.getElementById('bulkUnlockModal');
        if (bulkModal) {
            const closeBulkModal = bulkModal.querySelector('.close-modal');
            const cancelBulkUnlock = bulkModal.querySelector('.cancel-bulk-unlock');
            const confirmBulkUnlock = bulkModal.querySelector('.confirm-bulk-unlock');

            if (closeBulkModal) {
                closeBulkModal.addEventListener('click', () => hideModal(bulkModal));
            }
            
            if (cancelBulkUnlock) {
                cancelBulkUnlock.addEventListener('click', () => hideModal(bulkModal));
            }

            if (confirmBulkUnlock) {
                confirmBulkUnlock.addEventListener('click', () => {
                    const reason = document.getElementById('bulkUnlockReason')?.value.trim() || '';
                    if (this.selectedAccounts.size > 0) {
                        this.unlockBulkAccounts(reason);
                    } else {
                        this.showWarning('No accounts selected for unlocking');
                    }
                    hideModal(bulkModal);
                });
            }

            // Close modals on outside click
            bulkModal.addEventListener('click', (e) => {
                if (e.target === bulkModal) {
                    hideModal(bulkModal);
                }
            });
        }

        // Close single modal on outside click
        unlockModal.addEventListener('click', (e) => {
            if (e.target === unlockModal) {
                hideModal(unlockModal);
            }
        });

        // Store references
        this.unlockModal = unlockModal;
        this.bulkModal = bulkModal;
        this.showModal = showModal;
        this.hideModal = hideModal;
    }

    showUnlockModal(accountId, userType, email, name, lockReason) {
        const modalUserId = document.getElementById('modalUserId');
        const modalUserName = document.getElementById('modalUserName');
        const modalUserEmail = document.getElementById('modalUserEmail');
        const modalLockReason = document.getElementById('modalLockReason');
        const unlockReason = document.getElementById('unlockReason');
        
        if (modalUserId) modalUserId.textContent = accountId;
        if (modalUserName) modalUserName.textContent = name;
        if (modalUserEmail) modalUserEmail.textContent = email;
        if (modalLockReason) modalLockReason.textContent = lockReason || 'Too many failed login attempts';
        if (unlockReason) unlockReason.value = '';
        
        if (this.unlockModal) {
            this.unlockModal.dataset.accountId = accountId;
            this.unlockModal.dataset.userType = userType;
            this.showModal(this.unlockModal);
        }
    }

    showBulkUnlockModal() {
        const selectedCount = this.selectedAccounts.size;
        const bulkUnlockCount = document.getElementById('bulkUnlockCount');
        const bulkUnlockReason = document.getElementById('bulkUnlockReason');
        const selectedAccountsList = document.getElementById('selectedAccountsList');
        
        if (bulkUnlockCount) bulkUnlockCount.textContent = selectedCount;
        if (bulkUnlockReason) bulkUnlockReason.value = '';
        
        if (selectedAccountsList) {
            selectedAccountsList.innerHTML = '';
            
            const selectedRows = document.querySelectorAll('.account-checkbox:checked');
            selectedRows.forEach(checkbox => {
                const row = checkbox.closest('tr');
                const accountId = checkbox.dataset.id;
                const userType = checkbox.dataset.userType;
                const name = row.cells[2]?.querySelector('strong')?.textContent || 'N/A';
                const email = row.cells[3]?.textContent || 'N/A';
                
                const accountItem = document.createElement('div');
                accountItem.className = 'selected-account-item';
                accountItem.innerHTML = `
                    <div>
                        <strong>${this.escapeHtml(name)}</strong>
                        <div class="text-muted">${this.escapeHtml(email)} (${userType}: ${accountId})</div>
                    </div>
                `;
                selectedAccountsList.appendChild(accountItem);
            });
        }
        
        if (this.bulkModal) {
            this.showModal(this.bulkModal);
        }
    }

    async unlockSingleAccount(accountId, userType, reason) {
        try {
            const response = await fetch(this.apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'unlock_account',
                    user_type: userType,
                    account_id: accountId,
                    reason: reason
                })
            });

            const data = await response.json();

            if (data.success) {
                this.showSuccess(`Account unlocked: ${data.account.email}`);
                // Remove from selected accounts if present
                const accountKey = `${userType}|${accountId}`;
                this.selectedAccounts.delete(accountKey);
                this.loadAccounts();
            } else {
                this.showError('Failed to unlock account: ' + data.message);
            }
        } catch (error) {
            console.error('Error unlocking account:', error);
            this.showError('Failed to unlock account');
        }
    }

    async unlockBulkAccounts(reason) {
        const accountsByType = {};
        
        // Group accounts by user type
        this.selectedAccounts.forEach(accountKey => {
            const [userType, accountId] = accountKey.split('|');
            if (!accountsByType[userType]) {
                accountsByType[userType] = [];
            }
            accountsByType[userType].push(accountId);
        });
        
        if (Object.keys(accountsByType).length === 0) {
            this.showWarning('No accounts selected');
            return;
        }

        try {
            let totalSuccess = 0;
            let totalFailed = 0;
            
            for (const [userType, accountIds] of Object.entries(accountsByType)) {
                const response = await fetch(this.apiUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'bulk_unlock',
                        user_type: userType,
                        account_ids: accountIds,
                        reason: reason
                    })
                });

                const data = await response.json();
                
                if (data.success) {
                    totalSuccess += data.summary.successful;
                    totalFailed += data.summary.failed;
                } else {
                    totalFailed += accountIds.length;
                }
            }
            
            if (totalFailed === 0) {
                this.showSuccess(`Successfully unlocked ${totalSuccess} account(s)`);
            } else {
                this.showWarning(`Unlocked ${totalSuccess} account(s), ${totalFailed} failed`);
            }
            
            this.selectedAccounts.clear();
            this.loadAccounts();
        } catch (error) {
            console.error('Error in bulk unlock:', error);
            this.showError('Failed to perform bulk unlock');
        }
    }

    exportToCSV() {
        // Get all account data from the table
        const rows = document.querySelectorAll('#accountsTableBody tr:not(#loadingRow)');
        if (rows.length === 0) {
            this.showWarning('No data to export');
            return;
        }

        let csv = 'User ID,Name,Email,Role,Lock Type,Lock Reason,Failed Attempts,Locked Until,Status\n';
        
        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            if (cells.length >= 10) {
                const data = [
                    cells[1]?.textContent.trim() || '', // User ID
                    cells[2]?.querySelector('strong')?.textContent || cells[2]?.textContent.trim() || '', // Name
                    cells[3]?.textContent.trim() || '', // Email
                    cells[4]?.textContent.trim() || '', // Role
                    cells[5]?.querySelector('.lock-type-badge')?.textContent || cells[5]?.textContent.trim() || '', // Lock Type
                    cells[6]?.textContent.trim() || '', // Lock Reason
                    cells[7]?.textContent.trim() || '', // Failed Attempts
                    cells[8]?.textContent.trim() || '', // Locked Until
                    cells[9]?.querySelector('.status-badge')?.textContent || cells[9]?.textContent.trim() || '' // Status
                ];
                
                // Escape commas and quotes
                const escapedData = data.map(cell => {
                    if (cell.includes(',') || cell.includes('"') || cell.includes('\n')) {
                        return `"${cell.replace(/"/g, '""')}"`;
                    }
                    return cell;
                });
                
                csv += escapedData.join(',') + '\n';
            }
        });

        // Create download link
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `locked_accounts_${new Date().toISOString().split('T')[0]}.csv`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
        
        this.showSuccess('Export completed successfully');
    }

    setupToasts() {
        if (typeof toastr !== 'undefined') {
            toastr.options = {
                "closeButton": true,
                "debug": false,
                "newestOnTop": true,
                "progressBar": true,
                "positionClass": "toast-top-right",
                "preventDuplicates": false,
                "onclick": null,
                "showDuration": "300",
                "hideDuration": "1000",
                "timeOut": "5000",
                "extendedTimeOut": "1000",
                "showEasing": "swing",
                "hideEasing": "linear",
                "showMethod": "fadeIn",
                "hideMethod": "fadeOut"
            };
        }
    }

    showSuccess(message) {
        if (typeof toastr !== 'undefined') {
            toastr.success(message, 'Success');
        } else {
            console.log('Success:', message);
        }
    }

    showError(message) {
        if (typeof toastr !== 'undefined') {
            toastr.error(message, 'Error');
        } else {
            console.error('Error:', message);
        }
    }

    showWarning(message) {
        if (typeof toastr !== 'undefined') {
            toastr.warning(message, 'Warning');
        } else {
            console.warn('Warning:', message);
        }
    }

    formatDateTime(dateTimeString) {
        if (!dateTimeString) return 'N/A';
        
        try {
            const date = new Date(dateTimeString);
            return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        } catch (e) {
            return dateTimeString;
        }
    }

    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Initialize the manager when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.accountUnlockManager = new AccountUnlockManager();
});