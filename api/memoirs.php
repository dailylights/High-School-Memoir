<?php
require 'db.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

function getMediaType($fileType, $fileName) {
    $imageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];
    $videoTypes = ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'];
    $audioTypes = ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/ogg', 'audio/webm', 'audio/aac'];
    
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    $videoExts = ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv'];
    $audioExts = ['mp3', 'wav', 'ogg', 'webm', 'aac', 'm4a', 'flac'];
    
    if (in_array($fileType, $imageTypes) || in_array($extension, $imageExts)) {
        return 'image';
    }
    if (in_array($fileType, $videoTypes) || in_array($extension, $videoExts)) {
        return 'video';
    }
    if (in_array($fileType, $audioTypes) || in_array($extension, $audioExts)) {
        return 'audio';
    }
    
    return null;
}

function validateMediaFile($file, $mediaType) {
    $allowedTypes = [
        'image' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'],
        'video' => ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'],
        'audio' => ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/ogg', 'audio/webm', 'audio/aac', 'audio/x-m4a', 'audio/flac']
    ];
    
    $maxSizes = [
        'image' => 10 * 1024 * 1024,
        'video' => 100 * 1024 * 1024,
        'audio' => 50 * 1024 * 1024
    ];
    
    $allowedExts = [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'],
        'video' => ['mp4', 'webm', 'ogg', 'mov', 'mkv'],
        'audio' => ['mp3', 'wav', 'ogg', 'webm', 'aac', 'm4a', 'flac']
    ];
    
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['valid' => false, 'message' => '文件上传失败'];
    }
    
    $fileType = $file['type'];
    $fileName = $file['name'];
    $fileSize = $file['size'];
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    
    if (!in_array($fileType, $allowedTypes[$mediaType]) && !in_array($extension, $allowedExts[$mediaType])) {
        return ['valid' => false, 'message' => '不支持的文件格式'];
    }
    
    if ($fileSize > $maxSizes[$mediaType]) {
        $sizeMB = round($maxSizes[$mediaType] / 1024 / 1024);
        return ['valid' => false, 'message' => "文件大小不能超过 {$sizeMB}MB"];
    }
    
    if ($mediaType === 'image') {
        $imageInfo = @getimagesize($file['tmp_name']);
        if (!$imageInfo || !in_array($imageInfo['mime'], $allowedTypes['image'])) {
            return ['valid' => false, 'message' => '不是有效的图片文件'];
        }
    }
    
    if ($mediaType === 'video') {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (strpos($mimeType, 'video/') !== 0 && strpos($mimeType, 'application/') !== 0) {
                return ['valid' => false, 'message' => '不是有效的视频文件'];
            }
        }
    }
    
    if ($mediaType === 'audio') {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (strpos($mimeType, 'audio/') !== 0 && strpos($mimeType, 'application/') !== 0) {
                return ['valid' => false, 'message' => '不是有效的音频文件'];
            }
        }
    }
    
    return ['valid' => true];
}

function uploadMediaFile($file, $mediaType, $userId, $memoirId) {
    $uploadDir = "../uploads/memoirs/" . $mediaType . "s/";
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $newFilename = $mediaType . "_" . $userId . "_" . $memoirId . "_" . time() . "_" . bin2hex(random_bytes(6)) . "." . $extension;
    $targetPath = $uploadDir . $newFilename;
    
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        @chmod($targetPath, 0644);
        $dbPath = "uploads/memoirs/" . $mediaType . "s/" . $newFilename;
        return [
            'success' => true,
            'path' => $dbPath,
            'size' => $file['size']
        ];
    }
    
    return ['success' => false, 'message' => '文件保存失败'];
}

function saveMediaToDB($conn, $memoirId, $userId, $mediaType, $filePath, $fileSize) {
    $stmt = $conn->prepare("INSERT INTO memoir_media (memoir_id, user_id, media_type, file_path, file_size) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iissi", $memoirId, $userId, $mediaType, $filePath, $fileSize);
    
    if ($stmt->execute()) {
        return $conn->insert_id;
    }
    return false;
}

