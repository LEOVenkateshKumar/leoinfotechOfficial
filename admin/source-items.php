<?php
require_once '../includes/functions.php';
requireAdmin();

$db = getDB();
$message = '';

// Handle delete
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $db->prepare("DELETE FROM source_items WHERE id = ?");
    if ($stmt->execute([$id])) {
        $message = '<div class="bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300 p-3 rounded">Item deleted.</div>';
    } else {
        $message = '<div class="bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300 p-3 rounded">Error deleting item.</div>';
    }
}

// Handle add / edit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $categoryId = intval($_POST['category_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $sort = intval($_POST['sort_order'] ?? 0);
    $status = $_POST['status'] ?? 'active';
    $id = intval($_POST['id'] ?? 0);
    
    // New: external link
    $linkUrl = trim($_POST['link_url'] ?? '');
    if (!empty($linkUrl) && !filter_var($linkUrl, FILTER_VALIDATE_URL)) {
        $message = '<div class="bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300 p-3 rounded">Invalid URL format.</div>';
    }

    if (empty($title) || $categoryId == 0) {
        $message = '<div class="bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300 p-3 rounded">Title and category are required.</div>';
    } elseif (empty($linkUrl) && empty($_FILES['source_file']['name']) && empty($_POST['existing_file'])) {
        // For edit, existing file might be kept; for new, must provide either file or link
        if (!$id) {
            $message = '<div class="bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300 p-3 rounded">You must provide either a file upload or an external link.</div>';
        }
    } else {
        // Handle file upload if any
        $filePath = null;
        $fileSize = 0;
        if (!empty($_FILES['source_file']['name'])) {
            $targetDir = '../uploads/source/files/';
            if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
            $ext = pathinfo($_FILES['source_file']['name'], PATHINFO_EXTENSION);
            $filename = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $targetFile = $targetDir . $filename;
            if (move_uploaded_file($_FILES['source_file']['tmp_name'], $targetFile)) {
                $filePath = '/uploads/source/files/' . $filename;
                $fileSize = $_FILES['source_file']['size'];
            }
        }

        // Handle thumbnail upload
        $thumbnailPath = null;
        if (!empty($_FILES['thumbnail']['name'])) {
            $targetDir = '../uploads/source/thumbnails/';
            if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
            $ext = pathinfo($_FILES['thumbnail']['name'], PATHINFO_EXTENSION);
            $filename = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $targetFile = $targetDir . $filename;
            if (move_uploaded_file($_FILES['thumbnail']['tmp_name'], $targetFile)) {
                $thumbnailPath = '/uploads/source/thumbnails/' . $filename;
            }
        }

        if ($id > 0) {
            // Update
            $sql = "UPDATE source_items SET category_id=?, title=?, description=?, sort_order=?, status=?, link_url=?";
            $params = [$categoryId, $title, $desc, $sort, $status, $linkUrl ?: null];
            
            if ($filePath) {
                $sql .= ", file_path=?, file_size=?";
                $params[] = $filePath;
                $params[] = $fileSize;
            }
            if ($thumbnailPath) {
                $sql .= ", thumbnail=?";
                $params[] = $thumbnailPath;
            }
            $sql .= " WHERE id=?";
            $params[] = $id;
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $message = '<div class="bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300 p-3 rounded">Item updated.</div>';
        } else {
            // Insert – require either file or link
            if (empty($filePath) && empty($linkUrl)) {
                $message = '<div class="bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300 p-3 rounded">Source file or link is required.</div>';
            } else {
                $stmt = $db->prepare("INSERT INTO source_items (category_id, title, description, thumbnail, file_path, file_size, link_url, sort_order, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$categoryId, $title, $desc, $thumbnailPath, $filePath, $fileSize, $linkUrl ?: null, $sort, $status]);
                $message = '<div class="bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300 p-3 rounded">Item added.</div>';
            }
        }
    }
}

// Get filter category
$filterCat = isset($_GET['category']) ? intval($_GET['category']) : 0;

// Fetch all categories for dropdown
$categories = $db->query("SELECT id, name FROM source_categories ORDER BY name")->fetchAll();

// Fetch items (optionally filtered)
$sql = "SELECT i.*, c.name as cat_name FROM source_items i JOIN source_categories c ON i.category_id = c.id";
$params = [];
if ($filterCat > 0) {
    $sql .= " WHERE i.category_id = ?";
    $params[] = $filterCat;
}
$sql .= " ORDER BY i.sort_order ASC, i.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

