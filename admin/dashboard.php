<?php
require_once '../includes/functions.php';
requireAdmin();

$pageTitle = 'Admin Dashboard';
include '../includes/header.php';

$db = getDB();

// Date ranges
$today = date('Y-m-d');
$weekAgo = date('Y-m-d', strtotime('-7 days'));

// Initialize all stats with default 0 to avoid undefined key errors
$stats = [
    'total_users' => 0,
    'new_users_today' => 0,
    'active_users' => 0,
    'blocked_users' => 0,
    'total_posts' => 0,
    'total_comments' => 0,
    'pending_comments' => 0,
    'total_coins' => 0,
    'coins_earned_today' => 0,
    'today_visits' => 0,
    'week_visits' => 0,
    'unique_visitors_today' => 0,
    'games_played_today' => 0,
    'total_games_played' => 0
];

try {
    // Basic counts
    $stats['total_users'] = $db->query("SELECT COUNT(*) FROM users")->fetchColumn() ?? 0;
    $stats['new_users_today'] = $db->query("SELECT COUNT(*) FROM users WHERE DATE(created_at) = '$today'")->fetchColumn() ?? 0;
    $stats['active_users'] = $db->query("SELECT COUNT(DISTINCT user_id) FROM user_sessions WHERE is_valid = 1 AND expires_at > NOW()")->fetchColumn() ?? 0;
    $stats['blocked_users'] = $db->query("SELECT COUNT(*) FROM users WHERE status = 'banned'")->fetchColumn() ?? 0;
    
    // Content stats
    $stats['total_posts'] = $db->query("SELECT COUNT(*) FROM blogs WHERE status = 'published'")->fetchColumn() ?? 0;
    $stats['total_comments'] = $db->query("SELECT COUNT(*) FROM blog_comments")->fetchColumn() ?? 0;
    $stats['pending_comments'] = $db->query("SELECT COUNT(*) FROM blog_comments WHERE status = 'pending'")->fetchColumn() ?? 0;
    
    // Coin stats
    $stats['total_coins'] = $db->query("SELECT COALESCE(SUM(coin_balance), 0) FROM users")->fetchColumn() ?? 0;
    $stats['coins_earned_today'] = $db->query("SELECT COALESCE(SUM(amount), 0) FROM coin_transactions WHERE DATE(created_at) = '$today' AND amount > 0")->fetchColumn() ?? 0;
    
    // Traffic stats
    $stats['today_visits'] = $db->query("SELECT COUNT(*) FROM page_analytics WHERE hit_date = '$today'")->fetchColumn() ?? 0;
    $stats['week_visits'] = $db->query("SELECT COUNT(*) FROM page_analytics WHERE hit_date >= '$weekAgo'")->fetchColumn() ?? 0;
    $stats['unique_visitors_today'] = $db->query("SELECT COUNT(DISTINCT ip_address) FROM page_analytics WHERE hit_date = '$today'")->fetchColumn() ?? 0;
    
    // Game stats
    $stats['games_played_today'] = $db->query("SELECT COUNT(*) FROM game_scores WHERE DATE(played_at) = '$today'")->fetchColumn() ?? 0;
    $stats['total_games_played'] = $db->query("SELECT COUNT(*) FROM game_scores")->fetchColumn() ?? 0;
    
} catch (PDOException $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
}

