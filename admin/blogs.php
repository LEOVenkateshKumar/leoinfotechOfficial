<?php
require_once '../includes/functions.php';
requireAdmin();

$pageTitle = 'Blog Management';
$db = getDB();

// ─── Helper: Create upload folder from slug ────────────────────────────────
function createBlogFolder($slug) {
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '-', strtolower($slug));
    $safe = trim($safe, '-');
    if (strlen($safe) > 50) $safe = substr($safe, 0, 50);
    if (empty($safe)) $safe = 'post-' . time();
    $base = realpath(__DIR__ . '/..') . '/uploads/blogs/';
    if (!is_dir($base)) @mkdir($base, 0755, true);
    $folder = $safe; $n = 1;
    while (is_dir($base . $folder)) { $folder = $safe . '-' . $n++; if ($n > 100) break; }
    return $folder;
}

// ─── Delete Blog ───────────────────────────────────────────────────────────
if (isset($_GET['delete']) && is_numeric($_GET['delete']) && validateCSRF($_GET['csrf'] ?? '')) {
    try {
        $id = intval($_GET['delete']);
        $folder = $db->query("SELECT upload_folder FROM blogs WHERE id = $id")->fetchColumn();
        $db->prepare("DELETE FROM blogs WHERE id = ?")->execute([$id]);
        if ($folder) {
            $fp = realpath(__DIR__ . '/..') . '/uploads/blogs/' . $folder;
            if (is_dir($fp)) { array_map('unlink', glob("$fp/*")); @rmdir($fp); }
        }
        $db->prepare("DELETE FROM blog_media WHERE blog_id = ?")->execute([$id]);
        redirect('/admin/blogs.php', 'Blog deleted', 'success');
    } catch (PDOException $e) { $error = "Delete failed: " . $e->getMessage(); }
}

// ─── Delete Gallery Image ──────────────────────────────────────────────────
if (isset($_GET['delete_media']) && is_numeric($_GET['delete_media']) && validateCSRF($_GET['csrf'] ?? '')) {
    try {
        $mediaId = intval($_GET['delete_media']);
        $editId  = intval($_GET['edit'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM blog_media WHERE id = ? AND blog_id = ?");
        $stmt->execute([$mediaId, $editId]);
        $media = $stmt->fetch();
        if ($media) {
            $fp = realpath(__DIR__ . '/..') . '/' . ltrim($media['media_url'], '/');
            if (file_exists($fp)) @unlink($fp);
            $db->prepare("DELETE FROM blog_media WHERE id = ?")->execute([$mediaId]);
            redirect('/admin/blogs.php?edit=' . $editId, 'Image deleted', 'success');
        }
    } catch (PDOException $e) { $error = "Delete failed: " . $e->getMessage(); }
}

// ─── Gallery-only Upload (AJAX) ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_gallery_only']) && validateCSRF($_POST['csrf_token'] ?? '')) {
    $blogId = intval($_POST['blog_id'] ?? 0);
    $allowed = ['jpg','jpeg','png','gif','webp'];
    $uploaded = []; $errors = [];

    if ($blogId > 0) {
        $eb = $db->query("SELECT * FROM blogs WHERE id = $blogId")->fetch();
        if (!$eb) { $errors[] = "Blog not found"; } else { $folderName = $eb['upload_folder']; }
    } else {
        $folderName = createBlogFolder(clean($_POST['temp_slug'] ?? 'temp-' . time()));
    }

    if (empty($errors)) {
        $uploadDir = realpath(__DIR__ . '/..') . '/uploads/blogs/' . $folderName . '/';
        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
        $maxPos = $db->query("SELECT COALESCE(MAX(position),-1) FROM blog_media WHERE blog_id = " . ($blogId ?: 0))->fetchColumn();
        if (!empty($_FILES['gallery']['tmp_name'][0])) {
            foreach ($_FILES['gallery']['tmp_name'] as $k => $tmp) {
                if ($_FILES['gallery']['error'][$k] !== UPLOAD_ERR_OK) continue;
                $ext = strtolower(pathinfo($_FILES['gallery']['name'][$k], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed)) { $errors[] = "Invalid: " . $_FILES['gallery']['name'][$k]; continue; }
                $pos = ++$maxPos;
                $fn  = 'gallery_' . $pos . '_' . time() . '.' . $ext;
                if (move_uploaded_file($tmp, $uploadDir . $fn)) {
                    $url = '/uploads/blogs/' . $folderName . '/' . $fn;
                    $db->prepare("INSERT INTO blog_media (blog_id, media_type, media_url, position) VALUES (?, 'image', ?, ?)")->execute([$blogId ?: 0, $url, $pos]);
                    $uploaded[] = ['id' => $db->lastInsertId(), 'url' => $url, 'position' => $pos];
                } else { $errors[] = "Failed: " . $_FILES['gallery']['name'][$k]; }
            }
        }
    }
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => empty($errors), 'uploaded' => $uploaded, 'errors' => $errors, 'folder' => $folderName ?? '']);
        exit;
    }
    $msg = empty($errors) ? "Gallery uploaded!" : "Some failed: " . implode(', ', $errors);
    if ($blogId) redirect('/admin/blogs.php?edit=' . $blogId, $msg, empty($errors) ? 'success' : 'warning');
    else { $_SESSION['temp_folder'] = $folderName; redirect('/admin/blogs.php?new=1', $msg, 'success'); }
}

// ─── Load editing data ─────────────────────────────────────────────────────
$editBlog  = null; $blogMedia = [];
$blogId    = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
if ($blogId > 0) {
    $stmt = $db->prepare("SELECT * FROM blogs WHERE id = ?");
    $stmt->execute([$blogId]);
    $editBlog = $stmt->fetch();
    if ($editBlog) $blogMedia = $db->query("SELECT * FROM blog_media WHERE blog_id = $blogId ORDER BY position")->fetchAll();
} elseif (isset($_GET['new'], $_SESSION['temp_folder'])) {
    $tempFolder = $_SESSION['temp_folder']; unset($_SESSION['temp_folder']);
    $blogMedia  = $db->query("SELECT * FROM blog_media WHERE blog_id = 0 AND media_url LIKE '%$tempFolder%' ORDER BY position")->fetchAll();
}

