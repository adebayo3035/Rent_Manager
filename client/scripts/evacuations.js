// client/scripts/evacuations.js

const EvacState = {
  page: 1,
  limit: 20,
  filters: {
    search: "",
    property_code: "",
    date_from: "",
    date_to: "",
    debt: "",
  },
  properties: [],
};

document.addEventListener("DOMContentLoaded", () => {
  renderEvacuationsPage();
  bindFilterEvents();
  loadProperties();
  loadEvacuations(1);
});

// ---------- Render page shell ----------
function renderEvacuationsPage() {
  const contentArea = document.getElementById("contentArea");
  if (!contentArea) return;

  contentArea.innerHTML = `
        <div class="evac-container">
            <div class="page-header">
                <div>
                    <h1><i class="fas fa-house-circle-xmark"></i> Evacuations</h1>
                    <p>Monitor tenancies that ended and their settlements</p>
                </div>
                <div class="page-header-actions">
                    <button class="btn-export" onclick="exportEvacuations()">
                        <i class="fas fa-download"></i> Export
                    </button>
                </div>
            </div>

            <div class="summary-cards" id="evacSummaryCards">
                <div class="loading-overlay"><div class="loading-spinner"></div></div>
            </div>

            <div class="filters-bar">
                <div class="filters-row">
                    <div class="filter-group">
                        <label>Search</label>
                        <input type="text" id="evacSearch" placeholder="Tenant name, code, or reference">
                    </div>
                    <div class="filter-group">
                        <label>Property</label>
                        <select id="evacProperty">
                            <option value="">All Properties</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Date From</label>
                        <input type="date" id="evacDateFrom">
                    </div>
                    <div class="filter-group">
                        <label>Date To</label>
                        <input type="date" id="evacDateTo">
                    </div>
                    <div class="filter-group">
                        <label>Debt Status</label>
                        <select id="evacDebt">
                            <option value="">All</option>
                            <option value="with_debt">With outstanding debt</option>
                            <option value="clear">No debt</option>
                        </select>
                    </div>
                    <div class="filter-actions">
                        <button class="btn-apply" onclick="applyFilters()">
                            <i class="fas fa-search"></i> Apply
                        </button>
                        <button class="btn-reset" onclick="resetFilters()">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                    </div>
                </div>
            </div>

            <div id="evacLoading" class="loading-overlay" style="display:none;">
                <div class="loading-spinner"></div>
                <p>Loading evacuations...</p>
            </div>

            <div id="evacEmpty" class="evac-empty" style="display:none;">
                <i class="fas fa-inbox"></i>
                <h3>No evacuations found</h3>
                <p>No tenants have evacuated under the selected filters.</p>
            </div>

            <div id="evacGrid" class="evac-grid" style="display:none;"></div>

            <div id="evacPagination" class="pagination"></div>
        </div>

        <!-- Slide-over panel -->
        <div id="evacSlideOver" class="evac-slide-over" role="dialog" aria-modal="true" aria-labelledby="evacSlideOverTitle">
            <div class="evac-slide-over__backdrop" onclick="closeEvacSlideOver()"></div>
            <aside class="evac-slide-over__panel" onclick="event.stopPropagation()">
                <div class="evac-slide-over__header">
                    <div>
                        <h2 id="evacSlideOverTitle">Evacuation Details</h2>
                        <p id="evacSlideOverSubtitle"></p>
                    </div>
                    <button class="evac-slide-over__close" onclick="closeEvacSlideOver()" aria-label="Close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="evac-slide-over__body" id="evacSlideOverBody">
                    <div class="loading-overlay" style="display:flex;">
                        <div class="loading-spinner"></div>
                        <p>Loading details...</p>
                    </div>
                </div>
                <div class="evac-slide-over__footer" id="evacSlideOverFooter"></div>
            </aside>
        </div>
    `;
}

// ---------- Filters ----------
function bindFilterEvents() {
  const $ = (id) => document.getElementById(id);
  $("evacProperty")?.addEventListener("change", applyFilters);
  $("evacDebt")?.addEventListener("change", applyFilters);
  $("evacDateFrom")?.addEventListener("change", applyFilters);
  $("evacDateTo")?.addEventListener("change", applyFilters);

  let t;
  $("evacSearch")?.addEventListener("input", () => {
    clearTimeout(t);
    t = setTimeout(applyFilters, 400);
  });

  document.addEventListener("keydown", (e) => {
    if (
      e.key === "Escape" &&
      document.getElementById("evacSlideOver")?.classList.contains("show")
    ) {
      closeEvacSlideOver();
    }
  });
}

