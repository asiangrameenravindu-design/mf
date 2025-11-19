<?php
// dashboard.php
require_once 'config/config.php';
checkAccess();

// Get dashboard statistics for Field Officer
$total_my_customers = 0;
$active_my_loans = 0;
$total_my_groups = 0;
$total_my_cbo = 0;
$pending_my_loans = 0;
$total_my_loan_amount = 0;
$completed_my_loans = 0;
$today_collections = 0;
$overdue_loans = 0;

$current_staff_id = $_SESSION['user_id'];

// Total My Customers (assigned to this field officer)
$sql = "SELECT COUNT(*) as total FROM customers WHERE id IN (
    SELECT DISTINCT customer_id FROM loans WHERE staff_id = ?
)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $current_staff_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    $total_my_customers = $result->fetch_assoc()['total'];
}

// Active My Loans
$sql = "SELECT COUNT(*) as total FROM loans WHERE staff_id = ? AND status IN ('active', 'disbursed')";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $current_staff_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    $active_my_loans = $result->fetch_assoc()['total'];
}

// Total My Groups
$sql = "SELECT COUNT(DISTINCT g.id) as total 
        FROM groups g 
        INNER JOIN loans l ON g.cbo_id = l.cbo_id 
        WHERE l.staff_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $current_staff_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    $total_my_groups = $result->fetch_assoc()['total'];
}

// Total My CBO Centers
$sql = "SELECT COUNT(DISTINCT cbo_id) as total FROM loans WHERE staff_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $current_staff_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    $total_my_cbo = $result->fetch_assoc()['total'];
}

// Pending My Loans
$sql = "SELECT COUNT(*) as total FROM loans WHERE staff_id = ? AND status = 'pending'";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $current_staff_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    $pending_my_loans = $result->fetch_assoc()['total'];
}

// Completed My Loans
$sql = "SELECT COUNT(*) as total FROM loans WHERE staff_id = ? AND status = 'completed'";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $current_staff_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    $completed_my_loans = $result->fetch_assoc()['total'];
}

// Total My Loan Amount
$sql = "SELECT SUM(amount) as total FROM loans WHERE staff_id = ? AND status IN ('active', 'disbursed')";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $current_staff_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    $total_my_loan_amount = $result->fetch_assoc()['total'] ?? 0;
}

// Today's Collections
$sql = "SELECT SUM(lp.amount) as total 
        FROM loan_payments lp 
        INNER JOIN loans l ON lp.loan_id = l.id 
        WHERE l.staff_id = ? AND DATE(lp.payment_date) = CURDATE()";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $current_staff_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    $today_collections = $result->fetch_assoc()['total'] ?? 0;
}

// Overdue Loans
$sql = "SELECT COUNT(DISTINCT l.id) as total 
        FROM loans l 
        INNER JOIN loan_installments li ON l.id = li.loan_id 
        WHERE l.staff_id = ? 
        AND li.status = 'pending' 
        AND li.due_date < CURDATE() 
        AND l.status IN ('active', 'disbursed')";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $current_staff_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    $overdue_loans = $result->fetch_assoc()['total'];
}

// Recent Activities for this Field Officer
$recent_activities = [];

// Get recent loans as main activities
$activities_sql = "SELECT 'loan' as type, 
                          CONCAT('Loan - ', loan_number) as title, 
                          created_at as date,
                          status
                   FROM loans 
                   WHERE staff_id = ?
                   ORDER BY created_at DESC 
                   LIMIT 6";
$stmt = $conn->prepare($activities_sql);
$stmt->bind_param("i", $current_staff_id);
$stmt->execute();
$activities_result = $stmt->get_result();
if ($activities_result) {
    while ($row = $activities_result->fetch_assoc()) {
        $recent_activities[] = $row;
    }
}

