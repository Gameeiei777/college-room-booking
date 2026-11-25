<?php
require __DIR__ . '/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit;
}

$adminName = $_SESSION['full_name'] ?? 'ฤทธิไกร สุดหล่อ';
$flashError = '';

function normalizeBookingStatusEnum(string $raw): string
{
    $norm = mb_strtolower(trim($raw));
    if (in_array($norm, ['approved', 'approve', 'อนุมัติ', 'อนุมัติแล้ว'], true)) {
        return 'approved';
    }
    if (in_array($norm, ['rejected', 'reject', 'ปฏิเสธ', 'ปฏิเสธแล้ว', 'ไม่อนุมัติ'], true)) {
        return 'rejected';
    }
    if (in_array($norm, ['cancelled', 'cancel', 'ยกเลิก', 'ยกเลิกแล้ว'], true)) {
        return 'cancelled';
    }
    if (in_array($norm, ['completed', 'เสร็จสิ้น', 'เสร็จสิ้นการจอง'], true)) {
        return 'completed';
    }
    return 'pending';
}

function getThaiStatusDisplay(string $enumStatus): array
{
    return match (normalizeBookingStatusEnum($enumStatus)) {
        'approved' => ['label' => 'อนุมัติแล้ว', 'kind' => 'approved'],
        'rejected' => ['label' => 'ปฏิเสธแล้ว', 'kind' => 'rejected'],
        'completed' => ['label' => 'อนุมัติแล้ว', 'kind' => 'approved'],
        default => ['label' => 'รอดำเนินการ', 'kind' => 'pending'],
    };
}

$hasBookingsTable = false;
$bookingCols = [];
if ($conn) {
    $tCheck = $conn->query("SHOW TABLES LIKE 'bookings'");
    if ($tCheck && $tCheck->num_rows > 0) {
        $hasBookingsTable = true;
        $cRes = $conn->query("SHOW COLUMNS FROM `bookings`");
        if ($cRes) {
            while ($cRow = $cRes->fetch_assoc()) {
                $bookingCols[$cRow['Field']] = true;
            }
        }
    }
}

$hasRoomsTable = false;
$roomCols = [];
if ($conn) {
    $rCheck = $conn->query("SHOW TABLES LIKE 'rooms'");
    if ($rCheck && $rCheck->num_rows > 0) {
        $hasRoomsTable = true;
        $rcRes = $conn->query("SHOW COLUMNS FROM `rooms`");
        if ($rcRes) {
            while ($rcRow = $rcRes->fetch_assoc()) {
                $roomCols[$rcRow['Field']] = true;
            }
        }
    }
}

