<?php
// dashboard.php
require_once 'config/config.php';
checkAccess();

// Get dashboard statistics
$total_customers = 0;
$active_loans = 0;
$total_groups = 0;
$total_cbo = 0;
$pending_loans = 0;
$total_loan_amount = 0;
$completed_loans = 0;
$total_collected = 0;

// Total Customers
$sql = "SELECT COUNT(*) as total FROM customers";
$result = $conn->query($sql);
if ($result) {
    $total_customers = $result->fetch_assoc()['total'];
}

// Active Loans (disbursed status)
$sql = "SELECT COUNT(*) as total FROM loans WHERE status = 'disbursed'";
$result = $conn->query($sql);
if ($result) {
    $active_loans = $result->fetch_assoc()['total'];
}

// Total Groups
$sql = "SELECT COUNT(*) as total FROM groups";
$result = $conn->query($sql);
if ($result) {
    $total_groups = $result->fetch_assoc()['total'];
}

// Total CBO Centers
$sql = "SELECT COUNT(*) as total FROM cbo";
$result = $conn->query($sql);
if ($result) {
    $total_cbo = $result->fetch_assoc()['total'];
}

// Get CBO details for display
$cbo_details = [];
$cbo_sql = "SELECT cbo_number, name, meeting_day FROM cbo ORDER BY cbo_number ASC LIMIT 5";
$cbo_result = $conn->query($cbo_sql);
if ($cbo_result && $cbo_result->num_rows > 0) {
    while ($row = $cbo_result->fetch_assoc()) {
        $cbo_details[] = $row;
    }
}

// Pending Loans
$sql = "SELECT COUNT(*) as total FROM loans WHERE status = 'pending'";
$result = $conn->query($sql);
if ($result) {
    $pending_loans = $result->fetch_assoc()['total'];
}

// Completed Loans
$sql = "SELECT COUNT(*) as total FROM loans WHERE status = 'completed'";
$result = $conn->query($sql);
if ($result) {
    $completed_loans = $result->fetch_assoc()['total'];
}

// Total Loan Amount for active loans
$sql = "SELECT SUM(amount) as total FROM loans WHERE status = 'disbursed'";
$result = $conn->query($sql);
if ($result) {
    $total_loan_amount = $result->fetch_assoc()['total'] ?? 0;
}

// Total Collected Amount (total_loan_amount - balance for completed loans)
$sql = "SELECT SUM(total_loan_amount) as total_collected FROM loans WHERE status = 'completed'";
$result = $conn->query($sql);
if ($result) {
    $total_collected = $result->fetch_assoc()['total_collected'] ?? 0;
}

// Today's activities count
$today_activities = 0;
$today_sql = "SELECT COUNT(*) as today_count FROM loans WHERE DATE(created_at) = CURDATE()";
$today_result = $conn->query($today_sql);
if ($today_result) {
    $today_data = $today_result->fetch_assoc();
    $today_activities = $today_data['today_count'];
}

// Get recent activities
$recent_activities = [];

// Get recent loans with actual data from your database
$activities_sql = "SELECT 'loan' as type, 
                          CONCAT('Loan - ', loan_number) as title, 
                          created_at as date,
                          status,
                          amount,
                          total_loan_amount
                   FROM loans 
                   ORDER BY created_at DESC 
                   LIMIT 6";
$activities_result = $conn->query($activities_sql);
if ($activities_result && $activities_result->num_rows > 0) {
    while ($row = $activities_result->fetch_assoc()) {
        $recent_activities[] = $row;
    }
}

// Calculate performance metrics based on your actual loan data
$total_loans = $active_loans + $pending_loans + $completed_loans;
$performance_rate = $total_loans > 0 ? round(($completed_loans / $total_loans) * 100) : 0;
$disbursement_rate = $total_loans > 0 ? round(($active_loans / $total_loans) * 100) : 0;

// Calculate collection rate based on completed loans vs total loans
$collection_rate = $total_loans > 0 ? round(($completed_loans / $total_loans) * 100) : 0;

