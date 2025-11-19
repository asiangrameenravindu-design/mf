<?php
// includes/functions.php

/**
 * Check if user is logged in and redirect to login if not
 */
function requireLogin() {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
        header('Location: ../login.php');
        exit();
    }
}

/**
 * Get user role
 */
function getUserRole() {
    return isset($_SESSION['user_type']) ? $_SESSION['user_type'] : 'user';
}

/**
 * Check if user has specific role
 */
function hasRole($role) {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === $role;
}

/**
 * Sanitize user input
 */
function sanitizeInput($data) {
    global $conn;
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $conn->real_escape_string($data);
}

/**
 * Generate short name from full name
 */
function generateShortName($full_name) {
    // Split the full name into words
    $name_parts = explode(' ', trim($full_name));
    
    if (count($name_parts) >= 2) {
        // If there are at least 2 words, use first letter of first name + last name
        $first_name = $name_parts[0];
        $last_name = end($name_parts);
        return strtoupper(substr($first_name, 0, 1) . $last_name);
    } else {
        // If only one word, use first 4 characters
        return strtoupper(substr($full_name, 0, 4));
    }
}

// Customer related functions
/**
 * Check if customer NIC already exists
 */
function isCustomerNICExists($nic, $exclude_id = null) {
    global $conn;
    
    if ($exclude_id) {
        $sql = "SELECT id FROM customers WHERE national_id = ? AND id != ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $nic, $exclude_id);
    } else {
        $sql = "SELECT id FROM customers WHERE national_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $nic);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->num_rows > 0;
}

/**
 * Check if customer phone already exists
 */
function isCustomerPhoneExists($phone, $exclude_id = null) {
    global $conn;
    
    if ($exclude_id) {
        $sql = "SELECT id FROM customers WHERE phone = ? AND id != ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $phone, $exclude_id);
    } else {
        $sql = "SELECT id FROM customers WHERE phone = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $phone);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->num_rows > 0;
}

/**
 * Get all active customers
 */
function getAllCustomers() {
    global $conn;
    
    $sql = "SELECT * FROM customers WHERE status = 'active' ORDER BY full_name";
    $result = $conn->query($sql);
    
    return $result;
}

/**
 * Get customer details by ID
 */
function getCustomerDetails($customer_id) {
    global $conn;
    
    $sql = "SELECT * FROM customers WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc();
}

// CBO related functions
/**
 * Get staff members by position
 */
function getStaffByPosition($position) {
    global $conn;
    
    $sql = "SELECT * FROM staff WHERE position = ? ORDER BY full_name";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $position);
    $stmt->execute();
    
    return $stmt->get_result();
}

/**
 * Get all CBOs with staff information
 */
function getCBOs() {
    global $conn;
    
    $sql = "SELECT c.*, s.full_name as staff_name 
            FROM cbo c 
            LEFT JOIN staff s ON c.staff_id = s.id 
            ORDER BY c.name";
    $result = $conn->query($sql);
    
    return $result;
}

/**
 * Get CBO by ID
 */
function getCBOById($cbo_id) {
    global $conn;
    
    $sql = "SELECT c.*, s.full_name as staff_name 
            FROM cbo c 
            LEFT JOIN staff s ON c.staff_id = s.id 
            WHERE c.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $cbo_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc();
}

/**
 * Get next CBO number
 */
function getNextCBONumber() {
    global $conn;
    
    $sql = "SELECT MAX(cbo_number) as max_number FROM cbo";
    $result = $conn->query($sql);
    $data = $result->fetch_assoc();
    
    return $data['max_number'] ? $data['max_number'] + 1 : 1001;
}

/**
 * Get CBO active members
 */
function getCBOActiveMembers($cbo_id) {
    global $conn;
    
    $sql = "SELECT cm.*, c.full_name, c.short_name, c.national_id
            FROM cbo_members cm
            JOIN customers c ON cm.customer_id = c.id
            WHERE cm.cbo_id = ? AND cm.status = 'active'
            ORDER BY c.full_name";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $cbo_id);
    $stmt->execute();
    
    return $stmt->get_result();
}

/**
 * Get CBO active groups
 */
function getCBOActiveGroups($cbo_id) {
    global $conn;
    
    $sql = "SELECT g.*, 
            (SELECT COUNT(*) FROM group_members WHERE group_id = g.id) as member_count
            FROM groups g 
            WHERE g.cbo_id = ? AND g.is_active = 1
            ORDER BY g.group_number";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $cbo_id);
    $stmt->execute();
    
    return $stmt->get_result();
}

/**
 * Get group members
 */
function getGroupMembers($group_id) {
    global $conn;
    
    $sql = "SELECT gm.*, c.full_name, c.short_name, c.national_id
            FROM group_members gm
            JOIN customers c ON gm.customer_id = c.id
            WHERE gm.group_id = ?
            ORDER BY c.full_name";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    
    return $stmt->get_result();
}

/**
 * Get customer's group in CBO
 */
function getCustomerGroupInCBO($customer_id, $cbo_id) {
    global $conn;
    
    $sql = "SELECT g.* 
            FROM group_members gm
            JOIN groups g ON gm.group_id = g.id
            WHERE gm.customer_id = ? AND g.cbo_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $customer_id, $cbo_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc();
}

/**
 * Remove customer from CBO groups
 */
function removeCustomerFromCBOGroups($customer_id, $cbo_id) {
    global $conn;
    
    $sql = "DELETE gm FROM group_members gm
            JOIN groups g ON gm.group_id = g.id
            WHERE gm.customer_id = ? AND g.cbo_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $customer_id, $cbo_id);
    
    return $stmt->execute();
}

