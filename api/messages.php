<?php
require 'db.php';

// 获取当前登录用户ID
function getCurrentUserId() {
    return isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
}

// 获取请求参数（POST或GET）
function getRequestParam($key, $default = null) {
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

// 检查用户是否被拉黑
function isBlocked($conn, $userId, $otherUserId) {
    $stmt = $conn->prepare("SELECT id FROM blocks WHERE blocker_id = ? AND blocked_id = ?");
    $stmt->bind_param("ii", $userId, $otherUserId);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

// 获取会话列表
function getConversations($conn, $userId) {
    // 检查用户是否被拉黑对方的查询
    $stmt = $conn->prepare("
        SELECT 
            c.id as conversation_id,
            c.last_message_time,
            CASE 
                WHEN c.user1_id = ? THEN c.user2_id 
                ELSE c.user1_id 
            END as partner_id,
            u.name as partner_name,
            u.avatar as partner_avatar,
            u.class as partner_class,
            (
                SELECT content FROM messages 
                WHERE conversation_id = c.id AND is_deleted = 0 AND is_recalled = 0
                ORDER BY created_at DESC LIMIT 1
            ) as last_message,
            (
                SELECT COUNT(*) FROM messages 
                WHERE conversation_id = c.id AND sender_id != ? AND is_read = 0 AND is_deleted = 0 AND is_recalled = 0
            ) as unread_count,
            (SELECT is_recalled FROM messages WHERE id = c.last_message_id) as last_message_recalled,
            (SELECT image FROM messages WHERE id = c.last_message_id) as last_message_image
        FROM conversations c
        JOIN users u ON u.id = CASE 
            WHEN c.user1_id = ? THEN c.user2_id 
            ELSE c.user1_id 
        END
        WHERE ((c.user1_id = ? AND c.user1_deleted = 0) OR (c.user2_id = ? AND c.user2_deleted = 0))
        AND NOT EXISTS (SELECT 1 FROM blocks b WHERE b.blocker_id = ? AND b.blocked_id = CASE WHEN c.user1_id = ? THEN c.user2_id ELSE c.user1_id END)
        ORDER BY COALESCE(c.last_message_time, c.created_at) DESC
    ");
    $stmt->bind_param("iiiiiii", $userId, $userId, $userId, $userId, $userId, $userId, $userId);
    $stmt->execute();
    return $stmt->get_result();
}

// 获取消息历史
function getMessages($conn, $conversationId, $userId, $page = 1, $limit = 30) {
    // 验证用户是否有权访问此会话
    $checkStmt = $conn->prepare("
        SELECT id FROM conversations 
        WHERE id = ? AND (user1_id = ? OR user2_id = ?)
    ");
    $checkStmt->bind_param("iii", $conversationId, $userId, $userId);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows === 0) {
        return ['success' => false, 'message' => '无权访问此会话'];
    }
    
    $offset = ($page - 1) * $limit;
    
    // 获取消息（只显示未删除且未撤回的，或者发送者自己看已撤回的）
    $stmt = $conn->prepare("
        SELECT 
            m.id,
            m.sender_id,
            m.content,
            m.image,
            m.is_read,
            m.is_deleted,
            m.is_recalled,
            m.recalled_at,
            m.created_at,
            u.name as sender_name,
            u.avatar as sender_avatar,
            CASE WHEN m.sender_id = ? THEN 1 ELSE 0 END as is_mine
        FROM messages m
        JOIN users u ON u.id = m.sender_id
        WHERE m.conversation_id = ?
        AND (
            (m.is_deleted = 0 AND m.is_recalled = 0)
            OR (m.sender_id = ? AND m.is_deleted = 1)
            OR (m.sender_id = ? AND m.is_recalled = 1)
        )
        ORDER BY m.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param("iii", $userId, $conversationId, $userId, $userId, $limit, $offset);
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // 获取总消息数
    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM messages WHERE conversation_id = ? AND is_deleted = 0 AND is_recalled = 0");
    $countStmt->bind_param("i", $conversationId);
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['total'];
    
    // 标记消息为已读
    $readStmt = $conn->prepare("
        UPDATE messages 
        SET is_read = 1 
        WHERE conversation_id = ? AND sender_id != ? AND is_read = 0
    ");
    $readStmt->bind_param("ii", $conversationId, $userId);
    $readStmt->execute();
    
    return [
        'success' => true,
        'messages' => array_reverse($messages),
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => ceil($total / $limit)
        ]
    ];
}

// 发送消息
function sendMessage($conn, $conversationId, $senderId, $content, $image = null) {
    // 验证用户是否有权访问此会话
    $checkStmt = $conn->prepare("
        SELECT id FROM conversations 
        WHERE id = ? AND (user1_id = ? OR user2_id = ?)
    ");
    $checkStmt->bind_param("iii", $conversationId, $senderId, $senderId);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows === 0) {
        return ['success' => false, 'message' => '无权访问此会话'];
    }
    
    // 插入消息
    $stmt = $conn->prepare("INSERT INTO messages (conversation_id, sender_id, content, image) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiss", $conversationId, $senderId, $content, $image);
    
    if ($stmt->execute()) {
        $messageId = $conn->insert_id;
        
        // 更新会话的最后消息时间和最后消息ID
        $updateStmt = $conn->prepare("
            UPDATE conversations 
            SET last_message_id = ?, last_message_time = NOW() 
            WHERE id = ?
        ");
        $updateStmt->bind_param("ii", $messageId, $conversationId);
        $updateStmt->execute();
        
        // 获取发送的消息详情
        $msgStmt = $conn->prepare("
            SELECT m.*, u.name as sender_name, u.avatar as sender_avatar 
            FROM messages m 
            JOIN users u ON u.id = m.sender_id 
            WHERE m.id = ?
        ");
        $msgStmt->bind_param("i", $messageId);
        $msgStmt->execute();
        $message = $msgStmt->get_result()->fetch_assoc();
        
        return ['success' => true, 'message' => $message];
    }
    
    return ['success' => false, 'message' => '发送失败'];
}

// 撤回消息（2分钟内可撤回）
function recallMessage($conn, $messageId, $userId) {
    // 验证消息存在且属于当前用户
    $checkStmt = $conn->prepare("SELECT id, sender_id, created_at, is_recalled FROM messages WHERE id = ?");
    $checkStmt->bind_param("i", $messageId);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows === 0) {
        return ['success' => false, 'message' => '消息不存在'];
    }
    
    $msg = $result->fetch_assoc();
    
    if ($msg['sender_id'] != $userId) {
        return ['success' => false, 'message' => '无权撤回此消息'];
    }
    
    if ($msg['is_recalled']) {
        return ['success' => false, 'message' => '消息已撤回'];
    }
    
    // 检查是否在2分钟内
    $createdAt = strtotime($msg['created_at']);
    $now = time();
    if ($now - $createdAt > 120) {
        return ['success' => false, 'message' => '超过2分钟，无法撤回'];
    }
    
    // 标记为已撤回
    $updateStmt = $conn->prepare("UPDATE messages SET is_recalled = 1, recalled_at = NOW() WHERE id = ?");
    $updateStmt->bind_param("i", $messageId);
    
    if ($updateStmt->execute()) {
        return ['success' => true, 'message' => '已撤回'];
    }
    
    return ['success' => false, 'message' => '撤回失败'];
}

// 删除消息（软删除，只是自己看不见）
function deleteMessage($conn, $messageId, $userId) {
    $checkStmt = $conn->prepare("SELECT sender_id FROM messages WHERE id = ?");
    $checkStmt->bind_param("i", $messageId);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows === 0) {
        return ['success' => false, 'message' => '消息不存在'];
    }
    
    $msg = $result->fetch_assoc();
    if ($msg['sender_id'] != $userId) {
        return ['success' => false, 'message' => '无权删除此消息'];
    }
    
    $updateStmt = $conn->prepare("UPDATE messages SET is_deleted = 1 WHERE id = ?");
    $updateStmt->bind_param("i", $messageId);
    
    if ($updateStmt->execute()) {
        return ['success' => true, 'message' => '已删除'];
    }
    
    return ['success' => false, 'message' => '删除失败'];
}

// 删除整个聊天记录
function deleteConversation($conn, $conversationId, $userId) {
    $stmt = $conn->prepare("
        UPDATE conversations 
        SET user1_deleted = CASE WHEN user1_id = ? THEN 1 ELSE user1_deleted END,
            user2_deleted = CASE WHEN user2_id = ? THEN 1 ELSE user2_deleted END
        WHERE id = ? AND (user1_id = ? OR user2_id = ?)
    ");
    $stmt->bind_param("iiiii", $userId, $userId, $conversationId, $userId, $userId);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        return ['success' => true, 'message' => '聊天记录已删除'];
    }
    
    return ['success' => false, 'message' => '删除失败'];
}

// 拉黑用户
function blockUser($conn, $blockerId, $blockedId, $reason = null) {
    if ($blockerId == $blockedId) {
        return ['success' => false, 'message' => '不能拉黑自己'];
    }
    
    // 检查是否已存在
    $checkStmt = $conn->prepare("SELECT id FROM blocks WHERE blocker_id = ? AND blocked_id = ?");
    $checkStmt->bind_param("ii", $blockerId, $blockedId);
    $checkStmt->execute();
    
    if ($checkStmt->get_result()->num_rows > 0) {
        return ['success' => false, 'message' => '已拉黑过该用户'];
    }
    
    // 插入拉黑记录
    $insertStmt = $conn->prepare("INSERT INTO blocks (blocker_id, blocked_id, reason) VALUES (?, ?, ?)");
    $insertStmt->bind_param("iis", $blockerId, $blockedId, $reason);
    
    if ($insertStmt->execute()) {
        return ['success' => true, 'message' => '已拉黑该用户'];
    }
    
    return ['success' => false, 'message' => '拉黑失败'];
}

// 解除拉黑
function unblockUser($conn, $blockerId, $blockedId) {
    $stmt = $conn->prepare("DELETE FROM blocks WHERE blocker_id = ? AND blocked_id = ?");
    $stmt->bind_param("ii", $blockerId, $blockedId);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        return ['success' => true, 'message' => '已解除拉黑'];
    }
    
    return ['success' => false, 'message' => '未找到拉黑记录'];
}

// 获取黑名单列表
function getBlockedUsers($conn, $userId) {
    $stmt = $conn->prepare("
        SELECT b.*, u.name as blocked_name, u.avatar as blocked_avatar, u.class as blocked_class
        FROM blocks b
        JOIN users u ON u.id = b.blocked_id
        WHERE b.blocker_id = ?
        ORDER BY b.created_at DESC
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $blocks = [];
    while ($row = $result->fetch_assoc()) {
        $blocks[] = $row;
    }
    
    return $blocks;
}

// 检查是否拉黑了某用户
function isBlocking($conn, $userId, $otherUserId) {
    $stmt = $conn->prepare("SELECT id FROM blocks WHERE blocker_id = ? AND blocked_id = ?");
    $stmt->bind_param("ii", $userId, $otherUserId);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

// 创建或获取会话
function getOrCreateConversation($conn, $user1Id, $user2Id) {
    // 检查是否被拉黑
    if (isBlocked($conn, $user1Id, $user2Id) || isBlocked($conn, $user2Id, $user1Id)) {
        return ['success' => false, 'message' => '无法与该用户发起会话'];
    }
    
    // 确保user1Id < user2Id以保持一致性
    if ($user1Id > $user2Id) {
        list($user1Id, $user2Id) = [$user2Id, $user1Id];
    }
    
    // 检查是否已存在会话
    $stmt = $conn->prepare("SELECT id FROM conversations WHERE user1_id = ? AND user2_id = ?");
    $stmt->bind_param("ii", $user1Id, $user2Id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return ['success' => true, 'conversation_id' => $result->fetch_assoc()['id']];
    }
    
    // 创建新会话
    $insertStmt = $conn->prepare("INSERT INTO conversations (user1_id, user2_id) VALUES (?, ?)");
    $insertStmt->bind_param("ii", $user1Id, $user2Id);
    
    if ($insertStmt->execute()) {
        return ['success' => true, 'conversation_id' => $conn->insert_id];
    }
    
    return ['success' => false, 'message' => '创建会话失败'];
}

// 获取未读消息总数
function getUnreadCount($conn, $userId) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM messages m
        JOIN conversations c ON c.id = m.conversation_id
        WHERE (c.user1_id = ? OR c.user2_id = ?) 
        AND m.sender_id != ? 
        AND m.is_read = 0
        AND m.is_deleted = 0
        AND m.is_recalled = 0
        AND ((c.user1_id = ? AND c.user1_deleted = 0) OR (c.user2_id = ? AND c.user2_deleted = 0))
    ");
    $stmt->bind_param("iiiiii", $userId, $userId, $userId, $userId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc()['count'];
}

// 搜索用户（用于发起私信）
function searchUsers($conn, $query, $excludeUserId) {
    $stmt = $conn->prepare("
        SELECT id, name, avatar, class 
        FROM users 
        WHERE id != ? AND (name LIKE ? OR username LIKE ?)
        AND NOT EXISTS (SELECT 1 FROM blocks b WHERE b.blocker_id = ? AND b.blocked_id = users.id)
        LIMIT 10
    ");
    $searchTerm = "%" . $query . "%";
    $stmt->bind_param("isss", $excludeUserId, $searchTerm, $searchTerm, $excludeUserId);
    $stmt->execute();
    return $stmt->get_result();
}

// 获取用户信息
function getUserInfo($conn, $userId) {
    $stmt = $conn->prepare("SELECT id, name, avatar, class FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->num_rows > 0 ? $result->fetch_assoc() : null;
}

// 路由处理
$action = getRequestParam('action', '');

$userId = getCurrentUserId();

csrfProtection();

if (!$userId && $action !== 'search_users') {
    echo json_encode(['success' => false, 'message' => '请先登录']);
    exit;
}

switch ($action) {
    case 'get_conversations':
        $result = getConversations($conn, $userId);
        $conversations = [];
        while ($row = $result->fetch_assoc()) {
            $conversations[] = $row;
        }
        echo json_encode([
            'success' => true,
            'conversations' => $conversations
        ]);
        break;
        
    case 'get_messages':
        $conversationId = getRequestParam('conversation_id');
        $page = intval(getRequestParam('page', 1));
        
        if (!$conversationId) {
            echo json_encode(['success' => false, 'message' => '缺少会话ID']);
            exit;
        }
        
        $result = getMessages($conn, $conversationId, $userId, $page);
        echo json_encode($result);
        break;
        
    case 'send_message':
        $conversationId = getRequestParam('conversation_id');
        $content = trim(getRequestParam('content', ''));
        
        // 处理图片上传
        $imagePath = null;
        if (!empty($_FILES['image']['name'])) {
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $maxSize = 5 * 1024 * 1024; // 5MB
            
            $file = $_FILES['image'];
            if ($file['error'] === UPLOAD_ERR_OK) {
                $fileType = $file['type'];
                $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                
                if (!in_array($fileType, $allowedTypes) && !in_array($extension, $allowedExts)) {
                    echo json_encode(['success' => false, 'message' => '不支持的图片格式']);
                    exit;
                }
                
                if ($file['size'] > $maxSize) {
                    echo json_encode(['success' => false, 'message' => '图片大小不能超过5MB']);
                    exit;
                }
                
                $uploadDir = "../uploads/messages/";
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                $newFilename = "msg_" . $userId . "_" . time() . "_" . bin2hex(random_bytes(6)) . "." . $extension;
                $targetPath = $uploadDir . $newFilename;
                
                if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                    @chmod($targetPath, 0644);
                    $imagePath = "uploads/messages/" . $newFilename;
                }
            }
        }
        
        if (!$conversationId) {
            echo json_encode(['success' => false, 'message' => '缺少会话ID']);
            exit;
        }
        
        if (empty($content) && empty($imagePath)) {
            echo json_encode(['success' => false, 'message' => '消息内容不能为空']);
            exit;
        }
        
        if (strlen($content) > 2000) {
            echo json_encode(['success' => false, 'message' => '消息内容过长']);
            exit;
        }
        
        $result = sendMessage($conn, $conversationId, $userId, $content, $imagePath);
        echo json_encode($result);
        break;
        
    case 'recall_message':
        $messageId = intval(getRequestParam('message_id', 0));
        
        if (!$messageId) {
            echo json_encode(['success' => false, 'message' => '缺少消息ID']);
            exit;
        }
        
        $result = recallMessage($conn, $messageId, $userId);
        echo json_encode($result);
        break;
        
    case 'delete_message':
        $messageId = intval(getRequestParam('message_id', 0));
        
        if (!$messageId) {
            echo json_encode(['success' => false, 'message' => '缺少消息ID']);
            exit;
        }
        
        $result = deleteMessage($conn, $messageId, $userId);
        echo json_encode($result);
        break;
        
    case 'delete_conversation':
        $conversationId = intval(getRequestParam('conversation_id', 0));
        
        if (!$conversationId) {
            echo json_encode(['success' => false, 'message' => '缺少会话ID']);
            exit;
        }
        
        $result = deleteConversation($conn, $conversationId, $userId);
        echo json_encode($result);
        break;
        
    case 'block_user':
        $blockedId = intval(getRequestParam('user_id', 0));
        $reason = trim(getRequestParam('reason', ''));
        
        if (!$blockedId) {
            echo json_encode(['success' => false, 'message' => '缺少用户ID']);
            exit;
        }
        
        $result = blockUser($conn, $userId, $blockedId, $reason ?: null);
        echo json_encode($result);
        break;
        
    case 'unblock_user':
        $blockedId = intval(getRequestParam('user_id', 0));
        
        if (!$blockedId) {
            echo json_encode(['success' => false, 'message' => '缺少用户ID']);
            exit;
        }
        
        $result = unblockUser($conn, $userId, $blockedId);
        echo json_encode($result);
        break;
        
    case 'get_blocked_list':
        $blocks = getBlockedUsers($conn, $userId);
        echo json_encode(['success' => true, 'blocks' => $blocks]);
        break;
        
    case 'check_block_status':
        $otherUserId = intval(getRequestParam('user_id', 0));
        
        if (!$otherUserId) {
            echo json_encode(['success' => false, 'message' => '缺少用户ID']);
            exit;
        }
        
        $isBlocked = isBlocking($conn, $userId, $otherUserId);
        $isBlockedBy = isBlocking($conn, $otherUserId, $userId);
        
        echo json_encode([
            'success' => true,
            'is_blocking' => $isBlocked,
            'is_blocked_by' => $isBlockedBy
        ]);
        break;
        
    case 'create_conversation':
        $partnerId = getRequestParam('partner_id');
        
        if (!$partnerId) {
            echo json_encode(['success' => false, 'message' => '缺少用户ID']);
            exit;
        }
        
        if ($partnerId == $userId) {
            echo json_encode(['success' => false, 'message' => '不能给自己发私信']);
            exit;
        }
        
        // 检查用户是否存在
        $partner = getUserInfo($conn, $partnerId);
        if (!$partner) {
            echo json_encode(['success' => false, 'message' => '用户不存在']);
            exit;
        }
        
        // 检查是否被拉黑
        if (isBlocking($conn, $userId, $partnerId)) {
            echo json_encode(['success' => false, 'message' => '您已拉黑该用户']);
            exit;
        }
        
        if (isBlocking($conn, $partnerId, $userId)) {
            echo json_encode(['success' => false, 'message' => '该用户已拉黑您']);
            exit;
        }
        
        $result = getOrCreateConversation($conn, $userId, $partnerId);
        if ($result['success']) {
            $result['partner'] = $partner;
        }
        echo json_encode($result);
        break;
        
    case 'get_unread_count':
        $count = getUnreadCount($conn, $userId);
        echo json_encode(['success' => true, 'unread_count' => $count]);
        break;
        
    case 'search_users':
        $query = getRequestParam('query', '');
        
        if (strlen($query) < 1) {
            echo json_encode(['success' => false, 'message' => '搜索词太短']);
            exit;
        }
        
        $result = searchUsers($conn, $query, $userId);
        $users = [];
        while ($row = $result->fetch_assoc()) {
            // 检查双向拉黑状态
            $row['is_blocking'] = isBlocking($conn, $userId, $row['id']);
            $row['is_blocked_by'] = isBlocking($conn, $row['id'], $userId);
            $users[] = $row;
        }
        echo json_encode([
            'success' => true,
            'users' => $users
        ]);
        break;
        
    case 'mark_read':
        $conversationId = getRequestParam('conversation_id');
        
        if (!$conversationId) {
            echo json_encode(['success' => false, 'message' => '缺少会话ID']);
            exit;
        }
        
        $stmt = $conn->prepare("
            UPDATE messages 
            SET is_read = 1 
            WHERE conversation_id = ? AND sender_id != ? AND is_read = 0
        ");
        $stmt->bind_param("ii", $conversationId, $userId);
        $stmt->execute();
        
        echo json_encode(['success' => true]);
        break;
        
    case 'get_conversation_info':
        $conversationId = getRequestParam('conversation_id');
        
        if (!$conversationId) {
            echo json_encode(['success' => false, 'message' => '缺少会话ID']);
            exit;
        }
        
        // 验证用户是否有权访问此会话
        $stmt = $conn->prepare("
            SELECT c.*, 
                CASE 
                    WHEN c.user1_id = ? THEN c.user2_id 
                    ELSE c.user1_id 
                END as partner_id,
                u.name as partner_name,
                u.avatar as partner_avatar,
                u.class as partner_class
            FROM conversations c
            JOIN users u ON u.id = CASE 
                WHEN c.user1_id = ? THEN c.user2_id 
                ELSE c.user1_id 
            END
            WHERE c.id = ? AND (c.user1_id = ? OR c.user2_id = ?)
        ");
        $stmt->bind_param("iiiii", $userId, $userId, $conversationId, $userId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $conv = $result->fetch_assoc();
            // 检查拉黑状态
            $conv['is_blocking'] = isBlocking($conn, $userId, $conv['partner_id']);
            $conv['is_blocked_by'] = isBlocking($conn, $conv['partner_id'], $userId);
            
            echo json_encode([
                'success' => true,
                'conversation' => $conv
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => '会话不存在或无权访问']);
        }
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => '无效的操作']);
}
?>
