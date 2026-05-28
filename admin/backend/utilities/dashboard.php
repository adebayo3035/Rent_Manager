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
        "pendingTasks" => [],
        "timestamp" => date('Y-m-d H:i:s')
    ];

    // ==================== 1. GET DASHBOARD STATISTICS ====================
    $stats = [];

    // Active Tenants (status = 1 means active)
    $query = "SELECT COUNT(*) as count FROM tenants WHERE tenant_status = '1' AND deleted_at IS NULL";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['activeTenants'] = $result->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    // Monthly Revenue (current month) - using rent_payment_tracker or payments table
    $currentMonth = date('Y-m');
    $query = "SELECT SUM(amount_paid) as total FROM rent_payment_tracker 
              WHERE DATE_FORMAT(payment_date, '%Y-%m') = ? 
              AND status IN ('paid', 'approved')";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $currentMonth);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['monthlyRevenue'] = $result->fetch_assoc()['total'] ?? 0;
    $stmt->close();

    // Total Properties
    $query = "SELECT COUNT(*) as count FROM properties WHERE status = 1";
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
        'clients' => "status = 1",
        'agents' => "status = 1",
        'tenants' => "tenant_status = '1' AND deleted_at IS NULL",
        'properties' => "status = 1",
        'apartments' => "status = 1",
        'admin_tbl' => "status = 1"
    ];

    foreach ($tables as $table => $condition) {
        $query = "SELECT COUNT(*) as count FROM $table WHERE $condition";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $result = $stmt->get_result();
        $stats[$table] = $result->fetch_assoc()['count'] ?? 0;
        $stmt->close();
    }

    // Locked Accounts (from admin_lock_history)
    $query = "SELECT COUNT(*) as count FROM admin_lock_history WHERE status = 'locked'";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['lockedAccounts'] = $result->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    // Vacant Apartments
    $query = "SELECT COUNT(*) as count FROM apartments WHERE status = 1 AND (occupancy_status = 'NOT OCCUPIED' OR occupancy_status = 'vacant')";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['vacantApartments'] = $result->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    // Overdue Payments (from rent_payment_tracker)
    $query = "SELECT COUNT(*) as count FROM rent_payments 
              WHERE due_date < CURDATE() 
              AND status = 'pending'";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['overduePayments'] = $result->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    $response['stats'] = $stats;

    // ==================== 2. GET RECENT ACTIVITIES ====================
    $activities = [];
    
    // Fetch from activity_log table if exists, otherwise use individual queries
    $query = "
        (SELECT 'tenant_registered' as type, 
                CONCAT('New tenant: ', t.firstname, ' ', t.lastname) as description, 
                t.created_at as timestamp,
                'fas fa-user-plus' as icon,
                '#10b981' as color
         FROM tenants t
         WHERE t.deleted_at IS NULL 
         ORDER BY t.created_at DESC 
         LIMIT 3)
        
        UNION ALL
        
        (SELECT 'payment_received' as type, 
                CONCAT('Payment: ₦', FORMAT(rpt.amount_paid, 2), ' from ', t.firstname, ' ', t.lastname) as description,
                rpt.payment_date as timestamp,
                'fas fa-money-bill-wave' as icon,
                '#3b82f6' as color
         FROM rent_payment_tracker rpt
         JOIN tenants t ON rpt.tenant_code = t.tenant_code
         WHERE rpt.status IN ('paid', 'approved')
         ORDER BY rpt.payment_date DESC 
         LIMIT 3)
        
        UNION ALL
        
        (SELECT 'maintenance_request' as type, 
                CONCAT('Maintenance: ', mr.issue_type) as description,
                mr.created_at as timestamp,
                'fas fa-tools' as icon,
                '#f59e0b' as color
         FROM maintenance_requests mr
         WHERE mr.status = 'pending'
         ORDER BY mr.created_at DESC 
         LIMIT 3)
        
        UNION ALL
        
        (SELECT 'account_reactivation' as type, 
                CONCAT('Reactivation requested for: ', ar.user_id) as description,
                ar.created_at as timestamp,
                'fas fa-user-check' as icon,
                '#8b5cf6' as color
         FROM account_reactivation_requests ar
         WHERE ar.status = 'pending'
         ORDER BY ar.created_at DESC 
         LIMIT 3)
        
        ORDER BY timestamp DESC 
        LIMIT 12
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

    // ==================== 3. GET REVENUE DATA (for chart) ====================
    $revenueData = [];
    $period = isset($_GET['period']) ? $_GET['period'] : 'monthly';
    
    if ($period === 'monthly') {
        $query = "
            SELECT 
                DATE_FORMAT(payment_date, '%Y-%m') as period,
                DATE_FORMAT(payment_date, '%b %Y') as period_name,
                SUM(amount_paid) as revenue
            FROM rent_payment_tracker 
            WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                AND status IN ('paid', 'approved')
            GROUP BY DATE_FORMAT(payment_date, '%Y-%m'), DATE_FORMAT(payment_date, '%b %Y')
            ORDER BY period
        ";
    } elseif ($period === 'quarterly') {
        $query = "
            SELECT 
                CONCAT(YEAR(payment_date), '-Q', QUARTER(payment_date)) as period,
                CONCAT('Q', QUARTER(payment_date), ' ', YEAR(payment_date)) as period_name,
                SUM(amount_paid) as revenue
            FROM rent_payment_tracker 
            WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 4 QUARTER)
                AND status IN ('paid', 'approved')
            GROUP BY YEAR(payment_date), QUARTER(payment_date)
            ORDER BY YEAR(payment_date), QUARTER(payment_date)
        ";
    } else { // yearly
        $query = "
            SELECT 
                YEAR(payment_date) as period,
                YEAR(payment_date) as period_name,
                SUM(amount_paid) as revenue
            FROM rent_payment_tracker 
            WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 5 YEAR)
                AND status IN ('paid', 'approved')
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

    // ==================== 4. GET OCCUPANCY DATA ====================
    $occupancyData = [];
    $query = "
        SELECT 
            COUNT(CASE WHEN occupancy_status = 'OCCUPIED' THEN 1 END) as occupied,
            COUNT(CASE WHEN occupancy_status = 'NOT OCCUPIED' THEN 1 END) as vacant,
            COUNT(CASE WHEN occupancy_status = 'MAINTENANCE' THEN 1 END) as maintenance,
            COUNT(CASE WHEN occupancy_status = 'RESERVED' THEN 1 END) as reserved
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
        $total = ($occupancyData['occupied'] ?? 0) + ($occupancyData['vacant'] ?? 0) + 
                 ($occupancyData['maintenance'] ?? 0) + ($occupancyData['reserved'] ?? 0);
        if ($total > 0) {
            foreach ($occupancyData as $key => $value) {
                $occupancyData[$key . '_percent'] = round(($value / $total) * 100, 1);
            }
        }
        $occupancyData['total_units'] = $total;
        $occupancyData['occupancy_rate'] = $total > 0 ? round(($occupancyData['occupied'] / $total) * 100, 1) : 0;
    }
    
    $response['occupancyData'] = $occupancyData;

    // ==================== 5. GET RECENT TRANSACTIONS ====================
    $recentTransactions = [];
    $query = "
        SELECT 
            rpt.tracker_id as id,
            rpt.amount_paid as amount,
            rpt.payment_date,
            rpt.status as payment_status,
            rpt.payment_method,
            rpt.payment_reference,
            t.firstname,
            t.lastname,
            CONCAT(t.firstname, ' ', t.lastname) as tenant_name,
            p.name as property_name,
            a.apartment_number
        FROM rent_payment_tracker rpt
        INNER JOIN tenants t ON rpt.tenant_code = t.tenant_code
        INNER JOIN apartments a ON rpt.apartment_code = a.apartment_code
        INNER JOIN properties p ON a.property_code = p.property_code
        WHERE t.deleted_at IS NULL
        ORDER BY rpt.payment_date DESC
        LIMIT 10
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        // Format amount
        $row['amount_formatted'] = '₦' . number_format($row['amount'], 2);
        
        // Format date
        $row['payment_date_formatted'] = date('M d, Y', strtotime($row['payment_date']));
        
        // Status color
        $row['status_color'] = getPaymentStatusColor($row['payment_status']);
        $row['status_badge'] = getPaymentStatusBadge($row['payment_status']);
        
        $recentTransactions[] = $row;
    }
    $stmt->close();
    
    $response['recentTransactions'] = $recentTransactions;

    // ==================== 6. GET PENDING TASKS ====================
    $pendingTasks = [];
    
    // Account reactivation requests
    $query = "
        SELECT 
            'account_reactivation' as type,
            COUNT(*) as count,
            'Review Account Reactivation Requests' as title,
            'High' as priority,
            '#ef4444' as color,
            '/admin/account_reactivations.php' as link
        FROM account_reactivation_requests 
        WHERE status = 'pending'
    ";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    $task = $result->fetch_assoc();
    if ($task && $task['count'] > 0) {
        $pendingTasks[] = $task;
    }
    $stmt->close();

    // Overdue payments
    $query = "
        SELECT 
            'overdue_payments' as type,
            COUNT(*) as count,
            'Overdue Payments to Follow Up' as title,
            'High' as priority,
            '#dc2626' as color,
            '/admin/payments.php?filter=overdue' as link
        FROM rent_payments 
        WHERE due_date < CURDATE() 
            AND status = 'pending'
    ";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    $task = $result->fetch_assoc();
    if ($task && $task['count'] > 0) {
        $pendingTasks[] = $task;
    }
    $stmt->close();

    // Pending maintenance requests
    $query = "
        SELECT 
            'maintenance' as type,
            COUNT(*) as count,
            'Pending Maintenance Requests' as title,
            'Medium' as priority,
            '#f59e0b' as color,
            '/admin/maintenance.php?status=pending' as link
        FROM maintenance_requests 
        WHERE status = 'pending'
    ";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    $task = $result->fetch_assoc();
    if ($task && $task['count'] > 0) {
        $pendingTasks[] = $task;
    }
    $stmt->close();

    // Low balance/empty properties (optional)
    $query = "
        SELECT 
            'vacant_properties' as type,
            COUNT(*) as count,
            'Vacant Apartments Ready for Rent' as title,
            'Low' as priority,
            '#10b981' as color,
            '/admin/apartments.php?filter=vacant' as link
        FROM apartments 
        WHERE status = 1 AND occupancy_status = 'NOT OCCUPIED'
    ";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    $task = $result->fetch_assoc();
    if ($task && $task['count'] > 0) {
        $pendingTasks[] = $task;
    }
    $stmt->close();
    
    $response['pendingTasks'] = $pendingTasks;

    // ==================== 7. GET USER ROLE PERMISSIONS ====================
    $response['userPermissions'] = [
        'role' => $userRole,
        'canManageStaff' => in_array($userRole, ['Super Admin']),
        'canManageAccounts' => in_array($userRole, ['Super Admin']),
        'canViewFinancials' => true,
        'canManageProperties' => in_array($userRole, ['Super Admin', 'Admin']),
        'canApproveReactivation' => in_array($userRole, ['Super Admin'])
    ];

    // ==================== 8. ADD SYSTEM HEALTH METRICS (Optional) ====================
    $response['systemHealth'] = [
        'database_size' => getDatabaseSize($conn),
        'last_backup' => getLastBackupDate(),
        'total_users' => ($stats['admin_tbl'] ?? 0) + ($stats['agents'] ?? 0) + ($stats['clients'] ?? 0) + ($stats['tenants'] ?? 0)
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

// ==================== HELPER FUNCTIONS ====================

function getTimeAgo($datetime) {
    if (!$datetime) return "Unknown";
    
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
        'paid' => '#10b981',
        'approved' => '#10b981',
        'pending' => '#f59e0b',
        'pending_verification' => '#f59e0b',
        'failed' => '#ef4444',
        'rejected' => '#ef4444',
        'refunded' => '#8b5cf6'
    ];
    return $colors[$status] ?? '#6b7280';
}

