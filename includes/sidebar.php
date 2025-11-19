<?php
// includes/sidebar.php

// Start session if not started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include config for constants
$config_path = __DIR__ . '/../config/config.php';
if (file_exists($config_path)) {
    require_once $config_path;
}

// Include sidebar functions
$sidebar_functions_path = __DIR__ . '/sidebar_functions.php';
if (file_exists($sidebar_functions_path)) {
    require_once $sidebar_functions_path;
}
?>

<!-- Sidebar -->
<div class="sidebar">
    <div class="sidebar-header">
        <div class="brand-section">
            <div class="logo-icon">
                <i class="bi bi-bank"></i>
            </div>
            <div class="brand-text">
                <h4 class="mb-0"><?php echo defined('SITE_NAME') ? SITE_NAME : 'Micro Finance'; ?></h4>
                <small class="version">v<?php echo defined('SITE_VERSION') ? SITE_VERSION : '1.0.0'; ?></small>
            </div>
        </div>
        <div class="user-greeting">
            <i class="bi bi-person-circle me-2"></i>
            <span>
                <?php 
                if (function_exists('getUserSpecificGreeting')) {
                    echo getUserSpecificGreeting();
                } else {
                    echo isset($_SESSION['user_name']) ? "Hi, " . $_SESSION['user_name'] : "Welcome";
                }
                ?>
            </span>
        </div>
    </div>
    
    <div class="sidebar-menu">
        <?php 
        if (function_exists('generateSidebar')) {
            echo generateSidebar();
        } else {
            // Fallback menu
            echo '
            <div class="sidebar-menu-parent">
                <a href="' . (defined('BASE_URL') ? BASE_URL : '') . '/modules/admin/dashboard.php" class="sidebar-menu-item">
                    <div class="menu-icon">
                        <i class="bi bi-speedometer2"></i>
                    </div>
                    <span class="menu-text">Dashboard</span>
                    <div class="menu-arrow">
                        <i class="bi bi-chevron-right"></i>
                    </div>
                </a>
            </div>
            <div class="sidebar-menu-parent">
                <a href="' . (defined('BASE_URL') ? BASE_URL : '') . '/modules/admin/manage_sidebar.php" class="sidebar-menu-item">
                    <div class="menu-icon">
                        <i class="bi bi-menu-button-wide"></i>
                    </div>
                    <span class="menu-text">Manage Menu</span>
                    <div class="menu-arrow">
                        <i class="bi bi-chevron-right"></i>
                    </div>
                </a>
            </div>';
        }
        ?>
        
        <!-- Logout Section -->
        <div class="sidebar-footer">
            <div class="sidebar-header-section">Account</div>
            <a href="<?php echo defined('BASE_URL') ? BASE_URL : ''; ?>/logout.php" class="sidebar-menu-item logout-item">
                <div class="menu-icon">
                    <i class="bi bi-box-arrow-right"></i>
                </div>
                <span class="menu-text">Logout</span>
                <div class="user-info"><?php echo $_SESSION['user_name'] ?? 'User'; ?></div>
            </a>
        </div>
    </div>
</div>

<style>
/* Modern Sidebar Styles */
.sidebar {
    width: 280px;
    height: 100vh;
    background: linear-gradient(180deg, #1a1b2e 0%, #2b2d42 100%);
    color: white;
    overflow-y: auto;
    position: fixed;
    left: 0;
    top: 0;
    z-index: 1000;
    border-right: 1px solid rgba(255,255,255,0.1);
    box-shadow: 4px 0 20px rgba(0, 0, 0, 0.3);
    transition: all 0.3s ease;
}

.sidebar-header {
    padding: 25px 20px;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    background: rgba(255,255,255,0.05);
    backdrop-filter: blur(10px);
}

.brand-section {
    display: flex;
    align-items: center;
    margin-bottom: 15px;
}

.logo-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #4361ee, #3a0ca3);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 12px;
    font-size: 1.2rem;
}

.brand-text h4 {
    font-weight: 700;
    font-size: 1.1rem;
    margin-bottom: 2px;
}

.version {
    font-size: 0.7rem;
    opacity: 0.7;
    font-weight: 500;
}

.user-greeting {
    display: flex;
    align-items: center;
    font-size: 0.9rem;
    opacity: 0.9;
    padding: 8px 12px;
    background: rgba(255,255,255,0.05);
    border-radius: 8px;
    border: 1px solid rgba(255,255,255,0.1);
}

.sidebar-menu {
    padding: 20px 0;
    height: calc(100vh - 180px);
    overflow-y: auto;
}

