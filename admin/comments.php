<?php
require_once '../includes/functions.php';
requireAdmin();

$pageTitle = 'Comment Moderation';
include '../includes/header.php';

$db = getDB();

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCSRF($_POST['csrf_token'] ?? '')) {
    
    // Approve Comment
    if (isset($_POST['approve'])) {
        $stmt = $db->prepare("UPDATE blog_comments SET status = 'approved' WHERE id = ?");
        $stmt->execute([$_POST['comment_id']]);
        redirect('/admin/comments.php', 'Comment approved successfully', 'success');
    }
    
    // Reject/Delete Comment
    if (isset($_POST['reject'])) {
        $stmt = $db->prepare("UPDATE blog_comments SET status = 'rejected' WHERE id = ?");
        $stmt->execute([$_POST['comment_id']]);
        redirect('/admin/comments.php', 'Comment rejected', 'success');
    }
    
    // Admin Reply to Comment
    if (isset($_POST['reply'])) {
        $content = clean($_POST['reply_content']);
        $parentId = intval($_POST['comment_id']);
        
        // Get parent comment details
        $parent = $db->prepare("SELECT blog_id, user_id FROM blog_comments WHERE id = ?");
        $parent->execute([$parentId]);
        $parentData = $parent->fetch();
        
        if ($parentData) {
            // Insert admin reply
            $stmt = $db->prepare("
                INSERT INTO blog_comments 
                (blog_id, user_id, content, parent_id, is_reply, status, created_at) 
                VALUES (?, ?, ?, ?, 1, 'approved', NOW())
            ");
            $stmt->execute([
                $parentData['blog_id'], 
                $_SESSION['user_id'], 
                $content, 
                $parentId
            ]);
            
            // Mark parent as notified
            $db->prepare("UPDATE blog_comments SET notified = 1 WHERE id = ?")
               ->execute([$parentId]);
            
            redirect('/admin/comments.php', 'Reply posted successfully', 'success');
        }
    }
    
    // Delete Comment (Permanent)
    if (isset($_POST['delete'])) {
        $stmt = $db->prepare("DELETE FROM blog_comments WHERE id = ?");
        $stmt->execute([$_POST['comment_id']]);
        redirect('/admin/comments.php', 'Comment deleted permanently', 'success');
    }
}

// Get comments with filter
$status = $_GET['status'] ?? 'pending';
$validStatuses = ['pending', 'approved', 'rejected', 'all'];
if (!in_array($status, $validStatuses)) $status = 'pending';

// Build query
$sql = "
    SELECT c.*, b.title as blog_title, b.slug as blog_slug, 
           u.name as user_name, u.email as user_email, u.avatar
    FROM blog_comments c
    JOIN blogs b ON c.blog_id = b.id
    JOIN users u ON c.user_id = u.id
    WHERE c.is_reply = 0
";
$params = [];

if ($status !== 'all') {
    $sql .= " AND c.status = ?";
    $params[] = $status;
}

$sql .= " ORDER BY c.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$comments = $stmt->fetchAll();

// Get replies for these comments
$commentIds = array_column($comments, 'id');
$replies = [];
if (!empty($commentIds)) {
    $placeholders = implode(',', array_fill(0, count($commentIds), '?'));
    $replyStmt = $db->prepare("
        SELECT r.*, u.name as reply_name, u.avatar as reply_avatar
        FROM blog_comments r
        JOIN users u ON r.user_id = u.id
        WHERE r.parent_id IN ($placeholders) AND r.is_reply = 1
        ORDER BY r.created_at ASC
    ");
    $replyStmt->execute($commentIds);
    foreach ($replyStmt->fetchAll() as $reply) {
        $replies[$reply['parent_id']][] = $reply;
    }
}

// Counts
$counts = [
    'pending' => $db->query("SELECT COUNT(*) FROM blog_comments WHERE status = 'pending' AND is_reply = 0")->fetchColumn(),
    'approved' => $db->query("SELECT COUNT(*) FROM blog_comments WHERE status = 'approved' AND is_reply = 0")->fetchColumn(),
    'rejected' => $db->query("SELECT COUNT(*) FROM blog_comments WHERE status = 'rejected' AND is_reply = 0")->fetchColumn(),
    'all' => $db->query("SELECT COUNT(*) FROM blog_comments WHERE is_reply = 0")->fetchColumn()
];
?>

<div class="max-w-6xl mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold">💬 Comment Moderation</h1>
        <a href="/admin/dashboard.php" class="neu-button px-4 py-2 rounded-lg">← Back to Dashboard</a>
    </div>
    
    <?php showFlashMessage(); ?>
    
    <!-- Status Tabs -->
    <div class="flex flex-wrap gap-3 mb-6">
        <?php foreach (['pending' => '⏳ Pending', 'approved' => '✅ Approved', 'rejected' => '❌ Rejected', 'all' => '📋 All'] as $key => $label): ?>
        <a href="?status=<?php echo $key; ?>" 
           class="neu-button px-4 py-2 rounded-lg text-sm font-medium <?php echo $status === $key ? 'bg-indigo-100 text-indigo-800 border-indigo-300' : ''; ?>">
            <?php echo $label; ?> (<?php echo $counts[$key]; ?>)
        </a>
        <?php endforeach; ?>
    </div>
    
    <!-- Comments List -->
    <div class="space-y-4">
        <?php if (empty($comments)): ?>
        <div class="neu-card p-8 text-center text-gray-500">
            <div class="text-4xl mb-3">💬</div>
            <p>No <?php echo $status; ?> comments found.</p>
        </div>
        <?php else: ?>
        
        <?php foreach ($comments as $comment): ?>
        <div class="neu-card p-6 <?php echo $comment['status'] === 'pending' ? 'border-l-4 border-yellow-400' : ''; ?>">
            
            <!-- Comment Header -->
            <div class="flex justify-between items-start mb-4">
                <div class="flex gap-3">
                    <?php if (!empty($comment['avatar'])): ?>
                    <img src="<?php echo clean($comment['avatar']); ?>" class="w-10 h-10 rounded-full object-cover">
                    <?php else: ?>
                    <div class="w-10 h-10 rounded-full bg-indigo-100 flex items-center justify-center font-bold text-indigo-600">
                        <?php echo strtoupper(substr($comment['user_name'], 0, 1)); ?>
                    </div>
                    <?php endif; ?>
                    
                    <div>
                        <div class="font-bold text-gray-900 dark:text-white">
                            <?php echo clean($comment['user_name']); ?>
                            <?php if ($comment['user_id'] == $_SESSION['user_id']): ?>
                            <span class="text-xs text-indigo-600 ml-1">(You)</span>
                            <?php endif; ?>
                        </div>
                        <div class="text-xs text-gray-500">
                            <?php echo clean($comment['user_email']); ?> • 
                            <?php echo timeAgo($comment['created_at']); ?>
                        </div>
                        <a href="/blog/<?php echo $comment['blog_slug']; ?>" target="_blank" class="text-xs text-indigo-600 hover:underline">
                            On: <?php echo clean($comment['blog_title']); ?>
                        </a>
                    </div>
                </div>
                
                <span class="px-3 py-1 rounded-full text-xs font-bold <?php 
                    echo $comment['status'] === 'pending' ? 'bg-yellow-100 text-yellow-800' : 
                         ($comment['status'] === 'approved' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'); 
                ?>">
                    <?php echo ucfirst($comment['status']); ?>
                </span>
            </div>
            
            <!-- Comment Content -->
            <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg mb-4">
                <p class="text-gray-700 dark:text-gray-300"><?php echo nl2br(clean($comment['content'])); ?></p>
            </div>
            
            <!-- Admin Replies -->
            <?php if (!empty($replies[$comment['id']])): ?>
            <div class="ml-8 mb-4 space-y-3">
                <?php foreach ($replies[$comment['id']] as $reply): ?>
                <div class="p-3 bg-indigo-50 dark:bg-indigo-900/20 rounded-lg border-l-4 border-indigo-400">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="font-bold text-sm text-indigo-800 dark:text-indigo-200">
                            <?php echo clean($reply['reply_name']); ?>
                        </span>
                        <span class="text-xs text-gray-500">• <?php echo timeAgo($reply['created_at']); ?></span>
                        <span class="text-xs bg-indigo-100 text-indigo-800 px-2 py-0.5 rounded">Admin</span>
                    </div>
                    <p class="text-sm text-gray-700 dark:text-gray-300"><?php echo nl2br(clean($reply['content'])); ?></p>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <!-- Action Buttons -->
            <div class="flex flex-wrap gap-3 items-center">
                <?php if ($comment['status'] === 'pending'): ?>
                <form method="POST" class="inline">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="comment_id" value="<?php echo $comment['id']; ?>">
                    <button type="submit" name="approve" class="neu-button px-4 py-2 rounded-lg bg-green-600 text-white text-sm hover:bg-green-700">
                        ✓ Approve
                    </button>
                </form>
                <?php endif; ?>
                
                <?php if ($comment['status'] !== 'rejected'): ?>
                <form method="POST" class="inline">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="comment_id" value="<?php echo $comment['id']; ?>">
                    <button type="submit" name="reject" class="neu-button px-4 py-2 rounded-lg bg-yellow-600 text-white text-sm hover:bg-yellow-700">
                        ✗ Reject
                    </button>
                </form>
                <?php endif; ?>
                
                <?php if ($comment['status'] === 'rejected'): ?>
                <form method="POST" class="inline" onsubmit="return confirm('Permanently delete this comment?');">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="comment_id" value="<?php echo $comment['id']; ?>">
                    <button type="submit" name="delete" class="neu-button px-4 py-2 rounded-lg bg-red-600 text-white text-sm hover:bg-red-700">
                        🗑️ Delete
                    </button>
                </form>
                <?php endif; ?>
                
                <!-- Reply Toggle -->
                <button onclick="toggleReply(<?php echo $comment['id']; ?>)" class="neu-button px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm hover:bg-indigo-700">
                    💬 Reply
                </button>
            </div>
            
            <!-- Reply Form (Hidden by default) -->
            <div id="reply-form-<?php echo $comment['id']; ?>" class="hidden mt-4 pt-4 border-t dark:border-gray-700">
                <form method="POST" class="flex gap-3">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="comment_id" value="<?php echo $comment['id']; ?>">
                    <input type="text" name="reply_content" placeholder="Write your reply as admin..." 
                           class="neu-button flex-grow px-4 py-2 rounded-lg text-sm" required>
                    <button type="submit" name="reply" class="neu-button px-6 py-2 rounded-lg bg-indigo-600 text-white text-sm font-bold">
                        Post Reply
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php endif; ?>
    </div>
</div>

<script>
function toggleReply(id) {
    const form = document.getElementById('reply-form-' + id);
    form.classList.toggle('hidden');
    if (!form.classList.contains('hidden')) {
        form.querySelector('input[name="reply_content"]').focus();
    }
}
</script>

<?php include '../includes/footer.php'; ?>
