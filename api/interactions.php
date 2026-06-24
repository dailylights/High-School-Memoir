<?php
require 'db.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

csrfProtection();

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
        
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!in_array($mimeType, $allowedTypes)) {
                echo json_encode(["success" => false, "message" => "不支持的图片格式"]);
                exit;
            }
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
        $comment_id = $conn->insert_id;
        
        // Process mentions
        processMentions($conn, $content, $memoir_id, $comment_id, 'comment', $current_user_id);
        
        echo json_encode(["success" => true, "message" => "评论成功", "comment_id" => $comment_id]);
    } else {
        echo json_encode(["success" => false, "message" => "评论失败"]);
    }

} elseif ($action == 'get_comments') {
    $memoir_id = $_GET['memoir_id'] ?? 0;
    $current_user_id = $_SESSION['user_id'] ?? 0;
    
    $sql = "SELECT c.*, u.name as author_name, u.avatar as author_avatar,
            (SELECT COUNT(*) FROM comment_likes cl WHERE cl.comment_id = c.id) as like_count,
            (SELECT COUNT(*) FROM comment_likes cl2 WHERE cl2.comment_id = c.id AND cl2.user_id = ?) as is_liked,
            (SELECT COUNT(*) FROM comments cr WHERE cr.parent_id = c.id) as reply_count
            FROM comments c 
            JOIN users u ON c.user_id = u.id 
            WHERE c.memoir_id = ? AND c.parent_id IS NULL
            ORDER BY c.created_at ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $current_user_id, $memoir_id);
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
    UNION ALL
    (SELECT 'mention' as type, u.name as actor_name, m.content as memoir_preview, mn.created_at 
    FROM mentions mn 
    JOIN users u ON mn.mentioner_user_id = u.id 
    LEFT JOIN memoirs m ON mn.memoir_id = m.id 
    WHERE mn.mentioned_user_id = ? AND mn.mention_type = 'memoir')
    UNION ALL
    (SELECT 'comment_mention' as type, u.name as actor_name, c.content as memoir_preview, mn.created_at 
    FROM mentions mn 
    JOIN users u ON mn.mentioner_user_id = u.id 
    LEFT JOIN comments c ON mn.comment_id = c.id 
    WHERE mn.mentioned_user_id = ? AND mn.mention_type = 'comment')
    ORDER BY created_at DESC";
    
    // First get total count
    $countSql = "SELECT COUNT(*) as total FROM ($baseSql) as all_notifications";
    $countStmt = $conn->prepare($countSql);
    $countStmt->bind_param("iiiiii", $uid, $uid, $uid, $uid, $uid, $uid);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $total = $countResult->fetch_assoc()['total'];
    
    // Then get paginated results
    $paginatedSql = $baseSql . " LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($paginatedSql);
    $stmt->bind_param("iiiiiiii", $uid, $uid, $uid, $uid, $uid, $uid, $limit, $offset);
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

} elseif ($action == 'toggle_favorite') {
    // 收藏/取消收藏回忆录
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(["success" => false, "message" => "请先登录"]);
        exit;
    }
    
    $memoir_id = intval($_POST['memoir_id'] ?? 0);
    
    if ($memoir_id <= 0) {
        echo json_encode(["success" => false, "message" => "无效的回忆录ID"]);
        exit;
    }
    
    // Check if favorited
    $check = $conn->prepare("SELECT id FROM favorites WHERE memoir_id = ? AND user_id = ?");
    $check->bind_param("ii", $memoir_id, $current_user_id);
    $check->execute();
    
    if ($check->get_result()->num_rows > 0) {
        // Unfavorite
        $stmt = $conn->prepare("DELETE FROM favorites WHERE memoir_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $memoir_id, $current_user_id);
        $stmt->execute();
        
        // Update count
        $conn->query("UPDATE memoirs SET favorite_count = GREATEST(0, favorite_count - 1) WHERE id = $memoir_id");
        
        echo json_encode(["success" => true, "favorited" => false]);
    } else {
        // Favorite
        $stmt = $conn->prepare("INSERT INTO favorites (memoir_id, user_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $memoir_id, $current_user_id);
        $stmt->execute();
        
        // Update count
        $conn->query("UPDATE memoirs SET favorite_count = favorite_count + 1 WHERE id = $memoir_id");
        
        echo json_encode(["success" => true, "favorited" => true]);
    }

} elseif ($action == 'get_favorites') {
    // 获取用户收藏列表
    $user_id = intval($_GET['user_id'] ?? 0);
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    
    if ($user_id <= 0) {
        echo json_encode(["success" => false, "message" => "无效的用户ID"]);
        exit;
    }
    
    $countSql = "SELECT COUNT(*) as total FROM favorites WHERE user_id = ?";
    $countStmt = $conn->prepare($countSql);
    $countStmt->bind_param("i", $user_id);
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['total'];
    $totalPages = ceil($total / $limit);
    
    $sql = "SELECT m.*, u.name as author_name, u.class as author_class, u.avatar as author_avatar, t.name as topic_name,
            (SELECT COUNT(*) FROM likes l WHERE l.memoir_id = m.id) as likes_count,
            (SELECT COUNT(*) FROM comments c WHERE c.memoir_id = m.id) as comments_count,
            (SELECT COUNT(*) FROM likes l2 WHERE l2.memoir_id = m.id AND l2.user_id = ?) as is_liked,
            1 as is_favorited
            FROM favorites f
            JOIN memoirs m ON f.memoir_id = m.id
            JOIN users u ON m.user_id = u.id
            LEFT JOIN topics t ON m.topic_id = t.id
            WHERE f.user_id = ?
            ORDER BY f.created_at DESC
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiii", $current_user_id, $user_id, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $memoirs = [];
    while ($row = $result->fetch_assoc()) {
        $row['images'] = json_decode($row['images']) ?? [];
        $memoirs[] = $row;
    }
    
    echo json_encode([
        "success" => true,
        "memoirs" => $memoirs,
        "pagination" => [
            "page" => $page,
            "limit" => $limit,
            "total" => $total,
            "total_pages" => $totalPages
        ]
    ]);

} elseif ($action == 'share_memoir') {
    // 转发/分享回忆录
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(["success" => false, "message" => "请先登录"]);
        exit;
    }
    
    $memoir_id = intval($_POST['memoir_id'] ?? 0);
    $share_type = $_POST['share_type'] ?? 'link';
    $share_text = trim($_POST['share_text'] ?? '');
    
    if ($memoir_id <= 0) {
        echo json_encode(["success" => false, "message" => "无效的回忆录ID"]);
        exit;
    }
    
    // Record share
    $stmt = $conn->prepare("INSERT INTO shares (memoir_id, user_id, share_text, share_type) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiss", $memoir_id, $current_user_id, $share_text, $share_type);
    $stmt->execute();
    
    // Update share count
    $conn->query("UPDATE memoirs SET share_count = share_count + 1 WHERE id = $memoir_id");
    
    // Return share URL/info for external sharing
    $shareUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/memoir.html?id=' . $memoir_id;
    
    echo json_encode([
        "success" => true, 
        "message" => "分享成功",
        "share_url" => $shareUrl,
        "share_count" => $conn->query("SELECT share_count FROM memoirs WHERE id = $memoir_id")->fetch_assoc()['share_count']
    ]);

} elseif ($action == 'get_shares') {
    // 获取分享列表
    $memoir_id = intval($_GET['memoir_id'] ?? 0);
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    
    if ($memoir_id <= 0) {
        echo json_encode(["success" => false, "message" => "无效的回忆录ID"]);
        exit;
    }
    
    $countSql = "SELECT COUNT(*) as total FROM shares WHERE memoir_id = ?";
    $countStmt = $conn->prepare($countSql);
    $countStmt->bind_param("i", $memoir_id);
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['total'];
    $totalPages = ceil($total / $limit);
    
    $sql = "SELECT s.*, u.name as user_name, u.avatar as user_avatar 
            FROM shares s 
            JOIN users u ON s.user_id = u.id 
            WHERE s.memoir_id = ? 
            ORDER BY s.created_at DESC 
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $memoir_id, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $shares = [];
    while ($row = $result->fetch_assoc()) {
        $shares[] = $row;
    }
    
    echo json_encode([
        "success" => true,
        "shares" => $shares,
        "pagination" => [
            "page" => $page,
            "limit" => $limit,
            "total" => $total,
            "total_pages" => $totalPages
        ]
    ]);

} elseif ($action == 'add_tags') {
    // 为回忆录添加标签
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(["success" => false, "message" => "请先登录"]);
        exit;
    }
    
    $memoir_id = intval($_POST['memoir_id'] ?? 0);
    $tags = isset($_POST['tags']) ? json_decode($_POST['tags'], true) : [];
    
    if ($memoir_id <= 0) {
        echo json_encode(["success" => false, "message" => "无效的回忆录ID"]);
        exit;
    }
    
    // Verify ownership
    $check = $conn->prepare("SELECT user_id FROM memoirs WHERE id = ?");
    $check->bind_param("i", $memoir_id);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    
    if (!$row || ($row['user_id'] != $current_user_id && !isCurrentUserAdmin($conn, $current_user_id))) {
        echo json_encode(["success" => false, "message" => "无权编辑此回忆录"]);
        exit;
    }
    
    $addedTags = [];
    
    if (is_array($tags) && !empty($tags)) {
        foreach ($tags as $tagName) {
            $tagName = trim($tagName);
            if (empty($tagName) || strlen($tagName) > 50) continue;
            
            // Get or create tag
            $tagStmt = $conn->prepare("SELECT id FROM tags WHERE name = ?");
            $tagStmt->bind_param("s", $tagName);
            $tagStmt->execute();
            $tagResult = $tagStmt->get_result();
            
            if ($tagResult->num_rows > 0) {
                $tag_id = $tagResult->fetch_assoc()['id'];
            } else {
                $insertTag = $conn->prepare("INSERT INTO tags (name) VALUES (?)");
                $insertTag->bind_param("s", $tagName);
                if ($insertTag->execute()) {
                    $tag_id = $conn->insert_id;
                } else {
                    continue;
                }
            }
            
            // Add memoir-tag relation if not exists
            $relCheck = $conn->prepare("SELECT id FROM memoir_tags WHERE memoir_id = ? AND tag_id = ?");
            $relCheck->bind_param("ii", $memoir_id, $tag_id);
            $relCheck->execute();
            
            if ($relCheck->get_result()->num_rows === 0) {
                $relInsert = $conn->prepare("INSERT INTO memoir_tags (memoir_id, tag_id) VALUES (?, ?)");
                $relInsert->bind_param("ii", $memoir_id, $tag_id);
                if ($relInsert->execute()) {
                    // Update tag use count
                    $conn->query("UPDATE tags SET use_count = use_count + 1 WHERE id = $tag_id");
                    $addedTags[] = ['id' => $tag_id, 'name' => $tagName];
                }
            }
        }
    }
    
    echo json_encode(["success" => true, "tags" => $addedTags]);

} elseif ($action == 'remove_tag') {
    // 移除回忆录标签
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(["success" => false, "message" => "请先登录"]);
        exit;
    }
    
    $memoir_id = intval($_POST['memoir_id'] ?? 0);
    $tag_id = intval($_POST['tag_id'] ?? 0);
    
    if ($memoir_id <= 0 || $tag_id <= 0) {
        echo json_encode(["success" => false, "message" => "无效的参数"]);
        exit;
    }
    
    // Verify ownership
    $check = $conn->prepare("SELECT user_id FROM memoirs WHERE id = ?");
    $check->bind_param("i", $memoir_id);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    
    if (!$row || ($row['user_id'] != $current_user_id && !isCurrentUserAdmin($conn, $current_user_id))) {
        echo json_encode(["success" => false, "message" => "无权编辑此回忆录"]);
        exit;
    }
    
    $deleteStmt = $conn->prepare("DELETE FROM memoir_tags WHERE memoir_id = ? AND tag_id = ?");
    $deleteStmt->bind_param("ii", $memoir_id, $tag_id);
    
    if ($deleteStmt->execute() && $deleteStmt->affected_rows > 0) {
        // Update tag use count
        $conn->query("UPDATE tags SET use_count = GREATEST(0, use_count - 1) WHERE id = $tag_id");
        echo json_encode(["success" => true, "message" => "标签已移除"]);
    } else {
        echo json_encode(["success" => false, "message" => "标签不存在"]);
    }

} elseif ($action == 'get_memoir_tags') {
    // 获取回忆录标签
    $memoir_id = intval($_GET['memoir_id'] ?? 0);
    
    if ($memoir_id <= 0) {
        echo json_encode(["success" => false, "message" => "无效的回忆录ID"]);
        exit;
    }
    
    $sql = "SELECT t.id, t.name, t.use_count 
            FROM memoir_tags mt 
            JOIN tags t ON mt.tag_id = t.id 
            WHERE mt.memoir_id = ? 
            ORDER BY t.use_count DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $memoir_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $tags = [];
    while ($row = $result->fetch_assoc()) {
        $tags[] = $row;
    }
    
    echo json_encode(["success" => true, "tags" => $tags]);

} elseif ($action == 'get_popular_tags') {
    // 获取热门标签
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    
    $sql = "SELECT * FROM tags ORDER BY use_count DESC LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $tags = [];
    while ($row = $result->fetch_assoc()) {
        $tags[] = $row;
    }
    
    echo json_encode(["success" => true, "tags" => $tags]);

} elseif ($action == 'toggle_comment_like') {
    // 评论点赞/取消点赞
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(["success" => false, "message" => "请先登录"]);
        exit;
    }
    
    $comment_id = intval($_POST['comment_id'] ?? 0);
    
    if ($comment_id <= 0) {
        echo json_encode(["success" => false, "message" => "无效的评论ID"]);
        exit;
    }
    
    // Check if liked
    $check = $conn->prepare("SELECT id FROM comment_likes WHERE comment_id = ? AND user_id = ?");
    $check->bind_param("ii", $comment_id, $current_user_id);
    $check->execute();
    
    if ($check->get_result()->num_rows > 0) {
        // Unlike
        $stmt = $conn->prepare("DELETE FROM comment_likes WHERE comment_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $comment_id, $current_user_id);
        $stmt->execute();
        
        // Update count
        $conn->query("UPDATE comments SET like_count = GREATEST(0, like_count - 1) WHERE id = $comment_id");
        
        echo json_encode(["success" => true, "liked" => false]);
    } else {
        // Like
        $stmt = $conn->prepare("INSERT INTO comment_likes (comment_id, user_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $comment_id, $current_user_id);
        $stmt->execute();
        
        // Update count
        $conn->query("UPDATE comments SET like_count = like_count + 1 WHERE id = $comment_id");
        
        echo json_encode(["success" => true, "liked" => true]);
    }

} elseif ($action == 'reply_comment') {
    // 回复评论（二级评论）
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(["success" => false, "message" => "请先登录"]);
        exit;
    }
    
    $memoir_id = intval($_POST['memoir_id'] ?? 0);
    $parent_id = intval($_POST['parent_id'] ?? 0);
    $reply_to_user_id = intval($_POST['reply_to_user_id'] ?? 0);
    $content = trim($_POST['content'] ?? '');
    
    if ($memoir_id <= 0) {
        echo json_encode(["success" => false, "message" => "无效的回忆录ID"]);
        exit;
    }
    
    if ($parent_id <= 0) {
        echo json_encode(["success" => false, "message" => "无效的父评论ID"]);
        exit;
    }
    
    if (strlen($content) === 0) {
        echo json_encode(["success" => false, "message" => "回复内容不能为空"]);
        exit;
    }
    
    if (strlen($content) > 2000) {
        echo json_encode(["success" => false, "message" => "回复内容过长"]);
        exit;
    }
    
    // Insert reply
    $stmt = $conn->prepare("INSERT INTO comments (memoir_id, user_id, content, parent_id, reply_to_user_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iisii", $memoir_id, $current_user_id, $content, $parent_id, $reply_to_user_id);
    
    if ($stmt->execute()) {
        $comment_id = $conn->insert_id;
        
        // Process mentions
        processMentions($conn, $content, $memoir_id, $comment_id, 'comment', $current_user_id);
        
        echo json_encode(["success" => true, "message" => "回复成功", "comment_id" => $comment_id]);
    } else {
        echo json_encode(["success" => false, "message" => "回复失败"]);
    }

} elseif ($action == 'get_comment_replies') {
    // 获取评论的回复列表
    $parent_id = intval($_GET['parent_id'] ?? 0);
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    
    if ($parent_id <= 0) {
        echo json_encode(["success" => false, "message" => "无效的评论ID"]);
        exit;
    }
    
    $countSql = "SELECT COUNT(*) as total FROM comments WHERE parent_id = ?";
    $countStmt = $conn->prepare($countSql);
    $countStmt->bind_param("i", $parent_id);
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['total'];
    $totalPages = ceil($total / $limit);
    
    $sql = "SELECT c.*, u.name as author_name, u.avatar as author_avatar,
            ru.name as reply_to_name,
            (SELECT COUNT(*) FROM comment_likes cl WHERE cl.comment_id = c.id) as likes_count,
            (SELECT COUNT(*) FROM comment_likes cl2 WHERE cl2.comment_id = c.id AND cl2.user_id = ?) as is_liked
            FROM comments c
            JOIN users u ON c.user_id = u.id
            LEFT JOIN users ru ON c.reply_to_user_id = ru.id
            WHERE c.parent_id = ?
            ORDER BY c.created_at ASC
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiii", $current_user_id, $parent_id, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $replies = [];
    while ($row = $result->fetch_assoc()) {
        $replies[] = $row;
    }
    
    echo json_encode([
        "success" => true,
        "replies" => $replies,
        "pagination" => [
            "page" => $page,
            "limit" => $limit,
            "total" => $total,
            "total_pages" => $totalPages
        ]
    ]);

} elseif ($action == 'get_mentions') {
    // 获取@提及我的列表
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(["success" => false, "message" => "请先登录"]);
        exit;
    }
    
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    
    $countSql = "SELECT COUNT(*) as total FROM mentions WHERE mentioned_user_id = ?";
    $countStmt = $conn->prepare($countSql);
    $countStmt->bind_param("i", $current_user_id);
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['total'];
    $totalPages = ceil($total / $limit);
    
    $sql = "SELECT m.*, u.name as mentioner_name, u.avatar as mentioner_avatar
            FROM mentions m
            JOIN users u ON m.mentioner_user_id = u.id
            WHERE m.mentioned_user_id = ?
            ORDER BY m.created_at DESC
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $current_user_id, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $mentions = [];
    while ($row = $result->fetch_assoc()) {
        $mentions[] = $row;
    }
    
    echo json_encode([
        "success" => true,
        "mentions" => $mentions,
        "pagination" => [
            "page" => $page,
            "limit" => $limit,
            "total" => $total,
            "total_pages" => $totalPages
        ]
    ]);

} elseif ($action == 'mark_mentions_read') {
    // 标记提及为已读
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(["success" => false, "message" => "请先登录"]);
        exit;
    }
    
    $stmt = $conn->prepare("UPDATE mentions SET is_read = 1 WHERE mentioned_user_id = ? AND is_read = 0");
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    
    echo json_encode(["success" => true, "message" => "已标记为已读"]);

} elseif ($action == 'search_users_for_mention') {
    // 搜索用户用于@提及
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(["success" => false, "message" => "请先登录"]);
        exit;
    }
    
    $keyword = trim($_GET['keyword'] ?? '');
    $limit = min(20, max(1, intval($_GET['limit'] ?? 10)));
    
    if (empty($keyword)) {
        echo json_encode(["success" => true, "users" => []]);
        exit;
    }
    
    $search_term = "%" . $keyword . "%";
    $sql = "SELECT id, name, username, avatar, class FROM users 
            WHERE name LIKE ? OR username LIKE ? 
            ORDER BY name ASC 
            LIMIT ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssi", $search_term, $search_term, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    
    echo json_encode(["success" => true, "users" => $users]);
}

