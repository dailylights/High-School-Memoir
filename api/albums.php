<?php
require 'db.php';

function getCurrentUserId() {
    return isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
}

function getRequestParam($key, $default = null) {
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

function uploadPhoto($file, $albumId, $userId) {
    global $conn;
    
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => '文件上传失败'];
    }
    
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $fileType = $file['type'];
    
    if (!in_array($fileType, $allowedTypes)) {
        return ['success' => false, 'message' => '不支持的图片格式'];
    }
    
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions)) {
        return ['success' => false, 'message' => '不支持的图片扩展名'];
    }
    
    $imageInfo = @getimagesize($file['tmp_name']);
    if (!$imageInfo || !in_array($imageInfo['mime'], $allowedTypes)) {
        return ['success' => false, 'message' => '不是有效的图片文件'];
    }
    
    $maxSize = 10 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'message' => '图片大小不能超过10MB'];
    }
    
    $albumId = intval($albumId);
    if ($albumId <= 0) {
        return ['success' => false, 'message' => '无效的相册ID'];
    }
    
    $targetDir = "../uploads/albums/{$albumId}/";
    if (!file_exists($targetDir)) {
        mkdir($targetDir, 0755, true);
    }
    
    $newFilename = "photo_" . $userId . "_" . time() . "_" . bin2hex(random_bytes(8)) . "." . $extension;
    $targetFile = $targetDir . $newFilename;
    
    if (move_uploaded_file($file['tmp_name'], $targetFile)) {
        @chmod($targetFile, 0644);
        
        $dbPath = "uploads/albums/{$albumId}/{$newFilename}";
        return ['success' => true, 'path' => $dbPath];
    }
    
    return ['success' => false, 'message' => '文件保存失败'];
}

function createAlbum($conn, $userId, $name, $description) {
    if (empty($name)) {
        return ['success' => false, 'message' => '相册名称不能为空'];
    }
    
    if (strlen($name) > 200) {
        return ['success' => false, 'message' => '相册名称过长'];
    }
    
    $stmt = $conn->prepare("INSERT INTO albums (user_id, name, description) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $userId, $name, $description);
    
    if ($stmt->execute()) {
        return ['success' => true, 'album_id' => $conn->insert_id];
    }
    
    return ['success' => false, 'message' => '创建相册失败'];
}

function updateAlbum($conn, $albumId, $userId, $name, $description) {
    $checkStmt = $conn->prepare("SELECT user_id FROM albums WHERE id = ?");
    $checkStmt->bind_param("i", $albumId);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows === 0) {
        return ['success' => false, 'message' => '相册不存在'];
    }
    
    $album = $result->fetch_assoc();
    if ($album['user_id'] != $userId) {
        return ['success' => false, 'message' => '无权修改此相册'];
    }
    
    $stmt = $conn->prepare("UPDATE albums SET name = ?, description = ? WHERE id = ?");
    $stmt->bind_param("ssi", $name, $description, $albumId);
    
    if ($stmt->execute()) {
        return ['success' => true];
    }
    
    return ['success' => false, 'message' => '更新失败'];
}

function deleteAlbum($conn, $albumId, $userId) {
    $checkStmt = $conn->prepare("SELECT user_id FROM albums WHERE id = ?");
    $checkStmt->bind_param("i", $albumId);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows === 0) {
        return ['success' => false, 'message' => '相册不存在'];
    }
    
    $album = $result->fetch_assoc();
    if ($album['user_id'] != $userId) {
        return ['success' => false, 'message' => '无权删除此相册'];
    }
    
    $photoDir = "../uploads/albums/{$albumId}/";
    if (file_exists($photoDir)) {
        array_map('unlink', glob($photoDir . "*.*"));
        rmdir($photoDir);
    }
    
    $stmt = $conn->prepare("DELETE FROM albums WHERE id = ?");
    $stmt->bind_param("i", $albumId);
    
    if ($stmt->execute()) {
        return ['success' => true];
    }
    
    return ['success' => false, 'message' => '删除失败'];
}

