<?php
// client/backend/agents/fetch_property_agents.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

$requestId = uniqid('client_agents_', true);
logActivity("[CLIENT_AGENTS] [ID:{$requestId}] ========== FETCH PROPERTY AGENTS - START ==========");

try {
    // Check authentication
    if (!isset($_SESSION['client_logged_in']) || !isset($_SESSION['client_code'])) {
        json_error("Unauthorized", 401);
    }

    $client_code = $_SESSION['client_code'];
    logActivity("[CLIENT_AGENTS] [ID:{$requestId}] Client Code: {$client_code}");

    // Get all properties with their agents for this client
    $query = "
        SELECT 
            p.property_code,
            p.name as property_name,
            p.address,
            p.status,
            p.property_type_unit as property_unit,
            a.agent_code,
            a.firstname as agent_firstname,
            a.lastname as agent_lastname,
            a.email as agent_email,
            a.phone as agent_phone,
            a.photo as agent_photo,
            a.avg_rating,
            a.total_ratings,
            COUNT(DISTINCT apt.apartment_code) as total_units,
            SUM(CASE WHEN apt.occupancy_status = 'OCCUPIED' THEN 1 ELSE 0 END) as occupied_units,
            (
                SELECT rating 
                FROM agent_ratings 
                WHERE client_code = ? 
                AND agent_code = a.agent_code 
                AND property_code = p.property_code
                LIMIT 1
            ) as my_rating,
            (
                SELECT comment 
                FROM agent_ratings 
                WHERE client_code = ? 
                AND agent_code = a.agent_code 
                AND property_code = p.property_code
                LIMIT 1
            ) as my_comment
        FROM properties p
        LEFT JOIN agents a ON p.agent_code = a.agent_code
        LEFT JOIN apartments apt ON p.property_code = apt.property_code
        WHERE p.client_code = ? AND p.status = 1
        GROUP BY p.property_code, a.agent_code
        ORDER BY p.name ASC
    ";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("sss", $client_code, $client_code, $client_code);
    $stmt->execute();
    $result = $stmt->get_result();

    $properties = [];
    while ($row = $result->fetch_assoc()) {
        $row['agent_name'] = $row['agent_firstname'] . ' ' . $row['agent_lastname'];
        $row['vacant_units'] = $row['total_units'] - $row['occupied_units'];
        $row['occupancy_rate'] = $row['total_units'] > 0 ? round(($row['occupied_units'] / $row['total_units']) * 100) : 0;
        $row['avg_rating'] = $row['avg_rating'] ? number_format($row['avg_rating'], 1) : '0.0';
        $properties[] = $row;
    }
    $stmt->close();

    logActivity("[CLIENT_AGENTS] [ID:{$requestId}] Found " . count($properties) . " properties with agents");
    logActivity("[CLIENT_AGENTS] [ID:{$requestId}] ========== FETCH PROPERTY AGENTS - SUCCESS ==========");

    json_success(['properties' => $properties], "Properties and agents retrieved successfully");

} catch (Exception $e) {
    logActivity("[CLIENT_AGENTS] [ID:{$requestId}] ERROR: " . $e->getMessage());
    json_error("Failed to fetch properties and agents", 500);
}
?>