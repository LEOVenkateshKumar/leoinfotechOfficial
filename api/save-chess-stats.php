<?php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

function chess_json($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    chess_json(['success' => false, 'message' => 'Method not allowed'], 405);
}

if (!isLoggedIn()) {
    chess_json(['success' => false, 'message' => 'Login required'], 401);
}

if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    chess_json(['success' => false, 'message' => 'Invalid token'], 403);
}

$userId = (int)$_SESSION['user_id'];
$elo = max(100, min(3000, (int)($_POST['elo'] ?? 1200)));
$wins = max(0, (int)($_POST['wins'] ?? 0));
$losses = max(0, (int)($_POST['losses'] ?? 0));
$draws = max(0, (int)($_POST['draws'] ?? 0));
$winStreak = max(0, (int)($_POST['win_streak'] ?? 0));
$mode = ($_POST['mode'] ?? 'ai') === 'local' ? 'local' : 'ai';
$levelPlayed = max(1, min(10, (int)($_POST['level_played'] ?? 1)));
$resultCode = max(0, min(1, (float)($_POST['result_code'] ?? 0)));
$playerColor = ($_POST['player_color'] ?? 'white') === 'black' ? 'black' : 'white';
$opponentName = trim((string)($_POST['opponent_name'] ?? 'AI'));
$openingName = trim((string)($_POST['opening_name'] ?? ''));
$totalMoves = max(0, (int)($_POST['total_moves'] ?? 0));
$pgn = trim((string)($_POST['pgn'] ?? ''));
$movesSan = trim((string)($_POST['moves_san'] ?? ''));
$finalFen = trim((string)($_POST['final_fen'] ?? ''));
$analysisSummary = trim((string)($_POST['analysis_summary'] ?? ''));

try {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS user_chess_stats (
        user_id INT PRIMARY KEY,
        elo INT DEFAULT 1200,
        wins INT DEFAULT 0,
        losses INT DEFAULT 0,
        draws INT DEFAULT 0,
        win_streak INT DEFAULT 0,
        max_streak INT DEFAULT 0,
        last_played DATE NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS user_chess_games (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        mode VARCHAR(20) DEFAULT 'ai',
        level_played INT DEFAULT 1,
        result_code DECIMAL(3,1) DEFAULT 0.0,
        player_color VARCHAR(10) DEFAULT 'white',
        opponent_name VARCHAR(120) DEFAULT 'AI',
        opening_name VARCHAR(160) DEFAULT NULL,
        total_moves INT DEFAULT 0,
        pgn MEDIUMTEXT NULL,
        moves_san MEDIUMTEXT NULL,
        final_fen TEXT NULL,
        analysis_summary TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_created (user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $stmt = $db->prepare("INSERT INTO user_chess_stats (user_id, elo, wins, losses, draws, win_streak, max_streak, last_played)
        VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE())
        ON DUPLICATE KEY UPDATE
            elo = VALUES(elo),
            wins = VALUES(wins),
            losses = VALUES(losses),
            draws = VALUES(draws),
            win_streak = VALUES(win_streak),
            max_streak = GREATEST(max_streak, VALUES(max_streak)),
            last_played = CURDATE()");
    $stmt->execute([$userId, $elo, $wins, $losses, $draws, $winStreak, $winStreak]);

    if ($pgn !== '' || $movesSan !== '' || $finalFen !== '') {
        $stmt = $db->prepare("INSERT INTO user_chess_games
            (user_id, mode, level_played, result_code, player_color, opponent_name, opening_name, total_moves, pgn, moves_san, final_fen, analysis_summary)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $userId,
            $mode,
            $levelPlayed,
            $resultCode,
            $playerColor,
            $opponentName !== '' ? mb_substr($opponentName, 0, 120) : 'AI',
            $openingName !== '' ? mb_substr($openingName, 0, 160) : null,
            $totalMoves,
            $pgn !== '' ? $pgn : null,
            $movesSan !== '' ? $movesSan : null,
            $finalFen !== '' ? $finalFen : null,
            $analysisSummary !== '' ? $analysisSummary : null
        ]);
    }

    chess_json(['success' => true]);
} catch (Exception $e) {
    error_log('Chess stats save error: ' . $e->getMessage());
    chess_json(['success' => false, 'message' => 'Stats save failed'], 500);
}
?>
