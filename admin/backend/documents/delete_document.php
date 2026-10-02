<?php
// admin/backend/documents/delete_document.php

header('Content-Type: application/json');
require_once __DIR__ . '/../../utilities/config.php';
require_once __DIR__ . '/../../utilities/auth_utils.php';
require_once __DIR__ . '/../../utilities/utils.php';
require_once __DIR__ . '/../../utilities/auth_guard.php';
require_once __DIR__ . '/../../utilities/rate_limit.php';
require_once __DIR__ . '/document_helper.php';

if (!isset($_SESSION)) session_start();
rateLimiter();

try {
    $auth = requireAuth([
        'method'     => 'POST',
        'rate_key'   => 'admin_delete_document',
        'rate_limit' => [30, 60],
        'csrf'       => ['enabled' => true, 'form_name' => 'admin_delete_document_form'],
        'roles'      => ['Super Admin', 'Admin']
    ]);
    $adminId  = $auth['user_id'];
    $userRole = $auth['role'];

    $input = json_decode(file_get_contents('php://input'), true);
    $documentId = (int)($input['document_id'] ?? 0);
    if ($documentId <= 0) json_error("Invalid document ID", 400);

    // Invoices cannot be deleted by anyone
    $stmt = $conn->prepare("SELECT document_type FROM tenant_documents WHERE document_id = ? AND is_deleted = 0 LIMIT 1");
    $stmt->bind_param("i", $documentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) json_error("Document not found", 404);
    if ($row['document_type'] === 'INVOICE') {
        json_error("Invoices cannot be deleted. They are part of the permanent audit trail.", 403);
    }

    // Visibility check
    [$visSQL, $visParams, $visTypes] = buildDocumentVisibilityFilter($adminId, $userRole);

    $stmt = $conn->prepare("
        SELECT d.document_id, d.file_name, d.storage_location, d.tenant_code
        FROM tenant_documents d
        WHERE d.document_id = ? AND d.is_deleted = 0 AND {$visSQL}
        LIMIT 1
    ");
    $bindParams = array_merge([$documentId], $visParams);
    $bindTypes  = 'i' . $visTypes;
    $stmt->bind_param($bindTypes, ...$bindParams);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$doc) json_error("Document not found or access denied", 404);

    // Physical file removal
    $dir = resolveStorageDir($doc['storage_location']);
    $path = $dir . $doc['file_name'];
    $fileDeleted = false;

    if (file_exists($path)) {
        $real = realpath($path);
        $realBase = realpath($dir);
        if ($real && $realBase && strpos($real, $realBase) === 0) {
            $fileDeleted = @unlink($path);
        }
    }

    // Soft delete
    $upd = $conn->prepare("
        UPDATE tenant_documents 
        SET is_deleted = 1, deleted_at = NOW(), deleted_by = ?,
            file_deleted = ?, file_deleted_at = NOW()
        WHERE document_id = ?
    ");
    $fileDeletedInt = $fileDeleted ? 1 : 0;
    $upd->bind_param("sii", $adminId, $fileDeletedInt, $documentId);
    if (!$upd->execute()) {
        throw new Exception("Failed to delete document: " . $upd->error);
    }
    $upd->close();

    logActivity("Admin {$adminId} ({$userRole}) deleted document #{$documentId} (file: " . ($fileDeleted ? 'yes' : 'no') . ")");

    json_success([
        'document_id'  => $documentId,
        'file_deleted' => $fileDeleted,
    ], "Document deleted successfully");

} catch (Exception $e) {
    logActivity("ERROR in admin delete_document: " . $e->getMessage());
    $status = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    json_error($e->getMessage(), $status);
}