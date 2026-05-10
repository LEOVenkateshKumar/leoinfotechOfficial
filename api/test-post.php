<?php
header('Content-Type: application/json');
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'post' => $_POST,
    'get' => $_GET,
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'none',
    'received_action' => $_POST['action'] ?? 'NOT SET'
]);
?>
