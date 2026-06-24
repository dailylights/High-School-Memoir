<?php
require 'db.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// CSRF protection for POST requests
csrfProtection();

// 获取当前登录用户ID
function getCurrentUserId() {
    return isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
}

// 验证用户是否存在
function userExists($conn, $userId) {
    $stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->num_rows > 0;
}

// 关注用户
function followUser($conn, $followerId, $followingId, $isSpecial = false) {
    // 不能关注自己
    if ($followerId == $followingId) {
        return ['success' => false, 'message' => '不能关注自己'];
    }
    
    // 验证用户存在
    if (!userExists($conn, $followingId)) {
        return ['success' => false, 'message' => '用户不存在'];
    }
    
    // 检查是否已关注
    $checkStmt = $conn->prepare("SELECT id, is_special FROM follows WHERE follower_id = ? AND following_id = ?");
    $checkStmt->bind_param("ii", $followerId, $followingId);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // 已关注，更新特别关注状态
        if ($row['is_special'] == $isSpecial) {
            return ['success' => true, 'message' => '已关注', 'is_following' => true, 'is_special' => (bool)$row['is_special']];
        }
        
        $updateStmt = $conn->prepare("UPDATE follows SET is_special = ? WHERE follower_id = ? AND following_id = ?");
        $updateStmt->bind_param("iii", $isSpecial, $followerId, $followingId);
        $updateStmt->execute();
        return ['success' => true, 'message' => $isSpecial ? '已设为特别关注' : '已更新关注状态', 'is_following' => true, 'is_special' => $isSpecial];
    }
    
    // 创建新关注
    $insertStmt = $conn->prepare("INSERT INTO follows (follower_id, following_id, is_special) VALUES (?, ?, ?)");
    $insertStmt->bind_param("iii", $followerId, $followingId, $isSpecial);
    $insertStmt->execute();
    
    return ['success' => true, 'message' => $isSpecial ? '已特别关注' : '已关注', 'is_following' => true, 'is_special' => $isSpecial];
}

// 取消关注
function unfollowUser($conn, $followerId, $followingId) {
    $stmt = $conn->prepare("DELETE FROM follows WHERE follower_id = ? AND following_id = ?");
    $stmt->bind_param("ii", $followerId, $followingId);
    $stmt->execute();
    
    return ['success' => $stmt->affected_rows > 0, 'message' => $stmt->affected_rows > 0 ? '已取消关注' : '未关注该用户'];
}

// 获取关注状态
function getFollowStatus($conn, $followerId, $followingId) {
    $stmt = $conn->prepare("SELECT is_special FROM follows WHERE follower_id = ? AND following_id = ?");
    $stmt->bind_param("ii", $followerId, $followingId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return ['is_following' => true, 'is_special' => (bool)$row['is_special']];
    }
    return ['is_following' => false, 'is_special' => false];
}

// 获取用户的关注列表
function getFollowingList($conn, $userId, $page = 1, $limit = 20, $specialOnly = false) {
    $offset = ($page - 1) * $limit;
    
    $whereClause = "f.follower_id = ?";
    if ($specialOnly) {
        $whereClause .= " AND f.is_special = 1";
    }
    
    // 统计总数
    $countSql = "SELECT COUNT(*) as total FROM follows f WHERE $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->bind_param("i", $userId);
    $countStmt->execute();
    $totalResult = $countStmt->get_result();
    $totalRows = $totalResult->fetch_assoc()['total'];
    $totalPages = ceil($totalRows / $limit);
    
    // 获取数据
    $sql = "SELECT u.id, u.name, u.username, u.class, u.avatar, u.created_at,
                   f.is_special, f.created_at as followed_at,
                   (SELECT COUNT(*) FROM memoirs m WHERE m.user_id = u.id) as memoir_count,
                   (SELECT COUNT(*) FROM follows f2 WHERE f2.following_id = u.id) as follower_count,
                   (SELECT COUNT(*) FROM follows f3 WHERE f3.follower_id = u.id) as following_count
            FROM follows f
            JOIN users u ON f.following_id = u.id
            WHERE $whereClause
            ORDER BY f.is_special DESC, f.created_at DESC
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $userId, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    
    return [
        'items' => $users,
        'total' => $totalRows,
        'page' => $page,
        'limit' => $limit,
        'total_pages' => $totalPages
    ];
}

// 获取用户的粉丝列表
function getFollowersList($conn, $userId, $page = 1, $limit = 20) {
    $offset = ($page - 1) * $limit;
    
    // 统计总数
    $countSql = "SELECT COUNT(*) as total FROM follows WHERE following_id = ?";
    $countStmt = $conn->prepare($countSql);
    $countStmt->bind_param("i", $userId);
    $countStmt->execute();
    $totalResult = $countStmt->get_result();
    $totalRows = $totalResult->fetch_assoc()['total'];
    $totalPages = ceil($totalRows / $limit);
    
    // 获取数据
    $sql = "SELECT u.id, u.name, u.username, u.class, u.avatar, u.created_at,
                   f.is_special, f.created_at as followed_at,
                   (SELECT COUNT(*) FROM memoirs m WHERE m.user_id = u.id) as memoir_count,
                   (SELECT COUNT(*) FROM follows f2 WHERE f2.following_id = u.id) as follower_count,
                   (SELECT COUNT(*) FROM follows f3 WHERE f3.follower_id = u.id) as following_count,
                   (SELECT is_special FROM follows WHERE follower_id = ? AND following_id = u.id) as i_follow_him
            FROM follows f
            JOIN users u ON f.follower_id = u.id
            WHERE f.following_id = ?
            ORDER BY f.is_special DESC, f.created_at DESC
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiii", $userId, $userId, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $row['i_follow_him'] = $row['i_follow_him'] !== null;
        $users[] = $row;
    }
    
    return [
        'items' => $users,
        'total' => $totalRows,
        'page' => $page,
        'limit' => $limit,
        'total_pages' => $totalPages
    ];
}

