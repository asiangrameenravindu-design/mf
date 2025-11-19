<?php
// Start output buffering to prevent header errors
ob_start();

// modules/customer/view.php

// Include configuration files with correct paths
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

// Check if user has permission (manager or field_officer)
$allowed_roles = ['manager', 'admin','accountant','credit_officer'];
if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], $allowed_roles)) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit();
}

// Initialize variables
$search_results = [];
$customer_details = null;
$customer_loans = [];
$cbo_memberships = [];
$customer_documents = [];
$profile_photos = [];
$nic_documents = [];
$loan_applications = [];
$conn = getDatabaseConnection();

// Debug: Check if database connection is working
if (!$conn) {
    die("Database connection failed");
}

// Document upload functionality
if (isset($_POST['upload_document']) && isset($_GET['customer_id'])) {
    $customer_id = intval($_GET['customer_id']);
    $document_type = $_POST['document_type'];
    $uploaded_by = $_SESSION['user_id'] ?? 1; // Default to admin if not set
    
    // File upload handling
    if (isset($_FILES['document_files']) && is_array($_FILES['document_files']['name'])) {
        $upload_success = 0;
        $upload_errors = [];
        
        // Create uploads directory if not exists
        $upload_dir = __DIR__ . '/../../uploads/customer_documents/' . $customer_id . '/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        // Loop through each file
        foreach ($_FILES['document_files']['name'] as $key => $name) {
            if ($_FILES['document_files']['error'][$key] === UPLOAD_ERR_OK) {
                $file = [
                    'name' => $name,
                    'type' => $_FILES['document_files']['type'][$key],
                    'tmp_name' => $_FILES['document_files']['tmp_name'][$key],
                    'error' => $_FILES['document_files']['error'][$key],
                    'size' => $_FILES['document_files']['size'][$key]
                ];
                
                // Validate file type
                $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'application/pdf'];
                $file_type = mime_content_type($file['tmp_name']);
                
                if (!in_array($file_type, $allowed_types)) {
                    $upload_errors[] = "{$file['name']}: පිළිගත් ගොනු වර්ග: JPG, JPEG, PNG, GIF, PDF";
                    continue;
                }
                
                // Generate unique filename
                $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                $file_name = $document_type . '_' . time() . '_' . uniqid() . '.' . $file_extension;
                $file_path = $upload_dir . $file_name;
                
                // Move uploaded file
                if (move_uploaded_file($file['tmp_name'], $file_path)) {
                    // Save to database
                    $insert_sql = "INSERT INTO customer_documents (customer_id, document_type, file_name, file_path, file_size, uploaded_by) 
                                  VALUES (?, ?, ?, ?, ?, ?)";
                    $insert_stmt = $conn->prepare($insert_sql);
                    $insert_stmt->bind_param("isssii", $customer_id, $document_type, $file_name, $file_path, $file['size'], $uploaded_by);
                    
                    if ($insert_stmt->execute()) {
                        $upload_success++;
                    } else {
                        $upload_errors[] = "{$file['name']}: දත්ත ගබඩාවේ දෝෂයක්";
                    }
                } else {
                    $upload_errors[] = "{$file['name']}: අප්ලෝඩ් කිරීමට අසමත් විය";
                }
            }
        }
        
        if ($upload_success > 0) {
            $_SESSION['success_message'] = "{$upload_success} ලේඛන සාර්ථකව අප්ලෝඩ් කරන ලදී!";
        }
        if (!empty($upload_errors)) {
            $_SESSION['error_message'] = implode("<br>", $upload_errors);
        }
    } else {
        $_SESSION['error_message'] = "ගොනු තෝරා නොමැත හෝ අප්ලෝඩ් දෝෂයක්";
    }
    
    // Redirect to avoid form resubmission
    header("Location: " . $_SERVER['REQUEST_URI']);
    exit();
}

// Document delete functionality
if (isset($_GET['delete_document']) && isset($_GET['customer_id'])) {
    $document_id = intval($_GET['delete_document']);
    $customer_id = intval($_GET['customer_id']);
    
    // Get document details
    $doc_sql = "SELECT * FROM customer_documents WHERE id = ? AND customer_id = ?";
    $doc_stmt = $conn->prepare($doc_sql);
    $doc_stmt->bind_param("ii", $document_id, $customer_id);
    $doc_stmt->execute();
    $document = $doc_stmt->get_result()->fetch_assoc();
    
    if ($document) {
        // Delete file from server
        if (file_exists($document['file_path'])) {
            unlink($document['file_path']);
        }
        
        // Delete from database
        $delete_sql = "DELETE FROM customer_documents WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $document_id);
        
        if ($delete_stmt->execute()) {
            $_SESSION['success_message'] = "ලේඛනය සාර්ථකව මකා දමන ලදී!";
        } else {
            $_SESSION['error_message'] = "ලේඛනය මකා දැමීමට අසමත් විය";
        }
    }
    
    // Redirect to avoid resubmission
    header("Location: " . str_replace("&delete_document=" . $document_id, "", $_SERVER['REQUEST_URI']));
    exit();
}

// Search functionality - Multiple fields
if (isset($_GET['nic']) || isset($_GET['phone']) || isset($_GET['name'])) {
    $nic = $_GET['nic'] ?? '';
    $phone = $_GET['phone'] ?? '';
    $name = $_GET['name'] ?? '';
    
    $search_sql = "SELECT * FROM customers WHERE 1=1";
    $params = [];
    $types = "";
    
    if (!empty($nic)) {
        $search_sql .= " AND national_id LIKE ?";
        $params[] = "%" . $nic . "%";
        $types .= "s";
    }
    
    if (!empty($phone)) {
        $search_sql .= " AND phone LIKE ?";
        $params[] = "%" . $phone . "%";
        $types .= "s";
    }
    
    if (!empty($name)) {
        $search_sql .= " AND (full_name LIKE ? OR short_name LIKE ?)";
        $params[] = "%" . $name . "%";
        $params[] = "%" . $name . "%";
        $types .= "ss";
    }
    
    if (!empty($params)) {
        $search_stmt = $conn->prepare($search_sql);
        $search_stmt->bind_param($types, ...$params);
        $search_stmt->execute();
        $search_results = $search_stmt->get_result();
    }
}

