<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

try {
    // Check authentication
    if (!isset($_SESSION['unique_id'])) {
        json_error("Not logged in", 401);
    }
    
    // Check if user is admin
    $userRole = $_SESSION['role'] ?? '';
    if (!in_array($userRole, ['Super Admin', 'Admin'])) {
        json_error("Unauthorized access", 403);
    }
    
    // Get property code from request
    $property_code = $_GET['property_code'] ?? '';
    
    if (empty($property_code)) {
        json_error("Property code is required", 400);
    }
    
    // First, verify property exists
    $property_check = "SELECT property_code, name FROM properties WHERE property_code = ? AND status = 1";
    $prop_stmt = $conn->prepare($property_check);
    $prop_stmt->bind_param("s", $property_code);
    $prop_stmt->execute();
    $prop_result = $prop_stmt->get_result();
    
    if ($prop_result->num_rows === 0) {
        json_error("Property not found", 404);
    }
    $property = $prop_result->fetch_assoc();
    $prop_stmt->close();
    
    // Query to get distinct apartment types under the property
    $query = "
        SELECT DISTINCT 
            at.type_id,
            at.type_name,
            at.description,
            COUNT(a.apartment_code) as apartment_count
        FROM apartment_type at
        INNER JOIN apartments a ON at.type_id = a.apartment_type_id
        WHERE a.property_code = ? 
        AND a.status = 1
        GROUP BY at.type_id, at.type_name, at.description
        ORDER BY at.type_name
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $property_code);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $apartment_types = [];
    while ($row = $result->fetch_assoc()) {
        $apartment_types[] = [
            'type_id' => (int)$row['type_id'],
            'type_name' => $row['type_name'],
            'description' => $row['description'],
            'apartment_count' => (int)$row['apartment_count']
        ];
    }
    $stmt->close();
    
    // If no apartments found, return all apartment types (for new properties)
    if (empty($apartment_types)) {
        $fallback_query = "
            SELECT type_id, type_name, description 
            FROM apartment_type 
            WHERE status = 1 
            ORDER BY type_name
        ";
        $fallback_result = $conn->query($fallback_query);
        
        while ($row = $fallback_result->fetch_assoc()) {
            $apartment_types[] = [
                'type_id' => (int)$row['type_id'],
                'type_name' => $row['type_name'],
                'description' => $row['description'],
                'apartment_count' => 0
            ];
        }
    }
    
    json_success([
        'property' => [
            'code' => $property['property_code'],
            'name' => $property['name']
        ],
        'apartment_types' => $apartment_types,
        'total_types' => count($apartment_types)
    ], "Apartment types retrieved successfully");
    
} catch (Exception $e) {
    logActivity("Error in get_apartment_types: " . $e->getMessage());
    json_error("Failed to fetch apartment types: " . $e->getMessage(), 500);
}
?>