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

// 获取会话列表
function getConversations($conn, $userId) {
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
                WHERE conversation_id = c.id 
                ORDER BY created_at DESC LIMIT 1
            ) as last_message,
            (
                SELECT COUNT(*) FROM messages 
                WHERE conversation_id = c.id AND sender_id != ? AND is_read = 0
            ) as unread_count
        FROM conversations c
        JOIN users u ON u.id = CASE 
            WHEN c.user1_id = ? THEN c.user2_id 
            ELSE c.user1_id 
        END
        WHERE c.user1_id = ? OR c.user2_id = ?
        ORDER BY COALESCE(c.last_message_time, c.created_at) DESC
    ");
    $stmt->bind_param("iiiii", $userId, $userId, $userId, $userId, $userId);
    $stmt->execute();
    return $stmt->get_result();
}

// 获取消息历史
function getMessages($conn, $conversationId, $userId, $page = 1, $limit = 20) {
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
    
    // 获取消息
    $stmt = $conn->prepare("
        SELECT 
            m.id,
            m.sender_id,
            m.content,
            m.is_read,
            m.created_at,
            u.name as sender_name,
            u.avatar as sender_avatar
        FROM messages m
        JOIN users u ON u.id = m.sender_id
        WHERE m.conversation_id = ?
        ORDER BY m.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param("iii", $conversationId, $limit, $offset);
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // 获取总消息数
    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM messages WHERE conversation_id = ?");
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
function sendMessage($conn, $conversationId, $senderId, $content) {
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
    $stmt = $conn->prepare("INSERT INTO messages (conversation_id, sender_id, content) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $conversationId, $senderId, $content);
    
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

// 创建或获取会话
function getOrCreateConversation($conn, $user1Id, $user2Id) {
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
    ");
    $stmt->bind_param("iii", $userId, $userId, $userId);
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
        LIMIT 10
    ");
    $searchTerm = "%" . $query . "%";
    $stmt->bind_param("iss", $excludeUserId, $searchTerm, $searchTerm);
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

if (!$userId) {
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
        
        if (!$conversationId) {
            echo json_encode(['success' => false, 'message' => '缺少会话ID']);
            exit;
        }
        
        if (empty($content)) {
            echo json_encode(['success' => false, 'message' => '消息内容不能为空']);
            exit;
        }
        
        if (strlen($content) > 2000) {
            echo json_encode(['success' => false, 'message' => '消息内容过长']);
            exit;
        }
        
        $result = sendMessage($conn, $conversationId, $userId, $content);
        echo json_encode($result);
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
            echo json_encode([
                'success' => true,
                'conversation' => $result->fetch_assoc()
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => '会话不存在或无权访问']);
        }
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => '无效的操作']);
}
?>
