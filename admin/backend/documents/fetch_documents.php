<?php
// admin/backend/documents/fetch_documents.php
// List documents visible to the current admin

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
require_once __DIR__ . '/document_helper.php';



if (!isset($_SESSION)) session_start();
rateLimiter();

logActivity("========== FETCH DOCUMENTS (ADMIN) - START ==========");

try {
    $adminId  = $_SESSION['unique_id'];
    $userRole = $_SESSION['role'];

    // Pagination
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    // Filters
    $search      = trim($_GET['search'] ?? '');
    $docType     = trim($_GET['document_type'] ?? '');
    $sectorKey   = trim($_GET['sector'] ?? '');
    $tenantCode  = trim($_GET['tenant_code'] ?? '');
    $dateFrom    = trim($_GET['date_from'] ?? '');
    $dateTo      = trim($_GET['date_to'] ?? '');

    // Load sector config from helper (cached, loaded once)
    $sectorsConfig   = getDocumentSectorsConfig();
    $allAllowedTypes = getAllowedDocumentTypes();

    // Role-based visibility
    [$visSQL, $visParams, $visTypes] = buildDocumentVisibilityFilter($adminId, $userRole);

    // Base WHERE
    $where  = ["d.is_deleted = 0", $visSQL];
    $params = $visParams;
    $types  = $visTypes;

    // Search
    if ($search !== '') {
        $where[] = "(d.document_name LIKE ? OR d.original_file_name LIKE ? OR t.firstname LIKE ? OR t.lastname LIKE ? OR CONCAT(t.firstname, ' ', t.lastname) LIKE ? OR t.tenant_code LIKE ?)";
        $like = "%{$search}%";
        array_push($params, $like, $like, $like, $like, $like, $like);
        $types .= 'ssssss';
    }

    // Document type filter
    if ($docType !== '' && in_array($docType, $allAllowedTypes, true)) {
        $where[] = "d.document_type = ?";
        $params[] = $docType;
        $types .= 's';
    }

    // Sector filter (expands to a set of types)
    if ($sectorKey !== '' && isset($sectorsConfig[$sectorKey])) {
        $sectorTypes = $sectorsConfig[$sectorKey]['types'];
        if (!empty($sectorTypes)) {
            $placeholders = implode(',', array_fill(0, count($sectorTypes), '?'));
            $where[] = "d.document_type IN ($placeholders)";
            foreach ($sectorTypes as $t) {
                $params[] = $t;
                $types .= 's';
            }
        }
    }

    // Tenant filter
    if ($tenantCode !== '') {
        $where[] = "d.tenant_code = ?";
        $params[] = $tenantCode;
        $types .= 's';
    }

    // Date range
    if ($dateFrom !== '') {
        $where[] = "DATE(d.uploaded_at) >= ?";
        $params[] = $dateFrom;
        $types .= 's';
    }
    if ($dateTo !== '') {
        $where[] = "DATE(d.uploaded_at) <= ?";
        $params[] = $dateTo;
        $types .= 's';
    }

    $whereSQL = implode(' AND ', $where);

    // ---------- COUNT ----------
    $countSQL = "
        SELECT COUNT(*) AS total
        FROM tenant_documents d
        JOIN tenants t ON d.tenant_code = t.tenant_code
        WHERE {$whereSQL}
    ";
    $stmt = $conn->prepare($countSQL);
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();

    // ---------- FETCH ----------
    $sql = "
        SELECT 
            d.document_id,
            d.tenant_code,
            d.document_name,
            d.document_type,
            d.file_name,
            d.original_file_name,
            d.file_size,
            d.file_type,
            d.storage_location,
            d.uploaded_by,
            d.uploaded_by_type,
            d.uploaded_at,
            CONCAT(t.firstname, ' ', t.lastname) AS tenant_name,
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
        WHERE {$whereSQL}
        ORDER BY d.uploaded_at DESC
        LIMIT ? OFFSET ?
    ";

    $paramsWithPag = $params;
    $paramsWithPag[] = $limit;
    $paramsWithPag[] = $offset;
    $typesWithPag = $types . 'ii';

    $stmt = $conn->prepare($sql);
    if (!empty($paramsWithPag)) $stmt->bind_param($typesWithPag, ...$paramsWithPag);
    $stmt->execute();
    $res = $stmt->get_result();

    $documents = [];
    while ($row = $res->fetch_assoc()) {
        // FIXED: no second argument to getSectorForType()
        $docSectorKey = getSectorForType($row['document_type']);
        $docSector = $sectorsConfig[$docSectorKey] ?? null;

        $documents[] = [
            'document_id'        => (int)$row['document_id'],
            'tenant_code'        => $row['tenant_code'],
            'tenant_name'        => $row['tenant_name'],
            'property_name'      => $row['property_name'] ?? 'N/A',
            'apartment_number'   => $row['apartment_number'] ?? 'N/A',
            'document_name'      => $row['document_name'],
            'document_type'      => $row['document_type'],
            'document_type_icon' => getDocumentTypeIcon($row['document_type']),
            'file_name'          => $row['file_name'],
            'original_file_name' => $row['original_file_name'],
            'file_size'          => (int)$row['file_size'],
            'file_size_formatted'=> formatFileSize((int)$row['file_size']),
            'file_type'          => $row['file_type'],
            'storage_location'   => $row['storage_location'],
            'uploaded_by'        => $row['uploaded_by'],
            'uploaded_by_type'   => $row['uploaded_by_type'],
            'uploaded_by_label'  => getUploadedByLabel($row['uploaded_by_type']),
            'uploader_name'      => $row['uploader_name'],
            'uploaded_at'        => $row['uploaded_at'],
            'uploaded_at_formatted' => date('M j, Y g:i A', strtotime($row['uploaded_at'])),
            'sector'             => $docSectorKey,
            'sector_label'       => $docSector['label'] ?? 'Other',
            'sector_color'       => $docSector['color'] ?? '#6b7280',
            'sector_bg_color'    => $docSector['bg_color'] ?? '#f3f4f6',
            'sector_icon'        => $docSector['icon'] ?? 'fa-folder',
        ];
    }
    $stmt->close();

    logActivity("Admin {$adminId} ({$userRole}) fetched {$total} documents (page {$page})");

    json_success([
        'documents'  => $documents,
        'sectors'    => array_map(function($key, $s) {
            return [
                'key'         => $key,
                'label'       => $s['label'],
                'description' => $s['description'],
                'icon'        => $s['icon'],
                'color'       => $s['color'],
                'bg_color'    => $s['bg_color'],
                'types'       => $s['types'],
            ];
        }, array_keys($sectorsConfig), $sectorsConfig),
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int)ceil($total / max(1, $limit)),
        ],
    ], "Documents retrieved successfully");

} catch (Exception $e) {
    logActivity("ERROR in fetch_documents (admin): " . $e->getMessage());
    $status = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    json_error($e->getMessage(), $status);
}