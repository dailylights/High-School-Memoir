<?php
require 'db.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

$skipCsrfActions = ['check_installed'];
if (!in_array($action, $skipCsrfActions)) {
    csrfProtection();
}

function getSiteConfig($key, $default = null) {
    global $conn;
    $stmt = $conn->prepare("SELECT config_value, config_type FROM site_config WHERE config_key = ?");
    if (!$stmt) return $default;
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

function recordLoginAttempt($ip, $username, $success) {
    global $conn;
    $stmt = $conn->prepare("INSERT INTO login_attempts (ip_address, username, success) VALUES (?, ?, ?)");
    $successInt = $success ? 1 : 0;
    $stmt->bind_param("ssi", $ip, $username, $successInt);
    $stmt->execute();
}

function checkLoginAttempts($ip, $username) {
    global $conn;
    $maxAttempts = getSiteConfig('login_max_attempts', 5);
    $lockoutTime = getSiteConfig('login_lockout_time', 15) * 60;
    
    $windowStart = date('Y-m-d H:i:s', time() - $lockoutTime);
    
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM login_attempts WHERE ip_address = ? AND username = ? AND success = 0 AND attempt_time > ?");
    $stmt->bind_param("sss", $ip, $username, $windowStart);
    $stmt->execute();
    $result = $stmt->get_result();
    $count = $result->fetch_assoc()['count'];
    
    return $count >= $maxAttempts;
}

function getClientIp() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

function validatePasswordStrength($password) {
    if (strlen($password) < 8) {
        return ['valid' => false, 'message' => '密码长度不能少于8位'];
    }
    if (strlen($password) > 50) {
        return ['valid' => false, 'message' => '密码长度不能超过50位'];
    }
    if (!preg_match('/[A-Za-z]/', $password)) {
        return ['valid' => false, 'message' => '密码必须包含至少一个字母'];
    }
    if (!preg_match('/[0-9]/', $password)) {
        return ['valid' => false, 'message' => '密码必须包含至少一个数字'];
    }
    return ['valid' => true];
}

function validateUsername($username) {
    if (strlen($username) < 3) {
        return ['valid' => false, 'message' => '用户名长度不能少于3位'];
    }
    if (strlen($username) > 20) {
        return ['valid' => false, 'message' => '用户名长度不能超过20位'];
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        return ['valid' => false, 'message' => '用户名只能包含字母、数字和下划线'];
    }
    return ['valid' => true];
}

if ($action == 'register') {
    $allowRegister = getSiteConfig('allow_register', true);
    if (!$allowRegister) {
        echo json_encode(["success" => false, "message" => "当前系统未开放注册"]);
        exit;
    }

    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $class = trim($_POST['class'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if (empty($name) || empty($username) || empty($password) || empty($class) || empty($phone)) {
        echo json_encode(["success" => false, "message" => "请填写所有字段"]);
        exit;
    }
    
    $usernameCheck = validateUsername($username);
    if (!$usernameCheck['valid']) {
        echo json_encode(["success" => false, "message" => $usernameCheck['message']]);
        exit;
    }
    
    $passwordCheck = validatePasswordStrength($password);
    if (!$passwordCheck['valid']) {
        echo json_encode(["success" => false, "message" => $passwordCheck['message']]);
        exit;
    }
    
    if (strlen($name) > 50) {
        echo json_encode(["success" => false, "message" => "姓名过长"]);
        exit;
    }
    
    if (strlen($class) > 100) {
        echo json_encode(["success" => false, "message" => "班级名称过长"]);
        exit;
    }
    
    if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
        echo json_encode(["success" => false, "message" => "请输入有效的手机号"]);
        exit;
    }

    // Check if username exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        echo json_encode(["success" => false, "message" => "用户名已存在"]);
        exit;
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (name, username, password, class, phone) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $name, $username, $hashed_password, $class, $phone);

    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "注册成功"]);
    } else {
        echo json_encode(["success" => false, "message" => "注册失败，用户名可能已存在"]);
    }
} elseif ($action == 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $ip = getClientIp();

    if (empty($username) || empty($password)) {
        echo json_encode(["success" => false, "message" => "请输入用户名和密码"]);
        exit;
    }

    if (checkLoginAttempts($ip, $username)) {
        $lockoutTime = getSiteConfig('login_lockout_time', 15);
        echo json_encode(["success" => false, "message" => "登录失败次数过多，请{$lockoutTime}分钟后再试"]);
        exit;
    }

    $stmt = $conn->prepare("SELECT id, password, name, class, phone, is_admin FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    $loginSuccess = false;
    $userData = null;
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            $loginSuccess = true;
            $userData = $user;
        }
    }
    
    if ($loginSuccess) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userData['id'];
        $_SESSION['user_name'] = $userData['name'];
        $_SESSION['last_activity'] = time();
        recordLoginAttempt($ip, $username, true);
        unset($userData['password']);
        echo json_encode(["success" => true, "message" => "登录成功", "user" => $userData]);
    } else {
        recordLoginAttempt($ip, $username, false);
        echo json_encode(["success" => false, "message" => "用户名或密码错误"]);
    }
} elseif ($action == 'logout') {
    session_destroy();
    echo json_encode(["success" => true, "message" => "已退出登录"]);
} elseif ($action == 'check_session') {
    $timeout = getSiteConfig('session_timeout', 120) * 60;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
        session_unset();
        session_destroy();
        echo json_encode(["success" => false, "message" => "会话已过期"]);
        exit;
    }
    $_SESSION['last_activity'] = time();

    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        $stmt = $conn->prepare("SELECT id, name, username, class, phone, avatar, is_admin FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows > 0) {
            echo json_encode(["success" => true, "user" => $res->fetch_assoc(), "csrf_token" => getCSRFToken()]);
        } else {
            session_destroy();
            echo json_encode(["success" => false, "csrf_token" => getCSRFToken()]);
        }
    } else {
        echo json_encode(["success" => false, "csrf_token" => getCSRFToken()]);
    }

} elseif ($action == 'get_public_config') {
    $configs = [];
    $publicKeys = ['site_name', 'site_description', 'allow_register'];
    foreach ($publicKeys as $key) {
        $configs[$key] = getSiteConfig($key);
    }
    $installed = false;
    $result = $conn->query("SELECT config_value FROM site_config WHERE config_key = 'site_installed'");
    if ($result && $result->num_rows > 0) {
        $installed = $result->fetch_assoc()['config_value'] == '1';
    }
    $configs['site_installed'] = $installed;
    echo json_encode(["success" => true, "configs" => $configs, "csrf_token" => getCSRFToken()]);
} elseif ($action == 'recover') {
    $username = trim($_POST['username'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $new_password = $_POST['new_password'] ?? '';

    if (empty($username) || empty($phone) || empty($new_password)) {
        echo json_encode(["success" => false, "message" => "请填写完整信息"]);
        exit;
    }
    
    $passwordCheck = validatePasswordStrength($new_password);
    if (!$passwordCheck['valid']) {
        echo json_encode(["success" => false, "message" => $passwordCheck['message']]);
        exit;
    }

    $ip = getClientIp();
    if (checkLoginAttempts($ip, $username)) {
        $lockoutTime = getSiteConfig('login_lockout_time', 15);
        echo json_encode(["success" => false, "message" => "尝试次数过多，请{$lockoutTime}分钟后再试"]);
        exit;
    }

    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND phone = ?");
    $stmt->bind_param("ss", $username, $phone);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $update = $conn->prepare("UPDATE users SET password = ? WHERE username = ?");
        $update->bind_param("ss", $hashed, $username);
        if ($update->execute()) {
            recordLoginAttempt($ip, $username, true);
            echo json_encode(["success" => true, "message" => "密码重置成功，请登录"]);
        } else {
            echo json_encode(["success" => false, "message" => "重置失败"]);
        }
    } else {
        recordLoginAttempt($ip, $username, false);
        echo json_encode(["success" => false, "message" => "账号或手机号不匹配"]);
    }
} elseif ($action == 'update_profile') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(["success" => false, "message" => "未登录"]);
        exit;
    }

    $user_id = $_SESSION['user_id'];
    $name = trim($_POST['name'] ?? '');
    
    $query = "UPDATE users SET ";
    $params = [];
    $types = "";
    $oldAvatar = null;
    
    $stmt = $conn->prepare("SELECT avatar FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $oldUser = $stmt->get_result()->fetch_assoc();
    if ($oldUser && !empty($oldUser['avatar'])) {
        $oldAvatar = $oldUser['avatar'];
    }
    
    if (!empty($name)) {
        if (strlen($name) > 50) {
            echo json_encode(["success" => false, "message" => "姓名过长"]);
            exit;
        }
        $query .= "name = ?, ";
        $params[] = $name;
        $types .= "s";
    }
    
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['avatar'];
        $maxSize = 2 * 1024 * 1024;
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        
        if ($file['size'] > $maxSize) {
            echo json_encode(["success" => false, "message" => "头像大小不能超过2MB"]);
            exit;
        }
        
        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($fileExt, $allowedExts)) {
            echo json_encode(["success" => false, "message" => "仅支持 JPG, PNG, GIF, WebP 格式"]);
            exit;
        }
        
        $imageInfo = @getimagesize($file['tmp_name']);
        if (!$imageInfo || !in_array($imageInfo['mime'], $allowedTypes)) {
            echo json_encode(["success" => false, "message" => "不是有效的图片文件"]);
            exit;
        }
        
        $target_dir = "../uploads/avatars/";
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0755, true);
        }
        
        $new_filename = "avatar_" . $user_id . "_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $fileExt;
        $target_file = $target_dir . $new_filename;
        
        if (move_uploaded_file($file['tmp_name'], $target_file)) {
            @chmod($target_file, 0644);
            $db_path = "uploads/avatars/" . $new_filename;
            $query .= "avatar = ?, ";
            $params[] = $db_path;
            $types .= "s";
            
            if ($oldAvatar && file_exists("../" . $oldAvatar)) {
                @unlink("../" . $oldAvatar);
            }
        } else {
            echo json_encode(["success" => false, "message" => "头像上传失败"]);
            exit;
        }
    }
    
    $query = rtrim($query, ", ");
    
    if (empty($params)) {
        echo json_encode(["success" => false, "message" => "没有需要更新的信息"]);
        exit;
    }
    
    $query .= " WHERE id = ?";
    $params[] = $user_id;
    $types .= "i";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    
    if ($stmt->execute()) {
        if (!empty($name)) {
            $_SESSION['user_name'] = $name;
        }
        
        $stmt = $conn->prepare("SELECT id, name, username, class, phone, avatar, is_admin FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        
        echo json_encode(["success" => true, "message" => "个人资料已更新", "user" => $user]);
    } else {
        echo json_encode(["success" => false, "message" => "更新失败"]);
    }

} elseif ($action == 'change_password') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(["success" => false, "message" => "未登录"]);
        exit;
    }
    
    $user_id = $_SESSION['user_id'];
    $old_password = $_POST['old_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    
    if (empty($old_password) || empty($new_password)) {
        echo json_encode(["success" => false, "message" => "请填写完整信息"]);
        exit;
    }
    
    $passwordCheck = validatePasswordStrength($new_password);
    if (!$passwordCheck['valid']) {
        echo json_encode(["success" => false, "message" => $passwordCheck['message']]);
        exit;
    }
    
    if ($old_password === $new_password) {
        echo json_encode(["success" => false, "message" => "新密码不能与旧密码相同"]);
        exit;
    }
    
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(["success" => false, "message" => "用户不存在"]);
        exit;
    }
    
    $user = $result->fetch_assoc();
    if (!password_verify($old_password, $user['password'])) {
        echo json_encode(["success" => false, "message" => "原密码错误"]);
        exit;
    }
    
    $hashed = password_hash($new_password, PASSWORD_DEFAULT);
    $update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $update->bind_param("si", $hashed, $user_id);
    
    if ($update->execute()) {
        echo json_encode(["success" => true, "message" => "密码修改成功"]);
    } else {
        echo json_encode(["success" => false, "message" => "密码修改失败"]);
    }

} else {
    echo json_encode(["success" => false, "message" => "Invalid action"]);
}
?>
