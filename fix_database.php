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

// Ensure recently_deleted matches cart plus deleted_at
$chk_rd = $conn->query("SHOW TABLES LIKE 'recently_deleted'");
if ($chk_rd && $chk_rd->num_rows > 0) {
    echo "Updating recently_deleted schema to match cart...\n";
    // We add missing columns from cart to recently_deleted
    $res_cols = $conn->query("SHOW COLUMNS FROM cart");
    while ($c = $res_cols->fetch_assoc()) {
        $col = $c['Field'];
        $type = $c['Type'];
        $null = ($c['Null'] == 'YES') ? 'NULL' : 'NOT NULL';
        $def = ($c['Default'] !== null) ? "DEFAULT '" . $conn->real_escape_string($c['Default']) . "'" : "";

        $chk = $conn->query("SHOW COLUMNS FROM `recently_deleted` LIKE '$col'");
        if ($chk && $chk->num_rows == 0) {
            echo "Adding $col to recently_deleted...\n";
            $conn->query("ALTER TABLE `recently_deleted` ADD COLUMN `$col` $type $null $def");
        }
    }

    $chk_da = $conn->query("SHOW COLUMNS FROM `recently_deleted` LIKE 'deleted_at'");
    if ($chk_da && $chk_da->num_rows == 0) {
        $conn->query("ALTER TABLE `recently_deleted` ADD COLUMN `deleted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    }
} else {
    echo "Creating recently_deleted table...\n";
    $conn->query("CREATE TABLE `recently_deleted` LIKE cart");
    $conn->query("ALTER TABLE `recently_deleted` ADD COLUMN `deleted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
}

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
