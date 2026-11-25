<?php
require __DIR__ . '/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header('Location: index.php');
    exit;
}

$roomId = (int) ($_GET['room_id'] ?? $_POST['room_id'] ?? 1);
$userId = (int) $_SESSION['user_id'];
$userName = trim((string) ($_SESSION['full_name'] ?? 'ฤทธิไกร อ่ำเกิด'));
$userDept = trim((string) ($_SESSION['department'] ?? 'เทคโนโลยีสารสนเทศ'));

// ดึงอีเมลและแผนกของผู้ใช้จาก session หรือตาราง users ให้ตรงกับ Foreign Key booker_email
$userEmail = trim((string) ($_SESSION['email'] ?? ''));
if ($conn) {
    $uStmt = $conn->prepare('SELECT email, full_name, department FROM users WHERE id = ? LIMIT 1');
    if ($uStmt) {
        $uStmt->bind_param('i', $userId);
        $uStmt->execute();
        $uRes = $uStmt->get_result();
        if ($uRow = $uRes->fetch_assoc()) {
            if ($userEmail === '' && !empty($uRow['email'])) {
                $userEmail = trim((string) $uRow['email']);
            }
            if (!empty($uRow['full_name'])) {
                $userName = trim((string) $uRow['full_name']);
            }
            if (!empty($uRow['department'])) {
                $userDept = trim((string) $uRow['department']);
            }
        }
        $uStmt->close();
    }
}
if ($userEmail === '' && $conn) {
    $fallbackUser = $conn->query('SELECT email FROM users LIMIT 1');
    if ($fallbackUser && ($fRow = $fallbackUser->fetch_assoc())) {
        $userEmail = trim((string) ($fRow['email'] ?? ''));
    }
}

// ดึงข้อมูลห้องจากฐานข้อมูล
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

$fallbackRooms = [
    1 => ['id' => 1, 'building_name' => 'อาคาร 1', 'room_name' => 'ห้องประชุม 1', 'details' => 'ห้องประชุมขนาดกลาง พร้อมโปรเจกเตอร์และระบบเสียง', 'capacity' => 30],
    2 => ['id' => 2, 'building_name' => 'อาคาร 1', 'room_name' => 'ห้องประชุม 2', 'details' => 'ห้องประชุมรูปตัวยู พร้อมไมโครโฟนและจอแสดงผล', 'capacity' => 40],
    3 => ['id' => 3, 'building_name' => 'อาคาร 2', 'room_name' => 'ห้องคอมพิวเตอร์ 1', 'details' => 'ห้องปฏิบัติการคอมพิวเตอร์ พร้อมเครื่องคอมพิวเตอร์และโปรเจกเตอร์', 'capacity' => 40],
];

if (!$room) {
    $room = $fallbackRooms[$roomId] ?? $fallbackRooms[1];
    $roomId = (int) $room['id'];
}

$roomName = trim((string) ($room['room_name'] ?? 'ห้องประชุม 1'));
if ($conn && $roomName !== '') {
    $chkRoom = $conn->prepare('SELECT room_name FROM rooms WHERE room_name = ? LIMIT 1');
    if ($chkRoom) {
        $chkRoom->bind_param('s', $roomName);
        $chkRoom->execute();
        $chkRes = $chkRoom->get_result();
        if (!$chkRes->fetch_assoc()) {
            $firstRoom = $conn->query('SELECT room_name FROM rooms LIMIT 1');
            if ($firstRoom && ($frRow = $firstRoom->fetch_assoc())) {
                $roomName = (string) $frRow['room_name'];
            }
        }
        $chkRoom->close();
    }
}

$defaultRoomType = (mb_strpos($roomName, 'คอมพิวเตอร์') !== false) ? 'ห้องคอมพิวเตอร์' : 'ห้องประชุม';

$timeSlots = [
    '08:00 - 09:00',
    '09:00 - 10:00',
    '10:00 - 11:00',
    '12:00 - 13:00',
    '13:00 - 14:00',
    '15:00 - 16:00',
];

