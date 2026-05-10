<?php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

function chess_join_json($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    chess_join_json(['success' => false, 'message' => 'Method not allowed'], 405);
}

if (!isLoggedIn()) {
    chess_join_json(['success' => false, 'message' => 'Login required'], 401);
}

if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    chess_join_json(['success' => false, 'message' => 'Invalid token'], 403);
}

$tournamentId = (int)($_POST['tournament_id'] ?? 0);
if ($tournamentId <= 0) {
    chess_join_json(['success' => false, 'message' => 'Invalid tournament'], 400);
}

try {
    $db = getDB();
    $userId = (int)$_SESSION['user_id'];

    $stmt = $db->prepare("SELECT * FROM chess_tournaments WHERE id = ? AND status = 'open'");
    $stmt->execute([$tournamentId]);
    $tournament = $stmt->fetch();
    if (!$tournament) {
        chess_join_json(['success' => false, 'message' => 'Tournament is not available'], 404);
    }

    $entryFee = (float)($tournament['entry_fee'] ?? 0);
    if ($entryFee > 0 && getCoinBalance($userId) < $entryFee) {
        chess_join_json(['success' => false, 'message' => 'Insufficient coins'], 400);
    }

    $db->beginTransaction();
    if ($entryFee > 0) {
        $stmt = $db->prepare("UPDATE users SET coin_balance = coin_balance - ? WHERE id = ? AND coin_balance >= ?");
        $stmt->execute([$entryFee, $userId, $entryFee]);
        if ($stmt->rowCount() === 0) {
            $db->rollBack();
            chess_join_json(['success' => false, 'message' => 'Insufficient coins'], 400);
        }
    }

    $stmt = $db->prepare("INSERT IGNORE INTO tournament_players (tournament_id, user_id, joined_at) VALUES (?, ?, NOW())");
    $stmt->execute([$tournamentId, $userId]);
    $db->prepare("UPDATE chess_tournaments SET prize_pool = prize_pool + ? WHERE id = ?")->execute([$entryFee, $tournamentId]);
    $db->commit();

    chess_join_json(['success' => true, 'message' => 'Joined tournament']);
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('Chess tournament join error: ' . $e->getMessage());
    chess_join_json(['success' => false, 'message' => 'Could not join tournament'], 500);
}
?>