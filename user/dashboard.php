<?php
require_once '../includes/functions.php';
require_once '../includes/post-functions.php';
requireLogin();

$userId = $_SESSION['user_id'];
$balance = getCoinBalance($userId);
$stats = getUserMicroStats($userId);
$trendingTags = getTrendingHashtags(5);

$message = '';
$messageType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_post'])) {
    if (validateCSRF($_POST['csrf_token'] ?? '')) {
        $content = $_POST['content'] ?? '';
        $visibility = $_POST['visibility'] ?? 'public';
        $mediaJson = $_POST['media_urls'] ?? null;
        $mediaUrls = $mediaJson ? json_decode($mediaJson, true) : null;
        
        if (!empty($content)) {
            $result = createMicroPost($userId, $content, $visibility, $mediaUrls);
            if ($result['success']) {
                header("Location: /user/dashboard.php?posted=1");
                exit();
            } else {
                $message = $result['message'];
                $messageType = 'danger';
            }
        }
    }
}

if (isset($_GET['posted']) && empty($message)) {
    $message = 'Posted successfully!';
    $messageType = 'success';
}

$db = getDB();
$stmt = $db->prepare("SELECT p.*, (SELECT COUNT(*) FROM user_interactions WHERE post_id = p.id AND type = 'like' AND user_id = ?) as user_liked FROM user_posts p WHERE p.user_id = ? AND p.status = 'active' ORDER BY p.created_at DESC LIMIT 5");
$stmt->execute([$userId, $userId]);
$recentPosts = $stmt->fetchAll();

$pageTitle = 'Dashboard';
include '../includes/header.php';
?>

