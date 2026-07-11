<?php

/* Use classic mysqli error handling */
mysqli_report(MYSQLI_REPORT_OFF);

// Database Configuration (Localhost XAMPP)
$servername = "localhost";
$username   = "root";
$password   = "";               // Default XAMPP password
$dbname     = "assignment_db";  // Your local database name

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

/* ToyyibPay Configuration */
$toyyibPaySecretKey = 'ewn3nxcp-bsmp-l05y-po0z-gicm19764afx';
$toyyibPayBaseUrl = 'https://dev.toyyibpay.com';
$subscriptionCategoryCode = 'dsxvw9mu'; // AI Checker Subscription
$assignmentCategoryCode   = 'j57qrdj8'; // Assignment Payment

/* Local project URL */
$siteUrl = "http://localhost/assignment_db";

?>