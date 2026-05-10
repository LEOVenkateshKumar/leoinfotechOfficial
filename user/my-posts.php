<?php
require_once '../includes/functions.php';
require_once '../includes/post-functions.php';
requireLogin();

$userId = $_SESSION['user_id'];
$pageTitle = 'My Posts';
include '../includes/header.php';

$db = getDB();

// Handle Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_post'])) {
    if (validateCSRF($_POST['csrf_token'] ?? '')) {
        $postId = intval($_POST['post_id'] ?? 0);
        $content = $_POST['content'] ?? '';
        $visibility = $_POST['visibility'] ?? 'public';
        
        $check = $db->prepare("SELECT media_urls FROM user_posts WHERE id = ? AND user_id = ?");
        $check->execute([$postId, $userId]);
        $existing = $check->fetch();
        
        if ($existing) {
            $existingMedia = json_decode($existing['media_urls'], true) ?: [];
            $newMedia = !empty($_POST['new_media_urls']) ? json_decode($_POST['new_media_urls'], true) : [];
            $deleted = !empty($_POST['deleted_files']) ? json_decode($_POST['deleted_files'], true) : [];
            
            foreach (array_reverse($deleted) as $idx) {
                if (isset($existingMedia[$idx])) {
                    @unlink(__DIR__ . '/..' . $existingMedia[$idx]['original']);
                    array_splice($existingMedia, $idx, 1);
                }
            }
            
            $final = array_slice(array_merge($existingMedia, $newMedia), 0, 5);
            $result = editMicroPost($userId, $postId, $content, $visibility, $final);
            
            if ($result['success']) {
                header("Location: /user/my-posts.php?edited=1");
                exit();
            }
        }
    }
}

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

$total = $db->query("SELECT COUNT(*) FROM user_posts WHERE user_id = $userId AND status = 'active'")->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));

$stmt = $db->prepare("SELECT * FROM user_posts WHERE user_id = ? AND status = 'active' ORDER BY created_at DESC LIMIT ? OFFSET ?");
$stmt->execute([$userId, $perPage, $offset]);
$posts = $stmt->fetchAll();

// Edit mode
$editPost = null;
$editMedia = [];
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    $check = $db->prepare("SELECT * FROM user_posts WHERE id = ? AND user_id = ?");
    $check->execute([$editId, $userId]);
    $editPost = $check->fetch();
    if ($editPost) $editMedia = getPostMedia($editPost['media_urls']);
}
?>

