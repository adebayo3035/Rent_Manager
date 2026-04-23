// payments.js - Complete Refactored for tracker-based payment system
let paymentHistory = [];
let currentPage = 1;
let totalPages = 1;
let currentUserData = null;
let currentPaymentData = null;
let paymentSummary = null;

document.addEventListener("DOMContentLoaded", function () {
  initializePayments();
  setupCardFormatting();
});

async function initializePayments() {
  try {
    await fetchPaymentUserData();
    await fetchDashboardData();
    await fetchPaymentHistory();
  } catch (error) {
    console.error("Error initializing payments:", error);
    if (window.showToast) {
      window.showToast("Failed to load payment data", "error");
    }
    showEmptyState();
  }
}

async function fetchPaymentUserData() {
  try {
    if (window.currentUser && window.currentUser.tenant_code) {
      currentUserData = window.currentUser;
      console.log("Using cached user data:", currentUserData);
      return;
    }

    const response = await fetch("../backend/tenant/fetch_user_data.php");
    const data = await response.json();
    
    if (data.success && data.data) {
      currentUserData = data.data;
      if (!window.currentUser) {
        window.currentUser = currentUserData;
      }
      console.log("User data loaded:", currentUserData);
    } else {
      throw new Error(data.message || "Failed to fetch user data");
    }
  } catch (error) {
    console.error("Error fetching user data:", error);
    throw error;
  }
}

async function fetchDashboardData() {
  try {
    const response = await fetch("../backend/tenant/fetch_dashboard_data.php");
    const data = await response.json();

    if (data.success && data.data) {
      window.dashboardData = data.data;
      paymentSummary = data.data.summary;
      console.log("Dashboard data loaded:", data.data);
    } else {
      throw new Error(data.message || "Failed to fetch dashboard data");
    }
  } catch (error) {
    console.error("Error fetching dashboard data:", error);
    if (window.showToast) {
      window.showToast("Failed to load payment information", "error");
    }
    throw error;
  }
}

async function fetchPaymentHistory() {
  try {
    const url = new URL("../backend/payment/fetch_payment_history.php", window.location.href);
    url.searchParams.append("page", currentPage);
    url.searchParams.append("limit", 10);

    const response = await fetch(url);
    const data = await response.json();

    if (data.success && data.data) {
      paymentHistory = data.data.payments || [];
      const pagination = data.data.pagination || {};
      totalPages = pagination.total_pages || 1;
      
      if (data.data.summary) {
        paymentSummary = data.data.summary;
      }
      
      renderPaymentPage();
    } else {
      throw new Error(data.message || "Failed to fetch payment history");
    }
  } catch (error) {
    console.error("Error fetching payment history:", error);
    if (window.showToast) {
      window.showToast("Failed to load payment history", "error");
    }
    showEmptyState();
  }
}

