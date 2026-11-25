<?php
require __DIR__ . '/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit;
}

$adminName = $_SESSION['full_name'] ?? 'ฤทธิไกร อ่ำเกิด';
$todayYmd = date('Y-m-d');

$totalRooms = 0;
$totalBookings = 0;
$pendingCount = 0;
$approvedCount = 0;
$todayBookingsCount = 0;
$recentBookings = [];

if ($conn) {
    // 1. นับจำนวนห้องทั้งหมด
    $rCheck = $conn->query("SHOW TABLES LIKE 'rooms'");
    if ($rCheck && $rCheck->num_rows > 0) {
        $rCountRes = $conn->query('SELECT COUNT(*) AS cnt FROM rooms');
        if ($rCountRes && ($rRow = $rCountRes->fetch_assoc())) {
            $totalRooms = (int) ($rRow['cnt'] ?? 0);
        }
    }

    // 2. นับสถิติการจองและดึงรายการล่าสุด
    $bCheck = $conn->query("SHOW TABLES LIKE 'bookings'");
    if ($bCheck && $bCheck->num_rows > 0) {
        $cols = [];
        $cRes = $conn->query("SHOW COLUMNS FROM `bookings`");
        if ($cRes) {
            while ($cRow = $cRes->fetch_assoc()) {
                $cols[$cRow['Field']] = true;
            }
        }

        $allRes = $conn->query('SELECT * FROM bookings ORDER BY id DESC');
        if ($allRes) {
            while ($b = $allRes->fetch_assoc()) {
                $totalBookings++;

                $bDate = trim((string) ($b['booking_date'] ?? ''));
                if ($bDate === $todayYmd) {
                    $todayBookingsCount++;
                }

                $rawStatus = strtolower(trim((string) ($b['status'] ?? 'pending')));
                if (in_array($rawStatus, ['pending', 'รอดำเนินการ', 'รออนุมัติ'], true)) {
                    $pendingCount++;
                    $statusLabel = 'รอดำเนินการ';
                    $statusType = 'pending';
                } elseif (in_array($rawStatus, ['cancelled', 'rejected', 'ปฏิเสธ', 'ยกเลิก', 'ยกเลิกแล้ว'], true)) {
                    $statusLabel = 'ปฏิเสธ / ยกเลิก';
                    $statusType = 'cancelled';
                } else {
                    $approvedCount++;
                    $statusLabel = 'อนุมัติ / เสร็จสิ้น';
                    $statusType = 'approved';
                }

                if (count($recentBookings) < 5) {
                    $timeText = trim((string) ($b['time_slot'] ?? ''));
                    if ($timeText === '' && !empty($b['start_time']) && !empty($b['end_time'])) {
                        $timeText = substr((string) $b['start_time'], 0, 5) . ' - ' . substr((string) $b['end_time'], 0, 5);
                    }

                    $bookerDisplay = trim((string) ($b['booker_name'] ?? ''));
                    if ($bookerDisplay === '') {
                        $bookerDisplay = trim((string) ($b['booker_email'] ?? 'อาจารย์ผู้จอง'));
                    }

                    $roomDisplay = trim((string) ($b['room_name'] ?? ''));
                    if ($roomDisplay === '') {
                        $roomDisplay = trim((string) ($b['room_type'] ?? 'ห้องประชุม 1'));
                    }

                    $recentBookings[] = [
                        'id' => (int) ($b['id'] ?? 0),
                        'booker' => $bookerDisplay,
                        'datetime' => trim(formatShortThaiDate($bDate) . ($timeText !== '' ? ' ' . $timeText : '')),
                        'room' => $roomDisplay,
                        'topic' => trim((string) ($b['topic'] ?? ($b['purpose'] ?? '-'))) ?: '-',
                        'status_label' => $statusLabel,
                        'status_type' => $statusType,
                    ];
                }
            }
        }
    }
}

// ข้อมูลตัวอย่างกรณีฐานข้อมูลยังไม่มีรายการ
if ($totalRooms === 0) {
    $totalRooms = 3;
}