$selectedDate = trim((string) ($_GET['date'] ?? $_POST['booking_date'] ?? '2026-11-04'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = '2026-11-04';
}

$selectedType = trim((string) ($_GET['room_type'] ?? $_POST['room_type'] ?? $defaultRoomType));
$topic = trim((string) ($_POST['topic'] ?? ''));

// รับช่วงเวลาที่เลือกได้หลายค่า
$selectedSlots = [];
if (isset($_POST['time_slots']) && is_array($_POST['time_slots'])) {
    foreach ($_POST['time_slots'] as $s) {
        $s = trim((string) $s);
        if (in_array($s, $timeSlots, true) && !in_array($s, $selectedSlots, true)) {
            $selectedSlots[] = $s;
        }
    }
} elseif (!empty($_POST['time_slot'])) {
    foreach (explode(',', (string) $_POST['time_slot']) as $s) {
        $s = trim($s);
        if (in_array($s, $timeSlots, true) && !in_array($s, $selectedSlots, true)) {
            $selectedSlots[] = $s;
        }
    }
}

$flashError = '';

// ตรวจสอบคอลัมน์ในตาราง bookings
$bookingColumns = [];
$hasBookingsTable = false;
if ($conn) {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'bookings'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $hasBookingsTable = true;
        $colRes = $conn->query("SHOW COLUMNS FROM `bookings`");
        if ($colRes) {
            while ($cRow = $colRes->fetch_assoc()) {
                $bookingColumns[$cRow['Field']] = true;
            }
        }
    }
}

$hasRoomIdCol = $hasBookingsTable && isset($bookingColumns['room_id']);
$hasRoomNameCol = $hasBookingsTable && isset($bookingColumns['room_name']);
$hasUserIdCol = $hasBookingsTable && isset($bookingColumns['user_id']);
$hasBookerEmailCol = $hasBookingsTable && isset($bookingColumns['booker_email']);
$hasTimeSlotCol = $hasBookingsTable && isset($bookingColumns['time_slot']);
$hasStartEndCols = $hasBookingsTable && isset($bookingColumns['start_time']) && isset($bookingColumns['end_time']);
$hasBookingDateCol = $hasBookingsTable && isset($bookingColumns['booking_date']);
$hasStatusCol = $hasBookingsTable && isset($bookingColumns['status']);

// ดึงช่วงเวลาที่ไม่ว่างของห้องและวันที่เลือก
// กฎกันสแปม:
// - สถานะ approved / completed -> ไม่ว่างสำหรับทุกคน
// - สถานะ pending (รอดำเนินการ) -> ไม่ว่างเฉพาะผู้จองคนเดิม (ต้องไปยกเลิกในประวัติก่อน) ส่วนคนอื่นยังจองเวลาเดียวกันได้
// - สถานะ rejected / cancelled -> ว่างสำหรับทุกคน
$bookedSlots = [];
$approvedBookedSlots = [];
$myPendingBookedSlots = [];

$isRowOwnedByCurrentUser = function (array $row) use ($hasUserIdCol, $hasBookerEmailCol, $userId, $userEmail): bool {
    if ($hasUserIdCol && isset($row['user_id']) && (int) $row['user_id'] > 0) {
        return (int) $row['user_id'] === $userId;
    }
    if ($hasBookerEmailCol && !empty($row['booker_email']) && $userEmail !== '') {
        return mb_strtolower(trim((string) $row['booker_email'])) === mb_strtolower($userEmail);
    }
    return false;
};

$isApprovedStatus = function (string $statusRaw): bool {
    $norm = mb_strtolower(trim($statusRaw));
    return in_array($norm, ['approved', 'approve', 'completed', 'อนุมัติ', 'อนุมัติแล้ว', 'เสร็จสิ้น', 'เสร็จสิ้นการจอง'], true);
};

$isRejectedOrCancelledStatus = function (string $statusRaw): bool {
    $norm = mb_strtolower(trim($statusRaw));
    return in_array($norm, ['rejected', 'reject', 'cancelled', 'cancel', 'ไม่อนุมัติ', 'ปฏิเสธ', 'ปฏิเสธแล้ว', 'ยกเลิก', 'ยกเลิกแล้ว'], true);
};

