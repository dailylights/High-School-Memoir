<?php
require 'db.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

csrfProtection();

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

if ($action == 'list') {
    $result = $conn->query("SELECT * FROM topics ORDER BY created_at DESC");
    $topics = [];
    while ($row = $result->fetch_assoc()) {
        $topics[] = $row;
    }
    echo json_encode(["success" => true, "topics" => $topics]);

} elseif ($action == 'ranking') {
    $sql = "
        SELECT t.*, COUNT(m.id) as usage_count
        FROM topics t
        LEFT JOIN memoirs m ON m.topic_id = t.id
        GROUP BY t.id
        ORDER BY usage_count DESC, t.name ASC
        LIMIT 8
    ";
    $result = $conn->query($sql);
    $topics = [];
    while ($row = $result->fetch_assoc()) {
        $topics[] = $row;
    }
    echo json_encode(["success" => true, "topics" => $topics]);

} elseif ($action == 'create') {
    $userId = getCurrentUserId();
    if (!$userId || !isAdmin($conn, $userId)) {
        echo json_encode(["success" => false, "message" => "仅管理员可创建话题"]);
        exit;
    }
    
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    if (empty($name)) {
        echo json_encode(["success" => false, "message" => "话题名称不能为空"]);
        exit;
    }
    
    if (strlen($name) > 50) {
        echo json_encode(["success" => false, "message" => "话题名称过长"]);
        exit;
    }
    
    if (strlen($description) > 500) {
        echo json_encode(["success" => false, "message" => "话题描述过长"]);
        exit;
    }
    
    $checkStmt = $conn->prepare("SELECT id FROM topics WHERE name = ?");
    $checkStmt->bind_param("s", $name);
    $checkStmt->execute();
    if ($checkStmt->get_result()->num_rows > 0) {
        echo json_encode(["success" => false, "message" => "话题已存在"]);
        exit;
    }
    
    $stmt = $conn->prepare("INSERT INTO topics (name, description, created_by) VALUES (?, ?, ?)");
    $stmt->bind_param("ssi", $name, $description, $userId);
    
    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "创建成功", "topic_id" => $conn->insert_id]);
    } else {
        echo json_encode(["success" => false, "message" => "创建失败"]);
    }

} elseif ($action == 'update') {
    $userId = getCurrentUserId();
    if (!$userId || !isAdmin($conn, $userId)) {
        echo json_encode(["success" => false, "message" => "仅管理员可修改话题"]);
        exit;
    }
    
    $id = intval($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    if ($id <= 0) {
        echo json_encode(["success" => false, "message" => "无效的话题ID"]);
        exit;
    }
    
    if (empty($name)) {
        echo json_encode(["success" => false, "message" => "话题名称不能为空"]);
        exit;
    }
    
    $checkStmt = $conn->prepare("SELECT id FROM topics WHERE id = ?");
    $checkStmt->bind_param("i", $id);
    $checkStmt->execute();
    if ($checkStmt->get_result()->num_rows === 0) {
        echo json_encode(["success" => false, "message" => "话题不存在"]);
        exit;
    }
    
    $stmt = $conn->prepare("UPDATE topics SET name = ?, description = ? WHERE id = ?");
    $stmt->bind_param("ssi", $name, $description, $id);
    
    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "更新成功"]);
    } else {
        echo json_encode(["success" => false, "message" => "更新失败"]);
    }

} elseif ($action == 'delete') {
    $userId = getCurrentUserId();
    if (!$userId || !isAdmin($conn, $userId)) {
        echo json_encode(["success" => false, "message" => "仅管理员可删除话题"]);
        exit;
    }
    
    $id = intval($_POST['id'] ?? 0);
    
    if ($id <= 0) {
        echo json_encode(["success" => false, "message" => "无效的话题ID"]);
        exit;
    }
    
    $checkStmt = $conn->prepare("SELECT id FROM topics WHERE id = ?");
    $checkStmt->bind_param("i", $id);
    $checkStmt->execute();
    if ($checkStmt->get_result()->num_rows === 0) {
        echo json_encode(["success" => false, "message" => "话题不存在"]);
        exit;
    }
    
    $memoirCheck = $conn->prepare("SELECT COUNT(*) as count FROM memoirs WHERE topic_id = ?");
    $memoirCheck->bind_param("i", $id);
    $memoirCheck->execute();
    $memoirCount = $memoirCheck->get_result()->fetch_assoc()['count'];
    if ($memoirCount > 0) {
        echo json_encode(["success" => false, "message" => "该话题下有 {$memoirCount} 篇回忆录，无法删除"]);
        exit;
    }
    
    $stmt = $conn->prepare("DELETE FROM topics WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "删除成功"]);
    } else {
        echo json_encode(["success" => false, "message" => "删除失败"]);
    }

} else {
    echo json_encode(["success" => false, "message" => "Invalid action"]);
}
?>