async function loadProperties() {
  try {
    const res = await fetch(
      "../backend/dashboard/fetch_properties.php?limit=100",
    );
    const data = await res.json();
    // Accept multiple response shapes; the backend may or may not use the {data: {...}} envelope.
    const list = data.success
      ? data.data?.properties || data.data || data.properties || []
      : [];
    EvacState.properties = Array.isArray(list) ? list : [];

    const sel = document.getElementById("evacProperty");
    if (sel && EvacState.properties.length) {
      sel.innerHTML =
        '<option value="">All Properties</option>' +
        EvacState.properties
          .map(
            (p) => `
                    <option value="${escapeAttribute(p.property_code)}">
                        ${escapeHtml(p.name || p.property_code)}
                    </option>
                `,
          )
          .join("");
    }
  } catch (err) {
    console.warn("Could not load properties:", err);
  }
}

function applyFilters() {
  const $ = (id) => document.getElementById(id);
  EvacState.filters.search = ($("evacSearch")?.value || "").trim();
  EvacState.filters.property_code = $("evacProperty")?.value || "";
  EvacState.filters.date_from = $("evacDateFrom")?.value || "";
  EvacState.filters.date_to = $("evacDateTo")?.value || "";
  EvacState.filters.debt = $("evacDebt")?.value || "";
  loadEvacuations(1);
}

function resetFilters() {
  const $ = (id) => document.getElementById(id);
  $("evacSearch").value = "";
  $("evacProperty").value = "";
  $("evacDateFrom").value = "";
  $("evacDateTo").value = "";
  $("evacDebt").value = "";
  EvacState.filters = {
    search: "",
    property_code: "",
    date_from: "",
    date_to: "",
    debt: "",
  };
  loadEvacuations(1);
}

// ---------- Load list ----------  ◄── THIS IS THE FIX
async function loadEvacuations(page) {
  EvacState.page = page || 1;

  const loading = document.getElementById("evacLoading");
  const empty = document.getElementById("evacEmpty");
  const grid = document.getElementById("evacGrid");

  loading.style.display = "flex";
  empty.style.display = "none";
  grid.style.display = "none";

  const qs = new URLSearchParams({
    page: EvacState.page,
    limit: EvacState.limit,
  });
  Object.entries(EvacState.filters).forEach(([k, v]) => {
    if (v) qs.set(k, v);
  });

  try {
    const res = await fetch(
      `../backend/evacuations/fetch_evacuations.php?${qs}`,
    );
    const data = await res.json();

    if (!data.success) {
      throw new Error(data.message || "Failed to load evacuations");
    }

    // ✅ FIX: unwrap the { success, data: {...} } envelope
    const payload = data.data || data; // fall back to data itself if backend ever flattens it

    const stats = payload.stats || {};
    const rows = Array.isArray(payload.evacuations) ? payload.evacuations : [];
    const pagination = payload.pagination || {};

    renderSummary(stats);

    if (!rows.length) {
      loading.style.display = "none";
      empty.style.display = "flex";
      grid.innerHTML = "";
      renderPagination(pagination);
      return;
    }

    grid.innerHTML = rows.map(renderEvacCard).join("");
    loading.style.display = "none";
    grid.style.display = "grid";
    renderPagination(pagination);
  } catch (err) {
    console.error("Load evacuations failed:", err);
    loading.style.display = "none";
    empty.style.display = "flex";
    empty.querySelector("h3").textContent = "Could not load evacuations";
    empty.querySelector("p").textContent = err.message;
  }
}

