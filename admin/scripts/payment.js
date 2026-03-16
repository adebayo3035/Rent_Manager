// Global variables
let currentPage = 1;
let currentLimit = 10;
let currentFilters = {};
let revenueChart = null;

// Initialize
document.addEventListener("DOMContentLoaded", function () {
  setTodayDate();
  loadFilters();
  loadPayments();
  loadStatistics();
  loadQuickTenants();
  loadTenantsForModal();
  loadApartmentsForModal();
});

// Set today's date in date fields
function setTodayDate() {
  const today = new Date().toISOString().split("T")[0];
  document.getElementById("payment_date").value = today;
  document.getElementById("due_date").value = today;
}

// Load filter dropdowns
async function loadFilters() {
  try {
    const response = await fetch(
      `../backend/payment/payment_manager.php?action=fetch&page=1&limit=1`,
    );
    const data = await response.json();

    if (data.success) {
      populateFilters(data.filters);
    }
  } catch (error) {
    console.error("Error loading filters:", error);
  }
}

function populateFilters(filters) {
  const container = document.getElementById("filtersContainer");
  container.innerHTML = `
                <div class="form-group">
                    <label>Search</label>
                    <input type="text" class="form-control" id="filterSearch" 
                           placeholder="Search tenant, receipt #...">
                </div>
                <div class="form-group">
                    <label>Tenant</label>
                    <select class="form-control" id="filterTenant">
                        <option value="">All Tenants</option>
                        ${filters.tenants
                          .map(
                            (t) =>
                              `<option value="${t.id}">${t.fullname}</option>`,
                          )
                          .join("")}
                    </select>
                </div>
                <div class="form-group">
                    <label>Property</label>
                    <select class="form-control" id="filterProperty">
                        <option value="">All Properties</option>
                        ${filters.properties
                          .map(
                            (p) =>
                              `<option value="${p.property_code}">${p.name}</option>`,
                          )
                          .join("")}
                    </select>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select class="form-control" id="filterStatus">
                        <option value="">All Statuses</option>
                        ${filters.payment_statuses
                          .map(
                            (s) =>
                              `<option value="${s}">${s.charAt(0).toUpperCase() + s.slice(1)}</option>`,
                          )
                          .join("")}
                    </select>
                </div>
                <div class="form-group">
                    <label>Date From</label>
                    <input type="date" class="form-control" id="filterDateFrom">
                </div>
                <div class="form-group">
                    <label>Date To</label>
                    <input type="date" class="form-control" id="filterDateTo">
                </div>
            `;
}

// Load payments
async function loadPayments() {
  const limit = document.getElementById("limitSelect").value;
  currentLimit = parseInt(limit);

  // Build query string
  let query = `action=fetch&page=${currentPage}&limit=${currentLimit}`;

  // Add filters
  if (currentFilters.search)
    query += `&search=${encodeURIComponent(currentFilters.search)}`;
  if (currentFilters.tenant_id)
    query += `&tenant_id=${currentFilters.tenant_id}`;
  if (currentFilters.property_id)
    query += `&property_id=${currentFilters.property_id}`;
  if (currentFilters.payment_status)
    query += `&payment_status=${currentFilters.payment_status}`;
  if (currentFilters.date_from)
    query += `&date_from=${currentFilters.date_from}`;
  if (currentFilters.date_to) query += `&date_to=${currentFilters.date_to}`;

  try {
    const response = await fetch(`../backend/payment/payment_manager.php?${query}`);
    const data = await response.json();

    if (data.success) {
      renderPaymentsTable(data.payments);
      renderPagination(data.pagination);
    }
  } catch (error) {
    console.error("Error loading payments:", error);
    showAlert("Error loading payments", "error");
  }
}

