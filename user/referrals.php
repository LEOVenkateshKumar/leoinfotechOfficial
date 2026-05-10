<?php
require_once '../includes/functions.php';
requireLogin();

$userId = $_SESSION['user_id'];
$code = substr(strtoupper($_SESSION['user_name']), 0, 3) . $userId;

$pageTitle = 'Connect & Invite';
include '../includes/header.php';
?>

<div class="max-w-2xl mx-auto">
    <div class="neu-card p-6 mb-6 text-center">
        <h1 class="text-2xl font-bold mb-2">🤝 Invite Friends</h1>
        <p class="text-gray-600">Share and earn coins when friends join!</p>
    </div>

    <div class="neu-card p-6 mb-6 bg-primary-50 dark:bg-primary-900/20 border-2 border-primary-200">
        <h3 class="font-bold mb-3">Your Invite Link</h3>
        <div class="flex gap-2 mb-4">
            <input type="text" id="refLink" value="https://akkuapps.in/auth/login.php?ref=<?php echo $code; ?>" 
                   readonly class="flex-grow neu-card px-4 py-3 text-sm font-mono">
            <button onclick="copyLink()" class="neu-button px-6 py-2 rounded-lg font-bold">Copy</button>
        </div>
        <div class="flex gap-2 justify-center flex-wrap">
            <a href="https://wa.me/?text=Join%20AkkuApps!%20https://akkuapps.in/auth/login.php?ref=<?php echo $code; ?>" 
               target="_blank" class="neu-button px-4 py-2 rounded-full text-green-600">WhatsApp</a>
            <a href="https://www.facebook.com/sharer/sharer.php?u=https://akkuapps.in/auth/login.php?ref=<?php echo $code; ?>" 
               target="_blank" class="neu-button px-4 py-2 rounded-full text-blue-600">Facebook</a>
        </div>
    </div>

    <div class="neu-card p-6 text-center">
        <h3 class="font-bold mb-4">How it works</h3>
        <div class="space-y-2 text-sm text-gray-600">
            <p>1️⃣ Share your unique link</p>
            <p>2️⃣ Friends sign up</p>
            <p>3️⃣ Earn 0.050 coins per signup!</p>
        </div>
    </div>
</div>

<script>
function copyLink() {
    const input = document.getElementById('refLink');
    input.select();
    navigator.clipboard.writeText(input.value).then(() => alert('Copied!'));
}
</script>

<?php include '../includes/footer.php'; ?>
