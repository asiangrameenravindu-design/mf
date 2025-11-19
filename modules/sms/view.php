<?php
session_start();

// Include files
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Initialize variables
$search_results = [];
$staff_details = null;

// Handle search
if (isset($_GET['search_nic'])) {
    $search_nic = $_GET['search_nic'];
    $sql = "SELECT * FROM staff WHERE national_id LIKE ?";
    $stmt = $conn->prepare($sql);
    $search_nic_like = $search_nic . '%';
    $stmt->bind_param("s", $search_nic_like);
    $stmt->execute();
    $search_results = $stmt->get_result();
}

// Get specific staff member
if (isset($_GET['staff_id'])) {
    $staff_id = $_GET['staff_id'];
    $sql = "SELECT * FROM staff WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $staff_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $staff_details = $result->fetch_assoc();
    $stmt->close();
}

// Get all staff for listing
$all_staff_sql = "SELECT * FROM staff ORDER BY position, full_name";
$all_staff_result = $conn->query($all_staff_sql);
?>

<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Staff - Micro Finance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3f37c9;
            --success-color: #4cc9f0;
            --warning-color: #f72585;
            --light-bg: #f8f9fa;
            --card-shadow: 0 8px 25px rgba(0,0,0,0.1);
            --hover-shadow: 0 12px 35px rgba(0,0,0,0.15);
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4edf5 100%);
            min-height: 100vh;
        }
        
        .main-content { 
            margin-left: 280px; 
            padding: 20px; 
            margin-top: 56px; 
            background-color: transparent; 
            min-height: calc(100vh - 56px); 
        }
        
        @media (max-width: 768px) { 
            .main-content { 
                margin-left: 0; 
            } 
        }
        
        .navbar {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%) !important;
            box-shadow: 0 2px 15px rgba(0,0,0,0.1);
        }
        
        .sidebar {
            background: linear-gradient(180deg, #ffffff 0%, #f8f9fe 100%);
            box-shadow: 5px 0 15px rgba(0,0,0,0.05);
            border-right: 1px solid rgba(0,0,0,0.05);
        }
        
        .nav-link {
            border-radius: 8px;
            margin: 2px 8px;
            transition: all 0.3s ease;
            color: #495057;
        }
        
        .nav-link:hover, .nav-link.active {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            transform: translateX(5px);
        }
        
        .staff-avatar {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: bold;
            color: white;
            box-shadow: 0 4px 10px rgba(67, 97, 238, 0.3);
        }
        
        .position-badge {
            font-size: 0.75rem;
            border-radius: 20px;
            padding: 4px 10px;
        }
        
        .card {
            border: none;
            border-radius: 16px;
            box-shadow: var(--card-shadow);
            transition: all 0.3s ease;
            overflow: hidden;
            background: white;
        }
        
        .card-hover {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .card-hover:hover {
            transform: translateY(-5px);
            box-shadow: var(--hover-shadow);
        }
        
        .page-header {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fe 100%);
            border-radius: 16px;
            padding: 20px;
            box-shadow: var(--card-shadow);
            margin-bottom: 24px;
        }
        
        .stat-card {
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
            background: white;
            border-left: 4px solid var(--primary-color);
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--hover-shadow);
        }
        
        .stat-card i {
            font-size: 2.5rem;
            margin-bottom: 10px;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            border: none;
            border-radius: 8px;
            padding: 10px 20px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.4);
        }
        
        .table-responsive {
            border-radius: 12px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
        }
        
        .table {
            margin-bottom: 0;
        }
        
        .table thead th {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            padding: 15px;
            font-weight: 500;
        }
        
        .table tbody tr {
            transition: all 0.2s ease;
        }
        
        .table tbody tr:hover {
            background-color: rgba(67, 97, 238, 0.05);
            transform: translateX(5px);
        }
        
        .search-box {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fe 100%);
            border-radius: 16px;
            padding: 20px;
            box-shadow: var(--card-shadow);
        }
        
        .form-control {
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            padding: 10px 15px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
            border-color: var(--primary-color);
        }
        
        .list-group-item {
            border: none;
            border-radius: 8px !important;
            margin-bottom: 8px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .list-group-item:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .staff-details-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fe 100%);
            border-radius: 16px;
            overflow: hidden;
        }
        
        .empty-state {
            padding: 40px 20px;
            text-align: center;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .badge-manager {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
        }
        
        .badge-accountant {
            background: linear-gradient(135deg, #4cc9f0 0%, #4895ef 100%);
        }
        
        .badge-field_officer {
            background: linear-gradient(135deg, #f72585 0%, #b5179e 100%);
        }
    </style>
</head>
<body>
    

    <!-- Sidebar -->
            <!-- Include Header -->
    <?php include '../../includes/header.php'; ?>

    <!-- Include Sidebar -->
    <?php include '../../includes/sidebar.php'; ?>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                <div class="container-fluid py-4">
                    <!-- Page Header -->
                    <div class="page-header">
                        <div class="row align-items-center">
                            <div class="col">
                                <h1 class="h3 mb-0">
                                    <i class="bi bi-people text-primary me-2"></i>Staff Management
                                </h1>
                                <p class="text-muted mb-0">View and manage staff members</p>
                            </div>
                            <div class="col-auto">
                                <a href="register.php" class="btn btn-primary">
                                    <i class="bi bi-person-plus me-2"></i>Add Staff
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Search Section -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <h5 class="card-title mb-4">
                                <i class="bi bi-search me-2"></i>Search Staff
                            </h5>
                            <form method="GET" class="row g-3 align-items-end">
                                <div class="col-md-8">
                                    <label class="form-label fw-semibold">Search by National ID</label>
                                    <input type="text" 
                                           class="form-control" 
                                           name="search_nic" 
                                           value="<?php echo $_GET['search_nic'] ?? ''; ?>" 
                                           placeholder="Enter NIC number...">
                                </div>
                                <div class="col-md-4">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="bi bi-search me-2"></i>Search
                                    </button>
                                </div>
                            </form>

                            <?php if (isset($_GET['search_nic']) && $search_results->num_rows > 0): ?>
                            <div class="mt-4">
                                <h6 class="mb-3">Search Results:</h6>
                                <div class="row g-3">
                                    <?php while ($staff = $search_results->fetch_assoc()): 
                                        $position_class = [
                                            'manager' => 'badge-manager',
                                            'accountant' => 'badge-accountant',
                                            'field_officer' => 'badge-field_officer'
                                        ][$staff['position']];
                                    ?>
                                    <div class="col-md-6">
                                        <a href="?staff_id=<?php echo $staff['id']; ?>&search_nic=<?php echo $_GET['search_nic']; ?>" 
                                           class="card card-hover text-decoration-none">
                                            <div class="card-body">
                                                <div class="d-flex align-items-center">
                                                    <div class="flex-shrink-0">
                                                        <div class="staff-avatar">
                                                            <?php echo strtoupper(substr($staff['full_name'], 0, 1)); ?>
                                                        </div>
                                                    </div>
                                                    <div class="flex-grow-1 ms-3">
                                                        <h6 class="mb-1 text-dark"><?php echo $staff['full_name']; ?></h6>
                                                        <p class="mb-1 text-muted small"><?php echo $staff['short_name']; ?></p>
                                                        <span class="badge <?php echo $position_class; ?> position-badge me-1">
                                                            <?php echo ucfirst($staff['position']); ?>
                                                        </span>
                                                        <span class="badge bg-light text-dark"><?php echo $staff['national_id']; ?></span>
                                                    </div>
                                                    <div class="flex-shrink-0">
                                                        <i class="bi bi-chevron-right text-muted"></i>
                                                    </div>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                    <?php endwhile; ?>
                                </div>
                            </div>
                            <?php elseif (isset($_GET['search_nic'])): ?>
                            <div class="alert alert-warning mt-4">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                No staff members found with the provided NIC number.
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Staff Details Section -->
                    <?php if ($staff_details): ?>
                    <div class="row">
                        <!-- Staff Information -->
                        <div class="col-lg-4">
                            <div class="card staff-details-card mb-4">
                                <div class="card-body text-center">
                                    <div class="staff-avatar mx-auto mb-3" style="width: 80px; height: 80px; font-size: 2rem;">
                                        <?php echo strtoupper(substr($staff_details['full_name'], 0, 1)); ?>
                                    </div>
                                    <h5 class="mb-1"><?php echo $staff_details['full_name']; ?></h5>
                                    <p class="text-muted mb-2"><?php echo $staff_details['short_name']; ?></p>
                                    <?php
                                    $position_class = [
                                        'manager' => 'badge-manager',
                                        'accountant' => 'badge-accountant',
                                        'field_officer' => 'badge-field_officer'
                                    ][$staff_details['position']];
                                    ?>
                                    <span class="badge <?php echo $position_class; ?> mb-3 p-2">
                                        <?php echo ucfirst($staff_details['position']); ?>
                                    </span>
                                    
                                    <div class="text-start mt-4">
                                        <div class="mb-3">
                                            <small class="text-muted">National ID</small>
                                            <div class="fw-semibold"><?php echo $staff_details['national_id']; ?></div>
                                        </div>
                                        <div class="mb-3">
                                            <small class="text-muted">Phone</small>
                                            <div class="fw-semibold"><?php echo $staff_details['phone']; ?></div>
                                        </div>
                                        <?php if ($staff_details['email']): ?>
                                        <div class="mb-3">
                                            <small class="text-muted">Email</small>
                                            <div class="fw-semibold"><?php echo $staff_details['email']; ?></div>
                                        </div>
                                        <?php endif; ?>
                                        <div class="mb-0">
                                            <small class="text-muted">Joined</small>
                                            <div class="fw-semibold"><?php echo date('F j, Y', strtotime($staff_details['created_at'])); ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-footer bg-transparent">
                                    <div class="d-grid gap-2">
                                        <a href="edit.php?staff_id=<?php echo $staff_details['id']; ?>" class="btn btn-warning btn-sm">
                                            <i class="bi bi-pencil me-2"></i>Edit Profile
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Staff Statistics -->
                        <div class="col-lg-8">
                            <div class="card">
                                <div class="card-header bg-transparent">
                                    <h5 class="card-title mb-0">Staff Statistics</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row text-center">
                                        <div class="col-md-4 mb-3">
                                            <div class="stat-card">
                                                <i class="bi bi-building"></i>
                                                <h4 class="mb-1">
                                                    <?php
                                                    $cbo_count = $conn->query("SELECT COUNT(*) as count FROM cbo WHERE staff_id = " . $staff_details['id'])->fetch_assoc()['count'];
                                                    echo $cbo_count;
                                                    ?>
                                                </h4>
                                                <small class="text-muted">CBOs Assigned</small>
                                            </div>
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <div class="stat-card">
                                                <i class="bi bi-cash-coin"></i>
                                                <h4 class="mb-1">
                                                    <?php
                                                    $loans_count = $conn->query("SELECT COUNT(*) as count FROM loans WHERE staff_id = " . $staff_details['id'])->fetch_assoc()['count'];
                                                    echo $loans_count;
                                                    ?>
                                                </h4>
                                                <small class="text-muted">Loans Processed</small>
                                            </div>
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <div class="stat-card">
                                                <i class="bi bi-person-check"></i>
                                                <h4 class="mb-1">
                                                    <?php
                                                    $customers_count = $conn->query("SELECT COUNT(DISTINCT customer_id) as count FROM loans WHERE staff_id = " . $staff_details['id'])->fetch_assoc()['count'];
                                                    echo $customers_count;
                                                    ?>
                                                </h4>
                                                <small class="text-muted">Customers Served</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Assigned CBOs -->
                            <div class="card mt-4">
                                <div class="card-header bg-transparent">
                                    <h5 class="card-title mb-0">Assigned CBOs</h5>
                                </div>
                                <div class="card-body">
                                    <?php
                                    $cbo_sql = "SELECT * FROM cbo WHERE staff_id = ? ORDER BY name";
                                    $cbo_stmt = $conn->prepare($cbo_sql);
                                    $cbo_stmt->bind_param("i", $staff_details['id']);
                                    $cbo_stmt->execute();
                                    $cbo_result = $cbo_stmt->get_result();
                                    
                                    if ($cbo_result->num_rows > 0): ?>
                                        <div class="list-group">
                                            <?php while ($cbo = $cbo_result->fetch_assoc()): ?>
                                            <a href="../cbo/overview.php?cbo_id=<?php echo $cbo['id']; ?>" class="list-group-item list-group-item-action">
                                                <div class="d-flex w-100 justify-content-between">
                                                    <h6 class="mb-1"><?php echo $cbo['name']; ?></h6>
                                                    <small>CBO #<?php echo $cbo['cbo_number']; ?></small>
                                                </div>
                                                <p class="mb-1">Meeting Day: <?php echo ucfirst($cbo['meeting_day']); ?></p>
                                            </a>
                                            <?php endwhile; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="empty-state">
                                            <i class="bi bi-building"></i>
                                            <p>No CBOs assigned to this staff member.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    
                    <!-- All Staff Listing -->
                    <div class="card">
                        <div class="card-header bg-transparent">
                            <h5 class="card-title mb-0">All Staff Members</h5>
                        </div>
                        <div class="card-body">
                            <?php if ($all_staff_result->num_rows > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Staff Member</th>
                                                <th>Position</th>
                                                <th>National ID</th>
                                                <th>Phone</th>
                                                <th>Joined Date</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while ($staff = $all_staff_result->fetch_assoc()): 
                                                $position_class = [
                                                    'manager' => 'badge-manager',
                                                    'accountant' => 'badge-accountant',
                                                    'field_officer' => 'badge-field_officer'
                                                ][$staff['position']];
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="staff-avatar me-3">
                                                            <?php echo strtoupper(substr($staff['full_name'], 0, 1)); ?>
                                                        </div>
                                                        <div>
                                                            <div class="fw-semibold"><?php echo $staff['full_name']; ?></div>
                                                            <small class="text-muted"><?php echo $staff['short_name']; ?></small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge <?php echo $position_class; ?>">
                                                        <?php echo ucfirst($staff['position']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo $staff['national_id']; ?></td>
                                                <td><?php echo $staff['phone']; ?></td>
                                                <td><?php echo date('M j, Y', strtotime($staff['created_at'])); ?></td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="?staff_id=<?php echo $staff['id']; ?>" class="btn btn-outline-primary">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                        <a href="edit.php?staff_id=<?php echo $staff['id']; ?>" class="btn btn-outline-warning">
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
                                    <i class="bi bi-people"></i>
                                    <h5 class="text-muted">No Staff Members</h5>
                                    <p class="text-muted mb-4">No staff members have been registered yet.</p>
                                    <a href="register.php" class="btn btn-primary">
                                        <i class="bi bi-person-plus me-2"></i>Add First Staff Member
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>