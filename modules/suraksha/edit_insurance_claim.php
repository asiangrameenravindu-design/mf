<?php
// edit_insurance_claim.php - FIXED VERSION
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once '../../config/config.php';

// Check access - Only admin and manager can edit
if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] !== 'admin' && $_SESSION['user_type'] !== 'manager')) {
    header("Location: /suraksha/unauthorized.php");
    exit();
}

$claim_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($claim_id == 0) {
    die("<div class='alert alert-danger'>Invalid claim ID</div>");
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get form data
        $voucher_no = $_POST['voucher_no'];
        $claim_type = $_POST['claim_type'];
        $incident_date = $_POST['incident_date'];
        $admission_date = $_POST['admission_date'] ?? null;
        $discharge_date = $_POST['discharge_date'] ?? null;
        $number_of_days = $_POST['number_of_days'] ?? 0;
        $claim_person = $_POST['claim_person'] ?? '';
        $claim_amount = $_POST['claim_amount'];
        $total_outstanding = $_POST['total_outstanding'];
        $arrears_amount = $_POST['arrears_amount'];
        $status = $_POST['status'];
        $notes = $_POST['notes'] ?? '';

        // First check if notes column exists
        $check_notes = $conn->query("SHOW COLUMNS FROM insurance_claims LIKE 'notes'");
        $notes_column_exists = $check_notes->num_rows > 0;

        // Build update query dynamically
        $update_fields = [
            "voucher_no = ?",
            "claim_type = ?", 
            "incident_date = ?",
            "admission_date = ?",
            "discharge_date = ?", 
            "number_of_days = ?",
            "claim_person = ?",
            "claim_amount = ?", 
            "total_outstanding = ?",
            "arrears_amount = ?",
            "status = ?",
            "updated_at = NOW()"
        ];
        
        $update_params = [
            $voucher_no, $claim_type, $incident_date,
            $admission_date, $discharge_date, $number_of_days,
            $claim_person, $claim_amount, $total_outstanding,
            $arrears_amount, $status
        ];
        
        // Add notes only if column exists
        if ($notes_column_exists) {
            $update_fields[] = "notes = ?";
            $update_params[] = $notes;
        }
        
        $update_params[] = $claim_id;
        
        $update_query = "UPDATE insurance_claims SET " . implode(', ', $update_fields) . " WHERE id = ?";
        $stmt = $conn->prepare($update_query);
        
        // Bind parameters dynamically
        $types = str_repeat('s', count($update_params) - 1) . 'i'; // All strings except last which is integer ID
        $stmt->bind_param($types, ...$update_params);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Insurance claim updated successfully!";
            
            // Log the activity
            $log_stmt = $conn->prepare("
                INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent, created_at) 
                VALUES (?, 'update_insurance_claim', ?, ?, ?, NOW())
            ");
            $log_description = "Updated insurance claim: {$voucher_no}";
            $log_stmt->bind_param("isss", $_SESSION['user_id'], $log_description, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
            $log_stmt->execute();
            
        } else {
            throw new Exception("Failed to update claim: " . $stmt->error);
        }
        
        header("Location: view_insurance_claim.php?id=$claim_id");
        exit();
        
    } catch (Exception $e) {
        $error = "Error updating claim: " . $e->getMessage();
    }
}

