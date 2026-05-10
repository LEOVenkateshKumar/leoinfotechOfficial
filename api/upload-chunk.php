<?php
ini_set('memory_limit', '512M');
ini_set('max_execution_time', '120');
ini_set('display_errors', '0');

require_once '../includes/functions.php';

header('Content-Type: application/json');
$response = ['success' => false, 'message' => ''];

try {
    if (!isLoggedIn()) throw new Exception('Login required');
    if (!validateCSRF($_POST['csrf_token'] ?? '')) throw new Exception('Invalid token');

    $userId = (int)$_SESSION['user_id'];
    $fileId = preg_replace('/[^a-z0-9_-]/', '', $_POST['file_id'] ?? '');
    $chunkIndex = (int)($_POST['chunk_index'] ?? -1);
    $totalChunks = (int)($_POST['total_chunks'] ?? 0);
    $fileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($_POST['file_name'] ?? 'unknown'));
    $category = $_POST['category'] ?? 'image';

    if (!$fileId || $chunkIndex < 0 || $totalChunks < 1) {
        throw new Exception('Invalid chunk data');
    }

    // Setup directories
    $uploadDir = __DIR__ . '/../uploads/temp/' . $userId . '/';
    $chunkDir = $uploadDir . 'chunks/';
    
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    if (!is_dir($chunkDir)) mkdir($chunkDir, 0755, true);

    // Save chunk
    $chunkFile = $chunkDir . $fileId . '.part' . $chunkIndex;

    if (!empty($_FILES['chunk']['tmp_name']) && is_uploaded_file($_FILES['chunk']['tmp_name'])) {
        if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $chunkFile)) {
            throw new Exception('Failed to save chunk');
        }
    } elseif (!empty($_POST['chunk'])) {
        $data = base64_decode($_POST['chunk']);
        if ($data === false || file_put_contents($chunkFile, $data) === false) {
            throw new Exception('Failed to write chunk');
        }
    } else {
        throw new Exception('No chunk data');
    }

    // Check if all chunks uploaded
    $uploadedChunks = glob($chunkDir . $fileId . '.part*');
    if (count($uploadedChunks) < $totalChunks) {
        $response = [
            'success' => true,
            'chunk_uploaded' => true,
            'progress' => round(count($uploadedChunks) / $totalChunks * 100)
        ];
        echo json_encode($response);
        exit;
    }

    // Reassemble file
    $finalName = $fileId . '_' . $fileName;
    $finalPath = $uploadDir . $finalName;
    $out = fopen($finalPath, 'wb');
    
    if (!$out) throw new Exception('Cannot create final file');

    for ($i = 0; $i < $totalChunks; $i++) {
        $partFile = $chunkDir . $fileId . '.part' . $i;
        if (!file_exists($partFile)) {
            fclose($out);
            unlink($finalPath);
            throw new Exception("Missing chunk $i");
        }
        fwrite($out, file_get_contents($partFile));
        unlink($partFile);
    }
    fclose($out);

    if (filesize($finalPath) == 0) {
        unlink($finalPath);
        throw new Exception('Combined file is empty');
    }

    // Process file
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $type = $category;
    $thumbRelPath = null;
    $thumbName = 'thumb_' . $fileId . '.jpg';
    $thumbPath = $uploadDir . $thumbName;
    
    if ($type === 'image' && extension_loaded('gd')) {
        if (createImageThumbnail($finalPath, $thumbPath, 800)) {
            $thumbRelPath = '/uploads/temp/' . $userId . '/' . $thumbName;
        }
    } 
    elseif ($type === 'video') {
        // ALWAYS create a video thumbnail
        if (extension_loaded('gd')) {
            createVideoThumbnail($thumbPath, $fileName);
        }
        
        // Check if thumbnail was created
        if (file_exists($thumbPath) && filesize($thumbPath) > 0) {
            $thumbRelPath = '/uploads/temp/' . $userId . '/' . $thumbName;
        } else {
            $thumbRelPath = '/assets/images/video-placeholder.jpg';
        }
    }

    // Build response
    $fileInfo = [
        'original' => '/uploads/temp/' . $userId . '/' . $finalName,
        'type' => $type,
        'ext' => $ext,
        'name' => $fileName,
        'size' => filesize($finalPath),
        'thumbnail' => $thumbRelPath ?? '/assets/images/default.png'
    ];
    
    if ($type === 'video') {
        $fileInfo['duration'] = getVideoDuration($finalPath);
    }
    
    if ($type === 'document') {
        $icons = [
            'pdf' => '📕', 'doc' => '📝', 'docx' => '📝',
            'xls' => '📊', 'xlsx' => '📊', 'ppt' => '📊',
            'zip' => '📦', 'rar' => '📦', 'txt' => '📄'
        ];
        $fileInfo['icon'] = $icons[$ext] ?? '📄';
    }

    // Cleanup
    @rmdir($chunkDir);
    
    $response['success'] = true;
    $response['files'] = [$fileInfo];

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    error_log("Upload error: " . $e->getMessage());
}