/**
 * Check if group name exists in CBO
 */
function isGroupNameExistsInCBO($cbo_id, $group_name) {
    global $conn;
    
    $sql = "SELECT id FROM groups WHERE cbo_id = ? AND group_name = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $cbo_id, $group_name);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->num_rows > 0;
}

/**
 * Get next group number for CBO
 */
function getNextGroupNumber($cbo_id) {
    global $conn;
    
    $sql = "SELECT MAX(group_number) as max_number FROM groups WHERE cbo_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $cbo_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    
    return $data['max_number'] ? $data['max_number'] + 1 : 1;
}

/**
 * Check if customer can change CBO
 */
function canCustomerChangeCBO($customer_id) {
    global $conn;
    
    // Check if customer has active loans
    $sql = "SELECT COUNT(*) as active_loans 
            FROM loans 
            WHERE customer_id = ? AND status IN ('active', 'disbursed', 'approved')";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    
    if ($data['active_loans'] > 0) {
        return [
            'can_change' => false,
            'reason' => 'Customer has active loans. Cannot change CBO until all loans are settled.'
        ];
    }
    
    return ['can_change' => true, 'reason' => ''];
}

/**
 * Reactivate CBO membership
 */
function reactivateCBOMembership($cbo_id, $customer_id) {
    global $conn;
    
    $sql = "UPDATE cbo_members 
            SET status = 'active', joined_date = CURDATE(), left_date = NULL, left_reason = NULL
            WHERE cbo_id = ? AND customer_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $cbo_id, $customer_id);
    
    return $stmt->execute();
}

// Staff related functions
/**
 * Get all active staff members
 */
function getActiveStaff() {
    global $conn;
    
    $sql = "SELECT * FROM staff WHERE status = 'active' ORDER BY full_name";
    $result = $conn->query($sql);
    
    return $result;
}

/**
 * Check if staff email already exists
 */
