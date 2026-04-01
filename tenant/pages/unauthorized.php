<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            margin: 0;
            padding: 0;
            height: 100vh;
        }

        .unauthorized-container {
            text-align: center;
            padding: 50px;
        }

        .unauthorized-button {
            background-color: #4CAF50;
            /* Green color */
            border: none;
            color: white;
            padding: 15px 32px;
            text-align: center;
            text-decoration: none;
            display: inline-block;
            font-size: 16px;
            margin: 4px 2px;
            cursor: pointer;
            border-radius: 5px;
        }

        .unauthorized-button:hover {
            background-color: #45a049;
            /* Darker green on hover */
        }
    </style>
</head>

<body>
<?php include "navbar.php" ?>
    <div class="unauthorized-container">
        <h1>Unauthorized Access</h1>
        <p>Sorry, you don't have permission to view this page.</p>
        <a href="homepage.php" class="unauthorized-button">Return to Homepage</a>
    </div>

</body>

</html>