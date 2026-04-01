<?php
date_default_timezone_set('Africa/Lagos'); // Example for Nigeria (UTC+1)
include('activity_logger.php');
  $hostname = "localhost";
  $username = "root";
  $password = "";
  $dbname = "rent_manager";

  $conn = mysqli_connect($hostname, $username, $password, $dbname);
  if(!$conn){
    echo "Database connection error".mysqli_connect_error();
    logactivity(mysqli_connect_error()."Error Connecting to Database");
  }

  function checkAdminSession($staffID)
  {
    if (!isset($staffID)) {
      $error_message = "Admin not logged in. Admin's Session ID Cannot be found.";
      http_response_code(401);  // Unauthorized
      error_log($error_message);
      logActivity($error_message);
      echo json_encode(['error' => 'Unauthorized access']);
      exit();
    }
  }
  function sendAdminNotification($conn, $title, $eventType, $eventDetails, $userId, $logMessage = null) {
    // Insert into admin_notifications
    $stmt = $conn->prepare("INSERT INTO admin_notifications (event_title, event_type, event_details, created_at, user_id) VALUES (?, ?, ?, NOW(), ?)");
    $stmt->bind_param("ssss", $title, $eventType, $eventDetails, $userId);
    $stmt->execute();
    $stmt->close();
  }