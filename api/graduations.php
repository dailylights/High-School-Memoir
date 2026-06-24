<?php
require 'db.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

function getUserId() {
    return $_SESSION['user_id'] ?? null;
}

function isUserAdmin($conn, $userId) {
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

function updateGraduationClassCount($conn, $graduationId) {
    $countStmt = $conn->prepare("SELECT COUNT(*) as count FROM classes WHERE graduation_id = ?");
    $countStmt->bind_param("i", $graduationId);
    $countStmt->execute();
    $count = $countStmt->get_result()->fetch_assoc()['count'];
    
    $updateStmt = $conn->prepare("UPDATE graduations SET class_count = ? WHERE id = ?");
    $updateStmt->bind_param("ii", $count, $graduationId);
    $updateStmt->execute();
}

function createGraduation($conn, $userId, $year, $name, $description) {
    if (empty($year)) {
        return ['success' => false, 'message' => '届别年份不能为空'];
    }
    
    if (!is_numeric($year) || $year < 1990 || $year > 2100) {
        return ['success' => false, 'message' => '届别年份无效'];
    }
    
    if (empty($name)) {
        $name = $year . '届';
    }
    
    if (strlen($name) > 100) {
        return ['success' => false, 'message' => '届别名称过长'];
    }
    
    $checkStmt = $conn->prepare("SELECT id FROM graduations WHERE year = ?");
    $checkStmt->bind_param("i", $year);
    $checkStmt->execute();
    if ($checkStmt->get_result()->num_rows > 0) {
        return ['success' => false, 'message' => '该届别已存在'];
    }
    
    $stmt = $conn->prepare("INSERT INTO graduations (year, name, description, created_by) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("issi", $year, $name, $description, $userId);
    
    if ($stmt->execute()) {
        return ['success' => true, 'graduation_id' => $conn->insert_id];
    }
    
    return ['success' => false, 'message' => '创建届别失败'];
}

function getGraduationList($conn, $page = 1, $limit = 20) {
    $page = max(1, intval($page));
    $limit = max(1, min(100, intval($limit)));
    $offset = ($page - 1) * $limit;
    
    $countSql = "SELECT COUNT(*) as total FROM graduations";
    $totalResult = $conn->query($countSql);
    $total = $totalResult->fetch_assoc()['total'];
    $totalPages = ceil($total / $limit);
    
    $sql = "SELECT g.*, u.name as creator_name 
            FROM graduations g 
            JOIN users u ON g.created_by = u.id 
            ORDER BY g.year DESC 
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $graduations = [];
    while ($row = $result->fetch_assoc()) {
        $graduations[] = $row;
    }
    
    return [
        'success' => true,
        'graduations' => $graduations,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $totalPages
        ]
    ];
}

function getGraduationDetail($conn, $graduationId) {
    $graduationId = intval($graduationId);
    if (!$graduationId) {
        return ['success' => false, 'message' => '无效的届别ID'];
    }
    
    $stmt = $conn->prepare("SELECT g.*, u.name as creator_name 
                            FROM graduations g 
                            JOIN users u ON g.created_by = u.id 
                            WHERE g.id = ?");
    $stmt->bind_param("i", $graduationId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return ['success' => false, 'message' => '届别不存在'];
    }
    
    $graduation = $result->fetch_assoc();
    
    $classStmt = $conn->prepare("SELECT c.*, 
        (SELECT COUNT(*) FROM class_members cm WHERE cm.class_id = c.id) as member_count
        FROM classes c 
        WHERE c.graduation_id = ? 
        ORDER BY c.created_at DESC");
    $classStmt->bind_param("i", $graduationId);
    $classStmt->execute();
    $classes = [];
    $classResult = $classStmt->get_result();
    while ($row = $classResult->fetch_assoc()) {
        $classes[] = $row;
    }
    
    $graduation['classes'] = $classes;
    
    return ['success' => true, 'graduation' => $graduation];
}

function updateGraduation($conn, $userId, $graduationId, $name, $description) {
    $graduationId = intval($graduationId);
    if (!$graduationId) {
        return ['success' => false, 'message' => '无效的届别ID'];
    }
    
    $checkStmt = $conn->prepare("SELECT created_by FROM graduations WHERE id = ?");
    $checkStmt->bind_param("i", $graduationId);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows === 0) {
        return ['success' => false, 'message' => '届别不存在'];
    }
    
    $graduation = $result->fetch_assoc();
    if ($graduation['created_by'] != $userId) {
        return ['success' => false, 'message' => '只有创建者可以修改届别'];
    }
    
    if (empty($name)) {
        return ['success' => false, 'message' => '届别名称不能为空'];
    }
    
    if (strlen($name) > 100) {
        return ['success' => false, 'message' => '届别名称过长'];
    }
    
    $stmt = $conn->prepare("UPDATE graduations SET name = ?, description = ? WHERE id = ?");
    $stmt->bind_param("ssi", $name, $description, $graduationId);
    
    if ($stmt->execute()) {
        return ['success' => true, 'message' => '更新成功'];
    }
    
    return ['success' => false, 'message' => '更新失败'];
}

function deleteGraduation($conn, $userId, $graduationId) {
    $graduationId = intval($graduationId);
    if (!$graduationId) {
        return ['success' => false, 'message' => '无效的届别ID'];
    }
    
    $checkStmt = $conn->prepare("SELECT created_by, class_count FROM graduations WHERE id = ?");
    $checkStmt->bind_param("i", $graduationId);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows === 0) {
        return ['success' => false, 'message' => '届别不存在'];
    }
    
    $graduation = $result->fetch_assoc();
    if ($graduation['created_by'] != $userId) {
        return ['success' => false, 'message' => '只有创建者可以删除届别'];
    }
    
    if ($graduation['class_count'] > 0) {
        $updateStmt = $conn->prepare("UPDATE classes SET graduation_id = NULL WHERE graduation_id = ?");
        $updateStmt->bind_param("i", $graduationId);
        $updateStmt->execute();
    }
    
    $stmt = $conn->prepare("DELETE FROM graduations WHERE id = ? AND created_by = ?");
    $stmt->bind_param("ii", $graduationId, $userId);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        return ['success' => true, 'message' => '删除成功'];
    }
    
    return ['success' => false, 'message' => '删除失败'];
}

