<?php
require_once '../includes/functions.php';
requireLogin();

$userId = $_SESSION['user_id'];
$format = $_GET['format'] ?? 'csv';

$db = getDB();
$stmt = $db->prepare("SELECT name, email, phone FROM user_contacts WHERE user_id = ? ORDER BY name");
$stmt->execute([$userId]);
$contacts = $stmt->fetchAll();

if ($format === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="akkucontacts_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Name', 'Email', 'Phone']);
    foreach ($contacts as $c) {
        fputcsv($output, [$c['name'], $c['email'], $c['phone']]);
    }
    fclose($output);
    
} elseif ($format === 'vcf') {
    header('Content-Type: text/vcard');
    header('Content-Disposition: attachment; filename="akkucontacts_' . date('Y-m-d') . '.vcf"');
    
    foreach ($contacts as $c) {
        echo "BEGIN:VCARD\n";
        echo "VERSION:3.0\n";
        echo "FN:" . $c['name'] . "\n";
        if ($c['email']) echo "EMAIL:" . $c['email'] . "\n";
        if ($c['phone']) echo "TEL:" . $c['phone'] . "\n";
        echo "END:VCARD\n";
    }
}