<div class="max-w-6xl mx-auto px-4 py-6">
    <!-- Header -->
    <div class="neu-card p-6 mb-6 bg-gradient-to-r from-primary-50 to-purple-50 dark:from-primary-900/20 dark:to-purple-900/20 border-l-4 border-primary-500">
        <div class="flex flex-col md:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <div class="w-16 h-16 rounded-full bg-gradient-to-br from-primary-500 to-purple-600 flex items-center justify-center text-2xl font-bold text-white shadow-lg">
                    <?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?>
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-gray-800 dark:text-white">Welcome, <?php echo clean($_SESSION['user_name']); ?>!</h1>
                    <p class="text-gray-600 dark:text-gray-400 text-sm">What's on your mind today?</p>
                </div>
            </div>
            <div class="flex gap-3 items-center">
                <div class="neu-card px-6 py-3 bg-amber-50 dark:bg-amber-900/20 text-center">
                    <p class="text-xs text-gray-600 dark:text-gray-400 mb-1">Balance</p>
                    <p class="text-2xl font-bold text-amber-600 dark:text-amber-400"><?php echo formatCoins($balance); ?></p>
                </div>
                <a href="/user/wallet.php" class="neu-button px-4 py-3 rounded-xl bg-green-100 text-green-700 hover:bg-green-200 transition">
                    <span class="text-xl">+</span><span class="text-sm font-bold">Add</span>
                </a>
            </div>
        </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-6">
        <!-- Left Column -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Post Box -->
            <div class="neu-card p-6">
                <h3 class="font-bold mb-4 flex items-center gap-2 text-lg">
                    <span class="text-2xl">✨</span> Quick Post
                    <span class="ml-auto text-xs font-normal text-gray-500 bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded-full">Cost: 0.010 🪙</span>
                </h3>
                
                <?php if ($message): ?>
                <div class="mb-4 p-4 rounded-xl <?php echo $messageType === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>"><?php echo clean($message); ?></div>
                <?php endif; ?>

                <form method="POST" action="" id="postForm" class="space-y-4">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="media_urls" id="mediaUrlsInput" value="">
                    
                    <div class="relative">
                        <textarea name="content" id="postContent" rows="4" class="w-full neu-card p-4 bg-transparent border-2 border-transparent focus:border-primary-500 resize-none text-gray-800 dark:text-gray-200 rounded-xl" placeholder="What's on your mind? Use #hashtags" maxlength="400" required></textarea>
                        <div class="absolute bottom-3 right-3 text-xs text-gray-400 bg-white dark:bg-gray-800 px-2 py-1 rounded-full"><span id="charCount">0</span>/400</div>
                    </div>
                    
                    <!-- Media Preview - FIXED for Video -->
                    <div id="mediaPreviewContainer" class="hidden">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Files (<span id="mediaCount">0</span>/5)</span>
                            <button type="button" onclick="clearAllMedia()" class="text-xs text-red-500 hover:text-red-700">Clear All</button>
                        </div>
                        <div class="flex flex-wrap gap-2" id="mediaPreviewList"></div>
                        <div class="text-xs text-gray-500 mt-1">Images/Docs: Max 5MB total | Video: Max 50MB</div>
                    </div>
                    
                    <!-- Progress -->
                    <div id="uploadProgressContainer" class="hidden">
                        <div class="flex items-center gap-2">
                            <div class="flex-grow"><div class="w-full bg-gray-200 rounded-full h-2"><div class="bg-gradient-to-r from-primary-500 to-purple-600 h-2 rounded-full transition-all duration-300" style="width: 0%" id="uploadProgressBar"></div></div></div>
                            <span class="text-xs text-gray-500 min-w-[60px] text-right" id="uploadProgressText">0%</span>
                        </div>
                    </div>
                    
                    <!-- Emoji -->
                    <div class="flex flex-wrap gap-2 p-3 bg-gray-50 dark:bg-gray-800/50 rounded-lg">
                        <?php foreach (['❤️','🔥','✨','😂','👍','🙏','😍','🎉','💯','👏'] as $emoji): ?>
                        <button type="button" onclick="insertEmoji('<?php echo $emoji; ?>')" class="w-10 h-10 text-xl hover:bg-white dark:hover:bg-gray-700 rounded-lg transition transform hover:scale-125"><?php echo $emoji; ?></button>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Buttons -->
                    <div class="flex flex-col sm:flex-row justify-between items-center gap-4 pt-2 border-t border-gray-200 dark:border-gray-700">
                        <div class="flex items-center gap-2 flex-wrap">
                            <select name="visibility" class="neu-button px-4 py-2 rounded-lg text-sm bg-transparent">
                                <option value="public">Public</option>
                                <option value="followers">Followers Only</option>
                                <option value="private">Private</option>
                            </select>
                            <button type="button" onclick="triggerUpload('image')" class="neu-button px-4 py-2 rounded-lg text-sm flex items-center gap-2 text-blue-600 hover:bg-blue-50" id="addImageBtn"><span>📷</span><span>Image</span></button>
                            <button type="button" onclick="triggerUpload('video')" class="neu-button px-4 py-2 rounded-lg text-sm flex items-center gap-2 text-red-600 hover:bg-red-50" id="addVideoBtn"><span>🎥</span><span>Video</span></button>
                            <button type="button" onclick="triggerUpload('document')" class="neu-button px-4 py-2 rounded-lg text-sm flex items-center gap-2 text-green-600 hover:bg-green-50" id="addDocBtn"><span>📎</span><span>File</span></button>
                            
                            <input type="file" id="imageInput" accept="image/*" multiple style="display: none;" onchange="handleFiles(this, 'image')">
                            <input type="file" id="videoInput" accept="video/*" style="display: none;" onchange="handleFiles(this, 'video')">
                            <input type="file" id="documentInput" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.rar,.txt" multiple style="display: none;" onchange="handleFiles(this, 'document')">
                        </div>
                        <button type="submit" name="quick_post" class="neu-button px-8 py-3 rounded-full font-bold text-white bg-gradient-to-r from-primary-600 to-purple-600 hover:from-primary-700 hover:to-purple-700 transition flex items-center gap-2 shadow-lg hover:shadow-xl"><span>POST</span><span>🚀</span></button>
                    </div>
                </form>
            </div>

            <!-- Recent Posts -->
            <div class="neu-card p-6">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="font-bold text-lg flex items-center gap-2"><span>📋</span> Recent Posts</h3>
                    <a href="/user/my-posts.php" class="text-sm text-primary-600 hover:underline">View All →</a>
                </div>
                
                <?php if (empty($recentPosts)): ?>
                <div class="text-center py-12 text-gray-500"><div class="text-6xl mb-4 opacity-50">📝</div><p class="text-lg font-medium">No posts yet</p></div>
                <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($recentPosts as $post): ?>
                    <?php $media = getPostMedia($post['media_urls'] ?? ''); ?>
                    <div class="p-4 rounded-xl bg-gray-50 dark:bg-gray-800/50 border-l-4 border-primary-500 hover:shadow-md transition relative group">
                        <?php if ($post['visibility'] !== 'public'): ?>
                        <span class="absolute top-2 right-2 text-xs px-2 py-1 rounded-full bg-gray-200 dark:bg-gray-700 text-gray-600"><?php echo $post['visibility'] === 'private' ? 'Private' : 'Followers'; ?></span>
                        <?php endif; ?>
                        
                        <p class="text-gray-800 dark:text-gray-200 mb-3 text-sm leading-relaxed"><?php echo formatPostContent($post['content']); ?></p>
                        
                        <?php if (!empty($media)): ?>
                        <div class="flex flex-wrap gap-2 mb-3">
                            <?php foreach (array_slice($media, 0, 3) as $item): 
                                $type = $item['type'] ?? 'image';
                                if ($type === 'video'):
                            ?>
                            <div class="relative w-20 h-20 bg-black rounded-lg flex items-center justify-center overflow-hidden">
                                <?php 
                                // FIX: Check if thumbnail exists and is not placeholder
                                $thumb = $item['thumbnail'] ?? '/assets/images/video-placeholder.jpg';
                                if ($thumb && $thumb !== '/assets/images/video-placeholder.jpg' && strpos($thumb, 'video-placeholder') === false): 
                                ?>
                                <img src="<?php echo htmlspecialchars($thumb); ?>" class="w-full h-full object-cover opacity-80" onerror="this.style.display='none'">
                                <?php endif; ?>
                                <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                                    <span class="text-2xl">▶️</span>
                                    <?php if (!empty($item['duration'])): ?>
                                    <span class="text-[10px] text-white bg-black/60 px-1 rounded mt-1"><?php echo htmlspecialchars($item['duration']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <span class="absolute top-1 left-1 text-[10px] bg-red-500 text-white px-1 rounded pointer-events-none">VIDEO</span>
                            </div>
                            <?php elseif ($type === 'document'): ?>
                            <div class="w-20 h-20 bg-gray-100 dark:bg-gray-700 rounded-lg flex flex-col items-center justify-center border-2 border-gray-300">
                                <span class="text-2xl"><?php echo $item['icon'] ?? '📄'; ?></span>
                                <span class="text-[10px] text-gray-600 dark:text-gray-400 mt-1 uppercase"><?php echo $item['ext'] ?? 'FILE'; ?></span>
                            </div>
                            <?php else: ?>
                            <a href="<?php echo htmlspecialchars($item['original']); ?>" target="_blank" class="relative group/img">
                                <img src="<?php echo htmlspecialchars($item['thumbnail']); ?>" class="w-20 h-20 object-cover rounded-lg hover:opacity-80 transition">
                            </a>
                            <?php endif; endforeach; ?>
                            <?php if (count($media) > 3): ?>
                            <div class="w-20 h-20 bg-gray-200 rounded flex items-center justify-center text-xs">+<?php echo count($media)-3; ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="flex justify-between items-center text-xs text-gray-500">
                            <span><?php echo timeAgo($post['created_at']); ?></span>
                            <div class="flex gap-4">
                                <span class="<?php echo $post['user_liked'] ? 'text-red-500' : ''; ?>">❤️ <?php echo $post['likes_count']; ?></span>
                                <span>💬 <?php echo $post['comments_count']; ?></span>
                                <button onclick="copyLink('<?php echo $post['hash_id']; ?>')" class="opacity-0 group-hover:opacity-100 transition">🔗</button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <div class="neu-card p-6">
                <h3 class="font-bold mb-4 flex items-center gap-2 text-lg"><span>🔥</span> Trending</h3>
                <?php if (empty($trendingTags)): ?>
                <p class="text-gray-500 text-sm text-center py-4">No trending topics</p>
                <?php else: ?>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($trendingTags as $tag): ?>
                    <a href="/user/feed.php?tag=<?php echo urlencode($tag['tag']); ?>" class="neu-button px-3 py-2 rounded-full text-sm hover:bg-primary-50">#<?php echo clean($tag['tag']); ?></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
let uploadedMedia = [];
const maxFiles = 5;

function updateCharCount(textarea) {
    document.getElementById('charCount').textContent = textarea.value.length;
}

function insertEmoji(emoji) {
    const textarea = document.getElementById('postContent');
    const start = textarea.selectionStart;
    textarea.value = textarea.value.substring(0, start) + emoji + textarea.value.substring(textarea.selectionEnd);
    textarea.selectionStart = textarea.selectionEnd = start + emoji.length;
    textarea.focus();
}

function triggerUpload(type) {
    const input = document.getElementById(type + 'Input');
    input.value = '';
    setTimeout(() => input.click(), 0);
}

async function handleFiles(input, type) {
    if (!input.files || input.files.length === 0) return;
    
    const files = Array.from(input.files);
    const currentCount = uploadedMedia.length;
    
    if (currentCount >= maxFiles) {
        alert('Maximum ' + maxFiles + ' files allowed');
        return;
    }
    
    document.getElementById('uploadProgressContainer').classList.remove('hidden');
    updateProgress(10, 'Preparing...');
    
    const formData = new FormData();
    formData.append('action', 'upload_media');
    formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');
    
        // Filter and validate files before adding
    for (let file of files) {
        if (type === 'image' && file.size > 2 * 1024 * 1024) {  // 2MB for images
            alert('Image ' + file.name + ' is too large. Max 2MB per image.');
            continue;
        }
        if (type === 'video' && file.size > 50 * 1024 * 1024) {  // 50MB for videos
            alert('Video ' + file.name + ' is too large. Max 50MB.');
            continue;
        }
        
        if (type === 'image') formData.append('images[]', file);
        else if (type === 'video') formData.append('videos[]', file);
        else formData.append('documents[]', file);
    }

    
    try {
        updateProgress(30, 'Uploading...');
        const response = await fetch('/api/post-actions.php', { method: 'POST', body: formData });
        updateProgress(70, 'Processing...');
        
        const text = await response.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('Server response:', text);
            throw new Error('Server returned invalid response. Check console.');
        }
        
        if (data.success) {
            uploadedMedia = [...uploadedMedia, ...data.files];
            updateMediaPreview();
            updateProgress(100, 'Done');
            document.getElementById('mediaUrlsInput').value = JSON.stringify(uploadedMedia);
            setTimeout(() => document.getElementById('uploadProgressContainer').classList.add('hidden'), 1000);
        } else {
            throw new Error(data.message || 'Upload failed');
        }
    } catch (err) {
        alert('Upload failed: ' + err.message);
        document.getElementById('uploadProgressContainer').classList.add('hidden');
    }
    input.value = '';
}