// Get claim details
try {
    $stmt = $conn->prepare("
        SELECT ic.*, 
               c.full_name, c.national_id, c.phone,
               l.loan_number, l.amount as loan_amount,
               cb.name as center_name
        FROM insurance_claims ic 
        LEFT JOIN customers c ON ic.customer_id = c.id 
        LEFT JOIN loans l ON ic.loan_id = l.id 
        LEFT JOIN cbo cb ON ic.center_id = cb.id 
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

// Date formatting function
if (!function_exists('formatClaimDate')) {
    function formatClaimDate($date) {
        if (empty($date) || $date == '0000-00-00') return '';
        return date('Y-m-d', strtotime($date));
    }
}
?>

<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Insurance Claim - Suraksha Insurance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .required::after {
            content: " *";
            color: red;
        }
        .form-section {
            background-color: #f8f9fa;
            border-radius: 5px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .section-title {
            border-bottom: 2px solid #007bff;
            padding-bottom: 0.5rem;
            margin-bottom: 1rem;
            color: #2c3e50;
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
                        <li class="breadcrumb-item"><a href="view_insurance_claim.php?id=<?php echo $claim_id; ?>">View Claim</a></li>
                        <li class="breadcrumb-item active">Edit Claim</li>
                    </ol>
                </nav>

                <!-- Display Messages -->
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle"></i> <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle"></i> <?php echo $_SESSION['success']; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php unset($_SESSION['success']); ?>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header bg-warning text-dark">
                        <h4 class="mb-0">
                            <i class="bi bi-pencil-square"></i> Edit Insurance Claim - <?php echo htmlspecialchars($claim['voucher_no']); ?>
                        </h4>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="editClaimForm">
                            <!-- Basic Information -->
                            <div class="form-section">
                                <h5 class="section-title">Basic Information</h5>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="voucher_no" class="form-label required">Voucher Number</label>
                                            <input type="text" class="form-control" id="voucher_no" name="voucher_no" 
                                                   value="<?php echo htmlspecialchars($claim['voucher_no']); ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="claim_type" class="form-label required">Claim Type</label>
                                            <select class="form-control" id="claim_type" name="claim_type" required>
                                                <option value="hospitalized" <?php echo $claim['claim_type'] == 'hospitalized' ? 'selected' : ''; ?>>Hospitalized</option>
                                                <option value="death" <?php echo $claim['claim_type'] == 'death' ? 'selected' : ''; ?>>Death</option>
                                                <option value="accident" <?php echo $claim['claim_type'] == 'accident' ? 'selected' : ''; ?>>Accident</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="incident_date" class="form-label required">Incident Date</label>
                                            <input type="date" class="form-control" id="incident_date" name="incident_date" 
                                                   value="<?php echo formatClaimDate($claim['incident_date']); ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="status" class="form-label required">Status</label>
                                            <select class="form-control" id="status" name="status" required>
                                                <option value="pending" <?php echo $claim['status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                <option value="approved" <?php echo $claim['status'] == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                                <option value="rejected" <?php echo $claim['status'] == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                                <option value="paid" <?php echo $claim['status'] == 'paid' ? 'selected' : ''; ?>>Paid</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Hospitalization Details (Show only for hospitalized claims) -->
                            <div class="form-section" id="hospitalizationSection" 
                                 style="<?php echo $claim['claim_type'] !== 'hospitalized' ? 'display: none;' : ''; ?>">
                                <h5 class="section-title">Hospitalization Details</h5>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="admission_date" class="form-label">Admission Date</label>
                                            <input type="date" class="form-control" id="admission_date" name="admission_date" 
                                                   value="<?php echo formatClaimDate($claim['admission_date']); ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="discharge_date" class="form-label">Discharge Date</label>
                                            <input type="date" class="form-control" id="discharge_date" name="discharge_date" 
                                                   value="<?php echo formatClaimDate($claim['discharge_date']); ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="number_of_days" class="form-label">Number of Days</label>
                                            <input type="number" class="form-control" id="number_of_days" name="number_of_days" 
                                                   value="<?php echo $claim['number_of_days']; ?>" min="0">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Financial Information -->
                            <div class="form-section">
                                <h5 class="section-title">Financial Information</h5>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="claim_amount" class="form-label required">Claim Amount (Rs.)</label>
                                            <input type="number" class="form-control" id="claim_amount" name="claim_amount" 
                                                   value="<?php echo $claim['claim_amount']; ?>" step="0.01" min="0" required>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="total_outstanding" class="form-label required">Total Outstanding (Rs.)</label>
                                            <input type="number" class="form-control" id="total_outstanding" name="total_outstanding" 
                                                   value="<?php echo $claim['total_outstanding']; ?>" step="0.01" min="0" required>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="arrears_amount" class="form-label">Arrears Amount (Rs.)</label>
                                            <input type="number" class="form-control" id="arrears_amount" name="arrears_amount" 
                                                   value="<?php echo $claim['arrears_amount']; ?>" step="0.01" min="0">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Additional Information -->
                            <div class="form-section">
                                <h5 class="section-title">Additional Information</h5>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="claim_person" class="form-label">Claim Person</label>
                                            <input type="text" class="form-control" id="claim_person" name="claim_person" 
                                                   value="<?php echo htmlspecialchars($claim['claim_person'] ?? ''); ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="notes" class="form-label">Notes</label>
                                            <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo htmlspecialchars($claim['notes'] ?? ''); ?></textarea>
                                            <small class="text-muted">Optional notes about this claim</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Read-only Information -->
                            <div class="form-section">
                                <h5 class="section-title">Reference Information (Read Only)</h5>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label">Customer Name</label>
                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($claim['full_name']); ?>" readonly>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label">NIC Number</label>
                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($claim['national_id']); ?>" readonly>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label">Loan Number</label>
                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($claim['loan_number']); ?>" readonly>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Center</label>
                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($claim['center_name']); ?>" readonly>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Loan Amount</label>
                                            <input type="text" class="form-control" value="Rs. <?php echo number_format($claim['loan_amount'], 2); ?>" readonly>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <div class="row mt-4">
                                <div class="col-md-12">
                                    <div class="d-flex gap-2 justify-content-between">
                                        <div>
                                            <a href="view_insurance_claim.php?id=<?php echo $claim_id; ?>" class="btn btn-secondary">
                                                <i class="bi bi-arrow-left"></i> Cancel
                                            </a>
                                            <button type="button" class="btn btn-info" onclick="resetForm()">
                                                <i class="bi bi-arrow-clockwise"></i> Reset
                                            </button>
                                        </div>
                                        <div>
                                            <button type="submit" class="btn btn-success">
                                                <i class="bi bi-check-circle"></i> Update Claim
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Show/hide hospitalization section based on claim type
        document.getElementById('claim_type').addEventListener('change', function() {
            const hospitalizationSection = document.getElementById('hospitalizationSection');
            if (this.value === 'hospitalized') {
                hospitalizationSection.style.display = 'block';
            } else {
                hospitalizationSection.style.display = 'none';
            }
        });

        // Auto-calculate number of days when admission and discharge dates are filled
        function calculateDays() {
            const admission = document.getElementById('admission_date').value;
            const discharge = document.getElementById('discharge_date').value;
            
            if (admission && discharge) {
                const start = new Date(admission);
                const end = new Date(discharge);
                const diffTime = Math.abs(end - start);
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                document.getElementById('number_of_days').value = diffDays;
            }
        }

        document.getElementById('admission_date').addEventListener('change', calculateDays);
        document.getElementById('discharge_date').addEventListener('change', calculateDays);

        // Form validation
        document.getElementById('editClaimForm').addEventListener('submit', function(e) {
            const claimAmount = document.getElementById('claim_amount').value;
            const totalOutstanding = document.getElementById('total_outstanding').value;
            
            if (parseFloat(claimAmount) <= 0) {
                alert('Claim amount must be greater than 0');
                e.preventDefault();
                return;
            }
            
            if (parseFloat(totalOutstanding) < 0) {
                alert('Total outstanding cannot be negative');
                e.preventDefault();
                return;
            }
        });

        // Reset form to original values
        function resetForm() {
            if (confirm('Are you sure you want to reset all changes?')) {
                document.getElementById('editClaimForm').reset();
                // Trigger change event to update hospitalization section
                document.getElementById('claim_type').dispatchEvent(new Event('change'));
            }
        }

        // Confirm before leaving page if form has changes
        let formChanged = false;
        const form = document.getElementById('editClaimForm');
        const initialFormData = new FormData(form);

        form.addEventListener('change', function() {
            formChanged = true;
        });

        window.addEventListener('beforeunload', function(e) {
            if (formChanged) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    </script>
</body>
</html>