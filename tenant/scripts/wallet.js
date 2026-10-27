// tenant/scripts/wallet.js

const TENANT_WALLET_API = {
  getBalance: "../backend/wallet/get_balance.php",
  getBanks: "../backend/wallet/get_banks.php",
  inquiryBeneficiary: "../backend/wallet/inquiry_beneficiary.php",
  saveBank: "../backend/wallet/save_bank_account.php",
  changeBank: "../backend/wallet/change_bank_account.php",
  requestWithdrawal: "../backend/wallet/request_withdrawal.php",
  transactionDetail: "../backend/wallet/get_transaction_detail.php",
  bankHistory: "../backend/wallet/get_bank_history.php",
  cancelWithdrawal:   '../backend/wallet/cancel_withdrawal.php',  
};

let walletData = null;
let activeTab = "transactions";
let banksCache = null;
let userIdentity = null;

let currentFilters = {
  status: "all",
  type: "all",
  search: "",
  dateFrom: "",
  dateTo: "",
  page: 1,
  limit: 20,
};

let searchTimer = null;

// Withdrawal flow state
let withdrawalState = {
  step: "inquiry", // 'setup_bank' | 'inquiry' | 'amount' | 'confirm'
  bankCode: "",
  bankName: "",
  accountNumber: "",
  beneficiaryName: "",
  maskedAccount: "",
  amount: 0,
};

// Change bank flow state
let changeBankState = {
  step: "form", // 'form' | 'confirm'
  bankCode: "",
  bankName: "",
  accountNumber: "",
  accountName: "",
  currentSecretAnswer: "",
};

document.addEventListener("DOMContentLoaded", () => {
  renderWalletPage();
  loadWalletData();
});

// =========================================================
// PAGE SHELL
// =========================================================
function renderWalletPage() {
  const contentArea = document.getElementById("contentArea");
  if (!contentArea) return;

  contentArea.innerHTML = `
        <div class="wallet-container">
            <div class="wallet-page-header">
                <div>
                    <h1><i class="fas fa-wallet" style="color:#667eea;margin-right:8px;"></i> My Wallet</h1>
                    <p>Your refunds and credits. Request a withdrawal anytime.</p>
                </div>
            </div>

            <div class="wallet-balance-card">
                <div class="balance-block">
                    <div class="label">Available Balance</div>
                    <div class="amount" id="walletBalance">₦0.00</div>
                    <div class="subtext">Refunds are typically credited within 24 hours</div>
                </div>
                <div class="balance-actions">
                    <button class="btn-wallet-primary" onclick="openWithdrawalModal()">
                        <i class="fas fa-money-bill-wave"></i> Withdraw Funds
                    </button>
                </div>
            </div>

            <div class="wallet-mini-stats">
                <div class="mini-stat">
                    <div class="mini-label">Total In</div>
                    <div class="mini-value in" id="totalIn">₦0.00</div>
                </div>
                <div class="mini-stat">
                    <div class="mini-label">Total Out</div>
                    <div class="mini-value out" id="totalOut">₦0.00</div>
                </div>
                <div class="mini-stat">
                    <div class="mini-label">Pending Requests</div>
                    <div class="mini-value" id="pendingCount">0</div>
                </div>
            </div>

            <div style="text-align: right; margin-bottom: 12px;">
                <a href="#" onclick="event.preventDefault(); openChangeBankModal();"
                   style="color:#667eea; font-size:13px;">
                    <i class="fas fa-exchange-alt"></i> Change bank account
                </a>
            </div>

           <div class="wallet-tabs">
    <button class="wallet-tab active" data-tab="transactions" onclick="switchWalletTab('transactions')">
        <i class="fas fa-history"></i> Transactions
    </button>
    <button class="wallet-tab" data-tab="requests" onclick="switchWalletTab('requests')">
        <i class="fas fa-file-invoice"></i> Withdrawals
    </button>
    <button class="wallet-tab" data-tab="bank" onclick="switchWalletTab('bank')">
        <i class="fas fa-university"></i> Bank
    </button>
</div>

            <div class="wallet-filters" id="walletFilters">
                <div class="filter-group">
                    <label>Status</label>
                    <select id="filterStatus" onchange="applyFilters()">
                        <option value="all">All statuses</option>
                        <option value="Pending">Pending</option>
                        <option value="Approved">Approved</option>
                        <option value="Rejected">Rejected</option>
                        <option value="Failed">Failed</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Type</label>
                    <select id="filterType" onchange="applyFilters()">
                        <option value="all">All types</option>
                        <option value="withdrawal">Withdrawal</option>
                        <option value="refund_credit">Refund Credit</option>
                        <option value="rent_credit">Rent Credit</option>
                        <option value="admin_adjustment">Admin Adjustment</option>
                        <option value="deposit_release">Deposit Release</option>
                        <option value="debt_recovery">Debt Recovery</option>
                        <option value="settlement_debit">Settlement Debit</option>
                        <option value="topup">Top-Up</option>
                    </select>
                </div>
                <div class="filter-group grow">
                    <label>Search</label>
                    <input type="text" id="filterSearch" placeholder="Request ID or notes…"
                           onkeyup="debounceSearch()" />
                </div>
                <div class="filter-group">
                    <label>From</label>
                    <input type="date" id="filterFrom" onchange="applyFilters()" />
                </div>
                <div class="filter-group">
                    <label>To</label>
                    <input type="date" id="filterTo" onchange="applyFilters()" />
                </div>
                <div class="filter-group">
                    <label>&nbsp;</label>
                    <button class="btn-wallet-secondary" onclick="clearFilters()">
                        <i class="fas fa-times"></i> Clear
                    </button>
                </div>
            </div>

            <div id="walletTabContent">
                <div class="empty-state"><i class="fas fa-spinner fa-spin"></i> Loading…</div>
            </div>

            <div id="walletPagination" class="wallet-pagination"></div>
        </div>

        <!-- Withdrawal modal -->
        <div id="withdrawalModal" class="wallet-modal">
            <div class="wallet-modal-content">
                <div class="wallet-modal-header">
                    <h3 id="withdrawalModalTitle"><i class="fas fa-money-bill-wave"></i> Withdraw Funds</h3>
                    <button class="wallet-modal-close" onclick="closeWithdrawalModal()">&times;</button>
                </div>
                <div class="wallet-modal-body" id="withdrawalModalBody"></div>
                <div class="wallet-modal-footer" id="withdrawalModalFooter"></div>
            </div>
        </div>

        <!-- Change bank modal -->
        <div id="changeBankModal" class="wallet-modal">
            <div class="wallet-modal-content">
                <div class="wallet-modal-header">
                    <h3 id="changeBankModalTitle"><i class="fas fa-exchange-alt"></i> Change Bank Account</h3>
                    <button class="wallet-modal-close" onclick="closeChangeBankModal()">&times;</button>
                </div>
                <div class="wallet-modal-body" id="changeBankModalBody"></div>
                <div class="wallet-modal-footer" id="changeBankModalFooter"></div>
            </div>
        </div>

        <div id="walletLoader" class="wallet-loader-overlay" style="display:none;">
            <div class="wallet-loader-spinner"></div>
            <div style="color:#475569;font-size:14px;">Processing…</div>
        </div>

<div id="confirmModal" class="confirm-modal-overlay">
    <div class="confirm-modal">
        <div class="confirm-modal-icon" id="confirmModalIcon"></div>
        <h3 class="confirm-modal-title" id="confirmModalTitle">Confirm</h3>
        <div class="confirm-modal-body" id="confirmModalBody"></div>
        <div class="confirm-modal-actions">
            <button type="button" class="btn-wallet-secondary" id="confirmModalCancel">Cancel</button>
            <button type="button" class="btn-wallet-submit" id="confirmModalConfirm">Confirm</button>
        </div>
    </div>
</div>
    `;
}

