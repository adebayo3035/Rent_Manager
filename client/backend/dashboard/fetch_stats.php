<?php
// client/backend/dashboard/fetch_stats.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

try {
    if (!isset($_SESSION['client_logged_in']) || !isset($_SESSION['client_code'])) {
        json_error("Unauthorized", 401);
    }
    
    $client_code = $_SESSION['client_code'];
    
    // Get client name
    $clientQuery = "SELECT firstname, lastname FROM clients WHERE client_code = ?";
    $clientStmt = $conn->prepare($clientQuery);
    $clientStmt->bind_param("s", $client_code);
    $clientStmt->execute();
    $client = $clientStmt->get_result()->fetch_assoc();
    $clientStmt->close();
    
    $client_name = ($client['firstname'] ?? '') . ' ' . ($client['lastname'] ?? '');
    
    // Get property stats
    $query = "
        SELECT 
            COUNT(p.property_code) as total_properties,
            COALESCE(SUM(p.property_type_unit), 0) as total_units,
            COALESCE((
                SELECT COUNT(a.apartment_code)
                FROM apartments a
                JOIN properties ap ON a.property_code = ap.property_code
                WHERE ap.client_code = ? 
                AND ap.status = 1 
                AND a.occupancy_status = 'OCCUPIED'
            ), 0) as occupied_units
        FROM properties p
        WHERE p.client_code = ? AND p.status = 1
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $client_code, $client_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats = $result->fetch_assoc();
    $stmt->close();
    
    $total_units = (int)($stats['total_units'] ?? 0);
    $occupied_units = (int)($stats['occupied_units'] ?? 0);
    $occupancy_rate = $total_units > 0 ? round(($occupied_units / $total_units) * 100) : 0;
    
    json_success([
        'client_name' => $client_name,
        'total_properties' => (int)($stats['total_properties'] ?? 0),
        'total_units' => $total_units,
        'occupied_units' => $occupied_units,
        'vacant_units' => $total_units - $occupied_units,
        'occupancy_rate' => $occupancy_rate
    ], "Dashboard stats retrieved");
    
} catch (Exception $e) {
    logActivity("Error fetching dashboard stats: " . $e->getMessage());
    json_error("Failed to fetch dashboard stats", 500);
}
?>