function formatShortThaiDate(string $ymd): string
{
    $ymd = trim($ymd);
    if ($ymd === '') {
        return '-';
    }
    $parts = explode('-', $ymd);
    if (count($parts) !== 3) {
        return $ymd;
    }
    $d = str_pad((string) (int) $parts[2], 2, '0', STR_PAD_LEFT);
    $m = str_pad((string) (int) $parts[1], 2, '0', STR_PAD_LEFT);
    $yShort = substr((string) ((int) $parts[0] + 543), -2);
    return "{$d}/{$m}/{$yShort}";
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>หน้าหลักผู้ดูแลระบบ | ระบบจองห้องประชุมและห้องคอมพิวเตอร์</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Prompt', sans-serif; }
    </style>
</head>
<body class="min-h-screen bg-[#fdf0ed] text-slate-900 flex flex-col">

    <!-- Top Navbar -->
    <header class="bg-white shadow-sm border-b border-slate-200">
        <div class="mx-auto flex h-24 max-w-7xl items-center justify-between px-6 sm:px-8">
            <a href="admin_dashboard.php" class="flex items-center gap-4">
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
                <nav class="hidden md:flex items-center gap-3 text-base font-medium text-[#14234c]">
                    <a href="admin_dashboard.php" class="rounded-lg bg-[#c4c4c4] px-5 py-2.5 font-semibold text-[#14234c]">หน้าหลัก</a>
                    <a href="admin_bookings.php" class="rounded-lg px-4 py-2 hover:bg-slate-100 hover:text-blue-700">จัดการการจอง</a>
                    <a href="admin_rooms.php" class="rounded-lg px-4 py-2 hover:bg-slate-100 hover:text-blue-700">จัดการห้อง</a>
                </nav>

                <!-- Profile Dropdown -->
                <div class="relative inline-block">
                    <button type="button" onclick="toggleProfileMenu()"
                            class="flex w-full items-center justify-between gap-3 rounded bg-slate-100 px-4 py-2.5 text-base font-medium text-slate-900 hover:bg-slate-200">
                        <span class="flex items-center gap-2.5">
                            <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-slate-800 text-sm text-white">👤</span>
                            <span><?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?></span>
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
        <div class="mx-auto flex max-w-7xl items-center justify-between px-6 sm:px-8">
            <div class="flex items-center gap-3.5">
                <span class="text-2xl">🛡️</span>
                <h1 class="text-xl font-semibold sm:text-2xl">หน้าหลักผู้ดูแลระบบ (Admin Dashboard)</h1>
            </div>
            <div class="hidden sm:block text-sm text-slate-300">
                ภาพรวมการจองห้องประชุมและห้องคอมพิวเตอร์
            </div>
        </div>
    </section>

    <!-- Main Content -->
    <main class="mx-auto w-full max-w-7xl flex-1 px-6 py-10 sm:px-8">

        <!-- 1. Summary Cards -->
        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-slate-500">คำขอรอดำเนินการ</span>
                    <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-amber-100 text-lg text-amber-700">⏳</span>
                </div>
                <div class="mt-4 text-3xl font-bold text-amber-600"><?= $pendingCount ?> <span class="text-base font-normal text-slate-500">รายการ</span></div>
            </div>

            <div class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-slate-500">อนุมัติ / เสร็จสิ้น</span>
                    <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-100 text-lg text-emerald-700">✓</span>
                </div>
                <div class="mt-4 text-3xl font-bold text-emerald-600"><?= $approvedCount ?> <span class="text-base font-normal text-slate-500">รายการ</span></div>
            </div>

            <div class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-slate-500">จำนวนห้องในระบบ</span>
                    <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-blue-100 text-lg text-blue-700">🏢</span>
                </div>
                <div class="mt-4 text-3xl font-bold text-[#14234c]"><?= $totalRooms ?> <span class="text-base font-normal text-slate-500">ห้อง</span></div>
            </div>

            <div class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-slate-500">รายการจองทั้งหมด</span>
                    <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-100 text-lg text-indigo-700">📅</span>
                </div>
                <div class="mt-4 text-3xl font-bold text-indigo-700"><?= $totalBookings ?> <span class="text-base font-normal text-slate-500">รายการ</span></div>
            </div>
        </div>

        <!-- 2. Quick Actions -->
        <div class="mt-8 grid grid-cols-1 gap-6 md:grid-cols-2">
            <a href="admin_bookings.php"
               class="group flex items-center justify-between rounded-2xl bg-[#3b6fc7] p-7 text-white shadow-sm transition hover:bg-[#2f5eb0]">
                <div>
                    <div class="text-xl font-semibold">จัดการการจองห้อง</div>
                    <p class="mt-1 text-sm text-blue-100">ตรวจสอบคำขอจองห้อง อนุมัติ ปฏิเสธ และกรองข้อมูลตามวันที่หรือผู้จอง</p>
                </div>
                <span class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-white/20 text-2xl transition group-hover:translate-x-1">→</span>
            </a>

            <a href="admin_rooms.php"
               class="group flex items-center justify-between rounded-2xl bg-[#14234c] p-7 text-white shadow-sm transition hover:bg-[#1d326b]">
                <div>
                    <div class="text-xl font-semibold">จัดการห้องประชุมและห้องคอมพิวเตอร์</div>
                    <p class="mt-1 text-sm text-slate-300">เพิ่มห้องใหม่ แก้ไขรูปห้อง อาคาร ความจุ และรายละเอียดอุปกรณ์ประจำห้อง</p>
                </div>
                <span class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-white/15 text-2xl transition group-hover:translate-x-1">→</span>
            </a>
        </div>

        <!-- 3. Recent Bookings Table -->
        <div class="mt-8 rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
            <div class="mb-5 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-xl font-bold text-[#14234c]">รายการจองล่าสุด</h2>
                    <p class="text-sm text-slate-500">แสดงรายการจองล่าสุด 5 รายการในระบบ</p>
                </div>
                <a href="admin_bookings.php"
                   class="rounded-xl bg-[#5475e8] px-5 py-2.5 text-sm font-medium text-white transition hover:bg-blue-600">
                    ดูรายการทั้งหมด
                </a>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] border-collapse text-center">
                    <thead>
                        <tr class="bg-[#14234c] text-sm font-semibold text-white">
                            <th class="rounded-tl-xl px-4 py-3.5">ผู้ขอจอง</th>
                            <th class="px-4 py-3.5">วันที่ - เวลา</th>
                            <th class="px-4 py-3.5">ห้อง</th>
                            <th class="px-4 py-3.5">หัวข้อการจอง</th>
                            <th class="rounded-tr-xl px-4 py-3.5">สถานะ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-sm">
                        <?php if (empty($recentBookings)): ?>
                            <tr>
                                <td colspan="5" class="py-10 text-slate-500">
                                    ยังไม่มีรายการจองในระบบ
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentBookings as $idx => $item): ?>
                                <?php
                                    $rowBg = ($idx % 2 === 0) ? 'bg-[#e6e6e6]/60' : 'bg-white';
                                    $badgeClass = match ($item['status_type']) {
                                        'pending' => 'bg-[#ffe36e] text-slate-900',
                                        'cancelled' => 'bg-[#f58a8a] text-white',
                                        default => 'bg-[#7ee687] text-slate-900',
                                    };
                                ?>
                                <tr class="<?= $rowBg ?> hover:bg-blue-50/50 transition">
                                    <td class="px-4 py-3.5 font-medium text-slate-900">
                                        <?= htmlspecialchars($item['booker'], ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="px-4 py-3.5 text-slate-800">
                                        <?= htmlspecialchars($item['datetime'], ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="px-4 py-3.5 font-medium text-slate-900">
                                        <?= htmlspecialchars($item['room'], ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="px-4 py-3.5 text-slate-700">
                                        <?= htmlspecialchars($item['topic'], ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="px-4 py-3.5">
                                        <span class="inline-flex items-center rounded-full px-3.5 py-1 text-xs font-semibold <?= $badgeClass ?>">
                                            <?= htmlspecialchars($item['status_label'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

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