function renderPaymentsTable(payments) {
  const tbody = document.getElementById("paymentsTableBody");
  tbody.innerHTML = "";

  if (payments.length === 0) {
    tbody.innerHTML = `
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px; color: var(--gray);">
                            <i class="fas fa-search" style="font-size: 48px; margin-bottom: 15px; display: block;"></i>
                            <h3>No payments found</h3>
                            <p>Try adjusting your filters or record a new payment</p>
                        </td>
                    </tr>
                `;
    return;
  }

  payments.forEach((payment) => {
    const row = document.createElement("tr");
    row.innerHTML = `
                    <td><strong>${payment.receipt_number}</strong></td>
                    <td>
                        <div><strong>${payment.tenant_name}</strong></div>
                        <small>${payment.tenant_email}</small>
                    </td>
                    <td>
                        <div>${payment.property_name}</div>
                        <small>Apartment: ${payment.apartment_number}</small>
                    </td>
                    <td><strong>$${payment.amount_formatted}</strong></td>
                    <td>${payment.payment_date_formatted}</td>
                    <td>${payment.payment_method.replace("_", " ").toUpperCase()}</td>
                    <td>
                        <span class="status-badge status-${payment.payment_status}">
                            ${payment.payment_status.toUpperCase()}
                        </span>
                    </td>
                    <td>
                        <div class="action-btns">
                            <button class="action-btn" onclick="viewPayment(${payment.id})" 
                                    title="View Details">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="action-btn" onclick="editPayment(${payment.id})" 
                                    title="Edit">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="action-btn" onclick="deletePayment(${payment.id})" 
                                    title="Delete" style="color: var(--danger);">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                `;
    tbody.appendChild(row);
  });
}

function renderPagination(pagination) {
  const container = document.getElementById("paginationContainer");
  container.innerHTML = "";

  const totalPages = pagination.total_pages;
  if (totalPages <= 1) return;

  // Previous button
  const prevBtn = document.createElement("button");
  prevBtn.className = "page-btn";
  prevBtn.innerHTML = '<i class="fas fa-chevron-left"></i>';
  prevBtn.disabled = currentPage === 1;
  prevBtn.onclick = () => {
    if (currentPage > 1) {
      currentPage--;
      loadPayments();
    }
  };
  container.appendChild(prevBtn);

  // Page numbers
  const startPage = Math.max(1, currentPage - 2);
  const endPage = Math.min(totalPages, currentPage + 2);

  for (let i = startPage; i <= endPage; i++) {
    const pageBtn = document.createElement("button");
    pageBtn.className = `page-btn ${i === currentPage ? "active" : ""}`;
    pageBtn.textContent = i;
    pageBtn.onclick = () => {
      currentPage = i;
      loadPayments();
    };
    container.appendChild(pageBtn);
  }

  // Next button
  const nextBtn = document.createElement("button");
  nextBtn.className = "page-btn";
  nextBtn.innerHTML = '<i class="fas fa-chevron-right"></i>';
  nextBtn.disabled = currentPage === totalPages;
  nextBtn.onclick = () => {
    if (currentPage < totalPages) {
      currentPage++;
      loadPayments();
    }
  };
  container.appendChild(nextBtn);
}

// Filter functions
function applyFilters() {
  currentFilters = {
    search: document.getElementById("filterSearch").value,
    tenant_id: document.getElementById("filterTenant").value,
    property_id: document.getElementById("filterProperty").value,
    payment_status: document.getElementById("filterStatus").value,
    date_from: document.getElementById("filterDateFrom").value,
    date_to: document.getElementById("filterDateTo").value,
  };

  currentPage = 1;
  loadPayments();
}

function resetFilters() {
  currentFilters = {};

  // Reset form fields
  const filterIds = [
    "filterSearch",
    "filterTenant",
    "filterProperty",
    "filterStatus",
    "filterDateFrom",
    "filterDateTo",
  ];
  filterIds.forEach((id) => {
    const element = document.getElementById(id);
    if (element) element.value = "";
  });

  currentPage = 1;
  loadPayments();
}

