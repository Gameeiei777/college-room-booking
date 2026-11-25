<?php
require __DIR__ . '/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit;
}

$adminName = $_SESSION['full_name'] ?? 'ฤทธิไกร สุดหล่อ';
$flashError = '';

$uploadDir = __DIR__ . '/uploads/rooms';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0777, true);
}

function resolveRoomImageSrc(?string $img): string
{
    $img = trim((string) $img);
    if ($img === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $img)) {
        return $img;
    }
    $cleanPath = ltrim($img, '/');
    if (file_exists(__DIR__ . '/' . $cleanPath)) {
        return $cleanPath;
    }
    if (file_exists(__DIR__ . '/uploads/rooms/' . basename($img))) {
        return 'uploads/rooms/' . basename($img);
    }
    return '';
}

function handleRoomImageUpload(string $fieldName, string $targetDir, string $existingValue = ''): array
{
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return ['ok' => true, 'filename' => $existingValue];
    }

    $err = (int) ($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'filename' => $existingValue];
    }
    if ($err !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'อัปโหลดรูปภาพห้องไม่สำเร็จ (รหัสข้อผิดพลาด: ' . $err . ')'];
    }

    $tmpName = (string) ($_FILES[$fieldName]['tmp_name'] ?? '');
    $origName = (string) ($_FILES[$fieldName]['name'] ?? '');
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    if (!in_array($ext, $allowedExt, true)) {
        return ['ok' => false, 'error' => 'รองรับไฟล์รูปภาพนามสกุล JPG, JPEG, PNG, WEBP, GIF เท่านั้น'];
    }

    if (!is_dir($targetDir) && !@mkdir($targetDir, 0777, true)) {
        return ['ok' => false, 'error' => 'ไม่สามารถสร้างโฟลเดอร์เก็บไฟล์รูปภาพได้'];
    }

    $newFileName = 'room_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destPath = rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR . $newFileName;

    if (!move_uploaded_file($tmpName, $destPath)) {
        return ['ok' => false, 'error' => 'ไม่สามารถบันทึกไฟล์รูปภาพลงเครื่องได้'];
    }

    return ['ok' => true, 'filename' => 'uploads/rooms/' . $newFileName];
}

function normalizeRoomTypeLabel(string $rawType, string $roomName = '', string $details = ''): string
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

