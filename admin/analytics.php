<?php
require_once '../includes/functions.php';
requireAdmin();

$pageTitle = 'Advanced Analytics';
include '../includes/header.php';

$db = getDB();

// Date ranges
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$weekAgo = date('Y-m-d', strtotime('-7 days'));
$monthAgo = date('Y-m-d', strtotime('-30 days'));

$period = $_GET['period'] ?? 'today';

switch ($period) {
    case 'week': $dateFilter = "hit_date >= '$weekAgo'"; break;
    case 'month': $dateFilter = "hit_date >= '$monthAgo'"; break;
    case 'yesterday': $dateFilter = "hit_date = '$yesterday'"; break;
    default: $dateFilter = "hit_date = '$today'";
}

// OVERVIEW METRICS
$stats = [
    'total_visits' => $db->query("SELECT COUNT(*) FROM page_analytics WHERE $dateFilter")->fetchColumn() ?? 0,
    'unique_visitors' => $db->query("SELECT COUNT(DISTINCT ip_address) FROM page_analytics WHERE $dateFilter")->fetchColumn() ?? 0,
    'unique_sessions' => $db->query("SELECT COUNT(DISTINCT session_id) FROM page_analytics WHERE $dateFilter")->fetchColumn() ?? 0,
    'logged_in_users' => $db->query("SELECT COUNT(DISTINCT user_id) FROM page_analytics WHERE user_id IS NOT NULL AND $dateFilter")->fetchColumn() ?? 0,
    'avg_time' => $db->query("SELECT AVG(time_spent_seconds) FROM page_analytics WHERE time_spent_seconds > 0 AND $dateFilter")->fetchColumn() ?? 0,
];

