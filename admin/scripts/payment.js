// Global variables
let currentPage = 1;
let currentLimit = 10;
let currentFilters = {};
let quickFeeLoadToken = 0;

// Admin Rent Payment Global Variables
let selectedAdminTenant = null;
let selectedAdminPeriod = null;
let adminCurrentStep = 1;

// Initialize
document.addEventListener("DOMContentLoaded", function () {
  setTodayDate();
  loadFilters();
  loadPayments();
  loadStatistics();
  loadQuickTenants();
  loadTenantsForModal();
  loadApartmentsForModal();

  // Quick payment tenant selection controls fee choices only.
  const tenantSelect = document.getElementById("quickTenant");
  if (tenantSelect) {
    tenantSelect.addEventListener("change", function () {
      const selectedTenantCode = this.value;
      resetQuickPaymentFeeFields();
      loadTenantFees(selectedTenantCode);
    });
  }

  // 3. Set up fee selection listener
  const feeSelect = document.getElementById("quickFeeType");
  if (feeSelect) {
    feeSelect.addEventListener("change", onFeeTypeChange);
  }

  const methodSelect = document.getElementById("quickMethod");
  if (methodSelect) {
    methodSelect.addEventListener("change", updateQuickTransactionReference);
  }

  resetQuickPaymentFeeFields();
});

// ==================== ADMIN INITIATED RENT PAYMENT ====================

// Open modal and load tenants
async function openInitiatePaymentModal() {
  await loadAdminTenants();
  openModal("initiateRentPaymentModal");
}

function openModal(modalId) {
  const modal = document.getElementById(modalId);
  if (modal) modal.style.display = "flex";
}

function closeModal(modalId) {
  const modal = document.getElementById(modalId);
  if (modal) modal.style.display = "none";
}

// Load tenants for admin dropdown
async function loadAdminTenants() {
  try {
    const response = await fetch(
      "../backend/tenants/get_tenants.php?status=1&limit=200",
    );
    const data = await response.json();

    if (data.success) {
      const tenants = data.data || data.tenants || [];
      const select = document.getElementById("adminTenantSelect");
      if (select) {
        select.innerHTML =
          '<option value="">-- Select Tenant --</option>' +
          tenants
            .map(
              (t) =>
                `<option value="${t.tenant_code}">${t.firstname} ${t.lastname} (${t.tenant_code})</option>`,
            )
            .join("");

        // Add change event listener
        select.onchange = () => onAdminTenantSelect(select.value);
      }
    }
  } catch (error) {
    console.error("Error loading tenants:", error);
    showAlert("Failed to load tenants", "error");
  }
}

// Handle tenant selection
async function onAdminTenantSelect(tenantCode) {
  if (!tenantCode) {
    document.getElementById("adminPaymentSummary").style.display = "none";
    return;
  }

  showAlert("Loading tenant payment information...", "info");

  try {
    const response = await fetch(
      `../backend/payment/get_tenant_period.php?tenant_code=${tenantCode}`,
    );
    const data = await response.json();

    if (data.success && data.data) {
      selectedAdminTenant = data.message.tenant;
      selectedAdminPeriod = data.message.current_period;

      // Update summary
      document.getElementById("adminSummaryTenant").textContent =
        `${selectedAdminTenant.firstname} ${selectedAdminTenant.lastname}`;
      document.getElementById("adminSummaryProperty").textContent =
        selectedAdminTenant.property_name || "N/A";
      document.getElementById("adminSummaryApartment").textContent =
        selectedAdminTenant.apartment_number || "N/A";
      document.getElementById("adminSummaryStatus").textContent =
        ` ${selectedAdminPeriod.status}`.toUpperCase();
      document.getElementById("adminSummaryPeriod").textContent =
        `Period #${selectedAdminPeriod.period_number}`;
      document.getElementById("adminSummaryDate").textContent =
        `${formatDateRange(selectedAdminPeriod.start_date, selectedAdminPeriod.end_date)}`;
      document.getElementById("adminSummaryAmount").textContent =
        `₦${parseFloat(selectedAdminPeriod.amount_paid || selectedAdminTenant.payment_amount_per_period).toLocaleString()}`;
      document.getElementById("adminSummaryDueDate").textContent = formatDate(
        selectedAdminPeriod.end_date,
      );

      // Show summary and move to step 2
      document.getElementById("adminPaymentSummary").style.display = "block";

      // Reset and show step 2
      resetAdminToStep(2);
    } else {
      showAlert(data.message || "No available payment period found", "error");
      document.getElementById("adminPaymentSummary").style.display = "none";
    }
  } catch (error) {
    console.error("Error fetching tenant period:", error);
    showAlert("Error fetching tenant payment information", "error");
  }
}