// ---------- Summary ----------
function renderSummary(stats) {
  const el = document.getElementById("evacSummaryCards");
  if (!el) return;

  stats = stats || {}; // ✅ guard even if stats is missing

  el.innerHTML = `
        <div class="summary-card total">
            <div class="summary-icon"><i class="fas fa-house-circle-xmark"></i></div>
            <div class="summary-info">
                <div class="summary-label">Total Evacuated</div>
                <div class="summary-value">${stats.total_evacuated || 0}</div>
            </div>
        </div>
        <div class="summary-card last-30">
            <div class="summary-icon"><i class="fas fa-clock"></i></div>
            <div class="summary-info">
                <div class="summary-label">Last 30 Days</div>
                <div class="summary-value">${stats.evacuated_last_30d || 0}</div>
            </div>
        </div>
        <div class="summary-card settled">
            <div class="summary-icon"><i class="fas fa-check-circle"></i></div>
            <div class="summary-info">
                <div class="summary-label">Total Settled</div>
                <div class="summary-value">${formatCurrency(stats.total_settled)}</div>
            </div>
        </div>
        <div class="summary-card debt">
            <div class="summary-icon"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="summary-info">
                <div class="summary-label">Outstanding Debt</div>
                <div class="summary-value">${formatCurrency(stats.total_outstanding_debt)}</div>
            </div>
        </div>
    `;
}

// ---------- Card renderer ----------
function renderEvacCard(r) {
  const debtBadge =
    Number(r.current_fees_count) > 0
      ? `<span class="evac-badge evac-badge--danger">
              <i class="fas fa-exclamation-triangle"></i>
              ${r.current_fees_count} fee${r.current_fees_count === 1 ? "" : "s"}
           </span>`
      : `<span class="evac-badge evac-badge--success">
              <i class="fas fa-check"></i> No debt
           </span>`;

  const settlementLine =
    r.final_settlement_amount != null
      ? `<div class="evac-row"><span>Final Settlement</span><strong>${formatCurrency(r.final_settlement_amount)}</strong></div>`
      : "";

  return `
        <div class="evac-card"
             role="button"
             tabindex="0"
             onclick="openEvacSlideOver('${escapeAttribute(r.request_id)}')"
             onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openEvacSlideOver('${escapeAttribute(r.request_id)}');}">
            <div class="evac-card-header">
                <div class="evac-avatar">
                    ${
                      r.photo
                        ? `<img src="../../admin/backend/tenants/tenant_photos/${escapeAttribute(r.photo)}" alt="">`
                        : `<span>${escapeHtml((r.tenant_name || "?").charAt(0))}</span>`
                    }
                </div>
                <div class="evac-card-title">
                    <h3>${escapeHtml(r.tenant_name)}</h3>
                    <p>${escapeHtml(r.property_name)} &middot; Apt ${escapeHtml(r.apartment_number || "—")}</p>
                </div>
                ${debtBadge}
            </div>
            <div class="evac-card-body">
                <div class="evac-row"><span>Tenant Code</span><strong>${escapeHtml(r.tenant_code)}</strong></div>
                <div class="evac-row"><span>Move-out</span><strong>${formatDate(r.move_out_date)}</strong></div>
                <div class="evac-row"><span>Processed</span><strong>${formatDate(r.processed_at)}</strong></div>
                <div class="evac-row"><span>Deposit Refund</span><strong>${formatCurrency(r.security_deposit_refund)}</strong></div>
                ${settlementLine}
            </div>
            <div class="evac-card-footer">
                <span class="evac-ref"><i class="fas fa-hashtag"></i> ${escapeHtml(r.request_id || "—")}</span>
                <span class="evac-view">View <i class="fas fa-arrow-right"></i></span>
            </div>
        </div>
    `;
}

// ---------- Pagination ----------
function renderPagination(p) {
  const el = document.getElementById("evacPagination");
  if (!el) return;
  if (!p || p.total_pages <= 1) {
    el.innerHTML = "";
    return;
  }

  const cur = p.page,
    max = p.total_pages;
  let html = `
        <div class="pagination-info">
            Showing ${Math.min(p.total, (cur - 1) * p.limit + 1)} to
            ${Math.min(p.total, cur * p.limit)} of ${p.total} evacuations
        </div>
        <div class="pagination-controls">
    `;

  if (cur > 1) {
    html += `<button class="page-btn" onclick="loadEvacuations(${cur - 1})"><i class="fas fa-angle-left"></i></button>`;
  }
  const start = Math.max(1, cur - 2);
  const end = Math.min(max, cur + 2);
  for (let i = start; i <= end; i++) {
    html += `<button class="page-btn ${i === cur ? "active" : ""}" onclick="loadEvacuations(${i})">${i}</button>`;
  }
  if (cur < max) {
    html += `<button class="page-btn" onclick="loadEvacuations(${cur + 1})"><i class="fas fa-angle-right"></i></button>`;
  }

  html += `</div>`;
  el.innerHTML = html;
}

