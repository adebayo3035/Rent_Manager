<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';

try {
    // Authentication check
    if (!isset($_SESSION['unique_id'])) {
        logActivity("Unauthorized dashboard access attempt: No session found.");
        echo json_encode(["success" => false, "message" => "Not logged in."]);
        exit();
    }

    $adminId = $_SESSION['unique_id'];
    $userRole = $_SESSION['role'] ?? 'Unknown';
    
    if (!$conn) {
        logActivity("Database connection failed for dashboard.");
        echo json_encode(["success" => false, "message" => "Database connection error."]);
        exit();
    }

    // Initialize response array
    $response = [
        "success" => true,
        "stats" => [],
        "activities" => [],
        "revenueData" => [],
        "occupancyData" => [],
        "recentTransactions" => [],
        "timestamp" => date('Y-m-d H:i:s')
    ];

    // 1. GET DASHBOARD STATISTICS
    $stats = [];

    // Active Tenants
    $query = "SELECT COUNT(*) as count FROM tenants WHERE status = '1'";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['activeTenants'] = $result->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    // Monthly Revenue (current month)
    $currentMonth = date('Y-m');
    $query = "SELECT SUM(amount) as total FROM payments 
              WHERE DATE_FORMAT(payment_date, '%Y-%m') = ? 
              AND payment_status = 'completed' 
              AND status = 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $currentMonth);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['monthlyRevenue'] = $result->fetch_assoc()['total'] ?? 0;
    $stmt->close();

    // Total Properties
    $query = "SELECT COUNT(*) as count FROM properties WHERE status = '1'";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['totalProperties'] = $result->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    // Pending Account Reactivation Requests
    $query = "SELECT COUNT(*) as count FROM account_reactivation_requests WHERE status = 'pending'";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['pendingRequests'] = $result->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    // Module Counts
    $tables = [
        'clients' => 'status = 1',
        'agents' => 'status = 1',
        'tenants' => 'status = 1',
        'properties' => 'status = 1',
        'apartments' => 'status = 1',
        'payments' => 'status = 1',
        'admin_tbl' => 'status = 1'
    ];

    foreach ($tables as $table => $condition) {
        $query = "SELECT COUNT(*) as count FROM $table WHERE $condition";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $result = $stmt->get_result();
        $stats[$table] = $result->fetch_assoc()['count'] ?? 0;
        $stmt->close();
    }

    // Locked Accounts
    $query = "SELECT COUNT(*) as count FROM admin_lock_history WHERE status = 'locked' ";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['lockedAccounts'] = $result->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    // Vacant Apartments
    $query = "SELECT COUNT(*) as count FROM apartments WHERE status = '1' AND (occupancy_status = 'NOT OCCUPIED' OR '')";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['vacantApartments'] = $result->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    // Overdue Payments
    $query = "SELECT COUNT(*) as count FROM payments 
              WHERE due_date < CURDATE() 
              AND payment_status IN ('pending', 'overdue') 
              AND status = 1";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['overduePayments'] = $result->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    $response['stats'] = $stats;

    // 2. GET RECENT ACTIVITIES
    $activities = [];
    $query = "
        (SELECT 'tenant_registered' as type, 
                CONCAT('New tenant: ', firstname) as description, 
                created_at as timestamp,
                'fas fa-user-plus' as icon,
                '#10b981' as color
         FROM tenants 
         WHERE status = 1 
         ORDER BY created_at DESC 
         LIMIT 2)
        
        UNION
        
        (SELECT 'payment_received' as type, 
                CONCAT('Payment: $', FORMAT(amount, 2), ' from ', 
                       (SELECT firstname FROM tenants WHERE tenants.id = payments.tenant_id)) as description,
                payment_date as timestamp,
                'fas fa-money-bill-wave' as icon,
                '#3b82f6' as color
         FROM payments 
         WHERE payment_status = 'completed' AND status = 1
         ORDER BY payment_date DESC 
         LIMIT 3)
        
        UNION
        
        (SELECT 'maintenance_request' as type, 
                CONCAT('Maintenance: ', description) as description,
                created_at as timestamp,
                'fas fa-tools' as icon,
                '#f59e0b' as color
         FROM maintenance_requests 
         WHERE status = 'pending' AND status = 1
         ORDER BY created_at DESC 
         LIMIT 2)
        
        UNION
        
        (SELECT 'account_locked' as type, 
                CONCAT('Account locked: ', email) as description,
                locked_at as timestamp,
                'fas fa-user-lock' as icon,
                '#ef4444' as color
         FROM users 
         WHERE account_locked = 1 AND status = 1
         ORDER BY locked_at DESC 
         LIMIT 1)
        
        UNION
        
        (SELECT 'account_reactivation' as type, 
                CONCAT('Reactivation requested: ', 
                       (SELECT email FROM users WHERE users.unique_id = account_reactivation_requests.user_id)) as description,
                created_at as timestamp,
                'fas fa-user-check' as icon,
                '#8b5cf6' as color
         FROM account_reactivation_requests 
         WHERE status = 'pending'
         ORDER BY created_at DESC 
         LIMIT 2)
        
        ORDER BY timestamp DESC 
        LIMIT 10
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $row['time_ago'] = getTimeAgo($row['timestamp']);
        $activities[] = $row;
    }
    $stmt->close();
    
    $response['activities'] = $activities;

    // 3. GET REVENUE DATA (for chart)
    $revenueData = [];
    $period = isset($_GET['period']) ? $_GET['period'] : 'monthly';
    
    if ($period === 'monthly') {
        $query = "
            SELECT 
                DATE_FORMAT(payment_date, '%Y-%m') as month,
                DATE_FORMAT(payment_date, '%b') as month_name,
                SUM(amount) as revenue
            FROM payments 
            WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                AND payment_status = 'completed'
                AND status = 1
            GROUP BY DATE_FORMAT(payment_date, '%Y-%m'), DATE_FORMAT(payment_date, '%b')
            ORDER BY month
        ";
    } elseif ($period === 'quarterly') {
        $query = "
            SELECT 
                CONCAT(YEAR(payment_date), '-Q', QUARTER(payment_date)) as quarter,
                SUM(amount) as revenue
            FROM payments 
            WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 4 QUARTER)
                AND payment_status = 'completed'
                AND status = 1
            GROUP BY YEAR(payment_date), QUARTER(payment_date)
            ORDER BY YEAR(payment_date), QUARTER(payment_date)
        ";
    } else { // yearly
        $query = "
            SELECT 
                YEAR(payment_date) as year,
                SUM(amount) as revenue
            FROM payments 
            WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 5 YEAR)
                AND payment_status = 'completed'
                AND status = 1
            GROUP BY YEAR(payment_date)
            ORDER BY YEAR(payment_date)
        ";
    }

    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $revenueData[] = $row;
    }
    $stmt->close();
    
    $response['revenueData'] = $revenueData;

    // 4. GET OCCUPANCY DATA
    $occupancyData = [];
    $query = "
        SELECT 
            COUNT(CASE WHEN occupancy_status = 'occupied' THEN 1 END) as occupied,
            COUNT(CASE WHEN occupancy_status = 'not occupied' THEN 1 END) as vacant,
            COUNT(CASE WHEN occupancy_status = 'maintenance' THEN 1 END) as maintenance,
            COUNT(CASE WHEN occupancy_status = 'reserved' THEN 1 END) as reserved
        FROM apartments 
        WHERE status = 1
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    $occupancyData = $result->fetch_assoc();
    $stmt->close();
    
    // Calculate percentages
    if ($occupancyData) {
        $total = array_sum($occupancyData);
        if ($total > 0) {
            foreach ($occupancyData as $key => $value) {
                $occupancyData[$key . '_percent'] = round(($value / $total) * 100, 1);
            }
        }
    }
    
    $response['occupancyData'] = $occupancyData;

    // 5. GET RECENT TRANSACTIONS
    $recentTransactions = [];
    $query = "
        SELECT 
            p.id,
            p.amount,
            p.payment_date,
            p.payment_status,
            t.firstname as tenant_name,
            pr.name,
            a.apartment_number
        FROM payments p
        LEFT JOIN tenants t ON p.tenant_id = t.id
        LEFT JOIN apartments a ON p.apartment_id = a.id
        LEFT JOIN properties pr ON a.property_code = pr.property_code
        WHERE p.status = 1
        ORDER BY p.payment_date DESC
        LIMIT 10
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        // Format amount
        $row['amount_formatted'] = '$' . number_format($row['amount'], 2);
        
        // Format date
        $row['payment_date_formatted'] = date('M d, Y', strtotime($row['payment_date']));
        
        // Status color
        $row['status_color'] = getPaymentStatusColor($row['payment_status']);
        
        $recentTransactions[] = $row;
    }
    $stmt->close();
    
    $response['recentTransactions'] = $recentTransactions;

    // 6. GET PENDING TASKS
    $pendingTasks = [];
    
    // Account reactivation requests
    $query = "
        SELECT 
            'account_reactivation' as type,
            COUNT(*) as count,
            'Review Account Reactivation Requests' as title,
            'High Priority' as priority,
            '#ef4444' as color
        FROM account_reactivation_requests 
        WHERE status = 'pending'
    ";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    $pendingTasks[] = $result->fetch_assoc();
    $stmt->close();

    // Overdue payments
    $query = "
        SELECT 
            'overdue_payments' as type,
            COUNT(*) as count,
            'Overdue Payments to Follow Up' as title,
            'High Priority' as priority,
            '#dc2626' as color
        FROM payments 
        WHERE due_date < CURDATE() 
            AND payment_status IN ('pending', 'overdue')
            AND status = 1
    ";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    $pendingTasks[] = $result->fetch_assoc();
    $stmt->close();

    // Contract renewals (expiring in next 30 days)
    $query = "
        SELECT 
            'contract_renewals' as type,
            COUNT(*) as count,
            'Contract Renewals Due Soon' as title,
            'Medium Priority' as priority,
            '#f59e0b' as color
        FROM tenant_contracts 
        WHERE end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            AND status = 'active'
            AND status = 1
    ";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    $pendingTasks[] = $result->fetch_assoc();
    $stmt->close();

    // Maintenance requests
    $query = "
        SELECT 
            'maintenance' as type,
            COUNT(*) as count,
            'Pending Maintenance Requests' as title,
            'Medium Priority' as priority,
            '#3b82f6' as color
        FROM maintenance_requests 
        WHERE status = 'pending'
            AND status = 1
    ";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    $pendingTasks[] = $result->fetch_assoc();
    $stmt->close();
    
    $response['pendingTasks'] = $pendingTasks;

    // 7. GET USER ROLE PERMISSIONS (for UI customization)
    $response['userPermissions'] = [
        'role' => $userRole,
        'canManageStaff' => in_array($userRole, ['Super Admin']),
        'canManageAccounts' => in_array($userRole, ['Super Admin']),
        'canViewFinancials' => true,
        'canManageProperties' => in_array($userRole, ['Super Admin', 'Admin']),
        'canApproveReactivation' => in_array($userRole, ['Super Admin'])
    ];

    echo json_encode($response);
    $conn->close();

} catch (Exception $e) {
    if (isset($conn) && $conn->connect_errno == 0) {
        $conn->close();
    }
    logActivity("Dashboard error: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "An unexpected error occurred.", "error" => $e->getMessage()]);
    exit();
}

// Helper Functions
function getTimeAgo($datetime) {
    $time = strtotime($datetime);
    $time_diff = time() - $time;
    
    if ($time_diff < 60) {
        return "Just now";
    } elseif ($time_diff < 3600) {
        $minutes = floor($time_diff / 60);
        return $minutes . " min" . ($minutes > 1 ? "s" : "") . " ago";
    } elseif ($time_diff < 86400) {
        $hours = floor($time_diff / 3600);
        return $hours . " hour" . ($hours > 1 ? "s" : "") . " ago";
    } elseif ($time_diff < 604800) {
        $days = floor($time_diff / 86400);
        return $days . " day" . ($days > 1 ? "s" : "") . " ago";
    } else {
        return date('M d, Y', $time);
    }
}

function getPaymentStatusColor($status) {
    $colors = [
        'completed' => '#10b981',
        'pending' => '#f59e0b',
        'overdue' => '#ef4444',
        'failed' => '#6b7280',
        'refunded' => '#8b5cf6'
    ];
    return $colors[$status] ?? '#6b7280';
}