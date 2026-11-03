class AccountUnlockManager {
  constructor() {
    this.currentPage = 1;
    this.limit = 20;
    this.searchTerm = "";
    this.selectedAccounts = new Set();
    this.currentUserType = "admin";
    this.apiUrl = "../backend/utilities/unlock_account.php";
    this.init();
  }

  init() {
    this.loadAccounts();
    this.setupEventListeners();
    this.setupModals();
    this.setupToasts();
  }

  // ==================== ACCOUNT MANAGEMENT ====================

  async loadAccounts() {
    const loadingRow = document.getElementById("loadingRow");
    const emptyState = document.getElementById("emptyState");
    const tableBody = document.getElementById("accountsTableBody");

    if (loadingRow) loadingRow.style.display = "";
    if (emptyState) emptyState.style.display = "none";

    if (tableBody) {
      Array.from(tableBody.children).forEach((row) => {
        if (row.id !== "loadingRow") row.remove();
      });
    }

    try {
      const params = new URLSearchParams({
        page: this.currentPage,
        limit: this.limit,
        user_type: this.currentUserType,
      });

      if (this.searchTerm) params.append("search", this.searchTerm);

      const response = await fetch(`${this.apiUrl}?${params}`);
      const data = await response.json();

      if (data.success) {
        this.renderAccounts(data.accounts);
        this.updatePagination(data.pagination);
        this.updateStats(data.accounts);
        this.updateSelectedCount();

        if (data.accounts.length === 0) {
          if (loadingRow) loadingRow.style.display = "none";
          if (emptyState) emptyState.style.display = "block";
        } else {
          if (loadingRow) loadingRow.style.display = "none";
        }
      } else {
        this.showError(
          "Failed to load accounts: " + (data.message || "Unknown error"),
        );
        if (loadingRow) loadingRow.style.display = "none";
        if (emptyState) emptyState.style.display = "block";
      }
    } catch (error) {
      console.error("Error loading accounts:", error);
      this.showError("Failed to load accounts");
      if (loadingRow) loadingRow.style.display = "none";
      if (emptyState) emptyState.style.display = "block";
    }
  }

  renderAccounts(accounts) {
    const tableBody = document.getElementById("accountsTableBody");
    if (!tableBody) return;

    accounts.forEach((account) => {
      const row = document.createElement("tr");
      row.dataset.accountId = account.user_id;

      const lockTypeText =
        account.lock_type === "login_attempts"
          ? "Failed Logins"
          : "Manual Lock";
      const lockTypeClass =
        account.lock_type === "login_attempts"
          ? "lock-type-login"
          : "lock-type-manual";
      const attemptsSuffix = account.attempts ? ` · ${account.attempts}` : "";
      const userIcon = this.getUserTypeIcon(account.user_type);
      const isSelected = this.selectedAccounts.has(
        `${account.user_type}|${account.user_id}`,
      );

      row.innerHTML = `
                <td data-label="">
                    <input type="checkbox" class="account-checkbox"
                           data-id="${account.user_id}"
                           data-user-type="${account.user_type}"
                           ${isSelected ? "checked" : ""}>
                </td>
                <td data-label="User">
                    <i class="fas ${userIcon}" style="color:#667eea;margin-right:6px;"></i>
                    <strong>${this.escapeHtml(account.name)}</strong>
                </td>
                <td data-label="Email">${this.escapeHtml(account.email)}</td>
                <td data-label="Role">${this.escapeHtml(account.role)}</td>
                <td data-label="Lock Type">
                    <span class="lock-type-badge ${lockTypeClass}">${lockTypeText}${attemptsSuffix}</span>
                </td>
                <td data-label="Locked Until">${this.formatDateTime(account.locked_until) || "—"}</td>
                <td data-label="Action">
                    <button class="btn-unlock"
                            data-id="${account.user_id}"
                            data-user-type="${account.user_type}"
                            data-email="${this.escapeHtml(account.email)}"
                            data-name="${this.escapeHtml(account.name)}"
                            data-reason="${this.escapeHtml(account.lock_reason || "")}">
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
      admin: "fa-user-shield",
      tenant: "fa-user",
      agent: "fa-user-tie",
      client: "fa-briefcase",
    };
    return icons[userType] || "fa-user";
  }

  updatePagination(pagination) {
    const paginationContainer = document.getElementById("pagination");
    if (!paginationContainer) return;

    if (pagination.total_pages <= 1) {
      paginationContainer.innerHTML = "";
      return;
    }

    let buttons = "";

    if (this.currentPage > 1) {
      buttons += `<button class="page-btn" data-page="${this.currentPage - 1}">
                <i class="fas fa-chevron-left"></i></button>`;
    }

    const maxVisible = 5;
    let startPage = Math.max(1, this.currentPage - Math.floor(maxVisible / 2));
    let endPage = Math.min(pagination.total_pages, startPage + maxVisible - 1);

    if (endPage - startPage + 1 < maxVisible) {
      startPage = Math.max(1, endPage - maxVisible + 1);
    }

    for (let i = startPage; i <= endPage; i++) {
      buttons += `<button class="page-btn ${i === this.currentPage ? "active" : ""}" data-page="${i}">${i}</button>`;
    }

    if (this.currentPage < pagination.total_pages) {
      buttons += `<button class="page-btn" data-page="${this.currentPage + 1}">
                <i class="fas fa-chevron-right"></i></button>`;
    }

    const pageInfo = `<div class="page-info">
            Page ${this.currentPage} of ${pagination.total_pages}
            (${pagination.total} total accounts)
        </div>`;

    paginationContainer.innerHTML = buttons + pageInfo;
    this.setupPaginationEventListeners();
  }

  updateStats(accounts) {
    const totalLocked = accounts.length;
    const loginLocks = accounts.filter(
      (a) => a.lock_type === "login_attempts",
    ).length;
    const manualLocks = accounts.filter(
      (a) => a.lock_type === "manual_lock",
    ).length;

    const elements = {
      totalLocked: totalLocked,
      loginLocks: loginLocks,
      manualLocks: manualLocks,
      unlockedToday: 0,
    };

    Object.entries(elements).forEach(([id, value]) => {
      const el = document.getElementById(id);
      if (el) el.textContent = value;
    });
  }

  updateSelectedCount() {
    const selectedCount = this.selectedAccounts.size;
    const selectedCountSpan = document.getElementById("selectedCount");
    const bulkBarCount = document.getElementById("bulkBarCount");
    const bulkActionBar = document.getElementById("bulkActionBar");
    const selectAllHeader = document.getElementById("selectAllHeader");
    const selectAll = document.getElementById("selectAll");

    if (selectedCountSpan)
      selectedCountSpan.textContent = `${selectedCount} selected`;
    if (bulkBarCount) bulkBarCount.textContent = selectedCount;

    if (bulkActionBar) bulkActionBar.hidden = selectedCount === 0;

    const totalCheckboxes = document.querySelectorAll(
      ".account-checkbox:not(:disabled)",
    ).length;
    const checkedCheckboxes = document.querySelectorAll(
      ".account-checkbox:checked",
    ).length;
    const allChecked =
      totalCheckboxes > 0 && checkedCheckboxes === totalCheckboxes;

    if (selectAllHeader) selectAllHeader.checked = allChecked;
    if (selectAll) selectAll.checked = allChecked;
  }

  setupEventListeners() {
    const searchInput = document.getElementById("searchInput");
    const refreshBtn = document.getElementById("refreshBtn");
    const bulkUnlockBtn = document.getElementById("bulkUnlockBtn");
    const clearSelectionBtn = document.getElementById("clearSelectionBtn");
    const exportBtn = document.getElementById("exportBtn");
    const selectAllHeader = document.getElementById("selectAllHeader");
    const selectAll = document.getElementById("selectAll");
    const userTypeSelect = document.getElementById("userTypeSelect");

    // Search (debounced on Enter + button removed; toolbar uses input only)
    if (searchInput) {
      let debounceTimer;
      searchInput.addEventListener("input", (e) => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
          this.searchTerm = e.target.value.trim();
          this.currentPage = 1;
          this.selectedAccounts.clear();
          this.loadAccounts();
        }, 400);
      });
    }

    if (refreshBtn) {
      refreshBtn.addEventListener("click", () => {
        this.searchTerm = "";
        if (searchInput) searchInput.value = "";
        this.currentPage = 1;
        this.selectedAccounts.clear();
        this.loadAccounts();
        this.showSuccess("Accounts list refreshed");
      });
    }

    if (bulkUnlockBtn) {
      bulkUnlockBtn.addEventListener("click", () => this.showBulkUnlockModal());
    }

    if (clearSelectionBtn) {
      clearSelectionBtn.addEventListener("click", () => {
        this.selectedAccounts.clear();
        document
          .querySelectorAll(".account-checkbox")
          .forEach((cb) => (cb.checked = false));
        this.updateSelectedCount();
      });
    }

    if (exportBtn) {
      exportBtn.addEventListener("click", () => this.exportToCSV());
    }

    if (selectAllHeader) {
      selectAllHeader.addEventListener("change", (e) => {
        this.toggleAllCheckboxes(e.target.checked);
      });
    }

    if (selectAll) {
      selectAll.addEventListener("change", (e) => {
        this.toggleAllCheckboxes(e.target.checked);
      });
    }

    if (userTypeSelect) {
      userTypeSelect.addEventListener("change", (e) => {
        this.currentUserType = e.target.value;
        this.currentPage = 1;
        this.selectedAccounts.clear();
        this.loadAccounts();
      });
    }
  }

  toggleAllCheckboxes(isChecked) {
    document
      .querySelectorAll(".account-checkbox:not(:disabled)")
      .forEach((checkbox) => {
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
  }

  setupRowEventListeners() {
    document.querySelectorAll(".account-checkbox").forEach((checkbox) => {
      if (checkbox._listener)
        checkbox.removeEventListener("change", checkbox._listener);

      checkbox._listener = (e) => {
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

      checkbox.addEventListener("change", checkbox._listener);
    });

    document.querySelectorAll(".btn-unlock").forEach((button) => {
      if (button._listener)
        button.removeEventListener("click", button._listener);

      button._listener = (e) => {
        e.stopPropagation();
        const accountId = button.dataset.id;
        const userType = button.dataset.userType;
        const email = button.dataset.email;
        const name = button.dataset.name;
        const reason = button.dataset.reason;

        this.showUnlockModal(accountId, userType, email, name, reason);
      };

      button.addEventListener("click", button._listener);
    });
  }

  setupPaginationEventListeners() {
    document.querySelectorAll(".page-btn").forEach((button) => {
      if (button._listener)
        button.removeEventListener("click", button._listener);

      button._listener = (e) => {
        const page = parseInt(e.currentTarget.dataset.page);
        if (page && page !== this.currentPage) {
          this.currentPage = page;
          this.loadAccounts();
          window.scrollTo({ top: 0, behavior: "smooth" });
        }
      };

      button.addEventListener("click", button._listener);
    });
  }

  setupModals() {
    const showModal = (modal) => {
      modal.classList.add("show");
      document.body.style.overflow = "hidden";
    };

    const hideModal = (modal) => {
      modal.classList.remove("show");
      document.body.style.overflow = "";
    };

    // ===== SINGLE UNLOCK MODAL =====
    const unlockModal = document.getElementById("unlockModal");
    if (unlockModal) {
      const closeUnlockModal = unlockModal.querySelector(".close-modal");
      const cancelUnlock = unlockModal.querySelector(".cancel-unlock");
      const confirmUnlock = unlockModal.querySelector(".confirm-unlock");

      if (closeUnlockModal)
        closeUnlockModal.addEventListener("click", () =>
          hideModal(unlockModal),
        );
      if (cancelUnlock)
        cancelUnlock.addEventListener("click", () => hideModal(unlockModal));

      if (confirmUnlock) {
        confirmUnlock.addEventListener("click", () => {
          const accountId = unlockModal.dataset.accountId;
          const userType = unlockModal.dataset.userType;
          const reason =
            document.getElementById("unlockReason")?.value.trim() || "";

          if (accountId && userType) {
            this.unlockSingleAccount(accountId, userType, reason);
          } else {
            this.showError("Unable to unlock account: Missing information");
          }
          hideModal(unlockModal);
        });
      }

      unlockModal.addEventListener("click", (e) => {
        if (e.target === unlockModal) hideModal(unlockModal);
      });
    }

    // ===== BULK UNLOCK MODAL =====
    const bulkModal = document.getElementById("bulkUnlockModal");
    if (bulkModal) {
      const closeBulkModal = bulkModal.querySelector(".close-modal");
      const cancelBulkUnlock = bulkModal.querySelector(".cancel-bulk-unlock");
      const confirmBulkUnlock = bulkModal.querySelector(".confirm-bulk-unlock");

      if (closeBulkModal)
        closeBulkModal.addEventListener("click", () => hideModal(bulkModal));
      if (cancelBulkUnlock)
        cancelBulkUnlock.addEventListener("click", () => hideModal(bulkModal));

      if (confirmBulkUnlock) {
        confirmBulkUnlock.addEventListener("click", () => {
          const reason =
            document.getElementById("bulkUnlockReason")?.value.trim() || "";
          if (this.selectedAccounts.size > 0) {
            this.unlockBulkAccounts(reason);
          } else {
            this.showWarning("No accounts selected for unlocking");
          }
          hideModal(bulkModal);
        });
      }

      bulkModal.addEventListener("click", (e) => {
        if (e.target === bulkModal) hideModal(bulkModal);
      });
    }

    this.unlockModal = unlockModal;
    this.bulkModal = bulkModal;
    this.showModal = showModal;
    this.hideModal = hideModal;
  }

  showUnlockModal(accountId, userType, email, name, lockReason) {
    const modalUserId = document.getElementById("modalUserId");
    const modalUserName = document.getElementById("modalUserName");
    const modalUserEmail = document.getElementById("modalUserEmail");
    const modalLockReason = document.getElementById("modalLockReason");
    const unlockReason = document.getElementById("unlockReason");

    if (modalUserId) modalUserId.textContent = accountId;
    if (modalUserName) modalUserName.textContent = name;
    if (modalUserEmail) modalUserEmail.textContent = email;
    if (modalLockReason)
      modalLockReason.textContent =
        lockReason || "Too many failed login attempts";
    if (unlockReason) unlockReason.value = "";

    if (this.unlockModal) {
      this.unlockModal.dataset.accountId = accountId;
      this.unlockModal.dataset.userType = userType;
      this.showModal(this.unlockModal);
    }
  }

  showBulkUnlockModal() {
    const selectedCount = this.selectedAccounts.size;
    const bulkUnlockCount = document.getElementById("bulkUnlockCount");
    const bulkUnlockReason = document.getElementById("bulkUnlockReason");
    const selectedAccountsList = document.getElementById(
      "selectedAccountsList",
    );

    if (bulkUnlockCount) bulkUnlockCount.textContent = selectedCount;
    if (bulkUnlockReason) bulkUnlockReason.value = "";

    if (selectedAccountsList) {
      selectedAccountsList.innerHTML = "";

      document
        .querySelectorAll(".account-checkbox:checked")
        .forEach((checkbox) => {
          const row = checkbox.closest("tr");
          const accountId = checkbox.dataset.id;
          const userType = checkbox.dataset.userType;
          const name =
            row.querySelector('td[data-label="User"] strong')?.textContent ||
            "N/A";
          const email =
            row.querySelector('td[data-label="Email"]')?.textContent || "N/A";

          const accountItem = document.createElement("div");
          accountItem.className = "selected-account-item";
          accountItem.innerHTML = `
                    <strong>${this.escapeHtml(name)}</strong>
                    <div class="text-muted">${this.escapeHtml(email)} (${userType}: ${accountId})</div>
                `;
          selectedAccountsList.appendChild(accountItem);
        });
    }

    if (this.bulkModal) this.showModal(this.bulkModal);
  }

  async unlockSingleAccount(accountId, userType, reason) {
    try {
      const response = await fetch(this.apiUrl, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "unlock_account",
          user_type: userType,
          account_id: accountId,
          reason: reason,
        }),
      });

      const data = await response.json();

      if (data.success) {
        this.showSuccess(
          `Account unlocked: ${data.account?.email || accountId}`,
        );
        const accountKey = `${userType}|${accountId}`;
        this.selectedAccounts.delete(accountKey);
        this.loadAccounts();
      } else {
        this.showError("Failed to unlock account: " + data.message);
      }
    } catch (error) {
      console.error("Error unlocking account:", error);
      this.showError("Failed to unlock account");
    }
  }

  async unlockBulkAccounts(reason) {
    const accountsByType = {};

    this.selectedAccounts.forEach((accountKey) => {
      const [userType, accountId] = accountKey.split("|");
      if (!accountsByType[userType]) accountsByType[userType] = [];
      accountsByType[userType].push(accountId);
    });

    if (Object.keys(accountsByType).length === 0) {
      this.showWarning("No accounts selected");
      return;
    }

    try {
      let totalSuccess = 0;
      let totalFailed = 0;

      for (const [userType, accountIds] of Object.entries(accountsByType)) {
        const response = await fetch(this.apiUrl, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            action: "bulk_unlock",
            user_type: userType,
            account_ids: accountIds,
            reason: reason,
          }),
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
        this.showWarning(
          `Unlocked ${totalSuccess} account(s), ${totalFailed} failed`,
        );
      }

      this.selectedAccounts.clear();
      this.loadAccounts();
    } catch (error) {
      console.error("Error in bulk unlock:", error);
      this.showError("Failed to perform bulk unlock");
    }
  }

  exportToCSV() {
    const rows = document.querySelectorAll(
      "#accountsTableBody tr:not(#loadingRow)",
    );
    if (rows.length === 0) {
      this.showWarning("No data to export");
      return;
    }

    let csv =
      "User ID,Name,Email,Role,Lock Type,Locked Until,Status\n";

    rows.forEach((row) => {
      const userCell = row.querySelector('td[data-label="User"]');
      const emailCell = row.querySelector('td[data-label="Email"]');
      const roleCell = row.querySelector('td[data-label="Role"]');
      const lockTypeCell = row.querySelector('td[data-label="Lock Type"]');
      const lockedUntilCell = row.querySelector('td[data-label="Locked Until"]');
      const checkbox = row.querySelector(".account-checkbox");

      if (!checkbox) return;

      const data = [
        checkbox.dataset.id || "",
        userCell?.querySelector("strong")?.textContent.trim() || "",
        emailCell?.textContent.trim() || "",
        roleCell?.textContent.trim() || "",
        lockTypeCell?.textContent.trim() || "",
        lockedUntilCell?.textContent.trim() || "",
        "LOCKED",
      ];

      const escapedData = data.map((cell) => {
        if (cell.includes(",") || cell.includes('"') || cell.includes("\n")) {
          return `"${cell.replace(/"/g, '""')}"`;
        }
        return cell;
      });

      csv += escapedData.join(",") + "\n";
    });

    const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = `locked_accounts_${new Date().toISOString().split("T")[0]}.csv`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);

    this.showSuccess("Export completed successfully");
  }

  // ==================== UTILITIES ====================

  setupToasts() {
    if (typeof toastr !== "undefined") {
      toastr.options = {
        closeButton: true,
        debug: false,
        newestOnTop: true,
        progressBar: true,
        positionClass: "toast-top-right",
        preventDuplicates: false,
        showDuration: "300",
        hideDuration: "1000",
        timeOut: "5000",
        extendedTimeOut: "1000",
        showEasing: "swing",
        hideEasing: "linear",
        showMethod: "fadeIn",
        hideMethod: "fadeOut",
      };
    }
  }

  showSuccess(message) {
    if (typeof toastr !== "undefined") toastr.success(message, "Success");
    else console.log("Success:", message);
  }

  showError(message) {
    if (typeof toastr !== "undefined") toastr.error(message, "Error");
    else console.error("Error:", message);
  }

  showWarning(message) {
    if (typeof toastr !== "undefined") toastr.warning(message, "Warning");
    else console.warn("Warning:", message);
  }

  formatDateTime(dateTimeString) {
    if (!dateTimeString) return "N/A";
    try {
      const date = new Date(dateTimeString);
      return (
        date.toLocaleDateString() +
        " " +
        date.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })
      );
    } catch (e) {
      return dateTimeString;
    }
  }

  escapeHtml(text) {
    if (!text) return "";
    const div = document.createElement("div");
    div.textContent = text;
    return div.innerHTML;
  }
}

document.addEventListener("DOMContentLoaded", () => {
  window.accountUnlockManager = new AccountUnlockManager();
});