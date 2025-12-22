<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

// Optional: rate limiting (same style as onboarding)
rateLimit("fetch_agent_property_type", 10, 60); 
// 10 requests per 60 seconds for safety

try {
    // Fetch all agents
    $agentQuery = "SELECT agent_code, firstname, lastname FROM agents WHERE status = 1 ORDER BY firstname ASC";
    $agentResult = $conn->query($agentQuery);

    $agents = [];
    while ($row = $agentResult->fetch_assoc()) {
        $agents[] = $row;
    }

    // Fetch all clients
    $clientQuery = "SELECT client_code, firstname, lastname FROM clients WHERE status = 1 ORDER BY firstname ASC";
    $clientResult = $conn->query($clientQuery);

    $clients = [];
    while ($row = $clientResult->fetch_assoc()) {
        $clients[] = $row;
    }

    // Fetch all property types
    $typeQuery = "SELECT type_id, type_name FROM property_type where status = 1 ORDER BY type_name ASC";
    $typeResult = $conn->query($typeQuery);

    $property_types = [];
    while ($row = $typeResult->fetch_assoc()) {
        $property_types[] = $row;
    }

    

    // Final output
    echo json_encode([
        "response_code" => 200,
        "message" => "Success",
        "data" => [
            "agents" => $agents,
            "clients" => $clients,
            "property_types" => $property_types
        ]
    ]);

    logActivity("Support data fetched successfully");

} catch (Exception $e) {
    logActivity("Error pulling support data: " . $e->getMessage());

    echo json_encode([
        "response_code" => 500,
        "message" => "An error occurred fetching support data"
    ]);
}