// Load statistics
async function loadStatistics() {
  try {
    const response = await fetch("../backend/payment/payment_manager.php?action=get_statistics");
    const data = await response.json();

    if (data.success) {
      renderStatistics(data.statistics);
    }
  } catch (error) {
    console.error("Error loading statistics:", error);
  }
}

function renderStatistics(stats) {
  const container = document.getElementById("statsContainer");

  const statCards = [
    {
      icon: "fas fa-money-bill-wave",
      color: "#10b981",
      title: "Total Revenue",
      value:
        "$" +
        (stats.summary.total_revenue
          ? parseFloat(stats.summary.total_revenue).toLocaleString(undefined, {
              minimumFractionDigits: 2,
            })
          : "0.00"),
    },
    {
      icon: "fas fa-receipt",
      color: "#3b82f6",
      title: "Total Payments",
      value: stats.summary.total_payments || 0,
    },
    {
      icon: "fas fa-check-circle",
      color: "#10b981",
      title: "Completed",
      value: stats.summary.completed_payments || 0,
    },
    {
      icon: "fas fa-clock",
      color: "#f59e0b",
      title: "Pending",
      value: stats.summary.pending_payments || 0,
    },
    {
      icon: "fas fa-exclamation-triangle",
      color: "#ef4444",
      title: "Overdue",
      value: stats.summary.overdue_payments || 0,
    },
    {
      icon: "fas fa-dollar-sign",
      color: "#8b5cf6",
      title: "Average Payment",
      value:
        "$" +
        (stats.summary.average_payment
          ? parseFloat(stats.summary.average_payment).toLocaleString(
              undefined,
              { minimumFractionDigits: 2 },
            )
          : "0.00"),
    },
  ];

  container.innerHTML = statCards
    .map(
      (card) => `
                <div class="stat-card">
                    <div class="stat-icon" style="background: ${card.color}">
                        <i class="${card.icon}"></i>
                    </div>
                    <div class="stat-info">
                        <h3>${card.title}</h3>
                        <p class="stat-number">${card.value}</p>
                    </div>
                </div>
            `,
    )
    .join("");

  // Render revenue chart
  renderRevenueChart(stats.revenue_trend);
}

function renderRevenueChart(revenueTrend) {
  const ctx = document.getElementById("revenueChart").getContext("2d");

  if (revenueChart) {
    revenueChart.destroy();
  }

  const labels = revenueTrend.map((item) => item.month_name);
  const data = revenueTrend.map((item) => item.revenue);

  revenueChart = new Chart(ctx, {
    type: "line",
    data: {
      labels: labels,
      datasets: [
        {
          label: "Revenue",
          data: data,
          borderColor: "#2563eb",
          backgroundColor: "rgba(37, 99, 235, 0.1)",
          borderWidth: 2,
          fill: true,
          tension: 0.4,
        },
      ],
    },
    options: {
      responsive: true,
      plugins: {
        legend: { display: false },
      },
      scales: {
        y: {
          beginAtZero: true,
          ticks: {
            callback: function (value) {
              return "$" + value.toLocaleString();
            },
          },
        },
      },
    },
  });
}

// Modal functions
function openRecordPaymentModal() {
  document.getElementById("recordPaymentModal").style.display = "flex";
}

function closeRecordPaymentModal() {
  document.getElementById("recordPaymentModal").style.display = "none";
  document.getElementById("paymentForm").reset();
  setTodayDate();
}

function openViewPaymentModal() {
  document.getElementById("viewPaymentModal").style.display = "flex";
}

function closeViewPaymentModal() {
  document.getElementById("viewPaymentModal").style.display = "none";
}

