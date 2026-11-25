<?php
require __DIR__ . '/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header('Location: index.php');
    exit;
}

$roomId = (int) ($_GET['room_id'] ?? $_GET['id'] ?? 1);
$userId = (int) $_SESSION['user_id'];
$userName = trim((string) ($_SESSION['full_name'] ?? 'ผู้ใช้งาน'));

if ($conn && $userId > 0) {
    $uCheck = $conn->query("SHOW TABLES LIKE 'users'");
    if ($uCheck && $uCheck->num_rows > 0) {
        $uStmt = $conn->prepare('SELECT full_name FROM users WHERE id = ? LIMIT 1');
        if ($uStmt) {
            $uStmt->bind_param('i', $userId);
            $uStmt->execute();
            $uRes = $uStmt->get_result();
            if ($uRow = $uRes->fetch_assoc()) {
                if (!empty($uRow['full_name'])) {
                    $userName = trim((string) $uRow['full_name']);
                }
            }
            $uStmt->close();
        }
    }
}

function resolveUserRoomImage(?string $img): string
{
    $img = trim((string) $img);
    if ($img === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $img)) {
        return $img;
    }
    $clean = ltrim($img, '/\\');
    if (file_exists(__DIR__ . '/' . $clean)) {
        return $clean;
    }
    if (file_exists(__DIR__ . '/uploads/rooms/' . basename($clean))) {
        return 'uploads/rooms/' . basename($clean);
    }
    return '';
}

function cleanRoomDetailsText(?string $text): string
{
    $cleaned = preg_replace('/^\[ประเภท:\s*(ห้องประชุม|ห้องคอมพิวเตอร์)\]\s*/u', '', trim((string) $text));
    return trim((string) $cleaned);
}

function getRoomStatusBadge(?string $rawStatus): array
{
    $norm = mb_strtolower(trim((string) $rawStatus));
    if (in_array($norm, ['maintenance', 'unavailable', 'closed', 'ปิดปรับปรุง', 'ไม่ว่าง'], true)) {
        return ['label' => 'ปิดปรับปรุง', 'class' => 'bg-rose-100 text-rose-700 border-rose-200', 'can_book' => false];
    }
    return ['label' => 'พร้อมใช้งาน', 'class' => 'bg-emerald-100 text-emerald-700 border-emerald-200', 'can_book' => true];
}

// ดึงข้อมูลห้องล่าสุดจากฐานข้อมูลเสมอ เพื่อให้ตรงกับที่แอดมินแก้ไข
$room = null;
if ($conn) {
    $stmt = $conn->prepare('SELECT id, building_name, room_name, details, capacity, room_image, status FROM rooms WHERE id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $roomId);
        $stmt->execute();
        $res = $stmt->get_result();
        $room = $res->fetch_assoc();
        $stmt->close();
    }
}

if (!$room && $conn) {
    $fallbackRes = $conn->query('SELECT id, building_name, room_name, details, capacity, room_image, status FROM rooms ORDER BY id ASC LIMIT 1');
    if ($fallbackRes) {
        $room = $fallbackRes->fetch_assoc();
    }
}

$roomId = (int) ($room['id'] ?? $roomId);
$roomName = trim((string) ($room['room_name'] ?? 'ห้องประชุม'));
$buildingName = trim((string) ($room['building_name'] ?? '-'));
$capacity = (int) ($room['capacity'] ?? 0);
$details = cleanRoomDetailsText($room['details'] ?? '');
$roomImageSrc = resolveUserRoomImage($room['room_image'] ?? '');
$statusInfo = getRoomStatusBadge($room['status'] ?? 'available');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายละเอียด<?= htmlspecialchars($roomName, ENT_QUOTES, 'UTF-8') ?> | ระบบจองห้องประชุมและห้องคอมพิวเตอร์</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Prompt', sans-serif; }
    </style>
