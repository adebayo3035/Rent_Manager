<?php
// onboard_tenant.php
// Secure, refactored onboarding endpoint with comprehensive validation and payment recording

header('Content-Type: application/json; charset=utf-8');
define('CSRF_FORM_NAME', 'add_tenant_form');

// Define constants
define('MAX_FILE_SIZE', 500000); // 500KB
define('MAX_FIELD_LENGTH', 255);
define('RATE_LIMIT_COUNT', 10);
define('RATE_LIMIT_SECONDS', 60);
define('MIN_LEASE_MONTHS', 1);
define('MAX_LEASE_MONTHS', 36); // 3 years max

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';

$auth = requireAuth([
    'method' => 'POST',
    'rate_key' => 'add_tenant',
    'rate_limit' => [10, 60],
    'csrf' => [
        'enabled' => true,
        'form_name' => 'add_tenant_form'
    ],
    'roles' => ['Super Admin', 'Admin']
]);

$userId = $auth['user_id'];
$userRole = $auth['role'];

logActivity("Authenticated user: {$userId} | Role: {$userRole}");

// ---------- Accept & sanitize inputs ----------
$required_fields = [
    'property_code',
    'apartment_code',
    'firstname',
    'lastname',
    'gender',
    'email',
    'phone',
    'occupation',
    'name_of_employer',
    'employer_address',
    'employer_contact',
    'lease_start_date',
    'lease_end_date',
    'payment_frequency',
    'referee_name',
    'referee_phone',
    'emergency_contact_name',
    'emergency_contact_phone'
];

$optional_fields = [];

$inputs = [];

// Check required fields
foreach ($required_fields as $field) {
    if (!isset($_POST[$field]) || trim($_POST[$field]) === '') {
        logActivity("Missing required field: {$field}");
        json_error("Field '{$field}' is required.", 400);
    }
    $inputs[$field] = trim($_POST[$field]);
}

// Add optional fields
foreach ($optional_fields as $field) {
    $inputs[$field] = isset($_POST[$field]) ? trim($_POST[$field]) : '';
}

// Sanitize inputs
$inputs = sanitize_inputs($inputs);

// Log non-sensitive input summary
$logCopy = $inputs;
logActivity('Inputs received: ' . json_encode($logCopy));

// ---------- Comprehensive Validations ----------

// 1. Field length validation
foreach ($inputs as $key => $value) {
    if (strlen($value) > MAX_FIELD_LENGTH) {
        logActivity("Field {$key} exceeds maximum length");
        json_error("Field '{$key}' is too long. Maximum " . MAX_FIELD_LENGTH . " characters allowed.", 400);
    }
}

// 2. Email validation
if (!filter_var($inputs['email'], FILTER_VALIDATE_EMAIL)) {
    logActivity("Invalid email format: {$inputs['email']}");
    json_error('Invalid email address.', 400);
}

// 3. Phone validation for all phone fields
$phone_fields = [
    'phone' => 'Phone Number',
    'employer_contact' => 'Employer Phone Number',
    'emergency_contact_phone' => 'Emergency Contact Phone Number',
    'referee_phone' => 'Referee Phone Number'
];

foreach ($phone_fields as $field => $label) {
    if (!empty($inputs[$field]) && !validate_phone($inputs[$field])) {
        logActivity("Invalid {$label}: {$inputs[$field]}");
        json_error("Please input a valid {$label}.", 400);
    }
}

// 4. Date validation
$date_format = 'Y-m-d';
$start_date = DateTime::createFromFormat($date_format, $inputs['lease_start_date']);
$end_date = DateTime::createFromFormat($date_format, $inputs['lease_end_date']);

if (!$start_date || !$end_date) {
    logActivity("Invalid date format. Start: {$inputs['lease_start_date']}, End: {$inputs['lease_end_date']}");
    json_error('Invalid date format. Use YYYY-MM-DD format.', 400);
}

$today = new DateTime();
$today->setTime(0, 0, 0);

if ($start_date < $today) {
    logActivity("Lease start date is in the past: {$inputs['lease_start_date']}");
    json_error('Lease start date cannot be in the past.', 400);
}

// 5. Lease duration validation based on payment frequency
$frequency_to_months = [
    'Monthly' => 1,
    'Quarterly' => 3,
    'Semi-Annually' => 6,
    'Annually' => 12
];

