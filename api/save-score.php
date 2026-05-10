<?php
require_once '../includes/functions.php';
header('Content-Type: application/json');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Check login
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please login first']);
    exit;
}

// Validate CSRF
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit;
}

// Get data
$gameSlug = sanitize($_POST['game'] ?? '');
$score = intval($_POST['score'] ?? 0);
$level = intval($_POST['level'] ?? 1);
$coinsEarned = floatval($_POST['coins_earned'] ?? 0);
$reportedPB = intval($_POST['personal_best'] ?? 0);

if (empty($gameSlug) || $score < 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid game data']);
    exit;
}
$extraData = $_POST['moves'] ?? null;

// Game configurations
$games = [
    'snake' => [
        'reward_per_1000' => 0.010,
        'level_step' => 500
    ],
    'akku-ball-shooter' => [
        'reward_per_1000' => 0.015,
        'level_step' => 600
    ],
    'akku-color-crush' => [
        'reward_per_1000' => 0.012,
        'level_step' => 400
    ],
    'akku-chess-v1' => [
        'reward_per_1000' => 0.050,
        'level_step' => 1
    ]
];

if (!isset($games[$gameSlug])) {
    echo json_encode(['success' => false, 'message' => 'Invalid game']);
    exit;
}

$config = $games[$gameSlug];

try {
    $db = getDB();
    $userId = $_SESSION['user_id'];
    
    // Get user's actual personal best from database (security)
    $stmt = $db->prepare("SELECT MAX(score) as personal_best, MAX(level_reached) as max_level FROM game_scores WHERE user_id = ? AND game_slug = ?");
    $stmt->execute([$userId, $gameSlug]);
    $actualStats = $stmt->fetch();
    $actualPB = $actualStats['personal_best'] ?? 0;
    $actualMaxLevel = $actualStats['max_level'] ?? 0;
    
    // Verify coins earned (don't trust client completely)
    $verifiedCoins = 0;
    $isNewPersonalBest = false;
    
    if ($gameSlug === 'akku-chess-v1' && $score > 0) {
        $isNewPersonalBest = $score > $actualPB;
        $verifiedCoins = $config['reward_per_1000'];
    } elseif ($score > $actualPB) {
        $isNewPersonalBest = true;
        $improvement = $score - $actualPB;
        $verifiedCoins = floor($improvement / 1000) * $config['reward_per_1000'];
    }
    
    // Use the verified amount (client might try to cheat)
    $finalCoins = min($verifiedCoins, $coinsEarned); // Use smaller of two for safety
    
    $db->beginTransaction();
    
    // 1. Save game score
    $stmt = $db->prepare("INSERT INTO game_scores (user_id, game_slug, score, level_reached, coins_earned, played_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$userId, $gameSlug, $score, $level, $finalCoins]);
    $scoreId = $db->lastInsertId();
    
    // 2. Add coins to user wallet if earned
    if ($finalCoins > 0) {
        // Update user coin balance
        $stmt = $db->prepare("UPDATE users SET coin_balance = coin_balance + ? WHERE id = ?");
        $stmt->execute([$finalCoins, $userId]);
        
        // Get new balance
        $newBalance = getCoinBalance($userId);
        
        // Log transaction
        $stmt = $db->prepare("INSERT INTO coin_transactions 
            (user_id, reference_type, reference_id, amount, balance_after, description, created_at) 
            VALUES (?, 'game_score', ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $userId, 
            $scoreId, 
            $finalCoins, 
            $newBalance,
            "Game reward: $gameSlug (Score: $score, Improvement: " . ($score - $actualPB) . ")"
        ]);
    } else {
        $newBalance = getCoinBalance($userId);
    }
    
    $db->commit();
    
    // Success response
    echo json_encode([
        'success' => true,
        'coins_added' => $finalCoins,
        'new_balance' => formatCoins($newBalance),
        'new_personal_best' => $isNewPersonalBest,
        'message' => $finalCoins > 0 
            ? "🎉 +$finalCoins 🪙 for beating your record!" 
            : "Score saved! Keep playing to earn coins."
    ]);
    
} catch (Exception $e) {
    if (isset($db)) $db->rollBack();
    error_log("Save score error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?>
