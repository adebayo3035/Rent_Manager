// payments.js
let paymentHistory = [];
let currentPage = 1;
let totalPages = 1;
let nextPaymentAmount = 0;
let nextPaymentDate = "";
let currentUserData = null;
let currentPaymentData = null;
let lastPaymentDate = null;
let lastPaymentPeriod = null;
let paymentSummary = null;

document.addEventListener("DOMContentLoaded", function () {
  initializePayments();
  initializePaymentModal();
  setupCardFormatting();
});

async function initializePayments() {
  if (window.currentUser) {
    await Promise.all([fetchDashboardData(), fetchPaymentHistory()]);
  } else {
    window.addEventListener("userDataLoaded", async function () {
      await Promise.all([fetchDashboardData(), fetchPaymentHistory()]);
    });

    setTimeout(async () => {
      if (
        !window.currentUser &&
        !document.querySelector(".payments-container")
      ) {
        await Promise.all([fetchDashboardData(), fetchPaymentHistory()]);
      }
    }, 1000);
  }
}

async function fetchDashboardData() {
  try {
    const response = await fetch("../backend/tenant/fetch_dashboard_data.php");
    const data = await response.json();

    if (data.success && data.data) {
      const dashboard = data.data;
      paymentSummary = dashboard.summary;

      // Get next payment information from dashboard data
      nextPaymentAmount =
        dashboard.next_payment_amount || dashboard.rent_amount || 0;
      nextPaymentDate =
        dashboard.next_payment_due_date ||
        dashboard.lease_end_date ||
        new Date().toISOString().split("T")[0];

      // Also store lease info
      if (dashboard.lease_end_date) {
        currentUserData = {
          ...currentUserData,
          lease_end_date: dashboard.lease_end_date,
          lease_start_date: dashboard.lease_start_date,
          payment_frequency: dashboard.payment_frequency,
          rent_amount: dashboard.rent_amount,
          property_name: dashboard.property_name,
          apartment_number: dashboard.apartment_number,
        };
      }

      console.log("Dashboard data loaded:", dashboard);
    }
  } catch (error) {
    console.error("Error fetching dashboard data:", error);
    if (window.showToast)
      window.showToast("Failed to load payment information", "error");
  }
}

async function fetchPaymentHistory() {
  try {
    const url = new URL(
      "../backend/payment/fetch_payment_history.php",
      window.location.href,
    );
    url.searchParams.append("page", currentPage);
    url.searchParams.append("limit", 10);

    const response = await fetch(url);
    const data = await response.json();

    if (data.success && data.data) {
      paymentHistory = data.data.payments || [];
      const pagination = data.data.pagination || {};
      totalPages = pagination.total_pages || 1;

      // Update summary from API response
      if (data.data.summary) {
        paymentSummary = data.data.summary;
      }

      // Get the last completed rent payment
      const lastRentPayment = paymentHistory.find(
        (p) => p.status === "completed" && p.payment_type === "rent",
      );
      if (lastRentPayment) {
        lastPaymentDate = lastRentPayment.payment_date;
        lastPaymentPeriod = lastRentPayment.payment_period;
        console.log("Last payment:", lastRentPayment);
      }

      renderPaymentPage();
    } else {
      throw new Error(data.message || "Failed to fetch payment history");
    }
  } catch (error) {
    console.error("Error fetching payment history:", error);
    if (window.showToast)
      window.showToast("Failed to load payment history", "error");
    showEmptyState();
  }
}

