[file name]: test_sms.php
[file content begin]
<?php
// modules/sms/test_sms.php

// Include config and check access
require_once __DIR__ . '/../../config/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

// Include SMS functions
require_once __DIR__ . '/../../includes/sms_functions.php';

if ($_POST) {
    $phone = $_POST['phone'];
    $message = $_POST['message'];
    
    $result = sendSMS($phone, $message);
    
    if ($result['success']) {
        $_SESSION['success'] = "SMS sent successfully!";
        if (isset($result['method'])) {
            $_SESSION['success'] .= " (Method: " . $result['method'] . ")";
        }
        if (SMS_TEST_MODE) {
            $_SESSION['success'] .= " (TEST MODE - No actual SMS sent)";
        }
    } else {
        $_SESSION['error'] = "SMS sending failed: " . $result['message'];
    }
    
    // Debug info
    $_SESSION['debug'] = $result;
    
    header("Location: test_sms.php");
    exit();
}

// Check SMS balance
$balance_info = checkSMSBalance();
?>
<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test SMS - Micro Finance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <div class="row">
            <div class="col-md-8">
                <h2>Test SMS Sending</h2>
                
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['debug'])): ?>
                    <div class="alert alert-info">
                        <h6>Debug Info:</h6>
                        <pre><?php print_r($_SESSION['debug']); unset($_SESSION['debug']); ?></pre>
                    </div>
                <?php endif; ?>
                
                <div class="card">
                    <div class="card-body">
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Phone Number:</label>
                                <input type="text" name="phone" class="form-control" placeholder="07XXXXXXXX" value="0778969190" required>
                                <div class="form-text">Format: 07XXXXXXXX or +947XXXXXXXX</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Message:</label>
                                <textarea name="message" class="form-control" rows="5" required>This is a test message from Micro Finance System. Please ignore.</textarea>
                                <div class="form-text">Max: 612 characters</div>
                            </div>
                            <button type="submit" class="btn btn-primary">Send Test SMS</button>
                            <?php if (SMS_TEST_MODE): ?>
                                <span class="badge bg-warning ms-2">TEST MODE</span>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
                
                <div class="mt-3">
                    <h5>Current SMS Configuration:</h5>
                    <ul class="list-group">
                        <li class="list-group-item">Provider: <?php echo SMS_PROVIDER; ?></li>
                        <li class="list-group-item">Sender ID: <?php echo SMS_SENDER_ID; ?></li>
                        <li class="list-group-item">Test Mode: <?php echo SMS_TEST_MODE ? 'Yes' : 'No'; ?></li>
                        <li class="list-group-item">
                            Balance: 
                            <?php if ($balance_info['success']): ?>
                                <span class="badge bg-success"><?php echo $balance_info['balance']; ?></span>
                            <?php else: ?>
                                <span class="badge bg-danger"><?php echo $balance_info['balance']; ?></span>
                            <?php endif; ?>
                        </li>
                    </ul>
                </div>
                
                <div class="mt-3">
                    <div class="alert alert-warning">
                        <h6>API Testing Methods:</h6>
                        <ul class="mb-0">
                            <li><strong>OAuth-recipient:</strong> Uses 'recipient' parameter with OAuth</li>
                            <li><strong>OAuth-to:</strong> Uses 'to' parameter with OAuth</li>
                            <li><strong>HTTP:</strong> Uses traditional HTTP API</li>
                        </ul>
                        <p class="mt-2 mb-0"><small>Check error logs to see which method is being used and their responses.</small></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
[file content end]