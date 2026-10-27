// admin/scripts/wallet_requests_v2.js

const ADMIN_WALLET_API = {
    report:        '../backend/wallet/report.php',
    requestDetail: '../backend/wallet/request_detail.php',
    approveTopup:  '../backend/wallet/approve_topup.php',
    approveWithdrawal: '../backend/wallet/approve_withdrawal.php',
    reject:        '../backend/wallet/reject_request.php',
    proof:         '../backend/wallet/get_proof.php',
};

const state = {
    tab: 'inflows',
    status: 'pending',
    userType: 'all',
    search: '',
    dateFrom: '',
    dateTo: '',
    page: 1,
    limit: 20,
};

let searchTimer = null;

document.addEventListener('DOMContentLoaded', () => {
    loadReport();
});

// =========================================================
// TABS / FILTERS
// =========================================================
function switchTab(tab) {
    state.tab = tab;
    state.page = 1;
    document.querySelectorAll('.report-tab').forEach(el =>
        el.classList.toggle('active', el.dataset.tab === tab));

    // Status and user_type filters visibility
    document.getElementById('statusFilterGroup').style.display = (tab === 'ledger') ? 'none' : 'flex';
    document.getElementById('userTypeFilterGroup').style.display = (tab === 'ledger') ? 'flex' : 'none';

    loadReport();
}

function debounceSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        state.search = document.getElementById('searchInput').value.trim();
        state.page = 1;
        loadReport();
    }, 400);
}

function applyFilters() {
    state.status   = document.getElementById('statusFilter').value;
    state.userType = document.getElementById('userTypeFilter').value;
    state.dateFrom = document.getElementById('dateFrom').value;
    state.dateTo   = document.getElementById('dateTo').value;
    state.page     = 1;
    loadReport();
}

function clearFilters() {
    document.getElementById('statusFilter').value = 'pending';
    document.getElementById('userTypeFilter').value = 'all';
    document.getElementById('searchInput').value = '';
    document.getElementById('dateFrom').value = '';
    document.getElementById('dateTo').value = '';
    Object.assign(state, { status: 'pending', userType: 'all', search: '', dateFrom: '', dateTo: '', page: 1 });
    loadReport();
}

// =========================================================
// DATA LOADING
// =========================================================
async function loadReport() {
    showLoader();
    try {
        const params = new URLSearchParams({
            tab: state.tab,
            page: state.page,
            limit: state.limit,
        });
        if (state.tab !== 'ledger') {
            params.set('status', state.status);
        } else {
            params.set('user_type', state.userType);
        }
        if (state.search) params.set('search', state.search);
        if (state.dateFrom) params.set('date_from', state.dateFrom);
        if (state.dateTo) params.set('date_to', state.dateTo);

        const res = await fetch(`${ADMIN_WALLET_API.report}?${params}`, { credentials: 'same-origin' });
        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'Failed to load');

        renderContent(data.data);
        renderPagination(data.data.pagination);
        updateBadgesAndSummary(data.data.stats);
    } catch (err) {
        console.error(err);
        document.getElementById('reportContent').innerHTML = `
            <div class="admin-empty-state">
                <i class="fas fa-exclamation-triangle"></i>
                <p>${escapeHtml(err.message || 'Failed to load report')}</p>
            </div>`;
    } finally {
        hideLoader();
    }
}

// =========================================================
// RENDER
// =========================================================
function renderContent(data) {
    const wrap = document.getElementById('reportContent');
    const items = data.items || [];
    if (!items.length) {
        wrap.innerHTML = `<div class="admin-empty-state">
            <i class="fas fa-inbox"></i>
            <p>No ${state.tab === 'ledger' ? 'transactions' : 'requests'} match your filters</p>
        </div>`;
        return;
    }

    if (state.tab === 'ledger') {
        wrap.innerHTML = renderLedgerTable(items);
    } else {
        wrap.innerHTML = items.map(r => renderRequestCard(r)).join('');
    }
}