if (!isset($frequency_to_months[$inputs['payment_frequency']])) {
    logActivity("Invalid payment frequency: {$inputs['payment_frequency']}");
    json_error('Invalid payment frequency selected.', 400);
}

$expected_months = $frequency_to_months[$inputs['payment_frequency']];
$interval = $start_date->diff($end_date);
$actual_months = ($interval->y * 12) + $interval->m;

if ($actual_months != $expected_months) {
    logActivity("Lease duration mismatch. Expected {$expected_months} month(s), got {$actual_months} month(s)");
    json_error("For {$inputs['payment_frequency']} payment frequency, lease must be exactly {$expected_months} month(s).", 400);
}

// Check maximum lease duration
if ($actual_months > MAX_LEASE_MONTHS) {
    logActivity("Lease duration too long: {$actual_months} months exceeds maximum of " . MAX_LEASE_MONTHS);
    json_error("Maximum lease duration is " . MAX_LEASE_MONTHS . " months.", 400);
}

// 6. Gender validation
$valid_genders = ['Male', 'Female', 'Other'];
if (!in_array($inputs['gender'], $valid_genders, true)) {
    logActivity("Invalid gender: {$inputs['gender']}");
    json_error('Please select a valid gender.', 400);
}

// 7. Validate file upload
if (!isset($_FILES['photo'])) {
    logActivity("Photo missing from request.");
    json_error('Please upload a Photo of the Tenant.', 400);
}

$photo = $_FILES['photo'];

if (!is_array($photo) || $photo['error'] !== UPLOAD_ERR_OK) {
    logActivity("Upload error: " . ($photo['error'] ?? 'no-file'));
    json_error('Photo upload failed.', 400);
}

$img_tmp = $photo['tmp_name'];
$img_name = basename($photo['name']);
$img_size = $photo['size'];
$img_type = mime_content_type($img_tmp);

logActivity("Uploaded image detected: {$img_name}, size={$img_size}, type={$img_type}");

$allowed_ext = ['jpg', 'jpeg', 'png'];
$allowed_mime = ['image/jpeg', 'image/png', 'image/jpg'];

$ext = strtolower(pathinfo($img_name, PATHINFO_EXTENSION));

if (!in_array($ext, $allowed_ext, true) || !in_array($img_type, $allowed_mime, true)) {
    logActivity("Rejected invalid image upload: EXT={$ext}, MIME={$img_type}");
    json_error('Only JPG, JPEG & PNG images allowed.', 400);
}

if ($img_size > MAX_FILE_SIZE) {
    logActivity("Image too large: {$img_size} bytes");
    json_error('Image too large. Max allowed size is ' . (MAX_FILE_SIZE / 1024) . 'KB.', 400);
}

// Validate image content
$image_info = @getimagesize($img_tmp);
if (!$image_info) {
    logActivity("Invalid image file content");
    json_error('Invalid image file.', 400);
}

$file_hash = hash_file('sha256', $img_tmp);
$file_name = $file_hash . '.' . $ext;

logActivity("Image hashed to unique name: {$file_name}");

$upload_dir = __DIR__ . '/tenant_photos/';
$upload_path = $upload_dir . $file_name;

if (!is_dir($upload_dir)) {
    if (!mkdir($upload_dir, 0750, true)) {
        logActivity("Failed to create upload directory: {$upload_dir}");
        json_error("Server error preparing upload directory", 500);
    }
    logActivity("Created upload directory: {$upload_dir}");
}

// ---------- Database Operations with Transaction ----------
$conn->begin_transaction();
logActivity("Database transaction started");

