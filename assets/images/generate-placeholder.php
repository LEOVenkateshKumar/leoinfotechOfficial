<?php
// Run this once by visiting: /assets/images/generate-placeholder.php
$targetFile = __DIR__ . '/video-placeholder.jpg';

if (file_exists($targetFile)) {
    echo "Placeholder already exists!";
    exit;
}

$width = 640;
$height = 360;

$img = imagecreatetruecolor($width, $height);

// Colors
$bg = imagecolorallocate($img, 30, 30, 45);
$white = imagecolorallocate($img, 255, 255, 255);
$red = imagecolorallocate($img, 220, 53, 69);

// Fill background
imagefill($img, 0, 0, $bg);

// Play button
$centerX = $width / 2;
$centerY = $height / 2;

// Circle
imagefilledellipse($img, $centerX, $centerY, 120, 120, imagecolorallocate($img, 255, 255, 255));
imagefilledellipse($img, $centerX, $centerY, 100, 100, $bg);

// Triangle
$points = [
    $centerX - 25, $centerY - 30,
    $centerX + 35, $centerY,
    $centerX - 25, $centerY + 30
];
imagefilledpolygon($img, $points, 3, $white);

// VIDEO text
$font = 5;
$text = "VIDEO";
$x = ($width - (strlen($text) * imagefontwidth($font))) / 2;
imagestring($img, $font, $x, $height - 80, $text, $white);

// Save
imagejpeg($img, $targetFile, 90);
imagedestroy($img);

echo "Placeholder created at: " . $targetFile;
?>
