<?php
// includes/sidebar.php

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Safely get session values
$user_name = $_SESSION['user_name'] ?? 'User';
$user_type = $_SESSION['user_type'] ?? 'user';
$branch = $_SESSION['branch'] ?? 'Main Branch';

// Set CORRECT BASE_URL
$base_url = 'http://localhost/micro_finance_system';
?>

<!-- Sidebar -->
<div class="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <i class="bi bi-cash-coin"></i>
            <span class="logo-text">Micro Finance</span>
        </div>
        <button class="sidebar-toggle" id="sidebarToggle">
            <i class="bi bi-list"></i>
        </button>
    </div>

    <div class="sidebar-menu">
        <ul class="sidebar-nav">
            <!-- Dashboard -->
            <li class="nav-item">
                <a href="<?php echo $base_url; ?>/admin_dashboard.php" class="nav-link">
                    <i class="bi bi-speedometer2"></i>
                    <span>Dashboard</span>
                </a>
            </li>

            <!-- User Management -->
            <li class="nav-item">
                <a href="#" class="nav-link has-dropdown active">
                    <i class="bi bi-people"></i>
                    <span>User Management</span>
                    <i class="bi bi-chevron-down dropdown-arrow"></i>
                </a>
                <ul class="submenu">
                    <li><a href="<?php echo $base_url; ?>/modules/admin/users.php">Manage Users</a></li>
                    <li><a href="<?php echo $base_url; ?>/modules/admin/create_user.php">Create User</a></li>
                    <li><a href="<?php echo $base_url; ?>/modules/admin/user_roles.php">User Roles</a></li>
                    <li><a href="<?php echo $base_url; ?>/modules/staff/register.php">Staff Registration</a></li>
                    <li><a href="<?php echo $base_url; ?>/modules/staff/view.php">View Staff</a></li>
                </ul>
            </li>

            <!-- Customer Management -->
            <li class="nav-item">
                <a href="#" class="nav-link has-dropdown">
                    <i class="bi bi-person-lines-fill"></i>
                    <span>Customer Management</span>
                    <i class="bi bi-chevron-down dropdown-arrow"></i>
                </a>
                <ul class="submenu">
                    <li><a href="<?php echo $base_url; ?>/modules/customer/register.php">Register Customer</a></li>
                    <li><a href="<?php echo $base_url; ?>/modules/customer/view.php">View Customers</a></li>
                    <li><a href="<?php echo $base_url; ?>/modules/customer/search.php">Search Customer</a></li>
                    <li><a href="<?php echo $base_url; ?>/modules/customer/edit.php">Edit Customers</a></li>
                </ul>
            </li>

            <!-- Loan Management -->
            <li class="nav-item">
                <a href="#" class="nav-link has-dropdown">
                    <i class="bi bi-cash-stack"></i>
                    <span>Loan Management</span>
                    <i class="bi bi-chevron-down dropdown-arrow"></i>
                </a>
                <ul class="submenu">
                    <li><a href="<?php echo $base_url; ?>/modules/loans/new.php">New Loan Application</a></li>
                    <li><a href="<?php echo $base_url; ?>/modules/loans/index.php">Loan Overview</a></li>
                    <li><a href="<?php echo $base_url; ?>/modules/loans/approve.php">Approve Loans</a></li>
                    <li><a href="<?php echo $base_url; ?>/modules/loans/disbursement.php">Loan Disbursement</a></li>
                    <li><a href="<?php echo $base_url; ?>/modules/loans/view.php">All Loans</a></li>
                </ul>
            </li>

            <!-- CBO Management -->
            <li class="nav-item">
                <a href="#" class="nav-link has-dropdown">
                    <i class="bi bi-building"></i>
                    <span>CBO Management</span>
                    <i class="bi bi-chevron-down dropdown-arrow"></i>
                </a>
                <ul class="submenu">
                    <li><a href="<?php echo $base_url; ?>/modules/cbo/new.php">Create CBO</a></li>
                    <li><a href="<?php echo $base_url; ?>/modules/cbo/overview.php">CBO Overview</a></li>
                    <li><a href="<?php echo $base_url; ?>/modules/cbo/groups.php">Manage Groups</a></li>
                    <li><a href="<?php echo $base_url; ?>/modules/cbo/add_member.php">Manage Members</a></li>
                    <li><a href="<?php echo $base_url; ?>/modules/cbo/manage.php">Edit CBO</a></li>
                </ul>
            </li>

            <!-- Payment Management -->
            <li class="nav-item">
                <a href="#" class="nav-link has-dropdown">
                    <i class="bi bi-cash-coin"></i>
                    <span>Payment Management</span>
                    <i class="bi bi-chevron-down dropdown-arrow"></i>
                </a>
                <ul class="submenu">
                    <li><a href="<?php echo $base_url; ?>/modules/payment/Payment_dashboard.php">Payment Dashboard</a></li>
                    <li><a href="<?php echo $base_url; ?>/modules/payment/new_payment_entry.php">Payment Entry</a></li>
                    <li><a href="<?php echo $base_url; ?>/modules/payment/payment_history.php">Payment History</a></li>
                    <li><a href="<?php echo $base_url; ?>/modules/payment/payment_report.php">Payment Reports</a></li>
                </ul>
            </li>

            <!-- Branch Management -->
            <li class="nav-item">
                <a href="#" class="nav-link has-dropdown">
                    <i class="bi bi-diagram-3"></i>
                    <span>Branch Management</span>
                    <i class="bi bi-chevron-down dropdown-arrow"></i>
                </a>
                <ul class="submenu">
                    <li><a href="<?php echo $base_url; ?>/modules/branch/manage.php">Manage Branches</a></li>
                    <li><a href="<?php echo $base_url; ?>/modules/branch/add.php">Add Branch</a></li>
                    <li><a href="<?php echo $base_url; ?>/modules/branch/reports.php">Branch Reports</a></li>
                </ul>
            </li>

            <!-- Reports & Analytics -->
            <li class="nav-item">
                <a href="#" class="nav-link has-dropdown">
                    <i class="bi bi-graph-up"></i>
                    <span>Reports & Analytics</span>
                    <i class="bi bi-chevron-down dropdown-arrow"></i>
                </a>
                <ul class="submenu">
                    <li><a href="<?php echo $base_url; ?>/modules/reports/daily.php">Daily Reports</a></li>
                    <li><a href="<?php echo $base_url; ?>/modules/reports/loan.php">Loan Reports</a></li>
                    <li><a href="<?php echo $base_url; ?>/modules/reports/collection.php">Collection Reports</a></li>
                    <li><a href="<?php echo $base_url; ?>/modules/reports/performance.php">Performance Analytics</a></li>
                    <li><a href="<?php echo $base_url; ?>/modules/reports/financial.php">Financial Reports</a></li>
                </ul>
            </li>

            <!-- System Settings -->
            <li class="nav-item">
                <a href="#" class="nav-link has-dropdown">
                    <i class="bi bi-gear"></i>
                    <span>System Settings</span>
                    <i class="bi bi-chevron-down dropdown-arrow"></i>
                </a>
                <ul class="submenu">
                    <li><a href="<?php echo $base_url; ?>/modules/admin/system_settings.php">Profile Settings</a></li>
                    <li><a href="<?php echo $base_url; ?>/modules/settings/system.php">System Configuration</a></li>
                    <li><a href="<?php echo $base_url; ?>/modules/settings/backup.php">Backup & Restore</a></li>
                    <li><a href="<?php echo $base_url; ?>/modules/settings/audit.php">Audit Logs</a></li>
                </ul>
            </li>
        </ul>
    </div>

    <div class="sidebar-footer">
        <div class="user-info">
            <div class="user-avatar">
                <i class="bi bi-person-circle"></i>
            </div>
            <div class="user-details">
                <div class="user-name"><?php echo htmlspecialchars($user_name); ?></div>
                <div class="user-role">Administrator</div>
                <div class="user-branch"><?php echo htmlspecialchars($branch); ?></div>
            </div>
        </div>
        <a href="<?php echo $base_url; ?>/logout.php" class="logout-btn">
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>
    </div>