if ($hasBookingsTable && $hasBookingDateCol && ($hasTimeSlotCol || $hasStartEndCols)) {
    $whereParts = ['booking_date = ?'];
    $bTypes = 's';
    $bParams = [$selectedDate];

    if ($hasRoomIdCol) {
        array_unshift($whereParts, 'room_id = ?');
        $bTypes = 'i' . $bTypes;
        array_unshift($bParams, $roomId);
    } elseif ($hasRoomNameCol) {
        array_unshift($whereParts, 'room_name = ?');
        $bTypes = 's' . $bTypes;
        array_unshift($bParams, $roomName);
    }

    if ($hasStatusCol) {
        $whereParts[] = "LOWER(COALESCE(status, 'pending')) NOT IN ('rejected', 'reject', 'cancelled', 'cancel')";
    }

    $selectFields = [];
    if ($hasTimeSlotCol) {
        $selectFields[] = 'time_slot';
    }
    if ($hasStartEndCols) {
        $selectFields[] = 'start_time';
        $selectFields[] = 'end_time';
    }
    if ($hasStatusCol) {
        $selectFields[] = 'status';
    }
    if ($hasUserIdCol) {
        $selectFields[] = 'user_id';
    }
    if ($hasBookerEmailCol) {
        $selectFields[] = 'booker_email';
    }

    $bSql = 'SELECT ' . implode(', ', $selectFields) . ' FROM bookings WHERE ' . implode(' AND ', $whereParts);
    $bStmt = $conn->prepare($bSql);
    if ($bStmt) {
        $bStmt->bind_param($bTypes, ...$bParams);
        $bStmt->execute();
        $bRes = $bStmt->get_result();
        while ($bRow = $bRes->fetch_assoc()) {
            $rowStatus = (string) ($bRow['status'] ?? 'pending');
            if ($isRejectedOrCancelledStatus($rowStatus)) {
                continue;
            }

            $matchedSlots = [];
            if ($hasTimeSlotCol && !empty($bRow['time_slot'])) {
                $matchedSlots[] = trim((string) $bRow['time_slot']);
            } elseif ($hasStartEndCols) {
                $st = substr((string) ($bRow['start_time'] ?? ''), 0, 5);
                $en = substr((string) ($bRow['end_time'] ?? ''), 0, 5);
                if ($st !== '' && $en !== '') {
                    foreach ($timeSlots as $slotCandidate) {
                        [$candStart, $candEnd] = array_map('trim', explode('-', $slotCandidate));
                        if ($candStart < $en && $candEnd > $st) {
                            $matchedSlots[] = $slotCandidate;
                        }
                    }
                }
            }

            $isApproved = $isApprovedStatus($rowStatus);
            $isMine = $isRowOwnedByCurrentUser($bRow);

            foreach ($matchedSlots as $ms) {
                if ($isApproved) {
                    $bookedSlots[] = $ms;
                    $approvedBookedSlots[] = $ms;
                } elseif ($isMine) {
                    $bookedSlots[] = $ms;
                    $myPendingBookedSlots[] = $ms;
                }
            }
        }
        $bStmt->close();
    }
}

if (!empty($_SESSION['temp_bookings'])) {
    foreach ($_SESSION['temp_bookings'] as $tb) {
        $tbStatus = (string) ($tb['status'] ?? 'pending');
        if ($isRejectedOrCancelledStatus($tbStatus)) {
            continue;
        }
        if ((int) ($tb['room_id'] ?? 0) === $roomId && ($tb['booking_date'] ?? '') === $selectedDate) {
            $tbIsApproved = $isApprovedStatus($tbStatus);
            $tbEmail = trim((string) ($tb['booker_email'] ?? ''));
            $tbIsMine = ($tbEmail !== '' && $userEmail !== '')
                ? (mb_strtolower($tbEmail) === mb_strtolower($userEmail))
                : true;

            foreach ((array) ($tb['time_slots'] ?? [$tb['time_slot'] ?? '']) as $ts) {
                if ($ts !== '') {
                    if ($tbIsApproved) {
                        $bookedSlots[] = $ts;
                        $approvedBookedSlots[] = $ts;
                    } elseif ($tbIsMine) {
                        $bookedSlots[] = $ts;
                        $myPendingBookedSlots[] = $ts;
                    }
                }
            }
        }
    }
}
$bookedSlots = array_values(array_unique($bookedSlots));
$approvedBookedSlots = array_values(array_unique($approvedBookedSlots));
$myPendingBookedSlots = array_values(array_unique($myPendingBookedSlots));

