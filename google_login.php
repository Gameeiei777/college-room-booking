<?php
require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$credential = trim($_POST['credential'] ?? '');

if ($credential === '') {
    $_SESSION['login_error'] = 'ไม่สามารถเข้าสู่ระบบได้';
    header('Location: index.php');
    exit;
}

$parts = explode('.', $credential);
if (count($parts) !== 3) {
    $_SESSION['login_error'] = 'ไม่สามารถเข้าสู่ระบบได้';
    header('Location: index.php');
    exit;
}

$payloadJson = base64_decode(strtr($parts[1], '-_', '+/'));
$payload = json_decode($payloadJson, true);

$email = strtolower(trim($payload['email'] ?? ''));
$emailVerified = !empty($payload['email_verified']);

if ($email === '' || !$emailVerified) {
    $_SESSION['login_error'] = 'ไม่สามารถเข้าสู่ระบบได้';
    header('Location: index.php');
    exit;
}

$stmt = $conn->prepare(
    'SELECT id, email, full_name, department, role
     FROM users
     WHERE LOWER(email) = ?
     LIMIT 1'
);
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->bind_result($userId, $userEmail, $fullName, $department, $role);

if ($stmt->fetch() && in_array(strtolower(trim((string) $role)), ['admin', 'teacher'], true)) {
    session_regenerate_id(true);

    $_SESSION['user_id'] = $userId;
    $_SESSION['email'] = $userEmail;
    $_SESSION['full_name'] = $fullName;
    $_SESSION['department'] = $department;
    $_SESSION['role'] = strtolower(trim((string) $role));

    $stmt->close();

    header('Location: ' . ($_SESSION['role'] === 'admin'
        ? 'admin_dashboard.php'
        : 'teacher_dashboard.php'));
    exit;
}

$stmt->close();
$_SESSION['login_error'] = 'ไม่สามารถเข้าสู่ระบบได้';
header('Location: index.php');
exit;