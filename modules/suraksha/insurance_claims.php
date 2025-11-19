<?php
require_once '../../config/config.php';
checkAccess();

$page_title = "Insurance Claims Management";
include '../../includes/header.php';

// Check if insurance_claims table exists
$table_check = $conn->query("SHOW TABLES LIKE 'insurance_claims'");
if ($table_check->num_rows == 0) {
    echo '<div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <div class="alert alert-danger">
                    <h4>Insurance Claims Table Not Found</h4>
                    <p>The insurance_claims table does not exist in the database. Please run the database setup script.</p>
                    <a href="create_insurance_claim.php" class="btn btn-primary">Create First Claim</a>
                </div>
            </div>
        </div>
    </div>';
    include '../../includes/footer.php';
    exit;
}

// Search and filter functionality
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$status = isset($_GET['status']) ? $conn->real_escape_string($_GET['status']) : '';
$claim_type = isset($_GET['claim_type']) ? $conn->real_escape_string($_GET['claim_type']) : '';

// Build WHERE conditions
$where_conditions = [];
if (!empty($search)) {
    $where_conditions[] = "(ic.voucher_no LIKE '%$search%' OR c.full_name LIKE '%$search%' OR c.national_id LIKE '%$search%')";
}

if (!empty($status)) {
    $where_conditions[] = "ic.status = '$status'";
}

