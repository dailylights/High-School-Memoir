<?php
require 'db.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;

csrfProtection();

if (!$userId) {
    echo json_encode(['success' => false, 'message' => '请先登录']);
    exit;
}

// 获取草稿列表
if ($action == 'list') {
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    
    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM drafts WHERE user_id = ?");
    $countStmt->bind_param("i", $userId);
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['total'];
    
    $stmt = $conn->prepare("
        SELECT d.*, t.name as topic_name
        FROM drafts d
        LEFT JOIN topics t ON t.id = d.topic_id
        WHERE d.user_id = ?
        ORDER BY d.updated_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param("iii", $userId, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $drafts = [];
    while ($row = $result->fetch_assoc()) {
        $row['images'] = json_decode($row['images']) ?? [];
        $drafts[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'drafts' => $drafts,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => ceil($total / $limit)
        ]
    ]);
    exit;
}

// 保存草稿
if ($action == 'save') {
    $draftId = intval($_POST['draft_id'] ?? 0);
    $content = trim($_POST['content'] ?? '');
    $topicId = intval($_POST['topic_id'] ?? 0) ?: null;
    
    // 处理图片
    $images = [];
    if (!empty($_POST['images'])) {
        $images = json_decode($_POST['images'], true) ?? [];
    }
    
    // 如果有图片上传
    if (!empty($_FILES['images']['name'][0])) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $maxSize = 5 * 1024 * 1024; // 5MB
        $uploadDir = "../uploads/drafts/";
        
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        foreach ($_FILES['images']['name'] as $key => $name) {
            if ($_FILES['images']['error'][$key] === UPLOAD_ERR_OK) {
                $tmpName = $_FILES['images']['tmp_name'][$key];
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                
                // 验证扩展名
                if (!in_array($ext, $allowedExts)) {
                    continue; // 跳过无效扩展名
                }
                
                // 验证MIME类型
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $tmpName);
                finfo_close($finfo);
                
                if (!in_array($mimeType, $allowedTypes)) {
                    continue; // 跳过无效MIME类型
                }
                
                // 验证是否为真实图片
                $imageInfo = @getimagesize($tmpName);
                if (!$imageInfo || !in_array($imageInfo['mime'], $allowedTypes)) {
                    continue; // 跳过非图片文件
                }
                
                // 验证文件大小
                if ($_FILES['images']['size'][$key] > $maxSize) {
                    continue; // 跳过过大文件
                }
                
                $newName = "draft_" . $userId . "_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $ext;
                $targetPath = $uploadDir . $newName;
                
                if (move_uploaded_file($tmpName, $targetPath)) {
                    @chmod($targetPath, 0644);
                    $images[] = "uploads/drafts/" . $newName;
                }
            }
        }
    }
    
    $imagesJson = json_encode($images);
    
    if ($draftId > 0) {
        // 更新草稿
        $stmt = $conn->prepare("UPDATE drafts SET content = ?, topic_id = ?, images = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
        $stmt->bind_param("sisii", $content, $topicId, $imagesJson, $draftId, $userId);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => '草稿已保存', 'draft_id' => $draftId]);
        } else {
            echo json_encode(['success' => false, 'message' => '保存失败']);
        }
    } else {
        // 创建新草稿
        if (empty($content) && empty($images)) {
            echo json_encode(['success' => false, 'message' => '草稿内容不能为空']);
            exit;
        }
        
        $stmt = $conn->prepare("INSERT INTO drafts (user_id, content, topic_id, images) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isis", $userId, $content, $topicId, $imagesJson);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => '草稿已保存', 'draft_id' => $conn->insert_id]);
        } else {
            echo json_encode(['success' => false, 'message' => '保存失败']);
        }
    }
    exit;
}

// 获取单个草稿
if ($action == 'get') {
    $draftId = intval($_GET['draft_id'] ?? 0);
    
    if (!$draftId) {
        echo json_encode(['success' => false, 'message' => '缺少草稿ID']);
        exit;
    }
    
    $stmt = $conn->prepare("SELECT d.*, t.name as topic_name FROM drafts d LEFT JOIN topics t ON t.id = d.topic_id WHERE d.id = ? AND d.user_id = ?");
    $stmt->bind_param("ii", $draftId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $row['images'] = json_decode($row['images']) ?? [];
        echo json_encode(['success' => true, 'draft' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => '草稿不存在']);
    }
    exit;
}

// 删除草稿
if ($action == 'delete') {
    $draftId = intval($_POST['draft_id'] ?? 0);
    
    if (!$draftId) {
        echo json_encode(['success' => false, 'message' => '缺少草稿ID']);
        exit;
    }
    
    $stmt = $conn->prepare("DELETE FROM drafts WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $draftId, $userId);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo json_encode(['success' => true, 'message' => '草稿已删除']);
    } else {
        echo json_encode(['success' => false, 'message' => '删除失败或草稿不存在']);
    }
    exit;
}

// 获取草稿数量
if ($action == 'count') {
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM drafts WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $count = $stmt->get_result()->fetch_assoc()['cnt'];
    echo json_encode(['success' => true, 'count' => $count]);
    exit;
}

echo json_encode(['success' => false, 'message' => '无效的操作']);
?>