// Create full page loader overlay
function createFullPageLoader(message = "Processing...") {
  // Check if loader already exists
  let loader = document.getElementById("fullPageLoader");
  if (loader) {
    loader.remove();
  }

  loader = document.createElement("div");
  loader.id = "fullPageLoader";
  loader.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.7);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 99999;
        backdrop-filter: blur(4px);
    `;

  loader.innerHTML = `
        <div style="background: white; padding: 30px 40px; border-radius: 12px; text-align: center; min-width: 250px; box-shadow: 0 10px 40px rgba(0,0,0,0.2);">
            <div style="margin-bottom: 20px;">
                <i class="fas fa-spinner fa-spin" style="font-size: 40px; color: #1e3c72;"></i>
            </div>
            <p style="margin: 0; font-size: 16px; color: #333;">${message}</p>
            <p style="margin: 10px 0 0 0; font-size: 12px; color: #666;">Please do not close this window</p>
        </div>
    `;

  document.body.appendChild(loader);
  return loader;
}

// Remove loader
function removeFullPageLoader() {
  const loader = document.getElementById("fullPageLoader");
  if (loader) {
    loader.remove();
  }
}

// Enhanced sendPaymentOtp with loader and timeout
async function sendPaymentOtp() {
  if (!selectedAdminTenant || !selectedAdminPeriod) {
    showAlert("Please select a tenant first", "error");
    return;
  }

  const sendBtn = document.getElementById("sendOtpBtn");
  const originalText = sendBtn.innerHTML;

  // Show full page loader
  const loader = createFullPageLoader("Sending OTP to tenant's email...");

  // Disable button and show loading state
  sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
  sendBtn.disabled = true;

  // Create abort controller for timeout
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), 60000); // 60 seconds timeout
  const OTPNotifier = document.getElementById("OTPNotifier");

  try {
    const response = await fetch("../backend/utilities/send_otp.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        email: selectedAdminTenant.email,
        user_type: "tenant",
        title: "Admin: Rent Payment Authorization OTP",
      }),
      signal: controller.signal,
    });

    clearTimeout(timeoutId);
    const data = await response.json();

    // Remove loader
    removeFullPageLoader();

    if (data.success || (data.code = 500)) {
      showAlert("✓ OTP sent to tenant's email successfully!", "success");
      OTPNotifier.textContent =
        "Enter the OTP sent to Customer's Email in the Input below";

      // Show OTP input section with a small animation
      const otpSection = document.getElementById("otpSection");
      otpSection.style.display = "block";
      otpSection.style.opacity = "0";
      otpSection.style.transform = "translateY(-10px)";
      otpSection.style.transition = "all 0.3s ease";

      setTimeout(() => {
        otpSection.style.opacity = "1";
        otpSection.style.transform = "translateY(0)";
      }, 50);

      // Hide send button
      sendBtn.style.display = "none";

      // Focus on OTP input
      document.getElementById("otpCode").focus();

      // Start OTP resend timer (2 minutes)
      startOtpResendTimer(120);
    } else {
      throw new Error(data.message);
      OTPNotifier.textContent = "Unable to Generate OTP. Please Try Again";
    }
  } catch (error) {
    clearTimeout(timeoutId);
    removeFullPageLoader();

    console.error("Error sending OTP:", error);

    let errorMessage = error.message;
    if (error.name === "AbortError") {
      errorMessage =
        "Request timeout. Please check your internet connection and try again.";
    }

    showAlert(errorMessage, "error");
    sendBtn.innerHTML = originalText;
    sendBtn.disabled = false;
  }
}

// OTP Resend Timer
let otpTimerInterval = null;

function startOtpResendTimer(durationSeconds) {
  const sendBtn = document.getElementById("sendOtpBtn");
  const originalText = sendBtn.innerHTML;

  // Clear any existing timer
  if (otpTimerInterval) {
    clearInterval(otpTimerInterval);
  }

  let timeLeft = durationSeconds;

  otpTimerInterval = setInterval(() => {
    const minutes = Math.floor(timeLeft / 60);
    const seconds = timeLeft % 60;

    sendBtn.innerHTML = `<i class="fas fa-clock"></i> Resend in ${minutes}:${seconds.toString().padStart(2, "0")}`;
    sendBtn.disabled = true;

    if (timeLeft <= 0) {
      clearInterval(otpTimerInterval);
      otpTimerInterval = null;
      sendBtn.innerHTML = '<i class="fas fa-envelope"></i> Resend OTP';
      sendBtn.disabled = false;
      sendBtn.style.display = "block";

      // Show that resend is available
      showAlert("You can now request a new OTP if needed", "info");
    }

    timeLeft--;
  }, 1000);
}

// Cancel OTP timer (call when OTP is verified or modal closes)
function cancelOtpTimer() {
  if (otpTimerInterval) {
    clearInterval(otpTimerInterval);
    otpTimerInterval = null;
  }
}

// Enhanced verifyPaymentOtp with loader
async function verifyPaymentOtp() {
  const otpCode = document.getElementById("otpCode").value.trim();
  const OTPNotifier = document.getElementById("OTPNotifier");

  if (!otpCode || otpCode.length !== 6) {
    showAlert("Please enter a valid 6-digit OTP code", "error");
    return;
  }

  const verifyBtn = document.getElementById("verifyOtpBtn");
  const originalText = verifyBtn.innerHTML;

  // Show loader
  const loader = createFullPageLoader("Verifying OTP...");

  verifyBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';
  verifyBtn.disabled = true;

  // Create abort controller for timeout
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), 30000); // 30 seconds timeout

  try {
    const response = await fetch("../backend/payment/verify_payment_otp.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        tenant_code: selectedAdminTenant.tenant_code,
        otp_code: otpCode,
      }),
      signal: controller.signal,
    });

    clearTimeout(timeoutId);
    const data = await response.json();

    removeFullPageLoader();

    if (data.success) {
      showAlert("✓ OTP verified successfully!", "success");
      OTPNotifier.textContent =
        "OTP Verified successfully. Now Complete Payment";

      // Cancel the resend timer since OTP is verified
      cancelOtpTimer();

      // Move to step 3 with animation
      resetAdminToStep(3);
    } else {
      OTPNotifier.textContent = "Unable to Verify OTP. Please Try Again Later";
      throw new Error(data.message);
    }
  } catch (error) {
    clearTimeout(timeoutId);
    removeFullPageLoader();

    console.error("Error verifying OTP:", error);

    let errorMessage = error.message;
    if (error.name === "AbortError") {
      errorMessage = "Verification timeout. Please try again.";
    }

    showAlert(errorMessage, "error");
    verifyBtn.innerHTML = originalText;
    verifyBtn.disabled = false;

    // Clear OTP input on failure
    document.getElementById("otpCode").value = "";
    document.getElementById("otpCode").focus();
  }
}

// Process the payment
async function processAdminRentPayment() {
  const notes = document.getElementById("adminPaymentNotes").value;
  const OTPNotifier = document.getElementById("OTPNotifier");

  // Close payment modal and show processing
  closeModal("initiateRentPaymentModal");
  openModal("adminProcessingModal");

  const processingMsg = document.getElementById("adminProcessingMessage");
  processingMsg.innerHTML = "Initiating payment... Please wait.";

  try {
    const response = await fetch(
      "../backend/payment/admin_initiate_rent_payment.php",
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          tenant_code: selectedAdminTenant.tenant_code,
          period_number: selectedAdminPeriod.period_number,
          notes: notes,
        }),
      },
    );

    const data = await response.json();

    if (data.success) {
      processingMsg.innerHTML =
        "Payment initiated successfully!<br>Waiting for verification...";
      OTPNotifier.textContent =
        "Payment Initiated Successfully.Contact Admin for Verification";

      setTimeout(() => {
        closeModal("adminProcessingModal");
        showAlert(
          `Payment initiated successfully! Receipt: ${data.message.receipt_number}`,
          "success",
        );

        // Reset and close
        resetAdminPaymentState();
        closeInitiatePaymentModal();

        // Refresh data
        loadPayments();
        loadStatistics();
      }, 2000);
    } else {
      throw new Error(data.message);
      OTPNotifier.textContent =
        "Unable to Complete Rent Payment. Please Try Again";
    }
  } catch (error) {
    console.error("Error processing payment:", error);
    closeModal("adminProcessingModal");
    showAlert(error.message, "error");
    resetAdminPaymentState();
  }
}

// Helper function to reset to specific step
// Update the resetAdminToStep function to handle timer
function resetAdminToStep(step) {
  adminCurrentStep = step;

  // Hide all step containers
  document.getElementById("adminStep2").style.display = "none";
  document.getElementById("adminStep3").style.display = "none";
  document.getElementById("processPaymentBtn").style.display = "none";

  if (step === 2) {
    // Reset OTP section (keep timer if active, otherwise reset)
    const sendBtn = document.getElementById("sendOtpBtn");

    // Only reset if no active timer
    if (!otpTimerInterval) {
      sendBtn.style.display = "block";
      sendBtn.disabled = false;
      sendBtn.innerHTML = '<i class="fas fa-envelope"></i> Send OTP to Tenant';
    }

    document.getElementById("otpSection").style.display = "none";
    document.getElementById("otpCode").value = "";
    document.getElementById("adminStep2").style.display = "block";
  } else if (step === 3) {
    // Cancel timer when moving to step 3 (OTP verified)
    cancelOtpTimer();
    document.getElementById("adminStep3").style.display = "block";
    document.getElementById("processPaymentBtn").style.display = "block";
  }
}

// Reset admin state (updated to cancel timer)
function resetAdminPaymentState() {
  selectedAdminTenant = null;
  selectedAdminPeriod = null;
  adminCurrentStep = 1;

  // Cancel OTP timer
  cancelOtpTimer();

  const tenantSelect = document.getElementById("adminTenantSelect");
  if (tenantSelect) tenantSelect.value = "";

  document.getElementById("adminPaymentSummary").style.display = "none";
  document.getElementById("adminStep2").style.display = "none";
  document.getElementById("adminStep3").style.display = "none";
  document.getElementById("processPaymentBtn").style.display = "none";

  const sendBtn = document.getElementById("sendOtpBtn");
  sendBtn.style.display = "block";
  sendBtn.disabled = false;
  sendBtn.innerHTML = '<i class="fas fa-envelope"></i> Send OTP to Tenant';
  document.getElementById("otpSection").style.display = "none";
  document.getElementById("otpCode").value = "";
  document.getElementById("adminPaymentNotes").value = "";
}

// Close modal (updated to cancel timer)
function closeInitiatePaymentModal() {
  resetAdminPaymentState();
  closeModal("initiateRentPaymentModal");
}

// Helper function for date range
function formatDateRange(startDate, endDate) {
  if (!startDate || !endDate) return "N/A";
  return `${formatDate(startDate)} - ${formatDate(endDate)}`;
}

// ==================== END ADMIN INITIATED RENT PAYMENT ====================

// Set today's date in date fields
function setTodayDate() {
  const today = new Date().toISOString().split("T")[0];
  const paymentDate = document.getElementById("payment_date");
  const dueDate = document.getElementById("due_date");
  if (paymentDate) paymentDate.value = today;
  if (dueDate) dueDate.value = today;
}

// Load filter dropdowns
async function loadFilters() {
  try {
    const response = await fetch(
      `../backend/payment/payment_manager.php?action=fetch&page=1&limit=1`,
    );
    const data = await response.json();

    if (data.success) {
      // Build filter UI dynamically
      buildFilterUI();
    }
  } catch (error) {
    console.error("Error loading filters:", error);
  }
}

function buildFilterUI() {
  const container = document.getElementById("filtersContainer");
  if (!container) return;

  container.innerHTML = `
        <div class="form-group">
            <label>Search</label>
            <input type="text" class="form-control" id="filterSearch" 
                   placeholder="Search tenant, receipt #...">
        </div>
        <div class="form-group">
            <label>Status</label>
            <select class="form-control" id="filterStatus">
                <option value="">All Statuses</option>
                <option value="pending">Pending</option>
                <option value="completed">Completed</option>
                <option value="failed">Failed</option>
                <option value="refunded">Refunded</option>
                <option value="is_deleted">Deleted</option>
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
  const limitSelect = document.getElementById("limitSelect");
  if (limitSelect) {
    currentLimit = parseInt(limitSelect.value);
  }

  // Build query string
  let query = `action=fetch&page=${currentPage}&limit=${currentLimit}`;

  // Add filters
  if (currentFilters.search)
    query += `&search=${encodeURIComponent(currentFilters.search)}`;
  if (currentFilters.tenant_code)
    query += `&tenant_code=${currentFilters.tenant_code}`;
  if (currentFilters.property_code)
    query += `&property_code=${currentFilters.property_code}`;
  if (currentFilters.apartment_code)
    query += `&apartment_code=${currentFilters.apartment_code}`;
  if (currentFilters.payment_status)
    query += `&payment_status=${currentFilters.payment_status}`;
  if (currentFilters.payment_method)
    query += `&payment_method=${currentFilters.payment_method}`;
  if (currentFilters.date_from)
    query += `&date_from=${currentFilters.date_from}`;
  if (currentFilters.date_to) query += `&date_to=${currentFilters.date_to}`;

  try {
    const response = await fetch(
      `../backend/payment/payment_manager.php?${query}`,
    );
    const data = await response.json();

    if (data.success) {
      renderPaymentsTable(data.payments || []);
      renderPagination(data.pagination);
    } else {
      showAlert(data.message || "Error loading payments", "error");
    }
  } catch (error) {
    console.error("Error loading payments:", error);
    showAlert("Error loading payments", "error");
  }
}