// Load tenants for quick payment
async function loadQuickTenants() {
  try {
    const response = await fetch("../backend/payment/payment_manager.php?action=fetch&limit=100");
    const data = await response.json();

    if (data.success && data.filters.tenants) {
      const select = document.getElementById("quickTenant");
      select.innerHTML =
        '<option value="">Select Tenant</option>' +
        data.filters.tenants
          .map((t) => `<option value="${t.id}">${t.fullname}</option>`)
          .join("");
    }
  } catch (error) {
    console.error("Error loading tenants:", error);
  }
}

async function loadTenantsForModal() {
  try {
    const response = await fetch("../backend/payment/payment_manager.php?action=fetch&limit=100");
    const data = await response.json();

    if (data.success && data.filters.tenants) {
      const select = document.getElementById("tenant_id");
      select.innerHTML =
        '<option value="">Select Tenant</option>' +
        data.filters.tenants
          .map(
            (t) =>
              `<option value="${t.id}">${t.fullname} (${t.email})</option>`,
          )
          .join("");
    }
  } catch (error) {
    console.error("Error loading tenants for modal:", error);
  }
}

async function loadApartmentsForModal() {
  try {
    const response = await fetch("../backend/payment/payment_manager.php?action=fetch&limit=100");
    const data = await response.json();

    if (data.success && data.filters.apartments) {
      const select = document.getElementById("apartment_id");
      select.innerHTML =
        '<option value="">Select Tenant</option>' +
        data.filters.apartments
          .map(
            (apt) =>
              `<option value="${apt.id}">${apt.display_name} (${apt.apartment_number})</option>`,
          )
          .join("");
    }
  } catch (error) {
    console.error("Error loading Apartments for modal:", error);
  }
}

// Form submissions
async function quickRecordPayment(event) {
  event.preventDefault();

  const formData = new FormData(event.target);
  const data = Object.fromEntries(formData.entries());

  try {
    const response = await fetch("../backend/payment/payment_manager.php?action=record_payment", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify(data),
    });

    const result = await response.json();

    if (result.success) {
      showAlert(
        "Payment recorded successfully! Receipt #: " + result.receipt_number,
        "success",
      );
      event.target.reset();
      loadPayments();
      loadStatistics();
    } else {
      showAlert(result.message, "error");
    }
  } catch (error) {
    console.error("Error:", error);
    showAlert("Error recording payment", "error");
  }
}

async function submitPaymentForm(event = null) {
  if (event) event.preventDefault();

  const form = document.getElementById("paymentForm");
  const formData = new FormData(form);
  const data = Object.fromEntries(formData.entries());

  try {
    const response = await fetch("../backend/payment/payment_manager.php?action=create", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify(data),
    });

    const result = await response.json();

    if (result.success) {
      showAlert(
        "Payment created successfully! Receipt #: " + result.receipt_number,
        "success",
      );
      closeRecordPaymentModal();
      loadPayments();
      loadStatistics();
    } else {
      showAlert(result.message, "error");
    }
  } catch (error) {
    console.error("Error:", error);
    showAlert("Error creating payment", "error");
  }
}

// View payment details
async function viewPayment(paymentId) {
  try {
    const response = await fetch(
      `../backend/payment/payment_manager.php?action=fetch_single&id=${paymentId}`,
    );
    const data = await response.json();

    if (data.success) {
      renderPaymentDetails(data.payment);
      openViewPaymentModal();
    }
  } catch (error) {
    console.error("Error:", error);
    showAlert("Error loading payment details", "error");
  }
}

