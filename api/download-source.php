<?php
require_once '../includes/functions.php';

// 🔐 Require login
if (!isLoggedIn()) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: /auth/login.php');
    exit;
}

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    header('HTTP/1.0 404 Not Found');
    exit;
}

$db = getDB();
$stmt = $db->prepare("SELECT file_path, link_url, title FROM source_items WHERE id = ? AND status = 'active'");
$stmt->execute([$id]);
$item = $stmt->fetch();

if (!$item) {
    header('HTTP/1.0 404 Not Found');
    exit;
}

// Update download count
$db->prepare("UPDATE source_items SET downloads_count = downloads_count + 1 WHERE id = ?")->execute([$id]);

// Serve local file
if (!empty($item['file_path'])) {
    $file = realpath(__DIR__ . '/..' . $item['file_path']);
    if (!$file || !file_exists($file)) {
        header('HTTP/1.0 404 Not Found');
        exit;
    }
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    readfile($file);
    exit;
}

// Redirect to external link
if (!empty($item['link_url'])) {
    header('Location: ' . $item['link_url']);
    exit;
}

// No source
header('HTTP/1.0 404 Not Found');
exit;