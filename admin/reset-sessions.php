<?php
require_once '../includes/functions.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCSRF($_POST['csrf_token'] ?? '')) {
    $db = getDB();
    
    if (isset($_POST['clear_expired'])) {
        // Clear all expired sessions
        $stmt = $db->prepare("UPDATE user_sessions SET is_valid = 0 WHERE expires_at < NOW() OR is_valid = 0");
        $stmt->execute();
        $_SESSION['flash_message'] = 'Expired sessions cleared';
        $_SESSION['flash_type'] = 'success';
    }
    
    if (isset($_POST['kill_session']) && isset($_POST['session_id'])) {
        // Kill specific session
        $stmt = $db->prepare("UPDATE user_sessions SET is_valid = 0 WHERE id = ?");
        $stmt->execute([$_POST['session_id']]);
        $_SESSION['flash_message'] = 'Session terminated';
        $_SESSION['flash_type'] = 'success';
    }
}

header('Location: dashboard.php');
exit();
?>
