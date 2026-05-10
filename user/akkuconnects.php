<?php
require_once '../includes/functions.php';
requireLogin();

$userId = $_SESSION['user_id'];
$db = getDB();

// Manual add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add'])) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    if ($name && $email) {
        try {
            $stmt = $db->prepare("INSERT INTO user_contacts (user_id, name, email, source) VALUES (?, ?, ?, 'manual')");
            $stmt->execute([$userId, $name, $email]);
        } catch (Exception $e) {}
    }
}

// Get contacts
$contacts = [];
try {
    $stmt = $db->prepare("SELECT * FROM user_contacts WHERE user_id = ? ORDER BY source DESC, name ASC");
    $stmt->execute([$userId]);
    $contacts = $stmt->fetchAll();
} catch (Exception $e) {
    $dbError = $e->getMessage();
}

$pageTitle = 'AkkuConnects';
include '../includes/header.php';
?>

<div class="max-w-4xl mx-auto">
    <div class="neu-card p-6 mb-6">
        <h1 class="text-2xl font-bold mb-2">🤝 AkkuConnects</h1>
        <p class="text-gray-600">Your contacts, imported and secure</p>
    </div>

    <?php if (isset($_GET['imported'])): ?>
    <div class="bg-green-100 border-l-4 border-green-500 p-4 mb-6 text-green-700">
        ✅ <?php echo intval($_GET['imported']); ?> contacts imported successfully!
    </div>
    <?php endif; ?>

    <?php if (isset($dbError)): ?>
    <div class="bg-yellow-100 p-4 mb-6 rounded">
        <p>Create table first:</p>
        <code class="block bg-gray-800 text-white p-2 mt-2 rounded text-xs">
            CREATE TABLE user_contacts (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, name VARCHAR(100), email VARCHAR(255), phone VARCHAR(20), source VARCHAR(50) DEFAULT 'manual', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
        </code>
    </div>
    <?php endif; ?>

    <!-- Google Sync - Uses existing login flow -->
    <div class="neu-card p-6 mb-6 bg-blue-50 dark:bg-blue-900/20 border border-blue-200">
        <h2 class="font-bold mb-2 flex items-center gap-2">
            <img src="https://upload.wikimedia.org/wikipedia/commons/5/53/Google_%22G%22_Logo.svg" class="w-5">
            Google Contacts
        </h2>
        <p class="text-sm text-gray-600 mb-4">Clicking below will re-authenticate with Google and import your contacts.</p>
        <a href="/auth/google-auth.php?for=contacts" class="inline-flex items-center gap-2 neu-button px-6 py-3 rounded-lg bg-white text-blue-600 font-bold">
            <svg class="w-5 h-5" viewBox="0 0 24 24">
                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
            </svg>
            Sync Google Contacts
        </a>
    </div>

    <!-- Manual Add -->
    <div class="neu-card p-6 mb-6">
        <h2 class="font-bold mb-4">Add Contact</h2>
        <form method="POST" class="flex gap-2">
            <input type="text" name="name" placeholder="Name" required class="flex-1 neu-card px-4 py-2">
            <input type="email" name="email" placeholder="Email" required class="flex-1 neu-card px-4 py-2">
            <button type="submit" name="add" class="neu-button px-6 py-2 rounded bg-primary-100 text-primary-700 font-bold">Add</button>
        </form>
    </div>

    <!-- Contacts List -->
    <div class="neu-card p-6">
        <h2 class="font-bold mb-4">Contacts (<?php echo count($contacts); ?>)</h2>
        <?php if (empty($contacts)): ?>
        <p class="text-gray-500 text-center py-8">No contacts. Import from Google or add manually.</p>
        <?php else: ?>
        <div class="space-y-2">
            <?php foreach ($contacts as $c): ?>
            <div class="flex justify-between p-3 bg-gray-50 rounded">
                <div>
                    <div class="font-bold"><?php echo htmlspecialchars($c['name']); ?></div>
                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($c['email']); ?></div>
                </div>
                <span class="text-xs bg-<?php echo $c['source'] === 'google' ? 'blue' : 'purple'; ?>-100 text-<?php echo $c['source'] === 'google' ? 'blue' : 'purple'; ?>-700 px-2 py-1 rounded">
                    <?php echo $c['source']; ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