$hasUsersTable = false;
$userCols = [];
if ($conn) {
    $uCheck = $conn->query("SHOW TABLES LIKE 'users'");
    if ($uCheck && $uCheck->num_rows > 0) {
        $hasUsersTable = true;
        $ucRes = $conn->query("SHOW COLUMNS FROM `users`");
        if ($ucRes) {
            while ($ucRow = $ucRes->fetch_assoc()) {
                $userCols[$ucRow['Field']] = true;
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_id'])) {
    $bookingId = (int) $_POST['booking_id'];
    $postAction = trim((string) ($_POST['action'] ?? 'update_status'));

    if ($bookingId > 0 && $postAction === 'delete_rejected') {
        $deleted = false;

        if ($conn && $hasBookingsTable && isset($bookingCols['id'], $bookingCols['status'])) {
            $delStmt = $conn->prepare("DELETE FROM bookings WHERE id = ? AND LOWER(TRIM(status)) IN ('rejected', 'reject', 'ปฏิเสธ', 'ปฏิเสธแล้ว') LIMIT 1");
            if ($delStmt) {
                $delStmt->bind_param('i', $bookingId);
                if ($delStmt->execute() && $delStmt->affected_rows > 0) {
                    $deleted = true;
                }
                $delStmt->close();
            }
        }

        if (!empty($_SESSION['temp_bookings'])) {
            $kept = [];
            foreach ($_SESSION['temp_bookings'] as $tb) {
                $tbId = (int) ($tb['id'] ?? 0);
                $tbEnum = normalizeBookingStatusEnum((string) ($tb['status'] ?? 'pending'));
                if ($tbId === $bookingId && $tbEnum === 'rejected') {
                    $deleted = true;
                    continue;
                }
                $kept[] = $tb;
            }
            $_SESSION['temp_bookings'] = $kept;
        }

        if ($deleted) {
            header('Location: admin_bookings.php');
            exit;
        } else {
            $flashError = 'ลบได้เฉพาะรายการที่สถานะเป็น "ปฏิเสธแล้ว" เท่านั้น';
        }
    } elseif ($bookingId > 0) {
        $rawDecision = (string) ($_POST['status_value'] ?? ($_POST['decision'] ?? ''));
        $targetEnum = normalizeBookingStatusEnum($rawDecision);
        if (!in_array($targetEnum, ['pending', 'approved', 'rejected'], true)) {
            $targetEnum = 'pending';
        }

        $updated = false;

        if ($conn && $hasBookingsTable && isset($bookingCols['status']) && isset($bookingCols['id'])) {
            $updStmt = $conn->prepare('UPDATE bookings SET status = ? WHERE id = ? LIMIT 1');
            if ($updStmt) {
                $updStmt->bind_param('si', $targetEnum, $bookingId);
                if ($updStmt->execute()) {
                    $updated = true;
                }
                $updStmt->close();
            }
        }

        if (!empty($_SESSION['temp_bookings'])) {
            foreach ($_SESSION['temp_bookings'] as $idx => $tb) {
                if ((int) ($tb['id'] ?? 0) === $bookingId) {
                    $_SESSION['temp_bookings'][$idx]['status'] = $targetEnum;
                    $updated = true;
                }
            }
        }

        if ($updated) {
            header('Location: admin_bookings.php');
            exit;
        } else {
            $flashError = 'ไม่สามารถอัปเดตสถานะการจองได้ กรุณาลองใหม่อีกครั้ง';
        }
    }
}

$bookings = [];
if ($conn && $hasBookingsTable) {
    $selectParts = ['b.id'];
    $joins = [];

    if (isset($bookingCols['room_id']) && $hasRoomsTable && isset($roomCols['id']) && isset($roomCols['room_name'])) {
        $selectParts[] = 'r.room_name AS joined_room_name';
        $joins[] = 'LEFT JOIN rooms r ON b.room_id = r.id';
    }

    if (isset($bookingCols['user_id']) && $hasUsersTable && isset($userCols['id'])) {
        if (isset($userCols['full_name'])) {
            $selectParts[] = 'u.full_name AS joined_user_name';
        }
        if (isset($userCols['email'])) {
            $selectParts[] = 'u.email AS joined_user_email';
        }
        $joins[] = 'LEFT JOIN users u ON b.user_id = u.id';
    } elseif (isset($bookingCols['booker_email']) && $hasUsersTable && isset($userCols['email'])) {
        if (isset($userCols['full_name'])) {
            $selectParts[] = 'u.full_name AS joined_user_name';
        }
        $joins[] = 'LEFT JOIN users u ON LOWER(b.booker_email) = LOWER(u.email)';
    }

    foreach (['room_id', 'room_name', 'room_type', 'user_id', 'booker_name', 'booker_email', 'topic', 'purpose', 'booking_date', 'time_slot', 'start_time', 'end_time', 'status'] as $col) {
        if (isset($bookingCols[$col])) {
            $selectParts[] = 'b.' . $col;
        }
    }

    $sql = 'SELECT ' . implode(', ', $selectParts) . ' FROM bookings b ' . implode(' ', $joins);
    if (isset($bookingCols['status'])) {
        $sql .= " WHERE LOWER(COALESCE(b.status, '')) NOT IN ('cancelled', 'cancel')";
    }
    $sql .= ' ORDER BY b.id DESC';

    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $enumVal = normalizeBookingStatusEnum((string) ($row['status'] ?? 'pending'));
            if ($enumVal === 'cancelled') {
                continue;
            }

            $bookerName = trim((string) ($row['booker_name'] ?? ''));
            if ($bookerName === '') {
                $bookerName = trim((string) ($row['joined_user_name'] ?? ''));
            }
            if ($bookerName === '') {
                $bookerName = trim((string) ($row['booker_email'] ?? ($row['joined_user_email'] ?? 'ผู้จอง')));
            }

            $roomDisplay = trim((string) ($row['room_name'] ?? ''));
            if ($roomDisplay === '') {
                $roomDisplay = trim((string) ($row['joined_room_name'] ?? ''));
            }
            if ($roomDisplay === '') {
                $roomDisplay = trim((string) ($row['room_type'] ?? 'ห้องประชุม 1'));
            }

            $timeText = trim((string) ($row['time_slot'] ?? ''));
            if ($timeText === '' && !empty($row['start_time']) && !empty($row['end_time'])) {
                $timeText = substr((string) $row['start_time'], 0, 5) . ' - ' . substr((string) $row['end_time'], 0, 5);
            }

            $rawDate = trim((string) ($row['booking_date'] ?? ''));
            $dateShort = formatShortThaiDate($rawDate);
            $dateTimeText = trim($dateShort . ($timeText !== '' ? ' ' . $timeText : ''));

            $statusDisp = getThaiStatusDisplay($enumVal);

            $bookings[] = [
                'id' => (int) ($row['id'] ?? 0),
                'booker' => $bookerName !== '' ? $bookerName : '-',
                'datetime' => $dateTimeText !== '' ? $dateTimeText : '-',
                'room' => $roomDisplay !== '' ? $roomDisplay : '-',
                'enum_status' => $enumVal,
                'status_label' => $statusDisp['label'],
                'status_kind' => $statusDisp['kind'],
            ];
        }
    }
}

function formatShortThaiDate(string $ymd): string
{
    $ymd = trim($ymd);
    if ($ymd === '') {
        return '';
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
    <title>จัดการการจอง | ระบบจองห้องประชุมและห้องคอมพิวเตอร์</title>
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
                    <a href="admin_dashboard.php" class="rounded-lg px-4 py-2 hover:bg-slate-100 hover:text-blue-700">หน้าหลัก</a>
                    <a href="admin_bookings.php" class="rounded-lg bg-[#c4c4c4] px-5 py-2.5 font-semibold text-[#14234c]">จัดการการจอง</a>
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
    <section class="bg-[#182959] py-6 text-white">
        <div class="mx-auto flex max-w-7xl items-center gap-3.5 px-6 sm:px-8">
            <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-white/40 text-lg">✓</span>
            <h1 class="text-xl font-semibold sm:text-2xl">ระบบอนุมัติการจองห้องประชุมและคอมพิวเตอร์</h1>
        </div>
    </section>

    <!-- Main Content -->
    <main class="mx-auto w-full max-w-7xl flex-1 px-6 py-8 sm:px-8">

        <?php if ($flashError !== ''): ?>
            <div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 px-5 py-4 text-base font-medium text-rose-800">
                ✕ <?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <!-- Large Bookings Table -->
        <div class="overflow-hidden rounded-2xl bg-white shadow-md border border-slate-200">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[980px] border-collapse text-left">
                    <thead>
                        <tr class="bg-[#f3f2f2] text-base sm:text-lg font-bold text-slate-900 border-b border-slate-200">
                            <th class="px-7 py-5">ผู้ขอจอง</th>
                            <th class="px-7 py-5">วันที่-เวลา</th>
                            <th class="px-7 py-5">ห้อง</th>
                            <th class="px-7 py-5 text-center">สถานะ</th>
                            <th class="px-7 py-5 text-center">เปลี่ยนสถานะ</th>
                            <th class="w-20 px-4 py-5 text-center"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 text-base">
                        <?php if (empty($bookings)): ?>
                            <tr>
                                <td colspan="6" class="py-16 text-center text-lg text-slate-500">
                                    ไม่พบรายการคำขอจองห้อง
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($bookings as $idx => $b): ?>
                                <?php
                                    $rowBg = ($idx % 2 === 0) ? 'bg-[#e6e6e6]' : 'bg-white';
                                    $statusPillClass = match ($b['status_kind']) {
                                        'approved' => 'bg-[#7ee687] text-slate-950',
                                        'rejected' => 'bg-[#e97474] text-white',
                                        default => 'bg-[#ffe36e] text-slate-900',
                                    };
                                    $canDeleteRejected = ($b['enum_status'] === 'rejected');
                                ?>
                                <tr class="<?= $rowBg ?> transition hover:brightness-[0.99]">
                                    <td class="px-7 py-5 font-medium text-slate-900">
                                        <?= htmlspecialchars($b['booker'], ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="px-7 py-5 font-medium text-slate-900">
                                        <?= htmlspecialchars($b['datetime'], ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="px-7 py-5 font-medium text-slate-900">
                                        <?= htmlspecialchars($b['room'], ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="px-7 py-5 text-center">
                                        <span class="inline-flex items-center justify-center rounded-full px-5 py-1.5 text-sm font-semibold <?= $statusPillClass ?>">
                                            <?= htmlspecialchars($b['status_label'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td class="px-7 py-5 text-center">
                                        <form method="post" action="admin_bookings.php" class="inline-flex flex-wrap items-center justify-center gap-3">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
                                            <select name="status_value"
                                                    class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-900 outline-none focus:border-blue-500">
                                                <option value="pending" <?= $b['enum_status'] === 'pending' ? 'selected' : '' ?>>รอดำเนินการ</option>
                                                <option value="approved" <?= $b['enum_status'] === 'approved' ? 'selected' : '' ?>>อนุมัติแล้ว</option>
                                                <option value="rejected" <?= $b['enum_status'] === 'rejected' ? 'selected' : '' ?>>ปฏิเสธแล้ว</option>
                                            </select>
                                            <button type="submit"
                                                    class="ml-12 mr-4 inline-flex items-center gap-1.5 rounded-xl bg-[#5475e8] px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-600">
                                                <span>บันทึก</span>
                                            </button>
                                        </form>
                                    </td>
                                    <td class="px-4 py-5 text-center">
                                        <?php if ($canDeleteRejected): ?>
                                            <form method="post" action="admin_bookings.php" class="inline"
                                                  onsubmit="return confirm('ยืนยันการลบรายการจองที่ปฏิเสธแล้วของ <?= htmlspecialchars(addslashes($b['booker']), ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars(addslashes($b['room']), ENT_QUOTES, 'UTF-8') ?>) ออกจากฐานข้อมูล ?');">
                                                <input type="hidden" name="action" value="delete_rejected">
                                                <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
                                                <button type="submit"
                                                        title="ลบรายการที่ปฏิเสธแล้ว"
                                                        class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-[#e05252] text-base text-white shadow-sm transition hover:bg-red-600">
                                                    🗑
                                                </button>
                                            </form>
                                        <?php endif; ?>
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
    <footer class="bg-[#182959] py-8 text-white">
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
