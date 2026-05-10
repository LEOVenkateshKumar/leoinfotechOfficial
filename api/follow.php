<?php
require_once '../includes/functions.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Login required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
    exit;
}

$followerId = $_SESSION['user_id'];
$followingId = intval($_POST['user_id'] ?? 0);

if (!$followingId || $followerId === $followingId) {
    echo json_encode(['success' => false, 'message' => 'Invalid user']);
    exit;
}

$db = getDB();

// Check if already following
$check = $db->prepare("SELECT id FROM user_follows WHERE follower_id = ? AND following_id = ?");
$check->execute([$followerId, $followingId]);
$existing = $check->fetch();

try {
    if ($existing) {
        // Unfollow
        $stmt = $db->prepare("DELETE FROM user_follows WHERE follower_id = ? AND following_id = ?");
        $stmt->execute([$followerId, $followingId]);
        echo json_encode(['success' => true, 'action' => 'unfollowed']);
    } else {
        // Follow
        $stmt = $db->prepare("INSERT INTO user_follows (follower_id, following_id, created_at) VALUES (?, ?, NOW())");
        $stmt->execute([$followerId, $followingId]);
        echo json_encode(['success' => true, 'action' => 'followed']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
