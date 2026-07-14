// client/scripts/dashboard.js

let currentPeriod = 'monthly';

const dashboardEndpoints = {
    stats: '../backend/dashboard/fetch_stats.php',
    revenue: '../backend/dashboard/fetch_revenue.php',
    properties: '../backend/dashboard/fetch_properties.php',
    payments: '../backend/dashboard/fetch_recent_payments.php'
};

document.addEventListener('DOMContentLoaded', function() {
    loadDashboardData();
    loadRevenueData();
    loadProperties();
    loadRecentPayments();

    const periodSelect = document.getElementById('revenuePeriod');
    if (periodSelect) {
        periodSelect.addEventListener('change', function() {
            currentPeriod = this.value;
            loadRevenueData();
        });
    }
});

async function loadDashboardData() {
    try {
        const data = await fetchDashboardJson(dashboardEndpoints.stats);
        const stats = data.data || {};

        setText('totalProperties', formatInteger(stats.total_properties));
        setText('totalUnits', formatInteger(stats.total_units));
        setText('occupiedUnits', formatInteger(stats.occupied_units));
        setText('occupancyRate', `${formatInteger(stats.occupancy_rate)}%`);
        setText('dashboardClientName', stats.client_name || 'Client');
    } catch (error) {
        console.error('Error loading dashboard stats:', error);
        setText('dashboardClientName', 'Client');
    }
}

async function loadRevenueData() {
    try {
        const url = `${dashboardEndpoints.revenue}?period=${encodeURIComponent(currentPeriod)}`;
        const data = await fetchDashboardJson(url);
        const revenue = data.data || {};

        // ==================== RENT REVENUE ====================
        const rentRevenue = revenue.rent_revenue || {};
        setText('totalCollected', formatCurrency(rentRevenue.total_collected));
        setText('pendingPayments', formatCurrency(rentRevenue.total_pending));
        setText('overduePayments', formatCurrency(rentRevenue.total_overdue));
        setText('expectedRevenue', formatCurrency(rentRevenue.expected_revenue));

        // ==================== SETTLEMENT REVENUE (NEW) ====================
        const settlementRevenue = revenue.settlement_revenue || {};
        setText('settlementTotalEarned', formatCurrency(settlementRevenue.total_earned));
        setText('settlementTotalPaid', formatCurrency(settlementRevenue.total_paid));
        setText('settlementTotalPending', formatCurrency(settlementRevenue.total_pending));
        setText('settlementRateDisplay', `${settlementRevenue.settlement_rate || 0}%`);
        setText('settlementCompleted', formatInteger(settlementRevenue.completed_settlements));
        setText('settlementPending', formatInteger(settlementRevenue.pending_settlements));

        // ==================== DEDUCTIONS ====================
        const deductions = revenue.deductions || {};
        setText('deductionAdminFees', formatCurrency(deductions.admin_fees));
        setText('deductionAgentCommissions', formatCurrency(deductions.agent_commissions));
        setText('deductionTotal', formatCurrency(deductions.total_deductions));

        // ==================== SUMMARY ====================
        const summary = revenue.summary || {};
        setText('summaryNetReceived', formatCurrency(summary.net_received));
        setText('summaryPendingPayout', formatCurrency(summary.pending_payout));
        setText('summarySettlementGap', formatCurrency(summary.settlement_gap));

        // ==================== UPDATE SETTLEMENT STATS CARDS ====================
        updateSettlementStats(settlementRevenue, deductions, summary);

    } catch (error) {
        console.error('Error loading revenue data:', error);
        // Rent Revenue
        setText('totalCollected', formatCurrency(0));
        setText('pendingPayments', formatCurrency(0));
        setText('overduePayments', formatCurrency(0));
        setText('expectedRevenue', formatCurrency(0));
        // Settlement Revenue
        setText('settlementTotalEarned', formatCurrency(0));
        setText('settlementTotalPaid', formatCurrency(0));
        setText('settlementTotalPending', formatCurrency(0));
        setText('settlementRate', '0%');
        setText('settlementCompleted', '0');
        setText('settlementPending', '0');
        // Deductions
        setText('deductionAdminFees', formatCurrency(0));
        setText('deductionAgentCommissions', formatCurrency(0));
        setText('deductionTotal', formatCurrency(0));
        // Summary
        setText('summaryNetReceived', formatCurrency(0));
        setText('summaryPendingPayout', formatCurrency(0));
        setText('summarySettlementGap', formatCurrency(0));
    }
}