function renderPaymentPage() {
  const contentArea = document.getElementById("contentArea");
  if (!contentArea) return;

  // Get summary data from dashboard
  const totalPaid = paymentSummary?.total_paid || 0;
  const successfulPayments = paymentSummary?.successful_payments || 0;
  const paymentPerPeriod = paymentSummary?.payment_per_period || currentUserData?.payment_amount_per_period || 0;
  const remainingBalance = paymentSummary?.remaining_balance || 0;
  
  // Get pending periods from dashboard - these are periods with status 'available' or 'failed'
  const pendingPeriods = window.dashboardData?.pending_periods || [];
  const hasPendingVerification = window.dashboardData?.has_pending_payment || false;
  const isLeaseFullyPaid = window.dashboardData?.is_lease_fully_paid || false;
  
  // Find the next available period (status = 'available') - this is what the tenant can pay
  const nextAvailablePeriod = pendingPeriods.find(p => p.status === 'available');
  const hasAvailablePeriod = !!nextAvailablePeriod;
  
  // Also check for failed period
  const failedPeriod = pendingPeriods.find(p => p.status === 'failed');
  const hasFailedPeriod = !!failedPeriod;
  
  // Determine which period to show (priority: available > failed)
  const periodToShow = nextAvailablePeriod || failedPeriod;
  const hasPeriodToShow = !!periodToShow;
  
  const nextPaymentAmount = periodToShow ? periodToShow.amount_due : paymentPerPeriod;
  const nextPaymentDueDate = periodToShow ? calculateDueDate(periodToShow.end_date, currentUserData?.payment_frequency) : null;

  console.log("=== Payment Page Debug ===");
  console.log("pendingPeriods:", pendingPeriods);
  console.log("hasPendingVerification:", hasPendingVerification);
  console.log("isLeaseFullyPaid:", isLeaseFullyPaid);
  console.log("nextAvailablePeriod:", nextAvailablePeriod);
  console.log("failedPeriod:", failedPeriod);
  console.log("periodToShow:", periodToShow);

  const html = `
    <div class="payments-container">
      <div class="page-header">
        <h1>Payments</h1>
        <p>Manage your rent payments and view payment history</p>
      </div>
      
      <div class="summary-cards">
        <div class="summary-card">
          <h4>Payment per Period</h4>
          <div class="amount">₦${formatNumber(paymentPerPeriod)}</div>
          <div class="label">${currentUserData?.agreed_payment_frequency || currentUserData?.payment_frequency || 'Monthly'}</div>
        </div>
        <div class="summary-card">
          <h4>Total Paid</h4>
          <div class="amount">₦${formatNumber(totalPaid)}</div>
          <div class="label">${successfulPayments} payments</div>
        </div>
        <div class="summary-card">
          <h4>Remaining Balance</h4>
          <div class="amount">₦${formatNumber(remainingBalance)}</div>
          <div class="label">${pendingPeriods.length} periods left</div>
        </div>
      </div>
      
      ${hasPendingVerification ? `
      <div class="payment-card pending-card">
        <div class="payment-info">
          <i class="fas fa-clock" style="font-size: 48px; color: #f59e0b; margin-bottom: 15px;"></i>
          <h3>Payment Pending Verification</h3>
          <p style=" color: #000;">You have a pending payment waiting for admin verification.</p>
          <div class="pending-warning" style=" color: #000;">Please wait for admin verification before making another payment.</div>
        </div>
      </div>
      ` : isLeaseFullyPaid ? `
      <div class="payment-card success-card">
        <div class="payment-info">
          <i class="fas fa-check-circle" style="font-size: 48px; color: #10b981; margin-bottom: 15px;"></i>
          <h3>Lease Fully Paid!</h3>
          <p>Congratulations! You have completed all your rent payments.</p>
        </div>
      </div>
      ` : hasPeriodToShow ? `
      <div class="payment-card">
        <div class="payment-info">
          <h3>${hasFailedPeriod ? 'Retry Payment' : 'Next Payment Due'}</h3>
          <div class="period-badge">Period #${periodToShow.period_number}</div>
          <div class="period-label">${escapeHtml(periodToShow.period || formatPeriodForDisplay(periodToShow.start_date, periodToShow.end_date, currentUserData?.payment_frequency))}</div>
          <div class="period-range">${formatDateRange(periodToShow.start_date, periodToShow.end_date)}</div>
          <div class="amount">₦${formatNumber(periodToShow.amount_due || paymentPerPeriod)}</div>
          <div class="date">Due Date: ${formatDate(nextPaymentDueDate)}</div>
          ${hasFailedPeriod ? '<div class="failed-badge">Previous payment failed - Please retry</div>' : ''}
        </div>
        <button class="btn-primary" onclick="openRentPaymentModal()">
          <i class="fas fa-credit-card"></i> ${hasFailedPeriod ? 'Retry Payment' : 'Make Payment'}
        </button>
      </div>
      ` : `
      <div class="payment-card info-card">
        <div class="payment-info">
          <i class="fas fa-info-circle" style="font-size: 48px; color: #17a2b8; margin-bottom: 15px;"></i>
          <h3>No Pending Payments</h3>
          <p>You have no pending payments at this time.</p>
        </div>
      </div>
      `}
      
     <div class="payments-table">
  <h3>Rent Payment History</h3>
  <div style="overflow-x: auto;">
    <table>
      <thead>
        <tr>
          <th>Date</th>
          <th>Period #</th>
          <th>Amount</th>
          <th>Period</th>
          <th>Period Range</th>
          <th>Status</th>
          <th>Receipt</th>
        </tr>
      </thead>
      <tbody>
        ${paymentHistory.length === 0 ? `
        <tr>
          <td colspan="7" style="text-align: center; padding: 40px;">
            <i class="fas fa-receipt" style="font-size: 48px; color: #ccc; margin-bottom: 10px; display: block;"></i>
            No payment records found
          </td
        </tr>
        ` : paymentHistory.map((payment) => {
          let periodDisplay = payment.payment_period || 'N/A';
          let periodRange = '';
          let periodNumber = '';

          if (payment.payment_type === "security_deposit") {
            periodDisplay = "Security Deposit";
            periodRange = "One-time payment";
          } else if (payment.period_number) {
            periodNumber = `#${payment.period_number}`;
          }
          
          if (payment.period_start_date && payment.period_end_date) {
            periodRange = `${formatDate(payment.period_start_date)} - ${formatDate(payment.period_end_date)}`;
          } else if (payment.payment_period) {
            periodRange = payment.payment_period;
          }

          let statusClass = '';
          let statusText = payment.status_display || payment.status;
          
          if (payment.status === 'pending_verification' || payment.status === 'pending') {
            statusClass = 'status-pending';
            statusText = 'Pending';
          } else if (payment.status === 'paid') {
            statusClass = 'status-completed';
            statusText = 'Paid';
          } else if (payment.status === 'failed') {
            statusClass = 'status-failed';
            statusText = 'Failed';
          } else if (payment.status === 'completed') {
            statusClass = 'status-completed';
            statusText = 'Completed';
          }

          return `
          <tr>
            <td>${payment.payment_date ? formatDate(payment.payment_date) : '—'}</td>
            <td>${periodNumber || '—'}</td>
            <td>₦${formatNumber(payment.amount)}</td>
            <td>${escapeHtml(periodDisplay)}</td>
            <td>${periodRange || '—'}</td>
            <td class="${statusClass}">${statusText}</td>
            <td>
            ${payment.receipt_number && (payment.status === 'paid' || payment.status === 'completed') ? `