echo json_encode($response);

// ============== HELPER FUNCTIONS ==============

function createImageThumbnail($src, $dst, $maxWidth) {
    if (!extension_loaded('gd')) return false;
    
    $info = @getimagesize($src);
    if (!$info) return false;
    
    list($w, $h, $type) = $info;
    if ($w <= 0 || $h <= 0) return false;
    
    if ($w > $maxWidth) {
        $newW = $maxWidth;
        $newH = intval(($h * $maxWidth) / $w);
    } else {
        $newW = $w;
        $newH = $h;
    }
    
    $srcImg = null;
    switch ($type) {
        case IMAGETYPE_JPEG: $srcImg = @imagecreatefromjpeg($src); break;
        case IMAGETYPE_PNG: $srcImg = @imagecreatefrompng($src); break;
        case IMAGETYPE_GIF: $srcImg = @imagecreatefromgif($src); break;
        case IMAGETYPE_WEBP: $srcImg = @imagecreatefromwebp($src); break;
    }
    
    if (!$srcImg) return false;
    
    $dstImg = imagecreatetruecolor($newW, $newH);
    
    if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
        imagealphablending($dstImg, false);
        imagesavealpha($dstImg, true);
        $transparent = imagecolorallocatealpha($dstImg, 255, 255, 255, 127);
        imagefilledrectangle($dstImg, 0, 0, $newW, $newH, $transparent);
    }
    
    imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $newW, $newH, $w, $h);
    $result = imagejpeg($dstImg, $dst, 85);
    
    imagedestroy($srcImg);
    imagedestroy($dstImg);
    
    return $result && file_exists($dst) && filesize($dst) > 0;
}

function createVideoThumbnail($thumbPath, $originalFilename) {
    if (!extension_loaded('gd')) return false;
    
    $width = 640;
    $height = 360;
    
    $img = @imagecreatetruecolor($width, $height);
    if (!$img) return false;
    
    // Colors
    $darkBg = imagecolorallocate($img, 30, 30, 45);
    $lightBg = imagecolorallocate($img, 50, 50, 70);
    $white = imagecolorallocate($img, 255, 255, 255);
    $red = imagecolorallocate($img, 220, 53, 69);
    $gray = imagecolorallocate($img, 200, 200, 200);
    
    // Fill background
    imagefill($img, 0, 0, $darkBg);
    
    // Film strip pattern
    for ($i = 0; $i < 5; $i++) {
        $x = $i * 80;
        imagefilledrectangle($img, $x, 10, $x + 30, 25, $lightBg);
        imagefilledrectangle($img, $x, $height - 25, $x + 30, $height - 10, $lightBg);
    }
    
    // Play button
    $centerX = $width / 2;
    $centerY = $height / 2;
    
    imagefilledellipse($img, $centerX, $centerY, 80, 80, $white);
    imagefilledellipse($img, $centerX, $centerY, 70, 70, $darkBg);
    
    $triPoints = [
        $centerX - 18, $centerY - 22,
        $centerX + 22, $centerY,
        $centerX - 18, $centerY + 22
    ];
    imagefilledpolygon($img, $triPoints, 3, $white);
    
    // Video badge
    imagefilledrectangle($img, $width/2 - 60, 20, $width/2 + 60, 50, $red);
    $text = "VIDEO";
    $font = 5;
    $textWidth = strlen($text) * imagefontwidth($font);
    imagestring($img, $font, ($width - $textWidth)/2, 30, $text, $white);
    
    // Filename
    $displayName = $originalFilename;
    if (strlen($displayName) > 35) {
        $displayName = substr($displayName, 0, 32) . '...';
    }
    $fileWidth = strlen($displayName) * imagefontwidth(2);
    imagestring($img, 2, ($width - $fileWidth)/2, $height - 40, $displayName, $gray);
    
    // Extension badge
    $ext = strtoupper(pathinfo($originalFilename, PATHINFO_EXTENSION));
    imagefilledrectangle($img, $width - 70, $height - 35, $width - 10, $height - 10, $red);
    imagestring($img, 3, $width - 60, $height - 28, $ext, $white);
    
    $result = imagejpeg($img, $thumbPath, 90);
    imagedestroy($img);
    
    return $result && file_exists($thumbPath) && filesize($thumbPath) > 0;
}

function getVideoDuration($videoPath) {
    // Return a placeholder duration based on file size
    $size = filesize($videoPath);
    $seconds = $size / (1024 * 1024 * 1.5); // Rough estimate
    
    if ($seconds < 60) return '0:' . str_pad(round($seconds), 2, '0', STR_PAD_LEFT);
    if ($seconds < 3600) {
        $mins = floor($seconds / 60);
        $secs = round($seconds % 60);
        return $mins . ':' . str_pad($secs, 2, '0', STR_PAD_LEFT);
    }
    
    return '10:00';
}
?>