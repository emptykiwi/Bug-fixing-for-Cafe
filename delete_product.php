<?php
// 1. ENABLE ERROR REPORTING (Helps fix the 500 error)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 2. CONNECT TO DATABASE
include 'db_connect.php'; 
require_once 'audit_log.php';
require_once 'recycle_bin_helper.php';

// 3. CHECK IF ID IS PRESENT
if (isset($_GET['id'])) {
    $product_id = $_GET['id'];

    // VALIDATE ID (Make sure it's a number to prevent SQL injection)
    if (!is_numeric($product_id)) {
        die("Invalid ID");
    }

    try {
        $conn->begin_transaction();
        
        // --- STEP 1: GET PRODUCT DETAILS ---
        $sel = $conn->prepare("SELECT * FROM products WHERE id = ?");
        $sel->bind_param("i", $product_id);
        $sel->execute();
        $product = $sel->get_result()->fetch_assoc();
        $sel->close();

        if (!$product) {
            throw new Exception("Product #$product_id not found.");
        }

        $p_name = $product['name'] ?? "ID #$product_id";

        // --- STEP 2: ROBUST RECYCLE BIN LOGIC (Helper Used) ---
        moveToRecycleBin($conn, 'products', 'recently_deleted_products', $product_id);

        // --- STEP 3: DELETE FROM PRODUCTS ---
        $del = $conn->prepare("DELETE FROM products WHERE id = ?");
        $del->bind_param("i", $product_id);
        if (!$del->execute()) throw new Exception("Failed to delete product from inventory: " . $del->error);
        $del->close();

        // --- STEP 4: LOG & COMMIT ---
        $conn->commit();
        logAdminAction($conn, $_SESSION['user_id'] ?? 0, $_SESSION['fullname'] ?? 'Admin', 'product_to_recycle', "Moved product '$p_name' to Recycle Bin", 'products', $product_id);
        
        header("Location: practiceaddproduct.php?msg=ProductMovedToBin");
        exit();

    } catch (Exception $e) {
        if (isset($conn)) $conn->rollback();
        // This will print the specific error if something goes wrong
        echo "Error: " . $e->getMessage();
    }

} else {
    // If no ID is provided, go back
    header("Location: practiceaddproduct.php?error=NoID");
    exit();
}

$conn->close();
?>