<?php
/**
 * recycle_bin_helper.php
 * Helper functions to handle robust Recycle Bin schema management and data transfers.
 */

/**
 * Ensures the target recycle bin table perfectly mirrors the structure of its source table.
 * All columns in the target table (except PK and deleted_at) are made NULLABLE 
 * to ensure that even incomplete data can be safely moved to the bin.
 *
 * @param mysqli $conn The database connection
 * @param string $source The source table name (e.g., 'products')
 * @param string $target The target recycle bin table name (e.g., 'recently_deleted_products')
 * @throws Exception if schema synchronization fails
 */
function syncRecycleBinSchema($conn, $source, $target) {
    // 1. Create table IF NOT EXISTS
    $chk_table = $conn->query("SHOW TABLES LIKE '$target'");
    if (!$chk_table || $chk_table->num_rows == 0) {
        $conn->query("CREATE TABLE `$target` LIKE `$source`") or throw new Exception("Failed to create $target: " . $conn->error);
        // After LIKE, we might need to immediately drop primary key to avoid collisions
        @$conn->query("ALTER TABLE `$target` DROP PRIMARY KEY");
    }
    
    // 2. Add missing columns from source to target
    $res_cols = $conn->query("SHOW COLUMNS FROM `$source`") or throw new Exception("Failed to show columns from $source: " . $conn->error);
    while ($c = $res_cols->fetch_assoc()) {
        $col = $c['Field'];
        $type = $c['Type'];
        
        $chk = $conn->query("SHOW COLUMNS FROM `$target` LIKE '$col'");
        if ($chk && $chk->num_rows == 0) {
            $conn->query("ALTER TABLE `$target` ADD COLUMN `$col` $type NULL") or throw new Exception("Failed to add $col to $target: " . $conn->error);
        }
    }

    // 3. "Dumb down" the columns in target table:
    // We want to remove AUTO_INCREMENT and UNIQUE constraints from original columns, 
    // and make them all nullable so they don't block insertions.
    $res_target_cols = $conn->query("SHOW COLUMNS FROM `$target`") or throw new Exception("Failed to show columns from $target: " . $conn->error);
    while ($tc = $res_target_cols->fetch_assoc()) {
        $col_name = $tc['Field'];
        $col_type = $tc['Type'];
        $extra = strtolower($tc['Extra']);
        
        // Skip the new primary key we might add
        if ($col_name === 'bin_id') continue;
        
        // Skip deleted_at
        if ($col_name === 'deleted_at') continue;

        // Force NULLABLE and remove any extra properties (like auto_increment)
        // If it was auto_increment, we MUST modify it to remove that BEFORE we can drop the primary key
        if (strpos($extra, 'auto_increment') !== false) {
            $conn->query("ALTER TABLE `$target` MODIFY COLUMN `$col_name` $col_type NULL") or throw new Exception("Failed to remove auto_increment from $col_name in $target: " . $conn->error);
        } else {
            $conn->query("ALTER TABLE `$target` MODIFY COLUMN `$col_name` $col_type NULL") or throw new Exception("Failed to modify $col_name in $target: " . $conn->error);
        }
    }
    
    // 4. Ensure deleted_at column exists in target
    $chk_da = $conn->query("SHOW COLUMNS FROM `$target` LIKE 'deleted_at'");
    if (!$chk_da || $chk_da->num_rows == 0) {
        $conn->query("ALTER TABLE `$target` ADD COLUMN `deleted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP") or throw new Exception("Failed to add deleted_at to $target: " . $conn->error);
    }

    // 5. ENSURE bin_id is the ONLY Primary Key in the Recycle Bin
    // This allows us to delete the same ID multiple times and keep multiple copies.
    $chk_bin_id = $conn->query("SHOW COLUMNS FROM `$target` LIKE 'bin_id'");
    if (!$chk_bin_id || $chk_bin_id->num_rows == 0) {
        // A. Drop any existing primary key before adding bin_id
        // NOTE: Auto-increment must be removed first (already handled in step 3)
        $pk_res = $conn->query("SHOW INDEX FROM `$target` WHERE Key_name = 'PRIMARY'");
        if ($pk_res && $pk_res->num_rows > 0) {
            @$conn->query("ALTER TABLE `$target` DROP PRIMARY KEY");
        }
        
        // B. Add bin_id as Primary Key
        $conn->query("ALTER TABLE `$target` ADD COLUMN `bin_id` INT AUTO_INCREMENT PRIMARY KEY FIRST") or throw new Exception("Failed to add bin_id to $target: " . $conn->error);
    } else {
        // bin_id exists, but ensure it IS the primary key
        $pk_res = $conn->query("SHOW INDEX FROM `$target` WHERE Key_name = 'PRIMARY'");
        $has_correct_pk = false;
        if ($pk_res) {
            while ($pk = $pk_res->fetch_assoc()) {
                if ($pk['Column_name'] === 'bin_id') $has_correct_pk = true;
            }
        }
        
        if (!$has_correct_pk) {
            @$conn->query("ALTER TABLE `$target` DROP PRIMARY KEY");
            $conn->query("ALTER TABLE `$target` MODIFY COLUMN `bin_id` INT AUTO_INCREMENT PRIMARY KEY") or throw new Exception("Failed to set bin_id as primary key in $target: " . $conn->error);
        }
    }

    // 6. ALWAYS Drop all other unique indexes to prevent collisions
    // We do this every sync to catch any new indexes from source updates
    $res_idx = $conn->query("SHOW INDEX FROM `$target` WHERE Key_name != 'bin_id' AND Non_unique = 0");
    if ($res_idx && $res_idx->num_rows > 0) {
        $indexes_to_drop = [];
        while ($idx = $res_idx->fetch_assoc()) {
            if ($idx['Key_name'] === 'PRIMARY') {
                $indexes_to_drop[] = "DROP PRIMARY KEY";
            } else {
                $indexes_to_drop[] = "DROP INDEX `{$idx['Key_name']}`";
            }
        }
        $indexes_to_drop = array_unique($indexes_to_drop);
        foreach ($indexes_to_drop as $drop_cmd) {
            @$conn->query("ALTER TABLE `$target` $drop_cmd");
        }
    }
}

/**
 * Robustly moves a record from a source table to a target recycle bin table.
 *
 * @param mysqli $conn
 * @param string $source
 * @param string $target
 * @param int $id The record ID to move
 * @throws Exception if move fails
 */
function moveToRecycleBin($conn, $source, $target, $id) {
    // A. Sync schema first (Self-Healing)
    syncRecycleBinSchema($conn, $source, $target);

    // B. Get column list from source
    $columns = [];
    $res_cols = $conn->query("SHOW COLUMNS FROM `$source`") or throw new Exception($conn->error);
    while ($c = $res_cols->fetch_assoc()) {
        $columns[] = "`" . $c['Field'] . "`";
    }
    $col_list = implode(", ", $columns);

    // C. Perform Insert using SELECT
    $copy_sql = "INSERT INTO `$target` ($col_list, deleted_at) SELECT $col_list, NOW() FROM `$source` WHERE id = ?";
    $stmt = $conn->prepare($copy_sql);
    if (!$stmt) throw new Exception("Prepare move failed: " . $conn->error);
    $stmt->bind_param("i", $id);
    if (!$stmt->execute()) throw new Exception("Execute move failed: " . $stmt->error);
    
    if ($stmt->affected_rows === 0) {
        throw new Exception("No record found in $source with ID $id.");
    }
    $stmt->close();
}
?>
