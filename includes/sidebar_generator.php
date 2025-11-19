<?php
// includes/sidebar_generator.php

function generateSidebarMenu() {
    global $conn;
    
    if (!isset($_SESSION['user_type'])) {
        return '';
    }
    
    $user_type = $_SESSION['user_type'];
    
    // Define menu structure with icons and permissions
    $menu_structure = [
        'dashboard' => [
            'title' => 'Dashboard',
            'icon' => 'speedometer2',
            'path' => '/dashboard.php',
            'always_show' => true
        ],
        
        'management' => [
            'title' => 'Management',
            'icon' => 'gear',
            'type' => 'header',
            'children' => [
                'customers' => [
                    'title' => 'Customers',
                    'icon' => 'people',
                    'path' => '/modules/customers/',
                    'children' => [
                        'add_customer' => [
                            'title' => 'Add Customer',
                            'icon' => 'person-plus',
                            'path' => '/modules/customers/add_customer.php'
                        ],
                        'list_customers' => [
                            'title' => 'List Customers', 
                            'icon' => 'list',
                            'path' => '/modules/customers/list_customers.php'
                        ]
                    ]
                ],
                'loans' => [
                    'title' => 'Loans',
                    'icon' => 'cash-coin',
                    'path' => '/modules/loans/',
                    'children' => [
                        'add_loan' => [
                            'title' => 'Add Loan',
                            'icon' => 'plus-circle',
                            'path' => '/modules/loans/add_loan.php'
                        ],
                        'list_loans' => [
                            'title' => 'List Loans',
                            'icon' => 'list',
                            'path' => '/modules/loans/list_loans.php'
                        ],
                        'loan_payments' => [
                            'title' => 'Loan Payments',
                            'icon' => 'credit-card',
                            'path' => '/modules/loans/payments.php'
                        ]
                    ]
                ],
                'groups' => [
                    'title' => 'Groups',
                    'icon' => 'collection',
                    'path' => '/modules/groups/',
                    'children' => [
                        'add_group' => [
                            'title' => 'Add Group',
                            'icon' => 'plus-circle',
                            'path' => '/modules/groups/add_group.php'
                        ],
                        'list_groups' => [
                            'title' => 'List Groups',
                            'icon' => 'list',
                            'path' => '/modules/groups/list_groups.php'
                        ]
                    ]
                ],
                'cbo' => [
                    'title' => 'CBO Centers',
                    'icon' => 'building',
                    'path' => '/modules/cbo/',
                    'children' => [
                        'add_cbo' => [
                            'title' => 'Add CBO',
                            'icon' => 'plus-circle',
                            'path' => '/modules/cbo/add_cbo.php'
                        ],
                        'list_cbo' => [
                            'title' => 'List CBOs',
                            'icon' => 'list',
                            'path' => '/modules/cbo/list_cbo.php'
                        ]
                    ]
                ]
            ]
        ],
        
        'reports' => [
            'title' => 'Reports',
            'icon' => 'graph-up',
            'type' => 'header', 
            'children' => [
                'center_reports' => [
                    'title' => 'Center Reports',
                    'icon' => 'file-text',
                    'path' => '/modules/reports/center_report.php'
                ],
                'all_reports' => [
                    'title' => 'All Reports',
                    'icon' => 'files',
                    'path' => '/modules/reports/'
                ],
                'financial_reports' => [
                    'title' => 'Financial Reports',
                    'icon' => 'bar-chart',
                    'path' => '/modules/reports/financial_reports.php'
                ]
            ]
        ],
        
        'administration' => [
            'title' => 'Administration',
            'icon' => 'shield-lock',
            'type' => 'header',
            'admin_only' => true,
            'children' => [
                'user_management' => [
                    'title' => 'User Management',
                    'icon' => 'person-badge',
                    'path' => '/modules/users/',
                    'children' => [
                        'add_user' => [
                            'title' => 'Add User',
                            'icon' => 'person-plus',
                            'path' => '/modules/users/add_user.php'
                        ],
                        'list_users' => [
                            'title' => 'List Users',
                            'icon' => 'list',
                            'path' => '/modules/users/list_users.php'
                        ]
                    ]
                ],
                'permissions' => [
                    'title' => 'Permissions',
                    'icon' => 'key',
                    'path' => '/modules/admin/manage_permissions.php'
                ],
                'system_settings' => [
                    'title' => 'System Settings',
                    'icon' => 'sliders',
                    'path' => '/modules/admin/settings.php'
                ]
            ]
        ]
    ];
    
    return buildSidebarHTML($menu_structure, $user_type);
}

