<?php
require __DIR__ . '/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header('Location: index.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$userName = trim((string) ($_SESSION['full_name'] ?? ''));
$userEmail = trim((string) ($_SESSION['email'] ?? ''));
$defaultDept = trim((string) ($_SESSION['department'] ?? 'ไม่ระบุแผนก')) ?: 'ไม่ระบุแผนก';
$justBooked = ($_GET['booked'] ?? '') === '1';
$lastSummary = $_SESSION['last_booking_summary'] ?? null;
if ($justBooked && $lastSummary) {
    unset($_SESSION['last_booking_summary']);
}

if ($conn) {
    $uCheck = $conn->query("SHOW TABLES LIKE 'users'");
    if ($uCheck && $uCheck->num_rows > 0) {
        $uStmt = $conn->prepare('SELECT email, full_name, department FROM users WHERE id = ? LIMIT 1');
        if ($uStmt) {
            $uStmt->bind_param('i', $userId);
            $uStmt->execute();
            $uRes = $uStmt->get_result();
            if ($uRow = $uRes->fetch_assoc()) {
                if (!empty($uRow['email'])) {
                    $userEmail = trim((string) $uRow['email']);
                }
                if ($userName === '' && !empty($uRow['full_name'])) {
                    $userName = trim((string) $uRow['full_name']);
                }
                if ($defaultDept === 'ไม่ระบุแผนก' && !empty($uRow['department'])) {
                    $defaultDept = trim((string) $uRow['department']);
                }
            }
            $uStmt->close();
        }
    }
}
if ($userName === '') {
    $userName = 'ผู้ใช้งาน';
}

function mapBookingStatus(string $raw): array
{
    $norm = mb_strtolower(trim($raw));
    if (in_array($norm, ['approved', 'approve', 'อนุมัติแล้ว', 'อนุมัติ'], true)) {
        return ['label' => 'อนุมัติแล้ว', 'type' => 'approved'];
    }
    if (in_array($norm, ['completed', 'เสร็จสิ้น', 'เสร็จสิ้นการจอง'], true)) {
        return ['label' => 'เสร็จสิ้นการจอง', 'type' => 'completed'];
    }
    if (in_array($norm, ['rejected', 'reject', 'ปฏิเสธแล้ว', 'ปฏิเสธ', 'ไม่อนุมัติ'], true)) {
        return ['label' => 'ปฏิเสธแล้ว', 'type' => 'cancelled'];
    }
    if (in_array($norm, ['cancelled', 'cancel', 'ยกเลิกแล้ว', 'ยกเลิก'], true)) {
        return ['label' => 'ยกเลิกแล้ว', 'type' => 'cancelled'];
    }
    return ['label' => 'รอดำเนินการ', 'type' => 'pending'];
}

$flashError = '';

$hasBookingsTable = false;
$bookingCols = [];
if ($conn) {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'bookings'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $hasBookingsTable = true;
        $colRes = $conn->query("SHOW COLUMNS FROM `bookings`");
        if ($colRes) {
            while ($cRow = $colRes->fetch_assoc()) {
                $bookingCols[$cRow['Field']] = true;
            }
        }
    }
}

