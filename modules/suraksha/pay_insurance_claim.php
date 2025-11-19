<?php
require_once '../../config/config.php';
checkAccess();

if (!isset($_SESSION['user_type']) || ($_SESSION['user_type'] !== 'admin' && $_SESSION['user_type'] !== 'manager')) {
    header("Location: ../unauthorized.php");
    exit;
}

$claim_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get form data
        $paid_date = $conn->real_escape_string($_POST['paid_date']);
        $payment_method = $conn->real_escape_string($_POST['payment_method']);
        $payment_reference = $conn->real_escape_string($_POST['payment_reference']);
        $claim_receiving_person = $conn->real_escape_string($_POST['claim_receiving_person']);
        $claim_receiving_method = $conn->real_escape_string($_POST['claim_receiving_method']);
        
        // Bank details for transfer
        $beneficiary_account_no = $conn->real_escape_string($_POST['beneficiary_account_no'] ?? '');
        $beneficiary_bank_code = $conn->real_escape_string($_POST['beneficiary_bank_code'] ?? '');
        $beneficiary_branch_code = $conn->real_escape_string($_POST['beneficiary_branch_code'] ?? '');
        $beneficiary_bank_name = $conn->real_escape_string($_POST['beneficiary_bank_name'] ?? '');
        $beneficiary_branch_name = $conn->real_escape_string($_POST['beneficiary_branch_name'] ?? '');
        
        // Get claim details
        $stmt = $conn->prepare("
            SELECT ic.*, c.full_name, l.loan_number, cb.name as center_name 
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
            throw new Exception("Claim not found");
        }
        
        if ($claim['status'] != 'approved') {
            throw new Exception("Only approved claims can be paid");
        }
        
        // Update claim status to paid
        $update_stmt = $conn->prepare("
            UPDATE insurance_claims 
            SET status = 'paid',
                paid_date = ?,
                payment_method = ?,
                payment_reference = ?,
                claim_receiving_person = ?,
                claim_receiving_method = ?,
                beneficiary_account_no = ?,
                beneficiary_bank_code = ?,
                beneficiary_branch_code = ?,
                beneficiary_bank_name = ?,
                beneficiary_branch_name = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        
        $update_stmt->bind_param(
            "ssssssssssi",
            $paid_date,
            $payment_method,
            $payment_reference,
            $claim_receiving_person,
            $claim_receiving_method,
            $beneficiary_account_no,
            $beneficiary_bank_code,
            $beneficiary_branch_code,
            $beneficiary_bank_name,
            $beneficiary_branch_name,
            $claim_id
        );
        
        if (!$update_stmt->execute()) {
            throw new Exception("Error updating claim: " . $conn->error);
        }
        
        // Update insurance fund balance
        $fund_stmt = $conn->prepare("
            UPDATE insurance_fund 
            SET fund_balance = fund_balance - ?,
                total_claims_paid = total_claims_paid + ?,
                last_updated = NOW()
        ");
        $fund_stmt->bind_param("dd", $claim['claim_amount'], $claim['claim_amount']);
        
        if (!$fund_stmt->execute()) {
            throw new Exception("Error updating insurance fund: " . $conn->error);
        }
        
        // Log the activity
        $activity_query = "INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent, created_at) 
                          VALUES (?, 'insurance_claim_paid', ?, ?, ?, NOW())";
        $activity_stmt = $conn->prepare($activity_query);
        $description = "Claim ID: {$claim_id}, Amount: {$claim['claim_amount']}, Customer: {$claim['full_name']}";
        $activity_stmt->bind_param("issi", $_SESSION['user_id'], $description, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
        $activity_stmt->execute();
        
        $_SESSION['success_message'] = "Insurance claim paid successfully!";
        header('Location: insurance_claims.php');
        exit();
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error paying claim: " . $e->getMessage();
    }
}

// Get claim details for display
$stmt = $conn->prepare("
    SELECT ic.*, c.full_name, c.national_id, c.phone, 
           l.loan_number, cb.name as center_name,
           u.full_name as approved_by_name
    FROM insurance_claims ic 
    LEFT JOIN customers c ON ic.customer_id = c.id 
    LEFT JOIN loans l ON ic.loan_id = l.id 
    LEFT JOIN cbo cb ON ic.center_id = cb.id 
    LEFT JOIN users u ON ic.approved_by = u.id 
    WHERE ic.id = ?
");

$stmt->bind_param("i", $claim_id);
$stmt->execute();
$result = $stmt->get_result();
$claim = $result->fetch_assoc();

if (!$claim) {
    $_SESSION['error_message'] = "Insurance claim not found";
    header('Location: insurance_claims.php');
    exit();
}

if ($claim['status'] != 'approved') {
    $_SESSION['error_message'] = "Only approved claims can be paid";
    header('Location: insurance_claims.php');
    exit();
}

$page_title = "Pay Insurance Claim - Suraksha Insurance";
include '../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="../dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="insurance_claims.php">Insurance Claims</a></li>
                    <li class="breadcrumb-item active">Pay Claim</li>
                </ol>
            </nav>

            <!-- Display Messages -->
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo $_SESSION['error_message']; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0">
                        <i class="bi bi-cash-coin"></i> Pay Insurance Claim
                    </h4>
                </div>
                <div class="card-body">
                    <!-- Claim Details -->
                    <div class="card claim-details-card mb-4">
                        <div class="card-header bg-light">
                            <h5 class="mb-0">
                                <i class="bi bi-info-circle"></i> Claim Details
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3">
                                    <strong>Voucher No:</strong><br>
                                    <?php echo htmlspecialchars($claim['voucher_no']); ?>
                                </div>
                                <div class="col-md-3">
                                    <strong>Customer Name:</strong><br>
                                    <?php echo htmlspecialchars($claim['full_name']); ?>
                                </div>
                                <div class="col-md-3">
                                    <strong>NIC:</strong><br>
                                    <?php echo htmlspecialchars($claim['national_id']); ?>
                                </div>
                                <div class="col-md-3">
                                    <strong>Loan No:</strong><br>
                                    <?php echo htmlspecialchars($claim['loan_number']); ?>
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-3">
                                    <strong>Center:</strong><br>
                                    <?php echo htmlspecialchars($claim['center_name']); ?>
                                </div>
                                <div class="col-md-3">
                                    <strong>Claim Type:</strong><br>
                                    <span class="badge bg-info"><?php echo ucfirst($claim['claim_type']); ?></span>
                                </div>
                                <div class="col-md-3">
                                    <strong>Incident Date:</strong><br>
                                    <?php echo $claim['incident_date']; ?>
                                </div>
                                <div class="col-md-3">
                                    <strong>Approved By:</strong><br>
                                    <?php echo htmlspecialchars($claim['approved_by_name']); ?>
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-12 text-center">
                                    <div class="amount-highlight">
                                        Claim Amount: Rs. <?php echo number_format($claim['claim_amount'], 2); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Form -->
                    <div class="card payment-card">
                        <div class="card-header bg-light">
                            <h5 class="mb-0">
                                <i class="bi bi-credit-card"></i> Payment Information
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" id="paymentForm">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="paid_date" class="form-label">Payment Date *</label>
                                            <input type="date" class="form-control" id="paid_date" name="paid_date" 
                                                   value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="payment_method" class="form-label">Payment Method *</label>
                                            <select class="form-control" id="payment_method" name="payment_method" required>
                                                <option value="cash">Cash</option>
                                                <option value="cheque">Cheque</option>
                                                <option value="bank_transfer">Bank Transfer</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="payment_reference" class="form-label">Payment Reference *</label>
                                            <input type="text" class="form-control" id="payment_reference" name="payment_reference" 
                                                   placeholder="Cheque No / Transaction ID / Receipt No" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="claim_receiving_person" class="form-label">Received By Person *</label>
                                            <input type="text" class="form-control" id="claim_receiving_person" 
                                                   name="claim_receiving_person" placeholder="Name of person receiving payment" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="claim_receiving_method" class="form-label">Receiving Method *</label>
                                            <select class="form-control" id="claim_receiving_method" name="claim_receiving_method" required>
                                                <option value="cash">Cash</option>
                                                <option value="cheque">Cheque</option>
                                                <option value="bank_transfer">Bank Transfer</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <!-- Bank Details Section (shown only for bank transfer) -->
                                <div id="bankDetails" style="display: none;">
                                    <hr>
                                    <h6 class="text-primary">
                                        <i class="bi bi-bank"></i> Beneficiary Bank Details
                                    </h6>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="beneficiary_account_no" class="form-label">Account Number</label>
                                                <input type="text" class="form-control" id="beneficiary_account_no" 
                                                       name="beneficiary_account_no" placeholder="Beneficiary Account Number">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="beneficiary_bank_name" class="form-label">Bank Name</label>
                                                <input type="text" class="form-control" id="beneficiary_bank_name" 
                                                       name="beneficiary_bank_name" placeholder="Bank Name">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="beneficiary_bank_code" class="form-label">Bank Code</label>
                                                <input type="text" class="form-control" id="beneficiary_bank_code" 
                                                       name="beneficiary_bank_code" placeholder="Bank Code">
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="beneficiary_branch_code" class="form-label">Branch Code</label>
                                                <input type="text" class="form-control" id="beneficiary_branch_code" 
                                                       name="beneficiary_branch_code" placeholder="Branch Code">
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="beneficiary_branch_name" class="form-label">Branch Name</label>
                                                <input type="text" class="form-control" id="beneficiary_branch_name" 
                                                       name="beneficiary_branch_name" placeholder="Branch Name">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="row mt-4">
                                    <div class="col-md-12">
                                        <div class="alert alert-warning">
                                            <i class="bi bi-exclamation-triangle"></i>
                                            <strong>Important:</strong> This action will deduct Rs. <?php echo number_format($claim['claim_amount'], 2); ?> 
                                            from the insurance fund and mark this claim as paid. This action cannot be undone.
                                        </div>
                                    </div>
                                </div>

                                <div class="row mt-3">
                                    <div class="col-md-12 text-end">
                                        <a href="insurance_claims.php" class="btn btn-secondary">
                                            <i class="bi bi-arrow-left"></i> Cancel
                                        </a>
                                        <button type="submit" class="btn btn-success" onclick="return confirmPayment()">
                                            <i class="bi bi-check-circle"></i> Confirm Payment
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Show/hide bank details based on payment method
    document.getElementById('payment_method').addEventListener('change', function() {
        const bankDetails = document.getElementById('bankDetails');
        if (this.value === 'bank_transfer') {
            bankDetails.style.display = 'block';
        } else {
            bankDetails.style.display = 'none';
        }
    });

    // Also trigger for receiving method
    document.getElementById('claim_receiving_method').addEventListener('change', function() {
        const bankDetails = document.getElementById('bankDetails');
        if (this.value === 'bank_transfer') {
            bankDetails.style.display = 'block';
        } else {
            bankDetails.style.display = 'none';
        }
    });

    // Trigger change event on page load
    document.getElementById('payment_method').dispatchEvent(new Event('change'));

    function confirmPayment() {
        const amount = <?php echo $claim['claim_amount']; ?>;
        return confirm(`Are you sure you want to process payment of Rs. ${amount.toLocaleString()} for this claim?`);
    }

    // Set today's date as default for paid_date
    document.getElementById('paid_date').value = new Date().toISOString().split('T')[0];
</script>

<?php include '../includes/footer.php'; ?>