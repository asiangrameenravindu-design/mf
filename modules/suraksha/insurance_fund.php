<?php
require_once '../../config/config.php';
checkAccess();

if (!isset($_SESSION['user_type']) || ($_SESSION['user_type'] !== 'admin' && $_SESSION['user_type'] !== 'manager')) {
    header("Location: ../unauthorized.php");
    exit;
}

$page_title = "Insurance Fund Management";
include '../includes/header.php';

// Get fund balance
$fund_query = "SELECT * FROM insurance_fund ORDER BY id DESC LIMIT 1";
$fund_result = $conn->query($fund_query);
$fund = $fund_result->fetch_assoc();

if (!$fund) {
    // Initialize fund if not exists
    $conn->query("INSERT INTO insurance_fund (fund_balance, total_premium_collected, total_claims_paid) VALUES (0, 0, 0)");
    $fund = ['fund_balance' => 0, 'total_premium_collected' => 0, 'total_claims_paid' => 0];
}

// Get recent transactions
$transactions_query = "
    SELECT 'premium' as type, premium_date as date, premium_amount as amount, 
           CONCAT('Premium - Loan #', loan_id) as description
    FROM insurance_premiums 
    WHERE status = 'collected'
    UNION ALL
    SELECT 'claim' as type, paid_date as date, claim_amount as amount,
           CONCAT('Claim Payment - ', voucher_no) as description
    FROM insurance_claims 
    WHERE status = 'paid' AND paid_date IS NOT NULL
    ORDER BY date DESC 
    LIMIT 50";

$transactions_result = $conn->query($transactions_query);
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-4">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h5 class="card-title">Fund Balance</h5>
                    <h2 class="card-text">Rs. <?php echo number_format($fund['fund_balance'], 2); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h5 class="card-title">Total Premium Collected</h5>
                    <h2 class="card-text">Rs. <?php echo number_format($fund['total_premium_collected'], 2); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h5 class="card-title">Total Claims Paid</h5>
                    <h2 class="card-text">Rs. <?php echo number_format($fund['total_claims_paid'], 2); ?></h2>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Recent Transactions</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Description</th>
                                    <th>Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($transaction = $transactions_result->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo $transaction['date']; ?></td>
                                    <td>
                                        <span class="badge <?php echo $transaction['type'] === 'premium' ? 'bg-success' : 'bg-danger'; ?>">
                                            <?php echo ucfirst($transaction['type']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($transaction['description']); ?></td>
                                    <td class="text-end <?php echo $transaction['type'] === 'premium' ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo ($transaction['type'] === 'premium' ? '+' : '-') . number_format($transaction['amount'], 2); ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>