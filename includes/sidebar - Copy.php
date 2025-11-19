<?php
// includes/sidebar.php
?>
<!-- Sidebar -->
<div class="sidebar">
    <div class="p-3 text-center border-bottom">
        <h4 class="mb-0 text-warning">Micro Finance</h4>
        <small class="text-muted">Admin Panel</small>
    </div>
    
    <nav class="nav flex-column mt-3">
        <a href="admin_dashboard.php" class="nav-link">
            <i class="fas fa-tachometer-alt me-2"></i>Dashboard
        </a>
        <a href="user_management.php" class="nav-link active">
            <i class="fas fa-users me-2"></i>User Management
        </a>
        <a href="#" class="nav-link">
            <i class="fas fa-building me-2"></i>Branch Management
        </a>
        <a href="#" class="nav-link">
            <i class="fas fa-cog me-2"></i>System Settings
        </a>
        <a href="#" class="nav-link">
            <i class="fas fa-chart-bar me-2"></i>Reports
        </a>
    </nav>
    
    <div class="position-absolute bottom-0 w-100 p-3 border-top">
        <div class="d-flex align-items-center">
            <div class="flex-grow-1">
                <small class="d-block"><?php echo $_SESSION['full_name']; ?></small>
                <small class="text-muted">Administrator</small>
            </div>
            <a href="logout.php" class="btn btn-outline-light btn-sm">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </div>
</div>

<style>
.sidebar {
    background: #343a40;
    min-height: 100vh;
    color: white;
    position: fixed;
    width: 250px;
    transition: all 0.3s;
}

.sidebar .nav-link {
    color: white;
    padding: 12px 20px;
    border-left: 4px solid transparent;
    transition: all 0.3s;
}

.sidebar .nav-link:hover,
.sidebar .nav-link.active {
    background: #495057;
    border-left-color: #dc3545;
}

.main-content {
    margin-left: 250px;
    padding: 20px;
    transition: all 0.3s;
}

@media (max-width: 768px) {
    .sidebar {
        margin-left: -250px;
    }
    .main-content {
        margin-left: 0;
    }
    .sidebar.active {
        margin-left: 0;
    }
}
</style>