function isStaffEmailExists($email, $exclude_id = null) {
    global $conn;
    
    if ($exclude_id) {
        $sql = "SELECT id FROM staff WHERE email = ? AND id != ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $email, $exclude_id);
    } else {
        $sql = "SELECT id FROM staff WHERE email = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $email);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->num_rows > 0;
}

/**
 * Check if staff NIC already exists
 */
function isStaffNICExists($nic, $exclude_id = null) {
    global $conn;
    
    if ($exclude_id) {
        $sql = "SELECT id FROM staff WHERE nic = ? AND id != ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $nic, $exclude_id);
    } else {
        $sql = "SELECT id FROM staff WHERE nic = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $nic);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->num_rows > 0;
}

// Loan related functions
/**
 * Get customer by NIC number
 */
function getCustomerByNIC($nic) {
    global $conn;
    
    $sql = "SELECT * FROM customers WHERE national_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $nic);
    $stmt->execute();
    
    return $stmt->get_result();
}

/**
 * Get customer by ID
 */
function getCustomerById($customer_id) {
    global $conn;
    
    $sql = "SELECT * FROM customers WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc();
}

/**
 * Get loan by ID
 */
function getLoanById($loan_id) {
    global $conn;
    
    $sql = "SELECT l.*, 
                   c.full_name as customer_name, 
                   c.national_id, 
                   c.phone, 
                   c.address,
                   cb.name as cbo_name,
                   s.full_name as staff_name,
                   appr.full_name as approved_by_name,
                   rej.full_name as rejected_by_name,
                   disb.full_name as disbursed_by_name
            FROM loans l
            JOIN customers c ON l.customer_id = c.id
            JOIN cbo cb ON l.cbo_id = cb.id
            JOIN staff s ON l.staff_id = s.id
            LEFT JOIN staff appr ON l.approved_by = appr.id
            LEFT JOIN staff rej ON l.rejected_by = rej.id
            LEFT JOIN staff disb ON l.disbursed_by = disb.id
            WHERE l.id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $loan_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc();
}

/**
 * Check if customer can get a new loan
 */
function canCustomerGetLoan($customer_id) {
    global $conn;
    
    // Check if customer has any active loans
    $sql = "SELECT COUNT(*) as active_loans 
            FROM loans 
            WHERE customer_id = ? AND status IN ('active', 'disbursed', 'approved')";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    
    if ($data['active_loans'] > 0) {
        return [
            'can_loan' => false,
            'reason' => 'Customer has active loans. Cannot apply for new loan until existing loans are settled.'
        ];
    }
    
    return ['can_loan' => true, 'reason' => ''];
}

/**
 * Get pending loans
 */
function getPendingLoans() {
    global $conn;
    
    $sql = "SELECT l.*, c.full_name as customer_name, cb.name as cbo_name
            FROM loans l
            JOIN customers c ON l.customer_id = c.id
            JOIN cbo cb ON l.cbo_id = cb.id
            WHERE l.status = 'pending'
            ORDER BY l.applied_date ASC";
    
    $result = $conn->query($sql);
    return $result;
}

/**
 * Get approved loans
 */
function getApprovedLoans() {
    global $conn;
    
    $sql = "SELECT l.*, c.full_name as customer_name, cb.name as cbo_name
            FROM loans l
            JOIN customers c ON l.customer_id = c.id
            JOIN cbo cb ON l.cbo_id = cb.id
            WHERE l.status = 'approved'
            ORDER BY l.approved_date ASC";
    
    $result = $conn->query($sql);
    return $result;
}

/**
 * Get disbursed loans
 */
function getDisbursedLoans() {
    global $conn;
    
    $sql = "SELECT l.*, c.full_name as customer_name, cb.name as cbo_name
            FROM loans l
            JOIN customers c ON l.customer_id = c.id
            JOIN cbo cb ON l.cbo_id = cb.id
            WHERE l.status = 'disbursed'
            ORDER BY l.disbursed_date DESC";
    
    $result = $conn->query($sql);
    return $result;
}

/**
 * Calculate loan details
 */
function calculateLoanDetails($loan_amount, $number_of_weeks, $interest_rate, $document_charge) {
    $service_charge = $loan_amount * 0.03; // 3% service charge
    $interest_amount = ($loan_amount * $interest_rate / 100);
    $total_loan_amount = $loan_amount + $service_charge + $document_charge + $interest_amount;
    $weekly_installment = $total_loan_amount / $number_of_weeks;
    
    return [
        'loan_amount' => $loan_amount,
        'service_charge' => $service_charge,
        'document_charge' => $document_charge,
        'interest_rate' => $interest_rate,
        'interest_amount' => $interest_amount,
        'number_of_weeks' => $number_of_weeks,
        'total_loan_amount' => $total_loan_amount,
        'weekly_installment' => $weekly_installment
    ];
}

/**
 * Generate unique loan number
 */
function generateLoanNumber($cbo_id) {
    global $conn;
    
    // Get current year
    $current_year = date('Y');
    
    // Get CBO number
    $cbo_sql = "SELECT cbo_number FROM cbo WHERE id = ?";
    $cbo_stmt = $conn->prepare($cbo_sql);
    $cbo_stmt->bind_param("i", $cbo_id);
    $cbo_stmt->execute();
    $cbo_result = $cbo_stmt->get_result();
    $cbo_data = $cbo_result->fetch_assoc();
    $cbo_number = $cbo_data['cbo_number'];
    
    // Count loans for this CBO in current year
    $count_sql = "SELECT COUNT(*) as loan_count FROM loans 
                  WHERE cbo_id = ? AND YEAR(applied_date) = ?";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param("ii", $cbo_id, $current_year);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $count_data = $count_result->fetch_assoc();
    $loan_count = $count_data['loan_count'] + 1;
    
    // Format: LN/CBO_NUMBER/YEAR/SEQUENCE
    return sprintf("LN/%s/%s/%04d", $cbo_number, $current_year, $loan_count);
}

/**
 * Create loan installments
 */
function createLoanInstallments($loan_id, $weekly_installment, $number_of_weeks) {
    global $conn;
    
    try {
        for ($i = 1; $i <= $number_of_weeks; $i++) {
            $installment_sql = "INSERT INTO loan_installments (loan_id, installment_number, amount, status) 
                               VALUES (?, ?, ?, 'pending')";
            $installment_stmt = $conn->prepare($installment_sql);
            $installment_stmt->bind_param("iid", $loan_id, $i, $weekly_installment);
            $installment_stmt->execute();
        }
        return true;
    } catch (Exception $e) {
        error_log("Error creating installments: " . $e->getMessage());
        return false;
    }
}

/**
 * Get loan table for calculations
 */
function getLoanTable() {
    return [
        15000 => ['interest_rate' => 36.80, 'weeks' => 19, 'document_fee' => 450],
        20000 => ['interest_rate' => 35.30, 'weeks' => 22, 'document_fee' => 600],
        25000 => ['interest_rate' => 35.70, 'weeks' => 23, 'document_fee' => 750],
        30000 => ['interest_rate' => 35.20, 'weeks' => 24, 'document_fee' => 900],
        35000 => ['interest_rate' => 35.00, 'weeks' => 25, 'document_fee' => 1050],
        40000 => ['interest_rate' => 35.00, 'weeks' => 25, 'document_fee' => 1200],
        45000 => ['interest_rate' => 35.00, 'weeks' => 25, 'document_fee' => 1350],
        50000 => ['interest_rate' => 35.20, 'weeks' => 26, 'document_fee' => 1500],
        55000 => ['interest_rate' => 35.00, 'weeks' => 27, 'document_fee' => 1650],
        60000 => ['interest_rate' => 35.00, 'weeks' => 27, 'document_fee' => 1800],
        65000 => ['interest_rate' => 35.00, 'weeks' => 27, 'document_fee' => 1950],
        70000 => ['interest_rate' => 35.00, 'weeks' => 27, 'document_fee' => 2100],
        75000 => ['interest_rate' => 35.00, 'weeks' => 30, 'document_fee' => 2250],
        80000 => ['interest_rate' => 35.00, 'weeks' => 30, 'document_fee' => 2400],
        85000 => ['interest_rate' => 35.00, 'weeks' => 30, 'document_fee' => 2550],
        90000 => ['interest_rate' => 35.00, 'weeks' => 30, 'document_fee' => 2700],
        95000 => ['interest_rate' => 35.00, 'weeks' => 30, 'document_fee' => 2850],
        100000 => ['interest_rate' => 35.00, 'weeks' => 30, 'document_fee' => 3000]
    ];
}

/**
 * Check and create loan tables if they don't exist
 */
function checkAndCreateLoanTables() {
    global $conn;
    
    // Check if updated_by column exists
    $check_updated_sql = "SHOW COLUMNS FROM loans LIKE 'updated_by'";
    $updated_result = $conn->query($check_updated_sql);
    
    if ($updated_result->num_rows == 0) {
        // Add updated_by column
        $alter_sql = "ALTER TABLE loans ADD COLUMN updated_by INT NULL AFTER updated_at";
        $conn->query($alter_sql);
    }
    
    // Check if other required columns exist
    $check_columns_sql = "SHOW COLUMNS FROM loans LIKE 'approved_by'";
    $result = $conn->query($check_columns_sql);
    
    if ($result->num_rows == 0) {
        // Add missing columns to loans table
        $alter_sql = "ALTER TABLE loans 
                     ADD COLUMN approved_by INT NULL AFTER approved_date,
                     ADD COLUMN approval_notes TEXT NULL AFTER approved_by,
                     ADD COLUMN rejected_date DATE NULL AFTER approved_date,
                     ADD COLUMN rejected_by INT NULL AFTER rejected_date,
                     ADD COLUMN rejection_reason TEXT NULL AFTER rejected_by,
                     ADD COLUMN disbursed_by INT NULL AFTER disbursed_date,
                     ADD COLUMN disbursement_notes TEXT NULL AFTER disbursed_by";
        
        $conn->query($alter_sql);
    }
    
    // Check if loan_installments table has due_date column
    $check_due_date_sql = "SHOW COLUMNS FROM loan_installments LIKE 'due_date'";
    $due_date_result = $conn->query($check_due_date_sql);
    
    if ($due_date_result->num_rows == 0) {
        $alter_installments_sql = "ALTER TABLE loan_installments 
                                  ADD COLUMN due_date DATE NULL AFTER amount";
        $conn->query($alter_installments_sql);
    }
}

/**
 * Get loan statistics for dashboard
 */
function getLoanStatistics() {
    global $conn;
    
    $sql = "SELECT 
        COUNT(*) as total_loans,
        SUM(amount) as total_amount,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_loans,
        COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_loans,
        COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_loans,
        COUNT(CASE WHEN status = 'disbursed' THEN 1 END) as disbursed_loans,
        COUNT(CASE WHEN status = 'active' THEN 1 END) as active_loans,
        COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_loans
    FROM loans";
    
    $result = $conn->query($sql);
    return $result->fetch_assoc();
}

/**
 * Get recent loan activities
 */
function getRecentLoanActivities($limit = 10) {
    global $conn;
    
    $sql = "SELECT l.loan_number, c.full_name, l.status, l.updated_at,
                   CASE 
                       WHEN l.status = 'approved' THEN l.approved_date
                       WHEN l.status = 'rejected' THEN l.rejected_date
                       WHEN l.status = 'disbursed' THEN l.disbursed_date
                       ELSE l.applied_date
                   END as activity_date
            FROM loans l
            JOIN customers c ON l.customer_id = c.id
            ORDER BY l.updated_at DESC
            LIMIT ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    
    return $stmt->get_result();
}

/**
 * Get customer's loan history
 */
function getCustomerLoanHistory($customer_id) {
    global $conn;
    
    $sql = "SELECT l.*, cb.name as cbo_name
            FROM loans l
            JOIN cbo cb ON l.cbo_id = cb.id
            WHERE l.customer_id = ?
            ORDER BY l.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    
    return $stmt->get_result();
}

/**
 * Get overdue installments
 */
function getOverdueInstallments() {
    global $conn;
    
    $sql = "SELECT li.*, l.loan_number, c.full_name as customer_name
            FROM loan_installments li
            JOIN loans l ON li.loan_id = l.id
            JOIN customers c ON l.customer_id = c.id
            WHERE li.due_date < CURDATE() AND li.status = 'pending'
            ORDER BY li.due_date ASC";
    
    $result = $conn->query($sql);
    return $result;
}

/**
 * Update installment status
 */
function updateInstallmentStatus($installment_id, $status, $paid_amount = null, $payment_date = null) {
    global $conn;
    
    $sql = "UPDATE loan_installments 
            SET status = ?, paid_amount = ?, payment_date = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sdsi", $status, $paid_amount, $payment_date, $installment_id);
    
    return $stmt->execute();
}

/**
 * Get CB members
 */
function getCBOMembers($cbo_id) {
    global $conn;
    
    $sql = "SELECT c.*, cm.joined_date, cm.status as membership_status
            FROM customers c
            JOIN cbo_members cm ON c.id = cm.customer_id
            WHERE cm.cbo_id = ? AND cm.status = 'active'
            ORDER BY c.full_name";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $cbo_id);
    $stmt->execute();
    
    return $stmt->get_result();
}

/**
 * Add customer to CBO
 */
function addCustomerToCBO($customer_id, $cbo_id, $joined_date = null) {
    global $conn;
    
    if ($joined_date === null) {
        $joined_date = date('Y-m-d');
    }
    
    $sql = "INSERT INTO cbo_members (cbo_id, customer_id, joined_date, status) 
            VALUES (?, ?, ?, 'active')";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iis", $cbo_id, $customer_id, $joined_date);
    
    return $stmt->execute();
}

/**
 * Remove customer from CBO
 */
function removeCustomerFromCBO($customer_id, $cbo_id, $left_reason = '') {
    global $conn;
    
    $sql = "UPDATE cbo_members 
            SET status = 'inactive', left_date = CURDATE(), left_reason = ?
            WHERE cbo_id = ? AND customer_id = ? AND status = 'active'";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sii", $left_reason, $cbo_id, $customer_id);
    
    return $stmt->execute();
}

