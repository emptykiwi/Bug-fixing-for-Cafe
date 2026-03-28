<?php
session_start(); 

// 1. Enable error reporting to debug 500 errors
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 2. Connect to Database using your specific file
require_once 'db_connect.php';

// Check connection
if (!isset($conn) || $conn->connect_error) {
    die("Connection failed: " . ($conn->connect_error ?? "Database variable missing"));
}

// 3. Include Audit Log (Safely)
if (file_exists('audit_log.php')) {
    include 'audit_log.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id']);
    $action = $_POST['action'];

    // Use the single $conn from db_connect.php
    $conn->begin_transaction();
    try {
        // Determine identifying column
        $chk = $conn->query("SHOW COLUMNS FROM recently_deleted_products LIKE 'bin_id'");
        $id_col = ($chk && $chk->num_rows > 0) ? "bin_id" : "id";

        // Get the deleted product record
        $stmt = $conn->prepare("SELECT * FROM recently_deleted_products WHERE $id_col = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $product = $result->fetch_assoc();

        if (!$product) {
            throw new Exception("Product not found in recently deleted items.");
        }

        if ($action === 'restore') {
            // Restore: Insert it back into the main 'products' table dynamically
            $columns = [];
            $res_cols = $conn->query("SHOW COLUMNS FROM products");
            while ($c = $res_cols->fetch_assoc()) { $columns[] = $c['Field']; }
            
            $col_names = []; $placeholders = []; $values = []; $types = "";
            foreach ($columns as $col) {
                if (array_key_exists($col, $product)) {
                    $col_names[] = "`$col`";
                    $placeholders[] = "?";
                    $values[] = $product[$col];
                    $types .= "s";
                }
            }
            
            $sql_restore = "INSERT INTO products (" . implode(", ", $col_names) . ") VALUES (" . implode(", ", $placeholders) . ")";
            $res_stmt = $conn->prepare($sql_restore);
            $res_stmt->bind_param($types, ...$values);
            $res_stmt->execute();
            $new_id = $conn->insert_id;
            $res_stmt->close();

            // Now, delete from the 'recently_deleted_products' table
            $delete_stmt = $conn->prepare("DELETE FROM recently_deleted_products WHERE $id_col = ?");
            $delete_stmt->bind_param("i", $id);
            $delete_stmt->execute();
            $delete_stmt->close();
            
            $_SESSION['success_message'] = "Product restored successfully!";

            if (function_exists('logAdminAction')) {
                logAdminAction($conn, $_SESSION['user_id'] ?? 0, $_SESSION['fullname'] ?? 'Admin', 'product_restore', "Restored product: {$product['name']} (New ID: $new_id)", 'products', $new_id);
            }

        } elseif ($action === 'permanent_delete') {
            // Permanently delete: first, remove the image file if it exists
            if (!empty($product['image']) && file_exists($product['image'])) {
                @unlink($product['image']); 
            }
            // Delete the record permanently
            $delete_stmt = $conn->prepare("DELETE FROM recently_deleted_products WHERE $id_col = ?");
            $delete_stmt->bind_param("i", $id);
            $delete_stmt->execute();
            
            $_SESSION['success_message'] = "Product permanently deleted!";

            // Log Action
            if (function_exists('logAdminAction')) {
                logAdminAction(
                    $conn,
                    $_SESSION['user_id'] ?? 0,
                    $_SESSION['fullname'] ?? 'Admin',
                    'product_delete_permanent',
                    "Permanently deleted product: {$product['name']}",
                    'recently_deleted_products',
                    $id
                );
            }
        }

        $conn->commit();

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_message'] = "An error occurred: " . $e->getMessage();
    }
}

// Close connection
$conn->close();

// Redirect back
header("Location: recently_deleted.php");
exit();
?>