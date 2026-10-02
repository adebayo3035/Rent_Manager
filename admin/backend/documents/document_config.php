<?php
/**
 * document_config.php
 * Sector → document-type configuration for the admin Documents module.
 *
 * This file RETURNS DATA ONLY.
 * Do not declare functions here — they live in document_helper.php.
 *
 * To add a new sector:
 *   1. Add an entry below with a unique key.
 *   2. Add the DB document_type value(s) to its `types` array.
 *
 * To add a new type to an existing sector:
 *   Just append it to that sector's `types` array.
 */

return [
    'financial' => [
        'label'       => 'Financial',
        'description' => 'Invoices, receipts, fee records',
        'icon'        => 'fa-money-bill-wave',
        'color'       => '#10b981',
        'bg_color'    => '#ecfdf5',
        'types'       => ['INVOICE', 'PAYMENT_RECEIPT', 'FEE'],
    ],
    'legal' => [
        'label'       => 'Legal',
        'description' => 'Lease agreements and contracts',
        'icon'        => 'fa-gavel',
        'color'       => '#6366f1',
        'bg_color'    => '#eef2ff',
        'types'       => ['LEASE_AGREEMENT'],
    ],
    'personal' => [
        'label'       => 'Personal',
        'description' => 'Identification and personal records',
        'icon'        => 'fa-id-card',
        'color'       => '#f59e0b',
        'bg_color'    => '#fffbeb',
        'types'       => ['IDENTIFICATION'],
    ],
    'operations' => [
        'label'       => 'Operations',
        'description' => 'Maintenance and service requests',
        'icon'        => 'fa-tools',
        'color'       => '#8b5cf6',
        'bg_color'    => '#f5f3ff',
        'types'       => ['MAINTENANCE_REQUEST'],
    ],
    'other' => [
        'label'       => 'Other',
        'description' => 'Miscellaneous documents',
        'icon'        => 'fa-folder',
        'color'       => '#6b7280',
        'bg_color'    => '#f3f4f6',
        'types'       => ['OTHER'],
    ],
];