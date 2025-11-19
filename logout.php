<?php
// logout.php
require_once 'config/config.php';

$timeout = isset($_GET['timeout']) ? true : false;

session_destroy();

if ($timeout) {
    header("location: login.php?timeout=1");
} else {
    header("location: index.php");
}
exit;
?>