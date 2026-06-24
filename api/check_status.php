<?php
require 'db.php';

header("Content-Type: application/json; charset=UTF-8");

$dbOk = false;
$dbMessage = "数据库连接正常";

if ($conn) {
    $result = @$conn->query("SELECT 1");
    if ($result) {
        $dbOk = true;
    } else {
        $dbMessage = "数据库查询失败";
    }
} else {
    $dbMessage = "数据库连接失败";
}

echo json_encode([
    "success" => true,
    "status" => $dbOk ? "ok" : "error",
    "message" => $dbMessage
]);
?>
