<?php
session_start();

// หากเข้าสู่ระบบแล้ว ให้เปลี่ยนหน้าไปยัง report.php ทันที
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    header('Location: report.php');
    exit;
}

$error = '';

// กำหนด Username / Password สำหรับเข้าใช้งาน
$valid_username = 'admin';
$valid_password = '3cx@itd'; // เปลี่ยนรหัสผ่านตามต้องการที่นี่

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === $valid_username && $password === $valid_password) {
        // ตั้งค่า Session
        $_SESSION['loggedin'] = true;
        $_SESSION['username'] = $username;
        
        // ย้ายหน้าไปยังรายงาน
        header('Location: report.php');
        exit;
    } else {
        $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง!';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ - 3CX Billing System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Sarabun', sans-serif;
            background-color: #f8f9fa;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            width: 100%;
            max-width: 400px;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            background: #ffffff;
        }
        .login-title {
            color: #0000FF;
            font-weight: 700;
            text-align: center;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>

<div class="login-card">
    <h4 class="login-title">🔐 เข้าสู่ระบบ 3CX Billing</h4>
    
    <?php if ($error): ?>
        <div class="alert alert-danger py-2 text-center" role="alert">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <div class="mb-3">
            <label for="username" class="form-label fw-bold">ชื่อผู้ใช้ (Username)</label>
            <input type="text" class="form-control" id="username" name="username" required autofocus>
        </div>
        <div class="mb-3">
            <label for="password" class="form-label fw-bold">รหัสผ่าน (Password)</label>
            <input type="password" class="form-control" id="password" name="password" required>
        </div>
        <button type="submit" class="btn btn-primary w-100 fw-bold py-2 mt-2">เข้าสู่ระบบ</button>
    </form>
</div>

</body>
</html>