/**
 * Get staff by ID
 */
function getStaffById($staff_id) {
    global $conn;
    
    $sql = "SELECT * FROM staff WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $staff_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc();
}

/**
 * Get all staff members
 */
function getAllStaff() {
    global $conn;
    
    $sql = "SELECT * FROM staff ORDER BY full_name";
    $result = $conn->query($sql);
    
    return $result;
}

/**
 * Get all CBOs
 */
function getAllCBOs() {
    global $conn;
    
    $sql = "SELECT * FROM cbo ORDER BY name";
    $result = $conn->query($sql);
    
    return $result;
}

/**
 * Format currency
 */
function formatCurrency($amount) {
    return 'Rs. ' . number_format($amount, 2);
}

/**
 * Format date
 */
function formatDate($date, $format = 'F j, Y') {
    if (empty($date)) return '-';
    return date($format, strtotime($date));
}

/**
 * Get status badge class
 */
function getStatusBadgeClass($status) {
    $status_classes = [
        'pending' => 'bg-warning text-dark',
        'approved' => 'bg-success',
        'rejected' => 'bg-danger',
        'disbursed' => 'bg-info',
        'active' => 'bg-primary',
        'completed' => 'bg-secondary',
        'paid' => 'bg-success',
        'overdue' => 'bg-danger'
    ];
    
    return $status_classes[$status] ?? 'bg-secondary';
}