function renderPaymentsTable(payments) {
  const tbody = document.getElementById("paymentsTableBody");
  if (!tbody) return;

  tbody.innerHTML = "";

  if (!payments || payments.length === 0) {
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

    // Check if payment is deleted
    const isDeleted = payment.is_deleted == 1;

    // Add class to visually distinguish deleted payments
    if (isDeleted) {
      row.className = "deleted-payment-row";
    }

    // Determine payment status display
    let statusDisplay = (payment.payment_status || "pending").toUpperCase();
    let statusClass = `status-${payment.payment_status}`;

    // If deleted, show DELETED status
    if (isDeleted) {
      statusDisplay = "DELETED";
      statusClass = "status-deleted";
    }

    // Build action buttons
    let actionButtons = "";

    if (isDeleted) {
      // Show restore button for deleted payments
      actionButtons = `
                <button class="action-btn" onclick="viewPayment(${payment.id})" title="View Details">
                    <i class="fas fa-eye"></i>
                </button>
                <button class="action-btn" onclick="restorePayment(${payment.id})" title="Restore Payment" style="color: var(--success);">
                    <i class="fas fa-undo"></i>
                </button>
            `;
    } else {
      // Show normal action buttons for active payments
      actionButtons = `
                <button class="action-btn" onclick="viewPayment(${payment.id})" title="View Details">
                    <i class="fas fa-eye"></i>
                </button>
                <button class="action-btn" onclick="editPayment(${payment.id})" title="Edit">
                    <i class="fas fa-edit"></i>
                </button>
                <button class="action-btn" onclick="deletePayment(${payment.id})" title="Delete" style="color: var(--danger);">
                    <i class="fas fa-trash"></i>
                </button>
            `;
    }

    // Build tenant info with deleted indicator
    let tenantName = payment.tenant_name || "N/A";
    if (isDeleted) {
      tenantName += ' <span class="deleted-badge">(Deleted)</span>';
    }

    row.innerHTML = `
            <td>
                <strong>${payment.receipt_number || payment.id}</strong>
                ${isDeleted ? '<br><small class="deleted-label">Deleted</small>' : ""}
            </td>
            <td>
                <div><strong>${tenantName}</strong></div>
                <small>${payment.tenant_email || ""}</small>
            </td>
            <td>
                <div>${payment.property_name || "N/A"}</div>
                <small>Apartment: ${payment.apartment_number || "N/A"}</small>
            </td>
            <td>
                <strong>₦${payment.amount_formatted || formatNumber(payment.amount)}</strong>
                ${isDeleted ? '<br><small class="deleted-label">Removed from totals</small>' : ""}
            </td>
            <td>${payment.payment_date_formatted || formatDate(payment.payment_date)}</td>
            <td>${formatPaymentMethod(payment.payment_method)}</td>
            <td>
                <span class="status-badge ${statusClass}">
                    ${statusDisplay}
                </span>
            </td>
            <td>
                <div class="action-btns">
                    ${actionButtons}
                </div>
            </td>
        `;
    tbody.appendChild(row);
  });
}