// Device Stats (safe)
$deviceStats = [];
try {
    $deviceStats = $db->query("
        SELECT device_type, COUNT(*) as count 
        FROM page_analytics 
        WHERE hit_date = '$today'
        GROUP BY device_type
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    // Fallback
    $deviceStats = ['desktop' => 0, 'mobile' => 0, 'tablet' => 0];
}

// Ensure all device types exist
foreach (['desktop', 'mobile', 'tablet'] as $type) {
    if (!isset($deviceStats[$type])) {
        $deviceStats[$type] = 0;
    }
}

// Popular Pages
$popularPages = [];
try {
    $popularPages = $db->query("
        SELECT page_url, COUNT(*) as visits, 
               COUNT(DISTINCT ip_address) as unique_visitors
        FROM page_analytics 
        WHERE hit_date >= '$weekAgo'
        GROUP BY page_url 
        ORDER BY visits DESC 
        LIMIT 10
    ")->fetchAll();
} catch (PDOException $e) {
    $popularPages = [];
}

// Hourly stats
$hourlyStats = [];
try {
    $hourlyStats = $db->query("
        SELECT hit_hour, COUNT(*) as visits 
        FROM page_analytics 
        WHERE hit_date = '$today'
        GROUP BY hit_hour
        ORDER BY hit_hour
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    $hourlyStats = [];
}

// Ensure all hours are represented
$peakHours = [];
for ($i = 0; $i < 24; $i++) {
    $peakHours[] = [
        'time' => sprintf('%02d:00', $i),
        'count' => $hourlyStats[$i] ?? 0
    ];
}

// Popular Blog Posts
$popularPosts = [];
try {
    $popularPosts = $db->query("
        SELECT b.id, b.title, b.slug, b.view_count, 
               COUNT(DISTINCT bl.id) as likes_count,
               COUNT(DISTINCT bc.id) as comments_count
        FROM blogs b
        LEFT JOIN blog_likes bl ON b.id = bl.blog_id
        LEFT JOIN blog_comments bc ON b.id = bc.blog_id AND bc.status = 'approved'
        WHERE b.status = 'published'
        GROUP BY b.id
        ORDER BY b.view_count DESC
        LIMIT 5
    ")->fetchAll();
} catch (PDOException $e) {
    $popularPosts = [];
}

// Top Games Today
$topGames = [];
try {
    $topGames = $db->query("
        SELECT game_slug, COUNT(*) as plays, 
               COALESCE(SUM(coins_earned), 0) as total_coins_given,
               MAX(score) as high_score
        FROM game_scores
        WHERE DATE(played_at) = '$today'
        GROUP BY game_slug
        ORDER BY plays DESC
    ")->fetchAll();
} catch (PDOException $e) {
    $topGames = [];
}

// Active sessions
$activeSessions = [];
try {
    $activeSessions = $db->query("
        SELECT s.*, u.name, u.email, u.avatar
        FROM user_sessions s
        JOIN users u ON s.user_id = u.id
        WHERE s.is_valid = 1 AND s.expires_at > NOW()
        ORDER BY s.last_active DESC
        LIMIT 15
    ")->fetchAll();
} catch (PDOException $e) {
    $activeSessions = [];
}
?>

<div class="max-w-7xl mx-auto px-4 py-8">
    <!-- Admin Header -->
    <div class="neu-card p-6 mb-6 bg-gradient-to-r from-indigo-600 to-purple-600 text-white">
        <div class="flex justify-between items-center">
            <div>
                <h1 class="text-3xl font-bold">Admin Dashboard</h1>
                <p class="text-indigo-100 mt-1">Platform Analytics</p>
            </div>
            <div class="text-right">
                <div class="text-3xl font-bold" id="live-clock"><?php echo date('H:i'); ?></div>
                <div class="text-sm text-indigo-200"><?php echo date('l, F j, Y'); ?></div>
            </div>
        </div>
    </div>

    <!-- Key Metrics -->
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-4 mb-6">
        <div class="neu-card p-4 text-center border-l-4 border-cyan-500">
            <div class="text-2xl font-bold text-cyan-600 dark:text-cyan-400"><?php echo number_format((int)$stats['today_visits']); ?></div>
            <div class="text-xs text-gray-600 dark:text-gray-400">Today's Hits</div>
            <div class="text-xs text-green-600 mt-1">+<?php echo number_format((int)$stats['unique_visitors_today']); ?> unique</div>
        </div>
        
        <div class="neu-card p-4 text-center border-l-4 border-blue-500">
            <div class="text-2xl font-bold text-blue-600 dark:text-blue-400"><?php echo number_format((int)$stats['week_visits']); ?></div>
            <div class="text-xs text-gray-600 dark:text-gray-400">Week Hits</div>
        </div>

        <div class="neu-card p-4 text-center border-l-4 border-green-500">
            <div class="text-2xl font-bold text-green-600 dark:text-green-400"><?php echo number_format((int)$stats['new_users_today']); ?></div>
            <div class="text-xs text-gray-600 dark:text-gray-400">New Today</div>
        </div>
        
        <div class="neu-card p-4 text-center border-l-4 border-emerald-500">
            <div class="text-2xl font-bold text-emerald-600 dark:text-emerald-400"><?php echo number_format((int)$stats['active_users']); ?></div>
            <div class="text-xs text-gray-600 dark:text-gray-400">Active Now</div>
        </div>

        <div class="neu-card p-4 text-center border-l-4 border-purple-500">
            <div class="text-2xl font-bold text-purple-600 dark:text-purple-400"><?php echo number_format((int)$stats['total_posts']); ?></div>
            <div class="text-xs text-gray-600 dark:text-gray-400">Posts</div>
        </div>
        
        <div class="neu-card p-4 text-center border-l-4 border-pink-500">
            <div class="text-2xl font-bold text-pink-600 dark:text-pink-400"><?php echo number_format((int)$stats['pending_comments']); ?></div>
            <div class="text-xs text-gray-600 dark:text-gray-400">Pending ⏳</div>
        </div>

        <div class="neu-card p-4 text-center border-l-4 border-amber-500">
            <div class="text-2xl font-bold text-amber-600 dark:text-amber-400"><?php echo number_format((float)$stats['coins_earned_today'], 2); ?></div>
            <div class="text-xs text-gray-600 dark:text-gray-400">🪙 Today</div>
        </div>
        
        <div class="neu-card p-4 text-center border-l-4 border-yellow-500">
            <div class="text-2xl font-bold text-yellow-600 dark:text-yellow-400"><?php echo number_format((int)$stats['games_played_today']); ?></div>
            <div class="text-xs text-gray-600 dark:text-gray-400">Games 🎮</div>
        </div>
    </div>

    <!-- Analytics Grid -->
    <div class="grid lg:grid-cols-3 gap-6 mb-6">
        <!-- Popular Pages -->
        <div class="neu-card p-6 lg:col-span-2">
            <h3 class="font-bold text-lg mb-4 flex items-center gap-2">🔥 Popular Pages (Last 7 Days)</h3>
            <div class="space-y-3 max-h-80 overflow-y-auto">
                <?php if (empty($popularPages)): ?>
                <p class="text-gray-500 text-center py-8">No data yet</p>
                <?php else: 
                    $maxVisits = $popularPages[0]['visits'] ?? 1;
                    foreach ($popularPages as $index => $page): 
                        $percentage = ($page['visits'] / max($maxVisits, 1)) * 100;
                        $pageName = strlen($page['page_url']) > 40 ? substr($page['page_url'], 0, 40) . '...' : $page['page_url'];
                ?>
                <div class="relative">
                    <div class="flex justify-between items-center mb-1">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                            <?php echo ($index + 1) . '. ' . htmlspecialchars($pageName); ?>
                        </span>
                        <span class="text-xs text-gray-500"><?php echo number_format((int)$page['visits']); ?> hits</span>
                    </div>
                    <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                        <div class="bg-indigo-600 h-2 rounded-full transition-all duration-500" style="width: <?php echo $percentage; ?>%"></div>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <!-- Device Breakdown -->
        <div class="space-y-6">
            <div class="neu-card p-6">
                <h3 class="font-bold text-lg mb-4 flex items-center gap-2">📱 Devices Today</h3>
                <div class="space-y-3">
                    <?php 
                    $deviceIcons = ['desktop' => '💻', 'mobile' => '📱', 'tablet' => '📱'];
                    $deviceColors = ['desktop' => 'bg-blue-500', 'mobile' => 'bg-green-500', 'tablet' => 'bg-purple-500'];
                    $totalDevices = array_sum($deviceStats);
                    foreach ($deviceStats as $device => $count): 
                        $percentage = $totalDevices > 0 ? ($count / $totalDevices) * 100 : 0;
                    ?>
                    <div class="flex items-center gap-3">
                        <span class="text-xl"><?php echo $deviceIcons[$device] ?? '📱'; ?></span>
                        <div class="flex-1">
                            <div class="flex justify-between text-sm mb-1">
                                <span class="capitalize"><?php echo $device; ?></span>
                                <span class="font-bold"><?php echo number_format($percentage, 1); ?>%</span>
                            </div>
                            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                                <div class="<?php echo $deviceColors[$device] ?? 'bg-gray-500'; ?> h-2 rounded-full" style="width: <?php echo $percentage; ?>%"></div>
                            </div>
                        </div>
                        <span class="text-sm font-mono w-12 text-right"><?php echo number_format((int)$count); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Peak Hours -->
            <div class="neu-card p-6">
                <h3 class="font-bold text-lg mb-4 flex items-center gap-2">⏰ Peak Hours Today</h3>
                <div class="grid grid-cols-6 gap-1 text-center text-xs">
                    <?php foreach ($peakHours as $hour): ?>
                    <div class="p-2 rounded <?php echo $hour['count'] > 0 ? 'bg-indigo-100 dark:bg-indigo-900 text-indigo-800 dark:text-indigo-200' : 'bg-gray-50 dark:bg-gray-800 text-gray-400'; ?>">
                        <div><?php echo $hour['time']; ?></div>
                        <div class="text-lg font-bold"><?php echo $hour['count']; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Popular Content -->
    <div class="grid lg:grid-cols-2 gap-6 mb-6">
        <!-- Most Viewed Blog Posts -->
        <div class="neu-card p-6">
            <h3 class="font-bold text-lg mb-4 flex items-center gap-2">
                📚 Most Viewed Posts
                <a href="/admin/blogs.php" class="text-sm text-indigo-600 hover:underline ml-auto">Manage →</a>
            </h3>
            <div class="space-y-4">
                <?php if (empty($popularPosts)): ?>
                <p class="text-gray-500 text-center py-8">No posts yet</p>
                <?php else: foreach ($popularPosts as $post): ?>
                <div class="flex items-start gap-4 p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900 rounded-lg flex items-center justify-center text-xl flex-shrink-0">📝</div>
                    <div class="flex-1 min-w-0">
                        <h4 class="font-medium text-gray-900 dark:text-white truncate"><?php echo clean($post['title']); ?></h4>
                        <div class="flex gap-4 mt-1 text-xs text-gray-500">
                            <span>👁️ <?php echo number_format((int)$post['view_count']); ?> views</span>
                            <span>❤️ <?php echo (int)$post['likes_count']; ?> likes</span>
                        </div>
                    </div>
                    <a href="/blog/<?php echo $post['slug']; ?>" target="_blank" class="text-indigo-600 hover:text-indigo-800 text-sm">View</a>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <!-- Top Games Today -->
        <div class="neu-card p-6">
            <h3 class="font-bold text-lg mb-4 flex items-center gap-2">
                🎮 Top Games Today
                <a href="/games" class="text-sm text-indigo-600 hover:underline ml-auto" target="_blank">Play →</a>
            </h3>
            <div class="space-y-4">
                <?php if (empty($topGames)): ?>
                <p class="text-gray-500 text-center py-8">No games played today</p>
                <?php else: foreach ($topGames as $game): 
                    $gameNames = [
                        'snake' => '🐍 Snake Classic',
                        'akku-ball-shooter' => '🎯 Ball Shooter',
                        'akku-color-crush' => '🎱 Color Crush'
                    ];
                    $gameName = $gameNames[$game['game_slug']] ?? '🎮 ' . $game['game_slug'];
                ?>
                <div class="flex items-center gap-4 p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <div class="text-3xl flex-shrink-0"><?php echo explode(' ', $gameName)[0]; ?></div>
                    <div class="flex-1">
                        <h4 class="font-medium text-gray-900 dark:text-white"><?php echo preg_replace('/^[^\s]+\s/', '', $gameName); ?></h4>
                        <div class="flex gap-4 mt-1 text-xs text-gray-500">
                            <span><?php echo (int)$game['plays']; ?> plays</span>
                            <span>🏆 <?php echo (int)$game['high_score']; ?> high score</span>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-sm font-bold text-amber-600">+<?php echo number_format((float)$game['total_coins_given'], 3); ?> 🪙</div>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
    <!-- Download Stats -->
    <div class="grid lg:grid-cols-2 gap-6 mb-6">
        <!-- Total Downloads Card -->
        <div class="neu-card p-6">
            <h3 class="font-bold text-lg mb-4 flex items-center gap-2">📥 Download Statistics</h3>
            <?php
            $totalDownloads = $db->query("SELECT SUM(downloads_count) FROM source_items")->fetchColumn() ?: 0;
            $totalItems = $db->query("SELECT COUNT(*) FROM source_items WHERE status='active'")->fetchColumn() ?: 0;
            $topDownloads = $db->query("
                SELECT title, downloads_count 
                FROM source_items 
                WHERE status='active' 
                ORDER BY downloads_count DESC 
                LIMIT 5
            ")->fetchAll();
            ?>
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div class="text-center p-3 bg-indigo-50 dark:bg-indigo-900/30 rounded">
                    <div class="text-3xl font-bold text-indigo-600 dark:text-indigo-400"><?= number_format($totalDownloads) ?></div>
                    <div class="text-xs text-gray-600 dark:text-gray-400">Total Downloads</div>
                </div>
                <div class="text-center p-3 bg-amber-50 dark:bg-amber-900/30 rounded">
                    <div class="text-3xl font-bold text-amber-600 dark:text-amber-400"><?= $totalItems ?></div>
                    <div class="text-xs text-gray-600 dark:text-gray-400">Active Items</div>
                </div>
            </div>
            <?php if (!empty($topDownloads)): ?>
            <h4 class="font-medium mb-2">Most Downloaded</h4>
            <div class="space-y-2">
                <?php foreach ($topDownloads as $item): ?>
                <div class="flex justify-between items-center text-sm">
                    <span class="truncate max-w-[200px]"><?= htmlspecialchars($item['title']) ?></span>
                    <span class="font-mono text-indigo-600"><?= number_format($item['downloads_count']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- You can add another card here if needed -->
    </div>

    <!-- Quick Actions -->
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-6 mb-6">
            <!-- Analytics -->
            <a href="/admin/analytics.php" class="neu-card p-6 hover:scale-[1.02] transition block group border-l-4 border-cyan-500">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="font-bold text-lg group-hover:text-cyan-600 transition">📊 Analytics</h3>
                        <p class="text-sm text-gray-600">Traffic & Insights</p>
                    </div>
                    <span class="text-3xl group-hover:scale-110 transition">📈</span>
                </div>
                <div class="mt-2 text-xs text-cyan-600 font-medium">
                    <?php echo number_format($stats['today_visits'] ?? 0); ?> visits today
                </div>
            </a>

            <!-- Users -->
            <a href="/admin/users.php" class="neu-card p-6 hover:scale-[1.02] transition block group">
                <h3 class="font-bold text-lg group-hover:text-indigo-600">👥 Users</h3>
                <p class="text-sm text-gray-600">Manage users</p>
            </a>

            <!-- Blog -->
            <a href="/admin/blogs.php" class="neu-card p-6 hover:scale-[1.02] transition block group">
                <h3 class="font-bold text-lg group-hover:text-indigo-600">📝 Blog</h3>
                <p class="text-sm text-gray-600">Create/Edit posts</p>
            </a>

            <!-- Comments -->
            <a href="/admin/comments.php" class="neu-card p-6 hover:scale-[1.02] transition block group relative">
                <h3 class="font-bold text-lg group-hover:text-indigo-600">💬 Comments</h3>
                <p class="text-sm text-gray-600">Moderate & reply</p>
                <?php if (($pendingComments ?? 0) > 0): ?>
                <span class="absolute top-4 right-4 bg-red-500 text-white text-xs w-6 h-6 rounded-full flex items-center justify-center animate-pulse">
                    <?php echo $pendingComments; ?>
                </span>
                <?php endif; ?>
            </a>

            <!-- Settings -->
            <a href="/admin/settings.php" class="neu-card p-6 hover:scale-[1.02] transition block group">
                <h3 class="font-bold text-lg group-hover:text-indigo-600">⚙️ Settings</h3>
                <p class="text-sm text-gray-600">Site configuration</p>
            </a>

            <!-- NEW: Source Code Management -->
            <a href="/admin/source-categories.php" class="neu-card p-6 hover:scale-[1.02] transition block group border-l-4 border-amber-500">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="font-bold text-lg group-hover:text-amber-600 transition">📦 Source Code</h3>
                        <p class="text-sm text-gray-600">Categories & Items</p>
                    </div>
                    <span class="text-3xl group-hover:scale-110 transition">📁</span>
                </div>
                <div class="mt-2 text-xs text-amber-600 font-medium">
                    Manage downloads library
                </div>
            </a>
        </div>

    <!-- Active Users -->
    <?php if (count($activeSessions) > 0): ?>
    <div class="neu-card p-6">
        <h3 class="font-bold text-lg mb-4 flex items-center gap-2">🔵 Active Sessions (<?php echo count($activeSessions); ?>)</h3>
        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php foreach ($activeSessions as $session): ?>
            <div class="flex items-center gap-3 p-3 bg-gray-50 dark:bg-gray-800 rounded-lg border border-green-200 dark:border-green-800">
                <div class="w-10 h-10 rounded-full bg-indigo-100 flex items-center justify-center font-bold text-indigo-600">
                    <?php echo strtoupper(substr($session['name'], 0, 1)); ?>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="font-medium text-sm truncate"><?php echo clean($session['name']); ?></div>
                    <div class="text-xs text-gray-500"><?php echo ucfirst($session['device_type']); ?> • <?php echo timeAgo($session['last_active']); ?></div>
                </div>
                <div class="w-2 h-2 rounded-full bg-green-500 animate-pulse"></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function updateClock() {
    const now = new Date();
    document.getElementById('live-clock').textContent = now.toLocaleTimeString('en-US', { hour12: false, hour: '2-digit', minute: '2-digit' });
}
setInterval(updateClock, 1000);
updateClock();
</script>

<?php include '../includes/footer.php'; ?>
