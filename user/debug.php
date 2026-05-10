<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once '../includes/functions.php';
echo "Functions loaded OK<br>";
require_once '../includes/post-functions.php';
echo "Post functions loaded OK<br>";
echo "PHP Version: " . phpversion();