function renderPagination(pagination) {
  const container = document.getElementById("paginationContainer");
  if (!container) return;

  container.innerHTML = "";

  if (!pagination || pagination.total_pages <= 1) return;

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
  const endPage = Math.min(pagination.total_pages, currentPage + 2);

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
  nextBtn.disabled = currentPage === pagination.total_pages;
  nextBtn.onclick = () => {
    if (currentPage < pagination.total_pages) {
      currentPage++;
      loadPayments();
    }
  };
  container.appendChild(nextBtn);
}

// Filter functions
function applyFilters() {
  const searchInput = document.getElementById("filterSearch");
  const statusSelect = document.getElementById("filterStatus");
  const dateFrom = document.getElementById("filterDateFrom");
  const dateTo = document.getElementById("filterDateTo");

  currentFilters = {
    search: searchInput ? searchInput.value : "",
    payment_status: statusSelect ? statusSelect.value : "",
    date_from: dateFrom ? dateFrom.value : "",
    date_to: dateTo ? dateTo.value : "",
  };

  currentPage = 1;
  loadPayments();
}

function resetFilters() {
  currentFilters = {};

  // Reset form fields
  const filterIds = [
    "filterSearch",
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
    const response = await fetch(
      "../backend/payment/payment_manager.php?action=get_statistics",
    );
    const data = await response.json();

    if (data.success && data.statistics) {
      renderStatistics(data.statistics);
    }
  } catch (error) {
    console.error("Error loading statistics:", error);
  }
}

