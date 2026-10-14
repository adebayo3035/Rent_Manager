// tenant/scripts/wallet.js

const TENANT_WALLET_API = {
    getBalance:        '../backend/wallet/get_balance.php',
    requestWithdrawal: '../backend/wallet/request_withdrawal.php',
};

let walletData = null;
let activeTab  = 'transactions';

document.addEventListener('DOMContentLoaded', () => {
    renderWalletPage();
    loadWalletData();
});

function renderWalletPage() {
    const contentArea = document.getElementById('contentArea');
    if (!contentArea) return;

    contentArea.innerHTML = `
        <div class="wallet-container">
            <div class="wallet-page-header">
                <h1><i class="fas fa-wallet" style="color:#667eea;margin-right:8px;"></i> My Wallet</h1>
                <p>Your refunds and credits. Request a withdrawal anytime.</p>
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

            <div class="wallet-tabs">
                <button class="wallet-tab active" data-tab="transactions" onclick="switchWalletTab('transactions')">
                    <i class="fas fa-history"></i> Transactions
                </button>
                <button class="wallet-tab" data-tab="requests" onclick="switchWalletTab('requests')">
                    <i class="fas fa-file-invoice"></i> Withdrawals
                </button>
            </div>

            <div id="walletTabContent"></div>
        </div>

        <!-- Withdrawal modal -->
        <div id="withdrawalModal" class="wallet-modal">
            <div class="wallet-modal-content">
                <div class="wallet-modal-header">
                    <h3><i class="fas fa-money-bill-wave"></i> Withdraw Funds</h3>
                    <button class="wallet-modal-close" onclick="closeWithdrawalModal()">&times;</button>
                </div>
                <div class="wallet-modal-body">
                    <p style="font-size:13px;color:#64748b;margin-bottom:16px;">
                        Funds will be transferred to your bank account. Requests are usually processed within 1-2 business days.
                    </p>

                    <form id="withdrawalForm" onsubmit="submitWithdrawal(event)">
                        <div class="wallet-form-group">
                            <label>Amount (₦) *</label>
                            <input type="text" id="withdrawAmount" placeholder="e.g. 50,000" required>
                            <div class="hint">Minimum ₦1,000. Maximum: your wallet balance.</div>
                        </div>

                        <div class="wallet-form-group">
                            <label>Bank Name *</label>
                            <input type="text" id="withdrawBankName" required>
                        </div>

                        <div class="wallet-form-group">
                            <label>Account Name *</label>
                            <input type="text" id="withdrawAccountName" required>
                        </div>

                        <div class="wallet-form-group">
                            <label>Account Number *</label>
                            <input type="text" id="withdrawAccountNumber" maxlength="10" pattern="\\d{10}" required>
                            <div class="hint">10 digits, no spaces.</div>
                        </div>

                        <div class="wallet-form-group">
                            <label>Secret Answer *</label>
                            <input type="password" id="withdrawSecretAnswer" required>
                            <div class="hint">Answer to your secret question, for authorization.</div>
                        </div>
                    </form>
                </div>
                <div class="wallet-modal-footer">
                    <button type="button" class="btn-wallet-secondary" onclick="closeWithdrawalModal()">Cancel</button>
                    <button type="button" class="btn-wallet-submit" id="withdrawSubmitBtn" onclick="submitWithdrawal()">
                        <i class="fas fa-paper-plane"></i> Request Withdrawal
                    </button>
                </div>
            </div>
        </div>

        <div id="walletLoader" class="wallet-loader-overlay" style="display:none;">
            <div class="wallet-loader-spinner"></div>
            <div style="color:#475569;font-size:14px;">Processing...</div>
        </div>
    `;
}

async function loadWalletData() {
    try {
        const res = await fetch(TENANT_WALLET_API.getBalance, { credentials: 'same-origin' });
        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'Failed to load wallet');

        walletData = data.data;
        document.getElementById('walletBalance').textContent = walletData.wallet_balance_formatted;

        // Pre-fill bank fields if saved
        if (walletData.bank_name)              document.getElementById('withdrawBankName').value = walletData.bank_name;
        if (walletData.bank_account_name)      document.getElementById('withdrawAccountName').value = walletData.bank_account_name;
        if (walletData.bank_account_number)    document.getElementById('withdrawAccountNumber').value = walletData.bank_account_number;

        renderActiveTab();
    } catch (err) {
        console.error(err);
        showToast(err.message || 'Failed to load wallet', 'error');
    }
}

function switchWalletTab(tab) {
    activeTab = tab;
    document.querySelectorAll('.wallet-tab').forEach(el =>
        el.classList.toggle('active', el.dataset.tab === tab)
    );
    renderActiveTab();
}

function renderActiveTab() {
    if (!walletData) return;
    if (activeTab === 'transactions') renderTransactions();
    else renderRequests();
}

