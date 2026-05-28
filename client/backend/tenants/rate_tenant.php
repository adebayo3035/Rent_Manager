<?php
// client/backend/tenants/rate_tenant.php

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
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        json_error("Invalid input data", 400);
    }
    
    $tenant_code = isset($input['tenant_code']) ? trim($input['tenant_code']) : '';
    $rating = isset($input['rating']) ? (int)$input['rating'] : 0;
    $comment = isset($input['comment']) ? trim($input['comment']) : '';
    $category = isset($input['category']) ? trim($input['category']) : 'overall';
    
    // Validate inputs
    if (empty($tenant_code)) {
        json_error("Tenant code is required", 400);
    }
    
    if ($rating < 1 || $rating > 5) {
        json_error("Rating must be between 1 and 5", 400);
    }
    
    $allowed_categories = ['payment', 'behavior', 'cleanliness', 'maintenance', 'overall'];
    if (!in_array($category, $allowed_categories)) {
        $category = 'overall';
    }
    
    // Verify tenant belongs to client's property
    $verifyQuery = "
        SELECT t.tenant_code
        FROM tenants t
        INNER JOIN apartments a ON t.apartment_code = a.apartment_code
        INNER JOIN properties p ON a.property_code = p.property_code
        WHERE t.tenant_code = ? AND p.client_code = ?
        LIMIT 1
    ";
    $verifyStmt = $conn->prepare($verifyQuery);
    $verifyStmt->bind_param("ss", $tenant_code, $client_code);
    $verifyStmt->execute();
    
    if ($verifyStmt->get_result()->num_rows === 0) {
        json_error("Tenant not found or not associated with your properties", 404);
    }
    $verifyStmt->close();
    
    // Get property and apartment info
    $infoQuery = "
        SELECT p.property_code, a.apartment_code
        FROM tenants t
        INNER JOIN apartments a ON t.apartment_code = a.apartment_code
        INNER JOIN properties p ON a.property_code = p.property_code
        WHERE t.tenant_code = ?
        LIMIT 1
    ";
    $infoStmt = $conn->prepare($infoQuery);
    $infoStmt->bind_param("s", $tenant_code);
    $infoStmt->execute();
    $info = $infoStmt->get_result()->fetch_assoc();
    $infoStmt->close();
    
    // Insert or update rating
    $upsertQuery = "
        INSERT INTO tenant_ratings (client_code, tenant_code, property_code, apartment_code, rating, comment, category)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            rating = VALUES(rating),
            comment = VALUES(comment),
            updated_at = NOW()
    ";
    
    $stmt = $conn->prepare($upsertQuery);
    $stmt->bind_param("ssssiss", $client_code, $tenant_code, $info['property_code'], $info['apartment_code'], $rating, $comment, $category);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to save rating: " . $stmt->error);
    }
    
    $stmt->close();
    
    // Get updated average rating
    $avgQuery = "
        SELECT AVG(rating) as avg_rating, COUNT(*) as rating_count
        FROM tenant_ratings
        WHERE tenant_code = ?
    ";
    $avgStmt = $conn->prepare($avgQuery);
    $avgStmt->bind_param("s", $tenant_code);
    $avgStmt->execute();
    $avgResult = $avgStmt->get_result()->fetch_assoc();
    $avgStmt->close();
    
    logActivity("Client {$client_code} rated tenant {$tenant_code} with {$rating} stars for category {$category}");
    
    json_success([
        'message' => 'Rating submitted successfully',
        'avg_rating' => round($avgResult['avg_rating'], 1),
        'rating_count' => $avgResult['rating_count']
    ], "Rating saved successfully");
    
} catch (Exception $e) {
    logActivity("Error rating tenant: " . $e->getMessage());
    json_error("Failed to save rating: " . $e->getMessage(), 500);
}