// ─── Create / Update ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['upload_gallery_only']) && validateCSRF($_POST['csrf_token'] ?? '')) {
    $allowed  = ['jpg','jpeg','png','gif','webp'];
    $title    = clean($_POST['title'] ?? '');
    $content  = $_POST['content'] ?? '';
    $excerpt  = clean($_POST['excerpt'] ?? '');
    $metaTitle= clean($_POST['meta_title'] ?? '');
    $metaDesc = clean($_POST['meta_description'] ?? '');
    $tags     = json_encode(array_map('trim', explode(',', clean($_POST['tags'] ?? ''))));
    $status   = $_POST['status'] ?? 'draft';
    $blogId   = $_POST['blog_id'] ?? null;
    $slug     = clean($_POST['slug'] ?? '');
    if (empty($slug)) $slug = createSlug($title);

    // Folder
    if ($editBlog && !empty($editBlog['upload_folder'])) $folderName = $editBlog['upload_folder'];
    elseif (!empty($_POST['temp_folder']) && is_dir(realpath(__DIR__ . '/..') . '/uploads/blogs/' . $_POST['temp_folder'])) $folderName = $_POST['temp_folder'];
    else $folderName = createBlogFolder($slug);

    $uploadDir = realpath(__DIR__ . '/..') . '/uploads/blogs/' . $folderName . '/';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

    // Featured image
    $featuredImage = $editBlog['featured_image'] ?? null;
    if (isset($_POST['remove_featured']) && $_POST['remove_featured'] == '1') {
        if ($featuredImage) {
            $bp = realpath(__DIR__ . '/..') . '/' . ltrim($featuredImage, '/');
            $pi = pathinfo($bp);
            foreach (['thumb','medium','large'] as $s) @unlink($pi['dirname'].'/'.$pi['filename'].'_'.$s.'.'.$pi['extension']);
            @unlink($bp);
        }
        $featuredImage = null;
    }
    if (!empty($_FILES['featured_image']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['featured_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $tmp = $uploadDir . 'temp_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['featured_image']['tmp_name'], $tmp)) {
                if ($featuredImage) {
                    $ob = realpath(__DIR__ . '/..') . '/' . ltrim($featuredImage, '/');
                    $oi = pathinfo($ob);
                    foreach (['thumb','medium','large'] as $s) @unlink($oi['dirname'].'/'.$oi['filename'].'_'.$s.'.'.$oi['extension']);
                    @unlink($ob);
                }
                $bn = 'featured_' . time(); $bp = $uploadDir . $bn;
                optimizeImage($tmp, $bp . '.' . $ext, 1200, 85);
                optimizeImage($tmp, $bp . '_thumb.' . $ext, 300, 80);
                optimizeImage($tmp, $bp . '_medium.' . $ext, 600, 85);
                $featuredImage = '/uploads/blogs/' . $folderName . '/' . $bn . '.' . $ext;
                @unlink($tmp);
            }
        }
    }

    // Gallery images
    if (!empty($_FILES['gallery']['tmp_name'][0])) {
        foreach ($_FILES['gallery']['tmp_name'] as $k => $tmpName) {
            if ($_FILES['gallery']['error'][$k] !== UPLOAD_ERR_OK) continue;
            $ext = strtolower(pathinfo($_FILES['gallery']['name'][$k], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) continue;
            $tf = $uploadDir . 'temp_g_' . $k . '_' . time() . '.' . $ext;
            if (move_uploaded_file($tmpName, $tf)) {
                $bn = 'gallery_' . time() . '_' . $k; $bp = $uploadDir . $bn;
                optimizeImage($tf, $bp . '.' . $ext, 1200, 85);
                $url = '/uploads/blogs/' . $folderName . '/' . $bn . '.' . $ext;
                $db->prepare("INSERT INTO blog_media (blog_id, media_type, media_url, position) VALUES (?, 'image', ?, ?)")->execute([$blogId ?: 0, $url, $k]);
                @unlink($tf);
            }
        }
    }

    // Audio file upload
    if (!empty($_FILES['audio_file']['tmp_name'])) {
        $ae = strtolower(pathinfo($_FILES['audio_file']['name'], PATHINFO_EXTENSION));
        if (in_array($ae, ['mp3','ogg','wav','m4a'])) {
            $an = 'audio_' . time() . '.' . $ae;
            if (move_uploaded_file($_FILES['audio_file']['tmp_name'], $uploadDir . $an)) {
                $au = '/uploads/blogs/' . $folderName . '/' . $an;
                $db->prepare("INSERT INTO blog_media (blog_id, media_type, media_url, position) VALUES (?, 'audio', ?, 300)")->execute([$blogId ?: 0, $au]);
            }
        }
    }

    // YouTube / Video / Audio URLs
    foreach (['youtube_urls' => 'youtube', 'video_urls' => 'video', 'audio_urls' => 'audio'] as $field => $mtype) {
        if (!empty($_POST[$field])) {
            $pos = ['youtube' => 100, 'video' => 200, 'audio' => 300][$mtype];
            foreach ((array)$_POST[$field] as $i => $url) {
                if (!empty($url) && filter_var($url, FILTER_VALIDATE_URL)) {
                    $db->prepare("INSERT INTO blog_media (blog_id, media_type, media_url, position) VALUES (?, ?, ?, ?)")->execute([$blogId ?: 0, $mtype, $url, $pos + $i]);
                }
            }
        }
    }

    try {
        if ($blogId) {
            $db->prepare("UPDATE blogs SET title=?,slug=?,content=?,excerpt=?,meta_title=?,meta_description=?,tags=?,status=?,featured_image=?,upload_folder=?,updated_at=NOW() WHERE id=?")
               ->execute([$title,$slug,$content,$excerpt,$metaTitle,$metaDesc,$tags,$status,$featuredImage,$folderName,$blogId]);
            $db->query("UPDATE blog_media SET blog_id=$blogId WHERE blog_id=0 AND (media_url LIKE '%$folderName%' OR media_type IN ('youtube','video','audio'))");
            redirect('/admin/blogs.php?edit=' . $blogId, 'Blog updated!', 'success');
        } else {
            $db->prepare("INSERT INTO blogs (title,slug,content,excerpt,meta_title,meta_description,tags,status,author_id,featured_image,upload_folder,published_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())")
               ->execute([$title,$slug,$content,$excerpt,$metaTitle,$metaDesc,$tags,$status,$_SESSION['user_id'],$featuredImage,$folderName]);
            $newId = $db->lastInsertId();
            $db->query("UPDATE blog_media SET blog_id=$newId WHERE blog_id=0 AND (media_url LIKE '%$folderName%' OR media_type IN ('youtube','video','audio'))");
            redirect('/admin/blogs.php?edit=' . $newId, 'Blog created!', 'success');
        }
    } catch (PDOException $e) { $error = "Error: " . $e->getMessage(); }
}

// ─── Data for display ──────────────────────────────────────────────────────
$blogs           = $db->query("SELECT b.*, u.name as author_name FROM blogs b JOIN users u ON b.author_id = u.id ORDER BY b.created_at DESC")->fetchAll();
$pendingComments = $db->query("SELECT COUNT(*) FROM blog_comments WHERE status = 'pending'")->fetchColumn();

