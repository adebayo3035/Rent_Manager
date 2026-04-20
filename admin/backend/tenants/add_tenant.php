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
define('MAX_LEASE_MONTHS', 12); // 1 year max
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
foreach ($required_fields as $field) {
    if (!isset($_POST[$field]) || trim($_POST[$field]) === '') {
        logActivity("Missing required field: {$field}");
        json_error("Field '{$field}' is required.", 400);
    }
    $inputs[$field] = trim($_POST[$field]);
}

logActivity("Step 1.2: Processing optional fields");
foreach ($optional_fields as $field) {
    $inputs[$field] = isset($_POST[$field]) ? trim($_POST[$field]) : '';
}

logActivity("Step 1.3: Sanitizing inputs");
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

$expected_end_date = clone $start_date;
$expected_end_date->modify("+{$expected_months} months");
$expected_end_date->modify('-1 day');
$expected_end_date_str = $expected_end_date->format('Y-m-d');

logActivity("Expected end date: {$expected_end_date_str}, Actual end date: {$inputs['lease_end_date']}");

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
    logActivity("Apartment availability check passed. Annual Rent: {$rent_amount}, Deposit: {$security_deposit}");

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
    // Calculate rent period end Date (1 year from start date)
    $start_period= $inputs['lease_start_date'];
    $end_period_date= new DateTime($start_period);
    $end_period_date->modify('+1 year')->modify('-1 day');
    $end_period = $end_period_date->format('Y-m-d');

    $insert_sql = "INSERT INTO tenants
    (tenant_code, property_code, apartment_code, firstname, lastname, gender, email, phone, 
     photo, occupation, name_of_employer, employer_address, employer_contact, lease_start_date, 
     lease_end_date, temp_lease_end_date, payment_frequency, referee_name, referee_phone, emergency_contact_name, 
     emergency_contact_phone, password, created_by, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

    logActivity("SQL Query: " . $insert_sql);

    $insert_stmt = $conn->prepare($insert_sql);
    if (!$insert_stmt) {
        throw new Exception('Prepare failed for insert: ' . $conn->error);
    }

    $created_by = is_numeric($userId) ? (int) $userId : $userId;

    $insert_stmt->bind_param(
        'ssssssssssssssssssssssi',
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
        // $inputs['lease_end_date'],
        $end_period,
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

    // When an apartment becomes OCCUPIED update the status on properties table
$updatePropertyStmt = $conn->prepare("
    UPDATE properties 
    SET occupied_apartments = occupied_apartments + 1
    WHERE property_code = ?
");
$updatePropertyStmt->bind_param("s", $inputs['property_code']);
$updatePropertyStmt->execute();
$updatePropertyStmt->close();



    // ==================== PAYMENT RECORDING ====================
    logActivity("Step 3.8: Recording payments");

    $current_date = date('Y-m-d');

    // Calculate payment amounts based on frequency
    $annual_rent = (float) $rent_amount;
    $payment_amount_per_period = 0;

    switch ($inputs['payment_frequency']) {
        case 'Monthly':
            $payment_amount_per_period = $annual_rent / 12;
            break;
        case 'Quarterly':
            $payment_amount_per_period = $annual_rent / 4;
            break;
        case 'Semi-Annually':
            $payment_amount_per_period = $annual_rent / 2;
            break;
        case 'Annually':
            $payment_amount_per_period = $annual_rent;
            break;
        default:
            $payment_amount_per_period = $annual_rent;
    }

    $payment_amount_per_period = round($payment_amount_per_period, 2);
    $balance = round($annual_rent - $payment_amount_per_period, 2);

    logActivity("Payment calculation - Annual Rent: {$annual_rent}, Frequency: {$inputs['payment_frequency']}, Payment per period: {$payment_amount_per_period}, Balance: {$balance}");

    // Generate unique identifiers
    $receipt_number = generateReceiptNumber();
    $reference_number = generateReferenceNumber($tenant_code);

    // Calculate rent period (1 year from start date)
    $rent_period_start = $inputs['lease_start_date'];
    $rent_period_end_date = new DateTime($rent_period_start);
    $rent_period_end_date->modify('+1 year')->modify('-1 day');
    $rent_period_end = $rent_period_end_date->format('Y-m-d');

    // Calculate due date for the payment
    $due_date = calculateDueDate($inputs['payment_frequency'], $rent_period_end);

    logActivity("Rent period: {$rent_period_start} to {$rent_period_end}");
    logActivity("Due date: {$due_date}");

    // ==================== INSERT INTO RENT_PAYMENTS TABLE ====================
    logActivity("Step 3.8a: Inserting into rent_payments table");

    $rent_payment_id = 'RENT_' . strtoupper(uniqid());
    $payment_period_label = ($inputs['payment_frequency'] === 'Monthly') ? 'Monthly Payment' :
        (($inputs['payment_frequency'] === 'Quarterly') ? 'Quarterly Payment' :
            (($inputs['payment_frequency'] === 'Semi-Annually') ? 'Semi-Annual Payment' : 'Annual Payment'));

    $insert_rent_payment = $conn->prepare("
        INSERT INTO rent_payments (
            rent_payment_id,
            tenant_code,
            apartment_code,
            amount,
            amount_paid,
            balance,
            payment_date,
            payment_method,
            payment_period,
            period_start_date,
            period_end_date,
            due_date,
            reference_number,
            status,
            payment_type,
            receipt_number,
            notes,
            agreed_rent_amount,
            payment_amount_per_period,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    if (!$insert_rent_payment) {
        throw new Exception('Prepare failed for rent_payments insert: ' . $conn->error);
    }

    $payment_method = 'cash';
    $status = "";
    if($inputs['payment_frequency'] == 'Annually'){
        $status = 'completed';
    }
    else{
        $status = 'ongoing';
    }
    $payment_type = 'rent';
    $notes = "Initial rent payment for period: {$rent_period_start} to {$rent_period_end}";

    $insert_rent_payment->bind_param(
        "sssdddsssssssssssdd",
        $rent_payment_id,
        $tenant_code,
        $inputs['apartment_code'],
        $annual_rent,
        $payment_amount_per_period,
        $balance,
        $current_date,
        $payment_method,
        $payment_period_label,
        $rent_period_start,
        $rent_period_end,
        $due_date,
        $reference_number,
        $status,
        $payment_type,
        $receipt_number,
        $notes,
        $annual_rent,
        $payment_amount_per_period
    );

    if (!$insert_rent_payment->execute()) {
        logActivity("SQL Error (Rent Payment): " . $insert_rent_payment->error);
        throw new Exception('Failed to insert rent_payments record: ' . $insert_rent_payment->error);
    }

    logActivity("Rent payment record inserted successfully. Rent Payment ID: {$rent_payment_id}");


// ==================== CREATE PAYMENT TRACKER RECORDS ====================
logActivity("Step 3.8b: Creating ALL payment tracker records for the entire lease period");

// FIX: Use the FULL YEAR lease end date, not the input lease_end_date
$tracker_start_date = new DateTime($rent_period_start);  // 2026-05-02
$lease_full_end = new DateTime($rent_period_end);        // 2027-05-01 (calculated from +1 year)
$payment_frequency = $inputs['payment_frequency'];

logActivity("Tracker generation - Period from: {$tracker_start_date->format('Y-m-d')} to: {$lease_full_end->format('Y-m-d')}");

// Calculate tracker intervals based on payment frequency
$interval_months = 0;
switch ($payment_frequency) {
    case 'Monthly': $interval_months = 1; break;
    case 'Quarterly': $interval_months = 3; break;
    case 'Semi-Annually': $interval_months = 6; break;
    case 'Annually': $interval_months = 12; break;
    default: $interval_months = 1;
}

$tracker_count = 0;
$period_number = 1;
$remaining_balance = $annual_rent;

// Prepare the insert statement for ALL periods (initially all 'available')
$insert_tracker = $conn->prepare("
    INSERT INTO rent_payment_tracker (
        rent_payment_id,
        tenant_code,
        apartment_code,
        period_number,
        start_date,
        end_date,
        remaining_balance,
        amount_paid,
        payment_date,
        status,
        payment_id,
        created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, 0, NULL, 'available', ?, NOW())
");

if (!$insert_tracker) {
    throw new Exception('Prepare failed for tracker insert: ' . $conn->error);
}

// Generate ALL periods from lease start to FULL YEAR lease end
$current_start = clone $tracker_start_date;

while ($current_start <= $lease_full_end) {
    // Calculate period end date
    $current_end = clone $current_start;
    $current_end->modify("+{$interval_months} months");
    $current_end->modify('-1 day');
    
    // Adjust if end date exceeds full lease end
    if ($current_end > $lease_full_end) {
        $current_end = clone $lease_full_end;
    }
    
    $period_start_formatted = $current_start->format('Y-m-d');
    $period_end_formatted = $current_end->format('Y-m-d');
    
    // Generate UNIQUE payment_id for this tracker record
    $tracker_payment_id = 'TRAK_' . strtoupper(uniqid()) . '_' . $period_number;
    
    logActivity("Creating tracker record #{$period_number}: {$period_start_formatted} to {$period_end_formatted} with status 'available'");
    logActivity("Generated payment_id: {$tracker_payment_id}");
    
    $insert_tracker->bind_param(
        "sssissds",
        $rent_payment_id,
        $tenant_code,
        $inputs['apartment_code'],
        $period_number,
        $period_start_formatted,
        $period_end_formatted,
        $remaining_balance,
        $tracker_payment_id
    );
    
    if (!$insert_tracker->execute()) {
        logActivity("Warning: Failed to insert tracker record for period {$period_number}: " . $insert_tracker->error);
    } else {
        logActivity("Tracker record #{$period_number} created successfully with payment_id: {$tracker_payment_id}");
        $tracker_count++;
    }
    
    // Update remaining balance for next period
    $remaining_balance -= $payment_amount_per_period;
    
    // Move to next period
    $current_start = clone $current_end;
    $current_start->modify('+1 day');
    $period_number++;
    
    // Safety break to prevent infinite loop
    if ($period_number > 100) break;
}

$insert_tracker->close();
logActivity("Created {$tracker_count} payment tracker records for the entire lease period");
// ==================== UPDATE FIRST PERIOD TO 'PAID' ====================
logActivity("Step 3.8c: Updating first period to 'paid' status (onboarding payment)");

// First, get the payment_id of the first period
$get_first_payment_id = $conn->prepare("
    SELECT payment_id FROM rent_payment_tracker 
    WHERE rent_payment_id = ? AND period_number = 1
");
$get_first_payment_id->bind_param("s", $rent_payment_id);
$get_first_payment_id->execute();
$first_result = $get_first_payment_id->get_result();
$first_tracker = $first_result->fetch_assoc();
$get_first_payment_id->close();

if ($first_tracker) {
    $first_payment_id = $first_tracker['payment_id'];
    $action = "APPROVED FIRST RENT PAYMENT";
    $notes = "New Tenant First Rent Payment at Onboarding";
    
    $update_first_tracker = $conn->prepare("
        UPDATE rent_payment_tracker 
        SET status = 'paid', 
            payment_date = NOW(),
            verified_by = ?,
            verified_at = NOW(),
            admin_notes = CONCAT(IFNULL(admin_notes, ''), '\n[" . strtoupper($action) . "] ', NOW(), ' by Admin ID: {$userId}\nNotes: {$notes}'),
            amount_paid = ?,
            payment_method = 'cash',
            payment_reference = ?
        WHERE rent_payment_id = ? 
        AND period_number = 1
        LIMIT 1
    ");
    
    if (!$update_first_tracker) {
        throw new Exception('Prepare failed for first period update: ' . $conn->error);
    }
    
    $update_first_tracker->bind_param("sdss", $userId, $payment_amount_per_period, $reference_number, $rent_payment_id);
    $update_first_tracker->execute();
    
    if ($update_first_tracker->affected_rows > 0) {
        logActivity("First payment period (#1) marked as 'paid' with payment_id: {$first_payment_id}");
        logActivity("Amount paid: ₦{$payment_amount_per_period}, Reference: {$reference_number}");
    } else {
        logActivity("WARNING: Could not update first period - it may not exist or already paid");
    }
    $update_first_tracker->close();
} else {
    logActivity("ERROR: Could not find first period tracker record");
}

// ==================== VERIFY TRACKER RECORDS ====================
$verify_tracker = $conn->prepare("
    SELECT period_number, start_date, end_date, status, amount_paid, payment_id, payment_date
    FROM rent_payment_tracker 
    WHERE rent_payment_id = ? 
    ORDER BY period_number
");
$verify_tracker->bind_param("s", $rent_payment_id);
$verify_tracker->execute();
$verify_result = $verify_tracker->get_result();

logActivity("=== TRACKER RECORDS VERIFICATION ===");
while ($row = $verify_result->fetch_assoc()) {
    logActivity("Period #{$row['period_number']}: {$row['start_date']} to {$row['end_date']} | Status: {$row['status']} | Payment ID: {$row['payment_id']} | Amount Paid: {$row['amount_paid']} | Payment Date: {$row['payment_date']}");
}
$verify_tracker->close();

    // ==================== RECORD SECURITY DEPOSIT IN PAYMENTS TABLE ====================
    if ($security_deposit > 0) {
        logActivity("Step 3.8c: Recording security deposit payment of ₦{$security_deposit}");

        $deposit_description = "Security Deposit for apartment {$inputs['apartment_code']}";
        $payment_category = 'security_deposit';
        $deposit_due_date = $inputs['lease_start_date'];
        $deposit_receipt = generateReceiptNumber() . '-DEP';
        $deposit_reference = generateReferenceNumber($tenant_code) . '-DEP';

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
            ) VALUES (?, ?, ?, 0, NOW(), ?, ?, 'completed', ?, ?, ?, ?, NOW(), ?)
        ");

        if (!$insert_deposit) {
            throw new Exception('Prepare failed for deposit payment insert: ' . $conn->error);
        }

        $insert_deposit->bind_param(
            "ssdsssssss",
            $tenant_code,
            $inputs['apartment_code'],
            $security_deposit,
            $deposit_due_date,
            $payment_method,
            $deposit_receipt,
            $deposit_reference,
            $deposit_description,
            $created_by,
            $payment_category
        );

        if (!$insert_deposit->execute()) {
            logActivity("SQL Error (Deposit Payment): " . $insert_deposit->error);
            throw new Exception('Failed to record security deposit payment: ' . $insert_deposit->error);
        }

        $deposit_payment_id = $insert_deposit->insert_id;
        $insert_deposit->close();
        logActivity("Security deposit recorded successfully. Payment ID: {$deposit_payment_id}");
    } else {
        logActivity("Security deposit amount is 0, skipping deposit payment");
    }

    // ==================== UPDATE TENANT WITH AGREED RENT INFORMATION ====================
    logActivity("Step 3.8d: Updating tenant with agreed rent information");

    // FIXED: Added agreed_payment_frequency to the UPDATE statement
    $update_tenant = $conn->prepare("
        UPDATE tenants 
        SET agreed_rent_amount = ?,
            payment_amount_per_period = ?,
            rent_balance = ?,
            agreed_payment_frequency = ?
        WHERE tenant_code = ?
    ");

    $update_tenant->bind_param("dddss", $annual_rent, $payment_amount_per_period, $balance, $inputs['payment_frequency'], $tenant_code);
    $update_tenant->execute();
    $update_tenant->close();

    logActivity("Tenant updated with agreed rent - Annual: {$annual_rent}, Per Period: {$payment_amount_per_period}, Balance: {$balance}, Frequency: {$inputs['payment_frequency']}");

    // Commit transaction
    logActivity("Step 3.9: Committing transaction");
    $conn->commit();
    logActivity("Transaction committed successfully");

    // Log success
    logActivity("Tenant {$tenant_code} ({$inputs['firstname']} {$inputs['lastname']}) onboarded successfully by user {$userId}");
    logActivity("Rent Payment ID: {$rent_payment_id}");
    logActivity("Payment tracker records created: {$tracker_count}");

    // Consume CSRF token after successful operation
    consumeCsrfToken(CSRF_FORM_NAME);

    // Return success response
    $response = [
        'success' => true,
        'message' => 'New Tenant has been successfully Onboarded!',
        'tenant_code' => $tenant_code,
        'rent_payment_id' => $rent_payment_id,
        'data' => [
            'name' => $inputs['firstname'] . ' ' . $inputs['lastname'],
            'email' => $inputs['email'],
            'apartment' => $inputs['apartment_code'],
            'lease_period' => $actual_months . ' months',
            'payment_frequency' => $inputs['payment_frequency'],
            'payment_amount_per_period' => $payment_amount_per_period,
            'annual_rent' => $annual_rent,
            'balance' => $balance,
            'payment_tracker_count' => $tracker_count,
            'payments_recorded' => [
                'security_deposit' => $security_deposit,
                'total_initial_paid' => $payment_amount_per_period + $security_deposit
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
 * Calculate due date based on payment frequency and period end date
 * 
 * @param string $payment_frequency Monthly, Quarterly, Semi-Annually, Annually
 * @param string $period_end_date The end date of the payment period (Y-m-d format)
 * @return string The calculated due date (Y-m-d format)
 */
function calculateDueDate($payment_frequency, $period_end_date)
{
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