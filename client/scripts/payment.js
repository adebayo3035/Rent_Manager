// client/scripts/payment.js - Client rent payment & settlement overview

let recentPayments = [];
let revenueSummary = null;

document.addEventListener("DOMContentLoaded", () => {
  initializePayments();
});

async function initializePayments() {
  try {
    const [revenue, payments] = await Promise.all([
      fetchRevenueSummary(),
      fetchRecentPayments()
    ]);

    revenueSummary = revenue;
    recentPayments = payments;
    renderPaymentsPage();
  } catch (error) {
    console.error("Error initializing payments:", error);
    if (window.showToast) {
      window.showToast(error.message || "Failed to load payment data", "error");
    }
    renderErrorState();
  }
}

async function fetchRevenueSummary(period = "all") {
  const response = await fetch(`../backend/dashboard/fetch_revenue.php?period=${encodeURIComponent(period)}`, {
    credentials: "include",
    headers: { "Accept": "application/json" }
  });
  const data = await response.json();

  if (!response.ok || !data.success) {
    throw new Error(data.message || "Failed to load revenue summary");
  }

  return data.data || {};
}

async function fetchRecentPayments() {
  const response = await fetch("../backend/dashboard/fetch_recent_payments.php?limit=50", {
    credentials: "include",
    headers: { "Accept": "application/json" }
  });
  const data = await response.json();

  if (!response.ok || !data.success) {
    throw new Error(data.message || "Failed to load recent payments");
  }

  return Array.isArray(data.data?.payments) ? data.data.payments : [];
}

function renderPaymentsPage() {
  const contentArea = document.getElementById("contentArea");
  if (!contentArea) return;

  // Extract data from revenueSummary
  const rentRevenue = revenueSummary?.rent_revenue || {};
  const settlementRevenue = revenueSummary?.settlement_revenue || {};
  const deductions = revenueSummary?.deductions || {};
  const summary = revenueSummary?.summary || {};

  contentArea.innerHTML = `
    <div class="payments-container">
      <div class="page-header">
        <h1>Rent Payments & Settlements</h1>
        <p>Review rent collections, settlements, and your net earnings.</p>
      </div>

      <!-- ==================== RENT REVENUE CARDS ==================== -->
      <div class="section-label">
        <i class="fas fa-money-bill-wave"></i>
        <span>Rent Revenue</span>
      </div>
      <div class="payment-stats">
        <div class="stat-card">
          <div class="stat-icon success"><i class="fas fa-check-circle"></i></div>
          <div class="stat-content">
            <h3>${formatCurrency(rentRevenue.total_collected)}</h3>
            <p>Total Collected</p>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon warning"><i class="fas fa-clock"></i></div>
          <div class="stat-content">
            <h3>${formatCurrency(rentRevenue.total_pending)}</h3>
            <p>Pending Verification</p>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon danger"><i class="fas fa-exclamation-circle"></i></div>
          <div class="stat-content">
            <h3>${formatCurrency(rentRevenue.total_overdue)}</h3>
            <p>Overdue</p>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon info"><i class="fas fa-chart-line"></i></div>
          <div class="stat-content">
            <h3>${formatCurrency(rentRevenue.expected_revenue)}</h3>
            <p>Expected Revenue</p>
          </div>
        </div>
      </div>

      <!-- ==================== SETTLEMENT REVENUE CARDS ==================== -->
      <div class="section-label">
        <i class="fas fa-hand-holding-usd"></i>
        <span>Your Settlements (Net Earnings)</span>
      </div>
      <div class="payment-stats settlement-stats">
        <div class="stat-card stat-card-settlement">
          <div class="stat-icon success"><i class="fas fa-check-circle"></i></div>
          <div class="stat-content">
            <h3>${formatCurrency(settlementRevenue.total_earned)}</h3>
            <p>Total Earned <small>(All Settlements)</small></p>
          </div>
        </div>
        <div class="stat-card stat-card-settlement">
          <div class="stat-icon paid"><i class="fas fa-money-bill-wave"></i></div>
          <div class="stat-content">
            <h3>${formatCurrency(settlementRevenue.total_paid)}</h3>
            <p>Paid to You</p>
            <span class="stat-badge success">${settlementRevenue.settlement_rate || 0}% of rent</span>
          </div>
        </div>
        <div class="stat-card stat-card-settlement">
          <div class="stat-icon pending"><i class="fas fa-clock"></i></div>
          <div class="stat-content">
            <h3>${formatCurrency(settlementRevenue.total_pending)}</h3>
            <p>Pending Payout</p>
            <span class="stat-badge warning">${settlementRevenue.pending_settlements || 0} settlements</span>
          </div>
        </div>
        <div class="stat-card stat-card-settlement">
          <div class="stat-icon info"><i class="fas fa-percentage"></i></div>
          <div class="stat-content">
            <h3>${settlementRevenue.settlement_rate || 0}%</h3>
            <p>Settlement Rate</p>
            <span class="stat-badge info">${settlementRevenue.completed_settlements || 0} completed</span>
          </div>
        </div>
      </div>

      <!-- ==================== DEDUCTIONS & SUMMARY ==================== -->
      <div class="deduction-summary">
        <div class="deduction-card">
          <div class="deduction-icon admin-fee"><i class="fas fa-user-shield"></i></div>
          <div class="deduction-content">
            <span class="deduction-label">Admin Fees</span>
            <span class="deduction-value">${formatCurrency(deductions.admin_fees)}</span>
          </div>
        </div>
        <div class="deduction-card">
          <div class="deduction-icon agent-fee"><i class="fas fa-user-tie"></i></div>
          <div class="deduction-content">
            <span class="deduction-label">Agent Commissions</span>
            <span class="deduction-value">${formatCurrency(deductions.agent_commissions)}</span>
          </div>
        </div>
        <div class="deduction-card total">
          <div class="deduction-icon total-deduction"><i class="fas fa-calculator"></i></div>
          <div class="deduction-content">
            <span class="deduction-label">Total Deductions</span>
            <span class="deduction-value">${formatCurrency(deductions.total_deductions)}</span>
          </div>
        </div>
        <div class="deduction-card net">
          <div class="deduction-icon net-earning"><i class="fas fa-wallet"></i></div>
          <div class="deduction-content">
            <span class="deduction-label">Net Received</span>
            <span class="deduction-value">${formatCurrency(summary.net_received)}</span>
          </div>
        </div>
      </div>

      <!-- ==================== RECENT PAYMENTS TABLE ==================== -->
      <div class="payment-section">
        <div class="section-header">
          <h2>Recent Rent Payments</h2>
          <a href="payment_history.php" class="dashboard-action">
            <i class="fas fa-credit-card"></i>
            <span>View All Payments</span>
          </a>
        </div>
        
        ${renderPaymentsTable()}
      </div>
    </div>
  `;
}

