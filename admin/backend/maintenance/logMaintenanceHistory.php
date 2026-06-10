<?php
// admin/backend/maintenance/logMaintenanceHistory.php

function logMaintenanceHistory($conn, $request_id, $action, $old_value, $new_value, $changed_by, $changed_by_type, $notes = null) {
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    
    $query = "INSERT INTO maintenance_history (request_id, action, old_value, new_value, changed_by, changed_by_type, notes, ip_address, created_at) 
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("isssssss", $request_id, $action, $old_value, $new_value, $changed_by, $changed_by_type, $notes, $ip_address);
    
    if ($stmt->execute()) {
        $history_id = $stmt->insert_id;
        $stmt->close();
        return $history_id;
    }
    
    $stmt->close();
    return false;
}
?>