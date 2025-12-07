<?php
header('Content-Type: application/json');

include('restriction_checker.php');
// Validate session
if (!isset($_SESSION['unique_id'])) {
    logActivity("Access denied: No active session from IP: " . $_SERVER['REMOTE_ADDR']);
    http_response_code(401);
    exit(json_encode(['success' => false, 'message' => 'Access denied. Please log in first.']));
}

$adminId = $_SESSION['unique_id'];
$adminRole = $_SESSION['role'] ?? 'Unknown';

// Check if user has permission to add tenants
if (!in_array($adminRole, ["Super Admin", "Admin"])) {
    $errorMsg = "Unauthorized tenant creation attempt by user: " . $adminId;
    logActivity($errorMsg);
    echo json_encode(["success" => false, "message" => "You do not have permission to add tenants."]);
    exit();
}

// Validate input - using FormData instead of JSON for file uploads
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    logActivity("Invalid request method for adding tenant");
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']));
}

// Get form data
$firstname = trim($_POST['firstname'] ?? '');
$lastname = trim($_POST['lastname'] ?? '');
$gender = trim($_POST['gender'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$mobile_number = trim($_POST['mobile_number'] ?? '');
$address = trim($_POST['address'] ?? '');
$secret_question = trim($_POST['secret_question'] ?? '');
$secret_answer = trim($_POST['secret_answer'] ?? '');
$property_id = (int)($_POST['property_id'] ?? 0);
$unit_id = (int)($_POST['unit_id'] ?? 0);
$rent_start_date = trim($_POST['rent_start_date'] ?? '');
$rent_end_date = trim($_POST['rent_end_date'] ?? '');
$rent_amount = isset($_POST['rent_amount']) ? floatval($_POST['rent_amount']) : 0.00;
$security_deposit = isset($_POST['security_deposit']) ? floatval($_POST['security_deposit']) : 0.00;

// Handle file upload
$photo = null;
if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    $max_size = 5 * 1024 * 1024; // 5MB
    
    $file_type = $_FILES['photo']['type'];
    $file_size = $_FILES['photo']['size'];
    
    if (!in_array($file_type, $allowed_types)) {
        echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, and GIF are allowed.']);
        exit();
    }
    
    if ($file_size > $max_size) {
        echo json_encode(['success' => false, 'message' => 'File too large. Maximum size is 5MB.']);
        exit();
    }
    
    // Generate unique filename
    $file_extension = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
    $photo = 'tenant_' . uniqid() . '.' . $file_extension;
    $upload_path = 'backend/tenant_photos/' . $photo;
    
    if (!move_uploaded_file($_FILES['photo']['tmp_name'], $upload_path)) {
        echo json_encode(['success' => false, 'message' => 'Failed to upload photo.']);
        exit();
    }
}

// Validate required fields
$required_fields = [
    'firstname' => $firstname,
    'lastname' => $lastname,
    'gender' => $gender,
    'email' => $email,
    'password' => $password,
    'mobile_number' => $mobile_number,
    'address' => $address,
    'secret_question' => $secret_question,
    'secret_answer' => $secret_answer,
    'property_id' => $property_id,
    'unit_id' => $unit_id,
    'rent_start_date' => $rent_start_date,
    'rent_end_date' => $rent_end_date
];

$missing_fields = [];
foreach ($required_fields as $field => $value) {
    if (empty($value)) {
        $missing_fields[] = $field;
    }
}

if (!empty($missing_fields)) {
    logActivity("Missing required fields: " . implode(', ', $missing_fields));
    echo json_encode(['success' => false, 'message' => 'Missing required fields: ' . implode(', ', $missing_fields)]);
    exit();
}

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
    exit();
}

// Validate phone number (basic validation)
if (!preg_match('/^[0-9+\-\s()]{10,20}$/', $mobile_number)) {
    echo json_encode(['success' => false, 'message' => 'Invalid phone number format.']);
    exit();
}

// Validate dates
$start_date = DateTime::createFromFormat('Y-m-d', $rent_start_date);
$end_date = DateTime::createFromFormat('Y-m-d', $rent_end_date);
$today = new DateTime();

if (!$start_date || !$end_date) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format. Use YYYY-MM-DD.']);
    exit();
}

if ($start_date > $end_date) {
    echo json_encode(['success' => false, 'message' => 'Rent end date must be after start date.']);
    exit();
}