// บันทึกการจองเมื่อกด "ยืนยันการจอง"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'book') {
    $validSlotsToBook = array_values(array_filter($selectedSlots, function ($s) use ($bookedSlots) {
        return !in_array($s, $bookedSlots, true);
    }));

    $pickedMyPending = array_values(array_intersect($selectedSlots, $myPendingBookedSlots));
    $pickedApproved = array_values(array_intersect($selectedSlots, $approvedBookedSlots));

    if ($topic === '') {
        $flashError = 'กรุณากรอกหัวข้อการจอง';
    } elseif (empty($validSlotsToBook)) {
        if (!empty($pickedMyPending)) {
            $flashError = 'คุณได้จองช่วงเวลานี้ไว้แล้ว (รอดำเนินการ) หากต้องการจองใหม่กรุณายกเลิกการจองเดิมในหน้าประวัติการจอง';
        } elseif (!empty($pickedApproved)) {
            $flashError = 'ช่วงเวลาที่เลือกได้รับการอนุมัติแล้ว กรุณาเลือกช่วงเวลาอื่น';
        } else {
            $flashError = 'กรุณาคลิกเลือกช่วงเวลาที่ว่างอย่างน้อย 1 ช่วงเวลาก่อนกดยืนยันการจอง';
        }
    } else {
        $savedCount = 0;

        if ($hasBookingsTable && $hasBookingDateCol && ($hasTimeSlotCol || $hasStartEndCols)) {
            foreach ($validSlotsToBook as $slotItem) {
                $cols = [];
                $placeholders = [];
                $types = '';
                $params = [];

                if ($hasRoomIdCol) {
                    $cols[] = 'room_id';
                    $placeholders[] = '?';
                    $types .= 'i';
                    $params[] = $roomId;
                }
                if ($hasRoomNameCol) {
                    $cols[] = 'room_name';
                    $placeholders[] = '?';
                    $types .= 's';
                    $params[] = $roomName;
                }
                if (isset($bookingColumns['user_id'])) {
                    $cols[] = 'user_id';
                    $placeholders[] = '?';
                    $types .= 'i';
                    $params[] = $userId;
                }
                if (isset($bookingColumns['booker_name'])) {
                    $cols[] = 'booker_name';
                    $placeholders[] = '?';
                    $types .= 's';
                    $params[] = $userName;
                }
                if (isset($bookingColumns['booker_email']) && $userEmail !== '') {
                    $cols[] = 'booker_email';
                    $placeholders[] = '?';
                    $types .= 's';
                    $params[] = $userEmail;
                }
                if (isset($bookingColumns['department'])) {
                    $cols[] = 'department';
                    $placeholders[] = '?';
                    $types .= 's';
                    $params[] = $userDept;
                }
                if (isset($bookingColumns['room_type'])) {
                    $cols[] = 'room_type';
                    $placeholders[] = '?';
                    $types .= 's';
                    $params[] = $selectedType;
                }
                if (isset($bookingColumns['topic'])) {
                    $cols[] = 'topic';
                    $placeholders[] = '?';
                    $types .= 's';
                    $params[] = $topic;
                } elseif (isset($bookingColumns['purpose'])) {
                    $cols[] = 'purpose';
                    $placeholders[] = '?';
                    $types .= 's';
                    $params[] = $topic;
                }

                $cols[] = 'booking_date';
                $placeholders[] = '?';
                $types .= 's';
                $params[] = $selectedDate;

                if ($hasTimeSlotCol) {
                    $cols[] = 'time_slot';
                    $placeholders[] = '?';
                    $types .= 's';
                    $params[] = $slotItem;
                } elseif ($hasStartEndCols) {
                    [$startPart, $endPart] = array_map('trim', explode('-', $slotItem));
                    $cols[] = 'start_time';
                    $placeholders[] = '?';
                    $types .= 's';
                    $params[] = $startPart . ':00';

                    $cols[] = 'end_time';
                    $placeholders[] = '?';
                    $types .= 's';
                    $params[] = $endPart . ':00';
                }

                // ใช้ค่า 'pending' ให้ตรงกับ enum('pending','approved','rejected','completed')
                if ($hasStatusCol) {
                    $cols[] = 'status';
                    $placeholders[] = '?';
                    $types .= 's';
                    $params[] = 'pending';
                }

                $insSql = 'INSERT INTO bookings (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')';
                $insStmt = $conn->prepare($insSql);
                if ($insStmt) {
                    $insStmt->bind_param($types, ...$params);
                    if ($insStmt->execute()) {
                        $savedCount++;
                    }
                    $insStmt->close();
                }
            }
        }

        if (!isset($_SESSION['temp_bookings'])) {
            $_SESSION['temp_bookings'] = [];
        }
        foreach ($validSlotsToBook as $slotItem) {
            $_SESSION['temp_bookings'][] = [
                'room_id' => $roomId,
                'room_name' => $roomName,
                'room_type' => $selectedType,
                'topic' => $topic,
                'user_name' => $userName,
                'booker_email' => $userEmail,
                'department' => $userDept,
                'booking_date' => $selectedDate,
                'time_slot' => $slotItem,
                'status' => 'pending',
            ];
            if ($savedCount === 0) {
                $savedCount++;
            }
        }

        if ($savedCount > 0) {
            $_SESSION['last_booking_summary'] = [
                'room_name' => $roomName,
                'room_type' => $selectedType,
                'topic' => $topic,
                'booking_date' => $selectedDate,
                'time_slots' => $validSlotsToBook,
                'status' => 'รอดำเนินการ',
            ];
            header('Location: http://localhost/booking/teacher_dashboard.php?booked=1');
            exit;
        } else {
            $flashError = 'ไม่สามารถบันทึกการจองได้ กรุณาลองใหม่อีกครั้ง';
        }
    }
}