function renderTransactions() {
    const tx = walletData.transactions || [];
    const wrap = document.getElementById('walletTabContent');
    if (!tx.length) {
        wrap.innerHTML = `<div class="empty-state"><i class="fas fa-receipt"></i><p>No transactions yet</p></div>`;
        return;
    }
    wrap.innerHTML = `
        <div class="wallet-table-wrap">
            <table class="wallet-table">
                <thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Balance After</th></tr></thead>
                <tbody>
                    ${tx.map(t => `
                        <tr>
                            <td>${formatDateTime(t.created_at)}</td>
                            <td>${humanizeType(t.type)}</td>
                            <td class="${t.amount >= 0 ? 'tx-amount-credit' : 'tx-amount-debit'}">
                                ${t.amount >= 0 ? '+' : ''}${t.amount_formatted}
                            </td>
                            <td>₦${formatNumber(t.running_balance)}</td>
                        </tr>
                    `).join('')}
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
            <table class="wallet-table">
                <thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Status</th><th>Reference</th></tr></thead>
                <tbody>
                    ${req.map(r => `
                        <tr>
                            <td>${formatDateTime(r.created_at)}</td>
                            <td>Withdrawal</td>
                            <td>${r.amount_formatted}</td>
                            <td><span class="status-pill ${r.status}">${r.status}</span></td>
                            <td style="font-family:monospace;font-size:12px;color:#64748b;">${escapeHtml(r.request_code)}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        </div>
    `;
}

function openWithdrawalModal() {
    document.getElementById('withdrawalModal').classList.add('active');
}
function closeWithdrawalModal() {
    document.getElementById('withdrawalModal').classList.remove('active');
}

async function submitWithdrawal() {
    const amountRaw   = document.getElementById('withdrawAmount').value.trim();
    const bankName    = document.getElementById('withdrawBankName').value.trim();
    const accountName = document.getElementById('withdrawAccountName').value.trim();
    const accountNo   = document.getElementById('withdrawAccountNumber').value.trim();
    const secretAns   = document.getElementById('withdrawSecretAnswer').value.trim();

    if (!amountRaw) return showToast('Enter an amount', 'error');
    if (!bankName) return showToast('Enter bank name', 'error');
    if (!accountName) return showToast('Enter account name', 'error');
    if (!/^\d{10}$/.test(accountNo)) return showToast('Account number must be 10 digits', 'error');
    if (!secretAns) return showToast('Enter your secret answer', 'error');

    const amount = parseFloat(amountRaw.replace(/,/g, ''));
    if (isNaN(amount) || amount < 1000) return showToast('Minimum withdrawal is ₦1,000', 'error');

    const body = {
        amount: amountRaw,
        bank_name: bankName,
        bank_account_name: accountName,
        bank_account_number: accountNo,
        secret_answer: secretAns,
    };

    const csrf = await getCsrfToken('tenant_withdrawal_form');
    if (csrf) {
        body.csrf_token = csrf;
        body.token_id = 'tenant_withdrawal_form';
    }

    const btn = document.getElementById('withdrawSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    showLoader();

    try {
        const res = await fetch(TENANT_WALLET_API.requestWithdrawal, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
            credentials: 'same-origin',
        });
        const data = await res.json();

        if (!data.success) throw new Error(data.message || 'Request failed');

        hideLoader();
        closeWithdrawalModal();
        document.getElementById('withdrawSecretAnswer').value = '';
        showToast('Withdrawal request submitted. Admin will process it shortly.', 'success');
        loadWalletData();
    } catch (err) {
        hideLoader();
        console.error(err);
        showToast(err.message || 'Failed to submit request', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Request Withdrawal';
    }
}

function humanizeType(t) {
    const map = {
        refund_credit:    'Refund Credit',
        withdrawal:       'Withdrawal',
        admin_adjustment: 'Admin Adjustment',
        fee_debit:        'Fee Debit',
    };
    return map[t] || t;
}

function formatNumber(v) {
    return new Intl.NumberFormat('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v || 0);
}
function formatDateTime(str) {
    if (!str) return '—';
    const d = new Date(str);
    return d.toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' })
        + ' ' + d.toLocaleTimeString('en-US', { hour:'numeric', minute:'2-digit' });
}
function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}
function showToast(msg, type = 'info') {
    const existing = document.querySelector('.wallet-toast');
    if (existing) existing.remove();
    const icons = { success:'fa-check-circle', error:'fa-exclamation-circle', info:'fa-info-circle' };
    const toast = document.createElement('div');
    toast.className = `wallet-toast ${type}`;
    toast.innerHTML = `<i class="fas ${icons[type] || icons.info}"></i><span>${escapeHtml(msg)}</span>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 4000);
}
function showLoader() { const l = document.getElementById('walletLoader'); if (l) l.style.display = 'flex'; }
function hideLoader() { const l = document.getElementById('walletLoader'); if (l) l.style.display = 'none'; }

async function getCsrfToken (formName) {
    try {
      const res = await fetch(
        `../backend/utilities/get_token.php?form=${formName}`,
        {
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