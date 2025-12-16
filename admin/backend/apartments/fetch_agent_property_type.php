<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

// Rate limiting
rateLimit("fetch_agent_property_type", 10, 60);

try {

    /* ==========================
       Fetch Agents
    =========================== */
    $agentQuery = "
        SELECT agent_code, firstname, lastname
        FROM agents WHERE status = '1'
        ORDER BY firstname ASC
    ";
    $agentResult = $conn->query($agentQuery);

    $agents = [];
    while ($row = $agentResult->fetch_assoc()) {
        $agents[] = $row;
    }

    /* ==========================
       Fetch Property Types
    =========================== */
    $typeQuery = "
        SELECT type_id, type_name
        FROM property_type WHERE status = '1'
        ORDER BY type_name ASC
    ";
    $typeResult = $conn->query($typeQuery);

    $property_types = [];
    while ($row = $typeResult->fetch_assoc()) {
        $property_types[] = $row;
    }

    /* ==========================
       Fetch Properties
       (Address merged)
    =========================== */
    $propertyQuery = "
        SELECT 
            property_code,
            name,
            CONCAT_WS(', ',
                address,
                city,
                state,
                country
            ) AS address
        FROM properties
        WHERE status = '1'
        ORDER BY name ASC
    ";
    $propertyResult = $conn->query($propertyQuery);

    $properties = [];
    while ($row = $propertyResult->fetch_assoc()) {
        $properties[] = $row;
    }

    /* ==========================
       Final Response
    =========================== */
    echo json_encode([
        "response_code" => 200,
        "message" => "Success",
        "data" => [
            "agents" => $agents,
            "property_types" => $property_types,
            "properties" => $properties
        ]
    ]);

    logActivity("Support data (agents, property types, properties) fetched successfully");

} catch (Exception $e) {

    logActivity("Error pulling support data: " . $e->getMessage());

    echo json_encode([
        "response_code" => 500,
        "message" => "An error occurred fetching support data"
    ]);
}
