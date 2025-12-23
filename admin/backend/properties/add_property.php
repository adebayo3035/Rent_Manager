<?php
// onboard_admin.php — Production-ready property onboarding
header('Content-Type: application/json; charset=utf-8');

// Define constants
define('MAX_FILE_SIZE', 500000); // 500KB
define('MAX_NAME_LENGTH', 255);
define('MAX_ADDRESS_LENGTH', 500);
define('MAX_PHONE_LENGTH', 20);
define('MAX_NOTES_LENGTH', 1000);
define('MIN_PROPERTY_UNITS', 1);
define('MAX_PROPERTY_UNITS', 1000);
define('CSRF_FORM_NAME', 'add_property_form');

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';

// Authentication and authorization
$auth = requireAuth([
    'method' => 'POST',
    'rate_key' => 'add_property',
    'rate_limit' => [10, 60],
    'csrf' => [
        'enabled' => true,
        'form_name' => 'add_property_form'
    ],
    'roles' => ['Super Admin', 'Admin']
]);

$userId = $auth['user_id'];
$userRole = $auth['role'];

logActivity("==== Property Onboarding Started ====");
logActivity("User: {$userId} | Role: {$userRole}");

try {
    // ------------------------- INPUT COLLECTION & SANITIZATION -------------------------
    $rawInputs = [
        'agent_code' => $_POST['property_agent_code'] ?? '',
        'property_type'  => $_POST['property_type'] ?? '',
        'property_type_unit' => $_POST['property_type_unit'] ?? '',
        'property_name'     => $_POST['property_name'] ?? '',
        'client_code'     => $_POST['property_client_code'] ?? '',
        'property_address'     => $_POST['property_address'] ?? '',
        'property_city'   => $_POST['property_city'] ?? '',
        'property_state'    => $_POST['property_state'] ?? '',
        'property_country'    => $_POST['property_country'] ?? '',
        'property_contact_name'    => $_POST['property_contact_name'] ?? '',
        'property_contact_phone'    => $_POST['property_contact_phone'] ?? '',
        'property_note'    => $_POST['property_note'] ?? '',
    ];

    // Log non-sensitive inputs
    $logInputs = $rawInputs;
    unset($logInputs['property_contact_phone']);
    logActivity("Raw inputs received: " . json_encode($logInputs));

    // Sanitize inputs
    $inputs = sanitize_inputs($rawInputs);

    // ------------------------- COMPREHENSIVE VALIDATION -------------------------
    $errors = [];

    // Required fields validation
    $required = [
        'agent_code' => 'Agent Code',
        'property_type' => 'Property Type',
        'property_type_unit' => 'Number of Units',
        'client_code' => 'Client Code',
        'property_name' => 'Property Name',
        'property_address' => 'Property Address',
        'property_city' => 'City',
        'property_state' => 'State',
        'property_country' => 'Country',
        'property_contact_name' => 'Contact Name',
        'property_contact_phone' => 'Contact Phone'
    ];

    foreach ($required as $field => $label) {
        if (empty(trim($inputs[$field]))) {
            $errors[] = "{$label} is required.";
        }
    }

    // Property name validation
    if (!empty($inputs['property_name'])) {
        if (strlen($inputs['property_name']) > MAX_NAME_LENGTH) {
            $errors[] = "Property name cannot exceed " . MAX_NAME_LENGTH . " characters.";
        }
        
        // Check for potentially malicious content
        if (preg_match('/[<>"\']/', $inputs['property_name'])) {
            $errors[] = "Property name contains invalid characters.";
        }
    }

    // Address validation
    if (!empty($inputs['property_address']) && strlen($inputs['property_address']) > MAX_ADDRESS_LENGTH) {
        $errors[] = "Address cannot exceed " . MAX_ADDRESS_LENGTH . " characters.";
    }

    // Property type unit validation
    if (!empty($inputs['property_type_unit'])) {
        $unitCount = (int)$inputs['property_type_unit'];
        if ($unitCount < MIN_PROPERTY_UNITS || $unitCount > MAX_PROPERTY_UNITS) {
            $errors[] = "Number of units must be between " . MIN_PROPERTY_UNITS . " and " . MAX_PROPERTY_UNITS . ".";
        }
    }

    // Phone validation
    if (!empty($inputs['property_contact_phone'])) {
        if (!validate_phone($inputs['property_contact_phone'])) {
            $errors[] = "Invalid phone number format. Must be a valid phone number.";
        }
        
        // Normalize phone number
        $normalizedPhone = preg_replace('/\D+/', '', $inputs['property_contact_phone']);
        if (strlen($normalizedPhone) < 7 || strlen($normalizedPhone) > MAX_PHONE_LENGTH) {
            $errors[] = "Phone number must be between 7 and " . MAX_PHONE_LENGTH . " digits.";
        }
        $inputs['property_contact_phone'] = $normalizedPhone;
    }

    // Notes validation
    if (!empty($inputs['property_note']) && strlen($inputs['property_note']) > MAX_NOTES_LENGTH) {
        $errors[] = "Notes cannot exceed " . MAX_NOTES_LENGTH . " characters.";
    }

    // If any validation errors, return them
    if (!empty($errors)) {
        logActivity("Validation errors: " . implode(" | ", $errors));
        json_error(implode(" ", $errors), 400);
    }

    logActivity("Input validation passed.");

    // ------------------------- FILE UPLOAD VALIDATION -------------------------
    if (!isset($_FILES['property_photo'])) {
        logActivity("Photo missing from request.");
        json_error('Please upload a photo of the property.', 400);
    }

    $photo = $_FILES['property_photo'];

    // Check for upload errors
    if ($photo['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive.',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive.',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION => 'PHP extension stopped the file upload.'
        ];
        
        $errorMsg = $uploadErrors[$photo['error']] ?? 'Unknown upload error';
        logActivity("Upload error {$photo['error']}: {$errorMsg}");
        json_error("Photo upload failed: {$errorMsg}", 400);
    }

    // File type and size validation
    $img_tmp = $photo['tmp_name'];
    $img_name = basename($photo['name']); // Security: remove path info
    $img_size = $photo['size'];
    $img_type = mime_content_type($img_tmp);

    logActivity("Uploaded image: {$img_name}, size={$img_size}, type={$img_type}");

    $allowed_mime = ['image/jpeg', 'image/png', 'image/jpg'];
    $allowed_ext = ['jpg', 'jpeg', 'png'];

    $ext = strtolower(pathinfo($img_name, PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed_ext, true) || !in_array($img_type, $allowed_mime, true)) {
        logActivity("Rejected invalid image: EXT={$ext}, MIME={$img_type}");
        json_error('Only JPG, JPEG & PNG images are allowed.', 400);
    }

    if ($img_size > MAX_FILE_SIZE) {
        logActivity("Image too large: {$img_size} bytes");
        json_error('Image too large. Maximum allowed size is ' . (MAX_FILE_SIZE / 1024) . 'KB.', 400);
    }

    // Validate image content
    $image_info = @getimagesize($img_tmp);
    if (!$image_info) {
        logActivity("Invalid image file content");
        json_error('Invalid image file. Please upload a valid image.', 400);
    }

    // Generate unique filename
    $file_hash = hash_file('sha256', $img_tmp);
    $file_name = $file_hash . '.' . $ext;

    logActivity("Image hashed to unique name: {$file_name}");

    $upload_dir = __DIR__ . '/property_photos/';
    $upload_path = $upload_dir . $file_name;

    // Create upload directory if it doesn't exist
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0750, true)) { // More restrictive permissions
            logActivity("Failed to create upload directory: {$upload_dir}");
            json_error("Server error preparing upload directory.", 500);
        }
        logActivity("Created upload directory: {$upload_dir}");
    }

    // Check if file already exists (prevents duplicate storage)
    if (file_exists($upload_path)) {
        logActivity("Image already exists on disk: {$file_name}");
        // This is okay - we'll use the existing file
    }

    // ------------------------- DATABASE TRANSACTION -------------------------
    $conn->begin_transaction();
    logActivity("Database transaction started.");

    try {
        // ------------------------- FOREIGN KEY VALIDATION -------------------------
        // Validate agent exists and is active
        if (!empty($inputs['agent_code'])) {
            $agentStmt = $conn->prepare("SELECT agent_code FROM agents WHERE agent_code = ? AND status = 1 LIMIT 1");
            $agentStmt->bind_param("s", $inputs['agent_code']);
            $agentStmt->execute();
            $agentStmt->store_result();
            
            if ($agentStmt->num_rows === 0) {
                $agentStmt->close();
                throw new Exception("Agent not found or inactive.", 400);
            }
            $agentStmt->close();
            logActivity("Agent validation passed: {$inputs['agent_code']}");
        }

        // Validate client exists and is active
        if (!empty($inputs['client_code'])) {
            $clientStmt = $conn->prepare("SELECT client_code FROM clients WHERE client_code = ? AND status = 1 LIMIT 1");
            $clientStmt->bind_param("s", $inputs['client_code']);
            $clientStmt->execute();
            $clientStmt->store_result();
            
            if ($clientStmt->num_rows === 0) {
                $clientStmt->close();
                throw new Exception("Client not found or inactive.", 400);
            }
            $clientStmt->close();
            logActivity("Client validation passed: {$inputs['client_code']}");
        }

        // Validate property type exists and is active
        $property_type_id = (int)$inputs['property_type'];
        if ($property_type_id <= 0) {
            throw new Exception("Invalid property type selected.", 400);
        }
        
        $typeStmt = $conn->prepare("SELECT type_name FROM property_type WHERE type_id = ? AND status = 1 LIMIT 1");
        $typeStmt->bind_param("i", $property_type_id);
        $typeStmt->execute();
        $typeStmt->store_result();
        
        if ($typeStmt->num_rows === 0) {
            $typeStmt->close();
            throw new Exception("Property type not found or inactive.", 400);
        }
        $typeStmt->close();
        logActivity("Property type validation passed: ID {$property_type_id}");

        // Check for duplicate property name (case-insensitive, active properties only)
        $dupStmt = $conn->prepare("
            SELECT property_code FROM properties 
            WHERE LOWER(name) = LOWER(?) 
            LIMIT 1
        ");
        $dupStmt->bind_param("s", $inputs['property_name']);
        $dupStmt->execute();
        $dupStmt->store_result();
        
        if ($dupStmt->num_rows > 0) {
            $dupStmt->close();
            throw new Exception("A property with this name already exists.", 409);
        }
        $dupStmt->close();
        logActivity("Duplicate property name check passed.");

        // Check if photo already exists in database
        $photoStmt = $conn->prepare("SELECT property_code FROM properties WHERE photo = ? LIMIT 1");
        $photoStmt->bind_param("s", $file_name);
        $photoStmt->execute();
        $photoStmt->store_result();
        
        if ($photoStmt->num_rows > 0) {
            $photoStmt->close();
            throw new Exception("This image has already been uploaded for another property.", 409);
        }
        $photoStmt->close();
        logActivity("Duplicate photo check passed.");

        // ------------------------- MOVE UPLOADED FILE -------------------------
        if (!file_exists($upload_path)) {
            if (!move_uploaded_file($img_tmp, $upload_path)) {
                logActivity("Failed to move file to {$upload_path}");
                throw new Exception("Failed to save uploaded image.", 500);
            }
            logActivity("Image saved successfully: {$upload_path}");
        } else {
            logActivity("Using existing image file: {$upload_path}");
        }

        // ------------------------- GENERATE PROPERTY CODE -------------------------
        $property_code = "PROP" . random_unique_id();
        logActivity("Generated unique property code: {$property_code}");

        // ------------------------- DATABASE INSERT -------------------------
        $insert_sql = "
            INSERT INTO properties
            (agent_code, property_code, property_type_id, property_type_unit, client_code, 
             name, address, city, state, country, contact_name, contact_phone, 
             photo, notes, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ";

        $stmt = $conn->prepare($insert_sql);
        if (!$stmt) {
            logActivity("DB prepare failed: " . $conn->error);
            throw new Exception("Database prepare error: " . $conn->error, 500);
        }

        // Prepare parameters
        $property_type_unit = (int)$inputs['property_type_unit'];
        $created_by = is_numeric($userId) ? (int)$userId : $userId;
        
        // Note: $bindTypes corrected to match parameter count (15 parameters)
        $bindTypes = "ssiissssssssssi";
        
        $bindParams = [
            $inputs['agent_code'],
            $property_code,
            $property_type_id,
            $property_type_unit,
            $inputs['client_code'], // WAS MISSING IN YOUR ORIGINAL CODE
            $inputs['property_name'],
            $inputs['property_address'],
            $inputs['property_city'],
            $inputs['property_state'],
            $inputs['property_country'],
            $inputs['property_contact_name'],
            $inputs['property_contact_phone'],
            $file_name,
            $inputs['property_note'] ?? null,
            $created_by
        ];

        // Bind parameters
        $stmt->bind_param($bindTypes, ...$bindParams);

        if (!$stmt->execute()) {
            logActivity("DB execute failed: " . $stmt->error);
            throw new Exception("Failed to insert property: " . $stmt->error, 500);
        }

        $newPropertyId = $stmt->insert_id;
        $stmt->close();
        logActivity("Property inserted successfully. ID: {$newPropertyId}, Code: {$property_code}");

        // ------------------------- CREATE AUDIT TRAIL -------------------------
        // $auditSql = "INSERT INTO property_audit_log 
        //     (property_code, action, performed_by, details, ip_address, user_agent)
        //     VALUES (?, 'CREATE', ?, ?, ?, ?)";
        
        // $auditStmt = $conn->prepare($auditSql);
        // if ($auditStmt) {
        //     $details = json_encode([
        //         'property_name' => $inputs['property_name'],
        //         'property_type_id' => $property_type_id,
        //         'units' => $property_type_unit,
        //         'agent_code' => $inputs['agent_code'],
        //         'client_code' => $inputs['client_code']
        //     ]);
        //     $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        //     $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
            
        //     $auditStmt->bind_param('sssss', 
        //         $property_code, 
        //         $created_by, 
        //         $details, 
        //         $ipAddress, 
        //         $userAgent
        //     );
        //     $auditStmt->execute();
        //     $auditStmt->close();
        //     logActivity("Audit log created for property {$property_code}");
        // }

        // ------------------------- COMMIT TRANSACTION -------------------------
        $conn->commit();
        logActivity("Transaction committed successfully.");

        // Clean up temporary file if created
        if (isset($clean_tmp) && file_exists($clean_tmp)) {
            @unlink($clean_tmp);
        }

         // Consume CSRF token after successful operation
        consumeCsrfToken(CSRF_FORM_NAME);

        // ------------------------- SUCCESS RESPONSE -------------------------
        $response = [
            'success' => true,
            'message' => 'Property onboarded successfully!',
            'property_code' => $property_code,
            'property_id' => $newPropertyId,
            'photo_url' => '/property_photos/' . $file_name,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        
        logActivity("==== Property Onboarding Completed Successfully ====");
        
    } catch (Throwable $e) {
        // Rollback transaction
        $conn->rollback();
        logActivity("Transaction rolled back: " . $e->getMessage());
        
        // Clean up uploaded file if it was created
        if (isset($upload_path) && file_exists($upload_path) && 
            (!isset($file_hash) || strpos(basename($upload_path), $file_hash) !== false)) {
            @unlink($upload_path);
            logActivity("Cleaned up uploaded file: {$upload_path}");
        }
        
        // Clean up temporary file
        if (isset($clean_tmp) && file_exists($clean_tmp)) {
            @unlink($clean_tmp);
        }
        
        throw $e; // Re-throw for outer catch block
    }

} catch (Throwable $e) {
    // ------------------------- ERROR HANDLING -------------------------
    $errorCode = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    
    logActivity("FATAL ERROR: " . $e->getMessage() . 
                " | File: " . $e->getFile() . 
                " | Line: " . $e->getLine() . 
                " | Code: " . $errorCode);
    
    // Don't expose internal errors in production
    $errorMessage = ($errorCode === 500 && strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false)
        ? "An internal error occurred while processing your request."
        : $e->getMessage();
    
    json_error($errorMessage, $errorCode);
}