function renderStatistics(stats) {
  const container = document.getElementById("statsContainer");
  if (!container) return;

  const summary = stats.summary || {};

  const statCards = [
    {
      icon: "fas fa-money-bill-wave",
      color: "#10b981",
      title: "Total Revenue",
      value: "₦" + formatNumber(summary.total_revenue || 0),
    },
    {
      icon: "fas fa-receipt",
      color: "#3b82f6",
      title: "Total Payments",
      value: summary.total_payments || 0,
    },
    {
      icon: "fas fa-check-circle",
      color: "#10b981",
      title: "Completed",
      value: summary.completed_payments || 0,
    },
    {
      icon: "fas fa-clock",
      color: "#f59e0b",
      title: "Pending",
      value: summary.pending_payments || 0,
    },
    {
      icon: "fas fa-warning",
      color: "rgb(255, 85, 85)",
      title: "Failed",
      value: summary.failed_payments || 0,
    },
    {
      icon: "fas fa-cancel",
      color: "rgb(255, 201, 85)",
      title: "Cancelled",
      value: summary.cancelled_payments || 0,
    },
    {
      icon: "fas fa-undo",
      color: "rgb(255, 85, 232)",
      title: "Refunded",
      value: summary.refunded_payments || 0,
    },
    {
      icon: "fas fa-trash",
      color: "rgb(255, 91, 85)",
      title: "Deleted",
      value: summary.deleted_payments || 0,
    },
    {
      icon: "fas fa-chart-line",
      color: "#8b5cf6",
      title: "Average Payment",
      value: "₦" + formatNumber(summary.average_payment || 0),
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
}

// Modal functions
function openRecordPaymentModal() {
  const modal = document.getElementById("recordPaymentModal");
  if (modal) modal.style.display = "flex";
}

function closeRecordPaymentModal() {
  const modal = document.getElementById("recordPaymentModal");
  if (modal) modal.style.display = "none";
  const form = document.getElementById("paymentForm");
  if (form) form.reset();
  setTodayDate();
}

function openViewPaymentModal() {
  const modal = document.getElementById("viewPaymentModal");
  if (modal) modal.style.display = "flex";
}

function closeViewPaymentModal() {
  const modal = document.getElementById("viewPaymentModal");
  if (modal) modal.style.display = "none";
}

function openQuickFeePaymentModal() {
  const modal = document.getElementById("quickFeePaymentModal");
  if (modal) modal.style.display = "flex";
}

function closeQuickFeePaymentModal() {
  const modal = document.getElementById("quickFeePaymentModal");
  if (modal) modal.style.display = "none";
}

function showConfirmModal({
  title = "Confirm Action",
  message = "Are you sure you want to continue?",
  confirmText = "Confirm",
  cancelText = "Cancel",
  variant = "warning",
}) {
  return new Promise((resolve) => {
    let modal = document.getElementById("customConfirmModal");

    if (!modal) {
      modal = document.createElement("div");
      modal.id = "customConfirmModal";
      modal.className = "custom-confirm-overlay";
      modal.innerHTML = `
                <div class="custom-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="customConfirmTitle">
                    <div class="custom-confirm-icon">
                        <i class="fas fa-circle-exclamation"></i>
                    </div>
                    <div class="custom-confirm-content">
                        <h3 id="customConfirmTitle"></h3>
                        <p id="customConfirmMessage"></p>
                    </div>
                    <div class="custom-confirm-actions">
                        <button type="button" class="btn btn-outline custom-confirm-cancel">Cancel</button>
                        <button type="button" class="btn custom-confirm-ok">Confirm</button>
                    </div>
                </div>
            `;
      document.body.appendChild(modal);
    }

    const dialog = modal.querySelector(".custom-confirm-dialog");
    const icon = modal.querySelector(".custom-confirm-icon i");
    const titleElement = modal.querySelector("#customConfirmTitle");
    const messageElement = modal.querySelector("#customConfirmMessage");
    const confirmButton = modal.querySelector(".custom-confirm-ok");
    const cancelButton = modal.querySelector(".custom-confirm-cancel");

    const iconMap = {
      warning: "fa-circle-exclamation",
      success: "fa-circle-check",
      info: "fa-circle-info",
      danger: "fa-triangle-exclamation",
    };

    dialog.dataset.variant = variant;
    icon.className = `fas ${iconMap[variant] || iconMap.warning}`;
    titleElement.textContent = title;
    messageElement.textContent = message;
    confirmButton.textContent = confirmText;
    cancelButton.textContent = cancelText;

    const handleKeydown = (event) => {
      if (event.key === "Escape") {
        cleanup(false);
      }
    };

    const cleanup = (result) => {
      modal.classList.remove("active");
      confirmButton.onclick = null;
      cancelButton.onclick = null;
      modal.onclick = null;
      document.removeEventListener("keydown", handleKeydown);
      resolve(result);
    };

    confirmButton.onclick = () => cleanup(true);
    cancelButton.onclick = () => cleanup(false);
    modal.onclick = (event) => {
      if (event.target === modal) {
        cleanup(false);
      }
    };

    modal.classList.add("active");
    document.addEventListener("keydown", handleKeydown);
    confirmButton.focus();
  });
}

// Load tenants for quick payment
async function loadQuickTenants() {
  try {
    const response = await fetch(
      "../backend/tenants/get_tenants.php?status=1&limit=100",
    );
    const data = await response.json();

    if (data.success) {
      const select = document.getElementById("quickTenant");
      if (select) {
        const tenants = data.data || data.tenants || [];
        select.innerHTML =
          '<option value="">Select Tenant</option>' +
          tenants
            .map(
              (t) =>
                `<option value="${t.tenant_code}">${t.firstname} ${t.lastname} (${t.tenant_code})</option>`,
            )
            .join("");
      }

      const select2 = document.getElementById("tenant_id");
      if (select2) {
        const tenants = data.data || data.tenants || [];
        select2.innerHTML =
          '<option value="">Select Tenant</option>' +
          tenants
            .map(
              (t) =>
                `<option value="${t.tenant_code}">${t.firstname} ${t.lastname} (${t.tenant_code})</option>`,
            )
            .join("");
      }
    }
  } catch (error) {
    console.error("Error loading tenants:", error);
    showAlert("Failed to load tenants", "error");
  }
}

// Load fee types for a specific tenant
async function loadTenantFees(tenantCode) {
  const feeSelect = document.getElementById("quickFeeType");
  const requestToken = ++quickFeeLoadToken;

  if (!feeSelect) return;

  if (!tenantCode) {
    resetQuickPaymentFeeFields();
    return;
  }

  try {
    // Show loading state
    feeSelect.innerHTML = '<option value="">Loading fees...</option>';
    feeSelect.disabled = true;

    // Fetch fees for this tenant with pending status
    const response = await fetch(
      `../backend/fee_management/fetch_tenant_fees.php?status=pending&search=${encodeURIComponent(tenantCode)}`,
    );
    const data = await response.json();

    if (requestToken !== quickFeeLoadToken) return;

    if (data.success && data.message.fees) {
      const fees = data.message.fees;

      if (fees.length === 0) {
        feeSelect.innerHTML =
          '<option value="">No pending fees for this tenant</option>';
        feeSelect.disabled = true;
        resetQuickPaymentFeeFields({ keepFeeOptions: true });
        return;
      }

      // Populate fee select with pending fees
      feeSelect.innerHTML =
        '<option value="">Select Fee Type</option>' +
        fees
          .map(
            (fee) => `<option value="${fee.tenant_fee_id}" 
                    data-amount="${fee.amount}" 
                    data-due-date="${fee.due_date}"
                    data-fee-name="${escapeHtml(fee.fee_name)}">
                    ${escapeHtml(fee.fee_name)} - ₦${parseFloat(fee.amount).toLocaleString()} (Due: ${formatDate(fee.due_date)})
                </option>`,
          )
          .join("");

      // Enable only fee selection. Amount/due date stay empty until a fee is chosen.
      feeSelect.disabled = false;
      resetQuickPaymentFeeFields({ keepFeeOptions: true });
    } else {
      feeSelect.innerHTML = '<option value="">Error loading fees</option>';
      feeSelect.disabled = true;
      resetQuickPaymentFeeFields({ keepFeeOptions: true });
      showAlert("Failed to load tenant fees", "error");
    }
  } catch (error) {
    if (requestToken !== quickFeeLoadToken) return;
    console.error("Error loading tenant fees:", error);
    feeSelect.innerHTML = '<option value="">Error loading fees</option>';
    feeSelect.disabled = true;
    resetQuickPaymentFeeFields({ keepFeeOptions: true });
    showAlert("Failed to load tenant fees", "error");
  }
}

// Handle fee selection change
function onFeeTypeChange() {
  const feeSelect = document.getElementById("quickFeeType");
  if (!feeSelect) return;

  const selectedOption = feeSelect.options[feeSelect.selectedIndex];

  if (feeSelect.value && selectedOption) {
    const amount = selectedOption.getAttribute("data-amount");
    const dueDate = selectedOption.getAttribute("data-due-date");

    document.getElementById("quickAmount").value = amount || "";
    document.getElementById("quickDueDate").value = dueDate || "";
    // document.getElementById("quickAmount").disabled = false;
    // document.getElementById("quickDueDate").disabled = false;

    updateQuickTransactionReference();
  } else {
    resetQuickPaymentFeeFields({ keepFeeOptions: true });
  }
}

function resetQuickPaymentFeeFields({ keepFeeOptions = false } = {}) {
  const feeSelect = document.getElementById("quickFeeType");
  const amountInput = document.getElementById("quickAmount");
  const dueDateInput = document.getElementById("quickDueDate");
  const transactionReferenceInput = document.getElementById(
    "quickTransactionReference",
  );

  if (feeSelect) {
    feeSelect.value = "";
    if (!keepFeeOptions) {
      feeSelect.innerHTML = '<option value="">Select Fee Type</option>';
      feeSelect.disabled = true;
    }
  }

  if (amountInput) {
    amountInput.value = "";
    amountInput.disabled = true;
  }

  if (dueDateInput) {
    dueDateInput.value = "";
    dueDateInput.disabled = true;
  }

  if (transactionReferenceInput) {
    transactionReferenceInput.value = "";
    transactionReferenceInput.disabled = true;
  }
}

function updateQuickTransactionReference() {
  const feeSelect = document.getElementById("quickFeeType");
  const methodSelect = document.getElementById("quickMethod");
  const transactionReferenceInput = document.getElementById(
    "quickTransactionReference",
  );

  if (!transactionReferenceInput) return;

  if (!feeSelect || !feeSelect.value || !methodSelect || !methodSelect.value) {
    transactionReferenceInput.value = "";
    transactionReferenceInput.disabled = true;
    return;
  }

  transactionReferenceInput.value = generateReferenceNumber(methodSelect.value);
//   transactionReferenceInput.disabled = false;
}

// Generate transaction reference
function generateReferenceNumber(paymentMethod) {
  const prefixes = {
    bank_transfer: "BNK",
    card: "CARD",
    cash: "CSH",
    check: "CHK",
    cheque: "CHQ",
    mobile_money: "MM",
  };
  const prefix = prefixes[paymentMethod] || "REF";

  const date = new Date();
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  const random = Math.random().toString(36).substring(2, 8).toUpperCase();
  const timestamp = Date.now().toString().slice(-6);

  return `${prefix}-${year}${month}${day}-${random}-${timestamp}`;
}

async function loadTenantsForModal() {
  try {
    const response = await fetch(
      "../backend/tenants/get_tenants.php?status=1&limit=100",
    );
    const data = await response.json();

    if (data.success) {
      const select = document.getElementById("tenant_code");
      if (select) {
        const tenants = data.data || data.tenants || [];
        select.innerHTML =
          '<option value="">Select Tenant</option>' +
          tenants
            .map(
              (t) =>
                `<option value="${t.tenant_code}">${t.firstname} ${t.lastname} (${t.email})</option>`,
            )
            .join("");
      }
    }
  } catch (error) {
    console.error("Error loading tenants for modal:", error);
  }
}

async function loadApartmentsForModal() {
  try {
    const response = await fetch(
      "../backend/apartments/fetch_apartments.php?status=1&limit=100",
    );
    const data = await response.json();

    if (data.success) {
      const select = document.getElementById("apartment_code");
      if (select) {
        const apartments = data.data || data.apartments || [];
        select.innerHTML =
          '<option value="">Select Apartment</option>' +
          apartments
            .map(
              (apt) =>
                `<option value="${apt.apartment_code}">${apt.property_name || ""} - ${apt.apartment_number} (${apt.apartment_code})</option>`,
            )
            .join("");
      }
    }
  } catch (error) {
    console.error("Error loading apartments for modal:", error);
  }
}

// Form submissions
async function quickRecordPayment(event) {
  if (event) event.preventDefault();

  const tenantSelect = document.getElementById("quickTenant");
  const tenantFeeSelect = document.getElementById("quickFeeType");
  const amountInput = document.getElementById("quickAmount");
  const transactionReference = document.getElementById(
    "quickTransactionReference",
  );
  const dueDate = document.getElementById("quickDueDate");
  const methodSelect = document.getElementById("quickMethod");

  // Validate selections
  if (!tenantSelect || !tenantSelect.value) {
    showAlert("Please select a tenant", "error");
    return;
  }

  if (!tenantFeeSelect || !tenantFeeSelect.value) {
    showAlert("Please select a fee type", "error");
    return;
  }

  if (!amountInput || parseFloat(amountInput.value) <= 0) {
    showAlert("Please enter a valid amount", "error");
    return;
  }

  if (!dueDate || !dueDate.value) {
    showAlert("Due date is required", "error");
    return;
  }

  updateQuickTransactionReference();
  const selectedOption = tenantFeeSelect.options[tenantFeeSelect.selectedIndex];

  const data = {
    tenant_code: tenantSelect.value,
    tenant_fee_id: tenantFeeSelect.value,
    amount: parseFloat(amountInput.value),
    payment_method: methodSelect ? methodSelect.value : "cash",
    reference_number: transactionReference ? transactionReference.value : "",
    due_date: dueDate.value,
  };

  const confirmMsg =
    `Tenant: ${tenantSelect.options[tenantSelect.selectedIndex].text}\n` +
    `Fee: ${selectedOption.text}\n` +
    `Amount: NGN ${data.amount.toLocaleString()}\n` +
    `Payment Method: ${formatPaymentMethod(data.payment_method)}\n` +
    `Reference: ${data.reference_number}\n\n` +
    `Record this payment?`;

  const confirmed = await showConfirmModal({
    title: "Confirm Quick Payment",
    message: confirmMsg,
    confirmText: "Record Payment",
    cancelText: "Review Details",
    variant: "info",
  });

  if (!confirmed) {
    return;
  }

  // Disable submit button
  const submitBtn = document.querySelector(
    "#quickPaymentForm button[type='submit'], button[form='quickPaymentForm'][type='submit']",
  );
  const originalText = submitBtn ? submitBtn.innerHTML : "Submit";
  if (submitBtn) {
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Recording...';
    submitBtn.disabled = true;
  }

  try {
    const response = await fetch(
      "../backend/payment/payment_manager.php?action=record_payment",
      {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify(data),
      },
    );

    const result = await response.json();

    if (result.success) {
      showAlert(
        `✓ Payment recorded successfully!\nReceipt #: ${result.receipt_number}`,
        "success",
      );

      // Reset form
      resetQuickPaymentForm();
      closeQuickFeePaymentModal();

      // Refresh data
      if (typeof loadPayments === "function") loadPayments();
      if (typeof loadStatistics === "function") loadStatistics();
      if (typeof loadDashboardData === "function") loadDashboardData();
    } else {
      showAlert(result.message || "Failed to record payment", "error");
    }
  } catch (error) {
    console.error("Error recording payment:", error);
    showAlert("Error recording payment. Please try again.", "error");
  } finally {
    if (submitBtn) {
      submitBtn.innerHTML = originalText;
      submitBtn.disabled = false;
    }
  }
}

function resetQuickPaymentForm() {
  const tenantSelect = document.getElementById("quickTenant");
  if (tenantSelect) {
    tenantSelect.value = "";
  }

  const methodSelect = document.getElementById("quickMethod");
  if (methodSelect) {
    methodSelect.value = "cash";
  }

  resetQuickPaymentFeeFields();
}

function generateTransactionReference() {
  const timestamp = new Date().getTime();
  const random = Math.floor(Math.random() * 10000);
  return `ADMIN-${timestamp}-${random}`;
}

// async function submitPaymentForm(event) {
//     if (event) event.preventDefault();

//     const form = document.getElementById("paymentForm");
//     const formData = new FormData(form);
//     const data = Object.fromEntries(formData.entries());

//     // Convert numeric fields
//     data.amount = parseFloat(data.amount) || 0;
//     data.balance = parseFloat(data.balance) || 0;

//     try {
//         const response = await fetch("../backend/payment/payment_manager.php?action=create", {
//             method: "POST",
//             headers: {
//                 "Content-Type": "application/json",
//             },
//             body: JSON.stringify(data),
//         });

//         const result = await response.json();

//         if (result.success) {
//             showAlert("Payment created successfully! Receipt #: " + result.message.receipt_number, "success");
//             closeRecordPaymentModal();
//             loadPayments();
//             loadStatistics();
//         } else {
//             showAlert(result.message, "error");
//         }
//     } catch (error) {
//         console.error("Error:", error);
//         showAlert("Error creating payment", "error");
//     }
// }

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
    } else {
      showAlert(data.message, "error");
    }
  } catch (error) {
    console.error("Error:", error);
    showAlert("Error loading payment details", "error");
  }
}

