<?php
require_once '../includes/functions.php';
require_once '../includes/post-functions.php';
requireLogin();

$pageTitle = 'My Feed';
$db = getDB();
$userId = $_SESSION['user_id'];

// ========== HANDLE QUICK POST ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    if (validateCSRF($_POST['csrf_token'] ?? '')) {
        $content = $_POST['content'] ?? '';
        $visibility = $_POST['visibility'] ?? 'public';
        $mediaJson = $_POST['media_urls'] ?? null;
        $mediaUrls = $mediaJson ? json_decode($mediaJson, true) : null;

        if (!empty($content)) {
            $result = createMicroPost($userId, $content, $visibility, $mediaUrls);
            if ($result['success']) {
                header("Location: /user/index.php?posted=1");
                exit();
            } else {
                $message = $result['message'];
                $messageType = 'danger';
            }
        }
    }
}
$postMessage = '';
if (isset($_GET['posted'])) {
    $postMessage = 'Posted successfully!';
    $postMessageType = 'success';
}
// =========================================

// Recent posts for sidebar
$recentPosts = $db->prepare("
    SELECT id, hash_id, content, created_at, post_type 
    FROM user_posts 
    WHERE user_id = ? AND status = 'active' 
    ORDER BY created_at DESC 
    LIMIT 5
");
$recentPosts->execute([$userId]);
$recentPosts = $recentPosts->fetchAll();

$stats = getUserMicroStats($userId);

// Latest blogs (now including view_count)
$latestBlogs = $db->query("
    SELECT b.*, u.name as author_name 
    FROM blogs b 
    JOIN users u ON b.author_id = u.id 
    WHERE b.status = 'published' 
    ORDER BY b.published_at DESC 
    LIMIT 6
")->fetchAll();

$feedPosts = getFeedPosts($userId, 1, 10, 'all');

include '../includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 py-6">
    <div class="grid lg:grid-cols-3 gap-6">
        <!-- LEFT: Tabbed Content -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Tab Nav -->
            <div class="neu-card p-2 flex space-x-2 overflow-x-auto">
                <button onclick="switchTab('blogs')" id="tab-blogs" class="tab-btn flex-1 py-2 px-4 rounded-lg bg-indigo-600 text-white font-medium transition">📝 Blogs</button>
                <button onclick="switchTab('feed')" id="tab-feed" class="tab-btn flex-1 py-2 px-4 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 font-medium transition">📱 Feed</button>
                <button onclick="switchTab('other')" id="tab-other" class="tab-btn flex-1 py-2 px-4 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 font-medium transition">🎮 Games & Code</button>
            </div>

            <!-- Tab Blogs -->
            <div id="content-blogs" class="tab-content space-y-4">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-2xl font-bold">Latest Blogs</h2>
                    <a href="/blog" class="text-indigo-600 hover:underline text-sm">View All →</a>
                </div>
                <div class="grid md:grid-cols-2 gap-4">
                    <?php foreach ($latestBlogs as $blog): ?>
                    <article class="neu-card overflow-hidden hover:scale-[1.02] transition">
                        <?php if (!empty($blog['featured_image'])): ?>
                        <img src="<?php echo clean($blog['featured_image']); ?>" class="w-full h-40 object-cover">
                        <?php endif; ?>
                        <div class="p-4">
                            <h3 class="font-bold mb-2 line-clamp-2">
                                <a href="/blog/<?php echo $blog['slug']; ?>" class="hover:text-indigo-600"><?php echo clean($blog['title']); ?></a>
                            </h3>
                            <p class="text-xs text-gray-500">
                                By <?php echo clean($blog['author_name']); ?> • 
                                <?php echo date('M j', strtotime($blog['published_at'])); ?>
                                • 👁️ <?php echo number_format($blog['view_count'] ?? 0); ?> views
                            </p>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Tab Feed -->
            <div id="content-feed" class="tab-content hidden space-y-4">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-2xl font-bold">Community Feed</h2>
                    <a href="/user/feed.php" class="text-indigo-600 hover:underline text-sm">Full Feed →</a>
                </div>
                <?php if (empty($feedPosts)): ?>
                <div class="neu-card p-8 text-center text-gray-500"><p>No posts yet.</p></div>
                <?php else: ?>
                    <?php foreach ($feedPosts as $post): ?>
                    <div class="neu-card p-4">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-primary-500 to-purple-600 flex items-center justify-center text-white font-bold"><?php echo strtoupper(substr($post['author_name'], 0, 1)); ?></div>
                            <div>
                                <div class="font-bold text-sm"><?php echo clean($post['author_name']); ?></div>
                                <div class="text-xs text-gray-500"><?php echo timeAgo($post['created_at']); ?></div>
                            </div>
                        </div>
                        <p class="text-gray-800 dark:text-gray-200 mb-3 line-clamp-3"><?php echo formatPostContent($post['content']); ?></p>
                        <?php if ($post['post_type'] === 'mixed'): ?>
                            <?php $images = getPostImages($post['media_urls']); if (!empty($images)): ?>
                            <div class="grid grid-cols-2 gap-2 mb-3">
                                <?php foreach (array_slice($images,0,2) as $img): ?>
                                <img src="<?php echo htmlspecialchars($img['thumbnail']); ?>" class="w-full h-32 object-cover rounded-lg">
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <a href="/post-view.php?hash=<?php echo $post['hash_id']; ?>" class="text-indigo-600 text-sm">View Post →</a>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Tab Other -->
            <div id="content-other" class="tab-content hidden space-y-4">
                <div class="grid md:grid-cols-2 gap-4">
                    <div class="neu-card p-4 bg-gradient-to-br from-yellow-50 to-orange-50 dark:from-yellow-900/20 dark:to-orange-900/20">
                        <h3 class="font-bold text-lg mb-3">🎮 Games Hub</h3>
                        <div class="space-y-2">
                            <a href="/games/snake.php" class="block p-2 bg-white dark:bg-gray-800 rounded">🐍 Snake</a>
                            <a href="/games/ball-shooter.php" class="block p-2 bg-white dark:bg-gray-800 rounded">🎯 Ball Shooter</a>
                            <a href="/games/akku-color-crush.php" class="block p-2 bg-white dark:bg-gray-800 rounded">🎱 Color Crush</a>
                        </div>
                        <a href="/games" class="block mt-3 text-center text-sm text-indigo-600">View All →</a>
                    </div>
                    <div class="neu-card p-4 bg-gradient-to-br from-amber-50 to-yellow-50 dark:from-amber-900/20 dark:to-yellow-900/20">
                        <h3 class="font-bold text-lg mb-3">📦 Source Codes</h3>
                        <div class="space-y-2">
                            <a href="/downloads/source/" class="block p-2 bg-white dark:bg-gray-800 rounded">📁 Browse Categories</a>
                            <a href="/downloads/source/category.php?cat=php" class="block p-2 bg-white dark:bg-gray-800 rounded">🐘 PHP Scripts</a>
                        </div>
                        <a href="/downloads/source/" class="block mt-3 text-center text-sm text-indigo-600">Browse Library →</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT SIDEBAR: Quick Post + Stats -->
        <div class="space-y-6">
            <!-- Quick Post Widget (now posts to this same file) -->
            <div class="neu-card p-4 sticky top-4">
                <h3 class="font-bold text-lg mb-4">⚡ Quick Post</h3>
                <?php if (!empty($postMessage)): ?>
                    <div class="mb-3 p-2 bg-green-100 text-green-800 rounded-lg text-sm"><?php echo clean($postMessage); ?></div>
                <?php elseif (isset($message)): ?>
                    <div class="mb-3 p-2 bg-red-100 text-red-800 rounded-lg text-sm"><?php echo clean($message); ?></div>
                <?php endif; ?>
                <form id="quickPostForm" action="" method="POST" enctype="multipart/form-data" class="space-y-3">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="create">
                    <textarea name="content" rows="3" class="w-full neu-card p-3 rounded-lg resize-none" placeholder="What's on your mind?" maxlength="400" required></textarea>
                    <div class="flex items-center justify-between text-xs text-gray-500">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="file" name="media[]" multiple accept="image/*,video/*" class="hidden" id="quickMedia">
                            <span>📷 Add Media</span>
                        </label>
                        <span id="charCount">0/400</span>
                    </div>
                    <button type="submit" class="w-full neu-button py-2 rounded-lg bg-indigo-600 text-white font-semibold">Post Now</button>
                </form>
            </div>

            <!-- Recent Posts -->
            <div class="neu-card p-4">
                <h3 class="font-bold text-lg mb-4">📝 Your Recent Posts</h3>
                <?php if (empty($recentPosts)): ?>
                <p class="text-sm text-gray-500 text-center py-4">No posts yet</p>
                <?php else: ?>
                    <?php foreach ($recentPosts as $post): ?>
                    <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg mb-2">
                        <p class="text-sm line-clamp-2"><?php echo clean(substr($post['content'],0,100)); ?>...</p>
                        <div class="flex justify-between text-xs">
                            <span class="text-gray-500"><?php echo timeAgo($post['created_at']); ?></span>
                            <a href="/post-view.php?hash=<?php echo $post['hash_id']; ?>" class="text-indigo-600">View</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <a href="/user/my-posts.php" class="block mt-3 text-center text-sm text-indigo-600">View All Posts →</a>
            </div>

            <!-- Stats Card -->
            <div class="neu-card p-4 bg-gradient-to-br from-indigo-50 to-purple-50 dark:from-indigo-900/30 dark:to-purple-900/30">
                <h3 class="font-bold text-lg mb-3">Your Stats</h3>
                <div class="grid grid-cols-3 gap-2 text-center">
                    <div><div class="text-xl font-bold text-indigo-600"><?php echo $stats['posts']; ?></div><div class="text-xs">Posts</div></div>
                    <div><div class="text-xl font-bold text-purple-600"><?php echo $stats['followers']; ?></div><div class="text-xs">Followers</div></div>
                    <div><div class="text-xl font-bold text-pink-600"><?php echo $stats['following']; ?></div><div class="text-xs">Following</div></div>
                </div>
                <div class="mt-3 pt-3 border-t text-center">
                    <span class="text-amber-600 font-bold"><?php echo formatCoins(getCoinBalance($userId)); ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Tab switching
function switchTab(tabName) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('bg-indigo-600','text-white'));
    document.getElementById('content-'+tabName).classList.remove('hidden');
    document.getElementById('tab-'+tabName).classList.add('bg-indigo-600','text-white');
}

// Char count
document.querySelector('#quickPostForm textarea').addEventListener('input', function() {
    document.getElementById('charCount').textContent = this.value.length+'/400';
});

// Form submission (no need for fetch, normal POST)
</script>

<?php include '../includes/footer.php'; ?>