<?php
// client/backend/tenants/rate_tenant.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

// ==================== CONFIGURATION ====================
define('RATING_INTERVAL_HOURS', 24);
define('ALLOW_UPDATE_RATING', true);
define('MAX_RATING_PER_CATEGORY_PER_DAY', 1);

// Enable detailed logging
define('DEBUG_MODE', true);

try {
    // Log start of request
    logActivity("=== RATING REQUEST STARTED ===");
    logActivity("Client IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    logActivity("Request Method: " . $_SERVER['REQUEST_METHOD']);
    
    // Authentication check
    if (!isset($_SESSION['client_logged_in']) || !isset($_SESSION['client_code'])) {
        logActivity("UNAUTHORIZED: Client not logged in or session missing");
        json_error("Unauthorized", 401);
    }
    
    $client_code = $_SESSION['client_code'];
    logActivity("Client Code: {$client_code}");
    
    // Get and parse input
    $input = json_decode(file_get_contents('php://input'), true);
    logActivity("Input data: " . json_encode($input));
    
    if (!$input) {
        logActivity("ERROR: Invalid input data - " . json_last_error_msg());
        json_error("Invalid input data", 400);
    }
    
    // Extract and validate inputs
    $tenant_code = isset($input['tenant_code']) ? trim($input['tenant_code']) : '';
    $rating = isset($input['rating']) ? (int)$input['rating'] : 0;
    $comment = isset($input['comment']) ? trim($input['comment']) : '';
    $category = isset($input['category']) ? trim($input['category']) : 'overall';
    $force_update = isset($input['force_update']) ? (bool)$input['force_update'] : false;
    
    logActivity("Parsed inputs - Tenant: {$tenant_code}, Rating: {$rating}, Category: {$category}, Force: " . ($force_update ? 'Yes' : 'No'));
    
    // Validate tenant code
    if (empty($tenant_code)) {
        logActivity("ERROR: Tenant code is empty");
        json_error("Tenant code is required", 400);
    }
    
    // Validate rating
    if ($rating < 1 || $rating > 5) {
        logActivity("ERROR: Invalid rating value - {$rating}");
        json_error("Rating must be between 1 and 5", 400);
    }
    
    // Validate category
    $allowed_categories = ['payment', 'behavior', 'cleanliness', 'maintenance', 'overall'];
    if (!in_array($category, $allowed_categories)) {
        logActivity("WARNING: Invalid category '{$category}', defaulting to 'overall'");
        $category = 'overall';
    }
    
    // ==================== VERIFY TENANT ====================
    logActivity("Verifying tenant belongs to client...");
    
    $verifyQuery = "
        SELECT t.tenant_code, p.property_code, a.apartment_code, t.firstname, t.lastname
        FROM tenants t
        INNER JOIN apartments a ON t.apartment_code = a.apartment_code
        INNER JOIN properties p ON a.property_code = p.property_code
        WHERE t.tenant_code = ? AND p.client_code = ?
        LIMIT 1
    ";
    $verifyStmt = $conn->prepare($verifyQuery);
    $verifyStmt->bind_param("ss", $tenant_code, $client_code);
    $verifyStmt->execute();
    $verifyResult = $verifyStmt->get_result();
    
    if ($verifyResult->num_rows === 0) {
        logActivity("ERROR: Tenant {$tenant_code} not found or not associated with client {$client_code}");
        json_error("Tenant not found or not associated with your properties", 404);
    }
    $tenantInfo = $verifyResult->fetch_assoc();
    $verifyStmt->close();
    
    logActivity("Tenant verified: {$tenantInfo['firstname']} {$tenantInfo['lastname']} (Property: {$tenantInfo['property_code']})");
    
    // ==================== CHECK RATING HISTORY ====================
    logActivity("Checking rating history for tenant {$tenant_code}, category {$category}...");
    
    $checkQuery = "
        SELECT 
            id,
            rating as previous_rating,
            comment as previous_comment,
            created_at,
            updated_at,
            TIMESTAMPDIFF(HOUR, updated_at, NOW()) as hours_since_rating,
            TIMESTAMPDIFF(MINUTE, updated_at, NOW()) as minutes_since_rating,
            DATE(created_at) as created_date,
            DATE(updated_at) as last_rating_date
        FROM tenant_ratings
        WHERE client_code = ? AND tenant_code = ? AND category = ?
        ORDER BY updated_at DESC
        LIMIT 1
    ";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bind_param("sss", $client_code, $tenant_code, $category);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $existingRating = $checkResult->fetch_assoc();
    $checkStmt->close();
    
    // ==================== ENFORCE RATE LIMITING ====================
    $canRate = true;
    $rateLimitReason = '';
    $rateLimitData = [];
    
    if ($existingRating) {
        logActivity("Found existing rating - ID: {$existingRating['id']}, Previous Rating: {$existingRating['previous_rating']}");
        logActivity("Last Updated at: {$existingRating['updated_at']}");
        logActivity("Hours since: {$existingRating['hours_since_rating']}, Minutes since: {$existingRating['minutes_since_rating']}");
        
        // Check if the rating was updated today
        $isToday = ($existingRating['last_rating_date'] == date('Y-m-d'));
        logActivity("Is rating from today? " . ($isToday ? 'Yes' : 'No'));
        
        // Count how many ratings today for this tenant+category from this client
        $countQuery = "
            SELECT COUNT(*) as today_count
            FROM tenant_ratings
            WHERE client_code = ? 
            AND tenant_code = ? 
            AND category = ? 
            AND DATE(updated_at) = CURDATE()
        ";
        $countStmt = $conn->prepare($countQuery);
        $countStmt->bind_param("sss", $client_code, $tenant_code, $category);
        $countStmt->execute();
        $countResult = $countStmt->get_result()->fetch_assoc();
        $todayCount = (int)$countResult['today_count'];
        $countStmt->close();
        
        logActivity("Today's rating count for this tenant/category: {$todayCount}");
        logActivity("Max allowed per day: " . MAX_RATING_PER_CATEGORY_PER_DAY);
        
        // Check if already rated today
        if ($todayCount >= MAX_RATING_PER_CATEGORY_PER_DAY) {
            logActivity("RATE LIMIT: Already rated today (Count: {$todayCount})");
            
            $hoursSince = (int)$existingRating['hours_since_rating'];
            $intervalPassed = ($hoursSince >= RATING_INTERVAL_HOURS);
            
            logActivity("Hours since rating: {$hoursSince}, Interval: " . RATING_INTERVAL_HOURS . ", Passed: " . ($intervalPassed ? 'Yes' : 'No'));
            
            if ($intervalPassed) {
                logActivity("Interval passed, allowing new rating");
                $canRate = true;
            } else {
                logActivity("RATE LIMIT ENFORCED: Cannot rate yet");
                $canRate = false;
                $hoursRemaining = RATING_INTERVAL_HOURS - $hoursSince;
                $rateLimitReason = "You can rate again in " . round($hoursRemaining, 1) . " hours";
                
                // Prepare rate limit data for response
                $rateLimitData = [
                    'can_rate' => false,
                    'hours_remaining' => round($hoursRemaining, 1),
                    'minutes_remaining' => round((RATING_INTERVAL_HOURS * 60) - ($hoursSince * 60), 0),
                    'interval_hours' => RATING_INTERVAL_HOURS,
                    'current_rating' => (int)$existingRating['previous_rating'],
                    'current_comment' => $existingRating['previous_comment'],
                    'created_at' => $existingRating['created_at'],
                    'last_updated_at' => $existingRating['updated_at']
                ];
                
                logActivity("Rate limit reason: {$rateLimitReason}");
            }
        } else {
            logActivity("No rating today for this tenant/category, allowing rating");
            $canRate = true;
        }
        
        // Check force update
        if (!$canRate && $force_update) {
            logActivity("FORCE UPDATE: Overriding rate limit due to force_update flag");
            $canRate = true;
            $rateLimitReason = '';
            $rateLimitData = [];
        }
    } else {
        logActivity("No existing rating found for tenant {$tenant_code} in category {$category}");
        $canRate = true;
    }
    
    // ==================== BLOCK OR ALLOW RATING ====================
    if (!$canRate) {
        $nextAllowedTime = date('Y-m-d H:i:s', strtotime($existingRating['updated_at']) + (RATING_INTERVAL_HOURS * 3600));
        
        logActivity("BLOCKING RATING: " . $rateLimitReason);
        logActivity("Next allowed time: {$nextAllowedTime}");
        
        // Add next allowed time to rate limit data
        $rateLimitData['next_allowed_time'] = $nextAllowedTime;
        $rateLimitData['next_allowed_at'] = date('h:i A', strtotime($nextAllowedTime));
        
        // FIXED: Pass message as string, and data as separate parameter
        json_error(
            "You have already rated this tenant in this category. Please wait " . round(RATING_INTERVAL_HOURS - $existingRating['hours_since_rating'], 1) . " hours before rating again.",
            429,
            $rateLimitData,
            'RATE_LIMIT_EXCEEDED'
        );
    }
    
    // ==================== SAVE OR UPDATE RATING ====================
    logActivity("Proceeding to save/update rating...");
    
    if ($existingRating && ($existingRating['hours_since_rating'] >= RATING_INTERVAL_HOURS || $force_update)) {
        // Update existing rating
        logActivity("UPDATING existing rating ID: {$existingRating['id']}");
        logActivity("New rating: {$rating}, New comment: " . ($comment ?: 'empty'));
        
        $upsertQuery = "
            UPDATE tenant_ratings 
            SET 
                rating = ?,
                comment = ?,
                updated_at = NOW()
            WHERE id = ?
        ";
        $stmt = $conn->prepare($upsertQuery);
        $stmt->bind_param("isi", $rating, $comment, $existingRating['id']);
        $action = 'updated';
        
        if ($stmt->execute()) {
            logActivity("Update successful, affected rows: " . $stmt->affected_rows);
        } else {
            logActivity("ERROR: Update failed - " . $stmt->error);
            throw new Exception("Failed to update rating: " . $stmt->error);
        }
        $stmt->close();
        
    } else {
        // Insert new rating
        logActivity("INSERTING new rating for tenant {$tenant_code}");
        logActivity("Data - Client: {$client_code}, Rating: {$rating}, Category: {$category}");
        
        $upsertQuery = "
            INSERT INTO tenant_ratings (client_code, tenant_code, property_code, apartment_code, rating, comment, category)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ";
        $stmt = $conn->prepare($upsertQuery);
        $stmt->bind_param(
            "ssssiss", 
            $client_code, 
            $tenant_code, 
            $tenantInfo['property_code'], 
            $tenantInfo['apartment_code'], 
            $rating, 
            $comment, 
            $category
        );
        $action = 'created';
        
        if ($stmt->execute()) {
            logActivity("Insert successful, new ID: " . $stmt->insert_id);
        } else {
            logActivity("ERROR: Insert failed - " . $stmt->error);
            throw new Exception("Failed to insert rating: " . $stmt->error);
        }
        $stmt->close();
    }
    
    logActivity("Rating {$action} successfully for tenant {$tenant_code}");
    
    // ==================== GET UPDATED STATISTICS ====================
    logActivity("Fetching updated statistics...");
    
    // Get updated average rating for this tenant
    $avgQuery = "
        SELECT 
            AVG(rating) as avg_rating, 
            COUNT(*) as rating_count,
            AVG(CASE WHEN category = 'overall' THEN rating END) as overall_avg,
            AVG(CASE WHEN category = 'payment' THEN rating END) as payment_avg,
            AVG(CASE WHEN category = 'behavior' THEN rating END) as behavior_avg,
            AVG(CASE WHEN category = 'cleanliness' THEN rating END) as cleanliness_avg,
            AVG(CASE WHEN category = 'maintenance' THEN rating END) as maintenance_avg,
            SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as five_star,
            SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as four_star,
            SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as three_star,
            SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as two_star,
            SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as one_star
        FROM tenant_ratings
        WHERE tenant_code = ?
    ";
    $avgStmt = $conn->prepare($avgQuery);
    $avgStmt->bind_param("s", $tenant_code);
    $avgStmt->execute();
    $avgResult = $avgStmt->get_result()->fetch_assoc();
    $avgStmt->close();
    
    logActivity("Statistics - Total ratings: {$avgResult['rating_count']}, Avg: {$avgResult['avg_rating']}");
    
    // Get recent ratings for this tenant
    $recentQuery = "
        SELECT 
            tr.id,
            tr.client_code,
            tr.rating,
            tr.comment,
            tr.category,
            tr.updated_at,
            CONCAT(c.firstname, ' ', c.lastname) as client_name
        FROM tenant_ratings tr
        LEFT JOIN clients c ON tr.client_code = c.client_code
        WHERE tr.tenant_code = ?
        ORDER BY tr.updated_at DESC
        LIMIT 5
    ";
    $recentStmt = $conn->prepare($recentQuery);
    $recentStmt->bind_param("s", $tenant_code);
    $recentStmt->execute();
    $recentResult = $recentStmt->get_result();
    $recentRatings = [];
    while ($row = $recentResult->fetch_assoc()) {
        $recentRatings[] = $row;
    }
    $recentStmt->close();
    
    logActivity("Recent ratings fetched: " . count($recentRatings));
    
    // Calculate rating distribution percentages
    $totalRatings = (int)$avgResult['rating_count'];
    $distribution = [
        '5_star' => (int)$avgResult['five_star'],
        '4_star' => (int)$avgResult['four_star'],
        '3_star' => (int)$avgResult['three_star'],
        '2_star' => (int)$avgResult['two_star'],
        '1_star' => (int)$avgResult['one_star']
    ];
    
    if ($totalRatings > 0) {
        foreach ($distribution as $key => $count) {
            $distribution[$key . '_percentage'] = round(($count / $totalRatings) * 100, 1);
        }
    }
    
    // Calculate next allowed time
    $nextAllowedTime = null;
    if ($existingRating) {
        $nextAllowedTime = date('Y-m-d H:i:s', strtotime($existingRating['updated_at']) + (RATING_INTERVAL_HOURS * 3600));
    }
    
    // Log activity
    logActivity("Client {$client_code} {$action} rating for tenant {$tenant_code} with {$rating} stars for category {$category}");
    logActivity("=== RATING REQUEST COMPLETED SUCCESSFULLY ===");
    
    // ==================== RETURN RESPONSE ====================
    json_success([
        'message' => "Rating {$action} successfully",
        'action' => $action,
        'rating' => [
            'tenant_code' => $tenant_code,
            'rating' => $rating,
            'comment' => $comment,
            'category' => $category,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ],
        'statistics' => [
            'avg_rating' => round($avgResult['avg_rating'] ?? 0, 1),
            'total_ratings' => (int)$avgResult['rating_count'],
            'category_averages' => [
                'overall' => round($avgResult['overall_avg'] ?? 0, 1),
                'payment' => round($avgResult['payment_avg'] ?? 0, 1),
                'behavior' => round($avgResult['behavior_avg'] ?? 0, 1),
                'cleanliness' => round($avgResult['cleanliness_avg'] ?? 0, 1),
                'maintenance' => round($avgResult['maintenance_avg'] ?? 0, 1)
            ],
            'rating_distribution' => $distribution
        ],
        'recent_ratings' => $recentRatings,
        'rate_limit' => [
            'interval_hours' => RATING_INTERVAL_HOURS,
            'can_rate_again' => false,
            'next_allowed_time' => $nextAllowedTime,
            'remaining_hours' => $existingRating ? max(0, RATING_INTERVAL_HOURS - $existingRating['hours_since_rating']) : 0,
            'remaining_minutes' => $existingRating ? max(0, (RATING_INTERVAL_HOURS * 60) - ($existingRating['hours_since_rating'] * 60)) : 0
        ]
    ], "Rating {$action} successfully");
    
} catch (Exception $e) {
    logActivity("ERROR: " . $e->getMessage());
    logActivity("Error trace: " . $e->getTraceAsString());
    logActivity("=== RATING REQUEST FAILED ===");
    json_error("Failed to save rating: " . $e->getMessage(), 500);
}