/**
 * Get status icon
 */
function getStatusIcon($status) {
    $status_icons = [
        'pending' => 'bi-clock',
        'approved' => 'bi-check-circle',
        'rejected' => 'bi-x-circle',
        'disbursed' => 'bi-cash-coin',
        'active' => 'bi-play-circle',
        'completed' => 'bi-flag-fill',
        'paid' => 'bi-check-circle',
        'overdue' => 'bi-exclamation-triangle'
    ];
    
    return $status_icons[$status] ?? 'bi-info-circle';
}

/**
 * Log activity
 */
function logActivity($user_id, $action, $description, $module = 'general') {
    global $conn;
    
    // First check if activity_log table exists
    $check_table_sql = "SHOW TABLES LIKE 'activity_log'";
    $table_result = $conn->query($check_table_sql);
    
    if ($table_result->num_rows == 0) {
        // Create activity_log table if it doesn't exist
        $create_table_sql = "CREATE TABLE IF NOT EXISTS activity_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            action VARCHAR(100) NOT NULL,
            description TEXT,
            module VARCHAR(50),
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $conn->query($create_table_sql);
    }
    
    $sql = "INSERT INTO activity_log (user_id, action, description, module, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    
    $stmt->bind_param("isssss", $user_id, $action, $description, $module, $ip_address, $user_agent);
    return $stmt->execute();
}