function renderPaymentPage() {
  const contentArea = document.getElementById("contentArea");
  if (!contentArea) return;

  const totalPaid =
    paymentSummary?.total_paid ||
    paymentHistory.reduce(
      (sum, p) => (p.status === "completed" ? sum + parseFloat(p.amount) : sum),
      0,
    );
  const successfulPayments =
    paymentSummary?.successful_payments ||
    paymentHistory.filter((p) => p.status === "completed").length;

  const html = `
        <div class="payments-container">
            <div class="page-header">
                <h1>Payments</h1>
                <p>Manage your rent payments and view payment history</p>
            </div>
            
            <div class="summary-cards">
                <div class="summary-card">
                    <h4>Next Payment Due</h4>
                    <div class="amount">₦${formatNumber(nextPaymentAmount)}</div>
                    <div class="label">Due by ${formatDate(nextPaymentDate)}</div>
                </div>
                <div class="summary-card">
                    <h4>Total Paid (All Time)</h4>
                    <div class="amount">₦${formatNumber(totalPaid)}</div>
                    <div class="label">${successfulPayments} successful payments</div>
                </div>
                ${
                  paymentSummary?.total_pending > 0
                    ? `
                <div class="summary-card" style="border-left: 3px solid #f59e0b;">
                    <h4>Pending Payments</h4>
                    <div class="amount">₦${formatNumber(paymentSummary.total_pending)}</div>
                    <div class="label">${paymentSummary.pending_payments || 0} pending</div>
                </div>
                `
                    : ""
                }
                ${
                  paymentSummary?.total_overdue > 0
                    ? `
                <div class="summary-card" style="border-left: 3px solid #ef4444;">
                    <h4>Overdue Payments</h4>
                    <div class="amount">₦${formatNumber(paymentSummary.total_overdue)}</div>
                    <div class="label">${paymentSummary.overdue_payments || 0} overdue</div>
                </div>
                `
                    : ""
                }
            </div>
            
            <div class="payment-card">
                <div class="payment-info">
                    <h3>Upcoming Rent Payment</h3>
                    <div class="amount">₦${formatNumber(nextPaymentAmount)}</div>
                    <div class="date">Due Date: ${formatDate(nextPaymentDate)}</div>
                </div>
                <button class="btn-primary" onclick="openRentPaymentModal()">
                    <i class="fas fa-credit-card"></i> Make Payment
                </button>
            </div>
            
            <div class="payments-table">
                <h3 style="padding: 20px 20px 0 20px;">Payment History</h3>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Period</th>
                                <th>Period Range</th>
                                <th>Status</th>
                                <th>Receipt</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${
                              paymentHistory.length === 0
                                ? `
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 40px;">
                                        <i class="fas fa-receipt" style="font-size: 48px; color: #ccc; margin-bottom: 10px; display: block;"></i>
                                        No payment records found
                                    </td>
                                </tr>
                            `
                                : paymentHistory
                                    .map((payment) => {
                                      let periodDisplay =
                                        payment.payment_period;
                                      let periodRange = "";

                                      if (
                                        payment.payment_type ===
                                        "security_deposit"
                                      ) {
                                        periodDisplay = "Security Deposit";
                                        periodRange = "One-time payment";
                                      } else if (
                                        payment.period_start_date &&
                                        payment.period_end_date
                                      ) {
                                        periodRange = `${formatDate(payment.period_start_date)} - ${formatDate(payment.period_end_date)}`;
                                      } else if (payment.payment_period) {
                                        periodRange = payment.payment_period;
                                      }

                                      return `
                                    <tr>
                                        <td>${formatDate(payment.payment_date)}</td>
                                        <td>₦${formatNumber(payment.amount)}</td>
                                        <td>${formatPaymentMethod(payment.payment_method)}</td>
                                        <td>${periodDisplay}</td>
                                        <td>${periodRange}</td>
                                        <td class="status-${payment.status}">${payment.status_display || payment.status}</td>
                                        <td>
                                            ${
                                              payment.receipt_number
                                                ? `
                                                <button class="btn-download" data-receipt="${payment.receipt_number}" title="Download Receipt">
                                                    <i class="fas fa-download"></i>
                                                </button>
                                            `
                                                : "-"
                                            }
                                        </td>
                                    </tr>
                                `;
                                    })
                                    .join("")
                            }
                        </tbody>
                    </table>
                </div>
            </div>
            
            ${renderPagination()}
        </div>
    `;

  contentArea.innerHTML = html;

  document.querySelectorAll(".btn-download").forEach((btn) => {
    btn.addEventListener("click", function (e) {
      e.stopPropagation();
      const receiptNumber = this.getAttribute("data-receipt");
      if (receiptNumber) downloadReceipt(receiptNumber);
    });
  });
}

