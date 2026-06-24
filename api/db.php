<?php
// 禁止将 PHP 错误/警告直接输出到响应中，以免破坏 JSON 格式
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);
ini_set('display_errors', 0);

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');

session_start();

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = [
    'http://' . ($_SERVER['HTTP_HOST'] ?? ''),
    'https://' . ($_SERVER['HTTP_HOST'] ?? ''),
];
if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: " . $origin);
    header("Access-Control-Allow-Credentials: true");
    header("Vary: Origin");
}
header("Access-Control-Allow-Headers: Content-Type, X-CSRF-Token");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Strict-Transport-Security: max-age=31536000; includeSubDomains");

$configFile = __DIR__ . '/config.php';
if (file_exists($configFile)) {
    require_once $configFile;
}

$servername = DB_HOST ?? '127.0.0.1';
$username = DB_USER ?? 'root';
$password = DB_PASS ?? '';
$dbname = DB_NAME ?? 'high_school_memoir';
$port = defined('DB_PORT') ? DB_PORT : 3306;

try {
    $conn = new mysqli($servername, $username, $password, $dbname, $port);
} catch (mysqli_sql_exception $e) {
    die(json_encode(["success" => false, "message" => "数据库连接失败"]));
}

if ($conn->connect_error) {
    die(json_encode(["success" => false, "message" => "数据库连接失败"]));
}

$conn->set_charset("utf8mb4");

function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token = null) {
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    }
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function getCSRFToken() {
    return generateCSRFToken();
}

function csrfProtection() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validateCSRFToken()) {
            echo json_encode(["success" => false, "message" => "请求验证失败，请刷新页面重试"]);
            exit;
        }
    }
    header('X-CSRF-Token: ' . getCSRFToken());
}

generateCSRFToken();
?>
