// tenant/scripts/wallet.js

const TENANT_WALLET_API = {
    getBalance:         '../backend/wallet/get_balance.php',
    getBanks:           '../backend/wallet/get_banks.php',
    inquiryBeneficiary: '../backend/wallet/inquiry_beneficiary.php',
    saveBank:           '../backend/wallet/save_bank_account.php',   // NEW
    requestWithdrawal:  '../backend/wallet/request_withdrawal.php',
};

let walletData = null;
let activeTab  = 'transactions';
let banksCache = null;

// Multi-step withdrawal state
let withdrawalState = {
    step: 'inquiry', // 'setup_bank' | 'inquiry' | 'amount' | 'confirm'
    bankCode:        '',
    bankName:        '',
    accountNumber:   '',
    beneficiaryName: '',
    maskedAccount:   '',
    amount:          0,
};

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
                    <h3 id="withdrawalModalTitle"><i class="fas fa-money-bill-wave"></i> Withdraw Funds</h3>
                    <button class="wallet-modal-close" onclick="closeWithdrawalModal()">&times;</button>
                </div>
                <div class="wallet-modal-body" id="withdrawalModalBody"></div>
                <div class="wallet-modal-footer" id="withdrawalModalFooter"></div>
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

    // Build an index: for each request_id, note whether it has a rejected
    // withdrawal row. Used to classify admin_adjustment rows as reversals.
    const rejectedRequestIds = new Set();
    for (const t of tx) {
        if (t.type === 'withdrawal' && t.status === 'Rejected' && t.request_id) {
            rejectedRequestIds.add(t.request_id);
        }
    }

    wrap.innerHTML = `
        <div class="wallet-table-wrap">
            <table class="wallet-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Request ID</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Balance After</th>
                        <th>Status</th>
                        <th>Outcome</th>
                    </tr>
                </thead>
                <tbody>
                    ${tx.map(t => `
                        <tr>
                            <td>${formatDateTime(t.created_at)}</td>
                            <td>${(t.request_id)}</td>
                            <td>${humanizeType(t.type)}</td>
                            <td class="${t.amount >= 0 ? "tx-amount-credit" : "tx-amount-debit"}">
                                ${t.amount >= 0 ? "+" : ""}${t.amount_formatted}
                            </td>
                            <td>₦${formatNumber(t.running_balance)}</td>
                            <td>${statusBadge(t.status)}</td>
                            <td>${outcomeLabel(t, rejectedRequestIds)}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        </div>
    `;
}

function statusBadge(status) {
    const cls = {
        'Approved': 'status-pill approved',
        'Pending':  'status-pill pending',
        'Rejected': 'status-pill rejected',
        'Failed':   'status-pill cancelled',   // reuse existing pill class
    }[status] || 'status-pill';
    return `<span class="${cls}">${escapeHtml(status || '—')}</span>`;
}

function outcomeLabel(t, rejectedRequestIds) {
    // What does THIS row mean in the lifecycle of the request it belongs to?
    switch (t.type) {
        case 'withdrawal':
            if (t.status === 'Pending')  return 'Awaiting approval';
            if (t.status === 'Approved') return 'Completed';
            if (t.status === 'Rejected') return 'Rejected → refunded';
            if (t.status === 'Failed')   return 'Failed';
            return '—';

        case 'admin_adjustment':
            // Is this a reversal of a rejected withdrawal?
            if (t.request_id && rejectedRequestIds.has(t.request_id)) {
                return 'Reversal of rejected withdrawal';
            }
            return 'Admin adjustment';

        case 'refund_credit':    return 'Refund credited';
        case 'rent_credit':      return 'Rent credited';
        case 'topup':            return 'Top-up credited';
        case 'deposit_release':  return 'Deposit released';
        case 'debt_recovery':    return 'Debt recovered';
        case 'settlement_debit': return 'Settlement debit';

        default:
            return '—';
    }
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

/* ============================================================
   WITHDRAWAL FLOW
   ============================================================ */

async function openWithdrawalModal() {
    withdrawalState = {
        step: 'inquiry',
        bankCode: '', bankName: '',
        accountNumber: '', beneficiaryName: '',
        maskedAccount: '', amount: 0,
    };
    document.getElementById('withdrawalModal').classList.add('active');

    if (!banksCache) {
        try {
            const res = await fetch(TENANT_WALLET_API.getBanks, { credentials: 'same-origin' });
            const data = await res.json();
            if (data.success) banksCache = data.data.banks;
        } catch (_) { /* silent — form will show empty bank list */ }
    }

    // If no bank on file, force the setup step first
    if (!walletData?.has_bank_account) {
        withdrawalState.step = 'setup_bank';
        renderSetupBankStep();
        return;
    }

    // Otherwise, pre-fill the saved bank and run normal inquiry
    withdrawalState.bankCode      = walletData.bank_code || '';
    withdrawalState.bankName      = walletData.bank_name || '';
    withdrawalState.accountNumber = walletData.bank_account_number || '';
    withdrawalState.beneficiaryName = walletData.bank_account_name || '';

    renderInquiryStep();
}
function renderSetupBankStep() {
    document.getElementById('withdrawalModalTitle').innerHTML =
        '<i class="fas fa-university"></i> Add Your Bank Account';

    const bankOptions = (banksCache || []).map(b =>
        `<option value="${escapeHtml(b.code)}">${escapeHtml(b.name)}</option>`
    ).join('');

    document.getElementById('withdrawalModalBody').innerHTML = `
        <p style="font-size:13px;color:#64748b;margin-bottom:16px;">
            Before you can withdraw funds, add the bank account that should receive your transfers.
            You'll need to authorize this with your secret answer.
        </p>

        <form id="setupBankForm" onsubmit="submitSetupBank(event)">
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

    document.getElementById('withdrawalModalFooter').innerHTML = `
        <button type="button" class="btn-wallet-secondary" onclick="closeWithdrawalModal()">Cancel</button>
        <button type="button" class="btn-wallet-submit" id="setupBankBtn" onclick="submitSetupBank()">
            <i class="fas fa-save"></i> Save &amp; Continue
        </button>
    `;

    // Live digits-only truncation
    const acctInput = document.getElementById('setupAccountNumber');
    acctInput.addEventListener('input', () => {
        acctInput.value = acctInput.value.replace(/\D/g, '').slice(0, 10);
    });
}

