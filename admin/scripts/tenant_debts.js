// admin/scripts/tenant_debts.js

const DEBT_API = {
    list:         '../backend/debts/list_debts.php',
    get:          '../backend/debts/get_debt.php',
    recordPayment:'../backend/debts/record_payment.php',
    writeOff:     '../backend/debts/write_off.php',
};

let searchTimer = null;
let currentPage = 1;
let activeModal = null;
let activeDebtCode = null;
let activeDebtBalance = 0;

document.addEventListener('DOMContentLoaded', () => {
    loadDebts();
    const methodSelect = document.getElementById('payMethod');
    if (methodSelect) {
        methodSelect.addEventListener('change', () => {
            // Regenerate ref whenever method changes
            // (Admin can still edit it manually afterward)
            regenerateReference();
        });
    }
});

// =========================================================
// LOAD & RENDER
// =========================================================
function debounceSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => { currentPage = 1; loadDebts(); }, 400);
}

async function loadDebts(page = null) {
    if (page) currentPage = page;

    showLoader('Loading debts...');
    const list = document.getElementById('debtsList');

    try {
        const q = document.getElementById('debtSearch').value.trim();
        const status = document.getElementById('debtStatus').value;

        const url = `${DEBT_API.list}?status=${encodeURIComponent(status)}`
            + `&search=${encodeURIComponent(q)}`
            + `&page=${currentPage}&limit=20`;

        const res = await fetch(url, { credentials: 'same-origin' });
        const data = await res.json();

        if (!data.success) throw new Error(data.message || 'Failed to load');
        renderStats(data.data.stats);
        renderDebts(data.data.debts);
        renderPagination(data.data.pagination);
    } catch (err) {
        console.error(err);
        list.innerHTML = `
            <div class="admin-empty-state">
                <i class="fas fa-exclamation-triangle" style="color:#f59e0b;"></i>
                <p>${escapeHtml(err.message || 'Failed to load debts')}</p>
            </div>`;
        UI.toast(err.message || 'Failed to load debts', 'error');
    } finally {
        hideLoader();
    }
}

function renderStats(stats) {
    if (!stats) return;
    document.getElementById('statOutstanding').textContent = stats.outstanding_total_formatted;
    document.getElementById('statUnpaid').textContent      = stats.unpaid_count;
    document.getElementById('statPartial').textContent     = stats.partial_count;
    document.getElementById('statResolved').textContent    = stats.resolved_count;
}

function renderDebts(debts) {
    const list = document.getElementById('debtsList');

    if (!debts || debts.length === 0) {
        list.innerHTML = `
            <div class="admin-empty-state">
                <i class="fas fa-inbox"></i>
                <p>No debts found. Try adjusting the filters.</p>
            </div>`;
        return;
    }

    list.innerHTML = debts.map(renderDebtCard).join('');
}