// 获取用户统计
function getUserStats($conn, $userId) {
    $stmt = $conn->prepare("SELECT 
        (SELECT COUNT(*) FROM follows WHERE follower_id = ?) as following_count,
        (SELECT COUNT(*) FROM follows WHERE following_id = ?) as follower_count,
        (SELECT COUNT(*) FROM follows WHERE follower_id = ? AND is_special = 1) as special_count
    ");
    $stmt->bind_param("iii", $userId, $userId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// 路由处理
$userId = getCurrentUserId();

switch ($action) {
    case 'follow':
        // POST: 关注用户
        if (!$userId) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            break;
        }
        
        $targetId = intval($_POST['user_id'] ?? 0);
        $isSpecial = isset($_POST['is_special']) ? intval($_POST['is_special']) : 0;
        
        if ($targetId <= 0) {
            echo json_encode(['success' => false, 'message' => '无效的用户ID']);
            break;
        }
        
        $result = followUser($conn, $userId, $targetId, $isSpecial);
        echo json_encode($result);
        break;
        
    case 'unfollow':
        // POST: 取消关注
        if (!$userId) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            break;
        }
        
        $targetId = intval($_POST['user_id'] ?? 0);
        
        if ($targetId <= 0) {
            echo json_encode(['success' => false, 'message' => '无效的用户ID']);
            break;
        }
        
        $result = unfollowUser($conn, $userId, $targetId);
        echo json_encode($result);
        break;
        
    case 'toggle_special':
        // POST: 切换特别关注状态
        if (!$userId) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            break;
        }
        
        $targetId = intval($_POST['user_id'] ?? 0);
        
        if ($targetId <= 0) {
            echo json_encode(['success' => false, 'message' => '无效的用户ID']);
            break;
        }
        
        // 检查当前状态
        $status = getFollowStatus($conn, $userId, $targetId);
        if (!$status['is_following']) {
            // 未关注，先关注并设为特别关注
            $result = followUser($conn, $userId, $targetId, true);
        } else {
            // 已关注，切换特别关注状态
            $result = followUser($conn, $userId, $targetId, !$status['is_special']);
        }
        echo json_encode($result);
        break;
        
    case 'get_status':
        // GET: 获取关注状态
        $targetId = intval($_GET['user_id'] ?? 0);
        
        if ($targetId <= 0) {
            echo json_encode(['success' => false, 'message' => '无效的用户ID']);
            break;
        }
        
        $status = getFollowStatus($conn, $userId ?? 0, $targetId);
        echo json_encode(['success' => true, 'is_following' => $status['is_following'], 'is_special' => $status['is_special']]);
        break;
        
    case 'get_following':
        // GET: 获取关注列表
        $targetId = intval($_GET['user_id'] ?? 0);
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
        $specialOnly = isset($_GET['special_only']) && $_GET['special_only'] == '1';
        
        if ($targetId <= 0) {
            echo json_encode(['success' => false, 'message' => '无效的用户ID']);
            break;
        }
        
        $result = getFollowingList($conn, $targetId, $page, $limit, $specialOnly);
        echo json_encode([
            'success' => true,
            'type' => 'following',
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $result['page'],
            'limit' => $result['limit'],
            'total_pages' => $result['total_pages']
        ]);
        break;
        
    case 'get_followers':
        // GET: 获取粉丝列表
        $targetId = intval($_GET['user_id'] ?? 0);
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
        
        if ($targetId <= 0) {
            echo json_encode(['success' => false, 'message' => '无效的用户ID']);
            break;
        }
        
        $result = getFollowersList($conn, $targetId, $page, $limit);
        echo json_encode([
            'success' => true,
            'type' => 'followers',
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $result['page'],
            'limit' => $result['limit'],
            'total_pages' => $result['total_pages']
        ]);
        break;
        
    case 'get_stats':
        // GET: 获取用户统计
        $targetId = intval($_GET['user_id'] ?? 0);
        
        if ($targetId <= 0) {
            echo json_encode(['success' => false, 'message' => '无效的用户ID']);
            break;
        }
        
        $stats = getUserStats($conn, $targetId);
        echo json_encode([
            'success' => true,
            'following_count' => $stats['following_count'],
            'follower_count' => $stats['follower_count'],
            'special_count' => $stats['special_count']
        ]);
        break;
        
    case 'get_following_ids':
        // GET: 获取当前用户关注的所有用户ID（用于动态筛选）
        if (!$userId) {
            echo json_encode(['success' => true, 'ids' => [], 'special_ids' => []]);
            break;
        }
        
        $stmt = $conn->prepare("SELECT following_id, is_special FROM follows WHERE follower_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $ids = [];
        $specialIds = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = $row['following_id'];
            if ($row['is_special']) {
                $specialIds[] = $row['following_id'];
            }
        }
        
        echo json_encode(['success' => true, 'ids' => $ids, 'special_ids' => $specialIds]);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => '未知的操作']);
}
?>
