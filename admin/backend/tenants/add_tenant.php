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
define('MAX_LEASE_MONTHS', 12); //  1 year maxs
// Configurable due date offsets (in days)
define('DUE_DATE_OFFSET_MONTHLY', 7);        // 1 week
define('DUE_DATE_OFFSET_QUARTERLY', 14);     // 2 weeks
define('DUE_DATE_OFFSET_SEMI_ANNUALLY', 30); // 1 month
define('DUE_DATE_OFFSET_ANNUALLY', 90);      // 3 months

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';

logActivity("========== STARTING TENANT ONBOARDING PROCESS ==========");

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
logActivity("Step 1: Starting input collection and validation");

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

logActivity("Step 1.1: Checking required fields");
// Check required fields
foreach ($required_fields as $field) {
    if (!isset($_POST[$field]) || trim($_POST[$field]) === '') {
        logActivity("Missing required field: {$field}");
        json_error("Field '{$field}' is required.", 400);
    }
    $inputs[$field] = trim($_POST[$field]);
}

logActivity("Step 1.2: Processing optional fields");
// Add optional fields
foreach ($optional_fields as $field) {
    $inputs[$field] = isset($_POST[$field]) ? trim($_POST[$field]) : '';
}

logActivity("Step 1.3: Sanitizing inputs");
// Sanitize inputs
$inputs = sanitize_inputs($inputs);

// Log non-sensitive input summary
$logCopy = $inputs;
unset($logCopy['email'], $logCopy['phone'], $logCopy['employer_contact'], $logCopy['emergency_contact_phone'], $logCopy['referee_phone']);
logActivity('Inputs received: ' . json_encode($logCopy));

// ---------- Comprehensive Validations ----------
logActivity("Step 2: Starting comprehensive validations");

// 1. Field length validation
logActivity("Step 2.1: Validating field lengths");
foreach ($inputs as $key => $value) {
    if (strlen($value) > MAX_FIELD_LENGTH) {
        logActivity("Field {$key} exceeds maximum length");
        json_error("Field '{$key}' is too long. Maximum " . MAX_FIELD_LENGTH . " characters allowed.", 400);
    }
}

// 2. Email validation
logActivity("Step 2.2: Validating email format");
if (!filter_var($inputs['email'], FILTER_VALIDATE_EMAIL)) {
    logActivity("Invalid email format: {$inputs['email']}");
    json_error('Invalid email address.', 400);
}

// 3. Phone validation for all phone fields
logActivity("Step 2.3: Validating phone numbers");
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
logActivity("Step 2.4: Validating lease dates");
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
logActivity("Step 2.5: Validating lease duration against payment frequency");

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

logActivity("Expected months: {$expected_months}, Actual months: {$actual_months}");

// Allow a tolerance of +/- 1 day due to date calculations
// The lease end date should be exactly the expected months minus 1 day
// For example: 2026-04-21 to 2027-04-20 is 12 months - 1 day
$expected_end_date = clone $start_date;
$expected_end_date->modify("+{$expected_months} months");
$expected_end_date->modify('-1 day');
$expected_end_date_str = $expected_end_date->format('Y-m-d');

logActivity("Expected end date: {$expected_end_date_str}, Actual end date: {$inputs['lease_end_date']}");

// Compare dates instead of month count for more accurate validation
if ($inputs['lease_end_date'] !== $expected_end_date_str) {
    logActivity("Lease duration mismatch. Expected end date: {$expected_end_date_str}, Got: {$inputs['lease_end_date']}");
    json_error("For {$inputs['payment_frequency']} payment frequency, lease end date must be " . date('F j, Y', strtotime($expected_end_date_str)) . ".", 400);
}


// Check maximum lease duration
if ($actual_months > MAX_LEASE_MONTHS) {
    logActivity("Lease duration too long: {$actual_months} months exceeds maximum of " . MAX_LEASE_MONTHS);
    json_error("Maximum lease duration is " . MAX_LEASE_MONTHS . " months.", 400);
}

// 6. Gender validation
logActivity("Step 2.6: Validating gender");
$valid_genders = ['Male', 'Female', 'Other'];
if (!in_array($inputs['gender'], $valid_genders, true)) {
    logActivity("Invalid gender: {$inputs['gender']}");
    json_error('Please select a valid gender.', 400);
}

// 7. Validate file upload
logActivity("Step 2.7: Validating photo upload");
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
    logActivity("Creating upload directory: {$upload_dir}");
    if (!mkdir($upload_dir, 0750, true)) {
        logActivity("Failed to create upload directory: {$upload_dir}");
        json_error("Server error preparing upload directory", 500);
    }
    logActivity("Created upload directory: {$upload_dir}");
}