function showEmptyState() {
  const contentArea = document.getElementById("contentArea");
  if (!contentArea) return;

  contentArea.innerHTML = `
        <div class="payments-container">
            <div class="page-header">
                <h1>Payments</h1>
                <p>Manage your rent payments and view payment history</p>
            </div>
            <div class="summary-cards">
                <div class="summary-card">
                    <h4>Next Payment Due</h4>
                    <div class="amount">₦${formatNumber(nextPaymentAmount)}</div>
                    <div class="label">Due by ${formatDate(nextPaymentDate)}</div>
                </div>
                <div class="summary-card">
                    <h4>Total Paid (All Time)</h4>
                    <div class="amount">₦0.00</div>
                    <div class="label">0 successful payments</div>
                </div>
            </div>
            <div class="payment-card">
                <div class="payment-info">
                    <h3>Upcoming Rent Payment</h3>
                    <div class="amount">₦${formatNumber(nextPaymentAmount)}</div>
                    <div class="date">Due Date: ${formatDate(nextPaymentDate)}</div>
                </div>
                <button class="btn-primary" onclick="openRentPaymentModal()">
                    <i class="fas fa-credit-card"></i> Make Payment
                </button>
            </div>
            <div class="empty-state">
                <i class="fas fa-receipt"></i>
                <h3>No Payment History</h3>
                <p>You haven't made any payments yet.</p>
                <button class="btn-primary" onclick="openRentPaymentModal()" style="margin-top: 15px;">
                    Make Your First Payment
                </button>
            </div>
        </div>
    `;
}

function renderPagination() {
  if (totalPages <= 1) return "";
  let html = '<div class="pagination">';
  for (let i = 1; i <= totalPages; i++) {
    html += `<button class="page-btn ${i === currentPage ? "active" : ""}" onclick="goToPage(${i})">${i}</button>`;
  }
  html += "</div>";
  return html;
}

function goToPage(page) {
  currentPage = page;
  fetchPaymentHistory();
}

