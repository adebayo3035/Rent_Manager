<?php 
// include_once "session_checker.php";  ?> 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to Transaction Manager</title>
    <!-- font awesome -->
    <script src="https://kit.fontawesome.com/7cab3097e7.js" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Nunito&family=Roboto&display=swap" rel="stylesheet" />
    <!-- end of font awesome -->
    <link rel="stylesheet" href="../css/splashscreen.css">
</head>
<body>
<?php include "../backend/utilities/activity_checker.php"; ?>
    <div class="splash">
        <img src="../../images/trans_manager.png" alt="" class="rotate">
        <div class="loader">
            <div class="loader-text" id="loaderText"></div>
        </div>
    </div>
    <script src="../scripts/splashscreen.js"></script>
</body>
</html>