// Validate gender
if (!in_array($gender, ['Male', 'Female'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid gender value.']);
    exit();
}

logActivity("Attempting to add new tenant: " . $firstname . " " . $lastname . " by Admin " . $adminId);

// === Transaction ===
$conn->begin_transaction();

try {
    // Check if email already exists
    $emailCheckStmt = $conn->prepare("SELECT tenant_id FROM tenant_customers WHERE email = ?");
    if (!$emailCheckStmt || !$emailCheckStmt->bind_param("s", $email) || !$emailCheckStmt->execute()) {
        throw new Exception("Email check failed: " . ($conn->error ?? 'Unknown'));
    }
    
    $emailCheckStmt->bind_result($existing_tenant_id);
    if ($emailCheckStmt->fetch()) {
        $emailCheckStmt->close();
        throw new Exception("Email already exists in the system.");
    }
    $emailCheckStmt->close();

    // Check if unit is already occupied
    $unitCheckStmt = $conn->prepare("SELECT tenant_id FROM tenant_customers WHERE unit_id = ? AND (delete_status IS NULL OR delete_status != 'Yes')");
    if (!$unitCheckStmt || !$unitCheckStmt->bind_param("i", $unit_id) || !$unitCheckStmt->execute()) {
        throw new Exception("Unit check failed: " . ($conn->error ?? 'Unknown'));
    }
    
    $unitCheckStmt->bind_result($occupied_tenant_id);
    if ($unitCheckStmt->fetch()) {
        $unitCheckStmt->close();
        throw new Exception("Selected unit is already occupied by another tenant.");
    }
    $unitCheckStmt->close();

    // Generate unique tenant ID
    $tenant_id = 'TNT' . date('Ymd') . strtoupper(bin2hex(random_bytes(4)));

    // Hash password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $hashed_secret_answer = password_hash($secret_answer, PASSWORD_DEFAULT);

    // Calculate next rent due date (first of next month from lease start)
    $next_rent_due = date('Y-m-01', strtotime($rent_start_date . ' +1 month'));

    // Insert new tenant
    $insertStmt = $conn->prepare("INSERT INTO tenant_customers (
        tenant_id, firstname, lastname, gender, email, password, mobile_number, 
        address, secret_question, secret_answer, photo, property_id, unit_id, 
        tenant_status, lease_start, lease_end, rent_amount, security_deposit, 
        next_rent_due, restriction, delete_status, date_created, date_updated
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, 0, NULL, NOW(), NOW())");

    if (!$insertStmt) {
        throw new Exception("Prepare failed for tenant insertion: " . ($conn->error ?? 'Unknown'));
    }

    $insertStmt->bind_param(
        "sssssssssssiisssdds",
        $tenant_id,
        $firstname,
        $lastname,
        $gender,
        $email,
        $hashed_password,
        $mobile_number,
        $address,
        $secret_question,
        $hashed_secret_answer,
        $photo,
        $property_id,
        $unit_id,
        $rent_start_date,
        $rent_end_date,
        $rent_amount,
        $security_deposit,
        $next_rent_due
    );

    if (!$insertStmt->execute()) {
        throw new Exception("Tenant insertion failed: " . ($insertStmt->error ?? 'Unknown'));
    }
    $insertStmt->close();

    // Log tenant creation
    $referenceId = bin2hex(random_bytes(16));
    $logStmt = $conn->prepare("INSERT INTO tenant_creation_audit_log 
                              (reference_id, tenant_id, action_type, initiated_by, initiated_by_role, tenant_details) 
                              VALUES (?, ?, 'CREATE', ?, ?, ?)");
    if (!$logStmt) {
        throw new Exception("Audit log prepare failed: " . ($conn->error ?? 'Unknown'));
    }

    $tenant_details = json_encode([
        'firstname' => $firstname,
        'lastname' => $lastname,
        'email' => $email,
        'property_id' => $property_id,
        'unit_id' => $unit_id,
        'lease_period' => $rent_start_date . ' to ' . $rent_end_date
    ]);

    if (!$logStmt->bind_param("sssss", $referenceId, $tenant_id, $adminId, $adminRole, $tenant_details) || !$logStmt->execute()) {
        throw new Exception("Audit log failed: " . ($logStmt->error ?? 'Unknown'));
    }
    $logStmt->close();

    $conn->commit();
    
    logActivity("SUCCESS: New tenant created - ID: " . $tenant_id . " by Admin " . $adminId);
    http_response_code(201);
    echo json_encode([
        'success' => true, 
        'message' => 'Tenant created successfully.',
        'tenant_id' => $tenant_id
    ]);

} catch (Exception $e) {
    $conn->rollback();
    
    // Clean up uploaded file if insertion failed
    if ($photo && file_exists('backend/tenant_photos/' . $photo)) {
        unlink('backend/tenant_photos/' . $photo);
    }
    
    logActivity("FAILED: Tenant creation by Admin " . $adminId . " - " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Tenant creation failed: ' . $e->getMessage()]);
} finally {
    $conn->close();
}
