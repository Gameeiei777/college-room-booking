<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $cookieParameters = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $cookieParameters['path'],
        $cookieParameters['domain'],
        $cookieParameters['secure'],
        $cookieParameters['httponly']
    );
}

session_destroy();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>กำลังออกจากระบบ</title>
</head>
<body>
    <p>กำลังออกจากระบบ...</p>
    <script>
        (function () {
            var redirectTimer = window.setTimeout(redirectToLogin, 1500);
            var googleScript = document.createElement('script');

            function redirectToLogin() {
                window.clearTimeout(redirectTimer);
                window.location.replace('index.php');
            }

            googleScript.src = 'https://accounts.google.com/gsi/client';
            googleScript.async = true;
            googleScript.onload = function () {
                if (window.google && window.google.accounts && window.google.accounts.id) {
                    window.google.accounts.id.disableAutoSelect();
                }
                redirectToLogin();
            };
            googleScript.onerror = redirectToLogin;
            document.head.appendChild(googleScript);
        })();
    </script>
</body>
</html>
