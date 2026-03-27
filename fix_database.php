<?php
require_once 'config.php';

// 1. Add 'is_verified' column if it's missing
$sql = "ALTER TABLE users ADD COLUMN is_verified TINYINT(1) DEFAULT 0 AFTER role";
if ($conn->query($sql) === TRUE) {
    echo "<p style='color:green;'>✅ Successfully added 'is_verified' column.</p>";
    // Mark existing users as verified
    $conn->query("UPDATE users SET is_verified = 1 WHERE is_verified = 0");
} else {
    if (strpos($conn->error, 'Duplicate column') !== false) {
        echo "<p style='color:blue;'>ℹ️ 'is_verified' column already exists.</p>";
    } else {
        echo "<p style='color:red;'>❌ Error adding column: " . $conn->error . "</p>";
    }
}

// 2. Ensure 'contact' column exists (just in case)
$sql2 = "ALTER TABLE users ADD COLUMN contact VARCHAR(20) AFTER email";
if ($conn->query($sql2)) {
    echo "<p style='color:green;'>✅ Successfully added 'contact' column.</p>";
}

// 3. Sync status for my_orders.php
echo "<h3>Syncing order statuses...</h3>";
$res = $conn->query("SELECT user_id, total, status, created_at FROM cart WHERE status != 'Pending'");
while($row = $res->fetch_assoc()) {
    $uid = $row['user_id'];
    $tot = $row['total'];
    $st = $row['status'];
    $cat = $row['created_at'];

    // Improved Sync to orders table heuristic: Match user, total and approximate creation time (within 1 hour)
    $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE user_id = ? AND total = ? AND ABS(TIMESTAMPDIFF(SECOND, created_at, ?)) < 3600 AND (status IS NULL OR status = '' OR status = 'Pending') LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("sids", $st, $uid, $tot, $cat);
        $stmt->execute();
        $stmt->close();
    }
}

echo "<h3>Database Fix Complete. Please delete this file and try registering again.</h3>";
echo "<a href='index.php'>Go back to Home</a>";
$conn->close();
?>