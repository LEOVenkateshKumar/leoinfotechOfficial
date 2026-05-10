<?php
require_once '../includes/functions.php';
requireAdmin();

$db = getDB();
$message = '';

// Handle delete
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    // First delete items in this category (foreign key cascade)
    $stmt = $db->prepare("DELETE FROM source_categories WHERE id = ?");
    if ($stmt->execute([$id])) {
        $message = '<div class="bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300 p-3 rounded">Category deleted.</div>';
    } else {
        $message = '<div class="bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300 p-3 rounded">Error deleting category.</div>';
    }
}

// Handle add / edit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $sort = intval($_POST['sort_order'] ?? 0);
    $id = intval($_POST['id'] ?? 0);

    if (empty($name) || empty($slug)) {
        $message = '<div class="bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300 p-3 rounded">Name and slug are required.</div>';
    } else {
        // Generate slug if not provided (optional)
        if (empty($slug)) {
            $slug = createSlug($name); // use your existing createSlug() function
        }

        // Handle image upload
        $imagePath = null;
        if (!empty($_FILES['image']['name'])) {
            $targetDir = '../uploads/source/categories/';
            if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
            $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $filename = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $targetFile = $targetDir . $filename;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $targetFile)) {
                $imagePath = '/uploads/source/categories/' . $filename;
            }
        }

        if ($id > 0) {
            // Update
            $sql = "UPDATE source_categories SET name=?, slug=?, description=?, sort_order=?";
            $params = [$name, $slug, $desc, $sort];
            if ($imagePath) {
                $sql .= ", image=?";
                $params[] = $imagePath;
            }
            $sql .= " WHERE id=?";
            $params[] = $id;
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $message = '<div class="bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300 p-3 rounded">Category updated.</div>';
        } else {
            // Insert
            $stmt = $db->prepare("INSERT INTO source_categories (name, slug, description, image, sort_order) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $slug, $desc, $imagePath, $sort]);
            $message = '<div class="bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300 p-3 rounded">Category added.</div>';
        }
    }
}

// Fetch all categories
$categories = $db->query("SELECT * FROM source_categories ORDER BY sort_order ASC, name ASC")->fetchAll();

// If editing, load category data
$editCategory = null;
if (isset($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    $stmt = $db->prepare("SELECT * FROM source_categories WHERE id = ?");
    $stmt->execute([$editId]);
    $editCategory = $stmt->fetch();
}
?>
<?php $pageTitle = 'Source Categories'; include '../includes/header.php'; ?>

<div class="max-w-6xl mx-auto">
    <h1 class="text-2xl font-bold mb-6">Manage Source Categories</h1>
    <?= $message ?>

    <!-- Add/Edit Form -->
    <div class="neu-card p-6 mb-8">
        <h2 class="text-xl font-semibold mb-4"><?= $editCategory ? 'Edit' : 'Add New' ?> Category</h2>
        <form method="post" enctype="multipart/form-data">
            <?php if ($editCategory): ?>
                <input type="hidden" name="id" value="<?= $editCategory['id'] ?>">
            <?php endif; ?>
            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <label class="block mb-1 font-medium">Category Name *</label>
                    <input type="text" name="name" id="cat-name" required class="w-full p-2 border rounded dark:bg-gray-800 dark:border-gray-600" value="<?= htmlspecialchars($editCategory['name'] ?? '') ?>">
                </div>
                <div>
                    <label class="block mb-1 font-medium">Slug *</label>
                    <input type="text" name="slug" id="cat-slug" required class="w-full p-2 border rounded dark:bg-gray-800 dark:border-gray-600" value="<?= htmlspecialchars($editCategory['slug'] ?? '') ?>">
                    <p class="text-xs text-gray-500">URL-friendly name, e.g. "php-scripts"</p>
                </div>
                <div class="md:col-span-2">
                    <label class="block mb-1 font-medium">Description</label>
                    <textarea name="description" rows="3" class="w-full p-2 border rounded dark:bg-gray-800 dark:border-gray-600"><?= htmlspecialchars($editCategory['description'] ?? '') ?></textarea>
                </div>
                <div>
                    <label class="block mb-1 font-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="w-full p-2 border rounded dark:bg-gray-800 dark:border-gray-600" value="<?= intval($editCategory['sort_order'] ?? 0) ?>">
                </div>
                <div>
                    <label class="block mb-1 font-medium">Category Image (optional)</label>
                    <input type="file" name="image" accept="image/*" class="w-full p-2 border rounded dark:bg-gray-800 dark:border-gray-600">
                    <?php if (!empty($editCategory['image'])): ?>
                        <p class="text-xs mt-1">Current: <img src="<?= htmlspecialchars($editCategory['image']) ?>" class="h-10 inline-block rounded"></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" class="neu-button px-6 py-2 bg-primary-600 text-white rounded">Save Category</button>
                <?php if ($editCategory): ?>
                    <a href="source-categories.php" class="ml-2 neu-button px-6 py-2 rounded">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Categories List -->
    <div class="neu-card p-6">
        <h2 class="text-xl font-semibold mb-4">All Categories</h2>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-gray-100 dark:bg-gray-800">
                        <th class="p-2 text-left">ID</th>
                        <th class="p-2 text-left">Image</th>
                        <th class="p-2 text-left">Name</th>
                        <th class="p-2 text-left">Slug</th>
                        <th class="p-2 text-left">Items</th>
                        <th class="p-2 text-left">Sort</th>
                        <th class="p-2 text-left">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $cat): ?>
                        <?php
                        $count = $db->prepare("SELECT COUNT(*) FROM source_items WHERE category_id = ?");
                        $count->execute([$cat['id']]);
                        $itemCount = $count->fetchColumn();
                        ?>
                        <tr class="border-b dark:border-gray-700">
                            <td class="p-2"><?= $cat['id'] ?></td>
                            <td class="p-2">
                                <?php if ($cat['image']): ?>
                                    <img src="<?= htmlspecialchars($cat['image']) ?>" class="h-8 w-8 object-cover rounded">
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td class="p-2"><?= htmlspecialchars($cat['name']) ?></td>
                            <td class="p-2"><?= htmlspecialchars($cat['slug']) ?></td>
                            <td class="p-2"><?= $itemCount ?></td>
                            <td class="p-2"><?= $cat['sort_order'] ?></td>
                            <td class="p-2 space-x-2">
                                <a href="?edit=<?= $cat['id'] ?>" class="text-blue-600 hover:underline dark:text-blue-400">Edit</a>
                                <a href="?delete=<?= $cat['id'] ?>" class="text-red-600 hover:underline dark:text-red-400" onclick="return confirm('Delete this category? All items inside will also be deleted.')">Delete</a>
                                <a href="source-items.php?category=<?= $cat['id'] ?>" class="text-green-600 hover:underline dark:text-green-400">View Items</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// Auto-generate slug from name
document.getElementById('cat-name')?.addEventListener('input', function() {
    let slug = this.value.toLowerCase()
        .replace(/[^a-z0-9\s-]/g, '')   // remove special chars
        .replace(/\s+/g, '-')            // replace spaces with hyphens
        .replace(/-+/g, '-')             // collapse multiple hyphens
        .replace(/^-|-$/g, '');           // trim leading/trailing hyphens
    document.getElementById('cat-slug').value = slug;
});
</script>

<?php include '../includes/footer.php'; ?>