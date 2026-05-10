<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/post-functions.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

try {
    if (!isLoggedIn()) throw new Exception('Login required');
    
    $userId = (int)$_SESSION['user_id'];
    $action = $_POST['action'] ?? '';
    
    if ($action === 'upload_media' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $uploadedFiles = [];
        $totalSize = 0;
        $maxTotalSize = 5 * 1024 * 1024; // 5MB for images+docs
        $maxVideoSize = 50 * 1024 * 1024; // 50MB for video only
        
        $yearMonth = date('Y-m');
        $uploadDir = __DIR__ . '/../uploads/posts/' . $yearMonth . '/';
        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
        
        // Process Images (within 5MB total)
        if (!empty($_FILES['images'])) {
            for ($i = 0; $i < count($_FILES['images']['tmp_name']); $i++) {
                if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) continue;
                
                $fileSize = $_FILES['images']['size'][$i];
                if ($totalSize + $fileSize > $maxTotalSize) {
                    $response['warnings'][] = 'Image skipped: Would exceed 5MB total';
                    continue;
                }
                
                $ext = strtolower(pathinfo($_FILES['images']['name'][$i], PATHINFO_EXTENSION));
                $hash = bin2hex(random_bytes(8));
                $baseName = 'img_' . $userId . '_' . $hash;
                $path = $uploadDir . $baseName . '.' . $ext;
                
                if (move_uploaded_file($_FILES['images']['tmp_name'][$i], $path)) {
                    // Create thumbnail
                    $thumb = $uploadDir . $baseName . '_thumb.' . $ext;
                    @compressImage($path, $thumb, 300, 75);
                    
                    $uploadedFiles[] = [
                        'type' => 'image',
                        'original' => '/uploads/posts/' . $yearMonth . '/' . $baseName . '.' . $ext,
                        'thumbnail' => '/uploads/posts/' . $yearMonth . '/' . $baseName . '_thumb.' . $ext,
                        'size' => $fileSize,
                        'name' => $_FILES['images']['name'][$i]
                    ];
                    $totalSize += $fileSize;
                }
            }
        }
        
        // Process Videos (separate 50MB limit)
        if (!empty($_FILES['videos'])) {
            for ($i = 0; $i < count($_FILES['videos']['tmp_name']); $i++) {
                if ($_FILES['videos']['error'][$i] !== UPLOAD_ERR_OK) continue;
                
                $fileSize = $_FILES['videos']['size'][$i];
                if ($fileSize > $maxVideoSize) {
                    $response['warnings'][] = 'Video too large (max 50MB)';
                    continue;
                }
                
                $ext = strtolower(pathinfo($_FILES['videos']['name'][$i], PATHINFO_EXTENSION));
                if (!in_array($ext, ['mp4', 'webm', 'mov', 'mkv'])) continue;
                
                $hash = bin2hex(random_bytes(8));
                $baseName = 'vid_' . $userId . '_' . $hash;
                $path = $uploadDir . $baseName . '.' . $ext;
                
                if (move_uploaded_file($_FILES['videos']['tmp_name'][$i], $path)) {
                    // Try thumbnail
                    $thumbPath = '/assets/images/video-placeholder.jpg';
                    $duration = null;
                    
                    if (function_exists('exec')) {
                        $ffmpeg = trim(@shell_exec('which ffmpeg 2>/dev/null') ?: '');
                        if ($ffmpeg && file_exists($ffmpeg)) {
                            $thumbFile = $uploadDir . $baseName . '_thumb.jpg';
                            @exec("ffmpeg -i " . escapeshellarg($path) . " -ss 00:00:01 -vframes 1 -y " . escapeshellarg($thumbFile) . " 2>&1", $out, $ret);
                            if ($ret === 0 && file_exists($thumbFile)) {
                                $thumbPath = '/uploads/posts/' . $yearMonth . '/' . $baseName . '_thumb.jpg';
                            }
                            
                            $dur = @shell_exec("ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($path) . " 2>/dev/null");
                            if ($dur) {
                                $s = round(floatval($dur));
                                $duration = floor($s/60) . ':' . str_pad($s%60, 2, '0', STR_PAD_LEFT);
                            }
                        }
                    }
                    
                    $uploadedFiles[] = [
                        'type' => 'video',
                        'original' => '/uploads/posts/' . $yearMonth . '/' . $baseName . '.' . $ext,
                        'thumbnail' => $thumbPath,
                        'duration' => $duration,
                        'size' => $fileSize,
                        'name' => $_FILES['videos']['name'][$i]
                    ];
                }
            }
        }
        
        // Process Documents (within 5MB total)
        if (!empty($_FILES['documents'])) {
            for ($i = 0; $i < count($_FILES['documents']['tmp_name']); $i++) {
                if ($_FILES['documents']['error'][$i] !== UPLOAD_ERR_OK) continue;
                
                $fileSize = $_FILES['documents']['size'][$i];
                if ($totalSize + $fileSize > $maxTotalSize) continue;
                
                $ext = strtolower(pathinfo($_FILES['documents']['name'][$i], PATHINFO_EXTENSION));
                if (!in_array($ext, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', 'txt'])) continue;
                
                $hash = bin2hex(random_bytes(8));
                $baseName = 'doc_' . $userId . '_' . $hash;
                $path = $uploadDir . $baseName . '.' . $ext;
                
                if (move_uploaded_file($_FILES['documents']['tmp_name'][$i], $path)) {
                    $icon = ['pdf'=>'📕','doc'=>'📝','docx'=>'📝','xls'=>'📊','xlsx'=>'📊','ppt'=>'📽️','pptx'=>'📽️','zip'=>'📦','rar'=>'📦','txt'=>'📄'][$ext] ?? '📄';
                    
                    $uploadedFiles[] = [
                        'type' => 'document',
                        'original' => '/uploads/posts/' . $yearMonth . '/' . $baseName . '.' . $ext,
                        'thumbnail' => '/assets/images/document-icon.png',
                        'icon' => $icon,
                        'ext' => $ext,
                        'size' => $fileSize,
                        'name' => $_FILES['documents']['name'][$i]
                    ];
                    $totalSize += $fileSize;
                }
            }
        }
        
        if (count($uploadedFiles) > 0) {
            $response = ['success' => true, 'files' => $uploadedFiles, 'message' => 'Upload successful'];
        } else {
            throw new Exception('No files uploaded');
        }
    }
    
    // ... other actions (like, comment, delete) remain same ...
    elseif ($action === 'like') {
        $postId = intval($_POST['post_id'] ?? 0);
        if (!$postId) throw new Exception('Invalid post');
        
        $db = getDB();
        $check = $db->prepare("SELECT id FROM user_interactions WHERE user_id=? AND post_id=? AND type='like'");
        $check->execute([$userId, $postId]);
        
        if ($check->fetch()) {
            $db->prepare("DELETE FROM user_interactions WHERE user_id=? AND post_id=? AND type='like'")->execute([$userId, $postId]);
            $db->prepare("UPDATE user_posts SET likes_count=GREATEST(likes_count-1,0) WHERE id=?")->execute([$postId]);
            $newCount = $db->query("SELECT likes_count FROM user_posts WHERE id=$postId")->fetchColumn();
            $response = ['success'=>true, 'action'=>'unliked', 'new_count'=>$newCount];
        } else {
            $db->prepare("INSERT INTO user_interactions (post_id,user_id,type,created_at) VALUES (?,?,?,NOW())")->execute([$postId,$userId,'like']);
            $db->prepare("UPDATE user_posts SET likes_count=likes_count+1 WHERE id=?")->execute([$postId]);
            $newCount = $db->query("SELECT likes_count FROM user_posts WHERE id=$postId")->fetchColumn();
            $response = ['success'=>true, 'action'=>'liked', 'new_count'=>$newCount];
        }
    }
    elseif ($action === 'comment') {
        $postId = intval($_POST['post_id'] ?? 0);
        $content = trim($_POST['content'] ?? '');
        if (!$postId) throw new Exception('Invalid post');
        if (empty($content)) throw new Exception('Empty comment');
        
        $db = getDB();
        try {
            $db->prepare("INSERT INTO user_post_comments (post_id,user_id,content,status,created_at) VALUES (?,?,?,'active',NOW())")->execute([$postId,$userId,$content]);
        } catch (PDOException $e) {
            $db->prepare("INSERT INTO user_interactions (post_id,user_id,type,content,created_at) VALUES (?,?,?,?,NOW())")->execute([$postId,$userId,'comment',$content]);
        }
        $db->prepare("UPDATE user_posts SET comments_count=comments_count+1 WHERE id=?")->execute([$postId]);
        $response = ['success'=>true, 'message'=>'Posted'];
    }
    elseif ($action === 'get_comments') {
        $postId = intval($_GET['post_id'] ?? 0);
        $db = getDB();
        try {
            $stmt = $db->prepare("SELECT c.content,c.created_at,u.name as author_name FROM user_post_comments c JOIN users u ON c.user_id=u.id WHERE c.post_id=? AND c.status='active' ORDER BY c.created_at DESC");
            $stmt->execute([$postId]);
        } catch (PDOException $e) {
            $stmt = $db->prepare("SELECT i.content,i.created_at,u.name as author_name FROM user_interactions i JOIN users u ON i.user_id=u.id WHERE i.post_id=? AND i.type='comment' ORDER BY i.created_at DESC");
            $stmt->execute([$postId]);
        }
        $response = ['success'=>true, 'comments'=>$stmt->fetchAll()];
    }
    elseif ($action === 'delete') {
        $postId = intval($_POST['post_id'] ?? 0);
        if (!$postId) throw new Exception('Invalid post');
        $db = getDB();
        $check = $db->prepare("SELECT id FROM user_posts WHERE id = ? AND user_id = ?");
        $check->execute([$postId, $userId]);
        if (!$check->fetch()) throw new Exception('Unauthorized');
        $db->prepare("UPDATE user_posts SET status = 'deleted' WHERE id = ?")->execute([$postId]);
        $response = ['success'=>true, 'message'=>'Post deleted'];
    }
    else {
        throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    error_log("API Error: " . $e->getMessage());
}

echo json_encode($response);