function updateMemoirMediaCount($conn, $memoirId) {
    $countStmt = $conn->prepare("SELECT COUNT(*) as count, 
        SUM(CASE WHEN media_type = 'video' THEN 1 ELSE 0 END) as video_count,
        SUM(CASE WHEN media_type = 'audio' THEN 1 ELSE 0 END) as audio_count
        FROM memoir_media WHERE memoir_id = ?");
    $countStmt->bind_param("i", $memoirId);
    $countStmt->execute();
    $result = $countStmt->get_result()->fetch_assoc();
    
    $updateStmt = $conn->prepare("UPDATE memoirs SET media_count = ?, has_video = ?, has_audio = ? WHERE id = ?");
    $hasVideo = $result['video_count'] > 0 ? 1 : 0;
    $hasAudio = $result['audio_count'] > 0 ? 1 : 0;
    $updateStmt->bind_param("iiii", $result['count'], $hasVideo, $hasAudio, $memoirId);
    $updateStmt->execute();
}

function getMemoirMedia($conn, $memoirId) {
    $stmt = $conn->prepare("SELECT * FROM memoir_media WHERE memoir_id = ? ORDER BY created_at ASC");
    $stmt->bind_param("i", $memoirId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $media = [];
    while ($row = $result->fetch_assoc()) {
        $media[] = $row;
    }
    return $media;
}

if ($action == 'create') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(["success" => false, "message" => "请先登录"]);
        exit;
    }

    $content = trim($_POST['content'] ?? '');
    $topic_name = trim($_POST['topic_name'] ?? '');
    $user_id = $_SESSION['user_id'];
    
    $hasImages = !empty($_FILES['images']['name'][0]);
    $hasVideos = !empty($_FILES['videos']['name'][0]);
    $hasAudios = !empty($_FILES['audios']['name'][0]);
    
    if (empty($content) && !$hasImages && !$hasVideos && !$hasAudios) {
        echo json_encode(["success" => false, "message" => "内容不能为空"]);
        exit;
    }

    $topic_id = null;
    if (!empty($topic_name)) {
        $stmt = $conn->prepare("SELECT id FROM topics WHERE name = ?");
        $stmt->bind_param("s", $topic_name);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows > 0) {
            $topic_id = $res->fetch_assoc()['id'];
        } else {
            $stmt = $conn->prepare("INSERT INTO topics (name) VALUES (?)");
            $stmt->bind_param("s", $topic_name);
            if ($stmt->execute()) {
                $topic_id = $stmt->insert_id;
            }
        }
    }

    $stmt = $conn->prepare("INSERT INTO memoirs (user_id, content, images, topic_id) VALUES (?, ?, '[]', ?)");
    $stmt->bind_param("iss", $user_id, $content, $topic_id);

    if ($stmt->execute()) {
        $memoir_id = $conn->insert_id;
        
        $image_paths = [];
        $uploadedMedia = 0;
        
        if ($hasImages) {
            $total = count($_FILES['images']['name']);
            for ($i = 0; $i < $total; $i++) {
                if ($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
                    $file = [
                        'name' => $_FILES['images']['name'][$i],
                        'type' => $_FILES['images']['type'][$i],
                        'tmp_name' => $_FILES['images']['tmp_name'][$i],
                        'error' => $_FILES['images']['error'][$i],
                        'size' => $_FILES['images']['size'][$i]
                    ];
                    
                    $validation = validateMediaFile($file, 'image');
                    if ($validation['valid']) {
                        $upload = uploadMediaFile($file, 'image', $user_id, $memoir_id);
                        if ($upload['success']) {
                            $image_paths[] = $upload['path'];
                        }
                    }
                }
            }
        }
        
        if (!empty($image_paths)) {
            $images_json = json_encode($image_paths);
            $updateStmt = $conn->prepare("UPDATE memoirs SET images = ? WHERE id = ?");
            $updateStmt->bind_param("si", $images_json, $memoir_id);
            $updateStmt->execute();
        }
        
        if ($hasVideos) {
            $total = count($_FILES['videos']['name']);
            for ($i = 0; $i < $total; $i++) {
                if ($_FILES['videos']['error'][$i] === UPLOAD_ERR_OK) {
                    $file = [
                        'name' => $_FILES['videos']['name'][$i],
                        'type' => $_FILES['videos']['type'][$i],
                        'tmp_name' => $_FILES['videos']['tmp_name'][$i],
                        'error' => $_FILES['videos']['error'][$i],
                        'size' => $_FILES['videos']['size'][$i]
                    ];
                    
                    $validation = validateMediaFile($file, 'video');
                    if ($validation['valid']) {
                        $upload = uploadMediaFile($file, 'video', $user_id, $memoir_id);
                        if ($upload['success']) {
                            saveMediaToDB($conn, $memoir_id, $user_id, 'video', $upload['path'], $upload['size']);
                            $uploadedMedia++;
                        }
                    }
                }
            }
        }
        
        if ($hasAudios) {
            $total = count($_FILES['audios']['name']);
            for ($i = 0; $i < $total; $i++) {
                if ($_FILES['audios']['error'][$i] === UPLOAD_ERR_OK) {
                    $file = [
                        'name' => $_FILES['audios']['name'][$i],
                        'type' => $_FILES['audios']['type'][$i],
                        'tmp_name' => $_FILES['audios']['tmp_name'][$i],
                        'error' => $_FILES['audios']['error'][$i],
                        'size' => $_FILES['audios']['size'][$i]
                    ];
                    
                    $validation = validateMediaFile($file, 'audio');
                    if ($validation['valid']) {
                        $upload = uploadMediaFile($file, 'audio', $user_id, $memoir_id);
                        if ($upload['success']) {
                            saveMediaToDB($conn, $memoir_id, $user_id, 'audio', $upload['path'], $upload['size']);
                            $uploadedMedia++;
                        }
                    }
                }
            }
        }
        
        if ($uploadedMedia > 0) {
            updateMemoirMediaCount($conn, $memoir_id);
        }
        
        echo json_encode(["success" => true, "message" => "发布成功", "memoir_id" => $memoir_id]);
    } else {
        echo json_encode(["success" => false, "message" => "发布失败"]);
    }

} elseif ($action == 'list') {
    $search = $_GET['search'] ?? '';
    $filter_user_id = $_GET['user_id'] ?? 0;
    $filter_topic_id = $_GET['topic_id'] ?? 0;
    $current_user_id = $_SESSION['user_id'] ?? 0;
    
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 5;
    $offset = ($page - 1) * $limit;

    $where = "WHERE 1=1";
    $types = "";
    $params = [];
    
    if ($filter_user_id > 0) {
        $where .= " AND m.user_id = ?";
        $types .= "i";
        $params[] = $filter_user_id;
    }

    if ($filter_topic_id > 0) {
        $where .= " AND m.topic_id = ?";
        $types .= "i";
        $params[] = $filter_topic_id;
    }

    if (!empty($search)) {
        $where .= " AND (m.content LIKE ? OR u.name LIKE ?)";
        $search_term = "%" . $search . "%";
        $types .= "ss";
        $params[] = $search_term;
        $params[] = $search_term;
    }

    $count_sql = "SELECT COUNT(*) as total FROM memoirs m JOIN users u ON m.user_id = u.id $where";
    $stmt = $conn->prepare($count_sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $total_result = $stmt->get_result();
    $total_rows = $total_result->fetch_assoc()['total'];
    $total_pages = ceil($total_rows / $limit);

    $sql = "SELECT m.*, u.name as author_name, u.class as author_class, u.avatar as author_avatar, t.name as topic_name,
            (SELECT COUNT(*) FROM likes l WHERE l.memoir_id = m.id) as likes_count,
            (SELECT COUNT(*) FROM comments c WHERE c.memoir_id = m.id) as comments_count,
            (SELECT COUNT(*) FROM likes l2 WHERE l2.memoir_id = m.id AND l2.user_id = ?) as is_liked
            FROM memoirs m 
            JOIN users u ON m.user_id = u.id 
            LEFT JOIN topics t ON m.topic_id = t.id
            $where
            ORDER BY m.created_at DESC
            LIMIT ? OFFSET ?";
    
    $final_params = [$current_user_id];
    $final_types = "i";
    
    if (!empty($params)) {
        $final_params = array_merge($final_params, $params);
        $final_types .= $types;
    }
    
    $final_params[] = $limit;
    $final_params[] = $offset;
    $final_types .= "ii";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(["success" => false, "message" => "SQL准备失败: " . $conn->error]);
        exit;
    }
    $stmt->bind_param($final_types, ...$final_params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $memoirs = [];
    while ($row = $result->fetch_assoc()) {
        $row['images'] = json_decode($row['images']) ?? [];
        
        $media = getMemoirMedia($conn, $row['id']);
        $row['media'] = $media;
        
        $memoirs[] = $row;
    }
    
    echo json_encode([
        "success" => true, 
        "memoirs" => $memoirs,
        "pagination" => [
            "page" => $page,
            "limit" => $limit,
            "total" => $total_rows,
            "total_pages" => $total_pages
        ]
    ]);

} elseif ($action == 'popular') {
    $sql = "SELECT m.*, u.name as author_name, 
            (SELECT COUNT(*) FROM likes l WHERE l.memoir_id = m.id) as likes_count
            FROM memoirs m 
            JOIN users u ON m.user_id = u.id 
            ORDER BY likes_count DESC LIMIT 10";
    
    $result = $conn->query($sql);
    
    if (!$result) {
        echo json_encode(["success" => false, "message" => "查询失败"]);
        exit;
    }

    $memoirs = [];
    while ($row = $result->fetch_assoc()) {
        $content = $row['content'];
        if (function_exists('mb_substr')) {
            $preview = mb_substr($content, 0, 20);
        } else {
            $preview = substr($content, 0, 60);
        }
        
        $memoirs[] = [
            'id' => $row['id'],
            'content' => $preview . '...',
            'author_name' => $row['author_name'],
            'likes_count' => $row['likes_count']
        ];
    }
    echo json_encode(["success" => true, "memoirs" => $memoirs]);

} elseif ($action == 'delete') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(["success" => false, "message" => "请先登录"]);
        exit;
    }
    
    $memoir_id = intval($_POST['memoir_id'] ?? 0);
    $user_id = $_SESSION['user_id'];
    
    $isAdmin = false;
    $adminCheck = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
    $adminCheck->bind_param("i", $user_id);
    $adminCheck->execute();
    $adminResult = $adminCheck->get_result();
    if ($adminResult && $adminResult->num_rows > 0) {
        $isAdmin = $adminResult->fetch_assoc()['is_admin'] == 1;
    }
    
    if ($isAdmin) {
        $memoirStmt = $conn->prepare("SELECT images, user_id FROM memoirs WHERE id = ?");
        $memoirStmt->bind_param("i", $memoir_id);
    } else {
        $memoirStmt = $conn->prepare("SELECT images, user_id FROM memoirs WHERE id = ? AND user_id = ?");
        $memoirStmt->bind_param("ii", $memoir_id, $user_id);
    }
    $memoirStmt->execute();
    $memoirResult = $memoirStmt->get_result();
    
    if ($memoirResult->num_rows === 0) {
        echo json_encode(["success" => false, "message" => "删除失败或无权限"]);
        exit;
    }
    
    $memoirRow = $memoirResult->fetch_assoc();
    
    $media = getMemoirMedia($conn, $memoir_id);
    foreach ($media as $item) {
        $filePath = "../" . $item['file_path'];
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    }
    
    if ($isAdmin) {
        $stmt = $conn->prepare("DELETE FROM memoirs WHERE id = ?");
        $stmt->bind_param("i", $memoir_id);
    } else {
        $stmt = $conn->prepare("DELETE FROM memoirs WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $memoir_id, $user_id);
    }
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $oldImages = json_decode($memoirRow['images'] ?? '[]', true);
        if (is_array($oldImages)) {
            foreach ($oldImages as $img) {
                $imgPath = "../" . $img;
                if (file_exists($imgPath)) {
                    @unlink($imgPath);
                }
            }
        }
        
        echo json_encode(["success" => true, "message" => "删除成功"]);
    } else {
        echo json_encode(["success" => false, "message" => "删除失败或无权限"]);
    }
} elseif ($action == 'export') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(["success" => false, "message" => "请先登录"]);
        exit;
    }
    
    $user_id = $_SESSION['user_id'];
    $export_user_id = intval($_GET['user_id'] ?? 0);
    $class_id = intval($_GET['class_id'] ?? 0);
    $memoir_id = intval($_GET['memoir_id'] ?? 0);
    
    $where = "WHERE 1=1";
    $types = "";
    $params = [];
    
    if ($memoir_id > 0) {
        $where .= " AND m.id = ?";
        $types .= "i";
        $params[] = $memoir_id;
    } elseif ($class_id > 0) {
        $where .= " AND m.class_id = ? AND m.is_class_post = 1";
        $types .= "i";
        $params[] = $class_id;
    } elseif ($export_user_id > 0) {
        $where .= " AND m.user_id = ?";
        $types .= "i";
        $params[] = $export_user_id;
    } else {
        $where .= " AND m.user_id = ?";
        $types .= "i";
        $params[] = $user_id;
    }
    
    if ($memoir_id > 0) {
        $checkStmt = $conn->prepare("SELECT user_id, class_id, is_class_post FROM memoirs WHERE id = ?");
        $checkStmt->bind_param("i", $memoir_id);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        if ($checkResult->num_rows === 0) {
            echo json_encode(["success" => false, "message" => "回忆录不存在"]);
            exit;
        }
        $memoirData = $checkResult->fetch_assoc();
        $canExport = false;
        if ($memoirData['user_id'] == $user_id) {
            $canExport = true;
        }
        if (!$canExport) {
            echo json_encode(["success" => false, "message" => "无权限导出"]);
            exit;
        }
    } elseif ($class_id > 0) {
        $memberCheck = $conn->prepare("SELECT id FROM class_members WHERE class_id = ? AND user_id = ?");
        $memberCheck->bind_param("ii", $class_id, $user_id);
        $memberCheck->execute();
        if ($memberCheck->get_result()->num_rows === 0) {
            echo json_encode(["success" => false, "message" => "仅班级成员可导出班级回忆录"]);
            exit;
        }
    } elseif ($export_user_id > 0 && $export_user_id != $user_id) {
        echo json_encode(["success" => false, "message" => "只能导出自己的回忆录"]);
        exit;
    }
    
    $sql = "SELECT m.*, u.name as author_name, u.class as author_class, u.avatar as author_avatar, t.name as topic_name
            FROM memoirs m 
            JOIN users u ON m.user_id = u.id 
            LEFT JOIN topics t ON m.topic_id = t.id
            $where
            ORDER BY m.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $memoirs = [];
    while ($row = $result->fetch_assoc()) {
        $row['images'] = json_decode($row['images']) ?? [];
        $media = getMemoirMedia($conn, $row['id']);
        $row['media'] = $media;
        
        $commentStmt = $conn->prepare("SELECT c.*, u.name as author_name FROM comments c JOIN users u ON c.user_id = u.id WHERE c.memoir_id = ? ORDER BY c.created_at ASC");
        $commentStmt->bind_param("i", $row['id']);
        $commentStmt->execute();
        $comments = [];
        $commentResult = $commentStmt->get_result();
        while ($c = $commentResult->fetch_assoc()) {
            $comments[] = $c;
        }
        $row['comments'] = $comments;
        
        $likeStmt = $conn->prepare("SELECT COUNT(*) as count FROM likes WHERE memoir_id = ?");
        $likeStmt->bind_param("i", $row['id']);
        $likeStmt->execute();
        $row['likes_count'] = $likeStmt->get_result()->fetch_assoc()['count'];
        
        $memoirs[] = $row;
    }
    
    $userInfo = null;
    if ($export_user_id > 0 || ($memoir_id == 0 && $class_id == 0)) {
        $targetUserId = $export_user_id > 0 ? $export_user_id : $user_id;
        $userStmt = $conn->prepare("SELECT id, name, username, class, avatar, created_at FROM users WHERE id = ?");
        $userStmt->bind_param("i", $targetUserId);
        $userStmt->execute();
        $userInfo = $userStmt->get_result()->fetch_assoc();
    }
    
    echo json_encode([
        "success" => true,
        "memoirs" => $memoirs,
        "user" => $userInfo,
        "total" => count($memoirs)
    ]);

} elseif ($action == 'get_detail') {
    $memoir_id = intval($_GET['memoir_id'] ?? 0);
    $current_user_id = $_SESSION['user_id'] ?? 0;
    
    if (!$memoir_id) {
        echo json_encode(["success" => false, "message" => "无效的回忆ID"]);
        exit;
    }
    
    $sql = "SELECT m.*, u.name as author_name, u.class as author_class, u.avatar as author_avatar, t.name as topic_name,
            (SELECT COUNT(*) FROM likes l WHERE l.memoir_id = m.id) as likes_count,
            (SELECT COUNT(*) FROM comments c WHERE c.memoir_id = m.id) as comments_count,
            (SELECT COUNT(*) FROM likes l2 WHERE l2.memoir_id = m.id AND l2.user_id = ?) as is_liked
            FROM memoirs m 
            JOIN users u ON m.user_id = u.id 
            LEFT JOIN topics t ON m.topic_id = t.id
            WHERE m.id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $current_user_id, $memoir_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(["success" => false, "message" => "回忆不存在"]);
        exit;
    }
    
    $memoir = $result->fetch_assoc();
    $memoir['images'] = json_decode($memoir['images']) ?? [];
    $memoir['media'] = getMemoirMedia($conn, $memoir_id);
    
    echo json_encode(["success" => true, "memoir" => $memoir]);
} else {
    echo json_encode(["success" => false, "message" => "Invalid action: " . $action]);
}
?>