function renderPaymentDetails(payment) {
  const container = document.getElementById("paymentDetails");

  const detailsHTML = `
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px;">
                    <div>
                        <h4>Payment Information</h4>
                        <table class="data-table" style="width: 100%;">
                            <tr><td><strong>Receipt #:</strong></td><td>${payment.receipt_number}</td></tr>
                            <tr><td><strong>Amount:</strong></td><td>$${payment.amount_formatted}</td></tr>
                            <tr><td><strong>Balance:</strong></td><td>$${payment.balance_formatted}</td></tr>
                            <tr><td><strong>Date:</strong></td><td>${payment.payment_date_formatted}</td></tr>
                            <tr><td><strong>Status:</strong></td><td>
                                <span class="status-badge status-${payment.payment_status}">
                                    ${payment.payment_status.toUpperCase()}
                                </span>
                            </td></tr>
                            <tr><td><strong>Method:</strong></td><td>${payment.method_name || payment.payment_method}</td></tr>
                            <tr><td><strong>Reference:</strong></td><td>${payment.reference_number || "N/A"}</td></tr>
                        </table>
                    </div>
                    
                    <div>
                        <h4>Tenant Information</h4>
                        <table class="data-table" style="width: 100%;">
                            <tr><td><strong>Name:</strong></td><td>${payment.tenant_name}</td></tr>
                            <tr><td><strong>Email:</strong></td><td>${payment.tenant_email}</td></tr>
                            <tr><td><strong>Phone:</strong></td><td>${payment.tenant_phone}</td></tr>
                            <tr><td><strong>ID Number:</strong></td><td>${payment.id_number || "N/A"}</td></tr>
                        </table>
                        
                        <h4 style="margin-top: 20px;">Property Information</h4>
                        <table class="data-table" style="width: 100%;">
                            <tr><td><strong>Property:</strong></td><td>${payment.property_name}</td></tr>
                            <tr><td><strong>Apartment:</strong></td><td>${payment.apartment_number}</td></tr>
                            <tr><td><strong>Monthly Rent:</strong></td><td>$${payment.monthly_rent ? payment.monthly_rent.toFixed(2) : "N/A"}</td></tr>
                        </table>
                    </div>
                </div>
                
                ${
                  payment.description
                    ? `
                <div style="margin-bottom: 20px;">
                    <h4>Description</h4>
                    <p>${payment.description}</p>
                </div>
                `
                    : ""
                }
                
                ${
                  payment.payment_history && payment.payment_history.length > 0
                    ? `
                <div>
                    <h4>Payment History</h4>
                    <table class="data-table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${payment.payment_history
                              .map(
                                (h) => `
                                <tr>
                                    <td>${h.payment_date_formatted}</td>
                                    <td>$${h.amount_formatted}</td>
                                    <td>${h.payment_method}</td>
                                    <td>${h.payment_status}</td>
                                </tr>
                            `,
                              )
                              .join("")}
                        </tbody>
                    </table>
                </div>
                `
                    : ""
                }
            `;

  container.innerHTML = detailsHTML;
}

// Edit payment
async function editPayment(paymentId) {
  // Similar to view but with editable form
  // Implementation depends on your requirements
  showAlert("Edit functionality to be implemented", "info");
}

// Delete payment
async function deletePayment(paymentId) {
  if (
    !confirm(
      "Are you sure you want to delete this payment? This action cannot be undone.",
    )
  ) {
    return;
  }

  try {
    const response = await fetch("../backend/payment/payment_manager.php?action=delete", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify({ id: paymentId }),
    });

    const result = await response.json();

    if (result.success) {
      showAlert("Payment deleted successfully", "success");
      loadPayments();
      loadStatistics();
    } else {
      showAlert(result.message, "error");
    }
  } catch (error) {
    console.error("Error:", error);
    showAlert("Error deleting payment", "error");
  }
}

// Export payments
async function exportPayments() {
  // Build export URL with current filters
  let query = `action=fetch&limit=10000`; // Large limit for export

  if (currentFilters.search)
    query += `&search=${encodeURIComponent(currentFilters.search)}`;
  if (currentFilters.tenant_id)
    query += `&tenant_id=${currentFilters.tenant_id}`;
  if (currentFilters.property_id)
    query += `&property_id=${currentFilters.property_id}`;
  if (currentFilters.payment_status)
    query += `&payment_status=${currentFilters.payment_status}`;
  if (currentFilters.date_from)
    query += `&date_from=${currentFilters.date_from}`;
  if (currentFilters.date_to) query += `&date_to=${currentFilters.date_to}`;

  // In a real implementation, you would generate CSV/Excel file
  // For now, show message
  showAlert("Export functionality to be implemented", "info");
}

