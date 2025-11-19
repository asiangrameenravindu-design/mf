<?php
// includes/sidebar_functions.php

function getUserSpecificGreeting() {
    if (isset($_SESSION['user_name'])) {
        $hour = date('H');
        if ($hour < 12) {
            return "Good Morning, " . $_SESSION['user_name'];
        } elseif ($hour < 17) {
            return "Good Afternoon, " . $_SESSION['user_name'];
        } else {
            return "Good Evening, " . $_SESSION['user_name'];
        }
    }
    return "Welcome";
}

function generateSidebar() {
    $user_type = $_SESSION['user_type'] ?? 'user';
    $user_id = $_SESSION['user_id'] ?? 0;
    
    $html = '';
    
    // Get main menu items for this user type
    $main_menu_items = getMenuItemsByUserType($user_type, 0);
    
    foreach ($main_menu_items as $menu_item) {
        $sub_menu_items = getSubMenuItems($menu_item['id'], $user_type);
        $has_submenu = !empty($sub_menu_items);
        $is_active = isMenuActive($menu_item['file_path']);
        
        $html .= '<div class="sidebar-menu-parent">';
        $html .= '<a href="' . ($has_submenu ? '#' : BASE_URL . '/' . $menu_item['file_path']) . '" ';
        $html .= 'class="sidebar-menu-item ' . ($is_active ? 'active' : '') . '" ';
        if ($has_submenu) {
            $html .= 'onclick="toggleSubmenu(this)"';
        }
        $html .= '>';
        $html .= '<i class="' . ($menu_item['icon'] ?? 'bi bi-file-earmark') . ' me-3"></i>';
        $html .= htmlspecialchars($menu_item['description'] ?? $menu_item['file_path']);
        if ($has_submenu) {
            $html .= '<i class="bi bi-chevron-down float-end"></i>';
        }
        $html .= '</a>';
        
        // Add submenu if exists
        if ($has_submenu) {
            $html .= '<div class="sidebar-submenu" style="display: ' . ($is_active ? 'block' : 'none') . ';">';
            foreach ($sub_menu_items as $sub_item) {
                $is_sub_active = isMenuActive($sub_item['file_path']);
                $html .= '<a href="' . BASE_URL . '/' . $sub_item['file_path'] . '" ';
                $html .= 'class="sidebar-submenu-item ' . ($is_sub_active ? 'active' : '') . '">';
                $html .= '<i class="' . ($sub_item['icon'] ?? 'bi bi-circle') . ' me-2"></i>';
                $html .= htmlspecialchars($sub_item['description'] ?? $sub_item['file_path']);
                $html .= '</a>';
            }
            $html .= '</div>';
        }
        
        $html .= '</div>';
    }
    
    // If no menu items found, show default items
    if (empty($main_menu_items)) {
        $html = getDefaultMenuItems($user_type);
    }
    
    return $html;
}

