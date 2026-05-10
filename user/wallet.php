<?php
require_once '../includes/functions.php';
requireLogin();

$pageTitle = 'My Wallet';
include '../includes/header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// Get user balances
$user = $db->prepare("SELECT coin_balance, wallet_balance FROM users WHERE id = ?");
$user->execute([$userId]);
$balances = $user->fetch();

// Get coin purchase rates
$rates = $db->query("SELECT * FROM coin_purchase_rates WHERE is_active = 1 ORDER BY rupee_amount ASC")->fetchAll();

// Get wallet transaction history
$transactions = $db->prepare("
    SELECT * FROM wallet_transactions 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 20
");
$transactions->execute([$userId]);
$walletHistory = $transactions->fetchAll();

// Get conversion rate
$coinRate = $db->query("SELECT setting_value FROM site_settings WHERE setting_key = 'coin_rate'")->fetchColumn() ?: 1;

// Handle conversion (Wallet → Coins)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'convert' && validateCSRF($_POST['csrf_token'] ?? '')) {
        $convertAmount = floatval($_POST['amount'] ?? 0);
        
        if ($convertAmount < 10) {
            $error = "Minimum ₹10 required for conversion";
        } elseif ($convertAmount > $balances['wallet_balance']) {
            $error = "Insufficient wallet balance";
        } else {
            $coinAmount = $convertAmount * $coinRate; // Simple 1:1 or use rates table
            
            try {
                $db->beginTransaction();
                
                // Deduct from wallet
                $stmt = $db->prepare("UPDATE users SET wallet_balance = wallet_balance - ? WHERE id = ?");
                $stmt->execute([$convertAmount, $userId]);
                
                // Add to coins
                $stmt = $db->prepare("UPDATE users SET coin_balance = coin_balance + ? WHERE id = ?");
                $stmt->execute([$coinAmount, $userId]);
                
                // Log wallet transaction
                $stmt = $db->prepare("INSERT INTO wallet_transactions (user_id, amount, transaction_type, coin_amount, coin_rate, status, description) VALUES (?, ?, 'converted_to_coins', ?, ?, 'completed', ?)");
                $stmt->execute([
                    $userId, 
                    -$convertAmount, 
                    $coinAmount, 
                    $coinRate, 
                    "Converted ₹$convertAmount to $coinAmount coins"
                ]);
                
                // Log coin transaction
                $stmt = $db->prepare("INSERT INTO coin_transactions (user_id, reference_type, amount, balance_after, description, created_at) VALUES (?, 'purchase', ?, (SELECT coin_balance FROM users WHERE id = ?), ?, NOW())");
                $stmt->execute([
                    $userId,
                    $coinAmount,
                    $userId,
                    "Converted from wallet: ₹$convertAmount"
                ]);
                
                $db->commit();
                $success = "Successfully converted ₹$convertAmount to $coinAmount coins!";
                
                // Refresh balances
                $user->execute([$userId]);
                $balances = $user->fetch();
                
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Conversion failed: " . $e->getMessage();
            }
        }
    }
}
?>