function renderDebtCard(d) {
    const isResolved = ['resolved','written_off'].includes(d.status);
    const canAct = ['unpaid','partial','disputed'].includes(d.status);
    const isPaidPartial = d.amount_paid > 0;
    const progressPct = d.original_amount > 0
        ? Math.min(100, (d.amount_paid / d.original_amount) * 100)
        : 0;

    const ageClass = d.age_days <= 7 ? 'fresh'
                   : d.age_days <= 30 ? 'warning'
                   : 'danger';

    return `
        <div class="debt-card">
            <div class="debt-card-header">
                <div class="left">
                    <h3>${escapeHtml(d.tenant_name)}</h3>
                    <div class="debt-meta">
                        <span>${escapeHtml(d.debt_code)}</span>
                        <span>·</span>
                        <span>${escapeHtml(d.source_type)}</span>
                        <span>·</span>
                        <span class="age-badge ${ageClass}">
                            <i class="fas fa-clock"></i> ${d.age_days} days
                        </span>
                    </div>
                </div>
                <div class="right">
                    <div class="amount ${isResolved ? 'resolved' : ''}">
                        ${isResolved ? d.original_amount_formatted : d.balance_formatted}
                    </div>
                    <span class="status-pill ${d.status}">${humanizeStatus(d.status)}</span>
                </div>
            </div>

            <div class="debt-info-grid">
                <div class="debt-info-item">
                    <div class="label">Original Amount</div>
                    <div class="value mono">${d.original_amount_formatted}</div>
                </div>
                <div class="debt-info-item">
                    <div class="label">Paid So Far</div>
                    <div class="value mono ${isPaidPartial ? 'success' : ''}">${d.amount_paid_formatted}</div>
                </div>
                <div class="debt-info-item">
                    <div class="label">Tenant</div>
                    <div class="value">${escapeHtml(d.tenant_code)}</div>
                </div>
                ${d.client_name ? `
                    <div class="debt-info-item">
                        <div class="label">Owed To</div>
                        <div class="value">${escapeHtml(d.client_name)}</div>
                    </div>
                ` : ''}
                ${d.tenant_phone ? `
                    <div class="debt-info-item">
                        <div class="label">Phone</div>
                        <div class="value">${escapeHtml(d.tenant_phone)}</div>
                    </div>
                ` : ''}
                ${d.resolved_at_formatted ? `
                    <div class="debt-info-item">
                        <div class="label">Resolved</div>
                        <div class="value">${escapeHtml(d.resolved_at_formatted)}</div>
                    </div>
                ` : ''}
            </div>

            <div class="debt-progress-wrap">
                <div class="debt-progress-bar">
                    <div class="debt-progress-fill" style="width:${progressPct}%"></div>
                </div>
                <div class="debt-progress-label">
                    <span>${Math.round(progressPct)}% recovered</span>
                    <span>${d.amount_paid_formatted} of ${d.original_amount_formatted}</span>
                </div>
            </div>

            <div class="debt-card-actions">
                <button class="btn btn-outline" onclick="viewDetails('${escapeHtml(d.debt_code)}')">
                    <i class="fas fa-eye"></i> Details
                </button>
                ${canAct ? `
                    <button class="btn btn-success"
                            onclick="openPaymentModal('${escapeHtml(d.debt_code)}', ${d.balance}, '${escapeHtml(d.balance_formatted)}', '${escapeHtml(d.tenant_name)}')">
                        <i class="fas fa-money-bill"></i> Record Payment
                    </button>
                    <button class="btn btn-danger"
                            onclick="openWriteOffModal('${escapeHtml(d.debt_code)}', '${escapeHtml(d.tenant_name)}', '${escapeHtml(d.balance_formatted)}')">
                        <i class="fas fa-ban"></i> Write Off
                    </button>
                ` : ''}
            </div>
        </div>
    `;
}

function renderPagination(pg) {
    const wrap = document.getElementById('debtsPagination');
    if (!pg || pg.total_pages <= 1) {
        wrap.innerHTML = '';
        return;
    }

    const pages = [];
    pages.push(`<button ${pg.page === 1 ? 'disabled' : ''} onclick="loadDebts(${pg.page - 1})">
                    <i class="fas fa-chevron-left"></i>
                </button>`);

    const start = Math.max(1, pg.page - 2);
    const end   = Math.min(pg.total_pages, pg.page + 2);

    if (start > 1) {
        pages.push(`<button onclick="loadDebts(1)">1</button>`);
        if (start > 2) pages.push(`<button disabled>…</button>`);
    }

    for (let i = start; i <= end; i++) {
        pages.push(`<button class="${i === pg.page ? 'active' : ''}" onclick="loadDebts(${i})">${i}</button>`);
    }

    if (end < pg.total_pages) {
        if (end < pg.total_pages - 1) pages.push(`<button disabled>…</button>`);
        pages.push(`<button onclick="loadDebts(${pg.total_pages})">${pg.total_pages}</button>`);
    }

    pages.push(`<button ${pg.page === pg.total_pages ? 'disabled' : ''} onclick="loadDebts(${pg.page + 1})">
                    <i class="fas fa-chevron-right"></i>
                </button>`);

    wrap.innerHTML = `<div class="pagination">${pages.join('')}</div>`;
}

