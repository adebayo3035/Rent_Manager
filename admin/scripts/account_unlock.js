class AccountUnlockManager {
    constructor() {
        this.currentPage = 1;
        this.limit = 20;
        this.searchTerm = '';
        this.selectedAccounts = new Set();
        this.apiUrl = '../backend/utilities/unlock_account.php';
        this.init();
    }

    init() {
        this.loadAccounts();
        this.setupEventListeners();
        this.setupModals();
        this.setupToasts();
    }

    async loadAccounts() {
        const loadingRow = document.getElementById('loadingRow');
        const emptyState = document.getElementById('emptyState');
        const tableBody = document.getElementById('accountsTableBody');
        
        // Show loading
        loadingRow.style.display = '';
        emptyState.style.display = 'none';
        
        // Clear existing rows except loading row
        Array.from(tableBody.children).forEach(row => {
            if (row.id !== 'loadingRow') {
                row.remove();
            }
        });

        try {
            // Build query parameters
            const params = new URLSearchParams({
                page: this.currentPage,
                limit: this.limit
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
                
                // Show empty state if no accounts
                if (data.accounts.length === 0) {
                    loadingRow.style.display = 'none';
                    emptyState.style.display = 'block';
                } else {
                    loadingRow.style.display = 'none';
                }
            } else {
                this.showError('Failed to load accounts: ' + (data.message || 'Unknown error'));
                loadingRow.style.display = 'none';
                emptyState.style.display = 'block';
            }
        } catch (error) {
            console.error('Error loading accounts:', error);
            this.showError('Failed to load accounts');
            loadingRow.style.display = 'none';
            emptyState.style.display = 'block';
        }
    }

    renderAccounts(accounts) {
        const tableBody = document.getElementById('accountsTableBody');
        
        accounts.forEach(account => {
            const row = document.createElement('tr');
            row.dataset.accountId = account.unique_id;
            
            // Determine lock type and status
            const lockType = account.lock_type || 'login_attempts';
            const lockTypeText = lockType === 'login_attempts' ? 'Failed Logins' : 'Manual Lock';
            const lockTypeClass = lockType === 'login_attempts' ? 'lock-type-login' : 'lock-type-manual';
            
            // Determine status
            const isLocked = account.is_locked || (account.locked_until && new Date(account.locked_until) > new Date());
            const statusText = isLocked ? 'LOCKED' : 'UNLOCKED';
            const statusClass = isLocked ? 'status-locked' : 'status-unlocked';
            
            row.innerHTML = `
                <td>
                    <input type="checkbox" class="account-checkbox" 
                           data-id="${account.unique_id}"
                           ${!isLocked ? 'disabled' : ''}>
                </td>
                <td>${account.unique_id}</td>
                <td><strong>${account.firstname} ${account.lastname}</strong></td>
                <td>${account.email}</td>
                <td>${account.role}</td>
                <td><span class="lock-type-badge ${lockTypeClass}">${lockTypeText}</span></td>
                <td>${account.lock_reason || 'Too many failed login attempts'}</td>
                <td>${account.attempts || 'N/A'}</td>
                <td>${account.locked_until ? this.formatDateTime(account.locked_until) : 'N/A'}</td>
                <td><span class="status-badge ${statusClass}">${statusText}</span></td>
                <td>
                    <div class="action-buttons">
                        ${isLocked ? `
                            <button class="btn-unlock" data-id="${account.unique_id}" 
                                    data-email="${account.email}" 
                                    data-name="${account.firstname} ${account.lastname}"
                                    data-reason="${account.lock_reason || ''}">
                                <i class="fas fa-unlock-alt"></i> Unlock
                            </button>
                        ` : `
                            <span class="text-muted">Already unlocked</span>
                        `}
                    </div>
                </td>
            `;
            
            tableBody.appendChild(row);
        });

        // Add event listeners to checkboxes and unlock buttons
        this.setupRowEventListeners();
    }

    updatePagination(pagination) {
        const paginationContainer = document.getElementById('pagination');
        
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

        // Add event listeners to page buttons
        this.setupPaginationEventListeners();
    }

    updateStats(accounts) {
        const totalLocked = accounts.length;
        const loginLocks = accounts.filter(a => a.lock_type === 'login_attempts').length;
        const manualLocks = accounts.filter(a => a.lock_type === 'manual_lock').length;
        
        // For unlocked today, we'd need additional API call or keep track
        // For now, we'll show 0 and update if we implement that feature
        const unlockedToday = 0;

        document.getElementById('totalLocked').textContent = totalLocked;
        document.getElementById('loginLocks').textContent = loginLocks;
        document.getElementById('manualLocks').textContent = manualLocks;
        document.getElementById('unlockedToday').textContent = unlockedToday;
    }

    updateSelectedCount() {
        const selectedCount = this.selectedAccounts.size;
        document.getElementById('selectedCount').textContent = `${selectedCount} selected`;
        
        const bulkUnlockBtn = document.getElementById('bulkUnlockBtn');
        bulkUnlockBtn.disabled = selectedCount === 0;
        
        const selectAllHeader = document.getElementById('selectAllHeader');
        const selectAll = document.getElementById('selectAll');
        
        if (selectedCount > 0) {
            selectAll.checked = true;
            selectAllHeader.checked = true;
        } else {
            selectAll.checked = false;
            selectAllHeader.checked = false;
        }
    }

    setupEventListeners() {
        // Search
        document.getElementById('searchBtn').addEventListener('click', () => {
            this.searchTerm = document.getElementById('searchInput').value.trim();
            this.currentPage = 1;
            this.selectedAccounts.clear();
            this.loadAccounts();
        });

        document.getElementById('searchInput').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                this.searchTerm = e.target.value.trim();
                this.currentPage = 1;
                this.selectedAccounts.clear();
                this.loadAccounts();
            }
        });

        // Refresh
        document.getElementById('refreshBtn').addEventListener('click', () => {
            this.searchTerm = '';
            document.getElementById('searchInput').value = '';
            this.currentPage = 1;
            this.selectedAccounts.clear();
            this.loadAccounts();
            this.showSuccess('Accounts list refreshed');
        });

        // Bulk unlock
        document.getElementById('bulkUnlockBtn').addEventListener('click', () => {
            this.showBulkUnlockModal();
        });

        // Export CSV
        document.getElementById('exportBtn').addEventListener('click', () => {
            this.exportToCSV();
        });

        // Select all checkboxes
        document.getElementById('selectAllHeader').addEventListener('change', (e) => {
            const isChecked = e.target.checked;
            document.querySelectorAll('.account-checkbox:not(:disabled)').forEach(checkbox => {
                checkbox.checked = isChecked;
                const accountId = checkbox.dataset.id;
                if (isChecked) {
                    this.selectedAccounts.add(accountId);
                } else {
                    this.selectedAccounts.delete(accountId);
                }
            });
            this.updateSelectedCount();
        });

        document.getElementById('selectAll').addEventListener('change', (e) => {
            const isChecked = e.target.checked;
            document.querySelectorAll('.account-checkbox:not(:disabled)').forEach(checkbox => {
                checkbox.checked = isChecked;
                const accountId = checkbox.dataset.id;
                if (isChecked) {
                    this.selectedAccounts.add(accountId);
                } else {
                    this.selectedAccounts.delete(accountId);
                }
            });
            this.updateSelectedCount();
        });
    }

    setupRowEventListeners() {
        // Checkbox events
        document.querySelectorAll('.account-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', (e) => {
                const accountId = e.target.dataset.id;
                if (e.target.checked) {
                    this.selectedAccounts.add(accountId);
                } else {
                    this.selectedAccounts.delete(accountId);
                }
                this.updateSelectedCount();
            });
        });

        // Unlock button events
        document.querySelectorAll('.btn-unlock').forEach(button => {
            button.addEventListener('click', (e) => {
                const accountId = e.target.dataset.id || e.target.closest('.btn-unlock').dataset.id;
                const email = e.target.dataset.email || e.target.closest('.btn-unlock').dataset.email;
                const name = e.target.dataset.name || e.target.closest('.btn-unlock').dataset.name;
                const reason = e.target.dataset.reason || e.target.closest('.btn-unlock').dataset.reason;
                
                this.showUnlockModal(accountId, email, name, reason);
            });
        });
    }

    setupPaginationEventListeners() {
        document.querySelectorAll('.page-btn').forEach(button => {
            button.addEventListener('click', (e) => {
                const page = parseInt(e.target.dataset.page);
                if (page && page !== this.currentPage) {
                    this.currentPage = page;
                    this.loadAccounts();
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            });
        });
    }

    setupModals() {
        // Single unlock modal
        const unlockModal = document.getElementById('unlockModal');
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

        [closeUnlockModal, cancelUnlock].forEach(btn => {
            btn.addEventListener('click', () => hideModal(unlockModal));
        });

        confirmUnlock.addEventListener('click', () => {
            const accountId = unlockModal.dataset.accountId;
            const reason = document.getElementById('unlockReason').value.trim();
            this.unlockSingleAccount(accountId, reason);
            hideModal(unlockModal);
        });

        // Bulk unlock modal
        const bulkModal = document.getElementById('bulkUnlockModal');
        const closeBulkModal = bulkModal.querySelector('.close-modal');
        const cancelBulkUnlock = bulkModal.querySelector('.cancel-bulk-unlock');
        const confirmBulkUnlock = bulkModal.querySelector('.confirm-bulk-unlock');

        [closeBulkModal, cancelBulkUnlock].forEach(btn => {
            btn.addEventListener('click', () => hideModal(bulkModal));
        });

        confirmBulkUnlock.addEventListener('click', () => {
            const reason = document.getElementById('bulkUnlockReason').value.trim();
            this.unlockBulkAccounts(reason);
            hideModal(bulkModal);
        });

        // Close modals on outside click
        [unlockModal, bulkModal].forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    hideModal(modal);
                }
            });
        });

        // Store references
        this.unlockModal = unlockModal;
        this.bulkModal = bulkModal;
        this.showModal = showModal;
        this.hideModal = hideModal;
    }

    showUnlockModal(accountId, email, name, lockReason) {
        document.getElementById('modalUserId').textContent = accountId;
        document.getElementById('modalUserName').textContent = name;
        document.getElementById('modalUserEmail').textContent = email;
        document.getElementById('modalLockReason').textContent = lockReason || 'Too many failed login attempts';
        document.getElementById('unlockReason').value = '';
        
        this.unlockModal.dataset.accountId = accountId;
        this.showModal(this.unlockModal);
    }

    showBulkUnlockModal() {
        const selectedCount = this.selectedAccounts.size;
        document.getElementById('bulkUnlockCount').textContent = selectedCount;
        document.getElementById('bulkUnlockReason').value = '';
        
        // Clear and populate selected accounts list
        const accountsList = document.getElementById('selectedAccountsList');
        accountsList.innerHTML = '';
        
        // We need to get account details for display
        const selectedRows = document.querySelectorAll('.account-checkbox:checked');
        selectedRows.forEach(checkbox => {
            const row = checkbox.closest('tr');
            const accountId = row.dataset.accountId;
            const name = row.cells[2].querySelector('strong').textContent;
            const email = row.cells[3].textContent;
            
            const accountItem = document.createElement('div');
            accountItem.className = 'selected-account-item';
            accountItem.innerHTML = `
                <div>
                    <strong>${name}</strong>
                    <div class="text-muted">${email} (ID: ${accountId})</div>
                </div>
            `;
            accountsList.appendChild(accountItem);
        });
        
        this.showModal(this.bulkModal);
    }

    async unlockSingleAccount(accountId, reason) {
        try {
            const response = await fetch(this.apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'unlock_account',
                    account_id: parseInt(accountId),
                    reason: reason
                })
            });

            const data = await response.json();

            if (data.success) {
                this.showSuccess(`Account unlocked: ${data.account.email}`);
                // Remove from selected accounts if present
                this.selectedAccounts.delete(accountId.toString());
                // Reload accounts
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
        const accountIds = Array.from(this.selectedAccounts).map(id => parseInt(id));
        
        if (accountIds.length === 0) {
            this.showWarning('No accounts selected');
            return;
        }

        try {
            const response = await fetch(this.apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'bulk_unlock',
                    account_ids: accountIds,
                    reason: reason
                })
            });

            const data = await response.json();

            if (data.success) {
                const successful = data.summary.successful;
                const failed = data.summary.failed;
                
                if (failed === 0) {
                    this.showSuccess(`Successfully unlocked ${successful} account(s)`);
                } else {
                    this.showWarning(`Unlocked ${successful} account(s), ${failed} failed`);
                }
                
                // Clear selected accounts
                this.selectedAccounts.clear();
                // Reload accounts
                this.loadAccounts();
            } else {
                this.showError('Bulk unlock failed: ' + data.message);
            }
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
            const data = [
                cells[1].textContent, // User ID
                cells[2].querySelector('strong').textContent, // Name
                cells[3].textContent, // Email
                cells[4].textContent, // Role
                cells[5].querySelector('.lock-type-badge').textContent, // Lock Type
                cells[6].textContent, // Lock Reason
                cells[7].textContent, // Failed Attempts
                cells[8].textContent, // Locked Until
                cells[9].querySelector('.status-badge').textContent // Status
            ];
            
            // Escape commas and quotes
            const escapedData = data.map(cell => {
                if (cell.includes(',') || cell.includes('"') || cell.includes('\n')) {
                    return `"${cell.replace(/"/g, '""')}"`;
                }
                return cell;
            });
            
            csv += escapedData.join(',') + '\n';
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

    showSuccess(message) {
        toastr.success(message, 'Success');
    }

    showError(message) {
        toastr.error(message, 'Error');
    }

    showWarning(message) {
        toastr.warning(message, 'Warning');
    }

    formatDateTime(dateTimeString) {
        if (!dateTimeString) return 'N/A';
        
        const date = new Date(dateTimeString);
        return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Initialize the manager when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.accountUnlockManager = new AccountUnlockManager();
});