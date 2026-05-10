<?php
// D:\2026akkuapps.in\api\mobile-api.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Device-ID');

require_once __DIR__ . '/../includes/config.php';

$input = json_decode(file_get_contents('php://input'), true);
$action = $_GET['action'] ?? $input['action'] ?? '';
$deviceId = $_SERVER['HTTP_X_DEVICE_ID'] ?? $input['device_id'] ?? '';

try {
    $db = getDB();
    
    switch ($action) {
        // ═══════════════════════════════════════════════════
        // AUTHENTICATION (WebView Callback Handler)
        // ═══════════════════════════════════════════════════
        case 'verify_google':
            // Called after WebView Google Sign-In
            $email = $input['email'] ?? '';
            $googleId = $input['google_id'] ?? '';
            $name = $input['name'] ?? 'User';
            $avatar = $input['avatar'] ?? '';
            
            if (empty($email) || empty($googleId)) {
                throw new Exception('Invalid credentials');
            }
            
            // Check existing user
            $stmt = $db->prepare("SELECT * FROM users WHERE email = ? OR google_id = ?");
            $stmt->execute([$email, $googleId]);
            $user = $stmt->fetch();
            
            if ($user) {
                // Check if already logged in another device (1 device policy)
                $sessionCheck = $db->prepare("SELECT device_id FROM user_sessions 
                    WHERE user_id = ? AND is_valid = 1 
                    AND last_active > DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
                $sessionCheck->execute([$user['id']]);
                $existingSession = $sessionCheck->fetch();
                
                if ($existingSession && $existingSession['device_id'] != $deviceId) {
                    // Optional: Force logout other device or reject
                    // For now, we allow but invalidate old session
                    $db->prepare("UPDATE user_sessions SET is_valid = 0 WHERE user_id = ?")
                       ->execute([$user['id']]);
                }
                
                $userId = $user['id'];
            } else {
                // Create new user
                $stmt = $db->prepare("INSERT INTO users (google_id, email, name, avatar, coin_balance, created_at) 
                                     VALUES (?, ?, ?, ?, 0.050, NOW())");
                $stmt->execute([$googleId, $email, $name, $avatar]);
                $userId = $db->lastInsertId();
            }
            
            // Create session token (24 hours)
            $sessionToken = bin2hex(random_bytes(32));
            $db->prepare("INSERT INTO user_sessions 
                (user_id, session_token, device_id, device_info, expires_at, is_valid) 
                VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR), 1)")
               ->execute([$userId, $sessionToken, $deviceId, 'MAUI-App']);
            
            // Get fresh user data with balances
            $stmt = $db->prepare("SELECT id, name, email, avatar, coin_balance, wallet_balance, role 
                                 FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'session_token' => $sessionToken,
                'user' => $userData,
                'is_new' => empty($user)
            ]);
            break;

        // ═══════════════════════════════════════════════════
        // FEED (Doom Scroll Pagination)
        // ═══════════════════════════════════════════════════
        case 'feed':
            $page = intval($_GET['page'] ?? 1);
            $limit = intval($_GET['limit'] ?? 10);
            $offset = ($page - 1) * $limit;
            $userId = intval($_GET['user_id'] ?? 0);
            
            // Get posts with author info
            $stmt = $db->prepare("
                SELECT 
                    p.id, p.hash_id, p.content, p.created_at,
                    p.likes_count, p.comments_count, p.views_count,
                    p.visibility, p.user_id as author_id,
                    u.name as author_name, u.avatar as author_avatar,
                    (SELECT 1 FROM user_likes WHERE post_id = p.id AND user_id = ?) as has_liked
                FROM user_posts p
                JOIN users u ON p.user_id = u.id
                WHERE p.status = 'active' AND p.visibility = 'public'
                ORDER BY p.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$userId, $limit, $offset]);
            $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get coin settings for display
            $settings = $db->query("SELECT setting_key, setting_value FROM site_settings 
                                   WHERE setting_key IN ('like_cost', 'like_reward', 'comment_cost', 'post_cost')")
                          ->fetchAll(PDO::FETCH_KEY_PAIR);
            
            echo json_encode([
                'success' => true,
                'posts' => $posts,
                'page' => $page,
                'has_more' => count($posts) == $limit,
                'coin_settings' => $settings
            ]);
            break;

        // ═══════════════════════════════════════════════════
        // COIN TRANSACTIONS
        // ═══════════════════════════════════════════════════
        case 'like_post':
            $userId = $input['user_id'] ?? 0;
            $postId = $input['post_id'] ?? 0;
            $postOwnerId = $input['post_owner_id'] ?? 0;
            
            // Check if already liked
            $check = $db->prepare("SELECT 1 FROM user_likes WHERE user_id = ? AND post_id = ?");
            $check->execute([$userId, $postId]);
            if ($check->fetch()) {
                throw new Exception('Already liked');
            }
            
            // Get costs from settings
            $likeCost = $db->query("SELECT setting_value FROM site_settings WHERE setting_key = 'like_cost'")->fetchColumn() ?: 0.002;
            $likeReward = $db->query("SELECT setting_value FROM site_settings WHERE setting_key = 'like_reward'")->fetchColumn() ?: 0.001;
            
            $db->beginTransaction();
            
            // Deduct from liker
            $stmt = $db->prepare("UPDATE users SET coin_balance = coin_balance - ? WHERE id = ? AND coin_balance >= ?");
            $stmt->execute([$likeCost, $userId, $likeCost]);
            if ($stmt->rowCount() == 0) {
                throw new Exception('Insufficient coins');
            }
            
            // Add to post owner
            $db->prepare("UPDATE users SET coin_balance = coin_balance + ? WHERE id = ?")
               ->execute([$likeReward, $postOwnerId]);
            
            // Record like
            $db->prepare("INSERT INTO user_likes (user_id, post_id, created_at) VALUES (?, ?, NOW())")->execute([$userId, $postId]);
            $db->prepare("UPDATE user_posts SET likes_count = likes_count + 1 WHERE id = ?")->execute([$postId]);
            
            // Log transactions
            $db->prepare("INSERT INTO coin_transactions (user_id, reference_type, reference_id, amount, balance_after, description) 
                        VALUES (?, 'like_given', ?, ?, (SELECT coin_balance FROM users WHERE id = ?), 'Like given')")
               ->execute([$userId, $postId, -$likeCost, $userId]);
            
            $db->prepare("INSERT INTO coin_transactions (user_id, reference_type, reference_id, amount, balance_after, description) 
                        VALUES (?, 'like_received', ?, ?, (SELECT coin_balance FROM users WHERE id = ?), 'Like received')")
               ->execute([$postOwnerId, $postId, $likeReward, $postOwnerId]);
            
            $db->commit();
            
            // Get updated balance
            $newBalance = $db->query("SELECT coin_balance FROM users WHERE id = $userId")->fetchColumn();
            echo json_encode(['success' => true, 'new_balance' => $newBalance, 'message' => "Like sent! -$likeCost 🪙"]);
            break;

        case 'submit_game_score':
            $userId = $input['user_id'] ?? 0;
            $gameSlug = $input['game_slug'] ?? 'snake';
            $score = intval($input['score'] ?? 0);
            $level = intval($input['level'] ?? 1);
            
            // Calculate coins (example: score/100)
            $coinsEarned = round($score / 100, 4);
            
            $db->beginTransaction();
            
            // Update user balance
            $db->prepare("UPDATE users SET coin_balance = coin_balance + ? WHERE id = ?")
               ->execute([$coinsEarned, $userId]);
            
            // Save game score
            $db->prepare("INSERT INTO game_scores (user_id, game_slug, score, level_reached, coins_earned) 
                         VALUES (?, ?, ?, ?, ?)")
               ->execute([$userId, $gameSlug, $score, $level, $coinsEarned]);
            
            // Log transaction
            $db->prepare("INSERT INTO coin_transactions (user_id, reference_type, amount, balance_after, description) 
                        VALUES (?, 'game_score', ?, (SELECT coin_balance FROM users WHERE id = ?), ?)")
               ->execute([$userId, $coinsEarned, $userId, "Game: $gameSlug, Score: $score"]);
            
            $db->commit();
            
            $newBalance = $db->query("SELECT coin_balance FROM users WHERE id = $userId")->fetchColumn();
            echo json_encode([
                'success' => true, 
                'coins_earned' => $coinsEarned,
                'new_balance' => $newBalance
            ]);
            break;

        case 'get_balance':
            $userId = intval($_GET['user_id'] ?? 0);
            $stmt = $db->prepare("SELECT coin_balance, wallet_balance FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $balance = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'balance' => $balance]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
