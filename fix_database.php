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

// 3. Robust Link and Sync for my_orders.php
echo "<h3>Linking and Syncing order statuses...</h3>";

// Link cart.order_id to orders.id first
$res = $conn->query("SELECT id, user_id, total, created_at FROM cart WHERE order_id IS NULL");
while($row = $res->fetch_assoc()) {
    $cid = $row['id'];
    $uid = $row['user_id'];
    $tot = $row['total'];
    $cat = $row['created_at'];

    $stmt = $conn->prepare("SELECT id FROM orders WHERE user_id = ? AND total = ? AND DATE(created_at) = DATE(?) LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("ids", $uid, $tot, $cat);
        $stmt->execute();
        $ord_id = $stmt->get_result()->fetch_assoc()['id'] ?? null;
        $stmt->close();

        if ($ord_id) {
            $conn->query("UPDATE cart SET order_id = $ord_id WHERE id = $cid");
        }
    }
}

// Now Sync statuses based on the link or heuristic
$res = $conn->query("SELECT order_id, user_id, total, status, created_at FROM cart WHERE status != 'Pending'");
while($row = $res->fetch_assoc()) {
    $oid = $row['order_id'];
    $uid = $row['user_id'];
    $tot = $row['total'];
    $st  = $row['status'];
    $cat = $row['created_at'];

    if ($oid) {
        $conn->query("UPDATE orders SET status = '$st' WHERE id = $oid AND (status IS NULL OR status = '' OR status = 'Pending')");
    } else {
        $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE user_id = ? AND total = ? AND DATE(created_at) = DATE(?) AND (status IS NULL OR status = '' OR status = 'Pending') LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("sids", $st, $uid, $tot, $cat);
            $stmt->execute();
            $stmt->close();
        }
    }
}

echo "<h3>Database Fix Complete. Please delete this file and try registering again.</h3>";
echo "<a href='index.php'>Go back to Home</a>";
$conn->close();
?>