.sidebar-footer {
    margin-top: auto;
    padding-top: 15px;
    border-top: 1px solid rgba(255,255,255,0.1);
}

.sidebar-header-section {
    padding: 10px 20px 8px 20px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    color: rgba(255,255,255,0.5);
    letter-spacing: 1px;
}

.sidebar-menu-parent {
    position: relative;
    margin-bottom: 2px;
}

.sidebar-menu-item {
    color: rgba(255,255,255,0.8);
    text-decoration: none;
    padding: 12px 20px;
    display: flex;
    align-items: center;
    transition: all 0.3s ease;
    position: relative;
    cursor: pointer;
    border-left: 3px solid transparent;
}

.sidebar-menu-item:hover {
    background: rgba(255,255,255,0.08);
    color: white;
    border-left-color: #4361ee;
    transform: translateX(5px);
}

.sidebar-menu-item.active {
    background: linear-gradient(90deg, rgba(67, 97, 238, 0.2), transparent);
    color: white;
    border-left-color: #4361ee;
}

.sidebar-menu-item.active::before {
    content: '';
    position: absolute;
    right: 0;
    top: 0;
    height: 100%;
    width: 3px;
    background: #4361ee;
}

.menu-icon {
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 12px;
    font-size: 1.1rem;
    opacity: 0.8;
}

.menu-text {
    flex: 1;
    font-weight: 500;
    font-size: 0.9rem;
}

.menu-arrow {
    opacity: 0.5;
    font-size: 0.8rem;
    transition: transform 0.3s ease;
}

.sidebar-menu-item:hover .menu-arrow {
    transform: translateX(3px);
    opacity: 1;
}

.logout-item {
    color: #ff6b6b !important;
    border-left-color: transparent !important;
}

.logout-item:hover {
    background: rgba(255, 107, 107, 0.1) !important;
    color: #ff6b6b !important;
    border-left-color: #ff6b6b !important;
}

/* Submenu Styles */
.sidebar-submenu {
    margin-left: 20px;
    border-left: 2px solid rgba(255,255,255,0.1);
    animation: slideDown 0.3s ease;
    background: rgba(0,0,0,0.1);
    display: block !important; /* Ensure submenu is always visible */
}

.sidebar-submenu-item {
    padding: 10px 20px 10px 45px !important;
    font-size: 0.85rem;
    border-left: 3px solid transparent;
    color: rgba(255,255,255,0.7);
    display: block !important; /* Ensure submenu items are visible */
}

.sidebar-submenu-item:hover {
    background: rgba(255,255,255,0.05);
    color: white;
    border-left-color: rgba(255,255,255,0.3);
}

.sidebar-submenu-item.active {
    background: rgba(255,255,255,0.08);
    color: white;
    border-left-color: #4cc9f0;
}

.user-info {
    font-size: 0.75rem;
    opacity: 0.6;
    margin-left: auto;
    padding-left: 10px;
}

/* Scrollbar styling */
.sidebar-menu::-webkit-scrollbar {
    width: 6px;
}

.sidebar-menu::-webkit-scrollbar-track {
    background: rgba(255,255,255,0.05);
    border-radius: 3px;
}

.sidebar-menu::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,0.2);
    border-radius: 3px;
}

.sidebar-menu::-webkit-scrollbar-thumb:hover {
    background: rgba(255,255,255,0.3);
}

/* Submenu Styles */
.sidebar-submenu {
    margin-left: 20px;
    border-left: 2px solid rgba(255,255,255,0.1);
    animation: slideDown 0.3s ease;
    background: rgba(0,0,0,0.1);
}

.sidebar-submenu-item {
    padding: 10px 20px 10px 45px !important;
    font-size: 0.85rem;
    border-left: 3px solid transparent;
    color: rgba(255,255,255,0.7);
}

.sidebar-submenu-item:hover {
    background: rgba(255,255,255,0.05);
    color: white;
    border-left-color: rgba(255,255,255,0.3);
}

.sidebar-submenu-item.active {
    background: rgba(255,255,255,0.08);
    color: white;
    border-left-color: #4cc9f0;
}

/* Animations */
@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateX(-10px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .sidebar {
        transform: translateX(-100%);
        width: 280px;
    }
    
    .sidebar.active {
        transform: translateX(0);
        box-shadow: 4px 0 30px rgba(0, 0, 0, 0.5);
    }
}

/* Ensure main content has proper margin */
.main-content {
    margin-left: 280px;
    transition: margin-left 0.3s ease;
}

@media (max-width: 768px) {
    .main-content {
        margin-left: 0;
    }
}
</style>