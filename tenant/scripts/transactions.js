// tenant/scripts/transactions.js

const TxState = {
    page: 1,
    limit: 25,
    filters: { type: '', direction: '', status: '', date_from: '', date_to: '', search: '' },
    rows: [],
};

const TYPE_LABELS = {
    rent_payment: 'Rent Payment',
    fee: 'Fee',
    deposit: 'Security Deposit',
    topup: 'Wallet Top-up',
    withdrawal: 'Wallet Withdrawal',
    settlement: 'Settlement',
    refund: 'Refund',
    adjustment: 'Adjustment',
};
const TYPE_ICONS = {
    rent_payment: 'fa-house',
    fee: 'fa-file-invoice-dollar',
    deposit: 'fa-shield-halved',
    topup: 'fa-arrow-down',
    withdrawal: 'fa-arrow-up',
    settlement: 'fa-handshake',
    refund: 'fa-rotate-left',
    adjustment: 'fa-sliders',
};

document.addEventListener('DOMContentLoaded', () => {
    renderFilterUI();
    bindEvents();
    loadTransactions();

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && document.getElementById('txSlideOver')?.classList.contains('show')) {
            closeTxSlide();
        }
    });
});

function renderFilterUI() {
    const grid = document.getElementById('txFilterGrid');
    if (!grid) return;
    grid.innerHTML = `
        <div class="tx-field">
            <label>Type</label>
            <select id="txFilterType">
                <option value="">All types</option>
                <option value="rent_payment">Rent Payment</option>
                <option value="fee">Fee</option>
                <option value="deposit">Security Deposit</option>
                <option value="topup">Wallet Top-up</option>
                <option value="withdrawal">Wallet Withdrawal</option>
                <option value="settlement">Settlement</option>
                <option value="refund">Refund</option>
                <option value="adjustment">Adjustment</option>
            </select>
        </div>
        <div class="tx-field">
            <label>Direction</label>
            <select id="txFilterDirection">
                <option value="">All</option>
                <option value="in">Money In</option>
                <option value="out">Money Out</option>
            </select>
        </div>
        <div class="tx-field">
            <label>Status</label>
            <select id="txFilterStatus">
                <option value="">All</option>
                <option value="completed">Completed</option>
                <option value="paid">Paid</option>
                <option value="pending">Pending</option>
                <option value="pending_verification">Pending Verification</option>
                <option value="failed">Failed</option>
                <option value="reversed">Reversed</option>
            </select>
        </div>
        <div class="tx-field">
            <label>From</label>
            <input type="date" id="txFilterFrom">
        </div>
        <div class="tx-field">
            <label>To</label>
            <input type="date" id="txFilterTo">
        </div>
        <div class="tx-field">
            <label>Search</label>
            <input type="text" id="txFilterSearch" placeholder="Description, reference">
        </div>
        <div class="tx-filter-actions">
            <button class="tx-btn tx-btn-primary" onclick="applyFilters()">
                <i class="fas fa-filter"></i> Apply
            </button>
            <button class="tx-btn tx-btn-outline" onclick="resetFilters()">
                <i class="fas fa-redo"></i> Reset
            </button>
        </div>
    `;
}

function bindEvents() {
    ['txFilterType','txFilterDirection','txFilterStatus','txFilterFrom','txFilterTo'].forEach(id => {
        document.getElementById(id)?.addEventListener('change', applyFilters);
    });
    let t;
    document.getElementById('txFilterSearch')?.addEventListener('input', () => {
        clearTimeout(t);
        t = setTimeout(applyFilters, 400);
    });
}

function applyFilters() {
    TxState.filters = {
        type:       document.getElementById('txFilterType').value,
        direction:  document.getElementById('txFilterDirection').value,
        status:     document.getElementById('txFilterStatus').value,
        date_from:  document.getElementById('txFilterFrom').value,
        date_to:    document.getElementById('txFilterTo').value,
        search:     document.getElementById('txFilterSearch').value.trim(),
    };
    TxState.page = 1;
    loadTransactions();
}

function resetFilters() {
    document.getElementById('txFilterType').value = '';
    document.getElementById('txFilterDirection').value = '';
    document.getElementById('txFilterStatus').value = '';
    document.getElementById('txFilterFrom').value = '';
    document.getElementById('txFilterTo').value = '';
    document.getElementById('txFilterSearch').value = '';
    TxState.filters = { type: '', direction: '', status: '', date_from: '', date_to: '', search: '' };
    TxState.page = 1;
    loadTransactions();
}

