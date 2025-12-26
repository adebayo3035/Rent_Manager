<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();
logActivity("==== Random People Fetch Request Started ====");

try {
    // ---------------- AUTH ----------------
    if (!isset($_SESSION['unique_id'])) {
        json_error("Not logged in.", 401);
    }

    rateLimit("fetch_random_people", 10, 60);

    // ---------------- CONFIG ----------------
    $LIMIT = 10; // number of testimonials needed

    $sources = [
        'clients' => [
            'count_sql' => "SELECT COUNT(*) cnt FROM clients WHERE status = 1",
            'data_sql'  => "SELECT firstname, lastname FROM clients WHERE status = 1 LIMIT ?, 1",
            'role'      => 'Client'
        ],
        'tenants' => [
            'count_sql' => "SELECT COUNT(*) cnt FROM tenants WHERE status = 1",
            'data_sql'  => "SELECT firstname, lastname FROM tenants WHERE status = 1 LIMIT ?, 1",
            'role'      => 'Tenant'
        ],
        'agents' => [
            'count_sql' => "SELECT COUNT(*) cnt FROM agents WHERE status = 1",
            'data_sql'  => "SELECT firstname, lastname FROM agents WHERE status = 1 LIMIT ?, 1",
            'role'      => 'Agent'
        ]
    ];

    $results = [];

    // ---------------- STEP 1: FETCH COUNTS ----------------
    foreach ($sources as $key => &$src) {
        $res = $conn->query($src['count_sql']);
        $src['count'] = (int) $res->fetch_assoc()['cnt'];
        logActivity("{$key} count: {$src['count']}");
    }

    // ---------------- STEP 2: RANDOM OFFSET FETCH ----------------
    foreach ($sources as $src) {
        if (count($results) >= $LIMIT) break;
        if ($src['count'] === 0) continue;

        $offset = random_int(0, max(0, $src['count'] - 1));

        $stmt = $conn->prepare($src['data_sql']);
        $stmt->bind_param("i", $offset);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $results[] = [
                'firstname' => ucwords(strtolower($row['firstname'])),
                'lastname'  => ucwords(strtolower($row['lastname'])),
                'role'      => $src['role']
            ];
        }
    }

    // ---------------- STEP 3: FALLBACK (FILL IF STILL SHORT) ----------------
    if (count($results) < $LIMIT) {
        logActivity("Fallback triggered — insufficient random results");

        foreach ($sources as $src) {
            if (count($results) >= $LIMIT) break;
            if ($src['count'] === 0) continue;

            $sql = str_replace('LIMIT ?, 1', 'LIMIT 5', $src['data_sql']);
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $res = $stmt->get_result();

            while ($row = $res->fetch_assoc()) {
                if (count($results) >= $LIMIT) break;

                $results[] = [
                    'firstname' => ucwords(strtolower($row['firstname'])),
                    'lastname'  => ucwords(strtolower($row['lastname'])),
                    'role'      => $src['role']
                ];
            }
            $stmt->close();
        }
    }

    // ---------------- FINAL RESPONSE ----------------
    echo json_encode([
        'success' => true,
        'data' => [
            'customer_names' => $results,
            'count' => count($results)
        ]
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    logActivity("Random people fetch successful | Returned: " . count($results));

} catch (Throwable $e) {
    logActivity("ERROR: " . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch testimonials'
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
    logActivity("==== Random People Fetch Request Ended ====");
}
