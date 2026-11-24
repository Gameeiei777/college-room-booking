<?php
require __DIR__ . '/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header('Location: index.php');
    exit;
}

$roomType = trim($_GET['room_type'] ?? 'all');
$bookingDate = trim($_GET['booking_date'] ?? '');
$timeSlot = trim($_GET['time_slot'] ?? '');
$capacityFilter = trim($_GET['capacity'] ?? '');

$rooms = [];
$sql = 'SELECT id, building_name, room_name, details, capacity, room_image, status FROM rooms WHERE 1=1';
$params = [];
$types = '';

if ($roomType !== '' && $roomType !== 'all') {
    $sql .= ' AND (room_name LIKE ? OR details LIKE ?)';
    $likeType = '%' . $roomType . '%';
    $params[] = $likeType;
    $params[] = $likeType;
    $types .= 'ss';
}

if ($capacityFilter !== '' && is_numeric($capacityFilter)) {
    $sql .= ' AND capacity >= ?';
    $params[] = (int) $capacityFilter;
    $types .= 'i';
}

$sql .= ' ORDER BY id ASC';

$stmt = $conn->prepare($sql);
if ($stmt) {
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rooms[] = $row;
    }
    $stmt->close();
}

if (empty($rooms) && $roomType === 'all' && $capacityFilter === '') {
    $rooms = [
        [
            'id' => 1,
            'building_name' => 'อาคาร 1',
            'room_name' => 'ห้องประชุม 1',
            'details' => 'ห้องประชุมขนาดกลาง พร้อมโปรเจกเตอร์และระบบเสียง',
            'capacity' => 30,
            'room_image' => 'room1.jpg',
            'status' => 'available',
        ],
        [
            'id' => 2,
            'building_name' => 'อาคาร 1',
            'room_name' => 'ห้องประชุม 2',
            'details' => 'ห้องประชุมรูปตัวยู พร้อมไมโครโฟนและจอแสดงผล',
            'capacity' => 40,
            'room_image' => 'room2.jpg',
            'status' => 'available',
        ],
    ];
}