function renderPaymentsTable() {
  if (!recentPayments.length) {
    return `
      <div class="empty-state">
        <i class="fas fa-receipt"></i>
        <h3>No Payments Found</h3>
        <p>No rent payment activity is available yet.</p>
      </div>
    `;
  }

  return `
    <div class="table-container">
      <table class="payments-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Tenant</th>
            <th>Property</th>
            <th>Apartment</th>
            <th>Period</th>
            <th>Amount</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          ${recentPayments.map(payment => `
            <tr>
              <td data-label="Date">${formatDate(payment.payment_date)}</td>
              <td data-label="Tenant">${escapeHtml(payment.tenant_name)}</td>
              <td data-label="Property">${escapeHtml(payment.property_name)}</td>
              <td data-label="Apartment">${escapeHtml(payment.apartment_number || "N/A")}</td>
              <td data-label="Period">${escapeHtml(formatPeriod(payment))}</td>
              <td data-label="Amount" class="amount">${formatCurrency(payment.amount)}</td>
              <td data-label="Status"><span class="status-badge status-${normalizeStatus(payment.status)}">${escapeHtml(payment.status || "pending")}</span></td>
            </tr>
          `).join("")}
        </tbody>
      </table>
    </div>
    <div class="payment-mobile-list" aria-label="Recent rent payments">
      ${recentPayments.map(payment => `
        <article class="payment-mobile-card">
          <div class="payment-mobile-card-header">
            <div>
              <span class="payment-mobile-date">${formatDate(payment.payment_date)}</span>
              <strong>${escapeHtml(payment.tenant_name || "Unknown Tenant")}</strong>
            </div>
            <span class="status-badge status-${normalizeStatus(payment.status)}">${escapeHtml(payment.status || "pending")}</span>
          </div>
          <div class="payment-mobile-amount">${formatCurrency(payment.amount)}</div>
          <div class="payment-mobile-details">
            <div>
              <span>Property</span>
              <strong>${escapeHtml(payment.property_name || "N/A")}</strong>
            </div>
            <div>
              <span>Apartment</span>
              <strong>${escapeHtml(payment.apartment_number || "N/A")}</strong>
            </div>
            <div>
              <span>Period</span>
              <strong>${escapeHtml(formatPeriod(payment))}</strong>
            </div>
          </div>
        </article>
      `).join("")}
    </div>
  `;
}

function renderErrorState() {
  const contentArea = document.getElementById("contentArea");
  if (!contentArea) return;

  contentArea.innerHTML = `
    <div class="payments-container">
      <div class="empty-state">
        <i class="fas fa-exclamation-circle"></i>
        <h3>Failed to Load Payments</h3>
        <p>Please refresh the page and try again.</p>
      </div>
    </div>
  `;
}

function formatPeriod(payment) {
  if (payment.period_number) {
    return `Period ${payment.period_number}`;
  }
  if (payment.period_start_date && payment.period_end_date) {
    return `${formatDate(payment.period_start_date)} - ${formatDate(payment.period_end_date)}`;
  }
  return "N/A";
}

function formatCurrency(value) {
  return `\u20a6${new Intl.NumberFormat("en-NG", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  }).format(Number(value) || 0)}`;
}

function formatDate(dateString) {
  if (!dateString) return "N/A";
  const date = new Date(dateString);
  if (Number.isNaN(date.getTime())) return "N/A";
  return date.toLocaleDateString("en-NG", {
    year: "numeric",
    month: "short",
    day: "numeric"
  });
}

function normalizeStatus(status) {
  return String(status || "pending").toLowerCase().replace(/[^a-z0-9_-]/g, "");
}

function escapeHtml(text) {
  if (text === null || text === undefined) return "";
  const div = document.createElement("div");
  div.textContent = String(text);
  return div.innerHTML;
}