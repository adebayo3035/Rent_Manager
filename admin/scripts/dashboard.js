// dashboard.js
let dashboardData = {};
let revenueChart = null;
let occupancyChart = null;

// Load dashboard data
async function loadDashboardData() {
    try {
        const response = await fetch('../backend/utilities/dashboard.php');
        const data = await response.json();
        
        if (data.success) {
            dashboardData = data;
            updateDashboardUI();
            updateCharts();
        } else {
            showToast('Error loading dashboard data', 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Network error loading dashboard', 'error');
    }
}

// Update UI with data
function updateDashboardUI() {
    const stats = dashboardData.stats;
    
    // Update counters with animation
    animateCounter('activeTenants', stats.activeTenants);
    animateCounter('monthlyRevenue', stats.monthlyRevenue, '$');
    animateCounter('totalProperties', stats.totalProperties);
    animateCounter('pendingRequests', stats.pendingRequests);
    
    // Update module counts
    document.getElementById('clientsCount').textContent = stats.clients;
    document.getElementById('agentsCount').textContent = stats.agents;
    document.getElementById('tenantsCount').textContent = stats.tenants;
    document.getElementById('propertiesCount').textContent = stats.properties;
    document.getElementById('apartmentsCount').textContent = stats.apartments;
    document.getElementById('paymentsCount').textContent = stats.payments;
    document.getElementById('lockedAccountsCount').textContent = `${stats.lockedAccounts} Locked`;
    document.getElementById('staffCount').textContent = stats.staff;
    
    // Update recent activities
    updateRecentActivities();
    
    // Update recent transactions
    updateRecentTransactions();
    
    // Update pending tasks
    updatePendingTasks();
}

// Update charts
function updateCharts() {
    updateRevenueChart();
    updateOccupancyChart();
}

function updateRevenueChart() {
    const ctx = document.getElementById('revenueChart').getContext('2d');
    const revenueData = dashboardData.revenueData;
    
    if (revenueChart) {
        revenueChart.destroy();
    }
    
    const labels = revenueData.map(item => item.month_name || item.quarter || item.year);
    const data = revenueData.map(item => item.revenue);
    
    revenueChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Revenue',
                data: data,
                borderColor: '#2563eb',
                backgroundColor: 'rgba(37, 99, 235, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '$' + value.toLocaleString();
                        }
                    }
                }
            }
        }
    });
}

function updateOccupancyChart() {
    const ctx = document.getElementById('occupancyChart').getContext('2d');
    const occupancy = dashboardData.occupancyData;
    
    if (occupancyChart) {
        occupancyChart.destroy();
    }
    
    occupancyChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Occupied', 'Vacant', 'Maintenance', 'Reserved'],
            datasets: [{
                data: [
                    occupancy.occupied || 0,
                    occupancy.vacant || 0,
                    occupancy.maintenance || 0,
                    occupancy.reserved || 0
                ],
                backgroundColor: [
                    '#10b981',
                    '#3b82f6',
                    '#f59e0b',
                    '#8b5cf6'
                ],
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });
}

// Helper functions
function animateCounter(elementId, target, prefix = '') {
    const element = document.getElementById(elementId);
    if (!element) return;
    
    let current = 0;
    const increment = target / 100;
    const timer = setInterval(() => {
        current += increment;
        if (current >= target) {
            current = target;
            clearInterval(timer);
        }
        element.textContent = prefix + Math.floor(current).toLocaleString();
    }, 20);
}

function updateRecentActivities() {
    const container = document.getElementById('recentActivities');
    container.innerHTML = '';
    
    dashboardData.activities.forEach(activity => {
        const activityHTML = `
            <div class="activity-item">
                <div class="activity-icon" style="background: ${activity.color}">
                    <i class="${activity.icon}"></i>
                </div>
                <div class="activity-info">
                    <h4>${activity.description}</h4>
                    <p>${activity.type.replace('_', ' ').toUpperCase()}</p>
                </div>
                <span class="activity-time">${activity.time_ago}</span>
            </div>
        `;
        container.innerHTML += activityHTML;
    });
}

function updateRecentTransactions() {
    const container = document.getElementById('recentTransactions');
    container.innerHTML = '';
    
    dashboardData.recentTransactions.forEach(transaction => {
        const statusClass = `status-${transaction.payment_status}`;
        const transactionHTML = `
            <tr>
                <td>${transaction.tenant_name || 'N/A'}</td>
                <td>${transaction.property_name || 'N/A'}</td>
                <td><strong>${transaction.amount_formatted}</strong></td>
                <td>${transaction.payment_date_formatted}</td>
                <td><span class="status-badge ${statusClass}" 
                         style="background: ${transaction.status_color}20; color: ${transaction.status_color}">
                    ${transaction.payment_status.toUpperCase()}
                </span></td>
            </tr>
        `;
        container.innerHTML += transactionHTML;
    });
}

function updatePendingTasks() {
    const container = document.getElementById('pendingTasks');
    container.innerHTML = '';
    
    dashboardData.pendingTasks.forEach(task => {
        const taskHTML = `
            <div class="task-item">
                <div class="task-icon" style="background: ${task.color}">
                    <i class="fas ${getTaskIcon(task.type)}"></i>
                </div>
                <div class="task-info">
                    <h4>${task.title}</h4>
                    <p>${task.count} item(s)</p>
                </div>
                <span class="task-time ${task.priority.toLowerCase().replace(' ', '-')}">
                    ${task.priority}
                </span>
            </div>
        `;
        container.innerHTML += taskHTML;
    });
}

function getTaskIcon(type) {
    const icons = {
        'account_reactivation': 'fa-user-check',
        'overdue_payments': 'fa-exclamation-triangle',
        'contract_renewals': 'fa-file-contract',
        'maintenance': 'fa-tools'
    };
    return icons[type] || 'fa-tasks';
}

// Initialize dashboard
document.addEventListener('DOMContentLoaded', function() {
    loadDashboardData();
    
    // Refresh data every 30 seconds
    setInterval(loadDashboardData, 30000);
    
    // Handle time filter change
    document.getElementById('timeFilter').addEventListener('change', function(e) {
        loadDashboardData();
    });
});

// Toast notification
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `
        <i class="fas ${getToastIcon(type)}"></i>
        <span>${message}</span>
    `;
    
    document.getElementById('toastContainer').appendChild(toast);
    
    setTimeout(() => {
        toast.remove();
    }, 3000);
}

function getToastIcon(type) {
    const icons = {
        'success': 'fa-check-circle',
        'error': 'fa-exclamation-circle',
        'warning': 'fa-exclamation-triangle',
        'info': 'fa-info-circle'
    };
    return icons[type] || 'fa-info-circle';
}