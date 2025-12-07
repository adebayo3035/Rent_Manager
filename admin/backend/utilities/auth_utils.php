<?php
function hashPassword($password) {
    $options = [
        'memory_cost' => 1 << 17, // 128 MB
        'time_cost'   => 4,
        'threads'     => 2
    ];

    return password_hash($password, PASSWORD_ARGON2ID, $options);
}
function passwordVerify($password, $hash) {
    return password_verify($password, $hash);
}
function verifyAndRehashPassword($pdo, $adminId, $inputPassword, $storedHash) {

    if (!passwordVerify($inputPassword, $storedHash)) {
        return false; // Incorrect password
    }

    // If PHP recommends rehash, update it
    if (password_needs_rehash($storedHash, PASSWORD_ARGON2ID)) {

        $newHash = hashPassword($inputPassword);

        $stmt = $pdo->prepare("UPDATE admin_tbl SET password = ? WHERE unique_id = ?");
        $stmt->execute([$newHash, $adminId]);
    }

    return true;
}
function password_needsRehashPassword($pdo, $adminId, $inputPassword, $storedHash) {

    // If PHP recommends rehash, update it
    if (password_needs_rehash($storedHash, PASSWORD_ARGON2ID)) {

        $newHash = hashPassword($inputPassword);

        $stmt = $pdo->prepare("UPDATE admin_tbl SET password = ? WHERE unique_id = ?");
        $stmt->execute([$newHash, $adminId]);
    }
}