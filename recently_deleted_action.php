<?php
include 'db_connect.php';
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id']);
    $action = $_POST['action'];

    $chk = $conn->query("SHOW COLUMNS FROM recently_deleted LIKE 'bin_id'");
    $id_col = ($chk && $chk->num_rows > 0) ? "bin_id" : "id";

    // kunin muna yung record
    $res = $conn->query("SELECT * FROM recently_deleted WHERE $id_col = $id");
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();

        if ($action === 'restore') {
            // ibalik sa orders / cart tables
            // Logic handled by restore_delete.php for consistency
            require_once 'restore_delete.php';
            exit;
        } elseif ($action === 'permanent_delete') {
            // tuluyang burahin
            $conn->query("DELETE FROM recently_deleted WHERE $id_col = $id");
        }
    }
}

header("Location: recently_deleted.php");
exit;
