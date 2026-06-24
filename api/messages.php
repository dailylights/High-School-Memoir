<?php
require 'db.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "message" => "请先登录"]);
    exit;
}

$current_user_id = $_SESSION['user_id'];

if ($action == 'send') {
    $receiver_id = intval($_POST['receiver_id'] ?? 0);
    $content = trim($_POST['content'] ?? '');

    if ($receiver_id <= 0) {
        echo json_encode(["success" => false, "message" => "接收者无效"]);
        exit;
    }

    if ($receiver_id == $current_user_id) {
        echo json_encode(["success" => false, "message" => "不能给自己发消息"]);
        exit;
    }

    if (empty($content)) {
        echo json_encode(["success" => false, "message" => "消息内容不能为空"]);
        exit;
    }

    if (mb_strlen($content) > 2000) {
        echo json_encode(["success" => false, "message" => "消息内容不能超过2000字"]);
        exit;
    }

    $stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->bind_param("i", $receiver_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows == 0) {
        echo json_encode(["success" => false, "message" => "接收用户不存在"]);
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, content) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $current_user_id, $receiver_id, $content);

    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "发送成功", "message_id" => $stmt->insert_id]);
    } else {
        echo json_encode(["success" => false, "message" => "发送失败: " . $conn->error]);
    }

} elseif ($action == 'get_conversations') {
    $sql = "
        SELECT 
            u.id as user_id,
            u.name as user_name,
            u.avatar as user_avatar,
            u.class as user_class,
            m.content as last_message,
            m.created_at as last_time,
            (SELECT COUNT(*) FROM messages m2 
             WHERE m2.receiver_id = ? AND m2.sender_id = u.id AND m2.is_read = 0) as unread_count
        FROM users u
        INNER JOIN messages m ON 
            (m.sender_id = u.id AND m.receiver_id = ?) OR 
            (m.receiver_id = u.id AND m.sender_id = ?)
        WHERE m.id = (
            SELECT MAX(m3.id) FROM messages m3
            WHERE (m3.sender_id = u.id AND m3.receiver_id = ?) OR 
                  (m3.receiver_id = u.id AND m3.sender_id = ?)
        )
        GROUP BY u.id
        ORDER BY m.created_at DESC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiiii", $current_user_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $conversations = [];
    while ($row = $result->fetch_assoc()) {
        $conversations[] = $row;
    }

    echo json_encode(["success" => true, "conversations" => $conversations]);

} elseif ($action == 'get_messages') {
    $other_user_id = intval($_GET['user_id'] ?? 0);
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(50, max(1, intval($_GET['limit']))) : 20;
    $offset = ($page - 1) * $limit;

    if ($other_user_id <= 0) {
        echo json_encode(["success" => false, "message" => "用户ID无效"]);
        exit;
    }

    $count_sql = "SELECT COUNT(*) as total FROM messages 
                  WHERE (sender_id = ? AND receiver_id = ?) OR 
                        (sender_id = ? AND receiver_id = ?)";
    $stmt = $conn->prepare($count_sql);
    $stmt->bind_param("iiii", $current_user_id, $other_user_id, $other_user_id, $current_user_id);
    $stmt->execute();
    $total = $stmt->get_result()->fetch_assoc()['total'];
    $total_pages = ceil($total / $limit);

    $sql = "SELECT * FROM messages 
            WHERE (sender_id = ? AND receiver_id = ?) OR 
                  (sender_id = ? AND receiver_id = ?)
            ORDER BY created_at DESC 
            LIMIT ? OFFSET ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiiiii", $current_user_id, $other_user_id, $other_user_id, $current_user_id, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();

    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $messages[] = $row;
    }

    $messages = array_reverse($messages);

    $update_sql = "UPDATE messages SET is_read = 1 
                   WHERE sender_id = ? AND receiver_id = ? AND is_read = 0";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("ii", $other_user_id, $current_user_id);
    $update_stmt->execute();

    echo json_encode([
        "success" => true,
        "messages" => $messages,
        "pagination" => [
            "page" => $page,
            "limit" => $limit,
            "total" => $total,
            "total_pages" => $total_pages
        ]
    ]);

} elseif ($action == 'get_unread_count') {
    $sql = "SELECT COUNT(*) as unread_count FROM messages 
            WHERE receiver_id = ? AND is_read = 0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    echo json_encode(["success" => true, "unread_count" => intval($row['unread_count'])]);

} elseif ($action == 'mark_as_read') {
    $sender_id = intval($_POST['sender_id'] ?? 0);

    if ($sender_id <= 0) {
        echo json_encode(["success" => false, "message" => "参数无效"]);
        exit;
    }

    $sql = "UPDATE messages SET is_read = 1 
            WHERE sender_id = ? AND receiver_id = ? AND is_read = 0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $sender_id, $current_user_id);

    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "已标记为已读"]);
    } else {
        echo json_encode(["success" => false, "message" => "操作失败"]);
    }

} elseif ($action == 'search_users') {
    $keyword = trim($_GET['keyword'] ?? '');

    if (empty($keyword)) {
        echo json_encode(["success" => false, "message" => "请输入搜索关键词"]);
        exit;
    }

    if (mb_strlen($keyword) < 1) {
        echo json_encode(["success" => false, "message" => "搜索关键词太短"]);
        exit;
    }

    $search_term = "%" . $keyword . "%";
    $sql = "SELECT id, name, username, class, avatar FROM users 
            WHERE id != ? AND (name LIKE ? OR username LIKE ? OR class LIKE ?)
            LIMIT 10";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isss", $current_user_id, $search_term, $search_term, $search_term);
    $stmt->execute();
    $result = $stmt->get_result();

    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }

    echo json_encode(["success" => true, "users" => $users]);

} else {
    echo json_encode(["success" => false, "message" => "无效的操作"]);
}
?>
