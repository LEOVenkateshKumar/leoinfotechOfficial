<?php
/**
 * /api/poker-action.php  (Updated for V2 - supports XP, mode)
 */
require_once '../includes/functions.php';
header('Content-Type: application/json');

if (!isLoggedIn()) { echo json_encode(['success'=>false,'error'=>'Login required']); exit; }
if (!validateCSRF($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'error'=>'Invalid token']); exit; }

$userId    = (int)$_SESSION['user_id'];
$action    = $_POST['action_type'] ?? '';
$result    = $_POST['result'] ?? 'lose';
$netCoins  = floatval($_POST['net_coins'] ?? 0);
$anteBet   = floatval($_POST['ante_bet'] ?? 0);
$playBet   = floatval($_POST['play_bet'] ?? 0);
$ppBet     = floatval($_POST['pair_plus_bet'] ?? 0);
$pRank     = $_POST['player_rank'] ?? '';
$dRank     = $_POST['dealer_rank'] ?? '';
$dQual     = (int)($_POST['dealer_qualifies'] ?? 0);
$pCards    = $_POST['player_cards'] ?? '';
$dCards    = $_POST['dealer_cards'] ?? '';
$mode      = in_array($_POST['mode']??'',['seen','blind']) ? $_POST['mode'] : 'seen';
$xpEarned  = min(200, max(0, intval($_POST['xp_earned'] ?? 0)));

if (!in_array($action,['fold','play']) || !in_array($result,['win','lose','push'])) {
    echo json_encode(['success'=>false,'error'=>'Invalid params']); exit;
}
if ($anteBet <= 0 || $anteBet > 2.0) {
    echo json_encode(['success'=>false,'error'=>'Invalid bet amount']); exit;
}

try {
    $db = getDB();
    $db->beginTransaction();

    $stmt = $db->prepare("SELECT coin_balance FROM users WHERE id = ? FOR UPDATE");
    $stmt->execute([$userId]);
    $current = floatval($stmt->fetchColumn());

    $newBalance = round($current + $netCoins, 4);
    if ($newBalance < 0) $newBalance = 0;

    $db->prepare("UPDATE users SET coin_balance = ? WHERE id = ?")->execute([$newBalance, $userId]);

    if ($netCoins != 0) {
        $desc = "Poker V2 ($mode): $result — $pRank vs $dRank";
        $db->prepare("INSERT INTO coin_transactions (user_id, reference_type, amount, balance_after, description, created_at) VALUES (?, 'poker', ?, ?, ?, NOW())")
           ->execute([$userId, round($netCoins,4), $newBalance, $desc]);
    }

    // Save poker hand
    try {
        $db->prepare("INSERT INTO poker_hands (user_id,player_cards,dealer_cards,ante_bet,play_bet,pair_plus_bet,action,player_rank,dealer_rank,dealer_qualifies,result,net_coins,coins_before,played_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
           ->execute([$userId,$pCards,$dCards,round($anteBet,4),round($playBet,4),round($ppBet,4),$action,$pRank,$dRank,$dQual,$result,round($netCoins,4),round($current,4)]);
    } catch (PDOException $e) {
        error_log("poker_hands skip: " . $e->getMessage());
    }

    // Save XP as game score entry
    if ($xpEarned > 0) {
        $db->prepare("INSERT INTO game_scores (user_id, game_slug, score, level_reached, coins_earned, played_at) VALUES (?, 'poker-v2-xp', ?, 1, 0, NOW())")
           ->execute([$userId, $xpEarned]);
    }

    // Save to regular game_scores for leaderboard
    if ($result === 'win' && $netCoins > 0) {
        $score = intval($netCoins * 10000);
        $db->prepare("INSERT INTO game_scores (user_id, game_slug, score, level_reached, coins_earned, played_at) VALUES (?, 'three-card-poker', ?, 1, ?, NOW())")
           ->execute([$userId, $score, max(0, $netCoins)]);
    }

    $db->commit();
    echo json_encode(['success'=>true, 'new_balance'=>$newBalance, 'net_coins'=>$netCoins, 'result'=>$result]);

} catch (PDOException $e) {
    if (isset($db)) $db->rollBack();
    error_log("poker-action error: " . $e->getMessage());
    echo json_encode(['success'=>false,'error'=>'DB error']);
}