<div class="max-w-5xl mx-auto px-4 py-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold">My Posts</h1>
            <p class="text-gray-500 text-sm mt-1"><?php echo $total; ?> posts total</p>
        </div>
        <a href="/user/dashboard.php" class="neu-button px-6 py-2 rounded-full bg-primary-100 text-primary-700 font-medium">+ Create New Post</a>
    </div>

    <?php if (isset($_GET['edited'])): ?>
    <div class="mb-4 p-3 bg-green-100 text-green-800 rounded-lg">Post updated successfully!</div>
    <?php endif; ?>

    <!-- Posts Grid -->
    <?php if (empty($posts)): ?>
    <div class="neu-card p-12 text-center">
        <div class="text-6xl mb-4">📝</div>
        <p class="text-gray-500">No posts yet. Create your first post!</p>
    </div>
    <?php else: ?>
    <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($posts as $post): 
            $media = getPostMedia($post['media_urls']);
        ?>
        <div class="neu-card p-4 hover:shadow-lg transition group">
            <!-- Top: Visibility + Actions -->
            <div class="flex justify-between items-start mb-3">
                <span class="text-xs px-2 py-1 rounded-full <?php echo $post['visibility'] === 'public' ? 'bg-green-100 text-green-700' : ($post['visibility'] === 'private' ? 'bg-red-100 text-red-700' : 'bg-blue-100 text-blue-700'); ?>">
                    <?php echo ucfirst($post['visibility']); ?>
                </span>
                <div class="flex gap-1 opacity-0 group-hover:opacity-100 transition">
                    <a href="?edit=<?php echo $post['id']; ?>" class="p-2 hover:bg-blue-50 text-blue-600 rounded-full" title="Edit">✏️</a>
                    <button onclick="deletePost(<?php echo $post['id']; ?>)" class="p-2 hover:bg-red-50 text-red-600 rounded-full" title="Delete">🗑️</button>
                </div>
            </div>
            
            <!-- Content Preview -->
            <a href="/post/<?php echo $post['hash_id']; ?>" class="block mb-3">
                <p class="text-gray-800 dark:text-gray-200 text-sm line-clamp-3"><?php echo formatPostContent($post['content']); ?></p>
            </a>
            
            <!-- Media Preview -->
            <?php if (!empty($media)): ?>
            <div class="flex gap-2 mb-3 overflow-x-auto pb-1">
                <?php foreach (array_slice($media, 0, 4) as $m): 
                    $t = $m['type'] ?? 'image';
                ?>
                    <?php if ($t === 'video'): ?>
                    <div class="w-16 h-16 bg-black rounded flex items-center justify-center text-white text-xs flex-shrink-0 relative">
                        <?php if (!empty($m['thumbnail']) && strpos($m['thumbnail'], 'placeholder') === false): ?>
                        <img src="<?php echo htmlspecialchars($m['thumbnail']); ?>" class="w-full h-full object-cover opacity-70">
                        <?php endif; ?>
                        <span class="absolute text-lg">▶️</span>
                    </div>
                    <?php elseif ($t === 'document'): ?>
                    <div class="w-16 h-16 bg-gray-100 rounded flex items-center justify-center text-2xl flex-shrink-0">
                        <?php echo $m['icon'] ?? '📄'; ?>
                    </div>
                    <?php else: ?>
                    <img src="<?php echo $m['thumbnail']; ?>" class="w-16 h-16 object-cover rounded flex-shrink-0">
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if (count($media) > 4): ?>
                <div class="w-16 h-16 bg-gray-200 rounded flex items-center justify-center text-xs flex-shrink-0">+<?php echo count($media)-4; ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Stats -->
            <div class="flex items-center justify-between text-xs text-gray-500 pt-3 border-t border-gray-200">
                <span><?php echo timeAgo($post['created_at']); ?></span>
                <div class="flex gap-3">
                    <span>❤️ <?php echo $post['likes_count']; ?></span>
                    <span>💬 <?php echo $post['comments_count']; ?></span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="flex justify-center gap-2 mt-6">
        <?php if ($page > 1): ?>
        <a href="?page=<?php echo $page-1; ?>" class="neu-button px-4 py-2 rounded-lg">← Prev</a>
        <?php endif; ?>
        <span class="px-4 py-2 font-medium">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
        <?php if ($page < $totalPages): ?>
        <a href="?page=<?php echo $page+1; ?>" class="neu-button px-4 py-2 rounded-lg">Next →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Edit Modal -->
<?php if ($editPost): ?>
<div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
    <div class="neu-card bg-white dark:bg-slate-800 w-full max-w-2xl max-h-[90vh] overflow-y-auto p-6">
        <h2 class="text-xl font-bold mb-4">Edit Post</h2>
        
        <form method="POST" action="">
            <?php echo csrfField(); ?>
            <input type="hidden" name="post_id" value="<?php echo $editPost['id']; ?>">
            <input type="hidden" name="new_media_urls" id="editNewMedia" value="[]">
            <input type="hidden" name="deleted_files" id="editDeleted" value="[]">
            
            <!-- Existing Media -->
            <?php if (!empty($editMedia)): ?>
            <div class="mb-4">
                <label class="text-sm font-medium mb-2 block">Current Files:</label>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($editMedia as $i => $m): 
                        $t = $m['type'] ?? 'image';
                    ?>
                    <div class="relative" id="edit-file-<?php echo $i; ?>">
                        <?php if ($t === 'video'): ?>
                        <div class="w-20 h-20 bg-black rounded flex items-center justify-center text-white">
                            <span class="text-2xl">▶️</span>
                        </div>
                        <?php elseif ($t === 'document'): ?>
                        <div class="w-20 h-20 bg-gray-100 rounded flex flex-col items-center justify-center">
                            <span class="text-2xl"><?php echo $m['icon'] ?? '📄'; ?></span>
                            <span class="text-[10px] uppercase"><?php echo $m['ext'] ?? ''; ?></span>
                        </div>
                        <?php else: ?>
                        <img src="<?php echo $m['thumbnail']; ?>" class="w-20 h-20 object-cover rounded">
                        <?php endif; ?>
                        <button type="button" onclick="removeEditFile(<?php echo $i; ?>)" class="absolute -top-2 -right-2 w-6 h-6 bg-red-500 text-white rounded-full text-xs">×</button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Add New -->
            <?php if (count($editMedia) < 5): ?>
            <div class="mb-4">
                <label class="text-sm font-medium mb-2 block">Add Files (Max <?php echo 5 - count($editMedia); ?>):</label>
                <div class="flex gap-2">
                    <button type="button" onclick="editUpload('image')" class="neu-button px-3 py-2 rounded text-sm text-blue-600">📷 Image</button>
                    <button type="button" onclick="editUpload('video')" class="neu-button px-3 py-2 rounded text-sm text-red-600">🎥 Video</button>
                    <button type="button" onclick="editUpload('document')" class="neu-button px-3 py-2 rounded text-sm text-green-600">📎 File</button>
                </div>
                <div id="editNewPreview" class="flex flex-wrap gap-2 mt-2"></div>
                
                <input type="file" id="edit-img-input" accept="image/*" multiple style="display:none" onchange="handleEditFile(this, 'image')">
                <input type="file" id="edit-vid-input" accept="video/*" style="display:none" onchange="handleEditFile(this, 'video')">
                <input type="file" id="edit-doc-input" accept=".pdf,.doc,.docx,.xls,.xlsx,.zip,.rar" multiple style="display:none" onchange="handleEditFile(this, 'document')">
            </div>
            <?php endif; ?>
            
            <div class="mb-4">
                <textarea name="content" rows="3" class="w-full neu-card p-3 rounded" required><?php echo htmlspecialchars($editPost['content']); ?></textarea>
            </div>
            
            <div class="mb-4">
                <select name="visibility" class="w-full neu-card p-3 rounded">
                    <option value="public" <?php echo $editPost['visibility'] === 'public' ? 'selected' : ''; ?>>Public</option>
                    <option value="followers" <?php echo $editPost['visibility'] === 'followers' ? 'selected' : ''; ?>>Followers Only</option>
                    <option value="private" <?php echo $editPost['visibility'] === 'private' ? 'selected' : ''; ?>>Private</option>
                </select>
            </div>
            
            <div class="flex gap-3">
                <button type="submit" name="edit_post" class="flex-1 neu-button py-2 bg-primary-100 text-primary-700 font-bold rounded">Save</button>
                <a href="my-posts.php" class="flex-1 neu-button py-2 text-center rounded">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
