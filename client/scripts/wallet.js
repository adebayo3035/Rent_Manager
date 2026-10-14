// client/scripts/wallet.js

const CLIENT_WALLET_API = {
    getBalance:    '../backend/wallet/get_balance.php',
    topupInitiate: '../backend/wallet/topup_initiate.php',
    getRequests:   '../backend/wallet/get_requests.php',
};

const ADMIN_BANK_DETAILS = {
    bank_name:      'GTBank',
    account_name:   'RentEase Properties Ltd',
    account_number: '0123456789',
};

let walletData = null;
let activeTab  = 'transactions';

document.addEventListener('DOMContentLoaded', () => {
    renderWalletPage();
    loadWalletData();
});

// =========================================================
// PAGE SHELL
// =========================================================
function renderWalletPage() {
    const contentArea = document.getElementById('contentArea');
    if (!contentArea) return;

    contentArea.innerHTML = `
        <div class="wallet-container">
            <div class="wallet-page-header">
                <h1><i class="fas fa-wallet" style="color:#667eea;margin-right:8px;"></i> My Wallet</h1>
                <p>Track your earnings, request top-ups, and view your transaction history</p>
            </div>

            <div class="wallet-balance-card">
                <div class="balance-block">
                    <div class="label">Available Balance</div>
                    <div class="amount" id="walletBalance">₦0.00</div>
                    <div class="subtext" id="walletSubtext">Lifetime earned: ₦0.00</div>
                </div>
                <div class="balance-actions">
                    <button class="btn-wallet-primary" onclick="openTopupModal()">
                        <i class="fas fa-plus"></i> Top Up Wallet
                    </button>
                </div>
            </div>

            <div class="wallet-tabs">
                <button class="wallet-tab active" data-tab="transactions" onclick="switchWalletTab('transactions')">
                    <i class="fas fa-history"></i> Transactions
                </button>
                <button class="wallet-tab" data-tab="requests" onclick="switchWalletTab('requests')">
                    <i class="fas fa-file-invoice"></i> Requests
                </button>
            </div>

            <div id="walletTabContent">
                <div class="empty-state"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
            </div>
        </div>

        <!-- Top-up modal -->
        <div id="topupModal" class="wallet-modal">
            <div class="wallet-modal-content">
                <div class="wallet-modal-header">
                    <h3><i class="fas fa-plus-circle"></i> Top Up Wallet</h3>
                    <button class="wallet-modal-close" onclick="closeTopupModal()">&times;</button>
                </div>
                <div class="wallet-modal-body">
                    <div class="bank-details-box">
                        <div class="bank-label">Send payment to</div>
                        <strong>${ADMIN_BANK_DETAILS.bank_name}</strong><br>
                        Account Name: ${ADMIN_BANK_DETAILS.account_name}<br>
                        Account Number: <strong>${ADMIN_BANK_DETAILS.account_number}</strong>
                    </div>

                    <p style="font-size:13px; color:#64748b; margin-bottom:16px;">
                        Transfer the amount to the account above, then fill this form with the bank reference and upload a screenshot or PDF of the receipt.
                    </p>

                    <form id="topupForm" onsubmit="submitTopup(event)">
                        <div class="wallet-form-group">
                            <label>Amount (₦) *</label>
                            <input type="text" id="topupAmount" placeholder="e.g. 50,000" required>
                            <div class="hint">Minimum ₦1,000. Maximum ₦50,000,000.</div>
                        </div>

                        <div class="wallet-form-group">
                            <label>Bank Transaction Reference *</label>
                            <input type="text" id="topupReference" placeholder="e.g. TRF-20261013-ABC123" required>
                        </div>

                        <div class="wallet-form-group">
                            <label>Proof of Payment *</label>
                            <input type="file" id="topupProof" accept="image/jpeg,image/png,application/pdf" required>
                            <div class="hint">JPG, PNG, or PDF. Max 3 MB.</div>
                        </div>

                        <div class="wallet-form-group">
                            <label>Notes (optional)</label>
                            <textarea id="topupNotes" rows="2" placeholder="Any additional info for the admin"></textarea>
                        </div>
                    </form>
                </div>
                <div class="wallet-modal-footer">
                    <button type="button" class="btn-wallet-secondary" onclick="closeTopupModal()">Cancel</button>
                    <button type="button" class="btn-wallet-submit" id="topupSubmitBtn" onclick="submitTopup()">
                        <i class="fas fa-paper-plane"></i> Submit Request
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

// =========================================================
// DATA LOADING
// =========================================================
async function loadWalletData() {
    try {
        const res = await fetch(CLIENT_WALLET_API.getBalance, { credentials: 'same-origin' });
        const data = await res.json();

        if (!data.success) throw new Error(data.message || 'Failed to load wallet');
        walletData = data.data;

        document.getElementById('walletBalance').textContent = walletData.wallet_balance_formatted;
        document.getElementById('walletSubtext').textContent =
            `Lifetime earned: ₦${formatNumber(walletData.total_settlement_earned)}`;

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
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Balance After</th>
                        <th>Reference</th>
                    </tr>
                </thead>
                <tbody>
                    ${tx.map(t => `
                        <tr>
                            <td>${formatDateTime(t.created_at)}</td>
                            <td>${humanizeType(t.type)}</td>
                            <td class="${t.amount >= 0 ? 'tx-amount-credit' : 'tx-amount-debit'}">
                                ${t.amount >= 0 ? '+' : ''}${t.amount_formatted}
                            </td>
                            <td>₦${formatNumber(t.running_balance)}</td>
                            <td style="font-family:monospace;font-size:12px;color:#64748b;">${escapeHtml(t.request_id || '—')}</td>
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
        wrap.innerHTML = `<div class="empty-state"><i class="fas fa-file-invoice"></i><p>No pending requests</p></div>`;
        return;
    }

    wrap.innerHTML = `
        <div class="wallet-table-wrap">
            <table class="wallet-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Reference</th>
                    </tr>
                </thead>
                <tbody>
                    ${req.map(r => `
                        <tr>
                            <td>${formatDateTime(r.created_at)}</td>
                            <td>${r.type === 'topup' ? 'Top-Up' : 'Withdrawal'}</td>
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