async function loadTransactions() {
    const limitEl = document.getElementById('txLimit');
    if (limitEl) TxState.limit = parseInt(limitEl.value, 10);

    const loading = document.getElementById('txLoading');
    const empty = document.getElementById('txEmpty');
    const wrap = document.getElementById('txTableWrap');

    loading.style.display = 'flex';
    empty.style.display = 'none';
    wrap.style.display = 'none';

    const qs = new URLSearchParams({
        page: TxState.page,
        limit: TxState.limit,
        ...TxState.filters,
    });

    try {
        const res = await fetch(`../backend/reports/fetch_transactions.php?${qs}`);
        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'Load failed');

        TxState.rows = data.data?.transactions || [];
        renderSummary(data.data?.summary || {});
        loading.style.display = 'none';

        if (TxState.rows.length === 0) {
            empty.style.display = 'flex';
            document.getElementById('txPagination').innerHTML = '';
            return;
        }

        renderTable(TxState.rows);
        renderPagination(data.data?.pagination || {});
        wrap.style.display = 'block';
    } catch (err) {
        console.error('loadTransactions failed:', err);
        loading.style.display = 'none';
        empty.style.display = 'flex';
        empty.querySelector('p').textContent = err.message;
    }
}

function renderSummary(s) {
    const el = document.getElementById('txStats');
    if (!el) return;
    el.innerHTML = `
        <div class="tx-stat">
            <div class="tx-stat-icon tx-stat-icon--in"><i class="fas fa-arrow-down"></i></div>
            <div class="tx-stat-body">
                <span class="tx-stat-label">Money In</span>
                <span class="tx-stat-value">${formatNaira(s.total_in || 0)}</span>
            </div>
        </div>
        <div class="tx-stat">
            <div class="tx-stat-icon tx-stat-icon--out"><i class="fas fa-arrow-up"></i></div>
            <div class="tx-stat-body">
                <span class="tx-stat-label">Money Out</span>
                <span class="tx-stat-value">${formatNaira(s.total_out || 0)}</span>
            </div>
        </div>
        <div class="tx-stat">
            <div class="tx-stat-icon tx-stat-icon--net"><i class="fas fa-scale-balanced"></i></div>
            <div class="tx-stat-body">
                <span class="tx-stat-label">Net</span>
                <span class="tx-stat-value">${formatNaira(s.net || 0)}</span>
            </div>
        </div>
        <div class="tx-stat">
            <div class="tx-stat-icon tx-stat-icon--count"><i class="fas fa-list"></i></div>
            <div class="tx-stat-body">
                <span class="tx-stat-label">Transactions</span>
                <span class="tx-stat-value">${s.total_count || 0}</span>
            </div>
        </div>
    `;
}

function renderTable(rows) {
    const tbody = document.getElementById('txTableBody');
    tbody.innerHTML = rows.map((r, idx) => {
        const isIn = r.direction === 'in';
        const statusClass = statusToClass(r.status);
        const statusLabel = (r.status || 'completed').replace(/_/g, ' ').toUpperCase();
        const typeLabel = TYPE_LABELS[r.transaction_type] || r.transaction_type;
        const typeIcon = TYPE_ICONS[r.transaction_type] || 'fa-circle';
        const refCell = r.reference
            ? `<span class="tx-ref">${escapeHtml(r.reference)}</span>`
            : `<span class="tx-ref tx-ref--empty">No reference</span>`;

        return `
            <tr data-index="${idx}" tabindex="0" role="button">
                <td><span class="tx-date">${formatDate(r.transaction_date)}</span></td>
                <td><span class="tx-type"><i class="fas ${typeIcon}"></i> ${typeLabel}</span></td>
                <td class="tx-descr">${escapeHtml(r.description || '—')}</td>
                <td>${refCell}</td>
                <td class="num ${isIn ? 'in' : 'out'}">${isIn ? '+' : '−'} ${formatNaira(r.amount)}</td>
                <td><span class="tx-badge tx-badge--${statusClass}">${statusLabel}</span></td>
                <td class="tx-row-chevron"><i class="fas fa-chevron-right"></i></td>
            </tr>
        `;
    }).join('');

    tbody.querySelectorAll('tr').forEach(tr => {
        const idx = parseInt(tr.dataset.index, 10);
        const record = rows[idx];
        tr.addEventListener('click', () => openTxSlide(record));
        tr.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openTxSlide(record); }
        });
    });
}

