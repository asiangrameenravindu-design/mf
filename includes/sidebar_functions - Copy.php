<?php
// includes/sidebar_functions.php

function generateSidebar() {
    if (!isset($_SESSION['user_type'])) {
        return '';
    }
    
    $user_type = $_SESSION['user_type'];
    $current_page = $_SERVER['PHP_SELF'];
    
    // Complete menu structure with sub-menus
    $menu_structure = [
        'dashboard' => [
            'title' => 'Dashboard',
            'icon' => 'speedometer2',
            'path' => '/dashboard.php',
            'access' => ['admin', 'manager', 'field_officer', 'accountant']
        ],
        
        // Management Section
        'management' => [
            'title' => 'Management',
            'icon' => 'gear',
            'type' => 'header',
            'access' => ['admin', 'manager', 'field_officer', 'accountant'],
            'children' => [
                'customers' => [
                    'title' => 'Customers',
                    'icon' => 'people',
                    'path' => '/modules/customers/',
                    'access' => ['admin', 'manager', 'field_officer', 'accountant'],
                    'children' => [
                        'add_customer' => [
                            'title' => 'Add Customer',
                            'icon' => 'person-plus',
                            'path' => '/modules/customers/add_customer.php',
                            'access' => ['admin', 'manager', 'field_officer']
                        ],
                        'list_customers' => [
                            'title' => 'List Customers',
                            'icon' => 'list',
                            'path' => '/modules/customers/list_customers.php',
                            'access' => ['admin', 'manager', 'field_officer', 'accountant']
                        ],
                        'view_customers' => [
                            'title' => 'View Customers',
                            'icon' => 'eye',
                            'path' => '/modules/customers/view_customers.php',
                            'access' => ['admin', 'manager', 'field_officer', 'accountant']
                        ]
                    ]
                ],
                
                'loans' => [
                    'title' => 'Loans',
                    'icon' => 'cash-coin',
                    'path' => '/modules/loans/',
                    'access' => ['manager', 'field_officer', 'accountant'],
                    'children' => [
                        'add_loan' => [
                            'title' => 'Add Loan',
                            'icon' => 'plus-circle',
                            'path' => '/modules/loans/add_loan.php',
                            'access' => ['manager', 'field_officer']
                        ],
                        'list_loans' => [
                            'title' => 'List Loans',
                            'icon' => 'list',
                            'path' => '/modules/loans/list_loans.php',
                            'access' => ['manager', 'field_officer', 'accountant']
                        ],
                        'loan_payments' => [
                            'title' => 'Loan Payments',
                            'icon' => 'credit-card',
                            'path' => '/modules/loans/payments.php',
                            'access' => ['manager', 'field_officer', 'accountant']
                        ],
                        'approve_loans' => [
                            'title' => 'Approve Loans',
                            'icon' => 'check-circle',
                            'path' => '/modules/loans/approve_loans.php',
                            'access' => ['manager']
                        ]
                    ]
                ],
                
                'groups' => [
                    'title' => 'Groups',
                    'icon' => 'collection',
                    'path' => '/modules/groups/',
                    'access' => ['admin', 'manager', 'field_officer', 'accountant'],
                    'children' => [
                        'add_group' => [
                            'title' => 'Add Group',
                            'icon' => 'plus-circle',
                            'path' => '/modules/groups/add_group.php',
                            'access' => ['admin', 'manager', 'field_officer']
                        ],
                        'list_groups' => [
                            'title' => 'List Groups',
                            'icon' => 'list',
                            'path' => '/modules/groups/list_groups.php',
                            'access' => ['admin', 'manager', 'field_officer', 'accountant']
                        ],
                        'group_members' => [
                            'title' => 'Group Members',
                            'icon' => 'people',
                            'path' => '/modules/groups/members.php',
                            'access' => ['admin', 'manager', 'field_officer', 'accountant']
                        ]
                    ]
                ],
                
                'cbo' => [
                    'title' => 'CBO Centers',
                    'icon' => 'building',
                    'path' => '/modules/cbo/',
                    'access' => ['admin', 'field_officer', 'accountant'],
                    'children' => [
                        'add_cbo' => [
                            'title' => 'Add CBO',
                            'icon' => 'plus-circle',
                            'path' => '/modules/cbo/add_cbo.php',
                            'access' => ['admin', 'field_officer']
                        ],
                        'list_cbo' => [
                            'title' => 'List CBOs',
                            'icon' => 'list',
                            'path' => '/modules/cbo/list_cbo.php',
                            'access' => ['admin', 'field_officer', 'accountant']
                        ],
                        'cbo_reports' => [
                            'title' => 'CBO Reports',
                            'icon' => 'file-text',
                            'path' => '/modules/cbo/reports.php',
                            'access' => ['admin', 'field_officer', 'accountant']
                        ]
                    ]
                ]
            ]
        ],
        
        // Reports Section
        'reports' => [
            'title' => 'Reports',
            'icon' => 'graph-up',
            'type' => 'header',
            'access' => ['admin', 'manager', 'field_officer', 'accountant'],
            'children' => [
                'center_reports' => [
                    'title' => 'Center Reports',
                    'icon' => 'file-text',
                    'path' => '/modules/reports/center_report.php',
                    'access' => ['admin', 'manager', 'field_officer', 'accountant']
                ],
                'financial_reports' => [
                    'title' => 'Financial Reports',
                    'icon' => 'bar-chart',
                    'path' => '/modules/reports/financial_reports.php',
                    'access' => ['admin', 'manager', 'accountant']
                ],
                'collection_reports' => [
                    'title' => 'Collection Reports',
                    'icon' => 'cash-stack',
                    'path' => '/modules/reports/collection_reports.php',
                    'access' => ['admin', 'manager', 'field_officer', 'accountant']
                ],
                'performance_reports' => [
                    'title' => 'Performance Reports',
                    'icon' => 'speedometer',
                    'path' => '/modules/reports/performance_reports.php',
                    'access' => ['admin', 'manager']
                ]
            ]
        ],
        
        // Administration Section (Admin only)
        'administration' => [
            'title' => 'Administration',
            'icon' => 'shield-lock',
            'type' => 'header',
            'access' => ['admin'],
            'children' => [
                'user_management' => [
                    'title' => 'User Management',
                    'icon' => 'person-badge',
                    'path' => '/modules/users/',
                    'access' => ['admin'],
                    'children' => [
                        'add_user' => [
                            'title' => 'Add User',
                            'icon' => 'person-plus',
                            'path' => '/modules/users/add_user.php',
                            'access' => ['admin']
                        ],
                        'list_users' => [
                            'title' => 'List Users',
                            'icon' => 'list',
                            'path' => '/modules/users/list_users.php',
                            'access' => ['admin']
                        ]
                    ]
                ],
                'permissions' => [
                    'title' => 'Permissions',
                    'icon' => 'key',
                    'path' => '/modules/admin/manage_permissions.php',
                    'access' => ['admin']
                ],
                'system_settings' => [
                    'title' => 'System Settings',
                    'icon' => 'sliders',
                    'path' => '/modules/admin/settings.php',
                    'access' => ['admin']
                ],
                'backup' => [
                    'title' => 'Backup & Restore',
                    'icon' => 'cloud-arrow-down',
                    'path' => '/modules/admin/backup.php',
                    'access' => ['admin']
                ]
            ]
        ]
    ];
    
    return buildSidebarHTML($menu_structure, $user_type, $current_page);
}

