<?php
// client/backend/agents/rate_agent.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

$requestId = uniqid('rate_agent_', true);
logActivity("[RATE_AGENT] [ID:{$requestId}] ========== RATE AGENT - START ==========");

try {
    if (!isset($_SESSION['client_logged_in']) || !isset($_SESSION['client_code'])) {
        json_error("Unauthorized", 401);
    }

    $client_code = $_SESSION['client_code'];
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        json_error("Invalid input data", 400);
    }

    $agent_code = isset($input['agent_code']) ? $input['agent_code'] : '';
    $property_code = isset($input['property_code']) ? $input['property_code'] : '';
    $rating = isset($input['rating']) ? (int)$input['rating'] : 0;
    $comment = isset($input['comment']) ? trim($input['comment']) : '';

    if (empty($agent_code) || empty($property_code)) {
        json_error("Agent code and property code are required", 400);
    }

    if ($rating < 1 || $rating > 5) {
        json_error("Rating must be between 1 and 5", 400);
    }

    logActivity("[RATE_AGENT] [ID:{$requestId}] Client: {$client_code}, Agent: {$agent_code}, Property: {$property_code}, Rating: {$rating}");

    // Verify property belongs to client and agent is assigned
    $verifyQuery = "
        SELECT property_code, agent_code 
        FROM properties 
        WHERE property_code = ? AND client_code = ? AND status = 1
    ";
    $verifyStmt = $conn->prepare($verifyQuery);
    $verifyStmt->bind_param("ss", $property_code, $client_code);
    $verifyStmt->execute();
    $property = $verifyStmt->get_result()->fetch_assoc();
    $verifyStmt->close();

    if (!$property) {
        logActivity("[RATE_AGENT] [ID:{$requestId}] Property not found or not owned by client");
        json_error("Property not found or not owned by you", 404);
    }

    if ($property['agent_code'] !== $agent_code) {
        logActivity("[RATE_AGENT] [ID:{$requestId}] Agent not assigned to this property");
        json_error("This agent is not assigned to the specified property", 403);
    }

    // Insert or update rating
    $upsertQuery = "
        INSERT INTO agent_ratings (client_code, agent_code, property_code, rating, comment, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE 
            rating = VALUES(rating),
            comment = VALUES(comment),
            updated_at = NOW()
    ";

    $upsertStmt = $conn->prepare($upsertQuery);
    $upsertStmt->bind_param("sssis", $client_code, $agent_code, $property_code, $rating, $comment);
    $upsertStmt->execute();
    $upsertStmt->close();

    logActivity("[RATE_AGENT] [ID:{$requestId}] Rating saved successfully");

    // Get updated agent average rating
    $avgQuery = "SELECT avg_rating, total_ratings FROM agents WHERE agent_code = ?";
    $avgStmt = $conn->prepare($avgQuery);
    $avgStmt->bind_param("s", $agent_code);
    $avgStmt->execute();
    $agentStats = $avgStmt->get_result()->fetch_assoc();
    $avgStmt->close();

    logActivity("[RATE_AGENT] [ID:{$requestId}] ========== RATE AGENT - SUCCESS ==========");

    json_success([
        'agent_code' => $agent_code,
        'property_code' => $property_code,
        'rating' => $rating,
        'avg_rating' => number_format($agentStats['avg_rating'], 1),
        'total_ratings' => $agentStats['total_ratings']
    ], "Rating submitted successfully");

} catch (Exception $e) {
    logActivity("[RATE_AGENT] [ID:{$requestId}] ERROR: " . $e->getMessage());
    json_error("Failed to submit rating", 500);
}
?>