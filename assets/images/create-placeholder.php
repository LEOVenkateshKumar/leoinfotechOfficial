<?php
// Run once: https://akkuapps.in/assets/images/create-placeholder.php
header('Content-Type: text/plain');

$target = __DIR__ . '/video-placeholder.jpg';

if (file_exists($target)) {
    echo "Already exists: $target\n";
    exit;
}

if (!extension_loaded('gd')) {
    echo "GD not available. Creating empty file instead.\n";
    // Create a 1x1 transparent pixel as fallback
    $data = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    file_put_contents($target, $data);
    exit;
}

// Create video placeholder with GD
$width = 640;
$height = 360;

$img = imagecreatetruecolor($width, $height);
if (!$img) {
    echo "Failed to create image\n";
    exit;
}

// Colors
$bg = imagecolorallocate($img, 30, 30, 45);
$white = imagecolorallocate($img, 255, 255, 255);
$red = imagecolorallocate($img, 220, 53, 69);
$gray = imagecolorallocate($img, 150, 150, 150);

// Fill background
imagefill($img, 0, 0, $bg);

// Play button
$cx = $width / 2;
$cy = $height / 2;

// Circle
imagefilledellipse($img, $cx, $cy, 100, 100, $white);
imagefilledellipse($img, $cx, $cy, 90, 90, $bg);

// Triangle
$points = array($cx-25, $cy-30, $cx+35, $cy, $cx-25, $cy+30);
imagefilledpolygon($img, $points, 3, $white);

// VIDEO label
imagefilledrectangle($img, $cx-60, 40, $cx+60, 70, $red);
imagestring($img, 5, $cx-30, 50, "VIDEO", $white);

// Save
if (imagejpeg($img, $target, 90)) {
    echo "Created: $target\n";
    echo "Size: " . filesize($target) . " bytes\n";
} else {
    echo "Failed to save image\n";
}

imagedestroy($img);
?>
