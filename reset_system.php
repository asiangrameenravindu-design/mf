<?php
// reset_system.php - Complete system reset
if (session_status() == PHP_SESSION_ACTIVE) {
    session_destroy();
}

// Clear all cookies
setcookie('PHPSESSID', '', time() - 3600, '/');

echo "<!DOCTYPE html>
<html>
<head>
    <title>System Reset</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css' rel='stylesheet'>
    <style>
        body { background: #f8f9fa; height: 100vh; display: flex; align-items: center; justify-content: center; }
    </style>
</head>
<body>
    <div class='container text-center'>
        <div class='card shadow' style='max-width: 500px;'>
            <div class='card-header bg-success text-white'>
                <h4 class='mb-0'>✅ System Reset Complete</h4>
            </div>
            <div class='card-body'>
                <p class='mb-3'>All sessions and cookies have been cleared.</p>
                <p><strong>Please:</strong></p>
                <ol class='text-start'>
                    <li>Close ALL browser tabs</li>
                    <li>Open new browser window</li>
                    <li>Go to the new login page</li>
                </ol>
                <a href='new_login.php' class='btn btn-success btn-lg mt-3'>Go to New Login Page</a>
            </div>
        </div>
    </div>
    
    <script>
        // Clear all client-side storage
        document.cookie.split(';').forEach(function(c) {
            document.cookie = c.replace(/^ +/, '').replace(/=.*/, '=;expires=' + new Date().toUTCString() + ';path=/');
        });
        localStorage.clear();
        sessionStorage.clear();
    </script>
</body>
</html>";
?>