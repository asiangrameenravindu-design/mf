<?php
// modules/admin/user_roles.php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Check if admin
checkPermission(['admin']);

$success = '';
$error = '';

// Define role permissions
$role_permissions = [
    'admin' => [
        'name' => 'පරිපාලක',
        'permissions' => ['සියලුම ප්‍රවේශය', 'පරිශීලක කළමනාකරණය', 'පද්ධති සැකසුම්']
    ],
    'manager' => [
        'name' => 'කළමනාකරු', 
        'permissions' => ['ගනුදෙනුකරු කළමනාකරණය', 'ණය කළමනාකරණය', 'CBO කළමනාකරණය', 'ගෙවීම්', 'වාර්තා']
    ],
    'credit_officer' => [
        'name' => 'ණය නිලධාරී',
        'permissions' => ['ගනුදෙනුකරු කළමනාකරණය', 'ණය කළමනාකරණය', 'CBO කළමනාකරණය']
    ],
    'accountant' => [
        'name' => 'ගිණුම් ලිපිකරු',
        'permissions' => ['ගෙවීම්', 'මූල්‍ය වාර්තා', 'ගිණුම්කරණය']
    ]
];

// Get user count by role
$user_counts = [];
foreach ($role_permissions as $role => $data) {
    $sql = "SELECT COUNT(*) as count FROM users WHERE user_type = ? AND status = 'active'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $role);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_counts[$role] = $result->fetch_assoc()['count'];
}
?>

<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>භූමිකාවන් කළමනාකරණය - මයික්‍රොෆයිනන්ස්</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        .role-card {
            transition: transform 0.2s;
            border-left: 4px solid;
        }
        .role-card:hover {
            transform: translateY(-2px);
        }
        .admin-card { border-left-color: #dc3545; }
        .manager-card { border-left-color: #ffc107; }
        .credit-card { border-left-color: #198754; }
        .accountant-card { border-left-color: #0dcaf0; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <?php include '../../includes/sidebar.php'; ?>
            
            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="bi bi-shield-check"></i>
                        භූමිකාවන් කළමනාකරණය
                    </h1>
                </div>

                <div class="row">
                    <!-- Role Cards -->
                    <?php foreach ($role_permissions as $role => $data): ?>
                    <div class="col-md-6 mb-4">
                        <div class="card role-card <?php echo $role; ?>-card h-100">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-<?php 
                                        switch($role) {
                                            case 'admin': echo 'shield-check'; break;
                                            case 'manager': echo 'person-gear'; break;
                                            case 'credit_officer': echo 'cash-coin'; break;
                                            case 'accountant': echo 'calculator'; break;
                                        }
                                    ?>"></i>
                                    <?php echo $data['name']; ?>
                                </h5>
                                <span class="badge bg-<?php 
                                    switch($role) {
                                        case 'admin': echo 'danger'; break;
                                        case 'manager': echo 'warning'; break;
                                        case 'credit_officer': echo 'success'; break;
                                        case 'accountant': echo 'info'; break;
                                    }
                                ?>">
                                    <?php echo $user_counts[$role]; ?> පරිශීලකයින්
                                </span>
                            </div>
                            <div class="card-body">
                                <h6 class="text-muted">අවසර ලත් ක්‍රියාවන්:</h6>
                                <ul class="list-unstyled">
                                    <?php foreach ($data['permissions'] as $permission): ?>
                                    <li class="mb-1">
                                        <i class="bi bi-check-circle text-success"></i>
                                        <?php echo $permission; ?>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <div class="card-footer">
                                <small class="text-muted">
                                    <i class="bi bi-info-circle"></i>
                                    මෙම භූමිකාව සඳහා වන සියලුම අවසරයන්
                                </small>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Permissions Table -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header bg-dark text-white">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-list-check"></i>
                                    භූමිකාවන් අනුව ප්‍රවේශ අවසර
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <thead class="table-primary">
                                            <tr>
                                                <th>මොඩියුලය</th>
                                                <?php foreach ($role_permissions as $role => $data): ?>
                                                <th class="text-center"><?php echo $data['name']; ?></th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td>පරිශීලක කළමනාකරණය</td>
                                                <td class="text-center"><i class="bi bi-check-circle-fill text-success"></i></td>
                                                <td class="text-center"><i class="bi bi-x-circle-fill text-danger"></i></td>
                                                <td class="text-center"><i class="bi bi-x-circle-fill text-danger"></i></td>
                                                <td class="text-center"><i class="bi bi-x-circle-fill text-danger"></i></td>
                                            </tr>
                                            <tr>
                                                <td>ගනුදෙනුකරු කළමනාකරණය</td>
                                                <td class="text-center"><i class="bi bi-check-circle-fill text-success"></i></td>
                                                <td class="text-center"><i class="bi bi-check-circle-fill text-success"></i></td>
                                                <td class="text-center"><i class="bi bi-check-circle-fill text-success"></i></td>
                                                <td class="text-center"><i class="bi bi-x-circle-fill text-danger"></i></td>
                                            </tr>
                                            <tr>
                                                <td>ණය කළමනාකරණය</td>
                                                <td class="text-center"><i class="bi bi-check-circle-fill text-success"></i></td>
                                                <td class="text-center"><i class="bi bi-check-circle-fill text-success"></i></td>
                                                <td class="text-center"><i class="bi bi-check-circle-fill text-success"></i></td>
                                                <td class="text-center"><i class="bi bi-x-circle-fill text-danger"></i></td>
                                            </tr>
                                            <tr>
                                                <td>CBO කළමනාකරණය</td>
                                                <td class="text-center"><i class="bi bi-check-circle-fill text-success"></i></td>
                                                <td class="text-center"><i class="bi bi-check-circle-fill text-success"></i></td>
                                                <td class="text-center"><i class="bi bi-check-circle-fill text-success"></i></td>
                                                <td class="text-center"><i class="bi bi-x-circle-fill text-danger"></i></td>
                                            </tr>
                                            <tr>
                                                <td>ගෙවීම් කළමනාකරණය</td>
                                                <td class="text-center"><i class="bi bi-check-circle-fill text-success"></i></td>
                                                <td class="text-center"><i class="bi bi-check-circle-fill text-success"></i></td>
                                                <td class="text-center"><i class="bi bi-x-circle-fill text-danger"></i></td>
                                                <td class="text-center"><i class="bi bi-check-circle-fill text-success"></i></td>
                                            </tr>
                                            <tr>
                                                <td>වාර්තා සහ විශ්ලේෂණ</td>
                                                <td class="text-center"><i class="bi bi-check-circle-fill text-success"></i></td>
                                                <td class="text-center"><i class="bi bi-check-circle-fill text-success"></i></td>
                                                <td class="text-center"><i class="bi bi-check-circle-fill text-success"></i></td>
                                                <td class="text-center"><i class="bi bi-check-circle-fill text-success"></i></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>