function getMenuItemsByUserType($user_type, $parent_id = 0) {
    global $conn;
    
    // Check if permissions table exists
    $table_exists = $conn->query("SHOW TABLES LIKE 'permissions'");
    if ($table_exists->num_rows == 0) {
        return [];
    }
    
    $stmt = $conn->prepare("
        SELECT p.* 
        FROM permissions p 
        WHERE p.user_type = ? AND p.parent_id = ? AND p.status = 'active'
        ORDER BY p.menu_order ASC
    ");
    
    if (!$stmt) {
        return [];
    }
    
    $stmt->bind_param("si", $user_type, $parent_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $menu_items = [];
    while ($row = $result->fetch_assoc()) {
        $menu_items[] = $row;
    }
    
    return $menu_items;
}

function getSubMenuItems($parent_id, $user_type) {
    global $conn;
    
    // Check if permissions table exists
    $table_exists = $conn->query("SHOW TABLES LIKE 'permissions'");
    if ($table_exists->num_rows == 0) {
        return [];
    }
    
    $stmt = $conn->prepare("
        SELECT p.* 
        FROM permissions p 
        WHERE p.user_type = ? AND p.parent_id = ? AND p.is_submenu = 1 AND p.status = 'active'
        ORDER BY p.menu_order ASC
    ");
    
    if (!$stmt) {
        return [];
    }
    
    $stmt->bind_param("si", $user_type, $parent_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $sub_items = [];
    while ($row = $result->fetch_assoc()) {
        $sub_items[] = $row;
    }
    
    return $sub_items;
}

function isMenuActive($file_path) {
    $current_file = basename($_SERVER['PHP_SELF']);
    $menu_file = basename($file_path);
    
    return $current_file === $menu_file;
}

function getDefaultMenuItems($user_type) {
    $html = '';
    
    // Default menu items based on user type
    switch ($user_type) {
        case 'admin':
            $html .= '
            <div class="sidebar-menu-parent">
                <a href="' . BASE_URL . '/modules/admin/dashboard.php" class="sidebar-menu-item ' . (isMenuActive('dashboard.php') ? 'active' : '') . '">
                    <i class="bi bi-speedometer2 me-3"></i>Dashboard
                </a>
            </div>
            <div class="sidebar-menu-parent">
                <a href="' . BASE_URL . '/modules/admin/manage_sidebar.php" class="sidebar-menu-item ' . (isMenuActive('manage_sidebar.php') ? 'active' : '') . '">
                    <i class="bi bi-menu-button-wide me-3"></i>Manage Menu
                </a>
            </div>
            <div class="sidebar-menu-parent">
                <a href="' . BASE_URL . '/modules/admin/users.php" class="sidebar-menu-item ' . (isMenuActive('users.php') ? 'active' : '') . '">
                    <i class="bi bi-people me-3"></i>User Management
                </a>
            </div>';
            break;
            
        case 'manager':
            $html .= '
            <div class="sidebar-menu-parent">
                <a href="' . BASE_URL . '/modules/manager/dashboard.php" class="sidebar-menu-item ' . (isMenuActive('dashboard.php') ? 'active' : '') . '">
                    <i class="bi bi-speedometer2 me-3"></i>Dashboard
                </a>
            </div>
            <div class="sidebar-menu-parent">
                <a href="' . BASE_URL . '/modules/manager/loans.php" class="sidebar-menu-item ' . (isMenuActive('loans.php') ? 'active' : '') . '">
                    <i class="bi bi-cash-coin me-3"></i>Loan Management
                </a>
            </div>';
            break;
            
        default:
            $html .= '
            <div class="sidebar-menu-parent">
                <a href="' . BASE_URL . '/modules/user/dashboard.php" class="sidebar-menu-item ' . (isMenuActive('dashboard.php') ? 'active' : '') . '">
                    <i class="bi bi-speedometer2 me-3"></i>Dashboard
                </a>
            </div>
            <div class="sidebar-menu-parent">
                <a href="' . BASE_URL . '/modules/user/profile.php" class="sidebar-menu-item ' . (isMenuActive('profile.php') ? 'active' : '') . '">
                    <i class="bi bi-person me-3"></i>My Profile
                </a>
            </div>';
            break;
    }
    
    return $html;
}
?>

<script>
function toggleSubmenu(element) {
    event.preventDefault();
    
    const parent = element.parentElement;
    const submenu = parent.querySelector('.sidebar-submenu');
    const icon = element.querySelector('.bi-chevron-down');
    
    if (!submenu) return;
    
    if (submenu.style.display === 'block') {
        submenu.style.display = 'none';
        if (icon) {
            icon.classList.remove('bi-chevron-up');
            icon.classList.add('bi-chevron-down');
        }
    } else {
        // Close other open submenus
        document.querySelectorAll('.sidebar-submenu').forEach(sub => {
            sub.style.display = 'none';
        });
        document.querySelectorAll('.bi-chevron-down').forEach(icn => {
            icn.classList.remove('bi-chevron-up');
            icn.classList.add('bi-chevron-down');
        });
        
        submenu.style.display = 'block';
        if (icon) {
            icon.classList.remove('bi-chevron-down');
            icon.classList.add('bi-chevron-up');
        }
    }
}

// Auto-close submenus when clicking outside
document.addEventListener('click', function(event) {
    if (!event.target.closest('.sidebar-menu-parent')) {
        document.querySelectorAll('.sidebar-submenu').forEach(sub => {
            sub.style.display = 'none';
        });
        document.querySelectorAll('.bi-chevron-down').forEach(icn => {
            icn.classList.remove('bi-chevron-up');
            icn.classList.add('bi-chevron-down');
        });
    }
});
</script>