</head>
<body class="min-h-screen bg-[#fdf5f4] text-slate-900 flex flex-col">

    <!-- Top Navbar -->
    <header class="bg-white shadow-sm border-b border-slate-200">
        <div class="mx-auto flex h-24 max-w-7xl items-center justify-between px-6 sm:px-8">
            <a href="teacher_dashboard.php" class="flex items-center gap-4">
                <?php if (file_exists(__DIR__ . '/logo.png')): ?>
                    <img src="logo.png" alt="ตราวิทยาลัยเทคนิคสุพรรณบุรี" class="h-16 w-16 object-contain">
                <?php else: ?>
                    <div class="flex h-16 w-16 items-center justify-center rounded-full bg-red-900 text-sm font-bold text-amber-300">
                        SPTC
                    </div>
                <?php endif; ?>
                <div>
                    <div class="text-lg font-bold text-[#14234c] sm:text-xl">ระบบจองห้องประชุมและห้องคอมพิวเตอร์</div>
                    <div class="text-sm font-medium text-blue-700 underline sm:text-base">Meeting Room Booking System</div>
                </div>
            </a>

            <div class="flex items-center gap-6 sm:gap-8">
                <nav class="hidden md:flex items-center gap-6 text-base font-medium text-[#14234c]">
                    <a href="teacher_dashboard.php" class="rounded-lg px-4 py-2 hover:bg-slate-100 hover:text-blue-700">จองห้อง</a>
                    <a href="my_bookings.php" class="rounded-lg px-4 py-2 hover:bg-slate-100 hover:text-blue-700">ประวัติการจอง</a>
                    <a href="contact.php" class="rounded-lg px-4 py-2 hover:bg-slate-100 hover:text-blue-700">ติดต่อเรา</a>
                </nav>

                <!-- Profile Dropdown -->
                <div class="relative inline-block">
                    <button type="button" onclick="toggleProfileMenu()"
                            class="flex w-full items-center justify-between gap-3 rounded bg-slate-100 px-4 py-2.5 text-base font-medium text-slate-900 hover:bg-slate-200">
                        <span class="flex items-center gap-2.5">
                            <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-slate-800 text-sm text-white">👤</span>
                            <span><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></span>
                        </span>
                        <span id="profile_arrow">⌄</span>
                    </button>

                    <div id="profile_menu" class="hidden absolute left-0 right-0 z-30 w-full shadow-md">
                        <a href="profile.php" class="block w-full bg-slate-200 px-4 py-3 text-center text-sm font-medium text-slate-900 hover:bg-slate-300">
                            ข้อมูลส่วนตัว
                        </a>
                        <a href="logout.php" class="block w-full bg-[#e05252] px-4 py-3 text-center text-sm font-medium text-white hover:bg-red-600">
                            ออกจากระบบ
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Dark Navy Title Bar -->
    <section class="bg-[#14234c] py-6 text-white">
        <div class="mx-auto flex max-w-7xl items-center gap-3.5 px-6 sm:px-8">
            <span class="text-2xl">🏢</span>
            <h1 class="text-xl font-semibold sm:text-2xl">รายละเอียดห้องประชุมและห้องคอมพิวเตอร์</h1>
        </div>
    </section>

    <!-- Main Content -->
    <main class="mx-auto w-full max-w-7xl flex-1 px-6 py-10 sm:px-8">

        <?php if (!$room): ?>
            <div class="rounded-2xl bg-white p-10 text-center shadow-sm border border-slate-200">
                <p class="text-lg text-slate-600">ไม่พบข้อมูลห้องที่ต้องการดูรายละเอียด</p>
                <a href="teacher_dashboard.php"
                   class="mt-5 inline-flex rounded-xl bg-[#14234c] px-7 py-3 text-sm font-medium text-white hover:bg-blue-900">
                    กลับหน้าหลัก
                </a>
            </div>
        <?php else: ?>
            <div class="overflow-hidden rounded-3xl bg-white shadow-md border border-slate-200">
                <div class="grid grid-cols-1 lg:grid-cols-12 items-center">

                    <!-- Standard Room Image Section -->
                    <div class="lg:col-span-6 bg-slate-100 flex items-center justify-center p-6 sm:p-8 min-h-[340px] lg:min-h-[420px]">
                        <?php if ($roomImageSrc !== ''): ?>
                            <img src="<?= htmlspecialchars($roomImageSrc, ENT_QUOTES, 'UTF-8') ?>"
                                 alt="<?= htmlspecialchars($roomName, ENT_QUOTES, 'UTF-8') ?>"
                                 class="max-h-[360px] w-full rounded-2xl object-contain"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <span class="hidden text-lg font-medium text-slate-500">
                                รูปภาพ <?= htmlspecialchars($roomName, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        <?php else: ?>
                            <span class="text-lg font-medium text-slate-500">
                                รูปภาพ <?= htmlspecialchars($roomName, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Room Information Table & Actions -->
                    <div class="lg:col-span-6 p-7 sm:p-10 flex flex-col justify-between">
                        <div>
                            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 pb-5">
                                <h2 class="text-2xl font-bold text-[#14234c] sm:text-4xl">
                                    <?= htmlspecialchars($roomName, ENT_QUOTES, 'UTF-8') ?>
                                </h2>
                                <span class="inline-flex items-center rounded-full border px-4 py-1.5 text-sm font-semibold <?= $statusInfo['class'] ?>">
                                    <?= htmlspecialchars($statusInfo['label'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>

                            <!-- Information Table -->
                            <div class="mt-6 overflow-hidden rounded-2xl border border-slate-200">
                                <table class="w-full border-collapse text-left text-base sm:text-lg">
                                    <tbody class="divide-y divide-slate-200">
                                        <tr class="bg-slate-50/70">
                                            <th class="w-40 sm:w-48 px-5 py-4 font-semibold text-slate-600 align-top">ชื่อห้อง</th>
                                            <td class="px-5 py-4 font-semibold text-slate-900">
                                                <?= htmlspecialchars($roomName, ENT_QUOTES, 'UTF-8') ?>
                                            </td>
                                        </tr>
                                        <tr class="bg-white">
                                            <th class="px-5 py-4 font-semibold text-slate-600 align-top">อาคาร</th>
                                            <td class="px-5 py-4 font-semibold text-slate-900">
                                                <?= htmlspecialchars($buildingName !== '' ? $buildingName : '-', ENT_QUOTES, 'UTF-8') ?>
                                            </td>
                                        </tr>
                                        <tr class="bg-slate-50/70">
                                            <th class="px-5 py-4 font-semibold text-slate-600 align-top">ความจุ</th>
                                            <td class="px-5 py-4 font-semibold text-slate-900">
                                                <?= $capacity > 0 ? $capacity . ' คน' : '-' ?>
                                            </td>
                                        </tr>
                                        <tr class="bg-white">
                                            <th class="px-5 py-4 font-semibold text-slate-600 align-top">รายละเอียดห้อง</th>
                                            <td class="px-5 py-4 leading-relaxed text-slate-800">
                                                <?= nl2br(htmlspecialchars($details !== '' ? $details : 'ไม่มีรายละเอียดเพิ่มเติม', ENT_QUOTES, 'UTF-8')) ?>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="mt-8 flex flex-wrap items-center justify-end gap-4">
                            <a href="teacher_dashboard.php"
                               class="inline-flex items-center justify-center rounded-xl bg-slate-200 px-7 py-3.5 text-base font-medium text-slate-800 transition hover:bg-slate-300">
                                ย้อนกลับ
                            </a>

                            <?php if ($statusInfo['can_book']): ?>
                                <a href="Reserve.php?room_id=<?= $roomId ?>"
                                   class="inline-flex items-center justify-center rounded-xl bg-[#7ee687] px-8 py-3.5 text-base font-semibold text-slate-950 shadow-sm transition hover:bg-[#65d970]">
                                    จองห้องนี้
                                </a>
                            <?php else: ?>
                                <span class="inline-flex cursor-not-allowed items-center justify-center rounded-xl bg-slate-300 px-8 py-3.5 text-base font-semibold text-slate-500">
                                    งดให้บริการชั่วคราว
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </div>
        <?php endif; ?>

    </main>

    <!-- Footer -->
    <footer class="bg-[#14234c] py-8 text-white">
        <div class="mx-auto flex max-w-7xl items-center gap-4 px-6 sm:px-8">
            <?php if (file_exists(__DIR__ . '/logo.png')): ?>
                <img src="logo.png" alt="ตราวิทยาลัยเทคนิคสุพรรณบุรี" class="h-16 w-16 object-contain">
            <?php else: ?>
                <div class="flex h-16 w-16 items-center justify-center rounded-full bg-red-900 text-sm font-bold text-amber-300">
                    SPTC
                </div>
            <?php endif; ?>
            <div>
                <div class="text-lg font-semibold sm:text-xl">วิทยาลัยเทคนิคสุพรรณบุรี</div>
                <div class="text-sm text-slate-300 sm:text-base">Suphan Buri Technical College</div>
            </div>
        </div>
    </footer>

    <script>
    function toggleProfileMenu() {
        const menu = document.getElementById('profile_menu');
        menu.classList.toggle('hidden');
    }

    document.addEventListener('click', function(event) {
        const menu = document.getElementById('profile_menu');
        const btn = event.target.closest('button[onclick="toggleProfileMenu()"]');
        if (!btn && menu && !menu.contains(event.target)) {
            menu.classList.add('hidden');
        }
    });
    </script>

</body>
</html>