// Print invoice
async function printInvoice() {
  const paymentId = getCurrentViewingPaymentId(); // You need to track this
  if (!paymentId) return;

  try {
    const response = await fetch(
      `../backend/payment/payment_manager.php?action=generate_invoice&payment_id=${paymentId}`,
    );
    const data = await response.json();

    if (data.success) {
      // Generate printable invoice HTML
      const invoiceWindow = window.open("", "_blank");
      invoiceWindow.document.write(generateInvoiceHTML(data.invoice));
      invoiceWindow.document.close();
      invoiceWindow.focus();
      invoiceWindow.print();
    }
  } catch (error) {
    console.error("Error generating invoice:", error);
    showAlert("Error generating invoice", "error");
  }
}

function generateInvoiceHTML(invoice) {
  return `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Invoice ${invoice.invoice_number}</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 40px; }
                        .invoice-header { text-align: center; margin-bottom: 40px; }
                        .invoice-details { margin-bottom: 30px; }
                        .invoice-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
                        .invoice-table th, .invoice-table td { border: 1px solid #ddd; padding: 10px; text-align: left; }
                        .invoice-table th { background: #f5f5f5; }
                        .total-row { font-weight: bold; }
                        .footer { margin-top: 50px; text-align: center; color: #666; }
                    </style>
                </head>
                <body>
                    <div class="invoice-header">
                        <h1>INVOICE</h1>
                        <h2>RentFlow Pro Management System</h2>
                    </div>
                    
                    <div class="invoice-details">
                        <table style="width: 100%;">
                            <tr>
                                <td>
                                    <strong>Invoice #:</strong> ${invoice.invoice_number}<br>
                                    <strong>Date:</strong> ${invoice.invoice_date}<br>
                                    <strong>Due Date:</strong> ${invoice.due_date}
                                </td>
                                <td style="text-align: right;">
                                    <strong>Bill To:</strong><br>
                                    ${invoice.tenant_name}<br>
                                    ${invoice.tenant_email}<br>
                                    ${invoice.tenant_phone}
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <table class="invoice-table">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Rent Payment for ${invoice.property_name}, Apartment ${invoice.apartment_number}</td>
                                <td>$${parseFloat(invoice.amount).toFixed(2)}</td>
                            </tr>
                            <tr class="total-row">
                                <td>TOTAL</td>
                                <td>$${parseFloat(invoice.amount).toFixed(2)}</td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <div class="footer">
                        <p>Thank you for your payment!</p>
                        <p>RentFlow Pro Management System</p>
                    </div>
                </body>
                </html>
            `;
}

// Utility functions
function showAlert(message, type = "info") {
  // Create alert element
  const alert = document.createElement("div");
  alert.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 15px 20px;
                background: ${type === "success" ? "#10b981" : type === "error" ? "#ef4444" : "#3b82f6"};
                color: white;
                border-radius: 6px;
                box-shadow: 0 4px 6px rgba(0,0,0,0.1);
                z-index: 10000;
                display: flex;
                align-items: center;
                gap: 10px;
                animation: slideIn 0.3s ease;
            `;

  alert.innerHTML = `
                <i class="fas ${type === "success" ? "fa-check-circle" : type === "error" ? "fa-exclamation-circle" : "fa-info-circle"}"></i>
                <span>${message}</span>
            `;

  document.body.appendChild(alert);

  // Remove after 3 seconds
  setTimeout(() => {
    alert.style.animation = "slideOut 0.3s ease";
    setTimeout(() => alert.remove(), 300);
  }, 3000);
}

// Auto-refresh every 5 minutes
setInterval(
  () => {
    loadPayments();
    loadStatistics();
  },
  60 * 60 * 1000,
);
