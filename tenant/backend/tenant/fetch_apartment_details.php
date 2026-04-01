<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

try {
    // Check authentication
    if (!isset($_SESSION['tenant_code'])) {
        json_error("Not logged in", 401);
    }

    // Check if user is a tenant
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        json_error("Unauthorized access", 403);
    }

    $tenant_code = $_SESSION['tenant_code'] ?? null;
    if (!$tenant_code) {
        json_error("Tenant code not found", 400);
    }

    // Fetch apartment details with all related information
    $query = "
        SELECT 
            a.apartment_code,
            a.apartment_number,
            a.apartment_type_unit,
            a.rent_amount,
            a.security_deposit,
            a.occupancy_status,
            a.status as apartment_status,
            a.created_at as apartment_created_at,
            at.type_name as apartment_type,
            at.type_id as apartment_type_id,
            p.name as property_name,
            p.address as property_address,
            p.property_code,
            CONCAT(ag.firstname, ' ', ag.lastname) as agent_name,
            ag.phone as agent_phone,
            ag.email as agent_email,
            ag.agent_code,
            t.lease_start_date,
            t.lease_end_date,
            t.payment_frequency,
            t.firstname as tenant_firstname,
            t.lastname as tenant_lastname,
            t.email as tenant_email,
            t.phone as tenant_phone
        FROM tenants t
        INNER JOIN apartments a ON t.apartment_code = a.apartment_code
        LEFT JOIN apartment_type at ON a.apartment_type_id = at.type_id
        LEFT JOIN properties p ON a.property_code = p.property_code
        LEFT JOIN agents ag ON p.agent_code = ag.agent_code
        WHERE t.tenant_code = ? AND t.status = 1
        LIMIT 1
    ";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $tenant_code);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        json_error("Apartment not found for this tenant", 404);
    }

    $apartment = $result->fetch_assoc();
    
    // Calculate days remaining on lease
    $days_remaining = 0;
    if ($apartment['lease_end_date']) {
        $end_date = new DateTime($apartment['lease_end_date']);
        $today = new DateTime();
        $days_remaining = $today->diff($end_date)->days;
        if ($today > $end_date) {
            $days_remaining = 0;
        }
    }

    // Get amenities if there's an amenities table
    $amenities = [];
    // $amenities_query = "
    //     SELECT amenity_name, description 
    //     FROM property_amenities 
    //     WHERE property_code = ? AND status = 1
    // ";
    // $amenities_stmt = $conn->prepare($amenities_query);
    // $amenities_stmt->bind_param("s", $apartment['property_code']);
    // $amenities_stmt->execute();
    // $amenities_result = $amenities_stmt->get_result();
    // while ($amenity = $amenities_result->fetch_assoc()) {
    //     $amenities[] = $amenity;
    // }
    // $amenities_stmt->close();

    // Prepare response data
    $response_data = [
        'apartment_details' => [
            'apartment_code' => $apartment['apartment_code'],
            'apartment_number' => $apartment['apartment_number'],
            'apartment_type' => $apartment['apartment_type'],
            'apartment_type_id' => $apartment['apartment_type_id'],
            'apartment_type_unit' => $apartment['apartment_type_unit'],
            'rent_amount' => (float)$apartment['rent_amount'],
            'security_deposit' => (float)$apartment['security_deposit'],
            'occupancy_status' => $apartment['occupancy_status'],
            'apartment_status' => $apartment['apartment_status'],
            'created_at' => $apartment['apartment_created_at']
        ],
        'property_details' => [
            'property_code' => $apartment['property_code'],
            'property_name' => $apartment['property_name'],
            'property_address' => $apartment['property_address'],
            'amenities' => $amenities
        ],
        'agent_details' => [
            'agent_code' => $apartment['agent_code'],
            'agent_name' => $apartment['agent_name'],
            'agent_phone' => $apartment['agent_phone'],
            'agent_email' => $apartment['agent_email']
        ],
        'lease_details' => [
            'lease_start_date' => $apartment['lease_start_date'],
            'lease_end_date' => $apartment['lease_end_date'],
            'days_remaining' => $days_remaining,
            'payment_frequency' => $apartment['payment_frequency'],
            'rent_amount' => (float)($apartment['tenant_rent_amount'] ?? $apartment['rent_amount'])
        ],
        'tenant_details' => [
            'firstname' => $apartment['tenant_firstname'],
            'lastname' => $apartment['tenant_lastname'],
            'email' => $apartment['tenant_email'],
            'phone' => $apartment['tenant_phone']
        ]
    ];

    $stmt->close();
    $conn->close();

    json_success($response_data, "Apartment details retrieved successfully");

} catch (Exception $e) {
    logActivity("Error in fetch_apartment_details: " . $e->getMessage());
    json_error("Failed to fetch apartment details: " . $e->getMessage(), 500);
}
?>