<?php
require_once '../../config/config.php';
checkAccess();

$page_title = "Approve Insurance Claim";
include '../../includes/header.php';

// Debug: Check what's in GET
echo "<!-- DEBUG: GET id = " . (isset($_GET['id']) ? $_GET['id'] : 'not set') . " -->\n";

// Get claim ID from URL
$claim_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

echo "<!-- DEBUG: claim_id = $claim_id -->\n";

if (!$claim_id) {
    echo '<div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <div class="alert alert-danger">
                    <h4>Invalid Claim ID</h4>
                    <p>No valid claim ID provided. Please go back to the claims list and select a claim to approve.</p>
                    <a href="insurance_claims.php" class="btn btn-primary">Back to Claims List</a>
                </div>
            </div>
        </div>
    </div>';
    include '../../includes/footer.php';
    exit;
}

// Get claim details
$query = "SELECT ic.*, c.full_name, cbo.name as center_name, l.loan_number
          FROM insurance_claims ic
          LEFT JOIN customers c ON ic.customer_id = c.id
          LEFT JOIN cbo ON ic.center_id = cbo.id
          LEFT JOIN loans l ON ic.loan_id = l.id
          WHERE ic.id = ?";
          
$stmt = $conn->prepare($query);
if (!$stmt) {
    echo "<!-- DEBUG: Prepare failed: " . $conn->error . " -->\n";
    die("Database error");
}

$stmt->bind_param("i", $claim_id);
$stmt->execute();
$result = $stmt->get_result();

echo "<!-- DEBUG: Found " . $result->num_rows . " rows -->\n";

if ($result->num_rows === 0) {
    echo '<div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <div class="alert alert-danger">
                    <h4>Claim Not Found</h4>
                    <p>Claim with ID ' . $claim_id . ' was not found in the database.</p>
                    <a href="insurance_claims.php" class="btn btn-primary">Back to Claims List</a>
                </div>
            </div>
        </div>
    </div>';
    include '../../includes/footer.php';
    exit;
}

$claim = $result->fetch_assoc();

echo "<!-- DEBUG: Claim status = " . $claim['status'] . " -->\n";

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_claim'])) {
    $update_query = "UPDATE insurance_claims SET status = 'approved', approved_by = ?, approved_date = NOW() WHERE id = ?";
    $stmt = $conn->prepare($update_query);
    
    if ($stmt) {
        $stmt->bind_param("ii", $_SESSION['user_id'], $claim_id);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Claim #" . $claim['voucher_no'] . " approved successfully!";
            header("Location: insurance_claims.php");
            exit;
        } else {
            $error = "Error approving claim: " . $conn->error;
        }
    } else {
        $error = "Database error: " . $conn->error;
    }
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-10 mx-auto">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h3 class="card-title mb-0"><i class="bi bi-check-circle"></i> Approve Insurance Claim</h3>
                    <a href="insurance_claims.php" class="btn btn-light btn-sm float-right">
                        <i class="bi bi-arrow-left"></i> Back to Claims
                    </a>
                </div>
                <div class="card-body">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>
                    
                    <!-- Claim Summary -->
                    <div class="alert alert-info">
                        <h5><i class="bi bi-info-circle"></i> Claim Summary</h5>
                        <p class="mb-0">You are about to approve insurance claim <strong><?php echo $claim['voucher_no']; ?></strong></p>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-primary text-white">
                                    <h6 class="mb-0">Claim Information</h6>
                                </div>
                                <div class="card-body">
                                    <table class="table table-sm table-bordered">
                                        <tr>
                                            <th width="40%">Voucher No:</th>
                                            <td><?php echo htmlspecialchars($claim['voucher_no']); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Claim Type:</th>
                                            <td>
                                                <span class="badge bg-info">
                                                    <?php echo ucfirst($claim['claim_type']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Claim Amount:</th>
                                            <td class="text-success fw-bold">
                                                Rs. <?php echo number_format($claim['claim_amount'], 2); ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Incident Date:</th>
                                            <td><?php echo $claim['incident_date']; ?></td>
                                        </tr>
                                        <tr>
                                            <th>Current Status:</th>
                                            <td>
                                                <span class="badge bg-warning">Pending</span>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-info text-white">
                                    <h6 class="mb-0">Customer Information</h6>
                                </div>
                                <div class="card-body">
                                    <table class="table table-sm table-bordered">
                                        <tr>
                                            <th width="40%">Customer Name:</th>
                                            <td><?php echo htmlspecialchars($claim['full_name']); ?></td>
                                        </tr>
                                        <tr>
                                            <th>NIC Number:</th>
                                            <td><?php echo htmlspecialchars($claim['nic']); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Mobile:</th>
                                            <td><?php echo htmlspecialchars($claim['mobile']); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Center:</th>
                                            <td><?php echo htmlspecialchars($claim['center_name']); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Loan Number:</th>
                                            <td><?php echo htmlspecialchars($claim['loan_number']); ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Approval Form -->
                    <div class="row mt-4">
                        <div class="col-md-12">
                            <div class="card border-success">
                                <div class="card-header bg-success text-white">
                                    <h6 class="mb-0"><i class="bi bi-check-circle"></i> Approval Confirmation</h6>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <div class="mb-3">
                                            <label for="approval_notes" class="form-label">Approval Notes (Optional)</label>
                                            <textarea class="form-control" id="approval_notes" name="approval_notes" 
                                                      rows="3" placeholder="Add any comments or notes about this approval..."></textarea>
                                        </div>
                                        
                                        <div class="alert alert-warning">
                                            <h6><i class="bi bi-exclamation-triangle"></i> Important</h6>
                                            <p class="mb-0">
                                                You are about to approve insurance claim <strong><?php echo $claim['voucher_no']; ?></strong> 
                                                for <strong>Rs. <?php echo number_format($claim['claim_amount'], 2); ?></strong>. 
                                                This action will change the claim status to "Approved" and cannot be undone.
                                            </p>
                                        </div>
                                        
                                        <div class="text-center">
                                            <button type="submit" name="approve_claim" class="btn btn-success btn-lg">
                                                <i class="bi bi-check-circle"></i> Confirm Approval
                                            </button>
                                            <a href="insurance_claims.php" class="btn btn-secondary">Cancel</a>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('form');
    form.addEventListener('submit', function(e) {
        const confirmApprove = confirm('Are you sure you want to approve this insurance claim? This action cannot be undone.');
        if (!confirmApprove) {
            e.preventDefault();
        }
    });
});
</script>

<?php include '../../includes/footer.php'; ?>