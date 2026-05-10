<?php
/**
 * API: Get User Profile with Coins & Wallet
 * Method: GET
 * Headers: X-User-Email: user@email.com
 * Output: Complete user profile
 */

require_once '../includes/config.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get user email from header
$headers = getallheaders();
$userEmail = $headers['X-User-Email'] ?? '';

if (empty($userEmail)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'User email required']);
    exit;
}

try {
    $db = getDB();
    
    // Get user with coin balance and wallet
    $stmt = $db->prepare("
        SELECT id, email, name, avatar, coin_balance, wallet_balance, role, status, created_at
        FROM users 
        WHERE email = ? AND status = 'active'
    ");
    $stmt->execute([$userEmail]);
    $user = $stmt->fetch();
    
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }
    
    // Get recent coin transactions (last 5)
    $stmt = $db->prepare("
        SELECT amount, balance_after, description, created_at
        FROM coin_transactions
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$user['id']]);
    $recentTransactions = $stmt->fetchAll();
    
    // Get total coins earned (positive transactions)
    $stmt = $db->prepare("
        SELECT SUM(amount) as total_earned
        FROM coin_transactions
        WHERE user_id = ? AND amount > 0
    ");
    $stmt->execute([$user['id']]);
    $totalEarned = $stmt->fetchColumn();
    
    // Get total coins spent (negative transactions)
    $stmt = $db->prepare("
        SELECT SUM(ABS(amount)) as total_spent
        FROM coin_transactions
        WHERE user_id = ? AND amount < 0
    ");
    $stmt->execute([$user['id']]);
    $totalSpent = $stmt->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'avatar' => $user['avatar'],
            'coin_balance' => floatval($user['coin_balance']),
            'wallet_balance' => floatval($user['wallet_balance']),
            'role' => $user['role'],
            'status' => $user['status'],
            'member_since' => $user['created_at'],
            'statistics' => [
                'total_earned' => floatval($totalEarned ?? 0),
                'total_spent' => floatval($totalSpent ?? 0)
            ],
            'recent_transactions' => $recentTransactions
        ]
    ]);
    
} catch (PDOException $e) {
    error_log("API profile error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}