function openTxSlide(record) {
    const slide = document.getElementById('txSlideOver');
    const body = document.getElementById('txSlideBody');
    const footer = document.getElementById('txSlideFooter');

    document.getElementById('txSlideTitle').textContent = TYPE_LABELS[record.transaction_type] || 'Transaction';
    document.getElementById('txSlideSubtitle').textContent = formatDate(record.transaction_date);

    const isIn = record.direction === 'in';
    const statusClass = statusToClass(record.status);
    const statusLabel = (record.status || '').replace(/_/g, ' ').toUpperCase();
    const amountColored = isIn ? '#059669' : '#dc2626';

    body.innerHTML = `
        <div class="tx-detail-section">
            <h4><i class="fas fa-info-circle"></i> Summary</h4>
            <div class="tx-detail-grid">
                <div class="tx-detail-field">
                    <span class="label">Amount</span>
                    <strong style="color:${amountColored};font-size:18px;">
                        ${isIn ? '+' : '−'} ${formatNaira(record.amount)}
                    </strong>
                </div>
                <div class="tx-detail-field">
                    <span class="label">Direction</span>
                    <strong>${isIn ? 'Money In' : 'Money Out'}</strong>
                </div>
                <div class="tx-detail-field">
                    <span class="label">Status</span>
                    <strong><span class="tx-badge tx-badge--${statusClass}">${statusLabel}</span></strong>
                </div>
                <div class="tx-detail-field">
                    <span class="label">Transaction Date</span>
                    <strong>${formatDateTime(record.transaction_date)}</strong>
                </div>
                <div class="tx-detail-field">
                    <span class="label">Reference</span>
                    <strong>${record.reference ? `<span class="tx-ref">${escapeHtml(record.reference)}</span>` : '<span class="tx-ref tx-ref--empty">No reference</span>'}</strong>
                </div>
                <div class="tx-detail-field">
                    <span class="label">Type</span>
                    <strong>${TYPE_LABELS[record.transaction_type] || record.transaction_type}</strong>
                </div>
            </div>
        </div>

        <div class="tx-detail-section">
            <h4><i class="fas fa-align-left"></i> Description</h4>
            <div class="tx-detail-note">${escapeHtml(record.description || 'No description recorded.')}</div>
        </div>

        ${record.balance_after !== null && record.balance_after !== undefined ? `
        <div class="tx-detail-section">
            <h4><i class="fas fa-wallet"></i> Wallet Impact</h4>
            <div class="tx-detail-grid">
                <div class="tx-detail-field">
                    <span class="label">Balance After</span>
                    <strong>${formatNaira(record.balance_after)}</strong>
                </div>
            </div>
        </div>` : ''}

        ${record.metadata && Object.keys(record.metadata).length ? `
        <div class="tx-detail-section">
            <h4><i class="fas fa-list-ul"></i> Additional Details</h4>
            <div class="tx-detail-grid">
                ${Object.entries(record.metadata)
                    .filter(([k, v]) => v !== null && v !== '' && k !== 'wallet_txn_id' && k !== 'payment_id')
                    .map(([k, v]) => `
                        <div class="tx-detail-field">
                            <span class="label">${escapeHtml(k.replace(/_/g, ' '))}</span>
                            <strong>${escapeHtml(String(v))}</strong>
                        </div>
                    `).join('')}
            </div>
        </div>` : ''}
    `;

    footer.innerHTML = `
        <button class="tx-btn tx-btn-outline" onclick="closeTxSlide()">Close</button>
    `;

    slide.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeTxSlide() {
    document.getElementById('txSlideOver').classList.remove('show');
    document.body.style.overflow = '';
}

function renderPagination(p) {
    const el = document.getElementById('txPagination');
    if (!p || p.total_pages <= 1) { el.innerHTML = ''; return; }

    const cur = p.page, max = p.total_pages;
    let html = `<div class="tx-page-info">Showing ${(cur - 1) * p.limit + 1}–${Math.min(cur * p.limit, p.total)} of ${p.total}</div><div class="tx-page-btns">`;

    if (cur > 1) html += `<button class="tx-page-btn" onclick="goPage(${cur - 1})"><i class="fas fa-chevron-left"></i></button>`;
    const start = Math.max(1, cur - 2);
    const end = Math.min(max, cur + 2);
    for (let i = start; i <= end; i++) {
        html += `<button class="tx-page-btn ${i === cur ? 'active' : ''}" onclick="goPage(${i})">${i}</button>`;
    }
    if (cur < max) html += `<button class="tx-page-btn" onclick="goPage(${cur + 1})"><i class="fas fa-chevron-right"></i></button>`;
    html += `</div>`;
    el.innerHTML = html;
}

function goPage(n) {
    TxState.page = Math.max(1, n);
    loadTransactions();
}

// ---------- Exports ----------
// ---------- Exports ----------
async function exportExcel() {
    const confirmed = await showStatementConfirm('excel');
    if (!confirmed) return;

    const qs = new URLSearchParams({ ...TxState.filters, export: '1' });
    window.location.href = `../backend/reports/export_transactions.php?${qs}`;
}

async function exportPdf() {
    const confirmed = await showStatementConfirm('pdf');
    if (!confirmed) return;

    // Fetch metadata + all filtered rows for the PDF
    let metadata, allRows;
    try {
        const [metaRes, rowsRes] = await Promise.all([
            fetch('../backend/reports/get_statement_metadata.php'),
            fetch(`../backend/reports/fetch_transactions.php?${new URLSearchParams({ ...TxState.filters, export: '1' })}`),
        ]);
        const metaData = await metaRes.json();
        const rowsData = await rowsRes.json();

        if (!metaData.success) throw new Error(metaData.message || 'Failed to load metadata');
        if (!rowsData.success) throw new Error(rowsData.message || 'Failed to load transactions');

        metadata = metaData.data;
        allRows = rowsData.data.transactions || [];
    } catch (err) {
        alert('Could not prepare statement: ' + err.message);
        return;
    }

    renderStatementPrintWindow(metadata, allRows);
}

// Confirm modal before exporting
function showStatementConfirm(format) {
    return new Promise((resolve) => {
        const overlay = document.createElement('div');
        overlay.style.cssText = `
            position: fixed; inset: 0; z-index: 9999;
            background: rgba(15,23,42,0.55); backdrop-filter: blur(3px);
            display: flex; align-items: center; justify-content: center; padding: 20px;
        `;
        overlay.innerHTML = `
            <div style="background:#fff;border-radius:12px;max-width:480px;width:100%;box-shadow:0 20px 60px rgba(15,23,42,.25);overflow:hidden;">
                <div style="padding:20px 24px;border-bottom:1px solid #e8edf5;background:#f8fafc;">
                    <h3 style="margin:0;font-size:17px;font-weight:700;color:#0f172a;">
                        <i class="fas fa-file-${format === 'pdf' ? 'pdf' : 'excel'}" style="color:#1e3c72;margin-right:8px;"></i>
                        Export Statement as ${format.toUpperCase()}
                    </h3>
                </div>
                <div style="padding:20px 24px;">
                    <p style="margin:0 0 12px;color:#334155;font-size:13px;line-height:1.6;">
                        The statement will include:
                    </p>
                    <ul style="margin:0 0 16px;padding-left:20px;color:#475569;font-size:13px;line-height:1.8;">
                        <li>Your tenant identity and contact information</li>
                        <li>Property and apartment details</li>
                        <li>The filters you've currently applied</li>
                        <li>Summary totals (money in, money out, net)</li>
                        <li>All ${TxState.rows.length ? 'matching' : 'available'} transaction rows</li>
                    </ul>
                    <div style="background:#eff6ff;border-left:3px solid #1e3c72;padding:10px 12px;border-radius:6px;font-size:12px;color:#1e40af;">
                        <i class="fas fa-info-circle"></i>
                        ${Object.values(TxState.filters).some(v => v) ? 'Only transactions matching your active filters will appear.' : 'No filters active — all transactions will be included.'}
                    </div>
                </div>
                <div style="padding:14px 24px;background:#f8fafc;border-top:1px solid #e8edf5;display:flex;justify-content:flex-end;gap:8px;">
                    <button id="stmtCancel" style="padding:9px 18px;border-radius:8px;border:1px solid #dbe3ef;background:#fff;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;color:#0f172a;">
                        Cancel
                    </button>
                    <button id="stmtConfirm" style="padding:9px 18px;border-radius:8px;border:none;background:#1e3c72;color:#fff;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;">
                        <i class="fas fa-download"></i> Download
                    </button>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);

        const cleanup = (result) => {
            overlay.remove();
            resolve(result);
        };
        overlay.querySelector('#stmtCancel').onclick = () => cleanup(false);
        overlay.querySelector('#stmtConfirm').onclick = () => cleanup(true);
        overlay.onclick = (e) => { if (e.target === overlay) cleanup(false); };
        document.addEventListener('keydown', function esc(e) {
            if (e.key === 'Escape') { document.removeEventListener('keydown', esc); cleanup(false); }
        });
    });
}

// Render the print window for PDF with full statement header
function renderStatementPrintWindow(meta, rows) {
    const f = TxState.filters;
    const activeFilters = [];
    if (f.type)      activeFilters.push(`Type: ${TYPE_LABELS[f.type] || f.type}`);
    if (f.direction) activeFilters.push(`Direction: ${f.direction === 'in' ? 'Money In' : 'Money Out'}`);
    if (f.status)    activeFilters.push(`Status: ${f.status.replace(/_/g,' ').replace(/\b\w/g, c => c.toUpperCase())}`);
    if (f.date_from) activeFilters.push(`From: ${f.date_from}`);
    if (f.date_to)   activeFilters.push(`To: ${f.date_to}`);
    if (f.search)    activeFilters.push(`Search: "${f.search}"`);
    const filterSummary = activeFilters.length ? activeFilters.join(' · ') : 'None — showing all transactions';

    const totalIn = rows.filter(r => r.direction === 'in').reduce((s, r) => s + Number(r.amount || 0), 0);
    const totalOut = rows.filter(r => r.direction === 'out').reduce((s, r) => s + Number(r.amount || 0), 0);
    const net = totalIn - totalOut;

    const rowsHtml = rows.map(r => `
        <tr>
            <td>${formatDate(r.transaction_date)}</td>
            <td>${TYPE_LABELS[r.transaction_type] || r.transaction_type}</td>
            <td>${escapeHtml(r.description || '')}</td>
            <td style="font-family:monospace;font-size:10px;">${escapeHtml(r.reference || '')}</td>
            <td style="text-align:right;color:${r.direction==='in'?'#059669':'#dc2626'};font-weight:600;">
                ${r.direction === 'in' ? '+' : '−'}${formatNaira(r.amount)}
            </td>
            <td>${(r.status || '').replace(/_/g,' ').toUpperCase()}</td>
            <td style="text-align:right;">${r.balance_after !== null && r.balance_after !== undefined ? formatNaira(r.balance_after) : '—'}</td>
        </tr>
    `).join('');

    const win = window.open('', '_blank');
    win.document.write(`
        <html><head><title>Transaction Statement — ${escapeHtml(meta.tenant.full_name)}</title>
        <style>
            @page { margin: 20mm 15mm; }
            body { font-family: 'Inter', Arial, sans-serif; color: #111; margin: 0; }
            .header { border-bottom: 3px solid #1e3c72; padding-bottom: 16px; margin-bottom: 20px; }
            .header h1 { margin: 0 0 4px; font-size: 22px; color: #1e3c72; }
            .header .sub { color: #666; font-size: 11px; }
            .section { margin-bottom: 16px; page-break-inside: avoid; }
            .section h3 { margin: 0 0 8px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #1e3c72; border-bottom: 1px solid #e8edf5; padding-bottom: 4px; }
            .kv { display: grid; grid-template-columns: 140px 1fr; gap: 4px 12px; font-size: 11px; }
            .kv .k { color: #666; font-weight: 600; }
            .kv .v { color: #111; }
            .summary { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin: 16px 0; }
            .summary-card { padding: 10px; border-radius: 8px; background: #f8fafc; border-left: 3px solid #1e3c72; }
            .summary-card .lbl { font-size: 9px; color: #666; text-transform: uppercase; letter-spacing: 0.4px; }
            .summary-card .val { font-size: 14px; font-weight: 700; margin-top: 2px; }
            table { width: 100%; border-collapse: collapse; font-size: 10px; }
            th { background: #1e3c72; color: #fff; padding: 6px 8px; text-align: left; font-weight: 600; }
            td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
            tr:nth-child(even) td { background: #fafbfc; }
            .footer { margin-top: 24px; padding-top: 12px; border-top: 1px solid #e5e7eb; font-size: 9px; color: #999; text-align: center; }
            @media print { .no-print { display: none; } }
        </style></head>
        <body>

            <div class="header">
                <h1>Transaction Statement</h1>
                <div class="sub">
                    Generated ${new Date().toLocaleString()} ·
                    ${rows.length} transaction${rows.length === 1 ? '' : 's'} ·
                    RentFlow Pro Tenant Portal
                </div>
            </div>

            <div class="section">
                <h3>Tenant</h3>
                <div class="kv">
                    <span class="k">Name</span><span class="v">${escapeHtml(meta.tenant.full_name)}</span>
                    <span class="k">Tenant Code</span><span class="v">${escapeHtml(meta.tenant.code)}</span>
                    <span class="k">Email</span><span class="v">${escapeHtml(meta.tenant.email || '—')}</span>
                    <span class="k">Phone</span><span class="v">${escapeHtml(meta.tenant.phone || '—')}</span>
                </div>
            </div>

            <div class="section">
                <h3>Property &amp; Apartment</h3>
                <div class="kv">
                    <span class="k">Property</span><span class="v">${escapeHtml(meta.property.name)} (${escapeHtml(meta.property.code || '')})</span>
                    <span class="k">Address</span><span class="v">${escapeHtml(meta.property.address || '—')}</span>
                    <span class="k">Apartment</span><span class="v">Apt ${escapeHtml(meta.apartment.number || '—')} (${escapeHtml(meta.apartment.code || '')})</span>
                    <span class="k">Lease Period</span><span class="v">${formatDate(meta.lease.start_date)} – ${formatDate(meta.lease.end_date)}</span>
                    <span class="k">Rent / Frequency</span><span class="v">${formatNaira(meta.lease.agreed_rent_amount)} · ${escapeHtml(meta.lease.payment_frequency || '')}</span>
                </div>
            </div>

            <div class="section">
                <h3>Filters Applied</h3>
                <div style="font-size:11px;color:#333;">${escapeHtml(filterSummary)}</div>
            </div>

            <div class="section">
                <h3>Summary</h3>
                <div class="summary">
                    <div class="summary-card">
                        <div class="lbl">Money In</div>
                        <div class="val" style="color:#059669;">${formatNaira(totalIn)}</div>
                    </div>
                    <div class="summary-card">
                        <div class="lbl">Money Out</div>
                        <div class="val" style="color:#dc2626;">${formatNaira(totalOut)}</div>
                    </div>
                    <div class="summary-card">
                        <div class="lbl">Net</div>
                        <div class="val">${formatNaira(net)}</div>
                    </div>
                    <div class="summary-card">
                        <div class="lbl">Transactions</div>
                        <div class="val">${rows.length}</div>
                    </div>
                </div>
            </div>

            <div class="section">
                <h3>Transactions (${rows.length})</h3>
                <table>
                    <thead><tr>
                        <th>Date</th><th>Type</th><th>Description</th><th>Reference</th>
                        <th style="text-align:right;">Amount</th><th>Status</th><th style="text-align:right;">Balance</th>
                    </tr></thead>
                    <tbody>${rowsHtml}</tbody>
                </table>
            </div>

            <div class="footer">
                This statement was generated automatically from the RentFlow Pro system and reflects all transactions recorded up to ${new Date().toLocaleString()}.
                For questions or discrepancies, contact support@rentflowpro.com.
            </div>

            <script>window.onload = () => window.print();</script>
        </body></html>
    `);
    win.document.close();
}

// ---------- Helpers ----------
function statusToClass(s) {
    const x = (s || '').toLowerCase();
    if (['completed','paid','approved','success'].includes(x)) return 'success';
    if (['pending','pending_verification','processing'].includes(x)) return 'warning';
    if (['failed','rejected','reversed'].includes(x)) return 'danger';
    return 'neutral';
}
function formatNaira(n) {
    return '₦' + Number(n || 0).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function formatDate(d) {
    if (!d) return '—';
    const dt = new Date(d);
    if (isNaN(dt)) return d;
    return dt.toLocaleDateString('en-NG', { year: 'numeric', month: 'short', day: 'numeric' });
}
function formatDateTime(d) {
    if (!d) return '—';
    const dt = new Date(d);
    if (isNaN(dt)) return dt;
    return dt.toLocaleString('en-NG', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}
function escapeHtml(t) {
    if (t === null || t === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(t);
    return div.innerHTML;
}