// Get customer details
if (isset($_GET['customer_id']) && !empty($_GET['customer_id'])) {
    $customer_id = intval($_GET['customer_id']);
    
    // Get customer details
    $customer_sql = "SELECT * FROM customers WHERE id = ?";
    $customer_stmt = $conn->prepare($customer_sql);
    $customer_stmt->bind_param("i", $customer_id);
    $customer_stmt->execute();
    $customer_details = $customer_stmt->get_result()->fetch_assoc();
    
    if ($customer_details) {
        // Get customer documents with error handling
        try {
            $doc_sql = "SELECT cd.*, s.full_name as uploaded_by_name 
                       FROM customer_documents cd 
                       LEFT JOIN staff s ON cd.uploaded_by = s.id 
                       WHERE cd.customer_id = ? 
                       ORDER BY cd.uploaded_at DESC";
            $doc_stmt = $conn->prepare($doc_sql);
            $doc_stmt->bind_param("i", $customer_id);
            $doc_stmt->execute();
            $customer_documents_result = $doc_stmt->get_result();
            $customer_documents = [];
            $profile_photos = [];
            $nic_documents = [];
            $loan_applications = [];
            
            while ($row = $customer_documents_result->fetch_assoc()) {
                $customer_documents[] = $row;
                
                // Categorize documents
                if ($row['document_type'] === 'profile_photo') {
                    $profile_photos[] = $row;
                } elseif ($row['document_type'] === 'nic') {
                    $nic_documents[] = $row;
                } elseif ($row['document_type'] === 'loan_application') {
                    $loan_applications[] = $row;
                }
            }
        } catch (Exception $e) {
            // Table doesn't exist, initialize empty arrays
            $customer_documents = [];
            $profile_photos = [];
            $nic_documents = [];
            $loan_applications = [];
        }
        
        // Get customer loans with payment information
        $loan_sql = "SELECT l.*, c.name as cbo_name, c.cbo_number, s.full_name as staff_name,
                            (SELECT COALESCE(SUM(amount), 0) FROM loan_payments WHERE loan_id = l.id) as total_paid
                     FROM loans l 
                     LEFT JOIN cbo c ON l.cbo_id = c.id 
                     LEFT JOIN staff s ON l.staff_id = s.id 
                     WHERE l.customer_id = ? 
                     ORDER BY l.created_at DESC";
        $loan_stmt = $conn->prepare($loan_sql);
        $loan_stmt->bind_param("i", $customer_id);
        $loan_stmt->execute();
        $customer_loans_result = $loan_stmt->get_result();
        $customer_loans = [];
        while ($row = $customer_loans_result->fetch_assoc()) {
            // Calculate repayment percentage and remaining balance
            $total_due = $row['total_loan_amount'] ?? $row['amount'];
            $total_paid = $row['total_paid'] ?? 0;
            $remaining_balance = $total_due - $total_paid;
            $repayment_percentage = $total_due > 0 ? ($total_paid / $total_due) * 100 : 0;
            
            $row['repayment_percentage'] = $repayment_percentage;
            $row['total_paid'] = $total_paid;
            $row['remaining_balance'] = $remaining_balance;
            $row['total_due'] = $total_due;
            
            // Set installment and term information
            $row['installment_amount'] = $row['weekly_installment'] ?? 1000.00;
            $row['term_weeks'] = $row['number_of_weeks'] ?? 18;
            $row['compounding_frequency'] = 'Weekly';
            $row['applied_date'] = $row['applied_date'] ?? $row['created_at'];
            
            $customer_loans[] = $row;
        }
        
        // Get CBO memberships with group information
        $cbo_sql = "SELECT cm.*, c.name as cbo_name, c.cbo_number, g.group_name, g.group_number
                    FROM cbo_members cm 
                    JOIN cbo c ON cm.cbo_id = c.id 
                    LEFT JOIN group_members gm ON cm.customer_id = gm.customer_id 
                    LEFT JOIN groups g ON gm.group_id = g.id 
                    WHERE cm.customer_id = ? AND cm.status = 'active'";
        $cbo_stmt = $conn->prepare($cbo_sql);
        $cbo_stmt->bind_param("i", $customer_id);
        $cbo_stmt->execute();
        $cbo_memberships_result = $cbo_stmt->get_result();
        $cbo_memberships = [];
        while ($row = $cbo_memberships_result->fetch_assoc()) {
            $cbo_memberships[] = $row;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Profile - Micro Finance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3a0ca3;
            --success: #4cc9f0;
            --info: #4895ef;
            --warning: #f72585;
            --light: #f8f9fa;
            --dark: #212529;
            --gradient-primary: linear-gradient(135deg, #4361ee 0%, #3a0ca3 100%);
            --gradient-success: linear-gradient(135deg, #4cc9f0 0%, #4895ef 100%);
            --gradient-warning: linear-gradient(135deg, #f72585 0%, #b5179e 100%);
            --completed: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4edf5 100%);
            min-height: 100vh;
            color: #334155;
        }
        
        .main-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            margin-bottom: 2rem;
        }
        
        .profile-header {
            background: var(--gradient-primary);
            color: white;
            padding: 3rem 0;
            position: relative;
            overflow: hidden;
        }
        
        .profile-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 200%;
            background: rgba(255, 255, 255, 0.1);
            transform: rotate(30deg);
        }
        
        .profile-avatar {
            width: 180px;
            height: 180px;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            font-size: 4rem;
            font-weight: 700;
            border: 6px solid white;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2);
        }
        
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            border: none;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-primary);
        }
        
        .stat-card.success::before {
            background: var(--gradient-success);
        }
        
        .quick-action-row {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
        }
        
        .quick-action-item {
            text-align: center;
            padding: 1rem;
            border-radius: 12px;
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        
        .quick-action-item:hover {
            transform: translateY(-5px);
            background: #f8f9fa;
            text-decoration: none;
            color: inherit;
        }
        
        .quick-action-icon {
            width: 60px;
            height: 60px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.8rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .search-box {
            background: white;
            border-radius: 20px;
            border: none;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            padding: 2rem;
            margin-bottom: 2rem;
        }
        
        .info-item {
            border-bottom: 1px solid #f1f5f9;
            padding: 1.25rem 0;
        }
        
        .info-item:last-child {
            border-bottom: none;
        }
        
        .loan-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            border-left: 4px solid var(--primary);
            position: relative;
            overflow: hidden;
            border: 1px solid #e9ecef;
        }
        
        .loan-card.completed {
            border-left-color: #28a745;
            background: linear-gradient(135deg, #e8f5e8 0%, #d4edda 100%);
            border: 2px solid #28a745;
        }
        
        .repayment-percentage {
            position: absolute;
            top: 1rem;
            right: 1rem;
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: var(--gradient-primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 1.1rem;
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.3);
            border: 4px solid white;
        }
        
        .repayment-percentage.completed {
            background: var(--completed);
        }
        
        .status-badge {
            padding: 0.5rem 1.25rem;
            border-radius: 25px;
            font-weight: 600;
            font-size: 0.875rem;
        }
        
        .section-title {
            color: #1e293b;
            font-weight: 700;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 3px solid #f1f5f9;
            position: relative;
        }
        
        .section-title::after {
            content: '';
            position: absolute;
            bottom: -3px;
            left: 0;
            width: 60px;
            height: 3px;
            background: var(--gradient-primary);
            border-radius: 3px;
        }
        
        .breadcrumb {
            background: transparent;
            padding: 0;
        }
        
        .breadcrumb-item a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }
        
        .main-content {
            margin-left: 250px;
            padding: 20px;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
        
        .floating-action-btn {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--gradient-primary);
            color: white;
            border: none;
            box-shadow: 0 10px 30px rgba(67, 97, 238, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            z-index: 1000;
        }
        
        .cbo-membership-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            border-left: 4px solid var(--success);
        }
        
        .empty-state {
            padding: 3rem 2rem;
            text-align: center;
            background: white;
            border-radius: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            opacity: 0.7;
        }
        
        .loan-detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .loan-detail-item {
            background: #f8f9fa;
            padding: 0.75rem;
            border-radius: 8px;
            text-align: center;
            border: 1px solid #e9ecef;
        }
        
        .loan-detail-label {
            font-size: 0.75rem;
            color: #6c757d;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 0.25rem;
        }
        
        .loan-detail-value {
            font-size: 0.875rem;
            font-weight: 700;
            color: #212529;
        }
        
        .loan-number {
            font-family: 'Courier New', monospace;
            font-weight: 700;
            color: var(--primary);
            font-size: 1.1rem;
        }
        
        .loan-header {
            display: flex;
            justify-content: between;
            align-items: flex-start;
            margin-bottom: 1rem;
            padding-right: 90px;
        }
        
        .loan-title {
            flex: 1;
        }
        
        .loan-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #e9ecef;
        }
        
        .payment-info {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 0.75rem;
            margin-top: 0.5rem;
        }
        
        .completed-loan-glow {
            box-shadow: 0 5px 20px rgba(40, 167, 69, 0.3);
        }

        /* Document Management Styles */
        .document-section {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            border-left: 4px solid #17a2b8;
        }

        .document-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
            border: 1px solid #e9ecef;
        }

        .document-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-right: 1rem;
        }

        .document-icon.profile {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
        }

        .document-icon.nic {
            background: linear-gradient(135deg, #28a745, #1e7e34);
            color: white;
        }

        .document-icon.loan {
            background: linear-gradient(135deg, #ffc107, #e0a800);
            color: white;
        }

        .upload-area {
            border: 2px dashed #dee2e6;
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            background: #f8f9fa;
            cursor: pointer;
        }

        .upload-area:hover {
            border-color: #007bff;
            background: #e9ecef;
        }

        .document-count-badge {
            background: var(--gradient-primary);
            color: white;
            border-radius: 20px;
            padding: 0.25rem 0.75rem;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .profile-photo-large {
            width: 200px;
            height: 200px;
            border-radius: 50%;
            object-fit: cover;
            border: 6px solid white;
            box-shadow: 0 15px 40px rgba(0,0,0,0.15);
        }

        .document-table {
            width: 100%;
        }
        
        .document-table th {
            background: #f8f9fa;
            font-weight: 600;
            border-bottom: 2px solid #dee2e6;
        }
        
        /* Tabs Styling */
        .nav-tabs {
            border-bottom: 2px solid #e9ecef;
            margin-bottom: 1.5rem;
        }
        
        .nav-tabs .nav-link {
            border: none;
            color: #6c757d;
            font-weight: 500;
            padding: 1rem 1.5rem;
            border-radius: 8px 8px 0 0;
        }
        
        .nav-tabs .nav-link:hover {
            color: var(--primary);
            background-color: rgba(67, 97, 238, 0.05);
        }
        
        .nav-tabs .nav-link.active {
            color: var(--primary);
            background-color: white;
            border-bottom: 3px solid var(--primary);
            font-weight: 600;
        }
        
        .tab-content {
            padding: 0;
        }
        
        .tab-pane {
            padding: 0;
        }
        
        /* Profile Photo in Personal Info */
        .profile-photo-personal {
            width: 200px;
            height: 200px;
            border-radius: 50%;
            object-fit: cover;
            border: 6px solid white;
            box-shadow: 0 15px 40px rgba(0,0,0,0.15);
            margin-bottom: 1rem;
        }
        
        .personal-info-with-photo {
            display: flex;
            align-items: flex-start;
            gap: 3rem;
            margin-bottom: 2rem;
        }
        
        @media (max-width: 768px) {
            .personal-info-with-photo {
                flex-direction: column;
            }
        }

        /* Customer Card Styles */
        .customer-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            border-left: 4px solid var(--primary);
            cursor: pointer;
        }
        
        .customer-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            border-left-color: var(--success);
        }
        
        .customer-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1.5rem;
        }
        
        .info-badge {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 20px;
            padding: 0.25rem 0.75rem;
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* Group Badge */
        .group-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 20px;
            padding: 0.4rem 1rem;
            font-size: 0.8rem;
            font-weight: 600;
        }

        /* Button fixes - WORKING BUTTONS */
        .btn-working {
            border: none;
            border-radius: 12px;
            padding: 12px 24px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
        }
        
        .btn-working:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            text-decoration: none;
        }
        
        /* Make sure anchor tags with btn-working class look like buttons */
        a.btn-working {
            color: inherit;
            text-decoration: none;
        }
        
        a.btn-working:hover {
            color: inherit;
            text-decoration: none;
        }
        
        /* Specific button colors */
        .btn-working.btn-light {
            background: #f8f9fa;
            color: #212529;
            border: 1px solid #dee2e6;
        }
        
        .btn-working.btn-light:hover {
            background: #e9ecef;
            border-color: #adb5bd;
        }
        
        .btn-working.btn-info {
            background: linear-gradient(135deg, #17a2b8, #138496);
            color: white;
            border: none;
        }
        
        .btn-working.btn-info:hover {
            background: linear-gradient(135deg, #138496, #117a8b);
            color: white;
        }
        
        .btn-working.btn-primary {
            background: var(--gradient-primary);
            color: white;
            border: none;
        }
        
        .btn-working.btn-primary:hover {
            background: linear-gradient(135deg, #3a0ca3, #4361ee);
            color: white;
        }
    </style>
</head>
<body>

    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="container-fluid">
            <!-- Success/Error Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i>
                    <?php echo $_SESSION['success_message']; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <?php echo $_SESSION['error_message']; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Page Header -->
            <div class="row mb-4">
                <div class="col">
                    <nav aria-label="breadcrumb" class="mb-3">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/dashboard.php" class="text-decoration-none">Dashboard</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Customer View</li>
                        </ol>
                    </nav>
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h1 class="h2 fw-bold text-dark mb-1">Customer Management</h1>
                            <p class="text-muted mb-0">Search and manage customer profiles</p>
                        </div>
                        <a href="<?php echo BASE_URL; ?>/modules/customer/register.php" 
                           class="btn btn-primary btn-lg px-4 btn-working">
                            <i class="bi bi-person-plus me-2"></i>Register Customer
                        </a>
                    </div>
                </div>
            </div>

            <!-- Search Section -->
            <div class="row">
                <div class="col-12">
                    <div class="search-box">
                        <h5 class="fw-semibold mb-3 text-dark">
                            <i class="bi bi-search me-2" style="background: var(--gradient-primary); -webkit-background-clip: text; -webkit-text-fill-color: transparent;"></i>
                            Find Customer
                        </h5>
                        <form method="GET" class="row g-3 align-items-end">
                            <!-- NIC Input -->
                            <div class="col-md-4">
                                <label class="form-label fw-semibold text-dark mb-2">NIC Number</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-light border-end-0 py-2">
                                        <i class="bi bi-credit-card" style="color: var(--primary); font-size: 0.9rem;"></i>
                                    </span>
                                    <input type="text" 
                                           class="form-control border-start-0 py-2" 
                                           name="nic" 
                                           value="<?php echo isset($_GET['nic']) ? htmlspecialchars($_GET['nic']) : ''; ?>" 
                                           placeholder="NIC number...">
                                </div>
                            </div>

                            <!-- Phone Number Input -->
                            <div class="col-md-4">
                                <label class="form-label fw-semibold text-dark mb-2">Phone Number</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-light border-end-0 py-2">
                                        <i class="bi bi-telephone" style="color: var(--success); font-size: 0.9rem;"></i>
                                    </span>
                                    <input type="text" 
                                           class="form-control border-start-0 py-2" 
                                           name="phone" 
                                           value="<?php echo isset($_GET['phone']) ? htmlspecialchars($_GET['phone']) : ''; ?>" 
                                           placeholder="Phone number...">
                                </div>
                            </div>

                            <!-- Name Input -->
                            <div class="col-md-4">
                                <label class="form-label fw-semibold text-dark mb-2">Customer Name</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-light border-end-0 py-2">
                                        <i class="bi bi-person" style="color: var(--warning); font-size: 0.9rem;"></i>
                                    </span>
                                    <input type="text" 
                                           class="form-control border-start-0 py-2" 
                                           name="name" 
                                           value="<?php echo isset($_GET['name']) ? htmlspecialchars($_GET['name']) : ''; ?>" 
                                           placeholder="Customer name...">
                                </div>
                            </div>

                            <!-- Search Button -->
                            <div class="col-12 mt-3">
                                <button type="submit" class="btn btn-primary btn-sm px-4 py-2 btn-working">
                                    <i class="bi bi-search me-2"></i>Search Customer
                                </button>
                                <a href="?" class="btn btn-outline-secondary btn-sm py-2 ms-2">
                                    <i class="bi bi-arrow-clockwise me-2"></i>Clear
                                </a>
                            </div>

                            <!-- Search Hint -->
                            <div class="col-12">
                                <small class="form-text text-muted">
                                    <i class="bi bi-info-circle me-1"></i>
                                    You can search by: NIC, Phone Number, or Name
                                </small>
                            </div>
                        </form>

                        <!-- Search Results -->
                        <?php if (isset($_GET['nic']) || isset($_GET['phone']) || isset($_GET['name'])): ?>
                            <?php if (is_object($search_results) && $search_results->num_rows > 0): ?>
                            <div class="mt-4">
                                <h6 class="fw-semibold mb-3 text-dark">
                                    Search Results:
                                    <span class="badge bg-primary ms-2"><?php echo $search_results->num_rows; ?> found</span>
                                </h6>
                                <div class="row g-3">
                                    <?php while ($customer = $search_results->fetch_assoc()): ?>
                                    <div class="col-md-6 col-lg-4">
                                        <div class="customer-card" onclick="window.location.href='?customer_id=<?php echo $customer['id']; ?><?php 
                                            echo isset($_GET['nic']) ? '&nic=' . urlencode($_GET['nic']) : '';
                                            echo isset($_GET['phone']) ? '&phone=' . urlencode($_GET['phone']) : '';
                                            echo isset($_GET['name']) ? '&name=' . urlencode($_GET['name']) : '';
                                        ?>'">
                                            <div class="d-flex align-items-center">
                                                <div class="customer-avatar me-3">
                                                    <?php echo substr($customer['full_name'], 0, 1); ?>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <h6 class="fw-bold mb-1 text-dark"><?php echo htmlspecialchars($customer['full_name']); ?></h6>
                                                    <p class="text-muted small mb-2"><?php echo htmlspecialchars($customer['short_name']); ?></p>
                                                    <div class="d-flex flex-wrap gap-2">
                                                        <span class="info-badge">
                                                            <i class="bi bi-credit-card me-1"></i><?php echo htmlspecialchars($customer['national_id']); ?>
                                                        </span>
                                                        <?php if ($customer['phone']): ?>
                                                        <span class="info-badge">
                                                            <i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($customer['phone']); ?>
                                                        </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="ms-3">
                                                    <i class="bi bi-chevron-right text-muted"></i>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endwhile; ?>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="alert alert-warning mt-4 border-0" style="background: #fff3cd; border-radius: 12px;">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                No customers found with the provided search criteria.
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Customer Profile Section -->
            <?php if ($customer_details): ?>
            <div class="main-container">
                <!-- Profile Header -->
                <div class="profile-header">
                    <div class="container">
                        <div class="row align-items-center">
                            <div class="col-auto">
                                <div class="profile-avatar rounded-circle d-flex align-items-center justify-content-center text-white mx-auto position-relative">
                                    <?php if (!empty($profile_photos)): ?>
                                        <img src="<?php echo BASE_URL . '/uploads/customer_documents/' . $customer_details['id'] . '/' . $profile_photos[0]['file_name']; ?>" 
                                             alt="Profile Photo" 
                                             class="w-100 h-100 rounded-circle object-fit-cover"
                                             onerror="this.style.display='none'">
                                    <?php endif; ?>
                                    <span class="position-absolute w-100 h-100 d-flex align-items-center justify-content-center" 
                                          style="<?php echo !empty($profile_photos) ? 'display:none' : ''; ?>">
                                        <?php echo substr($customer_details['full_name'], 0, 1); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="col">
                                <h2 class="h2 fw-bold mb-1"><?php echo htmlspecialchars($customer_details['full_name']); ?></h2>
                                <p class="mb-2 opacity-75"><?php echo htmlspecialchars($customer_details['short_name']); ?></p>
                                <div class="d-flex flex-wrap gap-2 align-items-center">
                                    <div class="badge bg-white text-primary px-3 py-2 fs-6" style="border-radius: 20px;">
                                        <i class="bi bi-credit-card me-1"></i>
                                        <?php echo htmlspecialchars($customer_details['national_id']); ?>
                                    </div>
                                    <?php if ($customer_details['phone']): ?>
                                    <div class="badge bg-white text-success px-3 py-2 fs-6" style="border-radius: 20px;">
                                        <i class="bi bi-telephone me-1"></i>
                                        <?php echo htmlspecialchars($customer_details['phone']); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <!-- BUTTONS REMOVED FROM PROFILE HEADER -->
                        </div>
                    </div>
                </div>

                <!-- Quick Actions Row -->
                <div class="container py-3">
                    <div class="quick-action-row">
                        <div class="row g-4">
                            <div class="col-md-3 col-6">
                                <a href="<?php echo BASE_URL; ?>/modules/loans/new.php?customer_id=<?php echo $customer_details['id']; ?>" class="quick-action-item">
                                    <div class="quick-action-icon" style="background: linear-gradient(135deg, #28a745, #20c997);">
                                        <i class="bi bi-plus-lg text-white"></i>
                                    </div>
                                    <h6 class="fw-bold mb-1 text-dark">New Loan</h6>
                                    <small class="text-muted">Create new loan</small>
                                </a>
                            </div>
                            <div class="col-md-3 col-6">
                                <a href="<?php echo BASE_URL; ?>/modules/cbo/add_member.php?customer_id=<?php echo $customer_details['id']; ?>" class="quick-action-item">
                                    <div class="quick-action-icon" style="background: var(--gradient-primary);">
                                        <i class="bi bi-people text-white"></i>
                                    </div>
                                    <h6 class="fw-bold mb-1 text-dark">Add to CBO</h6>
                                    <small class="text-muted">Assign to group</small>
                                </a>
                            </div>
                            <div class="col-md-3 col-6">
                                <!-- Edit Profile in Quick Actions -->
                                <a href="edit.php?customer_id=<?php echo $customer_details['id']; ?>" class="quick-action-item">
                                    <div class="quick-action-icon" style="background: var(--gradient-warning);">
                                        <i class="bi bi-pencil-square text-white"></i>
                                    </div>
                                    <h6 class="fw-bold mb-1 text-dark">Edit Profile</h6>
                                    <small class="text-muted">Update information</small>
                                </a>
                            </div>
                            <div class="col-md-3 col-6">
                                <!-- Upload Documents in Quick Actions -->
                                <div class="quick-action-item" style="cursor: pointer;" onclick="openUploadModal()">
                                    <div class="quick-action-icon" style="background: linear-gradient(135deg, #17a2b8, #138496);">
                                        <i class="bi bi-upload text-white"></i>
                                    </div>
                                    <h6 class="fw-bold mb-1 text-dark">Upload Docs</h6>
                                    <small class="text-muted">Add documents</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- EXTRA BUTTONS ROW REMOVED -->
                    </div>
                </div>

                <div class="container py-4">
                    <!-- Tabs Navigation -->
                    <ul class="nav nav-tabs" id="customerTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="personal-tab" data-bs-toggle="tab" data-bs-target="#personal" type="button" role="tab" aria-controls="personal" aria-selected="true">
                                <i class="bi bi-person me-1"></i>Personal Information
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="loans-tab" data-bs-toggle="tab" data-bs-target="#loans" type="button" role="tab" aria-controls="loans" aria-selected="false">
                                <i class="bi bi-cash-coin me-1"></i>Loan History
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="documents-tab" data-bs-toggle="tab" data-bs-target="#documents" type="button" role="tab" aria-controls="documents" aria-selected="false">
                                <i class="bi bi-folder me-1"></i>Customer Documents
                            </button>
                        </li>
                    </ul>

                    <!-- Tabs Content -->
                    <div class="tab-content" id="customerTabsContent">
                        <!-- Personal Information Tab -->
                        <div class="tab-pane fade show active" id="personal" role="tabpanel" aria-labelledby="personal-tab">
                            <div class="row">
                                <div class="col-lg-8">
                                    <!-- Personal Information with Profile Photo -->
                                    <div class="personal-info-with-photo">
                                        <?php if (!empty($profile_photos)): ?>
                                        <div class="text-center">
                                            <img src="<?php echo BASE_URL . '/uploads/customer_documents/' . $customer_details['id'] . '/' . $profile_photos[0]['file_name']; ?>" 
                                                 alt="Profile Photo" 
                                                 class="profile-photo-personal"
                                                 data-bs-toggle="modal" data-bs-target="#documentPreviewModal"
                                                 onclick="previewDocument('<?php echo BASE_URL . '/uploads/customer_documents/' . $customer_details['id'] . '/' . $profile_photos[0]['file_name']; ?>', '<?php echo $profile_photos[0]['file_name']; ?>')"
                                                 style="cursor: pointer;">
                                            <p class="text-muted small mb-0 mt-2">
                                                Uploaded: <?php echo date('Y-m-d H:i', strtotime($profile_photos[0]['uploaded_at'])); ?>
                                            </p>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <div class="flex-grow-1">
                                            <div class="card border-0">
                                                <div class="card-body">
                                                    <h5 class="section-title">
                                                        <i class="bi bi-person me-2 text-primary"></i>Personal Details
                                                    </h5>
                                                    
                                                    <?php if ($customer_details['birth_date']): ?>
                                                    <div class="info-item">
                                                        <div class="d-flex align-items-center">
                                                            <div class="bg-light rounded p-2 me-3" style="background: var(--gradient-primary); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">
                                                                <i class="bi bi-calendar3"></i>
                                                            </div>
                                                            <div>
                                                                <small class="text-muted">Birth Date</small>
                                                                <div class="fw-semibold"><?php echo date('F j, Y', strtotime($customer_details['birth_date'])); ?></div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($customer_details['phone']): ?>
                                                    <div class="info-item">
                                                        <div class="d-flex align-items-center">
                                                            <div class="bg-light rounded p-2 me-3" style="background: var(--gradient-primary); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">
                                                                <i class="bi bi-telephone"></i>
                                                            </div>
                                                            <div>
                                                                <small class="text-muted">Phone Number</small>
                                                                <div class="fw-semibold"><?php echo htmlspecialchars($customer_details['phone']); ?></div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($customer_details['address']): ?>
                                                    <div class="info-item">
                                                        <div class="d-flex align-items-start">
                                                            <div class="bg-light rounded p-2 me-3" style="background: var(--gradient-primary); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">
                                                                <i class="bi bi-geo-alt"></i>
                                                            </div>
                                                            <div>
                                                                <small class="text-muted">Address</small>
                                                                <div class="fw-semibold"><?php echo htmlspecialchars($customer_details['address']); ?></div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($customer_details['created_at']): ?>
                                                    <div class="info-item">
                                                        <div class="d-flex align-items-center">
                                                            <div class="bg-light rounded p-2 me-3" style="background: var(--gradient-primary); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">
                                                                <i class="bi bi-calendar-check"></i>
                                                            </div>
                                                            <div>
                                                                <small class="text-muted">Member Since</small>
                                                                <div class="fw-semibold"><?php echo date('F j, Y', strtotime($customer_details['created_at'])); ?></div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-lg-4">
                                    <!-- Statistics & Quick Actions -->
                                    <div class="row g-3 mb-4">
                                        <div class="col-md-6 col-lg-12">
                                            <div class="stat-card">
                                                <div class="d-flex align-items-center">
                                                    <div class="flex-shrink-0">
                                                        <i class="bi bi-cash-coin text-primary fs-2"></i>
                                                    </div>
                                                    <div class="flex-grow-1 ms-3">
                                                        <h3 class="fw-bold mb-0"><?php echo count($customer_loans); ?></h3>
                                                        <small class="text-muted">Total Loans</small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6 col-lg-12">
                                            <div class="stat-card success">
                                                <div class="d-flex align-items-center">
                                                    <div class="flex-shrink-0">
                                                        <i class="bi bi-building text-success fs-2"></i>
                                                    </div>
                                                    <div class="flex-grow-1 ms-3">
                                                        <h3 class="fw-bold mb-0"><?php echo count($cbo_memberships); ?></h3>
                                                        <small class="text-muted">CBO Memberships</small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- CBO Memberships -->
                                    <?php if (count($cbo_memberships) > 0): ?>
                                    <div class="card border-0">
                                        <div class="card-body">
                                            <h5 class="section-title">
                                                <i class="bi bi-building me-2 text-success"></i>CBO Memberships
                                            </h5>
                                            <?php foreach ($cbo_memberships as $cbo): ?>
                                            <div class="cbo-membership-card">
                                                <div class="d-flex align-items-center justify-content-between">
                                                    <div class="d-flex align-items-center">
                                                        <div class="bg-success rounded p-2 me-3">
                                                            <i class="bi bi-building text-white"></i>
                                                        </div>
                                                        <div>
                                                            <h6 class="mb-1 fw-semibold"><?php echo htmlspecialchars($cbo['cbo_name']); ?></h6>
                                                            <small class="text-muted">CBO No: <?php echo htmlspecialchars($cbo['cbo_number']); ?></small>
                                                        </div>
                                                    </div>
                                                    <?php if (!empty($cbo['group_name'])): ?>
                                                    <div class="group-badge">
                                                        <i class="bi bi-diagram-3 me-1"></i>
                                                        Group <?php echo $cbo['group_number']; ?>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Loan History Tab -->
                        <div class="tab-pane fade" id="loans" role="tabpanel" aria-labelledby="loans-tab">
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <h5 class="section-title mb-0">
                                    <i class="bi bi-cash-coin me-2 text-warning"></i>Loan History
                                </h5>
                                <a href="<?php echo BASE_URL; ?>/modules/loans/new.php?customer_id=<?php echo $customer_details['id']; ?>" 
                                   class="btn btn-primary btn-working">
                                    <i class="bi bi-plus-circle me-2"></i>New Loan
                                </a>
                            </div>

                            <?php if (count($customer_loans) > 0): ?>
                                <div class="loan-list">
                                    <?php foreach ($customer_loans as $loan): 
                                        $status_class = [
                                            'pending' => ['class' => 'pending', 'badge' => 'warning'],
                                            'approved' => ['class' => 'success', 'badge' => 'success'],
                                            'disbursed' => ['class' => 'success', 'badge' => 'primary'],
                                            'rejected' => ['class' => 'warning', 'badge' => 'danger'],
                                            'active' => ['class' => 'success', 'badge' => 'info'],
                                            'completed' => ['class' => 'completed', 'badge' => 'success']
                                        ];
                                        
                                        $loan_info = $status_class[$loan['status']] ?? $status_class['pending'];
                                        $loan_card_class = $loan_info['class'];
                                        $badge_class = $loan_info['badge'];
                                        $repayment_percentage = $loan['repayment_percentage'] ?? 0;
                                        
                                        // Determine percentage class based on repayment percentage
                                        if ($repayment_percentage >= 100) {
                                            $percentage_class = 'completed';
                                        } elseif ($repayment_percentage >= 70) {
                                            $percentage_class = 'success';
                                        } elseif ($repayment_percentage >= 30) {
                                            $percentage_class = 'warning';
                                        } else {
                                            $percentage_class = '';
                                        }
                                        
                                        // Add glow effect for completed loans
                                        $completed_glow = $loan['status'] === 'completed' ? 'completed-loan-glow' : '';
                                    ?>
                                    <div class="loan-card <?php echo $loan_card_class; ?> <?php echo $completed_glow; ?>">
                                        <div class="repayment-percentage <?php echo $percentage_class; ?>">
                                            <?php echo number_format($repayment_percentage, 0); ?>%
                                        </div>
                                        
                                        <div class="loan-header">
                                            <div class="loan-title">
                                                <h6 class="loan-number mb-1"><?php echo htmlspecialchars($loan['loan_number']); ?></h6>
                                                <p class="text-muted mb-1"><?php echo htmlspecialchars($loan['cbo_name'] ?? 'N/A'); ?></p>
                                            </div>
                                        </div>
                                        
                                        <div class="loan-detail-grid">
                                            <div class="loan-detail-item">
                                                <div class="loan-detail-label">Loan Amount</div>
                                                <div class="loan-detail-value">Rs. <?php echo number_format($loan['amount'], 2); ?></div>
                                            </div>
                                            <div class="loan-detail-item">
                                                <div class="loan-detail-label">Installment</div>
                                                <div class="loan-detail-value">Rs. <?php echo number_format($loan['installment_amount'], 2); ?></div>
                                            </div>
                                            <div class="loan-detail-item">
                                                <div class="loan-detail-label">Term</div>
                                                <div class="loan-detail-value"><?php echo $loan['term_weeks']; ?> Weeks</div>
                                            </div>
                                            <div class="loan-detail-item">
                                                <div class="loan-detail-label">Frequency</div>
                                                <div class="loan-detail-value"><?php echo $loan['compounding_frequency']; ?></div>
                                            </div>
                                        </div>
                                        
                                        <div class="payment-info">
                                            <div class="row text-center">
                                                <div class="col-4">
                                                    <div class="loan-detail-label">Total Paid</div>
                                                    <div class="loan-detail-value text-success">Rs. <?php echo number_format($loan['total_paid'], 2); ?></div>
                                                </div>
                                                <div class="col-4">
                                                    <div class="loan-detail-label">Remaining</div>
                                                    <div class="loan-detail-value text-primary">Rs. <?php echo number_format($loan['remaining_balance'], 2); ?></div>
                                                </div>
                                                <div class="col-4">
                                                    <div class="loan-detail-label">Total Due</div>
                                                    <div class="loan-detail-value text-dark">Rs. <?php echo number_format($loan['total_due'], 2); ?></div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="loan-footer">
                                            <div class="loan-status-info">
                                                <span class="status-badge bg-<?php echo $badge_class; ?> text-white">
                                                    <?php echo ucfirst($loan['status']); ?>
                                                </span>
                                                <small class="text-muted">
                                                    Applied: <?php echo date('Y-m-d', strtotime($loan['applied_date'])); ?>
                                                </small>
                                            </div>
                                            <a href="<?php echo BASE_URL; ?>/modules/loans/view.php?loan_id=<?php echo $loan['id']; ?>" 
                                               class="btn btn-sm btn-outline-primary" style="border-radius: 20px;">
                                                <i class="bi bi-eye me-1"></i>View Details
                                            </a>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="bi bi-cash-coin"></i>
                                    <h5 class="text-muted">No Loan History</h5>
                                    <p class="text-muted mb-4">This customer hasn't applied for any loans yet.</p>
                                    <a href="<?php echo BASE_URL; ?>/modules/loans/new.php?customer_id=<?php echo $customer_details['id']; ?>" 
                                       class="btn btn-primary btn-working">
                                        <i class="bi bi-plus-circle me-2"></i>Create First Loan
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Documents Tab -->
                        <div class="tab-pane fade" id="documents" role="tabpanel" aria-labelledby="documents-tab">
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <h5 class="section-title mb-0">
                                    <i class="bi bi-folder me-2 text-info"></i>Customer Documents
                                </h5>
                                <div class="d-flex gap-2">
                                    <span class="document-count-badge">
                                        <i class="bi bi-files me-1"></i>
                                        <?php echo count($customer_documents); ?> Files
                                    </span>
                                    <!-- Upload Button in Documents Tab -->
                                    <button type="button" class="btn btn-info btn-working" onclick="openUploadModal()">
                                        <i class="bi bi-upload me-2"></i>Upload Documents
                                    </button>
                                </div>
                            </div>

                            <!-- Profile Photos Section -->
                            <div class="document-section">
                                <div class="d-flex align-items-center mb-4">
                                    <div class="document-icon profile">
                                        <i class="bi bi-person-badge"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-1 fw-bold">Profile Photos</h6>
                                        <span class="badge bg-primary"><?php echo count($profile_photos); ?> files</span>
                                    </div>
                                </div>
                                
                                <?php if (!empty($profile_photos)): ?>
                                    <div class="row g-4">
                                        <?php foreach ($profile_photos as $doc): ?>
                                        <div class="col-md-4 col-lg-3">
                                            <div class="document-card text-center">
                                                <img src="<?php echo BASE_URL . '/uploads/customer_documents/' . $customer_details['id'] . '/' . $doc['file_name']; ?>" 
                                                     alt="Profile Photo" 
                                                     class="profile-photo-large mb-3"
                                                     data-bs-toggle="modal" data-bs-target="#documentPreviewModal"
                                                     onclick="previewDocument('<?php echo BASE_URL . '/uploads/customer_documents/' . $customer_details['id'] . '/' . $doc['file_name']; ?>', '<?php echo $doc['file_name']; ?>')">
                                                <div class="mt-2">
                                                    <small class="text-muted d-block">
                                                        <i class="bi bi-calendar me-1"></i>
                                                        <?php echo date('Y-m-d H:i', strtotime($doc['uploaded_at'])); ?>
                                                    </small>
                                                    <?php if ($doc['uploaded_by_name']): ?>
                                                        <small class="text-muted d-block">
                                                            <i class="bi bi-person me-1"></i>
                                                            <?php echo $doc['uploaded_by_name']; ?>
                                                        </small>
                                                    <?php endif; ?>
                                                    <div class="mt-2">
                                                        <a href="?customer_id=<?php echo $customer_details['id']; ?>&delete_document=<?php echo $doc['id']; ?>" 
                                                           class="btn btn-sm btn-outline-danger" 
                                                           onclick="return confirm('මෙම ඡායාරූපය මකා දැමීමට අවශ්‍යද?')">
                                                            <i class="bi bi-trash"></i> Delete
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="bi bi-image text-muted" style="font-size: 3rem;"></i>
                                        <p class="text-muted mb-0 mt-2">No profile photos uploaded</p>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- NIC Documents Section -->
                            <div class="document-section">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="document-icon nic">
                                        <i class="bi bi-credit-card"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-1 fw-bold">NIC Documents</h6>
                                        <span class="badge bg-success"><?php echo count($nic_documents); ?> files</span>
                                    </div>
                                </div>
                                
                                <?php if (!empty($nic_documents)): ?>
                                    <div class="table-responsive">
                                        <table class="table document-table">
                                            <thead>
                                                <tr>
                                                    <th>File Name</th>
                                                    <th>Uploaded Date</th>
                                                    <th>Uploaded By</th>
                                                    <th>File Size</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($nic_documents as $doc): ?>
                                                <tr>
                                                    <td>
                                                        <i class="bi bi-file-earmark me-2"></i>
                                                        <?php echo htmlspecialchars($doc['file_name']); ?>
                                                    </td>
                                                    <td><?php echo date('Y-m-d H:i', strtotime($doc['uploaded_at'])); ?></td>
                                                    <td><?php echo $doc['uploaded_by_name'] ?? 'System'; ?></td>
                                                    <td><?php echo round($doc['file_size'] / 1024, 2); ?> KB</td>
                                                    <td>
                                                        <button class="btn btn-sm btn-outline-primary view-document-btn" 
                                                                data-bs-toggle="modal" data-bs-target="#documentPreviewModal"
                                                                onclick="previewDocument('<?php echo BASE_URL . '/uploads/customer_documents/' . $customer_details['id'] . '/' . $doc['file_name']; ?>', '<?php echo $doc['file_name']; ?>')">
                                                            <i class="bi bi-eye me-1"></i> View
                                                        </button>
                                                        <a href="?customer_id=<?php echo $customer_details['id']; ?>&delete_document=<?php echo $doc['id']; ?>" 
                                                           class="btn btn-sm btn-outline-danger" 
                                                           onclick="return confirm('මෙම ලේඛනය මකා දැමීමට අවශ්‍යද?')">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-3">
                                        <i class="bi bi-credit-card text-muted" style="font-size: 2rem;"></i>
                                        <p class="text-muted mb-0 mt-2">No NIC documents uploaded</p>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Loan Applications Section -->
                            <div class="document-section">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="document-icon loan">
                                        <i class="bi bi-file-earmark-text"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-1 fw-bold">Loan Applications</h6>
                                        <span class="badge bg-warning text-dark"><?php echo count($loan_applications); ?> files</span>
                                    </div>
                                </div>
                                
                                <?php if (!empty($loan_applications)): ?>
                                    <div class="table-responsive">
                                        <table class="table document-table">
                                            <thead>
                                                <tr>
                                                    <th>File Name</th>
                                                    <th>Uploaded Date</th>
                                                    <th>Uploaded By</th>
                                                    <th>File Size</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($loan_applications as $doc): ?>
                                                <tr>
                                                    <td>
                                                        <i class="bi bi-file-earmark me-2"></i>
                                                        <?php echo htmlspecialchars($doc['file_name']); ?>
                                                    </td>
                                                    <td><?php echo date('Y-m-d H:i', strtotime($doc['uploaded_at'])); ?></td>
                                                    <td><?php echo $doc['uploaded_by_name'] ?? 'System'; ?></td>
                                                    <td><?php echo round($doc['file_size'] / 1024, 2); ?> KB</td>
                                                    <td>
                                                        <button class="btn btn-sm btn-outline-primary view-document-btn" 
                                                                data-bs-toggle="modal" data-bs-target="#documentPreviewModal"
                                                                onclick="previewDocument('<?php echo BASE_URL . '/uploads/customer_documents/' . $customer_details['id'] . '/' . $doc['file_name']; ?>', '<?php echo $doc['file_name']; ?>')">
                                                            <i class="bi bi-eye me-1"></i> View
                                                        </button>
                                                        <a href="?customer_id=<?php echo $customer_details['id']; ?>&delete_document=<?php echo $doc['id']; ?>" 
                                                           class="btn btn-sm btn-outline-danger" 
                                                           onclick="return confirm('මෙම ලේඛනය මකා දැමීමට අවශ්‍යද?')">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-3">
                                        <i class="bi bi-file-earmark-text text-muted" style="font-size: 2rem;"></i>
                                        <p class="text-muted mb-0 mt-2">No loan applications uploaded</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Upload Document Modal -->
    <div class="modal fade" id="uploadDocumentModal" tabindex="-1" aria-labelledby="uploadDocumentModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="uploadDocumentModalLabel">
                        <i class="bi bi-upload me-2"></i>Upload Customer Documents
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="documentUploadForm">
                    <div class="modal-body">
                        <input type="hidden" name="upload_document" value="1">
                        
                        <!-- Document Type Selection -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Document Type</label>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <div class="form-check card border-0 shadow-sm">
                                        <input class="form-check-input" type="radio" name="document_type" id="profile_photo" value="profile_photo" checked>
                                        <label class="form-check-label card-body" for="profile_photo">
                                            <div class="text-center">
                                                <i class="bi bi-person-badge fs-1 text-primary mb-2"></i>
                                                <h6 class="mb-1">Profile Photo</h6>
                                                <small class="text-muted">ප්‍රොෆයිල් ඡායාරූප</small>
                                            </div>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-check card border-0 shadow-sm">
                                        <input class="form-check-input" type="radio" name="document_type" id="nic" value="nic">
                                        <label class="form-check-label card-body" for="nic">
                                            <div class="text-center">
                                                <i class="bi bi-credit-card fs-1 text-success mb-2"></i>
                                                <h6 class="mb-1">NIC Document</h6>
                                                <small class="text-muted">ජාතික හැඳුනුම්පත</small>
                                            </div>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-check card border-0 shadow-sm">
                                        <input class="form-check-input" type="radio" name="document_type" id="loan_application" value="loan_application">
                                        <label class="form-check-label card-body" for="loan_application">
                                            <div class="text-center">
                                                <i class="bi bi-file-earmark-text fs-1 text-warning mb-2"></i>
                                                <h6 class="mb-1">Loan Application</h6>
                                                <small class="text-muted">ණය අයදුම්පත</small>
                                            </div>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- File Upload Area -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Select Files</label>
                            <div class="upload-area" id="uploadArea">
                                <input type="file" name="document_files[]" id="documentFiles" multiple 
                                       class="file-input-hidden" accept=".jpg,.jpeg,.png,.gif,.pdf">
                                <div class="py-4">
                                    <i class="bi bi-cloud-upload fs-1 text-muted mb-3"></i>
                                    <h5 class="text-muted">Click to select files or drag and drop</h5>
                                    <p class="text-muted mb-0">Supported formats: JPG, JPEG, PNG, GIF, PDF</p>
                                    <p class="text-muted">Multiple files can be selected</p>
                                </div>
                            </div>
                            <div id="fileList" class="mt-3"></div>
                        </div>

                        <!-- File Requirements -->
                        <div class="alert alert-info">
                            <h6 class="alert-heading mb-2"><i class="bi bi-info-circle me-2"></i>File Requirements</h6>
                            <ul class="mb-0 small">
                                <li>Maximum file size: 10MB per file</li>
                                <li>Supported formats: JPG, JPEG, PNG, GIF, PDF</li>
                                <li>Multiple files can be uploaded at once</li>
                                <li>Profile photos should be clear and recent</li>
                            </ul>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-working">
                            <i class="bi bi-upload me-2"></i>Upload Documents
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Document Preview Modal -->
    <div class="modal fade" id="documentPreviewModal" tabindex="-1" aria-labelledby="documentPreviewModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="documentPreviewModalLabel">Document Preview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <img id="previewImage" src="" alt="Document Preview" class="img-fluid modal-document-preview" style="display: none;">
                    <div id="pdfPreview" class="d-none">
                        <iframe id="pdfFrame" src="" width="100%" height="600px" style="border: none;"></iframe>
                    </div>
                    <div id="unsupportedPreview" class="d-none">
                        <i class="bi bi-file-earmark-text fs-1 text-muted mb-3"></i>
                        <p class="text-muted">This file type cannot be previewed in the browser.</p>
                        <a href="#" id="downloadLink" class="btn btn-primary btn-working">
                            <i class="bi bi-download me-2"></i>Download File
                        </a>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <a href="#" id="downloadBtn" class="btn btn-primary btn-working" download>
                        <i class="bi bi-download me-2"></i>Download
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Floating Action Button -->
    <button class="floating-action-btn" onclick="scrollToTop()">
        <i class="bi bi-arrow-up"></i>
    </button>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function scrollToTop() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        
        // Simple and reliable button functionality
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Page loaded - initializing ALL buttons');
            
            // File upload functionality
            const uploadArea = document.getElementById('uploadArea');
            const fileInput = document.getElementById('documentFiles');
            const fileList = document.getElementById('fileList');

            if (uploadArea && fileInput) {
                uploadArea.addEventListener('click', () => {
                    fileInput.click();
                });
                
                fileInput.addEventListener('change', function() {
                    fileList.innerHTML = '';
                    if (fileInput.files.length > 0) {
                        const fileCount = document.createElement('div');
                        fileCount.className = 'alert alert-info mb-3';
                        fileCount.innerHTML = `<i class="bi bi-info-circle me-2"></i>Selected ${fileInput.files.length} file(s)`;
                        fileList.appendChild(fileCount);
                        
                        Array.from(fileInput.files).forEach((file, index) => {
                            const fileItem = document.createElement('div');
                            fileItem.className = 'd-flex justify-content-between align-items-center p-2 border-bottom';
                            fileItem.innerHTML = `
                                <div>
                                    <i class="bi bi-file-earmark me-2"></i>
                                    <span>${file.name}</span>
                                    <small class="text-muted ms-2">(${(file.size / 1024 / 1024).toFixed(2)} MB)</small>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeFile(${index})">
                                    <i class="bi bi-x"></i>
                                </button>
                            `;
                            fileList.appendChild(fileItem);
                        });
                    }
                });

                window.removeFile = function(index) {
                    const dt = new DataTransfer();
                    const files = Array.from(fileInput.files);
                    files.splice(index, 1);
                    files.forEach(file => dt.items.add(file));
                    fileInput.files = dt.files;
                    fileInput.dispatchEvent(new Event('change'));
                };
            }

            // Form validation
            const uploadForm = document.getElementById('documentUploadForm');
            if (uploadForm) {
                uploadForm.addEventListener('submit', function(e) {
                    const fileInput = document.getElementById('documentFiles');
                    if (!fileInput || fileInput.files.length === 0) {
                        e.preventDefault();
                        alert('කරුණාකර අවම වශයෙන් එක් ගොනුවක් හෝ තෝරන්න');
                        return false;
                    }
                    return true;
                });
            }
        });

        // Simple modal opening function
        function openUploadModal() {
            console.log('Opening upload modal...');
            const modalElement = document.getElementById('uploadDocumentModal');
            if (modalElement) {
                const modal = new bootstrap.Modal(modalElement);
                modal.show();
                console.log('Modal should be open now');
            } else {
                console.error('Upload modal not found!');
                alert('Upload modal not found. Please refresh the page.');
            }
        }

        // Document preview functionality
        function previewDocument(filePath, fileName) {
            const previewImage = document.getElementById('previewImage');
            const pdfPreview = document.getElementById('pdfPreview');
            const pdfFrame = document.getElementById('pdfFrame');
            const unsupportedPreview = document.getElementById('unsupportedPreview');
            const downloadBtn = document.getElementById('downloadBtn');
            const downloadLink = document.getElementById('downloadLink');

            // Reset all displays
            previewImage.style.display = 'none';
            pdfPreview.classList.add('d-none');
            unsupportedPreview.classList.add('d-none');

            // Set download links
            downloadBtn.href = filePath;
            downloadBtn.download = fileName;
            downloadLink.href = filePath;

            // Check file type and show appropriate preview
            if (filePath.toLowerCase().endsWith('.pdf')) {
                pdfFrame.src = filePath;
                pdfPreview.classList.remove('d-none');
            } else if (filePath.toLowerCase().match(/\.(jpg|jpeg|png|gif)$/)) {
                previewImage.src = filePath;
                previewImage.style.display = 'block';
            } else {
                unsupportedPreview.classList.remove('d-none');
            }
        }
    </script>

    <?php include __DIR__ . '/../../includes/footer.php'; ?>
</body>
</html>
<?php
ob_end_flush();
?>