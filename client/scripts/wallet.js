// client/scripts/wallet.js

const CLIENT_WALLET_API = {
  getBalance: "../backend/wallet/get_balance.php",
  getTopupDestination: "../backend/wallet/get_topup_destination.php",
  topupInitiate: "../backend/wallet/topup_initiate.php",
  getRequests: "../backend/wallet/get_requests.php",
};

let walletData = null;
let activeTab = "transactions";

// Multi-step top-up state
let topupState = {
  step: "bank_details", // 'bank_details' | 'amount' | 'confirm'
  bankName: "",
  accountName: "",
  accountNumber: "",
  suggestedReference: "",
  amount: 0,
  reference: "",
  notes: "",
  proofFile: null,
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
                    <h3 id="topupModalTitle"><i class="fas fa-plus-circle"></i> Top Up Wallet</h3>
                    <button class="wallet-modal-close" onclick="closeTopupModal()">&times;</button>
                </div>
                <div class="wallet-modal-body" id="topupModalBody"></div>
                <div class="wallet-modal-footer" id="topupModalFooter"></div>
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
    const res = await fetch(CLIENT_WALLET_API.getBalance, {
      credentials: "same-origin",
    });
    const data = await res.json();
    if (!data.success) throw new Error(data.message || "Failed to load wallet");

    walletData = data.data;
    document.getElementById("walletBalance").textContent =
      walletData.wallet_balance_formatted;
    document.getElementById("walletSubtext").textContent =
      `Lifetime earned: ₦${formatNumber(walletData.total_settlement_earned)}`;

    renderActiveTab();
  } catch (err) {
    console.error(err);
    showToast(err.message || "Failed to load wallet", "error");
  }
}

function switchWalletTab(tab) {
  activeTab = tab;
  document
    .querySelectorAll(".wallet-tab")
    .forEach((el) => el.classList.toggle("active", el.dataset.tab === tab));
  renderActiveTab();
}

function renderActiveTab() {
  if (!walletData) return;
  if (activeTab === "transactions") renderTransactions();
  else renderRequests();
}