</div>

<style>
/* Sidebar Styles - FIXED */
.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    height: 100vh;
    width: 280px;
    background: linear-gradient(180deg, #1e3c72 0%, #2a5298 100%);
    color: white;
    z-index: 1000;
    transition: all 0.3s ease;
    box-shadow: 2px 0 10px rgba(0,0,0,0.1);
    display: flex;
    flex-direction: column;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

.sidebar-header {
    padding: 1.5rem 1rem;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: rgba(0,0,0,0.1);
}

.sidebar-logo {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.sidebar-logo i {
    font-size: 2rem;
    color: #4cc9f0;
}

.logo-text {
    font-size: 1.25rem;
    font-weight: 700;
    color: white;
}

.sidebar-toggle {
    background: none;
    border: none;
    color: white;
    font-size: 1.25rem;
    cursor: pointer;
    padding: 0.25rem;
    border-radius: 4px;
    transition: background-color 0.3s ease;
}

.sidebar-toggle:hover {
    background-color: rgba(255,255,255,0.1);
}

.sidebar-menu {
    flex: 1;
    overflow-y: auto;
    padding: 1rem 0;
}

.sidebar-nav {
    list-style: none;
    padding: 0;
    margin: 0;
}

.nav-item {
    margin-bottom: 0.25rem;
}

.nav-link {
    display: flex;
    align-items: center;
    padding: 0.75rem 1.5rem;
    color: rgba(255,255,255,0.8);
    text-decoration: none;
    transition: all 0.3s ease;
    border-left: 3px solid transparent;
    font-size: 0.95rem;
}

.nav-link:hover,
.nav-link.active {
    background-color: rgba(255,255,255,0.1);
    color: white;
    border-left-color: #4cc9f0;
}

.nav-link i:first-child {
    margin-right: 0.75rem;
    font-size: 1.1rem;
    width: 20px;
    text-align: center;
}

.nav-link.has-dropdown {
    justify-content: space-between;
}

.dropdown-arrow {
    transition: transform 0.3s ease;
    font-size: 0.8rem;
}

.nav-item.active .dropdown-arrow {
    transform: rotate(180deg);
}

.submenu {
    list-style: none;
    padding: 0;
    margin: 0;
    background-color: rgba(0,0,0,0.2);
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s ease;
}

.nav-item.active .submenu {
    max-height: 500px;
}

.submenu li a {
    display: block;
    padding: 0.6rem 1.5rem 0.6rem 3rem;
    color: rgba(255,255,255,0.7);
    text-decoration: none;
    transition: all 0.3s ease;
    font-size: 0.9rem;
    border-left: 3px solid transparent;
}

.submenu li a:hover {
    background-color: rgba(255,255,255,0.05);
    color: white;
    border-left-color: #43e97b;
}

.sidebar-footer {
    border-top: 1px solid rgba(255,255,255,0.1);
    padding: 1rem;
    background: rgba(0,0,0,0.1);
}

.user-info {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.user-avatar i {
    font-size: 2.5rem;
    color: #4cc9f0;
}

.user-details {
    flex: 1;
}

.user-name {
    font-weight: 600;
    font-size: 0.9rem;
    margin-bottom: 0.1rem;
}

.user-role {
    font-size: 0.8rem;
    color: #4cc9f0;
    font-weight: 500;
    margin-bottom: 0.1rem;
}

.user-branch {
    font-size: 0.75rem;
    color: rgba(255,255,255,0.7);
}

.logout-btn {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem;
    background-color: rgba(231, 76, 60, 0.2);
    color: white;
    text-decoration: none;
    border-radius: 6px;
    transition: all 0.3s ease;
    border: 1px solid rgba(231, 76, 60, 0.3);
    font-size: 0.9rem;
}

.logout-btn:hover {
    background-color: rgba(231, 76, 60, 0.3);
    color: white;
    text-decoration: none;
    transform: translateY(-1px);
}

/* Main Content Area */
.main-content {
    margin-left: 280px;
    padding: 20px;
    transition: all 0.3s ease;
    min-height: 100vh;
    background-color: #f8f9fa;
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .sidebar {
        transform: translateX(-100%);
        width: 280px;
    }
    
    .sidebar.mobile-open {
        transform: translateX(0);
        box-shadow: 2px 0 15px rgba(0,0,0,0.3);
    }
    
    .main-content {
        margin-left: 0 !important;
        padding: 15px;
    }
}

/* Scrollbar Styling */
.sidebar-menu::-webkit-scrollbar {
    width: 6px;
}

.sidebar-menu::-webkit-scrollbar-track {
    background: rgba(255,255,255,0.1);
    border-radius: 3px;
}

.sidebar-menu::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,0.3);
    border-radius: 3px;
}

.sidebar-menu::-webkit-scrollbar-thumb:hover {
    background: rgba(255,255,255,0.5);
}

/* Animation for menu items */
.nav-link {
    position: relative;
    overflow: hidden;
}

.nav-link::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
    transition: left 0.5s;
}