function formatThaiDate(string $ymd): string
{
    $thaiMonths = [
        1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
        5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
        9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม',
    ];
    $parts = explode('-', $ymd);
    if (count($parts) !== 3) {
        return $ymd;
    }
    $day = (int) $parts[2];
    $month = (int) $parts[1];
    $yearBE = (int) $parts[0] + 543;
    $monthName = $thaiMonths[$month] ?? '';
    return "วันที่ {$day} {$monthName} {$yearBE}";
}

$ymdParts = explode('-', $selectedDate);
$calYear = (int) ($ymdParts[0] ?? 2026);
$calMonth = (int) ($ymdParts[1] ?? 11);
$calDay = (int) ($ymdParts[2] ?? 4);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จอง<?= htmlspecialchars($roomName, ENT_QUOTES, 'UTF-8') ?> | ระบบจองห้องประชุมและห้องคอมพิวเตอร์</title>
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

            <div class="flex items-center gap-8">
                <nav class="hidden md:flex items-center gap-8 text-base font-medium text-[#14234c]">
                    <a href="teacher_dashboard.php" class="hover:text-blue-700">หน้าหลัก</a>
                    <a href="my_bookings.php" class="hover:text-blue-700">ประวัติการจอง</a>
                    <a href="contact.php" class="hover:text-blue-700">ติดต่อเรา</a>
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
            <span class="text-2xl">🗓️</span>
            <h1 class="text-xl font-semibold sm:text-2xl">ระบบจองห้องประชุมและห้องคอมพิวเตอร์</h1>
        </div>
    </section>

    <!-- Main Content -->
    <main class="mx-auto w-full max-w-7xl flex-1 px-6 py-10 sm:px-8">

        <?php if ($flashError !== ''): ?>
            <div class="mb-6 rounded-xl border border-red-300 bg-red-50 px-5 py-4 text-sm font-medium text-red-700">
                <?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form id="bookingForm" action="Reserve.php?room_id=<?= $roomId ?>" method="post"
              class="flex flex-col lg:flex-row items-stretch gap-8">
            <input type="hidden" name="action" value="book">
            <input type="hidden" name="room_id" value="<?= $roomId ?>">
            <input type="hidden" id="booking_date" name="booking_date" value="<?= htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8') ?>">
            <div id="selected_slots_inputs">
                <?php foreach ($selectedSlots as $preSlot): ?>
                    <input type="hidden" name="time_slots[]" value="<?= htmlspecialchars($preSlot, ENT_QUOTES, 'UTF-8') ?>">
                <?php endforeach; ?>
            </div>

            <!-- Left Column: Calendar + Room Type + Topic -->
            <div class="w-full lg:w-1/2 rounded-2xl bg-white p-7 shadow-sm border border-slate-100">
                <h2 class="text-2xl font-semibold text-slate-900">
                    ปฏิทิน<?= htmlspecialchars($roomName, ENT_QUOTES, 'UTF-8') ?>
                </h2>

                <!-- Calendar Box -->
                <div class="mt-5 rounded-xl bg-[#e6e6e6] p-4">
                    <div class="flex items-center justify-between px-2 pb-3 text-lg font-medium text-slate-800">
                        <button type="button" onclick="changeMonth(-1)" class="px-2 py-1 text-slate-600 hover:text-slate-950">&lt;</button>
                        <div id="calendar_title">พฤศจิกายน 2569</div>
                        <button type="button" onclick="changeMonth(1)" class="px-2 py-1 text-slate-600 hover:text-slate-950">&gt;</button>
                    </div>

                    <div class="grid grid-cols-7 border-t border-slate-300 pt-2 text-center text-sm font-medium text-slate-700">
                        <div>จ</div>
                        <div>อ</div>
                        <div>พ</div>
                        <div>พฤ</div>
                        <div>ศ</div>
                        <div>ส</div>
                        <div>อา</div>
                    </div>

                    <div id="calendar_days" class="mt-2 grid grid-cols-7 gap-1.5 text-center text-base"></div>
                </div>

                <!-- Room Type & Topic -->
                <div class="mt-6 grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <label for="room_type" class="mb-2 block text-base font-medium text-slate-900">ประเภทห้อง</label>
                        <select id="room_type" name="room_type"
                                class="w-full rounded-xl border border-slate-300 bg-[#e6e6e6] px-4 py-3 text-sm text-slate-900 outline-none focus:border-blue-500">
                            <option value="ห้องประชุม" <?= $selectedType === 'ห้องประชุม' ? 'selected' : '' ?>>ห้องประชุม</option>
                            <option value="ห้องคอมพิวเตอร์" <?= $selectedType === 'ห้องคอมพิวเตอร์' ? 'selected' : '' ?>>ห้องคอมพิวเตอร์</option>
                        </select>
                    </div>

                    <div>
                        <label for="topic" class="mb-2 block text-base font-medium text-slate-900">หัวข้อ</label>
                        <input id="topic" name="topic" type="text"
                               value="<?= htmlspecialchars($topic, ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="ระบุหัวข้อการจอง"
                               class="w-full rounded-xl border border-slate-300 bg-[#e6e6e6] px-4 py-3 text-sm text-slate-900 outline-none focus:border-blue-500">
                    </div>
                </div>
            </div>

            <!-- Right Column: Time Slots + Action Buttons -->
            <div class="w-full lg:w-1/2 flex flex-col justify-between rounded-2xl bg-white p-7 shadow-sm border border-slate-100">
                <div>
                    <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 pb-4">
                        <div>
                            <h2 id="selected_date_label" class="text-2xl font-semibold text-slate-900">
                                <?= htmlspecialchars(formatThaiDate($selectedDate), ENT_QUOTES, 'UTF-8') ?>
                            </h2>
                            <p class="mt-1 text-xs text-slate-500">คลิกที่แถบสีเขียวเพื่อเลือกได้หลายช่วงเวลา</p>
                        </div>

                        <div class="flex items-center gap-5 text-base font-medium text-slate-900">
                            <span class="flex items-center gap-2">
                                <span class="inline-block h-7 w-7 rounded-md bg-[#7ee687]"></span>
                                <span>ว่าง</span>
                            </span>
                            <span class="flex items-center gap-2">
                                <span class="inline-block h-7 w-7 rounded-md bg-[#f58a8a]"></span>
                                <span>ไม่ว่าง</span>
                            </span>
                        </div>
                    </div>

                    <!-- Time Slot Bars -->
                    <div class="mt-6 space-y-4">
                        <?php foreach ($timeSlots as $slot): ?>
                            <?php
                                $isApprovedBooked = in_array($slot, $approvedBookedSlots, true);
                                $isMyPending = in_array($slot, $myPendingBookedSlots, true);
                                $isBooked = in_array($slot, $bookedSlots, true);
                                $isPicked = (!$isBooked && in_array($slot, $selectedSlots, true));
                                $barClass = $isBooked
                                    ? 'bg-[#f58a8a] text-white text-sm font-medium cursor-not-allowed'
                                    : ($isPicked
                                        ? 'bg-[#15803d] ring-4 ring-emerald-300 border-2 border-[#052e16] shadow-md text-white font-semibold cursor-pointer'
                                        : 'bg-[#7ee687] hover:bg-[#65d970] cursor-pointer');
                                $slotTitle = $isApprovedBooked
                                    ? 'ไม่ว่าง (ได้รับการอนุมัติแล้ว)'
                                    : ($isMyPending
                                        ? 'คุณจองเวลานี้ไว้แล้ว (รอดำเนินการ) กรุณายกเลิกในหน้าประวัติการจองหากต้องการจองใหม่'
                                        : 'คลิกเพื่อเลือก/ยกเลิกช่วงเวลานี้');
                            ?>
                            <div class="grid grid-cols-12 items-center gap-4">
                                <div class="col-span-4 sm:col-span-3 text-base font-medium text-slate-900">
                                    <?= htmlspecialchars($slot, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <div class="col-span-8 sm:col-span-9">
                                    <button type="button"
                                            data-slot="<?= htmlspecialchars($slot, ENT_QUOTES, 'UTF-8') ?>"
                                            data-selected="<?= $isPicked ? '1' : '0' ?>"
                                            <?= $isBooked ? 'disabled' : 'onclick="toggleSlot(this)"' ?>
                                            class="slot-bar flex h-11 w-full items-center justify-center gap-2 rounded-xl transition <?= $barClass ?>"
                                            title="<?= htmlspecialchars($slotTitle, ENT_QUOTES, 'UTF-8') ?>">
                                        <?php if ($isApprovedBooked): ?>
                                            <span>ไม่ว่าง </span>
                                        <?php elseif ($isMyPending): ?>
                                            <span>คุณจองเวลานี้แล้ว </span>
                                        <?php elseif ($isPicked): ?>
                                            <span>เลือกเวลานี้แล้ว</span>
                                        <?php endif; ?>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Bottom Buttons -->
                <div class="mt-8 flex flex-wrap items-center justify-end gap-4">
                    <a href="teacher_dashboard.php"
                       class="inline-flex min-w-[140px] items-center justify-center rounded-xl bg-[#b8b8b8] px-7 py-3.5 text-base font-medium text-white transition hover:bg-slate-500">
                        ย้อนกลับ
                    </a>

                    <button type="submit"
                            class="inline-flex min-w-[160px] items-center justify-center rounded-xl bg-[#5475e8] px-7 py-3.5 text-base font-medium text-white transition hover:bg-blue-600">
                        ยืนยันการจอง
                    </button>
                </div>
            </div>
        </form>

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
    const thaiMonths = [
        'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
        'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'
    ];

    let currentYear = <?= (int) $calYear ?>;
    let currentMonth = <?= (int) $calMonth - 1 ?>;
    let selectedDay = <?= (int) $calDay ?>;

    function pad2(n) {
        return n < 10 ? '0' + n : '' + n;
    }

    function renderCalendar() {
        const titleEl = document.getElementById('calendar_title');
        const daysEl = document.getElementById('calendar_days');
        titleEl.textContent = `${thaiMonths[currentMonth]} ${currentYear + 543}`;
        daysEl.innerHTML = '';

        const firstDayJs = new Date(currentYear, currentMonth, 1).getDay();
        const startOffset = (firstDayJs + 6) % 7;
        const daysInMonth = new Date(currentYear, currentMonth + 1, 0).getDate();
        const daysInPrevMonth = new Date(currentYear, currentMonth, 0).getDate();

        if (selectedDay > daysInMonth) {
            selectedDay = 1;
        }

        for (let i = startOffset - 1; i >= 0; i--) {
            const prevNum = daysInPrevMonth - i;
            const cell = document.createElement('div');
            cell.className = 'flex h-11 items-center justify-center rounded-md bg-transparent text-slate-400 select-none';
            cell.textContent = prevNum;
            daysEl.appendChild(cell);
        }

        for (let d = 1; d <= daysInMonth; d++) {
            const btn = document.createElement('button');
            btn.type = 'button';
            const isSelected = (d === selectedDay);
            btn.className = isSelected
                ? 'flex h-11 w-full items-center justify-center rounded-md bg-[#0a84ff] font-semibold text-white'
                : 'flex h-11 w-full items-center justify-center rounded-md bg-[#dcdcdc] text-slate-900 hover:bg-slate-300';
            btn.textContent = d;
            btn.addEventListener('click', () => {
                selectedDay = d;
                const ymd = `${currentYear}-${pad2(currentMonth + 1)}-${pad2(d)}`;
                document.getElementById('booking_date').value = ymd;
                const roomTypeVal = encodeURIComponent(document.getElementById('room_type').value);
                window.location.href = `Reserve.php?room_id=<?= $roomId ?>&date=${ymd}&room_type=${roomTypeVal}`;
            });
            daysEl.appendChild(btn);
        }

        const totalCells = daysEl.children.length;
        const remainder = totalCells % 7;
        if (remainder !== 0) {
            const nextCount = 7 - remainder;
            for (let n = 1; n <= nextCount; n++) {
                const nextCell = document.createElement('div');
                nextCell.className = 'flex h-11 items-center justify-center rounded-md bg-[#f5d0d0] text-slate-400 select-none';
                nextCell.textContent = n;
                daysEl.appendChild(nextCell);
            }
        }
    }

    function changeMonth(delta) {
        currentMonth += delta;
        if (currentMonth < 0) {
            currentMonth = 11;
            currentYear -= 1;
        } else if (currentMonth > 11) {
            currentMonth = 0;
            currentYear += 1;
        }
        selectedDay = 1;
        const ymd = `${currentYear}-${pad2(currentMonth + 1)}-${pad2(selectedDay)}`;
        document.getElementById('booking_date').value = ymd;
        const roomTypeVal = encodeURIComponent(document.getElementById('room_type').value);
        window.location.href = `Reserve.php?room_id=<?= $roomId ?>&date=${ymd}&room_type=${roomTypeVal}`;
    }

    function syncHiddenSlotInputs() {
        const container = document.getElementById('selected_slots_inputs');
        container.innerHTML = '';
        document.querySelectorAll('.slot-bar[data-selected="1"]').forEach(btn => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'time_slots[]';
            input.value = btn.getAttribute('data-slot') || '';
            container.appendChild(input);
        });
    }

    function toggleSlot(el) {
        const isPicked = el.getAttribute('data-selected') === '1';
        if (isPicked) {
            el.setAttribute('data-selected', '0');
            el.classList.remove('bg-[#15803d]', 'ring-4', 'ring-emerald-300', 'border-2', 'border-[#052e16]', 'shadow-md', 'text-white', 'font-semibold');
            el.classList.add('bg-[#7ee687]', 'hover:bg-[#65d970]');
            el.innerHTML = '';
        } else {
            el.setAttribute('data-selected', '1');
            el.classList.remove('bg-[#7ee687]', 'hover:bg-[#65d970]');
            el.classList.add('bg-[#15803d]', 'ring-4', 'ring-emerald-300', 'border-2', 'border-[#052e16]', 'shadow-md', 'text-white', 'font-semibold');
            el.innerHTML = '<span>เลือกเวลานี้แล้ว</span>';
        }
        syncHiddenSlotInputs();
    }

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

    renderCalendar();
    </script>

</body>
</html>