<button class="btn-download" onclick="downloadReceipt(${payment.payment_id || 0}, '${payment.receipt_number}', '${payment.payment_type}')" title="Download Receipt">
    <i class="fas fa-download"></i>
</button>
` : '—'}
            </td>
          </tr>
        `;
        }).join('')}
      </tbody>
    </table>
  </div>
  ${renderPagination()}
</div>
  `;

  contentArea.innerHTML = html;

  // document.querySelectorAll(".btn-download").forEach((btn) => {
  //   btn.addEventListener("click", function (e) {
  //     e.stopPropagation();
  //     const receiptNumber = this.getAttribute("data-receipt");
  //     if (receiptNumber) downloadReceipt(receiptNumber);
  //   });
  // });
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
      <div class="empty-state">
        <i class="fas fa-receipt"></i>
        <h3>No Payment Data</h3>
        <p>Unable to load payment information. Please try again later.</p>
        <button class="btn-primary" onclick="location.reload()" style="margin-top: 15px;">
          Refresh Page
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

function populatePaymentSummary() {
  if (!currentUserData || !window.dashboardData) {
    console.error("Missing required data for payment summary");
    return;
  }

  const pendingPeriods = window.dashboardData.pending_periods || [];
  
  // Check for pending verification first
  if (window.dashboardData.has_pending_payment) {
    const summaryPeriod = document.getElementById("summaryPeriod");
    const summaryAmount = document.getElementById("summaryAmount");
    const summaryDueDate = document.getElementById("summaryDueDate");
    const warningContainer = document.getElementById("paymentWarningContainer");
    
    if (summaryPeriod) summaryPeriod.textContent = "Payment Pending";
    if (summaryAmount) summaryAmount.textContent = "Awaiting Verification";
    if (summaryDueDate) summaryDueDate.textContent = "N/A";
    if (warningContainer) {
      warningContainer.innerHTML = `
        <div class="warning-message" style="background: #fff3cd; border-left: 3px solid #f59e0b; padding: 10px; margin-bottom: 15px; border-radius: 4px;">
          <i class="fas fa-clock"></i> 
          <strong>Pending Verification:</strong> You have a pending payment waiting for admin verification.
        </div>
      `;
      warningContainer.style.display = "block";
    }
    return;
  }
  
  // Check if lease is fully paid
  if (window.dashboardData.is_lease_fully_paid) {
    const summaryPeriod = document.getElementById("summaryPeriod");
    const summaryAmount = document.getElementById("summaryAmount");
    const summaryDueDate = document.getElementById("summaryDueDate");
    const warningContainer = document.getElementById("paymentWarningContainer");
    
    if (summaryPeriod) summaryPeriod.textContent = "Lease Fully Paid";
    if (summaryAmount) summaryAmount.textContent = "₦0.00";
    if (summaryDueDate) summaryDueDate.textContent = "N/A";
    if (warningContainer) {
      warningContainer.innerHTML = `
        <div class="success-message" style="background: #d4edda; border-left: 3px solid #28a745; padding: 10px; margin-bottom: 15px; border-radius: 4px;">
          <i class="fas fa-check-circle"></i> 
          <strong>Congratulations!</strong> Your lease has been fully paid.
        </div>
      `;
      warningContainer.style.display = "block";
    }
    
    const paymentButton = document.querySelector('#paymentModal .btn-primary');
    if (paymentButton) paymentButton.disabled = true;
    return;
  }
  
  // Find the period to pay (available or failed)
  const periodToPay = pendingPeriods.find(p => p.status === 'available') || pendingPeriods.find(p => p.status === 'failed');
  
  if (!periodToPay) {
    const warningContainer = document.getElementById("paymentWarningContainer");
    if (warningContainer) {
      warningContainer.innerHTML = `
        <div class="info-message" style="background: #d1ecf1; border-left: 3px solid #17a2b8; padding: 10px; margin-bottom: 15px; border-radius: 4px;">
          <i class="fas fa-info-circle"></i> 
          <strong>No Pending Payments:</strong> You have no pending payments at this time.
        </div>
      `;
      warningContainer.style.display = "block";
    }
    return;
  }
  
  const paymentFrequency = currentUserData.agreed_payment_frequency || currentUserData.payment_frequency || 'Monthly';
  const dueDate = calculateDueDate(periodToPay.end_date, paymentFrequency);
  const isOverdue = new Date(dueDate) < new Date();
  const periodDisplay = formatPeriodForDisplay(periodToPay.start_date, periodToPay.end_date, paymentFrequency);
  
  const summaryPeriod = document.getElementById("summaryPeriod");
  const summaryAmount = document.getElementById("summaryAmount");
  const summaryDueDate = document.getElementById("summaryDueDate");
  const summaryProperty = document.getElementById("summaryProperty");
  const summaryApartment = document.getElementById("summaryApartment");
  const warningContainer = document.getElementById("paymentWarningContainer");
  
  if (summaryPeriod) summaryPeriod.textContent = periodDisplay;
  if (summaryAmount) summaryAmount.textContent = `₦${formatNumber(periodToPay.amount_due)}`;
  if (summaryDueDate) summaryDueDate.textContent = formatDate(dueDate);
  if (summaryProperty) summaryProperty.textContent = currentUserData.property_name || "N/A";
  if (summaryApartment) summaryApartment.textContent = currentUserData.apartment_number || "N/A";
  
  if (warningContainer) {
    if (periodToPay.status === 'failed') {
      warningContainer.innerHTML = `
        <div class="warning-message" style="background: #f8d7da; border-left: 3px solid #dc3545; padding: 10px; margin-bottom: 15px; border-radius: 4px;">
          <i class="fas fa-exclamation-triangle"></i> 
          <strong>Failed Payment!</strong> Your previous payment for this period failed. Please retry.
        </div>
      `;
      warningContainer.style.display = "block";
    } else if (isOverdue) {
      warningContainer.innerHTML = `
        <div class="warning-message" style="background: #f8d7da; border-left: 3px solid #dc3545; padding: 10px; margin-bottom: 15px; border-radius: 4px;">
          <i class="fas fa-exclamation-triangle"></i> 
          <strong>Overdue Payment!</strong> This payment was due on ${formatDate(dueDate)}. Please make payment as soon as possible.
        </div>
      `;
      warningContainer.style.display = "block";
    } else {
      warningContainer.innerHTML = "";
      warningContainer.style.display = "none";
    }
  }
  
  currentPaymentData = {
    period_number: periodToPay.period_number,
    period: periodToPay.period,
    period_start: periodToPay.start_date,
    period_end: periodToPay.end_date,
    amount: periodToPay.amount_due,
    due_date: dueDate,
    formatted_due_date: formatDate(dueDate),
    property_name: currentUserData.property_name,
    apartment_number: currentUserData.apartment_number,
    payment_frequency: paymentFrequency,
    is_overdue: isOverdue,
    is_failed: periodToPay.status === 'failed'
  };
  
  console.log("Current payment data set:", currentPaymentData);
}

function calculateDueDate(periodEndDate, paymentFrequency) {
  const gracePeriods = {
    "Monthly": 7,
    "Quarterly": 14,
    "Semi-Annually": 30,
    "Annually": 90
  };
  
  const daysToAdd = gracePeriods[paymentFrequency] || 7;
  const dueDate = new Date(periodEndDate);
  dueDate.setDate(dueDate.getDate() + daysToAdd);
  return dueDate.toISOString().split('T')[0];
}

function formatPeriodForDisplay(startDate, endDate, frequency) {
  const start = new Date(startDate);
  
  switch(frequency) {
    case "Monthly":
      return start.toLocaleDateString("en-US", { month: "long", year: "numeric" });
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

function formatDateRange(startDate, endDate) {
  const start = new Date(startDate);
  const end = new Date(endDate);
  return `${start.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" })} - ${end.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" })}`;
}

async function openRentPaymentModal() {
  if (window.dashboardData?.has_pending_payment) {
    if (window.showToast) {
      window.showToast("You have a pending payment waiting for verification", "warning");
    }
    return;
  }
  
  if (!currentUserData || !window.dashboardData) {
    await fetchPaymentUserData();
    await fetchDashboardData();
  }
  
  populatePaymentSummary();

  document.querySelectorAll('input[name="paymentMethodRadio"]').forEach((radio) => {
    radio.checked = false;
  });
  document.getElementById("paymentMethod").value = "";
  document.querySelectorAll(".payment-details-section").forEach((section) => {
    section.style.display = "none";
  });

  const fields = [
    "bankReference", "cardNumber", "cardExpiry", "cardCvv", 
    "cardHolderName", "chequeNumber", "chequeBank", "chequeDate", "paymentNotes"
  ];
  fields.forEach((field) => {
    const el = document.getElementById(field);
    if (el) el.value = "";
  });

  openModal("paymentModal");
}

function setupPaymentMethodListeners() {
  document.querySelectorAll('input[name="paymentMethodRadio"]').forEach((radio) => {
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
    if (window.showToast) window.showToast("Please select a payment method", "error");
    return;
  }

  if (!currentPaymentData) {
    if (window.showToast) window.showToast("Payment data not available", "error");
    return;
  }

  let referenceNumber = null;

  if (paymentMethod === "bank_transfer") {
    referenceNumber = document.getElementById("bankReference").value;
    if (!referenceNumber) {
      if (window.showToast) window.showToast("Please enter the bank transaction reference", "error");
      return;
    }
  } else if (paymentMethod === "card") {
    const cardNumber = document.getElementById("cardNumber").value.replace(/\s/g, "");
    const cardExpiry = document.getElementById("cardExpiry").value;
    const cardCvv = document.getElementById("cardCvv").value;
    const cardHolderName = document.getElementById("cardHolderName").value;

    if (!cardNumber || cardNumber.length < 16) {
      if (window.showToast) window.showToast("Please enter a valid card number", "error");
      return;
    }
    if (!cardExpiry || !cardExpiry.match(/^\d{2}\/\d{2}$/)) {
      if (window.showToast) window.showToast("Please enter a valid expiry date (MM/YY)", "error");
      return;
    }
    if (!cardCvv || cardCvv.length < 3) {
      if (window.showToast) window.showToast("Please enter a valid CVV", "error");
      return;
    }
    if (!cardHolderName) {
      if (window.showToast) window.showToast("Please enter card holder name", "error");
      return;
    }
    referenceNumber = `CARD-${Date.now()}-${cardNumber.slice(-4)}`;
  } else if (paymentMethod === "cheque") {
    const chequeNumber = document.getElementById("chequeNumber").value;
    const chequeBank = document.getElementById("chequeBank").value;
    const chequeDate = document.getElementById("chequeDate").value;

    if (!chequeNumber) {
      if (window.showToast) window.showToast("Please enter cheque number", "error");
      return;
    }
    if (!chequeBank) {
      if (window.showToast) window.showToast("Please enter bank name", "error");
      return;
    }
    if (!chequeDate) {
      if (window.showToast) window.showToast("Please select cheque date", "error");
      return;
    }
    referenceNumber = `CHQ-${chequeNumber}`;
  } else if (paymentMethod === "cash") {
    referenceNumber = `CASH-${Date.now()}`;
  }

  closeModal("paymentModal");
  openModal("processingModal");

  const processingMsg = document.getElementById("processingMessage");
  if (processingMsg) {
    processingMsg.innerHTML = "Processing your payment...<br>Please do not close this window.";
  }

  try {
    if (paymentMethod === "card") {
      await simulateCardPayment();
    }

    const paymentData = {
      payment_method: paymentMethod,
      reference_number: referenceNumber,
      notes: `${paymentNotes}\nPayment period: ${currentPaymentData.period}\nPeriod #${currentPaymentData.period_number}\nPeriod start: ${currentPaymentData.period_start}\nPeriod end: ${currentPaymentData.period_end}`
    };

    console.log("Sending payment data:", paymentData);

    const response = await fetch("../backend/payment/initiate_rent_payment.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(paymentData),
    });

    const data = await response.json();

    if (!data.success) {
      throw new Error(data.message || "Failed to process payment");
    }

    const paymentResult = data.data;

    if (processingMsg) {
      processingMsg.innerHTML = "Payment initiated!<br>Waiting for verification...";
    }

    await new Promise((resolve) => setTimeout(resolve, 1500));

    closeModal("processingModal");
    
    showPaymentPendingModal(paymentResult);

    await Promise.all([fetchDashboardData(), fetchPaymentHistory()]);
    
  } catch (error) {
    console.error("Payment error:", error);
    closeModal("processingModal");
    if (window.showToast) {
      window.showToast(error.message, "error");
    }
  }
}