// Helper function: process mentions in content
function processMentions($conn, $content, $memoir_id, $comment_id, $type, $mentioner_id) {
    // Match @username or @name patterns
    if (preg_match_all('/@([\x{4e00}-\x{9fa5}A-Za-z0-9_]+)/u', $content, $matches)) {
        foreach ($matches[1] as $mentionName) {
            // Find user by name or username
            $stmt = $conn->prepare("SELECT id FROM users WHERE name = ? OR username = ?");
            $stmt->bind_param("ss", $mentionName, $mentionName);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $mentionedUserId = $row['id'];
                // Don't mention self
                if ($mentionedUserId == $mentioner_id) continue;
                
                // Check if already mentioned
                $checkStmt = $conn->prepare("SELECT id FROM mentions WHERE 
                    memoir_id = ? AND comment_id = ? AND mentioned_user_id = ? AND mention_type = ?");
                $mid = $memoir_id;
                $cid = $comment_id;
                $muid = $mentionedUserId;
                $mtype = $type;
                $checkStmt->bind_param("iiis", $mid, $cid, $muid, $mtype);
                $checkStmt->execute();
                
                if ($checkStmt->get_result()->num_rows === 0) {
                    $insertStmt = $conn->prepare("INSERT INTO mentions (memoir_id, comment_id, mentioned_user_id, mentioner_user_id, mention_type) VALUES (?, ?, ?, ?, ?)");
                    $insertStmt->bind_param("iiiss", $mid, $cid, $muid, $mentioner_id, $mtype);
                    $insertStmt->execute();
                }
            }
        }
    }
}
?>
