<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start session and check authentication
session_start();
$requestId = uniqid('profile_', true);
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// Log request start
logActivity("[PROFILE_REQUEST_START] [ID:{$requestId}] [IP:{$ipAddress}] Admin profile request started");

// Check if user is logged in
if (!isset($_SESSION['unique_id']) || !isset($_SESSION['role'])) {
    logActivity("[AUTH_FAILED] [ID:{$requestId}] Unauthorized access attempt - No valid admin session");
    
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access. Please login.',
        'code' => 401
    ]);
    exit();
}

// Check database connection
if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    logActivity("[DB_CONNECTION_FAILED] [ID:{$requestId}] Database connection error: " . ($conn->connect_error ?? 'Unknown'));
    
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'Service temporarily unavailable.',
        'code' => 503
    ]);
    exit();
}

try {
    $userId = $_SESSION['unique_id'];
    $userType = $_SESSION['role'];
    
    logActivity("[PROFILE_FETCH_START] [ID:{$requestId}] Fetching profile for user ID: {$userId}, type: {$userType}");

    // Query with all available columns from admin_tbl
    $query = "SELECT 
                id,
                unique_id,
                firstname,
                lastname,
                email,
                phone,
                address,
                gender,
                secret_question,
                photo,
                role,
                created_at,
                updated_at,
                restriction_id,
                block_id,
                status,
                last_deactivated_by,
                unlock_token,
                token_expiry,
                onboarded_by,
                last_updated_by
              FROM admin_tbl 
              WHERE unique_id = ? AND status = '1'";
    
    logActivity("[PROFILE_QUERY] [ID:{$requestId}] Executing query for unique_id: {$userId}");
    
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        $errorMsg = "Failed to prepare query: " . $conn->error;
        logActivity("[DB_PREPARE_ERROR] [ID:{$requestId}] {$errorMsg}");
        throw new Exception($errorMsg);
    }
    
    // Bind the unique_id parameter
    $stmt->bind_param("i", $userId);
    
    if (!$stmt->execute()) {
        $errorMsg = "Failed to execute query: " . $stmt->error;
        logActivity("[DB_EXECUTE_ERROR] [ID:{$requestId}] {$errorMsg}");
        throw new Exception($errorMsg);
    }
    
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        logActivity("[PROFILE_NOT_FOUND] [ID:{$requestId}] No active admin found with unique_id: {$userId}");
        
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Profile not found or account is inactive.',
            'code' => 404
        ]);
        exit();
    }
    
    $profile = $result->fetch_assoc();
    
    // Format and enhance the profile data
    $formattedProfile = formatProfileData($profile);
    
    $stmt->close();
    
    logActivity("[PROFILE_FETCH_SUCCESS] [ID:{$requestId}] Profile fetched successfully for unique_id: {$userId}");
    
    // Get additional statistics if needed
    $stats = getAdditionalStats($conn, $userId);
    
    // Return success response
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Profile retrieved successfully',
        'data' => $formattedProfile,
        'stats' => $stats,
        'code' => 200,
        'request_id' => $requestId
    ]);
    
} catch (Exception $e) {
    logActivity("[PROFILE_ERROR] [ID:{$requestId}] Exception: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to retrieve profile. Please try again.',
        'code' => 500,
        'request_id' => $requestId
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
    logActivity("[PROFILE_REQUEST_END] [ID:{$requestId}] Request completed");
}

/**
 * Format and enhance the profile data for frontend display
 */
function formatProfileData($profile) {
    $formatted = $profile;
    
    // Combine firstname and lastname
    $formatted['full_name'] = trim($profile['firstname'] . ' ' . $profile['lastname']);
    
    // Generate initials for avatar
    $formatted['initials'] = getInitials($formatted['full_name']);
    
    // Format role for display
    $formatted['role_display'] = formatRole($profile['role']);
    
    // Format status
    $formatted['status_display'] = $profile['status'] == '1' ? 'Active' : 'Inactive';
    $formatted['status_class'] = $profile['status'] == '1' ? 'active' : 'inactive';
    
    // Format gender
    $formatted['gender_display'] = formatGender($profile['gender']);
    
    // Format dates
    if (!empty($profile['created_at'])) {
        $formatted['created_at_formatted'] = date('F j, Y', strtotime($profile['created_at']));
        $formatted['member_since'] = date('F Y', strtotime($profile['created_at']));
        $formatted['account_age'] = getAccountAge($profile['created_at']);
    }
    
    if (!empty($profile['updated_at'])) {
        $formatted['updated_at_formatted'] = date('F j, Y g:i A', strtotime($profile['updated_at']));
        $formatted['last_updated_relative'] = getRelativeTime($profile['updated_at']);
    }
    
    if (!empty($profile['token_expiry'])) {
        $formatted['token_expiry_formatted'] = date('F j, Y g:i A', strtotime($profile['token_expiry']));
    }
    
    // Handle photo path
    if (!empty($profile['photo'])) {
        $formatted['photo_url'] = getPhotoUrl($profile['photo']);
        $formatted['has_photo'] = true;
    } else {
        $formatted['has_photo'] = false;
        $formatted['photo_url'] = null;
    }
    
    // Mask sensitive information
    $formatted['email_masked'] = maskEmail($profile['email']);
    if (!empty($profile['phone'])) {
        $formatted['phone_masked'] = maskPhone($profile['phone']);
    }
    
    // Add security indicators
    $formatted['security'] = [
        'has_secret_question' => !empty($profile['secret_question']),
        'is_restricted' => !empty($profile['restriction_id']) && $profile['restriction_id'] > 0,
        'is_blocked' => !empty($profile['block_id']) && $profile['block_id'] > 0,
        'has_unlock_token' => !empty($profile['unlock_token']),
        'token_valid' => !empty($profile['token_expiry']) && strtotime($profile['token_expiry']) > time()
    ];
    
    // Remove sensitive fields completely
    unset($formatted['password']);
    unset($formatted['secret_answer']);
    unset($formatted['unlock_token']);
    
    return $formatted;
}

/**
 * Get additional statistics for the user
 */
function getAdditionalStats($conn, $uniqueId) {
    $stats = [];
    
    try {
        // Count total logins (if you have a login_logs table)
        if ($conn->query("SHOW TABLES LIKE 'login_logs'")->num_rows > 0) {
            $loginQuery = "SELECT COUNT(*) as login_count FROM login_logs WHERE user_id = ? AND user_type = 'admin'";
            $loginStmt = $conn->prepare($loginQuery);
            if ($loginStmt) {
                $loginStmt->bind_param("i", $uniqueId);
                $loginStmt->execute();
                $loginResult = $loginStmt->get_result();
                if ($loginRow = $loginResult->fetch_assoc()) {
                    $stats['total_logins'] = (int)$loginRow['login_count'];
                }
                $loginStmt->close();
            }
        }
        
        // Get last login time
        if ($conn->query("SHOW TABLES LIKE 'login_logs'")->num_rows > 0) {
            $lastLoginQuery = "SELECT login_time FROM login_logs WHERE user_id = ? AND user_type = 'admin' ORDER BY login_time DESC LIMIT 1";
            $lastLoginStmt = $conn->prepare($lastLoginQuery);
            if ($lastLoginStmt) {
                $lastLoginStmt->bind_param("i", $uniqueId);
                $lastLoginStmt->execute();
                $lastLoginResult = $lastLoginStmt->get_result();
                if ($lastLoginRow = $lastLoginResult->fetch_assoc()) {
                    $stats['last_login'] = $lastLoginRow['login_time'];
                    $stats['last_login_formatted'] = date('F j, Y g:i A', strtotime($lastLoginRow['login_time']));
                    $stats['last_login_relative'] = getRelativeTime($lastLoginRow['login_time']);
                }
                $lastLoginStmt->close();
            }
        }
        
        // Count activities if you have an activities table
        if ($conn->query("SHOW TABLES LIKE 'admin_activities'")->num_rows > 0) {
            $activityQuery = "SELECT COUNT(*) as activity_count FROM admin_activities WHERE admin_id = ?";
            $activityStmt = $conn->prepare($activityQuery);
            if ($activityStmt) {
                $activityStmt->bind_param("i", $uniqueId);
                $activityStmt->execute();
                $activityResult = $activityStmt->get_result();
                if ($activityRow = $activityResult->fetch_assoc()) {
                    $stats['total_activities'] = (int)$activityRow['activity_count'];
                }
                $activityStmt->close();
            }
        }
        
    } catch (Exception $e) {
        // Log but don't throw - stats are optional
        logActivity("[PROFILE_STATS_ERROR] Failed to get stats: " . $e->getMessage());
    }
    
    return $stats;
}

/**
 * Helper function to get initials from name
 */
function getInitials($name) {
    if (empty($name)) return 'AD';
    
    $names = explode(' ', $name);
    $initials = '';
    
    foreach ($names as $n) {
        if (!empty(trim($n))) {
            $initials .= strtoupper(substr(trim($n), 0, 1));
        }
    }
    
    return substr($initials, 0, 2);
}

/**
 * Format role for display
 */
function formatRole($role) {
    if (empty($role)) return 'Administrator';
    
    $roleMap = [
        'Super Admin' => 'Super Administrator',
        'admin' => 'Administrator'
    ];
    
    return $roleMap[$role] ?? ucfirst(str_replace('_', ' ', $role));
}

/**
 * Format gender for display
 */
function formatGender($gender) {
    if (empty($gender)) return 'Not specified';
    
    $genderMap = [
        'M' => 'Male',
        'F' => 'Female',
        'O' => 'Other'
    ];
    
    return $genderMap[$gender] ?? ucfirst($gender);
}

/**
 * Get relative time string
 */
function getRelativeTime($date) {
    $time = strtotime($date);
    $timeDiff = time() - $time;
    
    if ($timeDiff < 60) {
        return 'just now';
    } elseif ($timeDiff < 3600) {
        $minutes = floor($timeDiff / 60);
        return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
    } elseif ($timeDiff < 86400) {
        $hours = floor($timeDiff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($timeDiff < 604800) {
        $days = floor($timeDiff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } elseif ($timeDiff < 2592000) {
        $weeks = floor($timeDiff / 604800);
        return $weeks . ' week' . ($weeks > 1 ? 's' : '') . ' ago';
    } elseif ($timeDiff < 31536000) {
        $months = floor($timeDiff / 2592000);
        return $months . ' month' . ($months > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y', $time);
    }
}

/**
 * Calculate account age
 */
function getAccountAge($createdAt) {
    $created = new DateTime($createdAt);
    $now = new DateTime();
    $interval = $created->diff($now);
    
    if ($interval->y > 0) {
        return $interval->y . ' year' . ($interval->y > 1 ? 's' : '');
    } elseif ($interval->m > 0) {
        return $interval->m . ' month' . ($interval->m > 1 ? 's' : '');
    } else {
        return $interval->d . ' day' . ($interval->d > 1 ? 's' : '');
    }
}

/**
 * Get full photo URL
 */
function getPhotoUrl($photoPath) {
    // Adjust this based on your file structure
    if (strpos($photoPath, 'http') === 0) {
        return $photoPath; // Already a full URL
    } elseif (strpos($photoPath, '/') === 0) {
        return $photoPath; // Absolute path
    } else {
        // Relative path - adjust base URL as needed
        $baseUrl = isset($_SERVER['HTTPS']) ? 'https://' : 'http://';
        $baseUrl .= $_SERVER['HTTP_HOST'];
        return $baseUrl . '/rent_manager/admin/backend/staffs/admin_photos/' . $photoPath;
        
    }
}

/**
 * Mask email for display
 */
function maskEmail($email) {
    if (empty($email)) return '';
    
    $parts = explode('@', $email);
    if (count($parts) !== 2) return $email;
    
    $username = $parts[0];
    $domain = $parts[1];
    
    if (strlen($username) <= 2) {
        $maskedUsername = str_repeat('*', strlen($username));
    } else {
        $maskedUsername = substr($username, 0, 2) . str_repeat('*', strlen($username) - 2);
    }
    
    return $maskedUsername . '@' . $domain;
}

/**
 * Mask phone number for display
 */
function maskPhone($phone) {
    if (empty($phone)) return '';
    
    if (strlen($phone) <= 4) {
        return str_repeat('*', strlen($phone));
    }
    
    $visibleDigits = 4;
    $maskedPart = str_repeat('*', strlen($phone) - $visibleDigits);
    $visiblePart = substr($phone, -$visibleDigits);
    
    return $maskedPart . $visiblePart;
}