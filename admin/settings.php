<?php
require_once '../includes/functions.php';
requireAdmin();

$pageTitle = 'Site Settings';
include '../includes/header.php';

$db = getDB();

// Update settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCSRF($_POST['csrf_token'] ?? '')) {
    foreach ($_POST['settings'] as $key => $value) {
        $stmt = $db->prepare("UPDATE site_settings SET setting_value = ? WHERE setting_key = ?");
        $stmt->execute([$value, $key]);
    }
    $success = "Settings updated successfully";
}

// Get all settings
$settings = $db->query("SELECT * FROM site_settings ORDER BY setting_key")->fetchAll();
$settingsAssoc = [];
foreach ($settings as $s) {
    $settingsAssoc[$s['setting_key']] = $s;
}
?>

<div class="max-w-4xl mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold">Site Settings</h1>
        <a href="dashboard.php" class="neu-button px-4 py-2 rounded-lg">← Back to Dashboard</a>
    </div>

    <?php if (isset($success)): ?>
    <div class="neu-card p-4 mb-6 bg-green-50 text-green-800 border-l-4 border-green-500">
        <?php echo clean($success); ?>
    </div>
    <?php endif; ?>

    <form method="POST" class="neu-card p-6 space-y-6">
        <?php echo csrfField(); ?>
        
        <h3 class="font-bold text-lg border-b pb-2">General Settings</h3>
        
        <div class="grid md:grid-cols-2 gap-6">
            <div>
                <label class="block text-sm font-medium mb-1">Site Name</label>
                <input type="text" name="settings[site_name]" 
                       value="<?php echo clean($settingsAssoc['site_name']['setting_value'] ?? 'AkkuApps'); ?>" 
                       class="neu-button w-full px-4 py-2 rounded bg-transparent">
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-1">Maintenance Mode</label>
                <select name="settings[maintenance_mode]" class="neu-button w-full px-4 py-2 rounded bg-transparent">
                    <option value="0" <?php echo ($settingsAssoc['maintenance_mode']['setting_value'] ?? '0') == '0' ? 'selected' : ''; ?>>Off</option>
                    <option value="1" <?php echo ($settingsAssoc['maintenance_mode']['setting_value'] ?? '0') == '1' ? 'selected' : ''; ?>>On</option>
                </select>
            </div>
        </div>

        <h3 class="font-bold text-lg border-b pb-2 mt-6">Coin Economy</h3>
        
        <div class="grid md:grid-cols-2 gap-6">
            <div>
                <label class="block text-sm font-medium mb-1">Post Cost (Coins)</label>
                <input type="number" step="0.001" name="settings[post_cost]" 
                       value="<?php echo $settingsAssoc['post_cost']['setting_value'] ?? '0.010'; ?>" 
                       class="neu-button w-full px-4 py-2 rounded bg-transparent">
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-1">Like Cost (Coins)</label>
                <input type="number" step="0.001" name="settings[like_cost]" 
                       value="<?php echo $settingsAssoc['like_cost']['setting_value'] ?? '0.002'; ?>" 
                       class="neu-button w-full px-4 py-2 rounded bg-transparent">
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-1">Like Reward (Coins)</label>
                <input type="number" step="0.001" name="settings[like_reward]" 
                       value="<?php echo $settingsAssoc['like_reward']['setting_value'] ?? '0.001'; ?>" 
                       class="neu-button w-full px-4 py-2 rounded bg-transparent">
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-1">Comment Cost (Coins)</label>
                <input type="number" step="0.001" name="settings[comment_cost]" 
                       value="<?php echo $settingsAssoc['comment_cost']['setting_value'] ?? '0.002'; ?>" 
                       class="neu-button w-full px-4 py-2 rounded bg-transparent">
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-1">Comment Reward (Coins)</label>
                <input type="number" step="0.001" name="settings[comment_reward]" 
                       value="<?php echo $settingsAssoc['comment_reward']['setting_value'] ?? '0.001'; ?>" 
                       class="neu-button w-full px-4 py-2 rounded bg-transparent">
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-1">Rupee to Coin Rate</label>
                <input type="number" step="0.1" name="settings[coin_rate]" 
                       value="<?php echo $settingsAssoc['coin_rate']['setting_value'] ?? '1'; ?>" 
                       class="neu-button w-full px-4 py-2 rounded bg-transparent">
            </div>
        </div>

        <div class="flex justify-end pt-4">
            <button type="submit" class="neu-button px-8 py-3 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 font-bold">
                Save Settings
            </button>
        </div>
    </form>
</div>

<?php include '../includes/footer.php'; ?>