function renderTransactions() {
  const tx = walletData.transactions || [];
  const wrap = document.getElementById("walletTabContent");

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
                    ${tx
                      .map(
                        (t) => `
                        <tr>
                            <td>${formatDateTime(t.created_at)}</td>
                            <td>${humanizeType(t.type)}</td>
                            <td class="${t.amount >= 0 ? "tx-amount-credit" : "tx-amount-debit"}">
                                ${t.amount >= 0 ? "+" : ""}${t.amount_formatted}
                            </td>
                            <td>₦${formatNumber(t.running_balance)}</td>
                            <td style="font-family:monospace;font-size:12px;color:#64748b;">${escapeHtml(t.request_id || "—")}</td>
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
  const wrap = document.getElementById("walletTabContent");

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
                    ${req
                      .map(
                        (r) => `
                        <tr>
                            <td>${formatDateTime(r.created_at)}</td>
                            <td>${r.type === "topup" ? "Top-Up" : "Withdrawal"}</td>
                            <td>${r.amount_formatted}</td>
                            <td><span class="status-pill ${r.status}">${r.status}</span></td>
                            <td style="font-family:monospace;font-size:12px;color:#64748b;">${escapeHtml(r.request_code)}</td>
                        </tr>
                    `,
                      )
                      .join("")}
                </tbody>
            </table>
        </div>
    `;
}

// =========================================================
// TOP-UP — 3-STEP FLOW
// =========================================================
async function openTopupModal() {
  // Reset state
  topupState = {
    step: "bank_details",
    bankName: "",
    accountName: "",
    accountNumber: "",
    suggestedReference: "",
    amount: 0,
    reference: "",
    notes: "",
    proofFile: null,
  };

  document.getElementById("topupModal").classList.add("active");

  // Fetch bank details + suggested reference from the server
  document.getElementById("topupModalBody").innerHTML =
    `<div class="empty-state"><i class="fas fa-spinner fa-spin"></i><p>Loading bank details...</p></div>`;
  document.getElementById("topupModalFooter").innerHTML = "";

  try {
    const csrf = await getCsrfToken("client_topup_form");
    const body = {};
    if (csrf) {
      body.csrf_token = csrf;
      body.token_id = "client_topup_form";
    }

    const res = await fetch(CLIENT_WALLET_API.getTopupDestination, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
      credentials: "same-origin",
    });
    const data = await res.json();
    if (!data.success)
      throw new Error(data.message || "Failed to load bank details");

    topupState.bankName = data.data.bank_name;
    topupState.accountName = data.data.account_name;
    topupState.accountNumber = data.data.account_number;
    topupState.suggestedReference = data.data.suggested_reference;

    renderBankDetailsStep();
  } catch (err) {
    document.getElementById("topupModalBody").innerHTML = `
            <div class="empty-state">
                <i class="fas fa-exclamation-triangle" style="color:#ef4444;"></i>
                <p>${escapeHtml(err.message || "Failed to load bank details")}</p>
            </div>
        `;
    document.getElementById("topupModalFooter").innerHTML = `
            <button type="button" class="btn-wallet-secondary" onclick="closeTopupModal()">Close</button>
        `;
  }
}

function closeTopupModal() {
  document.getElementById("topupModal").classList.remove("active");
  topupState = {
    step: "bank_details",
    bankName: "",
    accountName: "",
    accountNumber: "",
    suggestedReference: "",
    amount: 0,
    reference: "",
    notes: "",
    proofFile: null,
  };
}

// ---- STEP 1: Bank details ----
function renderBankDetailsStep() {
  document.getElementById("topupModalTitle").innerHTML =
    '<i class="fas fa-university"></i> Send Payment';

  document.getElementById("topupModalBody").innerHTML = `
        <p style="font-size:13px;color:#64748b;margin-bottom:16px;">
            Transfer the amount to the account below. Include the reference
            number in your bank transfer description so we can match your payment.
        </p>

        <div class="bank-details-box" style="background:#eff6ff;border-left-color:#3b82f6;">
            <div class="bank-label" style="color:#1e40af;">Platform Account</div>
            <div style="font-weight:600;color:#1e3a8a;font-size:15px;">${escapeHtml(topupState.accountName)}</div>
            <div style="color:#1e40af;">${escapeHtml(topupState.bankName)}</div>
            <div style="color:#1e40af;font-size:16px;font-weight:700;letter-spacing:1px;margin-top:4px;">
                ${escapeHtml(topupState.accountNumber)}
            </div>
        </div>

        <div class="bank-details-box" style="background:#fffbeb;border-left-color:#f59e0b;">
            <div class="bank-label" style="color:#92400e;">
                Your Reference <i class="fas fa-lock" style="font-size:10px;opacity:0.7;"></i>
            </div>
            <div style="font-family:monospace;font-size:15px;font-weight:700;color:#78350f;letter-spacing:0.5px;">
                ${escapeHtml(topupState.suggestedReference)}
            </div>
            <div style="font-size:12px;color:#92400e;margin-top:4px;">
                Include this in your bank transfer description. This reference is fixed for your account and cannot be changed.
            </div>
        </div>

        <button type="button" class="btn-wallet-secondary" style="width:100%;margin-bottom:8px;"
                onclick="copyTopupDetails()">
            <i class="fas fa-copy"></i> Copy Bank Details
        </button>
    `;

  document.getElementById("topupModalFooter").innerHTML = `
        <button type="button" class="btn-wallet-secondary" onclick="closeTopupModal()">Cancel</button>
        <button type="button" class="btn-wallet-submit" onclick="goToAmountStep()">
            I've Made the Transfer <i class="fas fa-arrow-right"></i>
        </button>
    `;
}

function copyTopupDetails() {
  const text = [
    `Bank: ${topupState.bankName}`,
    `Account Name: ${topupState.accountName}`,
    `Account Number: ${topupState.accountNumber}`,
    `Reference: ${topupState.suggestedReference}`,
  ].join("\n");

  navigator.clipboard
    .writeText(text)
    .then(() => showToast("Bank details copied", "success"))
    .catch(() => showToast("Could not copy. Please copy manually.", "error"));
}

async function goToAmountStep() {
    topupState.step = 'amount';
    if (!topupState.reference) {
        topupState.reference = topupState.suggestedReference;
    }

    // Show a brief "verifying" state to mirror a real confirmation beat
    const body   = document.getElementById('topupModalBody');
    const footer = document.getElementById('topupModalFooter');

    body.innerHTML = `
    <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:40px 20px;gap:14px;">
        <div class="wallet-loader-spinner" style="width:36px;height:36px;border-width:3px;"></div>
        <div style="color:#64748b;font-size:14px;">Verifying transfer details...</div>
    </div>
`;
    footer.innerHTML = '';   // hide buttons while "verifying"

    // Fixed delay — feels like a real backend round-trip
    await new Promise(resolve => setTimeout(resolve, 900));

    renderAmountStep();
}

// ---- STEP 2: Amount + Proof ----
function renderAmountStep() {
    document.getElementById('topupModalTitle').innerHTML =
        '<i class="fas fa-receipt"></i> Confirm Payment Details';

    document.getElementById('topupModalBody').innerHTML = `
        <p style="font-size:13px;color:#64748b;margin-bottom:16px;">
            Enter the amount you transferred and upload proof of payment.
        </p>

        <form id="topupForm" onsubmit="event.preventDefault(); goToConfirmStep();">
            <div class="wallet-form-group">
                <label>Amount Transferred (₦) *</label>
                <input type="text" id="topupAmount" inputmode="decimal"
                       placeholder="e.g. 50000 or 50000.00"
                       value="${topupState.amount ? String(topupState.amount) : ''}"
                       autocomplete="off" required>
                <div class="hint">Numbers only. Minimum ₦1,000. Maximum ₦50,000,000.</div>
            </div>

            <div class="wallet-form-group">
                <label>
                    Bank Reference
                    <i class="fas fa-lock" style="font-size:10px;opacity:0.5;margin-left:4px;"></i>
                </label>
                <input type="text" id="topupReference"
                       value="${escapeHtml(topupState.reference)}"
                       readonly
                       style="background:#f8fafc;color:#475569;cursor:not-allowed;">
                <div class="hint">
                    This is your fixed top-up reference. It cannot be edited.
                    Use it as the description in your bank transfer.
                </div>
            </div>

            <div class="wallet-form-group">
                <label>Proof of Payment *</label>
                <input type="file" id="topupProof"
                       accept="image/jpeg,image/png,application/pdf"
                       ${topupState.proofFile ? '' : 'required'}>
                <div class="hint">
                    JPG, PNG, or PDF. Max 3 MB.
                    ${topupState.proofFile ? `<br><strong>Current: ${escapeHtml(topupState.proofFile.name)}</strong>` : ''}
                </div>
            </div>

            <div class="wallet-form-group">
                <label>Notes (optional)</label>
                <textarea id="topupNotes" rows="2"
                          placeholder="Any additional info for the admin">${escapeHtml(topupState.notes)}</textarea>
            </div>
        </form>
    `;

    document.getElementById('topupModalFooter').innerHTML = `
        <button type="button" class="btn-wallet-secondary" onclick="renderBankDetailsStep()">
            <i class="fas fa-arrow-left"></i> Back
        </button>
        <button type="button" class="btn-wallet-submit" onclick="goToConfirmStep()">
            Continue <i class="fas fa-arrow-right"></i>
        </button>
    `;

    // ← Attach the numeric filter after the DOM is in place
    attachAmountInputFilter(document.getElementById('topupAmount'));
}

function goToConfirmStep() {
  const amountRaw = document.getElementById("topupAmount").value.trim();
  const reference = document.getElementById("topupReference").value.trim();
  const notes = document.getElementById("topupNotes").value.trim();
  const fileInput = document.getElementById("topupProof");
  const newFile = fileInput.files[0];

  const amount = parseFloat(amountRaw.replace(/,/g, ""));
  if (isNaN(amount) || amount <= 0)
    return showToast("Enter a valid amount", "error");
  if (amount < 1000) return showToast("Minimum top-up is ₦1,000", "error");
  if (amount > 50_000_000)
    return showToast("Maximum top-up is ₦50,000,000", "error");
  if (!reference) return showToast("Enter the bank reference", "error");
  if (reference.length > 100)
    return showToast("Reference is too long (max 100)", "error");
  if (!newFile && !topupState.proofFile)
    return showToast("Upload proof of payment", "error");

  // Validate file if a new one was selected
  if (newFile) {
    const allowed = ["image/jpeg", "image/png", "application/pdf"];
    if (!allowed.includes(newFile.type))
      return showToast("Proof must be JPG, PNG, or PDF", "error");
    if (newFile.size > 3 * 1024 * 1024)
      return showToast("Proof must be under 3 MB", "error");
    topupState.proofFile = newFile;
  }

  topupState.amount = amount;
  topupState.reference = reference;
  topupState.notes = notes;
  topupState.step = "confirm";

  renderConfirmStep();
}

// ---- STEP 3: Confirm & Submit ----
function renderConfirmStep() {
  document.getElementById("topupModalTitle").innerHTML =
    '<i class="fas fa-check-circle"></i> Confirm Top-Up';

  const amountFormatted = "₦" + formatNumber(topupState.amount);
  const fileSize = (topupState.proofFile.size / 1024).toFixed(0);

  document.getElementById("topupModalBody").innerHTML = `
        <p style="font-size:13px;color:#64748b;margin-bottom:16px;">
            Please confirm the details below. Your top-up will be credited after admin review.
        </p>

        <div style="background:#f8fafc;border-radius:8px;padding:14px 16px;margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;margin-bottom:10px;">
                <span style="color:#64748b;font-size:13px;">Amount</span>
                <strong style="color:#1a1f36;font-size:16px;">${amountFormatted}</strong>
            </div>
            <div style="display:flex;justify-content:space-between;margin-bottom:10px;">
                <span style="color:#64748b;font-size:13px;">Reference</span>
                <strong style="color:#1a1f36;font-family:monospace;font-size:13px;">
                    ${escapeHtml(topupState.reference)}
                </strong>
            </div>
            <div style="display:flex;justify-content:space-between;">
                <span style="color:#64748b;font-size:13px;">Proof</span>
                <strong style="color:#1a1f36;font-size:13px;">
                    ${escapeHtml(topupState.proofFile.name)} (${fileSize} KB)
                </strong>
            </div>
        </div>

        <div class="bank-details-box" style="background:#eff6ff;border-left-color:#3b82f6;">
            <div class="bank-label" style="color:#1e40af;">Payment sent to</div>
            <div style="color:#1e40af;">${escapeHtml(topupState.accountName)}</div>
            <div style="color:#1e40af;">${escapeHtml(topupState.bankName)} &middot; ${escapeHtml(topupState.accountNumber)}</div>
        </div>

        <p style="font-size:12px;color:#94a3b8;margin-top:12px;">
            <i class="fas fa-info-circle"></i>
            Your wallet will be credited within 1-2 business days after verification.
        </p>
    `;

  document.getElementById("topupModalFooter").innerHTML = `
        <button type="button" class="btn-wallet-secondary" onclick="renderAmountStep()">
            <i class="fas fa-arrow-left"></i> Back
        </button>
        <button type="button" class="btn-wallet-submit" id="topupSubmitBtn" onclick="submitTopup()">
            <i class="fas fa-paper-plane"></i> Submit Top-Up
        </button>
    `;
}

async function submitTopup() {
    const fd = new FormData();
    fd.append('amount', String(topupState.amount));
    fd.append('notes', topupState.notes);
    fd.append('proof', topupState.proofFile);
    fd.append("reference_number", topupState.reference);
    const csrf = await getCsrfToken('client_topup_form');
    if (csrf) {
        fd.append('csrf_token', csrf);
        fd.append('token_id', 'client_topup_form');
    }

    const btn = document.getElementById('topupSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    showLoader();

    // Ensure the loader is visible for at least 1000ms, even if the
    // server responds faster. Prevents "flash of done" that makes the
    // submission feel insecure.
    const minDelay = new Promise(resolve => setTimeout(resolve, 1000));

    try {
        // Fire the request and the timer in parallel
        const requestPromise = fetch(CLIENT_WALLET_API.topupInitiate, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
        });

        const [res] = await Promise.all([requestPromise, minDelay]);

        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'Request failed');

        hideLoader();
        closeTopupModal();
        showToast(
            `Top-up submitted. Reference: ${data.data.reference_number || topupState.reference}`,
            'success'
        );
        loadWalletData();
    } catch (err) {
        // Even on error, let the min-delay finish so the user sees a
        // consistent "processing" state before the error.
        await minDelay;
        hideLoader();
        showToast(err.message || 'Failed to submit request', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Top-Up';
    }
}
// =========================================================
// HELPERS
// =========================================================
function humanizeType(t) {
  const map = {
    rent_credit: "Rent Share Credit",
    topup: "Wallet Top-Up",
    settlement_debit: "Evacuation Refund Debit",
    deposit_release: "Deposit Released",
    withdrawal: "Withdrawal",
    admin_adjustment: "Admin Adjustment",
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
      { method: "POST", credentials: "include" },
    );
    const data = await res.json();
    return data.success ? data.token : null;
  } catch (err) {
    console.error("CSRF token fetch failed:", err);
    return null;
  }
}

// Add this helper near the bottom of wallet.js, with the other helpers:
function attachAmountInputFilter(inputEl) {
    if (!inputEl) return;
    inputEl.addEventListener('input', () => {
        let v = inputEl.value;

        // Keep digits and at most one decimal point
        v = v.replace(/[^\d.]/g, '');          // drop everything not digit or dot
        const firstDot = v.indexOf('.');
        if (firstDot !== -1) {
            // Remove any additional dots after the first
            v = v.slice(0, firstDot + 1) + v.slice(firstDot + 1).replace(/\./g, '');
            // Truncate to 2 decimal places
            const [whole, frac] = v.split('.');
            v = whole + '.' + (frac || '').slice(0, 2);
        }

        // Prevent leading zeros (e.g. "007" -> "7"), but allow "0.5"
        if (v.length > 1 && v[0] === '0' && v[1] !== '.') {
            v = v.replace(/^0+/, '') || '0';
        }

        if (inputEl.value !== v) inputEl.value = v;
    });

    // Optional: strip trailing '.' on blur so "1000." becomes "1000"
    inputEl.addEventListener('blur', () => {
        if (inputEl.value.endsWith('.')) {
            inputEl.value = inputEl.value.slice(0, -1);
        }
    });
}
