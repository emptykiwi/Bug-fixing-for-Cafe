<?php
// Set MySQLi to return false on errors instead of throwing exceptions
mysqli_report(MYSQLI_REPORT_OFF);
// Database configuration
$host = "localhost";
$user = "u763865560_Mancave";
$password = "ManCave2025";
$database = "u763865560_EmmanuelCafeDB";

// Set default timezone to UTC+08:00 Taipei
date_default_timezone_set('Asia/Taipei');

// Create connection
$conn = new mysqli($host, $user, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to ensure special characters display correctly
$conn->set_charset("utf8mb4");

// Set MySQL session timezone to UTC+08:00
$conn->query("SET time_zone = '+08:00'");