<?php
require 'db.php';

// 获取当前登录用户ID
function getCurrentUserId() {
    return isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
}

// 获取请求参数
function getRequestParam($key, $default = null) {
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

// 检查用户是否是班级管理员
function isClassAdmin($conn, $classId, $userId) {
    $stmt = $conn->prepare("
        SELECT role FROM class_members 
        WHERE class_id = ? AND user_id = ? AND role IN ('admin', 'owner')
    ");
    $stmt->bind_param("ii", $classId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->num_rows > 0;
}

// 获取班级公告列表
function getAnnouncements($conn, $classId, $page = 1, $limit = 10) {
    $offset = ($page - 1) * $limit;
    
    $countSql = "SELECT COUNT(*) as total FROM class_announcements WHERE class_id = ?";
    $countStmt = $conn->prepare($countSql);
    $countStmt->bind_param("i", $classId);
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['total'];
    
    $sql = "SELECT a.*, u.name as author_name, u.avatar as author_avatar
            FROM class_announcements a
            JOIN users u ON u.id = a.author_id
            WHERE a.class_id = ?
            ORDER BY a.is_pinned DESC, a.created_at DESC
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $classId, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $announcements = [];
    while ($row = $result->fetch_assoc()) {
        $announcements[] = $row;
    }
    
    return [
        'items' => $announcements,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'total_pages' => ceil($total / $limit)
    ];
}

// 创建班级公告
function createAnnouncement($conn, $classId, $authorId, $title, $content, $isPinned = false) {
    $stmt = $conn->prepare("
        INSERT INTO class_announcements (class_id, author_id, title, content, is_pinned)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("iissi", $classId, $authorId, $title, $content, $isPinned);
    
    if ($stmt->execute()) {
        return ['success' => true, 'id' => $conn->insert_id];
    }
    return ['success' => false, 'message' => '创建失败'];
}

// 获取班级活动列表
function getActivities($conn, $classId, $page = 1, $limit = 10) {
    $offset = ($page - 1) * $limit;
    
    $countSql = "SELECT COUNT(*) as total FROM class_activities WHERE class_id = ?";
    $countStmt = $conn->prepare($countSql);
    $countStmt->bind_param("i", $classId);
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['total'];
    
    $sql = "SELECT a.*, u.name as author_name, u.avatar as author_avatar,
            (SELECT COUNT(*) FROM class_activity_participants WHERE activity_id = a.id AND status = 'going') as participant_count
            FROM class_activities a
            JOIN users u ON u.id = a.author_id
            WHERE a.class_id = ?
            ORDER BY a.start_time DESC
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $classId, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $activities = [];
    while ($row = $result->fetch_assoc()) {
        $activities[] = $row;
    }
    
    return [
        'items' => $activities,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'total_pages' => ceil($total / $limit)
    ];
}

// 创建班级活动
function createActivity($conn, $classId, $authorId, $title, $description, $activityType, $startTime, $endTime, $location, $maxParticipants) {
    $stmt = $conn->prepare("
        INSERT INTO class_activities (class_id, author_id, title, description, activity_type, start_time, end_time, location, max_participants)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("iissssss", $classId, $authorId, $title, $description, $activityType, $startTime, $endTime, $location, $maxParticipants);
    
    if ($stmt->execute()) {
        return ['success' => true, 'id' => $conn->insert_id];
    }
    return ['success' => false, 'message' => '创建失败'];
}

// 参加活动
function joinActivity($conn, $activityId, $userId, $status = 'going') {
    // 检查是否已参加
    $checkStmt = $conn->prepare("SELECT id, status FROM class_activity_participants WHERE activity_id = ? AND user_id = ?");
    $checkStmt->bind_param("ii", $activityId, $userId);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows > 0) {
        $existing = $result->fetch_assoc();
        if ($existing['status'] == $status) {
            return ['success' => true, 'message' => '状态未变化'];
        }
        // 更新状态
        $updateStmt = $conn->prepare("UPDATE class_activity_participants SET status = ? WHERE activity_id = ? AND user_id = ?");
        $updateStmt->bind_param("sii", $status, $activityId, $userId);
        $updateStmt->execute();
        return ['success' => true, 'message' => '状态已更新'];
    }
    
    $stmt = $conn->prepare("INSERT INTO class_activity_participants (activity_id, user_id, status) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $activityId, $userId, $status);
    
    if ($stmt->execute()) {
        return ['success' => true, 'message' => '已报名'];
    }
    return ['success' => false, 'message' => '报名失败'];
}

// 获取投票列表
function getPolls($conn, $classId, $page = 1, $limit = 10) {
    $offset = ($page - 1) * $limit;
    
    $countSql = "SELECT COUNT(*) as total FROM class_polls WHERE class_id = ? AND is_active = 1";
    $countStmt = $conn->prepare($countSql);
    $countStmt->bind_param("i", $classId);
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['total'];
    
    $sql = "SELECT p.*, u.name as author_name,
            (SELECT COUNT(*) FROM class_poll_votes WHERE poll_id = p.id) as vote_count
            FROM class_polls p
            JOIN users u ON u.id = p.author_id
            WHERE p.class_id = ? AND p.is_active = 1
            ORDER BY p.created_at DESC
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $classId, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $polls = [];
    while ($row = $result->fetch_assoc()) {
        // 获取选项
        $optionsStmt = $conn->prepare("SELECT * FROM class_poll_options WHERE poll_id = ? ORDER BY option_order");
        $optionsStmt->bind_param("i", $row['id']);
        $optionsStmt->execute();
        $optionsResult = $optionsStmt->get_result();
        
        $options = [];
        while ($opt = $optionsResult->fetch_assoc()) {
            // 获取每个选项的投票数
            $voteCountStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM class_poll_votes WHERE option_id = ?");
            $voteCountStmt->bind_param("i", $opt['id']);
            $voteCountStmt->execute();
            $opt['vote_count'] = $voteCountStmt->get_result()->fetch_assoc()['cnt'];
            $options[] = $opt;
        }
        $row['options'] = $options;
        $polls[] = $row;
    }
    
    return [
        'items' => $polls,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'total_pages' => ceil($total / $limit)
    ];
}

// 创建投票
function createPoll($conn, $classId, $authorId, $title, $description, $questionType, $isAnonymous, $endTime, $options) {
    $stmt = $conn->prepare("
        INSERT INTO class_polls (class_id, author_id, title, description, question_type, is_anonymous, end_time)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $isAnon = $isAnonymous ? 1 : 0;
    $stmt->bind_param("iissss", $classId, $authorId, $title, $description, $questionType, $isAnon, $endTime);
    
    if (!$stmt->execute()) {
        return ['success' => false, 'message' => '创建投票失败'];
    }
    
    $pollId = $conn->insert_id;
    
    // 插入选项
    foreach ($options as $order => $optionText) {
        $optionStmt = $conn->prepare("INSERT INTO class_poll_options (poll_id, option_text, option_order) VALUES (?, ?, ?)");
        $optionStmt->bind_param("isi", $pollId, $optionText, $order);
        $optionStmt->execute();
    }
    
    return ['success' => true, 'id' => $pollId];
}

// 投票
function votePoll($conn, $pollId, $userId, $optionIds) {
    // 检查是否已投票
    $checkStmt = $conn->prepare("SELECT id FROM class_poll_votes WHERE poll_id = ? AND user_id = ?");
    $checkStmt->bind_param("ii", $pollId, $userId);
    $checkStmt->execute();
    
    if ($checkStmt->get_result()->num_rows > 0) {
        return ['success' => false, 'message' => '您已投过票'];
    }
    
    // 检查投票是否过期
    $pollStmt = $conn->prepare("SELECT end_time, is_active FROM class_polls WHERE id = ?");
    $pollStmt->bind_param("i", $pollId);
    $pollStmt->execute();
    $poll = $pollStmt->get_result()->fetch_assoc();
    
    if (!$poll || !$poll['is_active']) {
        return ['success' => false, 'message' => '投票不存在或已关闭'];
    }
    
    if ($poll['end_time'] && strtotime($poll['end_time']) < time()) {
        return ['success' => false, 'message' => '投票已结束'];
    }
    
    // 插入投票记录
    foreach ($optionIds as $optionId) {
        $insertStmt = $conn->prepare("INSERT INTO class_poll_votes (poll_id, option_id, user_id) VALUES (?, ?, ?)");
        $insertStmt->bind_param("iii", $pollId, $optionId, $userId);
        $insertStmt->execute();
    }
    
    return ['success' => true, 'message' => '投票成功'];
}

// 获取用户对投票的选择
function getUserVoteOptions($conn, $pollId, $userId) {
    $stmt = $conn->prepare("SELECT option_id FROM class_poll_votes WHERE poll_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $pollId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $optionIds = [];
    while ($row = $result->fetch_assoc()) {
        $optionIds[] = $row['option_id'];
    }
    return $optionIds;
}

// 获取班级活动参与者
function getActivityParticipants($conn, $activityId) {
    $stmt = $conn->prepare("
        SELECT p.*, u.name, u.avatar, u.class
        FROM class_activity_participants p
        JOIN users u ON u.id = p.user_id
        WHERE p.activity_id = ?
        ORDER BY p.status, p.created_at
    ");
    $stmt->bind_param("i", $activityId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $participants = ['going' => [], 'interested' => [], 'not_going' => []];
    while ($row = $result->fetch_assoc()) {
        $participants[$row['status']][] = $row;
    }
    return $participants;
}

// 路由处理
$action = getRequestParam('action', '');
$userId = getCurrentUserId();

csrfProtection();

if (!$userId) {
    echo json_encode(['success' => false, 'message' => '请先登录']);
    exit;
}

switch ($action) {
    // ========== 公告 ==========
    case 'get_announcements':
        $classId = intval(getRequestParam('class_id', 0));
        $page = max(1, intval(getRequestParam('page', 1)));
        $limit = min(50, max(1, intval(getRequestParam('limit', 10))));
        
        if (!$classId) {
            echo json_encode(['success' => false, 'message' => '缺少班级ID']);
            exit;
        }
        
        $result = getAnnouncements($conn, $classId, $page, $limit);
        echo json_encode(['success' => true, 'announcements' => $result['items'], 'pagination' => $result]);
        break;
        
    case 'create_announcement':
        $classId = intval(getRequestParam('class_id', 0));
        $title = trim(getRequestParam('title', ''));
        $content = trim(getRequestParam('content', ''));
        $isPinned = getRequestParam('is_pinned', '0') === '1';
        
        if (!$classId || !$title || !$content) {
            echo json_encode(['success' => false, 'message' => '缺少必要参数']);
            exit;
        }
        
        if (!isClassAdmin($conn, $classId, $userId)) {
            echo json_encode(['success' => false, 'message' => '只有班级管理员可以发布公告']);
            exit;
        }
        
        $result = createAnnouncement($conn, $classId, $userId, $title, $content, $isPinned);
        echo json_encode($result);
        break;
        
    case 'view_announcement':
        $announcementId = intval(getRequestParam('id', 0));
        
        $stmt = $conn->prepare("UPDATE class_announcements SET view_count = view_count + 1 WHERE id = ?");
        $stmt->bind_param("i", $announcementId);
        $stmt->execute();
        
        echo json_encode(['success' => true]);
        break;
        
    // ========== 活动 ==========
    case 'get_activities':
        $classId = intval(getRequestParam('class_id', 0));
        $page = max(1, intval(getRequestParam('page', 1)));
        $limit = min(50, max(1, intval(getRequestParam('limit', 10))));
        
        if (!$classId) {
            echo json_encode(['success' => false, 'message' => '缺少班级ID']);
            exit;
        }
        
        $result = getActivities($conn, $classId, $page, $limit);
        echo json_encode(['success' => true, 'activities' => $result['items'], 'pagination' => $result]);
        break;
        
    case 'create_activity':
        $classId = intval(getRequestParam('class_id', 0));
        $title = trim(getRequestParam('title', ''));
        $description = trim(getRequestParam('description', ''));
        $activityType = getRequestParam('activity_type', 'other');
        $startTime = getRequestParam('start_time');
        $endTime = getRequestParam('end_time');
        $location = trim(getRequestParam('location', ''));
        $maxParticipants = intval(getRequestParam('max_participants', 0));
        
        if (!$classId || !$title) {
            echo json_encode(['success' => false, 'message' => '缺少必要参数']);
            exit;
        }
        
        if (!isClassAdmin($conn, $classId, $userId)) {
            echo json_encode(['success' => false, 'message' => '只有班级管理员可以创建活动']);
            exit;
        }
        
        $result = createActivity($conn, $classId, $userId, $title, $description, $activityType, $startTime, $endTime, $location, $maxParticipants);
        echo json_encode($result);
        break;
        
    case 'join_activity':
        $activityId = intval(getRequestParam('activity_id', 0));
        $status = getRequestParam('status', 'going');
        
        if (!$activityId) {
            echo json_encode(['success' => false, 'message' => '缺少活动ID']);
            exit;
        }
        
        $result = joinActivity($conn, $activityId, $userId, $status);
        echo json_encode($result);
        break;
        
    case 'get_activity_participants':
        $activityId = intval(getRequestParam('activity_id', 0));
        
        if (!$activityId) {
            echo json_encode(['success' => false, 'message' => '缺少活动ID']);
            exit;
        }
        
        $participants = getActivityParticipants($conn, $activityId);
        echo json_encode(['success' => true, 'participants' => $participants]);
        break;
        
    // ========== 投票 ==========
    case 'get_polls':
        $classId = intval(getRequestParam('class_id', 0));
        $page = max(1, intval(getRequestParam('page', 1)));
        $limit = min(50, max(1, intval(getRequestParam('limit', 10))));
        
        if (!$classId) {
            echo json_encode(['success' => false, 'message' => '缺少班级ID']);
            exit;
        }
        
        $result = getPolls($conn, $classId, $page, $limit);
        
        // 添加用户投票状态
        foreach ($result['items'] as &$poll) {
            $poll['user_voted_options'] = getUserVoteOptions($conn, $poll['id'], $userId);
        }
        
        echo json_encode(['success' => true, 'polls' => $result['items'], 'pagination' => $result]);
        break;
        
    case 'create_poll':
        $classId = intval(getRequestParam('class_id', 0));
        $title = trim(getRequestParam('title', ''));
        $description = trim(getRequestParam('description', ''));
        $questionType = getRequestParam('question_type', 'single');
        $isAnonymous = getRequestParam('is_anonymous', '0') === '1';
        $endTime = getRequestParam('end_time');
        $optionsJson = getRequestParam('options', '[]');
        
        if (!$classId || !$title) {
            echo json_encode(['success' => false, 'message' => '缺少必要参数']);
            exit;
        }
        
        if (!isClassAdmin($conn, $classId, $userId)) {
            echo json_encode(['success' => false, 'message' => '只有班级管理员可以创建投票']);
            exit;
        }
        
        $options = json_decode($optionsJson, true);
        if (!is_array($options) || count($options) < 2) {
            echo json_encode(['success' => false, 'message' => '至少需要2个选项']);
            exit;
        }
        
        $result = createPoll($conn, $classId, $userId, $title, $description, $questionType, $isAnonymous, $endTime, $options);
        echo json_encode($result);
        break;
        
    case 'vote_poll':
        $pollId = intval(getRequestParam('poll_id', 0));
        $optionsJson = getRequestParam('options', '[]');
        
        if (!$pollId) {
            echo json_encode(['success' => false, 'message' => '缺少投票ID']);
            exit;
        }
        
        $optionIds = json_decode($optionsJson, true);
        if (!is_array($optionIds) || count($optionIds) < 1) {
            echo json_encode(['success' => false, 'message' => '请选择选项']);
            exit;
        }
        
        $result = votePoll($conn, $pollId, $userId, $optionIds);
        echo json_encode($result);
        break;
        
    case 'get_poll_detail':
        $pollId = intval(getRequestParam('poll_id', 0));
        
        if (!$pollId) {
            echo json_encode(['success' => false, 'message' => '缺少投票ID']);
            exit;
        }
        
        $stmt = $conn->prepare("SELECT p.*, u.name as author_name FROM class_polls p JOIN users u ON u.id = p.author_id WHERE p.id = ?");
        $stmt->bind_param("i", $pollId);
        $stmt->execute();
        $poll = $stmt->get_result()->fetch_assoc();
        
        if (!$poll) {
            echo json_encode(['success' => false, 'message' => '投票不存在']);
            exit;
        }
        
        // 获取选项
        $optionsStmt = $conn->prepare("SELECT * FROM class_poll_options WHERE poll_id = ? ORDER BY option_order");
        $optionsStmt->bind_param("i", $pollId);
        $optionsStmt->execute();
        $optionsResult = $optionsStmt->get_result();
        
        $options = [];
        while ($opt = $optionsResult->fetch_assoc()) {
            $voteCountStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM class_poll_votes WHERE option_id = ?");
            $voteCountStmt->bind_param("i", $opt['id']);
            $voteCountStmt->execute();
            $opt['vote_count'] = $voteCountStmt->get_result()->fetch_assoc()['cnt'];
            $options[] = $opt;
        }
        $poll['options'] = $options;
        $poll['user_voted_options'] = getUserVoteOptions($conn, $pollId, $userId);
        
        echo json_encode(['success' => true, 'poll' => $poll]);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => '无效的操作']);
}
?>