// =========================================================
// DATA LOADING
// =========================================================
async function loadWalletData() {
  try {
    const params = new URLSearchParams({
      page: currentFilters.page,
      limit: currentFilters.limit,
    });
    if (currentFilters.status !== "all")
      params.set("status", currentFilters.status);
    if (currentFilters.type !== "all") params.set("type", currentFilters.type);
    if (currentFilters.search) params.set("search", currentFilters.search);
    if (currentFilters.dateFrom)
      params.set("date_from", currentFilters.dateFrom);
    if (currentFilters.dateTo) params.set("date_to", currentFilters.dateTo);

    const res = await fetch(`${TENANT_WALLET_API.getBalance}?${params}`, {
      credentials: "same-origin",
    });
    const data = await res.json();
    if (!data.success) throw new Error(data.message || "Failed to load wallet");

    walletData = data.data;

    document.getElementById("walletBalance").textContent =
      walletData.wallet_balance_formatted;
    document.getElementById("totalIn").textContent =
      walletData.total_in_formatted || "₦0.00";
    document.getElementById("totalOut").textContent =
      walletData.total_out_formatted || "₦0.00";
    document.getElementById("pendingCount").textContent = (
      walletData.pending_requests || []
    ).length;

    renderActiveTab();
    renderPagination(walletData.pagination);
  } catch (err) {
    console.error(err);
    showToast(err.message || "Failed to load wallet", "error");
  }
}

async function ensureBanksCache() {
  if (banksCache && userIdentity) return;
  try {
    const res = await fetch(TENANT_WALLET_API.getBanks, {
      credentials: "same-origin",
    });
    const data = await res.json();
    if (data.success) {
      banksCache = data.data.banks;
      userIdentity = data.data.user || null;
    }
  } catch (_) {
    /* silent — form will show empty list */
  }
}

function switchWalletTab(tab) {
    activeTab = tab;
    document.querySelectorAll('.wallet-tab').forEach(el =>
        el.classList.toggle('active', el.dataset.tab === tab));

    // Filters only apply to the Transactions tab
    document.getElementById('walletFilters').style.display =
        (tab === 'transactions') ? 'flex' : 'none';

    renderActiveTab();
    renderPagination(walletData?.pagination);
}

function renderActiveTab() {
    if (!walletData) return;
    if (activeTab === 'transactions')  renderTransactions();
    else if (activeTab === 'requests') renderRequests();
    else if (activeTab === 'bank')     renderBankTab();
}

// =========================================================
// FILTERS
// =========================================================
function applyFilters() {
  currentFilters.status = document.getElementById("filterStatus").value;
  currentFilters.type = document.getElementById("filterType").value;
  currentFilters.dateFrom = document.getElementById("filterFrom").value;
  currentFilters.dateTo = document.getElementById("filterTo").value;
  currentFilters.page = 1;
  loadWalletData();
}

function debounceSearch() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    currentFilters.search = document
      .getElementById("filterSearch")
      .value.trim();
    currentFilters.page = 1;
    loadWalletData();
  }, 400);
}

function clearFilters() {
  document.getElementById("filterStatus").value = "all";
  document.getElementById("filterType").value = "all";
  document.getElementById("filterSearch").value = "";
  document.getElementById("filterFrom").value = "";
  document.getElementById("filterTo").value = "";
  Object.assign(currentFilters, {
    status: "all",
    type: "all",
    search: "",
    dateFrom: "",
    dateTo: "",
    page: 1,
  });
  loadWalletData();
}

// =========================================================
// TRANSACTIONS
// =========================================================
function renderTransactions() {
  const tx = walletData.transactions || [];
  const wrap = document.getElementById("walletTabContent");

  if (!tx.length) {
    wrap.innerHTML = `<div class="empty-state"><i class="fas fa-receipt"></i><p>No transactions match your filters</p></div>`;
    return;
  }

  const rejectedRequestIds = new Set();
  for (const t of tx) {
    if (t.type === "withdrawal" && t.status === "Rejected" && t.request_id) {
      rejectedRequestIds.add(t.request_id);
    }
  }

  wrap.innerHTML = `
        <div class="wallet-table-wrap">
            <table class="wallet-table responsive-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Balance After</th>
                        <th>Status</th>
                        <th>Outcome</th>
                    </tr>
                </thead>
                <tbody>
                    ${tx
                      .map(
                        (t) => `
                        <tr onclick="openTransactionDetail(${t.id})" style="cursor:pointer;">
                            <td data-label="Date">${formatDateTime(t.created_at)}</td>
                            <td data-label="Type">${humanizeType(t.type)}</td>
                            <td data-label="Amount" class="${t.amount >= 0 ? "tx-amount-credit" : "tx-amount-debit"}">
                                ${t.amount >= 0 ? "+" : ""}${t.amount_formatted}
                            </td>
                            <td data-label="Balance After">₦${formatNumber(t.running_balance)}</td>
                            <td data-label="Status">${statusBadge(t.status)}</td>
                            <td data-label="Outcome">${outcomeLabel(t, rejectedRequestIds)}</td>
                        </tr>
                    `,
                      )
                      .join("")}
                </tbody>
            </table>
        </div>
    `;
}

