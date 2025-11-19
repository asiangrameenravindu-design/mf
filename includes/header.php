<?php
// includes/header.php

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Safely get session values with null coalescing operator
$user_name = $_SESSION['user_name'] ?? 'Guest';
$user_type = $_SESSION['user_type'] ?? 'user';
$branch = $_SESSION['branch'] ?? 'Main Branch';
?>

<!-- User info display -->
<span class="navbar-text me-3">
    <strong><?php echo htmlspecialchars($user_name); ?></strong>
    <small class="text-muted">
        (<?php echo ucfirst(str_replace('_', ' ', $user_type)); ?>
        <?php if(!empty($branch)): ?>
         - <?php echo htmlspecialchars($branch); ?>
        <?php endif; ?>)
    </small>
</span>