function getPaymentStatusBadge($status) {
    $badges = [
        'paid' => 'success',
        'approved' => 'success',
        'pending' => 'warning',
        'pending_verification' => 'warning',
        'failed' => 'danger',
        'rejected' => 'danger',
        'refunded' => 'info'
    ];
    return $badges[$status] ?? 'secondary';
}

function getDatabaseSize($conn) {
    $query = "SELECT SUM(data_length + index_length) as size 
              FROM information_schema.tables 
              WHERE table_schema = DATABASE()";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    $size = $result->fetch_assoc()['size'] ?? 0;
    $stmt->close();
    
    if ($size < 1024) {
        return $size . " bytes";
    } elseif ($size < 1048576) {
        return round($size / 1024, 2) . " KB";
    } elseif ($size < 1073741824) {
        return round($size / 1048576, 2) . " MB";
    } else {
        return round($size / 1073741824, 2) . " GB";
    }
}

function getLastBackupDate() {
    // This would need a backup_logs table or file system check
    // For now, return a placeholder
    $backupDir = __DIR__ . '/../../backups/';
    if (is_dir($backupDir)) {
        $files = glob($backupDir . '*.sql');
        if (!empty($files)) {
            $latest = max($files);
            return date('Y-m-d H:i:s', filemtime($latest));
        }
    }
    return 'No backups found';
}
?>