// ---------- Slide-over ----------
async function openEvacSlideOver(requestId) {
  const overlay = document.getElementById("evacSlideOver");
  const body = document.getElementById("evacSlideOverBody");
  const footer = document.getElementById("evacSlideOverFooter");
  const title = document.getElementById("evacSlideOverTitle");
  const subtitle = document.getElementById("evacSlideOverSubtitle");

  title.textContent = "Evacuation Details";
  subtitle.textContent = "";
  body.innerHTML = `
        <div class="loading-overlay" style="display:flex;">
            <div class="loading-spinner"></div>
            <p>Loading details...</p>
        </div>
    `;
  footer.innerHTML = "";
  overlay.classList.add("show");
  document.body.style.overflow = "hidden";

  try {
    const res = await fetch(
      `../backend/evacuations/fetch_evacuation_details.php?request_id=${encodeURIComponent(requestId)}`,
    );
    const data = await res.json();
    if (!data.success)
      throw new Error(data.message || "Failed to load details");

    // ✅ Same fix — unwrap `data.data`
    const payload = data.data || data;
    renderEvacSlideOverBody(payload);
  } catch (err) {
    body.innerHTML = `
            <div class="evac-empty" style="display:flex;">
                <i class="fas fa-exclamation-circle"></i>
                <h3>Could not load details</h3>
                <p>${escapeHtml(err.message)}</p>
            </div>
        `;
  }
}

function closeEvacSlideOver() {
  document.getElementById("evacSlideOver").classList.remove("show");
  document.body.style.overflow = "";
}

