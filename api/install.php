<?php
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=UTF-8');

$lockFile = __DIR__ . '/install.lock';
$configFile = __DIR__ . '/config.php';

function sendResponse($success, $message, $extra = []) {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

function validateDbName($name) {
    return preg_match('/^[a-zA-Z0-9_]+$/', $name) && strlen($name) <= 64;
}

function validateDbUser($user) {
    return preg_match('/^[a-zA-Z0-9_]+$/', $user) && strlen($user) <= 32;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action == 'check_status') {
    $installed = file_exists($lockFile);
    $configExists = file_exists($configFile);
    sendResponse(true, '', [
        'installed' => $installed,
        'config_exists' => $configExists
    ]);
}

if (file_exists($lockFile)) {
    sendResponse(false, '系统已安装，如需重新安装请删除 api/install.lock 文件');
}

if ($action == 'test_db') {
    $host = trim($_POST['db_host'] ?? '127.0.0.1');
    $port = intval($_POST['db_port'] ?? 3306);
    $user = trim($_POST['db_user'] ?? 'root');
    $pass = $_POST['db_pass'] ?? '';
    $name = trim($_POST['db_name'] ?? 'high_school_memoir');

    if (empty($host) || empty($user) || empty($name)) {
        sendResponse(false, '请填写完整的数据库信息');
    }

    if (!validateDbName($name)) {
        sendResponse(false, '数据库名称只能包含字母、数字和下划线，长度不超过64位');
    }

    if (!validateDbUser($user)) {
        sendResponse(false, '数据库用户名格式不正确');
    }

    if (strlen($host) > 255) {
        sendResponse(false, '数据库地址过长');
    }

    $port = $port > 0 ? $port : 3306;

    try {
        $conn = @new mysqli($host, $user, $pass, '', $port);
    } catch (mysqli_sql_exception $e) {
        sendResponse(false, '数据库连接失败：' . $e->getMessage());
    }

    if ($conn->connect_error) {
        sendResponse(false, '数据库连接失败：' . $conn->connect_error);
    }

    $conn->set_charset('utf8mb4');

    $dbExists = $conn->select_db($name);
    if (!$dbExists) {
        $safeName = $conn->real_escape_string($name);
        if ($conn->query("CREATE DATABASE IF NOT EXISTS `$safeName` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
            $conn->select_db($name);
            $dbExists = true;
        }
    }

    if (!$dbExists) {
        sendResponse(false, '数据库不存在且无法创建，请手动创建');
    }

    $conn->close();
    sendResponse(true, '数据库连接成功');
}

if ($action == 'install') {
    $host = trim($_POST['db_host'] ?? '127.0.0.1');
    $port = intval($_POST['db_port'] ?? 3306);
    $dbuser = trim($_POST['db_user'] ?? 'root');
    $dbpass = $_POST['db_pass'] ?? '';
    $dbname = trim($_POST['db_name'] ?? 'high_school_memoir');
    $adminUsername = trim($_POST['admin_username'] ?? '');
    $adminPassword = $_POST['admin_password'] ?? '';
    $adminName = trim($_POST['admin_name'] ?? '管理员');
    $siteName = trim($_POST['site_name'] ?? '高中回忆录');
    $siteDescription = trim($_POST['site_description'] ?? '');
    $allowRegister = isset($_POST['allow_register']) ? 1 : 0;
    $loginMaxAttempts = intval($_POST['login_max_attempts'] ?? 5);
    $loginLockoutTime = intval($_POST['login_lockout_time'] ?? 15);
    $sessionTimeout = intval($_POST['session_timeout'] ?? 120);

    if (empty($host) || empty($dbuser) || empty($dbname)) {
        sendResponse(false, '请填写完整的数据库信息');
    }
    if (!validateDbName($dbname)) {
        sendResponse(false, '数据库名称只能包含字母、数字和下划线，长度不超过64位');
    }
    if (!validateDbUser($dbuser)) {
        sendResponse(false, '数据库用户名格式不正确');
    }
    if (strlen($host) > 255) {
        sendResponse(false, '数据库地址过长');
    }
    if (empty($adminUsername) || empty($adminPassword)) {
        sendResponse(false, '管理员账号和密码不能为空');
    }
    if (strlen($adminPassword) < 6) {
        sendResponse(false, '管理员密码至少6位');
    }

    $port = $port > 0 ? $port : 3306;

    try {
        $conn = @new mysqli($host, $dbuser, $dbpass, '', $port);
    } catch (mysqli_sql_exception $e) {
        sendResponse(false, '数据库连接失败：' . $e->getMessage());
    }

    if ($conn->connect_error) {
        sendResponse(false, '数据库连接失败：' . $conn->connect_error);
    }

    $conn->set_charset('utf8mb4');

    if (!$conn->select_db($dbname)) {
        $safeDbName = $conn->real_escape_string($dbname);
        if (!$conn->query("CREATE DATABASE IF NOT EXISTS `$safeDbName` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
            sendResponse(false, '数据库创建失败：' . $conn->error);
        }
        $conn->select_db($dbname);
    }

    $sqlFile = __DIR__ . '/../database.sql';
    if (!file_exists($sqlFile)) {
        sendResponse(false, '数据库初始化文件不存在');
    }

    $sql = file_get_contents($sqlFile);
    $sql = preg_replace('/--.*$/m', '', $sql);
    $sql = preg_replace('/^DROP DATABASE[^;]*;/m', '', $sql);
    $sql = preg_replace('/^CREATE DATABASE[^;]*;/m', '', $sql);
    $sql = preg_replace('/^USE[^;]*;/m', '', $sql);

    $statements = array_filter(array_map('trim', explode(';', $sql)));
    $conn->begin_transaction();

    try {
        foreach ($statements as $stmt) {
            if (!empty($stmt)) {
                if (!$conn->query($stmt)) {
                    throw new Exception('SQL执行失败: ' . $conn->error);
                }
            }
        }

        $checkStmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $checkStmt->bind_param("s", $adminUsername);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
            throw new Exception('该用户名已存在');
        }

        $hashedPassword = password_hash($adminPassword, PASSWORD_DEFAULT);
        $insertStmt = $conn->prepare("INSERT INTO users (username, password, name, class, phone, is_admin) VALUES (?, ?, ?, '管理员组', '00000000000', 1)");
        $insertStmt->bind_param("sss", $adminUsername, $hashedPassword, $adminName);
        $insertStmt->execute();

        $configs = [
            ['site_installed', '1', 'boolean', '系统是否已安装'],
            ['site_name', $siteName, 'string', '站点名称'],
            ['site_description', $siteDescription, 'string', '站点描述'],
            ['allow_register', $allowRegister, 'boolean', '是否开放用户注册'],
            ['login_max_attempts', $loginMaxAttempts, 'number', '最大登录失败次数'],
            ['login_lockout_time', $loginLockoutTime, 'number', '登录锁定时长（分钟）'],
            ['login_attempt_window', 30, 'number', '登录尝试统计窗口（分钟）'],
            ['session_timeout', $sessionTimeout, 'number', '会话超时时间（分钟）'],
            ['max_upload_size', 10, 'number', '最大上传文件大小（MB）'],
            ['enable_comments', 1, 'boolean', '是否开启评论功能'],
            ['enable_likes', 1, 'boolean', '是否开启点赞功能'],
            ['memoir_need_approval', 0, 'boolean', '回忆录发布是否需要审核'],
        ];

        foreach ($configs as $cfg) {
            $stmt = $conn->prepare("INSERT INTO site_config (config_key, config_value, config_type, description) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), config_type = VALUES(config_type), description = VALUES(description)");
            $stmt->bind_param("ssss", $cfg[0], $cfg[1], $cfg[2], $cfg[3]);
            $stmt->execute();
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        sendResponse(false, '安装失败：' . $e->getMessage());
    }

    $configContent = "<?php\n";
    $configContent .= "define('DB_HOST', " . var_export($host, true) . ");\n";
    $configContent .= "define('DB_PORT', " . intval($port) . ");\n";
    $configContent .= "define('DB_USER', " . var_export($dbuser, true) . ");\n";
    $configContent .= "define('DB_PASS', " . var_export($dbpass, true) . ");\n";
    $configContent .= "define('DB_NAME', " . var_export($dbname, true) . ");\n";
    $configContent .= "?>";

    if (@file_put_contents($configFile, $configContent) === false) {
        sendResponse(false, '配置文件写入失败，请检查 api/目录是否有写入权限');
    }

    @chmod($configFile, 0600);

    $lockContent = "<?php die(); ?>\n";
    $lockContent .= "installed_at: " . date('Y-m-d H:i:s') . "\n";
    $lockContent .= "ip: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n";
    @file_put_contents($lockFile, $lockContent);
    @chmod($lockFile, 0600);

    $conn->close();
    sendResponse(true, '安装成功！');
}

sendResponse(false, '无效的操作');
?>