// =========================================================
// DETAILS
// =========================================================
async function viewDetails(debtCode) {
    showLoader('Loading details...');
    try {
        const res = await fetch(`${DEBT_API.get}?debt_code=${encodeURIComponent(debtCode)}`, {
            credentials: 'same-origin',
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.message);

        renderDetailsModal(data.data.debt, data.data.payments);
        openModal('detailsModal');
    } catch (err) {
        UI.toast(err.message || 'Failed to load details', 'error');
    } finally {
        hideLoader();
    }
}

function renderDetailsModal(d, payments) {
    const paymentRows = payments && payments.length
        ? payments.map(p => `
            <tr>
                <td>${escapeHtml(p.created_at_formatted)}</td>
                <td class="mono">${p.amount_formatted}</td>
                <td>${humanizePaymentMethod(p.method)}</td>
                <td class="mono">${escapeHtml(p.reference_number || '—')}</td>
            </tr>
        `).join('')
        : `<tr><td colspan="4" style="text-align:center;color:#94a3b8;padding:24px;">No payments recorded yet</td></tr>`;

    document.getElementById('detailsBody').innerHTML = `
        <div class="debt-summary-box">
            <div class="row">
                <strong>Debt Code</strong>
                <span class="value">${escapeHtml(d.debt_code)}</span>
            </div>
            <div class="row">
                <strong>Tenant</strong>
                <span class="value">${escapeHtml(d.tenant_name)} (${escapeHtml(d.tenant_code)})</span>
            </div>
            <div class="row">
                <strong>Source</strong>
                <span class="value">${escapeHtml(d.source_type)} · ${escapeHtml(d.source_id || '—')}</span>
            </div>
            <div class="row">
                <strong>Original Amount</strong>
                <span class="value">${d.original_amount_formatted}</span>
            </div>
            <div class="row">
                <strong>Paid</strong>
                <span class="value">${d.amount_paid_formatted}</span>
            </div>
            <div class="row total">
                <strong>Outstanding Balance</strong>
                <span class="value">${d.balance_formatted}</span>
            </div>
        </div>

        ${d.notes ? `
            <div style="margin:18px 0;">
                <div style="font-size:11px;text-transform:uppercase;color:#64748b;font-weight:600;letter-spacing:0.4px;margin-bottom:6px;">
                    Notes
                </div>
                <div style="font-size:13px;color:#1e293b;white-space:pre-wrap;line-height:1.6;">${escapeHtml(d.notes)}</div>
            </div>
        ` : ''}

        <div style="margin-top:24px;">
            <div style="font-size:12px;text-transform:uppercase;color:#64748b;font-weight:600;letter-spacing:0.4px;margin-bottom:10px;">
                Payment History (${payments.length})
            </div>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead>
                        <tr style="background:#f8fafc;">
                            <th style="padding:8px 10px;text-align:left;border-bottom:1px solid #e2e8f0;">Date</th>
                            <th style="padding:8px 10px;text-align:left;border-bottom:1px solid #e2e8f0;">Amount</th>
                            <th style="padding:8px 10px;text-align:left;border-bottom:1px solid #e2e8f0;">Method</th>
                            <th style="padding:8px 10px;text-align:left;border-bottom:1px solid #e2e8f0;">Reference</th>
                        </tr>
                    </thead>
                    <tbody>${paymentRows}</tbody>
                </table>
            </div>
        </div>
    `;
}

// =========================================================
// PAYMENT
// =========================================================
function openPaymentModal(debtCode, balance, balanceFormatted, tenantName) {
    activeDebtCode = debtCode;
    activeDebtBalance = balance;

    document.getElementById('paymentDebtSummary').innerHTML = `
        <div class="row">
            <strong>Tenant</strong>
            <span class="value">${escapeHtml(tenantName)}</span>
        </div>
        <div class="row">
            <strong>Debt Code</strong>
            <span class="value">${escapeHtml(debtCode)}</span>
        </div>
        <div class="row total">
            <strong>Outstanding Balance</strong>
            <span class="value">${balanceFormatted}</span>
        </div>
    `;
    document.getElementById('payAmountHint').textContent =
        `Max: ${balanceFormatted}`;
    document.getElementById('payAmount').value = '';
    document.getElementById('payAmount').placeholder = `e.g. ${Math.min(balance, 50000).toLocaleString()}`;
    document.getElementById('payReference').value = '';
    document.getElementById('payNotes').value = '';
    document.getElementById('payMethod').value = 'bank_transfer';

     // Auto-generate reference for the default method
    regenerateReference();

    openModal('paymentModal');
    setTimeout(() => document.getElementById('payAmount').focus(), 100);
}

async function submitPayment() {
    const amount = document.getElementById('payAmount').value.trim();
    const method = document.getElementById('payMethod').value;
    const ref    = document.getElementById('payReference').value.trim();
    const notes  = document.getElementById('payNotes').value.trim();

    const amountNum = parseFloat(amount.replace(/,/g, ''));
    if (!amountNum || amountNum <= 0) {
        return UI.alert('Enter a valid amount', 'Error');
    }
    if (amountNum > activeDebtBalance) {
        return UI.alert(`Amount cannot exceed balance of ₦${activeDebtBalance.toLocaleString()}`, 'Error');
    }

    // Validate reference on the frontend
    const refCheck = PaymentRefs.validate(ref, method);
    if (!refCheck.valid) {
        return UI.alert(refCheck.message, 'Error');
    }

    const body = {
        debt_code: activeDebtCode,
        amount: amountNum,
        payment_method: method,
        reference_number: ref,
        notes: notes,
    };

    const csrf = await getCsrfToken('admin_debt_form');
    if (csrf) {
        body.csrf_token = csrf;
        body.token_id = 'admin_debt_form';
    }

    const btn = document.getElementById('paySubmitBtn');
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Recording...';

    try {
        const res = await fetch(DEBT_API.recordPayment, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
            credentials: 'same-origin',
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.message);

        closeModal('paymentModal');
        UI.alert(data.message || 'Payment recorded', 'Success');
        loadDebts();
    } catch (err) {
        UI.alert(err.message || 'Failed to record payment', 'Error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = original;
    }
}

// =========================================================
// WRITE OFF
// =========================================================
function openWriteOffModal(debtCode, tenantName, balanceFormatted) {
    activeDebtCode = debtCode;

    document.getElementById('writeOffDebtSummary').innerHTML = `
        <div class="row">
            <strong>Tenant</strong>
            <span class="value">${escapeHtml(tenantName)}</span>
        </div>
        <div class="row">
            <strong>Debt Code</strong>
            <span class="value">${escapeHtml(debtCode)}</span>
        </div>
        <div class="row total">
            <strong>Amount to Forgive</strong>
            <span class="value">${balanceFormatted}</span>
        </div>
    `;
    document.getElementById('writeOffReason').value = '';

    openModal('writeOffModal');
    setTimeout(() => document.getElementById('writeOffReason').focus(), 100);
}

async function submitWriteOff() {
    const reason = document.getElementById('writeOffReason').value.trim();
    if (reason.length < 5) {
        return UI.alert('Please provide a reason (min 5 chars)', 'Error');
    }

    UI.confirm(
        `Write off this debt? The tenant will no longer owe this amount. This cannot be undone.`,
        async (confirmed) => {
            if (!confirmed) return;

            const body = { debt_code: activeDebtCode, reason: reason };
            const csrf = await getCsrfToken('admin_debt_form');
            if (csrf) { body.csrf_token = csrf; body.token_id = 'admin_debt_form'; }

            const btn = document.getElementById('writeOffSubmitBtn');
            const original = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

            try {
                const res = await fetch(DEBT_API.writeOff, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body),
                    credentials: 'same-origin',
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.message);

                closeModal('writeOffModal');
                UI.alert(data.message || 'Debt written off', 'Success');
                loadDebts();
            } catch (err) {
                UI.alert(err.message || 'Failed to write off', 'Error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = original;
            }
        },
        'Confirm Write Off'
    );
}

// =========================================================
// HELPERS
// =========================================================
function humanizeStatus(s) {
    const map = {
        unpaid:       'Unpaid',
        partial:      'Partially Paid',
        resolved:     'Resolved',
        written_off:  'Written Off',
        disputed:     'Disputed',
    };
    return map[s] || s;
}

function humanizePaymentMethod(m) {
    const map = {
        wallet_offset: 'Wallet Offset',
        bank_transfer: 'Bank Transfer',
        cash:          'Cash',
        other:         'Other',
    };
    return map[m] || m;
}

function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}

function openModal(id) {
    document.getElementById(id).classList.add('active');
    activeModal = id;
}
function closeModal(id) {
    document.getElementById(id).classList.remove('active');
    if (activeModal === id) activeModal = null;
}

function showLoader(msg = null) {
    const l = document.getElementById('adminWalletLoader');
    if (!l) return;
    const msgEl = l.querySelector('.admin-loader-message');
    if (msgEl) msgEl.textContent = msg || 'Loading...';
    l.style.display = 'flex';
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
        console.error('CSRF fetch failed:', err);
        return null;
    }
}

/**
 * Regenerate the reference to match the current method.
 * Called on modal open and on method change.
 */
function regenerateReference() {
    const method = document.getElementById('payMethod').value;
    const refInput = document.getElementById('payReference');
    refInput.value = PaymentRefs.generate(method);
}

// Close modals on backdrop click
document.addEventListener('click', (e) => {
    if (e.target.classList.contains('modal') && e.target.classList.contains('active')) {
        e.target.classList.remove('active');
        activeModal = null;
    }
});

// Close modals on Escape
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && activeModal) {
        closeModal(activeModal);
    }
});