// Calculate next payment period based on last payment
// Calculate next payment period based on last payment's end date
// Calculate next payment period based on last payment's end date
function calculateNextPaymentPeriod() {
    if (!currentUserData) return null;

    const paymentFrequency = currentUserData.payment_frequency;
    const rentAmount = currentUserData.rent_amount;
    const leaseStart = new Date(currentUserData.lease_start_date);
    const leaseEnd = new Date(currentUserData.lease_end_date);
    const today = new Date();
    
    let periodStart = null;
    let periodEnd = null;
    let periodLabel = "";
    let dueDate = null;

    // Get the last payment's end date from the payment history
    let referenceEndDate = null;
    
    if (lastPaymentEndDate) {
        // Use last payment's end date if available
        referenceEndDate = new Date(lastPaymentEndDate);
        console.log('Using last payment end date:', referenceEndDate);
    } else {
        // First payment - use lease start date minus 1 day as reference
        referenceEndDate = new Date(leaseStart);
        referenceEndDate.setDate(referenceEndDate.getDate() - 1);
        console.log('Using lease start as reference (first payment):', referenceEndDate);
    }

    // Calculate next period start (last payment end date + 1 day)
    periodStart = new Date(referenceEndDate);
    periodStart.setDate(periodStart.getDate() + 1);
    
    // Calculate period end based on payment frequency
    periodEnd = new Date(periodStart);
    
    // Define grace periods for due date calculation
    const gracePeriods = {
        "Monthly": 7,
        "Quarterly": 14,
        "Semi-Annually": 30,
        "Annually": 90
    };
    
    const graceDays = gracePeriods[paymentFrequency] || 7;
    
    switch (paymentFrequency) {
        case "Monthly":
            periodEnd.setMonth(periodEnd.getMonth() + 1);
            periodEnd.setDate(periodEnd.getDate() - 1);
            periodLabel = formatPeriodRange(periodStart, periodEnd, "Monthly");
            dueDate = new Date(periodEnd);
            dueDate.setDate(dueDate.getDate() + graceDays);
            break;

        case "Quarterly":
            periodEnd.setMonth(periodEnd.getMonth() + 3);
            periodEnd.setDate(periodEnd.getDate() - 1);
            periodLabel = formatPeriodRange(periodStart, periodEnd, "Quarterly");
            dueDate = new Date(periodEnd);
            dueDate.setDate(dueDate.getDate() + graceDays);
            break;

        case "Semi-Annually":
            periodEnd.setMonth(periodEnd.getMonth() + 6);
            periodEnd.setDate(periodEnd.getDate() - 1);
            periodLabel = formatPeriodRange(periodStart, periodEnd, "Semi-Annually");
            dueDate = new Date(periodEnd);
            dueDate.setDate(dueDate.getDate() + graceDays);
            break;

        case "Annually":
            periodEnd.setFullYear(periodEnd.getFullYear() + 1);
            periodEnd.setDate(periodEnd.getDate() - 1);
            periodLabel = formatPeriodRange(periodStart, periodEnd, "Annually");
            dueDate = new Date(periodEnd);
            dueDate.setDate(dueDate.getDate() + graceDays);
            break;

        default:
            periodEnd.setMonth(periodEnd.getMonth() + 1);
            periodEnd.setDate(periodEnd.getDate() - 1);
            periodLabel = formatPeriodRange(periodStart, periodEnd, "Monthly");
            dueDate = new Date(periodEnd);
            dueDate.setDate(dueDate.getDate() + graceDays);
    }

    // Check if the calculated period start is beyond lease end
    const isBeyondLease = periodStart > leaseEnd;
    
    // Calculate upcoming period (even if beyond lease, for advance payments)
    const formattedDueDate = dueDate.toLocaleDateString("en-US", {
        year: "numeric",
        month: "long",
        day: "numeric",
    });

    console.log('Calculated next payment:', {
        period_start: periodStart,
        period_end: periodEnd,
        period_label: periodLabel,
        amount: rentAmount,
        due_date: dueDate,
        formatted_due_date: formattedDueDate,
        is_beyond_lease: isBeyondLease
    });

    return {
        has_upcoming: true, // Always allow upfront payment
        is_advance_payment: isBeyondLease,
        period_start: periodStart.toISOString().split("T")[0],
        period_end: periodEnd.toISOString().split("T")[0],
        period_label: periodLabel,
        amount: rentAmount,
        due_date: dueDate.toISOString().split("T")[0],
        formatted_due_date: formattedDueDate,
        payment_frequency: paymentFrequency,
        message: isBeyondLease ? "This will be an advance payment extending your lease" : null
    };
}
// Helper function to format period range based on frequency
function formatPeriodRange(startDate, endDate, frequency) {
  const start = new Date(startDate);
  const end = new Date(endDate);

  switch (frequency) {
    case "Monthly":
      return `${start.toLocaleDateString("en-US", { month: "long", year: "numeric" })}`;
    case "Quarterly":
      const quarter = Math.ceil((start.getMonth() + 1) / 3);
      return `Q${quarter} ${start.getFullYear()}`;
    case "Semi-Annually":
      const half = start.getMonth() < 6 ? "H1" : "H2";
      return `${half} ${start.getFullYear()}`;
    case "Annually":
      return `${start.getFullYear()}`;
    default:
      return `${start.toLocaleDateString("en-US", { month: "long", year: "numeric" })}`;
  }
}

