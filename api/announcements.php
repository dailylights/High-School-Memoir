<?php
require 'db.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

function isAdmin($conn, $userId) {
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

if ($action == 'get_latest') {
    $result = $conn->query("SELECT id, title, content, created_at FROM announcements ORDER BY created_at DESC LIMIT 2");
    $announcements = [];
    while ($row = $result->fetch_assoc()) {
        $announcements[] = $row;
    }
    echo json_encode(["success" => true, "announcements" => $announcements]);

} elseif ($action == 'get_all') {
    $result = $conn->query("SELECT id, title, content, created_at, updated_at FROM announcements ORDER BY created_at DESC");
    $announcements = [];
    while ($row = $result->fetch_assoc()) {
        $announcements[] = $row;
    }
    echo json_encode(["success" => true, "announcements" => $announcements]);

} elseif ($action == 'create') {
    $userId = getCurrentUserId();
    if (!$userId || !isAdmin($conn, $userId)) {
        echo json_encode(["success" => false, "message" => "仅管理员可发布公告"]);
        exit;
    }
    
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    
    if (empty($title) || empty($content)) {
        echo json_encode(["success" => false, "message" => "标题和内容不能为空"]);
        exit;
    }
    
    if (strlen($title) > 200) {
        echo json_encode(["success" => false, "message" => "标题过长"]);
        exit;
    }
    
    $stmt = $conn->prepare("INSERT INTO announcements (title, content, created_by) VALUES (?, ?, ?)");
    $stmt->bind_param("ssi", $title, $content, $userId);
    
    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "发布成功", "id" => $conn->insert_id]);
    } else {
        echo json_encode(["success" => false, "message" => "发布失败"]);
    }

} elseif ($action == 'update') {
    $userId = getCurrentUserId();
    if (!$userId || !isAdmin($conn, $userId)) {
        echo json_encode(["success" => false, "message" => "仅管理员可修改公告"]);
        exit;
    }
    
    $id = intval($_POST['id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    
    if ($id <= 0) {
        echo json_encode(["success" => false, "message" => "无效的公告ID"]);
        exit;
    }
    
    if (empty($title) || empty($content)) {
        echo json_encode(["success" => false, "message" => "标题和内容不能为空"]);
        exit;
    }
    
    $checkStmt = $conn->prepare("SELECT id FROM announcements WHERE id = ?");
    $checkStmt->bind_param("i", $id);
    $checkStmt->execute();
    if ($checkStmt->get_result()->num_rows === 0) {
        echo json_encode(["success" => false, "message" => "公告不存在"]);
        exit;
    }
    
    $stmt = $conn->prepare("UPDATE announcements SET title = ?, content = ? WHERE id = ?");
    $stmt->bind_param("ssi", $title, $content, $id);
    
    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "更新成功"]);
    } else {
        echo json_encode(["success" => false, "message" => "更新失败"]);
    }

} elseif ($action == 'delete') {
    $userId = getCurrentUserId();
    if (!$userId || !isAdmin($conn, $userId)) {
        echo json_encode(["success" => false, "message" => "仅管理员可删除公告"]);
        exit;
    }
    
    $id = intval($_POST['id'] ?? 0);
    
    if ($id <= 0) {
        echo json_encode(["success" => false, "message" => "无效的公告ID"]);
        exit;
    }
    
    $stmt = $conn->prepare("DELETE FROM announcements WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "删除成功"]);
    } else {
        echo json_encode(["success" => false, "message" => "删除失败"]);
    }

} else {
    echo json_encode(["success" => false, "message" => "无效的操作"]);
}
?>
