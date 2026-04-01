// payments.js
let paymentHistory = [];
let currentPage = 1;
let totalPages = 1;
let nextPaymentAmount = 0;
let nextPaymentDate = '';

document.addEventListener('DOMContentLoaded', function() {
    initializePayments();
});

async function initializePayments() {
    // Wait for user data to be loaded from navbar
    if (window.currentUser) {
        await Promise.all([fetchDashboardData(), fetchPaymentHistory()]);
    } else {
        // Listen for user data loaded event
        window.addEventListener('userDataLoaded', async function() {
            await Promise.all([fetchDashboardData(), fetchPaymentHistory()]);
        });
        
        // Also try to fetch if not available after a short delay
        setTimeout(async () => {
            if (!window.currentUser && !document.querySelector('.payments-container')) {
                await Promise.all([fetchDashboardData(), fetchPaymentHistory()]);
            }
        }, 1000);
    }
}

async function fetchDashboardData() {
    try {
        const response = await fetch('../backend/tenant/fetch_dashboard_data.php');
        const data = await response.json();
        
        console.log('Dashboard data:', data); // Debug log
        
        if (data.success && data.data) {
            const dashboard = data.data;
            nextPaymentAmount = dashboard.next_payment_amount || dashboard.rent_amount || 0;
            nextPaymentDate = dashboard.next_payment_date || new Date().toISOString().split('T')[0];
        }
    } catch (error) {
        console.error('Error fetching dashboard data:', error);
        if (window.showToast) {
            window.showToast('Failed to load payment information', 'error');
        }
    }
}

async function fetchPaymentHistory() {
    try {
        const response = await fetch(`../backend/tenant/fetch_payment_history.php?page=${currentPage}&limit=10`);
        const data = await response.json();
        
        console.log('Payment history response:', data); // Debug log
        
        if (data.success && data.data) {
            paymentHistory = data.data.payments || [];
            const pagination = data.data.pagination || {};
            totalPages = pagination.total_pages || 1;
            renderPaymentPage();
        } else {
            throw new Error(data.message || 'Failed to fetch payment history');
        }
    } catch (error) {
        console.error('Error fetching payment history:', error);
        if (window.showToast) {
            window.showToast('Failed to load payment history', 'error');
        }
        showEmptyState();
    }
}

function renderPaymentPage() {
    const contentArea = document.getElementById('contentArea');
    if (!contentArea) return;
    
    // Calculate summary
    const totalPaid = paymentHistory.reduce((sum, p) => p.status === 'completed' ? sum + parseFloat(p.amount) : sum, 0);
    const successfulPayments = paymentHistory.filter(p => p.status === 'completed').length;

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
            </div>
            
            <div class="payment-card">
                <div class="payment-info">
                    <h3>Upcoming Rent Payment</h3>
                    <div class="amount">₦${formatNumber(nextPaymentAmount)}</div>
                    <div class="date">Due Date: ${formatDate(nextPaymentDate)}</div>
                </div>
                <button class="btn-primary" onclick="openPaymentModal()">
                    <i class="fas fa-credit-card"></i> Make Payment
                </button>
            </div>
            
            <div class="payments-table">
                <h3 style="padding: 20px 20px 0 20px;">Payment History</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th>Status</th>
                            <th>Receipt</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${paymentHistory.length === 0 ? `
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 40px;">
                                    <i class="fas fa-receipt" style="font-size: 48px; color: #ccc; margin-bottom: 10px; display: block;"></i>
                                    No payment records found
                                </td>
                            </tr>
                        ` : paymentHistory.map(payment => `
                            <tr>
                                <td>${formatDate(payment.payment_date)}</td>
                                <td>₦${formatNumber(payment.amount)}</td>
                                <td>${formatPaymentMethod(payment.payment_method)}</td>
                                <td>${payment.reference_number || '-'}</td>
                                <td class="status-${payment.status}">${payment.status.toUpperCase()}</td>
                                <td>
                                    ${payment.receipt_path ? 
                                        `<button class="btn-download" onclick="downloadReceipt(${payment.payment_id})" title="Download Receipt">
                                            <i class="fas fa-download"></i>
                                        </button>` : 
                                        '<span style="color: #ccc;">-</span>'
                                    }
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
            
            ${renderPagination()}
        </div>
    `;
    
    contentArea.innerHTML = html;
}

function showEmptyState() {
    const contentArea = document.getElementById('contentArea');
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
                <button class="btn-primary" onclick="openPaymentModal()">
                    <i class="fas fa-credit-card"></i> Make Payment
                </button>
            </div>
            
            <div class="empty-state">
                <i class="fas fa-receipt"></i>
                <h3>No Payment History</h3>
                <p>You haven't made any payments yet.</p>
                <button class="btn-primary" onclick="openPaymentModal()" style="margin-top: 15px;">
                    Make Your First Payment
                </button>
            </div>
        </div>
    `;
}

function renderPagination() {
    if (totalPages <= 1) return '';
    
    let html = '<div class="pagination">';
    for (let i = 1; i <= totalPages; i++) {
        html += `<button class="page-btn ${i === currentPage ? 'active' : ''}" onclick="goToPage(${i})">${i}</button>`;
    }
    html += '</div>';
    return html;
}

function goToPage(page) {
    currentPage = page;
    fetchPaymentHistory();
}

function openPaymentModal() {
    const modal = document.getElementById('paymentModal');
    if (modal) {
        modal.classList.add('active');
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
        const form = document.getElementById('paymentForm');
        if (form) form.reset();
    }
}

async function processPayment() {
    const paymentMethod = document.getElementById('paymentMethod')?.value;
    const referenceNumber = document.getElementById('referenceNumber')?.value;

    if (!paymentMethod) {
        if (window.showToast) {
            window.showToast('Please select a payment method', 'error');
        }
        return;
    }

    if (window.showToast) {
        window.showToast('Redirecting to payment gateway...', 'info');
    }
    
    // Simulate payment processing - In production, integrate with actual payment gateway
    setTimeout(async () => {
        if (window.showToast) {
            window.showToast('Payment successful!', 'success');
        }
        closeModal('paymentModal');
        await Promise.all([fetchDashboardData(), fetchPaymentHistory()]);
    }, 2000);
}

function downloadReceipt(paymentId) {
    window.open(`../backend/tenant/download_receipt.php?payment_id=${paymentId}`, '_blank');
}

// ==================== UTILITY FUNCTIONS ====================
function formatNumber(value) {
    if (!value || value === '0') return '0.00';
    return new Intl.NumberFormat('en-NG', { 
        minimumFractionDigits: 2, 
        maximumFractionDigits: 2 
    }).format(value);
}

function formatDate(dateString) {
    if (!dateString) return 'N/A';
    try {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric' 
        });
    } catch (e) {
        return dateString;
    }
}

function formatPaymentMethod(method) {
    const map = { 
        'bank_transfer': 'Bank Transfer', 
        'card': 'Card', 
        'cash': 'Cash', 
        'cheque': 'Cheque' 
    };
    return map[method] || method;
}