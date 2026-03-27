<?php
// diagnose_error.php
// A utility to help diagnose 500 errors on the Cafe Emmanuel server

// 1. Force error reporting ON
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h2>Cafe Emmanuel - Server Diagnostic Tool</h2>";
echo "PHP Version: " . PHP_VERSION . "<br>";
echo "Current Time: " . date('Y-m-d H:i:s') . "<br><hr>";

// 2. Check Database
echo "<h3>1. Database Check</h3>";
if (!file_exists('db_connect.php')) {
    echo "<span style='color:red;'>ERROR: db_connect.php not found!</span><br>";
} else {
    require_once 'db_connect.php';
    if (!isset($conn)) {
        echo "<span style='color:red;'>ERROR: $conn variable not found after including db_connect.php!</span><br>";
    } elseif ($conn->connect_error) {
        echo "<span style='color:red;'>ERROR: Database connection failed: " . $conn->connect_error . "</span><br>";
    } else {
        echo "<span style='color:green;'>SUCCESS: Database connected.</span><br>";
        
        $table_check = $conn->query("SHOW TABLES LIKE 'reservations'");
        if ($table_check->num_rows == 0) {
            echo "<span style='color:orange;'>WARNING: 'reservations' table does not exist.</span><br>";
        } else {
            echo "<span style='color:green;'>SUCCESS: 'reservations' table exists.</span><br>";
            $col_check = $conn->query("SHOW COLUMNS FROM reservations LIKE 'valid_id'");
            if ($col_check->num_rows == 0) {
                echo "<span style='color:red;'>ERROR: 'valid_id' column is missing in reservations table. You MUST run migrate_reservations.php</span><br>";
            } else {
                echo "<span style='color:green;'>SUCCESS: 'valid_id' column found.</span><br>";
            }
        }
    }
}

// 3. Check Folders
echo "<h3>2. Permissions Check</h3>";
$folders = ['uploads', 'uploads/reservations'];
foreach ($folders as $folder) {
    if (!is_dir($folder)) {
        echo "<span style='color:orange;'>WARNING: Folder '$folder' does not exist.</span><br>";
    } else {
        echo "Folder '$folder' exists. ";
        if (is_writable($folder)) {
            echo "<span style='color:green;'>Writable.</span><br>";
        } else {
            echo "<span style='color:red;'>NOT WRITABLE!</span><br>";
        }
    }
}

// 4. Check PHPMailer
echo "<h3>3. PHPMailer Check</h3>";
$mailer_files = ['PHPMailer/PHPMailer.php', 'PHPMailer/Exception.php', 'PHPMailer/SMTP.php'];
foreach ($mailer_files as $file) {
    if (file_exists($file)) {
        echo "File '$file' found.<br>";
    } else {
        echo "<span style='color:red;'>ERROR: '$file' MISSING!</span><br>";
    }
}

echo "<hr><p>If all items above are green but you still get a 500 error, please check the 'Error Log' in your hosting control panel (Hostinger) for the exact error message.</p>";
echo "<a href='index.php'>Go back to Home</a>";
?>