<?php
require_once '../includes/functions.php';
require_once '../includes/post-functions.php';
requireLogin();

$pageTitle = 'Feed';
include '../includes/header.php';

$userId = $_SESSION['user_id'];

$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$type = $_GET['type'] ?? 'all';
$tag = $_GET['tag'] ?? null;

// Get posts
$posts = getFeedPosts($userId, $page, 10, $type, $tag);

// If following tab selected but no posts found, show message
$showFollowSuggestion = ($type === 'following' && empty($posts));

$trendingTags = getTrendingHashtags(8);
?>

<div class="max-w-3xl mx-auto px-4 py-6">
    <!-- Filter Tabs -->
    <div class="flex gap-2 mb-6 overflow-x-auto pb-2 scrollbar-hide">
        <a href="?type=all" 
           class="neu-button px-6 py-2 rounded-full text-sm font-medium whitespace-nowrap <?php echo ($type === 'all' && !$tag) ? 'bg-primary-100 text-primary-700 dark:bg-primary-900 dark:text-primary-300' : ''; ?>">
            All Posts
        </a>
        <a href="?type=following" 
           class="neu-button px-6 py-2 rounded-full text-sm font-medium whitespace-nowrap <?php echo ($type === 'following') ? 'bg-primary-100 text-primary-700 dark:bg-primary-900 dark:text-primary-300' : ''; ?>">
            Following
        </a>
        <a href="?type=trending" 
           class="neu-button px-6 py-2 rounded-full text-sm font-medium whitespace-nowrap <?php echo ($type === 'trending' || $tag) ? 'bg-primary-100 text-primary-700 dark:bg-primary-900 dark:text-primary-300' : ''; ?>">
            Trending <?php echo $tag ? '#'.clean($tag) : ''; ?>
        </a>
        <?php if ($tag): ?>
        <a href="feed.php" class="neu-button px-4 py-2 rounded-full text-sm text-red-600">Clear</a>
        <?php endif; ?>
    </div>

    <?php if ($showFollowSuggestion): ?>
    <div class="neu-card p-6 mb-6 bg-yellow-50 border-l-4 border-yellow-500 text-yellow-800">
        <p class="font-medium">You're not following anyone yet!</p>
        <p class="text-sm mt-1">Showing trending posts instead. <a href="/user/feed.php?type=trending" class="underline">Discover users</a> to follow.</p>
    </div>
    <?php endif; ?>

    <!-- Posts Feed -->
    <div class="space-y-4" id="postsFeed">
        <?php foreach ($posts as $post): ?>
        <!-- FIX: Use getPostMedia to get all media including videos -->
        <?php $media = getPostMedia($post['media_urls'] ?? null); ?>
        <article class="neu-card p-5 hover:shadow-lg transition duration-300" id="post-<?php echo $post['id']; ?>" data-post-id="<?php echo $post['id']; ?>">
            <!-- Author Header -->
            <div class="flex items-center justify-between mb-3">
                <a href="/user/@<?php echo urlencode($post['author_name']); ?>" class="flex items-center gap-3 hover:opacity-80">
                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-primary-500 to-purple-600 flex items-center justify-center text-white font-bold">
                        <?php echo strtoupper(substr($post['author_name'], 0, 1)); ?>
                    </div>
                    <div>
                        <div class="font-bold text-gray-900 dark:text-gray-100"><?php echo clean($post['author_name']); ?></div>
                        <div class="text-xs text-gray-500"><?php echo timeAgo($post['created_at']); ?></div>
                    </div>
                </a>
                
                <?php if ($post['visibility'] !== 'public'): ?>
                <span class="text-xs px-2 py-1 rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600">
                    <?php echo $post['visibility'] === 'private' ? 'Private' : 'Followers'; ?>
                </span>
                <?php endif; ?>
            </div>

            <!-- Content -->
            <a href="/post/<?php echo $post['hash_id']; ?>" class="block mb-4 text-gray-800 dark:text-gray-200 leading-relaxed hover:bg-gray-50 dark:hover:bg-gray-800/50 p-2 -mx-2 rounded-lg transition">
                <?php echo formatPostContent($post['content']); ?>
            </a>
            
            <!-- FIX: Media Grid with Video Support -->
            <?php if (!empty($media)): ?>
            <div class="mb-4">
                <?php 
                $imageCount = 0;
                $videoCount = 0;
                $totalMedia = count($media);
                
                if ($totalMedia === 1): 
                    $item = $media[0];
                    $type = $item['type'] ?? 'image';
                ?>
                    <!-- Single Media -->
                    <?php if ($type === 'video'): ?>
                        <a href="/post/<?php echo $post['hash_id']; ?>" class="block relative rounded-lg overflow-hidden bg-black aspect-video">
                            <?php if (!empty($item['thumbnail']) && $item['thumbnail'] !== '/assets/images/video-placeholder.jpg'): ?>
                            <img src="<?php echo htmlspecialchars($item['thumbnail']); ?>" class="w-full h-full object-cover opacity-80">
                            <?php endif; ?>
                            <div class="absolute inset-0 flex flex-col items-center justify-center">
                                <div class="w-16 h-16 bg-white/20 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <span class="text-4xl">▶️</span>
                                </div>
                                <?php if (!empty($item['duration'])): ?>
                                <span class="absolute bottom-3 right-3 text-white text-xs bg-black/70 px-2 py-1 rounded"><?php echo htmlspecialchars($item['duration']); ?></span>
                                <?php endif; ?>
                            </div>
                            <span class="absolute top-2 left-2 text-[10px] bg-red-500 text-white px-2 py-1 rounded font-bold">VIDEO</span>
                        </a>
                    <?php else: ?>
                        <a href="/post/<?php echo $post['hash_id']; ?>" class="block">
                            <img src="<?php echo $item['thumbnail']; ?>" class="w-full h-64 object-cover rounded-lg hover:opacity-95 transition">
                        </a>
                    <?php endif; ?>
                    
                <?php elseif ($totalMedia === 2): ?>
                    <!-- Two Media - Side by Side -->
                    <div class="grid grid-cols-2 gap-2">
                        <?php foreach ($media as $item): 
                            $type = $item['type'] ?? 'image';
                        ?>
                            <?php if ($type === 'video'): ?>
                            <a href="/post/<?php echo $post['hash_id']; ?>" class="block relative aspect-video bg-black rounded-lg overflow-hidden">
                                <?php if (!empty($item['thumbnail']) && $item['thumbnail'] !== '/assets/images/video-placeholder.jpg'): ?>
                                <img src="<?php echo htmlspecialchars($item['thumbnail']); ?>" class="w-full h-full object-cover opacity-80">
                                <?php endif; ?>
                                <div class="absolute inset-0 flex items-center justify-center">
                                    <span class="text-3xl">▶️</span>
                                </div>
                                <?php if (!empty($item['duration'])): ?>
                                <span class="absolute bottom-1 right-1 text-white text-[10px] bg-black/70 px-1 rounded"><?php echo htmlspecialchars($item['duration']); ?></span>
                                <?php endif; ?>
                            </a>
                            <?php else: ?>
                            <a href="/post/<?php echo $post['hash_id']; ?>" class="block relative aspect-video">
                                <img src="<?php echo $item['thumbnail']; ?>" class="w-full h-full object-cover rounded-lg hover:opacity-95 transition">
                            </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <!-- Multiple Media - Grid -->
                    <div class="grid grid-cols-3 gap-2">
                        <?php foreach (array_slice($media, 0, 3) as $index => $item): 
                            $type = $item['type'] ?? 'image';
                        ?>
                            <?php if ($type === 'video'): ?>
                            <a href="/post/<?php echo $post['hash_id']; ?>" class="block relative aspect-square bg-black rounded-lg overflow-hidden <?php echo $index === 0 ? 'col-span-2 row-span-2' : ''; ?>">
                                <?php if (!empty($item['thumbnail']) && $item['thumbnail'] !== '/assets/images/video-placeholder.jpg'): ?>
                                <img src="<?php echo htmlspecialchars($item['thumbnail']); ?>" class="w-full h-full object-cover opacity-80">
                                <?php endif; ?>
                                <div class="absolute inset-0 flex items-center justify-center">
                                    <span class="text-2xl">▶️</span>
                                </div>
                                <?php if (!empty($item['duration']) && $index === 0): ?>
                                <span class="absolute bottom-2 right-2 text-white text-xs bg-black/70 px-2 py-1 rounded"><?php echo htmlspecialchars($item['duration']); ?></span>
                                <?php endif; ?>
                            </a>
                            <?php else: ?>
                            <a href="/post/<?php echo $post['hash_id']; ?>" class="block relative aspect-square <?php echo $index === 0 ? 'col-span-2 row-span-2' : ''; ?>">
                                <img src="<?php echo $item['thumbnail']; ?>" class="w-full h-full object-cover rounded-lg hover:opacity-95 transition">
                            </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Actions Bar -->
            <div class="flex items-center justify-between pt-4 border-t border-gray-200 dark:border-gray-700">
                <div class="flex gap-6">
                    <button onclick="event.preventDefault(); toggleLike(<?php echo $post['id']; ?>)" 
                            class="flex items-center gap-2 text-sm transition hover:scale-105 <?php echo $post['user_liked'] ? 'text-red-500' : 'text-gray-500 hover:text-red-500'; ?>"
                            id="like-btn-<?php echo $post['id']; ?>">
                        <span class="text-xl"><?php echo $post['user_liked'] ? '❤️' : '🤍'; ?></span>
                        <span id="likes-count-<?php echo $post['id']; ?>"><?php echo $post['likes_count'] ?? 0; ?></span>
                    </button>
                    
                    <a href="/post/<?php echo $post['hash_id']; ?>#comments" class="flex items-center gap-2 text-sm text-gray-500 hover:text-blue-500 transition">
                        <span class="text-xl">💬</span>
                        <span><?php echo $post['comments_count'] ?? 0; ?></span>
                    </a>
                    
                    <button onclick="event.preventDefault(); sharePost('<?php echo $post['hash_id']; ?>')" 
                            class="flex items-center gap-2 text-sm text-gray-500 hover:text-green-500 transition">
                        <span class="text-xl">↗️</span>
                        <span class="hidden sm:inline">Share</span>
                    </button>
                </div>
                
                <a href="/post/<?php echo $post['hash_id']; ?>" class="text-xs text-gray-400 hover:text-primary-600">
                    <?php echo $post['views_count'] ?? 0; ?> views
                </a>
            </div>
        </article>
        <?php endforeach; ?>


        <!-- Load More -->
        <?php if (count($posts) >= 10): ?>
        <div class="text-center mt-6">
            <button onclick="loadMorePosts()" id="loadMoreBtn" class="neu-button px-8 py-3 rounded-full text-primary-600 font-medium">
                Load More
            </button>
        </div>
        <?php endif; ?>
    </div>

    <script>
    async function toggleLike(postId) {
        try {
            const response = await fetch('/api/post-actions.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=like&post_id=${postId}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
            });
            const data = await response.json();
            
            if (data.success) {
                const btn = document.getElementById(`like-btn-${postId}`);
                const count = document.getElementById(`likes-count-${postId}`);
                const icon = btn.querySelector('span:first-child');
                
                if (data.action === 'liked') {
                    btn.classList.remove('text-gray-500');
                    btn.classList.add('text-red-500');
                    icon.textContent = '❤️';
                    count.textContent = data.new_count;
                    showToast('Liked!');
                } else {
                    btn.classList.add('text-gray-500');
                    btn.classList.remove('text-red-500');
                    icon.textContent = '🤍';
                    count.textContent = data.new_count;
                }
            }
        } catch (err) {
            console.error('Like error:', err);
        }
    }

    function sharePost(hashId) {
        const url = `https://akkuapps.in/post/${hashId}`;
        navigator.clipboard.writeText(url).then(() => {
            showToast('Link copied to clipboard!');
        });
    }

    function showToast(message) {
        const toast = document.createElement('div');
        toast.className = 'fixed bottom-4 left-1/2 transform -translate-x-1/2 bg-gray-800 text-white px-6 py-3 rounded-full shadow-lg z-50 text-sm font-medium animate-bounce';
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 3000);
    }
    </script>

<?php include '../includes/footer.php'; ?>
