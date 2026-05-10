<?php
require_once '../includes/functions.php';
requireAdmin();

$pageTitle = 'User Management';
include '../includes/header.php';

$db = getDB();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCSRF($_POST['csrf_token'] ?? '')) {
    if (isset($_POST['action']) && isset($_POST['user_id'])) {
        $userId = intval($_POST['user_id']);
        
        switch ($_POST['action']) {
            case 'block':
                $stmt = $db->prepare("UPDATE users SET status = 'banned' WHERE id = ?");
                $stmt->execute([$userId]);
                // Kill all sessions
                $stmt = $db->prepare("UPDATE user_sessions SET is_valid = 0 WHERE user_id = ?");
                $stmt->execute([$userId]);
                $message = "User blocked successfully";
                break;
                
            case 'unblock':
                $stmt = $db->prepare("UPDATE users SET status = 'active' WHERE id = ?");
                $stmt->execute([$userId]);
                $message = "User unblocked successfully";
                break;
                
            case 'add_coins':
                $amount = floatval($_POST['amount'] ?? 0);
                if ($amount > 0) {
                    addCoins($userId, $amount, 'admin_add', 0, 'Admin added coins');
                    $message = "Added " . formatCoins($amount) . " to user";
                }
                break;
        }
    }
}

// Filters
$filterStatus = $_GET['status'] ?? 'all';
$filterSearch = $_GET['search'] ?? '';
$filterDevice = $_GET['device'] ?? 'all';

// Build query
$sql = "SELECT u.*, 
        (SELECT COUNT(*) FROM user_sessions WHERE user_id = u.id AND is_valid = 1 AND expires_at > NOW()) as active_sessions,
        (SELECT COUNT(*) FROM user_posts WHERE user_id = u.id) as post_count
        FROM users u WHERE 1=1";
$params = [];

if ($filterStatus !== 'all') {
    $sql .= " AND u.status = ?";
    $params[] = $filterStatus;
}

if ($filterSearch) {
    $sql .= " AND (u.name LIKE ? OR u.email LIKE ?)";
    $params[] = "%$filterSearch%";
    $params[] = "%$filterSearch%";
}

$sql .= " ORDER BY u.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

// Get user details if ID specified
$selectedUser = null;
$selectedSessions = [];
if (isset($_GET['id'])) {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    $selectedUser = $stmt->fetch();
    
    if ($selectedUser) {
        $stmt = $db->prepare("SELECT * FROM user_sessions WHERE user_id = ? ORDER BY last_active DESC");
        $stmt->execute([$_GET['id']]);
        $selectedSessions = $stmt->fetchAll();
    }
}
?>

