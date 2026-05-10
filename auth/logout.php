<?php
require_once '../includes/functions.php';
logout();
redirect('/auth/login.php', 'Logged out successfully', 'success');
?>
