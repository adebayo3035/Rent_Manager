<?php
// utilities/rate_limit_config.php  —  AGENT MODULE

return [
    // Default settings (applies to any endpoint not explicitly listed)
    'default' => [
        'limit' => 30,
        'seconds' => 60
    ],

    // Endpoint-specific overrides (keys MUST match .php filename without extension)
    'endpoints' => [

        // ============================================
        // AUTHENTICATION (VERY STRICT — pre-auth, brute-force target)
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
        // OTP (VERY STRICT — prevent email/SMS abuse)
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
        'submit_reactivation_request' => [
            'limit' => 3,
            'seconds' => 300
        ],

        // ============================================
        // SESSION / ACTIVITY / POLLING (LENIENT)
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
        'navbar' => [
            'limit' => 60,
            'seconds' => 60
        ],
        'notification' => [
            'limit' => 60,
            'seconds' => 60
        ],

        // ============================================
        // NOTIFICATIONS (LENIENT — polling from frontend)
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

        // ============================================
        // DASHBOARD (moderate — fetched often)
        // ============================================
        'fetch_dashboard_data' => [
            'limit' => 40,
            'seconds' => 60
        ],
        'fetch_stats' => [
            'limit' => 40,
            'seconds' => 60
        ],
        'fetch_revenue' => [
            'limit' => 30,
            'seconds' => 60
        ],
        'fetch_properties' => [
            'limit' => 40,
            'seconds' => 60
        ],
        'fetch_recent_payments' => [
            'limit' => 40,
            'seconds' => 60
        ],

        // ============================================
        // AGENTS (rating is write-op — stricter)
        // ============================================
        'rate_agent' => [
            'limit' => 5,
            'seconds' => 60
        ],
        'fetch_agent_details' => [
            'limit' => 30,
            'seconds' => 60
        ],
        'fetch_property_agents' => [
            'limit' => 30,
            'seconds' => 60
        ],

        // ============================================
        // TENANTS / CLIENTS (read + rating write)
        // ============================================
        'rate_tenant' => [
            'limit' => 5,
            'seconds' => 60
        ],
        'fetch_tenants' => [
            'limit' => 30,
            'seconds' => 60
        ],
        'fetch_tenant_details' => [
            'limit' => 30,
            'seconds' => 60
        ],
        'fetch_profile' => [
            'limit' => 30,
            'seconds' => 60
        ],
        'update_profile' => [
            'limit' => 10,
            'seconds' => 60
        ],

        // ============================================
        // PAYMENTS (STRICT — financial ops)
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
        'download_receipt' => [
            'limit' => 20,
            'seconds' => 60
        ],

        // ============================================
        // FEES
        // ============================================
        'fetch_client_fees' => [
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
        // MAINTENANCE
        // ============================================
        'fetch_maintenance_requests' => [
            'limit' => 30,
            'seconds' => 60
        ],
        'fetch_maintenance_request_details' => [
            'limit' => 30,
            'seconds' => 60
        ],

        // ============================================
        // DOCUMENTS (write ops stricter)
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
    ],

    // Global security rules
    'security' => [
        'enable_logging' => true,
        'log_blocked_attempts' => true,
        'block_ip_after' => 5,       // Block IP after X rate-limit violations
        'block_duration' => 3600,    // Block for 1 hour
    ]
];