// CATEGORY-WISE ANALYTICS
$categoryStats = $db->query("
    SELECT category, COUNT(*) as visits, AVG(time_spent_seconds) as avg_time
    FROM page_analytics 
    WHERE $dateFilter AND category != 'other'
    GROUP BY category ORDER BY visits DESC
")->fetchAll();

// TOP PAGES
$topPages = $db->query("
    SELECT page_url, category, COUNT(*) as views, AVG(time_spent_seconds) as avg_time
    FROM page_analytics 
    WHERE $dateFilter
    GROUP BY page_url ORDER BY views DESC LIMIT 15
")->fetchAll();

// DEVICE STATS
$deviceStats = $db->query("
    SELECT device_type, COUNT(*) as count FROM page_analytics 
    WHERE $dateFilter GROUP BY device_type
")->fetchAll(PDO::FETCH_KEY_PAIR);

// GEOGRAPHY STATS - Countries
$countryStats = $db->query("
    SELECT country, COUNT(*) as visits 
    FROM page_analytics 
    WHERE $dateFilter AND country IS NOT NULL AND country != 'Unknown'
    GROUP BY country 
    ORDER BY visits DESC 
    LIMIT 10
")->fetchAll();

// GEOGRAPHY STATS - Regions/States
$regionStats = $db->query("
    SELECT region, country, COUNT(*) as visits 
    FROM page_analytics 
    WHERE $dateFilter AND region IS NOT NULL AND region != 'Unknown'
    GROUP BY region, country
    ORDER BY visits DESC 
    LIMIT 10
")->fetchAll();

// GEOGRAPHY STATS - Cities
$cityStats = $db->query("
    SELECT city, region, country, COUNT(*) as visits 
    FROM page_analytics 
    WHERE $dateFilter AND city IS NOT NULL AND city != 'Unknown'
    GROUP BY city, region, country
    ORDER BY visits DESC 
    LIMIT 10
")->fetchAll();

// TIMEZONE STATS
$timezoneStats = $db->query("
    SELECT timezone, COUNT(*) as visits 
    FROM page_analytics 
    WHERE $dateFilter AND timezone IS NOT NULL
    GROUP BY timezone 
    ORDER BY visits DESC 
    LIMIT 8
")->fetchAll();

// IP ADDRESSES (Recent unique)
$recentIPs = $db->query("
    SELECT DISTINCT ip_address, country, region, city, timezone, 
           COUNT(*) as requests, MAX(hit_date) as last_visit
    FROM page_analytics 
    WHERE $dateFilter
    GROUP BY ip_address 
    ORDER BY requests DESC 
    LIMIT 15
")->fetchAll();

// HOURLY STATS
$hourlyStats = [];
for ($i = 0; $i < 24; $i++) $hourlyStats[$i] = 0;
$hourlyData = $db->query("SELECT hit_hour, COUNT(*) as count FROM page_analytics WHERE $dateFilter GROUP BY hit_hour")->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($hourlyData as $h => $c) $hourlyStats[$h] = $c;

// Helper function for country flags
function getCountryFlag($country) {
    $flags = [
        'India' => '🇮🇳', 'United States' => '🇺🇸', 'United Kingdom' => '🇬🇧',
        'Canada' => '🇨🇦', 'Australia' => '🇦🇺', 'Germany' => '🇩🇪',
        'France' => '🇫🇷', 'Singapore' => '🇸🇬', 'Japan' => '🇯🇵',
        'Sri Lanka' => '🇱🇰', 'Bangladesh' => '🇧🇩', 'Pakistan' => '🇵🇰',
        'China' => '🇨🇳', 'Russia' => '🇷🇺', 'Brazil' => '🇧🇷'
    ];
    return $flags[$country] ?? '🌐';
}
?>

<div class="max-w-7xl mx-auto px-4 py-8">
    <div class="neu-card p-6 mb-6 bg-gradient-to-r from-indigo-600 to-purple-600 text-white">
        <div class="flex flex-col md:flex-row justify-between items-center gap-4">
            <div>
                <h1 class="text-3xl font-bold">📊 Advanced Analytics</h1>
                <p class="text-indigo-100">Geographic & Behavioral Insights</p>
            </div>
            <div class="flex gap-2">
                <a href="?period=today" class="px-4 py-2 rounded <?php echo $period=='today'?'bg-white text-indigo-600':'bg-indigo-500 text-white'; ?>">Today</a>
                <a href="?period=yesterday" class="px-4 py-2 rounded <?php echo $period=='yesterday'?'bg-white text-indigo-600':'bg-indigo-500 text-white'; ?>">Yesterday</a>
                <a href="?period=week" class="px-4 py-2 rounded <?php echo $period=='week'?'bg-white text-indigo-600':'bg-indigo-500 text-white'; ?>">7 Days</a>
                <a href="?period=month" class="px-4 py-2 rounded <?php echo $period=='month'?'bg-white text-indigo-600':'bg-indigo-500 text-white'; ?>">30 Days</a>
            </div>
        </div>
    </div>

    <!-- KEY METRICS -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="neu-card p-4 text-center border-l-4 border-blue-500">
            <div class="text-2xl font-bold text-blue-600"><?php echo number_format((int)$stats['total_visits']); ?></div>
            <div class="text-xs text-gray-600">Total Hits</div>
        </div>
        <div class="neu-card p-4 text-center border-l-4 border-green-500">
            <div class="text-2xl font-bold text-green-600"><?php echo number_format((int)$stats['unique_visitors']); ?></div>
            <div class="text-xs text-gray-600">Unique Visitors</div>
        </div>
        <div class="neu-card p-4 text-center border-l-4 border-purple-500">
            <div class="text-2xl font-bold text-purple-600"><?php echo number_format((int)$stats['unique_sessions']); ?></div>
            <div class="text-xs text-gray-600">Sessions</div>
        </div>
        <div class="neu-card p-4 text-center border-l-4 border-amber-500">
            <div class="text-2xl font-bold text-amber-600"><?php echo gmdate("i:s", round($stats['avg_time'])); ?></div>
            <div class="text-xs text-gray-600">Avg Time</div>
        </div>
    </div>

    <!-- GEOGRAPHY SECTION -->
    <h2 class="text-xl font-bold mb-4 flex items-center gap-2">🌍 Geographic Distribution</h2>
    <div class="grid lg:grid-cols-3 gap-6 mb-6">
        <!-- Countries -->
        <div class="neu-card p-6">
            <h3 class="font-bold text-lg mb-4">Top Countries</h3>
            <?php if (empty($countryStats)): ?>
            <p class="text-gray-500 text-center py-4">No location data yet</p>
            <?php else: foreach ($countryStats as $country): ?>
            <div class="flex items-center justify-between mb-3 p-2 bg-gray-50 dark:bg-gray-800 rounded">
                <div class="flex items-center gap-2">
                    <span class="text-2xl"><?php echo getCountryFlag($country['country']); ?></span>
                    <span class="font-medium"><?php echo $country['country']; ?></span>
                </div>
                <span class="font-bold text-blue-600"><?php echo number_format($country['visits']); ?></span>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <!-- Regions/States -->
        <div class="neu-card p-6">
            <h3 class="font-bold text-lg mb-4">Top Regions/States</h3>
            <?php if (empty($regionStats)): ?>
            <p class="text-gray-500 text-center py-4">No region data yet</p>
            <?php else: foreach ($regionStats as $region): ?>
            <div class="flex items-center justify-between mb-3 p-2 bg-gray-50 dark:bg-gray-800 rounded">
                <div>
                    <div class="font-medium"><?php echo $region['region']; ?></div>
                    <div class="text-xs text-gray-500"><?php echo $region['country']; ?></div>
                </div>
                <span class="font-bold text-green-600"><?php echo number_format($region['visits']); ?></span>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <!-- Cities -->
        <div class="neu-card p-6">
            <h3 class="font-bold text-lg mb-4">Top Cities</h3>
            <?php if (empty($cityStats)): ?>
            <p class="text-gray-500 text-center py-4">No city data yet</p>
            <?php else: foreach ($cityStats as $city): ?>
            <div class="flex items-center justify-between mb-3 p-2 bg-gray-50 dark:bg-gray-800 rounded">
                <div>
                    <div class="font-medium"><?php echo $city['city']; ?></div>
                    <div class="text-xs text-gray-500"><?php echo $city['region'] . ', ' . $city['country']; ?></div>
                </div>
                <span class="font-bold text-purple-600"><?php echo number_format($city['visits']); ?></span>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- TIMEZONE & IP SECTION -->
    <div class="grid lg:grid-cols-2 gap-6 mb-6">
        <!-- Timezones -->
        <div class="neu-card p-6">
            <h3 class="font-bold text-lg mb-4">🕐 Timezones</h3>
            <?php foreach ($timezoneStats as $tz): ?>
            <div class="flex items-center justify-between mb-2 p-2 bg-gray-50 dark:bg-gray-800 rounded">
                <span class="font-mono text-sm"><?php echo $tz['timezone']; ?></span>
                <span class="font-bold text-indigo-600"><?php echo number_format($tz['visits']); ?> visits</span>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Recent IP Addresses -->
        <div class="neu-card p-6">
            <h3 class="font-bold text-lg mb-4">🌐 Recent IP Addresses</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left border-b dark:border-gray-700">
                            <th class="pb-2">IP Address</th>
                            <th class="pb-2">Location</th>
                            <th class="pb-2 text-right">Requests</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentIPs as $ip): ?>
                        <tr class="border-b dark:border-gray-700 last:border-0">
                            <td class="py-2 font-mono text-xs"><?php echo $ip['ip_address']; ?></td>
                            <td class="py-2 text-xs">
                                <?php 
                                $loc = [];
                                if ($ip['city'] != 'Unknown') $loc[] = $ip['city'];
                                if ($ip['region'] != 'Unknown') $loc[] = $ip['region'];
                                if ($ip['country'] != 'Unknown') $loc[] = getCountryFlag($ip['country']);
                                echo implode(', ', $loc) ?: 'Unknown';
                                ?>
                            </td>
                            <td class="py-2 text-right font-bold"><?php echo $ip['requests']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- CATEGORY & DEVICE SECTION -->
    <div class="grid lg:grid-cols-2 gap-6 mb-6">
        <!-- Category Performance -->
        <div class="neu-card p-6">
            <h3 class="font-bold text-lg mb-4">📁 Category Performance</h3>
            <?php 
            $total = array_sum(array_column($categoryStats, 'visits'));
            foreach ($categoryStats as $cat): 
                $pct = $total > 0 ? ($cat['visits']/$total)*100 : 0;
                $icons = ['blog'=>'📝','games'=>'🎮','social'=>'👥','home'=>'🏠','admin'=>'⚙️','downloads'=>'📥','finance'=>'💰','auth'=>'🔐','other'=>'📄'];
            ?>
            <div class="mb-3">
                <div class="flex justify-between text-sm mb-1">
                    <span><?php echo ($icons[$cat['category']]??'📄').' '.ucfirst($cat['category']); ?></span>
                    <span class="font-bold"><?php echo number_format($cat['visits']); ?> (<?php echo round($pct,1); ?>%)</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2">
                    <div class="bg-indigo-500 h-2 rounded-full" style="width:<?php echo $pct; ?>%"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Device Breakdown -->
        <div class="neu-card p-6">
            <h3 class="font-bold text-lg mb-4">📱 Devices</h3>
            <?php 
            $totalDev = array_sum($deviceStats);
            $devIcons = ['desktop'=>'💻','mobile'=>'📱','tablet'=>'📲'];
            foreach ($deviceStats as $dev=>$count): 
                $pct = $totalDev>0?($count/$totalDev)*100:0;
            ?>
            <div class="flex items-center gap-3 mb-3">
                <span class="text-2xl"><?php echo $devIcons[$dev]??'📱'; ?></span>
                <div class="flex-1">
                    <div class="flex justify-between text-sm">
                        <span class="capitalize"><?php echo $dev; ?></span>
                        <span class="font-bold"><?php echo round($pct,1); ?>%</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div class="bg-green-500 h-2 rounded-full" style="width:<?php echo $pct; ?>%"></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- TOP PAGES & HOURLY -->
    <div class="grid lg:grid-cols-2 gap-6">
        <!-- Top Pages -->
        <div class="neu-card p-6">
            <h3 class="font-bold text-lg mb-4">🔥 Top Pages</h3>
            <div class="space-y-3 max-h-80 overflow-y-auto">
                <?php foreach ($topPages as $i=>$page): 
                    $name = strlen($page['page_url'])>35?substr($page['page_url'],0,35).'...':$page['page_url'];
                ?>
                <div class="p-2 bg-gray-50 dark:bg-gray-800 rounded">
                    <div class="flex justify-between text-sm mb-1">
                        <span><?php echo ($i+1).'. '.htmlspecialchars($name); ?></span>
                        <span class="font-bold text-blue-600"><?php echo $page['views']; ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Hourly Traffic -->
        <div class="neu-card p-6">
            <h3 class="font-bold text-lg mb-4">⏰ Hourly Traffic</h3>
            <div class="grid grid-cols-6 gap-1 text-center text-xs">
                <?php foreach ($hourlyStats as $h=>$count): 
                    $isNow = ($h == date('H') && $period=='today');
                ?>
                <div class="p-2 rounded <?php echo $count>0?'bg-indigo-100 dark:bg-indigo-900':'bg-gray-50 dark:bg-gray-800'; ?> <?php echo $isNow?'ring-2 ring-indigo-500':''; ?>">
                    <div class="text-gray-500"><?php echo sprintf('%02d',$h); ?></div>
                    <div class="text-lg font-bold <?php echo $count>0?'text-indigo-600':'text-gray-300'; ?>"><?php echo $count; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