<div class="max-w-7xl mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold">User Management</h1>
        <a href="dashboard.php" class="neu-button px-4 py-2 rounded-lg">← Back to Dashboard</a>
    </div>

    <!-- Filters -->
    <div class="neu-card p-4 mb-6">
        <form method="GET" class="flex flex-wrap gap-4 items-end">
            <div>
                <label class="block text-sm text-gray-600 mb-1">Status</label>
                <select name="status" class="neu-button px-3 py-2 rounded bg-transparent">
                    <option value="all" <?php echo $filterStatus == 'all' ? 'selected' : ''; ?>>All</option>
                    <option value="active" <?php echo $filterStatus == 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="banned" <?php echo $filterStatus == 'banned' ? 'selected' : ''; ?>>Blocked</option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-gray-600 mb-1">Search</label>
                <input type="text" name="search" value="<?php echo clean($filterSearch); ?>" 
                       placeholder="Name or email..." class="neu-button px-3 py-2 rounded bg-transparent">
            </div>
            <button type="submit" class="neu-button px-6 py-2 rounded bg-indigo-600 text-white">Filter</button>
        </form>
    </div>

    <?php if (isset($message)): ?>
    <div class="neu-card p-4 mb-6 bg-green-50 dark:bg-green-900/20 text-green-800 dark:text-green-200 border-l-4 border-green-500">
        <?php echo clean($message); ?>
    </div>
    <?php endif; ?>

    <div class="grid lg:grid-cols-3 gap-6">
        <!-- Users List -->
        <div class="lg:col-span-2">
            <div class="neu-card overflow-hidden">
                <div class="p-4 border-b dark:border-gray-700">
                    <h3 class="font-bold">Users (<?php echo count($users); ?>)</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="p-3 text-left">User</th>
                                <th class="p-3 text-left">Coins</th>
                                <th class="p-3 text-center">Posts</th>
                                <th class="p-3 text-center">Devices</th>
                                <th class="p-3 text-center">Status</th>
                                <th class="p-3 text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                            <tr class="border-b dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 <?php echo $user['id'] == ($_GET['id'] ?? 0) ? 'bg-indigo-50 dark:bg-indigo-900/20' : ''; ?>">
                                <td class="p-3">
                                    <div class="font-medium"><?php echo clean($user['name']); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo clean($user['email']); ?></div>
                                </td>
                                <td class="p-3 font-mono text-amber-600"><?php echo number_format($user['coin_balance'], 3); ?></td>
                                <td class="p-3 text-center"><?php echo $user['post_count']; ?></td>
                                <td class="p-3 text-center">
                                    <?php if ($user['active_sessions'] > 1): ?>
                                    <span class="px-2 py-1 bg-orange-100 text-orange-800 rounded-full text-xs font-bold">
                                        <?php echo $user['active_sessions']; ?>
                                    </span>
                                    <?php else: ?>
                                    <span class="text-gray-400"><?php echo $user['active_sessions']; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3 text-center">
                                    <span class="px-2 py-1 rounded-full text-xs <?php echo $user['status'] == 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                        <?php echo $user['status']; ?>
                                    </span>
                                </td>
                                <td class="p-3 text-center">
                                    <a href="?id=<?php echo $user['id']; ?>" class="text-indigo-600 hover:underline text-xs">Manage</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- User Detail Panel -->
        <div>
            <?php if ($selectedUser): ?>
            <div class="neu-card p-6 sticky top-4">
                <h3 class="font-bold text-lg mb-4"><?php echo clean($selectedUser['name']); ?></h3>
                
                <div class="space-y-3 mb-6">
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600">Email:</span>
                        <span><?php echo clean($selectedUser['email']); ?></span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600">Coins:</span>
                        <span class="font-mono text-amber-600"><?php echo formatCoins($selectedUser['coin_balance']); ?></span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600">Joined:</span>
                        <span><?php echo date('Y-m-d', strtotime($selectedUser['created_at'])); ?></span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600">Status:</span>
                        <span class="capitalize <?php echo $selectedUser['status'] == 'active' ? 'text-green-600' : 'text-red-600'; ?>">
                            <?php echo $selectedUser['status']; ?>
                        </span>
                    </div>
                </div>

                <!-- Actions -->
                <div class="space-y-2 mb-6">
                    <form method="POST" class="flex gap-2">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="user_id" value="<?php echo $selectedUser['id']; ?>">
                        <input type="hidden" name="action" value="<?php echo $selectedUser['status'] == 'active' ? 'block' : 'unblock'; ?>">
                        <button type="submit" class="neu-button flex-1 px-4 py-2 rounded <?php echo $selectedUser['status'] == 'active' ? 'bg-red-50 text-red-600' : 'bg-green-50 text-green-600'; ?>">
                            <?php echo $selectedUser['status'] == 'active' ? '🚫 Block User' : '✅ Unblock User'; ?>
                        </button>
                    </form>
                    
                    <form method="POST" class="flex gap-2">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="user_id" value="<?php echo $selectedUser['id']; ?>">
                        <input type="hidden" name="action" value="add_coins">
                        <input type="number" name="amount" step="0.001" placeholder="0.000" class="neu-button w-24 px-2 py-2 rounded text-sm">
                        <button type="submit" class="neu-button flex-1 px-4 py-2 rounded bg-amber-50 text-amber-600">Add Coins</button>
                    </form>
                </div>

                <!-- Sessions -->
                <h4 class="font-bold mb-2 text-sm">Sessions (<?php echo count($selectedSessions); ?>)</h4>
                <div class="space-y-2 max-h-48 overflow-y-auto">
                    <?php foreach ($selectedSessions as $session): ?>
                    <div class="p-2 bg-gray-50 dark:bg-gray-800 rounded text-xs">
                        <div class="flex justify-between">
                            <span class="capitalize"><?php echo $session['device_type']; ?></span>
                            <span class="<?php echo $session['is_valid'] && strtotime($session['expires_at']) > time() ? 'text-green-600' : 'text-gray-400'; ?>">
                                <?php echo $session['is_valid'] && strtotime($session['expires_at']) > time() ? 'Active' : 'Expired'; ?>
                            </span>
                        </div>
                        <div class="text-gray-500"><?php echo $session['ip_address']; ?></div>
                        <div class="text-gray-400"><?php echo timeAgo($session['last_active']); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="neu-card p-6 text-center text-gray-500">
                Select a user to view details
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