function renderRequestCard(r) {
    const isTopup = r.request_type === 'topup';
    const isPending = r.status === 'pending';

    return `
        <div class="request-card">
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
                                      target="_blank" class="btn btn-view btn-sm">
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

            <div class="request-card-actions">
                <button class="btn btn-view" onclick="openDetail('${escapeHtml(r.request_code)}')">
                    <i class="fas fa-eye"></i> View Details
                </button>
                ${isPending ? `
                    <button class="btn btn-reject" onclick="openRejectModal('${escapeHtml(r.request_code)}')">
                        <i class="fas fa-times"></i> Reject
                    </button>
                    <button class="btn btn-approve" onclick="approveRequest('${escapeHtml(r.request_code)}', '${r.request_type}')">
                        <i class="fas fa-check"></i> ${isTopup ? 'Credit Wallet' : 'Mark as Paid'}
                    </button>
                ` : ''}
            </div>
        </div>
    `;
}

function renderLedgerTable(items) {
    return `
        <div class="ledger-table-wrap">
            <table class="ledger-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>User</th>
                        <th>Type</th>
                        <th>Transaction</th>
                        <th>Amount</th>
                        <th>Balance After</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    ${items.map(r => `
                        <tr>
                            <td>${formatDateTime(r.created_at)}</td>
                            <td>
                                <div class="user-cell">
                                    <span class="user-type-badge ${r.user_type}">${r.user_type}</span>
                                    <span class="user-code">${escapeHtml(String(r.user_code))}</span>
                                </div>
                            </td>
                            <td>${humanizeType(r.transaction_type)}</td>
                            <td>
                                ${r.request_id
                                    ? `<a href="#" onclick="event.preventDefault(); openDetail('${escapeHtml(r.request_id)}')"
                                          style="color:#667eea;font-family:monospace;font-size:12px;">
                                          ${escapeHtml(r.request_id)}
                                      </a>`
                                    : '<span style="color:#94a3b8;">—</span>'}
                            </td>
                            <td class="${r.direction === 'in' ? 'amount-in' : 'amount-out'}">
                                ${r.amount_formatted}
                            </td>
                            <td>₦${formatNumber(r.running_balance)}</td>
                            <td>${statusBadge(r.status)}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        </div>
    `;
}

function renderPagination(p) {
    const wrap = document.getElementById('pagination');
    if (!p || p.total_pages <= 1) { wrap.innerHTML = ''; return; }

    const pages = [];
    const current = p.page;
    const last = p.total_pages;
    for (let i = 1; i <= last; i++) {
        if (i === 1 || i === last || Math.abs(i - current) <= 2) {
            pages.push(i);
        } else if (pages[pages.length - 1] !== '…') {
            pages.push('…');
        }
    }

    wrap.innerHTML = `
        <button class="page-btn" ${current === 1 ? 'disabled' : ''} onclick="goToPage(${current - 1})">
            <i class="fas fa-chevron-left"></i>
        </button>
        ${pages.map(pg => pg === '…'
            ? `<span class="page-ellipsis">…</span>`
            : `<button class="page-btn ${pg === current ? 'active' : ''}" onclick="goToPage(${pg})">${pg}</button>`
        ).join('')}
        <button class="page-btn" ${current === last ? 'disabled' : ''} onclick="goToPage(${current + 1})">
            <i class="fas fa-chevron-right"></i>
        </button>
        <span class="page-info">${current} of ${last} · ${p.total} total</span>
    `;
}

function goToPage(page) {
    state.page = page;
    loadReport();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function updateBadgesAndSummary(stats) {
    if (!stats) return;

    if (state.tab === 'inflows' || state.tab === 'outflows') {
        const badge = document.getElementById(`${state.tab}Badge`);
        if (badge) {
            badge.textContent = stats.pending || 0;
            badge.dataset.zero = (stats.pending || 0) === 0 ? 'true' : 'false';
        }
        document.getElementById('pendingSummary').textContent = `${stats.pending || 0} requests`;
    }
    if (stats.inflow_total !== undefined) {
        document.getElementById('inflowSummary').textContent = '₦' + formatNumber(stats.inflow_total);
        document.getElementById('outflowSummary').textContent = '₦' + formatNumber(stats.outflow_total);
    }
}

// =========================================================
// DETAIL DRAWER
// =========================================================
async function openDetail(requestCode) {
    if (!requestCode) return;
    showLoader();
    try {
        const res = await fetch(`${ADMIN_WALLET_API.requestDetail}?request_code=${encodeURIComponent(requestCode)}`,
            { credentials: 'same-origin' });
        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'Failed to load detail');
        renderDetailDrawer(data.data);
    } catch (err) {
        UI.toast(err.message || 'Failed to load detail', 'error');
    } finally {
        hideLoader();
    }
}

function renderDetailDrawer(data) {
    const existing = document.getElementById('detailDrawer');
    if (existing) existing.remove();

    const req = data.request;
    const ledger = data.ledger || [];

    const html = `
        <div id="detailDrawer" class="drawer-overlay" onclick="if(event.target===this)closeDetail()">
            <div class="drawer">
                <div class="drawer-header">
                    <div>
                        <h2>${escapeHtml(req.request_code)}</h2>
                        <div class="sub">
                            ${escapeHtml(req.user_type)} ·
                            ${escapeHtml(req.full_name || req.user_code)}
                        </div>
                    </div>
                    <button class="drawer-close" onclick="closeDetail()">&times;</button>
                </div>
                <div class="drawer-body">
                    <div class="detail-section">
                        <div class="detail-grid">
                            <div><label>Type</label><div>${req.request_type === 'topup' ? 'Top-Up' : 'Withdrawal'}</div></div>
                            <div><label>Amount</label><div><strong>${req.amount_formatted}</strong></div></div>
                            <div><label>Status</label><div>${statusBadge(req.status)}</div></div>
                            <div><label>Submitted</label><div>${formatDateTime(req.created_at)}</div></div>
                            ${req.approved_at ? `<div><label>Resolved</label><div>${formatDateTime(req.approved_at)}</div></div>` : ''}
                            ${req.approved_by_name ? `<div><label>Reviewed By</label><div>${escapeHtml(req.approved_by_name)}</div></div>` : ''}
                        </div>
                    </div>

                    ${req.request_type === 'topup' ? `
                        <div class="detail-section">
                            <h3>Proof Details</h3>
                            <div class="detail-grid">
                                <div><label>Reference</label><div style="font-family:monospace;">${escapeHtml(req.reference_number || '—')}</div></div>
                                ${req.bank_proof_path ? `
                                    <div style="grid-column: 1 / -1;">
                                        <label>File</label>
                                        <div>
                                            <a href="${ADMIN_WALLET_API.proof}?request_code=${encodeURIComponent(req.request_code)}"
                                               target="_blank" class="btn btn-view btn-sm">
                                                <i class="fas fa-file"></i> View Proof
                                            </a>
                                        </div>
                                    </div>
                                ` : ''}
                                ${req.bank_proof_notes ? `<div style="grid-column: 1 / -1;"><label>Client Notes</label><div>${escapeHtml(req.bank_proof_notes)}</div></div>` : ''}
                            </div>
                        </div>
                    ` : `
                        <div class="detail-section">
                            <h3>Destination</h3>
                            <div class="detail-grid">
                                <div><label>Bank</label><div>${escapeHtml(req.bank_name || '—')}</div></div>
                                <div><label>Account Name</label><div>${escapeHtml(req.bank_account_name || '—')}</div></div>
                                <div><label>Account Number</label><div style="font-family:monospace;">${escapeHtml(req.bank_account_number || '—')}</div></div>
                            </div>
                        </div>
                    `}

                    ${req.rejection_reason ? `
                        <div class="detail-section alert-section">
                            <h3 style="color:#991b1b;">Rejection Reason</h3>
                            <div>${escapeHtml(req.rejection_reason)}</div>
                        </div>
                    ` : ''}

                    <div class="detail-section">
                        <h3>Ledger Trail (${ledger.length} entries)</h3>
                        ${ledger.length ? `
                            <table class="ledger-mini">
                                <thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Balance</th><th>Status</th></tr></thead>
                                <tbody>
                                    ${ledger.map(l => `
                                        <tr>
                                            <td>${formatDateTime(l.created_at)}</td>
                                            <td>${humanizeType(l.transaction_type)}</td>
                                            <td class="${l.amount >= 0 ? 'amount-in' : 'amount-out'}">${l.amount_formatted}</td>
                                            <td>₦${formatNumber(l.running_balance)}</td>
                                            <td>${statusBadge(l.status)}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        ` : `<div class="empty-inline">No ledger entries for this request</div>`}
                    </div>
                </div>
            </div>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', html);
}

function closeDetail() {
    const d = document.getElementById('detailDrawer');
    if (d) d.remove();
}

// =========================================================
// ACTIONS (approve / reject)
// =========================================================
async function approveRequest(requestCode, type) {
    const isTopup = type === 'topup';
    const confirmMessage = isTopup
        ? `Credit the client wallet for request ${requestCode}?\n\nOnly proceed if the bank transfer has been confirmed.`
        : `Mark withdrawal ${requestCode} as paid?\n\nOnly proceed after you've made the bank transfer.`;

    UI.confirm(confirmMessage, async (confirmed) => {
        if (!confirmed) return;

        const endpoint = isTopup ? ADMIN_WALLET_API.approveTopup : ADMIN_WALLET_API.approveWithdrawal;
        const body = { request_code: requestCode };
        const csrf = await getCsrfToken('admin_wallet_form');
        if (csrf) { body.csrf_token = csrf; body.token_id = 'admin_wallet_form'; }

        showLoader();
        try {
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
                credentials: 'same-origin',
            });

            if (!res.headers.get('content-type')?.includes('application/json')) {
                const text = await res.text();
                console.error('Non-JSON response:', text.slice(0, 500));
                throw new Error('Server returned an unexpected response');
            }

            const data = await res.json();
            if (!data.success) throw new Error(data.message || 'Approval failed');

            UI.toast(data.message || 'Request approved', 'success');
            loadReport();
        } catch (err) {
            console.error(err);
            UI.toast(err.message || 'Approval failed', 'error');
        } finally {
            hideLoader();
        }
    }, 'Confirm Approval');
}

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
                    <p class="modal-hint">Provide a reason. It will be shown to the user.</p>
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
    if (reason.length < 5) { UI.toast('Please provide a reason (min 5 chars)', 'error'); return; }

    const body = { request_code: requestCode, rejection_reason: reason };
    const csrf = await getCsrfToken('admin_wallet_form');
    if (csrf) { body.csrf_token = csrf; body.token_id = 'admin_wallet_form'; }

    showLoader();
    try {
        const res = await fetch(ADMIN_WALLET_API.reject, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
            credentials: 'same-origin',
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'Rejection failed');

        closeRejectModal();
        UI.toast(data.message || 'Request rejected', 'success');
        loadReport();
    } catch (err) {
        UI.toast(err.message || 'Rejection failed', 'error');
    } finally {
        hideLoader();
    }
}