.nav-link:hover::before {
    left: 100%;
}
</style>

<script>
// Wait for DOM to be fully loaded
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.querySelector('.sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const dropdownLinks = document.querySelectorAll('.nav-link.has-dropdown');
    const mainContent = document.querySelector('.main-content');
    
    console.log('Sidebar script loaded'); // Debug log
    
    // Mobile sidebar toggle
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('mobile-open');
            console.log('Sidebar toggled'); // Debug log
        });
    }
    
    // Dropdown functionality
    dropdownLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const parentItem = this.parentElement;
            const isActive = parentItem.classList.contains('active');
            
            // Close all dropdowns first
            dropdownLinks.forEach(otherLink => {
                otherLink.parentElement.classList.remove('active');
            });
            
            // Toggle current dropdown
            if (!isActive) {
                parentItem.classList.add('active');
            }
            
            console.log('Dropdown clicked'); // Debug log
        });
    });
    
    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(e) {
        if (window.innerWidth <= 768) {
            if (!sidebar.contains(e.target) && !e.target.matches('.sidebar-toggle')) {
                sidebar.classList.remove('mobile-open');
            }
        }
    });
    
    // Close dropdowns when clicking on main content
    if (mainContent) {
        mainContent.addEventListener('click', function() {
            if (window.innerWidth <= 768) {
                sidebar.classList.remove('mobile-open');
            }
            dropdownLinks.forEach(link => {
                link.parentElement.classList.remove('active');
            });
        });
    }
    
    // Set active menu item based on current page
    function setActiveMenu() {
        const currentPage = window.location.pathname.split('/').pop();
        const navLinks = document.querySelectorAll('.nav-link, .submenu a');
        
        console.log('Current page:', currentPage); // Debug log
        
        navLinks.forEach(link => {
            link.classList.remove('active');
            const href = link.getAttribute('href');
            
            if (href && href.includes(currentPage)) {
                link.classList.add('active');
                console.log('Active link found:', href); // Debug log
                
                // Open parent dropdown if it's a submenu item
                const parentDropdown = link.closest('.submenu')?.previousElementSibling;
                if (parentDropdown && parentDropdown.classList.contains('has-dropdown')) {
                    parentDropdown.parentElement.classList.add('active');
                    console.log('Parent dropdown activated'); // Debug log
                }
            }
        });
    }
    
    // Initialize
    setActiveMenu();
    
    // Add resize handler
    window.addEventListener('resize', function() {
        if (window.innerWidth > 768) {
            sidebar.classList.remove('mobile-open');
        }
    });
});

// Additional JavaScript for smooth interactions
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    sidebar.classList.toggle('mobile-open');
}

// Export functions for global access
window.toggleSidebar = toggleSidebar;
</script>