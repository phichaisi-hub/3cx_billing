<?php
session_start();

// 1. เคลียร์ค่า Session ทั้งหมด
$_SESSION = array();

// 2. ลบ Session Cookie หากมี
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. ทำลาย Session
session_destroy();

// 4. ส่งผู้ใช้กลับไปยังหน้า login.php
header("Location: login.php");
exit;
?>