$galleryImages = array_values(array_filter($blogMedia, fn($m) => $m['media_type'] === 'image'));
$youtubeVideos = array_values(array_filter($blogMedia, fn($m) => $m['media_type'] === 'youtube'));
$videoUrls     = array_values(array_filter($blogMedia, fn($m) => $m['media_type'] === 'video'));
$audioMedia    = array_values(array_filter($blogMedia, fn($m) => $m['media_type'] === 'audio'));

function getTagsString($json) {
    if (empty($json)) return '';
    $arr = json_decode($json, true);
    return is_array($arr) ? implode(', ', $arr) : '';
}

include '../includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 py-6">

    <!-- Page Header -->
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">📝 Blog Management</h1>
        <div class="flex gap-2">
            <a href="/admin/comments.php" class="neu-button px-4 py-2 rounded-lg text-sm relative">
                💬 Comments
                <?php if ($pendingComments > 0): ?>
                <span class="absolute -top-2 -right-2 bg-red-500 text-white text-xs w-5 h-5 rounded-full flex items-center justify-center"><?php echo $pendingComments; ?></span>
                <?php endif; ?>
            </a>
            <a href="/blog" target="_blank" class="neu-button px-4 py-2 rounded-lg text-sm">View Blog ↗</a>
        </div>
    </div>

    <?php if (isset($error)): ?>
    <div class="neu-card p-4 mb-4 bg-red-50 text-red-800 border-l-4 border-red-500"><?php echo clean($error); ?></div>
    <?php endif; ?>
    <?php showFlashMessage(); ?>

    <!-- ═══════════════════════════════════════════════════════ -->
    <!-- CREATE / EDIT FORM                                      -->
    <!-- ═══════════════════════════════════════════════════════ -->
    <div class="neu-card p-6 mb-6">
        <div class="flex items-center justify-between mb-5 pb-3 border-b dark:border-gray-700">
            <h2 class="font-bold text-lg">
                <?php echo $editBlog ? '✏️ Edit: <span class="text-indigo-600">' . clean($editBlog['title']) . '</span>' : '➕ New Post'; ?>
            </h2>
            <?php if ($editBlog): ?>
            <a href="?edit=<?php echo $editBlog['id']; ?>&delete=<?php echo $editBlog['id']; ?>&csrf=<?php echo $_SESSION['csrf_token']; ?>"
               onclick="return confirm('Delete this post?')"
               class="text-xs text-red-500 hover:text-red-700">🗑️ Delete Post</a>
            <?php endif; ?>
        </div>

        <form method="POST" enctype="multipart/form-data" id="blogForm" class="space-y-5">
            <?php echo csrfField(); ?>
            <?php if ($editBlog): ?>
            <input type="hidden" name="blog_id" value="<?php echo $editBlog['id']; ?>">
            <input type="hidden" name="temp_folder" value="<?php echo $editBlog['upload_folder']; ?>">
            <?php elseif (!empty($tempFolder)): ?>
            <input type="hidden" name="temp_folder" value="<?php echo htmlspecialchars($tempFolder); ?>">
            <?php endif; ?>

            <!-- ── Row 1: Title + Slug + Status ── -->
            <div class="grid md:grid-cols-3 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium mb-1">📌 Title <span class="text-red-500">*</span></label>
                    <input type="text" name="title" id="blogTitle" required
                           value="<?php echo clean($editBlog['title'] ?? ''); ?>"
                           class="w-full px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800 focus:ring-2 focus:ring-indigo-500"
                           placeholder="Blog post title..." onkeyup="generateSlug(this.value)">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">🏷️ Status</label>
                    <select name="status" class="w-full px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800 focus:ring-2 focus:ring-indigo-500">
                        <option value="draft" <?php echo ($editBlog['status'] ?? '') === 'draft' ? 'selected' : ''; ?>>📝 Draft</option>
                        <option value="published" <?php echo ($editBlog['status'] ?? '') === 'published' ? 'selected' : ''; ?>>✅ Published</option>
                        <option value="archived" <?php echo ($editBlog['status'] ?? '') === 'archived' ? 'selected' : ''; ?>>📦 Archived</option>
                    </select>
                </div>
            </div>

            <!-- ── Row 2: Slug + Tags ── -->
            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">🔗 URL Slug</label>
                    <input type="text" name="slug" id="slug" value="<?php echo clean($editBlog['slug'] ?? ''); ?>"
                           class="w-full px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800 font-mono text-sm"
                           placeholder="url-slug-auto-generated">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">🔖 Tags</label>
                    <input type="text" name="tags" value="<?php echo clean(getTagsString($editBlog['tags'] ?? '')); ?>"
                           class="w-full px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800"
                           placeholder="php, tutorial, web (comma separated)">
                </div>
            </div>

            <!-- ── Row 3: SEO fields (compact) ── -->
            <details class="border dark:border-gray-700 rounded-lg">
                <summary class="px-4 py-3 cursor-pointer font-medium text-sm text-gray-600 dark:text-gray-400 hover:text-gray-800 select-none">
                    🔍 SEO Settings (Meta Title, Description, Excerpt)
                </summary>
                <div class="px-4 pb-4 pt-2 grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Meta Title <span class="text-xs text-gray-400">(max 60 chars)</span></label>
                        <input type="text" name="meta_title" maxlength="70"
                               value="<?php echo clean($editBlog['meta_title'] ?? ''); ?>"
                               class="w-full px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Meta Description <span class="text-xs text-gray-400">(max 160 chars)</span></label>
                        <input type="text" name="meta_description" maxlength="170"
                               value="<?php echo clean($editBlog['meta_description'] ?? ''); ?>"
                               class="w-full px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800 text-sm">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium mb-1">Excerpt <span class="text-xs text-gray-400">(shown in blog list)</span></label>
                        <textarea name="excerpt" rows="2"
                                  class="w-full px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800 text-sm resize-none"
                                  placeholder="Short description of the post..."><?php echo clean($editBlog['excerpt'] ?? ''); ?></textarea>
                    </div>
                </div>
            </details>

            <!-- ── Row 4: Featured Image ── -->
            <div class="border dark:border-gray-700 rounded-lg p-4">
                <label class="block font-medium mb-3">🖼️ Featured Image (Cover Photo)</label>
                <div class="flex flex-wrap items-center gap-3">
                    <input type="file" name="featured_image" accept="image/*"
                           class="text-sm file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-indigo-50 file:text-indigo-700 file:font-medium hover:file:bg-indigo-100">
                    <?php if ($editBlog && !empty($editBlog['featured_image'])): ?>
                    <label class="flex items-center gap-2 text-sm text-red-600 cursor-pointer">
                        <input type="checkbox" name="remove_featured" value="1" class="rounded"> Remove current
                    </label>
                    <?php endif; ?>
                </div>
                <?php if ($editBlog && !empty($editBlog['featured_image'])): ?>
                <div class="mt-3 flex items-center gap-3">
                    <img src="<?php echo htmlspecialchars($editBlog['featured_image']); ?>"
                         class="h-24 w-40 rounded-lg object-cover border dark:border-gray-600">
                    <span class="text-xs text-gray-500 font-mono break-all"><?php echo htmlspecialchars($editBlog['featured_image']); ?></span>
                </div>
                <?php endif; ?>
            </div>

            <!-- ══════════════════════════════════════════════════ -->
            <!-- MEDIA LIBRARY (Tabbed Panel)                       -->
            <!-- ══════════════════════════════════════════════════ -->
            <div class="border dark:border-gray-700 rounded-xl overflow-hidden">
                <div class="bg-gray-50 dark:bg-gray-800 px-4 py-3 border-b dark:border-gray-700 font-medium flex items-center gap-2">
                    📎 Media Library
                    <span class="text-xs text-gray-400 font-normal">— Insert images, audio & video into your post</span>
                </div>

                <!-- Tab buttons -->
                <div class="flex border-b dark:border-gray-700 bg-white dark:bg-gray-900">
                    <?php
                    $tabs = [
                        'gallery' => ['📷', 'Gallery (' . count($galleryImages) . ')'],
                        'imgurl'  => ['🔗', 'Image URL'],
                        'audio'   => ['🎵', 'Audio'],
                        'video'   => ['🎥', 'YouTube/Video'],
                    ];
                    foreach ($tabs as $tabId => [$icon, $label]): ?>
                    <button type="button" onclick="switchMediaTab('<?php echo $tabId; ?>')"
                            id="tab-<?php echo $tabId; ?>"
                            class="media-tab px-5 py-3 text-sm font-medium border-b-2 transition <?php echo $tabId === 'gallery' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>">
                        <?php echo "$icon $label"; ?>
                    </button>
                    <?php endforeach; ?>
                </div>

                <div class="p-4">

                    <!-- ── Gallery Tab ── -->
                    <div id="panel-gallery">
                        <div class="flex flex-wrap gap-2 mb-4">
                            <input type="file" name="gallery_batch[]" id="galleryInput" accept="image/*" multiple
                                   class="text-sm file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-green-50 file:text-green-700 file:font-medium"
                                   onchange="updateGalleryFileList(this)">
                            <button type="button" onclick="uploadGalleryNow()"
                                    class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm rounded-lg font-medium">
                                📤 Upload Now
                            </button>
                        </div>
                        <div id="galleryFileInfo" class="hidden text-xs text-gray-500 mb-3 p-2 bg-gray-50 dark:bg-gray-800 rounded"></div>

                        <?php if (!empty($galleryImages)): ?>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm font-medium text-gray-600 dark:text-gray-400">📋 Uploaded (<?php echo count($galleryImages); ?>)</span>
                            <div class="flex gap-2">
                                <button type="button" onclick="insertAllImages()"
                                        class="text-xs px-3 py-1 bg-indigo-100 text-indigo-700 rounded hover:bg-indigo-200">⤵ Insert All</button>
                                <button type="button" onclick="buildCarousel()"
                                        class="text-xs px-3 py-1 bg-purple-100 text-purple-700 rounded hover:bg-purple-200">🎠 Make Carousel</button>
                            </div>
                        </div>
                        <div class="grid gap-2 max-h-56 overflow-y-auto" id="galleryItems">
                            <?php foreach ($galleryImages as $idx => $img): ?>
                            <div class="flex items-center gap-3 p-2 bg-gray-50 dark:bg-gray-800 rounded-lg gallery-item" data-url="<?php echo htmlspecialchars($img['media_url']); ?>">
                                <img src="<?php echo htmlspecialchars($img['media_url']); ?>"
                                     class="w-14 h-14 object-cover rounded cursor-pointer hover:opacity-80 flex-shrink-0"
                                     onclick="window.open('<?php echo htmlspecialchars($img['media_url']); ?>')">
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-mono truncate text-gray-500"><?php echo basename($img['media_url']); ?></p>
                                </div>
                                <div class="flex gap-1 flex-shrink-0">
                                    <button type="button" onclick="insertImageToEditor('<?php echo htmlspecialchars($img['media_url']); ?>')"
                                            class="px-2 py-1 bg-indigo-600 text-white text-xs rounded hover:bg-indigo-700">Insert</button>
                                    <button type="button" onclick="copyToClipboard('<?php echo htmlspecialchars($img['media_url']); ?>')"
                                            class="px-2 py-1 bg-gray-200 text-gray-700 text-xs rounded hover:bg-gray-300">Copy</button>
                                    <a href="?edit=<?php echo $editBlog['id'] ?? 0; ?>&delete_media=<?php echo $img['id']; ?>&csrf=<?php echo $_SESSION['csrf_token']; ?>"
                                       onclick="return confirm('Delete image?')"
                                       class="px-2 py-1 bg-red-100 text-red-600 text-xs rounded hover:bg-red-200">🗑</a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div id="emptyGallery" class="text-center py-6 text-gray-400">
                            <p class="text-3xl mb-2">🖼️</p>
                            <p class="text-sm">No gallery images yet. Upload images above.</p>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- ── Image URL Tab ── -->
                    <div id="panel-imgurl" class="hidden">
                        <p class="text-sm text-gray-500 mb-3">Paste an image URL to insert it directly into the content.</p>
                        <div class="flex gap-2">
                            <input type="url" id="imageUrlInput"
                                   class="flex-1 px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800 text-sm"
                                   placeholder="https://example.com/image.jpg">
                            <button type="button" onclick="insertImageUrl()"
                                    class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700 font-medium">
                                ⤵ Insert
                            </button>
                        </div>
                        <p class="text-xs text-gray-400 mt-2">Image will be inserted at the cursor position in the editor.</p>
                    </div>

                    <!-- ── Audio Tab ── -->
                    <div id="panel-audio" class="hidden">
                        <p class="text-sm text-gray-500 mb-4">Upload an audio file OR paste an audio URL to insert an HTML5 player.</p>
                        <div class="space-y-3">
                            <!-- Upload audio file -->
                            <div class="border dark:border-gray-700 rounded-lg p-3">
                                <label class="block text-xs font-semibold text-gray-600 dark:text-gray-400 mb-2 uppercase tracking-wide">Upload Audio File (MP3, OGG, WAV)</label>
                                <div class="flex gap-2 flex-wrap">
                                    <input type="file" name="audio_file" accept=".mp3,.ogg,.wav,.m4a"
                                           id="audioFileInput"
                                           class="text-sm file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-pink-50 file:text-pink-700 file:font-medium">
                                </div>
                                <p class="text-xs text-gray-400 mt-1">File will be uploaded and saved with the blog post.</p>
                            </div>
                            <!-- Audio URL -->
                            <div class="border dark:border-gray-700 rounded-lg p-3">
                                <label class="block text-xs font-semibold text-gray-600 dark:text-gray-400 mb-2 uppercase tracking-wide">Or Paste Audio URL</label>
                                <div class="flex gap-2">
                                    <input type="url" id="audioUrlInput"
                                           name="audio_urls[]"
                                           class="flex-1 px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800 text-sm"
                                           placeholder="https://example.com/audio.mp3">
                                    <button type="button" onclick="insertAudioPlayer()"
                                            class="px-4 py-2 bg-pink-600 text-white text-sm rounded-lg hover:bg-pink-700 font-medium">
                                        🎵 Insert Player
                                    </button>
                                </div>
                            </div>
                        </div>
                        <!-- Existing audio -->
                        <?php if (!empty($audioMedia)): ?>
                        <div class="mt-3 space-y-2">
                            <p class="text-xs font-medium text-gray-500">Saved audio:</p>
                            <?php foreach ($audioMedia as $a): ?>
                            <div class="flex items-center gap-2 p-2 bg-gray-50 dark:bg-gray-800 rounded">
                                <span class="text-pink-500">🎵</span>
                                <span class="text-xs font-mono truncate flex-1"><?php echo htmlspecialchars($a['media_url']); ?></span>
                                <button type="button" onclick="insertAudioPlayerFromUrl('<?php echo htmlspecialchars($a['media_url']); ?>')"
                                        class="text-xs px-2 py-1 bg-pink-100 text-pink-700 rounded hover:bg-pink-200">Insert</button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- ── YouTube / Video Tab ── -->
                    <div id="panel-video" class="hidden">
                        <p class="text-sm text-gray-500 mb-4">Paste a YouTube or video URL to embed it.</p>

                        <div class="space-y-3">
                            <!-- YouTube -->
                            <div class="border dark:border-gray-700 rounded-lg p-3">
                                <label class="block text-xs font-semibold text-gray-600 dark:text-gray-400 mb-2 uppercase tracking-wide">📺 YouTube URL</label>
                                <div class="flex gap-2">
                                    <input type="url" id="youtubeUrlInput"
                                           name="youtube_urls[]"
                                           class="flex-1 px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800 text-sm"
                                           placeholder="https://youtube.com/watch?v=...">
                                    <button type="button" onclick="insertYouTube()"
                                            class="px-4 py-2 bg-red-600 text-white text-sm rounded-lg hover:bg-red-700 font-medium">
                                        📺 Embed
                                    </button>
                                </div>
                                <button type="button" onclick="addYouTubeField()" class="mt-2 text-xs text-blue-500 hover:underline">+ Add another YouTube URL</button>
                                <div id="extra-youtube"></div>
                            </div>
                            <!-- Direct Video URL -->
                            <div class="border dark:border-gray-700 rounded-lg p-3">
                                <label class="block text-xs font-semibold text-gray-600 dark:text-gray-400 mb-2 uppercase tracking-wide">🎬 Direct Video URL (MP4, WebM)</label>
                                <div class="flex gap-2">
                                    <input type="url" id="videoUrlInput"
                                           name="video_urls[]"
                                           class="flex-1 px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800 text-sm"
                                           placeholder="https://example.com/video.mp4">
                                    <button type="button" onclick="insertVideoUrl()"
                                            class="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 font-medium">
                                        🎬 Embed
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Existing YouTube/Video -->
                        <?php if (!empty($youtubeVideos) || !empty($videoUrls)): ?>
                        <div class="mt-3 space-y-2">
                            <p class="text-xs font-medium text-gray-500">Saved videos:</p>
                            <?php foreach (array_merge($youtubeVideos, $videoUrls) as $v): ?>
                            <div class="flex items-center gap-2 p-2 bg-gray-50 dark:bg-gray-800 rounded">
                                <span><?php echo $v['media_type'] === 'youtube' ? '📺' : '🎬'; ?></span>
                                <span class="text-xs font-mono truncate flex-1"><?php echo htmlspecialchars($v['media_url']); ?></span>
                                <button type="button"
                                        onclick="<?php echo $v['media_type'] === 'youtube' ? "insertYouTubeFromUrl('" . htmlspecialchars($v['media_url']) . "')" : "insertVideoFromUrl('" . htmlspecialchars($v['media_url']) . "')"; ?>"
                                        class="text-xs px-2 py-1 bg-blue-100 text-blue-700 rounded hover:bg-blue-200">Insert</button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                </div><!-- /tab panels -->
            </div><!-- /media library -->

            <!-- ══════════════════════════════════════════════════ -->
            <!-- CONTENT EDITOR                                     -->
            <!-- ══════════════════════════════════════════════════ -->
            <div class="border dark:border-gray-700 rounded-xl overflow-hidden">
                <div class="bg-gray-50 dark:bg-gray-800 px-4 py-2 border-b dark:border-gray-700 flex items-center justify-between">
                    <span class="font-medium">📝 Content</span>
                    <div class="flex gap-2">
                        <button type="button" onclick="setMode('visual')" id="btn-visual"
                                class="px-3 py-1.5 text-xs rounded font-medium bg-indigo-600 text-white">Visual</button>
                        <button type="button" onclick="setMode('html')" id="btn-html"
                                class="px-3 py-1.5 text-xs rounded font-medium bg-gray-200 text-gray-700">HTML Source</button>
                    </div>
                </div>

                <!-- Toolbar -->
                <div class="flex flex-wrap gap-1 p-2 bg-white dark:bg-gray-900 border-b dark:border-gray-700" id="editorToolbar">
                    <!-- Format -->
                    <select onchange="formatDoc('formatBlock', this.value); this.value=''"
                            class="px-2 py-1 text-xs rounded border dark:border-gray-600 dark:bg-gray-800">
                        <option value="" selected>Format</option>
                        <option value="P">Paragraph</option>
                        <option value="H2">Heading 2</option>
                        <option value="H3">Heading 3</option>
                        <option value="H4">Heading 4</option>
                        <option value="BLOCKQUOTE">Quote</option>
                    </select>
                    <div class="w-px bg-gray-200 dark:bg-gray-700 mx-1"></div>
                    <button type="button" onclick="formatDoc('bold')"        class="toolbar-btn font-bold">B</button>
                    <button type="button" onclick="formatDoc('italic')"      class="toolbar-btn italic">I</button>
                    <button type="button" onclick="formatDoc('underline')"   class="toolbar-btn underline">U</button>
                    <button type="button" onclick="formatDoc('strikeThrough')" class="toolbar-btn line-through">S</button>
                    <div class="w-px bg-gray-200 dark:bg-gray-700 mx-1"></div>
                    <button type="button" onclick="formatDoc('insertUnorderedList')" class="toolbar-btn">• List</button>
                    <button type="button" onclick="formatDoc('insertOrderedList')"   class="toolbar-btn">1. List</button>
                    <div class="w-px bg-gray-200 dark:bg-gray-700 mx-1"></div>
                    <!-- Insert shortcuts -->
                    <button type="button" onclick="showMediaTab('gallery'); quickInsertImage()"
                            class="toolbar-btn text-indigo-600" title="Insert Image from Gallery">🖼 Image</button>
                    <button type="button" onclick="switchMediaTab('imgurl'); document.getElementById('imageUrlInput').focus()"
                            class="toolbar-btn text-indigo-600" title="Insert Image by URL">🔗 Img URL</button>
                    <button type="button" onclick="buildCarousel()"
                            class="toolbar-btn text-purple-600" title="Insert Image Carousel">🎠 Carousel</button>
                    <button type="button" onclick="switchMediaTab('audio'); document.getElementById('audioUrlInput').focus()"
                            class="toolbar-btn text-pink-600"   title="Insert Audio Player">🎵 Audio</button>
                    <button type="button" onclick="switchMediaTab('video'); document.getElementById('youtubeUrlInput').focus()"
                            class="toolbar-btn text-red-600"    title="Embed YouTube/Video">🎥 Video</button>
                    <div class="w-px bg-gray-200 dark:bg-gray-700 mx-1"></div>
                    <button type="button" onclick="insertHR()"      class="toolbar-btn text-gray-500">── HR</button>
                    <button type="button" onclick="cleanHTML()"     class="toolbar-btn text-yellow-600">🧹 Clean</button>
                    <button type="button" onclick="insertCustomLink()" class="toolbar-btn text-blue-600">🔗 Link</button>
                </div>

                <!-- Visual Editor -->
                <div id="visual-editor-wrap">
                    <div id="editor" contenteditable="true"
                         class="min-h-[400px] max-h-[600px] overflow-y-auto p-4 focus:outline-none prose dark:prose-invert max-w-none bg-white dark:bg-gray-900"
                         style="line-height:1.7;"
                         oninput="syncToTextarea()">
                        <?php echo $editBlog['content'] ?? ''; ?>
                    </div>
                </div>

                <!-- HTML Source Editor -->
                <div id="html-editor-wrap" class="hidden">
                    <textarea id="htmlSource" rows="20"
                              class="w-full p-4 font-mono text-sm bg-gray-950 text-green-300 focus:outline-none"
                              oninput="syncFromSource()"><?php echo htmlspecialchars($editBlog['content'] ?? ''); ?></textarea>
                </div>

                <!-- Hidden textarea for form submission -->
                <textarea name="content" id="contentTextarea" class="hidden"><?php echo htmlspecialchars($editBlog['content'] ?? ''); ?></textarea>
            </div>

            <!-- ── Save buttons ── -->
            <div class="flex gap-3 justify-between items-center pt-2">
                <div class="flex gap-2">
                    <?php if ($editBlog): ?>
                    <a href="/blog/<?php echo $editBlog['slug']; ?>" target="_blank"
                       class="neu-button px-5 py-2 rounded-lg text-sm text-blue-600">👁 Preview</a>
                    <a href="/admin/blogs.php" class="neu-button px-5 py-2 rounded-lg text-sm text-gray-600">Cancel</a>
                    <?php endif; ?>
                </div>
                <div class="flex gap-2">
                    <button type="submit" onclick="document.querySelector('[name=status]').value='draft'"
                            class="neu-button px-6 py-2 rounded-lg text-sm font-semibold text-yellow-700 bg-yellow-50 hover:bg-yellow-100">
                        💾 Save Draft
                    </button>
                    <button type="submit" onclick="document.querySelector('[name=status]').value='published'"
                            class="px-8 py-2 rounded-lg text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-700 shadow-lg">
                        🚀 Publish
                    </button>
                </div>
            </div>

        </form>
    </div>

    <!-- ═══════════════════════════════════════════════════════ -->
    <!-- BLOG POSTS LIST                                         -->
    <!-- ═══════════════════════════════════════════════════════ -->
    <div class="neu-card overflow-hidden">
        <div class="px-4 py-3 bg-gray-50 dark:bg-gray-800 border-b dark:border-gray-700 flex items-center justify-between">
            <h3 class="font-bold">All Posts (<?php echo count($blogs); ?>)</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800 border-b dark:border-gray-700 text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="p-3 text-left">Post</th>
                        <th class="p-3 text-center">Status</th>
                        <th class="p-3 text-center">Views</th>
                        <th class="p-3 text-center">Date</th>
                        <th class="p-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y dark:divide-gray-700">
                    <?php foreach ($blogs as $blog): ?>
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 transition <?php echo ($editBlog['id'] ?? 0) == $blog['id'] ? 'bg-indigo-50 dark:bg-indigo-900/10' : ''; ?>">
                        <td class="p-3">
                            <div class="flex items-center gap-3">
                                <?php if (!empty($blog['featured_image'])): ?>
                                <img src="<?php echo htmlspecialchars($blog['featured_image']); ?>"
                                     class="w-10 h-10 rounded object-cover bg-gray-200 flex-shrink-0">
                                <?php else: ?>
                                <div class="w-10 h-10 rounded bg-indigo-100 flex items-center justify-center flex-shrink-0 text-lg">📝</div>
                                <?php endif; ?>
                                <div class="min-w-0">
                                    <div class="font-medium truncate"><?php echo clean($blog['title']); ?></div>
                                    <div class="text-xs text-gray-400 font-mono">/blog/<?php echo $blog['slug']; ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="p-3 text-center">
                            <span class="px-2 py-0.5 rounded-full text-xs font-medium <?php echo $blog['status'] === 'published' ? 'bg-green-100 text-green-800' : ($blog['status'] === 'draft' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-600'); ?>">
                                <?php echo ucfirst($blog['status']); ?>
                            </span>
                        </td>
                        <td class="p-3 text-center text-xs text-gray-500"><?php echo number_format($blog['view_count'] ?? 0); ?></td>
                        <td class="p-3 text-center text-xs text-gray-500"><?php echo date('M j, Y', strtotime($blog['published_at'])); ?></td>
                        <td class="p-3 text-right space-x-2">
                            <a href="?edit=<?php echo $blog['id']; ?>" class="text-indigo-600 hover:underline text-xs">Edit</a>
                            <a href="/blog/<?php echo $blog['slug']; ?>" target="_blank" class="text-blue-500 hover:underline text-xs">View</a>
                            <a href="?delete=<?php echo $blog['id']; ?>&csrf=<?php echo $_SESSION['csrf_token']; ?>"
                               onclick="return confirm('Delete post?')" class="text-red-500 hover:underline text-xs">Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- JAVASCRIPT                                                          -->