// Update initializePaymentModal to get the last payment's end date
async function initializePaymentModal() {
  try {
    const userResponse = await fetch("../backend/tenant/fetch_user_data.php");
    const userData = await userResponse.json();

    if (userData.success) {
      currentUserData = userData.data;
      console.log("Current User Data:", {
        payment_frequency: currentUserData.payment_frequency,
        rent_amount: currentUserData.rent_amount,
        lease_start_date: currentUserData.lease_start_date,
        lease_end_date: currentUserData.lease_end_date,
      });
    }

    // Fetch payment history to get the last payment's end date
    const paymentResponse = await fetch(
      "../backend/payment/fetch_payment_history.php?limit=1",
    );
    const paymentData = await paymentResponse.json();

    if (paymentData.success && paymentData.data?.payments) {
      const lastRentPayment = paymentData.data.payments.find(
        (p) => p.status === "completed" && p.payment_type === "rent",
      );
      if (lastRentPayment) {
        lastPaymentDate = lastRentPayment.payment_date;
        lastPaymentPeriod = lastRentPayment.payment_period;
        // Get the period end date from the payment record
        lastPaymentEndDate = lastRentPayment.period_end_date;
        console.log("Last payment end date:", lastPaymentEndDate);
      }
    }
  } catch (error) {
    console.error("Error loading payment data:", error);
  }
}

// Add global variable for last payment end date
let lastPaymentEndDate = null;

// Update populatePaymentSummary to use the new calculation
// Populate payment summary in modal

const formatDateShort = (date) => {
  return date.toLocaleDateString('en-GB', {
    day: '2-digit',
    month: 'long',
    year: 'numeric'
  });
};

function populatePaymentSummary() {
    if (!currentUserData) return;

    const nextPayment = calculateNextPaymentPeriod();

    if (!nextPayment) {
        console.error("Could not calculate next payment period");
        return;
    }

    // Format period display for the modal
    let periodDisplay = "";
    let warningMessage = "";
    
    if (nextPayment.is_advance_payment) {
        warningMessage = `<div class="warning-message" style="background: #fff3cd; border-left: 3px solid #ffc107; padding: 10px; margin-bottom: 15px; border-radius: 4px;">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Advance Payment:</strong> ${nextPayment.message || "This payment will extend your lease period."}
                          </div>`;
    }
    
    if (currentUserData.payment_frequency === "Monthly") {
        periodDisplay = `${new Date(nextPayment.period_start).toLocaleDateString("en-US", { month: "long", day: "numeric", year: "numeric" })} - ${new Date(nextPayment.period_end).toLocaleDateString("en-US", { month: "long", day: "numeric", year: "numeric" })}`;
    } else if (currentUserData.payment_frequency === "Quarterly") {
        periodDisplay = `${new Date(nextPayment.period_start).toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" })} - ${new Date(nextPayment.period_end).toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" })}`;
    } else if (currentUserData.payment_frequency === "Annually") {
        // const startYear = new Date(nextPayment.period_start).getFullYear();
        // const endYear = new Date(nextPayment.period_end).getFullYear();
        // periodDisplay = `${startYear} - ${endYear}`;

        const startDate = formatDateShort(new Date(nextPayment.period_start));
        const endDate = formatDateShort(new Date(nextPayment.period_end));
        periodDisplay = `${startDate} - ${endDate}`;
    } else {
        periodDisplay = nextPayment.period_label;
    }

    const summaryPeriod = document.getElementById("summaryPeriod");
    const summaryAmount = document.getElementById("summaryAmount");
    const summaryDueDate = document.getElementById("summaryDueDate");
    const summaryProperty = document.getElementById("summaryProperty");
    const summaryApartment = document.getElementById("summaryApartment");
    const warningContainer = document.getElementById("paymentWarningContainer");

    if (summaryPeriod) summaryPeriod.textContent = periodDisplay;
    if (summaryAmount)
        summaryAmount.textContent = `₦${formatNumber(nextPayment.amount)}`;
    if (summaryDueDate)
        summaryDueDate.textContent = nextPayment.formatted_due_date;
    if (summaryProperty)
        summaryProperty.textContent = currentUserData.property_name || "N/A";
    if (summaryApartment)
        summaryApartment.textContent = currentUserData.apartment_number || "N/A";
    
    // Add warning message if advance payment
    if (warningContainer) {
        if (nextPayment.is_advance_payment) {
            warningContainer.innerHTML = warningMessage;
            warningContainer.style.display = "block";
        } else {
            warningContainer.innerHTML = "";
            warningContainer.style.display = "none";
        }
    }

    currentPaymentData = {
        period: periodDisplay,
        period_start: nextPayment.period_start,
        period_end: nextPayment.period_end,
        raw_period: nextPayment.period_label,
        amount: nextPayment.amount,
        due_date: nextPayment.due_date,
        formatted_due_date: nextPayment.formatted_due_date,
        property_name: currentUserData.property_name,
        apartment_number: currentUserData.apartment_number,
        payment_frequency: currentUserData.payment_frequency,
        is_advance_payment: nextPayment.is_advance_payment
    };
}
// Open payment modal
async function openRentPaymentModal() {
  if (!currentUserData) await initializePaymentModal();
  populatePaymentSummary();

  document
    .querySelectorAll('input[name="paymentMethodRadio"]')
    .forEach((radio) => (radio.checked = false));
  document.getElementById("paymentMethod").value = "";
  document
    .querySelectorAll(".payment-details-section")
    .forEach((section) => (section.style.display = "none"));

  const fields = [
    "bankReference",
    "cardNumber",
    "cardExpiry",
    "cardCvv",
    "cardHolderName",
    "chequeNumber",
    "chequeBank",
    "chequeDate",
    "paymentNotes",
  ];
  fields.forEach((field) => {
    const el = document.getElementById(field);
    if (el) el.value = "";
  });

  openModal("paymentModal");
}