$userName = $_SESSION['full_name'] ?? 'เกม';
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
<body class="min-h-screen bg-[#fdf6f2] text-slate-900 flex flex-col">

    <!-- Top Navbar -->
    <header class="bg-white shadow-sm border-b border-slate-200">
        <div class="mx-auto flex h-24 max-w-7xl items-center justify-between px-6 sm:px-8">
            <div class="flex items-center gap-4">
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
            </div>

            <nav class="hidden md:flex items-center gap-10 text-base font-medium text-[#14234c] sm:text-lg">
                <a href="teacher_dashboard.php" class="text-blue-700 font-semibold">หน้าหลัก</a>
                <a href="my_bookings.php" class="hover:text-blue-700">ประวัติการจอง</a>
                <a href="contact.php" class="hover:text-blue-700">ติดต่อเรา</a>
            </nav>

            <!-- Profile Dropdown -->
            <div class="relative">
                <button type="button" onclick="toggleProfileMenu()"
                        class="flex items-center gap-3 rounded bg-slate-200 px-5 py-3 text-base font-medium text-slate-900 hover:bg-slate-300">
                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-slate-700 text-sm text-white">👤</span>
                    <span><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></span>
                    <span id="profile_arrow">⌄</span>
                </button>

                <div id="profile_menu" class="hidden absolute right-0 z-30 w-52 shadow-md">
                    <a href="profile.php" class="block bg-slate-200 px-4 py-3.5 text-center text-base font-medium text-slate-900 hover:bg-slate-300">
                        ข้อมูลส่วนตัว
                    </a>
                    <a href="logout.php" class="block bg-[#e05252] px-4 py-3.5 text-center text-base font-medium text-white hover:bg-red-600">
                        ออกจากระบบ
                    </a>
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
    <main class="mx-auto w-full max-w-7xl flex-1 px-6 py-10 sm:px-8">

        <!-- Blue Filter Bar -->
        <form action="teacher_dashboard.php" method="get"
              class="rounded-xl bg-[#3874c9] p-6 text-white shadow-sm">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label for="room_type" class="mb-2 block text-sm font-medium text-white">ประเภทห้อง</label>
                    <select id="room_type" name="room_type"
                            class="w-full rounded-md bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none">
                        <option value="all" <?= $roomType === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="ประชุม" <?= $roomType === 'ประชุม' ? 'selected' : '' ?>>Meeting (ห้องประชุม)</option>
                        <option value="คอมพิวเตอร์" <?= $roomType === 'คอมพิวเตอร์' ? 'selected' : '' ?>>Computer (ห้องคอมพิวเตอร์)</option>
                    </select>
                </div>

                <div>
                    <label for="booking_date" class="mb-2 block text-sm font-medium text-white">วันที่ต้องการจอง</label>
                    <input id="booking_date" name="booking_date" type="date"
                           value="<?= htmlspecialchars($bookingDate, ENT_QUOTES, 'UTF-8') ?>"
                           class="w-full rounded-md bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none">
                </div>

                <div>
                    <label for="time_slot" class="mb-2 block text-sm font-medium text-white">เวลา</label>
                    <select id="time_slot" name="time_slot"
                            class="w-full rounded-md bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none">
                        <option value="" <?= $timeSlot === '' ? 'selected' : '' ?>>เลือกเวลา</option>
                        <option value="09:00 - 11:00" <?= $timeSlot === '09:00 - 11:00' ? 'selected' : '' ?>>09:00 - 11:00</option>
                        <option value="11:00 - 13:00" <?= $timeSlot === '11:00 - 13:00' ? 'selected' : '' ?>>11:00 - 13:00</option>
                        <option value="13:00 - 15:00" <?= $timeSlot === '13:00 - 15:00' ? 'selected' : '' ?>>13:00 - 15:00</option>
                        <option value="15:00 - 17:00" <?= $timeSlot === '15:00 - 17:00' ? 'selected' : '' ?>>15:00 - 17:00</option>
                    </select>
                </div>

                <div>
                    <label for="capacity" class="mb-2 block text-sm font-medium text-white">จำนวนคน</label>
                    <select id="capacity" name="capacity"
                            class="w-full rounded-md bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none">
                        <option value="" <?= $capacityFilter === '' ? 'selected' : '' ?>>จำนวนคน</option>
                        <option value="10" <?= $capacityFilter === '10' ? 'selected' : '' ?>>10 คนขึ้นไป</option>
                        <option value="20" <?= $capacityFilter === '20' ? 'selected' : '' ?>>20 คนขึ้นไป</option>
                        <option value="30" <?= $capacityFilter === '30' ? 'selected' : '' ?>>30 คนขึ้นไป</option>
                        <option value="50" <?= $capacityFilter === '50' ? 'selected' : '' ?>>50 คนขึ้นไป</option>
                    </select>
                </div>
            </div>
        </form>

        <!-- Room Cards Grid -->
        <div class="mt-9 grid grid-cols-1 gap-8 md:grid-cols-2">
            <?php if (empty($rooms)): ?>
                <div class="col-span-2 rounded-xl bg-white p-10 text-center text-base text-slate-600 shadow-sm">
                    ไม่พบห้องที่ตรงกับเงื่อนไขที่เลือก
                </div>
            <?php else: ?>
                <?php foreach ($rooms as $room): ?>
                    <?php
                        $roomId = (int) $room['id'];
                        $roomName = htmlspecialchars($room['room_name'] ?? '', ENT_QUOTES, 'UTF-8');
                        $roomImageRaw = trim($room['room_image'] ?? '');
                        $hasLocalImage = $roomImageRaw !== '' && file_exists(__DIR__ . '/' . ltrim($roomImageRaw, '/'));
                        $isUrlImage = preg_match('/^https?:\/\//i', $roomImageRaw) === 1;

                        $bookUrl = 'book_room.php?room_id=' . $roomId;
                        if ($bookingDate !== '') {
                            $bookUrl .= '&date=' . urlencode($bookingDate);
                        }
                        if ($timeSlot !== '') {
                            $bookUrl .= '&time=' . urlencode($timeSlot);
                        }
                    ?>
                    <article class="flex h-full flex-col justify-between rounded-xl bg-white p-6 shadow-sm border border-slate-100">
                        <div>
                            <div class="h-64 w-full overflow-hidden rounded-lg bg-slate-200">
                                <?php if ($hasLocalImage || $isUrlImage): ?>
                                    <img src="<?= htmlspecialchars($roomImageRaw, ENT_QUOTES, 'UTF-8') ?>"
                                         alt="<?= $roomName ?>"
                                         class="h-full w-full object-cover">
                                <?php else: ?>
                                    <div class="flex h-full w-full items-center justify-center bg-slate-200 text-base font-medium text-slate-600">
                                        รูปภาพ <?= $roomName ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <h2 class="mt-5 text-xl font-semibold text-slate-900">
                                <?= $roomName ?>
                            </h2>
                        </div>

                        <div class="mt-5 space-y-3">
                            <a href="<?= htmlspecialchars($bookUrl, ENT_QUOTES, 'UTF-8') ?>"
                               class="block w-full rounded-md bg-[#8be3a8] py-3 text-center text-sm font-semibold text-white transition hover:bg-emerald-500 sm:text-base">
                                จอง
                            </a>

                            <a href="room_detail.php?room_id=<?= $roomId ?>"
                               class="block w-full rounded-md bg-[#6495db] py-3 text-center text-sm font-semibold text-white transition hover:bg-blue-600 sm:text-base">
                                รายละเอียดห้องประชุม
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
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