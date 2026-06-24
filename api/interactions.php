<?php
require 'db.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if (!isset($_SESSION['user_id']) && $action != 'get_comments') {
    echo json_encode(["success" => false, "message" => "请先登录"]);
    exit;
}

$current_user_id = $_SESSION['user_id'] ?? 0;

function isCurrentUserAdmin($conn, $userId) {
    if (!$userId) return false;
    $stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        return $result->fetch_assoc()['is_admin'] == 1;
    }
    return false;
}

if ($action == 'toggle_like') {
    $memoir_id = $_POST['memoir_id'] ?? 0;
    
    // Check if liked
    $check = $conn->prepare("SELECT id FROM likes WHERE memoir_id = ? AND user_id = ?");
    $check->bind_param("ii", $memoir_id, $current_user_id);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        // Unlike
        $stmt = $conn->prepare("DELETE FROM likes WHERE memoir_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $memoir_id, $current_user_id);
        $stmt->execute();
        echo json_encode(["success" => true, "liked" => false]);
    } else {
        // Like
        $stmt = $conn->prepare("INSERT INTO likes (memoir_id, user_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $memoir_id, $current_user_id);
        $stmt->execute();
        echo json_encode(["success" => true, "liked" => true]);
    }

} elseif ($action == 'add_comment') {
    $memoir_id = intval($_POST['memoir_id'] ?? 0);
    $content = trim($_POST['content'] ?? '');
    
    if ($memoir_id <= 0) {
        echo json_encode(["success" => false, "message" => "无效的回忆录ID"]);
        exit;
    }
    
    $image_path = null;
    if (!empty($_FILES['image']['name'])) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $maxSize = 5 * 1024 * 1024;
        
        $file = $_FILES['image'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(["success" => false, "message" => "文件上传失败"]);
            exit;
        }
        
        if ($file['size'] > $maxSize) {
            echo json_encode(["success" => false, "message" => "图片大小不能超过5MB"]);
            exit;
        }
        
        $fileType = $file['type'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($fileType, $allowedTypes) || !in_array($extension, $allowedExts)) {
            echo json_encode(["success" => false, "message" => "不支持的图片格式"]);
            exit;
        }
        
        $imageInfo = @getimagesize($file['tmp_name']);
        if (!$imageInfo || !in_array($imageInfo['mime'], $allowedTypes)) {
            echo json_encode(["success" => false, "message" => "不是有效的图片文件"]);
            exit;
        }
        
        $uploadDir = "../uploads/comments/";
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $newFilename = "comment_" . $current_user_id . "_" . time() . "_" . bin2hex(random_bytes(8)) . "." . $extension;
        $targetPath = $uploadDir . $newFilename;
        
        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            @chmod($targetPath, 0644);
            $image_path = "uploads/comments/" . $newFilename;
        } else {
            echo json_encode(["success" => false, "message" => "图片保存失败"]);
            exit;
        }
    }

    if (strlen($content) === 0 && empty($image_path)) {
        echo json_encode(["success" => false, "message" => "评论内容不能为空"]);
        exit;
    }
    
    if (strlen($content) > 2000) {
        echo json_encode(["success" => false, "message" => "评论内容过长"]);
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO comments (memoir_id, user_id, content, image) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiss", $memoir_id, $current_user_id, $content, $image_path);
    
    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "评论成功", "comment_id" => $conn->insert_id]);
    } else {
        echo json_encode(["success" => false, "message" => "评论失败"]);
    }

} elseif ($action == 'get_comments') {
    $memoir_id = $_GET['memoir_id'] ?? 0;
    
    $sql = "SELECT c.*, u.name as author_name, u.avatar as author_avatar FROM comments c 
            JOIN users u ON c.user_id = u.id 
            WHERE c.memoir_id = ? ORDER BY c.created_at ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $memoir_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $comments = [];
    while ($row = $result->fetch_assoc()) {
        $comments[] = $row;
    }
    
    echo json_encode(["success" => true, "comments" => $comments]);

} elseif ($action == 'delete_comment') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(["success" => false, "message" => "请先登录"]);
        exit;
    }
    
    $comment_id = intval($_POST['comment_id'] ?? 0);
    
    if ($comment_id <= 0) {
        echo json_encode(["success" => false, "message" => "无效的评论ID"]);
        exit;
    }
    
    $stmt = $conn->prepare("SELECT user_id, image FROM comments WHERE id = ?");
    $stmt->bind_param("i", $comment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(["success" => false, "message" => "评论不存在"]);
        exit;
    }
    
    $comment = $result->fetch_assoc();
    $isAdmin = isCurrentUserAdmin($conn, $current_user_id);
    
    if ($comment['user_id'] != $current_user_id && !$isAdmin) {
        echo json_encode(["success" => false, "message" => "无权删除此评论"]);
        exit;
    }
    
    if (!empty($comment['image'])) {
        $imgPath = "../" . $comment['image'];
        if (file_exists($imgPath)) {
            @unlink($imgPath);
        }
    }
    
    $deleteStmt = $conn->prepare("DELETE FROM comments WHERE id = ?");
    $deleteStmt->bind_param("i", $comment_id);
    
    if ($deleteStmt->execute()) {
        echo json_encode(["success" => true, "message" => "删除成功"]);
    } else {
        echo json_encode(["success" => false, "message" => "删除失败"]);
    }

} elseif ($action == 'get_notifications') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(["success" => false, "message" => "请先登录"]);
        exit;
    }
    $uid = $_SESSION['user_id'];
    
    // Get pagination parameters
    $page = $_GET['page'] ?? 1;
    $limit = $_GET['limit'] ?? 10;
    $offset = ($page - 1) * $limit;
    
    // Base SQL for getting notifications
    $baseSql = "
    (SELECT 'like' as type, u.name as actor_name, m.content as memoir_preview, l.created_at 
    FROM likes l 
    JOIN users u ON l.user_id = u.id 
    JOIN memoirs m ON l.memoir_id = m.id 
    WHERE m.user_id = ? AND l.user_id != ?)
    UNION ALL
    (SELECT 'comment' as type, u.name as actor_name, m.content as memoir_preview, c.created_at 
    FROM comments c 
    JOIN users u ON c.user_id = u.id 
    JOIN memoirs m ON c.memoir_id = m.id 
    WHERE m.user_id = ? AND c.user_id != ?)
    ORDER BY created_at DESC";
    
    // First get total count
    $countSql = "SELECT COUNT(*) as total FROM ($baseSql) as all_notifications";
    $countStmt = $conn->prepare($countSql);
    $countStmt->bind_param("iiii", $uid, $uid, $uid, $uid);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $total = $countResult->fetch_assoc()['total'];
    
    // Then get paginated results
    $paginatedSql = $baseSql . " LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($paginatedSql);
    $stmt->bind_param("iiiiii", $uid, $uid, $uid, $uid, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $notifs = [];
    while ($row = $result->fetch_assoc()) {
        $notifs[] = $row;
    }
    
    // Calculate total pages
    $totalPages = ceil($total / $limit);
    
    // Return results with pagination info
    echo json_encode([
        "success" => true, 
        "notifications" => $notifs,
        "pagination" => [
            "page" => $page,
            "limit" => $limit,
            "total" => $total,
            "total_pages" => $totalPages
        ]
    ]);
}
?>