function buildSidebarHTML($menu_structure, $user_type, $current_page) {
    $html = '';
    
    foreach ($menu_structure as $key => $item) {
        // Check if user has access to this menu item
        if (!in_array($user_type, $item['access'])) {
            continue;
        }
        
        if (isset($item['type']) && $item['type'] === 'header') {
            // Menu header
            $html .= '<div class="sidebar-header-section">';
            $html .= '<i class="bi bi-' . $item['icon'] . ' me-2"></i>' . $item['title'];
            $html .= '</div>';
            
            // Add children
            if (isset($item['children'])) {
                $html .= buildMenuItems($item['children'], $user_type, $current_page);
            }
        } else {
            // Single menu item
            $is_active = isCurrentPage($item['path'], $current_page);
            $active_class = $is_active ? 'active' : '';
            
            $html .= '<a href="' . BASE_URL . $item['path'] . '" class="sidebar-menu-item ' . $active_class . '">';
            $html .= '<i class="bi bi-' . $item['icon'] . ' me-3"></i>' . $item['title'];
            $html .= '</a>';
            
            // Add children if exists
            if (isset($item['children'])) {
                $html .= buildMenuItems($item['children'], $user_type, $current_page, true);
            }
        }
    }
    
    return $html;
}

function buildMenuItems($items, $user_type, $current_page, $is_submenu = false) {
    $html = '';
    $has_accessible_items = false;
    
    foreach ($items as $key => $item) {
        if (!in_array($user_type, $item['access'])) {
            continue;
        }
        
        $has_accessible_items = true;
        $is_active = isCurrentPage($item['path'], $current_page);
        $active_class = $is_active ? 'active' : '';
        
        if ($is_submenu) {
            $html .= '<a href="' . BASE_URL . $item['path'] . '" class="sidebar-submenu-item ' . $active_class . '">';
            $html .= '<i class="bi bi-' . $item['icon'] . ' me-3"></i>' . $item['title'];
            $html .= '</a>';
        } else {
            $html .= '<a href="' . BASE_URL . $item['path'] . '" class="sidebar-menu-item ' . $active_class . '">';
            $html .= '<i class="bi bi-' . $item['icon'] . ' me-3"></i>' . $item['title'];
            $html .= '</a>';
        }
        
        // Add children if exists
        if (isset($item['children'])) {
            $html .= '<div class="sidebar-submenu">';
            $html .= buildMenuItems($item['children'], $user_type, $current_page, true);
            $html .= '</div>';
        }
    }
    
    return $has_accessible_items ? $html : '';
}

function isCurrentPage($menu_path, $current_page) {
    // Remove BASE_URL from current page for comparison
    $relative_current = str_replace(BASE_URL, '', $current_page);
    
    if ($relative_current === $menu_path) {
        return true;
    }
    
    // Check if current page starts with menu path (for directories)
    if (strpos($relative_current, $menu_path) === 0 && $menu_path !== '/') {
        return true;
    }
    
    return false;
}

function getUserSpecificGreeting() {
    $user_type = $_SESSION['user_type'];
    $greetings = [
        'admin' => 'System Administrator',
        'manager' => 'Manager',
        'field_officer' => 'Field Officer', 
        'accountant' => 'Accountant'
    ];
    
    return $greetings[$user_type] ?? 'User';
}
?>