function renderEvacSlideOverBody(d) {
  const req = d.request || {};
  const t = d.tenant || {};
  const p = d.property || {};
  const fin = d.financials || {};
  const s = d.settlement;
  const debts = d.outstanding_fees || [];
  const txns = d.wallet_transactions || [];
  const deductions = d.deductions || [];

  // ---------- Header ----------
  document.getElementById("evacSlideOverTitle").textContent =
    `Evacuation — ${t.full_name || "Tenant"}`;

  document.getElementById("evacSlideOverSubtitle").textContent = [
    p.property_name,
    p.apartment_number ? `Apt ${p.apartment_number}` : null,
    t.tenant_code,
  ]
    .filter(Boolean)
    .join(" · ");

  // ---------- Section 1: Tenant ----------
  const tenantSection = `
        <div class="evac-section">
            <h4><i class="fas fa-user"></i> Tenant</h4>
            <div class="evac-section-grid">
                <div class="evac-field"><span class="label">Full Name</span><strong>${escapeHtml(t.full_name || "—")}</strong></div>
                <div class="evac-field"><span class="label">Tenant Code</span><strong>${escapeHtml(t.tenant_code || "—")}</strong></div>
                <div class="evac-field"><span class="label">Email</span><strong>${escapeHtml(t.email || "—")}</strong></div>
                <div class="evac-field"><span class="label">Phone</span><strong>${escapeHtml(t.phone || "—")}</strong></div>
                <div class="evac-field"><span class="label">Gender</span><strong>${escapeHtml(t.gender || "—")}</strong></div>
                <div class="evac-field"><span class="label">Property</span><strong>${escapeHtml(p.property_name || "—")}</strong></div>
                <div class="evac-field"><span class="label">Apartment</span><strong>${escapeHtml(p.apartment_number || "—")}</strong></div>
                <div class="evac-field"><span class="label">Lease Period</span><strong>${formatDate(t.lease_start_date)} &ndash; ${formatDate(t.lease_end_date)}</strong></div>
                <div class="evac-field"><span class="label">Payment Frequency</span><strong>${escapeHtml(t.payment_frequency || "—")}</strong></div>
            </div>
        </div>
    `;

  // ---------- Section 2: Evacuation ----------
  const statusBadgeClass =
    req.status === "rejected" ? "evac-badge--danger" : "evac-badge--success";
  const statusLabel = (req.status || "—")
    .replace(/_/g, " ")
    .replace(/\b\w/g, (c) => c.toUpperCase());

  const evacuationSection = `
    <div class="evac-section">
        <h4><i class="fas fa-sign-out-alt"></i> Evacuation</h4>
        <div class="evac-section-grid">
            <div class="evac-field"><span class="label">Request Reference</span><strong>${escapeHtml(req.request_id || "—")}</strong></div>
            <div class="evac-field"><span class="label">Status</span><strong><span class="evac-badge ${statusBadgeClass}">${escapeHtml(statusLabel)}</span></strong></div>
            <div class="evac-field"><span class="label">Requested Move-out</span><strong>${formatDate(req.requested_move_out_date)}</strong></div>
            <div class="evac-field"><span class="label">Approved Move-out</span><strong>${formatDate(req.approved_move_out_date)}</strong></div>
            <div class="evac-field"><span class="label">Reviewed At</span><strong>${formatDateTime(req.reviewed_at)}</strong></div>
            <div class="evac-field"><span class="label">Processed At</span><strong>${formatDateTime(req.processed_at)}</strong></div>
            ${req.reviewed_by_name ? `<div class="evac-field"><span class="label">Reviewed By</span><strong>${escapeHtml(req.reviewed_by_name)}</strong></div>` : ""}
            ${req.processed_by_name ? `<div class="evac-field"><span class="label">Processed By</span><strong>${escapeHtml(req.processed_by_name)}</strong></div>` : ""}
            ${req.settlement_transaction_id ? `<div class="evac-field"><span class="label">Settlement Transaction</span><strong><code>${escapeHtml(req.settlement_transaction_id)}</code></strong></div>` : ""}
        </div>
        ${req.reason ? `<div class="evac-note" style="margin-top: 14px;"><strong>Reason:</strong> ${escapeHtml(req.reason)}</div>` : ""}
        ${req.admin_notes ? `<div class="evac-note" style="margin-top: 8px;"><strong>Admin notes:</strong> ${escapeHtml(req.admin_notes)}</div>` : ""}
    </div>
  `;

  // ---------- Section 3: Financials ----------
  const evacuationFeeLine =
    Number(fin.early_termination_fee_applicable) === 1 ||
    fin.early_termination_fee_applicable === true
      ? `<div class="evac-field"><span class="label">Early Termination Fee</span><strong>${formatCurrency(fin.early_termination_fee)}</strong></div>`
      : `<div class="evac-field"><span class="label">Early Termination Fee</span><strong>N/A</strong></div>`;

  const financialsSection = `
        <div class="evac-section">
            <h4><i class="fas fa-coins"></i> Rent Reconciliation</h4>
            <div class="evac-section-grid">
                <div class="evac-field"><span class="label">Cycle Rent Amount</span><strong>${formatCurrency(fin.cycle_rent_amount)}</strong></div>
                <div class="evac-field"><span class="label">Total Paid in Cycle</span><strong>${formatCurrency(fin.total_paid_in_cycle)}</strong></div>
                <div class="evac-field"><span class="label">Days Used / Total</span><strong>${Number(fin.cycle_days_used) || 0} / ${Number(fin.cycle_total_days) || 0}</strong></div>
                <div class="evac-field"><span class="label">Rent Used</span><strong>${formatCurrency(fin.rent_used)}</strong></div>
                <div class="evac-field"><span class="label">Unused Rent</span><strong>${formatCurrency(fin.unused_rent)}</strong></div>
                <div class="evac-field"><span class="label">Tenant Share</span><strong>${formatCurrency(fin.tenant_rent_share)}</strong></div>
                <div class="evac-field"><span class="label">Landlord (Your) Share</span><strong>${formatCurrency(fin.landlord_rent_share)}</strong></div>
                ${evacuationFeeLine}
            </div>
        </div>
        <div class="evac-section">
            <h4><i class="fas fa-tools"></i> Damages</h4>
            <div class="evac-section-grid">
                <div class="evac-field"><span class="label">Total Damages</span><strong>${formatCurrency(fin.damages_total)}</strong></div>
                <div class="evac-field"><span class="label">Covered by Deposit</span><strong>${formatCurrency(fin.damages_from_deposit)}</strong></div>
                <div class="evac-field"><span class="label">Covered by Share</span><strong>${formatCurrency(fin.damages_from_share)}</strong></div>
                <div class="evac-field"><span class="label">Owed by Tenant</span><strong>${formatCurrency(fin.damages_owed_by_tenant)}</strong></div>
            </div>
        </div>
        <div class="evac-section">
            <h4><i class="fas fa-hand-holding-usd"></i> Final Settlement</h4>
            <div class="evac-section-grid">
                <div class="evac-field"><span class="label">Final Settlement Amount</span><strong>${formatCurrency(fin.final_settlement_amount)}</strong></div>
                <div class="evac-field"><span class="label">Security Deposit Refund</span><strong>${formatCurrency(fin.security_deposit_refund)}</strong></div>
                <div class="evac-field"><span class="label">Outstanding at Evacuation</span><strong>${Number(fin.has_outstanding_balance) === 1 ? formatCurrency(fin.outstanding_amount) : "None"}</strong></div>
                ${fin.settlement_receipt_number ? `<div class="evac-field"><span class="label">Receipt Number</span><strong>${escapeHtml(fin.settlement_receipt_number)}</strong></div>` : ""}
            </div>
        </div>
    `;

  // ---------- Section 4: Deductions ----------
  const deductionsSection = deductions.length
    ? `
        <div class="evac-section">
            <h4><i class="fas fa-minus-circle"></i> Deductions</h4>
            <table class="evac-table">
                <thead><tr><th>Type</th><th>Description</th><th class="num">Amount</th></tr></thead>
                <tbody>
                    ${deductions
                      .map(
                        (x) => `
                        <tr>
                            <td>${escapeHtml(x.deduction_type || "—")}</td>
                            <td>${escapeHtml(x.description || "—")}</td>
                            <td class="num">${formatCurrency(x.amount)}</td>
                        </tr>
                    `,
                      )
                      .join("")}
                </tbody>
                <tfoot><tr><th colspan="2" style="text-align:right;">Total Deductions</th><th class="num">${formatCurrency(d.deductions_total || 0)}</th></tr></tfoot>
            </table>
        </div>`
    : `
        <div class="evac-section">
            <h4><i class="fas fa-minus-circle"></i> Deductions</h4>
            <p style="color:#64748b; margin:0;">No deductions recorded for this evacuation.</p>
        </div>`;

  // ---------- Section 5: Outstanding Fees ----------
  const feesSection = debts.length
    ? `
        <div class="evac-section">
            <h4><i class="fas fa-exclamation-circle"></i> Outstanding Fees (Current)</h4>
            <table class="evac-table">
                <thead><tr><th>Fee</th><th>Due Date</th><th>Status</th><th class="num">Amount</th></tr></thead>
                <tbody>
                    ${debts
                      .map(
                        (f) => `
                        <tr>
                            <td>${escapeHtml(f.fee_type_name || "Unnamed Fee")}</td>
                            <td>${formatDate(f.due_date)}</td>
                            <td><span class="evac-badge ${f.status === "overdue" ? "evac-badge--danger" : "evac-badge--success"}">${escapeHtml((f.status || "").toUpperCase())}</span></td>
                            <td class="num">${formatCurrency(f.amount)}</td>
                        </tr>
                    `,
                      )
                      .join("")}
                </tbody>
                <tfoot><tr><th colspan="3" style="text-align:right;">Total Outstanding</th><th class="num">${formatCurrency(d.outstanding_fees_total || 0)}</th></tr></tfoot>
            </table>
        </div>`
    : `
        <div class="evac-section">
            <h4><i class="fas fa-exclamation-circle"></i> Outstanding Fees (Current)</h4>
            <p style="color:#64748b; margin:0;">No outstanding fees. Tenant is fully settled.</p>
        </div>`;

  // ---------- Section 6: Settlement Transaction ----------
  const settlementSection = s
    ? `
        <div class="evac-section">
            <h4><i class="fas fa-file-invoice-dollar"></i> Settlement Transaction</h4>
            <div class="evac-section-grid">
                <div class="evac-field"><span class="label">Rent Payment ID</span><strong>${escapeHtml(s.rent_payment_id || "—")}</strong></div>
                <div class="evac-field"><span class="label">Status</span><strong><span class="evac-badge ${s.settlement_status === "completed" ? "evac-badge--success" : "evac-badge--danger"}">${escapeHtml((s.settlement_status || "").toUpperCase())}</span></strong></div>
                <div class="evac-field"><span class="label">Total Rent Amount</span><strong>${formatCurrency(s.total_rent_amount)}</strong></div>
                <div class="evac-field"><span class="label">Your Share (Client)</span><strong>${formatCurrency(s.client_share)}</strong></div>
                <div class="evac-field"><span class="label">Admin Share</span><strong>${formatCurrency(s.admin_share)}</strong></div>
                <div class="evac-field"><span class="label">Agent Share</span><strong>${formatCurrency(s.agent_share)}</strong></div>
                <div class="evac-field"><span class="label">Settlement Date</span><strong>${formatDateTime(s.settlement_date)}</strong></div>
                <div class="evac-field"><span class="label">Paid to You On</span><strong>${formatDateTime(s.client_payment_date)}</strong></div>
            </div>
            ${s.notes ? `<div class="evac-note" style="margin-top: 14px;">${escapeHtml(s.notes)}</div>` : ""}
        </div>`
    : `
        <div class="evac-section">
            <h4><i class="fas fa-file-invoice-dollar"></i> Settlement Transaction</h4>
            <p style="color:#64748b; margin:0;">No settlement transaction has been recorded yet.</p>
        </div>`;

  // ---------- Section 7: Wallet Activity ----------
  const walletSection = txns.length
    ? `
        <div class="evac-section">
            <h4><i class="fas fa-receipt"></i> Recent Wallet Activity</h4>
            <table class="evac-table">
                <thead><tr><th>Date</th><th>Type</th><th>Reference</th><th class="num">Amount</th><th class="num">Balance</th></tr></thead>
                <tbody>
                    ${txns
                      .map(
                        (x) => `
                        <tr>
                            <td>${formatDateTime(x.created_at)}</td>
                            <td>${escapeHtml((x.transaction_type || "").replace(/_/g, " "))}</td>
                            <td>${escapeHtml(x.request_id || "—")}</td>
                            <td class="num">${formatCurrency(x.amount)}</td>
                            <td class="num">${formatCurrency(x.running_balance)}</td>
                        </tr>
                    `,
                      )
                      .join("")}
                </tbody>
            </table>
        </div>`
    : `
        <div class="evac-section">
            <h4><i class="fas fa-receipt"></i> Recent Wallet Activity</h4>
            <p style="color:#64748b; margin:0;">No wallet activity recorded for this evacuation.</p>
        </div>`;

  // ---------- Assemble body ----------
  document.getElementById("evacSlideOverBody").innerHTML = `
        ${tenantSection}
        ${evacuationSection}
        ${financialsSection}
        ${deductionsSection}
        ${feesSection}
        ${settlementSection}
        ${walletSection}
    `;

  // ---------- Footer ----------
  const receipt =
    req.settlement_receipt_number || fin.settlement_receipt_number || null;
  document.getElementById("evacSlideOverFooter").innerHTML = `
        ${
          receipt
            ? `<button class="btn-primary" onclick="downloadSettlementReceipt('${escapeAttribute(receipt)}')">
                 <i class="fas fa-file-pdf"></i> Download Receipt
               </button>`
            : ""
        }
        <button class="btn-secondary" onclick="closeEvacSlideOver()">
            <i class="fas fa-times"></i> Close
        </button>
    `;
}

