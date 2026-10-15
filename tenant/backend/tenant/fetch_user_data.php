<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/rate_limit.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
rateLimiter();

try {
    // ==================== AUTHENTICATION ====================
    if (!isset($_SESSION['tenant_code'])) {
        json_error("Not logged in", 401, null, 'AUTH_REQUIRED');
    }

    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        json_error("Unauthorized access", 403, null, 'UNAUTHORIZED');
    }

    $tenant_code = $_SESSION['tenant_code'] ?? null;
    if (!$tenant_code) {
        json_error("Tenant code not found", 400, null, 'TENANT_CODE_MISSING');
    }

    // ==================== FETCH TENANT ====================
    // Access rules:
    //   - Active tenant:   tenant_status='1' AND status=1
    //   - Alumni (evacuated): tenant_status='3' AND status=0 AND evacuation_status='evacuated'
    //   - Everything else:  denied
    //
    // Both `status` and `tenant_status` are enforced together because they
    // can drift; requiring both to agree prevents accidental access.
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
            t.tenant_status,
            t.evacuation_status,
            t.move_out_date,
            t.final_settlement_amount,
            t.wallet_balance,
            t.agreed_rent_amount,
            t.payment_amount_per_period,
            t.has_secret_set,
            a.apartment_number,
            a.rent_amount,
            a.security_deposit,
            p.name    AS property_name,
            p.address AS property_address
        FROM tenants t
        LEFT JOIN apartments a ON t.apartment_code = a.apartment_code
        LEFT JOIN properties p ON t.property_code = p.property_code
        WHERE t.tenant_code = ?
          AND (
              -- Case 1: Active tenant (both columns agree)
              (t.tenant_status = '1' AND t.status = 1)
              OR
              -- Case 2: Evacuated tenant / alumni (all three agree)
              (t.tenant_status = '3'
               AND t.status = 0
               AND t.evacuation_status = 'evacuated')
          )
        LIMIT 1
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("s", $tenant_code);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        // Log the reason so drift is discoverable
        logActivity("fetch_user_data denied for tenant {$tenant_code}: "
            . "no matching active/alumni state");
        json_error("Tenant not found", 404, null, 'TENANT_NOT_FOUND');
    }

    $user = $result->fetch_assoc();
    $stmt->close();

    // ==================== CLASSIFY STATE ====================
    $isEvacuated = ($user['evacuation_status'] === 'evacuated');
    $user['is_alumni']    = $isEvacuated;
    $user['tenant_state'] = $user['evacuation_status']; // 'active' | 'pending_evacuation' | 'evacuated'

    if ($isEvacuated) {
        $user['evacuation'] = [
            'move_out_date'           => $user['move_out_date'],
            'final_settlement_amount' => (float) ($user['final_settlement_amount'] ?? 0),
            'final_settlement_formatted' => '₦' . number_format((float) ($user['final_settlement_amount'] ?? 0), 2),
            'wallet_balance'          => (float) ($user['wallet_balance'] ?? 0),
            'wallet_balance_formatted'=> '₦' . number_format((float) ($user['wallet_balance'] ?? 0), 2),
        ];
    }

    // ==================== RATINGS SUMMARY ====================
    $ratingsQuery = "
        SELECT
            COUNT(*) AS total_ratings,
            ROUND(AVG(rating), 1) AS average_rating,
            ROUND(AVG(CASE WHEN category = 'overall'     THEN rating END), 1) AS overall_rating,
            ROUND(AVG(CASE WHEN category = 'payment'     THEN rating END), 1) AS payment_rating,
            ROUND(AVG(CASE WHEN category = 'behavior'    THEN rating END), 1) AS behavior_rating,
            ROUND(AVG(CASE WHEN category = 'cleanliness' THEN rating END), 1) AS cleanliness_rating,
            ROUND(AVG(CASE WHEN category = 'maintenance' THEN rating END), 1) AS maintenance_rating,
            SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) AS five_star,
            SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) AS four_star,
            SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) AS three_star,
            SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) AS two_star,
            SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) AS one_star
        FROM tenant_ratings
        WHERE tenant_code = ?
    ";

    $ratingsStmt = $conn->prepare($ratingsQuery);
    if (!$ratingsStmt) {
        throw new Exception("Ratings prepare failed: " . $conn->error);
    }
    $ratingsStmt->bind_param("s", $tenant_code);
    $ratingsStmt->execute();
    $ratingsSummary = $ratingsStmt->get_result()->fetch_assoc();
    $ratingsStmt->close();

    // ==================== RECENT RATINGS ====================
    $recentQuery = "
        SELECT
            tr.id,
            tr.client_code,
            tr.rating,
            tr.comment,
            tr.category,
            tr.created_at,
            CONCAT(c.firstname, ' ', c.lastname) AS client_name
        FROM tenant_ratings tr
        LEFT JOIN clients c ON tr.client_code = c.client_code
        WHERE tr.tenant_code = ?
        ORDER BY tr.created_at DESC
        LIMIT 5
    ";

    $recentStmt = $conn->prepare($recentQuery);
    if (!$recentStmt) {
        throw new Exception("Recent ratings prepare failed: " . $conn->error);
    }
    $recentStmt->bind_param("s", $tenant_code);
    $recentStmt->execute();
    $recentResult = $recentStmt->get_result();
    $recentRatings = [];
    while ($row = $recentResult->fetch_assoc()) {
        $recentRatings[] = $row;
    }
    $recentStmt->close();

    // ==================== ASSEMBLE RATINGS ====================
    $user['ratings'] = [
        'summary' => [
            'total_ratings'      => (int)   ($ratingsSummary['total_ratings']      ?? 0),
            'average_rating'     => (float) ($ratingsSummary['average_rating']     ?? 0),
            'overall_rating'     => (float) ($ratingsSummary['overall_rating']     ?? 0),
            'payment_rating'     => (float) ($ratingsSummary['payment_rating']     ?? 0),
            'behavior_rating'    => (float) ($ratingsSummary['behavior_rating']    ?? 0),
            'cleanliness_rating' => (float) ($ratingsSummary['cleanliness_rating'] ?? 0),
            'maintenance_rating' => (float) ($ratingsSummary['maintenance_rating'] ?? 0),
            'rating_distribution' => [
                '5_star' => (int) ($ratingsSummary['five_star'] ?? 0),
                '4_star' => (int) ($ratingsSummary['four_star'] ?? 0),
                '3_star' => (int) ($ratingsSummary['three_star'] ?? 0),
                '2_star' => (int) ($ratingsSummary['two_star'] ?? 0),
                '1_star' => (int) ($ratingsSummary['one_star'] ?? 0),
            ],
        ],
        'recent' => $recentRatings,
    ];

    // Percentage breakdown
    $total = $user['ratings']['summary']['total_ratings'];
    if ($total > 0) {
        foreach ($user['ratings']['summary']['rating_distribution'] as $key => $count) {
            if (strpos($key, '_percentage') !== false) continue; // skip already-computed keys (safety)
            $user['ratings']['summary']['rating_distribution'][$key . '_percentage'] =
                round(($count / $total) * 100, 1);
        }
    }

    // ==================== RESPONSE ====================
    json_success($user, "User data retrieved successfully");

} catch (Throwable $e) {
    logActivity("Error in fetch_user_data: " . $e->getMessage());
    json_error("Failed to fetch user data", 500, null, 'SERVER_ERROR');
}