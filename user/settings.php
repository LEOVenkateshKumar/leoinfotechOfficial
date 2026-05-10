<?php
require_once '../includes/functions.php';
requireLogin();

$userId = $_SESSION['user_id'];
$db = getDB();

// Get current user data
$stmt = $db->prepare("SELECT name, email, avatar, bio, profile_visibility FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

$message = '';
$messageType = '';

// Handle Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (validateCSRF($_POST['csrf_token'] ?? '')) {
        $name = trim($_POST['name'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        $avatar = trim($_POST['avatar'] ?? '');
        $visibility = $_POST['profile_visibility'] ?? 'public';
        
        if (empty($name)) {
            $message = 'Name cannot be empty';
            $messageType = 'danger';
        } elseif (strlen($name) > 100) {
            $message = 'Name too long';
            $messageType = 'danger';
        } else {
            $stmt = $db->prepare("UPDATE users SET name = ?, bio = ?, avatar = ?, profile_visibility = ? WHERE id = ?");
            $stmt->execute([$name, $bio, $avatar, $visibility, $userId]);
            
            // Update session
            $_SESSION['user_name'] = $name;
            
            $message = 'Profile updated successfully!';
            $messageType = 'success';
            
            // Refresh data
            $stmt = $db->prepare("SELECT name, email, avatar, bio, profile_visibility FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
        }
    }
}

$pageTitle = 'Settings';
include '../includes/header.php';
?>

<div class="max-w-2xl mx-auto">
    <div class="neu-card p-6 mb-6">
        <h1 class="text-2xl font-bold mb-2">⚙️ Profile Settings</h1>
        <p class="text-gray-600 dark:text-gray-400">Update your profile information</p>
    </div>

    <?php if ($message): ?>
    <div class="neu-card p-4 mb-6 <?php echo $messageType === 'success' ? 'bg-green-50 border-l-4 border-green-500 text-green-800' : 'bg-red-50 border-l-4 border-red-500 text-red-800'; ?>">
        <?php echo clean($message); ?>
    </div>
    <?php endif; ?>

    <div class="neu-card p-6">
        <form method="POST" action="">
            <?php echo csrfField(); ?>
            
            <!-- Name -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Display Name</label>
                <input type="text" name="name" value="<?php echo clean($user['name']); ?>" 
                       class="w-full neu-card px-4 py-3 bg-transparent border-none focus:ring-0"
                       maxlength="100" required>
                <p class="text-xs text-gray-500 mt-1">This will be shown as @username</p>
            </div>

            <!-- Bio -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Bio</label>
                <textarea name="bio" rows="3" maxlength="200"
                          class="w-full neu-card px-4 py-3 bg-transparent border-none focus:ring-0 resize-none"
                          placeholder="Tell us about yourself..."><?php echo clean($user['bio'] ?? ''); ?></textarea>
                <p class="text-xs text-gray-500 mt-1">Max 200 characters</p>
            </div>

            <!-- Avatar URL -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Avatar URL</label>
                <input type="url" name="avatar" value="<?php echo clean($user['avatar'] ?? ''); ?>" 
                       class="w-full neu-card px-4 py-3 bg-transparent border-none focus:ring-0"
                       placeholder="https://example.com/avatar.jpg">
                <p class="text-xs text-gray-500 mt-1">Leave empty to use default letter avatar</p>
            </div>

            <!-- Profile Visibility -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Profile Visibility</label>
                <div class="flex gap-4">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="profile_visibility" value="public" 
                               <?php echo ($user['profile_visibility'] ?? 'public') === 'public' ? 'checked' : ''; ?>
                               class="accent-primary-600">
                        <span>🌐 Public - Anyone can view</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="profile_visibility" value="private" 
                               <?php echo ($user['profile_visibility'] ?? '') === 'private' ? 'checked' : ''; ?>
                               class="accent-primary-600">
                        <span>🔒 Private - Only followers can view posts</span>
                    </label>
                </div>
            </div>

            <!-- Email (Read only) -->
            <div class="mb-6 opacity-75">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Email (Cannot change)</label>
                <input type="email" value="<?php echo clean($user['email']); ?>" disabled
                       class="w-full neu-card px-4 py-3 bg-gray-100 dark:bg-gray-800 border-none cursor-not-allowed">
            </div>

            <div class="flex gap-4">
                <button type="submit" name="update_profile" 
                        class="neu-button px-8 py-3 rounded-full font-bold text-primary-700 dark:text-primary-300 flex-1">
                    Save Changes
                </button>
                <a href="/user/dashboard.php" 
                   class="neu-button px-8 py-3 rounded-full text-center text-gray-600">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
