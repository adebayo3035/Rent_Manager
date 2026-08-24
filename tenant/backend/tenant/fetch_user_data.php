<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

try {
    // Check if user is logged in
    if (!isset($_SESSION['tenant_code'])) {
        json_error("Not logged in", 401, null, 'AUTH_REQUIRED');
    }

    // Check if user is a tenant
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        json_error("Unauthorized access", 403, null, 'UNAUTHORIZED');
    }

    $tenant_code = $_SESSION['tenant_code'] ?? null;

    if (!$tenant_code) {
        json_error("Tenant code not found", 400, null, 'TENANT_CODE_MISSING');
    }

    // Fetch tenant details
    $query = "
        SELECT 
            t.tenant_code,
            t.firstname,
            t.lastname,
            t.email,
            t.phone,
            t.gender,
            t.photo,
            t.apartment_code,
            t.property_code,
            t.lease_start_date,
            t.lease_end_date,
            t.payment_frequency,
            t.status,
            t.agreed_rent_amount,
            t.payment_amount_per_period,
            t.has_secret_set,
            a.apartment_number,
            a.rent_amount,
            a.security_deposit,
            p.name as property_name,
            p.address as property_address
        FROM tenants t
        LEFT JOIN apartments a ON t.apartment_code = a.apartment_code
        LEFT JOIN properties p ON t.property_code = p.property_code
        WHERE t.tenant_code = ? AND t.status = 1
        LIMIT 1
    ";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $tenant_code);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        json_error("Tenant not found", 404, null, 'TENANT_NOT_FOUND');
    }

    $user = $result->fetch_assoc();
    $stmt->close();

    // Fetch ratings summary
    $ratingsQuery = "
        SELECT 
            COUNT(*) as total_ratings,
            ROUND(AVG(rating), 1) as average_rating,
            ROUND(AVG(CASE WHEN category = 'overall' THEN rating END), 1) as overall_rating,
            ROUND(AVG(CASE WHEN category = 'payment' THEN rating END), 1) as payment_rating,
            ROUND(AVG(CASE WHEN category = 'behavior' THEN rating END), 1) as behavior_rating,
            ROUND(AVG(CASE WHEN category = 'cleanliness' THEN rating END), 1) as cleanliness_rating,
            ROUND(AVG(CASE WHEN category = 'maintenance' THEN rating END), 1) as maintenance_rating,
            SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as five_star,
            SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as four_star,
            SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as three_star,
            SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as two_star,
            SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as one_star
        FROM tenant_ratings
        WHERE tenant_code = ?
    ";

    $ratingsStmt = $conn->prepare($ratingsQuery);
    $ratingsStmt->bind_param("s", $tenant_code);
    $ratingsStmt->execute();
    $ratingsResult = $ratingsStmt->get_result();
    $ratingsSummary = $ratingsResult->fetch_assoc();
    $ratingsStmt->close();

    // Fetch recent ratings with client names
    $recentQuery = "
        SELECT 
            tr.id,
            tr.client_code,
            tr.rating,
            tr.comment,
            tr.category,
            tr.created_at,
            CONCAT(c.firstname, ' ', c.lastname) as client_name
        FROM tenant_ratings tr
        LEFT JOIN clients c ON tr.client_code = c.client_code
        WHERE tr.tenant_code = ?
        ORDER BY tr.created_at DESC
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
    $conn->close();

    // Build ratings data
    $user['ratings'] = [
        'summary' => [
            'total_ratings' => (int)($ratingsSummary['total_ratings'] ?? 0),
            'average_rating' => (float)($ratingsSummary['average_rating'] ?? 0),
            'overall_rating' => (float)($ratingsSummary['overall_rating'] ?? 0),
            'payment_rating' => (float)($ratingsSummary['payment_rating'] ?? 0),
            'behavior_rating' => (float)($ratingsSummary['behavior_rating'] ?? 0),
            'cleanliness_rating' => (float)($ratingsSummary['cleanliness_rating'] ?? 0),
            'maintenance_rating' => (float)($ratingsSummary['maintenance_rating'] ?? 0),
            'rating_distribution' => [
                '5_star' => (int)($ratingsSummary['five_star'] ?? 0),
                '4_star' => (int)($ratingsSummary['four_star'] ?? 0),
                '3_star' => (int)($ratingsSummary['three_star'] ?? 0),
                '2_star' => (int)($ratingsSummary['two_star'] ?? 0),
                '1_star' => (int)($ratingsSummary['one_star'] ?? 0)
            ]
        ],
        'recent' => $recentRatings
    ];

    // Calculate percentages for distribution
    $total = $user['ratings']['summary']['total_ratings'];
    if ($total > 0) {
        foreach ($user['ratings']['summary']['rating_distribution'] as $key => $count) {
            $user['ratings']['summary']['rating_distribution'][$key . '_percentage'] = 
                round(($count / $total) * 100, 1);
        }
    }

    // Return success with user data
    json_success($user, "User data retrieved successfully");

} catch (Exception $e) {
    logActivity("Error in fetch_user_data: " . $e->getMessage());
    json_error("Failed to fetch user data", 500, null, 'SERVER_ERROR');
}