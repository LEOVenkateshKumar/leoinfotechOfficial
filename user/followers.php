<?php
require_once '../includes/functions.php';
requireLogin();

$userId = isset($_GET['id']) ? intval($_GET['id']) : $_SESSION['user_id'];
$pageTitle = 'Followers';
include '../includes/header.php';

$db = getDB();

// Get user info
$stmt = $db->prepare("SELECT name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    echo '<div class="max-w-2xl mx-auto neu-card p-12 text-center">User not found</div>';
    include '../includes/footer.php';
    exit;
}

// Get followers
$stmt = $db->prepare("
    SELECT u.id, u.name, u.avatar, u.bio, 
           (SELECT 1 FROM user_follows WHERE follower_id = ? AND following_id = u.id) as is_following
    FROM user_follows f
    JOIN users u ON f.follower_id = u.id
    WHERE f.following_id = ? AND u.status = 'active'
    ORDER BY f.created_at DESC
");
$stmt->execute([$_SESSION['user_id'], $userId]);
$followers = $stmt->fetchAll();
?>

<div class="max-w-2xl mx-auto">
    <div class="neu-card p-6 mb-6">
        <h1 class="text-2xl font-bold">👥 <?php echo clean($user['name']); ?>'s Followers</h1>
        <p class="text-gray-600 dark:text-gray-400"><?php echo count($followers); ?> followers</p>
    </div>

    <?php if (empty($followers)): ?>
    <div class="neu-card p-12 text-center text-gray-500">
        <div class="text-6xl mb-4">👤</div>
        <p>No followers yet</p>
    </div>
    <?php else: ?>
    <div class="space-y-4">
        <?php foreach ($followers as $person): ?>
        <div class="neu-card p-4 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="/user/@<?php echo urlencode($person['name']); ?>">
                    <div class="w-12 h-12 rounded-full bg-gradient-to-br from-primary-500 to-purple-600 flex items-center justify-center text-white font-bold">
                        <?php if ($person['avatar']): ?>
                            <img src="<?php echo clean($person['avatar']); ?>" class="w-full h-full rounded-full object-cover">
                        <?php else: ?>
                            <?php echo strtoupper(substr($person['name'], 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                </a>
                <div>
                    <a href="/user/@<?php echo urlencode($person['name']); ?>" class="font-bold hover:text-primary-600">
                        <?php echo clean($person['name']); ?>
                    </a>
                    <?php if ($person['bio']): ?>
                    <p class="text-xs text-gray-500 line-clamp-1"><?php echo clean($person['bio']); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ($person['id'] != $_SESSION['user_id']): ?>
            <button onclick="toggleFollow(<?php echo $person['id']; ?>, this)" 
                    class="neu-button px-4 py-2 rounded-full text-sm <?php echo $person['is_following'] ? 'bg-gray-200' : 'bg-primary-100 text-primary-700'; ?>">
                <?php echo $person['is_following'] ? 'Following' : 'Follow'; ?>
            </button>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script>
async function toggleFollow(userId, btn) {
    try {
        const response = await fetch('/api/follow.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `user_id=${userId}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
        });
        const data = await response.json();
        
        if (data.success) {
            btn.textContent = data.action === 'followed' ? 'Following' : 'Follow';
            btn.className = data.action === 'followed' 
                ? 'neu-button px-4 py-2 rounded-full text-sm bg-gray-200' 
                : 'neu-button px-4 py-2 rounded-full text-sm bg-primary-100 text-primary-700';
        }
    } catch (err) {
        console.error('Follow error:', err);
    }
}
</script>

<?php include '../includes/footer.php'; ?>