// =========================================================
// HELPERS
// =========================================================
function humanizeType(t) {
    const map = {
        topup: 'Top-Up', withdrawal: 'Withdrawal', rent_credit: 'Rent Credit',
        refund_credit: 'Refund', admin_adjustment: 'Adjustment',
        deposit_release: 'Deposit Release', debt_recovery: 'Debt Recovery',
        settlement_debit: 'Settlement Debit', fee_debit: 'Fee',
    };
    return map[t] || t;
}

function statusBadge(status) {
    const cls = { pending:'pending', approved:'approved', rejected:'rejected', cancelled:'cancelled' }[status] || '';
    return `<span class="status-pill ${cls}">${escapeHtml(status || '—')}</span>`;
}

function formatNumber(v) {
    return new Intl.NumberFormat('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v || 0);
}
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
function showLoader() { const l = document.getElementById('adminWalletLoader'); if (l) l.style.display = 'flex'; }
function hideLoader() { const l = document.getElementById('adminWalletLoader'); if (l) l.style.display = 'none'; }

async function getCsrfToken(formName) {
    try {
        const res = await fetch(`../backend/utilities/get_token.php?form=${encodeURIComponent(formName)}`,
            { credentials: 'include' });
        const data = await res.json();
        return data.success ? data.token : null;
    } catch (err) {
        console.error('CSRF token fetch failed:', err);
        return null;
    }
}