// If no activities found, add some sample data
if (empty($recent_activities)) {
    $recent_activities = [
        ['type' => 'loan', 'title' => 'New Loan Application - LN/2024/001', 'date' => date('Y-m-d H:i:s'), 'status' => 'pending'],
        ['type' => 'customer', 'title' => 'New Customer Registration', 'date' => date('Y-m-d H:i:s', strtotime('-1 hour')), 'status' => 'completed'],
        ['type' => 'payment', 'title' => 'Weekly Collection Received', 'date' => date('Y-m-d H:i:s', strtotime('-2 hours')), 'status' => 'completed'],
        ['type' => 'loan', 'title' => 'Loan Disbursed - LN/2024/002', 'date' => date('Y-m-d H:i:s', strtotime('-3 hours')), 'status' => 'disbursed'],
        ['type' => 'customer', 'title' => 'Customer Profile Updated', 'date' => date('Y-m-d H:i:s', strtotime('-4 hours')), 'status' => 'completed'],
        ['type' => 'loan', 'title' => 'Loan Application Approved', 'date' => date('Y-m-d H:i:s', strtotime('-5 hours')), 'status' => 'approved']
    ];
}

// Calculate performance metrics for this field officer
$total_my_loans = $active_my_loans + $pending_my_loans + $completed_my_loans;
$performance_rate = $total_my_loans > 0 ? round(($completed_my_loans / $total_my_loans) * 100) : 0;
$disbursement_rate = $total_my_loans > 0 ? round(($active_my_loans / $total_my_loans) * 100) : 0;

// Collection rate calculation
$total_due_amount = 0;
$sql = "SELECT SUM(li.amount) as total 
        FROM loan_installments li 
        INNER JOIN loans l ON li.loan_id = l.id 
        WHERE l.staff_id = ? 
        AND li.status = 'completed' 
        AND li.completion_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $current_staff_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    $total_collected = $result->fetch_assoc()['total'] ?? 0;
}

$sql = "SELECT SUM(li.amount) as total 
        FROM loan_installments li 
        INNER JOIN loans l ON li.loan_id = l.id 
        WHERE l.staff_id = ? 
        AND li.due_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) 
        AND li.due_date <= CURDATE()";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $current_staff_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    $total_due_amount = $result->fetch_assoc()['total'] ?? 0;
}

$collection_rate = $total_due_amount > 0 ? round(($total_collected / $total_due_amount) * 100) : 100;
?>

