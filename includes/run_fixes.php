<?php
// run_fixes.php - Run this first to fix all database issues
require_once 'config/database.php';

try {
    echo "<h3>Fixing Database Issues...</h3>";
    
    // 1. Add missing columns to groups table
    $sql1 = "ALTER TABLE groups ADD COLUMN IF NOT EXISTS is_active BOOLEAN DEFAULT TRUE";
    if ($conn->query($sql1)) {
        echo "✓ Added is_active column to groups table<br>";
    }
    
    // 2. Add missing columns to cbo_members table
    $sql2 = "ALTER TABLE cbo_members 
             ADD COLUMN IF NOT EXISTS left_date DATE NULL,
             ADD COLUMN IF NOT EXISTS left_reason VARCHAR(255) NULL";
    if ($conn->query($sql2)) {
        echo "✓ Added left_date and left_reason columns to cbo_members<br>";
    }
    
    // 3. Fix duplicate groups
    echo "<br><h4>Fixing duplicate groups...</h4>";
    $cbos = $conn->query("SELECT id FROM cbo");
    while ($cbo = $cbos->fetch_assoc()) {
        $cbo_id = $cbo['id'];
        
        // Find duplicate groups
        $duplicate_sql = "SELECT group_number, COUNT(*) as count 
                         FROM groups 
                         WHERE cbo_id = $cbo_id 
                         GROUP BY group_number 
                         HAVING count > 1";
        $duplicate_result = $conn->query($duplicate_sql);
        
        if ($duplicate_result->num_rows > 0) {
            echo "Found duplicates in CBO $cbo_id<br>";
            
            // Delete duplicates, keep the one with highest ID
            $delete_duplicates = "DELETE g1 FROM groups g1
                                INNER JOIN groups g2 
                                WHERE g1.id < g2.id 
                                AND g1.cbo_id = g2.cbo_id 
                                AND g1.group_number = g2.group_number
                                AND g1.cbo_id = $cbo_id";
            if ($conn->query($delete_duplicates)) {
                echo "✓ Removed duplicate groups from CBO $cbo_id<br>";
            }
        }
    }
    
    // 4. Reset group numbering
    echo "<br><h4>Resetting group numbering...</h4>";
    $cbos->data_seek(0);
    while ($cbo = $cbos->fetch_assoc()) {
        $cbo_id = $cbo['id'];
        
        $groups = $conn->query("SELECT id FROM groups WHERE cbo_id = $cbo_id ORDER BY id");
        $counter = 1;
        
        while ($group = $groups->fetch_assoc()) {
            $update_sql = "UPDATE groups SET group_number = $counter WHERE id = {$group['id']}";
            $conn->query($update_sql);
            $counter++;
        }
        echo "✓ Reset group numbering for CBO $cbo_id<br>";
    }
    
    // 5. Update all groups to active
    $sql3 = "UPDATE groups SET is_active = TRUE WHERE is_active IS NULL OR is_active = FALSE";
    if ($conn->query($sql3)) {
        echo "✓ Updated all groups to active<br>";
    }
    
    echo "<br><h3 style='color: green;'>✅ All database fixes completed successfully!</h3>";
    echo "<p>You can now use the CBO system normally.</p>";
    
} catch (Exception $e) {
    echo "<h3 style='color: red;'>❌ Error: " . $e->getMessage() . "</h3>";
}
?>