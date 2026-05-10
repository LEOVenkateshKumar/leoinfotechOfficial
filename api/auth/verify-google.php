<?php
/**
 * API: Verify Google Token and Return User Data
 * Method: POST
 * Input: { "access_token": "google_token" }
 * Output: User data or error
 */

require_once '../includes/config.php';
require_once '../includes/functions.php';

// Set JSON header
header('Content-Type: application/json');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$accessToken = $input['access_token'] ?? '';

if (empty($accessToken)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Access token required']);
    exit;
}

// Verify token with Google
$ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid Google token']);
    exit;
}

$userData = json_decode($response, true);

if (!isset($userData['email'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Email not found']);
    exit;
}

try {
    $db = getDB();
    
    // Check if user exists
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ? OR google_id = ?");
    $stmt->execute([$userData['email'], $userData['id']]);
    $user = $stmt->fetch();
    
    $isNewUser = false;
    
    if ($user) {
        $userId = $user['id'];
        
        // Update google_id if not set
        if (empty($user['google_id'])) {
            $stmt = $db->prepare("UPDATE users SET google_id = ?, avatar = ? WHERE id = ?");
            $stmt->execute([$userData['id'], $userData['picture'] ?? null, $userId]);
        }
    } else {
        $isNewUser = true;
        
        // Check if admin email
        $role = ($userData['email'] === 'leoinfotech.chinnamanur@gmail.com') ? 'admin' : 'user';
        
        // Create new user
        $stmt = $db->prepare("
            INSERT INTO users (google_id, email, name, avatar, role, coin_balance, wallet_balance, created_at) 
            VALUES (?, ?, ?, ?, ?, 0.0000, 0.00, NOW())
        ");
        $stmt->execute([
            $userData['id'],
            $userData['email'],
            $userData['name'] ?? 'User',
            $userData['picture'] ?? null,
            $role
        ]);
        
        $userId = $db->lastInsertId();
        
        // Welcome bonus for non-admin users
        if ($role !== 'admin') {
            addCoins($userId, 0.050, 'admin_add', $userId, 'Welcome bonus from Desktop App');
        }
    }
    
    // Get updated user data
    $stmt = $db->prepare("SELECT id, email, name, avatar, coin_balance, wallet_balance, role, status FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    // Log this login in analytics
    $deviceInfo = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    
    $stmt = $db->prepare("
        INSERT INTO page_analytics (page_url, category, user_id, ip_address, user_agent, device_type, browser, os, hit_date, hit_hour)
        VALUES ('/api/auth/verify-google', 'api_login', ?, ?, ?, 'desktop', 'Chrome', 'Windows', CURDATE(), HOUR(NOW()))
    ");
    $stmt->execute([$userId, $ip, $deviceInfo]);
    
    // Return user data
    echo json_encode([
        'success' => true,
        'is_new_user' => $isNewUser,
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'avatar' => $user['avatar'],
            'coin_balance' => floatval($user['coin_balance']),
            'wallet_balance' => floatval($user['wallet_balance']),
            'role' => $user['role'],
            'status' => $user['status']
        ]
    ]);
    
} catch (PDOException $e) {
    error_log("API verify-google error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}