function renderPaymentDetails(payment) {
  const container = document.getElementById("paymentDetails");
  if (!container) return;

  // Store payment ID for status update
  window.currentViewingPaymentId = payment.id;
  window.currentViewingPaymentStatus = payment.payment_status;
  window.currentViewingPaymentType = payment.payment_type;

  const statusOptions = `
        <select id="paymentStatusSelect" class="status-select" onchange="confirmStatusChange()">
            <option value="pending" ${payment.payment_status === "pending" ? "selected" : ""}>Pending</option>
            <option value="completed" ${payment.payment_status === "completed" ? "selected" : ""}>Completed</option>
            <option value="failed" ${payment.payment_status === "failed" ? "selected" : ""}>Failed</option>
            <option value="refunded" ${payment.payment_status === "refunded" ? "selected" : ""}>Refunded</option>
        </select>
    `;

  const detailsHTML = `
        <div class="payment-details-grid">
            <div class="payment-detail-section">
                <h4>Payment Information</h4>
                <table class="payment-detail-table">
                    <tr><td><strong>Receipt #:</strong></td><td>${payment.receipt_number || "N/A"}</td></tr>
                    <tr><td><strong>Amount:</strong></td><td>₦${formatNumber(payment.amount)}</td></tr>
                    <tr><td><strong>Date:</strong></td><td>${formatDate(payment.payment_date)}</td></tr>
                    <tr><td><strong>Due Date:</strong></td><td>${formatDate(payment.due_date) || "N/A"}</td></tr>
                    <tr>
                        <td><strong>Status:</strong></td>
                        <td>
                            <div class="payment-status-row">
                            ${
                                 payment.is_deleted == 1
                            ? `
            <span class="status-badge status-deleted">
                DELETED
            </span>
            <span style="font-size: 12px; color: #ef4444; margin-left: 8px;">
                <i class="fas fa-trash"></i> Deleted ${payment.deleted_at ? new Date(payment.deleted_at).toLocaleDateString() : ""}
            </span>
        `
            : `
            <span class="status-badge status-${payment.payment_status}">
                ${(payment.payment_status || "pending").toUpperCase()}
            </span>
            ${
              payment.payment_status === "pending"
                ? `
                <button class="btn-edit-status" onclick="openStatusUpdateModal(${payment.id})" 
                        style="background: #667eea; color: white; border: none; padding: 4px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; transition: all 0.2s;"
                        onmouseover="this.style.background='#5a67d8'"
                        onmouseout="this.style.background='#667eea'">
                    <i class="fas fa-edit"></i> Change Status
                </button>
            `
                : ""
            }
        `
        }
    </div>
</td>
                    </tr>
                    <tr><td><strong>Method:</strong></td><td>${formatPaymentMethod(payment.payment_method)}</td></tr>
                    <tr><td><strong>Reference:</strong></td><td>${payment.reference_number || "N/A"}</td></tr>
                    <tr><td><strong>Period:</strong></td><td>${payment.payment_period || "N/A"}</td></tr>
                    ${
                      payment.period_start_date
                        ? `
                    <tr><td><strong>Period Start:</strong></td><td>${formatDate(payment.period_start_date)}</td></tr>
                    <tr><td><strong>Period End:</strong></td><td>${formatDate(payment.period_end_date)}</td></tr>
                    `
                        : ""
                    }
                </table>
            </div>
            
            <div class="payment-detail-section">
                <h4>Tenant Information</h4>
                <table class="payment-detail-table">
                    <tr><td><strong>Name:</strong></td><td>${payment.tenant_name || "N/A"}</td></tr>
                    <tr><td><strong>Email:</strong></td><td>${payment.tenant_email || "N/A"}</td></tr>
                    <tr><td><strong>Phone:</strong></td><td>${payment.tenant_phone || "N/A"}</td></tr>
                    <tr><td><strong>Tenant Code:</strong></td><td>${payment.tenant_code || "N/A"}</td></tr>
                    
                </table>
                
                <h4 style="margin-top: 20px;">Property Information</h4>
                <table class="payment-detail-table">
                    <tr><td><strong>Property:</strong></td><td>${payment.property_name || "N/A"}</td></tr>
                    <tr><td><strong>Apartment:</strong></td><td>${payment.apartment_number || "N/A"}</td></tr>
                    <tr><td><strong>Property Code:</strong></td><td>${payment.property_code || "N/A"}</td></tr>
                </table>
            </div>
        </div>
        
        ${
          payment.description
            ? `
        <div class="payment-detail-note">
            <h4>Description</h4>
            <p>${payment.description}</p>
        </div>
        `
            : ""
        }
        
        ${
          payment.notes
            ? `
        <div class="payment-detail-note">
            <h4>Notes</h4>
            <p>${payment.notes}</p>
        </div>
        `
            : ""
        }
    `;

  container.innerHTML = detailsHTML;
}

