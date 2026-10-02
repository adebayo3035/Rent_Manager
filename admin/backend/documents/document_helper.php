<?php
/**
 * document_helper.php
 * Shared helpers for the admin Documents module.
 */

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/utils.php';

// ==================== LOAD CONFIG ONCE ====================
if (!defined('DOCUMENT_SECTORS_CONFIG_LOADED')) {
    define('DOCUMENT_SECTORS_CONFIG_LOADED', true);
    $GLOBALS['DOCUMENT_SECTORS_CONFIG'] = require_once __DIR__ . '/document_config.php';
}

/**
 * Return the cached document sectors config.
 */
function getDocumentSectorsConfig() {
    if (!isset($GLOBALS['DOCUMENT_SECTORS_CONFIG'])) {
        $GLOBALS['DOCUMENT_SECTORS_CONFIG'] = require_once __DIR__ . '/document_config.php';
    }
    return $GLOBALS['DOCUMENT_SECTORS_CONFIG'];
}

// ==================== SECTOR LOOKUP ====================
/**
 * Given a document_type value from the DB, return the sector key.
 * Falls back to 'other' if not mapped.
 */
function getSectorForType($document_type) {
    $config = getDocumentSectorsConfig();
    foreach ($config as $key => $sector) {
        if (in_array($document_type, $sector['types'], true)) {
            return $key;
        }
    }
    return 'other';
}

/**
 * Flatten the config into a single array of allowed document types.
 */
function getAllowedDocumentTypes() {
    $config = getDocumentSectorsConfig();
    $types = [];
    foreach ($config as $sector) {
        $types = array_merge($types, $sector['types']);
    }
    return array_values(array_unique($types));
}

// ==================== STORAGE PATHS ====================
define('DOC_ADMIN_BASE_DIR',     __DIR__ . '/../tenant_documents/');
define('DOC_INVOICES_DIR',       DOC_ADMIN_BASE_DIR . 'invoices/');
define('DOC_UPLOADS_DIR',        DOC_ADMIN_BASE_DIR . 'uploads/');
define('DOC_TENANT_MODULE_DIR',  __DIR__ . '/../../../tenant/backend/tenant_documents/');

// ==================== TYPE → STORAGE RESOLUTION ====================
function resolveStorageDir($storage_location) {
    switch ($storage_location) {
        case 'admin_invoices': return DOC_INVOICES_DIR;
        case 'admin_uploads':  return DOC_UPLOADS_DIR;
        case 'tenant':
        default:               return DOC_TENANT_MODULE_DIR;
    }
}

// ==================== VISIBILITY FILTER ====================
function buildDocumentVisibilityFilter($userId, $userRole) {
    if ($userRole === 'Super Admin') {
        return ['1=1', [], ''];
    }
    $sql = "EXISTS (
        SELECT 1 FROM tenants t 
        WHERE t.tenant_code = d.tenant_code 
        AND t.created_by = ?
    )";
    return [$sql, [$userId], 'i'];
}

// ==================== UPLOADED_BY LABEL ====================
function getUploadedByLabel($type) {
    $map = [
        'tenant'      => 'Tenant',
        'client'      => 'Client',
        'agent'       => 'Agent',
        'admin'       => 'Admin',
        'super_admin' => 'Super Admin',
        'system'      => 'System',
    ];
    return $map[$type] ?? ucfirst($type);
}

// ==================== FILE SIZE FORMAT ====================
function formatFileSize($bytes) {
    if (!is_numeric($bytes)) return 'N/A';
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 2) . ' MB';
}

// ==================== ICON PER DOCUMENT TYPE ====================
function getDocumentTypeIcon($type) {
    $map = [
        'INVOICE'             => 'fa-file-invoice-dollar',
        'PAYMENT_RECEIPT'     => 'fa-receipt',
        'LEASE_AGREEMENT'     => 'fa-file-contract',
        'IDENTIFICATION'      => 'fa-id-card',
        'MAINTENANCE_REQUEST' => 'fa-tools',
        'FEE'                 => 'fa-money-bill-wave',
        'OTHER'               => 'fa-file',
    ];
    return $map[$type] ?? 'fa-file';
}