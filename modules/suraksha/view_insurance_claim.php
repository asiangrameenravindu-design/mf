<?php
// view_insurance_claim.php - COMPLETE VERSION
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once '../../config/config.php';

// Check access
if (!isset($_SESSION['user_id'])) {
    header("Location: /suraksha/login.php");
    exit();
}

$claim_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($claim_id == 0) {
    die("<div class='alert alert-danger'>Invalid claim ID</div>");
}

try {
    // Get complete claim details using MySQLi
    $stmt = $conn->prepare("
        SELECT ic.*, 
               c.full_name, c.national_id, c.phone, c.address,
               l.loan_number, l.amount as loan_amount, l.disbursed_date,
               l.total_loan_amount, l.balance as loan_balance,
               l.weekly_installment, l.interest_rate, l.duration_months,
               cb.name as center_name,
               u1.full_name as approved_by_name,
               u2.full_name as rejected_by_name,
               u3.full_name as created_by_name,
               s.full_name as staff_name
        FROM insurance_claims ic 
        LEFT JOIN customers c ON ic.customer_id = c.id 
        LEFT JOIN loans l ON ic.loan_id = l.id 
        LEFT JOIN cbo cb ON ic.center_id = cb.id 
        LEFT JOIN users u1 ON ic.approved_by = u1.id 
        LEFT JOIN users u2 ON ic.rejected_by = u2.id 
        LEFT JOIN users u3 ON ic.created_by = u3.id 
        LEFT JOIN staff s ON l.staff_id = s.id 
        WHERE ic.id = ?
    ");
    
    $stmt->bind_param("i", $claim_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $claim = $result->fetch_assoc();

    if (!$claim) {
        die("<div class='alert alert-danger'>Insurance claim not found</div>");
    }
    
} catch (Exception $e) {
    die("<div class='alert alert-danger'>Database error: " . $e->getMessage() . "</div>");
}

// Helper functions for badges
function getStatusBadge($status) {
    $badges = [
        'pending' => 'bg-warning',
        'approved' => 'bg-success', 
        'rejected' => 'bg-danger',
        'paid' => 'bg-primary'
    ];
    return $badges[$status] ?? 'bg-secondary';
}

function getClaimTypeBadge($type) {
    $badges = [
        'hospitalized' => 'bg-info',
        'death' => 'bg-dark',
        'accident' => 'bg-warning'
    ];
    return $badges[$type] ?? 'bg-secondary';
}

// Check if formatDate function already exists before declaring
if (!function_exists('formatClaimDate')) {
    function formatClaimDate($date) {
        if (empty($date) || $date == '0000-00-00') return 'N/A';
        return date('M j, Y', strtotime($date));
    }
}

if (!function_exists('formatClaimDateTime')) {
    function formatClaimDateTime($datetime) {
        if (empty($datetime) || $datetime == '0000-00-00 00:00:00') return 'N/A';
        return date('M j, Y g:i A', strtotime($datetime));
    }
}
?>

<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Insurance Claim - Suraksha Insurance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .detail-card {
            border-left: 4px solid #007bff;
            margin-bottom: 1rem;
        }
        .status-badge {
            font-size: 0.9rem;
            padding: 0.5rem 1rem;
        }
        .detail-row {
            border-bottom: 1px solid #eee;
            padding: 0.75rem 0;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .value-highlight {
            font-weight: 600;
            color: #2c3e50;
        }
        .amount-display {
            font-size: 1.5rem;
            font-weight: bold;
            color: #28a745;
        }
        .section-title {
            border-bottom: 2px solid #007bff;
            padding-bottom: 0.5rem;
            margin-bottom: 1rem;
            color: #2c3e50;
        }
        .info-label {
            font-weight: 600;
            color: #495057;
        }
        .info-value {
            color: #212529;
        }
        .bank-details {
            background-color: #f8f9fa;
            border-radius: 5px;
            padding: 1rem;
            margin-top: 1rem;
        }
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .action-buttons .btn {
            margin-bottom: 5px;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-md-12">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="/suraksha/dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="insurance_claims.php">Insurance Claims</a></li>
                        <li class="breadcrumb-item active">View Claim - <?php echo htmlspecialchars($claim['voucher_no']); ?></li>
                    </ol>
                </nav>

                <!-- Display Messages -->
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle"></i> <?php echo $_SESSION['error']; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php unset($_SESSION['error']); ?>
                <?php endif; ?>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle"></i> <?php echo $_SESSION['success']; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php unset($_SESSION['success']); ?>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h4 class="mb-0">
                            <i class="bi bi-eye"></i> Insurance Claim Details - <?php echo htmlspecialchars($claim['voucher_no']); ?>
                        </h4>
                        <div>
                            <span class="badge status-badge <?php echo getStatusBadge($claim['status']); ?>">
                                <?php echo strtoupper($claim['status']); ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Header Section with Amount -->
                        <div class="row mb-4">
                            <div class="col-md-8">
                                <h3 class="text-primary"><?php echo htmlspecialchars($claim['full_name']); ?></h3>
                                <p class="text-muted mb-0">
                                    <i class="bi bi-calendar"></i> Created: <?php echo formatClaimDateTime($claim['created_at']); ?> 
                                    by <?php echo htmlspecialchars($claim['created_by_name']); ?>
                                </p>
                                <p class="text-muted mb-0">
                                    <i class="bi bi-arrow-clockwise"></i> Last Updated: <?php echo formatClaimDateTime($claim['updated_at']); ?>
                                </p>
                            </div>
                            <div class="col-md-4 text-end">
                                <div class="amount-display">
                                    Rs. <?php echo number_format($claim['claim_amount'], 2); ?>
                                </div>
                                <span class="badge <?php echo getClaimTypeBadge($claim['claim_type']); ?>">
                                    <?php echo ucfirst($claim['claim_type']); ?> Claim
                                </span>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="row mb-4">
                            <div class="col-md-12">
                                <div class="action-buttons">
                                    <a href="insurance_claims.php" class="btn btn-secondary">
                                        <i class="bi bi-arrow-left"></i> Back to List
                                    </a>
                                    
                                    <!-- Edit button for admin/manager -->
                                    <?php if ($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'manager'): ?>
                                    <a href="edit_insurance_claim.php?id=<?php echo $claim['id']; ?>" 
                                       class="btn btn-warning">
                                        <i class="bi bi-pencil-square"></i> Edit Claim
                                    </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($claim['status'] === 'pending'): ?>
                                        <a href="approve_insurance_claim.php?id=<?php echo $claim['id']; ?>" 
                                           class="btn btn-success" 
                                           onclick="return confirm('Are you sure you want to approve this claim?')">
                                            <i class="bi bi-check-circle"></i> Approve Claim
                                        </a>
                                        <a href="reject_insurance_claim.php?id=<?php echo $claim['id']; ?>" 
                                           class="btn btn-danger"
                                           onclick="return confirm('Are you sure you want to reject this claim?')">
                                            <i class="bi bi-x-circle"></i> Reject Claim
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($claim['status'] === 'approved' && empty($claim['paid_date'])): ?>
                                        <a href="pay_insurance_claim.php?id=<?php echo $claim['id']; ?>" 
                                           class="btn btn-primary">
                                            <i class="bi bi-cash-coin"></i> Pay Claim
                                        </a>
                                    <?php endif; ?>
                                    
                                    <a href="print_insurance_claim.php?id=<?php echo $claim['id']; ?>" 
                                       class="btn btn-outline-dark" target="_blank">
                                        <i class="bi bi-printer"></i> Print
                                    </a>
                                    
                                    <button class="btn btn-outline-info" onclick="window.location.reload()">
                                        <i class="bi bi-arrow-clockwise"></i> Refresh
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <!-- Customer Information -->
                            <div class="col-md-6">
                                <div class="card detail-card h-100">
                                    <div class="card-header bg-light">
                                        <h5 class="mb-0">
                                            <i class="bi bi-person-badge"></i> Customer Information
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="detail-row">
                                            <div class="row">
                                                <div class="col-sm-4 info-label">Full Name:</div>
                                                <div class="col-sm-8 info-value value-highlight"><?php echo htmlspecialchars($claim['full_name']); ?></div>
                                            </div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="row">
                                                <div class="col-sm-4 info-label">NIC Number:</div>
                                                <div class="col-sm-8 info-value"><?php echo htmlspecialchars($claim['national_id']); ?></div>
                                            </div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="row">
                                                <div class="col-sm-4 info-label">Phone:</div>
                                                <div class="col-sm-8 info-value"><?php echo htmlspecialchars($claim['phone']); ?></div>
                                            </div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="row">
                                                <div class="col-sm-4 info-label">Address:</div>
                                                <div class="col-sm-8 info-value"><?php echo htmlspecialchars($claim['address']); ?></div>
                                            </div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="row">
                                                <div class="col-sm-4 info-label">Center:</div>
                                                <div class="col-sm-8 info-value value-highlight"><?php echo htmlspecialchars($claim['center_name']); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Loan Information -->
                            <div class="col-md-6">
                                <div class="card detail-card h-100">
                                    <div class="card-header bg-light">
                                        <h5 class="mb-0">
                                            <i class="bi bi-file-earmark-text"></i> Loan Information
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="detail-row">
                                            <div class="row">
                                                <div class="col-sm-4 info-label">Loan Number:</div>
                                                <div class="col-sm-8 info-value value-highlight"><?php echo htmlspecialchars($claim['loan_number']); ?></div>
                                            </div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="row">
                                                <div class="col-sm-4 info-label">Loan Amount:</div>
                                                <div class="col-sm-8 info-value">Rs. <?php echo number_format($claim['loan_amount'], 2); ?></div>
                                            </div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="row">
                                                <div class="col-sm-4 info-label">Total Loan Amount:</div>
                                                <div class="col-sm-8 info-value">Rs. <?php echo number_format($claim['total_loan_amount'], 2); ?></div>
                                            </div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="row">
                                                <div class="col-sm-4 info-label">Current Balance:</div>
                                                <div class="col-sm-8 info-value">Rs. <?php echo number_format($claim['loan_balance'], 2); ?></div>
                                            </div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="row">
                                                <div class="col-sm-4 info-label">Weekly Installment:</div>
                                                <div class="col-sm-8 info-value">Rs. <?php echo number_format($claim['weekly_installment'], 2); ?></div>
                                            </div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="row">
                                                <div class="col-sm-4 info-label">Interest Rate:</div>
                                                <div class="col-sm-8 info-value"><?php echo $claim['interest_rate']; ?>%</div>
                                            </div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="row">
                                                <div class="col-sm-4 info-label">Duration:</div>
                                                <div class="col-sm-8 info-value"><?php echo $claim['duration_months']; ?> months</div>
                                            </div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="row">
                                                <div class="col-sm-4 info-label">Disbursement Date:</div>
                                                <div class="col-sm-8 info-value"><?php echo formatClaimDate($claim['disbursed_date']); ?></div>
                                            </div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="row">
                                                <div class="col-sm-4 info-label">Loan Disbursement Date:</div>
                                                <div class="col-sm-8 info-value"><?php echo formatClaimDate($claim['loan_disbursement_date']); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row mt-3">
                            <!-- Claim Details -->
                            <div class="col-md-6">
                                <div class="card detail-card h-100">
                                    <div class="card-header bg-light">
                                        <h5 class="mb-0">
                                            <i class="bi bi-clipboard-check"></i> Claim Details
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="detail-row">
                                            <div class="row">
                                                <div class="col-sm-4 info-label">Claim Type:</div>
                                                <div class="col-sm-8">
                                                    <span class="badge <?php echo getClaimTypeBadge($claim['claim_type']); ?>">
                                                        <?php echo ucfirst($claim['claim_type']); ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="row">
                                                <div class="col-sm-4 info-label">Incident Date:</div>
                                                <div class="col-sm-8 info-value"><?php echo formatClaimDate($claim['incident_date']); ?></div>
                                            </div>
                                        </div>
                                        
                                        <?php if ($claim['claim_type'] === 'hospitalized'): ?>
                                            <div class="detail-row">
                                                <div class="row">
                                                    <div class="col-sm-4 info-label">Admission Date:</div>
                                                    <div class="col-sm-8 info-value"><?php echo formatClaimDate($claim['admission_date']); ?></div>
                                                </div>
                                            </div>
                                            <div class="detail-row">
                                                <div class="row">
                                                    <div class="col-sm-4 info-label">Discharge Date:</div>
                                                    <div class="col-sm-8 info-value"><?php echo formatClaimDate($claim['discharge_date']); ?></div>
                                                </div>
                                            </div>
                                            <div class="detail-row">
                                                <div class="row">
                                                    <div class="col-sm-4 info-label">Hospitalized Days:</div>
                                                    <div class="col-sm-8 info-value"><?php echo $claim['number_of_days']; ?> days</div>
                                                </div>
                                            </div>
                                            <div class="detail-row">
                                                <div class="row">
                                                    <div class="col-sm-4 info-label">Claim Approval Days:</div>
                                                    <div class="col-sm-8 info-value"><?php echo $claim['claim_approval_days']; ?> days</div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="detail-row">
                                            <div class="row">
                                                <div class="col-sm-4 info-label">Claim Person:</div>
                                                <div class="col-sm-8 info-value"><?php echo htmlspecialchars($claim['claim_person'] ?? 'N/A'); ?></div>
                                            </div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="row">
                                                <div class="col-sm-4 info-label">Claim Amount:</div>
                                                <div class="col-sm-8 info-value value-highlight text-success">
                                                    Rs. <?php echo number_format($claim['claim_amount'], 2); ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="row">
                                                <div class="col-sm-4 info-label">Total Outstanding:</div>
                                                <div class="col-sm-8 info-value">Rs. <?php echo number_format($claim['total_outstanding'], 2); ?></div>
                                            </div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="row">
                                                <div class="col-sm-4 info-label">Arrears Amount:</div>
                                                <div class="col-sm-8 info-value">Rs. <?php echo number_format($claim['arrears_amount'], 2); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Status & Approval Information -->
                            <div class="col-md-6">
                                <div class="card detail-card h-100">
                                    <div class="card-header bg-light">
                                        <h5 class="mb-0">
                                            <i class="bi bi-clock-history"></i> Status & Approval
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="detail-row">
                                            <div class="row">
                                                <div class="col-sm-4 info-label">Current Status:</div>
                                                <div class="col-sm-8">
                                                    <span class="badge <?php echo getStatusBadge($claim['status']); ?>">
                                                        <?php echo ucfirst($claim['status']); ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <?php if ($claim['status'] === 'approved' || $claim['status'] === 'paid'): ?>
                                            <div class="detail-row">
                                                <div class="row">
                                                    <div class="col-sm-4 info-label">Approved By:</div>
                                                    <div class="col-sm-8 info-value value-highlight"><?php echo htmlspecialchars($claim['approved_by_name']); ?></div>
                                                </div>
                                            </div>
                                            <div class="detail-row">
                                                <div class="row">
                                                    <div class="col-sm-4 info-label">Approved Date:</div>
                                                    <div class="col-sm-8 info-value"><?php echo formatClaimDate($claim['approved_date']); ?></div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($claim['status'] === 'rejected'): ?>
                                            <div class="detail-row">
                                                <div class="row">
                                                    <div class="col-sm-4 info-label">Rejected By:</div>
                                                    <div class="col-sm-8 info-value value-highlight"><?php echo htmlspecialchars($claim['rejected_by_name']); ?></div>
                                                </div>
                                            </div>
                                            <div class="detail-row">
                                                <div class="row">
                                                    <div class="col-sm-4 info-label">Rejected Date:</div>
                                                    <div class="col-sm-8 info-value"><?php echo formatClaimDate($claim['rejected_date']); ?></div>
                                                </div>
                                            </div>
                                            <div class="detail-row">
                                                <div class="row">
                                                    <div class="col-sm-4 info-label">Rejection Reason:</div>
                                                    <div class="col-sm-8 info-value"><?php echo htmlspecialchars($claim['rejection_reason'] ?? 'N/A'); ?></div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="detail-row">
                                            <div class="row">
                                                <div class="col-sm-4 info-label">Created By:</div>
                                                <div class="col-sm-8 info-value"><?php echo htmlspecialchars($claim['created_by_name']); ?></div>
                                            </div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="row">
                                                <div class="col-sm-4 info-label">Created Date:</div>
                                                <div class="col-sm-8 info-value"><?php echo formatClaimDateTime($claim['created_at']); ?></div>
                                            </div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="row">
                                                <div class="col-sm-4 info-label">Last Updated:</div>
                                                <div class="col-sm-8 info-value"><?php echo formatClaimDateTime($claim['updated_at']); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Payment Information (Only for paid claims) -->
                        <?php if ($claim['status'] === 'paid'): ?>
                        <div class="row mt-3">
                            <div class="col-md-12">
                                <div class="card detail-card">
                                    <div class="card-header bg-light">
                                        <h5 class="mb-0">
                                            <i class="bi bi-cash-coin"></i> Payment Information
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="detail-row">
                                                    <div class="row">
                                                        <div class="col-sm-4 info-label">Paid Date:</div>
                                                        <div class="col-sm-8 info-value value-highlight"><?php echo formatClaimDate($claim['paid_date']); ?></div>
                                                    </div>
                                                </div>
                                                <div class="detail-row">
                                                    <div class="row">
                                                        <div class="col-sm-4 info-label">Payment Method:</div>
                                                        <div class="col-sm-8 info-value"><?php echo ucfirst($claim['payment_method'] ?? 'N/A'); ?></div>
                                                    </div>
                                                </div>
                                                <div class="detail-row">
                                                    <div class="row">
                                                        <div class="col-sm-4 info-label">Payment Reference:</div>
                                                        <div class="col-sm-8 info-value"><?php echo htmlspecialchars($claim['payment_reference'] ?? 'N/A'); ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="detail-row">
                                                    <div class="row">
                                                        <div class="col-sm-4 info-label">Received By:</div>
                                                        <div class="col-sm-8 info-value"><?php echo htmlspecialchars($claim['claim_receiving_person'] ?? 'N/A'); ?></div>
                                                    </div>
                                                </div>
                                                <div class="detail-row">
                                                    <div class="row">
                                                        <div class="col-sm-4 info-label">Receiving Method:</div>
                                                        <div class="col-sm-8 info-value"><?php echo ucfirst($claim['claim_receiving_method'] ?? 'N/A'); ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Bank Details -->
                                        <?php if (!empty($claim['beneficiary_account_no'])): ?>
                                        <div class="bank-details mt-3">
                                            <h6 class="section-title">
                                                <i class="bi bi-bank"></i> Beneficiary Bank Details
                                            </h6>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="detail-row">
                                                        <div class="row">
                                                            <div class="col-sm-4 info-label">Account No:</div>
                                                            <div class="col-sm-8 info-value"><?php echo htmlspecialchars($claim['beneficiary_account_no']); ?></div>
                                                        </div>
                                                    </div>
                                                    <div class="detail-row">
                                                        <div class="row">
                                                            <div class="col-sm-4 info-label">Bank Name:</div>
                                                            <div class="col-sm-8 info-value"><?php echo htmlspecialchars($claim['beneficiary_bank_name'] ?? 'N/A'); ?></div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="detail-row">
                                                        <div class="row">
                                                            <div class="col-sm-4 info-label">Bank Code:</div>
                                                            <div class="col-sm-8 info-value"><?php echo htmlspecialchars($claim['beneficiary_bank_code'] ?? 'N/A'); ?></div>
                                                        </div>
                                                    </div>
                                                    <div class="detail-row">
                                                        <div class="row">
                                                            <div class="col-sm-4 info-label">Branch Name:</div>
                                                            <div class="col-sm-8 info-value"><?php echo htmlspecialchars($claim['beneficiary_branch_name'] ?? 'N/A'); ?></div>
                                                        </div>
                                                    </div>
                                                    <div class="detail-row">
                                                        <div class="row">
                                                            <div class="col-sm-4 info-label">Branch Code:</div>
                                                            <div class="col-sm-8 info-value"><?php echo htmlspecialchars($claim['beneficiary_branch_code'] ?? 'N/A'); ?></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Contact Information -->
                        <div class="row mt-3">
                            <div class="col-md-12">
                                <div class="card">
                                    <div class="card-header bg-light">
                                        <h6 class="mb-0">
                                            <i class="bi bi-telephone"></i> Contact Information
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="detail-row">
                                                    <div class="row">
                                                        <div class="col-sm-4 info-label">Mobile:</div>
                                                        <div class="col-sm-8 info-value"><?php echo htmlspecialchars($claim['mobile'] ?? 'N/A'); ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="detail-row">
                                                    <div class="row">
                                                        <div class="col-sm-4 info-label">Phone:</div>
                                                        <div class="col-sm-8 info-value"><?php echo htmlspecialchars($claim['phone'] ?? 'N/A'); ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="detail-row">
                                                    <div class="row">
                                                        <div class="col-sm-4 info-label">Field Officer:</div>
                                                        <div class="col-sm-8 info-value"><?php echo htmlspecialchars($claim['staff_name'] ?? 'N/A'); ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- System Information -->
                        <div class="row mt-3">
                            <div class="col-md-12">
                                <div class="card">
                                    <div class="card-header bg-light">
                                        <h6 class="mb-0">
                                            <i class="bi bi-info-circle"></i> System Information
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-3">
                                                <small class="text-muted">Claim ID: <?php echo $claim['id']; ?></small>
                                            </div>
                                            <div class="col-md-3">
                                                <small class="text-muted">Customer ID: <?php echo $claim['customer_id']; ?></small>
                                            </div>
                                            <div class="col-md-3">
                                                <small class="text-muted">Loan ID: <?php echo $claim['loan_id']; ?></small>
                                            </div>
                                            <div class="col-md-3">
                                                <small class="text-muted">Center ID: <?php echo $claim['center_id']; ?></small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Confirm actions
        function confirmAction(action, claimId) {
            if (confirm(`Are you sure you want to ${action} this claim?`)) {
                window.location.href = `${action}_insurance_claim.php?id=${claimId}`;
            }
        }

        // Print functionality
        function printClaim() {
            window.open(`print_insurance_claim.php?id=<?php echo $claim_id; ?>`, '_blank');
        }
    </script>
</body>
</html>