// Status Update Modal Functions
function openStatusUpdateModal() {
  const modalHtml = `
        <div id="statusUpdateModal" class="modal" style="display: flex;">
            <div class="modal-content" style="max-width: 450px;">
                <div class="modal-header">
                    <h3>Update Payment Status</h3>
                    <button class="modal-close action-btn" onclick="closeStatusUpdateModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Current Status</label>
                        <input type="text" class="form-control" value="${window.currentViewingPaymentStatus.toUpperCase()}" readonly style="background: #f5f5f5;">
                    </div>
                    <div class="form-group">
                        <label>New Status *</label>
                        <select id="newPaymentStatus" class="form-control" required>
                            <option value="">Select Status</option>
                            <option value="pending">Pending</option>
                            <option value="completed">Completed</option>
                            <option value="failed">Failed</option>
                            <option value="refunded">Refunded</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Notes (Optional)</label>
                        <textarea id="statusUpdateNotes" rows="3" class="form-control" 
                                  placeholder="Enter reason for status change..."></textarea>
                    </div>
                    ${
                      window.currentViewingPaymentType === "rent"
                        ? `
                    <div class="alert-info" style="background: #e8f0fe; padding: 10px; border-radius: 6px; margin-top: 10px;">
                        <i class="fas fa-info-circle"></i> 
                        <small>Changing status to "Completed" will update the tenant's lease end date.</small>
                    </div>
                    `
                        : ""
                    }
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" onclick="closeStatusUpdateModal()">Cancel</button>
                    <button class="btn btn-primary" onclick="confirmStatusUpdate()">Update Status</button>
                </div>
            </div>
        </div>
    `;

  // Remove existing modal if any
  const existingModal = document.getElementById("statusUpdateModal");
  if (existingModal) existingModal.remove();

  document.body.insertAdjacentHTML("beforeend", modalHtml);
}

