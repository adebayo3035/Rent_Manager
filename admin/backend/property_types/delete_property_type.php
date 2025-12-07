<?php
// delete_property_type.php

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_utils.php';

$input = json_decode(file_get_contents("php://input"), true);

$type_id   = $input['type_id'] ?? null;
$deleted_by = $input['deleted_by'] ?? null;

if (!$type_id || !$deleted_by) {
    logActivity("Delete failed: Missing type_id or deleted_by");
    json_error("type_id and deleted_by are required.", 400);
}

// Ensure type exists
$check = $conn->prepare("
    SELECT type_id FROM property_type 
    WHERE type_id = ? AND deleted_at IS NULL
");
$check->bind_param("i", $type_id);
$check->execute();
$check->store_result();

if ($check->num_rows === 0) {
    logActivity("Delete failed: Property type $type_id not found");
    json_error("Property type not found.", 404);
}
$check->close();

// Soft delete
$stmt = $conn->prepare("
    UPDATE property_type
    SET deleted_at = NOW(), updated_by = ?, updated_at = NOW()
    WHERE type_id = ?
");

$stmt->bind_param("ii", $deleted_by, $type_id);

if ($stmt->execute()) {
    logActivity("Soft deleted property type $type_id by user $deleted_by");
    json_success("Property type deleted successfully.", 200);
} else {
    logActivity("Delete failed: " . $stmt->error);
    json_error("Failed to delete property type.", 500);
}

$stmt->close();
$conn->close();
