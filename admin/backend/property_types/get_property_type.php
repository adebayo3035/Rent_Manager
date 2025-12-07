<?php
// fetch_property_type_by_id.php

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_utils.php';

$input = json_decode(file_get_contents("php://input"), true);
$type_id = $input['type_id'] ?? null;

if (!$type_id) {
    logActivity("Fetch property type failed: type_id missing");
    json_error("type_id is required.", 400);
}

$stmt = $conn->prepare("
    SELECT type_id, type_name, description, status, created_by, updated_by, created_at, updated_at
    FROM property_type
    WHERE type_id = ? AND deleted_at IS NULL
");
$stmt->bind_param("i", $type_id);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {
    logActivity("Property type not found for id $type_id");
    json_error("Property type not found.", 404);
}

$type = $result->fetch_assoc();

logActivity("Fetched property type id $type_id");
json_success($type, 200);