if (!empty($claim_type)) {
    $where_conditions[] = "ic.claim_type = '$claim_type'";
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

// Get total count for summary
$count_query = "SELECT COUNT(*) as total FROM insurance_claims ic
                LEFT JOIN customers c ON ic.customer_id = c.id
                $where_clause";
$count_result = $conn->query($count_query);
$total_rows = $count_result ? $count_result->fetch_assoc()['total'] : 0;

// Get status counts for summary cards
$status_counts = [
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'paid' => 0
];

$status_query = "SELECT status, COUNT(*) as count FROM insurance_claims GROUP BY status";
$status_result = $conn->query($status_query);
if ($status_result) {
    while ($row = $status_result->fetch_assoc()) {
        $status_counts[$row['status']] = $row['count'];
    }
}

// Get claims data
$query = "SELECT ic.*, c.full_name, cbo.name as center_name, l.loan_number,
          u1.full_name as approved_by_name, u2.full_name as rejected_by_name
          FROM insurance_claims ic
          LEFT JOIN customers c ON ic.customer_id = c.id
          LEFT JOIN cbo ON ic.center_id = cbo.id
          LEFT JOIN loans l ON ic.loan_id = l.id
          LEFT JOIN users u1 ON ic.approved_by = u1.id
          LEFT JOIN users u2 ON ic.rejected_by = u2.id
          $where_clause
          ORDER BY ic.created_at DESC
          LIMIT 100";

$result = $conn->query($query);
?>

<style>
.actions-column {
    min-width: 200px;
    white-space: nowrap;
}
.action-buttons {
    display: flex;
    gap: 5px;
    justify-content: center;
}
.action-buttons .btn {
    padding: 4px 8px;
    font-size: 12px;
    border-radius: 4px;
}
.action-buttons .btn i {
    margin-right: 3px;
}
</style>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title mb-0">
                        <i class="bi bi-shield-check"></i> Insurance Claims Management
                    </h3>
                    <div>
                        <a href="create_insurance_claim.php" class="btn btn-primary btn-sm">
                            <i class="bi bi-plus-circle"></i> New Claim
                        </a>
                        <a href="insurance_fund.php" class="btn btn-info btn-sm">
                            <i class="bi bi-graph-up"></i> Fund
                        </a>
                        <a href="insurance_reports.php" class="btn btn-success btn-sm">
                            <i class="bi bi-file-text"></i> Reports
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Search and Filter Form -->
                    <div class="card mb-4">
                        <div class="card-header bg-light">
                            <h6 class="card-title mb-0"><i class="bi bi-search"></i> Search & Filter</h6>
                        </div>
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <div class="col-md-3">
                                    <input type="text" name="search" class="form-control" placeholder="Search Voucher, Name, NIC..." 
                                           value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                                <div class="col-md-2">
                                    <select name="status" class="form-control">
                                        <option value="">All Status</option>
                                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                        <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                        <option value="paid" <?php echo $status === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <select name="claim_type" class="form-control">
                                        <option value="">All Types</option>
                                        <option value="hospitalized" <?php echo $claim_type === 'hospitalized' ? 'selected' : ''; ?>>Hospitalized</option>
                                        <option value="death" <?php echo $claim_type === 'death' ? 'selected' : ''; ?>>Death</option>
                                        <option value="accident" <?php echo $claim_type === 'accident' ? 'selected' : ''; ?>>Accident</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-filter"></i> Apply Filters
                                    </button>
                                    <a href="insurance_claims.php" class="btn btn-secondary">
                                        <i class="bi bi-arrow-clockwise"></i> Reset
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Summary Cards -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="card bg-primary text-white">
                                <div class="card-body text-center py-3">
                                    <h5 class="card-title mb-1">Total Claims</h5>
                                    <h2 class="mb-0"><?php echo $total_rows; ?></h2>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card bg-warning text-white">
                                <div class="card-body text-center py-3">
                                    <h5 class="card-title mb-1">Pending</h5>
                                    <h2 class="mb-0"><?php echo $status_counts['pending']; ?></h2>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card bg-success text-white">
                                <div class="card-body text-center py-3">
                                    <h5 class="card-title mb-1">Approved</h5>
                                    <h2 class="mb-0"><?php echo $status_counts['approved']; ?></h2>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card bg-danger text-white">
                                <div class="card-body text-center py-3">
                                    <h5 class="card-title mb-1">Rejected</h5>
                                    <h2 class="mb-0"><?php echo $status_counts['rejected']; ?></h2>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card bg-info text-white">
                                <div class="card-body text-center py-3">
                                    <h5 class="card-title mb-1">Paid</h5>
                                    <h2 class="mb-0"><?php echo $status_counts['paid']; ?></h2>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Claims Table -->
                    <div class="card">
                        <div class="card-header bg-light">
                            <h6 class="card-title mb-0"><i class="bi bi-list-ul"></i> Insurance Claims List</h6>
                        </div>
                        <div class="card-body p-0">
                            <?php if ($result && $result->num_rows > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped table-hover mb-0">
                                        <thead class="table-dark">
                                            <tr>
                                                <th width="120">Voucher No</th>
                                                <th>Client Name</th>
                                                <th width="120">NIC</th>
                                                <th width="120">Loan No</th>
                                                <th width="100">Claim Type</th>
                                                <th width="120">Claim Amount</th>
                                                <th width="100">Status</th>
                                                <th width="100">Incident Date</th>
                                                <th width="100">Created Date</th>
                                                <th width="200" class="text-center actions-column">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while ($row = $result->fetch_assoc()): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($row['voucher_no']); ?></strong>
                                                </td>
                                                <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                                                <td><?php echo htmlspecialchars($row['nic']); ?></td>
                                                <td><?php echo htmlspecialchars($row['loan_number']); ?></td>
                                                <td>
                                                    <span class="badge bg-info text-capitalize">
                                                        <?php echo ucfirst($row['claim_type']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-end fw-bold text-success">
                                                    Rs. <?php echo number_format($row['claim_amount'], 2); ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $status_badge = [
                                                        'pending' => 'bg-warning',
                                                        'approved' => 'bg-success',
                                                        'rejected' => 'bg-danger',
                                                        'paid' => 'bg-primary'
                                                    ];
                                                    $badge_class = $status_badge[$row['status']] ?? 'bg-secondary';
                                                    ?>
                                                    <span class="badge <?php echo $badge_class; ?>">
                                                        <?php echo ucfirst($row['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo $row['incident_date']; ?></td>
                                                <td><?php echo date('Y-m-d', strtotime($row['created_at'])); ?></td>
                                                <td class="text-center actions-column">
                                                    <div class="action-buttons">
                                                        <a href="view_insurance_claim.php?id=<?php echo $row['id']; ?>" 
                                                           class="btn btn-info" title="View Details">
                                                            <i class="bi bi-eye"></i> View
                                                        </a>
                                                        
                                                        <?php if ($row['status'] === 'pending'): ?>
                                                        <a href="approve_insurance_claim.php?id=<?php echo $row['id']; ?>" 
                                                           class="btn btn-success" title="Approve Claim">
                                                            <i class="bi bi-check-circle"></i> Approve
                                                        </a>
                                                        <a href="reject_insurance_claim.php?id=<?php echo $row['id']; ?>" 
                                                           class="btn btn-danger" title="Reject Claim">
                                                            <i class="bi bi-x-circle"></i> Reject
                                                        </a>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($row['status'] === 'approved' && $row['paid_date'] === null): ?>
                                                        <a href="pay_insurance_claim.php?id=<?php echo $row['id']; ?>" 
                                                           class="btn btn-primary" title="Pay Claim">
                                                            <i class="bi bi-cash"></i> Pay
                                                        </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-5">
                                    <div class="mb-3">
                                        <i class="bi bi-inbox display-1 text-muted"></i>
                                    </div>
                                    <h4 class="text-muted">No Insurance Claims Found</h4>
                                    <p class="text-muted mb-4">
                                        <?php if (!empty($search) || !empty($status) || !empty($claim_type)): ?>
                                            No claims match your search criteria. Try adjusting your filters.
                                        <?php else: ?>
                                            There are no insurance claims in the system yet.
                                        <?php endif; ?>
                                    </p>
                                    <a href="create_insurance_claim.php" class="btn btn-primary btn-lg">
                                        <i class="bi bi-plus-circle"></i> Create First Claim
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($result && $result->num_rows > 0): ?>
                    <div class="row mt-3">
                        <div class="col-md-12">
                            <div class="alert alert-info mb-0">
                                <small>
                                    <i class="bi bi-info-circle"></i> 
                                    Showing <?php echo $result->num_rows; ?> claim(s). 
                                    <?php if (!empty($search) || !empty($status) || !empty($claim_type)): ?>
                                        Results filtered by your search criteria.
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>