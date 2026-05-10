<?php
require_once '../includes/functions.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['error' => 'Login required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

if (!isset($_FILES['file'])) {
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['file'];
$allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$maxSize = 2 * 1024 * 1024; // 2MB

if (!in_array($file['type'], $allowed)) {
    echo json_encode(['error' => 'Invalid file type']);
    exit;
}

if ($file['size'] > $maxSize) {
    echo json_encode(['error' => 'File too large (max 2MB)']);
    exit;
}

$uploadDir = __DIR__ . '/../public_html/uploads/blogs/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = 'content_' . time() . '_' . uniqid() . '.' . $ext;
$target = $uploadDir . $filename;

if (move_uploaded_file($file['tmp_name'], $target)) {
    echo json_encode(['location' => '/uploads/blogs/' . $filename]);
} else {
    echo json_encode(['error' => 'Failed to save file']);
}
?>