// If editing, load item data
$editItem = null;
if (isset($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    $stmt = $db->prepare("SELECT * FROM source_items WHERE id = ?");
    $stmt->execute([$editId]);
    $editItem = $stmt->fetch();
}
?>
<?php $pageTitle = 'Source Items'; include '../includes/header.php'; ?>

<div class="max-w-6xl mx-auto">
    <h1 class="text-2xl font-bold mb-6">Manage Source Items</h1>
    <?= $message ?>

    <!-- Filter by category -->
    <div class="mb-4 flex items-center gap-2">
        <span class="text-gray-700 dark:text-gray-300">Filter by category:</span>
        <select onchange="window.location='?category='+this.value" class="p-2 border rounded dark:bg-gray-800 dark:border-gray-600">
            <option value="0">All Categories</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= $filterCat == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($filterCat > 0): ?>
            <a href="source-items.php" class="text-sm text-blue-600 dark:text-blue-400">Clear filter</a>
        <?php endif; ?>
    </div>

    <!-- Add/Edit Form -->
    <div class="neu-card p-6 mb-8">
        <h2 class="text-xl font-semibold mb-4"><?= $editItem ? 'Edit' : 'Add New' ?> Item</h2>
        <form method="post" enctype="multipart/form-data">
            <?php if ($editItem): ?>
                <input type="hidden" name="id" value="<?= $editItem['id'] ?>">
                <input type="hidden" name="existing_file" value="<?= htmlspecialchars($editItem['file_path'] ?? '') ?>">
            <?php endif; ?>
            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <label class="block mb-1 font-medium">Category *</label>
                    <select name="category_id" required class="w-full p-2 border rounded dark:bg-gray-800 dark:border-gray-600">
                        <option value="">Select Category</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= ($editItem && $editItem['category_id'] == $cat['id']) ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block mb-1 font-medium">Title *</label>
                    <input type="text" name="title" required class="w-full p-2 border rounded dark:bg-gray-800 dark:border-gray-600" value="<?= htmlspecialchars($editItem['title'] ?? '') ?>">
                </div>
                <div class="md:col-span-2">
                    <label class="block mb-1 font-medium">Description</label>
                    <textarea name="description" rows="3" class="w-full p-2 border rounded dark:bg-gray-800 dark:border-gray-600"><?= htmlspecialchars($editItem['description'] ?? '') ?></textarea>
                </div>
                <div>
                    <label class="block mb-1 font-medium">Thumbnail Image (optional)</label>
                    <input type="file" name="thumbnail" accept="image/*" class="w-full p-2 border rounded dark:bg-gray-800 dark:border-gray-600">
                    <?php if (!empty($editItem['thumbnail'])): ?>
                        <p class="text-xs mt-1">Current: <img src="<?= htmlspecialchars($editItem['thumbnail']) ?>" class="h-10 inline-block rounded"></p>
                    <?php endif; ?>
                </div>

                <!-- Option 1: Upload File -->
                <div>
                    <label class="block mb-1 font-medium">Upload Source File</label>
                    <input type="file" name="source_file" class="w-full p-2 border rounded dark:bg-gray-800 dark:border-gray-600">
                    <?php if (!empty($editItem['file_path'])): ?>
                        <p class="text-xs mt-1">Current: <?= basename($editItem['file_path']) ?> (<?= round($editItem['file_size']/1024,2) ?> KB)</p>
                    <?php endif; ?>
                </div>

                <!-- Option 2: External Link -->
                <div>
                    <label class="block mb-1 font-medium">Or External Link (Google Drive, etc.)</label>
                    <input type="url" name="link_url" class="w-full p-2 border rounded dark:bg-gray-800 dark:border-gray-600" value="<?= htmlspecialchars($editItem['link_url'] ?? '') ?>" placeholder="https://drive.google.com/...">
                    <p class="text-xs text-gray-500">If both file and link are provided, file takes precedence.</p>
                </div>

                <div>
                    <label class="block mb-1 font-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="w-full p-2 border rounded dark:bg-gray-800 dark:border-gray-600" value="<?= intval($editItem['sort_order'] ?? 0) ?>">
                </div>
                <div>
                    <label class="block mb-1 font-medium">Status</label>
                    <select name="status" class="w-full p-2 border rounded dark:bg-gray-800 dark:border-gray-600">
                        <option value="active" <?= ($editItem && $editItem['status']=='active') ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= ($editItem && $editItem['status']=='inactive') ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" class="neu-button px-6 py-2 bg-primary-600 text-white rounded">Save Item</button>
                <?php if ($editItem): ?>
                    <a href="source-items.php<?= $filterCat ? '?category='.$filterCat : '' ?>" class="ml-2 neu-button px-6 py-2 rounded">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Items List -->
    <div class="neu-card p-6">
        <h2 class="text-xl font-semibold mb-4">Items</h2>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-gray-100 dark:bg-gray-800">
                        <th class="p-2 text-left">ID</th>
                        <th class="p-2 text-left">Thumb</th>
                        <th class="p-2 text-left">Title</th>
                        <th class="p-2 text-left">Category</th>
                        <th class="p-2 text-left">Source</th>
                        <th class="p-2 text-left">Downloads</th>
                        <th class="p-2 text-left">Status</th>
                        <th class="p-2 text-left">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr class="border-b dark:border-gray-700">
                            <td class="p-2"><?= $item['id'] ?></td>
                            <td class="p-2">
                                <?php if ($item['thumbnail']): ?>
                                    <img src="<?= htmlspecialchars($item['thumbnail']) ?>" class="h-8 w-8 object-cover rounded">
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td class="p-2"><?= htmlspecialchars($item['title']) ?></td>
                            <td class="p-2"><?= htmlspecialchars($item['cat_name']) ?></td>
                            <td class="p-2">
                                <?php if ($item['file_path']): ?>
                                    <a href="<?= htmlspecialchars($item['file_path']) ?>" target="_blank" class="text-blue-600 dark:text-blue-400" title="Local file">📁</a>
                                <?php elseif ($item['link_url']): ?>
                                    <a href="<?= htmlspecialchars($item['link_url']) ?>" target="_blank" class="text-purple-600 dark:text-purple-400" title="External link">🔗</a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td class="p-2"><?= $item['downloads_count'] ?></td>
                            <td class="p-2">
                                <span class="px-2 py-1 rounded text-xs <?= $item['status']=='active' ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' ?>">
                                    <?= $item['status'] ?>
                                </span>
                            </td>
                            <td class="p-2 space-x-2">
                                <a href="?edit=<?= $item['id'] ?>&category=<?= $filterCat ?>" class="text-blue-600 hover:underline dark:text-blue-400">Edit</a>
                                <a href="?delete=<?= $item['id'] ?>&category=<?= $filterCat ?>" class="text-red-600 hover:underline dark:text-red-400" onclick="return confirm('Delete this item?')">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>