<?php
session_start();
// If the user is already logged in, redirect them to the home page
if (isset($_SESSION['unique_id'])) {
    header("Location: homepage.php");
    exit(); // Important to prevent further execution after redirect
}

