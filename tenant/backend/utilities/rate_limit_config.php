<?php
// utilities/rate_limit_config.php

return [
    // Default settings (applies to all endpoints not explicitly listed)
    'default' => [
        'limit' => 30,
        'seconds' => 60
    ],
    
    // Endpoint-specific overrides
    'endpoints' => [
        
        // ============================================
        // AUTHENTICATION ENDPOINTS (VERY STRICT)
        // ============================================
        'login' => [
            'limit' => 5,
            'seconds' => 60
        ],
        'logout' => [
            'limit' => 10,
            'seconds' => 60
        ],
        'change_password' => [
            'limit' => 5,
            'seconds' => 60
        ],
        'change_default_password' => [
            'limit' => 5,
            'seconds' => 60
        ],
        'reset_password' => [
            'limit' => 3,
            'seconds' => 120
        ],
        'account_reactivation' => [
            'limit' => 3,
            'seconds' => 300
        ],
        'submit_reactivation_request' => [
            'limit' => 3,
            'seconds' => 300
        ],
        'set_secret_question_answer' => [
            'limit' => 5,
            'seconds' => 60
        ],
        'reset_secret_question_answer' => [
            'limit' => 3,
            'seconds' => 120
        ],
        'get_secret_question' => [
            'limit' => 10,
            'seconds' => 60
        ],
        
        // ============================================
        // OTP ENDPOINTS (VERY STRICT - prevent abuse)
        // ============================================
        'send_otp' => [
            'limit' => 3,
            'seconds' => 120
        ],
        'verify_otp' => [
            'limit' => 5,
            'seconds' => 60
        ],
        'sendOTPEmail' => [
            'limit' => 3,
            'seconds' => 120
        ],
        'sendOTPGmail' => [
            'limit' => 3,
            'seconds' => 120
        ],
        
        // ============================================
        // SESSION & ACTIVITY ENDPOINTS
        // ============================================
        'check_session' => [
            'limit' => 60,
            'seconds' => 60
        ],
        'heartbeat' => [
            'limit' => 60,
            'seconds' => 60
        ],
        'activity_checker' => [
            'limit' => 60,
            'seconds' => 60
        ],
        
        // ============================================
        // PAYMENT ENDPOINTS (STRICT - financial ops)
        // ============================================
        'initiate_rent_payment' => [
            'limit' => 5,
            'seconds' => 60
        ],
        'pay_fees' => [
            'limit' => 5,
            'seconds' => 60
        ],
        'fetch_payment_history' => [
            'limit' => 30,
            'seconds' => 60
        ],
        'fetch_tenant_rent_payment_history' => [
            'limit' => 30,
            'seconds' => 60
        ],
        'fetch_tenant_rent_payment_details' => [
            'limit' => 30,
            'seconds' => 60
        ],
        'download_receipt' => [
            'limit' => 20,
            'seconds' => 60
        ],
        
        // ============================================
        // FEE ENDPOINTS
        // ============================================
        'fetch_tenant_fees' => [
            'limit' => 20,
            'seconds' => 60
        ],
        'fetch_applicable_fees' => [
            'limit' => 20,
            'seconds' => 60
        ],
        'fetch_fee_types' => [
            'limit' => 20,
            'seconds' => 60
        ],
        'get_payment_by_fee_id' => [
            'limit' => 20,
            'seconds' => 60
        ],
        'download_fee_receipt' => [
            'limit' => 20,
            'seconds' => 60
        ],
        
        // ============================================
        // MAINTENANCE ENDPOINTS
        // ============================================
        'create_maintenance_request' => [
            'limit' => 10,
            'seconds' => 60
        ],
        'fetch_maintenance_requests' => [
            'limit' => 30,
            'seconds' => 60
        ],
        'fetch_maintenance_request_details' => [
            'limit' => 30,
            'seconds' => 60
        ],
        'cancel_maintenance_request' => [
            'limit' => 10,
            'seconds' => 60
        ],
        'confirm_resolution' => [
            'limit' => 10,
            'seconds' => 60
        ],
        
        // ============================================
        // EVACUATION ENDPOINTS
        // ============================================
        'request_evacuation' => [
            'limit' => 5,
            'seconds' => 60
        ],
        'cancel_evacuation_request' => [
            'limit' => 10,
            'seconds' => 60
        ],
        'fetch_evacuation_request' => [
            'limit' => 30,
            'seconds' => 60
        ],
        'fetch_evacuation_request_details' => [
            'limit' => 30,
            'seconds' => 60
        ],
        
        // ============================================
        // DOCUMENT ENDPOINTS
        // ============================================
        'upload_document' => [
            'limit' => 10,
            'seconds' => 60
        ],
        'delete_document' => [
            'limit' => 10,
            'seconds' => 60
        ],
        'fetch_documents' => [
            'limit' => 30,
            'seconds' => 60
        ],
        'download_document' => [
            'limit' => 20,
            'seconds' => 60
        ],
        
        // ============================================
        // TENANT / PROFILE ENDPOINTS
        // ============================================
        'fetch_dashboard_data' => [
            'limit' => 30,
            'seconds' => 60
        ],
        'fetch_user_data' => [
            'limit' => 30,
            'seconds' => 60
        ],
        'fetch_apartment_details' => [
            'limit' => 30,
            'seconds' => 60
        ],
        'fetch_unit_property_type' => [
            'limit' => 30,
            'seconds' => 60
        ],
        'update_profile' => [
            'limit' => 10,
            'seconds' => 60
        ],
        'update_profile_photo' => [
            'limit' => 10,
            'seconds' => 60
        ],
        'initiate_new_lease_cycle' => [
            'limit' => 3,
            'seconds' => 300
        ],
        'download_invoice' => [
            'limit' => 20,
            'seconds' => 60
        ],
        
        // ============================================
        // NOTIFICATION ENDPOINTS (LENIENT - polling)
        // ============================================
        'fetch_notifications' => [
            'limit' => 60,
            'seconds' => 60
        ],
        'mark_notification_read' => [
            'limit' => 30,
            'seconds' => 60
        ],
        'mark_all_notification_read' => [
            'limit' => 10,
            'seconds' => 60
        ],
        'notification' => [
            'limit' => 60,
            'seconds' => 60
        ],
        
        // ============================================
        // NAVBAR / UI ENDPOINTS
        // ============================================
        'navbar' => [
            'limit' => 60,
            'seconds' => 60
        ],
    ],
    
    // Global security rules
    'security' => [
        'enable_logging' => true,
        'log_blocked_attempts' => true,
        'block_ip_after' => 5,      // Block IP after X rate limit violations
        'block_duration' => 3600,   // Block for 1 hour
    ]
];