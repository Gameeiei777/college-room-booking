<?php
require __DIR__ . '/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header('Location: index.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$userName = trim((string) ($_SESSION['full_name'] ?? 'ผู้ใช้งาน'));
$selectedType = trim((string) ($_GET['room_type'] ?? 'all'));
if (!in_array($selectedType, ['all', 'ห้องประชุม', 'ห้องคอมพิวเตอร์'], true)) {
    $selectedType = 'all';
}

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

function resolveDashboardRoomImage(?string $img): string
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

function detectDashboardRoomType(string $rawType, string $roomName, string $details): string
{
    $norm = trim($rawType);
    if ($norm === 'ห้องคอมพิวเตอร์' || mb_strpos(mb_strtolower($norm), 'คอม') !== false) {
        return 'ห้องคอมพิวเตอร์';
    }
    if ($norm === 'ห้องประชุม' || mb_strpos(mb_strtolower($norm), 'ประชุม') !== false) {
        return 'ห้องประชุม';
    }
    $combined = mb_strtolower($roomName . ' ' . $details);
    if (mb_strpos($combined, 'คอมพิวเตอร์') !== false || mb_strpos($combined, 'คอม') !== false) {
        return 'ห้องคอมพิวเตอร์';
    }
    return 'ห้องประชุม';
}

function getDashboardRoomStatus(string $rawStatus): array
{
    $norm = mb_strtolower(trim($rawStatus));
    if (in_array($norm, ['maintenance', 'unavailable', 'closed', 'ปิดปรับปรุง', 'ไม่ว่าง'], true)) {
        return ['label' => 'ปิดปรับปรุง', 'can_book' => false];
    }
    return ['label' => 'พร้อมใช้งาน', 'can_book' => true];
}

$roomCols = [];
if ($conn) {
    $rcRes = $conn->query("SHOW COLUMNS FROM `rooms`");
    if ($rcRes) {
        while ($rcRow = $rcRes->fetch_assoc()) {
            $roomCols[$rcRow['Field']] = true;
        }
    }
}
$hasRoomTypeCol = isset($roomCols['room_type']);

$rooms = [];
if ($conn) {
    $sql = $hasRoomTypeCol
        ? 'SELECT id, building_name, room_name, room_type, details, capacity, room_image, status FROM rooms ORDER BY id ASC'
        : 'SELECT id, building_name, room_name, details, capacity, room_image, status FROM rooms ORDER BY id ASC';
    $res = $conn->query($sql);
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $roomTypeLabel = detectDashboardRoomType(
                (string) ($r['room_type'] ?? ''),
                (string) ($r['room_name'] ?? ''),
                (string) ($r['details'] ?? '')
            );
            if ($selectedType !== 'all' && $roomTypeLabel !== $selectedType) {
                continue;
            }
            $rooms[] = [
                'id' => (int) ($r['id'] ?? 0),
                'room_name' => (string) ($r['room_name'] ?? ''),
                'room_type' => $roomTypeLabel,
                'building_name' => (string) ($r['building_name'] ?? ''),
                'capacity' => (int) ($r['capacity'] ?? 0),
                'room_image' => (string) ($r['room_image'] ?? ''),
                'status' => (string) ($r['status'] ?? 'available'),
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบจองห้องประชุมและห้องคอมพิวเตอร์ | วิทยาลัยเทคนิคสุพรรณบุรี</title>
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
                    <a href="teacher_dashboard.php" class="rounded-lg bg-[#c4c4c4] px-5 py-2.5 font-semibold text-[#14234c]">หน้าหลัก</a>
                    <a href="my_bookings.php" class="rounded-lg px-4 py-2 hover:bg-slate-100 hover:text-blue-700">ประวัติการจอง</a>
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
            <span class="text-2xl">📅</span>
            <h1 class="text-xl font-semibold sm:text-2xl">ระบบจองห้องประชุมและห้องคอมพิวเตอร์</h1>
        </div>
    </section>

    <!-- Main Content -->
    <main class="mx-auto w-full max-w-7xl flex-1 px-6 py-8 sm:px-8">

        <!-- Room Type Filter Bar -->
        <form method="get" action="teacher_dashboard.php" class="mb-8 rounded-2xl bg-[#3573d4] px-6 py-5 shadow-sm">
            <label for="room_type" class="mb-2 block text-sm font-semibold text-white">ประเภทห้อง</label>
            <select id="room_type" name="room_type" onchange="this.form.submit()"
                    class="w-full max-w-sm rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-900 outline-none focus:border-blue-600">
                <option value="all" <?= $selectedType === 'all' ? 'selected' : '' ?>>ทั้งหมด</option>
                <option value="ห้องประชุม" <?= $selectedType === 'ห้องประชุม' ? 'selected' : '' ?>>ห้องประชุม</option>
                <option value="ห้องคอมพิวเตอร์" <?= $selectedType === 'ห้องคอมพิวเตอร์' ? 'selected' : '' ?>>ห้องคอมพิวเตอร์</option>
            </select>
        </form>

        <!-- Rooms Grid -->
        <?php if (empty($rooms)): ?>
            <div class="rounded-2xl bg-white p-12 text-center text-slate-500 shadow-sm border border-slate-200">
                ไม่พบข้อมูลห้องที่ตรงกับประเภทที่เลือก
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 gap-7 md:grid-cols-2">
                <?php foreach ($rooms as $rm): ?>
                    <?php
                        $imgSrc = resolveDashboardRoomImage($rm['room_image']);
                        $st = getDashboardRoomStatus($rm['status']);
                    ?>
                    <div class="overflow-hidden rounded-2xl bg-white p-5 shadow-sm border border-slate-200 flex flex-col justify-between">
                        <div>
                            <div class="flex h-64 w-full items-center justify-center overflow-hidden rounded-xl bg-slate-100">
                                <?php if ($imgSrc !== ''): ?>
                                    <img src="<?= htmlspecialchars($imgSrc, ENT_QUOTES, 'UTF-8') ?>"
                                         alt="<?= htmlspecialchars($rm['room_name'], ENT_QUOTES, 'UTF-8') ?>"
                                         class="h-full w-full object-cover"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <span class="hidden text-base font-medium text-slate-500">
                                        รูปภาพ <?= htmlspecialchars($rm['room_name'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-base font-medium text-slate-500">
                                        รูปภาพ <?= htmlspecialchars($rm['room_name'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <h2 class="mt-4 text-xl font-bold text-slate-900">
                                <?= htmlspecialchars($rm['room_name'], ENT_QUOTES, 'UTF-8') ?>
                            </h2>
                        </div>

                        <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <a href="Room_information.php?room_id=<?= (int) $rm['id'] ?>"
                               class="flex w-full items-center justify-center rounded-xl bg-slate-200 py-3 text-base font-semibold text-slate-800 transition hover:bg-slate-300">
                                ดูรายละเอียด
                            </a>

                            <?php if ($st['can_book']): ?>
                                <a href="Reserve.php?room_id=<?= (int) $rm['id'] ?>"
                                   class="flex w-full items-center justify-center rounded-xl bg-[#7ee687] py-3 text-base font-semibold text-slate-950 transition hover:bg-[#65d970]">
                                    จองห้อง
                                </a>
                            <?php else: ?>
                                <span class="flex w-full cursor-not-allowed items-center justify-center rounded-xl bg-slate-300 py-3 text-base font-semibold text-slate-500">
                                    ปิดปรับปรุง
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
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
