<?php
// modules/loans/index.php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

// Check if user has permission (manager or field_officer)
$allowed_roles = ['manager', 'admin','accountant', ];
if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], $allowed_roles)) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit();
}

// Get loans list - FIXED SQL QUERY
$sql = "SELECT l.id, l.loan_number, l.customer_id, l.cbo_id, 
               l.amount as loan_amount, 
               l.number_of_weeks, 
               l.weekly_installment, 
               l.status, 
               l.applied_date,
               l.created_at,
               c.full_name as customer_name, 
               cb.name as cbo_name 
        FROM loans l
        JOIN customers c ON l.customer_id = c.id
        JOIN cbo cb ON l.cbo_id = cb.id
        ORDER BY l.created_at DESC
        LIMIT 50";
$loans = $conn->query($sql);

// Get loan statistics
$stats_sql = "SELECT 
    COUNT(*) as total_loans,
    SUM(amount) as total_amount,
    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_loans,
    COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_loans,
    COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_loans,
    COUNT(CASE WHEN status = 'disbursed' THEN 1 END) as disbursed_loans
    FROM loans";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loans Management - Micro Finance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3f37c9;
            --success: #4cc9f0;
            --warning: #f72585;
            --info: #7209b7;
            --light: #f8f9fa;
            --dark: #212529;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f5f7fb 0%, #e3e8ff 100%);
            min-height: 100vh;
        }
        
        .main-content {
            margin-left: 280px;
            padding: 30px;
            min-height: 100vh;
            transition: all 0.3s;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
        }
        
        .page-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 20px;
            color: white;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.1), transparent);
            transform: rotate(45deg);
            transition: all 0.6s ease;
        }
        
        .stat-card:hover::before {
            transform: rotate(45deg) translate(50%, 50%);
        }
        
        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px rgba(102, 126, 234, 0.3);
        }
        
        .stat-card.secondary {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        
        .stat-card.success {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }
        
        .stat-card.warning {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        }
        
        .stat-card.info {
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
        }
        
        .loan-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .loan-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.15);
            border-color: rgba(67, 97, 238, 0.3);
        }
        
        .status-badge {
            padding: 8px 16px;
            border-radius: 15px;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border: none;
            border-radius: 15px;
            padding: 12px 30px;
            font-weight: 600;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .btn-primary-custom::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }
        
        .btn-primary-custom:hover::before {
            left: 100%;
        }
        
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(67, 97, 238, 0.4);
        }
        
        .table-custom {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .table-custom thead {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
        }
        
        .table-custom th {
            border: none;
            padding: 20px;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .table-custom td {
            border: none;
            padding: 20px;
            vertical-align: middle;
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }
        
        .table-custom tbody tr {
            transition: all 0.3s ease;
        }
        
        .table-custom tbody tr:hover {
            background: rgba(67, 97, 238, 0.05);
            transform: scale(1.01);
        }
        
        .table-custom tbody tr:last-child td {
            border-bottom: none;
        }
        
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 5rem;
            margin-bottom: 20px;
            opacity: 0.3;
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .customer-avatar {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1.1rem;
        }
        
        .loan-number {
            font-weight: 700;
            color: var(--primary);
            font-size: 0.9rem;
        }
        
        .customer-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 2px;
        }
        
        .amount-display {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--primary);
        }
        
        .installment-display {
            font-weight: 600;
            color: var(--success);
        }
        
        .weeks-display {
            background: rgba(67, 97, 238, 0.1);
            color: var(--primary);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .action-btn {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            border: 2px solid rgba(67, 97, 238, 0.2);
        }
        
        .action-btn:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            transform: scale(1.1);
        }
        
        .section-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 20px;
            position: relative;
            padding-left: 15px;
        }
        
        .section-title::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 20px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 2px;
        }
        
        .stats-icon {
            font-size: 2.5rem;
            opacity: 0.8;
            margin-bottom: 15px;
        }
        
        .stats-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .stats-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        /* Animation classes */
        .fade-in {
            animation: fadeIn 0.6s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .slide-in {
            animation: slideIn 0.8s ease-out;
        }
        
        @keyframes slideIn {
            from { transform: translateX(-30px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
    </style>
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <?php include '../../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="container-fluid">
            <!-- Page Header -->
            <div class="page-header fade-in">
                <div class="row align-items-center">
                    <div class="col">
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb mb-2">
                                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/dashboard.php" class="text-decoration-none text-muted">Dashboard</a></li>
                                <li class="breadcrumb-item active text-primary fw-semibold">Loans Management</li>
                            </ol>
                        </nav>
                        <h1 class="h2 mb-1 fw-bold text-dark">Loans Management</h1>
                        <p class="text-muted mb-0">Manage and track all loan applications and approvals</p>
                    </div>
                    <div class="col-auto">
                        <a href="<?php echo BASE_URL; ?>/modules/loans/new.php" class="btn btn-primary-custom text-white">
                            <i class="bi bi-plus-circle me-2"></i>New Loan Application
                        </a>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="row mb-5 slide-in">
                <div class="col-xl-2 col-md-4 col-6 mb-4">
                    <div class="stat-card p-4">
                        <div class="text-center">
                            <i class="bi bi-wallet2 stats-icon"></i>
                            <div class="stats-number"><?php echo $stats['total_loans']; ?></div>
                            <div class="stats-label">Total Loans</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-6 mb-4">
                    <div class="stat-card secondary p-4">
                        <div class="text-center">
                            <i class="bi bi-cash-coin stats-icon"></i>
                            <div class="stats-number">Rs. <?php echo number_format($stats['total_amount'] ?? 0, 0); ?></div>
                            <div class="stats-label">Total Amount</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-6 mb-4">
                    <div class="stat-card success p-4">
                        <div class="text-center">
                            <i class="bi bi-clock-history stats-icon"></i>
                            <div class="stats-number"><?php echo $stats['pending_loans']; ?></div>
                            <div class="stats-label">Pending</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-6 mb-4">
                    <div class="stat-card warning p-4">
                        <div class="text-center">
                            <i class="bi bi-check-circle stats-icon"></i>
                            <div class="stats-number"><?php echo $stats['approved_loans']; ?></div>
                            <div class="stats-label">Approved</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-6 mb-4">
                    <div class="stat-card info p-4">
                        <div class="text-center">
                            <i class="bi bi-arrow-up-circle stats-icon"></i>
                            <div class="stats-number"><?php echo $stats['disbursed_loans']; ?></div>
                            <div class="stats-label">Disbursed</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-6 mb-4">
                    <div class="stat-card p-4" style="background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);">
                        <div class="text-center">
                            <i class="bi bi-x-circle stats-icon"></i>
                            <div class="stats-number"><?php echo $stats['rejected_loans']; ?></div>
                            <div class="stats-label">Rejected</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Loans List -->
            <div class="loan-card fade-in">
                <div class="card-header bg-transparent border-0 py-4">
                    <div class="row align-items-center">
                        <div class="col">
                            <h5 class="section-title mb-0">
                                <i class="bi bi-list-ul me-2"></i>Recent Loan Applications
                            </h5>
                        </div>
                        <div class="col-auto">
                            <span class="badge bg-primary bg-opacity-10 text-primary fs-6 px-3 py-2 rounded-pill">
                                <i class="bi bi-receipt me-1"></i><?php echo $loans->num_rows; ?> loans
                            </span>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if ($loans->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-custom table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Loan Details</th>
                                        <th>Customer</th>
                                        <th>CBO</th>
                                        <th>Amount</th>
                                        <th>Duration</th>
                                        <th>Installment</th>
                                        <th>Status</th>
                                        <th>Applied Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($loan = $loans->fetch_assoc()): 
                                        $status_config = [
                                            'pending' => ['class' => 'bg-warning text-dark', 'icon' => 'bi-clock'],
                                            'approved' => ['class' => 'bg-success', 'icon' => 'bi-check-circle'],
                                            'rejected' => ['class' => 'bg-danger', 'icon' => 'bi-x-circle'],
                                            'disbursed' => ['class' => 'bg-info', 'icon' => 'bi-arrow-up-circle'],
                                            'active' => ['class' => 'bg-primary', 'icon' => 'bi-play-circle'],
                                            'completed' => ['class' => 'bg-secondary', 'icon' => 'bi-flag-fill']
                                        ];
                                        $status_info = $status_config[$loan['status']] ?? $status_config['pending'];
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="flex-shrink-0">
                                                    <div class="customer-avatar">
                                                        <?php echo substr($loan['customer_name'], 0, 1); ?>
                                                    </div>
                                                </div>
                                                <div class="flex-grow-1 ms-3">
                                                    <div class="loan-number"><?php echo htmlspecialchars($loan['loan_number']); ?></div>
                                                    <small class="text-muted">Applied: <?php echo date('M j, Y', strtotime($loan['applied_date'])); ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="customer-name"><?php echo htmlspecialchars($loan['customer_name']); ?></div>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($loan['cbo_name']); ?></span>
                                        </td>
                                        <td>
                                            <div class="amount-display">Rs. <?php echo number_format($loan['loan_amount'], 2); ?></div>
                                        </td>
                                        <td>
                                            <span class="weeks-display"><?php echo $loan['number_of_weeks']; ?> weeks</span>
                                        </td>
                                        <td>
                                            <div class="installment-display">Rs. <?php echo number_format($loan['weekly_installment'], 2); ?></div>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $status_info['class']; ?>">
                                                <i class="<?php echo $status_info['icon']; ?> me-1"></i>
                                                <?php echo ucfirst($loan['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <small class="text-muted"><?php echo date('M j, Y', strtotime($loan['applied_date'])); ?></small>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-2">
                                                <a href="view.php?loan_id=<?php echo $loan['id']; ?>" 
                                                   class="action-btn text-primary"
                                                   data-bs-toggle="tooltip" 
                                                   title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="#" 
                                                   class="action-btn text-info"
                                                   data-bs-toggle="tooltip" 
                                                   title="Edit Loan">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-cash-coin"></i>
                            <h4 class="text-muted mb-3">No Loans Found</h4>
                            <p class="text-muted mb-4">No loan applications have been submitted yet.</p>
                            <a href="new.php" class="btn btn-primary-custom text-white">
                                <i class="bi bi-plus-circle me-2"></i>Create First Loan
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Initialize tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl)
        });

        // Add loading animation
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.stat-card, .loan-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });
        });
    </script>

    <?php include '../../includes/footer.php'; ?>
</body>
</html>