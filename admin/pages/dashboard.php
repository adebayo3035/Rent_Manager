<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RentFlow Pro | Management Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Dashboard CSS will be included below */
    </style>
</head>
<body>
     <?php include('navbar.php'); ?>
    <div class="dashboard-container">
        <!-- Header -->
        <!-- <header class="dashboard-header">
            <div class="header-left">
                <h1><i class="fas fa-home"></i> RentFlow Pro Dashboard</h1>
                <p class="subtitle">Complete Rent Management System</p>
            </div>
            <div class="header-right">
                <div class="date-time" id="currentDateTime"></div>
                <div class="user-profile">
                    <img src="https://ui-avatars.com/api/?name=Admin&background=2563eb&color=fff" alt="Admin">
                    <div class="user-info">
                        <span class="user-name">Super Admin</span>
                        <span class="user-role">System Administrator</span>
                    </div>
                    <i class="fas fa-chevron-down"></i>
                </div>
            </div>
        </header> -->

        <!-- Quick Stats -->
        <div class="quick-stats">
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-info">
                    <h3>Active Tenants</h3>
                    <p class="stat-number" id="activeTenants">0</p>
                    <span class="stat-change positive"><i class="fas fa-arrow-up"></i> 5%</span>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="stat-info">
                    <h3>Monthly Revenue</h3>
                    <p class="stat-number" id="monthlyRevenue">$0</p>
                    <span class="stat-change positive"><i class="fas fa-arrow-up"></i> 12%</span>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                    <i class="fas fa-building"></i>
                </div>
                <div class="stat-info">
                    <h3>Properties</h3>
                    <p class="stat-number" id="totalProperties">0</p>
                    <span class="stat-change"><i class="fas fa-plus"></i> 3 New</span>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                    <i class="fas fa-exclamation-circle"></i>
                </div>
                <div class="stat-info">
                    <h3>Pending Requests</h3>
                    <p class="stat-number" id="pendingRequests">0</p>
                    <span class="stat-change negative"><i class="fas fa-arrow-up"></i> 8%</span>
                </div>
            </div>
        </div>

        <!-- Main Dashboard Content -->
        <div class="dashboard-content">
            <!-- Left Column -->
            <div class="dashboard-left">
                <!-- Financial Overview -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <h2><i class="fas fa-chart-line"></i> Financial Overview</h2>
                        <select id="timeFilter" class="time-filter">
                            <option value="monthly">This Month</option>
                            <option value="quarterly">This Quarter</option>
                            <option value="yearly">This Year</option>
                        </select>
                    </div>
                    <div class="card-body">
                        <canvas id="revenueChart"></canvas>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <h2><i class="fas fa-history"></i> Recent Activity</h2>
                    </div>
                    <div class="card-body">
                        <div class="activity-list" id="recentActivities">
                            <!-- Will be populated by JavaScript -->
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column -->
            <div class="dashboard-right">
                <!-- Module Quick Access -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <h2><i class="fas fa-th-large"></i> Quick Access</h2>
                    </div>
                    <div class="card-body">
                        <div class="module-grid">
                            <!-- Clients Module -->
                            <a href="client.php" class="module-card">
                                <div class="module-icon" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);">
                                    <i class="fas fa-user-tie"></i>
                                </div>
                                <div class="module-info">
                                    <h3>Clients</h3>
                                    <p>Manage property owners</p>
                                    <span class="module-count" id="clientsCount">0</span>
                                </div>
                            </a>

                            <!-- Agents Module -->
                            <a href="agent.php" class="module-card">
                                <div class="module-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                                    <i class="fas fa-user-check"></i>
                                </div>
                                <div class="module-info">
                                    <h3>Agents</h3>
                                    <p>Manage property agents</p>
                                    <span class="module-count" id="agentsCount">0</span>
                                </div>
                            </a>

                            <!-- Tenants Module -->
                            <a href="tenant.php" class="module-card">
                                <div class="module-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div class="module-info">
                                    <h3>Tenants</h3>
                                    <p>Manage all tenants</p>
                                    <span class="module-count" id="tenantsCount">0</span>
                                </div>
                            </a>

                            <!-- Properties Module -->
                            <a href="property.php" class="module-card">
                                <div class="module-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                                    <i class="fas fa-building"></i>
                                </div>
                                <div class="module-info">
                                    <h3>Properties</h3>
                                    <p>Manage all properties</p>
                                    <span class="module-count" id="propertiesCount">0</span>
                                </div>
                            </a>

                            <!-- Apartments Module -->
                            <a href="apartment.php" class="module-card">
                                <div class="module-icon" style="background: linear-gradient(135deg, #ec4899, #db2777);">
                                    <i class="fas fa-home"></i>
                                </div>
                                <div class="module-info">
                                    <h3>Apartments</h3>
                                    <p>Manage apartments</p>
                                    <span class="module-count" id="apartmentsCount">0</span>
                                </div>
                            </a>

                            <!-- Payments Module -->
                            <a href="payments.php" class="module-card">
                                <div class="module-icon" style="background: linear-gradient(135deg, #14b8a6, #0d9488);">
                                    <i class="fas fa-credit-card"></i>
                                </div>
                                <div class="module-info">
                                    <h3>Payments</h3>
                                    <p>Manage all payments</p>
                                    <span class="module-count" id="paymentsCount">0</span>
                                </div>
                            </a>

                            <!-- Account Management -->
                            <a href="account_management.php" class="module-card">
                                <div class="module-icon" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                                    <i class="fas fa-user-lock"></i>
                                </div>
                                <div class="module-info">
                                    <h3>Account Management</h3>
                                    <p>View locked accounts</p>
                                    <span class="module-badge" id="lockedAccountsCount">0 Locked</span>
                                </div>
                            </a>

                            <!-- Staff Management -->
                            <a href="staff.php" class="module-card">
                                <div class="module-icon" style="background: linear-gradient(135deg, #6366f1, #4f46e5);">
                                    <i class="fas fa-user-shield"></i>
                                </div>
                                <div class="module-info">
                                    <h3>Staff</h3>
                                    <p>Manage admin users</p>
                                    <span class="module-count" id="staffCount">0</span>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Pending Tasks -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <h2><i class="fas fa-tasks"></i> Pending Actions</h2>
                    </div>
                    <div class="card-body">
                        <div class="tasks-list" id="pendingTasks">
                            <!-- Will be populated by JavaScript -->
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bottom Row -->
        <div class="dashboard-bottom">
            <!-- Occupancy Overview -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h2><i class="fas fa-bed"></i> Occupancy Overview</h2>
                </div>
                <div class="card-body">
                    <canvas id="occupancyChart"></canvas>
                </div>
            </div>

            <!-- Recent Transactions -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h2><i class="fas fa-exchange-alt"></i> Recent Transactions</h2>
                    <a href="payments.php" class="view-all">View All <i class="fas fa-arrow-right"></i></a>
                </div>
                <div class="card-body">
                    <table class="transactions-table">
                        <thead>
                            <tr>
                                <th>Tenant</th>
                                <th>Property</th>
                                <th>Amount</th>
                                <th>Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="recentTransactions">
                            <!-- Will be populated by JavaScript -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src = "../scripts/dashboard.js"></script>
</body>
</html>