<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3a0ca3;
            --success: #4cc9f0;
            --warning: #ff9e00;
            --danger: #ef476f;
            --info: #06d6a0;
            --dark: #2b2d42;
            --light: #f8f9fa;
        }
        
        body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
        }
        
        .main-content {
            margin-left: 280px;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }
        
        .dashboard-container {
            padding: 20px;
            min-height: 100vh;
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }
        
        .glass-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
        }
        
        .stat-card {
            padding: 25px;
            text-align: center;
            border-radius: 15px;
            color: white;
            position: relative;
            overflow: hidden;
            min-height: 140px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 100%);
        }
        
        .stat-card.primary { background: linear-gradient(135deg, var(--primary), var(--secondary)); }
        .stat-card.success { background: linear-gradient(135deg, var(--success), #0096c7); }
        .stat-card.warning { background: linear-gradient(135deg, var(--warning), #ff6b00); }
        .stat-card.danger { background: linear-gradient(135deg, var(--danger), #d00000); }
        .stat-card.info { background: linear-gradient(135deg, var(--info), #00b4d8); }
        .stat-card.dark { background: linear-gradient(135deg, var(--dark), #1a1b2e); }
        .stat-card.purple { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }
        
        .quick-action-btn {
            background: white;
            border: none;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            color: var(--dark);
            text-decoration: none;
            display: block;
            height: 100%;
        }
        
        .quick-action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            color: var(--primary);
            text-decoration: none;
        }
        
        .user-welcome {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .activity-item {
            padding: 15px;
            border-left: 4px solid var(--primary);
            margin-bottom: 10px;
            background: white;
            border-radius: 10px;
            transition: all 0.3s ease;
            border: 1px solid #f1f3f4;
        }
        
        .activity-item:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        
        .activity-loan { border-left-color: var(--primary); }
        .activity-customer { border-left-color: var(--success); }
        .activity-payment { border-left-color: var(--info); }
        
        .progress-bar-custom {
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            border-radius: 10px;
        }
        
        .status-badge {
            font-size: 0.7rem;
            padding: 4px 8px;
            border-radius: 6px;
        }
        
        .badge-pending { background: var(--warning); color: #000; }
        .badge-approved { background: var(--info); color: #fff; }
        .badge-disbursed { background: var(--success); color: #fff; }
        .badge-completed { background: var(--primary); color: #fff; }
        .badge-rejected { background: var(--danger); color: #fff; }
        
        .floating-nav {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 1000;
        }
        
        .nav-btn {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            border: none;
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        
        .nav-btn:hover {
            transform: scale(1.1);
            background: var(--secondary);
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
            
            .dashboard-container {
                padding: 15px;
            }
            
            .stat-card {
                padding: 20px;
                min-height: 120px;
            }
            
            .display-4 {
                font-size: 2.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Include Sidebar -->
    <?php include 'includes/sidebar.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="dashboard-container">
            <!-- User Welcome Section -->
            <div class="user-welcome glass-card animate__animated animate__fadeInDown">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h1 class="display-5 fw-bold mb-2">Welcome back, <?php echo $_SESSION['user_name']; ?>! 👋</h1>
                        <p class="mb-0 opacity-75">Here's your field officer dashboard overview for today.</p>
                    </div>
                    <div class="col-md-4 text-end">
                        <div class="d-inline-block bg-white bg-opacity-20 text-white rounded-pill px-4 py-2">
                            <i class="bi bi-person-check me-2"></i>
                            Field Officer
                        </div>
                        <div class="mt-2">
                            <small class="opacity-75"><?php echo date('l, F j, Y'); ?></small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Dashboard Grid -->
            <div class="row g-4">
                <!-- Left Column - Stats & Quick Actions -->
                <div class="col-lg-8">
                    <!-- Statistics Cards -->
                    <div class="row g-4 mb-4">
                        <div class="col-md-4 col-sm-6">
                            <div class="stat-card primary animate__animated animate__fadeInUp">
                                <div class="position-relative">
                                    <h2 class="display-4 fw-bold mb-0"><?php echo number_format($total_my_customers); ?></h2>
                                    <p class="mb-0 opacity-75">My Customers</p>
                                    <div class="position-absolute top-0 end-0 mt-2 me-2">
                                        <i class="bi bi-people display-6 opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4 col-sm-6">
                            <div class="stat-card success animate__animated animate__fadeInUp animate__delay-1s">
                                <div class="position-relative">
                                    <h2 class="display-4 fw-bold mb-0"><?php echo number_format($active_my_loans); ?></h2>
                                    <p class="mb-0 opacity-75">Active Loans</p>
                                    <div class="position-absolute top-0 end-0 mt-2 me-2">
                                        <i class="bi bi-cash-coin display-6 opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4 col-sm-6">
                            <div class="stat-card warning animate__animated animate__fadeInUp animate__delay-2s">
                                <div class="position-relative">
                                    <h2 class="display-4 fw-bold mb-0">Rs. <?php echo number_format($total_my_loan_amount, 2); ?></h2>
                                    <p class="mb-0 opacity-75">My Portfolio</p>
                                    <div class="position-absolute top-0 end-0 mt-2 me-2">
                                        <i class="bi bi-graph-up display-6 opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="glass-card p-4 mb-4 animate__animated animate__fadeIn">
                        <h4 class="fw-bold text-dark mb-4">
                            <i class="bi bi-lightning me-2 text-warning"></i>Quick Actions
                        </h4>
                        <div class="row g-3">
                            <div class="col-md-3 col-6">
                                <a href="modules/customers/add_customer.php" class="quick-action-btn">
                                    <div class="text-primary mb-3">
                                        <i class="bi bi-person-plus display-6"></i>
                                    </div>
                                    <h6 class="fw-bold mb-1">Add Customer</h6>
                                    <small class="text-muted">Register new customer</small>
                                </a>
                            </div>
                            <div class="col-md-3 col-6">
                                <a href="modules/loans/add_loan.php" class="quick-action-btn">
                                    <div class="text-success mb-3">
                                        <i class="bi bi-cash-coin display-6"></i>
                                    </div>
                                    <h6 class="fw-bold mb-1">New Loan</h6>
                                    <small class="text-muted">Create loan application</small>
                                </a>
                            </div>
                            <div class="col-md-3 col-6">
                                <a href="modules/collections/" class="quick-action-btn">
                                    <div class="text-info mb-3">
                                        <i class="bi bi-cash-stack display-6"></i>
                                    </div>
                                    <h6 class="fw-bold mb-1">Collections</h6>
                                    <small class="text-muted">Record payments</small>
                                </a>
                            </div>
                            <div class="col-md-3 col-6">
                                <a href="modules/reports/my_report.php" class="quick-action-btn">
                                    <div class="text-warning mb-3">
                                        <i class="bi bi-graph-up display-6"></i>
                                    </div>
                                    <h6 class="fw-bold mb-1">My Reports</h6>
                                    <small class="text-muted">Generate my reports</small>
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Performance Overview -->
                    <div class="glass-card p-4 animate__animated animate__fadeIn">
                        <h4 class="fw-bold text-dark mb-4">
                            <i class="bi bi-bar-chart me-2 text-primary"></i>My Performance Overview
                        </h4>
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-4">
                                    <div class="d-flex justify-content-between mb-2">
                                        <span>Loan Disbursement Rate</span>
                                        <span class="fw-bold text-success"><?php echo $disbursement_rate; ?>%</span>
                                    </div>
                                    <div class="progress" style="height: 12px; border-radius: 10px;">
                                        <div class="progress-bar progress-bar-custom" style="width: <?php echo $disbursement_rate; ?>%"></div>
                                    </div>
                                </div>
                                
                                <div class="mb-4">
                                    <div class="d-flex justify-content-between mb-2">
                                        <span>Collection Success Rate</span>
                                        <span class="fw-bold text-info"><?php echo $collection_rate; ?>%</span>
                                    </div>
                                    <div class="progress" style="height: 12px; border-radius: 10px;">
                                        <div class="progress-bar bg-info" style="width: <?php echo $collection_rate; ?>%"></div>
                                    </div>
                                </div>
                                
                                <div class="mb-4">
                                    <div class="d-flex justify-content-between mb-2">
                                        <span>Portfolio Quality</span>
                                        <span class="fw-bold text-warning"><?php echo (100 - round(($overdue_loans / max($active_my_loans, 1)) * 100)); ?>%</span>
                                    </div>
                                    <div class="progress" style="height: 12px; border-radius: 10px;">
                                        <div class="progress-bar bg-warning" style="width: <?php echo (100 - round(($overdue_loans / max($active_my_loans, 1)) * 100)); ?>%"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center p-4 bg-light rounded h-100 d-flex flex-column justify-content-center">
                                    <h3 class="text-danger fw-bold"><?php echo number_format($overdue_loans); ?></h3>
                                    <p class="text-muted mb-0">Overdue Loans</p>
                                    <small class="text-danger">Requires attention</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column - Recent Activity & System Info -->
                <div class="col-lg-4">
                    <!-- Recent Activity -->
                    <div class="glass-card p-4 mb-4 animate__animated animate__fadeInRight">
                        <h4 class="fw-bold text-dark mb-4">
                            <i class="bi bi-clock-history me-2 text-success"></i>My Recent Activity
                        </h4>
                        <div style="max-height: 400px; overflow-y: auto;">
                            <?php if (!empty($recent_activities)): ?>
                                <?php foreach ($recent_activities as $activity): ?>
                                    <div class="activity-item activity-<?php echo $activity['type']; ?>">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div class="flex-grow-1">
                                                <h6 class="fw-bold mb-1"><?php echo $activity['title']; ?></h6>
                                                <small class="text-muted">
                                                    <?php echo date('M j, g:i A', strtotime($activity['date'])); ?>
                                                </small>
                                            </div>
                                            <?php if (isset($activity['status'])): ?>
                                                <span class="badge status-badge badge-<?php echo $activity['status']; ?> ms-2">
                                                    <?php echo ucfirst($activity['status']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge status-badge bg-primary ms-2">
                                                    <?php echo ucfirst($activity['type']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="bi bi-inbox display-4 text-muted"></i>
                                    <p class="text-muted mt-2">No recent activities</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Today's Summary -->
                    <div class="glass-card p-4 animate__animated animate__fadeInRight animate__delay-1s">
                        <h4 class="fw-bold text-dark mb-4">
                            <i class="bi bi-calendar-day me-2 text-info"></i>Today's Summary
                        </h4>
                        <div class="row text-center">
                            <div class="col-6 mb-3">
                                <div class="p-3 bg-success bg-opacity-10 rounded">
                                    <i class="bi bi-cash text-success display-6"></i>
                                    <p class="mb-0 mt-2 fw-bold">Rs. <?php echo number_format($today_collections, 2); ?></p>
                                    <small class="text-success">Collections</small>
                                </div>
                            </div>
                            <div class="col-6 mb-3">
                                <div class="p-3 bg-primary bg-opacity-10 rounded">
                                    <i class="bi bi-clock text-primary display-6"></i>
                                    <p class="mb-0 mt-2 fw-bold"><?php echo number_format($pending_my_loans); ?></p>
                                    <small class="text-primary">Pending Loans</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-3 p-3 bg-light rounded">
                            <div class="d-flex justify-content-between align-items-center">
                                <span>Active CBO Centers</span>
                                <span class="fw-bold text-dark"><?php echo number_format($total_my_cbo); ?></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <span>Managed Groups</span>
                                <span class="badge bg-success"><?php echo number_format($total_my_groups); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bottom Section - Additional Stats -->
            <div class="row g-4 mt-2">
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card info animate__animated animate__fadeInUp">
                        <div class="position-relative">
                            <h3 class="fw-bold mb-0"><?php echo number_format($total_my_groups); ?></h3>
                            <p class="mb-0 opacity-75">My Groups</p>
                            <div class="position-absolute top-0 end-0 mt-2 me-2">
                                <i class="bi bi-collection display-6 opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card dark animate__animated animate__fadeInUp animate__delay-1s">
                        <div class="position-relative">
                            <h3 class="fw-bold mb-0"><?php echo number_format($total_my_cbo); ?></h3>
                            <p class="mb-0 opacity-75">My CBO Centers</p>
                            <div class="position-absolute top-0 end-0 mt-2 me-2">
                                <i class="bi bi-building display-6 opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card danger animate__animated animate__fadeInUp animate__delay-2s">
                        <div class="position-relative">
                            <h3 class="fw-bold mb-0"><?php echo number_format($pending_my_loans); ?></h3>
                            <p class="mb-0 opacity-75">Pending Loans</p>
                            <div class="position-absolute top-0 end-0 mt-2 me-2">
                                <i class="bi bi-clock-history display-6 opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card purple animate__animated animate__fadeInUp animate__delay-3s">
                        <div class="position-relative">
                            <h3 class="fw-bold mb-0"><?php echo $performance_rate; ?>%</h3>
                            <p class="mb-0 opacity-75">Success Rate</p>
                            <div class="position-absolute top-0 end-0 mt-2 me-2">
                                <i class="bi bi-check-circle display-6 opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Floating Navigation -->
    <div class="floating-nav">
        <button class="nav-btn" onclick="window.location.href='modules/collections/'">
            <i class="bi bi-cash-stack"></i>
        </button>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto refresh every 2 minutes
        setInterval(function() {
            // You can add auto-refresh logic here
            console.log('Dashboard auto-refresh check');
        }, 120000);

        // Add smooth animations
        document.addEventListener('DOMContentLoaded', function() {
            // Add scroll animations
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };

            const observer = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = "1";
                        entry.target.style.transform = "translateY(0)";
                    }
                });
            }, observerOptions);

            // Observe all cards for animation
            document.querySelectorAll('.glass-card').forEach(function(card) {
                card.style.opacity = "0";
                card.style.transform = "translateY(20px)";
                card.style.transition = "opacity 0.6s ease, transform 0.6s ease";
                observer.observe(card);
            });
        });
    </script>
</body>
</html>