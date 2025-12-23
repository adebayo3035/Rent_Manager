<?php
// fetch-apartments-by-property.php

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';

$auth = requireAuth([
    'method' => 'GET',
    'rate_limit' => [3, 60],
    'roles'  => ['Super Admin', 'Admin', 'Agent']
]);


// ================= AUTH CHECK =================
    if (!isset($_SESSION['unique_id'])) {
        logActivity("SECURITY: Unauthorized access attempt to fetch_apartments_by_property");
        json_error("Not logged in.", 401);
    }

    $userId = (int) $_SESSION['unique_id'];
    $userRole = $_SESSION['role'] ?? 'User';

    logActivity("User {$userId} ({$userRole}) fetching apartments by property");

    // ================= METHOD VALIDATION =================
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        logActivity("Invalid request method: " . $_SERVER['REQUEST_METHOD']);
        json_error("Invalid request method. Use GET.", 405);
    }

    

// ------------------------------
// INPUT VALIDATION
// ------------------------------
$propertyCode = trim($_GET['property_code'] ?? '');
if ($propertyCode === '') {
    json_error("Property code is required.", 400);
}
    // ================= VALIDATION =================
    if (empty($propertyCode)) {
        json_error("Property code is required.", 400);
    }

    // Validate property code format
    if (!preg_match('/^[A-Za-z0-9_\-]{4,50}$/', $propertyCode)) {
        json_error("Invalid property code format.", 400);
    }

    logActivity("Fetching apartments for property: {$propertyCode}");

    // ================= VERIFY PROPERTY EXISTS =================
    $propertyCheck = $conn->prepare("
        SELECT property_code, name, property_type_unit 
        FROM properties 
        WHERE property_code = ?
    ");
    
    if (!$propertyCheck) {
        throw new Exception("Database prepare failed: " . $conn->error);
    }
    
    $propertyCheck->bind_param("s", $propertyCode);
    $propertyCheck->execute();
    $propertyResult = $propertyCheck->get_result();
    
    if ($propertyResult->num_rows === 0) {
        $propertyCheck->close();
        json_error("Property not found.", 404);
    }
    
    $propertyData = $propertyResult->fetch_assoc();
    $propertyCheck->close();
    
    $propertyName = $propertyData['name'];
    $maxCapacity = (int) $propertyData['property_type_unit'];
    
    logActivity("Property found: {$propertyName} | Max capacity: {$maxCapacity}");


// ------------------------------
// FETCH APARTMENTS
// ------------------------------
$sql = "
    SELECT 
    apartment_code,
    apartment_number,
    apartment_type_unit
FROM apartments
WHERE 
    property_code = ?
    AND status = 1
    AND (
        occupancy_status = 'NOT OCCUPIED'
        OR occupancy_status = ''
        OR occupancy_status IS NULL
    )
ORDER BY apartment_number ASC

";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $propertyCode);
$stmt->execute();
$result = $stmt->get_result();

$apartments = [];

while ($row = $result->fetch_assoc()) {
    $apartments[] = $row;
}

$stmt->close();

$response = [
    "success"=> true,
    'property_name' => $propertyName,
    'max_capacity' => $maxCapacity,
    'available_apartments_count' => count($apartments),
    'apartments' => $apartments
];
echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);    
