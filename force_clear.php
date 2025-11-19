<?php
// force_clear.php
if (session_status() == PHP_SESSION_ACTIVE) {
    session_destroy();
}

// Clear all session cookies
setcookie('PHPSESSID', '', time() - 3600, '/');

echo "<h3>✅ Sessions and Cookies Force Cleared!</h3>";
echo "<p>Please:</p>";
echo "<ol>";
echo "<li><strong>Close ALL browser tabs</strong></li>";
echo "<li><strong>Open new browser window</strong></li>";
echo "<li><strong>Go to: <a href='test_login.php'>test_login.php</a></strong></li>";
echo "</ol>";

echo "<script>
    // Force clear client-side cookies
    document.cookie.split(';').forEach(function(c) {
        document.cookie = c.replace(/^ +/, '').replace(/=.*/, '=;expires=' + new Date().toUTCString() + ';path=/');
    });
    
    setTimeout(function() {
        window.location.href = 'test_login.php';
    }, 3000);
</script>";
?>