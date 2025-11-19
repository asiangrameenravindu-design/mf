<?php
// modules/admin/create_user.php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Check if admin
checkPermission(['admin']);

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_data = [
        'username' => trim($_POST['username']),
        'password' => trim($_POST['password']),
        'full_name' => trim($_POST['full_name']),
        'user_type' => $_POST['user_type'],
        'email' => trim($_POST['email']),
        'phone' => trim($_POST['phone']),
        'address' => trim($_POST['address']),
        'nic' => trim($_POST['nic'])
    ];
    
    if (isUsernameExists($user_data['username'])) {
        $error = "පරිශීලක නම දැනටමත් පවතී!";
    } else {
        if (createUser($user_data)) {
            $success = "පරිශීලකයා සාර්ථකව ඇතුලත් කරන ලදී!";
            // Clear form
            $_POST = array();
        } else {
            $error = "පරිශීලකයා ඇතුලත් කිරීමට අසමත් විය!";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>නව පරිශීලකයා - මයික්‍රොෆයිනන්ස්</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
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
                        <i class="bi bi-person-plus"></i>
                        නව පරිශීලකයා ඇතුලත් කිරීම
                    </h1>
                    <a href="users.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i>
                        ආපසු
                    </a>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>

                <div class="row justify-content-center">
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h5 class="card-title mb-0">පරිශීලක තොරතුරු</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">පරිශීලක නම *</label>
                                                <input type="text" name="username" class="form-control" required 
                                                       value="<?php echo $_POST['username'] ?? ''; ?>">
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">රහස් පදය *</label>
                                                <input type="password" name="password" class="form-control" required>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">සම්පූර්ණ නම *</label>
                                                <input type="text" name="full_name" class="form-control" required
                                                       value="<?php echo $_POST['full_name'] ?? ''; ?>">
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">ජාතික හැඳුනුම්පත් අංකය</label>
                                                <input type="text" name="nic" class="form-control"
                                                       value="<?php echo $_POST['nic'] ?? ''; ?>">
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">පරිශීලක වර්ගය *</label>
                                                <select name="user_type" class="form-control" required>
                                                    <option value="manager">කළමනාකරු</option>
                                                    <option value="credit_officer">ණය නිලධාරී</option>
                                                    <option value="accountant">ගිණුම් ලිපිකරු</option>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">විද්‍යුත් තැපෑල</label>
                                                <input type="email" name="email" class="form-control"
                                                       value="<?php echo $_POST['email'] ?? ''; ?>">
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">දුරකථන අංකය</label>
                                                <input type="text" name="phone" class="form-control"
                                                       value="<?php echo $_POST['phone'] ?? ''; ?>">
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">ලිපිනය</label>
                                                <textarea name="address" class="form-control" rows="3"><?php echo $_POST['address'] ?? ''; ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-12">
                                            <button type="submit" class="btn btn-success w-100 py-2">
                                                <i class="bi bi-person-check"></i>
                                                පරිශීලකයා ඇතුලත් කරන්න
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                        
                        <div class="card mt-4">
                            <div class="card-header bg-info text-white">
                                <h6 class="card-title mb-0">පරිශීලක භූමිකාවන්</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="border p-3 rounded">
                                            <h6 class="text-primary">කළමනාකරු</h6>
                                            <small class="text-muted">සම්පූර්ණ පද්ධති ප්‍රවේශය, වාර්තා, කළමනාකරණය</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="border p-3 rounded">
                                            <h6 class="text-success">ණය නිලධාරී</h6>
                                            <small class="text-muted">ගනුදෙනුකරු, ණය, CBO කළමනාකරණය</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="border p-3 rounded">
                                            <h6 class="text-warning">ගිණුම් ලිපිකරු</h6>
                                            <small class="text-muted">ගෙවීම්, මූල්‍ය වාර්තා, ගිණුම්කරණය</small>
                                        </div>
                                    </div>
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