function getAlbumList($conn, $userId, $page = 1, $limit = 12) {
    $offset = ($page - 1) * $limit;
    
    $stmt = $conn->prepare("
        SELECT 
            a.*,
            u.name as author_name,
            u.avatar as author_avatar,
            u.class as author_class,
            (SELECT COUNT(*) FROM photos WHERE album_id = a.id) as photo_count,
            (SELECT image_path FROM photos WHERE album_id = a.id ORDER BY created_at DESC LIMIT 1) as cover_image,
            (SELECT COUNT(*) FROM album_likes WHERE album_id = a.id) as like_count,
            CASE WHEN al.id IS NOT NULL THEN 1 ELSE 0 END as is_liked
        FROM albums a
        JOIN users u ON u.id = a.user_id
        LEFT JOIN album_likes al ON al.album_id = a.id AND al.user_id = ?
        GROUP BY a.id
        ORDER BY a.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param("iii", $userId, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $albums = [];
    while ($row = $result->fetch_assoc()) {
        $albums[] = $row;
    }
    
    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM albums");
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['total'];
    
    return [
        'success' => true,
        'albums' => $albums,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => ceil($total / $limit)
        ]
    ];
}

function getAlbumDetail($conn, $albumId, $userId) {
    $stmt = $conn->prepare("
        SELECT 
            a.*,
            u.name as author_name,
            u.avatar as author_avatar,
            u.class as author_class,
            (SELECT COUNT(*) FROM photos WHERE album_id = a.id) as photo_count,
            (SELECT COUNT(*) FROM album_likes WHERE album_id = a.id) as like_count,
            CASE WHEN al.id IS NOT NULL THEN 1 ELSE 0 END as is_liked
        FROM albums a
        JOIN users u ON u.id = a.user_id
        LEFT JOIN album_likes al ON al.album_id = a.id AND al.user_id = ?
        WHERE a.id = ?
    ");
    $stmt->bind_param("ii", $userId, $albumId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return ['success' => false, 'message' => '相册不存在'];
    }
    
    return ['success' => true, 'album' => $result->fetch_assoc()];
}

function getPhotos($conn, $albumId, $userId, $page = 1, $limit = 20) {
    $offset = ($page - 1) * $limit;
    
    $stmt = $conn->prepare("
        SELECT 
            p.*,
            u.name as author_name,
            u.avatar as author_avatar,
            (SELECT COUNT(*) FROM photo_likes WHERE photo_id = p.id) as like_count,
            CASE WHEN pl.id IS NOT NULL THEN 1 ELSE 0 END as is_liked
        FROM photos p
        JOIN users u ON u.id = p.user_id
        LEFT JOIN photo_likes pl ON pl.photo_id = p.id AND pl.user_id = ?
        WHERE p.album_id = ?
        ORDER BY COALESCE(p.taken_at, p.created_at) DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param("iiii", $userId, $albumId, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $photos = [];
    while ($row = $result->fetch_assoc()) {
        $photos[] = $row;
    }
    
    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM photos WHERE album_id = ?");
    $countStmt->bind_param("i", $albumId);
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['total'];
    
    return [
        'success' => true,
        'photos' => $photos,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => ceil($total / $limit)
        ]
    ];
}

function getTimelinePhotos($conn, $userId, $page = 1, $limit = 30) {
    $offset = ($page - 1) * $limit;
    
    $stmt = $conn->prepare("
        SELECT 
            p.*,
            a.name as album_name,
            a.id as album_id,
            u.name as author_name,
            u.avatar as author_avatar,
            u.class as author_class,
            (SELECT COUNT(*) FROM photo_likes WHERE photo_id = p.id) as like_count,
            CASE WHEN pl.id IS NOT NULL THEN 1 ELSE 0 END as is_liked
        FROM photos p
        JOIN albums a ON a.id = p.album_id
        JOIN users u ON u.id = p.user_id
        LEFT JOIN photo_likes pl ON pl.photo_id = p.id AND pl.user_id = ?
        ORDER BY COALESCE(p.taken_at, p.created_at) DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param("iii", $userId, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $photos = [];
    while ($row = $result->fetch_assoc()) {
        $photos[] = $row;
    }
    
    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM photos");
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['total'];
    
    return [
        'success' => true,
        'photos' => $photos,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => ceil($total / $limit)
        ]
    ];
}

function uploadPhotos($conn, $albumId, $userId, $files, $titles = [], $descriptions = [], $takenDates = []) {
    $checkStmt = $conn->prepare("SELECT user_id FROM albums WHERE id = ?");
    $checkStmt->bind_param("i", $albumId);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows === 0) {
        return ['success' => false, 'message' => '相册不存在'];
    }
    
    $album = $result->fetch_assoc();
    if ($album['user_id'] != $userId) {
        return ['success' => false, 'message' => '无权上传到此相册'];
    }
    
    $uploaded = [];
    $failed = [];
    
    $fileCount = count($files['name']);
    
    for ($i = 0; $i < $fileCount; $i++) {
        $file = [
            'name' => $files['name'][$i],
            'type' => $files['type'][$i],
            'tmp_name' => $files['tmp_name'][$i],
            'error' => $files['error'][$i],
            'size' => $files['size'][$i]
        ];
        
        $uploadResult = uploadPhoto($file, $albumId, $userId);
        
        if ($uploadResult['success']) {
            $title = $titles[$i] ?? '';
            $description = $descriptions[$i] ?? '';
            $takenAt = $takenDates[$i] ?? null;
            
            $stmt = $conn->prepare("INSERT INTO photos (album_id, user_id, image_path, title, description, taken_at) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iissss", $albumId, $userId, $uploadResult['path'], $title, $description, $takenAt);
            
            if ($stmt->execute()) {
                $photoId = $conn->insert_id;
                $uploaded[] = ['id' => $photoId, 'path' => $uploadResult['path']];
                
                $coverCheck = $conn->prepare("SELECT cover_image FROM albums WHERE id = ?");
                $coverCheck->bind_param("i", $albumId);
                $coverCheck->execute();
                $coverResult = $coverCheck->get_result()->fetch_assoc();
                
                if (empty($coverResult['cover_image'])) {
                    $updateCover = $conn->prepare("UPDATE albums SET cover_image = ? WHERE id = ?");
                    $updateCover->bind_param("si", $uploadResult['path'], $albumId);
                    $updateCover->execute();
                }
            } else {
                $failed[] = ['name' => $file['name'], 'error' => '数据库保存失败'];
            }
        } else {
            $failed[] = ['name' => $file['name'], 'error' => $uploadResult['message']];
        }
    }
    
    return [
        'success' => true,
        'uploaded' => count($uploaded),
        'failed' => count($failed),
        'uploaded_photos' => $uploaded,
        'failed_photos' => $failed
    ];
}

function deletePhoto($conn, $photoId, $userId) {
    $checkStmt = $conn->prepare("SELECT user_id, image_path, album_id FROM photos WHERE id = ?");
    $checkStmt->bind_param("i", $photoId);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows === 0) {
        return ['success' => false, 'message' => '照片不存在'];
    }
    
    $photo = $result->fetch_assoc();
    if ($photo['user_id'] != $userId) {
        return ['success' => false, 'message' => '无权删除此照片'];
    }
    
    $filePath = "../" . $photo['image_path'];
    if (file_exists($filePath)) {
        unlink($filePath);
    }
    
    $stmt = $conn->prepare("DELETE FROM photos WHERE id = ?");
    $stmt->bind_param("i", $photoId);
    
    if ($stmt->execute()) {
        $albumId = $photo['album_id'];
        $coverCheck = $conn->prepare("SELECT cover_image FROM albums WHERE id = ?");
        $coverCheck->bind_param("i", $albumId);
        $coverCheck->execute();
        $album = $coverCheck->get_result()->fetch_assoc();
        
        if ($album['cover_image'] == $photo['image_path']) {
            $newCover = $conn->prepare("SELECT image_path FROM photos WHERE album_id = ? ORDER BY created_at DESC LIMIT 1");
            $newCover->bind_param("i", $albumId);
            $newCover->execute();
            $newResult = $newCover->get_result();
            
            if ($newResult->num_rows > 0) {
                $newCoverPath = $newResult->fetch_assoc()['image_path'];
                $updateCover = $conn->prepare("UPDATE albums SET cover_image = ? WHERE id = ?");
                $updateCover->bind_param("si", $newCoverPath, $albumId);
                $updateCover->execute();
            } else {
                $updateCover = $conn->prepare("UPDATE albums SET cover_image = NULL WHERE id = ?");
                $updateCover->bind_param("i", $albumId);
                $updateCover->execute();
            }
        }
        
        return ['success' => true];
    }
    
    return ['success' => false, 'message' => '删除失败'];
}

function toggleAlbumLike($conn, $albumId, $userId) {
    $checkStmt = $conn->prepare("SELECT id FROM album_likes WHERE album_id = ? AND user_id = ?");
    $checkStmt->bind_param("ii", $albumId, $userId);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows > 0) {
        $stmt = $conn->prepare("DELETE FROM album_likes WHERE album_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $albumId, $userId);
        $liked = false;
    } else {
        $stmt = $conn->prepare("INSERT INTO album_likes (album_id, user_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $albumId, $userId);
        $liked = true;
    }
    
    $stmt->execute();
    
    $countStmt = $conn->prepare("SELECT COUNT(*) as count FROM album_likes WHERE album_id = ?");
    $countStmt->bind_param("i", $albumId);
    $countStmt->execute();
    $count = $countStmt->get_result()->fetch_assoc()['count'];
    
    return ['success' => true, 'liked' => $liked, 'like_count' => $count];
}

function togglePhotoLike($conn, $photoId, $userId) {
    $checkStmt = $conn->prepare("SELECT id FROM photo_likes WHERE photo_id = ? AND user_id = ?");
    $checkStmt->bind_param("ii", $photoId, $userId);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows > 0) {
        $stmt = $conn->prepare("DELETE FROM photo_likes WHERE photo_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $photoId, $userId);
        $liked = false;
    } else {
        $stmt = $conn->prepare("INSERT INTO photo_likes (photo_id, user_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $photoId, $userId);
        $liked = true;
    }
    
    $stmt->execute();
    
    $countStmt = $conn->prepare("SELECT COUNT(*) as count FROM photo_likes WHERE photo_id = ?");
    $countStmt->bind_param("i", $photoId);
    $countStmt->execute();
    $count = $countStmt->get_result()->fetch_assoc()['count'];
    
    return ['success' => true, 'liked' => $liked, 'like_count' => $count];
}

function getMyAlbums($conn, $userId) {
    $stmt = $conn->prepare("
        SELECT 
            a.*,
            (SELECT COUNT(*) FROM photos WHERE album_id = a.id) as photo_count,
            (SELECT image_path FROM photos WHERE album_id = a.id ORDER BY created_at DESC LIMIT 1) as cover_image
        FROM albums a
        WHERE a.user_id = ?
        ORDER BY a.created_at DESC
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $albums = [];
    while ($row = $result->fetch_assoc()) {
        $albums[] = $row;
    }
    
    return ['success' => true, 'albums' => $albums];
}

$action = getRequestParam('action', '');
$userId = getCurrentUserId();

if (!$userId) {
    echo json_encode(['success' => false, 'message' => '请先登录']);
    exit;
}

switch ($action) {
    case 'create_album':
        $name = trim(getRequestParam('name', ''));
        $description = getRequestParam('description', '');
        $result = createAlbum($conn, $userId, $name, $description);
        echo json_encode($result);
        break;
        
    case 'update_album':
        $albumId = getRequestParam('album_id');
        $name = trim(getRequestParam('name', ''));
        $description = getRequestParam('description', '');
        if (!$albumId) {
            echo json_encode(['success' => false, 'message' => '缺少相册ID']);
            exit;
        }
        $result = updateAlbum($conn, $albumId, $userId, $name, $description);
        echo json_encode($result);
        break;
        
    case 'delete_album':
        $albumId = getRequestParam('album_id');
        if (!$albumId) {
            echo json_encode(['success' => false, 'message' => '缺少相册ID']);
            exit;
        }
        $result = deleteAlbum($conn, $albumId, $userId);
        echo json_encode($result);
        break;
        
    case 'list_albums':
        $page = intval(getRequestParam('page', 1));
        $limit = intval(getRequestParam('limit', 12));
        $result = getAlbumList($conn, $userId, $page, $limit);
        echo json_encode($result);
        break;
        
    case 'album_detail':
        $albumId = getRequestParam('album_id');
        if (!$albumId) {
            echo json_encode(['success' => false, 'message' => '缺少相册ID']);
            exit;
        }
        $result = getAlbumDetail($conn, $albumId, $userId);
        echo json_encode($result);
        break;
        
    case 'album_photos':
        $albumId = getRequestParam('album_id');
        $page = intval(getRequestParam('page', 1));
        $limit = intval(getRequestParam('limit', 20));
        if (!$albumId) {
            echo json_encode(['success' => false, 'message' => '缺少相册ID']);
            exit;
        }
        $result = getPhotos($conn, $albumId, $userId, $page, $limit);
        echo json_encode($result);
        break;
        
    case 'timeline_photos':
        $page = intval(getRequestParam('page', 1));
        $limit = intval(getRequestParam('limit', 30));
        $result = getTimelinePhotos($conn, $userId, $page, $limit);
        echo json_encode($result);
        break;
        
    case 'upload_photos':
        $albumId = getRequestParam('album_id');
        if (!$albumId) {
            echo json_encode(['success' => false, 'message' => '缺少相册ID']);
            exit;
        }
        
        if (!isset($_FILES['photos'])) {
            echo json_encode(['success' => false, 'message' => '请选择要上传的图片']);
            exit;
        }
        
        $titles = getRequestParam('titles', []);
        $descriptions = getRequestParam('descriptions', []);
        $takenDates = getRequestParam('taken_dates', []);
        
        $result = uploadPhotos($conn, $albumId, $userId, $_FILES['photos'], $titles, $descriptions, $takenDates);
        echo json_encode($result);
        break;
        
    case 'delete_photo':
        $photoId = getRequestParam('photo_id');
        if (!$photoId) {
            echo json_encode(['success' => false, 'message' => '缺少照片ID']);
            exit;
        }
        $result = deletePhoto($conn, $photoId, $userId);
        echo json_encode($result);
        break;
        
    case 'toggle_album_like':
        $albumId = getRequestParam('album_id');
        if (!$albumId) {
            echo json_encode(['success' => false, 'message' => '缺少相册ID']);
            exit;
        }
        $result = toggleAlbumLike($conn, $albumId, $userId);
        echo json_encode($result);
        break;
        
    case 'toggle_photo_like':
        $photoId = getRequestParam('photo_id');
        if (!$photoId) {
            echo json_encode(['success' => false, 'message' => '缺少照片ID']);
            exit;
        }
        $result = togglePhotoLike($conn, $photoId, $userId);
        echo json_encode($result);
        break;
        
    case 'my_albums':
        $result = getMyAlbums($conn, $userId);
        echo json_encode($result);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => '无效的操作']);
}
?>
