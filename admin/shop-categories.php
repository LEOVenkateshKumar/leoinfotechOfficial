<?php
// admin/shop-categories.php
require_once '../includes/functions.php';
requireAdmin();

$db = getDB();
$pageTitle = 'Shop Categories';
$message = '';
$error = '';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCSRF($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? createSlug($name));
        $icon = trim($_POST['icon'] ?? '📦');

        if (empty($name)) {
            $error = 'Category name is required.';
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO shop_categories (name, slug, icon) VALUES (?, ?, ?)");
                $stmt->execute([$name, $slug, $icon]);
                $message = 'Category added successfully.';
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) { // Duplicate entry
                    $error = 'A category with this slug already exists.';
                } else {
                    $error = 'Database error: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'edit') {
        $id = intval($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? createSlug($name));
        $icon = trim($_POST['icon'] ?? '📦');

        if (empty($name) || $id <= 0) {
            $error = 'Invalid input for editing.';
        } else {
            try {
                $stmt = $db->prepare("UPDATE shop_categories SET name=?, slug=?, icon=? WHERE id=?");
                $stmt->execute([$name, $slug, $icon, $id]);
                $message = 'Category updated successfully.';
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = 'Another category already uses this slug.';
                } else {
                    $error = 'Database error: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                // Optional: Check if products exist in this category before deleting?
                $stmt = $db->prepare("DELETE FROM shop_categories WHERE id=?");
                $stmt->execute([$id]);
                $message = 'Category deleted successfully.';
            } catch (PDOException $e) {
                $error = 'Could not delete category. It might be in use.';
            }
        }
    }
}

// Fetch all categories
$stmt = $db->query("SELECT * FROM shop_categories ORDER BY name ASC");
$categories = $stmt->fetchAll();

// Check if we are editing
$editingCategory = null;
if (isset($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    $stmt = $db->prepare("SELECT * FROM shop_categories WHERE id = ?");
    $stmt->execute([$editId]);
    $editingCategory = $stmt->fetch();
}

include '../includes/header.php';
?>

<div class="max-w-4xl mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">🛍️ Shop Categories</h1>
        <a href="shop-products.php" class="neu-button px-4 py-2 rounded-lg text-sm">Manage Products</a>
    </div>

    <?php if ($message): ?>
        <div class="neu-card p-4 mb-4 bg-green-50 text-green-800 border-l-4 border-green-500"><?php echo clean($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="neu-card p-4 mb-4 bg-red-50 text-red-800 border-l-4 border-red-500"><?php echo clean($error); ?></div>
    <?php endif; ?>
    <?php showFlashMessage(); ?>

    <!-- Add/Edit Form -->
    <div class="neu-card p-6 mb-6">
        <h2 class="text-xl font-semibold mb-4"><?php echo $editingCategory ? 'Edit' : 'Add New'; ?> Category</h2>
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="<?php echo $editingCategory ? 'edit' : 'add'; ?>">
            <?php if ($editingCategory): ?>
                <input type="hidden" name="id" value="<?php echo $editingCategory['id']; ?>">
            <?php endif; ?>

            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Category Name *</label>
                    <input type="text" name="name" id="cat-name" required
                           class="w-full px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800"
                           value="<?php echo htmlspecialchars($editingCategory['name'] ?? ''); ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Slug (URL-friendly)</label>
                    <input type="text" name="slug" id="cat-slug"
                           class="w-full px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800"
                           value="<?php echo htmlspecialchars($editingCategory['slug'] ?? ''); ?>">
                    <p class="text-xs text-gray-500">Auto-generated if left blank.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Icon (Emoji)</label>
                    <input type="text" name="icon"
                           class="w-full px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800"
                           value="<?php echo htmlspecialchars($editingCategory['icon'] ?? '📦'); ?>"
                           maxlength="10">
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="neu-button px-6 py-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700">
                    <?php echo $editingCategory ? 'Update Category' : 'Add Category'; ?>
                </button>
                <?php if ($editingCategory): ?>
                    <a href="shop-categories.php" class="ml-2 neu-button px-6 py-2 rounded-lg">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Categories List -->
    <div class="neu-card overflow-hidden">
        <div class="px-4 py-3 bg-gray-50 dark:bg-gray-800 border-b dark:border-gray-700 flex items-center justify-between">
            <h3 class="font-bold">All Categories (<?php echo count($categories); ?>)</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800 border-b dark:border-gray-700 text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="p-3 text-left">ID</th>
                        <th class="p-3 text-left">Name</th>
                        <th class="p-3 text-left">Slug</th>
                        <th class="p-3 text-left">Icon</th>
                        <th class="p-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y dark:divide-gray-700">
                    <?php foreach ($categories as $cat): ?>
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 transition <?php echo ($editingCategory['id'] ?? 0) == $cat['id'] ? 'bg-indigo-50 dark:bg-indigo-900/10' : ''; ?>">
                        <td class="p-3"><?php echo $cat['id']; ?></td>
                        <td class="p-3 font-medium"><?php echo htmlspecialchars($cat['name']); ?></td>
                        <td class="p-3 text-gray-500 font-mono"><?php echo htmlspecialchars($cat['slug']); ?></td>
                        <td class="p-3 text-2xl"><?php echo htmlspecialchars($cat['icon']); ?></td>
                        <td class="p-3 text-right space-x-2">
                            <a href="?edit=<?php echo $cat['id']; ?>" class="text-indigo-600 hover:underline text-xs">Edit</a>
                            <form method="POST" class="inline" onsubmit="return confirm('Delete this category? This might affect products.');">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $cat['id']; ?>">
                                <button type="submit" class="text-red-500 hover:underline text-xs">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// Auto-generate slug from name (simple version)
document.getElementById('cat-name')?.addEventListener('input', function() {
    if (!document.getElementById('cat-slug').value) { // Only auto-fill if slug is empty
        let slug = this.value.toLowerCase()
            .replace(/[^a-z0-9\s-]/g, '')
            .replace(/\s+/g, '-')
            .replace(/-+/g, '-')
            .replace(/^-+|-$/g, '');
        document.getElementById('cat-slug').value = slug;
    }
});
</script>

<?php include '../includes/footer.php'; ?>