<!-- ═══════════════════════════════════════════════════════════════════ -->
<style>
.toolbar-btn {
    padding: 4px 10px;
    font-size: 12px;
    border-radius: 6px;
    background: #f3f4f6;
    color: #374151;
    transition: background .15s;
    cursor: pointer;
}
.toolbar-btn:hover { background: #e5e7eb; }
.dark .toolbar-btn { background: #374151; color: #e5e7eb; }
.dark .toolbar-btn:hover { background: #4b5563; }
#editor { font-family: 'Inter', 'Noto Sans Tamil', sans-serif; }
#editor h2 { font-size:1.5em; font-weight:700; margin:1em 0 .5em; }
#editor h3 { font-size:1.25em; font-weight:600; margin:.8em 0 .4em; }
#editor h4 { font-size:1.1em; font-weight:600; margin:.6em 0 .3em; }
#editor p  { margin:.5em 0; }
#editor blockquote { border-left:4px solid #6366f1; padding-left:1em; color:#6b7280; font-style:italic; margin:1em 0; }
#editor ul { list-style:disc; padding-left:1.5em; margin:.5em 0; }
#editor ol { list-style:decimal; padding-left:1.5em; margin:.5em 0; }
#editor img { max-width:100%; border-radius:8px; }
#editor .blog-carousel { position:relative; overflow:hidden; border-radius:12px; }
</style>

<script>
let currentMode = 'visual';
const csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';

// ── Mode switching ────────────────────────────────────────────────
function setMode(mode) {
    currentMode = mode;
    const isVisual = mode === 'visual';
    document.getElementById('visual-editor-wrap').classList.toggle('hidden', !isVisual);
    document.getElementById('html-editor-wrap').classList.toggle('hidden', isVisual);
    document.getElementById('btn-visual').className = `px-3 py-1.5 text-xs rounded font-medium ${isVisual ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-700'}`;
    document.getElementById('btn-html').className   = `px-3 py-1.5 text-xs rounded font-medium ${!isVisual ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-700'}`;
    if (isVisual) {
        document.getElementById('editor').innerHTML = document.getElementById('htmlSource').value;
    } else {
        document.getElementById('htmlSource').value = document.getElementById('editor').innerHTML;
    }
    syncToTextarea();
}

function syncToTextarea() {
    document.getElementById('contentTextarea').value = document.getElementById('editor').innerHTML;
}
function syncFromSource() {
    document.getElementById('contentTextarea').value = document.getElementById('htmlSource').value;
}

// ── Toolbar commands ──────────────────────────────────────────────
function formatDoc(cmd, val = null) {
    if (currentMode !== 'visual') return;
    document.execCommand(cmd, false, val);
    document.getElementById('editor').focus();
    syncToTextarea();
}

function insertHR() {
    insertHTML('<hr class="my-6 border-gray-200 dark:border-gray-700">');
}

function insertCustomLink() {
    const url  = prompt('URL:',  'https://');
    const text = prompt('Text:', 'Link text');
    if (url && text) insertHTML(`<a href="${url}" target="_blank" class="text-indigo-600 underline">${text}</a>`);
}

function cleanHTML() {
    let html = document.getElementById('editor').innerHTML;
    html = html.replace(/<\!DOCTYPE[^>]*>/gi,'')
               .replace(/<html[^>]*>|<\/html>/gi,'')
               .replace(/<head[^>]*>[\s\S]*?<\/head>/gi,'')
               .replace(/<body[^>]*>|<\/body>/gi,'')
               .replace(/<meta[^>]*>/gi,'')
               .replace(/<style[^>]*>[\s\S]*?<\/style>/gi,'')
               .replace(/<script[^>]*>[\s\S]*?<\/script>/gi,'')
               .replace(/<!--[\s\S]*?-->/g,'').trim();
    const m = html.match(/<body[^>]*>([\s\S]*?)<\/body>/i);
    if (m) html = m[1].trim();
    document.getElementById('editor').innerHTML = html;
    syncToTextarea();
    showToast('✅ HTML cleaned!');
}

function insertHTML(html) {
    if (currentMode === 'visual') {
        document.getElementById('editor').focus();
        document.execCommand('insertHTML', false, html);
    } else {
        const ta = document.getElementById('htmlSource');
        const pos = ta.selectionStart;
        ta.value = ta.value.slice(0,pos) + html + ta.value.slice(ta.selectionEnd);
    }
    syncToTextarea();
}

// ── Media tab switching ───────────────────────────────────────────
function switchMediaTab(tab) {
    ['gallery','imgurl','audio','video'].forEach(t => {
        document.getElementById('panel-' + t).classList.toggle('hidden', t !== tab);
        const btn = document.getElementById('tab-' + t);
        btn.className = 'media-tab px-5 py-3 text-sm font-medium border-b-2 transition ' +
            (t === tab ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700');
    });
}
function showMediaTab(tab) { switchMediaTab(tab); }

// ── Gallery upload ────────────────────────────────────────────────
function updateGalleryFileList(input) {
    const el = document.getElementById('galleryFileInfo');
    if (input.files.length) {
        el.classList.remove('hidden');
        el.textContent = Array.from(input.files).map((f,i) => `${i+1}. ${f.name} (${(f.size/1024).toFixed(1)}KB)`).join(' | ');
    } else { el.classList.add('hidden'); }
}

function uploadGalleryNow() {
    const input = document.getElementById('galleryInput');
    if (!input.files.length) { alert('Please select images first'); return; }
    const form = document.createElement('form');
    form.method = 'POST'; form.enctype = 'multipart/form-data'; form.action = location.href;
    const fields = { csrf_token: csrfToken, upload_gallery_only: '1',
        <?php echo $editBlog ? "blog_id: '" . $editBlog['id'] . "'" : "temp_slug: document.getElementById('slug').value || 'temp-' + Date.now()"; ?>
    };
    // Note: for temp_slug we need it at runtime not template time
    form.innerHTML = `<input type="hidden" name="csrf_token" value="${csrfToken}"><input type="hidden" name="upload_gallery_only" value="1">` +
        <?php echo $editBlog ? "'<input type=\"hidden\" name=\"blog_id\" value=\"" . $editBlog['id'] . "\">'" : "'<input type=\"hidden\" name=\"temp_slug\" id=\"tempSlugField\">'"; ?>;
    <?php if (!$editBlog): ?>
    form.querySelector('#tempSlugField').value = document.getElementById('slug').value || 'temp-' + Date.now();
    <?php endif; ?>
    const ni = input.cloneNode(true); ni.name = 'gallery[]'; form.appendChild(ni);
    document.body.appendChild(form); form.submit();
}

// ── Image insert ──────────────────────────────────────────────────
function insertImageToEditor(url) {
    const html = `<figure class="my-4 text-center">
  <img src="${url}" alt="Image" class="max-w-full rounded-lg shadow-md mx-auto">
  <figcaption class="text-sm text-gray-500 mt-2">Caption here</figcaption>
</figure><p><br></p>`;
    insertHTML(html);
    showToast('Image inserted!');
}

function insertImageUrl() {
    const url = document.getElementById('imageUrlInput').value.trim();
    if (!url) { alert('Please enter an image URL'); return; }
    insertImageToEditor(url);
    document.getElementById('imageUrlInput').value = '';
}

function quickInsertImage() {
    const items = document.querySelectorAll('.gallery-item');
    if (!items.length) { showToast('No gallery images — upload first'); return; }
    // Insert first not-yet-inserted image, or show gallery tab
    switchMediaTab('gallery');
}

// ── Carousel builder ──────────────────────────────────────────────
function buildCarousel() {
    const items = document.querySelectorAll('.gallery-item');
    if (items.length < 2) { showToast('Need 2+ gallery images for a carousel'); return; }

    const cId = 'c' + Date.now();
    let slides = '', dots = '';
    items.forEach((item, i) => {
        const url = item.dataset.url;
        slides += `\n    <div class="carousel-slide min-w-full"><img src="${url}" alt="Slide ${i+1}" class="w-full h-64 md:h-96 object-cover"></div>`;
        dots   += `\n    <span onclick="akkuCarouselGo('${cId}',${i})" style="width:10px;height:10px;border-radius:50%;background:rgba(255,255,255,${i===0?'1':'0.5'});cursor:pointer;display:inline-block;margin:0 3px"></span>`;
    });

    const html = `
<div class="blog-carousel" data-cid="${cId}" data-cur="0" style="position:relative;overflow:hidden;border-radius:12px;margin:1.5rem 0;background:#000;">
  <div id="ctrack-${cId}" style="display:flex;transition:transform .4s ease;">${slides}
  </div>
  <button onclick="akkuCarouselPrev('${cId}')" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);background:rgba(0,0,0,.5);color:#fff;border:none;width:36px;height:36px;border-radius:50%;cursor:pointer;font-size:18px;line-height:1;z-index:2">‹</button>
  <button onclick="akkuCarouselNext('${cId}')" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:rgba(0,0,0,.5);color:#fff;border:none;width:36px;height:36px;border-radius:50%;cursor:pointer;font-size:18px;line-height:1;z-index:2">›</button>
  <div id="cdots-${cId}" style="position:absolute;bottom:10px;left:0;right:0;text-align:center">${dots}
  </div>
</div>
<script>
function akkuCarouselGo(id,n){var c=document.querySelector('[data-cid="'+id+'"]'),t=document.getElementById('ctrack-'+id'),s=t.querySelectorAll('.carousel-slide');n=((n%s.length)+s.length)%s.length;c.dataset.cur=n;t.style.transform='translateX(-'+n*100+'%)';document.querySelectorAll('#cdots-'+id+' span').forEach(function(d,i){d.style.background='rgba(255,255,255,'+(i==n?1:.5)+')'});}
function akkuCarouselPrev(id){var c=document.querySelector('[data-cid="'+id+'"]');akkuCarouselGo(id,parseInt(c.dataset.cur||0)-1);}
function akkuCarouselNext(id){var c=document.querySelector('[data-cid="'+id+'"]');akkuCarouselGo(id,parseInt(c.dataset.cur||0)+1);}
<\/script>
<p><br></p>`;

    insertHTML(html);
    showToast('🎠 Carousel inserted with ' + items.length + ' images!');
}

// ── Audio insert ──────────────────────────────────────────────────
function insertAudioPlayer() {
    const url = document.getElementById('audioUrlInput').value.trim();
    if (!url) { alert('Enter an audio URL first'); return; }
    insertAudioPlayerFromUrl(url);
}
function insertAudioPlayerFromUrl(url) {
    const ext = url.split('.').pop().toLowerCase().split('?')[0];
    const mime = {'mp3':'audio/mpeg','ogg':'audio/ogg','wav':'audio/wav','m4a':'audio/mp4'}[ext] || 'audio/mpeg';
    const html = `
<div class="blog-audio my-4 p-3 bg-gray-50 dark:bg-gray-800 rounded-xl border dark:border-gray-700 flex items-center gap-3">
  <span class="text-3xl">🎵</span>
  <audio controls class="flex-1 min-w-0" style="max-width:100%">
    <source src="${url}" type="${mime}">
    Your browser does not support the audio element.
  </audio>
</div><p><br></p>`;
    insertHTML(html);
    showToast('🎵 Audio player inserted!');
}

// ── YouTube / Video insert ────────────────────────────────────────
function insertYouTube() {
    const url = document.getElementById('youtubeUrlInput').value.trim();
    if (!url) { alert('Enter a YouTube URL first'); return; }
    insertYouTubeFromUrl(url);
}
function insertYouTubeFromUrl(url) {
    let videoId = '';
    const m = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/);
    if (m) videoId = m[1];
    else { alert('Could not parse YouTube URL'); return; }
    const html = `
<div class="blog-video my-4" style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;border-radius:12px;">
  <iframe src="https://www.youtube.com/embed/${videoId}" allow="accelerometer;autoplay;clipboard-write;encrypted-media;gyroscope;picture-in-picture" allowfullscreen
          style="position:absolute;top:0;left:0;width:100%;height:100%;border:0;border-radius:12px;"></iframe>
</div><p><br></p>`;
    insertHTML(html);
    showToast('📺 YouTube video embedded!');
}

function insertVideoUrl() {
    const url = document.getElementById('videoUrlInput').value.trim();
    if (!url) { alert('Enter a video URL first'); return; }
    insertVideoFromUrl(url);
}
function insertVideoFromUrl(url) {
    const html = `
<div class="blog-video my-4" style="border-radius:12px;overflow:hidden;">
  <video controls style="width:100%;max-height:500px;background:#000;">
    <source src="${url}" type="video/mp4">
    Your browser does not support the video tag.
  </video>
</div><p><br></p>`;
    insertHTML(html);
    showToast('🎬 Video embedded!');
}

function addYouTubeField() {
    const c = document.getElementById('extra-youtube');
    const inp = document.createElement('input');
    inp.type = 'url'; inp.name = 'youtube_urls[]';
    inp.placeholder = 'https://youtube.com/watch?v=...';
    inp.className = 'mt-2 w-full px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800 text-sm';
    c.appendChild(inp);
}

// ── Slug generator ────────────────────────────────────────────────
function generateSlug(title) {
    document.getElementById('slug').value = title.toLowerCase()
        .replace(/[^\w\s-]/g,'').replace(/\s+/g,'-').replace(/-+/g,'-').replace(/^-+|-+$/g,'');
}

// ── Utilities ─────────────────────────────────────────────────────
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => showToast('Copied!'));
}

function showToast(msg) {
    const t = document.createElement('div');
    t.className = 'fixed bottom-4 right-4 bg-indigo-700 text-white px-5 py-3 rounded-xl shadow-xl z-50 text-sm font-medium';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 2500);
}

// ── Form submit: sync content ─────────────────────────────────────
document.getElementById('blogForm').addEventListener('submit', function() {
    syncToTextarea();
});
</script>

<?php include '../includes/footer.php'; ?>