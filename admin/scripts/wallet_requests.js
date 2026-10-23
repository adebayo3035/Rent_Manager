// admin/scripts/wallet_requests.js

const ADMIN_WALLET_API = {
    list:              '../backend/wallet/list_requests.php',
    approveTopup:      '../backend/wallet/approve_topup.php',
    approveWithdrawal: '../backend/wallet/approve_withdrawal.php',
    reject:            '../backend/wallet/reject_request.php',
    proof:             '../backend/wallet/get_proof.php',
};

let currentType     = 'topup';
let currentStatus   = 'pending';
let currentRequests = [];

document.addEventListener('DOMContentLoaded', () => {
    loadRequests();
});

function switchType(type) {
    currentType = type;
    document.querySelectorAll('.wallet-tab').forEach(el =>
        el.classList.toggle('active', el.dataset.type === type)
    );
    loadRequests();
}

function switchStatus(status) {
    currentStatus = status;
    document.querySelectorAll('.filter-btn').forEach(el =>
        el.classList.toggle('active', el.dataset.status === status)
    );
    loadRequests();
}

async function loadRequests() {
    showLoader();
    const list = document.getElementById('requestsList');
    try {
        const url = `${ADMIN_WALLET_API.list}?type=${encodeURIComponent(currentType)}&status=${encodeURIComponent(currentStatus)}`;
        const res = await fetch(url, { credentials: 'same-origin' });
        const data = await res.json();

        if (!data.success) throw new Error(data.message || 'Failed to load');
        currentRequests = data.data.requests || [];
        renderRequests();
        updateBadges(data.data.stats);
    } catch (err) {
        console.error(err);
        list.innerHTML = `<div class="admin-empty-state">
            <i class="fas fa-exclamation-triangle"></i>
            <p>${escapeHtml(err.message || 'Failed to load requests')}</p>
        </div>`;
        UI.toast(err.message || 'Failed to load requests', 'error');
    } finally {
        hideLoader();
    }
}

function updateBadges(stats) {
    if (!stats) return;
    const topupBadge = document.getElementById('topupBadge');
    const wdBadge    = document.getElementById('withdrawalBadge');
    topupBadge.textContent = stats.pending_topups || 0;
    wdBadge.textContent    = stats.pending_withdrawals || 0;
    topupBadge.dataset.zero = (stats.pending_topups || 0) === 0 ? 'true' : 'false';
    wdBadge.dataset.zero    = (stats.pending_withdrawals || 0) === 0 ? 'true' : 'false';
}

function renderRequests() {
    const list = document.getElementById('requestsList');

    if (!currentRequests.length) {
        list.innerHTML = `<div class="admin-empty-state">
            <i class="fas fa-inbox"></i>
            <p>No ${currentStatus} ${currentType}s</p>
        </div>`;
        return;
    }

    list.innerHTML = currentRequests.map(r => renderCard(r)).join('');
}

function renderCard(r) {
    const isTopup   = r.request_type === 'topup';
    const isPending = r.status === 'pending';

    return `
        <div class="request-card" data-request-code="${escapeHtml(r.request_code)}">
            <div class="request-card-header">
                <div class="left">
                    <h3>${escapeHtml(r.full_name || r.user_code)}</h3>
                    <div class="sub">${escapeHtml(r.request_code)} · ${r.user_type === 'client' ? 'Client' : 'Tenant'}</div>
                </div>
                <div class="right">
                    <div class="amount">${r.amount_formatted}</div>
                    <div class="status"><span class="status-pill ${r.status}">${r.status}</span></div>
                </div>
            </div>

            <div class="request-info-grid">
                <div class="info-item">
                    <div class="label">Requested</div>
                    <div class="value">${formatDateTime(r.created_at)}</div>
                </div>

                ${isTopup ? `
                    <div class="info-item">
                        <div class="label">Reference</div>
                        <div class="value" style="font-family:monospace;font-size:12px;">${escapeHtml(r.reference_number || '—')}</div>
                    </div>
                    <div class="info-item">
                        <div class="label">Proof</div>
                        <div class="value">
                            ${r.bank_proof_path
                                ? `<a href="${ADMIN_WALLET_API.proof}?request_code=${encodeURIComponent(r.request_code)}"
                                      target="_blank" class="btn btn-view" style="padding:4px 10px;font-size:12px;">
                                      <i class="fas fa-file"></i> View Proof
                                  </a>`
                                : '—'}
                        </div>
                    </div>
                ` : `
                    <div class="info-item">
                        <div class="label">Bank</div>
                        <div class="value">${escapeHtml(r.bank_name || '—')}</div>
                    </div>
                    <div class="info-item">
                        <div class="label">Account Name</div>
                        <div class="value">${escapeHtml(r.bank_account_name || '—')}</div>
                    </div>
                    <div class="info-item">
                        <div class="label">Account Number</div>
                        <div class="value" style="font-family:monospace;">${escapeHtml(r.bank_account_number || '—')}</div>
                    </div>
                `}

                ${r.rejection_reason ? `
                    <div class="info-item" style="grid-column: 1 / -1;">
                        <div class="label" style="color:#991b1b;">Rejection Reason</div>
                        <div class="value" style="color:#991b1b;">${escapeHtml(r.rejection_reason)}</div>
                    </div>
                ` : ''}
            </div>

            ${isPending ? `
                <div class="request-card-actions">
                    <button class="btn btn-reject" onclick="openRejectModal('${escapeHtml(r.request_code)}')">
                        <i class="fas fa-times"></i> Reject
                    </button>
                    <button class="btn btn-approve" onclick="approveRequest('${escapeHtml(r.request_code)}', '${r.request_type}')">
                        <i class="fas fa-check"></i> ${isTopup ? 'Credit Wallet' : 'Mark as Paid'}
                    </button>
                </div>
            ` : ''}
        </div>
    `;
}