function setupPaymentMethodListeners() {
  document
    .querySelectorAll('input[name="paymentMethodRadio"]')
    .forEach((radio) => {
      radio.removeEventListener("change", handlePaymentMethodChange);
      radio.addEventListener("change", handlePaymentMethodChange);
    });
}

function generateBankReferenceNumber() {
  const date = new Date();
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  const random = Math.random().toString(36).substring(2, 10).toUpperCase();
  const timestamp = Date.now().toString().slice(-6);

  return `BNK-${year}${month}${day}-${random}-${timestamp}`;
}

function handlePaymentMethodChange() {
  const method = this.value;
  document.getElementById("paymentMethod").value = method;

  document.querySelectorAll(".payment-details-section").forEach((section) => {
    section.style.display = "none";
  });

  if (method === "bank_transfer") {
    const section = document.getElementById("bankTransferDetails");
    if (section) section.style.display = "block";
    const bankReferenceInput = document.getElementById("bankReference");
    if (bankReferenceInput) {
      const autoRef = generateBankReferenceNumber();
      bankReferenceInput.value = autoRef;
      bankReferenceInput.readOnly = true;
      bankReferenceInput.style.background = "#f0f0f0";
      bankReferenceInput.style.cursor = "not-allowed";
    }
  } else if (method === "card") {
    const section = document.getElementById("cardPaymentDetails");
    if (section) section.style.display = "block";
  } else if (method === "cash") {
    const section = document.getElementById("cashPaymentDetails");
    if (section) section.style.display = "block";
  } else if (method === "cheque") {
    const section = document.getElementById("chequePaymentDetails");
    if (section) section.style.display = "block";
  }
}