// =========================================================
// TOP-UP
// =========================================================
function openTopupModal() {
    document.getElementById('topupModal').classList.add('active');
}
function closeTopupModal() {
    document.getElementById('topupModal').classList.remove('active');
    document.getElementById('topupForm').reset();
}

async function submitTopup() {
    const amountRaw = document.getElementById('topupAmount').value.trim();
    const reference = document.getElementById('topupReference').value.trim();
    const notes     = document.getElementById('topupNotes').value.trim();
    const fileInput = document.getElementById('topupProof');
    const proof     = fileInput.files[0];

    if (!amountRaw) return showToast('Enter an amount', 'error');
    if (!reference) return showToast('Enter the bank reference', 'error');
    if (!proof)     return showToast('Upload proof of payment', 'error');

    const amount = parseFloat(amountRaw.replace(/,/g, ''));
    if (isNaN(amount) || amount < 1000) return showToast('Minimum top-up is ₦1,000', 'error');

    const fd = new FormData();
    fd.append('amount', amountRaw);
    fd.append('reference_number', reference);
    fd.append('notes', notes);
    fd.append('proof', proof);

    // CSRF token — reuse your existing helper if you have one
    const csrf = await getCsrfToken('client_topup_form');
    if (csrf) {
        fd.append('csrf_token', csrf);
        fd.append('token_id', 'client_topup_form');
    }

    const btn = document.getElementById('topupSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    showLoader();

    try {
        const res = await fetch(CLIENT_WALLET_API.topupInitiate, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
        });
        const data = await res.json();

        if (!data.success) throw new Error(data.message || 'Request failed');

        hideLoader();
        closeTopupModal();
        showToast('Top-up request submitted. Admin will review it shortly.', 'success');
        loadWalletData();
    } catch (err) {
        hideLoader();
        console.error(err);
        showToast(err.message || 'Failed to submit request', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
    }
}

// =========================================================
// HELPERS
// =========================================================
function humanizeType(t) {
    const map = {
        rent_credit:       'Rent Share Credit',
        topup:             'Wallet Top-Up',
        settlement_debit:  'Evacuation Refund Debit',
        deposit_release:   'Deposit Released',
        withdrawal:        'Withdrawal',
        admin_adjustment:  'Admin Adjustment',
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

function showLoader() {
    const l = document.getElementById('walletLoader');
    if (l) l.style.display = 'flex';
}
function hideLoader() {
    const l = document.getElementById('walletLoader');
    if (l) l.style.display = 'none';
}

// CSRF helper — wire this to your existing implementation
// async function getCsrfToken(formName) {
//     // If your app stores the token on a meta tag, read it from there.
//     // Otherwise reuse whatever helper you already use elsewhere.
//     const meta = document.querySelector(`meta[name="csrf-${formName}"]`);
//     return meta ? meta.content : null;
// }

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