// Get total portfolio value (sum of all disbursed loan amounts)
$portfolio_sql = "SELECT SUM(amount) as portfolio_total FROM loans WHERE status = 'disbursed'";
$portfolio_result = $conn->query($portfolio_sql);
if ($portfolio_result) {
    $portfolio_data = $portfolio_result->fetch_assoc();
    $portfolio_total = $portfolio_data['portfolio_total'] ?? 0;
} else {
    $portfolio_total = 0;
}
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
        
        .cbo-item {
            padding: 10px 15px;
            border-left: 3px solid var(--info);
            margin-bottom: 8px;
            background: rgba(6, 214, 160, 0.1);
            border-radius: 8px;
        }
        
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
                        <p class="mb-0 opacity-75">Here's your microfinance management overview for today.</p>
                    </div>
                    <div class="col-md-4 text-end">
                        <div class="d-inline-block bg-white bg-opacity-20 text-white rounded-pill px-4 py-2">
                            <i class="bi bi-person-check me-2"></i>
                            <?php echo ucfirst($_SESSION['user_type']); ?>
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
                                    <h2 class="display-4 fw-bold mb-0"><?php echo number_format($total_customers); ?></h2>
                                    <p class="mb-0 opacity-75">Total Customers</p>
                                    <div class="position-absolute top-0 end-0 mt-2 me-2">
                                        <i class="bi bi-people display-6 opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4 col-sm-6">
                            <div class="stat-card success animate__animated animate__fadeInUp animate__delay-1s">
                                <div class="position-relative">
                                    <h2 class="display-4 fw-bold mb-0"><?php echo number_format($active_loans); ?></h2>
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
                                    <h2 class="display-4 fw-bold mb-0">Rs. <?php echo number_format($portfolio_total, 2); ?></h2>
                                    <p class="mb-0 opacity-75">Total Portfolio</p>
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
                                <a href="modules/reports/center_report.php" class="quick-action-btn">
                                    <div class="text-info mb-3">
                                        <i class="bi bi-graph-up display-6"></i>
                                    </div>
                                    <h6 class="fw-bold mb-1">Reports</h6>
                                    <small class="text-muted">Generate reports</small>
                                </a>
                            </div>
                            <div class="col-md-3 col-6">
                                <a href="modules/groups/" class="quick-action-btn">
                                    <div class="text-warning mb-3">
                                        <i class="bi bi-collection display-6"></i>
                                    </div>
                                    <h6 class="fw-bold mb-1">Groups</h6>
                                    <small class="text-muted">Manage groups</small>
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Performance Overview -->
                    <div class="glass-card p-4 animate__animated animate__fadeIn">
                        <h4 class="fw-bold text-dark mb-4">
                            <i class="bi bi-bar-chart me-2 text-primary"></i>Performance Overview
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
                                        <span>Portfolio Performance</span>
                                        <span class="fw-bold text-warning"><?php echo $performance_rate; ?>%</span>
                                    </div>
                                    <div class="progress" style="height: 12px; border-radius: 10px;">
                                        <div class="progress-bar bg-warning" style="width: <?php echo $performance_rate; ?>%"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center p-4 bg-light rounded h-100 d-flex flex-column justify-content-center">
                                    <h3 class="text-primary fw-bold"><?php echo number_format($today_activities); ?></h3>
                                    <p class="text-muted mb-0">Today's Activities</p>
                                    <small class="text-success">Real-time data</small>
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
                            <i class="bi bi-clock-history me-2 text-success"></i>Recent Activity
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
                                                <?php if (isset($activity['amount'])): ?>
                                                    <div class="mt-1">
                                                        <small class="text-primary fw-bold">Rs. <?php echo number_format($activity['amount'], 2); ?></small>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (isset($activity['status'])): ?>
                                                <span class="badge status-badge badge-<?php echo $activity['status']; ?> ms-2">
                                                    <?php echo ucfirst($activity['status']); ?>
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

                    <!-- CBO Centers Overview -->
                    <div class="glass-card p-4 animate__animated animate__fadeInRight animate__delay-1s">
                        <h4 class="fw-bold text-dark mb-4">
                            <i class="bi bi-building me-2 text-info"></i>CBO Centers
                        </h4>
                        <div class="mb-3">
                            <h5 class="text-primary">Total Centers: <?php echo $total_cbo; ?></h5>
                        </div>
                        <div style="max-height: 200px; overflow-y: auto;">
                            <?php if (!empty($cbo_details)): ?>
                                <?php foreach ($cbo_details as $cbo): ?>
                                    <div class="cbo-item">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="fw-bold mb-1"><?php echo $cbo['cbo_number']; ?> - <?php echo $cbo['name']; ?></h6>
                                                <small class="text-muted">Meeting: <?php echo ucfirst($cbo['meeting_day']); ?></small>
                                            </div>
                                            <span class="badge bg-info">Active</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-3">
                                    <i class="bi bi-building display-6 text-muted"></i>
                                    <p class="text-muted mt-2">No CBO centers found</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="text-center mt-3">
                            <a href="modules/cbo/" class="btn btn-sm btn-outline-primary">View All Centers</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bottom Section - Additional Stats -->
            <div class="row g-4 mt-2">
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card info animate__animated animate__fadeInUp">
                        <div class="position-relative">
                            <h3 class="fw-bold mb-0"><?php echo number_format($total_groups); ?></h3>
                            <p class="mb-0 opacity-75">Total Groups</p>
                            <div class="position-absolute top-0 end-0 mt-2 me-2">
                                <i class="bi bi-collection display-6 opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card dark animate__animated animate__fadeInUp animate__delay-1s">
                        <div class="position-relative">
                            <h3 class="fw-bold mb-0"><?php echo number_format($total_cbo); ?></h3>
                            <p class="mb-0 opacity-75">CBO Centers</p>
                            <div class="position-absolute top-0 end-0 mt-2 me-2">
                                <i class="bi bi-building display-6 opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card danger animate__animated animate__fadeInUp animate__delay-2s">
                        <div class="position-relative">
                            <h3 class="fw-bold mb-0"><?php echo number_format($pending_loans); ?></h3>
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
        <button class="nav-btn" onclick="window.location.href='modules/loans/'">
            <i class="bi bi-cash-coin"></i>
        </button>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto refresh every 2 minutes
        setInterval(function() {
            window.location.reload();
        }, 120000);

        // Add smooth animations
        document.addEventListener('DOMContentLoaded', function() {
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