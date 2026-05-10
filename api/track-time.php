<?php
require_once '../includes/functions.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$timeSpent = intval($_POST['time_spent'] ?? 0);
$sessionId = sanitize($_POST['session_id'] ?? '');

if ($timeSpent > 0 && !empty($sessionId)) {
    trackTimeOnPage($sessionId, $timeSpent);
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false]);
}
