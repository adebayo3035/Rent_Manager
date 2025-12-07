<?php
session_start();
require "config.php"; // Ensure logActivity function is available

// Check if user is authenticated
if (!isset($_SESSION['role'])) {
    logActivity("UNAUTHENTICATED_ACCESS: Attempted to fetch menu without login.");
    echo json_encode(['error' => 'Unauthenticated']);
    exit();
}

$role = $_SESSION['role'];
$menuItems = [];

// Log the role identified
logActivity("MENU_ACCESS_INITIATED: Role - $role");

// Common links for all roles
$menuItems[] = ['name' => 'Manage Orders', 'link' => 'dashboard.php', 'id' => 'orderFood'];
$menuItems[] = ['name' => 'View Pending Orders', 'link' => 'pending_orders.php', 'id' => 'viewOrders'];
$menuItems[] = ['name' => 'All Orders', 'link' => 'orders.php', 'id' => 'allOrder'];

// Role-based links
if ($role === 'Super Admin') {
    $menuItems[] = ['name' => 'Manage Revenue', 'link' => 'revenue_dashboard.php', 'id' => 'fundWallet'];
    // $menuItems[] = ['name' => 'All Orders', 'link' => 'orders.php', 'id' => 'allOrder'];
    $menuItems[] = ['name' => 'Transactions', 'link' => 'transactions.php', 'id' => 'allTransactions'];
    $menuItems[] = ['name' => 'Inflow and Outflow', 'link' => 'revenue.php', 'id' => 'allRevenues'];
    $menuItems[] = ['name' => 'Promos', 'link' => 'promo.php', 'id' => 'promo'];
    $menuItems[] = ['name' => 'Credit History', 'link' => 'credit_history.php', 'id' => 'creditHistory'];

    logActivity("MENU_ITEMS_LOADED: Super Admin menu items loaded.");
} else {
    logActivity("MENU_ITEMS_LOADED: Admin menu items loaded.");
}

// Output the menu
echo json_encode(['menuItems' => $menuItems]);
logActivity("MENU_RESPONSE_SENT: Menu items successfully sent for role - $role");
