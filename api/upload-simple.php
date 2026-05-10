<?php
session_start();
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Check login FIRST
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please login first']);
    exit;
}

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Session expired. Please login again.']);
    exit;
}

// Check CSRF
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page.']);
    exit;
}

$userId = $_SESSION['user_id'];
$type = $_POST['type'] ?? 'image';
$uploadDir = __DIR__ . '/../uploads/temp/' . $userId . '/';

if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$uploadedFiles = [];

// Handle image upload
if ($type === 'image' && !empty($_FILES['file'])) {
    $file = $_FILES['file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid image format']);
        exit;
    }
    
    $fileId = time() . '_' . rand(1000, 9999);
    $fileName = $fileId . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
    $destPath = $uploadDir . $fileName;
    
    if (move_uploaded_file($file['tmp_name'], $destPath)) {
        $thumbPath = $uploadDir . 'thumb_' . $fileId . '.jpg';
        if (extension_loaded('gd')) {
            createImageThumb($destPath, $thumbPath, 400);
        }
        
        $uploadedFiles[] = [
            'original' => '/uploads/temp/' . $userId . '/' . $fileName,
            'type' => 'image',
            'ext' => $ext,
            'name' => $file['name'],
            'thumbnail' => file_exists($thumbPath) ? '/uploads/temp/' . $userId . '/' . 'thumb_' . $fileId . '.jpg' : '/assets/images/default.png'
        ];
    }
}

// Handle video upload
if ($type === 'video' && !empty($_FILES['file'])) {
    $file = $_FILES['file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($ext, ['mp4','mov','avi','mkv','webm'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid video format']);
        exit;
    }
    
    $fileId = time() . '_' . rand(1000, 9999);
    $fileName = $fileId . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
    $destPath = $uploadDir . $fileName;
    
    if (move_uploaded_file($file['tmp_name'], $destPath)) {
        $thumbPath = $uploadDir . 'thumb_' . $fileId . '.jpg';
        if (extension_loaded('gd')) {
            createVideoThumb($thumbPath, $file['name']);
        }
        
        $uploadedFiles[] = [
            'original' => '/uploads/temp/' . $userId . '/' . $fileName,
            'type' => 'video',
            'ext' => $ext,
            'name' => $file['name'],
            'thumbnail' => file_exists($thumbPath) ? '/uploads/temp/' . $userId . '/' . 'thumb_' . $fileId . '.jpg' : '/assets/images/video-placeholder.jpg',
            'duration' => '0:30'
        ];
    }
}

// Handle document upload
if ($type === 'document' && !empty($_FILES['file'])) {
    $file = $_FILES['file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $icons = ['pdf' => '📕', 'doc' => '📝', 'docx' => '📝', 'xls' => '📊', 'xlsx' => '📊', 'txt' => '📄', 'zip' => '📦'];
    
    if (!in_array($ext, ['pdf','doc','docx','xls','xlsx','txt','zip','rar'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid document format']);
        exit;
    }
    
    $fileId = time() . '_' . rand(1000, 9999);
    $fileName = $fileId . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
    $destPath = $uploadDir . $fileName;
    
    if (move_uploaded_file($file['tmp_name'], $destPath)) {
        $uploadedFiles[] = [
            'original' => '/uploads/temp/' . $userId . '/' . $fileName,
            'type' => 'document',
            'ext' => $ext,
            'name' => $file['name'],
            'icon' => $icons[$ext] ?? '📄',
            'thumbnail' => '/assets/images/default.png'
        ];
    }
}

if (empty($uploadedFiles)) {
    echo json_encode(['success' => false, 'message' => 'Upload failed']);
    exit;
}

echo json_encode(['success' => true, 'files' => $uploadedFiles]);
exit;

// Helper functions
function createImageThumb($src, $dst, $maxWidth) {
    if (!extension_loaded('gd')) return false;
    $info = @getimagesize($src);
    if (!$info) return false;
    list($w, $h) = $info;
    if ($w > $maxWidth) {
        $newW = $maxWidth;
        $newH = intval(($h * $maxWidth) / $w);
    } else {
        $newW = $w;
        $newH = $h;
    }
    $srcImg = @imagecreatefromjpeg($src);
    if (!$srcImg) $srcImg = @imagecreatefrompng($src);
    if (!$srcImg) return false;
    $dstImg = imagecreatetruecolor($newW, $newH);
    imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $newW, $newH, $w, $h);
    imagejpeg($dstImg, $dst, 85);
    imagedestroy($srcImg);
    imagedestroy($dstImg);
    return true;
}

function createVideoThumb($thumbPath, $filename) {
    if (!extension_loaded('gd')) return false;
    $img = imagecreatetruecolor(400, 225);
    $dark = imagecolorallocate($img, 20, 20, 35);
    $gray = imagecolorallocate($img, 45, 45, 65);
    $white = imagecolorallocate($img, 255, 255, 255);
    $red = imagecolorallocate($img, 200, 50, 60);
    imagefill($img, 0, 0, $dark);
    for ($i = 0; $i < 6; $i++) {
        $x = $i * 70;
        imagefilledrectangle($img, $x, 5, $x + 25, 18, $gray);
        imagefilledrectangle($img, $x, 207, $x + 25, 220, $gray);
    }
    $cx = 200; $cy = 112;
    imagefilledellipse($img, $cx, $cy, 65, 65, $white);
    imagefilledellipse($img, $cx, $cy, 55, 55, $dark);
    $points = [$cx - 12, $cy - 15, $cx + 18, $cy, $cx - 12, $cy + 15];
    imagefilledpolygon($img, $points, 3, $white);
    imagefilledrectangle($img, 163, 12, 237, 38, $red);
    imagestring($img, 5, 183, 17, "VIDEO", $white);
    $ext = strtoupper(pathinfo($filename, PATHINFO_EXTENSION));
    imagefilledrectangle($img, 345, 197, 392, 220, $red);
    imagestring($img, 3, 352, 203, $ext, $white);
    imagejpeg($img, $thumbPath, 85);
    imagedestroy($img);
    return true;
}
?>