function updateProgress(percent, text) {
    document.getElementById('uploadProgressBar').style.width = percent + '%';
    document.getElementById('uploadProgressText').textContent = text;
}

function updateMediaPreview() {
    const container = document.getElementById('mediaPreviewContainer');
    const list = document.getElementById('mediaPreviewList');
    const count = document.getElementById('mediaCount');
    
    if (uploadedMedia.length === 0) {
        container.classList.add('hidden');
        return;
    }
    
    container.classList.remove('hidden');
    count.textContent = uploadedMedia.length;
    list.innerHTML = '';
    
    uploadedMedia.forEach((item, index) => {
        const div = document.createElement('div');
        div.className = 'relative group';
        const type = item.type || 'image';
        
        if (type === 'video') {
            // FIX: Better video preview with fallback
            const thumb = item.thumbnail || '/assets/images/video-placeholder.jpg';
            const hasRealThumb = thumb && thumb !== '/assets/images/video-placeholder.jpg' && !thumb.includes('placeholder');
            
            div.innerHTML = `
                <div class="w-24 h-24 bg-gray-900 rounded-lg flex items-center justify-center relative overflow-hidden border-2 border-red-200">
                    ${hasRealThumb ? `<img src="${thumb}" class="w-full h-full object-cover opacity-60" onerror="this.onerror=null; this.parentElement.innerHTML='<span class=\\'text-3xl\\'>▶️</span>';">` : '<span class="text-3xl">▶️</span>'}
                    <div class="absolute bottom-1 right-1 text-[10px] text-white bg-black/70 px-1 rounded">
                        ${item.duration ? item.duration : 'VIDEO'}
                    </div>
                </div>
                <button type="button" onclick="removeMedia(${index})" class="absolute -top-2 -right-2 w-6 h-6 bg-red-500 text-white rounded-full text-xs opacity-0 group-hover:opacity-100 transition shadow-lg">×</button>
            `;
        } else if (type === 'document') {
            div.innerHTML = `
                <div class="w-24 h-24 bg-gray-100 dark:bg-gray-700 rounded-lg flex flex-col items-center justify-center border-2 border-green-200">
                    <span class="text-3xl">${item.icon || '📄'}</span>
                    <span class="text-[10px] text-gray-600 dark:text-gray-400 mt-1 uppercase font-bold">${item.ext || 'FILE'}</span>
                    <span class="text-[8px] text-gray-500 truncate max-w-[90%] px-1 text-center">${item.name || ''}</span>
                </div>
                <button type="button" onclick="removeMedia(${index})" class="absolute -top-2 -right-2 w-6 h-6 bg-red-500 text-white rounded-full text-xs opacity-0 group-hover:opacity-100 transition shadow-lg">×</button>
            `;
        } else {
            div.innerHTML = `
                <img src="${item.thumbnail}" class="w-24 h-24 object-cover rounded-lg border-2 border-gray-200 dark:border-gray-700 shadow-sm">
                <button type="button" onclick="removeMedia(${index})" class="absolute -top-2 -right-2 w-6 h-6 bg-red-500 text-white rounded-full text-xs opacity-0 group-hover:opacity-100 transition shadow-lg">×</button>
            `;
        }
        list.appendChild(div);
    });
    
    // Update button states
    ['addImageBtn', 'addVideoBtn', 'addDocBtn'].forEach(id => {
        const btn = document.getElementById(id);
        if (btn) {
            btn.disabled = uploadedMedia.length >= maxFiles;
            btn.classList.toggle('opacity-50', uploadedMedia.length >= maxFiles);
        }
    });
}

function removeMedia(index) {
    uploadedMedia.splice(index, 1);
    updateMediaPreview();
    document.getElementById('mediaUrlsInput').value = JSON.stringify(uploadedMedia);
}

function clearAllMedia() {
    if (!confirm('Remove all files?')) return;
    uploadedMedia = [];
    updateMediaPreview();
    document.getElementById('mediaUrlsInput').value = '';
}

function copyLink(hashId) {
    navigator.clipboard.writeText(`${window.location.origin}/post/${hashId}`).then(() => {
        const toast = document.createElement('div');
        toast.className = 'fixed bottom-4 right-4 bg-gray-800 text-white px-6 py-3 rounded-lg shadow-lg z-50 animate-bounce';
        toast.textContent = 'Link copied!';
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 3000);
    });
}
</script>

<?php include '../includes/footer.php'; ?>
