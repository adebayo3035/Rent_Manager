<?php
// client/backend/agents/fetch_agent_details.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

$requestId = uniqid('agent_details_', true);
logActivity("[AGENT_DETAILS] [ID:{$requestId}] ========== FETCH AGENT DETAILS - START ==========");

try {
    if (!isset($_SESSION['client_logged_in']) || !isset($_SESSION['client_code'])) {
        json_error("Unauthorized", 401);
    }

    $client_code = $_SESSION['client_code'];
    $agent_code = isset($_GET['agent_code']) ? $_GET['agent_code'] : '';
    $property_code = isset($_GET['property_code']) ? $_GET['property_code'] : '';

    if (empty($agent_code)) {
        json_error("Agent code is required", 400);
    }

    logActivity("[AGENT_DETAILS] [ID:{$requestId}] Client: {$client_code}, Agent: {$agent_code}");

    // Verify this agent is assigned to a property owned by this client
    $verifyQuery = "
        SELECT COUNT(*) as count 
        FROM properties 
        WHERE client_code = ? AND agent_code = ? AND status = 1
    ";
    $verifyStmt = $conn->prepare($verifyQuery);
    $verifyStmt->bind_param("ss", $client_code, $agent_code);
    $verifyStmt->execute();
    $verifyResult = $verifyStmt->get_result()->fetch_assoc();
    $verifyStmt->close();

    if ($verifyResult['count'] == 0) {
        logActivity("[AGENT_DETAILS] [ID:{$requestId}] Unauthorized access - Agent not assigned to client's properties");
        json_error("Unauthorized access to agent details", 403);
    }

    // Get agent details
    $agentQuery = "
        SELECT 
            a.agent_code,
            a.firstname,
            a.lastname,
            a.email,
            a.phone,
            a.photo,
            a.avg_rating,
            a.total_ratings,
            a.status,
            a.date_created,
            COUNT(DISTINCT p.property_code) as managed_properties,
            COUNT(DISTINCT apt.apartment_code) as managed_units,
            SUM(CASE WHEN apt.occupancy_status = 'OCCUPIED' THEN 1 ELSE 0 END) as occupied_units
        FROM agents a
        LEFT JOIN properties p ON a.agent_code = p.agent_code AND p.client_code = ? AND p.status = 1
        LEFT JOIN apartments apt ON p.property_code = apt.property_code
        WHERE a.agent_code = ?
        GROUP BY a.agent_code
    ";

    $agentStmt = $conn->prepare($agentQuery);
    $agentStmt->bind_param("ss", $client_code, $agent_code);
    $agentStmt->execute();
    $agent = $agentStmt->get_result()->fetch_assoc();
    $agentStmt->close();

    if (!$agent) {
        json_error("Agent not found", 404);
    }

    $agent['fullname'] = $agent['firstname'] . ' ' . $agent['lastname'];
    $agent['vacant_units'] = $agent['managed_units'] - $agent['occupied_units'];
    $agent['occupancy_rate'] = $agent['managed_units'] > 0 ? round(($agent['occupied_units'] / $agent['managed_units']) * 100) : 0;
    $agent['avg_rating'] = $agent['avg_rating'] ? number_format($agent['avg_rating'], 1) : '0.0';

    // Get properties managed by this agent for this client
    $propertiesQuery = "
        SELECT 
            p.property_code,
            p.name as property_name,
            p.address,
            p.property_type_unit as property_capacity,
            COUNT(DISTINCT apt.apartment_code) as total_units,
            SUM(CASE WHEN apt.occupancy_status = 'OCCUPIED' THEN 1 ELSE 0 END) as occupied_units,
            (
                SELECT rating 
                FROM agent_ratings 
                WHERE client_code = ? 
                AND agent_code = ? 
                AND property_code = p.property_code
                LIMIT 1
            ) as my_rating,
            (
                SELECT comment 
                FROM agent_ratings 
                WHERE client_code = ? 
                AND agent_code = ? 
                AND property_code = p.property_code
                LIMIT 1
            ) as my_comment
        FROM properties p
        LEFT JOIN apartments apt ON p.property_code = apt.property_code
        WHERE p.client_code = ? AND p.agent_code = ? AND p.status = 1
        GROUP BY p.property_code
    ";

    $propsStmt = $conn->prepare($propertiesQuery);
    $propsStmt->bind_param("ssssss", $client_code, $agent_code, $client_code, $agent_code, $client_code, $agent_code);
    $propsStmt->execute();
    $properties = $propsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $propsStmt->close();

    // Get recent ratings for this agent
    $ratingsQuery = "
        SELECT 
            ar.rating,
            ar.comment,
            ar.created_at,
            ar.property_code,
            p.name as property_name,
            c.firstname as client_firstname,
            c.lastname as client_lastname
        FROM agent_ratings ar
        JOIN properties p ON ar.property_code = p.property_code
        JOIN clients c ON ar.client_code = c.client_code
        WHERE ar.agent_code = ?
        ORDER BY ar.created_at DESC
        LIMIT 10
    ";

    $ratingsStmt = $conn->prepare($ratingsQuery);
    $ratingsStmt->bind_param("s", $agent_code);
    $ratingsStmt->execute();
    $ratings = $ratingsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $ratingsStmt->close();

    logActivity("[AGENT_DETAILS] [ID:{$requestId}] Agent details retrieved successfully");
    logActivity("[AGENT_DETAILS] [ID:{$requestId}] ========== FETCH AGENT DETAILS - SUCCESS ==========");

    json_success([
        'agent' => $agent,
        'properties' => $properties,
        'ratings' => $ratings
    ], "Agent details retrieved successfully");

} catch (Exception $e) {
    logActivity("[AGENT_DETAILS] [ID:{$requestId}] ERROR: " . $e->getMessage());
    json_error("Failed to fetch agent details", 500);
}
?>