async function processPayment() {
  const paymentMethod = document.getElementById("paymentMethod").value;
  const paymentNotes = document.getElementById("paymentNotes").value;

  if (!paymentMethod) {
    if (window.showToast)
      window.showToast("Please select a payment method", "error");
    return;
  }

  let referenceNumber = null;

  if (paymentMethod === "bank_transfer") {
    referenceNumber = document.getElementById("bankReference").value;
    if (!referenceNumber) {
      if (window.showToast)
        window.showToast(
          "Please enter the bank transaction reference",
          "error",
        );
      return;
    }
  } else if (paymentMethod === "card") {
    const cardNumber = document
      .getElementById("cardNumber")
      .value.replace(/\s/g, "");
    const cardExpiry = document.getElementById("cardExpiry").value;
    const cardCvv = document.getElementById("cardCvv").value;
    const cardHolderName = document.getElementById("cardHolderName").value;

    if (!cardNumber || cardNumber.length < 16) {
      if (window.showToast)
        window.showToast("Please enter a valid card number", "error");
      return;
    }
    if (!cardExpiry || !cardExpiry.match(/^\d{2}\/\d{2}$/)) {
      if (window.showToast)
        window.showToast("Please enter a valid expiry date (MM/YY)", "error");
      return;
    }
    if (!cardCvv || cardCvv.length < 3) {
      if (window.showToast)
        window.showToast("Please enter a valid CVV", "error");
      return;
    }
    if (!cardHolderName) {
      if (window.showToast)
        window.showToast("Please enter card holder name", "error");
      return;
    }
    referenceNumber = `CARD-${Date.now()}-${cardNumber.slice(-4)}`;
  } else if (paymentMethod === "cheque") {
    const chequeNumber = document.getElementById("chequeNumber").value;
    const chequeBank = document.getElementById("chequeBank").value;
    const chequeDate = document.getElementById("chequeDate").value;

    if (!chequeNumber) {
      if (window.showToast)
        window.showToast("Please enter cheque number", "error");
      return;
    }
    if (!chequeBank) {
      if (window.showToast) window.showToast("Please enter bank name", "error");
      return;
    }
    if (!chequeDate) {
      if (window.showToast)
        window.showToast("Please select cheque date", "error");
      return;
    }
    referenceNumber = `CHQ-${chequeNumber}`;
  }

  closeModal("paymentModal");
  openModal("processingModal");

  const processingMsg = document.getElementById("processingMessage");
  if (processingMsg)
    processingMsg.innerHTML =
      "Processing your payment...<br>Please do not close this window.";

  try {
    if (paymentMethod === "card") {
      await simulateCardPayment();
    }

    const response = await fetch(
      "../backend/payment/initiate_rent_payment.php",
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          payment_method: paymentMethod,
          payment_period:
            currentPaymentData.raw_period || currentPaymentData.period,
          amount: currentPaymentData.amount,
          reference_number: referenceNumber,
          notes: `${paymentNotes}\nPayment period: ${currentPaymentData.period}`,
        }),
      },
    );

    const data = await response.json();

    if (!data.success)
      throw new Error(data.message || "Failed to process payment");

    const paymentResult = data.data;

    if (processingMsg)
      processingMsg.innerHTML = "Payment successful!<br>Generating receipt...";

    await new Promise((resolve) => setTimeout(resolve, 1000));

    closeModal("processingModal");
    showPaymentSuccessModal({
      ...paymentResult,
      payment_period: currentPaymentData.period,
    });

    lastPaymentDate = new Date().toISOString().split("T")[0];

    await Promise.all([fetchDashboardData(), fetchPaymentHistory()]);
  } catch (error) {
    console.error("Payment error:", error);
    closeModal("processingModal");
    if (window.showToast) window.showToast(error.message, "error");
  }
}

