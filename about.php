<?php
require_once 'includes/functions.php';
$pageTitle = 'About AkkuApps';
include 'includes/header.php';
?>
<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
    <section class="neu-card p-6 sm:p-10 bg-gradient-to-br from-slate-900 to-blue-950 text-white">
        <p class="text-sm font-bold text-cyan-200 uppercase tracking-widest">About</p>
        <h1 class="text-3xl sm:text-5xl font-black mt-3">AkkuApps by Leo Infotech</h1>
        <p class="mt-5 text-slate-200 max-w-3xl">AkkuApps.in is a Tamil Nadu based technology platform for AI tools, practical tech articles, games, source downloads, community posts, and computer/electronics services.</p>
    </section>
    <section class="grid md:grid-cols-3 gap-5">
        <div class="neu-card p-6"><h2 class="font-black text-xl">Learn</h2><p class="text-sm text-gray-600 dark:text-gray-400 mt-2">Blog articles on AI, programming, cyber security, and useful technology.</p></div>
        <div class="neu-card p-6"><h2 class="font-black text-xl">Create</h2><p class="text-sm text-gray-600 dark:text-gray-400 mt-2">Community posts, AI tools, image tools, OCR, and creative workflows.</p></div>
        <div class="neu-card p-6"><h2 class="font-black text-xl">Build</h2><p class="text-sm text-gray-600 dark:text-gray-400 mt-2">Source code, downloads, games, custom PC builder, and electronics support.</p></div>
    </section>
</div>
<?php include 'includes/footer.php'; ?>