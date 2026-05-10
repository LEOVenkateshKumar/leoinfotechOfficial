<?php
require_once '../includes/functions.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Login required']);
    exit;
}

$userId = $_SESSION['user_id'];
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCSRF($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add':
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            if ($name && $email) {
                $stmt = $db->prepare("INSERT INTO user_contacts (user_id, name, email, source) VALUES (?, ?, ?, 'manual')");
                $stmt->execute([$userId, $name, $email]);
                header("Location: /user/akkuconnects.php?tab=manual&added=1");
            }
            break;
            
        case 'delete':
            $id = intval($_POST['contact_id'] ?? 0);
            $stmt = $db->prepare("DELETE FROM user_contacts WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $userId]);
            echo json_encode(['success' => true]);
            break;
            
        case 'edit':
            $id = intval($_POST['contact_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            if ($id && $name) {
                $stmt = $db->prepare("UPDATE user_contacts SET name = ? WHERE id = ? AND user_id = ?");
                $stmt->execute([$name, $id, $userId]);
                echo json_encode(['success' => true]);
            }
            break;
    }
}
