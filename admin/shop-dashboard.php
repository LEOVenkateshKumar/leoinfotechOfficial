<?php
// Add this inside your existing dashboard stats array or create a new section
$shopStats = [
    'today_sales' => $db->query("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total FROM shop_orders WHERE DATE(created_at) = CURDATE()")->fetch(),
    'low_stock' => $db->query("SELECT COUNT(*) FROM shop_products WHERE stock_qty <= low_stock_alert AND status = 'active'")->fetchColumn(),
    'pending_orders' => $db->query("SELECT COUNT(*) FROM shop_orders WHERE status = 'pending'")->fetchColumn(),
    'pending_services' => $db->query("SELECT COUNT(*) FROM shop_service_bookings WHERE status = 'pending'")->fetchColumn()
];
?>

<!-- HTML for the Widgets (Place in your dashboard grid) -->
<div class="neu-card p-4 border-l-4 border-blue-500">
    <div class="text-2xl font-bold text-blue-600">₹<?php echo number_format($shopStats['today_sales']['total']); ?></div>
    <div class="text-xs text-gray-600">Today's Sales</div>
</div>

<div class="neu-card p-4 border-l-4 border-red-500">
    <div class="text-2xl font-bold text-red-600"><?php echo $shopStats['low_stock']; ?></div>
    <div class="text-text-xs text-gray-600">Low Stock Alerts</div>
</div>