function buildSidebarHTML($menu_structure, $user_type) {
    $html = '';
    $current_page = $_SERVER['PHP_SELF'];
    
    foreach ($menu_structure as $key => $item) {
        // Check if user has access to this menu item
        if (!shouldShowMenuItem($item, $user_type)) {
            continue;
        }
        
        if (isset($item['type']) && $item['type'] === 'header') {
            // Menu header
            $html .= '<div class="px-3 mt-3 mb-2 text-uppercase small opacity-75">';
            $html .= '<i class="bi bi-' . $item['icon'] . ' me-2"></i>' . $item['title'];
            $html .= '</div>';
            
            // Add children
            if (isset($item['children'])) {
                $html .= buildMenuItems($item['children'], $user_type, $current_page);
            }
        } else {
            // Single menu item
            $is_active = ($current_page === $item['path'] || strpos($current_page, $item['path']) !== false);
            $active_class = $is_active ? 'active' : '';
            
            $html .= '<a href="' . BASE_URL . $item['path'] . '" class="sidebar-menu-item ' . $active_class . '">';
            $html .= '<i class="bi bi-' . $item['icon'] . ' me-3"></i>' . $item['title'];
            $html .= '</a>';
            
            // Add children if exists
            if (isset($item['children'])) {
                $html .= '<div class="sidebar-submenu">';
                $html .= buildMenuItems($item['children'], $user_type, $current_page, true);
                $html .= '</div>';
            }
        }
    }
    
    // Add logout button
    $html .= '<div class="px-3 mt-3 mb-2 text-uppercase small opacity-75">Account</div>';
    $html .= '<a href="' . BASE_URL . '/logout.php" class="sidebar-menu-item text-warning">';
    $html .= '<i class="bi bi-box-arrow-right me-3"></i>Logout';
    $html .= '<small class="opacity-75 d-block mt-1">' . $_SESSION['user_name'] . '</small>';
    $html .= '</a>';
    
    return $html;
}

function buildMenuItems($items, $user_type, $current_page, $is_submenu = false) {
    $html = '';
    
    foreach ($items as $key => $item) {
        if (!shouldShowMenuItem($item, $user_type)) {
            continue;
        }
        
        $is_active = ($current_page === $item['path'] || strpos($current_page, $item['path']) !== false);
        $active_class = $is_active ? 'active' : '';
        $submenu_class = $is_submenu ? 'sidebar-submenu-item' : 'sidebar-menu-item';
        
        $html .= '<a href="' . BASE_URL . $item['path'] . '" class="' . $submenu_class . ' ' . $active_class . '">';
        $html .= '<i class="bi bi-' . $item['icon'] . ' me-3"></i>' . $item['title'];
        $html .= '</a>';
        
        // Add children if exists
        if (isset($item['children'])) {
            $html .= '<div class="sidebar-submenu">';
            $html .= buildMenuItems($item['children'], $user_type, $current_page, true);
            $html .= '</div>';
        }
    }
    
    return $html;
}

function shouldShowMenuItem($item, $user_type) {
    // Always show if marked as always_show
    if (isset($item['always_show']) && $item['always_show']) {
        return true;
    }
    
    // Admin only items
    if (isset($item['admin_only']) && $item['admin_only'] && $user_type !== 'admin') {
        return false;
    }
    
    // Check permissions for the path
    if (isset($item['path'])) {
        return hasAccess($item['path']);
    }
    
    // For items with children, show if any child is accessible
    if (isset($item['children'])) {
        foreach ($item['children'] as $child) {
            if (shouldShowMenuItem($child, $user_type)) {
                return true;
            }
        }
        return false;
    }
    
    return true;
}

// Function to scan and auto-update menu structure based on existing files
function autoUpdateMenuStructure() {
    global $conn;
    
    if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
        return;
    }
    
    $folders_to_scan = [
        'modules/customers',
        'modules/loans',
        'modules/groups', 
        'modules/cbo',
        'modules/reports',
        'modules/users',
        'modules/admin'
    ];
    
    $new_files = [];
    
    foreach ($folders_to_scan as $folder) {
        $folder_path = __DIR__ . '/../' . $folder;
        
        if (is_dir($folder_path)) {
            $files = scandir($folder_path);
            
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                    $file_path = '/' . $folder . '/' . $file;
                    
                    // Check if this file exists in permissions table
                    $check_sql = "SELECT COUNT(*) as count FROM user_permissions WHERE page_path = ?";
                    $check_stmt = $conn->prepare($check_sql);
                    $check_stmt->bind_param("s", $file_path);
                    $check_stmt->execute();
                    $result = $check_stmt->get_result();
                    $count = $result->fetch_assoc()['count'];
                    
                    if ($count == 0) {
                        $new_files[] = [
                            'path' => $file_path,
                            'name' => $file,
                            'folder' => $folder
                        ];
                    }
                }
            }
        }
    }
    
    return $new_files;
}
?>