try {
    // 1. Check duplicates in DB (email/phone)
    $check_sql = "SELECT tenant_code, email, phone FROM tenants WHERE email = ? OR phone = ? LIMIT 1 FOR UPDATE";
    $check_stmt = $conn->prepare($check_sql);
    if (!$check_stmt) {
        throw new Exception('Prepare failed for duplicate check: ' . $conn->error);
    }

    $check_stmt->bind_param('ss', $inputs['email'], $inputs['phone']);
    $check_stmt->execute();
    $res = $check_stmt->get_result();

    if ($res && $row = $res->fetch_assoc()) {
        $err = [];
        if ($row['email'] === $inputs['email'])
            $err['email'] = 'This Email already exists for another tenant';
        if ($row['phone'] === $inputs['phone'])
            $err['phone'] = 'Tenant Phone Number already exists for another tenant';
        if (!empty($err)) {
            logActivity("Duplicate found: " . json_encode($err));
            throw new Exception(json_encode($err), 409);
        }
    }
    $check_stmt->close();
    logActivity("Duplicate check passed");

    // 2. Check if Apartment is already leased and get rent_amount and security_deposit
    $stmt = $conn->prepare("
        SELECT a.occupancy_status, a.rent_amount, a.security_deposit, a.apartment_code, p.property_code 
        FROM apartments a
        INNER JOIN properties p ON a.property_code = p.property_code
        WHERE a.apartment_code = ? 
        AND a.property_code = ?
        FOR UPDATE
    ");
    $stmt->bind_param("ss", $inputs['apartment_code'], $inputs['property_code']);
    $stmt->execute();
    $stmt->bind_result($isOccupied, $rent_amount, $security_deposit, $apt_code, $prop_code);

    if (!$stmt->fetch()) {
        logActivity("Apartment {$inputs['apartment_code']} not found in property {$inputs['property_code']}");
        throw new Exception("Apartment not found in the specified property");
    }

    if ($isOccupied == 'OCCUPIED') {
        logActivity("Apartment {$inputs['apartment_code']} is already occupied");
        throw new Exception("Apartment is already occupied by Another Tenant");
    }
    $stmt->close();
    logActivity("Apartment availability check passed. Rent: {$rent_amount}, Deposit: {$security_deposit}");

    // 3. Check if same photo was already uploaded
    if (is_file($upload_path)) {
        logActivity("Photo already exists on disk: {$file_name}");
        throw new Exception('This image has already been uploaded for another Tenant.');
    }

    $photo_check_sql = "SELECT 1 FROM tenants WHERE photo = ? LIMIT 1";
    $photo_check_stmt = $conn->prepare($photo_check_sql);
    if (!$photo_check_stmt) {
        throw new Exception('Prepare failed for photo check');
    }

    $photo_check_stmt->bind_param('s', $file_name);
    $photo_check_stmt->execute();
    $photo_res = $photo_check_stmt->get_result();

    if ($photo_res && $photo_res->num_rows > 0) {
        $photo_check_stmt->close();
        throw new Exception('This image has already been uploaded for another Tenant.');
    }
    $photo_check_stmt->close();
    logActivity("Photo duplicate check passed");

    // 4. Move uploaded file
    if (!move_uploaded_file($img_tmp, $upload_path)) {
        throw new Exception('Failed to move uploaded file to ' . $upload_path);
    }
    logActivity("File uploaded to {$upload_path}");

    // 5. Generate tenant code
    $tenant_code = "TNT" . random_unique_id();
    $encrypt_pass = password_hash($tenant_code, PASSWORD_ARGON2ID);
    logActivity("Generated unique Tenant code: {$tenant_code}");

    // 6. Insert tenant
    $insert_sql = "INSERT INTO tenants
    (tenant_code, property_code, apartment_code, firstname, lastname, gender, email, phone, 
     photo, occupation, name_of_employer, employer_address, employer_contact, lease_start_date, 
     lease_end_date, payment_frequency, referee_name, referee_phone, emergency_contact_name, 
     emergency_contact_phone, password, created_by, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

    $insert_stmt = $conn->prepare($insert_sql);
    if (!$insert_stmt) {
        throw new Exception('Prepare failed for insert: ' . $conn->error);
    }

    $created_by = is_numeric($userId) ? (int) $userId : $userId;

    $insert_stmt->bind_param(
        'sssssssssssssssssssssi',
        $tenant_code,
        $inputs['property_code'],
        $inputs['apartment_code'],
        $inputs['firstname'],
        $inputs['lastname'],
        $inputs['gender'],
        $inputs['email'],
        $inputs['phone'],
        $file_name,
        $inputs['occupation'],
        $inputs['name_of_employer'],
        $inputs['employer_address'],
        $inputs['employer_contact'],
        $inputs['lease_start_date'],
        $inputs['lease_end_date'],
        $inputs['payment_frequency'],
        $inputs['referee_name'],
        $inputs['referee_phone'],
        $inputs['emergency_contact_name'],
        $inputs['emergency_contact_phone'],
        $encrypt_pass,
        $created_by
    );

    if (!$insert_stmt->execute()) {
        throw new Exception('DB insert failed: ' . $insert_stmt->error);
    }

    $new_tenant_id = $insert_stmt->insert_id;
    $insert_stmt->close();
    logActivity("Tenant inserted with ID: {$new_tenant_id}");

    // 7. Update apartment occupancy status
    $occupancy_status = 'OCCUPIED';
    $update_apt = $conn->prepare("UPDATE apartments SET occupied_by = ?, occupancy_status = ? WHERE apartment_code = ?");
    $update_apt->bind_param("sss", $tenant_code, $occupancy_status, $inputs['apartment_code']);

    if (!$update_apt->execute()) {
        throw new Exception('Failed to update apartment status: ' . $update_apt->error);
    }

    $update_apt->close();
    logActivity("Apartment occupancy status updated");

    // 8. RECORD PAYMENTS - Double entry for rent payment and security deposit
    
    $current_date = date('Y-m-d');
    $receipt_number = generateReceiptNumber();
    $reference_number = generateReferenceNumber($tenant_code);
    
    // 8a. Record Security Deposit Payment
    if ($security_deposit > 0) {
        $deposit_description = "Security Deposit for apartment {$inputs['apartment_code']}";
        
        $insert_deposit = $conn->prepare("
            INSERT INTO payments (
                tenant_code, 
                apartment_code, 
                amount, 
                balance, 
                payment_date, 
                due_date, 
                payment_method, 
                payment_status, 
                receipt_number, 
                reference_number, 
                description, 
                recorded_by, 
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        if (!$insert_deposit) {
            throw new Exception('Prepare failed for deposit payment insert: ' . $conn->error);
        }
        
        $balance = 0; // Security deposit is fully paid
        $due_date = $inputs['lease_start_date'];
        $payment_method = 'cash';
        $payment_status = 'completed';
        $receipt_number =  $receipt_number . '-DEP';
        $reference_number = $reference_number . '-DEP';
        
        $insert_deposit->bind_param(
            "ssddsssssssi",
            $tenant_code,
            $inputs['apartment_code'],
            $security_deposit,
            $balance,
            $current_date,
            $due_date,
            $payment_method,
            $payment_status,
            $receipt_number,
            $reference_number,
            $deposit_description,
            $created_by
        );
        
        if (!$insert_deposit->execute()) {
            throw new Exception('Failed to record security deposit payment: ' . $insert_deposit->error);
        }
        
        $deposit_payment_id = $insert_deposit->insert_id;
        $insert_deposit->close();
        logActivity("Security deposit recorded: ₦{$security_deposit} for tenant {$tenant_code}");
        
        // Also record in rent_payments table for consistency
        $insert_rent_deposit = $conn->prepare("
            INSERT INTO rent_payments (
                tenant_code,
                apartment_code,
                amount,
                payment_date,
                payment_method,
                reference_number,
                status,
                payment_type,
                notes,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $payment_type = 'security_deposit';
        $notes = "Security deposit payment for apartment {$inputs['apartment_code']}";
        $reference_number = $reference_number . '-DEP';
        
        $insert_rent_deposit->bind_param(
            "ssdssssss",
            $tenant_code,
            $inputs['apartment_code'],
            $security_deposit,
            $current_date,
            $payment_method,
            $reference_number,
            $payment_status,
            $payment_type,
            $notes
        );
        
        if (!$insert_rent_deposit->execute()) {
            logActivity("Warning: Failed to record security deposit in rent_payments table: " . $insert_rent_deposit->error);
        } else {
            logActivity("Security deposit recorded in rent_payments table");
        }
        $insert_rent_deposit->close();
    }
    
    // 8b. Record First Rent Payment (for the first month/period)
    if ($rent_amount > 0) {
        // Calculate first payment due date based on payment frequency
        $first_payment_due_date = $inputs['lease_start_date'];
        
        $rent_description = "First rent payment for apartment {$inputs['apartment_code']} - {$inputs['payment_frequency']} payment";
        
        $insert_rent = $conn->prepare("
            INSERT INTO payments (
                tenant_code, 
                apartment_code, 
                amount, 
                balance, 
                payment_date, 
                due_date, 
                payment_method, 
                payment_status, 
                receipt_number, 
                reference_number, 
                description, 
                recorded_by, 
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        if (!$insert_rent) {
            throw new Exception('Prepare failed for rent payment insert: ' . $conn->error);
        }
        
        $balance = 0; // Assuming full payment
        $payment_method = 'cash';
        $payment_status = 'completed';
        $receipt_number =  $receipt_number . '-RENT';
        $reference_number = $reference_number . '-RENT';
        
        $insert_rent->bind_param(
            "ssddsssssssi",
            $tenant_code,
            $inputs['apartment_code'],
            $rent_amount,
            $balance,
            $current_date,
            $first_payment_due_date,
            $payment_method,
            $payment_status,
            $receipt_number,
            $reference_number,
            $rent_description,
            $created_by
        );
        
        if (!$insert_rent->execute()) {
            throw new Exception('Failed to record rent payment: ' . $insert_rent->error);
        }
        
        $rent_payment_id = $insert_rent->insert_id;
        $insert_rent->close();
        logActivity("First rent payment recorded: ₦{$rent_amount} for tenant {$tenant_code}");
        
        // Also record in rent_payments table
        $insert_rent_only = $conn->prepare("
            INSERT INTO rent_payments (
                tenant_code,
                apartment_code,
                amount,
                payment_date,
                payment_method,
                reference_number,
                status,
                receipt_number,
                payment_type,
                notes,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $payment_type = 'rent';
        $notes = "First rent payment for lease starting {$inputs['lease_start_date']}";
        $reference_number = $reference_number . '-RENT';
        
        $insert_rent_only->bind_param(
            "ssdssssss",
            $tenant_code,
            $inputs['apartment_code'],
            $rent_amount,
            $current_date,
            $payment_method,
            $reference_number,
            $payment_status,
            $payment_type,
            $notes
        );
        
        if (!$insert_rent_only->execute()) {
            logActivity("Warning: Failed to record rent payment in rent_payments table: " . $insert_rent_only->error);
        } else {
            logActivity("Rent payment recorded in rent_payments table");
        }
        $insert_rent_only->close();
    }
    
    logActivity("Payment records created successfully for tenant {$tenant_code}");

    // Commit transaction
    $conn->commit();
    logActivity("Transaction committed successfully");
    
    // Log success
    logActivity("Tenant {$tenant_code} ({$inputs['firstname']} {$inputs['lastname']}) onboarded successfully by user {$userId}");

    // Consume CSRF token after successful operation
    consumeCsrfToken(CSRF_FORM_NAME);
    
    // Return success response
    $response = [
        'success' => true,
        'message' => 'New Tenant has been successfully Onboarded!',
        'tenant_code' => $tenant_code,
        'data' => [
            'name' => $inputs['firstname'] . ' ' . $inputs['lastname'],
            'email' => $inputs['email'],
            'apartment' => $inputs['apartment_code'],
            'lease_period' => $actual_months . ' months',
            'payments_recorded' => [
                'rent_amount' => $rent_amount,
                'security_deposit' => $security_deposit,
                'total_paid' => $rent_amount + $security_deposit
            ]
        ]
    ];

    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    // Rollback transaction on any error
    $conn->rollback();
    logActivity("Transaction rolled back due to error: " . $e->getMessage());

    // Clean up uploaded file if it was created
    if (isset($upload_path) && file_exists($upload_path)) {
        @unlink($upload_path);
        logActivity("Cleaned up uploaded file: {$upload_path}");
    }

    // Clean up temporary file
    if (isset($clean_tmp) && file_exists($clean_tmp)) {
        @unlink($clean_tmp);
    }

    // Determine HTTP status code
    $status_code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;

    // Check if error message is JSON (for duplicate errors)
    $error_data = json_decode($e->getMessage(), true);
    if (json_last_error() === JSON_ERROR_NONE) {
        json_error($error_data, $status_code);
    } else {
        json_error($e->getMessage(), $status_code);
    }
}

/**
 * Generate a unique receipt number
 */
function generateReceiptNumber() {
    $prefix = 'RCP';
    $date = date('Ymd');
    $random = strtoupper(substr(uniqid(), -6));
    return $prefix . '-' . $date . '-' . $random;
}

/**
 * Generate a unique reference number
 */
function generateReferenceNumber($tenant_code) {
    $prefix = 'REF';
    $date = date('Ymd');
    $tenant_short = substr($tenant_code, -6);
    $random = rand(1000, 9999);
    return $prefix . '-' . $date . '-' . $tenant_short . '-' . $random;
}
?>