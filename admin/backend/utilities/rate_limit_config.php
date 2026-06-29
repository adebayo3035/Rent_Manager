<?php
// utilities/rate_limit_config.php

return [
    // Default settings
    'default' => [
        'limit' => 20,
        'seconds' => 60
    ],
    
    // Endpoint-specific overrides
    'endpoints' => [
        // Fee endpoints (lower limits for heavy operations)
        'fetch_tenant_fees' => [
            'limit' => 10,
            'seconds' => 60
        ],
        'fetch_fee_types' => [
            'limit' => 15,
            'seconds' => 60
        ],
        'fetch_property_fees' => [
            'limit' => 20,
            'seconds' => 60
        ],
        'set_property_fees' => [
            'limit' => 5,
            'seconds' => 60
        ],
        'regenerate_tenant_fees' => [
            'limit' => 3,
            'seconds' => 120
        ],
        'mark_fee_paid' => [
            'limit' => 10,
            'seconds' => 60
        ],
        'manage_fee_type' => [
            'limit' => 5,
            'seconds' => 60
        ],
        'delete_property_fee' => [
            'limit' => 5,
            'seconds' => 60
        ],
        
        // Payment endpoints (higher limits for payment processing)
        'process_payment' => [
            'limit' => 30,
            'seconds' => 60
        ],
        'verify_payment' => [
            'limit' => 30,
            'seconds' => 60
        ],
        
        // Authentication endpoints (very strict)
        'admin_login3' => [
            'limit' => 5,
            'seconds' => 60
        ],
        'send_otp' => [
            'limit' => 3,
            'seconds' => 120
        ],
        'verify_otp' => [
            'limit' => 5,
            'seconds' => 60
        ],
        'report' => [
            'limit' => 3,
            'seconds' => 60
        ],
        
        // Maintenance endpoints
        'fetch_maintenance_requests' => [
            'limit' => 30,
            'seconds' => 60
        ],
        'create_maintenance_request' => [
            'limit' => 10,
            'seconds' => 60
        ],
        'update_maintenance_status' => [
            'limit' => 10,
            'seconds' => 60
        ],
        
        // Tenant endpoints
        'pay_fee' => [
            'limit' => 5,
            'seconds' => 60
        ],
        
        // General endpoints (moderate limits)
        'fetch_data' => [
            'limit' => 25,
            'seconds' => 60
        ],
    ],
    
    // Global security rules
    'security' => [
        'enable_logging' => true,
        'log_blocked_attempts' => true,
        'block_ip_after' => 5, // Block IP after X rate limit violations
        'block_duration' => 3600, // Block for 1 hour
    ]
];