// =========================================================
// APPROVE — with custom confirm
// =========================================================
async function approveRequest(requestCode, type) {
    const confirmMessage = type === 'topup'
        ? `Credit the client wallet for request ${requestCode}?\n\nOnly proceed if the bank transfer has been confirmed.`
        : `Mark withdrawal ${requestCode} as paid?\n\nOnly proceed after you've made the bank transfer.`;

    UI.confirm(
        confirmMessage,
        async (confirmed) => {
            if (!confirmed) return;

            const endpoint = type === 'topup'
                ? ADMIN_WALLET_API.approveTopup
                : ADMIN_WALLET_API.approveWithdrawal;

            const body = { request_code: requestCode };
            const csrf = await getCsrfToken('admin_wallet_form');
            if (csrf) {
                body.csrf_token = csrf;
                body.token_id = 'admin_wallet_form';
            }

            showLoader();
            try {
                const res = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body),
                    credentials: 'same-origin',
                });
                const data = await res.json();
                if (!data.success){
                    UI.toast(data.message || "Unable to Approve withdrawal request", 'error');
                    // throw new Error(data.message || 'Failed');
                } 
                UI.toast(data.message || 'Request approved', 'success');
                loadRequests();
            } catch (err) {
                console.error(err);
                UI.toast(err.message || 'An error occurred, please try again', 'error');
            } finally {
                hideLoader();
            }
        },
        'Confirm Approval'
    );
}

// =========================================================
// REJECT MODAL
// =========================================================
function openRejectModal(requestCode) {
    const existing = document.getElementById('rejectModal');
    if (existing) existing.remove();

    const html = `
        <div id="rejectModal" class="modal active">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Reject Request</h3>
                    <button class="modal-close" onclick="closeRejectModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <p style="font-size:13px;color:#64748b;margin-bottom:14px;">
                        Provide a reason. It will be shown to the user.
                    </p>
                    <div class="form-group">
                        <label>Reason *</label>
                        <textarea id="rejectReason" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-view" onclick="closeRejectModal()">Cancel</button>
                    <button class="btn btn-reject" onclick="submitReject('${requestCode}')">
                        <i class="fas fa-times"></i> Reject
                    </button>
                </div>
            </div>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', html);
}

function closeRejectModal() {
    const m = document.getElementById('rejectModal');
    if (m) m.remove();
}

async function submitReject(requestCode) {
    const reason = document.getElementById('rejectReason').value.trim();
    if (reason.length < 5) {
        UI.toast('Please provide a reason (min 5 chars)', 'error');
        return;
    }

    UI.confirm(
        `Reject request ${requestCode}?\n\nThe user will be notified with your reason.`,
        async (confirmed) => {
            if (!confirmed) return;

            const body = {
                request_code: requestCode,
                rejection_reason: reason,
            };
            const csrf = await getCsrfToken('admin_wallet_form');
            if (csrf) {
                body.csrf_token = csrf;
                body.token_id = 'admin_wallet_form';
            }

            showLoader();
            try {
                const res = await fetch(ADMIN_WALLET_API.reject, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body),
                    credentials: 'same-origin',
                });
                const data = await res.json();
                if (!data.success){
                    UI.toast(data.message || "Unable to Approve withdrawal request", 'error');
                    // throw new Error(data.message || 'Failed');
                } 
                closeRejectModal();
                UI.toast(data.message || 'Request rejected', 'success');
                loadRequests();
            } catch (err) {
                console.error(err);
                UI.toast(err.message || 'An error occurred, please try again', 'error');
            } finally {
                hideLoader();
            }
        },
        'Confirm Rejection'
    );
}

// =========================================================
// HELPERS
// =========================================================
function formatDateTime(str) {
    if (!str) return '—';
    const d = new Date(str);
    return d.toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' })
        + ' · ' + d.toLocaleTimeString('en-US', { hour:'numeric', minute:'2-digit' });
}

function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}

function showLoader() {
    const l = document.getElementById('adminWalletLoader');
    if (l) l.style.display = 'flex';
}

function hideLoader() {
    const l = document.getElementById('adminWalletLoader');
    if (l) l.style.display = 'none';
}

async function getCsrfToken(formName) {
    try {
        const res = await fetch(
            `../backend/utilities/get_token.php?form=${encodeURIComponent(formName)}`,
            { credentials: 'include' }
        );
        const data = await res.json();
        return data.success ? data.token : null;
    } catch (err) {
        console.error('CSRF token fetch failed:', err);
        return null;
    }
}