<?php
// client/backend/homepage/fetch_homepage_data.php
// Consolidated endpoint for the public homepage.
// Returns: stats, trusted-by, dashboard preview, featured property, testimonials.
//
// Data sources:
//   - properties            → property/unit stats
//   - rent_payments         → rent ledger (total billed/collected)
//   - rent_payment_tracker  → per-period payment breakdown
//   - payments              → NON-rent payments (fees, deposits, etc.) — not used here
//   - tenants               → recent tenants, occupancy
//   - clients               → trusted-by names

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300'); // 5-minute browser/CDN cache

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/rate_limit.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Public endpoint — rate limited, but no login required
rateLimiter('fetch_homepage_data');

if (!isset($conn) || !($conn instanceof mysqli)) {
    logActivity("DB connection unavailable in fetch_homepage_data.php", 'ERROR');
    json_error("Server error: database unavailable", 500);
}

// logActivity("Homepage data requested", 'INFO', ['event' => 'homepage.fetch.start']);
logActivity("Admin homepage data requested", 'INFO', ['event' => 'admin.homepage.fetch.start']);

try {
    // =================================================================
    // 1. PROPERTY / UNIT STATS
    // =================================================================
    $stats = [
        'properties_managed'  => 0,
        'apartments_total'    => 0,
        'apartments_occupied' => 0,
        'trusted_count'       => 0,
        'rent_billed'         => 0,
        'rent_processed'      => 0,
        'rent_collection_rate'=> 0,
        'satisfaction_rate'   => 98.7,
    ];

    $q = $conn->query("
        SELECT
            COUNT(*)                              AS properties_managed,
            COALESCE(SUM(apartments_created), 0)  AS apartments_total,
            COALESCE(SUM(occupied_apartments), 0) AS apartments_occupied
        FROM properties
        WHERE status = '1'
    ");
    if ($q && ($row = $q->fetch_assoc())) {
        $stats['properties_managed']  = (int)$row['properties_managed'];
        $stats['apartments_total']    = (int)$row['apartments_total'];
        $stats['apartments_occupied'] = (int)$row['apartments_occupied'];
    }

    // Trusted count = distinct clients with active properties
    $q = $conn->query("
        SELECT COUNT(DISTINCT client_code) AS c
        FROM properties
        WHERE status = '1'
    ");
    if ($q) $stats['trusted_count'] = (int)$q->fetch_assoc()['c'];

    // =================================================================
    // 2. RENT TOTALS (billed vs collected)
    // =================================================================
    // Uses rent_payments (rent ledger only).
    //  - amount       = amount billed for the period
    //  - amount_paid  = amount actually received
    //  - status       = completed | ongoing (both = real money received)
    // =================================================================
    $q = $conn->query("
        SELECT
            COALESCE(SUM(amount), 0)      AS billed,
            COALESCE(SUM(amount_paid), 0) AS collected
        FROM rent_payments
        WHERE status IN ('completed', 'ongoing')
    ");
    if ($q && ($row = $q->fetch_assoc())) {
        $stats['rent_billed']    = (float)$row['billed'];
        $stats['rent_processed'] = (float)$row['collected'];
        if ($stats['rent_billed'] > 0) {
            $stats['rent_collection_rate'] = round(
                ($stats['rent_processed'] / $stats['rent_billed']) * 100,
                1
            );
        }
    }

    // =================================================================
    // 3. TRUSTED-BY LOGOS
    // =================================================================
    // Subquery join avoids the cross-product bug from the earlier version.
    // =================================================================
    $trustedBy = [];
    $q = $conn->query("
        SELECT
            c.client_code,
            c.firstname,
            c.lastname,
            pc.property_count
        FROM clients c
        INNER JOIN (
            SELECT client_code, COUNT(*) AS property_count
            FROM properties
            WHERE status = '1'
            GROUP BY client_code
            ORDER BY property_count DESC
            LIMIT 5
        ) pc ON pc.client_code = c.client_code
        WHERE c.client_status = '1'
        ORDER BY pc.property_count DESC
    ");
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            $name = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''));
            if ($name !== '' && !is_numeric($name)) {
                $trustedBy[] = $name;
            }
        }
    }
    if (empty($trustedBy)) {
        // Fallback only for a genuinely empty database
        $trustedBy = ['Prime Properties', 'Elite Estates', 'Urban Living'];
    }

    // =================================================================
    // 4. DASHBOARD PREVIEW
    // =================================================================
    // Revenue this month (from rent_payments)
    $revenueThisMonth = 0;
    $q = $conn->query("
        SELECT COALESCE(SUM(amount_paid), 0) AS total
        FROM rent_payment_tracker
        WHERE status IN ('paid', 'ongoing')
          AND MONTH(payment_date) = MONTH(CURDATE())
          AND YEAR(payment_date)  = YEAR(CURDATE())
    ");
    if ($q) $revenueThisMonth = (float)$q->fetch_assoc()['total'];

    $activeProperties = $stats['properties_managed'];

    // Recent tenants — status from latest rent payment
    $recentTenants = [];
    $q = $conn->query("
        SELECT
            CONCAT(t.firstname, ' ', t.lastname) AS name,
            t.tenant_code,
            (
                SELECT rp.status
                FROM rent_payments rp
                WHERE rp.tenant_code = t.tenant_code
                ORDER BY rp.payment_date DESC, rp.payment_id DESC
                LIMIT 1
            ) AS last_rent_status
        FROM tenants t
        WHERE t.status = 1
          AND t.evacuation_status = 'active'
          AND t.deleted_at IS NULL
        ORDER BY t.created_at DESC
        LIMIT 2
    ");
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            $s = 'pending';
            if ($row['last_rent_status'] === 'completed')                  $s = 'paid';
            elseif ($row['last_rent_status'] === 'ongoing')                $s = 'pending';
            elseif ($row['last_rent_status'] === 'failed')                 $s = 'overdue';
            elseif ($row['last_rent_status'] === 'refunded')               $s = 'pending';

            $recentTenants[] = [
                'name'   => $row['name'],
                'status' => $s,
            ];
        }
    }

    // =================================================================
    // 5. FEATURED PROPERTY
    // =================================================================
    // Prefer properties with a photo, then most recent.
    // Rent estimate comes from tenants.agreed_rent_amount (properties has no rent column).
    $featured = null;
    $q = $conn->query("
        SELECT
            p.id,
            p.property_code,
            p.name,
            p.address,
            p.city,
            p.state,
            p.photo,
            p.apartments_created,
            p.occupied_apartments,
            p.vacant_apartments,
            (
                SELECT AVG(t.agreed_rent_amount)
                FROM tenants t
                WHERE t.property_code = p.property_code
                  AND t.status = 1
                  AND t.deleted_at IS NULL
            ) AS avg_rent
        FROM properties p
        WHERE p.status = '1'
        ORDER BY
            (p.photo IS NOT NULL AND p.photo <> '') DESC,
            p.created_at DESC
        LIMIT 1
    ");
    if ($q && ($row = $q->fetch_assoc())) {
        $location = trim(implode(', ', array_filter([
            $row['address'] ?? null,
            $row['city']    ?? null,
            $row['state']   ?? null,
        ])));

        $avgRent = (float)($row['avg_rent'] ?? 0);

        $featured = [
            'id'              => (int)$row['id'],
            'property_code'   => $row['property_code'],
            'title'           => $row['name'],
            'location'        => $location ?: 'Location not specified',
            'apartments'      => (int)$row['apartments_created'],
            'vacant'          => (int)($row['vacant_apartments'] ?? 0),
            'occupied'        => (int)$row['occupied_apartments'],
            'price'           => $avgRent,
            'price_formatted' => $avgRent > 0
                ? '₦' . number_format($avgRent, 0) . '/year avg'
                : 'Price on request',
            'status'          => 'available',
            'photo_url'       => resolvePropertyPhoto($row['photo'] ?? ''),
        ];
    }

    // =================================================================
    // 6. TESTIMONIALS (static for now)
    // =================================================================
    $testimonials = [
        [
            'name'   => 'David Johnson',
            'role'   => 'Property Manager, Elite Estates',
            'rating' => 5,
            'text'   => 'KaraKata Pro transformed how we manage our 50+ properties. The automation features saved us 20 hours per week!',
        ],
        [
            'name'   => 'Sarah Williams',
            'role'   => 'Property Owner, 12 Units',
            'rating' => 4.5,
            'text'   => 'As a landlord with multiple tenants, the automated reminders and payment tracking have been game-changing.',
        ],
        [
            'name'   => 'Michael Chen',
            'role'   => 'CEO, Urban Living Group',
            'rating' => 5,
            'text'   => 'The reporting features give us insights we never had before. Highly recommended for any serious property business.',
        ],
    ];

    // =================================================================
    // 7. ASSEMBLE RESPONSE
    // =================================================================
    $data = [
        'stats' => [
            // Raw values
            'properties_managed'   => $stats['properties_managed'],
            'apartments_total'     => $stats['apartments_total'],
            'apartments_occupied'  => $stats['apartments_occupied'],
            'rent_billed'          => $stats['rent_billed'],
            'rent_processed'       => $stats['rent_processed'],
            'rent_collection_rate' => $stats['rent_collection_rate'],
            'satisfaction_rate'    => $stats['satisfaction_rate'],
            'trusted_count'        => $stats['trusted_count'],

            // Formatted for direct DOM insertion
            'properties_managed_formatted'  => number_format($stats['properties_managed']) . '+',
            'apartments_total_formatted'    => number_format($stats['apartments_total']) . '+',
            'rent_processed_formatted'      => formatNairaShort($stats['rent_processed']) . '+',
            'rent_billed_formatted'         => formatNairaShort($stats['rent_billed']),
            'rent_collection_rate_formatted'=> $stats['rent_collection_rate'] . '%',
            'satisfaction_rate_formatted'   => number_format($stats['satisfaction_rate'], 1) . '%',
            'trusted_count_formatted'       => number_format($stats['trusted_count']) . '+',
        ],
        'trusted_by' => $trustedBy,
        'dashboard_preview' => [
            'revenue_this_month'           => $revenueThisMonth,
            'revenue_this_month_formatted' => formatNairaShort($revenueThisMonth) . ' This Month',
            'active_properties'            => $activeProperties,
            'recent_tenants'               => $recentTenants,
        ],
        'featured_property' => $featured,
        'testimonials'      => $testimonials,
    ];

    logActivity("Homepage data served", 'INFO', [
        'event'              => 'homepage.fetch.success',
        'properties_managed' => $stats['properties_managed'],
        'rent_processed'     => $stats['rent_processed'],
        'featured_code'      => $featured['property_code'] ?? null,
    ]);

    // json_success($data, 'Homepage data retrieved');
    json_success('Homepage data retrieved', $data);

} catch (Throwable $e) {
    logActivity("Homepage data error: " . $e->getMessage(), 'ERROR', [
        'event' => 'homepage.fetch.error',
        'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 5),
    ]);
    json_error("Unable to load homepage data. Please try again.", 500);
}

// =====================================================================
// HELPERS
// =====================================================================

/**
 * Build an app-root-aware URL.
 * Example: "admin/backend/properties/property_photos/x.jpg"
 * → "/Rent_Manager/admin/backend/properties/property_photos/x.jpg"
 */
function buildAppUrl(string $relativePath): string
{
    $documentRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    // $appRoot      = realpath(__DIR__ . '/../../..');
    $appRoot = realpath(__DIR__ . '/../../..');
    $appRoot      = $appRoot ? str_replace('\\', '/', $appRoot) : '';

    $basePath = '';
    if ($documentRoot && $appRoot && strpos($appRoot, $documentRoot) === 0) {
        $basePath = substr($appRoot, strlen($documentRoot));
    }

    return $basePath . '/' . ltrim($relativePath, '/');
}

/**
 * Resolve a property photo filename to a full URL.
 * Handles the case where the DB column stores just a hash (no extension).
 */
function resolvePropertyPhoto(string $photo): ?string
{
    if (empty($photo)) return null;

    $basePath = 'admin/backend/properties/property_photos/';
    $fsDir    = __DIR__ . '/../../../admin/backend/properties/property_photos/';

    // Exact filename match
    if (is_file($fsDir . $photo)) {
        return buildAppUrl($basePath . $photo);
    }

    // Try common extensions
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
        if (is_file($fsDir . $photo . '.' . $ext)) {
            return buildAppUrl($basePath . $photo . '.' . $ext);
        }
    }

    return null;
}

/**
 * Short human format for money: ₦2.4M, ₦4.8B, ₦12K
 */
function formatNairaShort(float $amount): string
{
    if ($amount >= 1_000_000_000) {
        return '₦' . rtrim(rtrim(number_format($amount / 1_000_000_000, 1), '0'), '.') . 'B';
    }
    if ($amount >= 1_000_000) {
        return '₦' . rtrim(rtrim(number_format($amount / 1_000_000, 1), '0'), '.') . 'M';
    }
    if ($amount >= 1_000) {
        return '₦' . rtrim(rtrim(number_format($amount / 1_000, 1), '0'), '.') . 'K';
    }
    return '₦' . number_format($amount);
}