<?php
// fix_database.php - Utility to repair database consistency
require_once 'config.php';

echo "Starting database repair...\n";

// 1. Ensure columns exist and tables match
echo "Checking table structures...\n";
$chk_cart = $conn->query("SHOW COLUMNS FROM `cart` LIKE 'order_id'");
if ($chk_cart && $chk_cart->num_rows == 0) {
    echo "Adding order_id to cart...\n";
    $conn->query("ALTER TABLE `cart` ADD COLUMN `order_id` INT NULL AFTER `user_id`");
}

// Helper functions moved to recycle_bin_helper.php

// Include helper for syncRecycleBinSchema
require_once 'recycle_bin_helper.php';

syncRecycleBinSchema($conn, 'cart', 'recently_deleted');
syncRecycleBinSchema($conn, 'products', 'recently_deleted_products');
syncRecycleBinSchema($conn, 'users', 'recently_deleted_users');

// 2. Link cart items to orders
echo "Linking cart items to orders...\n";
$cart_res = $conn->query("SELECT id, user_id, total, created_at, order_id FROM cart WHERE order_id IS NULL OR order_id = 0");
if ($cart_res) {
    while ($cart = $cart_res->fetch_assoc()) {
        $cid = $cart['id'];
        $uid = $cart['user_id'];
        $total = $cart['total'];
        $date = date('Y-m-d', strtotime($cart['created_at']));

        $order_stmt = $conn->prepare("SELECT id FROM orders WHERE user_id = ? AND total = ? AND DATE(created_at) = ? ORDER BY created_at DESC LIMIT 1");
        $order_stmt->bind_param("ids", $uid, $total, $date);
        $order_stmt->execute();
        $order_res = $order_stmt->get_result();

        if ($order = $order_res->fetch_assoc()) {
            $oid = $order['id'];
            $up_stmt = $conn->prepare("UPDATE cart SET order_id = ? WHERE id = ?");
            $up_stmt->bind_param("ii", $oid, $cid);
            $up_stmt->execute();
            $up_stmt->close();
            echo "Linked Cart #$cid to Order #$oid\n";
        }
        $order_stmt->close();
    }
}

// 3. Sync statuses
echo "Syncing statuses between cart and orders...\n";
$sync_res = $conn->query("SELECT id, order_id, status FROM cart WHERE order_id IS NOT NULL AND order_id > 0");
if ($sync_res) {
    while ($row = $sync_res->fetch_assoc()) {
        $oid = $row['order_id'];
        $status = $row['status'];

        $up_stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $up_stmt->bind_param("si", $status, $oid);
        $up_stmt->execute();
        $up_stmt->close();
    }
}

echo "Database repair complete!\n";
?>
