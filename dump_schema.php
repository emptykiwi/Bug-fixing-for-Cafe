<?php
require_once 'config.php';
foreach(['orders', 'cart', 'recently_deleted'] as $table) {
    echo "--- $table ---\n";
    $res = $conn->query("SHOW COLUMNS FROM $table");
    if ($res) {
        while($row = $res->fetch_assoc()) {
            print_r($row);
        }
    } else {
        echo "Table does not exist.\n";
    }
}
?>