let editDeleted = [];
let editNewFiles = [];
const existingCount = <?php echo count($editMedia); ?>;

function removeEditFile(index) {
    editDeleted.push(index);
    document.getElementById('editDeleted').value = JSON.stringify(editDeleted);
    document.getElementById('edit-file-' + index).style.opacity = '0.3';
}

function editUpload(type) {
    const map = {image: 'edit-img-input', video: 'edit-vid-input', document: 'edit-doc-input'};
    document.getElementById(map[type]).click();
}

async function handleEditFile(input, type) {
    if (!input.files.length) return;
    
    const formData = new FormData();
    formData.append('action', 'upload_media');
    formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');
    
    for (let f of input.files) {
        if (type === 'image') formData.append('images[]', f);
        else if (type === 'video') formData.append('videos[]', f);
        else formData.append('documents[]', f);
    }
    
    const res = await fetch('/api/post-actions.php', {method: 'POST', body: formData});
    const data = await res.json();
    
    if (data.success) {
        editNewFiles = [...editNewFiles, ...data.files];
        document.getElementById('editNewMedia').value = JSON.stringify(editNewFiles);
        updateEditPreview();
    }
    input.value = '';
}

function updateEditPreview() {
    const div = document.getElementById('editNewPreview');
    div.innerHTML = editNewFiles.map((f, i) => {
        if (f.type === 'video') return `<div class="w-20 h-20 bg-black rounded flex items-center justify-center text-white relative"><span class="text-xl">▶️</span><button onclick="removeNewFile(${i})" class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 rounded-full text-xs">×</button></div>`;
        if (f.type === 'document') return `<div class="w-20 h-20 bg-gray-100 rounded flex flex-col items-center justify-center relative"><span class="text-2xl">${f.icon}</span><span class="text-[10px] uppercase">${f.ext}</span><button onclick="removeNewFile(${i})" class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 rounded-full text-xs">×</button></div>`;
        return `<div class="relative"><img src="${f.thumbnail}" class="w-20 h-20 object-cover rounded"><button onclick="removeNewFile(${i})" class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 rounded-full text-xs text-white">×</button></div>`;
    }).join('');
}

function removeNewFile(index) {
    editNewFiles.splice(index, 1);
    document.getElementById('editNewMedia').value = JSON.stringify(editNewFiles);
    updateEditPreview();
}
</script>
<?php endif; ?>

<script>
function deletePost(id) {
    if (!confirm('Delete this post?')) return;
    fetch('/api/post-actions.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=delete&post_id=${id}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
    }).then(() => location.reload());
}
</script>

<?php include '../includes/footer.php'; ?>
