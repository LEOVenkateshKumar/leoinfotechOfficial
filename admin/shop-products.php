<?php
// admin/shop-products.php
require_once '../includes/functions.php';
requireAdmin();

$db = getDB();
$pageTitle = 'Shop Products';
$message = '';
$error = '';

// Fetch categories for dropdown
$stmt = $db->query("SELECT id, name FROM shop_categories ORDER BY name ASC");
$categories = $stmt->fetchAll();

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCSRF($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if (in_array($action, ['add', 'edit'])) {
        $id = ($action === 'edit') ? intval($_POST['id'] ?? 0) : null;
        $categoryId = intval($_POST['category_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? createSlug($name));
        $brand = trim($_POST['brand'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $sku = trim($_POST['sku'] ?? '');
        $price = floatval($_POST['price'] ?? 0);
        $mrp = !empty($_POST['mrp']) ? floatval($_POST['mrp']) : null;
        $offer_price = !empty($_POST['offer_price']) ? floatval($_POST['offer_price']) : null;
        $offer_expiry = !empty($_POST['offer_expiry']) ? date('Y-m-d', strtotime($_POST['offer_expiry'])) : null;
        $stockQty = intval($_POST['stock_qty'] ?? 0);
        $lowStockAlert = intval($_POST['low_stock_alert'] ?? 5);
        // Get Rich Text Description (no htmlspecialchars needed)
        $description = $_POST['description'] ?? '';
        $status = $_POST['status'] ?? 'active';
        $seller_name = trim($_POST['seller_name'] ?? '');
        $seller_phone = trim($_POST['seller_phone'] ?? '');

        // Collect Specs
        $specs = [];
        if (isset($_POST['specs']) && is_array($_POST['specs'])) {
            foreach($_POST['specs'] as $key => $value) {
                if (!empty($key) && !empty($value)) {
                    $specs[trim($key)] = trim($value);
                }
            }
        }
        $specsJson = json_encode($specs);

        if ($mrp === null) {
            $mrp = $price;
        }

        if (empty($name) || $categoryId <= 0 || $price < 0) {
            $error = 'Name, Category, and valid Price are required.';
        } else {
            try {
                if ($action === 'add') {
                    $stmt = $db->prepare("
                        INSERT INTO shop_products (
                            category_id, name, slug, brand, model, sku, description, 
                            price, mrp, offer_price, offer_expiry, stock_qty, low_stock_alert, 
                            specs, status, seller_name, seller_phone
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $categoryId, $name, $slug, $brand, $model, $sku, $description,
                        $price, $mrp, $offer_price, $offer_expiry, $stockQty, $lowStockAlert,
                        $specsJson, $status, $seller_name, $seller_phone
                    ]);
                    $newProductId = $db->lastInsertId();
                    
                    // Save media only if adding (for edit, separate handling)
                    saveProductMedia($newProductId, $_POST['media_urls'] ?? [], $_POST['media_types'] ?? []);
                    
                    $message = 'Product added successfully.';
                } else { // edit
                    $stmt = $db->prepare("
                        UPDATE shop_products SET 
                            category_id=?, name=?, slug=?, brand=?, model=?, sku=?, description=?, 
                            price=?, mrp=?, offer_price=?, offer_expiry=?, stock_qty=?, low_stock_alert=?, 
                            specs=?, status=?, seller_name=?, seller_phone=?
                        WHERE id=?
                    ");
                    $stmt->execute([
                        $categoryId, $name, $slug, $brand, $model, $sku, $description,
                        $price, $mrp, $offer_price, $offer_expiry, $stockQty, $lowStockAlert,
                        $specsJson, $status, $seller_name, $seller_phone, $id
                    ]);
                    
                    // Update media
                    deleteProductMedia($id); // Clear old media first (simple approach)
                    saveProductMedia($id, $_POST['media_urls'] ?? [], $_POST['media_types'] ?? []);
                    
                    $message = 'Product updated successfully.';
                }
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = 'A product with this slug already exists.';
                } else {
                    $error = 'Database error: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $stmt = $db->prepare("DELETE FROM shop_products WHERE id=?");
                $stmt->execute([$id]);
                $message = 'Product deleted successfully.';
            } catch (PDOException $e) {
                $error = 'Could not delete product. It might be linked to orders.';
            }
        }
    } elseif ($action === 'add_media') {
        // Simple upload for images, YouTube URL, etc.
        $productId = intval($_POST['product_id'] ?? 0);
        $mediaType = trim($_POST['media_type'] ?? '');
        $mediaUrl = trim($_POST['media_url'] ?? '');
        $title = trim($_POST['media_title'] ?? '');
        
        if ($productId > 0 && !empty($mediaType) && !empty($mediaUrl)) {
            try {
                // If image upload, handle it here. For simplicity, we assume URL for now.
                $stmt = $db->prepare("INSERT INTO shop_product_media (product_id, media_type, media_url, title, is_primary) VALUES (?, ?, ?, ?, 0)");
                $stmt->execute([$productId, $mediaType, $mediaUrl, $title]);
                echo json_encode(['success' => true, 'message' => 'Media added']);
                exit;
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit;
            }
        }
    }
}

// Helper: Save Media
function saveProductMedia($productId, $urls, $types) {
    if (empty($urls) || empty($types)) return;
    $db = getDB();
    foreach ($urls as $key => $url) {
        if (!empty($url)) {
            $stmt = $db->prepare("INSERT INTO shop_product_media (product_id, media_type, media_url) VALUES (?, ?, ?)");
            $stmt->execute([$productId, $types[$key], $url]);
        }
    }
}

// Helper: Delete Media
function deleteProductMedia($productId) {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM shop_product_media WHERE product_id = ?");
    $stmt->execute([$productId]);
}

// Fetch all products with category names
$stmt = $db->query("
    SELECT p.*, c.name as category_name 
    FROM shop_products p 
    LEFT JOIN shop_categories c ON p.category_id = c.id 
    ORDER BY p.created_at DESC
");
$products = $stmt->fetchAll();

// Check if we are editing
$editingProduct = null;
if (isset($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    $stmt = $db->prepare("SELECT * FROM shop_products WHERE id = ?");
    $stmt->execute([$editId]);
    $editingProduct = $stmt->fetch();
    $editingProductSpecs = json_decode($editingProduct['specs'], true) ?: [];
    $editingProductMedia = [];
    $stmt = $db->prepare("SELECT * FROM shop_product_media WHERE product_id = ? ORDER BY is_primary DESC, sort_order ASC");
    $stmt->execute([$editId]);
    $editingProductMedia = $stmt->fetchAll();
}

// Fetch reviews for product (if editing)
$editingProductReviews = [];
if ($editingProduct) {
    $stmt = $db->prepare("
        SELECT r.*, u.name as user_name, u.avatar 
        FROM shop_product_reviews r 
        LEFT JOIN users u ON r.user_id = u.id 
        WHERE r.product_id = ? AND r.status = 'approved' 
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$editingProduct['id']]);
    $editingProductReviews = $stmt->fetchAll();
}

include '../includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">🛒 Shop Products</h1>
        <div class="flex gap-2">
            <a href="shop-categories.php" class="neu-button px-4 py-2 rounded-lg text-sm">Manage Categories</a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="neu-card p-4 mb-4 bg-green-50 text-green-800 border-l-4 border-green-500">
            <?php echo clean($message); ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="neu-card p-4 mb-4 bg-red-50 text-red-800 border-l-4 border-red-500">
            <?php echo clean($error); ?>
        </div>
    <?php endif; ?>
    <?php showFlashMessage(); ?>

    <!-- Add/Edit Form -->
    <div class="neu-card p-6 mb-6">
        <h2 class="text-xl font-semibold mb-4">
            <?php echo $editingProduct ? '✏️ Edit Product' : '➕ Add New Product'; ?>
        </h2>
        <form method="POST" id="productForm" enctype="multipart/form-data">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="<?php echo $editingProduct ? 'edit' : 'add'; ?>">
            <?php if ($editingProduct): ?>
                <input type="hidden" name="id" value="<?php echo $editingProduct['id']; ?>">
            <?php endif; ?>

            <!-- Basic Info -->
            <div class="grid md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Product Name *</label>
                    <input type="text" name="name" id="prod-name" required
                           class="w-full px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800"
                           value="<?php echo htmlspecialchars($editingProduct['name'] ?? ''); ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Category *</label>
                    <select name="category_id" required class="w-full px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800">
                        <option value="">Select Category</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo (isset($editingProduct) && $editingProduct['category_id'] == $cat['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Brand, Model, SKU -->
            <div class="grid md:grid-cols-3 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Brand</label>
                    <input type="text" name="brand" 
                           class="w-full px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800"
                           value="<?php echo htmlspecialchars($editingProduct['brand'] ?? ''); ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Model</label>
                    <input type="text" name="model" 
                           class="w-full px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800"
                           value="<?php echo htmlspecialchars($editingProduct['model'] ?? ''); ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">SKU / S.N.</label>
                    <input type="text" name="sku" 
                           class="w-full px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800"
                           value="<?php echo htmlspecialchars($editingProduct['sku'] ?? ''); ?>">
                </div>
            </div>

            <!-- Slugs -->
            <div class="grid md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Slug (URL-friendly)</label>
                    <input type="text" name="slug" id="prod-slug"
                           class="w-full px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800"
                           value="<?php echo htmlspecialchars($editingProduct['slug'] ?? ''); ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Status</label>
                    <select name="status" class="w-full px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800">
                        <option value="active" <?php echo ($editingProduct['status'] ?? '') === 'active' ? 'selected' : ''; ?>>🟢 Active</option>
                        <option value="inactive" <?php echo ($editingProduct['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>🔴 Inactive</option>
                        <option value="out_of_stock" <?php echo ($editingProduct['status'] ?? '') === 'out_of_stock' ? 'selected' : ''; ?>>🟠 Out of Stock</option>
                    </select>
                </div>
            </div>

            <!-- Pricing -->
            <div class="border-b border-gray-200 dark:border-gray-700 pb-4 mb-4">
                <h3 class="font-bold text-lg mb-2">💰 Pricing & Offers</h3>
                <div class="grid md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Price (₹) *</label>
                        <input type="number" name="price" step="0.01" min="0" required
                               class="w-full px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800"
                               value="<?php echo $editingProduct['price'] ?? '0.00'; ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">MRP (₹)</label>
                        <input type="number" name="mrp" step="0.01" min="0"
                               class="w-full px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800"
                               value="<?php echo $editingProduct['mrp'] ?? ''; ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Offer Price (₹)</label>
                        <input type="number" name="offer_price" step="0.01" min="0"
                               class="w-full px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800"
                               value="<?php echo $editingProduct['offer_price'] ?? ''; ?>">
                    </div>
                </div>
                <div class="mt-3">
                    <label class="block text-sm font-medium mb-1">Offer Expiry</label>
                    <input type="date" name="offer_expiry"
                           class="w-full px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800"
                           value="<?php echo $editingProduct['offer_expiry'] ? date('Y-m-d', strtotime($editingProduct['offer_expiry'])) : ''; ?>">
                    <p class="text-xs text-gray-500 mt-1">Leave empty for no offer.</p>
                </div>
            </div>

            <!-- Inventory -->
            <div class="border-b border-gray-200 dark:border-gray-700 pb-4 mb-4">
                <h3 class="font-bold text-lg mb-2">📦 Inventory</h3>
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Stock Quantity</label>
                        <input type="number" name="stock_qty" min="0"
                               class="w-full px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800"
                               value="<?php echo $editingProduct['stock_qty'] ?? '0'; ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Low Stock Alert Level</label>
                        <input type="number" name="low_stock_alert" min="0"
                               class="w-full px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800"
                               value="<?php echo $editingProduct['low_stock_alert'] ?? '5'; ?>">
                    </div>
                </div>
            </div>

            <!-- Seller -->
            <div class="border-b border-gray-200 dark:border-gray-700 pb-4 mb-4">
                <h3 class="font-bold text-lg mb-2">📞 Seller Details</h3>
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Seller Name</label>
                        <input type="text" name="seller_name" 
                               class="w-full px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800"
                               value="<?php echo htmlspecialchars($editingProduct['seller_name'] ?? ''); ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Seller Phone</label>
                        <input type="tel" name="seller_phone" 
                               class="w-full px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800"
                               value="<?php echo htmlspecialchars($editingProduct['seller_phone'] ?? ''); ?>">
                    </div>
                </div>
            </div>

            <!-- Product Media (Images, YouTube, PDF) -->
            <div class="border-b border-gray-200 dark:border-gray-700 pb-4 mb-4">
                <h3 class="font-bold text-lg mb-2">🖼️ Product Media</h3>
                
                <?php if ($editingProduct): ?>
                <div class="mb-4">
                    <h4 class="font-medium text-sm mb-2">Existing Media</h4>
                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                        <?php foreach ($editingProductMedia as $media): ?>
                            <div class="relative group">
                                <img src="<?php echo htmlspecialchars($media['media_url']); ?>" class="w-full aspect-square object-cover rounded border border-gray-200 dark:border-gray-600">
                                <div class="absolute top-2 right-2">
                                    <span class="px-2 py-1 bg-indigo-600 text-white text-xs rounded capitalize">
                                        <?php echo $media['media_type']; ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Media Type</label>
                        <select id="mediaType" class="w-full px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800">
                            <option value="image">📷 Image URL</option>
                            <option value="youtube">📺 YouTube URL</option>
                            <option value="document">📄 PDF Document</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Media URL</label>
                        <input type="text" id="mediaUrl" placeholder="https://example.com/image.jpg or https://youtube.com/watch?v=..." 
                               class="w-full px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800">
                    </div>
                </div>
                <div class="mt-3">
                    <button type="button" onclick="addMediaToForm()" class="neu-button px-4 py-2 rounded bg-green-600 text-white">
                        + Add Media
                    </button>
                </div>
                
                <div id="mediaInputContainer" class="mt-4 space-y-2 hidden">
                    <!-- Media inputs will be added here via JS -->
                </div>
                
                <script>
                    function addMediaToForm() {
                        const type = document.getElementById('mediaType').value;
                        const url = document.getElementById('mediaUrl').value;
                        if (!url) return alert('Please enter a URL');
                        
                        const container = document.getElementById('mediaInputContainer');
                        container.classList.remove('hidden');
                        
                        const div = document.createElement('div');
                        div.className = 'flex gap-2 p-3 bg-gray-50 dark:bg-gray-800 rounded';
                        div.innerHTML = `
                            <input type="hidden" name="media_types[]" value="${type}">
                            <input type="hidden" name="media_urls[]" value="${url}">
                            <div class="flex-1">
                                <div class="text-xs font-bold text-gray-500 uppercase">URL:</div>
                                <div class="text-sm truncate" title="${url}">${url.substring(0, 40)}${url.length > 40 ? '...' : ''}</div>
                            </div>
                            <button type="button" onclick="this.closest('div').remove()" class="px-2 py-1 bg-red-500 text-white rounded text-sm">
                                Remove
                            </button>
                        `;
                        container.appendChild(div);
                        document.getElementById('mediaUrl').value = '';
                    }
                </script>
            </div>

            <!-- Specifications -->
            <h3 class="font-bold text-lg mb-2">🔧 Specifications</h3>
            <div id="specs-container" class="mb-4">
                <?php if ($editingProduct && $editingProductSpecs): ?>
                    <?php foreach ($editingProductSpecs as $key => $value): ?>
                        <div class="flex gap-2 mb-2 spec-row">
                            <input type="text" name="specs[<?php echo htmlspecialchars($key); ?>]" 
                                   placeholder="Spec Name (e.g. CPU)" class="flex-1 px-3 py-1 border rounded" 
                                   value="<?php echo htmlspecialchars($key); ?>">
                            <input type="text" name="specs[value_<?php echo htmlspecialchars($key); ?>]" 
                                   placeholder="Value (e.g. Intel i7)" class="flex-1 px-3 py-1 border rounded" 
                                   value="<?php echo htmlspecialchars($value); ?>">
                            <button type="button" onclick="removeSpec(this)" class="px-2 py-1 bg-red-500 text-white rounded">-</button>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <button type="button" onclick="addSpecRow()" class="mb-4 px-3 py-1 bg-indigo-500 text-white rounded text-sm">
                + Add Spec
            </button>

            <!-- Description (TinyMCE Editor) -->
            <div class="border-b border-gray-200 dark:border-gray-700 pb-4 mb-4">
                <h3 class="font-bold text-lg mb-2">📝 Description & Content</h3>
                
                <!-- TinyMCE CDN -->
                <script src="https://cdn.tiny.cloud/1/no-api-key/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
                <script>
                    tinymce.init({
                        selector: '#description',
                        plugins: 'image media link table code',
                        toolbar: 'undo redo | bold italic underline strikethrough | link image media | alignleft aligncenter alignright | outdent indent | code',
                        images_upload_url: '/api/upload-image.php', // Optional: For direct uploads
                        paste_data_images: true,
                        menubar: false,
                        statusbar: false,
                        height: 300,
                        relative_urls: false,
                        remove_script_host: false,
                        convert_urls: false,
                        entity_encoding: 'raw'
                    });
                </script>
                <textarea name="description" id="description" rows="10" 
                          class="w-full px-4 py-2 rounded-lg border dark:border-gray-600 dark:bg-gray-800">
                    <?php echo $editingProduct['description'] ?? ''; ?>
                </textarea>
                <p class="text-xs text-gray-500 mt-1">Supports images, videos, links, tables, etc.</p>
            </div>

            <!-- Review & Ratings Section (Display Only) -->
            <?php if ($editingProduct): ?>
            <div class="border-b border-gray-200 dark:border-gray-700 pb-4 mb-4">
                <h3 class="font-bold text-lg mb-2">⭐ Customer Reviews & Ratings</h3>
                <?php if (empty($editingProductReviews)): ?>
                    <p class="text-gray-500">No reviews yet.</p>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($editingProductReviews as $review): ?>
                        <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg border dark:border-gray-700">
                            <div class="flex items-center gap-2 mb-2">
                                <div class="flex text-amber-400">
                                    <?php for ($i=1; $i<=5; $i++): ?>
                                        <span class="<?php echo $i <= $review['rating'] ? '' : 'text-gray-300'; ?>">&#9733;</span>
                                    <?php endfor; ?>
                                </div>
                                <span class="text-sm text-gray-500">on <?php echo date('M d, Y', strtotime($review['created_at'])); ?></span>
                            </div>
                            <h4 class="font-semibold text-gray-900 dark:text-white mb-1"><?php echo htmlspecialchars($review['title'] ?? 'Review'); ?></h4>
                            <p class="text-gray-700 dark:text-gray-300 mb-2"><?php echo nl2br(htmlspecialchars($review['comment'])); ?></p>
                            <div class="text-xs text-gray-500">
                                <span>By: <?php echo htmlspecialchars($review['user_name'] ?? 'User'); ?></span>
                                <?php if ($review['verified_purchase']): ?>
                                    <span class="ml-2 px-1.5 py-0.5 bg-green-100 text-green-700 rounded text-[10px]">Verified Buyer</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="mt-6">
                <button type="submit" class="neu-button px-6 py-3 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 font-bold shadow-lg">
                    <?php echo $editingProduct ? 'Update Product' : 'Add Product'; ?>
                </button>
                <?php if ($editingProduct): ?>
                    <a href="shop-products.php" class="ml-2 neu-button px-6 py-3 rounded-lg bg-gray-600 text-white hover:bg-gray-700">
                        Cancel
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Products List -->
    <div class="neu-card overflow-hidden">
        <div class="px-4 py-3 bg-gray-50 dark:bg-gray-800 border-b dark:border-gray-700 flex items-center justify-between">
            <h3 class="font-bold">All Products (<?php echo count($products); ?>)</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800 border-b dark:border-gray-700 text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="p-3 text-left">S.N.</th>
                        <th class="p-3 text-left">Name</th>
                        <th class="p-3 text-left">Brand</th>
                        <th class="p-3 text-left">Price</th>
                        <th class="p-3 text-left">Offer</th>
                        <th class="p-3 text-left">Stock</th>
                        <th class="p-3 text-left">Updated</th>
                        <th class="p-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y dark:divide-gray-700">
                    <?php foreach ($products as $prod): 
                        $offerText = '';
                        if (!empty($prod['offer_price']) && 
                            !empty($prod['offer_expiry']) && 
                            strtotime($prod['offer_expiry']) >= strtotime(date('Y-m-d'))) {
                            $offerText = '<span class="text-red-600 font-bold">₹' . number_format($prod['offer_price'], 2) . '</span>
                                          <span class="text-xs bg-red-100 text-red-800 px-2 py-0.5 rounded ml-2">Ends: ' . date('M d', strtotime($prod['offer_expiry'])) . '</span>';
                        } else {
                            $offerText = '-';
                        }
                    ?>
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 transition <?php echo ($editingProduct['id'] ?? 0) == $prod['id'] ? 'bg-indigo-50 dark:bg-indigo-900/10' : ''; ?>">
                        <td class="p-3 font-mono text-xs text-gray-500">
                            <?php echo $prod['sku'] ?? $prod['id']; ?>
                        </td>
                        <td class="p-3">
                            <div class="font-semibold text-gray-900 dark:text-white">
                                <?php echo htmlspecialchars($prod['name']); ?>
                            </div>
                            <div class="text-xs text-gray-500">
                                <?php echo htmlspecialchars($prod['category_name'] ?? 'Uncategorized'); ?>
                            </div>
                        </td>
                        <td class="p-3 text-gray-600 dark:text-gray-400">
                            <?php echo htmlspecialchars($prod['brand'] ?? '-'); ?> <?php echo htmlspecialchars($prod['model'] ?? ''); ?>
                        </td>
                        <td class="p-3 font-mono">₹<?php echo number_format($prod['price'], 2); ?></td>
                        <td class="p-3">
                            <?php echo $offerText; ?>
                        </td>
                        <td class="p-3 <?php echo $prod['stock_qty'] <= $prod['low_stock_alert'] ? 'text-red-500 font-bold' : ''; ?>">
                            <?php echo $prod['stock_qty']; ?>
                            <?php if ($prod['stock_qty'] <= $prod['low_stock_alert'] && $prod['stock_qty'] > 0): ?>
                                <span class="ml-1 text-xs text-orange-500">(Low!)</span>
                            <?php elseif ($prod['stock_qty'] == 0): ?>
                                <span class="ml-1 text-xs text-red-600">(Out of Stock)</span>
                            <?php endif; ?>
                        </td>
                        <td class="p-3 text-xs text-gray-500">
                            <?php echo date('M j, Y', strtotime($prod['updated_at'])); ?>
                        </td>
                        <td class="p-3 text-right space-x-2">
                            <a href="?edit=<?php echo $prod['id']; ?>" class="text-indigo-600 hover:underline text-xs">Edit</a>
                            <form method="POST" class="inline" onsubmit="return confirm('Delete this product?');">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $prod['id']; ?>">
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
// Auto-generate slug
