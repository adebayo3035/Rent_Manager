<?php
// notification_helper.php - Helper functions for creating notifications

// require_once __DIR__ . '/config.php';

/**
 * Create a notification for a tenant
 * 
 * @param mysqli $conn Database connection
 * @param string $tenant_code Tenant code
 * @param string $type Notification type (payment, maintenance, document, lease, profile, security, apartment, fee, system)
 * @param string $title Notification title
 * @param string $message Notification message
 * @param array $details Additional details (will be JSON encoded)
 * @param string $priority Priority (low, medium, high, urgent)
 * @param string $action_url URL to navigate when clicked
 * @param string $action_text Button text for action
 * @return int|false Notification ID or false on failure
 */
function createNotification($conn, $tenant_code, $type, $title, $message, $details = [], $priority = 'medium', $action_url = null, $action_text = null) {
    try {
        $details_json = !empty($details) ? json_encode($details) : null;
        
        $query = "
            INSERT INTO tenant_notifications (
                tenant_code, 
                notification_type, 
                title, 
                message, 
                details, 
                priority,
                action_url,
                action_text,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ssssssss", $tenant_code, $type, $title, $message, $details_json, $priority, $action_url, $action_text);
        
        if ($stmt->execute()) {
            $notification_id = $stmt->insert_id;
            $stmt->close();
            
            logActivity("Notification created - ID: {$notification_id}, Tenant: {$tenant_code}, Type: {$type}");
            return $notification_id;
        }
        
        $stmt->close();
        return false;
        
    } catch (Exception $e) {
        logActivity("Error creating notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Create a payment notification
 */
function createPaymentNotification($conn, $tenant_code, $amount, $status, $period_number = null, $receipt_number = null) {
    $title = '';
    $message = '';
    $priority = 'medium';
    $action_url = '../payments.php';
    $action_text = 'View Payments';
    
    switch ($status) {
        case 'initiated':
            $title = 'Payment Initiated';
            $message = "Your payment of ₦" . number_format($amount, 2) . " has been initiated and is pending verification.";
            $priority = 'medium';
            break;
        case 'completed':
        case 'approved':
            $title = 'Payment Successful';
            $message = "Your payment of ₦" . number_format($amount, 2) . " has been successfully verified and completed.";
            $priority = 'low';
            $action_url = "../download_receipt.php?receipt_number={$receipt_number}";
            $action_text = 'Download Receipt';
            break;
        case 'failed':
        case 'rejected':
            $title = 'Payment Failed';
            $message = "Your payment of ₦" . number_format($amount, 2) . " could not be verified. Please contact support.";
            $priority = 'high';
            break;
        case 'pending_verification':
            $title = 'Payment Pending Verification';
            $message = "Your payment of ₦" . number_format($amount, 2) . " is pending admin verification.";
            $priority = 'medium';
            break;
        case 'overdue':
            $title = 'Payment Overdue';
            $message = "Your payment of ₦" . number_format($amount, 2) . " is now overdue. Please make payment immediately.";
            $priority = 'urgent';
            break;
    }
    
    $details = [
        'amount' => $amount,
        'status' => $status,
        'period_number' => $period_number,
        'receipt_number' => $receipt_number
    ];
    
    return createNotification($conn, $tenant_code, 'payment', $title, $message, $details, $priority, $action_url, $action_text);
}

/**
 * Create a maintenance request notification
 */
function createMaintenanceNotification($conn, $tenant_code, $request_id, $issue_type, $status, $admin_note = null) {
    $title = '';
    $message = '';
    $priority = 'medium';
    $action_url = '../maintenance.php';
    $action_text = 'View Request';
    
    switch ($status) {
        case 'submitted':
            $title = 'Maintenance Request Submitted';
            $message = "Your maintenance request for '{$issue_type}' has been submitted successfully.";
            $priority = 'medium';
            break;
        case 'in_progress':
            $title = 'Maintenance Request In Progress';
            $message = "Your maintenance request for '{$issue_type}' is now being processed.";
            $priority = 'medium';
            break;
        case 'completed':
            $title = 'Maintenance Request Completed';
            $message = "Your maintenance request for '{$issue_type}' has been completed.";
            $priority = 'low';
            break;
        case 'rejected':
            $title = 'Maintenance Request Rejected';
            $message = "Your maintenance request for '{$issue_type}' was rejected. Reason: " . ($admin_note ?? 'Not specified');
            $priority = 'high';
            break;
        case 'assigned':
            $title = 'Maintenance Staff Assigned';
            $message = "A maintenance staff has been assigned to your request for '{$issue_type}'.";
            $priority = 'medium';
            break;
    }
    
    $details = [
        'request_id' => $request_id,
        'issue_type' => $issue_type,
        'status' => $status,
        'admin_note' => $admin_note
    ];
    
    return createNotification($conn, $tenant_code, 'maintenance', $title, $message, $details, $priority, $action_url, $action_text);
}

/**
 * Create a document notification
 */
function createDocumentNotification($conn, $tenant_code, $document_name, $action) {
    $title = '';
    $message = '';
    $priority = 'low';
    $action_url = '../documents.php';
    $action_text = 'View Documents';
    
    switch ($action) {
        case 'uploaded':
            $title = 'Document Uploaded';
            $message = "Your document '{$document_name}' has been uploaded successfully.";
            $priority = 'low';
            break;
        case 'deleted':
            $title = 'Document Deleted';
            $message = "Your document '{$document_name}' has been deleted.";
            $priority = 'low';
            break;
        case 'expiring_soon':
            $title = 'Document Expiring Soon';
            $message = "Your document '{$document_name}' will expire soon. Please update it.";
            $priority = 'high';
            break;
        case 'expired':
            $title = 'Document Expired';
            $message = "Your document '{$document_name}' has expired. Please upload a new one.";
            $priority = 'urgent';
            break;
    }
    
    $details = ['document_name' => $document_name, 'action' => $action];
    
    return createNotification($conn, $tenant_code, 'document', $title, $message, $details, $priority, $action_url, $action_text);
}

/**
 * Create a lease notification
 */
function createLeaseNotification($conn, $tenant_code, $action, $value = null) {
    $title = '';
    $message = '';
    $priority = 'high';
    $action_url = '../dashboard.php';
    $action_text = 'View Lease';
    
    switch ($action) {
        case 'renewed':
            $title = 'Lease Renewed';
            $message = "Your lease has been renewed until " . date('F j, Y', strtotime($value)) . ".";
            $priority = 'low';
            break;
        case 'expiring_soon':
            $title = 'Lease Expiring Soon';
            $message = "Your lease will expire in {$value} days. Please contact admin for renewal.";
            $priority = 'urgent';
            break;
        case 'expired':
            $title = 'Lease Expired';
            $message = "Your lease has expired. Please contact admin to renew your lease.";
            $priority = 'urgent';
            break;
        case 'created':
            $title = 'Lease Created';
            $message = "Your lease has been created successfully. Period: " . date('F j, Y', strtotime($value['start'])) . " - " . date('F j, Y', strtotime($value['end']));
            $priority = 'medium';
            break;
        case 'extended':
            $title = 'Lease Extended';
            $message = "Your lease has been extended until " . date('F j, Y', strtotime($value)) . ".";
            $priority = 'medium';
            break;
    }
    
    $details = ['action' => $action, 'value' => $value];
    
    return createNotification($conn, $tenant_code, 'lease', $title, $message, $details, $priority, $action_url, $action_text);
}

/**
 * Create a profile/security notification
 */
function createSecurityNotification($conn, $tenant_code, $action) {
    $title = '';
    $message = '';
    $priority = 'high';
    $action_url = '../profile.php';
    $action_text = 'View Profile';
    
    switch ($action) {
        case 'password_changed':
            $title = 'Password Changed';
            $message = "Your password has been changed successfully. If you didn't make this change, please contact support immediately.";
            $priority = 'high';
            break;
        case 'profile_updated':
            $title = 'Profile Updated';
            $message = "Your profile information has been updated successfully.";
            $priority = 'low';
            break;
        case 'secret_question_set':
            $title = 'Security Question Set';
            $message = "Your security question has been set successfully.";
            $priority = 'medium';
            $action_url = '../profile.php?tab=security';
            $action_text = 'View Security';
            break;
        case 'login_alert':
            $title = 'New Login Detected';
            $message = "A new login to your account was detected. If this wasn't you, please contact support.";
            $priority = 'urgent';
            $action_url = '../profile.php?tab=security';
            $action_text = 'Secure Account';
            break;
        case 'email_changed':
            $title = 'Email Address Changed';
            $message = "Your email address has been updated successfully.";
            $priority = 'medium';
            break;
    }
    
    $details = ['action' => $action];
    
    return createNotification($conn, $tenant_code, 'security', $title, $message, $details, $priority, $action_url, $action_text);
}

/**
 * Create an apartment/assignment notification
 */
function createApartmentNotification($conn, $tenant_code, $apartment_code, $action) {
    $title = '';
    $message = '';
    $priority = 'medium';
    $action_url = '../apartment.php';
    $action_text = 'View Apartment';
    
    switch ($action) {
        case 'assigned':
            $title = 'Apartment Assigned';
            $message = "You have been assigned to Apartment {$apartment_code}.";
            $priority = 'medium';
            break;
        case 'vacated':
            $title = 'Apartment Vacated';
            $message = "You have successfully vacated Apartment {$apartment_code}.";
            $priority = 'low';
            break;
        case 'changed':
            $title = 'Apartment Changed';
            $message = "Your apartment has been changed to {$apartment_code}.";
            $priority = 'high';
            break;
    }
    
    $details = ['apartment_code' => $apartment_code, 'action' => $action];
    
    return createNotification($conn, $tenant_code, 'apartment', $title, $message, $details, $priority, $action_url, $action_text);
}

/**
 * Create a fee notification
 */
function createFeeNotification($conn, $tenant_code, $fee_name, $amount, $due_date, $status) {
    $title = '';
    $message = '';
    $priority = 'medium';
    $action_url = '../fees.php';
    $action_text = 'View Fees';
    
    switch ($status) {
        case 'added':
            $title = 'New Fee Added';
            $message = "A new fee '{$fee_name}' of ₦" . number_format($amount, 2) . " has been added. Due date: " . date('F j, Y', strtotime($due_date));
            $priority = 'high';
            break;
        case 'paid':
            $title = 'Fee Paid';
            $message = "Your fee '{$fee_name}' of ₦" . number_format($amount, 2) . " has been paid successfully.";
            $priority = 'low';
            $action_url = '../payments.php';
            $action_text = 'View Payment';
            break;
        case 'overdue':
            $title = 'Fee Overdue';
            $message = "Your fee '{$fee_name}' of ₦" . number_format($amount, 2) . " is now overdue.";
            $priority = 'urgent';
            break;
        case 'waived':
            $title = 'Fee Waived';
            $message = "Your fee '{$fee_name}' of ₦" . number_format($amount, 2) . " has been waived.";
            $priority = 'low';
            break;
    }
    
    $details = [
        'fee_name' => $fee_name,
        'amount' => $amount,
        'due_date' => $due_date,
        'status' => $status
    ];
    
    return createNotification($conn, $tenant_code, 'fee', $title, $message, $details, $priority, $action_url, $action_text);
}

/**
 * Create a system notification
 */
function createSystemNotification($conn, $tenant_code, $title, $message, $priority = 'medium', $action_url = null, $action_text = null) {
    $details = [];
    
    return createNotification($conn, $tenant_code, 'system', $title, $message, $details, $priority, $action_url, $action_text);
}

/**
 * Create a bulk notification for multiple tenants
 */
function createBulkNotification($conn, $tenant_codes, $type, $title, $message, $details = [], $priority = 'medium', $action_url = null, $action_text = null) {
    $success_count = 0;
    $failed_count = 0;
    
    foreach ($tenant_codes as $tenant_code) {
        if (createNotification($conn, $tenant_code, $type, $title, $message, $details, $priority, $action_url, $action_text)) {
            $success_count++;
        } else {
            $failed_count++;
        }
    }
    
    logActivity("Bulk notification - Sent to {$success_count} tenants, Failed: {$failed_count}");
    return ['success' => $success_count, 'failed' => $failed_count];
}

/**
 * Create a reminder notification for upcoming due dates
 */
function createDueDateReminder($conn, $tenant_code, $type, $item_name, $amount, $due_date, $days_left) {
    $title = '';
    $message = '';
    $priority = 'high';
    
    switch ($type) {
        case 'payment':
            $title = 'Rent Payment Reminder';
            $message = "Your rent payment of ₦" . number_format($amount, 2) . " is due in {$days_left} days on " . date('F j, Y', strtotime($due_date));
            $action_url = '../payments.php';
            $action_text = 'Make Payment';
            break;
        case 'fee':
            $title = 'Fee Payment Reminder';
            $message = "Your fee '{$item_name}' of ₦" . number_format($amount, 2) . " is due in {$days_left} days on " . date('F j, Y', strtotime($due_date));
            $action_url = '../fees.php';
            $action_text = 'Pay Now';
            break;
        case 'document':
            $title = 'Document Expiry Reminder';
            $message = "Your document '{$item_name}' will expire in {$days_left} days. Please update it.";
            $action_url = '../documents.php';
            $action_text = 'View Documents';
            break;
    }
    
    $details = [
        'type' => $type,
        'item_name' => $item_name,
        'amount' => $amount,
        'due_date' => $due_date,
        'days_left' => $days_left
    ];
    
    return createNotification($conn, $tenant_code, 'reminder', $title, $message, $details, $priority, $action_url, $action_text);
}
?>