/**
 * Validate NIC number (Sri Lankan format)
 */
function validateNIC($nic) {
    // Remove any spaces or special characters
    $nic = preg_replace('/[^0-9Vv]/', '', $nic);
    
    // Check length (old: 10 chars, new: 12 chars)
    if (strlen($nic) != 10 && strlen($nic) != 12) {
        return false;
    }
    
    // Additional validation logic can be added here
    return true;
}

/**
 * Validate phone number (Sri Lankan format)
 */
function validatePhone($phone) {
    // Remove any spaces or special characters
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Check if it's a valid Sri Lankan mobile number
    if (strlen($phone) == 10 && in_array(substr($phone, 0, 2), ['07', '01'])) {
        return true;
    }
    
    return false;
}

/**
 * Send notification (placeholder function)
 */
function sendNotification($user_id, $title, $message, $type = 'info') {
    // This is a placeholder function
    // In a real application, you would implement email, SMS, or push notifications here
    error_log("Notification: $title - $message (User: $user_id, Type: $type)");
    return true;
}

/**
 * Check user permissions
 */
function hasPermission($required_role) {
    if (!isset($_SESSION['user_type'])) {
        return false;
    }
    
    $user_role = $_SESSION['user_type'];
    
    // Role hierarchy
    if ($required_role === 'manager') {
        return in_array($user_role, ['manager']);
    } elseif ($required_role === 'field_officer') {
        return in_array($user_role, ['manager', 'field_officer']);
    } elseif ($required_role === 'accountant') {
        return in_array($user_role, ['manager', 'accountant']);
    }
    
    return $user_role === $required_role;
}