function stripTypePrefix(string $text): string
{
    $cleaned = preg_replace('/^\[ประเภท:\s*(ห้องประชุม|ห้องคอมพิวเตอร์)\]\s*/u', '', trim($text));
    return trim((string) $cleaned);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $conn) {
    $action = trim((string) ($_POST['room_action'] ?? ''));

    if ($action === 'add' || $action === 'edit') {
        $roomId = (int) ($_POST['room_id'] ?? 0);
        $roomType = normalizeRoomTypeLabel((string) ($_POST['room_type'] ?? 'ห้องประชุม'));
        $buildingName = trim((string) ($_POST['building_name'] ?? ''));
        $roomName = trim((string) ($_POST['room_name'] ?? ''));
        $detailsToSave = stripTypePrefix((string) ($_POST['details'] ?? ''));
        $capacity = max(1, (int) ($_POST['capacity'] ?? 30));
        $status = trim((string) ($_POST['status'] ?? 'available'));
        if (!in_array($status, ['available', 'maintenance', 'unavailable'], true)) {
            $status = 'available';
        }
        $existingImage = trim((string) ($_POST['existing_room_image'] ?? ''));
        $manualImage = trim((string) ($_POST['room_image_text'] ?? ''));
        $chosenImage = $manualImage !== '' ? $manualImage : $existingImage;

        if ($roomName === '' || $buildingName === '') {
            $flashError = 'กรุณากรอกชื่อห้องและชื่ออาคารให้ครบถ้วน';
        } else {
            $uploadRes = handleRoomImageUpload('room_image_file', $uploadDir, $chosenImage);
            if (!$uploadRes['ok']) {
                $flashError = (string) $uploadRes['error'];
            } else {
                $finalImage = (string) $uploadRes['filename'];

                if ($action === 'add') {
                    if ($hasRoomTypeCol) {
                        $stmt = $conn->prepare('INSERT INTO rooms (building_name, room_name, room_type, details, capacity, room_image, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
                        if ($stmt) {
                            $stmt->bind_param('ssssiss', $buildingName, $roomName, $roomType, $detailsToSave, $capacity, $finalImage, $status);
                        }
                    } else {
                        $stmt = $conn->prepare('INSERT INTO rooms (building_name, room_name, details, capacity, room_image, status) VALUES (?, ?, ?, ?, ?, ?)');
                        if ($stmt) {
                            $stmt->bind_param('sssiss', $buildingName, $roomName, $detailsToSave, $capacity, $finalImage, $status);
                        }
                    }

                    if (!empty($stmt)) {
                        if ($stmt->execute()) {
                            $stmt->close();
                            header('Location: admin_rooms.php');
                            exit;
                        } else {
                            $flashError = 'ไม่สามารถเพิ่มข้อมูลห้องได้: ' . $stmt->error;
                            $stmt->close();
                        }
                    }
                } elseif ($action === 'edit' && $roomId > 0) {
                    if ($hasRoomTypeCol) {
                        $stmt = $conn->prepare('UPDATE rooms SET building_name = ?, room_name = ?, room_type = ?, details = ?, capacity = ?, room_image = ?, status = ? WHERE id = ? LIMIT 1');
                        if ($stmt) {
                            $stmt->bind_param('ssssissi', $buildingName, $roomName, $roomType, $detailsToSave, $capacity, $finalImage, $status, $roomId);
                        }
                    } else {
                        $stmt = $conn->prepare('UPDATE rooms SET building_name = ?, room_name = ?, details = ?, capacity = ?, room_image = ?, status = ? WHERE id = ? LIMIT 1');
                        if ($stmt) {
                            $stmt->bind_param('sssissi', $buildingName, $roomName, $detailsToSave, $capacity, $finalImage, $status, $roomId);
                        }
                    }

                    if (!empty($stmt)) {
                        if ($stmt->execute()) {
                            $stmt->close();
                            header('Location: admin_rooms.php');
                            exit;
                        } else {
                            $flashError = 'ไม่สามารถแก้ไขข้อมูลห้องได้: ' . $stmt->error;
                            $stmt->close();
                        }
                    }
                }
            }
        }
    } elseif ($action === 'delete') {
        $roomId = (int) ($_POST['room_id'] ?? 0);
        if ($roomId > 0) {
            $imgToRemove = '';
            $selStmt = $conn->prepare('SELECT room_image FROM rooms WHERE id = ? LIMIT 1');
            if ($selStmt) {
                $selStmt->bind_param('i', $roomId);
                $selStmt->execute();
                $selRes = $selStmt->get_result();
                if ($selRow = $selRes->fetch_assoc()) {
                    $imgToRemove = trim((string) ($selRow['room_image'] ?? ''));
                }
                $selStmt->close();
            }

            $delStmt = $conn->prepare('DELETE FROM rooms WHERE id = ? LIMIT 1');
            if ($delStmt) {
                $delStmt->bind_param('i', $roomId);
                if ($delStmt->execute()) {
                    $delStmt->close();
                    if ($imgToRemove !== '' && str_starts_with($imgToRemove, 'uploads/rooms/')) {
                        $fullImgPath = __DIR__ . '/' . $imgToRemove;
                        if (file_exists($fullImgPath)) {
                            @unlink($fullImgPath);
                        }
                    }
                    header('Location: admin_rooms.php');
                    exit;
                } else {
                    $flashError = 'ไม่สามารถลบห้องได้ (อาจมีรายการจองผูกอยู่กับห้องนี้)';
                    $delStmt->close();
                }
            }
        }
    }
}

$rooms = [];
if ($conn) {
    $selectSql = $hasRoomTypeCol
        ? 'SELECT id, building_name, room_name, room_type, details, capacity, room_image, status FROM rooms ORDER BY id ASC'
        : 'SELECT id, building_name, room_name, details, capacity, room_image, status FROM rooms ORDER BY id ASC';
    $res = $conn->query($selectSql);
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rawDetails = (string) ($r['details'] ?? '');
            $cleanDetails = stripTypePrefix($rawDetails);
            $resolvedType = normalizeRoomTypeLabel(
                (string) ($r['room_type'] ?? ''),
                (string) ($r['room_name'] ?? ''),
                $rawDetails
            );

            $rooms[] = [
                'id' => (int) ($r['id'] ?? 0),
                'building_name' => (string) ($r['building_name'] ?? ''),
                'room_name' => (string) ($r['room_name'] ?? ''),
                'room_type' => $resolvedType,
                'details' => $cleanDetails,
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
    <title>จัดการห้อง | ระบบจองห้องประชุมและห้องคอมพิวเตอร์</title>
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
                    <a href="admin_bookings.php" class="rounded-lg px-4 py-2 hover:bg-slate-100 hover:text-blue-700">จัดการการจอง</a>
                    <a href="admin_rooms.php" class="rounded-lg bg-[#c4c4c4] px-5 py-2.5 font-semibold text-[#14234c]">จัดการห้อง</a>
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
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-6 sm:px-8">
            <div class="flex items-center gap-3.5">
                <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-white/40 text-lg">✓</span>
                <h1 class="text-xl font-semibold sm:text-2xl">ระบบจัดการห้องประชุมและคอมพิวเตอร์</h1>
            </div>

            <button type="button" onclick="openAddRoomModal()"
                    class="inline-flex items-center gap-2 rounded-xl bg-[#5475e8] px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-600">
                <span>+</span>
                <span>เพิ่มห้อง</span>
            </button>
        </div>
    </section>

    <!-- Main Content -->
    <main class="mx-auto w-full max-w-7xl flex-1 px-6 py-10 sm:px-8">

        <?php if ($flashError !== ''): ?>
            <div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 px-5 py-3.5 text-sm font-medium text-rose-800">
                ✕ <?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <!-- Rooms Table -->
        <div class="overflow-hidden rounded-2xl bg-white shadow-md border border-slate-200">
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-center">
                    <thead>
                        <tr class="bg-[#182959] text-sm sm:text-base font-semibold text-white">
                            <th class="px-3 py-3.5 whitespace-nowrap">ลำดับ</th>
                            <th class="px-3 py-3.5 whitespace-nowrap">รูปห้อง</th>
                            <th class="px-3 py-3.5 whitespace-nowrap">ประเภทห้อง</th>
                            <th class="px-3 py-3.5 whitespace-nowrap">ชื่อห้อง</th>
                            <th class="px-3 py-3.5 whitespace-nowrap">อาคาร</th>
                            <th class="px-3 py-3.5 whitespace-nowrap">ความจุ</th>
                            <th class="px-3 py-3.5 text-left">รายละเอียด</th>
                            <th class="px-3 py-3.5 whitespace-nowrap">การจัดการ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 text-sm sm:text-[15px] text-slate-800">
                        <?php if (empty($rooms)): ?>
                            <tr class="bg-white">
                                <td colspan="8" class="py-12 text-center text-base text-slate-500">
                                    ยังไม่มีข้อมูลห้องในระบบ กดปุ่ม <strong>+ เพิ่มห้อง</strong> เพื่อเพิ่มห้องใหม่
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rooms as $index => $rm): ?>
                                <?php
                                    $rowBg = ($index % 2 === 0) ? 'bg-white' : 'bg-slate-50/80';
                                    $imgSrc = resolveRoomImageSrc($rm['room_image']);
                                    $typeBadgeClass = ($rm['room_type'] === 'ห้องคอมพิวเตอร์')
                                        ? 'bg-indigo-50 text-indigo-800 border-indigo-200'
                                        : 'bg-sky-50 text-sky-800 border-sky-200';
                                ?>
                                <tr class="<?= $rowBg ?> transition hover:bg-slate-100/70">
                                    <td class="px-3 py-3.5 font-semibold text-slate-700">
                                        <?= $index + 1 ?>
                                    </td>
                                    <td class="px-3 py-3">
                                        <div class="mx-auto h-20 w-32 overflow-hidden rounded-xl border border-slate-200 bg-slate-100 shadow-sm">
                                            <?php if ($imgSrc !== ''): ?>
                                                <img src="<?= htmlspecialchars($imgSrc, ENT_QUOTES, 'UTF-8') ?>"
                                                     alt="<?= htmlspecialchars($rm['room_name'], ENT_QUOTES, 'UTF-8') ?>"
                                                     onerror="this.onerror=null; this.parentElement.innerHTML='<div class=&quot;flex h-full w-full flex-col items-center justify-center gap-0.5 text-slate-400 text-xs&quot;><span class=&quot;text-base&quot;>🖼️</span><span>ไม่มีรูปห้อง</span></div>';"
                                                     class="h-full w-full object-cover">
                                            <?php else: ?>
                                                <div class="flex h-full w-full flex-col items-center justify-center gap-0.5 text-slate-400 text-xs">
                                                    <span class="text-base">🖼️</span>
                                                    <span>ไม่มีรูปห้อง</span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-3 py-3.5 whitespace-nowrap">
                                        <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs sm:text-sm font-semibold <?= $typeBadgeClass ?>">
                                            <?= htmlspecialchars($rm['room_type'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td class="px-3 py-3.5 font-semibold text-[#14234c]">
                                        <?= htmlspecialchars($rm['room_name'], ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="px-3 py-3.5 font-medium text-slate-700">
                                        <?= htmlspecialchars($rm['building_name'], ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="px-3 py-3.5 font-medium text-slate-700 whitespace-nowrap">
                                        <?= (int) $rm['capacity'] ?> คน
                                    </td>
                                    <td class="px-3 py-3.5 text-left text-slate-700 leading-snug max-w-[220px]">
                                        <?= htmlspecialchars($rm['details'] !== '' ? $rm['details'] : '-', ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="px-3 py-3.5 whitespace-nowrap">
                                        <div class="flex items-center justify-center gap-2 font-medium">
                                            <button type="button"
                                                    data-room='<?= htmlspecialchars(json_encode($rm, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>'
                                                    onclick="openEditRoomModal(this)"
                                                    class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-slate-100 px-3 py-1.5 text-sm font-medium text-slate-800 transition hover:bg-blue-50 hover:border-blue-200 hover:text-blue-700">
                                                <span>✎</span>
                                                <span>แก้ไข</span>
                                            </button>

                                            <form method="post" action="admin_rooms.php" class="inline"
                                                  onsubmit="return confirm('ยืนยันการลบห้อง <?= htmlspecialchars(addslashes($rm['room_name']), ENT_QUOTES, 'UTF-8') ?> ?');">
                                                <input type="hidden" name="room_action" value="delete">
                                                <input type="hidden" name="room_id" value="<?= (int) $rm['id'] ?>">
                                                <button type="submit"
                                                        class="inline-flex items-center gap-1 rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-sm font-medium text-rose-700 transition hover:bg-rose-100">
                                                    <span>🗑</span>
                                                    <span>ลบ</span>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- Add / Edit Room Modal -->
    <div id="roomModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-4">
        <div class="w-full max-w-xl rounded-2xl bg-white p-6 sm:p-7 shadow-xl border border-slate-200">
            <div class="flex items-center justify-between border-b border-slate-200 pb-4">
                <h2 id="roomModalTitle" class="text-xl font-bold text-[#14234c]">เพิ่มห้องใหม่</h2>
                <button type="button" onclick="closeRoomModal()" class="text-2xl leading-none text-slate-400 hover:text-slate-700">&times;</button>
            </div>

            <form method="post" action="admin_rooms.php" enctype="multipart/form-data" class="mt-5 space-y-4">
                <input type="hidden" id="room_action" name="room_action" value="add">
                <input type="hidden" id="room_id" name="room_id" value="0">
                <input type="hidden" id="existing_room_image" name="existing_room_image" value="">

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="room_type" class="mb-1.5 block text-sm font-medium text-slate-800">ประเภทห้อง</label>
                        <select id="room_type" name="room_type" required
                                class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3.5 py-2.5 text-sm font-medium text-slate-900 outline-none focus:border-blue-500">
                            <option value="ห้องประชุม">ห้องประชุม</option>
                            <option value="ห้องคอมพิวเตอร์">ห้องคอมพิวเตอร์</option>
                        </select>
                    </div>

                    <div>
                        <label for="room_name" class="mb-1.5 block text-sm font-medium text-slate-800">ชื่อห้องประชุม / ห้องคอมพิวเตอร์</label>
                        <input id="room_name" name="room_name" type="text" required
                               placeholder="เช่น ห้องประชุม 1 หรือ ห้องคอมพิวเตอร์ 1"
                               class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-900 outline-none focus:border-blue-500">
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <label for="building_name" class="mb-1.5 block text-sm font-medium text-slate-800">ชื่ออาคาร</label>
                        <input id="building_name" name="building_name" type="text" required
                               placeholder="เช่น อาคาร 1"
                               class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-900 outline-none focus:border-blue-500">
                    </div>

                    <div>
                        <label for="capacity" class="mb-1.5 block text-sm font-medium text-slate-800">ความจุ (คน)</label>
                        <input id="capacity" name="capacity" type="number" min="1" value="30" required
                               class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-900 outline-none focus:border-blue-500">
                    </div>

                    <div>
                        <label for="status" class="mb-1.5 block text-sm font-medium text-slate-800">สถานะห้อง</label>
                        <select id="status" name="status"
                                class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-900 outline-none focus:border-blue-500">
                            <option value="available">เปิดใช้งาน (available)</option>
                            <option value="maintenance">ปิดปรับปรุง (maintenance)</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label for="details" class="mb-1.5 block text-sm font-medium text-slate-800">รายละเอียดห้อง / อุปกรณ์</label>
                    <textarea id="details" name="details" rows="3"
                              placeholder="ระบุรายละเอียดห้อง หรืออุปกรณ์ภายในห้อง"
                              class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-900 outline-none focus:border-blue-500"></textarea>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="room_image_file" class="mb-1.5 block text-sm font-medium text-slate-800">อัปโหลดรูปห้อง</label>
                        <input id="room_image_file" name="room_image_file" type="file" accept="image/*"
                               class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 text-xs text-slate-800">
                    </div>

                    <div>
                        <label for="room_image_text" class="mb-1.5 block text-sm font-medium text-slate-800">หรือระบุชื่อไฟล์ / URL รูป</label>
                        <input id="room_image_text" name="room_image_text" type="text"
                               placeholder="เช่น room1.jpg"
                               class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-900 outline-none focus:border-blue-500">
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-end gap-3 pt-2">
                    <button type="button" onclick="closeRoomModal()"
                            class="rounded-xl bg-slate-200 px-5 py-2.5 text-sm font-medium text-slate-800 hover:bg-slate-300">
                        ยกเลิก
                    </button>
                    <button type="submit"
                            class="rounded-xl bg-[#5475e8] px-6 py-2.5 text-sm font-semibold text-white hover:bg-blue-600">
                        บันทึกข้อมูลห้อง
                    </button>
                </div>
            </form>
        </div>
    </div>

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

    function openAddRoomModal() {
        document.getElementById('roomModalTitle').textContent = 'เพิ่มห้องใหม่';
        document.getElementById('room_action').value = 'add';
        document.getElementById('room_id').value = '0';
        document.getElementById('existing_room_image').value = '';
        document.getElementById('room_type').value = 'ห้องประชุม';
        document.getElementById('room_name').value = '';
        document.getElementById('building_name').value = '';
        document.getElementById('capacity').value = '30';
        document.getElementById('status').value = 'available';
        document.getElementById('details').value = '';
        document.getElementById('room_image_file').value = '';
        document.getElementById('room_image_text').value = '';

        const modal = document.getElementById('roomModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function openEditRoomModal(btn) {
        const data = JSON.parse(btn.getAttribute('data-room') || '{}');
        document.getElementById('roomModalTitle').textContent = 'แก้ไขข้อมูลห้อง';
        document.getElementById('room_action').value = 'edit';
        document.getElementById('room_id').value = data.id || 0;
        document.getElementById('existing_room_image').value = data.room_image || '';
        document.getElementById('room_type').value = data.room_type || 'ห้องประชุม';
        document.getElementById('room_name').value = data.room_name || '';
        document.getElementById('building_name').value = data.building_name || '';
        document.getElementById('capacity').value = data.capacity || 30;
        document.getElementById('status').value = data.status || 'available';
        document.getElementById('details').value = data.details || '';
        document.getElementById('room_image_file').value = '';
        document.getElementById('room_image_text').value = data.room_image || '';

        const modal = document.getElementById('roomModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeRoomModal() {
        const modal = document.getElementById('roomModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
    </script>

</body>
</html>