async function submitSetupBank() {
    const bankCode    = document.getElementById('setupBank').value;
    const accountNo   = document.getElementById('setupAccountNumber').value.trim();
    const accountName = document.getElementById('setupAccountName').value.trim();
    const secretAns   = document.getElementById('setupSecretAnswer').value.trim();

    if (!bankCode)                         return showToast('Select your bank', 'error');
    if (!/^\d{10}$/.test(accountNo))       return showToast('Account number must be 10 digits', 'error');
    if (!accountName)                      return showToast('Enter the account name', 'error');
    if (!secretAns)                        return showToast('Enter your secret answer', 'error');

    const selectedBank = (banksCache || []).find(b => b.code === bankCode);
    if (!selectedBank) return showToast('Invalid bank selection', 'error');

    const btn = document.getElementById('setupBankBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    showLoader();

    try {
        const csrf = await getCsrfToken('tenant_withdrawal_form');
        const body = {
            bank_name:     selectedBank.name,
            bank_code:     bankCode,
            account_name:  accountName,
            account_number: accountNo,
            secret_answer: secretAns,
        };
        if (csrf) { body.csrf_token = csrf; body.token_id = 'tenant_withdrawal_form'; }

        const res = await fetch(TENANT_WALLET_API.saveBank, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
            credentials: 'same-origin',
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'Failed to save bank account');

        // Refresh walletData so has_bank_account flips to true and bank fields
        // are populated for the withdrawal flow
        await loadWalletData();

        // Now transition to normal inquiry flow, pre-filled
        withdrawalState.step            = 'inquiry';
        withdrawalState.bankCode        = data.data.bank_code;
        withdrawalState.bankName        = data.data.bank_name;
        withdrawalState.accountNumber   = data.data.bank_account_number;
        withdrawalState.beneficiaryName = data.data.bank_account_name;
        withdrawalState.maskedAccount   = data.data.masked_account;

        hideLoader();
        showToast('Bank account saved', 'success');
        renderInquiryStep();   // user can now proceed with withdrawal
    } catch (err) {
        hideLoader();
        showToast(err.message || 'Failed to save bank account', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save &amp; Continue';
    }
}

function renderSetupBankStep() {
    document.getElementById('withdrawalModalTitle').innerHTML =
        '<i class="fas fa-university"></i> Add Your Bank Account';

    const bankOptions = (banksCache || []).map(b =>
        `<option value="${escapeHtml(b.code)}">${escapeHtml(b.name)}</option>`
    ).join('');

    document.getElementById('withdrawalModalBody').innerHTML = `
        <p style="font-size:13px;color:#64748b;margin-bottom:16px;">
            Before you can withdraw funds, add the bank account that should receive your transfers.
            You'll need to authorize this with your secret answer.
        </p>

        <form id="setupBankForm" onsubmit="submitSetupBank(event)">
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

    document.getElementById('withdrawalModalFooter').innerHTML = `
        <button type="button" class="btn-wallet-secondary" onclick="closeWithdrawalModal()">Cancel</button>
        <button type="button" class="btn-wallet-submit" id="setupBankBtn" onclick="submitSetupBank()">
            <i class="fas fa-save"></i> Save &amp; Continue
        </button>
    `;

    // Live digits-only truncation
    const acctInput = document.getElementById('setupAccountNumber');
    acctInput.addEventListener('input', () => {
        acctInput.value = acctInput.value.replace(/\D/g, '').slice(0, 10);
    });
}

async function submitSetupBank() {
    const bankCode    = document.getElementById('setupBank').value;
    const accountNo   = document.getElementById('setupAccountNumber').value.trim();
    const accountName = document.getElementById('setupAccountName').value.trim();
    const secretAns   = document.getElementById('setupSecretAnswer').value.trim();

    if (!bankCode)                         return showToast('Select your bank', 'error');
    if (!/^\d{10}$/.test(accountNo))       return showToast('Account number must be 10 digits', 'error');
    if (!accountName)                      return showToast('Enter the account name', 'error');
    if (!secretAns)                        return showToast('Enter your secret answer', 'error');

    const selectedBank = (banksCache || []).find(b => b.code === bankCode);
    if (!selectedBank) return showToast('Invalid bank selection', 'error');

    const btn = document.getElementById('setupBankBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    showLoader();

    try {
        const csrf = await getCsrfToken('tenant_withdrawal_form');
        const body = {
            bank_name:     selectedBank.name,
            bank_code:     bankCode,
            account_name:  accountName,
            account_number: accountNo,
            secret_answer: secretAns,
        };
        if (csrf) { body.csrf_token = csrf; body.token_id = 'tenant_withdrawal_form'; }

        const res = await fetch(TENANT_WALLET_API.saveBank, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
            credentials: 'same-origin',
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'Failed to save bank account');

        // Refresh walletData so has_bank_account flips to true and bank fields
        // are populated for the withdrawal flow
        await loadWalletData();

        // Now transition to normal inquiry flow, pre-filled
        withdrawalState.step            = 'inquiry';
        withdrawalState.bankCode        = data.data.bank_code;
        withdrawalState.bankName        = data.data.bank_name;
        withdrawalState.accountNumber   = data.data.bank_account_number;
        withdrawalState.beneficiaryName = data.data.bank_account_name;
        withdrawalState.maskedAccount   = data.data.masked_account;

        hideLoader();
        showToast('Bank account saved', 'success');
        renderInquiryStep();   // user can now proceed with withdrawal
    } catch (err) {
        hideLoader();
        showToast(err.message || 'Failed to save bank account', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save &amp; Continue';
    }
}

function closeWithdrawalModal() {
    document.getElementById('withdrawalModal').classList.remove('active');
    // Wipe state so a reopened modal starts fresh
    withdrawalState = { step: 'inquiry', bankCode: '', bankName: '', accountNumber: '', beneficiaryName: '', maskedAccount: '', amount: 0 };
}

function renderInquiryStep() {
    document.getElementById('withdrawalModalTitle').innerHTML =
        '<i class="fas fa-search"></i> Verify Beneficiary';

    const hasSaved = !!withdrawalState.bankCode && !!withdrawalState.accountNumber;

    if (hasSaved) {
        // Show the saved beneficiary and skip manual entry
        document.getElementById('withdrawalModalBody').innerHTML = `
            <p style="font-size:13px;color:#64748b;margin-bottom:16px;">
                You're withdrawing to your saved bank account. Confirm the beneficiary below.
            </p>
            <div class="bank-details-box">
                <div class="bank-label">Beneficiary</div>
                <div style="font-weight:600;color:#1a1f36;font-size:15px;">${escapeHtml(withdrawalState.beneficiaryName || '—')}</div>
                <div>${escapeHtml(withdrawalState.bankName)} &middot; ${escapeHtml(withdrawalState.maskedAccount || ('******' + withdrawalState.accountNumber.slice(-4)))}</div>
            </div>
            <p style="font-size:12px;color:#94a3b8;margin-top:8px;">
                To change your bank account, contact support.
            </p>
        `;
        document.getElementById('withdrawalModalFooter').innerHTML = `
            <button type="button" class="btn-wallet-secondary" onclick="closeWithdrawalModal()">Cancel</button>
            <button type="button" class="btn-wallet-submit" id="inquiryBtn" onclick="continueToAmountFromSaved()">
                <i class="fas fa-arrow-right"></i> Continue
            </button>
        `;
        return;
    }

    // Fallback: manual input (kept for legacy / edge cases)
    const bankOptions = (banksCache || []).map(b =>
        `<option value="${escapeHtml(b.code)}" ${withdrawalState.bankCode === b.code ? 'selected' : ''}>${escapeHtml(b.name)}</option>`
    ).join('');

    document.getElementById('withdrawalModalBody').innerHTML = `
        <p style="font-size:13px;color:#64748b;margin-bottom:16px;">
            Enter your bank details. We'll verify the account holder's name before you continue.
        </p>
        <form id="inquiryForm" onsubmit="submitInquiry(event)">
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

    document.getElementById('withdrawalModalFooter').innerHTML = `
        <button type="button" class="btn-wallet-secondary" onclick="closeWithdrawalModal()">Cancel</button>
        <button type="button" class="btn-wallet-submit" id="inquiryBtn" onclick="submitInquiry()">
            <i class="fas fa-arrow-right"></i> Continue
        </button>
    `;

    const acctInput = document.getElementById('inquiryAccountNumber');
    acctInput.addEventListener('input', () => {
        acctInput.value = acctInput.value.replace(/\D/g, '').slice(0, 10);
    });
}

// Skip inquiry since we already trust the saved bank
function continueToAmountFromSaved() {
    if (!withdrawalState.bankCode || !withdrawalState.accountNumber) {
        return showToast('Bank account missing. Please set one up.', 'error');
    }
    // Verify one more time with the backend to be sure the account is still on file
    // (belt and braces — the backend will reject anyway, but this gives a clean UX)
    verifySavedAndProceed();
}

async function verifySavedAndProceed() {
    const btn = document.getElementById('inquiryBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';

    try {
        const csrf = await getCsrfToken('tenant_withdrawal_form');
        const body = {
            bank_code:      withdrawalState.bankCode,
            account_number: withdrawalState.accountNumber,
        };
        if (csrf) { body.csrf_token = csrf; body.token_id = 'tenant_withdrawal_form'; }

        const res = await fetch(TENANT_WALLET_API.inquiryBeneficiary, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
            credentials: 'same-origin',
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'Account verification failed');

        // Refresh beneficiary data from backend truth
        withdrawalState.beneficiaryName = data.data.beneficiary_name;
        withdrawalState.maskedAccount   = data.data.masked_account;
        withdrawalState.bankName        = data.data.bank_name;

        withdrawalState.step = 'amount';
        renderAmountStep();
    } catch (err) {
        showToast(err.message || 'Account verification failed', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-arrow-right"></i> Continue';
    }
}

async function submitInquiry() {
    const bankCode = document.getElementById('inquiryBank').value;
    const accountNumber = document.getElementById('inquiryAccountNumber').value.trim();

    if (!bankCode) return showToast('Select your bank', 'error');
    if (!/^\d{10}$/.test(accountNumber)) return showToast('Account number must be 10 digits', 'error');

    const btn = document.getElementById('inquiryBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';

    try {
        const csrf = await getCsrfToken('tenant_withdrawal_form');
        const body = { bank_code: bankCode, account_number: accountNumber };
        if (csrf) { body.csrf_token = csrf; body.token_id = 'tenant_withdrawal_form'; }

        const res = await fetch(TENANT_WALLET_API.inquiryBeneficiary, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
            credentials: 'same-origin',
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'Account lookup failed');

        withdrawalState.bankCode        = bankCode;
        withdrawalState.bankName        = data.data.bank_name;
        withdrawalState.accountNumber   = accountNumber;
        withdrawalState.beneficiaryName = data.data.beneficiary_name;
        withdrawalState.maskedAccount   = data.data.masked_account;
        withdrawalState.step            = 'amount';

        renderAmountStep();
    } catch (err) {
        showToast(err.message || 'Account lookup failed', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-arrow-right"></i> Continue';
    }
}

function renderAmountStep() {
    document.getElementById('withdrawalModalTitle').innerHTML =
        '<i class="fas fa-user-check"></i> Enter Amount';

    document.getElementById('withdrawalModalBody').innerHTML = `
        <div class="bank-details-box">
            <div class="bank-label">Verified Beneficiary</div>
            <div style="font-weight:600;color:#1a1f36;font-size:15px;">${escapeHtml(withdrawalState.beneficiaryName)}</div>
            <div>${escapeHtml(withdrawalState.bankName)} &middot; ${escapeHtml(withdrawalState.maskedAccount)}</div>
        </div>

        <form id="amountForm" onsubmit="submitAmount(event)">
            <div class="wallet-form-group">
                <label>Amount (₦) *</label>
                <input type="text" id="withdrawAmount" inputmode="decimal" placeholder="e.g. 50,000" required>
                <div class="hint">Minimum ₦1,000. Available: ${escapeHtml(walletData?.wallet_balance_formatted || '₦0.00')}</div>
            </div>
        </form>
    `;

    document.getElementById('withdrawalModalFooter').innerHTML = `
        <button type="button" class="btn-wallet-secondary" onclick="renderInquiryStep()">Back</button>
        <button type="button" class="btn-wallet-submit" onclick="submitAmount()">
            <i class="fas fa-arrow-right"></i> Continue
        </button>
    `;
}

function submitAmount() {
    const raw = document.getElementById('withdrawAmount').value.trim();
    const amount = parseFloat(raw.replace(/,/g, ''));
    if (isNaN(amount) || amount <= 0) return showToast('Enter a valid amount', 'error');
    if (amount < 1000) return showToast('Minimum withdrawal is ₦1,000', 'error');

    const available = walletData?.wallet_balance ?? 0;
    if (amount > available) {
        return showToast(`Amount exceeds available balance (${walletData.wallet_balance_formatted})`, 'error');
    }

    withdrawalState.amount = amount;
    withdrawalState.step = 'confirm';
    renderConfirmStep();
}

function renderConfirmStep() {
    document.getElementById('withdrawalModalTitle').innerHTML =
        '<i class="fas fa-shield-alt"></i> Confirm Transfer';

    const amt = '₦' + formatNumber(withdrawalState.amount);
    console.log("beneficiary name is: " + withdrawalState.beneficiaryName);
     console.log("Bank Name is: " + withdrawalState.bankName);
         console.log("Account number is: " + withdrawalState.maskedAccount);

    document.getElementById('withdrawalModalBody').innerHTML = `
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

        <form id="confirmForm" onsubmit="submitWithdrawal(event)">
            <div class="wallet-form-group">
                <label>Secret Answer *</label>
                <input type="password" id="withdrawSecretAnswer" required autocomplete="off">
                <div class="hint">Answer to your security question, used to authorize this transfer.</div>
            </div>
        </form>
    `;

    document.getElementById('withdrawalModalFooter').innerHTML = `
        <button type="button" class="btn-wallet-secondary" onclick="renderAmountStep()">Back</button>
        <button type="button" class="btn-wallet-submit" id="withdrawSubmitBtn" onclick="submitWithdrawal()">
            <i class="fas fa-paper-plane"></i> Transfer ${amt}
        </button>
    `;
}

async function submitWithdrawal() {
    const secretAns = document.getElementById('withdrawSecretAnswer').value.trim();
    if (!secretAns) return showToast('Enter your secret answer', 'error');

    // Client-side guard: never send bank/account name. Backend re-resolves.
    const body = {
        amount:         withdrawalState.amount,
        bank_code:      withdrawalState.bankCode,
        account_number: withdrawalState.accountNumber,
        secret_answer:  secretAns,
    };

    const csrf = await getCsrfToken('tenant_withdrawal_form');
    if (csrf) { body.csrf_token = csrf; body.token_id = 'tenant_withdrawal_form'; }

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
        showToast('Transfer submitted. Admin will review and process shortly.', 'success');
        loadWalletData();
    } catch (err) {
        hideLoader();
        showToast(err.message || 'Failed to submit request', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = `<i class="fas fa-paper-plane"></i> Transfer ₦${formatNumber(withdrawalState.amount)}`;
    }
}

/* ============================================================
   HELPERS (unchanged)
   ============================================================ */

function humanizeType(t) {
    const map = {
        refund_credit: 'Refund Credit',
        withdrawal: 'Withdrawal',
        admin_adjustment: 'Admin Adjustment',
        fee_debit: 'Fee Debit',
        rent_credit: 'Rent Credit',
        topup: 'Top-up',
        deposit_release: 'Deposit Release',
        debt_recovery: 'Debt Recovery',
        settlement_debit: 'Settlement Debit',
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

async function getCsrfToken(formName) {
  try {
    const res = await fetch(`../backend/utilities/get_token.php?form=${encodeURIComponent(formName)}`, {
        method: 'POST',
        credentials: 'include',
    });
    const data = await res.json();
    return data.success ? data.token : null;
  } catch (err) {
    console.error('CSRF token fetch failed:', err);
    return null;
  }
}