function renderRequests() {
    const req = walletData.pending_requests || [];
    const wrap = document.getElementById('walletTabContent');

    if (!req.length) {
        wrap.innerHTML = `<div class="empty-state"><i class="fas fa-file-invoice"></i><p>No pending withdrawal requests</p></div>`;
        return;
    }

    wrap.innerHTML = `
        <div class="wallet-table-wrap">
            <table class="wallet-table responsive-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Reference</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    ${req.map(r => `
                        <tr>
                            <td data-label="Date">${formatDateTime(r.created_at)}</td>
                            <td data-label="Type">Withdrawal</td>
                            <td data-label="Amount">${r.amount_formatted}</td>
                            <td data-label="Status"><span class="status-pill ${r.status}">${r.status}</span></td>
                            <td data-label="Reference" style="font-family:monospace;font-size:12px;color:#64748b;">
                                ${escapeHtml(r.request_code)}
                            </td>
                            <td data-label="" style="text-align:right;">
                                <button class="btn-cancel-request"
                                        onclick="confirmCancelWithdrawal('${escapeHtml(r.request_code)}', ${r.amount})">
                                    <i class="fas fa-times"></i> Cancel
                                </button>
                            </td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        </div>
    `;
}
async function renderBankTab() {
    await ensureBanksCache();
    const wrap = document.getElementById('walletTabContent');
    wrap.innerHTML = `
        <div class="empty-state">
            <i class="fas fa-spinner fa-spin"></i>
            <p>Loading bank history…</p>
        </div>`;

    try {
        const res = await fetch(TENANT_WALLET_API.bankHistory, { credentials: 'same-origin' });
        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'Failed to load bank history');

        const current = data.data.current_bank;
        const history = data.data.history || [];

        // Current bank card
        const currentCard = current
            ? `
                <div class="bank-current-card">
                    <div class="bank-current-header">
                        <div>
                            <div class="bank-current-label">Current Bank Account</div>
                            <div class="bank-current-name">${escapeHtml(current.account_name)}</div>
                        </div>
                        <span class="status-pill approved">Active</span>
                    </div>
                    <div class="bank-current-details">
                        <div class="bank-detail-row">
                            <span class="label">Bank</span>
                            <span class="value">${escapeHtml(bankNameByCode(current.bank_code) || current.bank_code)}</span>
                        </div>
                        <div class="bank-detail-row">
                            <span class="label">Account</span>
                            <span class="value" style="font-family:monospace;">${escapeHtml(current.account_masked)}</span>
                        </div>
                        <div class="bank-detail-row">
                            <span class="label">Active since</span>
                            <span class="value">${formatDateTime(current.added_at)}</span>
                        </div>
                    </div>
                    <div class="bank-current-actions">
                        <button class="btn-wallet-secondary" onclick="openChangeBankModal()">
                            <i class="fas fa-exchange-alt"></i> Change Bank
                        </button>
                    </div>
                </div>
            `
            : `
                <div class="bank-current-empty">
                    <i class="fas fa-university"></i>
                    <p>No bank account on file yet.</p>
                    <p style="font-size:13px;color:#94a3b8;margin-top:6px;">
                        You'll be prompted to add one when you withdraw.
                    </p>
                </div>
            `;

        // History list (excluding the current entry)
        const pastEntries = history.filter(h => !h.is_current);

        let historySection = '';
        if (pastEntries.length) {
            historySection = `
                <div class="bank-history-section">
                    <h3 class="bank-history-title">
                        <i class="fas fa-history"></i> Previous Banks
                        <span class="bank-history-count">${pastEntries.length}</span>
                    </h3>
                    <div class="bank-history-list">
                        ${pastEntries.map((h, i) => `
                            <div class="bank-history-item">
                                <div class="bank-history-marker ${i === 0 ? 'first' : ''}"></div>
                                <div class="bank-history-content">
                                    <div class="bank-history-top">
                                        <div>
                                            <div class="bank-history-name">${escapeHtml(h.account_name)}</div>
                                            <div class="bank-history-bank">
                                                ${escapeHtml(bankNameByCode(h.bank_code) || h.bank_code)}
                                                · <span style="font-family:monospace;">${escapeHtml(h.account_masked)}</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="bank-history-timeline">
                                        <span class="timeline-item">
                                            <i class="fas fa-plus-circle" style="color:#059669;"></i>
                                            Added ${formatDateTime(h.added_at)}
                                        </span>
                                        <span class="timeline-item">
                                            <i class="fas fa-minus-circle" style="color:#dc2626;"></i>
                                            Removed ${formatDateTime(h.removed_at)}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
            `;
        } else if (history.length <= 1) {
            historySection = `
                <div class="bank-history-section">
                    <h3 class="bank-history-title">
                        <i class="fas fa-history"></i> Previous Banks
                    </h3>
                    <div class="admin-empty-state" style="padding:40px 20px;">
                        <i class="fas fa-info-circle" style="font-size:32px;"></i>
                        <p>No bank changes yet. Your account history will appear here.</p>
                    </div>
                </div>
            `;
        }

        wrap.innerHTML = currentCard + historySection;

    } catch (err) {
        wrap.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-exclamation-triangle" style="color:#ef4444;"></i>
                <p>${escapeHtml(err.message || 'Failed to load bank history')}</p>
            </div>`;
    }
}

function bankNameByCode(code) {
    if (!banksCache || !code) return null;
    const match = banksCache.find(b => b.code === code);
    return match ? match.name : null;
}

function renderPagination(p) {
  const wrap = document.getElementById("walletPagination");
  if (!p || activeTab !== "transactions" || p.total_pages <= 1) {
    wrap.innerHTML = "";
    return;
  }

  const pages = [];
  const current = p.page,
    last = p.total_pages;
  for (let i = 1; i <= last; i++) {
    if (i === 1 || i === last || Math.abs(i - current) <= 1) {
      pages.push(i);
    } else if (pages[pages.length - 1] !== "…") {
      pages.push("…");
    }
  }

  wrap.innerHTML = `
        <button class="page-btn" ${current === 1 ? "disabled" : ""} onclick="goToPage(${current - 1})">
            <i class="fas fa-chevron-left"></i>
        </button>
        ${pages
          .map((pg) =>
            pg === "…"
              ? `<span class="page-ellipsis">…</span>`
              : `<button class="page-btn ${pg === current ? "active" : ""}" onclick="goToPage(${pg})">${pg}</button>`,
          )
          .join("")}
        <button class="page-btn" ${current === last ? "disabled" : ""} onclick="goToPage(${current + 1})">
            <i class="fas fa-chevron-right"></i>
        </button>
        <span class="page-info">${current} of ${last}</span>
    `;
}

function goToPage(page) {
  currentFilters.page = page;
  loadWalletData();
  window.scrollTo({ top: 0, behavior: "smooth" });
}

// =========================================================
// TRANSACTION DETAIL DRAWER
// =========================================================
async function openTransactionDetail(txId) {
  if (!txId) return;
  showLoader();
  try {
    const res = await fetch(
      `${TENANT_WALLET_API.transactionDetail}?id=${encodeURIComponent(txId)}`,
      { credentials: "same-origin" },
    );
    const data = await res.json();
    if (!data.success)
      throw new Error(data.message || "Failed to load transaction");
    renderDetailDrawer(data.data);
  } catch (err) {
    showToast(err.message || "Failed to load transaction", "error");
  } finally {
    hideLoader();
  }
}

function renderDetailDrawer(data) {
  const existing = document.getElementById("txDetailDrawer");
  if (existing) existing.remove();

  const tx = data.transaction;
  const req = data.request;
  const related = data.related_transactions || [];

  const html = `
        <div id="txDetailDrawer" class="drawer-overlay" onclick="if(event.target===this)closeTransactionDetail()">
            <div class="drawer">
                <div class="drawer-header">
                    <div>
                        <h2>${escapeHtml(humanizeType(tx.type))}</h2>
                        <div class="sub">${formatDateTime(tx.created_at)}</div>
                    </div>
                    <button class="drawer-close" onclick="closeTransactionDetail()">&times;</button>
                </div>
                <div class="drawer-body">

                    <div class="detail-section">
                        <div class="detail-hero ${tx.direction === "in" ? "in" : "out"}">
                            <div class="hero-amount">${tx.amount_formatted}</div>
                            <div class="hero-label">${tx.direction === "in" ? "Credited to your wallet" : "Debited from your wallet"}</div>
                        </div>
                        <div class="detail-grid">
                            <div><label>Status</label><div>${statusBadge(tx.status)}</div></div>
                            <div><label>Balance after</label><div>₦${formatNumber(tx.running_balance)}</div></div>
                            <div><label>Date</label><div>${formatDateTime(tx.created_at)}</div></div>
                            ${tx.request_id ? `<div><label>Reference</label><div style="font-family:monospace;">${escapeHtml(tx.request_id)}</div></div>` : ""}
                        </div>
                        ${tx.notes ? `<div class="detail-notes"><label>Notes</label><div>${escapeHtml(tx.notes)}</div></div>` : ""}
                    </div>

                    ${
                      req
                        ? `
                        <div class="detail-section">
                            <h3>Request Details</h3>
                            <div class="detail-grid">
                                <div><label>Request</label><div style="font-family:monospace;">${escapeHtml(req.request_code)}</div></div>
                                <div><label>Type</label><div>${req.type === "topup" ? "Top-Up" : "Withdrawal"}</div></div>
                                <div><label>Amount</label><div>${req.amount_formatted}</div></div>
                                <div><label>Request Status</label><div>${statusBadge(req.status)}</div></div>
                                ${req.reference_number ? `<div><label>Bank Ref</label><div style="font-family:monospace;">${escapeHtml(req.reference_number)}</div></div>` : ""}
                                ${req.approved_at ? `<div><label>Resolved</label><div>${formatDateTime(req.approved_at)}</div></div>` : ""}
                            </div>

                            ${
                              req.bank_name
                                ? `
                                <h3 style="margin-top:16px;">Destination</h3>
                                <div class="detail-grid">
                                    <div><label>Bank</label><div>${escapeHtml(req.bank_name)}</div></div>
                                    <div><label>Account Name</label><div>${escapeHtml(req.bank_account_name || "—")}</div></div>
                                    <div><label>Account</label><div style="font-family:monospace;">******${escapeHtml(req.bank_account_last4 || "")}</div></div>
                                </div>
                            `
                                : ""
                            }

                            ${
                              req.rejection_reason
                                ? `
                                <div class="detail-section alert-section" style="margin-top:16px;">
                                    <h3 style="color:#991b1b;">Rejection Reason</h3>
                                    <div>${escapeHtml(req.rejection_reason)}</div>
                                </div>
                            `
                                : ""
                            }
                        </div>
                    `
                        : ""
                    }

                    ${
                      related.length > 1
                        ? `
                        <div class="detail-section">
                            <h3>Related Transactions</h3>
                            <div class="ledger-mini-wrap">
                                ${related
                                  .map(
                                    (r) => `
                                    <div class="ledger-mini-row ${r.id === tx.id ? "current" : ""}">
                                        <div>
                                            <div class="lm-type">${humanizeType(r.type)}</div>
                                            <div class="lm-date">${formatDateTime(r.created_at)}</div>
                                        </div>
                                        <div class="lm-amount ${r.amount >= 0 ? "in" : "out"}">${r.amount_formatted}</div>
                                    </div>
                                `,
                                  )
                                  .join("")}
                            </div>
                        </div>
                    `
                        : ""
                    }

                </div>
            </div>
        </div>
    `;
  document.body.insertAdjacentHTML("beforeend", html);
}

function closeTransactionDetail() {
  const d = document.getElementById("txDetailDrawer");
  if (d) d.remove();
}

// =========================================================
// WITHDRAWAL FLOW
// =========================================================
async function openWithdrawalModal() {
  withdrawalState = {
    step: "inquiry",
    bankCode: "",
    bankName: "",
    accountNumber: "",
    beneficiaryName: "",
    maskedAccount: "",
    amount: 0,
  };
  document.getElementById("withdrawalModal").classList.add("active");

  await ensureBanksCache();

  if (!walletData?.has_bank_account) {
    withdrawalState.step = "setup_bank";
    renderSetupBankStep();
    return;
  }

  withdrawalState.bankCode = walletData.bank_code || "";
  withdrawalState.bankName = walletData.bank_name || "";
  withdrawalState.accountNumber = walletData.bank_account_number || "";
  withdrawalState.beneficiaryName = walletData.bank_account_name || "";

  renderInquiryStep();
}

function closeWithdrawalModal() {
  document.getElementById("withdrawalModal").classList.remove("active");
  withdrawalState = {
    step: "inquiry",
    bankCode: "",
    bankName: "",
    accountNumber: "",
    beneficiaryName: "",
    maskedAccount: "",
    amount: 0,
  };
}

function renderSetupBankStep() {
  document.getElementById("withdrawalModalTitle").innerHTML =
    '<i class="fas fa-university"></i> Add Your Bank Account';

  const bankOptions = (banksCache || [])
    .map(
      (b) =>
        `<option value="${escapeHtml(b.code)}">${escapeHtml(b.name)}</option>`,
    )
    .join("");

  document.getElementById("withdrawalModalBody").innerHTML = `
        <p style="font-size:13px;color:#64748b;margin-bottom:16px;">
            Before you can withdraw funds, add the bank account that should receive your transfers.
            You'll need to authorize this with your secret answer.
        </p>

        <form id="setupBankForm" onsubmit="event.preventDefault(); submitSetupBank();">
            <div class="wallet-form-group">
                <label>Bank *</label>
                <select id="setupBank" required>
                    <option value="">Select your bank</option>
                    ${bankOptions}
                </select>
            </div>

            <div class="wallet-form-group">
                <label>Account Number *</label>
                <input type="text" id="setupAccountNumber" inputmode="numeric" maxlength="10"
                       pattern="\\d{10}" placeholder="10-digit account number" required>
                <div class="hint">Exactly 10 digits.</div>
            </div>

            <div class="wallet-form-group">
                <label>Account Name *</label>
                <input type="text" id="setupAccountName" maxlength="100"
                       placeholder="Name as it appears on your bank account" required>
                <div class="hint">This is the name on your bank statement. It cannot be changed later without support.</div>
            </div>

            <div class="wallet-form-group">
                <label>Secret Answer *</label>
                <input type="password" id="setupSecretAnswer" autocomplete="off" required>
                <div class="hint">Answer to your security question, used to authorize this change.</div>
            </div>
        </form>
    `;

  document.getElementById("withdrawalModalFooter").innerHTML = `
        <button type="button" class="btn-wallet-secondary" onclick="closeWithdrawalModal()">Cancel</button>
        <button type="button" class="btn-wallet-submit" id="setupBankBtn" onclick="submitSetupBank()">
            <i class="fas fa-save"></i> Save &amp; Continue
        </button>
    `;

  const acctInput = document.getElementById("setupAccountNumber");
  acctInput.addEventListener("input", () => {
    acctInput.value = acctInput.value.replace(/\D/g, "").slice(0, 10);
  });

  attachAccountNameAutofill(
    document.getElementById("setupBank"),
    document.getElementById("setupAccountNumber"),
    document.getElementById("setupAccountName"),
  );
}

async function submitSetupBank() {
  const bankCode = document.getElementById("setupBank").value;
  const accountNo = document.getElementById("setupAccountNumber").value.trim();
  const accountName = document.getElementById("setupAccountName").value.trim();
  const secretAns = document.getElementById("setupSecretAnswer").value.trim();

  if (!bankCode) return showToast("Select your bank", "error");
  if (!/^\d{10}$/.test(accountNo))
    return showToast("Account number must be 10 digits", "error");
  if (!accountName) return showToast("Enter the account name", "error");
  if (!secretAns) return showToast("Enter your secret answer", "error");

  const selectedBank = (banksCache || []).find((b) => b.code === bankCode);
  if (!selectedBank) return showToast("Invalid bank selection", "error");

  const btn = document.getElementById("setupBankBtn");
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
  showLoader();

  try {
    const csrf = await getCsrfToken("tenant_withdrawal_form");
    const body = {
      bank_name: selectedBank.name,
      bank_code: bankCode,
      account_name: accountName,
      account_number: accountNo,
      secret_answer: secretAns,
    };
    if (csrf) {
      body.csrf_token = csrf;
      body.token_id = "tenant_withdrawal_form";
    }

    const res = await fetch(TENANT_WALLET_API.saveBank, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
      credentials: "same-origin",
    });
    const data = await res.json();
    if (!data.success)
      throw new Error(data.message || "Failed to save bank account");

    await loadWalletData();

    withdrawalState.step = "inquiry";
    withdrawalState.bankCode = data.data.bank_code;
    withdrawalState.bankName = data.data.bank_name;
    withdrawalState.accountNumber = data.data.bank_account_number;
    withdrawalState.beneficiaryName = data.data.bank_account_name;
    withdrawalState.maskedAccount = data.data.masked_account;

    hideLoader();
    showToast("Bank account saved", "success");
    renderInquiryStep();
  } catch (err) {
    hideLoader();
    showToast(err.message || "Failed to save bank account", "error");
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-save"></i> Save &amp; Continue';
  }
}

function renderInquiryStep() {
  document.getElementById("withdrawalModalTitle").innerHTML =
    '<i class="fas fa-search"></i> Verify Beneficiary';

  const hasSaved =
    !!withdrawalState.bankCode && !!withdrawalState.accountNumber;

  if (hasSaved) {
    document.getElementById("withdrawalModalBody").innerHTML = `
            <p style="font-size:13px;color:#64748b;margin-bottom:16px;">
                You're withdrawing to your saved bank account. Confirm the beneficiary below.
            </p>
            <div class="bank-details-box">
                <div class="bank-label">Beneficiary</div>
                <div style="font-weight:600;color:#1a1f36;font-size:15px;">${escapeHtml(withdrawalState.beneficiaryName || "—")}</div>
                <div>${escapeHtml(withdrawalState.bankName)} &middot; ${escapeHtml(withdrawalState.maskedAccount || "******" + withdrawalState.accountNumber.slice(-4))}</div>
            </div>
            <p style="font-size:12px;color:#94a3b8;margin-top:8px;">
                To change your bank account, use the "Change bank account" option.
            </p>
        `;
    document.getElementById("withdrawalModalFooter").innerHTML = `
            <button type="button" class="btn-wallet-secondary" onclick="closeWithdrawalModal()">Cancel</button>
            <button type="button" class="btn-wallet-submit" id="inquiryBtn" onclick="verifySavedAndProceed()">
                <i class="fas fa-arrow-right"></i> Continue
            </button>
        `;
    return;
  }

  const bankOptions = (banksCache || [])
    .map(
      (b) =>
        `<option value="${escapeHtml(b.code)}" ${withdrawalState.bankCode === b.code ? "selected" : ""}>${escapeHtml(b.name)}</option>`,
    )
    .join("");

  document.getElementById("withdrawalModalBody").innerHTML = `
        <p style="font-size:13px;color:#64748b;margin-bottom:16px;">
            Enter your bank details. We'll verify the account holder's name before you continue.
        </p>
        <form id="inquiryForm" onsubmit="event.preventDefault(); submitInquiry();">
            <div class="wallet-form-group">
                <label>Bank *</label>
                <select id="inquiryBank" required>
                    <option value="">Select your bank</option>
                    ${bankOptions}
                </select>
            </div>
            <div class="wallet-form-group">
                <label>Account Number *</label>
                <input type="text" id="inquiryAccountNumber" inputmode="numeric" maxlength="10"
                       pattern="\\d{10}" value="${escapeHtml(withdrawalState.accountNumber)}"
                       placeholder="10-digit account number" required>
                <div class="hint">Exactly 10 digits.</div>
            </div>
        </form>
    `;

  document.getElementById("withdrawalModalFooter").innerHTML = `
        <button type="button" class="btn-wallet-secondary" onclick="closeWithdrawalModal()">Cancel</button>
        <button type="button" class="btn-wallet-submit" id="inquiryBtn" onclick="submitInquiry()">
            <i class="fas fa-arrow-right"></i> Continue
        </button>
    `;

  const acctInput = document.getElementById("inquiryAccountNumber");
  acctInput.addEventListener("input", () => {
    acctInput.value = acctInput.value.replace(/\D/g, "").slice(0, 10);
  });
}

async function verifySavedAndProceed() {
  const btn = document.getElementById("inquiryBtn");
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';

  try {
    const csrf = await getCsrfToken("tenant_withdrawal_form");
    const body = {
      bank_code: withdrawalState.bankCode,
      account_number: withdrawalState.accountNumber,
    };
    if (csrf) {
      body.csrf_token = csrf;
      body.token_id = "tenant_withdrawal_form";
    }

    const res = await fetch(TENANT_WALLET_API.inquiryBeneficiary, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
      credentials: "same-origin",
    });
    const data = await res.json();
    if (!data.success)
      throw new Error(data.message || "Account verification failed");

    withdrawalState.beneficiaryName = data.data.beneficiary_name;
    withdrawalState.maskedAccount = data.data.masked_account;
    withdrawalState.bankName = data.data.bank_name;
    withdrawalState.step = "amount";
    renderAmountStep();
  } catch (err) {
    showToast(err.message || "Account verification failed", "error");
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-arrow-right"></i> Continue';
  }
}

async function submitInquiry() {
  const bankCode = document.getElementById("inquiryBank").value;
  const accountNumber = document
    .getElementById("inquiryAccountNumber")
    .value.trim();

  if (!bankCode) return showToast("Select your bank", "error");
  if (!/^\d{10}$/.test(accountNumber))
    return showToast("Account number must be 10 digits", "error");

  const btn = document.getElementById("inquiryBtn");
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';

  try {
    const csrf = await getCsrfToken("tenant_withdrawal_form");
    const body = { bank_code: bankCode, account_number: accountNumber };
    if (csrf) {
      body.csrf_token = csrf;
      body.token_id = "tenant_withdrawal_form";
    }

    const res = await fetch(TENANT_WALLET_API.inquiryBeneficiary, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
      credentials: "same-origin",
    });
    const data = await res.json();
    if (!data.success) throw new Error(data.message || "Account lookup failed");

    withdrawalState.bankCode = bankCode;
    withdrawalState.bankName = data.data.bank_name;
    withdrawalState.accountNumber = accountNumber;
    withdrawalState.beneficiaryName = data.data.beneficiary_name;
    withdrawalState.maskedAccount = data.data.masked_account;
    withdrawalState.step = "amount";
    renderAmountStep();
  } catch (err) {
    showToast(err.message || "Account lookup failed", "error");
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-arrow-right"></i> Continue';
  }
}

function renderAmountStep() {
  document.getElementById("withdrawalModalTitle").innerHTML =
    '<i class="fas fa-user-check"></i> Enter Amount';

  document.getElementById("withdrawalModalBody").innerHTML = `
        <div class="bank-details-box">
            <div class="bank-label">Verified Beneficiary</div>
            <div style="font-weight:600;color:#1a1f36;font-size:15px;">${escapeHtml(withdrawalState.beneficiaryName)}</div>
            <div>${escapeHtml(withdrawalState.bankName)} &middot; ${escapeHtml(withdrawalState.maskedAccount)}</div>
        </div>

        <form id="amountForm" onsubmit="event.preventDefault(); submitAmount();">
            <div class="wallet-form-group">
                <label>Amount (₦) *</label>
                <input type="text" id="withdrawAmount" inputmode="decimal" placeholder="e.g. 50,000" required>
                <div class="hint">Minimum ₦1,000. Available: ${escapeHtml(walletData?.wallet_balance_formatted || "₦0.00")}</div>
            </div>
        </form>
    `;

  document.getElementById("withdrawalModalFooter").innerHTML = `
        <button type="button" class="btn-wallet-secondary" onclick="renderInquiryStep()">Back</button>
        <button type="button" class="btn-wallet-submit" onclick="submitAmount()">
            <i class="fas fa-arrow-right"></i> Continue
        </button>
    `;

  attachAmountInputFilter(document.getElementById("withdrawAmount"));
}

function submitAmount() {
  const raw = document.getElementById("withdrawAmount").value.trim();
  const amount = parseFloat(raw.replace(/,/g, ""));
  if (isNaN(amount) || amount <= 0)
    return showToast("Enter a valid amount", "error");
  if (amount < 1000) return showToast("Minimum withdrawal is ₦1,000", "error");

  const available = walletData?.wallet_balance ?? 0;
  if (amount > available) {
    return showToast(
      `Amount exceeds available balance (${walletData.wallet_balance_formatted})`,
      "error",
    );
  }

  withdrawalState.amount = amount;
  withdrawalState.step = "confirm";
  renderConfirmStep();
}

function renderConfirmStep() {
  document.getElementById("withdrawalModalTitle").innerHTML =
    '<i class="fas fa-shield-alt"></i> Confirm Transfer';

  const amt = "₦" + formatNumber(withdrawalState.amount);

  document.getElementById("withdrawalModalBody").innerHTML = `
        <p style="font-size:13px;color:#64748b;margin-bottom:16px;">
            You are about to transfer <strong style="color:#1a1f36;">${amt}</strong> to the account below.
            This amount will be deducted from your wallet immediately and held pending admin approval.
        </p>

        <div class="bank-details-box" style="border-left-color:#10b981;background:#ecfdf5;">
            <div class="bank-label" style="color:#047857;">Beneficiary</div>
            <div style="font-weight:600;color:#065f46;font-size:15px;">${escapeHtml(withdrawalState.beneficiaryName)}</div>
            <div style="color:#047857;">${escapeHtml(withdrawalState.bankName)}</div>
            <div style="color:#047857;">Account: ${escapeHtml(withdrawalState.maskedAccount)}</div>
        </div>

        <div style="background:#f8fafc;border-radius:8px;padding:12px 14px;margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;font-size:13px;color:#475569;">
                <span>Amount</span><strong style="color:#1a1f36;">${amt}</strong>
            </div>
        </div>

        <form id="confirmForm" onsubmit="event.preventDefault(); submitWithdrawal();">
            <div class="wallet-form-group">
                <label>Secret Answer *</label>
                <input type="password" id="withdrawSecretAnswer" required autocomplete="off">
                <div class="hint">Answer to your security question, used to authorize this transfer.</div>
            </div>
        </form>
    `;

  document.getElementById("withdrawalModalFooter").innerHTML = `
        <button type="button" class="btn-wallet-secondary" onclick="renderAmountStep()">Back</button>
        <button type="button" class="btn-wallet-submit" id="withdrawSubmitBtn" onclick="submitWithdrawal()">
            <i class="fas fa-paper-plane"></i> Transfer ${amt}
        </button>
    `;
}

async function submitWithdrawal() {
  const secretAns = document
    .getElementById("withdrawSecretAnswer")
    .value.trim();
  if (!secretAns) return showToast("Enter your secret answer", "error");

  const body = {
    amount: withdrawalState.amount,
    bank_code: withdrawalState.bankCode,
    account_number: withdrawalState.accountNumber,
    secret_answer: secretAns,
  };

  const csrf = await getCsrfToken("tenant_withdrawal_form");
  if (csrf) {
    body.csrf_token = csrf;
    body.token_id = "tenant_withdrawal_form";
  }

  const btn = document.getElementById("withdrawSubmitBtn");
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
  showLoader();

  const minDelay = new Promise((resolve) => setTimeout(resolve, 1000));

  try {
    const requestPromise = fetch(TENANT_WALLET_API.requestWithdrawal, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
      credentials: "same-origin",
    });
    const [res] = await Promise.all([requestPromise, minDelay]);
    const data = await res.json();
    if (!data.success) throw new Error(data.message || "Request failed");

    hideLoader();
    closeWithdrawalModal();
    showToast(
      "Transfer submitted. Admin will review and process shortly.",
      "success",
    );
    currentFilters.page = 1;
    loadWalletData();
  } catch (err) {
    await minDelay;
    hideLoader();
    showToast(err.message || "Failed to submit request", "error");
    btn.disabled = false;
    btn.innerHTML = `<i class="fas fa-paper-plane"></i> Transfer ₦${formatNumber(withdrawalState.amount)}`;
    const secretInput = document.getElementById("withdrawSecretAnswer");
    if (secretInput) {
      secretInput.value = "";
      secretInput.focus();
    }
  }
}

// Confirm Cancel Withdrawal
async function confirmCancelWithdrawal(requestCode, amount) {
    const confirmed = await showConfirmModal({
        title:       'Cancel Withdrawal?',
        tone:        'danger',
        confirmText: 'Yes, Cancel Withdrawal',
        cancelText:  'Keep Request',
        message: `
            <p style="margin:0 0 14px;color:#475569;font-size:14px;line-height:1.5;">
                You are about to cancel this withdrawal request. The funds will be returned
                to your wallet immediately.
            </p>
            <div style="background:#f8fafc;border-radius:8px;padding:14px 16px;">
                <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:13px;">
                    <span style="color:#64748b;">Amount</span>
                    <strong style="color:#1a1f36;">₦${formatNumber(amount)}</strong>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:13px;">
                    <span style="color:#64748b;">Reference</span>
                    <strong style="color:#1a1f36;font-family:monospace;font-size:12px;">
                        ${escapeHtml(requestCode)}
                    </strong>
                </div>
            </div>
            <p style="margin:14px 0 0;font-size:12px;color:#94a3b8;">
                <i class="fas fa-info-circle"></i>
                Once cancelled, you'll need to submit a new withdrawal request if you change your mind.
            </p>
        `,
    });

    if (!confirmed) return;

    showLoader();
    try {
        const csrf = await getCsrfToken('tenant_withdrawal_form');
        const body = { request_code: requestCode };
        if (csrf) { body.csrf_token = csrf; body.token_id = 'tenant_withdrawal_form'; }

        const res = await fetch(TENANT_WALLET_API.cancelWithdrawal, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
            credentials: 'same-origin',
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'Failed to cancel withdrawal');

        hideLoader();
        showToast(
            `Withdrawal cancelled. ₦${formatNumber(amount)} returned to your wallet.`,
            'success'
        );
        loadWalletData();
    } catch (err) {
        hideLoader();
        showToast(err.message || 'Failed to cancel withdrawal', 'error');
    }
}

// =========================================================
// CHANGE BANK FLOW
// =========================================================
async function openChangeBankModal() {
  changeBankState = {
    step: "form",
    bankCode: "",
    bankName: "",
    accountNumber: "",
    accountName: "",
    currentSecretAnswer: "",
  };
  document.getElementById("changeBankModal").classList.add("active");

  await ensureBanksCache();
  renderChangeBankForm();
}

function closeChangeBankModal() {
  document.getElementById("changeBankModal").classList.remove("active");
  changeBankState = {
    step: "form",
    bankCode: "",
    bankName: "",
    accountNumber: "",
    accountName: "",
    currentSecretAnswer: "",
  };
}

function renderChangeBankForm() {
  document.getElementById("changeBankModalTitle").innerHTML =
    '<i class="fas fa-exchange-alt"></i> Change Bank Account';

  const bankOptions = (banksCache || [])
    .map(
      (b) =>
        `<option value="${escapeHtml(b.code)}">${escapeHtml(b.name)}</option>`,
    )
    .join("");

  const currentBank = walletData?.bank_name
    ? `${escapeHtml(walletData.bank_name)} &middot; ${escapeHtml(walletData.bank_account_number_masked || "****")}`
    : "Not set";

  document.getElementById("changeBankModalBody").innerHTML = `
        <div class="bank-details-box" style="background:#fef3c7;border-left-color:#f59e0b;">
            <div class="bank-label" style="color:#92400e;">Current Bank Account</div>
            <div style="color:#78350f;">${currentBank}</div>
        </div>
        <p style="font-size:13px;color:#64748b;margin:12px 0;">
            Enter your new bank account. You must authorize this change with your secret answer.
            For your security, you cannot change your bank while a withdrawal is pending.
        </p>
        <form id="changeBankForm" onsubmit="event.preventDefault(); goToChangeConfirm();">
            <div class="wallet-form-group">
                <label>New Bank *</label>
                <select id="changeBank" required>
                    <option value="">Select your new bank</option>${bankOptions}
                </select>
            </div>
            <div class="wallet-form-group">
                <label>New Account Number *</label>
                <input type="text" id="changeAccountNumber" inputmode="numeric" maxlength="10"
                       pattern="\\d{10}" placeholder="10-digit account number" required>
            </div>
            <div class="wallet-form-group">
                <label>New Account Name *</label>
                <input type="text" id="changeAccountName" maxlength="100"
                       placeholder="Name as it appears on your bank statement" required>
            </div>
            <div class="wallet-form-group">
                <label>Current Secret Answer *</label>
                <input type="password" id="changeCurrentSecret" autocomplete="off" required>
                <div class="hint">Your current security answer, to prove it's really you.</div>
            </div>
        </form>
    `;

  document.getElementById("changeBankModalFooter").innerHTML = `
        <button type="button" class="btn-wallet-secondary" onclick="closeChangeBankModal()">Cancel</button>
        <button type="button" class="btn-wallet-submit" onclick="goToChangeConfirm()">
            Continue <i class="fas fa-arrow-right"></i>
        </button>
    `;

  const acctInput = document.getElementById("changeAccountNumber");
  acctInput.addEventListener("input", () => {
    acctInput.value = acctInput.value.replace(/\D/g, "").slice(0, 10);
  });

  attachAccountNameAutofill(
    document.getElementById("changeBank"),
    document.getElementById("changeAccountNumber"),
    document.getElementById("changeAccountName"),
  );
}

function goToChangeConfirm() {
  const bankCode = document.getElementById("changeBank").value;
  const accountNo = document.getElementById("changeAccountNumber").value.trim();
  const accountName = document.getElementById("changeAccountName").value.trim();
  const currentSecret = document
    .getElementById("changeCurrentSecret")
    .value.trim();

  if (!bankCode) return showToast("Select your new bank", "error");
  if (!/^\d{10}$/.test(accountNo))
    return showToast("Account number must be 10 digits", "error");
  if (!accountName) return showToast("Enter the account name", "error");
  if (!currentSecret)
    return showToast("Enter your current secret answer", "error");

  const selectedBank = (banksCache || []).find((b) => b.code === bankCode);
  if (!selectedBank) return showToast("Invalid bank selection", "error");

  changeBankState = {
    step: "confirm",
    bankCode,
    bankName: selectedBank.name,
    accountNumber: accountNo,
    accountName,
    currentSecretAnswer: currentSecret,
  };
  renderChangeBankConfirm();
}

function renderChangeBankConfirm() {
  document.getElementById("changeBankModalTitle").innerHTML =
    '<i class="fas fa-shield-alt"></i> Confirm Bank Change';

  const oldMasked =
    walletData?.bank_account_number_masked ||
    (walletData?.bank_account_number
      ? "******" + walletData.bank_account_number.slice(-4)
      : "—");
  const newMasked = "******" + changeBankState.accountNumber.slice(-4);

  document.getElementById("changeBankModalBody").innerHTML = `
        <p style="font-size:13px;color:#64748b;margin-bottom:16px;">
            Please confirm the change. Your new bank will be used for all future withdrawals.
        </p>
        <div style="background:#f8fafc;border-radius:8px;padding:14px 16px;margin-bottom:16px;">
            <div style="margin-bottom:12px;">
                <div style="font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;">From</div>
                <div style="font-weight:600;color:#1a1f36;">${escapeHtml(walletData?.bank_name || "—")} &middot; ${escapeHtml(oldMasked)}</div>
            </div>
            <div style="text-align:center;color:#94a3b8;margin:8px 0;">↓</div>
            <div>
                <div style="font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;">To</div>
                <div style="font-weight:600;color:#047857;font-size:15px;">${escapeHtml(changeBankState.bankName)} &middot; ${escapeHtml(newMasked)}</div>
                <div style="color:#475569;">${escapeHtml(changeBankState.accountName)}</div>
            </div>
        </div>
        <p style="font-size:12px;color:#94a3b8;">
            <i class="fas fa-info-circle"></i> You cannot change your bank again for 24 hours after this change.
        </p>
    `;

  document.getElementById("changeBankModalFooter").innerHTML = `
        <button type="button" class="btn-wallet-secondary" onclick="renderChangeBankForm()">Back</button>
        <button type="button" class="btn-wallet-submit" id="changeBankSubmitBtn" onclick="submitChangeBank()">
            <i class="fas fa-check"></i> Confirm Change
        </button>
    `;
}

async function submitChangeBank() {
  const btn = document.getElementById("changeBankSubmitBtn");
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
  showLoader();

  try {
    const csrf = await getCsrfToken("tenant_withdrawal_form");
    const body = {
      bank_name: changeBankState.bankName,
      bank_code: changeBankState.bankCode,
      account_name: changeBankState.accountName,
      account_number: changeBankState.accountNumber,
      secret_answer: changeBankState.currentSecretAnswer,
    };
    if (csrf) {
      body.csrf_token = csrf;
      body.token_id = "tenant_withdrawal_form";
    }

    const res = await fetch(TENANT_WALLET_API.changeBank, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
      credentials: "same-origin",
    });
    const data = await res.json();
    if (!data.success) throw new Error(data.message || "Failed to change bank");

    hideLoader();
    closeChangeBankModal();
    showToast("Bank account updated successfully", "success");
    loadWalletData();
  } catch (err) {
    hideLoader();
    showToast(err.message || "Failed to change bank", "error");
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-check"></i> Confirm Change';
  }
}

// =========================================================
// HELPERS
// =========================================================
function statusBadge(status) {
  const cls =
    {
      Approved: "status-pill approved",
      Pending: "status-pill pending",
      Rejected: "status-pill rejected",
      Failed: "status-pill cancelled",
    }[status] || "status-pill";
  return `<span class="${cls}">${escapeHtml(status || "—")}</span>`;
}

function outcomeLabel(t, rejectedRequestIds) {
  switch (t.type) {
    case "withdrawal":
    if (t.status === "Pending")  return "Awaiting approval";
    if (t.status === "Approved") return "Completed";
    if (t.status === "Rejected") {
        if (t.request_status === "cancelled") return "Cancelled → refunded";
        return "Rejected → refunded";
    }
    if (t.status === "Failed")   return "Failed";
    return "—";
    case "admin_adjustment":
    if (t.request_id && rejectedRequestIds.has(t.request_id)) {
        if (t.request_status === "cancelled") return "Reversal of cancelled withdrawal";
        if (t.request_status === "rejected")  return "Reversal of rejected withdrawal";
        return "Reversal of withdrawn request";
    }
    return "Admin adjustment";
    case "refund_credit":
      return "Refund credited";
    case "rent_credit":
      return "Rent credited";
    case "topup":
      return "Top-up credited";
    case "deposit_release":
      return "Deposit released";
    case "debt_recovery":
      return "Debt recovered";
    case "settlement_debit":
      return "Settlement debit";
    default:
      return "—";
  }
}

function humanizeType(t) {
  const map = {
    refund_credit: "Refund Credit",
    withdrawal: "Withdrawal",
    admin_adjustment: "Admin Adjustment",
    fee_debit: "Fee Debit",
    rent_credit: "Rent Credit",
    topup: "Top-up",
    deposit_release: "Deposit Release",
    debt_recovery: "Debt Recovery",
    settlement_debit: "Settlement Debit",
  };
  return map[t] || t;
}

function formatNumber(v) {
  return new Intl.NumberFormat("en-NG", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(v || 0);
}

function formatDateTime(str) {
  if (!str) return "—";
  const d = new Date(str);
  return (
    d.toLocaleDateString("en-US", {
      month: "short",
      day: "numeric",
      year: "numeric",
    }) +
    " " +
    d.toLocaleTimeString("en-US", { hour: "numeric", minute: "2-digit" })
  );
}

function escapeHtml(text) {
  if (text === null || text === undefined) return "";
  const div = document.createElement("div");
  div.textContent = String(text);
  return div.innerHTML;
}

function showToast(msg, type = "info") {
  const existing = document.querySelector(".wallet-toast");
  if (existing) existing.remove();
  const icons = {
    success: "fa-check-circle",
    error: "fa-exclamation-circle",
    info: "fa-info-circle",
  };
  const toast = document.createElement("div");
  toast.className = `wallet-toast ${type}`;
  toast.innerHTML = `<i class="fas ${icons[type] || icons.info}"></i><span>${escapeHtml(msg)}</span>`;
  document.body.appendChild(toast);
  setTimeout(() => toast.remove(), 4000);
}

function showLoader() {
  const l = document.getElementById("walletLoader");
  if (l) l.style.display = "flex";
}
function hideLoader() {
  const l = document.getElementById("walletLoader");
  if (l) l.style.display = "none";
}

async function getCsrfToken(formName) {
  try {
    const res = await fetch(
      `../backend/utilities/get_token.php?form=${encodeURIComponent(formName)}`,
      {
        method: "POST",
        credentials: "include",
      },
    );
    const data = await res.json();
    return data.success ? data.token : null;
  } catch (err) {
    console.error("CSRF token fetch failed:", err);
    return null;
  }
}

// Numeric-only filter for amount inputs (digits + one decimal, max 2 dp)
function attachAmountInputFilter(inputEl) {
  if (!inputEl) return;
  inputEl.addEventListener("input", () => {
    let v = inputEl.value;
    v = v.replace(/[^\d.]/g, "");
    const firstDot = v.indexOf(".");
    if (firstDot !== -1) {
      v = v.slice(0, firstDot + 1) + v.slice(firstDot + 1).replace(/\./g, "");
      const [whole, frac] = v.split(".");
      v = whole + "." + (frac || "").slice(0, 2);
    }
    if (v.length > 1 && v[0] === "0" && v[1] !== ".") {
      v = v.replace(/^0+/, "") || "0";
    }
    if (inputEl.value !== v) inputEl.value = v;
  });
  inputEl.addEventListener("blur", () => {
    if (inputEl.value.endsWith(".")) {
      inputEl.value = inputEl.value.slice(0, -1);
    }
  });
}

// Autofill account name from user identity when field is empty
function attachAccountNameAutofill(
  bankSelectEl,
  accountNumberEl,
  accountNameEl,
) {
  if (!bankSelectEl || !accountNumberEl || !accountNameEl) return;
  function autofillIfEmpty() {
    if (!accountNameEl.value.trim() && userIdentity?.full_name) {
      accountNameEl.value = userIdentity.full_name;
    }
  }
  bankSelectEl.addEventListener("change", autofillIfEmpty);
  accountNumberEl.addEventListener("blur", autofillIfEmpty);
}

/**
 * Show a custom confirm modal.
 *
 * Returns a Promise<boolean>:
 *   - true  when the user clicks the primary (confirm) button
 *   - false when the user cancels or closes the modal
 *
 * Options:
 *   - title:        string — modal heading (default: "Confirm")
 *   - message:      string | HTMLElement — body content (HTML supported but escape your own data)
 *   - confirmText:  string — label of the primary button (default: "Confirm")
 *   - cancelText:   string — label of the secondary button (default: "Cancel")
 *   - tone:         'danger' | 'warning' | 'info' (default: 'info') — colors the icon and confirm button
 *   - icon:         string — Font Awesome icon class (default derived from tone)
 */
function showConfirmModal(options = {}) {
    return new Promise((resolve) => {
        const {
            title       = 'Confirm',
            message     = '',
            confirmText = 'Confirm',
            cancelText  = 'Cancel',
            tone        = 'info',
            icon        = null,
        } = options;

        const icons = {
            danger:  'fa-exclamation-triangle',
            warning: 'fa-exclamation-circle',
            info:    'fa-question-circle',
            success: 'fa-check-circle',
        };

        const overlay = document.getElementById('confirmModal');
        if (!overlay) {
            // Fallback — should never happen if markup is deployed correctly
            console.warn('[showConfirmModal] Modal markup missing — falling back to native confirm()');
            resolve(window.confirm(typeof message === 'string' ? message : title));
            return;
        }

        const iconEl    = document.getElementById('confirmModalIcon');
        const titleEl   = document.getElementById('confirmModalTitle');
        const bodyEl    = document.getElementById('confirmModalBody');
        const cancelBtn = document.getElementById('confirmModalCancel');
        const confirmBtn= document.getElementById('confirmModalConfirm');

        // Set content
        iconEl.innerHTML  = `<i class="fas ${icon || icons[tone] || icons.info}"></i>`;
        iconEl.className  = `confirm-modal-icon ${tone}`;
        titleEl.textContent = title;

        if (typeof message === 'string') {
            bodyEl.innerHTML = message;
        } else {
            bodyEl.innerHTML = '';
            bodyEl.appendChild(message);
        }

        cancelBtn.textContent  = cancelText;
        confirmBtn.textContent = confirmText;
        confirmBtn.className   = `btn-wallet-submit confirm-btn-${tone}`;

        // Cleanup handler — removes listeners and closes modal
        function cleanup() {
            overlay.classList.remove('active');
            confirmBtn.removeEventListener('click', onConfirm);
            cancelBtn.removeEventListener('click', onCancel);
            overlay.removeEventListener('click', onOverlayClick);
            document.removeEventListener('keydown', onKeydown);
        }

        function onConfirm() { cleanup(); resolve(true);  }
        function onCancel()  { cleanup(); resolve(false); }
        function onOverlayClick(e) {
            // Only dismiss if the click was on the backdrop, not inside the modal
            if (e.target === overlay) onCancel();
        }
        function onKeydown(e) {
            if (e.key === 'Escape')     onCancel();
            if (e.key === 'Enter')      { e.preventDefault(); onConfirm(); }
        }

        // Wire handlers
        confirmBtn.addEventListener('click', onConfirm);
        cancelBtn.addEventListener('click', onCancel);
        overlay.addEventListener('click', onOverlayClick);
        document.addEventListener('keydown', onKeydown);

        // Show + focus
        overlay.classList.add('active');
        setTimeout(() => confirmBtn.focus(), 50);
    });
}