function showPaymentSuccessModal(paymentData) {
  const modalHtml = `
        <div class="modal active" id="paymentSuccessModal">
            <div class="modal-content" style="max-width: 450px;">
                <div class="modal-header">
                    <h3>Payment Successful!</h3>
                    <button class="modal-close" onclick="closeModal('paymentSuccessModal')">&times;</button>
                </div>
                <div class="modal-body">
                    <div style="text-align: center; margin-bottom: 20px;">
                        <i class="fas fa-check-circle" style="font-size: 64px; color: #10b981;"></i>
                    </div>
                    <div class="payment-details">
                        <div class="detail-row">
                            <span class="detail-label">Payment Period:</span>
                            <span class="detail-value">${escapeHtml(paymentData.payment_period)}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Amount Paid:</span>
                            <span class="detail-value">₦${formatNumber(paymentData.amount)}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Receipt Number:</span>
                            <span class="detail-value">${escapeHtml(paymentData.receipt_number)}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Reference Number:</span>
                            <span class="detail-value">${escapeHtml(paymentData.reference_number)}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Payment Date:</span>
                            <span class="detail-value">${new Date().toLocaleDateString()}</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="closeModal('paymentSuccessModal')">Close</button>
                    <button class="btn-primary" onclick="downloadReceipt('${paymentData.receipt_number}')">
                        <i class="fas fa-download"></i> Download Receipt
                    </button>
                </div>
            </div>
        </div>
    `;

  const existingModal = document.getElementById("paymentSuccessModal");
  if (existingModal) existingModal.remove();

  document.body.insertAdjacentHTML("beforeend", modalHtml);
}

async function simulateCardPayment() {
  return new Promise((resolve) => {
    let progress = 0;
    const interval = setInterval(() => {
      progress += 20;
      const processingMsg = document.getElementById("processingMessage");
      if (processingMsg)
        processingMsg.innerHTML = `Processing card payment... ${progress}%`;
      if (progress >= 100) {
        clearInterval(interval);
        resolve();
      }
    }, 500);
  });
}

function setupCardFormatting() {
  const cardNumberInput = document.getElementById("cardNumber");
  if (cardNumberInput) {
    cardNumberInput.addEventListener("input", function (e) {
      let value = e.target.value.replace(/\s/g, "");
      if (value.length > 16) value = value.slice(0, 16);
      value = value.replace(/(\d{4})/g, "$1 ").trim();
      e.target.value = value;
    });
  }

  const cardExpiryInput = document.getElementById("cardExpiry");
  if (cardExpiryInput) {
    cardExpiryInput.addEventListener("input", function (e) {
      let value = e.target.value.replace(/\//g, "");
      if (value.length >= 2) {
        value = value.slice(0, 2) + "/" + value.slice(2, 4);
      }
      e.target.value = value;
    });
  }
}

function downloadReceipt(receiptNumber) {
  if (!receiptNumber) {
    if (window.showToast) window.showToast("Receipt number not found", "error");
    return;
  }

  try {
    const encodedRef = encodeURIComponent(receiptNumber);
    window.open(
      `../backend/payment/download_receipt.php?receipt_number=${encodedRef}`,
      "_blank",
    );
  } catch (error) {
    console.error("Error downloading receipt:", error);
    if (window.showToast)
      window.showToast("Failed to download receipt", "error");
  }
}

function closeModal(modalId) {
  const modal = document.getElementById(modalId);
  if (modal) modal.classList.remove("active");
}

function openModal(modalId) {
  const modal = document.getElementById(modalId);
  if (modal) {
    modal.classList.add("active");
    setupPaymentMethodListeners();
  }
}

function formatNumber(value) {
  if (!value || value === "0") return "0.00";
  return new Intl.NumberFormat("en-NG", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(value);
}

function formatDate(dateString) {
  if (!dateString) return "N/A";
  try {
    const date = new Date(dateString);
    return date.toLocaleDateString("en-US", {
      year: "numeric",
      month: "short",
      day: "numeric",
    });
  } catch (e) {
    return dateString;
  }
}

function formatPaymentMethod(method) {
  const map = {
    bank_transfer: "Bank Transfer",
    card: "Card",
    cash: "Cash",
    cheque: "Cheque",
  };
  return map[method] || method;
}

function escapeHtml(text) {
  if (!text) return "";
  const div = document.createElement("div");
  div.textContent = text;
  return div.innerHTML;
}