// ยกเลิกได้เฉพาะรายการที่สถานะเป็น "รอดำเนินการ" (pending) เท่านั้น
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_booking') {
    $cancelId = (int) ($_POST['booking_id'] ?? 0);
    $cancelKey = trim((string) ($_POST['booking_key'] ?? ''));
    $deletedAny = false;

    if ($cancelId > 0 && $conn && $hasBookingsTable && isset($bookingCols['id'])) {
        $whereSql = 'id = ?';
        $types = 'i';
        $params = [$cancelId];

        if (isset($bookingCols['user_id']) && $userId > 0) {
            $whereSql .= ' AND user_id = ?';
            $types .= 'i';
            $params[] = $userId;
        } elseif (isset($bookingCols['booker_email']) && $userEmail !== '') {
            $whereSql .= ' AND LOWER(booker_email) = LOWER(?)';
            $types .= 's';
            $params[] = $userEmail;
        }

        if (isset($bookingCols['status'])) {
            $whereSql .= " AND LOWER(COALESCE(status, 'pending')) IN ('pending', '')";
        }

        // ดึงข้อมูลรายการที่จะลบเพื่อใช้ล้างรายการเดียวกันใน temp_bookings ด้วย
        $cancelledRowMeta = null;
        $preStmt = $conn->prepare("SELECT * FROM bookings WHERE {$whereSql} LIMIT 1");
        if ($preStmt) {
            $preStmt->bind_param($types, ...$params);
            $preStmt->execute();
            $preRes = $preStmt->get_result();
            $cancelledRowMeta = $preRes->fetch_assoc() ?: null;
            $preStmt->close();
        }

        $delStmt = $conn->prepare("DELETE FROM bookings WHERE {$whereSql} LIMIT 1");
        if ($delStmt) {
            $delStmt->bind_param($types, ...$params);
            if ($delStmt->execute() && $delStmt->affected_rows > 0) {
                $deletedAny = true;
            }
            $delStmt->close();
        }

        if ($deletedAny && $cancelledRowMeta && !empty($_SESSION['temp_bookings'])) {
            $metaRoom = trim((string) ($cancelledRowMeta['room_name'] ?? ''));
            $metaDate = trim((string) ($cancelledRowMeta['booking_date'] ?? ''));
            $metaSlot = trim((string) ($cancelledRowMeta['time_slot'] ?? ''));
            if ($metaSlot === '' && !empty($cancelledRowMeta['start_time']) && !empty($cancelledRowMeta['end_time'])) {
                $metaSlot = substr((string) $cancelledRowMeta['start_time'], 0, 5) . ' - ' . substr((string) $cancelledRowMeta['end_time'], 0, 5);
            }
            $metaTopic = trim((string) ($cancelledRowMeta['topic'] ?? ($cancelledRowMeta['purpose'] ?? '')));

            $_SESSION['temp_bookings'] = array_values(array_filter($_SESSION['temp_bookings'], function ($tb) use ($metaRoom, $metaDate, $metaSlot, $metaTopic) {
                $tbRoom = trim((string) ($tb['room_name'] ?? ''));
                $tbDate = trim((string) ($tb['booking_date'] ?? ''));
                $tbSlot = trim((string) ($tb['time_slot'] ?? ''));
                $tbTopic = trim((string) ($tb['topic'] ?? ''));
                if ($tbRoom === $metaRoom && $tbDate === $metaDate && $tbSlot === $metaSlot && $tbTopic === $metaTopic) {
                    return false;
                }
                return true;
            }));
        }
    }

    if (!empty($_SESSION['temp_bookings'])) {
        $kept = [];
        foreach ($_SESSION['temp_bookings'] as $tb) {
            $tbId = (int) ($tb['id'] ?? 0);
            $tbRoom = trim((string) ($tb['room_name'] ?? ($tb['room_type'] ?? 'ห้องประชุม 1')));
            $tbDate = (string) ($tb['booking_date'] ?? '');
            $tbSlot = trim((string) ($tb['time_slot'] ?? ''));
            $tbTopic = trim((string) ($tb['topic'] ?? '-')) ?: '-';
            $tbKey = $tbRoom . '|' . $tbDate . '|' . $tbSlot . '|' . $tbTopic;
            $tbStatus = mb_strtolower(trim((string) ($tb['status'] ?? 'pending')));

            if ((($cancelId > 0 && $tbId === $cancelId) || ($cancelKey !== '' && $tbKey === $cancelKey)) && ($tbStatus === 'pending' || $tbStatus === '')) {
                $deletedAny = true;
                continue;
            }
            $kept[] = $tb;
        }
        $_SESSION['temp_bookings'] = $kept;
    }

    if ($deletedAny) {
        header('Location: my_bookings.php');
        exit;
    } else {
        $flashError = 'ไม่สามารถยกเลิกการจองได้ (ยกเลิกได้เฉพาะรายการที่สถานะรอดำเนินการเท่านั้น)';
    }
}

$rows = [];
$seenKeys = [];