function showPaymentPendingModal(paymentData) {
  const modalHtml = `
    <div class="modal active" id="paymentSuccessModal">
      <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
          <h3>Payment Initiated!</h3>
          <button class="modal-close" onclick="closeModal('paymentSuccessModal')">&times;</button>
        </div>
        <div class="modal-body">
          <div style="text-align: center; margin-bottom: 20px;">
            <i class="fas fa-hourglass-half" style="font-size: 64px; color: #f59e0b;"></i>
            <p style="margin-top: 10px;">Your payment has been submitted for verification.</p>
          </div>
          
          <div class="payment-details">
            <div class="detail-row">
              <span class="detail-label">Period #:</span>
              <span class="detail-value">${paymentData.period_number}</span>
            </div>
            <div class="detail-row">
              <span class="detail-label">Amount:</span>
              <span class="detail-value">₦${formatNumber(paymentData.amount)}</span>
            </div>
            <div class="detail-row">
              <span class="detail-label">Reference:</span>
              <span class="detail-value">${escapeHtml(paymentData.reference_number)}</span>
            </div>
            <div class="detail-row">
              <span class="detail-label">Status:</span>
              <span class="detail-value">Pending Verification</span>
            </div>
          </div>
          <div class="info-message" style="margin-top: 15px; padding: 10px; background: #d1ecf1; border-radius: 6px;">
            <i class="fas fa-info-circle"></i> 
            You will receive a notification once your payment is verified by an admin.
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn-primary" onclick="closeModal('paymentSuccessModal')">Close</button>
        </div>
      </div>
    </div>
  `;

  const existingModal = document.getElementById("paymentSuccessModal");
  if (existingModal) existingModal.remove();
  document.body.insertAdjacentHTML("beforeend", modalHtml);
}