// ---------- Database Operations with Transaction ----------
logActivity("Step 3: Starting database transaction");
$conn->begin_transaction();
logActivity("Database transaction started");

try {
    // 1. Check duplicates in DB (email/phone)
    logActivity("Step 3.1: Checking for duplicate email/phone");
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
    logActivity("Step 3.2: Checking apartment availability and getting rent/deposit amounts");
    logActivity("Apartment code: {$inputs['apartment_code']}, Property code: {$inputs['property_code']}");

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
    logActivity("Step 3.3: Checking for duplicate photo");
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
    logActivity("Step 3.4: Moving uploaded file to: {$upload_path}");
    if (!move_uploaded_file($img_tmp, $upload_path)) {
        throw new Exception('Failed to move uploaded file to ' . $upload_path);
    }
    logActivity("File uploaded successfully to {$upload_path}");

    // 5. Generate tenant code
    logActivity("Step 3.5: Generating tenant code");
    $tenant_code = "TNT" . random_unique_id();
    $encrypt_pass = password_hash($tenant_code, PASSWORD_ARGON2ID);
    logActivity("Generated unique Tenant code: {$tenant_code}");

    // 6. Insert tenant
    logActivity("Step 3.6: Preparing to insert tenant into database");

    $insert_sql = "INSERT INTO tenants
    (tenant_code, property_code, apartment_code, firstname, lastname, gender, email, phone, 
     photo, occupation, name_of_employer, employer_address, employer_contact, lease_start_date, 
     lease_end_date, payment_frequency, referee_name, referee_phone, emergency_contact_name, 
     emergency_contact_phone, password, created_by, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

    logActivity("SQL Query: " . $insert_sql);

    $insert_stmt = $conn->prepare($insert_sql);
    if (!$insert_stmt) {
        throw new Exception('Prepare failed for insert: ' . $conn->error);
    }

    $created_by = is_numeric($userId) ? (int) $userId : $userId;

    $bind_params = [
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
    ];

    logActivity("Number of bind parameters: " . count($bind_params));
    logActivity("Bind parameters (excluding sensitive): " . json_encode(array_slice($bind_params, 0, 15)));

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

    logActivity("About to execute tenant insert");
    if (!$insert_stmt->execute()) {
        logActivity("SQL Error: " . $insert_stmt->error);
        logActivity("SQL Error Code: " . $insert_stmt->errno);
        throw new Exception('DB insert failed: ' . $insert_stmt->error);
    }

    $new_tenant_id = $insert_stmt->insert_id;
    $insert_stmt->close();
    logActivity("Tenant inserted successfully with ID: {$new_tenant_id}");

    // 7. Update apartment occupancy status
    logActivity("Step 3.7: Updating apartment occupancy status");
    $occupancy_status = 'OCCUPIED';
    $update_apt = $conn->prepare("UPDATE apartments SET occupied_by = ?, occupancy_status = ? WHERE apartment_code = ?");
    $update_apt->bind_param("sss", $tenant_code, $occupancy_status, $inputs['apartment_code']);

    if (!$update_apt->execute()) {
        throw new Exception('Failed to update apartment status: ' . $update_apt->error);
    }

    $update_apt->close();
    logActivity("Apartment occupancy status updated to OCCUPIED");

    // 8. RECORD PAYMENTS - Double entry for rent payment and security deposit
    logActivity("Step 3.8: Recording payments");

    $current_date = date('Y-m-d');

    // Calculate payment period range for rent based on lease start date and payment frequency
    $rentPeriod = calculatePaymentPeriodRange($inputs['payment_frequency'], $inputs['lease_start_date']);
    $rent_payment_period = $rentPeriod['period_display'];
    $rent_period_start = $rentPeriod['start_date'];
    $rent_period_end = $rentPeriod['end_date'];

    // Calculate due date based on payment frequency and period end date
    $rent_due_date = calculateDueDate($inputs['payment_frequency'], $rent_period_end);

    logActivity("Calculated rent payment period: {$rent_payment_period}");
    logActivity("Rent period: {$rent_period_start} to {$rent_period_end}");
    logActivity("Rent due date: {$rent_due_date}");

    // Generate separate numbers for each payment
    $deposit_receipt = generateReceiptNumber() . '-DEP';
    $deposit_reference = generateReferenceNumber($tenant_code) . '-DEP';
    $rent_receipt = generateReceiptNumber() . '-RENT';
    $rent_reference = generateReferenceNumber($tenant_code) . '-RENT';

    logActivity("Deposit receipt: {$deposit_receipt}, Deposit reference: {$deposit_reference}");
    logActivity("Rent receipt: {$rent_receipt}, Rent reference: {$rent_reference}");

    // 8a. Record Security Deposit Payment
    if ($security_deposit > 0) {
        logActivity("Step 3.8a: Recording security deposit payment of ₦{$security_deposit}");
        $deposit_description = "Security Deposit for apartment {$inputs['apartment_code']}";
        $payment_category = 'security_deposit';

        // Security deposit due date is at lease start (paid upfront)
        $deposit_due_date = $inputs['lease_start_date'];

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
            created_at,
            payment_category
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
    ");

        if (!$insert_deposit) {
            throw new Exception('Prepare failed for deposit payment insert: ' . $conn->error);
        }

        $balance = 0;
        $payment_method = 'cash';
        $payment_status = 'completed';

        logActivity("Binding deposit payment parameters");
        $insert_deposit->bind_param(
            "ssddsssssssis",
            $tenant_code,
            $inputs['apartment_code'],
            $security_deposit,
            $balance,
            $current_date,
            $deposit_due_date,
            $payment_method,
            $payment_status,
            $deposit_receipt,
            $deposit_reference,
            $deposit_description,
            $created_by,
            $payment_category
        );

        logActivity("About to execute deposit payment insert");
        if (!$insert_deposit->execute()) {
            logActivity("SQL Error (Deposit Payment): " . $insert_deposit->error);
            throw new Exception('Failed to record security deposit payment: ' . $insert_deposit->error);
        }

        $deposit_payment_id = $insert_deposit->insert_id;
        $insert_deposit->close();
        logActivity("Security deposit recorded successfully. Payment ID: {$deposit_payment_id}");

        // Also record in rent_payments table for consistency
        logActivity("Recording security deposit in rent_payments table");
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
            receipt_number,
            payment_period,
            period_start_date,
            period_end_date,
            due_date,
            notes,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

        $payment_type = 'security_deposit';
        $payment_period = 'Security Deposit';
        $notes = "Security deposit payment for apartment {$inputs['apartment_code']}";

        $insert_rent_deposit->bind_param(
            "ssdsssssssssss",
            $tenant_code,
            $inputs['apartment_code'],
            $security_deposit,
            $current_date,
            $payment_method,
            $deposit_reference,
            $payment_status,
            $payment_type,
            $deposit_receipt,
            $payment_period,
            $rent_period_start,
            $rent_period_end,
            $deposit_due_date,
            $notes
        );

        if (!$insert_rent_deposit->execute()) {
            logActivity("Warning: Failed to record security deposit in rent_payments table: " . $insert_rent_deposit->error);
        } else {
            logActivity("Security deposit recorded in rent_payments table");
        }
        $insert_rent_deposit->close();
    } else {
        logActivity("Security deposit amount is 0, skipping deposit payment");
    }

    // 8b. Record First Rent Payment
    if ($rent_amount > 0) {
        logActivity("Step 3.8b: Recording first rent payment of ₦{$rent_amount}");
        logActivity("Payment period: {$rent_payment_period} ({$rent_period_start} to {$rent_period_end})");
        logActivity("Due date: {$rent_due_date}");

        $rent_description = "Rent payment for period: {$rent_payment_period} - {$inputs['payment_frequency']} payment";
        $payment_category = 'rent';

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
            created_at,
            payment_category
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
    ");

        if (!$insert_rent) {
            throw new Exception('Prepare failed for rent payment insert: ' . $conn->error);
        }

        $balance = 0;
        $payment_method = 'cash';
        $payment_status = 'completed'; // Set to pending, will be updated when payment is confirmed

        logActivity("Binding rent payment parameters");
        $insert_rent->bind_param(
            "ssddsssssssis",
            $tenant_code,
            $inputs['apartment_code'],
            $rent_amount,
            $balance,
            $current_date,
            $rent_due_date,
            $payment_method,
            $payment_status,
            $rent_receipt,
            $rent_reference,
            $rent_description,
            $created_by,
            $payment_category
        );

        logActivity("About to execute rent payment insert");
        if (!$insert_rent->execute()) {
            logActivity("SQL Error (Rent Payment): " . $insert_rent->error);
            throw new Exception('Failed to record rent payment: ' . $insert_rent->error);
        }

        $rent_payment_id = $insert_rent->insert_id;
        $insert_rent->close();
        logActivity("First rent payment recorded successfully. Payment ID: {$rent_payment_id}");

        // Also record in rent_payments table with date range and due date
        logActivity("Recording rent payment in rent_payments table");
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
            payment_period,
            period_start_date,
            period_end_date,
            due_date,
            notes,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

        $payment_type = 'rent';
        $notes = "Rent payment for period: {$rent_payment_period}";

        $insert_rent_only->bind_param(
            "ssdsssssssssss",
            $tenant_code,
            $inputs['apartment_code'],
            $rent_amount,
            $current_date,
            $payment_method,
            $rent_reference,
            $payment_status,
            $rent_receipt,
            $payment_type,
            $rent_payment_period,
            $rent_period_start,
            $rent_period_end,
            $rent_due_date,
            $notes
        );

        if (!$insert_rent_only->execute()) {
            logActivity("Warning: Failed to record rent payment in rent_payments table: " . $insert_rent_only->error);
        } else {
            logActivity("Rent payment recorded in rent_payments table with period: {$rent_payment_period}");
        }
        $insert_rent_only->close();
    } else {
        logActivity("Rent amount is 0, skipping rent payment");
    }

    logActivity("Payment records created successfully for tenant {$tenant_code}");

    // Commit transaction
    logActivity("Step 3.9: Committing transaction");
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
    logActivity("========== TENANT ONBOARDING COMPLETED SUCCESSFULLY ==========");

} catch (Exception $e) {
    // Rollback transaction on any error
    logActivity("Step ERROR: Exception caught. Rolling back transaction.");
    $conn->rollback();
    logActivity("Transaction rolled back due to error: " . $e->getMessage());
    logActivity("Exception trace: " . $e->getTraceAsString());

    // Clean up uploaded file if it was created
    if (isset($upload_path) && file_exists($upload_path)) {
        @unlink($upload_path);
        logActivity("Cleaned up uploaded file: {$upload_path}");
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
function generateReceiptNumber()
{
    $prefix = 'RCP';
    $date = date('Ymd');
    $random = strtoupper(substr(uniqid(), -6));
    return $prefix . '-' . $date . '-' . $random;
}

/**
 * Generate a unique reference number
 */
function generateReferenceNumber($tenant_code)
{
    $prefix = 'REF';
    $date = date('Ymd');
    $tenant_short = substr($tenant_code, -6);
    $random = rand(1000, 9999);
    return $prefix . '-' . $date . '-' . $tenant_short . '-' . $random;
}

/**
 * Calculate payment period range based on payment frequency and start date
 * 
 * @param string $payment_frequency Monthly, Quarterly, Semi-Annually, Annually
 * @param string $start_date The start date of the payment period (Y-m-d format)
 * @return array Associative array with period_display, start_date, end_date
 */
function calculatePaymentPeriodRange($payment_frequency, $start_date)
{
    $startDate = new DateTime($start_date);
    $endDate = clone $startDate;

    switch ($payment_frequency) {
        case 'Monthly':
            $endDate->modify('+1 month')->modify('-1 day');
            $period_display = $startDate->format('F j, Y') . ' to ' . $endDate->format('F j, Y');
            break;
        case 'Quarterly':
            $endDate->modify('+3 months')->modify('-1 day');
            $period_display = $startDate->format('F j, Y') . ' to ' . $endDate->format('F j, Y');
            break;
        case 'Semi-Annually':
            $endDate->modify('+6 months')->modify('-1 day');
            $period_display = $startDate->format('F j, Y') . ' to ' . $endDate->format('F j, Y');
            break;
        case 'Annually':
            $endDate->modify('+1 year')->modify('-1 day');
            $period_display = $startDate->format('F j, Y') . ' to ' . $endDate->format('F j, Y');
            break;
        default:
            $endDate->modify('+1 month')->modify('-1 day');
            $period_display = $startDate->format('F j, Y') . ' to ' . $endDate->format('F j, Y');
    }

    return [
        'period_display' => $period_display,
        'start_date' => $startDate->format('Y-m-d'),
        'end_date' => $endDate->format('Y-m-d')
    ];
}

/**
 * Calculate due date based on payment frequency and period end date
 * 
 * @param string $payment_frequency Monthly, Quarterly, Semi-Annually, Annually
 * @param string $period_end_date The end date of the payment period (Y-m-d format)
 * @return string The calculated due date (Y-m-d format)
 */
function calculateDueDate($payment_frequency, $period_end_date) {
    $dueDateConfig = [
        'Monthly' => DUE_DATE_OFFSET_MONTHLY,
        'Quarterly' => DUE_DATE_OFFSET_QUARTERLY,
        'Semi-Annually' => DUE_DATE_OFFSET_SEMI_ANNUALLY,
        'Annually' => DUE_DATE_OFFSET_ANNUALLY
    ];
    
    $daysToAdd = $dueDateConfig[$payment_frequency] ?? 7;
    
    $dueDate = new DateTime($period_end_date);
    $dueDate->modify("+{$daysToAdd} days");
    
    return $dueDate->format('Y-m-d');
}
?>