if ($conn && $hasBookingsTable) {
    $hasRoomsTable = false;
    $rCheck = $conn->query("SHOW TABLES LIKE 'rooms'");
    if ($rCheck && $rCheck->num_rows > 0) {
        $hasRoomsTable = true;
    }

    $hasUsersTable = false;
    $userCols = [];
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

    $selectParts = ['b.id'];
    $joins = [];

    if (isset($bookingCols['room_id']) && $hasRoomsTable) {
        $selectParts[] = 'r.room_name';
        $joins[] = 'LEFT JOIN rooms r ON b.room_id = r.id';
    } elseif (isset($bookingCols['room_name'])) {
        $selectParts[] = 'b.room_name';
    }

    if (isset($bookingCols['user_id']) && $hasUsersTable) {
        if (isset($userCols['full_name'])) {
            $selectParts[] = 'u.full_name';
        }
        if (isset($userCols['department'])) {
            $selectParts[] = 'u.department AS user_dept';
        }
        $joins[] = 'LEFT JOIN users u ON b.user_id = u.id';
    } elseif (isset($bookingCols['booker_email']) && $hasUsersTable && isset($userCols['email'])) {
        if (isset($userCols['full_name'])) {
            $selectParts[] = 'u.full_name';
        }
        if (isset($userCols['department'])) {
            $selectParts[] = 'u.department AS user_dept';
        }
        $joins[] = 'LEFT JOIN users u ON LOWER(b.booker_email) = LOWER(u.email)';
    }

    foreach (['building_name', 'room_type', 'topic', 'purpose', 'booker_email', 'department', 'booking_date', 'time_slot', 'start_time', 'end_time', 'status', 'created_at'] as $cName) {
        if (isset($bookingCols[$cName])) {
            $selectParts[] = 'b.' . $cName;
        }
    }

    $sql = 'SELECT ' . implode(', ', $selectParts) . ' FROM bookings b ' . implode(' ', $joins);

    $stmt = null;
    if (isset($bookingCols['user_id']) && $userId > 0) {
        $sql .= ' WHERE b.user_id = ?';
        if (isset($bookingCols['status'])) {
            $sql .= " AND LOWER(COALESCE(b.status, '')) NOT IN ('cancelled', 'cancel')";
        }
        $sql .= ' ORDER BY b.id DESC';
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $userId);
        }
    } elseif (isset($bookingCols['booker_email']) && $userEmail !== '') {
        $sql .= ' WHERE LOWER(b.booker_email) = LOWER(?)';
        if (isset($bookingCols['status'])) {
            $sql .= " AND LOWER(COALESCE(b.status, '')) NOT IN ('cancelled', 'cancel')";
        }
        $sql .= ' ORDER BY b.id DESC';
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('s', $userEmail);
        }
    }

    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $rawStatusNorm = mb_strtolower(trim((string) ($r['status'] ?? '')));
            if (in_array($rawStatusNorm, ['cancelled', 'cancel', 'ยกเลิก', 'ยกเลิกแล้ว'], true)) {
                continue;
            }

            $timeText = trim((string) ($r['time_slot'] ?? ''));
            if ($timeText === '' && !empty($r['start_time']) && !empty($r['end_time'])) {
                $timeText = substr((string) $r['start_time'], 0, 5) . ' - ' . substr((string) $r['end_time'], 0, 5);
            }

            $dateRaw = (string) ($r['booking_date'] ?? '');
            $dateFormatted = formatShortThaiDate($dateRaw);
            $dateTimeLabel = trim($dateFormatted . ($timeText !== '' ? ' (' . $timeText . ')' : ''));

            $statusInfo = mapBookingStatus((string) ($r['status'] ?? 'pending'));

            $rawRoomName = trim((string) ($r['room_name'] ?? ''));
            if ($rawRoomName === '') {
                $rawRoomName = trim((string) ($r['room_type'] ?? 'ห้องประชุม 1'));
            }
            $roomDisplay = $rawRoomName;
            $buildingDisplay = trim((string) ($r['building_name'] ?? ''));
            if ($buildingDisplay !== '' && mb_strpos($roomDisplay, $buildingDisplay) === false) {
                $roomDisplay .= ' (' . $buildingDisplay . ')';
            }

            $deptDisplay = trim((string) ($r['department'] ?? ''));
            if ($deptDisplay === '') {
                $deptDisplay = trim((string) ($r['user_dept'] ?? ''));
            }
            if ($deptDisplay === '') {
                $deptDisplay = $defaultDept;
            }

            $topicVal = trim((string) ($r['topic'] ?? ($r['purpose'] ?? '-'))) ?: '-';
            $dedupKey = $rawRoomName . '|' . $dateRaw . '|' . $timeText . '|' . $topicVal;
            $seenKeys[$dedupKey] = true;

            $rows[] = [
                'id' => (int) ($r['id'] ?? 0),
                'dedup_key' => $dedupKey,
                'room_display' => $roomDisplay,
                'topic' => $topicVal,
                'user_name' => trim((string) ($r['full_name'] ?? $userName)) ?: $userName,
                'department' => $deptDisplay,
                'datetime_text' => $dateTimeLabel !== '' ? $dateTimeLabel : '-',
                'status_label' => $statusInfo['label'],
                'status_type' => $statusInfo['type'],
            ];
        }
        $stmt->close();
    }
}