function closeStatusUpdateModal() {
  const modal = document.getElementById("statusUpdateModal");
  if (modal) modal.remove();
}

async function confirmStatusUpdate() {
  const newStatus = document.getElementById("newPaymentStatus")?.value;
  const notes = document.getElementById("statusUpdateNotes")?.value;

  if (!newStatus) {
    alert("Please select a new status");
    return;
  }

  if (newStatus === window.currentViewingPaymentStatus) {
    alert("New status is the same as current status");
    return;
  }

  const confirmMessage = `Are you sure you want to change the payment status from ${window.currentViewingPaymentStatus.toUpperCase()} to ${newStatus.toUpperCase()}?`;
  const confirmed = await showConfirmModal({
    title: "Update Payment Status?",
    message: confirmMessage,
    confirmText: "Yes, Update",
    cancelText: "Cancel",
    variant: "warning",
  });
  if (!confirmed) return;

  // Show loading state
  const confirmBtn = document.querySelector("#statusUpdateModal .btn-primary");
  const originalText = confirmBtn.innerHTML;
  confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
  confirmBtn.disabled = true;

  try {
    const response = await fetch(
      "../backend/payment/payment_manager.php?action=update_status",
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          payment_id: window.currentViewingPaymentId,
          status: newStatus,
          notes: notes,
        }),
      },
    );

    const data = await response.json();

    if (data.success) {
      alert(data.message);
      closeStatusUpdateModal();
      // Refresh the payment details and the table
      await viewPayment(window.currentViewingPaymentId);
      await loadPayments();
    } else {
      alert(data.message || "Failed to update payment status");
    }
  } catch (error) {
    console.error("Error updating payment status:", error);
    alert("An error occurred while updating the payment status");
  } finally {
    confirmBtn.innerHTML = originalText;
    confirmBtn.disabled = false;
  }
}

// Delete payment
async function deletePayment(paymentId) {
  const confirmed = await showConfirmModal({
    title: "Delete Payment?",
    message:
      "Are you sure you want to delete this payment? This action cannot be undone.",
    confirmText: "Yes, Delete",
    cancelText: "Keep Payment",
    variant: "danger",
  });

  if (!confirmed) {
    return;
  }

  try {
    const response = await fetch(
      "../backend/payment/payment_manager.php?action=delete",
      {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({ id: paymentId }),
      },
    );

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

async function restorePayment(paymentId) {
  const confirmed = await showConfirmModal({
    title: "Restore Payment?",
    message: "Are you sure you want to restore this payment? .",
    confirmText: "Yes, Restore",
    cancelText: "Cancel",
    variant: "warning",
  });

  if (!confirmed) {
    return;
  }

  try {
    const response = await fetch(
      "../backend/payment/payment_manager.php?action=restore",
      {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({ id: paymentId }),
      },
    );

    const result = await response.json();

    if (result.success) {
      showAlert("Payment Restored successfully", "success");
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

// Edit payment (placeholder)
function editPayment(paymentId) {
  showAlert("Edit functionality to be implemented", "info");
}

// Export payments
function exportPayments() {
  const params = new URLSearchParams({ action: "export" });

  if (currentFilters.search) params.set("search", currentFilters.search);
  if (currentFilters.tenant_code)
    params.set("tenant_code", currentFilters.tenant_code);
  if (currentFilters.property_code)
    params.set("property_code", currentFilters.property_code);
  if (currentFilters.apartment_code)
    params.set("apartment_code", currentFilters.apartment_code);
  if (currentFilters.payment_status)
    params.set("payment_status", currentFilters.payment_status);
  if (currentFilters.payment_method)
    params.set("payment_method", currentFilters.payment_method);
  if (currentFilters.date_from)
    params.set("date_from", currentFilters.date_from);
  if (currentFilters.date_to) params.set("date_to", currentFilters.date_to);

  window.location.href = `../backend/payment/payment_manager.php?${params.toString()}`;
}

// Utility functions
function formatNumber(value) {
  if (!value) return "0.00";
  return parseFloat(value).toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
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
  const methods = {
    bank_transfer: "Bank Transfer",
    card: "Card",
    cash: "Cash",
    cheque: "Cheque",
    admin_initiated: "Admin Initiated",
  };
  return methods[method] || method || "N/A";
}

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

function escapeHtml(text) {
  if (!text) return "";
  const div = document.createElement("div");
  div.textContent = text;
  return div.innerHTML;
}

// Auto-refresh every 5 minutes
setInterval(
  () => {
    if (document.visibilityState === "visible") {
      loadPayments();
      loadStatistics();
    }
  },
  5 * 60 * 1000,
);
