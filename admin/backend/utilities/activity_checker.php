<?php 
session_start();
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

// Set session timeout to 2 minutes
$session_timeout = 30; // 2 minutes in seconds

// Check if user is logged in
if (isset($_SESSION['unique_id'])) {
    // Check if last activity timestamp is set
    if (isset($_SESSION['last_activity'])) {
        // Calculate time difference between current time and last activity time
        $idle_time = time() - $_SESSION['last_activity'];
        
        // If idle time exceeds session timeout, call logout.php with unique_id
        if ($idle_time > $session_timeout) {
            $unique_id = $_SESSION['unique_id'];

            // Prepare data to send in POST request
            $data = ['logout_id' => $unique_id];
            $options = [
                'http' => [
                    'header'  => "Content-type: application/json\r\n",
                    'method'  => 'POST',
                    'content' => json_encode($data)
                ]
            ];
            $context  = stream_context_create($options);
            $response = file_get_contents("localhost/rent_manager/admin/backend/logout.php", false, $context);


            // Exit after making logout call
            exit();
        }
    }

    // Update last activity timestamp
    $_SESSION['last_activity'] = time();
} else {
    // If user is not logged in, redirect to login page
    header("Location: index.php");
    exit();
}