function setClassGraduation($conn, $userId, $classId, $graduationId) {
    $classId = intval($classId);
    $graduationId = intval($graduationId);
    
    if (!$classId) {
        return ['success' => false, 'message' => '无效的班级ID'];
    }
    
    $classStmt = $conn->prepare("SELECT created_by FROM classes WHERE id = ?");
    $classStmt->bind_param("i", $classId);
    $classStmt->execute();
    $classResult = $classStmt->get_result();
    
    if ($classResult->num_rows === 0) {
        return ['success' => false, 'message' => '班级不存在'];
    }
    
    $class = $classResult->fetch_assoc();
    
    $memberStmt = $conn->prepare("SELECT role FROM class_members WHERE class_id = ? AND user_id = ?");
    $memberStmt->bind_param("ii", $classId, $userId);
    $memberStmt->execute();
    $memberResult = $memberStmt->get_result();
    
    $isMonitor = false;
    if ($memberResult->num_rows > 0) {
        $role = $memberResult->fetch_assoc()['role'];
        $isMonitor = ($role === 'monitor' || $role === 'vice_monitor');
    }
    
    if (!$isMonitor && $class['created_by'] != $userId) {
        return ['success' => false, 'message' => '只有班长可以设置届别'];
    }
    
    if ($graduationId > 0) {
        $gradStmt = $conn->prepare("SELECT id FROM graduations WHERE id = ?");
        $gradStmt->bind_param("i", $graduationId);
        $gradStmt->execute();
        if ($gradStmt->get_result()->num_rows === 0) {
            return ['success' => false, 'message' => '届别不存在'];
        }
    }
    
    $oldGradStmt = $conn->prepare("SELECT graduation_id FROM classes WHERE id = ?");
    $oldGradStmt->bind_param("i", $classId);
    $oldGradStmt->execute();
    $oldGradId = $oldGradStmt->get_result()->fetch_assoc()['graduation_id'];
    
    if ($graduationId > 0) {
        $stmt = $conn->prepare("UPDATE classes SET graduation_id = ? WHERE id = ?");
        $stmt->bind_param("ii", $graduationId, $classId);
    } else {
        $stmt = $conn->prepare("UPDATE classes SET graduation_id = NULL WHERE id = ?");
        $stmt->bind_param("i", $classId);
    }
    
    if ($stmt->execute()) {
        if ($oldGradId) {
            updateGraduationClassCount($conn, $oldGradId);
        }
        if ($graduationId > 0) {
            updateGraduationClassCount($conn, $graduationId);
        }
        return ['success' => true, 'message' => '设置成功'];
    }
    
    return ['success' => false, 'message' => '设置失败'];
}

if ($action == 'list') {
    $page = intval($_GET['page'] ?? 1);
    $limit = intval($_GET['limit'] ?? 20);
    $result = getGraduationList($conn, $page, $limit);
    echo json_encode($result);

} elseif ($action == 'detail') {
    $graduationId = intval($_GET['id'] ?? 0);
    $result = getGraduationDetail($conn, $graduationId);
    echo json_encode($result);

} elseif ($action == 'create') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => '请先登录']);
        exit;
    }
    
    $userId = $_SESSION['user_id'];
    if (!isUserAdmin($conn, $userId)) {
        echo json_encode(['success' => false, 'message' => '仅管理员可创建届别']);
        exit;
    }
    
    $year = intval($_POST['year'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    $result = createGraduation($conn, $userId, $year, $name, $description);
    echo json_encode($result);

} elseif ($action == 'update') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => '请先登录']);
        exit;
    }
    
    $userId = $_SESSION['user_id'];
    if (!isUserAdmin($conn, $userId)) {
        echo json_encode(['success' => false, 'message' => '仅管理员可修改届别']);
        exit;
    }
    
    $graduationId = intval($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    $result = updateGraduation($conn, $userId, $graduationId, $name, $description);
    echo json_encode($result);

} elseif ($action == 'delete') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => '请先登录']);
        exit;
    }
    
    $userId = $_SESSION['user_id'];
    if (!isUserAdmin($conn, $userId)) {
        echo json_encode(['success' => false, 'message' => '仅管理员可删除届别']);
        exit;
    }
    
    $graduationId = intval($_POST['id'] ?? 0);
    
    $result = deleteGraduation($conn, $userId, $graduationId);
    echo json_encode($result);

} elseif ($action == 'set_class_graduation') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => '请先登录']);
        exit;
    }
    
    $userId = $_SESSION['user_id'];
    $classId = intval($_POST['class_id'] ?? 0);
    $graduationId = intval($_POST['graduation_id'] ?? 0);
    
    $result = setClassGraduation($conn, $userId, $classId, $graduationId);
    echo json_encode($result);

} else {
    echo json_encode(['success' => false, 'message' => '无效的操作: ' . $action]);
}
?>
