<?php
require_once '../includes/functions.php';
require_once '../includes/post-functions.php';

// Get username from URL
$rawUsername = $_GET['username'] ?? '';
$username = rawurldecode($rawUsername);

if (empty($username)) {
    header("Location: /user/dashboard.php");
    exit();
}

$db = getDB();

// Try to find user
$stmt = $db->prepare("SELECT id, name, email, avatar, bio, profile_visibility, coin_balance, created_at, status FROM users WHERE name = ? AND status = 'active'");
$stmt->execute([$username]);
$user = $stmt->fetch();

// Fallback with urldecode
if (!$user && $username !== urldecode($rawUsername)) {
    $username = urldecode($rawUsername);
    $stmt->execute([$username]);
    $user = $stmt->fetch();
}

if (!$user) {
    $pageTitle = 'User Not Found';
    include '../includes/header.php';
    echo '<div class="max-w-2xl mx-auto neu-card p-12 text-center mt-10"><h2 class="text-2xl font-bold mb-4">User Not Found</h2><p>This user does not exist.</p></div>';
    include '../includes/footer.php';
    exit();
}

$currentUserId = $_SESSION['user_id'] ?? 0;
$isOwner = ($currentUserId == $user['id']);

// Privacy check
$canViewFullProfile = true;
if (!$isOwner && $user['profile_visibility'] === 'private') {
    $check = $db->prepare("SELECT 1 FROM user_follows WHERE follower_id = ? AND following_id = ?");
    $check->execute([$currentUserId, $user['id']]);
    if (!$check->fetch()) {
        $canViewFullProfile = false;
    }
}

// Check following status
$isFollowing = false;
if (!$isOwner && $currentUserId) {
    $check = $db->prepare("SELECT 1 FROM user_follows WHERE follower_id = ? AND following_id = ?");
    $check->execute([$currentUserId, $user['id']]);
    $isFollowing = $check->fetch() ? true : false;
}

$stats = getUserMicroStats($user['id']);

