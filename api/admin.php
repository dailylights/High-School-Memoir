<?php
require 'db.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

function checkSessionTimeout() {
    $timeout = getConfig('session_timeout', 120) * 60;
    
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
        session_unset();
        session_destroy();
        return false;
    }
    $_SESSION['last_activity'] = time();
    return true;
}

function isAdmin() {
    if (!isset($_SESSION['user_id'])) return false;
    if (!checkSessionTimeout()) return false;
    global $conn;
    $userId = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        return $result->fetch_assoc()['is_admin'] == 1;
    }
    return false;
}

function hasAdmin() {
    global $conn;
    $result = $conn->query("SELECT COUNT(*) as count FROM users WHERE is_admin = 1");
    return $result->fetch_assoc()['count'] > 0;
}

function isInstalled() {
    global $conn;
    $stmt = $conn->prepare("SELECT config_value FROM site_config WHERE config_key = 'site_installed'");
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        return $result->fetch_assoc()['config_value'] == '1';
    }
    return false;
}

function getConfig($key, $default = null) {
    global $conn;
    $stmt = $conn->prepare("SELECT config_value, config_type FROM site_config WHERE config_key = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $val = $row['config_value'];
        switch ($row['config_type']) {
            case 'number': return intval($val);
            case 'boolean': return $val == '1';
            case 'json': return json_decode($val, true);
            default: return $val;
        }
    }
    return $default;
}