if (!empty($_SESSION['temp_bookings'])) {
    foreach (array_reverse($_SESSION['temp_bookings']) as $tb) {
        $tbStatusNorm = mb_strtolower(trim((string) ($tb['status'] ?? 'pending')));
        if (in_array($tbStatusNorm, ['cancelled', 'cancel', 'ยกเลิก', 'ยกเลิกแล้ว'], true)) {
            continue;
        }

        $rawDate = (string) ($tb['booking_date'] ?? '');
        $tLabel = trim((string) ($tb['time_slot'] ?? ''));
        $roomDisplay = trim((string) ($tb['room_name'] ?? ($tb['room_type'] ?? 'ห้องประชุม 1')));
        $topicVal = trim((string) ($tb['topic'] ?? '-')) ?: '-';

        $dedupKey = $roomDisplay . '|' . $rawDate . '|' . $tLabel . '|' . $topicVal;
        if (isset($seenKeys[$dedupKey])) {
            continue;
        }

        $dLabel = formatShortThaiDate($rawDate);
        $statusInfo = mapBookingStatus((string) ($tb['status'] ?? 'pending'));

        $rows[] = [
            'id' => (int) ($tb['id'] ?? 0),
            'dedup_key' => $dedupKey,
            'room_display' => $roomDisplay,
            'topic' => $topicVal,
            'user_name' => trim((string) ($tb['user_name'] ?? $userName)) ?: $userName,
            'department' => trim((string) ($tb['department'] ?? $defaultDept)) ?: $defaultDept,
            'datetime_text' => trim($dLabel . ($tLabel !== '' ? ' (' . $tLabel . ')' : '')) ?: '-',
            'status_label' => $statusInfo['label'],
            'status_type' => $statusInfo['type'],
        ];
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
    <title>ประวัติการจองห้อง | ระบบจองห้องประชุมและห้องคอมพิวเตอร์</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Prompt', sans-serif; }
    </style>
</head>
<body class="min-h-screen bg-[#f2f2f2] text-slate-900 flex flex-col">

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
                    <a href="teacher_dashboard.php" class="rounded-lg px-4 py-2 hover:bg-slate-100 hover:text-blue-700">หน้าหลัก</a>
                    <a href="my_bookings.php" class="rounded-lg bg-[#c4c4c4] px-5 py-2.5 font-semibold text-[#14234c]">ประวัติการจอง</a>
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

    <!-- Main Content -->
    <main class="mx-auto w-full max-w-7xl flex-1 px-6 py-10 sm:px-8">

        <?php if ($flashError !== ''): ?>
            <div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 px-5 py-3.5 text-sm font-medium text-rose-800">
                ✕ <?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($justBooked): ?>
            <div class="mb-6 flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-emerald-200 bg-white px-6 py-4 shadow-sm">
                <div class="flex items-center gap-3">
                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-emerald-100 text-lg font-bold text-emerald-700">✓</span>
                    <div>
                        <div class="text-base font-semibold text-emerald-800">ส่งคำขอจองห้องเรียบร้อยแล้ว</div>
                        <?php if ($lastSummary): ?>
                            <div class="text-sm text-slate-600">
                                <?= htmlspecialchars($lastSummary['room_name'], ENT_QUOTES, 'UTF-8') ?> •
                                หัวข้อ: <?= htmlspecialchars($lastSummary['topic'], ENT_QUOTES, 'UTF-8') ?> •
                                วันที่ <?= htmlspecialchars(formatShortThaiDate($lastSummary['booking_date']), ENT_QUOTES, 'UTF-8') ?>
                                (<?= htmlspecialchars(implode(', ', $lastSummary['time_slots'] ?? []), ENT_QUOTES, 'UTF-8') ?>) •
                                สถานะ: <?= htmlspecialchars($lastSummary['status'] ?? 'รอดำเนินการ', ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <a href="teacher_dashboard.php"
                   class="rounded-xl bg-[#14234c] px-4 py-2 text-sm font-medium text-white hover:bg-blue-900">
                    จองห้องเพิ่ม
                </a>
            </div>
        <?php endif; ?>

        <!-- Page Heading -->
        <div class="flex items-center gap-4">
            <span class="inline-flex h-12 w-12 items-center justify-center rounded-full border-4 border-slate-900 text-xl font-bold">↺</span>
            <h1 class="text-3xl font-bold text-slate-900 sm:text-4xl">ประวัติการจองห้อง</h1>
        </div>

        <!-- Table Card -->
        <div class="mt-7 overflow-hidden rounded-2xl bg-white p-6 shadow-sm border border-slate-200">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[880px] border-collapse text-center">
                    <thead>
                        <tr class="bg-[#d9d9d9] text-base font-semibold text-slate-900">
                            <th class="rounded-tl-xl px-4 py-4">ห้องที่จอง</th>
                            <th class="px-4 py-4">หัวข้อ</th>
                            <th class="px-4 py-4">ผู้จอง</th>
                            <th class="px-4 py-4">แผนก</th>
                            <th class="px-4 py-4">วันเวลาที่จอง</th>
                            <th class="px-4 py-4">สถานะการจอง</th>
                            <th class="rounded-tr-xl px-4 py-4">ยกเลิกการจอง</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-base">
                        <?php if (empty($rows)): ?>
                            <tr>
                                <td colspan="7" class="py-12 text-slate-500">
                                    ยังไม่มีประวัติการจองห้อง
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                    $badgeClass = match ($row['status_type']) {
                                        'cancelled' => 'bg-rose-100 text-rose-700 border-rose-200',
                                        'pending' => 'bg-amber-100 text-amber-800 border-amber-200',
                                        'approved' => 'bg-emerald-100 text-emerald-700 border-emerald-200',
                                        default => 'bg-blue-100 text-blue-700 border-blue-200',
                                    };
                                    $canCancel = ($row['status_type'] === 'pending');
                                ?>
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="px-4 py-4 font-medium text-slate-800">
                                        <?= htmlspecialchars($row['room_display'], ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="px-4 py-4 text-slate-700">
                                        <?= htmlspecialchars($row['topic'], ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="px-4 py-4 text-slate-700">
                                        <?= htmlspecialchars($row['user_name'], ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="px-4 py-4 text-slate-700">
                                        <?= htmlspecialchars($row['department'], ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="px-4 py-4 text-slate-700">
                                        <?= htmlspecialchars($row['datetime_text'], ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="px-4 py-4">
                                        <span class="inline-flex items-center rounded-full border px-3.5 py-1 text-sm font-semibold <?= $badgeClass ?>">
                                            <?= htmlspecialchars($row['status_label'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-4">
                                        <?php if ($canCancel): ?>
                                            <form method="post" action="my_bookings.php" class="inline"
                                                  onsubmit="return confirm('ยืนยันการยกเลิกการจองห้อง <?= htmlspecialchars(addslashes($row['room_display']), ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars(addslashes($row['datetime_text']), ENT_QUOTES, 'UTF-8') ?>) ?');">
                                                <input type="hidden" name="action" value="cancel_booking">
                                                <input type="hidden" name="booking_id" value="<?= (int) $row['id'] ?>">
                                                <input type="hidden" name="booking_key" value="<?= htmlspecialchars($row['dedup_key'], ENT_QUOTES, 'UTF-8') ?>">
                                                <button type="submit"
                                                        class="inline-flex items-center gap-1 rounded-xl bg-[#e05252] px-3.5 py-1.5 text-sm font-semibold text-white shadow-sm transition hover:bg-red-600">
                                                    <span>ยกเลิกการจอง</span>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-sm text-slate-400">-</span>
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