function downloadSettlementReceipt(receiptNumber) {
  window.location.href = `../backend/evacuations/download_settlement_receipt.php?receipt=${encodeURIComponent(receiptNumber)}`;
}

function exportEvacuations() {
  const qs = new URLSearchParams();
  Object.entries(EvacState.filters).forEach(([k, v]) => {
    if (v) qs.set(k, v);
  });
  window.location.href = `../backend/evacuations/export_evacuations.php?${qs}`;
}

// ---------- Helpers ----------
function escapeHtml(text) {
  if (text === null || text === undefined) return "";
  const div = document.createElement("div");
  div.textContent = String(text);
  return div.innerHTML;
}
function escapeAttribute(text) {
  return escapeHtml(String(text ?? ""));
}
function formatCurrency(value) {
  return `₦${new Intl.NumberFormat("en-NG", {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  }).format(Number(value) || 0)}`;
}
function formatDate(d) {
  if (!d) return "—";
  const dt = new Date(d);
  if (isNaN(dt)) return d;
  return dt.toLocaleDateString("en-NG", {
    year: "numeric",
    month: "short",
    day: "numeric",
  });
}
function formatDateTime(d) {
  if (!d) return "—";
  const dt = new Date(d);
  if (isNaN(dt)) return d;
  return dt.toLocaleString("en-NG", {
    year: "numeric",
    month: "short",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}
