<?php
require 'db.php';

function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

function getRequestParam($key, $default = null) {
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

function generateInviteCode() {
    return strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 8));
}

function getUserClassRole($conn, $userId, $classId) {
    $stmt = $conn->prepare("SELECT role FROM class_members WHERE class_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $classId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        return $result->fetch_assoc()['role'];
    }
    return null;
}

function isClassMember($conn, $userId, $classId) {
    return getUserClassRole($conn, $userId, $classId) !== null;
}

function isClassMonitor($conn, $userId, $classId) {
    $role = getUserClassRole($conn, $userId, $classId);
    return $role === 'monitor' || $role === 'vice_monitor';
}

function updateClassMemberCount($conn, $classId) {
    $countStmt = $conn->prepare("SELECT COUNT(*) as count FROM class_members WHERE class_id = ?");
    $countStmt->bind_param("i", $classId);
    $countStmt->execute();
    $count = $countStmt->get_result()->fetch_assoc()['count'];
    
    $updateStmt = $conn->prepare("UPDATE classes SET member_count = ? WHERE id = ?");
    $updateStmt->bind_param("ii", $count, $classId);
    $updateStmt->execute();
}

function createClass($conn, $userId, $name, $description, $isPrivate = 0) {
    if (empty($name)) {
        return ['success' => false, 'message' => '班级名称不能为空'];
    }
    
    if (strlen($name) > 100) {
        return ['success' => false, 'message' => '班级名称过长'];
    }
    
    $inviteCode = generateInviteCode();
    $attempts = 0;
    while ($attempts < 10) {
        $checkStmt = $conn->prepare("SELECT id FROM classes WHERE invite_code = ?");
        $checkStmt->bind_param("s", $inviteCode);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows === 0) {
            break;
        }
        $inviteCode = generateInviteCode();
        $attempts++;
    }
    
    $stmt = $conn->prepare("INSERT INTO classes (name, description, invite_code, created_by, is_private) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssii", $name, $description, $inviteCode, $userId, $isPrivate);
    
    if ($stmt->execute()) {
        $classId = $conn->insert_id;
        
        $memberStmt = $conn->prepare("INSERT INTO class_members (class_id, user_id, role) VALUES (?, ?, 'monitor')");
        $memberStmt->bind_param("ii", $classId, $userId);
        $memberStmt->execute();
        
        updateClassMemberCount($conn, $classId);
        
        return ['success' => true, 'class_id' => $classId, 'invite_code' => $inviteCode];
    }
    
    return ['success' => false, 'message' => '创建班级失败'];
}

function joinClassByCode($conn, $userId, $inviteCode) {
    if (empty($inviteCode)) {
        return ['success' => false, 'message' => '请输入邀请码'];
    }
    
    $stmt = $conn->prepare("SELECT id, name FROM classes WHERE invite_code = ?");
    $stmt->bind_param("s", $inviteCode);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return ['success' => false, 'message' => '邀请码无效'];
    }
    
    $class = $result->fetch_assoc();
    $classId = $class['id'];
    
    if (isClassMember($conn, $userId, $classId)) {
        return ['success' => false, 'message' => '你已经是该班级成员'];
    }
    
    $insertStmt = $conn->prepare("INSERT INTO class_members (class_id, user_id, role) VALUES (?, ?, 'member')");
    $insertStmt->bind_param("ii", $classId, $userId);
    
    if ($insertStmt->execute()) {
        updateClassMemberCount($conn, $classId);
        return ['success' => true, 'class_id' => $classId, 'class_name' => $class['name']];
    }
    
    return ['success' => false, 'message' => '加入失败'];
}