function setConfig($key, $value, $type = 'string', $description = '') {
    global $conn;
    if ($type === 'boolean') {
        $value = $value ? '1' : '0';
    } elseif ($type === 'json') {
        $value = json_encode($value, JSON_UNESCAPED_UNICODE);
    } else {
        $value = strval($value);
    }
    
    $checkStmt = $conn->prepare("SELECT id FROM site_config WHERE config_key = ?");
    $checkStmt->bind_param("s", $key);
    $checkStmt->execute();
    
    if ($checkStmt->get_result()->num_rows > 0) {
        $stmt = $conn->prepare("UPDATE site_config SET config_value = ?, config_type = ? WHERE config_key = ?");
        $stmt->bind_param("sss", $value, $type, $key);
    } else {
        $stmt = $conn->prepare("INSERT INTO site_config (config_key, config_value, config_type, description) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $key, $value, $type, $description);
    }
    return $stmt->execute();
}

function getClientIp() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

function checkLoginAttempts($ip, $username = null) {
    global $conn;
    $maxAttempts = getConfig('login_max_attempts', 5);
    $lockoutTime = getConfig('login_lockout_time', 15) * 60;
    $timeWindow = getConfig('login_attempt_window', 30) * 60;
    
    $since = date('Y-m-d H:i:s', time() - $timeWindow);
    $ipStmt = $conn->prepare("SELECT COUNT(*) as count FROM login_attempts WHERE ip_address = ? AND success = 0 AND attempt_time > ?");
    $ipStmt->bind_param("ss", $ip, $since);
    $ipStmt->execute();
    $ipCount = $ipStmt->get_result()->fetch_assoc()['count'];
    
    if ($ipCount >= $maxAttempts) {
        $latestStmt = $conn->prepare("SELECT MAX(attempt_time) as last FROM login_attempts WHERE ip_address = ? AND success = 0");
        $latestStmt->bind_param("s", $ip);
        $latestStmt->execute();
        $lastAttempt = strtotime($latestStmt->get_result()->fetch_assoc()['last']);
        $remaining = $lockoutTime - (time() - $lastAttempt);
        if ($remaining > 0) {
            return ['locked' => true, 'remaining' => ceil($remaining / 60)];
        }
    }
    
    return ['locked' => false];
}

function recordLoginAttempt($ip, $username, $success) {
    global $conn;
    $stmt = $conn->prepare("INSERT INTO login_attempts (ip_address, username, success) VALUES (?, ?, ?)");
    $successVal = $success ? 1 : 0;
    $stmt->bind_param("ssi", $ip, $username, $successVal);
    $stmt->execute();
}

if ($action == 'check_install') {
    $installed = isInstalled();
    $hasAdminUser = hasAdmin();
    echo json_encode([
        'success' => true,
        'installed' => $installed,
        'has_admin' => $hasAdminUser
    ]);

} elseif ($action == 'install') {
    if (isInstalled()) {
        echo json_encode(['success' => false, 'message' => '系统已安装']);
        exit;
    }
    
    $adminUsername = trim($_POST['admin_username'] ?? '');
    $adminPassword = $_POST['admin_password'] ?? '';
    $adminName = trim($_POST['admin_name'] ?? '管理员');
    $siteName = trim($_POST['site_name'] ?? '高中回忆录');
    $siteDescription = trim($_POST['site_description'] ?? '');
    $allowRegister = isset($_POST['allow_register']) ? 1 : 0;
    $loginMaxAttempts = intval($_POST['login_max_attempts'] ?? 5);
    $loginLockoutTime = intval($_POST['login_lockout_time'] ?? 15);
    $sessionTimeout = intval($_POST['session_timeout'] ?? 120);
    
    if (empty($adminUsername) || empty($adminPassword)) {
        echo json_encode(['success' => false, 'message' => '管理员账号和密码不能为空']);
        exit;
    }
    
    if (strlen($adminPassword) < 6) {
        echo json_encode(['success' => false, 'message' => '管理员密码至少6位']);
        exit;
    }
    
    $conn->begin_transaction();
    
    try {
        $checkStmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $checkStmt->bind_param("s", $adminUsername);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
            throw new Exception('该用户名已存在');
        }
        
        $hashedPassword = password_hash($adminPassword, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (username, password, name, class, phone, is_admin) VALUES (?, ?, ?, '管理员组', '00000000000', 1)");
        $stmt->bind_param("sss", $adminUsername, $hashedPassword, $adminName);
        $stmt->execute();
        
        setConfig('site_installed', '1', 'boolean', '系统是否已安装');
        setConfig('site_name', $siteName, 'string', '站点名称');
        setConfig('site_description', $siteDescription, 'string', '站点描述');
        setConfig('allow_register', $allowRegister, 'boolean', '是否开放用户注册');
        setConfig('login_max_attempts', $loginMaxAttempts, 'number', '最大登录失败次数');
        setConfig('login_lockout_time', $loginLockoutTime, 'number', '登录锁定时长（分钟）');
        setConfig('login_attempt_window', 30, 'number', '登录尝试统计窗口（分钟）');
        setConfig('session_timeout', $sessionTimeout, 'number', '会话超时时间（分钟）');
        setConfig('max_upload_size', 10, 'number', '最大上传文件大小（MB）');
        setConfig('enable_comments', 1, 'boolean', '是否开启评论功能');
        setConfig('enable_likes', 1, 'boolean', '是否开启点赞功能');
        setConfig('memoir_need_approval', 0, 'boolean', '回忆录发布是否需要审核');
        
        $conn->commit();
        echo json_encode(['success' => true, 'message' => '安装成功！']);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }

} elseif ($action == 'admin_login') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $ip = getClientIp();
    
    $checkResult = checkLoginAttempts($ip, $username);
    if ($checkResult['locked']) {
        echo json_encode(['success' => false, 'message' => "登录失败次数过多，请 {$checkResult['remaining']} 分钟后再试"]);
        exit;
    }
    
    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'message' => '请输入用户名和密码']);
        exit;
    }

    $stmt = $conn->prepare("SELECT id, password, name, is_admin FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        
        if ($user['is_admin'] != 1) {
            recordLoginAttempt($ip, $username, false);
            echo json_encode(['success' => false, 'message' => '该账户没有管理员权限']);
            exit;
        }
        
        if (password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['is_admin'] = true;
            $_SESSION['last_activity'] = time();
            recordLoginAttempt($ip, $username, true);
            echo json_encode(['success' => true, 'message' => '登录成功', 'user' => ['id' => $user['id'], 'name' => $user['name']]]);
        } else {
            recordLoginAttempt($ip, $username, false);
            echo json_encode(['success' => false, 'message' => '密码错误']);
        }
    } else {
        recordLoginAttempt($ip, $username, false);
        echo json_encode(['success' => false, 'message' => '用户不存在']);
    }

} elseif ($action == 'get_config') {
    if (!isAdmin()) {
        echo json_encode(['success' => false, 'message' => '需要管理员权限']);
        exit;
    }
    
    $configs = [];
    $result = $conn->query("SELECT * FROM site_config ORDER BY id");
    while ($row = $result->fetch_assoc()) {
        $val = $row['config_value'];
        switch ($row['config_type']) {
            case 'number': $val = intval($val); break;
            case 'boolean': $val = ($val == '1'); break;
            case 'json': $val = json_decode($val, true); break;
        }
        $configs[$row['config_key']] = [
            'value' => $val,
            'type' => $row['config_type'],
            'description' => $row['description']
        ];
    }
    
    echo json_encode(['success' => true, 'configs' => $configs]);

} elseif ($action == 'update_config') {
    if (!isAdmin()) {
        echo json_encode(['success' => false, 'message' => '需要管理员权限']);
        exit;
    }
    
    $configs = $_POST['configs'] ?? [];
    if (!is_array($configs)) {
        $configs = json_decode($configs, true) ?? [];
    }
    
    $conn->begin_transaction();
    try {
        foreach ($configs as $key => $item) {
            $value = $item['value'] ?? '';
            $type = $item['type'] ?? 'string';
            $desc = $item['description'] ?? '';
            setConfig($key, $value, $type, $desc);
        }
        $conn->commit();
        echo json_encode(['success' => true, 'message' => '配置已更新']);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => '更新失败: ' . $e->getMessage()]);
    }

} elseif ($action == 'change_admin_password') {
    if (!isAdmin()) {
        echo json_encode(['success' => false, 'message' => '需要管理员权限']);
        exit;
    }
    
    $oldPassword = $_POST['old_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $userId = $_SESSION['user_id'];
    
    if (empty($oldPassword) || empty($newPassword)) {
        echo json_encode(['success' => false, 'message' => '请填写完整信息']);
        exit;
    }
    
    if (strlen($newPassword) < 6) {
        echo json_encode(['success' => false, 'message' => '新密码至少6位']);
        exit;
    }
    
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if (password_verify($oldPassword, $user['password'])) {
            $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
            $updateStmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $updateStmt->bind_param("si", $hashed, $userId);
            if ($updateStmt->execute()) {
                echo json_encode(['success' => true, 'message' => '密码修改成功']);
            } else {
                echo json_encode(['success' => false, 'message' => '修改失败']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => '原密码错误']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => '用户不存在']);
    }

} elseif ($action == 'change_admin_username') {
    if (!isAdmin()) {
        echo json_encode(['success' => false, 'message' => '需要管理员权限']);
        exit;
    }
    
    $newUsername = trim($_POST['new_username'] ?? '');
    $password = $_POST['password'] ?? '';
    $userId = $_SESSION['user_id'];
    
    if (empty($newUsername) || empty($password)) {
        echo json_encode(['success' => false, 'message' => '请填写完整信息']);
        exit;
    }
    
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            $checkStmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $checkStmt->bind_param("si", $newUsername, $userId);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                echo json_encode(['success' => false, 'message' => '该用户名已被使用']);
                exit;
            }
            
            $updateStmt = $conn->prepare("UPDATE users SET username = ? WHERE id = ?");
            $updateStmt->bind_param("si", $newUsername, $userId);
            if ($updateStmt->execute()) {
                echo json_encode(['success' => true, 'message' => '用户名修改成功']);
            } else {
                echo json_encode(['success' => false, 'message' => '修改失败']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => '密码错误']);
        }
    }

} elseif ($action == 'get_stats') {
    if (!isAdmin()) {
        echo json_encode(['success' => false, 'message' => '需要管理员权限']);
        exit;
    }
    
    $stats = [];
    
    $userCount = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
    $stats['total_users'] = intval($userCount);
    
    $memoirCount = $conn->query("SELECT COUNT(*) as count FROM memoirs")->fetch_assoc()['count'];
    $stats['total_memoirs'] = intval($memoirCount);
    
    $classCount = $conn->query("SELECT COUNT(*) as count FROM classes")->fetch_assoc()['count'];
    $stats['total_classes'] = intval($classCount);
    
    $graduationCount = $conn->query("SELECT COUNT(*) as count FROM graduations")->fetch_assoc()['count'];
    $stats['total_graduations'] = intval($graduationCount);
    
    $commentCount = $conn->query("SELECT COUNT(*) as count FROM comments")->fetch_assoc()['count'];
    $stats['total_comments'] = intval($commentCount);
    
    $albumCount = $conn->query("SELECT COUNT(*) as count FROM albums")->fetch_assoc()['count'];
    $stats['total_albums'] = intval($albumCount);
    
    $photoCount = $conn->query("SELECT COUNT(*) as count FROM photos")->fetch_assoc()['count'];
    $stats['total_photos'] = intval($photoCount);
    
    $today = date('Y-m-d 00:00:00');
    $tomorrow = date('Y-m-d 00:00:00', strtotime('+1 day'));
    
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE created_at >= ? AND created_at < ?");
    $stmt->bind_param("ss", $today, $tomorrow);
    $stmt->execute();
    $todayUsers = $stmt->get_result()->fetch_assoc()['count'];
    $stats['today_new_users'] = intval($todayUsers);
    
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM memoirs WHERE created_at >= ? AND created_at < ?");
    $stmt->bind_param("ss", $today, $tomorrow);
    $stmt->execute();
    $todayMemoirs = $stmt->get_result()->fetch_assoc()['count'];
    $stats['today_new_memoirs'] = intval($todayMemoirs);
    
    echo json_encode(['success' => true, 'stats' => $stats]);

} elseif ($action == 'get_users') {
    if (!isAdmin()) {
        echo json_encode(['success' => false, 'message' => '需要管理员权限']);
        exit;
    }
    
    $page = intval($_GET['page'] ?? 1);
    $limit = intval($_GET['limit'] ?? 20);
    $page = max(1, $page);
    $limit = max(1, min(100, $limit));
    $offset = ($page - 1) * $limit;
    
    $total = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
    
    $stmt = $conn->prepare("SELECT id, username, name, class, phone, avatar, is_admin, created_at FROM users ORDER BY id DESC LIMIT ? OFFSET ?");
    $stmt->bind_param("ii", $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'users' => $users,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => intval($total),
            'total_pages' => ceil($total / $limit)
        ]
    ]);

} elseif ($action == 'delete_user') {
    if (!isAdmin()) {
        echo json_encode(['success' => false, 'message' => '需要管理员权限']);
        exit;
    }
    
    $userId = intval($_POST['user_id'] ?? 0);
    if (!$userId) {
        echo json_encode(['success' => false, 'message' => '无效的用户ID']);
        exit;
    }
    
    if ($userId == $_SESSION['user_id']) {
        echo json_encode(['success' => false, 'message' => '不能删除自己']);
        exit;
    }
    
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => '用户已删除']);
    } else {
        echo json_encode(['success' => false, 'message' => '删除失败: ' . $conn->error]);
    }

} elseif ($action == 'toggle_admin') {
    if (!isAdmin()) {
        echo json_encode(['success' => false, 'message' => '需要管理员权限']);
        exit;
    }
    
    $userId = intval($_POST['user_id'] ?? 0);
    $isAdmin = intval($_POST['is_admin'] ?? 0);
    
    if (!$userId) {
        echo json_encode(['success' => false, 'message' => '无效的用户ID']);
        exit;
    }
    
    if ($userId == $_SESSION['user_id']) {
        echo json_encode(['success' => false, 'message' => '不能修改自己的管理员状态']);
        exit;
    }
    
    $stmt = $conn->prepare("UPDATE users SET is_admin = ? WHERE id = ?");
    $stmt->bind_param("ii", $isAdmin, $userId);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => '已更新']);
    } else {
        echo json_encode(['success' => false, 'message' => '操作失败']);
    }

} else {
    echo json_encode(['success' => false, 'message' => '无效的操作: ' . $action]);
}
?>