/**
 * Redirect with message
 */
function redirectWithMessage($url, $message, $type = 'success') {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
    header("Location: $url");
    exit();
}

/**
 * Get flash message
 */
function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        $type = $_SESSION['flash_type'] ?? 'info';
        unset($_SESSION['flash_message']);
        unset($_SESSION['flash_type']);
        return ['message' => $message, 'type' => $type];
    }
    return null;
}

/**
 * Update loan status with proper error handling
 */
function updateLoanStatus($loan_id, $new_status, $user_id, $reason = '') {
    global $conn;
    
    try {
        $conn->begin_transaction();
        
        $update_sql = "UPDATE loans SET 
                      status = ?,
                      updated_by = ?,
                      updated_at = CURRENT_TIMESTAMP";
        
        // Add appropriate date field based on status
        if ($new_status === 'approved') {
            $update_sql .= ", approved_date = CURDATE(), approved_by = ?";
            if (!empty($reason)) {
                $update_sql .= ", approval_notes = ?";
            }
        } elseif ($new_status === 'rejected') {
            $update_sql .= ", rejected_date = CURDATE(), rejected_by = ?";
            if (!empty($reason)) {
                $update_sql .= ", rejection_reason = ?";
            }
        } elseif ($new_status === 'disbursed') {
            $update_sql .= ", disbursed_date = CURDATE(), disbursed_by = ?";
            if (!empty($reason)) {
                $update_sql .= ", disbursement_notes = ?";
            }
        }
        
        $update_sql .= " WHERE id = ?";
        
        $stmt = $conn->prepare($update_sql);
        
        if ($new_status === 'approved') {
            if (!empty($reason)) {
                $stmt->bind_param("siisi", $new_status, $user_id, $user_id, $reason, $loan_id);
            } else {
                $stmt->bind_param("siii", $new_status, $user_id, $user_id, $loan_id);
            }
        } elseif ($new_status === 'rejected') {
            if (!empty($reason)) {
                $stmt->bind_param("siisi", $new_status, $user_id, $user_id, $reason, $loan_id);
            } else {
                $stmt->bind_param("siii", $new_status, $user_id, $user_id, $loan_id);
            }
        } elseif ($new_status === 'disbursed') {
            if (!empty($reason)) {
                $stmt->bind_param("siisi", $new_status, $user_id, $user_id, $reason, $loan_id);
            } else {
                $stmt->bind_param("siii", $new_status, $user_id, $user_id, $loan_id);
            }
        } else {
            $stmt->bind_param("sii", $new_status, $user_id, $loan_id);
        }
        
        if ($stmt->execute()) {
            $conn->commit();
            return true;
        } else {
            throw new Exception("Failed to update loan status: " . $stmt->error);
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error updating loan status: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if database columns exist and create if missing
 */
function ensureDatabaseColumns() {
    global $conn;
    
    // List of required columns and their SQL
    $required_columns = [
        'loans' => [
            'updated_by' => "ALTER TABLE loans ADD COLUMN updated_by INT NULL AFTER updated_at",
            'approved_by' => "ALTER TABLE loans ADD COLUMN approved_by INT NULL AFTER approved_date",
            'approval_notes' => "ALTER TABLE loans ADD COLUMN approval_notes TEXT NULL AFTER approved_by",
            'rejected_date' => "ALTER TABLE loans ADD COLUMN rejected_date DATE NULL AFTER approved_date",
            'rejected_by' => "ALTER TABLE loans ADD COLUMN rejected_by INT NULL AFTER rejected_date",
            'rejection_reason' => "ALTER TABLE loans ADD COLUMN rejection_reason TEXT NULL AFTER rejected_by",
            'disbursed_by' => "ALTER TABLE loans ADD COLUMN disbursed_by INT NULL AFTER disbursed_date",
            'disbursement_notes' => "ALTER TABLE loans ADD COLUMN disbursement_notes TEXT NULL AFTER disbursed_by"
        ],
        'loan_installments' => [
            'due_date' => "ALTER TABLE loan_installments ADD COLUMN due_date DATE NULL AFTER amount"
        ]
    ];
    
    foreach ($required_columns as $table => $columns) {
        foreach ($columns as $column => $sql) {
            $check_sql = "SHOW COLUMNS FROM $table LIKE '$column'";
            $result = $conn->query($check_sql);
            
            if ($result->num_rows == 0) {
                // Column doesn't exist, create it
                if ($conn->query($sql) === TRUE) {
                    error_log("Created column $column in table $table");
                } else {
                    error_log("Error creating column $column in table $table: " . $conn->error);
                }
            }
        }
    }
}

// Ensure required database columns exist when functions.php is loaded
ensureDatabaseColumns();

?>