function leaveClass($conn, $userId, $classId) {
    $role = getUserClassRole($conn, $userId, $classId);
    if (!$role) {
        return ['success' => false, 'message' => '你不是该班级成员'];
    }
    
    if ($role === 'monitor') {
        $memberCountStmt = $conn->prepare("SELECT COUNT(*) as count FROM class_members WHERE class_id = ?");
        $memberCountStmt->bind_param("i", $classId);
        $memberCountStmt->execute();
        $count = $memberCountStmt->get_result()->fetch_assoc()['count'];
        if ($count <= 1) {
            return ['success' => false, 'message' => '班长不能退出班级，请先解散班级或转让班长'];
        }
    }
    
    $stmt = $conn->prepare("DELETE FROM class_members WHERE class_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $classId, $userId);
    
    if ($stmt->execute()) {
        updateClassMemberCount($conn, $classId);
        return ['success' => true];
    }
    
    return ['success' => false, 'message' => '退出失败'];
}

function getClassList($conn, $userId) {
    $stmt = $conn->prepare("
        SELECT c.*, cm.role
        FROM classes c
        JOIN class_members cm ON cm.class_id = c.id
        WHERE cm.user_id = ?
        ORDER BY c.created_at DESC
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $classes = [];
    while ($row = $result->fetch_assoc()) {
        $classes[] = $row;
    }
    
    return ['success' => true, 'classes' => $classes];
}

function getClassDetail($conn, $classId, $userId) {
    $stmt = $conn->prepare("SELECT * FROM classes WHERE id = ?");
    $stmt->bind_param("i", $classId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return ['success' => false, 'message' => '班级不存在'];
    }
    
    $class = $result->fetch_assoc();
    
    if ($class['is_private'] && !isClassMember($conn, $userId, $classId)) {
        return ['success' => false, 'message' => '该班级为私有，仅成员可查看'];
    }
    
    $isMember = isClassMember($conn, $userId, $classId);
    $class['my_role'] = $isMember ? getUserClassRole($conn, $userId, $classId) : null;
    $class['is_member'] = $isMember;
    
    if (!$isMember) {
        unset($class['invite_code']);
    }
    
    return ['success' => true, 'class' => $class];
}

function getClassMembers($conn, $classId, $userId) {
    if (!isClassMember($conn, $userId, $classId)) {
        return ['success' => false, 'message' => '仅班级成员可查看成员列表'];
    }
    
    $stmt = $conn->prepare("
        SELECT cm.*, u.name, u.username, u.avatar, u.class as user_class
        FROM class_members cm
        JOIN users u ON u.id = cm.user_id
        WHERE cm.class_id = ?
        ORDER BY 
            CASE cm.role 
                WHEN 'monitor' THEN 1 
                WHEN 'vice_monitor' THEN 2 
                ELSE 3 
            END,
            cm.joined_at ASC
    ");
    $stmt->bind_param("i", $classId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $members = [];
    while ($row = $result->fetch_assoc()) {
        $members[] = $row;
    }
    
    return ['success' => true, 'members' => $members];
}

function updateClassInfo($conn, $classId, $userId, $name, $description, $isPrivate) {
    if (!isClassMonitor($conn, $userId, $classId)) {
        return ['success' => false, 'message' => '仅班长可修改班级信息'];
    }
    
    if (empty($name)) {
        return ['success' => false, 'message' => '班级名称不能为空'];
    }
    
    $stmt = $conn->prepare("UPDATE classes SET name = ?, description = ?, is_private = ? WHERE id = ?");
    $stmt->bind_param("ssii", $name, $description, $isPrivate, $classId);
    
    if ($stmt->execute()) {
        return ['success' => true];
    }
    
    return ['success' => false, 'message' => '更新失败'];
}

function changeMemberRole($conn, $classId, $operatorId, $targetUserId, $newRole) {
    if (!isClassMonitor($conn, $operatorId, $classId)) {
        return ['success' => false, 'message' => '仅班长可修改成员角色'];
    }
    
    if (!in_array($newRole, ['monitor', 'vice_monitor', 'member'])) {
        return ['success' => false, 'message' => '无效的角色'];
    }
    
    $operatorRole = getUserClassRole($conn, $operatorId, $classId);
    $targetRole = getUserClassRole($conn, $targetUserId, $classId);
    
    if (!$targetRole) {
        return ['success' => false, 'message' => '目标用户不是班级成员'];
    }
    
    if ($newRole === 'monitor' && $operatorRole !== 'monitor') {
        return ['success' => false, 'message' => '仅班长可转让班长职位'];
    }
    
    $stmt = $conn->prepare("UPDATE class_members SET role = ? WHERE class_id = ? AND user_id = ?");
    $stmt->bind_param("sii", $newRole, $classId, $targetUserId);
    
    if ($stmt->execute()) {
        if ($newRole === 'monitor' && $operatorRole === 'monitor' && $operatorId != $targetUserId) {
            $downgradeStmt = $conn->prepare("UPDATE class_members SET role = 'vice_monitor' WHERE class_id = ? AND user_id = ?");
            $downgradeStmt->bind_param("ii", $classId, $operatorId);
            $downgradeStmt->execute();
        }
        return ['success' => true];
    }
    
    return ['success' => false, 'message' => '修改失败'];
}

function removeMember($conn, $classId, $operatorId, $targetUserId) {
    if (!isClassMonitor($conn, $operatorId, $classId)) {
        return ['success' => false, 'message' => '仅班长可移除成员'];
    }
    
    $targetRole = getUserClassRole($conn, $targetUserId, $classId);
    if (!$targetRole) {
        return ['success' => false, 'message' => '目标用户不是班级成员'];
    }
    
    if ($targetRole === 'monitor') {
        return ['success' => false, 'message' => '不能移除班长'];
    }
    
    $stmt = $conn->prepare("DELETE FROM class_members WHERE class_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $classId, $targetUserId);
    
    if ($stmt->execute()) {
        updateClassMemberCount($conn, $classId);
        return ['success' => true];
    }
    
    return ['success' => false, 'message' => '移除失败'];
}

function deleteClass($conn, $classId, $userId) {
    $role = getUserClassRole($conn, $userId, $classId);
    if ($role !== 'monitor') {
        return ['success' => false, 'message' => '仅班长可解散班级'];
    }
    
    $stmt = $conn->prepare("DELETE FROM classes WHERE id = ?");
    $stmt->bind_param("i", $classId);
    
    if ($stmt->execute()) {
        return ['success' => true];
    }
    
    return ['success' => false, 'message' => '解散失败'];
}

function getClassMemoirs($conn, $classId, $userId, $page = 1, $limit = 10) {
    if (!isClassMember($conn, $userId, $classId)) {
        return ['success' => false, 'message' => '仅班级成员可查看班级动态'];
    }
    
    $offset = ($page - 1) * $limit;
    
    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM memoirs WHERE class_id = ? AND is_class_post = 1");
    $countStmt->bind_param("i", $classId);
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['total'];
    
    $currentUserId = $userId ?? 0;
    
    $stmt = $conn->prepare("
        SELECT m.*, u.name as author_name, u.class as author_class, u.avatar as author_avatar, t.name as topic_name,
            (SELECT COUNT(*) FROM likes l WHERE l.memoir_id = m.id) as likes_count,
            (SELECT COUNT(*) FROM comments c WHERE c.memoir_id = m.id) as comments_count,
            (SELECT COUNT(*) FROM likes l2 WHERE l2.memoir_id = m.id AND l2.user_id = ?) as is_liked
        FROM memoirs m
        JOIN users u ON u.id = m.user_id
        LEFT JOIN topics t ON t.id = m.topic_id
        WHERE m.class_id = ? AND m.is_class_post = 1
        ORDER BY m.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param("iiii", $currentUserId, $classId, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $memoirs = [];
    while ($row = $result->fetch_assoc()) {
        $row['images'] = json_decode($row['images']) ?? [];
        $memoirs[] = $row;
    }
    
    return [
        'success' => true,
        'memoirs' => $memoirs,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => ceil($total / $limit)
        ]
    ];
}

function getClassAlbums($conn, $classId, $userId, $page = 1, $limit = 12) {
    if (!isClassMember($conn, $userId, $classId)) {
        return ['success' => false, 'message' => '仅班级成员可查看班级相册'];
    }
    
    $offset = ($page - 1) * $limit;
    
    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM albums WHERE class_id = ?");
    $countStmt->bind_param("i", $classId);
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['total'];
    
    $stmt = $conn->prepare("
        SELECT a.*, u.name as author_name,
            (SELECT COUNT(*) FROM photos WHERE album_id = a.id) as photo_count,
            (SELECT image_path FROM photos WHERE album_id = a.id ORDER BY created_at DESC LIMIT 1) as cover_image
        FROM albums a
        JOIN users u ON u.id = a.user_id
        WHERE a.class_id = ?
        ORDER BY a.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param("iii", $classId, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $albums = [];
    while ($row = $result->fetch_assoc()) {
        $albums[] = $row;
    }
    
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

$action = getRequestParam('action', '');
$userId = getCurrentUserId();

if (!$userId) {
    echo json_encode(['success' => false, 'message' => '请先登录']);
    exit;
}

switch ($action) {
    case 'my_classes':
        $result = getClassList($conn, $userId);
        echo json_encode($result);
        break;
        
    case 'create':
        $name = trim(getRequestParam('name', ''));
        $description = getRequestParam('description', '');
        $isPrivate = intval(getRequestParam('is_private', 0));
        $result = createClass($conn, $userId, $name, $description, $isPrivate);
        echo json_encode($result);
        break;
        
    case 'join':
        $inviteCode = strtoupper(trim(getRequestParam('invite_code', '')));
        $result = joinClassByCode($conn, $userId, $inviteCode);
        echo json_encode($result);
        break;
        
    case 'leave':
        $classId = intval(getRequestParam('class_id', 0));
        $result = leaveClass($conn, $userId, $classId);
        echo json_encode($result);
        break;
        
    case 'detail':
        $classId = intval(getRequestParam('class_id', 0));
        $result = getClassDetail($conn, $classId, $userId);
        echo json_encode($result);
        break;
        
    case 'members':
        $classId = intval(getRequestParam('class_id', 0));
        $result = getClassMembers($conn, $classId, $userId);
        echo json_encode($result);
        break;
        
    case 'update':
        $classId = intval(getRequestParam('class_id', 0));
        $name = trim(getRequestParam('name', ''));
        $description = getRequestParam('description', '');
        $isPrivate = intval(getRequestParam('is_private', 0));
        $result = updateClassInfo($conn, $classId, $userId, $name, $description, $isPrivate);
        echo json_encode($result);
        break;
        
    case 'change_role':
        $classId = intval(getRequestParam('class_id', 0));
        $targetUserId = intval(getRequestParam('user_id', 0));
        $newRole = getRequestParam('new_role', 'member');
        $result = changeMemberRole($conn, $classId, $userId, $targetUserId, $newRole);
        echo json_encode($result);
        break;
        
    case 'remove_member':
        $classId = intval(getRequestParam('class_id', 0));
        $targetUserId = intval(getRequestParam('user_id', 0));
        $result = removeMember($conn, $classId, $userId, $targetUserId);
        echo json_encode($result);
        break;
        
    case 'delete':
        $classId = intval(getRequestParam('class_id', 0));
        $result = deleteClass($conn, $classId, $userId);
        echo json_encode($result);
        break;
        
    case 'memoirs':
        $classId = intval(getRequestParam('class_id', 0));
        $page = intval(getRequestParam('page', 1));
        $limit = intval(getRequestParam('limit', 10));
        $result = getClassMemoirs($conn, $classId, $userId, $page, $limit);
        echo json_encode($result);
        break;
        
    case 'albums':
        $classId = intval(getRequestParam('class_id', 0));
        $page = intval(getRequestParam('page', 1));
        $limit = intval(getRequestParam('limit', 12));
        $result = getClassAlbums($conn, $classId, $userId, $page, $limit);
        echo json_encode($result);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => '无效的操作']);
}
?>