<div class="max-w-4xl mx-auto px-4 py-8">
    <h1 class="text-3xl font-bold mb-6">💰 My Wallet</h1>
    
    <!-- Balance Cards -->
    <div class="grid md:grid-cols-2 gap-6 mb-8">
        <!-- Real Money Wallet -->
        <div class="neu-card p-6 bg-gradient-to-br from-green-50 to-emerald-50 dark:from-green-900/20 dark:to-emerald-900/20 border-green-200 dark:border-green-800">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-3">
                    <div class="text-4xl">💵</div>
                    <div>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Wallet Balance</p>
                        <p class="text-3xl font-bold text-green-600 dark:text-green-400">₹<?php echo number_format($balances['wallet_balance'], 2); ?></p>
                    </div>
                </div>
            </div>
            <p class="text-xs text-gray-500">Real money - Add via UPI/Card</p>
        </div>
        
        <!-- Game Coins -->
        <div class="neu-card p-6 bg-gradient-to-br from-amber-50 to-yellow-50 dark:from-amber-900/20 dark:to-yellow-900/20 border-amber-200 dark:border-amber-800">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-3">
                    <div class="text-4xl">🪙</div>
                    <div>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Game Coins</p>
                        <p class="text-3xl font-bold text-amber-600 dark:text-amber-400"><?php echo formatCoins($balances['coin_balance']); ?></p>
                    </div>
                </div>
            </div>
            <p class="text-xs text-gray-500">Use for posts, likes, games</p>
        </div>
    </div>
    
    <!-- Convert Section -->
    <div class="neu-card p-6 mb-8">
        <h2 class="text-xl font-bold mb-4 flex items-center gap-2">
            <span>🔄</span> Convert Wallet to Coins
        </h2>
        
        <?php if (isset($error)): ?>
            <div class="mb-4 p-3 bg-red-100 text-red-700 rounded-lg text-sm"><?php echo clean($error); ?></div>
        <?php endif; ?>
        
        <?php if (isset($success)): ?>
            <div class="mb-4 p-3 bg-green-100 text-green-700 rounded-lg text-sm"><?php echo clean($success); ?></div>
        <?php endif; ?>
        
        <form method="POST" class="flex flex-col md:flex-row gap-4 items-end">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="convert">
            
            <div class="flex-grow">
                <label class="block text-sm font-medium mb-1">Amount to Convert (₹)</label>
                <input type="number" name="amount" min="10" step="10" max="<?php echo $balances['wallet_balance']; ?>"
                       class="neu-button w-full px-4 py-2 rounded-lg bg-transparent" 
                       placeholder="Enter amount (Min ₹10)">
            </div>
            
            <button type="submit" class="neu-button px-6 py-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 font-bold">
                Convert to Coins
            </button>
        </form>
        
        <p class="text-xs text-gray-500 mt-2">Rate: ₹1 = <?php echo $coinRate; ?> coin(s)</p>
    </div>
    
    <!-- Coin Packages -->
    <div class="neu-card p-6 mb-8">
        <h2 class="text-xl font-bold mb-4">📦 Coin Packages (Coming Soon)</h2>
        <div class="grid md:grid-cols-3 gap-4">
            <?php foreach ($rates as $rate): 
                $bonusPercent = ($rate['bonus_coins'] / $rate['coin_amount']) * 100;
            ?>
            <div class="p-4 border-2 border-gray-200 dark:border-gray-700 rounded-xl text-center opacity-50">
                <div class="text-2xl font-bold text-indigo-600">₹<?php echo $rate['rupee_amount']; ?></div>
                <div class="text-lg font-semibold"><?php echo $rate['coin_amount'] + $rate['bonus_coins']; ?> 🪙</div>
                <?php if ($rate['bonus_coins'] > 0): ?>
                    <span class="text-xs bg-green-100 text-green-800 px-2 py-1 rounded-full">+<?php echo round($bonusPercent); ?>% Bonus</span>
                <?php endif; ?>
                <button disabled class="mt-3 w-full py-2 bg-gray-300 rounded-lg text-sm">Coming Soon</button>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Transaction History -->
    <div class="neu-card p-6">
        <h2 class="text-xl font-bold mb-4">📜 Recent Transactions</h2>
        
        <?php if (empty($walletHistory)): ?>
            <p class="text-gray-500 text-center py-8">No transactions yet</p>
        <?php else: ?>
            <div class="space-y-3 max-h-96 overflow-y-auto">
                <?php foreach ($walletHistory as $tx): ?>
                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg text-sm">
                    <div class="flex items-center gap-3">
                        <span class="text-xl">
                            <?php 
                            echo match($tx['transaction_type']) {
                                'deposit' => '➕',
                                'withdrawal' => '➖',
                                'converted_to_coins' => '🔄',
                                'bonus' => '🎁',
                                default => '💰'
                            };
                            ?>
                        </span>
                        <div>
                            <p class="font-medium"><?php echo clean($tx['description']); ?></p>
                            <p class="text-xs text-gray-500"><?php echo date('M d, Y H:i', strtotime($tx['created_at'])); ?></p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="font-bold <?php echo $tx['amount'] >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                            <?php echo $tx['amount'] >= 0 ? '+' : ''; ?>₹<?php echo number_format($tx['amount'], 2); ?>
                        </p>
                        <span class="text-xs px-2 py-1 rounded-full <?php echo $tx['status'] === 'completed' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                            <?php echo ucfirst($tx['status']); ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