function updateSettlementStats(settlementRevenue, deductions, summary) {
    // This function can be used to update additional UI elements if needed
    // For now, the setText calls above handle the updates
}

async function loadProperties() {
    const container = document.getElementById('propertiesGrid');
    if (!container) return;

    try {
        const data = await fetchDashboardJson(`${dashboardEndpoints.properties}?limit=3`);
        const properties = Array.isArray(data.data?.properties) ? data.data.properties : [];

        if (properties.length > 0) {
            container.innerHTML = properties.map(prop => `
                <div class="property-card" onclick="window.location.href='apartment.php?view=${encodeURIComponent(prop.property_code || '')}'">
                    <div class="property-header">
                        <h3>${escapeHtml(prop.property_name)}</h3>
                        <span class="property-status active">Active</span>
                    </div>
                    <div class="property-address">${escapeHtml(prop.property_address || 'No address')}</div>
                    <div class="property-stats">
                        <div class="stat">
                            <span class="stat-value">${formatInteger(prop.total_units)}</span>
                            <span class="stat-label">Units</span>
                        </div>
                        <div class="stat">
                            <span class="stat-value">${formatInteger(prop.occupied_units)}</span>
                            <span class="stat-label">Occupied</span>
                        </div>
                        <div class="stat">
                            <span class="stat-value">${formatInteger(prop.vacant_units)}</span>
                            <span class="stat-label">Vacant</span>
                        </div>
                        <div class="stat">
                            <span class="stat-value">${formatInteger(prop.occupancy_rate)}%</span>
                            <span class="stat-label">Occupancy</span>
                        </div>
                    </div>
                </div>
            `).join('');
        } else {
            container.innerHTML = '<div class="empty-state"><i class="fas fa-building"></i><p>No properties found</p></div>';
        }
    } catch (error) {
        console.error('Error loading properties:', error);
        container.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Failed to load properties</p></div>';
    }
}

async function loadRecentPayments() {
    const container = document.getElementById('recentPayments');
    if (!container) return;

    try {
        const data = await fetchDashboardJson(`${dashboardEndpoints.payments}?limit=5`);
        const payments = Array.isArray(data.data?.payments) ? data.data.payments : [];

        if (payments.length > 0) {
            container.innerHTML = `
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Tenant</th>
                            <th>Property</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${payments.map(payment => {
                            const status = normalizeStatus(payment.status);
                            return `
                                <tr>
                                    <td>${formatDate(payment.payment_date)}</td>
                                    <td>${escapeHtml(payment.tenant_name)}</td>
                                    <td>${escapeHtml(payment.property_name)}</td>
                                    <td class="payment-amount">${formatCurrency(payment.amount)}</td>
                                    <td><span class="payment-status ${status}">${escapeHtml(status)}</span></td>
                                </tr>
                            `;
                        }).join('')}
                    </tbody>
                </table>
            `;
        } else {
            container.innerHTML = '<div class="empty-state"><i class="fas fa-receipt"></i><p>No recent payments</p></div>';
        }
    } catch (error) {
        console.error('Error loading recent payments:', error);
        container.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Failed to load payments</p></div>';
    }
}

async function fetchDashboardJson(url) {
    const response = await fetch(url, {
        credentials: 'include',
        headers: { 'Accept': 'application/json' }
    });
    const data = await response.json();

    if (!response.ok || !data.success) {
        throw new Error(data.message || `Request failed (${response.status})`);
    }

    return data;
}

function setText(id, value) {
    const element = document.getElementById(id);
    if (element) {
        element.textContent = value;
    }
}

function formatInteger(value) {
    return new Intl.NumberFormat('en-NG', { maximumFractionDigits: 0 }).format(Number(value) || 0);
}

function formatNumber(value) {
    return new Intl.NumberFormat('en-NG', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(Number(value) || 0);
}

function formatCurrency(value) {
    return `\u20a6${formatNumber(value)}`;
}

function formatDate(dateString) {
    if (!dateString) return 'N/A';

    const date = new Date(dateString);
    if (Number.isNaN(date.getTime())) return 'N/A';

    return date.toLocaleDateString('en-NG', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function normalizeStatus(status) {
    return String(status || 'pending').toLowerCase().replace(/[^a-z0-9_-]/g, '');
}