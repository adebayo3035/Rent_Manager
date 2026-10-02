<?php
// admin/backend/documents/fetch_document_details.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
require_once __DIR__ . '/document_helper.php';

if (!isset($_SESSION)) session_start();
rateLimiter();

try {
    $adminId  = $_SESSION['unique_id'];
    $userRole = $_SESSION['role'];

    $input = json_decode(file_get_contents('php://input'), true);
    $documentId = (int)($input['document_id'] ?? 0);
    if ($documentId <= 0) json_error("Invalid document ID", 400);

    [$visSQL, $visParams, $visTypes] = buildDocumentVisibilityFilter($adminId, $userRole);

    $stmt = $conn->prepare("
        SELECT 
            d.*,
            CONCAT(t.firstname, ' ', t.lastname) AS tenant_name,
            t.email AS tenant_email,
            p.name AS property_name,
            a.apartment_number,
            CASE 
                WHEN d.uploaded_by_type = 'tenant' THEN CONCAT(t.firstname, ' ', t.lastname, ' (Tenant)')
                WHEN d.uploaded_by_type IN ('admin', 'super_admin') 
                    THEN COALESCE(CONCAT(au.firstname, ' ', au.lastname), 'Admin')
                ELSE d.uploaded_by
            END AS uploader_name
        FROM tenant_documents d
        JOIN tenants t ON d.tenant_code = t.tenant_code
        LEFT JOIN apartments a ON t.apartment_code = a.apartment_code
        LEFT JOIN properties p ON a.property_code = p.property_code
        LEFT JOIN admin_tbl au ON d.uploaded_by = au.unique_id
        WHERE d.document_id = ? AND d.is_deleted = 0 AND {$visSQL}
        LIMIT 1
    ");

    $bindParams = array_merge([$documentId], $visParams);
    $bindTypes  = 'i' . $visTypes;
    $stmt->bind_param($bindTypes, ...$bindParams);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) json_error("Document not found or access denied", 404);

    // FIXED: use helper instead of inline require
    $sectorsConfig = getDocumentSectorsConfig();
    $docSectorKey  = getSectorForType($row['document_type']);
    $docSector     = $sectorsConfig[$docSectorKey] ?? null;

    json_success([
        'document_id'         => (int)$row['document_id'],
        'tenant_code'         => $row['tenant_code'],
        'tenant_name'         => $row['tenant_name'],
        'tenant_email'        => $row['tenant_email'],
        'property_name'       => $row['property_name'] ?? 'N/A',
        'apartment_number'    => $row['apartment_number'] ?? 'N/A',
        'document_name'       => $row['document_name'],
        'document_type'       => $row['document_type'],
        'document_type_icon'  => getDocumentTypeIcon($row['document_type']),
        'original_file_name'  => $row['original_file_name'],
        'file_name'           => $row['file_name'],
        'file_size'           => (int)$row['file_size'],
        'file_size_formatted' => formatFileSize((int)$row['file_size']),
        'file_type'           => $row['file_type'],
        'storage_location'    => $row['storage_location'],
        'uploaded_by'         => $row['uploaded_by'],
        'uploaded_by_type'    => $row['uploaded_by_type'],
        'uploaded_by_label'   => getUploadedByLabel($row['uploaded_by_type']),
        'uploader_name'       => $row['uploader_name'],
        'uploaded_at'         => $row['uploaded_at'],
        'uploaded_at_formatted' => date('M j, Y g:i A', strtotime($row['uploaded_at'])),
        'sector'              => $docSectorKey,
        'sector_label'        => $docSector['label'] ?? 'Other',
        'sector_color'        => $docSector['color'] ?? '#6b7280',
        'sector_bg_color'     => $docSector['bg_color'] ?? '#f3f4f6',
        'sector_icon'         => $docSector['icon'] ?? 'fa-folder',
        // Preview capability
        'is_pdf'              => ($row['file_type'] === 'application/pdf'),
        'is_image'            => strpos($row['file_type'], 'image/') === 0,
    ], "Document details retrieved");

} catch (Exception $e) {
    logActivity("ERROR in fetch_document_details (admin): " . $e->getMessage());
    $status = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    json_error($e->getMessage(), $status);
}