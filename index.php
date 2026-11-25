<?php
$loginError = '';
$databaseError = '';

try {
    require __DIR__ . '/db.php';

    if (isset($_SESSION['login_error']) && is_string($_SESSION['login_error'])) {
        $loginError = $_SESSION['login_error'];
        unset($_SESSION['login_error']);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $submittedEmail = $_POST['email'] ?? '';
        $email = is_string($submittedEmail) ? trim($submittedEmail) : '';

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $loginError = 'ผู้ใช้นี้ไม่สามารถเข้าสู่ระบบได้';
        } else {
            $stmt = $conn->prepare(
                'SELECT id, email, full_name, department, role
                 FROM users
                 WHERE email = ?
                 LIMIT 1'
            );

            $stmt->bind_param('s', $email);
            $stmt->execute();
            $stmt->bind_result($userId, $userEmail, $fullName, $department, $role);

            if ($stmt->fetch() && in_array($role, ['admin', 'teacher'], true)) {
                session_regenerate_id(true);

                $_SESSION['user_id'] = $userId;
                $_SESSION['email'] = $userEmail;
                $_SESSION['full_name'] = $fullName;
                $_SESSION['department'] = $department;
                $_SESSION['role'] = $role;

                $stmt->close();

                header('Location: ' . ($role === 'admin'
                    ? 'admin_dashboard.php'
                    : 'teacher_dashboard.php'));
                exit;
            }

            $stmt->close();
            $loginError = 'ผู้ใช้นี้ไม่สามารถเข้าสู่ระบบได้';
        }
    }
} catch (mysqli_sql_exception $exception) {
    error_log($exception->getMessage());
    $databaseError = 'ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาลองใหม่ภายหลัง';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>เข้าสู่ระบบ วิทยาลัยเทคนิคสุพรรณบุรี</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Prompt', sans-serif;
        }

        .google-login-box iframe {
            transform: scale(1.12);
            transform-origin: center center;
        }
    </style>
</head>

<body class="min-h-screen bg-[#c8c8c8] text-slate-900">

    <!-- Header -->
    <header class="h-20 bg-white shadow-sm">
        <nav class="mx-auto flex h-full max-w-7xl items-center justify-between px-6 sm:px-10"
             aria-label="เมนูหลัก">

            <div class="flex items-center gap-8 text-base font-semibold text-slate-900 sm:gap-12 sm:text-xl">
                <a class="text-slate-900 transition hover:text-blue-700" href="index.php">
                    หน้าหลัก
                </a>

                <a class="text-slate-900 transition hover:text-blue-700" href="rooms.php">
                    รายการห้องประชุม
                </a>

                <a class="text-slate-900 transition hover:text-blue-700" href="contact.php">
                    ติดต่อเรา
                </a>
            </div>

        </nav>
    </header>

    <!-- Main -->
    <main class="flex min-h-[calc(100vh-5rem)] items-center justify-center px-4 py-10">

        <section class="min-h-[580px] w-full max-w-[680px] rounded-sm bg-[#f7f3f3] px-8 py-10 shadow-sm sm:px-12 sm:py-12">

            <!-- Logo and College Name -->
            <div class="flex items-center justify-center gap-5 sm:gap-6">

                <?php if (file_exists(__DIR__ . '/logo.png')): ?>

                    <img
                        src="logo.png"
                        alt="ตราวิทยาลัยเทคนิคสุพรรณบุรี"
                        class="w-[124px] h-[124px] shrink-0 object-contain sm:w-[132px] sm:h-[132px]"
                    >

                <?php else: ?>

                    <div class="w-[124px] h-[124px] shrink-0 rounded-full bg-red-900 text-amber-300 flex items-center justify-center font-bold text-base sm:w-[132px] sm:h-[132px]">
                        SPTC
                    </div>

                <?php endif; ?>

                <h1 class="text-2xl font-semibold leading-snug text-blue-800 sm:text-3xl">
                    วิทยาลัยเทคนิคสุพรรณบุรี
                </h1>

            </div>

            <!-- Login Form -->
            <div class="mx-auto mt-14 max-w-lg text-black">

                <?php if ($loginError !== ''): ?>

                    <div class="mb-6 rounded-md border border-red-300 bg-red-50 px-4 py-3.5 text-center text-base font-medium text-red-700"
                         role="alert">

                        <?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?>

                    </div>

                <?php endif; ?>

                <?php if ($databaseError !== ''): ?>

                    <div class="mb-6 rounded-md border border-red-300 bg-red-50 px-4 py-3.5 text-center text-base font-medium text-red-700"
                         role="alert">

                        <?= htmlspecialchars($databaseError, ENT_QUOTES, 'UTF-8') ?>

                    </div>

                <?php endif; ?>

                <script src="https://accounts.google.com/gsi/client" async defer></script>

                <div id="g_id_onload"
                     data-client_id="473322902719-s3qt7piqghfn5kj7p3bm6duo6rci3h1v.apps.googleusercontent.com"
                     data-context="signin"
                     data-ux_mode="popup"
                     data-login_uri="http://localhost/booking/google_login.php"
                     data-auto_prompt="false"
                     data-auto_select="false">
                </div>

                <div class="google-login-box rounded-md border border-slate-300 bg-white px-5 py-6 shadow-sm">
                    <div class="g_id_signin flex justify-center text-black"
                         data-type="standard"
                         data-shape="rectangular"
                         data-theme="outline"
                         data-text="signin_with"
                         data-size="large"
                         data-width="430"
                         data-logo_alignment="left"
                         data-locale="th">
                    </div>
                </div>

            </div>

        </section>

    </main>

</body>
</html>