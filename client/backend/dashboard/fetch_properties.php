<?php
// client/backend/dashboard/fetch_properties.php

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
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;

    $query = "
        SELECT 
            p.property_code,
            p.name as property_name,
            p.address,
            p.city,
            p.state,
            p.country,
            GREATEST(COALESCE(p.property_type_unit, 0), COUNT(a.apartment_code)) as total_units,
            COALESCE(SUM(CASE WHEN a.occupancy_status = 'OCCUPIED' THEN 1 ELSE 0 END), 0) as occupied_units
        FROM properties p
        LEFT JOIN apartments a ON p.property_code = a.property_code
        WHERE p.client_code = ? AND p.status = 1
        GROUP BY p.property_code, p.name, p.address, p.property_type_unit
        ORDER BY p.name ASC
        LIMIT ?
    ";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("si", $client_code, $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $properties = [];
    while ($row = $result->fetch_assoc()) {
        $row['vacant_units'] = $row['total_units'] - $row['occupied_units'];
        $row['occupancy_rate'] = $row['total_units'] > 0 ? round(($row['occupied_units'] / $row['total_units']) * 100) : 0;
        $row['property_address'] = implode(', ', array_filter([
            $row['address'],
            $row['city'],
            $row['state'],
            $row['country']
        ]));
        $properties[] = $row;
    }
    $stmt->close();

    json_success(['properties' => $properties], "Properties retrieved");

} catch (Exception $e) {
    logActivity("Error fetching properties: " . $e->getMessage());
    json_error("Failed to fetch properties", 500);
}
?>