// Get posts with media - FIX: Use getPostMedia
$posts = [];
if ($canViewFullProfile) {
    $visibilityFilter = $isOwner ? "'public', 'followers', 'private'" : ($isFollowing ? "'public', 'followers'" : "'public'");
    $stmt = $db->prepare("
        SELECT hash_id, content, created_at, likes_count, comments_count, views_count, visibility, media_urls, post_type
        FROM user_posts 
        WHERE user_id = ? AND status = 'active' 
        AND visibility IN ($visibilityFilter)
        ORDER BY created_at DESC 
        LIMIT 20
    ");
    $stmt->execute([$user['id']]);
    $posts = $stmt->fetchAll();
}

$pageTitle = $user['name'] . ' (@' . $username . ')';
include '../includes/header.php';
?>

<div class="max-w-4xl mx-auto px-4 py-6">
    <!-- Profile Header -->
    <div class="neu-card p-6 mb-6">
        <div class="flex flex-col md:flex-row gap-6">
            <div class="flex-shrink-0 flex justify-center md:justify-start">
                <div class="w-24 h-24 rounded-full bg-gradient-to-br from-primary-500 via-purple-500 to-pink-500 flex items-center justify-center text-4xl font-bold text-white shadow-lg">
                    <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                </div>
            </div>
            
            <div class="flex-grow">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-4">
                    <div>
                        <h1 class="text-2xl font-bold"><?php echo clean($user['name']); ?></h1>
                        <p class="text-gray-500 text-sm">@<?php echo clean($username); ?></p>
                        <?php if ($user['profile_visibility'] === 'private'): ?>
                        <span class="text-xs px-2 py-1 rounded bg-gray-200 dark:bg-gray-700 text-gray-600 mt-1 inline-block">Private Account</span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="flex gap-2">
                        <?php if ($isOwner): ?>
                            <a href="/user/settings.php" class="neu-button px-4 py-2 rounded-lg text-sm">Edit Profile</a>
                        <?php else: ?>
                            <button onclick="toggleFollow(<?php echo $user['id']; ?>)" id="followBtn" 
                                    class="neu-button px-6 py-2 rounded-full font-bold <?php echo $isFollowing ? 'bg-gray-200 dark:bg-gray-700' : 'bg-primary-100 dark:bg-primary-900 text-primary-700 dark:text-primary-300'; ?>">
                                <?php echo $isFollowing ? 'Following ✓' : '+ Follow'; ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if (!empty($user['bio'])): ?>
                <div class="neu-card p-3 mb-4 bg-gray-50 dark:bg-gray-800/50 border-l-4 border-primary-500">
                    <p class="text-gray-700 dark:text-gray-300 text-sm italic"><?php echo clean($user['bio']); ?></p>
                </div>
                <?php elseif ($isOwner): ?>
                <div class="mb-4 text-sm text-gray-500">
                    <a href="/user/settings.php" class="text-primary-600 hover:underline">+ Add bio</a>
                </div>
                <?php endif; ?>
                
                <div class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                    Joined <?php echo date('F Y', strtotime($user['created_at'])); ?>
                </div>
                
                <div class="flex gap-6 border-t border-gray-200 dark:border-gray-700 pt-4">
                    <div class="text-center">
                        <div class="text-xl font-bold text-gray-800 dark:text-gray-200"><?php echo $stats['posts']; ?></div>
                        <div class="text-xs text-gray-500 uppercase tracking-wide">Posts</div>
                    </div>
                    <a href="/user/followers.php?id=<?php echo $user['id']; ?>" class="text-center hover:scale-105 transition">
                        <div class="text-xl font-bold text-gray-800 dark:text-gray-200"><?php echo $stats['followers']; ?></div>
                        <div class="text-xs text-gray-500 uppercase tracking-wide">Followers</div>
                    </a>
                    <a href="/user/following.php?id=<?php echo $user['id']; ?>" class="text-center hover:scale-105 transition">
                        <div class="text-xl font-bold text-gray-800 dark:text-gray-200"><?php echo $stats['following']; ?></div>
                        <div class="text-xs text-gray-500 uppercase tracking-wide">Following</div>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Posts or Private Message -->
    <?php if (!$canViewFullProfile): ?>
    <div class="neu-card p-12 text-center">
        <div class="text-6xl mb-4">🔒</div>
        <h2 class="text-xl font-bold mb-2">Private Account</h2>
        <p class="text-gray-500">Follow this user to see their posts</p>
    </div>
    <?php else: ?>
    <div class="space-y-4">
        <h2 class="font-bold text-lg mb-4">Posts</h2>
        <?php if (empty($posts)): ?>
        <div class="neu-card p-12 text-center text-gray-500">
            <div class="text-6xl mb-4">📝</div>
            <p>No posts yet</p>
        </div>
        <?php else: ?>
            <?php foreach ($posts as $post): ?>
            <!-- FIX: Use getPostMedia for video support -->
            <?php $media = getPostMedia($post['media_urls']); ?>
            <div class="neu-card p-5 hover:scale-[1.01] transition">
                <a href="/post/<?php echo $post['hash_id']; ?>" class="block">
                    <?php if ($post['visibility'] !== 'public'): ?>
                    <div class="flex justify-end mb-2">
                        <span class="text-xs px-2 py-1 rounded bg-gray-100 dark:bg-gray-800">
                            <?php echo $post['visibility'] === 'private' ? '🔒' : '👥'; ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="text-gray-800 dark:text-gray-200 mb-3">
                        <?php echo formatPostContent($post['content']); ?>
                    </div>
                    
                    <!-- FIX: Display Media with Video Support -->
                    <?php if (!empty($media)): ?>
                    <div class="flex flex-wrap gap-2 mb-3">
                        <?php foreach (array_slice($media, 0, 3) as $item): 
                            $type = $item['type'] ?? 'image';
                        ?>
                            <?php if ($type === 'video'): ?>
                            <div class="relative w-24 h-24 bg-black rounded-lg overflow-hidden flex items-center justify-center">
                                <?php if (!empty($item['thumbnail']) && $item['thumbnail'] !== '/assets/images/video-placeholder.jpg'): ?>
                                <img src="<?php echo htmlspecialchars($item['thumbnail']); ?>" class="w-full h-full object-cover opacity-80">
                                <?php endif; ?>
                                <div class="absolute inset-0 flex items-center justify-center">
                                    <span class="text-2xl">▶️</span>
                                </div>
                                <?php if (!empty($item['duration'])): ?>
                                <span class="absolute bottom-1 right-1 text-white text-[10px] bg-black/70 px-1 rounded"><?php echo htmlspecialchars($item['duration']); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <img src="<?php echo $item['thumbnail']; ?>" class="w-24 h-24 object-cover rounded-lg hover:opacity-90 transition">
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if (count($media) > 3): ?>
                        <div class="w-24 h-24 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center text-sm text-gray-500">
                            +<?php echo count($media) - 3; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="flex justify-between items-center text-sm text-gray-500 pt-3 border-t border-gray-200 dark:border-gray-700">
                        <span><?php echo timeAgo($post['created_at']); ?></span>
                        <div class="flex gap-4">
                            <span>👁️ <?php echo $post['views_count'] ?? 0; ?></span>
                            <span>❤️ <?php echo $post['likes_count'] ?? 0; ?></span>
                            <span>💬 <?php echo $post['comments_count'] ?? 0; ?></span>
                        </div>
                    </div>
                </a>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
async function toggleFollow(userId) {
    try {
        const response = await fetch('/api/follow.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `user_id=${userId}&csrf_token=<?php echo $_SESSION['csrf_token'] ?? ''; ?>`
        });
        const data = await response.json();
        if (data.success) location.reload();
    } catch (err) {
        console.error(err);
    }
}
</script>

<?php include '../includes/footer.php'; ?>