function showPaymentSuccessModal(paymentData) {
  const formatDisplayDate = (dateString) => {
    if (!dateString) return "N/A";
    return new Date(dateString).toLocaleDateString("en-US", {
      year: "numeric",
      month: "long",
      day: "numeric"
    });
  };

  const periodDisplay = paymentData.payment_period || 
    formatPeriodForDisplay(paymentData.period_start_date, paymentData.period_end_date, currentUserData?.payment_frequency);

  const modalHtml = `
    <div class="modal active" id="paymentSuccessModal">
      <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
          <h3>Payment Successful!</h3>
          <button class="modal-close" onclick="closeModal('paymentSuccessModal')">&times;</button>
        </div>
        <div class="modal-body">
          <div style="text-align: center; margin-bottom: 20px;">
            <i class="fas fa-check-circle" style="font-size: 64px; color: #10b981;"></i>
            <p style="margin-top: 10px; color: #666;">Your payment has been processed successfully</p>
          </div>
          
          <div class="payment-details">
            <div class="detail-row">
              <span class="detail-label">Payment Period:</span>
              <span class="detail-value">${escapeHtml(periodDisplay)}</span>
            </div>
            <div class="detail-row">
              <span class="detail-label">Period Range:</span>
              <span class="detail-value">${formatDateRange(paymentData.period_start_date, paymentData.period_end_date)}</span>
            </div>
            <div class="detail-row">
              <span class="detail-label">Amount Paid:</span>
              <span class="detail-value">₦${formatNumber(paymentData.amount)}</span>
            </div>
            <div class="detail-row">
              <span class="detail-label">Payment Date:</span>
              <span class="detail-value">${formatDisplayDate(paymentData.payment_date)}</span>
            </div>
            <div class="detail-row">
              <span class="detail-label">Due Date:</span>
              <span class="detail-value">${paymentData.formatted_due_date || formatDisplayDate(paymentData.due_date)}</span>
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
              <span class="detail-label">Payment Method:</span>
              <span class="detail-value">${formatPaymentMethod(paymentData.payment_method)}</span>
            </div>
            ${paymentData.remaining_balance !== undefined ? `
            <div class="detail-row">
              <span class="detail-label">Remaining Balance:</span>
              <span class="detail-value">₦${formatNumber(paymentData.remaining_balance)}</span>
            </div>
            ` : ''}
            ${paymentData.is_fully_paid ? `
            <div class="detail-row" style="background: #d4edda; padding: 10px; border-radius: 6px; margin-top: 5px;">
              <span class="detail-label" style="color: #155724;">🎉 Congratulations!</span>
              <span class="detail-value" style="color: #155724;">Your lease is now fully paid!</span>
            </div>
            ` : ''}
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
      if (processingMsg) {
        processingMsg.innerHTML = `Processing card payment... ${progress}%`;
      }
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

// function downloadReceipt(receiptNumber) {
//   if (!receiptNumber) {
//     if (window.showToast) window.showToast("Receipt number not found", "error");
//     return;
//   }

//   try {
//     const encodedRef = encodeURIComponent(receiptNumber);
//     window.open(
//       `../backend/payment/download_receipt.php?receipt_number=${encodedRef}`,
//       "_blank",
//     );
//   } catch (error) {
//     console.error("Error downloading receipt:", error);
//     if (window.showToast) {
//       window.showToast("Failed to download receipt", "error");
//     }
//   }
// }

function downloadReceipt(paymentId, receiptNumber, paymentType) {
    console.log("Download receipt called - paymentId:", paymentId, "receiptNumber:", receiptNumber, "paymentType:", paymentType);
    
    let url = '../backend/payment/download_receipt.php?';
    
    // For rent period payments (payment_type is 'rent' and paymentId is the tracker_id)
    if (paymentType === 'rent' && paymentId && parseInt(paymentId) > 0) {
        url += `tracker_id=${paymentId}`;
        console.log("Using tracker_id URL:", url);
    } 
    // For security deposit, use receipt_number
    else if (receiptNumber && receiptNumber !== 'null' && receiptNumber !== 'undefined') {
        url += `receipt_number=${encodeURIComponent(receiptNumber)}`;
        console.log("Using receipt_number URL:", url);
    } 
    else {
        console.error("No valid identifier for receipt download");
        if (window.showToast) window.showToast("Receipt information not found", "error");
        return;
    }
    
    window.open(url, '_blank');
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
