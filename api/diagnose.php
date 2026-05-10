<?php
require_once '../includes/functions.php';
header('Content-Type: text/plain');

echo "=== SERVER UPLOAD DIAGNOSTICS ===\n\n";
echo "upload_max_filesize: " . ini_get('upload_max_filesize') . "\n";
echo "post_max_size: " . ini_get('post_max_size') . "\n";
echo "max_file_uploads: " . ini_get('max_file_uploads') . "\n";
echo "memory_limit: " . ini_get('memory_limit') . "\n";
echo "max_execution_time: " . ini_get('max_execution_time') . "\n\n";

echo "PHP Version: " . PHP_VERSION . "\n";
echo "Server Software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "\n\n";

// Check if Imagick exists
echo "Imagick Available: " . (extension_loaded('imagick') ? 'YES' : 'NO') . "\n";
echo "GD Available: " . (extension_loaded('gd') ? 'YES' : 'NO') . "\n";
echo "FFmpeg Available: " . (shell_exec('which ffmpeg') ? 'YES' : 'NO') . "\n\n";

// Check upload directories
$uploadDirs = [
    __DIR__ . '/../uploads/',
    __DIR__ . '/../uploads/temp/',
    __DIR__ . '/../uploads/blogs/',
];

foreach ($uploadDirs as $dir) {
    echo "Directory $dir: " . (is_dir($dir) ? 'EXISTS' : 'MISSING');
    if (is_dir($dir)) {
        echo " (Writable: " . (is_writable($dir) ? 'YES' : 'NO') . ")";
    }
    echo "\n";
}
?>
