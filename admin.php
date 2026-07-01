<?php
require_once __DIR__ . '/common.php';
requireAdmin();

$db = db();
$message = '';
$isError = false;

addColumnIfMissing($db, 'admin_users', 'email', "email VARCHAR(160) NULL AFTER full_name");
addColumnIfMissing($db, 'admin_users', 'role', "role ENUM('admin','reception','accounting','manager','housekeeping') NOT NULL DEFAULT 'admin' AFTER email");
$db->query("ALTER TABLE admin_users MODIFY role ENUM('admin','reception','accounting','manager','housekeeping') NOT NULL DEFAULT 'admin'");
addColumnIfMissing($db, 'admin_users', 'id_hotel', "id_hotel INT NULL AFTER role");
addColumnIfMissing($db, 'admin_users', 'is_active', "is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role");
addColumnIfMissing($db, 'guests', 'is_vip', "is_vip TINYINT(1) NOT NULL DEFAULT 0 AFTER address");
addColumnIfMissing($db, 'guests', 'loyalty_points', "loyalty_points INT NOT NULL DEFAULT 0 AFTER is_vip");
addColumnIfMissing($db, 'payments', 'payer_name', "payer_name VARCHAR(160) NULL AFTER note");
addColumnIfMissing($db, 'payments', 'cashier_name', "cashier_name VARCHAR(160) NULL AFTER payer_name");
addColumnIfMissing($db, 'rooms', 'housekeeping_status', "housekeeping_status ENUM('pending','cleaning') NULL AFTER status");
addColumnIfMissing($db, 'rooms', 'housekeeping_started_at', "housekeeping_started_at DATETIME NULL AFTER housekeeping_status");
addColumnIfMissing($db, 'rooms', 'cleaned_at', "cleaned_at DATETIME NULL AFTER housekeeping_started_at");
addColumnIfMissing($db, 'rooms', 'extra_guest_rate_percent', "extra_guest_rate_percent DECIMAL(5,2) NOT NULL DEFAULT 25.00 AFTER hourly_rate");
addColumnIfMissing($db, 'rooms', 'is_active', "is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER status");
$currentAdminId = (int)($_SESSION['hotel_admin_id'] ?? 0);
$stmt = $db->prepare("SELECT username, full_name, role, id_hotel FROM admin_users WHERE id_admin=? LIMIT 1");
$stmt->bind_param('i', $currentAdminId);
$stmt->execute();
$currentAdmin = $stmt->get_result()->fetch_assoc();
$currentAdminName = trim((string)($currentAdmin['full_name'] ?? ''));
$currentAdminUsername = trim((string)($currentAdmin['username'] ?? ''));
if ($currentAdminName === '') {
    $currentAdminName = $currentAdminUsername !== '' ? $currentAdminUsername : 'Nhân viên';
}
$rawAdminRole = strtolower(trim((string)($currentAdmin['role'] ?? 'reception')));
$roleAliases = [
    'tap vu' => 'housekeeping',
    'tapvu' => 'housekeeping',
    'tạp vụ' => 'housekeeping',
    'tạp_vụ' => 'housekeeping',
    'housekeeper' => 'housekeeping',
    'le tan' => 'reception',
    'letan' => 'reception',
    'lễ tân' => 'reception',
    'ke toan' => 'accounting',
    'kế toán' => 'accounting',
    'quan ly' => 'manager',
    'quản lý' => 'manager',
];
$currentAdminRole = $roleAliases[$rawAdminRole] ?? $rawAdminRole;
$currentAdminHotelId = (int)($currentAdmin['id_hotel'] ?? 0);
$canSetMaintenance = $currentAdminRole === 'admin';
$canManageHotels = $currentAdminRole === 'admin';
$canManageRooms = in_array($currentAdminRole, ['admin', 'manager'], true);
$canManageGuests = in_array($currentAdminRole, ['admin', 'manager', 'reception'], true);
$db->query("CREATE TABLE IF NOT EXISTS website_contents (
    id_content INT AUTO_INCREMENT PRIMARY KEY,
    content_type VARCHAR(40) NOT NULL,
    title VARCHAR(180) NOT NULL,
    image_url VARCHAR(500) NULL,
    body TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

require_once __DIR__ . '/includes/admin/helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        verifyCsrfToken();
        if (!adminCanRunAction($currentAdminRole, $action)) {
            throw new Exception('Bạn không có quyền thực hiện chức năng này.');
        }
        logSecurityEvent($db, 'admin_action_attempt', 'info', 'Admin thuc hien thao tac.', ['action' => $action, 'role' => $currentAdminRole]);
        if ($action === 'save_hotel') {
            $id = (int)($_POST['id_hotel'] ?? 0);
            $name = trim($_POST['hotel_name'] ?? '');
            $brand = trim($_POST['brand_name'] ?? 'Spotki Hotels');
            $stars = min(5, max(1, (int)($_POST['star_rating'] ?? 5)));
            $address = trim($_POST['address'] ?? '');
            $city = trim($_POST['city'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $hero = trim($_POST['hero_image'] ?? '');
            $uploadedHero = saveUploadedImage('hero_image_file', 'hotel');
            if ($uploadedHero !== '') {
                $hero = $uploadedHero;
            } else {
                $hero = localizeRemoteImage($hero, 'hotel');
            }
            $description = trim($_POST['description'] ?? '');
            if ($name === '' || $address === '' || $city === '') {
                throw new Exception('Vui lòng nhập tên, địa chỉ và thành phố của khách sạn.');
            }
            if ($currentAdminRole !== 'admin') {
                if ($currentAdminHotelId <= 0 || $id !== $currentAdminHotelId) {
                    throw new Exception('Bạn chỉ được cập nhật cơ sở được phân quyền.');
                }
            }
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE hotels SET hotel_name=?, brand_name=?, star_rating=?, address=?, city=?, phone=?, email=?, hero_image=?, description=? WHERE id_hotel=?");
                $stmt->bind_param('ssissssssi', $name, $brand, $stars, $address, $city, $phone, $email, $hero, $description, $id);
            } else {
                $stmt = $db->prepare("INSERT INTO hotels (hotel_name, brand_name, star_rating, address, city, phone, email, hero_image, description, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                $stmt->bind_param('ssissssss', $name, $brand, $stars, $address, $city, $phone, $email, $hero, $description);
            }
            $stmt->execute();
            $message = 'Đã lưu cơ sở khách sạn.';
        } elseif ($action === 'delete_hotel') {
            $id = (int)($_POST['id_hotel'] ?? 0);
            if ($id <= 0) {
                throw new Exception('Vui lòng chọn cơ sở cần xóa.');
            }
            if ($currentAdminRole !== 'admin' && ($currentAdminHotelId <= 0 || $id !== $currentAdminHotelId)) {
                throw new Exception('Bạn chỉ được xóa cơ sở được phân quyền.');
            }
            $stmtRooms = $db->prepare("SELECT COUNT(*) AS total FROM rooms WHERE id_hotel=?");
            $stmtRooms->bind_param('i', $id);
            $stmtRooms->execute();
            if ((int)$stmtRooms->get_result()->fetch_assoc()['total'] > 0) {
                throw new Exception('Cơ sở đã có phòng nên không thể xóa. Hãy xóa hoặc chuyển phòng trước.');
            }
            $stmtStaff = $db->prepare("SELECT COUNT(*) AS total FROM admin_users WHERE id_hotel=?");
            $stmtStaff->bind_param('i', $id);
            $stmtStaff->execute();
            if ((int)$stmtStaff->get_result()->fetch_assoc()['total'] > 0) {
                throw new Exception('Cơ sở đang được phân quyền cho nhân viên nên không thể xóa.');
            }
            $stmt = $db->prepare("DELETE FROM hotels WHERE id_hotel=?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $message = 'Đã xóa cơ sở.';
        } elseif ($action === 'save_staff') {
            $id = (int)($_POST['id_admin'] ?? 0);
            $username = trim($_POST['username'] ?? '');
            $fullName = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = trim($_POST['password'] ?? '');
            $role = $_POST['role'] ?? 'reception';
            $staffHotelId = (int)($_POST['id_hotel'] ?? 0);
            $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
            if ($username === '' || $fullName === '' || ($id <= 0 && $password === '')) {
                throw new Exception('Vui lòng nhập tài khoản, họ tên và mật khẩu nhân viên.');
            }
            if ($currentAdminRole === 'manager') {
                $lowerRoles = ['reception', 'accounting', 'housekeeping'];
                if (!in_array($role, $lowerRoles, true)) {
                    throw new Exception('Quản lý chỉ được phân quyền cho nhân viên cấp dưới.');
                }
                if ($currentAdminHotelId <= 0) {
                    throw new Exception('Tài khoản quản lý chưa được gán cơ sở.');
                }
                $staffHotelId = $currentAdminHotelId;
                if ($id > 0) {
                    $stmtCurrentStaff = $db->prepare("SELECT role, id_hotel FROM admin_users WHERE id_admin=?");
                    $stmtCurrentStaff->bind_param('i', $id);
                    $stmtCurrentStaff->execute();
                    $currentStaff = $stmtCurrentStaff->get_result()->fetch_assoc();
                    if (!$currentStaff || !in_array((string)$currentStaff['role'], $lowerRoles, true) || (int)($currentStaff['id_hotel'] ?? 0) !== $currentAdminHotelId) {
                        throw new Exception('Bạn chỉ được sửa nhân viên cấp dưới trong cơ sở của mình.');
                    }
                }
            } elseif ($role === 'admin') {
                $staffHotelId = 0;
            }
            if ($role !== 'admin' && $staffHotelId <= 0) {
                throw new Exception('Vui lòng chọn cơ sở phân quyền cho tài khoản này.');
            }
            if ($id > 0 && $password === '') {
                $stmt = $db->prepare("UPDATE admin_users SET username=?, full_name=?, email=?, role=?, id_hotel=?, is_active=? WHERE id_admin=?");
                $stmt->bind_param('ssssiii', $username, $fullName, $email, $role, $staffHotelId, $isActive, $id);
            } elseif ($id > 0) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE admin_users SET username=?, password_hash=?, full_name=?, email=?, role=?, id_hotel=?, is_active=? WHERE id_admin=?");
                $stmt->bind_param('sssssiii', $username, $hash, $fullName, $email, $role, $staffHotelId, $isActive, $id);
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO admin_users (username, password_hash, full_name, email, role, id_hotel, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
                $stmt->bind_param('sssssi', $username, $hash, $fullName, $email, $role, $staffHotelId);
            }
            $stmt->execute();
            $message = 'Đã lưu tài khoản nhân viên.';
        } elseif ($action === 'delete_staff') {
            $id = (int)($_POST['id_admin'] ?? 0);
            if ($id <= 0) {
                throw new Exception('Vui lòng chọn nhân viên cần xóa.');
            }
            if ($id === $currentAdminId) {
                throw new Exception('Không thể xóa tài khoản đang đăng nhập.');
            }
            $stmtStaff = $db->prepare("SELECT role, id_hotel FROM admin_users WHERE id_admin=?");
            $stmtStaff->bind_param('i', $id);
            $stmtStaff->execute();
            $staffRow = $stmtStaff->get_result()->fetch_assoc();
            if (!$staffRow) {
                throw new Exception('Nhân viên không tồn tại.');
            }
            if ($currentAdminRole === 'manager' && (!in_array((string)$staffRow['role'], ['reception', 'accounting', 'housekeeping'], true) || (int)($staffRow['id_hotel'] ?? 0) !== $currentAdminHotelId)) {
                throw new Exception('Bạn chỉ được xóa nhân viên cấp dưới trong cơ sở của mình.');
            }
            $stmt = $db->prepare("DELETE FROM admin_users WHERE id_admin=?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $message = 'Đã xóa nhân viên.';
        } elseif ($action === 'save_web_content') {
            $id = (int)($_POST['id_content'] ?? 0);
            $type = trim($_POST['content_type'] ?? 'banner');
            $title = trim($_POST['title'] ?? '');
            $imageUrl = trim($_POST['image_url'] ?? '');
            $uploadedContentImage = saveUploadedImage('content_image_file', 'content');
            if ($uploadedContentImage !== '') {
                $imageUrl = $uploadedContentImage;
            } else {
                $imageUrl = localizeRemoteImage($imageUrl, 'content');
            }
            $body = trim($_POST['body'] ?? '');
            $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
            if ($title === '') {
                throw new Exception('Vui lòng nhập tiêu đề nội dung website.');
            }
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE website_contents SET content_type=?, title=?, image_url=?, body=?, is_active=? WHERE id_content=?");
                $stmt->bind_param('ssssii', $type, $title, $imageUrl, $body, $isActive, $id);
            } else {
                $stmt = $db->prepare("INSERT INTO website_contents (content_type, title, image_url, body, is_active) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param('ssssi', $type, $title, $imageUrl, $body, $isActive);
            }
            $stmt->execute();
            $message = 'Đã lưu nội dung website.';
        } elseif ($action === 'save_room') {
            $id = (int)($_POST['id_room'] ?? 0);
            $hotelId = (int)($_POST['id_hotel'] ?? 0);
            $number = trim($_POST['room_number'] ?? '');
            $type = trim($_POST['room_type'] ?? '');
            $floor = max(1, (int)($_POST['floor_no'] ?? 1));
            $capacity = max(1, (int)($_POST['capacity'] ?? 1));
            $roomSize = trim($_POST['room_size'] ?? '');
            $bedType = trim($_POST['bed_type'] ?? '');
            $nightPrice = max(0, (float)($_POST['price_per_night'] ?? 0));
            $hourRate = max(0, (float)($_POST['hourly_rate'] ?? 0));
            $extraGuestRatePercent = min(100, max(0, (float)($_POST['extra_guest_rate_percent'] ?? 25)));
            $imageUrl = trim($_POST['image_url'] ?? '');
            $galleryUrls = trim($_POST['gallery_urls'] ?? '');
            $oldImageUrl = $imageUrl;
            $oldGalleryUrls = $galleryUrls;
            $deleteRoomImage = !empty($_POST['delete_room_image']);
            $deleteRoomGallery = !empty($_POST['delete_room_gallery']);
            if ($deleteRoomImage) {
                $imageUrl = '';
            }
            if ($deleteRoomGallery) {
                $galleryUrls = '';
            }
            $uploadedRoomImage = saveUploadedImage('room_image_file', 'room');
            if ($uploadedRoomImage !== '') {
                $imageUrl = $uploadedRoomImage;
            } else {
                $imageUrl = localizeRemoteImage($imageUrl, 'room');
            }
            $galleryImages = splitImageList($galleryUrls);
            $uploadedGalleryImages = saveUploadedImages('gallery_image_files', 'room-gallery');
            $galleryUrls = implode(',', array_values(array_unique(array_merge(array_map(static fn(string $image): string => localizeRemoteImage($image, 'room-gallery'), $galleryImages), $uploadedGalleryImages))));
            $imagesToDelete = [];
            if ($deleteRoomImage || ($uploadedRoomImage !== '' && $oldImageUrl !== '' && $oldImageUrl !== $uploadedRoomImage)) {
                $imagesToDelete[] = $oldImageUrl;
            }
            if ($deleteRoomGallery) {
                $imagesToDelete = array_merge($imagesToDelete, splitImageList($oldGalleryUrls));
            }
            $amenities = trim($_POST['amenities'] ?? '');
            $packageName = trim($_POST['package_name'] ?? '');
            $packageServices = trim($_POST['package_services'] ?? '');
            $status = $_POST['status'] ?? 'available';
            $note = trim($_POST['note'] ?? '');
            if ($status === 'maintenance' && !$canSetMaintenance) {
                throw new Exception('Chỉ admin mới được chuyển phòng sang trạng thái bảo trì.');
            }
            if ($id > 0 && !$canSetMaintenance) {
                $stmt = $db->prepare("SELECT status FROM rooms WHERE id_room=?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $currentRoom = $stmt->get_result()->fetch_assoc();
                if (($currentRoom['status'] ?? '') === 'maintenance' && $status !== 'maintenance') {
                    throw new Exception('Chỉ admin mới được thay đổi phòng đang bảo trì.');
                }
            }
            if ($hotelId <= 0 || $number === '' || $type === '') {
                throw new Exception('Vui lòng chọn khách sạn, nhập số phòng và loại phòng.');
            }
            if ($currentAdminRole !== 'admin' && ($currentAdminHotelId <= 0 || $hotelId !== $currentAdminHotelId)) {
                throw new Exception('Bạn chỉ được lưu phòng trong cơ sở được phân quyền.');
            }
            if ($id > 0) {
                adminEnsureRoomScope($db, $currentAdminRole, $currentAdminHotelId, $id);
                $stmt = $db->prepare("UPDATE rooms SET id_hotel=?, room_number=?, room_type=?, floor_no=?, capacity=?, room_size=?, bed_type=?, price_per_night=?, hourly_rate=?, extra_guest_rate_percent=?, image_url=?, gallery_urls=?, amenities=?, package_name=?, package_services=?, status=?, note=? WHERE id_room=?");
                $stmt->bind_param('issiissdddsssssssi', $hotelId, $number, $type, $floor, $capacity, $roomSize, $bedType, $nightPrice, $hourRate, $extraGuestRatePercent, $imageUrl, $galleryUrls, $amenities, $packageName, $packageServices, $status, $note, $id);
            } else {
                $stmt = $db->prepare("INSERT INTO rooms (id_hotel, room_number, room_type, floor_no, capacity, room_size, bed_type, price_per_night, hourly_rate, extra_guest_rate_percent, image_url, gallery_urls, amenities, package_name, package_services, status, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('issiissdddsssssss', $hotelId, $number, $type, $floor, $capacity, $roomSize, $bedType, $nightPrice, $hourRate, $extraGuestRatePercent, $imageUrl, $galleryUrls, $amenities, $packageName, $packageServices, $status, $note);
            }
            $stmt->execute();
            foreach (array_unique(array_filter($imagesToDelete)) as $imageToDelete) {
                deleteLocalImageIfUnused($db, $imageToDelete);
            }
            $message = 'Đã lưu thông tin phòng.';
        } elseif ($action === 'delete_room') {
            $id = (int)($_POST['id_room'] ?? 0);
            if ($id <= 0) {
                throw new Exception('Vui lòng chọn phòng cần xóa.');
            }
            adminEnsureRoomScope($db, $currentAdminRole, $currentAdminHotelId, $id);
            $stmtRoom = $db->prepare("SELECT image_url, gallery_urls FROM rooms WHERE id_room=? LIMIT 1");
            $stmtRoom->bind_param('i', $id);
            $stmtRoom->execute();
            $roomToDelete = $stmtRoom->get_result()->fetch_assoc();
            if (!$roomToDelete) {
                throw new Exception('Phòng không tồn tại.');
            }

            $stmtActive = $db->prepare("SELECT COUNT(*) AS total FROM bookings WHERE id_room=? AND (status='checked_in' OR (status='booked' AND check_out >= CURDATE()))");
            $stmtActive->bind_param('i', $id);
            $stmtActive->execute();
            if ((int)$stmtActive->get_result()->fetch_assoc()['total'] > 0) {
                throw new Exception('Phòng đang có booking hoặc khách đang ở nên không thể xóa.');
            }

            $stmtHistory = $db->prepare("SELECT COUNT(*) AS total FROM bookings WHERE id_room=?");
            $stmtHistory->bind_param('i', $id);
            $stmtHistory->execute();
            $hasBookingHistory = (int)$stmtHistory->get_result()->fetch_assoc()['total'] > 0;

            if ($hasBookingHistory) {
                $stmt = $db->prepare("UPDATE rooms SET is_active=0, status='maintenance', housekeeping_status=NULL, housekeeping_started_at=NULL, cleaned_at=NULL WHERE id_room=?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
            } else {
                $imagesToDelete = array_merge(
                    [(string)($roomToDelete['image_url'] ?? '')],
                    splitImageList((string)($roomToDelete['gallery_urls'] ?? ''))
                );
                $stmt = $db->prepare("DELETE FROM rooms WHERE id_room=?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                foreach (array_unique(array_filter($imagesToDelete)) as $imageToDelete) {
                    deleteLocalImageIfUnused($db, $imageToDelete);
                }
            }
            $message = 'Đã xóa phòng.';
        } elseif ($action === 'set_room_status') {
            $roomId = (int)($_POST['id_room'] ?? 0);
            $status = $_POST['status'] ?? 'available';
            adminEnsureRoomScope($db, $currentAdminRole, $currentAdminHotelId, $roomId);
            if ($status === 'maintenance' && !$canSetMaintenance) {
                throw new Exception('Chỉ admin mới được chuyển phòng sang trạng thái bảo trì.');
            }
            if (!$canSetMaintenance) {
                $stmt = $db->prepare("SELECT status FROM rooms WHERE id_room=?");
                $stmt->bind_param('i', $roomId);
                $stmt->execute();
                $currentRoom = $stmt->get_result()->fetch_assoc();
                if (($currentRoom['status'] ?? '') === 'maintenance' && $status !== 'maintenance') {
                    throw new Exception('Chỉ admin mới được thay đổi phòng đang bảo trì.');
                }
            }
            $stmt = $db->prepare("UPDATE rooms SET status=? WHERE id_room=?");
            $stmt->bind_param('si', $status, $roomId);
            $stmt->execute();
            $message = 'Đã cập nhật trạng thái phòng.';
            if ($status !== 'cleaning') {
                $stmt = $db->prepare("UPDATE rooms SET housekeeping_status=NULL, housekeeping_started_at=NULL WHERE id_room=?");
                $stmt->bind_param('i', $roomId);
                $stmt->execute();
            }
        } elseif ($action === 'set_housekeeping_status') {
            $roomId = (int)($_POST['id_room'] ?? 0);
            $status = $_POST['status'] ?? 'cleaning';
            adminEnsureRoomScope($db, $currentAdminRole, $currentAdminHotelId, $roomId);
            if ($roomId <= 0 || !in_array($status, ['cleaning', 'available'], true)) {
                throw new Exception('Trạng thái dọn phòng không hợp lệ.');
            }
            if ($status === 'cleaning') {
                $nowCleaning = date('Y-m-d H:i:s');
                $stmt = $db->prepare("UPDATE rooms SET status='cleaning', housekeeping_status='cleaning', housekeeping_started_at=COALESCE(housekeeping_started_at, ?), cleaned_at=NULL WHERE id_room=?");
                $stmt->bind_param('si', $nowCleaning, $roomId);
                $stmt->execute();
                $message = 'Đã chuyển phòng sang đang dọn.';
            } else {
                $cleanedAt = date('Y-m-d H:i:s');
                $stmt = $db->prepare("UPDATE rooms SET status='available', housekeeping_status=NULL, housekeeping_started_at=NULL, cleaned_at=? WHERE id_room=?");
                $stmt->bind_param('si', $cleanedAt, $roomId);
                $stmt->execute();
                $message = 'Đã hoàn tất dọn phòng và chuyển phòng sang trống.';
            }
        } elseif ($action === 'save_guest') {
            $id = (int)($_POST['id_guest'] ?? 0);
            adminEnsureGuestScope($db, $currentAdminRole, $currentAdminHotelId, $id);
            $name = trim($_POST['full_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $identity = trim($_POST['identity_no'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $isVip = isset($_POST['is_vip']) ? (int)$_POST['is_vip'] : 0;
            $points = max(0, (int)($_POST['loyalty_points'] ?? 0));
            if ($name === '' || $phone === '') {
                throw new Exception('Vui lòng nhập tên và số điện thoại khách.');
            }
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE guests SET full_name=?, phone=?, email=?, identity_no=?, address=?, is_vip=?, loyalty_points=? WHERE id_guest=?");
                $stmt->bind_param('sssssiii', $name, $phone, $email, $identity, $address, $isVip, $points, $id);
            } else {
                $stmt = $db->prepare("INSERT INTO guests (full_name, phone, email, identity_no, address, is_vip, loyalty_points) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('sssssii', $name, $phone, $email, $identity, $address, $isVip, $points);
            }
            $stmt->execute();
            $message = 'Đã lưu khách hàng.';
        } elseif ($action === 'delete_guest') {
            $id = (int)($_POST['id_guest'] ?? 0);
            if ($id <= 0) {
                throw new Exception('Vui lòng chọn khách hàng cần xóa.');
            }
            if ($currentAdminRole !== 'admin' && $currentAdminHotelId <= 0) {
                throw new Exception('Tài khoản này chưa được phân quyền cơ sở.');
            }
            $stmtCount = $db->prepare("SELECT COUNT(*) AS total FROM bookings WHERE id_guest=?");
            $stmtCount->bind_param('i', $id);
            $stmtCount->execute();
            if ((int)$stmtCount->get_result()->fetch_assoc()['total'] > 0) {
                throw new Exception('Khách hàng đã có booking nên không thể xóa để giữ lịch sử.');
            }
            $stmt = $db->prepare("DELETE FROM guests WHERE id_guest=?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $message = 'Đã xóa khách hàng.';
        } elseif ($action === 'save_booking' || $action === 'walk_in') {
            $isWalkIn = $action === 'walk_in';
            $bookingId = (int)($_POST['id_booking'] ?? 0);
            $bookingWasExisting = $bookingId > 0;
            $roomId = (int)($_POST['id_room'] ?? 0);
            $bookingHotelId = (int)($_POST['booking_id_hotel'] ?? 0);
            adminEnsureRoomScope($db, $currentAdminRole, $currentAdminHotelId, $roomId);
            if ($bookingId > 0) {
                adminEnsureBookingScope($db, $currentAdminRole, $currentAdminHotelId, $bookingId);
            }
            $name = trim($_POST['full_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $identity = trim($_POST['identity_no'] ?? '');
            $adults = max(1, (int)($_POST['adults'] ?? 1));
            $children = max(0, (int)($_POST['children'] ?? 0));
            $note = trim($_POST['note'] ?? '');
            $pricingMode = $isWalkIn ? 'hour' : ($_POST['pricing_mode'] ?? 'night');

            if (!$isWalkIn && $bookingHotelId <= 0) {
                throw new Exception('Vui lòng chọn cơ sở trước khi chọn phòng.');
            }
            if ($roomId <= 0 || $name === '' || $phone === '' || $email === '' || $identity === '') {
                throw new Exception('Vui lòng chọn phòng và nhập thông tin khách.');
            }

            $stmtRoomPolicy = $db->prepare("SELECT capacity, id_hotel, status FROM rooms WHERE id_room=? AND is_active=1 LIMIT 1");
            $stmtRoomPolicy->bind_param('i', $roomId);
            $stmtRoomPolicy->execute();
            $roomPolicy = $stmtRoomPolicy->get_result()->fetch_assoc();
            if (!$roomPolicy) {
                throw new Exception('Phòng không tồn tại.');
            }
            if (($roomPolicy['status'] ?? '') === 'maintenance') {
                throw new Exception('Phòng không còn trống để đặt.');
            }
            if (!$isWalkIn && (int)$roomPolicy['id_hotel'] !== $bookingHotelId) {
                throw new Exception('Phòng không thuộc cơ sở đã chọn.');
            }
            $occupancyPolicy = bookingOccupancyPolicy([
                'capacity' => (int)$roomPolicy['capacity'],
                'adults' => $adults,
                'children' => $children,
            ]);
            if (!$occupancyPolicy['is_valid']) {
                throw new Exception('Số khách chỉ được vượt tối đa 1 người so với sức chứa phòng.');
            }

            if ($isWalkIn) {
                $startAt = date('Y-m-d H:i:s');
                $walkinMode = ($_POST['walkin_mode'] ?? 'hour') === 'night' ? 'night' : 'hour';
                $pricingMode = $walkinMode === 'night' ? 'night' : 'hour';
                if ($walkinMode === 'night') {
                    $checkOutDate = $_POST['walkin_check_out'] ?? date('Y-m-d', strtotime('+1 day'));
                    $endAt = $checkOutDate . ' 12:00:00';
                } else {
                    $endAt = date('Y-m-d H:i:s', strtotime('+' . max(1, (int)($_POST['expected_hours'] ?? 2)) . ' hours'));
                }
                $status = 'checked_in';
            } else {
                $checkInDate = $_POST['check_in'] ?? date('Y-m-d');
                $checkOutDate = $_POST['check_out'] ?? date('Y-m-d', strtotime('+1 day'));
                $startAt = $checkInDate . ' 14:00:00';
                $endAt = $checkOutDate . ' 12:00:00';
                if ($bookingId > 0) {
                    $existingStatusBooking = fetchBooking($db, $bookingId);
                    $status = $existingStatusBooking['status'] ?? 'booked';
                } else {
                    $status = 'booked';
                }
            }

            if (strtotime($endAt) <= strtotime($startAt)) {
                throw new Exception('Thời gian trả phòng phải sau thời gian nhận phòng.');
            }
            if (!isRoomAvailable($db, $roomId, $startAt, $endAt, $bookingId)) {
                throw new Exception('Phòng đã có lịch đặt trong khoảng thời gian này.');
            }

            $checkIn = substr($startAt, 0, 10);
            $checkOut = substr($endAt, 0, 10);
            if ($checkOut <= $checkIn) {
                $checkOut = date('Y-m-d', strtotime($checkIn . ' +1 day'));
            }
            if ($bookingId > 0) {
                $existingBooking = fetchBooking($db, $bookingId);
                if (!$existingBooking) {
                throw new Exception('Booking không tồn tại.');
                }
                $stmt = $db->prepare("UPDATE guests SET full_name=?, phone=?, email=?, identity_no=? WHERE id_guest=?");
                $stmt->bind_param('ssssi', $name, $phone, $email, $identity, $existingBooking['id_guest']);
                $stmt->execute();

                $stmt = $db->prepare("UPDATE bookings SET id_room=?, check_in=?, check_out=?, check_in_at=?, expected_check_out_at=?, pricing_mode=?, adults=?, children=?, status=?, note=? WHERE id_booking=?");
                $stmt->bind_param('isssssiissi', $roomId, $checkIn, $checkOut, $startAt, $endAt, $pricingMode, $adults, $children, $status, $note, $bookingId);
                $stmt->execute();
                $code = $existingBooking['booking_code'];
            } else {
                $guestId = upsertGuestByIdentity($db, $name, $phone, $email, $identity);

                $code = bookingCode();
                $stmt = $db->prepare("INSERT INTO bookings (booking_code, id_guest, id_room, check_in, check_out, check_in_at, expected_check_out_at, pricing_mode, adults, children, status, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('siisssssiiss', $code, $guestId, $roomId, $checkIn, $checkOut, $startAt, $endAt, $pricingMode, $adults, $children, $status, $note);
                $stmt->execute();
                $bookingId = $db->insert_id;
            }

            $roomStatus = $status === 'checked_in' ? 'occupied' : ($status === 'checked_out' ? 'cleaning' : 'available');
            $housekeepingStatus = $status === 'checked_out' ? 'pending' : null;
            $stmt = $db->prepare("UPDATE rooms SET status=?, housekeeping_status=?, housekeeping_started_at=NULL, cleaned_at=NULL WHERE id_room=?");
            $stmt->bind_param('ssi', $roomStatus, $housekeepingStatus, $roomId);
            $stmt->execute();

            if ($status === 'checked_out') {
                $checkedOutAt = date('Y-m-d H:i:s');
                $stmt = $db->prepare("UPDATE bookings SET checked_out_at=COALESCE(checked_out_at, ?) WHERE id_booking=?");
                $stmt->bind_param('si', $checkedOutAt, $bookingId);
                $stmt->execute();
            }
            $bookingEvent = $isWalkIn
                ? ($bookingWasExisting ? 'walk_in_updated_by_admin' : 'walk_in_created_by_admin')
                : ($bookingWasExisting ? 'booking_updated_by_admin' : 'booking_created_by_admin');
            hotelBlockchainRecordBooking($db, (int)$bookingId, $bookingEvent);
            header('Location: ' . adminReturnLocation($status === 'booked' ? 'bookings' : 'rooms', ['created' => $code]));
            exit;
        } elseif ($action === 'set_booking_status') {
            $bookingId = (int)($_POST['id_booking'] ?? 0);
            $status = $_POST['status'] ?? 'booked';
            adminEnsureBookingScope($db, $currentAdminRole, $currentAdminHotelId, $bookingId);
            $bookingBefore = fetchBooking($db, $bookingId);
            if (!$bookingBefore) {
                throw new Exception('Booking không tồn tại.');
            }
            if ($status === 'cancelled') {
                hotelBlockchainRecordBookingSnapshot($db, $bookingBefore, 'booking_cancelled_by_admin', [
                    'requested_status' => 'cancelled',
                ]);
                $stmt = $db->prepare("UPDATE rooms SET status='available', housekeeping_status=NULL, housekeeping_started_at=NULL, cleaned_at=NULL WHERE id_room=? AND status <> 'maintenance'");
                $stmt->bind_param('i', $bookingBefore['id_room']);
                $stmt->execute();
                $stmt = $db->prepare("DELETE FROM bookings WHERE id_booking=?");
                $stmt->bind_param('i', $bookingId);
                $stmt->execute();
                header('Location: ' . adminReturnLocation('bookings', ['deleted' => 1]));
                exit;
            }
            if ($bookingBefore['status'] === 'booked' && !in_array($status, ['booked', 'checked_in', 'cancelled'], true)) {
                throw new Exception('Phòng đã đặt chỉ được chuyển sang đã check-in hoặc hủy phòng.');
            }
            $checkedOutAt = $status === 'checked_out' ? date('Y-m-d H:i:s') : null;
            $stmt = $db->prepare("UPDATE bookings SET status=?, checked_out_at=COALESCE(?, checked_out_at) WHERE id_booking=?");
            $stmt->bind_param('ssi', $status, $checkedOutAt, $bookingId);
            $stmt->execute();

            $booking = fetchBooking($db, $bookingId);
            if ($booking) {
                $roomStatus = $status === 'checked_in' ? 'occupied' : ($status === 'checked_out' ? 'cleaning' : 'available');
                $housekeepingStatus = $status === 'checked_out' ? 'pending' : null;
                $stmt = $db->prepare("UPDATE rooms SET status=?, housekeeping_status=?, housekeeping_started_at=NULL, cleaned_at=NULL WHERE id_room=?");
                $stmt->bind_param('ssi', $roomStatus, $housekeepingStatus, $booking['id_room']);
                $stmt->execute();
                if ($status === 'checked_out') {
                    awardGuestLoyaltyForClosedBill($db, $bookingId);
                }
            }
            hotelBlockchainRecordBooking($db, $bookingId, 'booking_status_updated_by_admin', [
                'previous_status' => $bookingBefore['status'] ?? null,
                'new_status' => $status,
            ]);
            $message = 'Đã cập nhật trạng thái đặt phòng.';
        } elseif ($action === 'save_service') {
            $id = (int)($_POST['id_service'] ?? 0);
            $name = trim($_POST['service_name'] ?? '');
            $unit = trim($_POST['unit'] ?? 'lần');
            $price = max(0, (float)($_POST['price'] ?? 0));
            $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
            if ($name === '') {
                throw new Exception('Vui lòng nhập tên dịch vụ.');
            }
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE services SET service_name=?, unit=?, price=?, is_active=? WHERE id_service=?");
                $stmt->bind_param('ssdii', $name, $unit, $price, $isActive, $id);
            } else {
                $stmt = $db->prepare("INSERT INTO services (service_name, unit, price, is_active) VALUES (?, ?, ?, ?)");
                $stmt->bind_param('ssdi', $name, $unit, $price, $isActive);
            }
            $stmt->execute();
            $message = 'Đã lưu item đồ ăn thức uống.';
        } elseif ($action === 'delete_service') {
            $id = (int)($_POST['id_service'] ?? 0);
            if ($id <= 0) {
                throw new Exception('Vui lòng chọn dịch vụ cần xóa.');
            }
            $stmtCount = $db->prepare("SELECT COUNT(*) AS total FROM booking_services WHERE id_service=?");
            $stmtCount->bind_param('i', $id);
            $stmtCount->execute();
            if ((int)$stmtCount->get_result()->fetch_assoc()['total'] > 0) {
                throw new Exception('Dịch vụ đã phát sinh trong hóa đơn nên không thể xóa. Có thể chuyển sang tạm ẩn.');
            }
            $stmt = $db->prepare("DELETE FROM services WHERE id_service=?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $message = 'Đã xóa dịch vụ.';
        } elseif ($action === 'add_booking_service') {
            $bookingId = (int)($_POST['id_booking'] ?? 0);
            adminEnsureBookingScope($db, $currentAdminRole, $currentAdminHotelId, $bookingId);
            $serviceId = (int)($_POST['id_service'] ?? 0);
            $quantity = max(1, (int)($_POST['quantity'] ?? 1));
            $stmt = $db->prepare("SELECT price FROM services WHERE id_service=?");
            $stmt->bind_param('i', $serviceId);
            $stmt->execute();
            $service = $stmt->get_result()->fetch_assoc();
            if (!$service) {
                throw new Exception('Item không tồn tại.');
            }
            $price = (float)$service['price'];
            $stmt = $db->prepare("INSERT INTO booking_services (id_booking, id_service, quantity, price) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('iiid', $bookingId, $serviceId, $quantity, $price);
            $stmt->execute();
            $message = 'Đã thêm item vào hóa đơn.';
        } elseif ($action === 'add_payment') {
            $bookingId = (int)($_POST['id_booking'] ?? 0);
            adminEnsureBookingScope($db, $currentAdminRole, $currentAdminHotelId, $bookingId);
            $note = trim($_POST['note'] ?? '');
            $redeemPointsRequested = max(0, (int)($_POST['redeem_points'] ?? 0));
            $paymentEntries = [];
            $amount = max(0, (float)($_POST['amount'] ?? 0));
            $method = trim((string)($_POST['method'] ?? ''));
            if ($amount > 0) {
                if ($method === '') {
                    throw new Exception('Vui lòng chọn hình thức thanh toán.');
                }
                $paymentEntries[] = ['amount' => $amount, 'method' => $method];
            }
            if (!$paymentEntries && $redeemPointsRequested <= 0) {
                throw new Exception('Số tiền thanh toán phải lớn hơn 0 hoặc chọn trừ điểm.');
            }
            $paidBooking = fetchBooking($db, $bookingId);
            if (!$paidBooking) {
                throw new Exception('Booking không tồn tại.');
            }
            $totalsBeforePayment = bookingTotals($db, $paidBooking);
            $debtBeforePayment = (float)$totalsBeforePayment['debt'];
            if ($debtBeforePayment <= 0) {
                header('Location: admin.php?view=invoices&booking=' . $bookingId);
                exit;
            }
            if ($redeemPointsRequested > 0) {
                $availablePoints = max(0, (int)($paidBooking['loyalty_points'] ?? 0));
                $redeemPoints = min($redeemPointsRequested, $availablePoints, (int)floor($debtBeforePayment));
                if ($redeemPoints <= 0) {
                    throw new Exception('Khách không có đủ điểm để khấu trừ.');
                }
                $paymentEntries[] = ['amount' => (float)$redeemPoints, 'method' => 'Điểm tích lũy', 'redeem_points' => $redeemPoints];
            }
            $paidNow = array_sum(array_map(static function ($entry) {
                return (float)$entry['amount'];
            }, $paymentEntries));
            if ($paidNow > $debtBeforePayment + 0.01) {
                throw new Exception('Số tiền thanh toán vượt quá số còn phải thu.');
            }
            $stmt = $db->prepare("INSERT INTO payments (id_booking, amount, method, note, cashier_name) VALUES (?, ?, ?, ?, ?)");
            foreach ($paymentEntries as $entry) {
                $amount = (float)$entry['amount'];
                $method = (string)$entry['method'];
                $stmt->bind_param('idsss', $bookingId, $amount, $method, $note, $currentAdminName);
                $stmt->execute();
                hotelBlockchainRecordPayment($db, (int)$db->insert_id, 'payment_added_by_admin');
                if (!empty($entry['redeem_points'])) {
                    redeemGuestLoyaltyPoints($db, (int)$paidBooking['id_guest'], (int)$entry['redeem_points']);
                }
            }
            $isFullyPaid = false;
            if ($paidBooking && ($paidBooking['status'] ?? '') === 'checked_in') {
                $totalsAfterPayment = bookingTotals($db, $paidBooking);
                if ((float)$totalsAfterPayment['debt'] <= 0) {
                    $isFullyPaid = true;
                    $checkedOutAt = date('Y-m-d H:i:s');
                    $stmt = $db->prepare("UPDATE bookings SET status='checked_out', checked_out_at=COALESCE(checked_out_at, ?) WHERE id_booking=?");
                    $stmt->bind_param('si', $checkedOutAt, $bookingId);
                    $stmt->execute();
                    $stmt = $db->prepare("UPDATE rooms SET status='cleaning', housekeeping_status='pending', housekeeping_started_at=NULL, cleaned_at=NULL WHERE id_room=?");
                    $stmt->bind_param('i', $paidBooking['id_room']);
                    $stmt->execute();
                    awardGuestLoyaltyForClosedBill($db, $bookingId);
                    $message = 'Thanh toán đủ, booking đã chuyển sang đã trả phòng.';
                }
            } elseif ($paidBooking) {
                $totalsAfterPayment = bookingTotals($db, $paidBooking);
                $isFullyPaid = (float)$totalsAfterPayment['debt'] <= 0;
                if ($isFullyPaid && ($paidBooking['status'] ?? '') === 'checked_out') {
                    awardGuestLoyaltyForClosedBill($db, $bookingId);
                }
            }
            hotelBlockchainRecordBooking($db, $bookingId, $isFullyPaid ? 'booking_payment_completed' : 'booking_payment_added');
            if ($isFullyPaid) {
                header('Location: ' . adminReturnLocation('room_status', ['paid' => 1]));
                exit;
            }
            header('Location: ' . adminReturnLocation('room_status', ['payment' => 1]));
            exit;
        }
    } catch (Throwable $e) {
        logSecurityEvent($db, 'admin_action_failed', 'failed', $e->getMessage(), ['action' => $action, 'role' => $currentAdminRole]);
        $message = $e->getMessage();
        $isError = true;
    }
}

$view = $_GET['view'] ?? 'dashboard';
if (in_array($view, ['walkin', 'website'], true)) {
    $view = 'dashboard';
}
if (!adminCanAccessView($currentAdminRole, $view)) {
    $view = adminDefaultView($currentAdminRole);
}
$allowedViews = adminAllowedViews($currentAdminRole);
$bookingId = (int)($_GET['booking'] ?? 0);
$search = trim($_GET['q'] ?? '');
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$selectedHotelId = (int)($_GET['hotel'] ?? 0);
if ($currentAdminRole !== 'admin') {
    $selectedHotelId = $currentAdminHotelId > 0 ? $currentAdminHotelId : -1;
}
$missingHotelScope = $currentAdminRole !== 'admin' && $currentAdminHotelId <= 0;
$currentAdminUrl = 'admin.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$bookingRange = $_GET['booking_range'] ?? 'all';
$invoiceRange = $_GET['invoice_range'] ?? 'all';
$reportRange = $_GET['report_range'] ?? 'all';
$bookingRangeOptions = ['today', 'next7', 'prev3', 'all', 'custom'];
$invoiceRangeOptions = ['today', 'month', 'prev3', 'all', 'custom'];
$reportRangeOptions = ['today', 'month', 'all', 'custom'];
if (!in_array($bookingRange, $bookingRangeOptions, true)) {
    $bookingRange = 'all';
}
if (!in_array($invoiceRange, $invoiceRangeOptions, true)) {
    $invoiceRange = 'all';
}
if (!in_array($reportRange, $reportRangeOptions, true)) {
    $reportRange = 'all';
}
if (in_array($view, ['bookings', 'invoices', 'reports'], true)) {
    $activeRange = $view === 'invoices' ? $invoiceRange : ($view === 'reports' ? $reportRange : $bookingRange);
    if ($activeRange !== 'custom') {
        $dateFrom = '';
        $dateTo = '';
    }
    if ($activeRange === 'custom') {
        // Keep the submitted date fields.
    } elseif ($activeRange === 'all') {
        $dateFrom = '';
        $dateTo = '';
    } elseif ($activeRange === 'month') {
        $dateFrom = date('Y-m-01');
        $dateTo = date('Y-m-t');
    } elseif ($activeRange === 'next7') {
        $dateFrom = $today;
        $dateTo = date('Y-m-d', strtotime('+7 days'));
    } elseif ($activeRange === 'prev3') {
        $dateFrom = date('Y-m-d', strtotime('-3 days'));
        $dateTo = $today;
    } else {
        $dateFrom = $today;
        $dateTo = $today;
    }
} elseif ($dateFrom !== '' || $dateTo !== '') {
    if ($view === 'invoices') {
        $invoiceRange = 'custom';
    } elseif ($view === 'reports') {
        $reportRange = 'custom';
    } else {
        $bookingRange = 'custom';
    }
}
if ($dateFrom === '' && $dateTo === '') {
    $dateRangeLabel = 'Tất cả ngày';
} elseif ($dateFrom !== '' && $dateTo !== '') {
    $dateRangeLabel = adminDate($dateFrom) . ' - ' . adminDate($dateTo);
} elseif ($dateFrom !== '') {
    $dateRangeLabel = 'Từ ' . adminDate($dateFrom);
} else {
    $dateRangeLabel = 'Đến ' . adminDate($dateTo);
}
$roomMode = $_GET['room_mode'] ?? 'hotel';
if (!in_array($roomMode, ['hotel', 'walkin'], true)) {
    $roomMode = 'hotel';
}

if ($currentAdminRole !== 'admin' && $currentAdminHotelId > 0) {
    $stmtHotels = $db->prepare("SELECT * FROM hotels WHERE is_active=1 AND id_hotel=? ORDER BY star_rating DESC, hotel_name");
    $stmtHotels->bind_param('i', $currentAdminHotelId);
    $stmtHotels->execute();
    $hotels = $stmtHotels->get_result()->fetch_all(MYSQLI_ASSOC);
} elseif ($currentAdminRole !== 'admin') {
    $hotels = [];
} else {
    $hotels = $db->query("SELECT * FROM hotels WHERE is_active=1 ORDER BY star_rating DESC, hotel_name")->fetch_all(MYSQLI_ASSOC);
}
$currentBranchName = $currentAdminRole === 'admin' ? 'Tất cả cơ sở' : ($hotels[0]['hotel_name'] ?? 'Chưa gán cơ sở');

$roomWhere = ['r.is_active=1'];
$roomTypes = '';
$roomParams = [];
if ($selectedHotelId !== 0) {
    $roomWhere[] = 'r.id_hotel=?';
    $roomTypes .= 'i';
    $roomParams[] = $selectedHotelId;
}
if ($view === 'rooms' && $roomMode === 'walkin') {
    $roomWhere[] = "r.status='available'";
}
if ($view === 'rooms' && $search !== '') {
    $like = '%' . $search . '%';
    $roomWhere[] = '(r.room_number LIKE ? OR r.room_type LIKE ? OR r.bed_type LIKE ? OR h.hotel_name LIKE ?)';
    $roomTypes .= 'ssss';
    array_push($roomParams, $like, $like, $like, $like);
}
$roomQuery = "SELECT r.*, h.hotel_name, h.address AS hotel_address, h.city AS hotel_city, h.star_rating, h.hero_image AS hotel_image
    FROM rooms r
    LEFT JOIN hotels h ON h.id_hotel=r.id_hotel"
    . ($roomWhere ? ' WHERE ' . implode(' AND ', $roomWhere) : '')
    . " ORDER BY h.hotel_name, r.floor_no, r.room_number";
if ($roomParams) {
    $stmt = $db->prepare($roomQuery);
    $stmt->bind_param($roomTypes, ...$roomParams);
    $stmt->execute();
    $rooms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $rooms = $db->query($roomQuery)->fetch_all(MYSQLI_ASSOC);
}
$walkinBookedRangesByRoom = [];
if ($rooms) {
    $roomIds = array_values(array_unique(array_map(static fn(array $room): int => (int)$room['id_room'], $rooms)));
    $roomIdList = implode(',', array_map('intval', $roomIds));
    if ($roomIdList !== '') {
        $rangeRows = $db->query("SELECT id_booking, id_room, check_in, check_out FROM bookings WHERE id_room IN ($roomIdList) AND status IN ('booked','checked_in') ORDER BY check_in")->fetch_all(MYSQLI_ASSOC);
        foreach ($rangeRows as $rangeRow) {
            $rangeRoomId = (int)$rangeRow['id_room'];
            if (!isset($walkinBookedRangesByRoom[$rangeRoomId])) {
                $walkinBookedRangesByRoom[$rangeRoomId] = [];
            }
            $walkinBookedRangesByRoom[$rangeRoomId][] = [
                'id' => (int)$rangeRow['id_booking'],
                'from' => (string)$rangeRow['check_in'],
                'to' => (string)$rangeRow['check_out'],
            ];
        }
    }
}
$roomsByHotel = [];
foreach ($rooms as $room) {
    $hotelKey = (string)($room['id_hotel'] ?? 0);
    if (!isset($roomsByHotel[$hotelKey])) {
        $roomsByHotel[$hotelKey] = [
            'hotel_name' => $room['hotel_name'] ?: 'Chưa gán cơ sở',
            'hotel_address' => $room['hotel_address'] ?: '',
            'hotel_image' => $room['hotel_image'] ?: ($room['image_url'] ?? ''),
            'available_count' => 0,
            'rooms' => [],
        ];
    }
    if (($room['status'] ?? '') === 'available') {
        $roomsByHotel[$hotelKey]['available_count']++;
    }
    $roomsByHotel[$hotelKey]['rooms'][] = $room;
}

$guestQuery = "SELECT id_guest, full_name, phone, email, identity_no, address, is_vip, loyalty_points FROM guests";
$guestParams = [];
$guestTypes = '';
$guestWhere = [];
if ($view === 'guests' && $search !== '') {
    $like = '%' . $search . '%';
    $guestWhere[] = '(full_name LIKE ? OR phone LIKE ? OR email LIKE ? OR identity_no LIKE ?)';
    $guestTypes .= 'ssss';
    array_push($guestParams, $like, $like, $like, $like);
}
if ($guestWhere) {
    $guestQuery .= ' WHERE ' . implode(' AND ', $guestWhere);
}
$guestQuery .= " ORDER BY id_guest DESC";
if ($guestParams) {
    $stmt = $db->prepare($guestQuery);
    $stmt->bind_param($guestTypes, ...$guestParams);
    $stmt->execute();
    $guests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $guests = $db->query($guestQuery)->fetch_all(MYSQLI_ASSOC);
}
$guestIds = array_map(static function (array $guest): int {
    return (int)$guest['id_guest'];
}, $guests);
$guestStatsById = [];
$guestBookingsById = [];
if ($view === 'guests' && $guestIds) {
    $idList = implode(',', array_map('intval', $guestIds));
    $guestStatsRows = $db->query("SELECT g.id_guest, COUNT(DISTINCT b.id_booking) AS booking_count, COALESCE(SUM(p.amount),0) AS total_paid, MAX(b.created_at) AS last_booking_at
        FROM guests g
        LEFT JOIN bookings b ON b.id_guest=g.id_guest
        LEFT JOIN payments p ON p.id_booking=b.id_booking
        WHERE g.id_guest IN ($idList)
        GROUP BY g.id_guest")->fetch_all(MYSQLI_ASSOC);
    foreach ($guestStatsRows as $row) {
        $guestStatsById[(int)$row['id_guest']] = $row;
    }

    $guestBookingRows = $db->query("SELECT b.id_guest, b.booking_code, b.check_in_at, b.expected_check_out_at, b.check_in, b.check_out, b.status, b.pricing_mode,
            r.room_number, h.hotel_name, COALESCE(SUM(p.amount),0) AS paid
        FROM bookings b
        JOIN rooms r ON r.id_room=b.id_room
        LEFT JOIN hotels h ON h.id_hotel=r.id_hotel
        LEFT JOIN payments p ON p.id_booking=b.id_booking
        WHERE b.id_guest IN ($idList)
        GROUP BY b.id_booking
        ORDER BY b.created_at DESC")->fetch_all(MYSQLI_ASSOC);
    foreach ($guestBookingRows as $row) {
        $guestIdKey = (int)$row['id_guest'];
        if (!isset($guestBookingsById[$guestIdKey])) {
            $guestBookingsById[$guestIdKey] = [];
        }
        $guestBookingsById[$guestIdKey][] = $row;
    }
}
$selectedBooking = ($bookingId > 0 && $currentAdminRole !== 'housekeeping') ? fetchBooking($db, $bookingId) : null;
if ($selectedBooking && $currentAdminRole !== 'admin' && ($currentAdminHotelId <= 0 || (int)($selectedBooking['id_hotel'] ?? 0) !== $currentAdminHotelId)) {
    $selectedBooking = null;
}
$services = [];
$activeServices = [];
if ($view === 'services' || $selectedBooking) {
    $serviceQuery = "SELECT * FROM services";
    $serviceParams = [];
    if ($view === 'services' && $search !== '') {
        $like = '%' . $search . '%';
        $serviceQuery .= " WHERE service_name LIKE ? OR unit LIKE ?";
        $serviceParams = [$like, $like];
    }
    $serviceQuery .= " ORDER BY is_active DESC, service_name";
    if ($serviceParams) {
        $stmt = $db->prepare($serviceQuery);
        $stmt->bind_param('ss', ...$serviceParams);
        $stmt->execute();
        $services = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $services = $db->query($serviceQuery)->fetch_all(MYSQLI_ASSOC);
    }
    $activeServices = array_values(array_filter($services, static function (array $service): bool {
        return !empty($service['is_active']);
    }));
}
$staffQuery = "SELECT a.id_admin, a.username, a.full_name, a.email, a.role, a.id_hotel, a.is_active, a.created_at, h.hotel_name
    FROM admin_users a
    LEFT JOIN hotels h ON h.id_hotel=a.id_hotel";
$staffWhere = [];
$staffTypes = '';
$staffParams = [];
if ($view === 'staff') {
    if ($currentAdminRole === 'manager') {
        $staffWhere[] = "a.role IN ('reception','accounting','housekeeping')";
        if ($currentAdminHotelId > 0) {
            $staffWhere[] = 'a.id_hotel=?';
            $staffTypes .= 'i';
            $staffParams[] = $currentAdminHotelId;
        } else {
            $staffWhere[] = '1=0';
        }
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $staffWhere[] = '(a.username LIKE ? OR a.full_name LIKE ? OR a.email LIKE ? OR a.role LIKE ? OR h.hotel_name LIKE ?)';
        $staffTypes .= 'sssss';
        array_push($staffParams, $like, $like, $like, $like, $like);
    }
    if ($staffWhere) {
        $staffQuery .= ' WHERE ' . implode(' AND ', $staffWhere);
    }
    $staffQuery .= " ORDER BY a.id_admin DESC";
    if ($staffParams) {
        $stmt = $db->prepare($staffQuery);
        $stmt->bind_param($staffTypes, ...$staffParams);
        $stmt->execute();
        $staff = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $staff = $db->query($staffQuery)->fetch_all(MYSQLI_ASSOC);
    }
} else {
    $staff = [];
}
$websiteContents = $view === 'settings'
    ? $db->query("SELECT * FROM website_contents ORDER BY created_at DESC LIMIT 100")->fetch_all(MYSQLI_ASSOC)
    : [];
$invoiceRows = [];
if ($view === 'invoices') {
    $invoiceSql = "SELECT x.* FROM (
        SELECT b.*, g.full_name, g.phone, r.id_hotel, r.room_number, r.room_type, r.capacity, r.price_per_night, r.hourly_rate, r.extra_guest_rate_percent, h.hotel_name,
            COALESCE(SUM(p.amount),0) AS paid_total, MAX(p.paid_at) AS last_paid_at
        FROM bookings b
        JOIN guests g ON g.id_guest=b.id_guest
        JOIN rooms r ON r.id_room=b.id_room
        LEFT JOIN hotels h ON h.id_hotel=r.id_hotel
        LEFT JOIN payments p ON p.id_booking=b.id_booking
        GROUP BY b.id_booking
    ) x
    WHERE x.status = 'checked_out'";
    $invoiceTypes = '';
    $invoiceParams = [];
    if ($selectedHotelId !== 0) {
        $invoiceSql .= ' AND x.id_hotel=?';
        $invoiceTypes .= 'i';
        $invoiceParams[] = $selectedHotelId;
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $normalizedSearch = strtoupper((string)preg_replace('/\s+/', '', $search));
        $codeLike = '%' . $normalizedSearch . '%';
        $invoiceSearchConditions = [
            "UPPER(REPLACE(CONCAT('HD', LPAD(x.id_booking, 5, '0')), ' ', '')) LIKE ?",
            "UPPER(REPLACE(x.booking_code, ' ', '')) LIKE ?",
            'x.full_name LIKE ?',
            'x.phone LIKE ?',
            'x.room_number LIKE ?',
            'x.hotel_name LIKE ?',
        ];
        $invoiceTypes .= 'ssssss';
        array_push($invoiceParams, $codeLike, $codeLike, $like, $like, $like, $like);
        if (preg_match('/^(?:HD)?0*(\d+)$/i', $normalizedSearch, $invoiceCodeMatch)) {
            $invoiceSearchConditions[] = 'x.id_booking=?';
            $invoiceTypes .= 'i';
            $invoiceParams[] = (int)$invoiceCodeMatch[1];
        }
        $invoiceSql .= ' AND (' . implode(' OR ', $invoiceSearchConditions) . ')';
    }
    if ($dateFrom !== '') {
        $invoiceSql .= ' AND DATE(COALESCE(x.last_paid_at, x.checked_out_at, x.created_at)) >= ?';
        $invoiceTypes .= 's';
        $invoiceParams[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $invoiceSql .= ' AND DATE(COALESCE(x.last_paid_at, x.checked_out_at, x.created_at)) <= ?';
        $invoiceTypes .= 's';
        $invoiceParams[] = $dateTo;
    }
    $invoiceSql .= " ORDER BY COALESCE(x.last_paid_at, x.created_at) DESC LIMIT 150";
    if ($invoiceParams) {
        $stmt = $db->prepare($invoiceSql);
        $stmt->bind_param($invoiceTypes, ...$invoiceParams);
        $stmt->execute();
        $invoiceRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $invoiceRows = $db->query($invoiceSql)->fetch_all(MYSQLI_ASSOC);
    }
}

$bookingSql = "SELECT b.*, g.full_name, g.phone, g.email, g.identity_no, r.room_number, r.room_type, r.capacity, r.price_per_night, r.hourly_rate, r.extra_guest_rate_percent, h.hotel_name, h.address AS hotel_address, h.city AS hotel_city
    FROM bookings b
    JOIN guests g ON g.id_guest = b.id_guest
    JOIN rooms r ON r.id_room = b.id_room
    LEFT JOIN hotels h ON h.id_hotel = r.id_hotel";
$bookingWhere = [];
$bookingTypes = '';
$bookingParams = [];
if ($selectedHotelId !== 0) {
    $bookingWhere[] = 'r.id_hotel=?';
    $bookingTypes .= 'i';
    $bookingParams[] = $selectedHotelId;
}
if ($view === 'bookings') {
    $bookingWhere[] = "b.status='booked'";
}
if ($search !== '') {
    $like = '%' . $search . '%';
    $bookingWhere[] = '(b.booking_code LIKE ? OR g.full_name LIKE ? OR g.phone LIKE ? OR g.identity_no LIKE ? OR r.room_number LIKE ? OR h.hotel_name LIKE ?)';
    $bookingTypes .= 'ssssss';
    array_push($bookingParams, $like, $like, $like, $like, $like, $like);
}
if ($dateFrom !== '') {
    $bookingWhere[] = 'b.check_in >= ?';
    $bookingTypes .= 's';
    $bookingParams[] = $dateFrom;
}
if ($dateTo !== '') {
    $bookingWhere[] = 'b.check_in <= ?';
    $bookingTypes .= 's';
    $bookingParams[] = $dateTo;
}
$bookings = [];
if ($view === 'bookings') {
    $bookingQuery = $bookingSql . ($bookingWhere ? ' WHERE ' . implode(' AND ', $bookingWhere) : '') . " ORDER BY b.created_at DESC LIMIT 200";
    if ($bookingParams) {
        $stmt = $db->prepare($bookingQuery);
        $stmt->bind_param($bookingTypes, ...$bookingParams);
        $stmt->execute();
        $bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $bookings = $db->query($bookingQuery)->fetch_all(MYSQLI_ASSOC);
    }
}

$statusSql = "SELECT r.*, h.hotel_name, h.city AS hotel_city, h.address AS hotel_address, h.hero_image AS hotel_image,
        b.id_booking, b.booking_code, b.status AS booking_status, b.check_in_at, b.expected_check_out_at, b.check_in, b.check_out, b.pricing_mode,
        b.contact_name, b.contact_phone, b.customer_edit_count, b.customer_edited_at,
        g.id_guest, g.full_name, g.phone, g.email, g.identity_no, g.address AS guest_address, g.is_vip, g.loyalty_points
    FROM rooms r
    LEFT JOIN hotels h ON h.id_hotel=r.id_hotel
    LEFT JOIN bookings b ON b.id_booking = (
        SELECT b2.id_booking FROM bookings b2
        WHERE b2.id_room=r.id_room
        AND b2.status='checked_in'
        ORDER BY COALESCE(b2.check_in_at, CONCAT(b2.check_in, ' 14:00:00')) DESC
        LIMIT 1
    )
    LEFT JOIN guests g ON g.id_guest=b.id_guest";
$statusTypes = '';
$statusParams = [];
$statusWhere = ['r.is_active=1'];
if ($selectedHotelId !== 0) {
    $statusWhere[] = 'r.id_hotel=?';
    $statusTypes .= 'i';
    $statusParams[] = $selectedHotelId;
}
$statusSql .= ' WHERE ' . implode(' AND ', $statusWhere);
$statusSql .= ' ORDER BY h.hotel_name, r.floor_no, r.room_number';
$stmt = $db->prepare($statusSql);
if ($statusParams) {
    $stmt->bind_param($statusTypes, ...$statusParams);
}
$stmt->execute();
$roomStatusRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$activeRoomRows = array_values(array_filter($roomStatusRows, static function (array $row): bool {
    return !empty($row['id_booking']);
}));

$housekeepingSql = "SELECT r.*, h.hotel_name, h.city AS hotel_city, h.address AS hotel_address, h.hero_image AS hotel_image,
        b.id_booking, b.booking_code, b.checked_out_at, g.full_name, g.phone
    FROM rooms r
    LEFT JOIN hotels h ON h.id_hotel=r.id_hotel
    LEFT JOIN bookings b ON b.id_booking = (
        SELECT b2.id_booking FROM bookings b2
        WHERE b2.id_room=r.id_room
        AND b2.status='checked_out'
        ORDER BY COALESCE(b2.checked_out_at, b2.created_at) DESC
        LIMIT 1
    )
    LEFT JOIN guests g ON g.id_guest=b.id_guest
    WHERE r.is_active=1 AND r.status='cleaning'";
$housekeepingTypes = '';
$housekeepingParams = [];
if ($selectedHotelId !== 0) {
    $housekeepingSql .= ' AND r.id_hotel=?';
    $housekeepingTypes .= 'i';
    $housekeepingParams[] = $selectedHotelId;
}
$housekeepingSql .= " ORDER BY FIELD(COALESCE(r.housekeeping_status, 'pending'), 'pending', 'cleaning'), h.hotel_name, r.floor_no, r.room_number";
$stmt = $db->prepare($housekeepingSql);
if ($housekeepingParams) {
    $stmt->bind_param($housekeepingTypes, ...$housekeepingParams);
}
$stmt->execute();
$housekeepingRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$housekeepingPendingCount = count(array_filter($housekeepingRows, static function (array $room): bool {
    return ($room['housekeeping_status'] ?? 'pending') === 'pending';
}));
$housekeepingCleaningCount = count(array_filter($housekeepingRows, static function (array $room): bool {
    return ($room['housekeeping_status'] ?? 'pending') === 'cleaning';
}));

$scopedHotelSql = $selectedHotelId !== 0 ? ' AND id_hotel=' . (int)$selectedHotelId : '';
$scopedRoomJoin = $selectedHotelId !== 0 ? ' JOIN rooms r_scope ON r_scope.id_room=b.id_room AND r_scope.id_hotel=' . (int)$selectedHotelId : '';
$scopedPaymentJoin = $selectedHotelId !== 0 ? ' JOIN bookings b_scope ON b_scope.id_booking=p.id_booking JOIN rooms r_scope ON r_scope.id_room=b_scope.id_room AND r_scope.id_hotel=' . (int)$selectedHotelId : '';
$stats = [
    'hotels' => count($hotels),
    'rooms' => count($rooms),
    'available' => (int)$db->query("SELECT COUNT(*) AS total FROM rooms WHERE is_active=1 AND status='available' $scopedHotelSql")->fetch_assoc()['total'],
    'booked_rooms' => (int)$db->query("SELECT COUNT(DISTINCT b.id_room) AS total FROM bookings b $scopedRoomJoin WHERE b.status='booked' AND b.check_in <= '$today' AND b.check_out > '$today'")->fetch_assoc()['total'],
    'occupied' => (int)$db->query("SELECT COUNT(*) AS total FROM rooms WHERE is_active=1 AND status='occupied' $scopedHotelSql")->fetch_assoc()['total'],
    'cleaning' => (int)$db->query("SELECT COUNT(*) AS total FROM rooms WHERE is_active=1 AND status='cleaning' $scopedHotelSql")->fetch_assoc()['total'],
    'maintenance' => (int)$db->query("SELECT COUNT(*) AS total FROM rooms WHERE is_active=1 AND status='maintenance' $scopedHotelSql")->fetch_assoc()['total'],
    'today_checkin' => (int)$db->query("SELECT COUNT(*) AS total FROM bookings b $scopedRoomJoin WHERE b.check_in='$today' AND b.status IN ('booked','checked_in')")->fetch_assoc()['total'],
    'today_checkout' => (int)$db->query("SELECT COUNT(*) AS total FROM bookings b $scopedRoomJoin WHERE b.check_out='$today' AND b.status IN ('checked_in','checked_out')")->fetch_assoc()['total'],
    'active_bookings' => (int)$db->query("SELECT COUNT(*) AS total FROM bookings b $scopedRoomJoin WHERE b.status IN ('booked','checked_in')")->fetch_assoc()['total'],
    'active_now' => count($activeRoomRows),
    'new_bookings' => (int)$db->query("SELECT COUNT(*) AS total FROM bookings b $scopedRoomJoin WHERE DATE(b.created_at)='$today'")->fetch_assoc()['total'],
    'revenue_today' => (float)$db->query("SELECT COALESCE(SUM(p.amount),0) AS total FROM payments p $scopedPaymentJoin WHERE DATE(p.paid_at)='$today'")->fetch_assoc()['total'],
    'revenue_month' => (float)$db->query("SELECT COALESCE(SUM(p.amount),0) AS total FROM payments p $scopedPaymentJoin WHERE DATE_FORMAT(p.paid_at,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')")->fetch_assoc()['total'],
];
$occupancy = $stats['rooms'] > 0 ? round(($stats['occupied'] / $stats['rooms']) * 100) : 0;
$revenueRows = $db->query("SELECT DATE(p.paid_at) AS paid_date, COALESCE(SUM(p.amount),0) AS total FROM payments p $scopedPaymentJoin WHERE p.paid_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(p.paid_at) ORDER BY paid_date")->fetch_all(MYSQLI_ASSOC);
$revenueByDate = array_column($revenueRows, 'total', 'paid_date');
$revenueChart = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $revenueChart[] = ['label' => date('d/m', strtotime($date)), 'value' => (float)($revenueByDate[$date] ?? 0)];
}
$revenueValues = array_map(static function ($row) { return $row['value']; }, $revenueChart);
$maxRevenue = max(array_merge([1], $revenueValues));
$calendarHotelSql = $selectedHotelId !== 0 ? ' AND r.id_hotel=' . (int)$selectedHotelId : '';
$calendarBookings = $db->query("SELECT b.booking_code, b.check_in, b.check_out, b.status, g.full_name, r.room_number
    FROM bookings b
    JOIN guests g ON g.id_guest=b.id_guest
    JOIN rooms r ON r.id_room=b.id_room
    WHERE b.check_in BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 13 DAY)
    $calendarHotelSql
    ORDER BY b.check_in, r.room_number LIMIT 60")->fetch_all(MYSQLI_ASSOC);
$topGuests = $db->query("SELECT g.full_name, g.phone, COUNT(*) AS stays, COALESCE(SUM(p.amount),0) AS paid
    FROM guests g
    JOIN bookings b ON b.id_guest=g.id_guest
    JOIN rooms r ON r.id_room=b.id_room
    LEFT JOIN payments p ON p.id_booking=b.id_booking
    " . ($selectedHotelId !== 0 ? 'WHERE r.id_hotel=' . (int)$selectedHotelId : '') . "
    GROUP BY g.id_guest
    ORDER BY stays DESC, paid DESC LIMIT 8")->fetch_all(MYSQLI_ASSOC);
$topServices = $db->query("SELECT s.service_name, COALESCE(SUM(bs.quantity),0) AS qty, COALESCE(SUM(bs.quantity*bs.price),0) AS revenue
    FROM services s
    LEFT JOIN booking_services bs ON bs.id_service=s.id_service
    LEFT JOIN bookings b ON b.id_booking=bs.id_booking
    LEFT JOIN rooms r ON r.id_room=b.id_room
    " . ($selectedHotelId !== 0 ? 'WHERE r.id_hotel=' . (int)$selectedHotelId : '') . "
    GROUP BY s.id_service
    ORDER BY qty DESC, revenue DESC LIMIT 8")->fetch_all(MYSQLI_ASSOC);
$roomBookingWhere = 'WHERE r.is_active=1' . ($selectedHotelId !== 0 ? ' AND r.id_hotel=' . (int)$selectedHotelId : '');
$roomBookingDateJoin = '';
if ($dateFrom !== '') {
    $roomBookingDateJoin .= " AND b.check_in >= '" . $db->real_escape_string($dateFrom) . "'";
}
if ($dateTo !== '') {
    $roomBookingDateJoin .= " AND b.check_in <= '" . $db->real_escape_string($dateTo) . "'";
}

$roomBookingRows = $db->query("SELECT 
        r.id_room,
        r.room_number,
        r.room_type,
        h.hotel_name,
        h.city AS hotel_city,

        COALESCE(SUM(CASE 
            WHEN b.id_booking IS NOT NULL AND b.status <> 'cancelled' 
            THEN 1 ELSE 0 
        END),0) AS total_booking,

        COALESCE(SUM(CASE 
            WHEN b.status='booked' 
            THEN 1 ELSE 0 
        END),0) AS booked_count,

        COALESCE(SUM(CASE 
            WHEN b.status='checked_in' 
            THEN 1 ELSE 0 
        END),0) AS checked_in_count,

        COALESCE(SUM(CASE 
            WHEN b.status='checked_out' 
            THEN 1 ELSE 0 
        END),0) AS checked_out_count,

        COALESCE(SUM(CASE 
            WHEN b.status='cancelled' 
            THEN 1 ELSE 0 
        END),0) AS cancelled_count,

        COALESCE(SUM(CASE 
            WHEN b.id_booking IS NOT NULL AND b.status <> 'cancelled' 
            THEN pay.paid_total ELSE 0 
        END),0) AS total_revenue

    FROM rooms r
    LEFT JOIN hotels h ON h.id_hotel = r.id_hotel
    LEFT JOIN bookings b ON b.id_room = r.id_room $roomBookingDateJoin

    LEFT JOIN (
        SELECT 
            id_booking, 
            COALESCE(SUM(amount),0) AS paid_total
        FROM payments
        GROUP BY id_booking
    ) pay ON pay.id_booking = b.id_booking

    $roomBookingWhere

    GROUP BY 
        r.id_room, 
        r.room_number, 
        r.room_type, 
        h.hotel_name, 
        h.city

    ORDER BY 
        total_revenue DESC, 
        total_booking DESC, 
        h.hotel_name ASC, 
        r.room_number ASC")->fetch_all(MYSQLI_ASSOC);

$roomBookingValues = array_map(static function ($row) { 
    return (int)$row['total_booking']; 
}, $roomBookingRows);

$roomRevenueValues = array_map(static function ($row) { 
    return (float)$row['total_revenue']; 
}, $roomBookingRows);

$totalRoomBooking = array_sum($roomBookingValues);
$totalRoomRevenue = array_sum($roomRevenueValues);

$maxRoomBooking = max(array_merge([1], $roomBookingValues));
$maxRoomRevenue = max(array_merge([1], $roomRevenueValues));
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if (in_array($view, ['room_status', 'housekeeping'], true)): ?><meta http-equiv="refresh" content="30"><?php endif; ?>
    <title>Quản trị chuỗi khách sạn</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
    <script>
    window.SpotkiCsrfToken = <?php echo json_encode(csrfToken(), JSON_UNESCAPED_SLASHES); ?>;
    document.addEventListener('submit', function (event) {
        const form = event.target;
        if (!form || String(form.method || '').toLowerCase() !== 'post') return;
        if (!form.querySelector('input[name="csrf_token"]')) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'csrf_token';
            input.value = window.SpotkiCsrfToken || '';
            input.defaultValue = input.value;
            form.appendChild(input);
        }
    }, true);
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form[method="post"]').forEach(function (form) {
            if (form.querySelector('input[name="csrf_token"]')) return;
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'csrf_token';
            input.value = window.SpotkiCsrfToken || '';
            input.defaultValue = input.value;
            form.appendChild(input);
        });
    });
    </script>
    <style>
        :root { --red:#dc2626; --red-dark:#991b1b; --dark:#111827; --muted:#6b7280; --line:#e5e7eb; --bg:#f4f6f9; --green:#059669; --amber:#d97706; --blue:#2563eb; }
        body { margin:0; background:var(--bg); color:var(--dark); font-family:"Inter","Segoe UI",Arial,sans-serif; font-size:14px; line-height:1.5; -webkit-font-smoothing:antialiased; }
        .shell { min-height:100vh; display:grid; grid-template-columns:270px minmax(0,1fr); }
        .sidebar { background:#0f172a; color:#fff; padding:18px 14px; position:sticky; top:0; height:100vh; border-right:1px solid rgba(255,255,255,.08); }
        .brand { display:flex; align-items:center; gap:10px; font-size:18px; font-weight:800; margin-bottom:6px; letter-spacing:0; }
        .brand i,.stat i { width:38px; height:38px; display:inline-flex; align-items:center; justify-content:center; border-radius:8px; background:var(--red); color:#fff; }
        .brand-time { color:#e2e8f0; font-size:12px; font-weight:750; margin:0 0 3px 48px; }
        .admin-identity { margin:10px 0 12px 48px; padding:10px 0; border-top:1px solid rgba(255,255,255,.10); border-bottom:1px solid rgba(255,255,255,.10); }
        .admin-identity strong { display:block; color:#fff; font-size:13px; line-height:1.3; }
        .admin-identity span { display:block; color:#cbd5e1; font-size:12px; margin-top:3px; }
        .side-meta { color:#94a3b8; font-size:12px; margin:0 0 16px 48px; }
        .navlink { display:flex; align-items:center; gap:10px; min-height:40px; padding:9px 11px; border-radius:8px; color:#cbd5e1; text-decoration:none; font-weight:650; margin-bottom:4px; }
        .navlink i { width:18px; text-align:center; }
        .navlink:hover,.navlink.active { background:rgba(220,38,38,.18); color:#fff; }
        .main { padding:22px; min-width:0; }
        .top { display:grid; grid-template-columns:minmax(0,1fr) minmax(360px,620px); gap:16px; align-items:end; margin-bottom:18px; }
        .kicker { color:var(--red); font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.08em; }
        .panel { background:#fff; border:1px solid var(--line); border-radius:8px; box-shadow:0 10px 28px rgba(15,23,42,.06); }
        .panel-body { padding:16px; }
        .panel-head { display:flex; justify-content:space-between; align-items:end; gap:12px; margin-bottom:13px; }
        .panel-actions { display:flex; align-items:center; gap:10px; flex-wrap:wrap; justify-content:flex-end; }
        .admin-modal { position:fixed; inset:0; z-index:80; display:none; align-items:flex-start; justify-content:center; padding:24px; background:rgba(15,23,42,.62); overflow:auto; }
        .admin-modal.is-open { display:flex; }
        .modal-panel { width:min(760px,100%); margin:auto 0; background:#fff; border:1px solid var(--line); border-radius:8px; box-shadow:0 24px 70px rgba(15,23,42,.28); overflow:hidden; }
        .modal-panel.modal-wide { width:min(920px,100%); }
        .modal-panel.invoice-bill { width:min(780px,100%); }
        .modal-head { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; padding:16px 18px; border-bottom:1px solid var(--line); background:#fff; }
        .modal-body { padding:18px; max-height:calc(100vh - 150px); overflow:auto; }
        .modal-close { width:34px; height:34px; display:inline-flex; align-items:center; justify-content:center; padding:0; border-radius:8px; }
        body.admin-modal-open { overflow:hidden; }
        .stat-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-bottom:18px; }
        .stat { padding:15px; min-height:118px; }
        .stat i { background:#fee2e2; color:var(--red); margin-bottom:10px; }
        .stat strong { display:block; font-size:28px; line-height:1; letter-spacing:-.02em; }
        .stat span { color:var(--muted); }
        .layout { display:grid; grid-template-columns:minmax(0,1fr) 390px; gap:14px; align-items:start; }
        .wide-layout { display:grid; grid-template-columns:minmax(0,1fr) 440px; gap:14px; align-items:start; }
        .room-board { display:grid; grid-template-columns:repeat(auto-fill,minmax(162px,1fr)); gap:10px; }
        .room-tile { border:1px solid var(--line); border-radius:8px; padding:12px; background:#fff; min-height:118px; display:flex; flex-direction:column; justify-content:space-between; }
        .room-tile img { width:100%; aspect-ratio:4/3; object-fit:cover; border-radius:6px; margin-bottom:10px; background:#e5e7eb; }
        .room-tile strong { font-size:16px; }
        .room-thumb { width:86px; height:62px; object-fit:cover; border-radius:6px; background:#e5e7eb; margin-right:10px; }
        .room-cell { display:flex; align-items:center; min-width:180px; }
        .live-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:12px; }
        .live-room { border:1px solid var(--line); border-radius:8px; overflow:hidden; background:#fff; display:flex; flex-direction:column; min-height:100%; }
        .live-room img { width:100%; aspect-ratio:16/10; object-fit:cover; background:#e5e7eb; }
        .live-room-body { padding:13px; display:grid; gap:9px; }
        .live-meta { display:grid; gap:5px; color:var(--muted); font-size:13px; }
        .live-actions { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
        .live-actions form { display:grid; margin:0; }
        .live-actions .btn { width:100%; }
        .live-room.is-active { border-color:#fecaca; box-shadow:0 12px 28px rgba(220,38,38,.12); }
        .live-room.is-active .live-room-body { border-top:3px solid var(--red); }
        .refresh-note { display:flex; align-items:center; gap:8px; color:var(--muted); font-size:13px; }
        .room-group { border:1px solid var(--line); border-radius:8px; overflow:hidden; margin-top:14px; background:#fff; }
        .room-group[open] { box-shadow:0 14px 36px rgba(15,23,42,.09); }
        .room-group-head { display:grid; grid-template-columns:150px minmax(0,1fr) auto; align-items:center; gap:14px; padding:12px; background:#fff; cursor:pointer; list-style:none; }
        .room-group-head::-webkit-details-marker { display:none; }
        .room-group-head img { width:150px; height:96px; object-fit:cover; border-radius:6px; background:#e5e7eb; }
        .room-group-head:after { content:'\f078'; font-family:'Font Awesome 6 Free'; font-weight:900; color:var(--muted); }
        .room-group[open] .room-group-head { border-bottom:1px solid var(--line); background:#f8fafc; }
        .room-group[open] .room-group-head:after { content:'\f077'; }
        .room-filter-bar { display:flex; justify-content:space-between; align-items:end; gap:12px; flex-wrap:wrap; margin-bottom:14px; }
        .mode-tabs { display:flex; flex-wrap:wrap; gap:8px; }
        .mode-tab { display:inline-flex; align-items:center; gap:7px; min-height:36px; padding:7px 11px; border:1px solid var(--line); border-radius:8px; color:var(--dark); text-decoration:none; font-weight:750; background:#fff; }
        .mode-tab.active,.mode-tab:hover { border-color:var(--red); color:var(--red); background:#fff5f5; }
        .status-pill { display:inline-flex; min-height:24px; align-items:center; padding:3px 8px; border-radius:999px; font-size:12px; font-weight:800; white-space:nowrap; }
        .status-available,.status-booked { background:#dcfce7; color:#166534; }
        .status-occupied,.status-checked_in { background:#fee2e2; color:#991b1b; }
        .status-cleaning { background:#fef3c7; color:#92400e; }
        .status-maintenance,.status-cancelled { background:#e5e7eb; color:#374151; }
        .status-checked_out { background:#dbeafe; color:#1d4ed8; }
        .btn-hotel { background:var(--red); border-color:var(--red); color:#fff; font-weight:750; }
        .btn-hotel:hover { background:var(--red-dark); border-color:var(--red-dark); color:#fff; }
        .form-control,.form-select { min-height:38px; border-color:var(--line); font-size:14px; }
        .table { margin-bottom:0; }
        .table td,.table th { vertical-align:middle; }
        .table th { color:#475569; font-size:12px; text-transform:uppercase; letter-spacing:.04em; white-space:nowrap; }
        .invoice-line { display:flex; justify-content:space-between; gap:12px; padding:7px 0; border-bottom:1px dashed var(--line); }
        .invoice-line:last-child { border-bottom:0; }
        .invoice-sheet { display:grid; gap:16px; color:#0f172a; background:#fff; }
        .invoice-title { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:18px; align-items:start; padding:18px; border:1px solid #dbe3ee; border-radius:8px; background:linear-gradient(180deg,#fff,#f8fafc); }
        .invoice-brand { display:flex; gap:12px; align-items:flex-start; }
        .invoice-logo { width:48px; height:48px; flex:0 0 48px; border-radius:8px; display:inline-flex; align-items:center; justify-content:center; background:#991b1b; color:#fff; font-size:22px; box-shadow:inset 0 -8px 18px rgba(0,0,0,.12); }
        .invoice-title h2 { margin:0; font-size:24px; font-weight:850; letter-spacing:0; }
        .invoice-code { min-width:190px; text-align:right; font-size:13px; color:var(--muted); }
        .invoice-code strong { display:block; color:#0f172a; font-size:18px; }
        .invoice-info-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .invoice-info-grid.invoice-stack { grid-template-columns:repeat(2,minmax(0,1fr)); }
        .invoice-box { border:1px solid #dbe3ee; border-radius:8px; padding:12px; background:#fff; }
        .invoice-box-title { font-size:12px; font-weight:800; text-transform:uppercase; color:#64748b; margin-bottom:8px; }
        .invoice-row { display:flex; justify-content:space-between; gap:12px; padding:4px 0; font-size:14px; }
        .invoice-row span { color:#64748b; }
        .invoice-row strong { text-align:right; }
        .invoice-table { width:100%; border-collapse:separate; border-spacing:0; border:1px solid #dbe3ee; border-radius:8px; overflow:hidden; }
        .invoice-table th { background:#f1f5f9; color:#475569; font-size:12px; text-transform:uppercase; padding:11px 12px; border-bottom:1px solid #dbe3ee; }
        .invoice-table td { padding:11px 12px; border-bottom:1px solid #edf2f7; vertical-align:top; }
        .invoice-table tr:last-child td { border-bottom:0; }
        .invoice-table .money-cell { text-align:right; font-weight:800; white-space:nowrap; }
        .invoice-note { color:#64748b; font-size:12px; margin-top:2px; }
        .invoice-summary { display:grid; grid-template-columns:minmax(0,1fr) 320px; gap:14px; align-items:start; }
        .invoice-summary .invoice-total-box { grid-column:2; }
        .invoice-total-box { border:1px solid #dbe3ee; border-radius:8px; padding:12px 14px; background:#fff; }
        .invoice-total-row { display:flex; justify-content:space-between; gap:12px; padding:7px 0; border-bottom:1px dashed var(--line); }
        .invoice-total-row strong { text-align:right; white-space:nowrap; }
        .invoice-total-row.payment-method { font-size:13px; color:#475569; }
        .invoice-total-row:last-child { border-bottom:0; }
        .invoice-total-row.grand { font-size:18px; font-weight:800; color:#991b1b; }
        .invoice-total-row.debt { color:#dc2626; font-weight:800; }
        .payment-entry-grid { display:grid; grid-template-columns:1fr 1fr 170px; gap:8px; align-items:end; }
        .payment-panel { border:1px solid #dbe3ee; border-radius:8px; padding:12px; background:#f8fafc; }
        .payment-stats { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:8px; margin-bottom:10px; }
        .payment-stat { border:1px solid #dbe3ee; border-radius:8px; padding:9px 10px; background:#fff; }
        .payment-stat span { display:block; color:#64748b; font-size:12px; font-weight:750; }
        .payment-stat strong { display:block; margin-top:2px; font-size:15px; }
        .point-badge { display:inline-flex; align-items:center; gap:6px; padding:5px 9px; border-radius:999px; background:#fff7ed; color:#9a3412; font-weight:800; white-space:nowrap; }
        .point-badge i { color:#f59e0b; }
        .point-note { display:block; color:#64748b; font-size:12px; margin-top:3px; }
        .hotel-card { display:grid; grid-template-columns:110px minmax(0,1fr); gap:12px; padding:12px; border:1px solid var(--line); border-radius:8px; margin-bottom:10px; }
        .hotel-card img { width:110px; height:82px; object-fit:cover; border-radius:6px; background:#e5e7eb; }
        .operation-list { display:grid; gap:10px; }
        .operation-item { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:12px; border:1px solid var(--line); border-radius:8px; }
        .chart { display:flex; align-items:end; gap:10px; min-height:220px; padding:14px; border:1px solid var(--line); border-radius:8px; background:linear-gradient(180deg,#fff,#f8fafc); }
        .bar { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:end; gap:7px; min-width:42px; }
        .bar-fill { width:100%; max-width:54px; min-height:8px; border-radius:7px 7px 3px 3px; background:linear-gradient(180deg,var(--red),#f97316); }
        .bar-label { color:var(--muted); font-size:12px; }
        .calendar-grid { display:grid; grid-template-columns:repeat(7,minmax(0,1fr)); gap:8px; }
        .calendar-day { min-height:92px; padding:9px; border:1px solid var(--line); border-radius:8px; background:#fff; }
        .calendar-day strong { display:block; margin-bottom:6px; }
        .booking-dot { display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; border-radius:999px; padding:3px 7px; margin-top:4px; font-size:11px; font-weight:700; }
        .dot-booked { background:#fee2e2; color:#991b1b; }
        .dot-checked_in { background:#fef3c7; color:#92400e; }
        .dot-checked_out { background:#dbeafe; color:#1d4ed8; }
        .notice { display:flex; gap:10px; align-items:flex-start; padding:12px; border:1px solid var(--line); border-radius:8px; background:#fff; }
        .notice i { color:var(--red); margin-top:2px; }
        .module-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; }
        .module-card { padding:14px; border:1px solid var(--line); border-radius:8px; background:#fff; }
        .module-card i { width:34px; height:34px; display:inline-flex; align-items:center; justify-content:center; border-radius:8px; background:#fee2e2; color:var(--red); margin-bottom:10px; }
        .cannot-manage-hotels [data-open-modal="hotelModal"], .cannot-manage-hotels #hotelModal,
        .cannot-manage-rooms [data-open-modal="roomModal"], .cannot-manage-rooms #roomModal,
        .cannot-manage-guests [data-open-modal="guestModal"], .cannot-manage-guests #guestModal { display:none !important; }
        @media (max-width:1120px) {
            .shell { display:block; }
            .sidebar { position:static; height:auto; }
            .side-nav { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:6px; }
            .navlink { margin:0; }
            .top,.stat-grid,.layout,.wide-layout { grid-template-columns:1fr; }
        }
        @media (max-width:700px) {
            .main { padding:14px; }
            .side-nav { grid-template-columns:1fr 1fr; }
            .top form { flex-direction:column; }
            .invoice-info-grid,.invoice-summary { grid-template-columns:1fr; }
            .invoice-title { grid-template-columns:1fr; }
            .invoice-info-grid.invoice-stack { grid-template-columns:1fr; }
            .invoice-code { text-align:left; min-width:0; }
            .invoice-summary .invoice-total-box { grid-column:1; }
            .payment-entry-grid { grid-template-columns:1fr; }
            .payment-stats { grid-template-columns:1fr; }
            .hotel-card { grid-template-columns:1fr; }
            .hotel-card img { width:100%; height:150px; }
            .room-group-head { grid-template-columns:1fr; }
            .room-group-head img { width:100%; height:150px; }
            .admin-modal { padding:12px; }
            .modal-body { max-height:calc(100vh - 120px); }
        }
        @media print {
            body * { visibility:hidden; }
            #printInvoice,#printInvoice * { visibility:visible; }
            #printInvoice { position:absolute; inset:0; box-shadow:none; border:0; }
            .no-print { display:none !important; }
        }
    </style>
</head>
<body class="<?php echo trim((!$canManageHotels ? 'cannot-manage-hotels ' : '') . (!$canManageRooms ? 'cannot-manage-rooms ' : '') . (!$canManageGuests ? 'cannot-manage-guests' : '')); ?>">
<div class="shell">
    <aside class="sidebar">
        <div class="brand"><i class="fa fa-hotel"></i> Spotki Admin</div>
        <div class="brand-time" id="adminClock"><?php echo date('H:i:s d/m/Y'); ?></div>
        <div class="admin-identity">
            <strong><?php echo h($currentAdminName); ?></strong>
            <span><?php echo h(adminRoleLabel($currentAdminRole)); ?></span>
        </div>
        <div class="side-meta">Cơ sở: <?php echo h($currentBranchName); ?></div>
        <nav class="side-nav">
            <?php foreach ([
                'dashboard' => ['fa-gauge-high', 'Tổng quan'],
                'hotels' => ['fa-building', 'Cơ sở'],
                'rooms' => ['fa-bed', 'Phòng'],
                'bookings' => ['fa-calendar-check', 'Phòng đặt trước'],
                'room_status' => ['fa-door-open', 'Phòng đang sử dụng'],
                'housekeeping' => ['fa-broom', 'Dọn phòng'],
                'guests' => ['fa-users', 'Khách hàng'],
                'staff' => ['fa-user-tie', 'Nhân viên'],
                'services' => ['fa-mug-hot', 'Dịch vụ'],
                'invoices' => ['fa-file-invoice-dollar', 'Hóa đơn'],
                'reports' => ['fa-chart-line', 'Báo cáo'],
            ] as $key => $item): ?><?php if (!in_array($key, $allowedViews, true)) { continue; } ?>                <a class="navlink <?php echo $view === $key ? 'active' : ''; ?>" href="admin.php?view=<?php echo h($key); ?>"><i class="fa <?php echo h($item[0]); ?>"></i> <?php echo h($item[1]); ?></a>
            <?php endforeach; ?>
            <a class="navlink" href="blockchain.php"><i class="fa fa-globe"></i> BlockChain</a>
            <a class="navlink" href="index.php"><i class="fa fa-globe"></i> Website</a>
            <a class="navlink" href="logout.php"><i class="fa fa-right-from-bracket"></i> Đăng xuất</a>
        </nav>
    </aside>

    <main class="main">
        <div class="top">
            <div>
                <div class="kicker">Hotel Operations</div>
                <h1 class="h3 fw-bold mb-1">
                    <?php echo [
                        'dashboard' => 'Tổng quan vận hành',
                        'hotels' => 'Quản lý cơ sở khách sạn',
                        'rooms' => 'Quản lý phòng và tình trạng phòng',
                        'room_status' => 'Quản lý phòng đang sử dụng',
                        'housekeeping' => 'Quản lý dọn phòng',
                        'bookings' => 'Quản lý đặt phòng',
                        'guests' => 'Hồ sơ khách hàng',
                        'staff' => 'Quản lý nhân viên và phân quyền',
                        'services' => 'Đồ ăn thức uống trong phòng',
                        'invoices' => 'Hóa đơn',
                        'reports' => 'Báo cáo và thống kê',
                        'settings' => 'Phân quyền và bảo mật',
                    ][$view] ?? 'Quản trị khách sạn'; ?>
                </h1>
            </div>
            <?php
            $searchPlaceholders = [
                'rooms' => 'Tìm số phòng, loại phòng, giường, cơ sở',
                'bookings' => 'Tìm mã đặt phòng, tên, SĐT, CCCD, phòng',
                'guests' => 'Tìm tên khách, SĐT, email, CCCD',
                'staff' => 'Tìm nhân viên, tài khoản, email, vai trò',
                'invoices' => 'Tìm mã hóa đơn hoặc mã booking',
            ];
            $searchPlaceholder = $searchPlaceholders[$view] ?? 'Tìm nhanh trong trang hiện tại';
            $showTopSearch = !in_array($view, ['hotels', 'rooms', 'housekeeping', 'reports', 'services'], true);
            ?>
            <?php if ($showTopSearch): ?>
            <form class="d-flex gap-2" method="get">
                <input type="hidden" name="view" value="<?php echo h($view); ?>">
                <?php if ($view === 'rooms'): ?><input type="hidden" name="room_mode" value="<?php echo h($roomMode); ?>"><?php endif; ?>
                <select class="form-select" name="hotel" onchange="this.form.submit()">
                    <option value="0">Tất cả khách sạn</option>
                    <?php foreach ($hotels as $hotel): ?>
                        <option value="<?php echo (int)$hotel['id_hotel']; ?>" <?php echo (int)$hotel['id_hotel'] === $selectedHotelId ? 'selected' : ''; ?>><?php echo h($hotel['hotel_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <input class="form-control" name="q" value="<?php echo h($search); ?>" placeholder="<?php echo h($searchPlaceholder); ?>">
                <?php if ($view === 'invoices'): ?>
                    <input type="hidden" name="invoice_range" value="<?php echo h($invoiceRange); ?>">
                    <?php if ($invoiceRange === 'custom'): ?>
                        <input type="hidden" name="date_from" value="<?php echo h($dateFrom); ?>">
                        <input type="hidden" name="date_to" value="<?php echo h($dateTo); ?>">
                    <?php endif; ?>
                <?php endif; ?>
                <button class="btn btn-outline-dark"><i class="fa fa-search"></i></button>
            </form>
            <?php endif; ?>
        </div>

        <?php if (!empty($_GET['created'])): ?><div class="alert alert-success">Đã tạo đặt phòng <?php echo h($_GET['created']); ?>.</div><?php endif; ?>
        <?php if (!empty($_GET['deleted'])): ?><div class="alert alert-success">Đã hủy và xóa dữ liệu booking.</div><?php endif; ?>
        <?php if ($message !== ''): ?><div class="alert <?php echo $isError ? 'alert-danger' : 'alert-success'; ?>"><?php echo h($message); ?></div><?php endif; ?>
        <?php if ($missingHotelScope): ?><div class="alert alert-danger">Tài khoản này chưa được gán cơ sở. Admin cần vào Nhân viên, chọn đúng cơ sở phân quyền rồi lưu lại tài khoản.</div><?php endif; ?>

        <?php if ($view === 'dashboard'): ?>
            <section class="stat-grid">
                <div class="panel stat"><i class="fa fa-building"></i><strong><?php echo $stats['hotels']; ?></strong><span>Cơ sở đang vận hành</span></div>
                <div class="panel stat"><i class="fa fa-bed"></i><strong><?php echo $stats['rooms']; ?></strong><span>Tổng phòng trong bộ lọc</span></div>
                <div class="panel stat"><i class="fa fa-calendar-check"></i><strong><?php echo $stats['booked_rooms']; ?></strong><span>Phòng đã đặt hôm nay</span></div>
                <div class="panel stat"><i class="fa fa-percent"></i><strong><?php echo $occupancy; ?>%</strong><span>Công suất đang ở</span></div>
                <div class="panel stat"><i class="fa fa-money-bill-wave"></i><strong><?php echo money($stats['revenue_today']); ?></strong><span>Thanh toán hôm nay</span></div>
                <div class="panel stat"><i class="fa fa-chart-line"></i><strong><?php echo money($stats['revenue_month']); ?></strong><span>Doanh thu tháng này</span></div>
                <div class="panel stat"><i class="fa fa-door-open"></i><strong><?php echo $stats['available']; ?></strong><span>Phòng trống</span></div>
                <div class="panel stat"><i class="fa fa-key"></i><strong><?php echo $stats['occupied']; ?></strong><span>Đang có khách</span></div>
                <div class="panel stat"><i class="fa fa-bell"></i><strong><?php echo $stats['new_bookings']; ?></strong><span>Booking mới hôm nay</span></div>
                <div class="panel stat"><i class="fa fa-broom"></i><strong><?php echo $stats['cleaning']; ?></strong><span>Cần dọn phòng</span></div>
                <div class="panel stat"><i class="fa fa-screwdriver-wrench"></i><strong><?php echo $stats['maintenance']; ?></strong><span>Bảo trì</span></div>
            </section>
            <section class="layout mb-3">
                <div class="panel panel-body">
                    <div class="panel-head">
                        <div><div class="kicker">Revenue Chart</div><h2 class="h5 fw-bold mb-0">Doanh thu 7 ngày gần nhất</h2></div>
                        <a class="btn btn-sm btn-outline-dark" href="?view=reports">Xem báo cáo</a>
                    </div>
                    <div class="chart">
                        <?php foreach ($revenueChart as $point): ?>
                            <div class="bar">
                                <div class="small fw-bold"><?php echo money($point['value']); ?></div>
                                <div class="bar-fill" style="height:<?php echo max(8, round(($point['value'] / $maxRevenue) * 170)); ?>px"></div>
                                <div class="bar-label"><?php echo h($point['label']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="panel panel-body">
                    <div class="panel-head"><div><div class="kicker">Notifications</div><h2 class="h5 fw-bold mb-0">Thông báo nhanh</h2></div></div>
                    <div class="operation-list">
                        <div class="notice"><i class="fa fa-circle-info"></i><div><strong><?php echo $stats['today_checkin']; ?> booking nhận phòng hôm nay</strong><div class="text-muted small">Ưu tiên xác nhận giấy tờ và phòng sạch.</div></div></div>
                        <div class="notice"><i class="fa fa-broom"></i><div><strong><?php echo $stats['cleaning']; ?> phòng đang dọn</strong><div class="text-muted small">Cập nhật lại trạng thái khi phòng sẵn sàng bán.</div></div></div>
                        <div class="notice"><i class="fa fa-screwdriver-wrench"></i><div><strong><?php echo $stats['maintenance']; ?> phòng bảo trì</strong><div class="text-muted small">Không phân phòng cho booking mới.</div></div></div>
                    </div>
                </div>
            </section>

        <?php elseif ($view === 'hotels'): ?>
            <section>
                <div class="panel panel-body">
                    <div class="panel-head">
                        <div><div class="kicker">Properties</div><h2 class="h5 fw-bold mb-0">Danh sách cơ sở</h2></div>
                        <button class="btn btn-hotel btn-sm" type="button" data-open-modal="hotelModal"><i class="fa fa-plus"></i> Thêm cơ sở</button>
                    </div>
                    <?php foreach ($hotels as $hotel): ?>
                        <div class="hotel-card">
                            <img src="<?php echo h($hotel['hero_image']); ?>" alt="<?php echo h($hotel['hotel_name']); ?>">
                            <div>
                                <div class="d-flex justify-content-between gap-2">
                                    <h3 class="h6 fw-bold mb-1"><?php echo h($hotel['hotel_name']); ?></h3>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="text-warning small"><?php echo str_repeat('★', (int)$hotel['star_rating']); ?></span>
                                        <button class="btn btn-sm btn-outline-dark" type="button" data-open-modal="hotelModal" data-modal-title="Chỉnh sửa cơ sở" data-id_hotel="<?php echo (int)$hotel['id_hotel']; ?>" data-hotel_name="<?php echo h($hotel['hotel_name']); ?>" data-brand_name="<?php echo h($hotel['brand_name']); ?>" data-star_rating="<?php echo (int)$hotel['star_rating']; ?>" data-city="<?php echo h($hotel['city']); ?>" data-address="<?php echo h($hotel['address']); ?>" data-phone="<?php echo h($hotel['phone']); ?>" data-email="<?php echo h($hotel['email']); ?>" data-hero_image="<?php echo h($hotel['hero_image']); ?>" data-description="<?php echo h($hotel['description']); ?>">Sửa</button>
                                    </div>
                                </div>
                                <div class="text-muted small mb-1"><?php echo h($hotel['address'] . ', ' . $hotel['city']); ?></div>
                                <div class="small"><?php echo h($hotel['phone']); ?> · <?php echo h($hotel['email']); ?></div>
                                <div class="text-muted small mt-1"><?php echo h($hotel['description']); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="admin-modal" id="hotelModal" aria-hidden="true">
                <form class="modal-panel" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_hotel">
                    <input type="hidden" name="id_hotel" value="">
                    <div class="modal-head">
                        <div><div class="kicker">Property setup</div><h2 class="h5 fw-bold mb-0" data-modal-heading>Thêm cơ sở khách sạn</h2></div>
                        <button class="btn btn-light modal-close" type="button" data-close-modal aria-label="Đóng"><i class="fa fa-xmark"></i></button>
                    </div>
                    <div class="modal-body">
                    <label class="form-label fw-bold">Tên khách sạn</label><input class="form-control mb-2" name="hotel_name" required>
                    <label class="form-label fw-bold">Thương hiệu</label><input class="form-control mb-2" name="brand_name" value="Spotki Hotels">
                    <div class="row g-2"><div class="col-5"><label class="form-label fw-bold">Sao</label><input class="form-control" type="number" name="star_rating" value="5" min="1" max="5"></div><div class="col-7"><label class="form-label fw-bold">Thành phố</label><input class="form-control" name="city" required></div></div>
                    <label class="form-label fw-bold mt-2">Địa chỉ</label><input class="form-control mb-2" name="address" required>
                    <div class="row g-2"><div class="col-6"><label class="form-label fw-bold">Điện thoại</label><input class="form-control" name="phone"></div><div class="col-6"><label class="form-label fw-bold">Email</label><input class="form-control" type="email" name="email"></div></div>
                    <label class="form-label fw-bold mt-2">Ảnh nền</label><input class="form-control mb-2" name="hero_image" placeholder="https://...">
                    <label class="form-label fw-bold">Mô tả nội bộ</label><textarea class="form-control mb-3" name="description" rows="3"></textarea>
                    <div class="modal-upload-shortcut mt-2">
                        <label class="form-label fw-bold">Tải ảnh nền từ máy</label>
                        <input class="form-control mb-2" type="file" name="hero_image_file" accept="image/*">
                    </div>
                    <button class="btn btn-hotel w-100">Lưu cơ sở</button>
                    </div>
                </form>
                </div>
            </section>

        <?php elseif ($view === 'rooms'): ?>
            <section>
                <div class="panel panel-body">
                    <div class="panel-head">
                        <div><div class="kicker">Inventory</div><h2 class="h5 fw-bold mb-0"><?php echo $roomMode === 'walkin' ? 'Phòng sẵn sàng cho khách vãng lai' : 'Phòng theo cơ sở'; ?></h2></div>
                        <div class="panel-actions">
                            <div class="text-muted small"><?php echo count($rooms); ?> phòng</div>
                            <button class="btn btn-hotel btn-sm" type="button" data-open-modal="roomModal"><i class="fa fa-plus"></i> Thêm phòng mới</button>
                        </div>
                    </div>
                    <div class="room-filter-bar">
                        <div class="mode-tabs">
                            <a class="mode-tab <?php echo $roomMode === 'hotel' ? 'active' : ''; ?>" href="?view=rooms&room_mode=hotel&hotel=<?php echo (int)$selectedHotelId; ?>"><i class="fa fa-building"></i> Theo cơ sở</a>
                            <a class="mode-tab <?php echo $roomMode === 'walkin' ? 'active' : ''; ?>" href="?view=rooms&room_mode=walkin&hotel=<?php echo (int)$selectedHotelId; ?>"><i class="fa fa-person-walking-luggage"></i> Khách vãng lai</a>
                        </div>
                        <form class="d-flex gap-2" method="get">
                            <input type="hidden" name="view" value="rooms">
                            <input type="hidden" name="room_mode" value="<?php echo h($roomMode); ?>">
                            <select class="form-select" name="hotel" onchange="this.form.submit()">
                                <option value="0">Tất cả cơ sở</option>
                                <?php foreach ($hotels as $hotel): ?><option value="<?php echo (int)$hotel['id_hotel']; ?>" <?php echo (int)$hotel['id_hotel'] === $selectedHotelId ? 'selected' : ''; ?>><?php echo h($hotel['hotel_name']); ?></option><?php endforeach; ?>
                            </select>
                        </form>
                    </div>

                    <?php foreach ($roomsByHotel as $group): ?>
                        <details class="room-group">
                            <summary class="room-group-head">
                                <img src="<?php echo h($group['hotel_image'] ?: defaultHotelHeroImage()); ?>" alt="<?php echo h($group['hotel_name']); ?>">
                                <div>
                                    <strong><?php echo h($group['hotel_name']); ?></strong>
                                    <div class="text-muted small"><?php echo h($group['hotel_address']); ?></div>
                                    <div class="mt-2 d-flex flex-wrap gap-2">
                                        <span class="status-pill status-available"><?php echo (int)$group['available_count']; ?> phòng trống</span>                                    </div>
                                </div>
                            </summary>
                            <?php if ($roomMode === 'walkin'): ?>
                                <div class="room-board p-3">
                                    <?php foreach ($group['rooms'] as $room): ?>
                                        <div class="room-tile">
                                            <img src="<?php echo h(roomDisplayImage($room)); ?>" alt="<?php echo h($room['room_type']); ?>">
                                            <div><strong><?php echo h($room['room_number']); ?></strong><div class="text-muted small"><?php echo h($room['room_type']); ?> · Tầng <?php echo (int)$room['floor_no']; ?></div><div class="small"><?php echo (int)$room['capacity']; ?> khách · <?php echo h($room['bed_type']); ?></div></div>
                                            <div class="d-grid gap-2">
                                                <span class="fw-bold"><?php echo money($room['hourly_rate']); ?>/giờ</span>
                                                <button class="btn btn-hotel btn-sm" type="button" data-open-modal="walkinModal" data-id_room="<?php echo (int)$room['id_room']; ?>" data-capacity="<?php echo (int)$room['capacity']; ?>" data-extra_guest_rate_percent="<?php echo h((string)($room['extra_guest_rate_percent'] ?? 25)); ?>" data-booked_ranges="<?php echo h(json_encode($walkinBookedRangesByRoom[(int)$room['id_room']] ?? [], JSON_UNESCAPED_SLASHES)); ?>" data-selected_room="<?php echo h($room['hotel_name'] . ' - Phòng ' . $room['room_number'] . ' - ' . $room['room_type']); ?>"></i> Nhận khách ngay</button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive"><table class="table table-hover">
                                    <thead><tr><th>Phòng</th><th>Loại</th><th>Sức chứa</th><th>Giá</th><th>Trạng thái</th><th></th></tr></thead>
                                    <tbody><?php foreach ($group['rooms'] as $room): ?><tr>
                                        <td class="fw-bold"><div class="room-cell"><img class="room-thumb" src="<?php echo h(roomDisplayImage($room)); ?>" alt="<?php echo h($room['room_type']); ?>"><div><?php echo h($room['room_number']); ?><div class="text-muted small">Tầng <?php echo (int)$room['floor_no']; ?></div><?php if (trim((string)($room['image_url'] ?? '')) === ''): ?><div class="text-danger small">Chưa có ảnh thật</div><?php endif; ?></div></div></td>
                                        <td><?php echo h($room['room_type']); ?><div class="text-muted small"><?php echo h($room['room_size'] . ' · ' . $room['bed_type']); ?></div></td>
                                        <td><?php echo (int)$room['capacity']; ?> khách</td>
                                        <td><strong><?php echo money($room['price_per_night']); ?></strong><div class="text-muted small"><?php echo money($room['hourly_rate']); ?>/giờ</div><div class="text-danger small fw-bold">Phụ thu: <?php echo rtrim(rtrim(number_format((float)($room['extra_guest_rate_percent'] ?? 25), 2, ',', '.'), '0'), ','); ?>%</div></td>
                                        <td><span class="status-pill status-<?php echo h($room['status']); ?>"><?php echo h(adminRoomStatusLabel($room['status'])); ?></span></td>
                                        <td><button class="btn btn-outline-dark btn-sm" type="button" data-open-modal="roomModal" data-modal-title="Chỉnh sửa phòng" data-id_room="<?php echo (int)$room['id_room']; ?>" data-id_hotel="<?php echo (int)$room['id_hotel']; ?>" data-room_number="<?php echo h($room['room_number']); ?>" data-room_type="<?php echo h($room['room_type']); ?>" data-floor_no="<?php echo (int)$room['floor_no']; ?>" data-capacity="<?php echo (int)$room['capacity']; ?>" data-room_size="<?php echo h($room['room_size']); ?>" data-bed_type="<?php echo h($room['bed_type']); ?>" data-price_per_night="<?php echo h((string)$room['price_per_night']); ?>" data-hourly_rate="<?php echo h((string)$room['hourly_rate']); ?>" data-extra_guest_rate_percent="<?php echo h((string)($room['extra_guest_rate_percent'] ?? 25)); ?>" data-image_url="<?php echo h($room['image_url']); ?>" data-gallery_urls="<?php echo h($room['gallery_urls']); ?>" data-amenities="<?php echo h($room['amenities']); ?>" data-package_name="<?php echo h($room['package_name']); ?>" data-package_services="<?php echo h($room['package_services']); ?>" data-status="<?php echo h($room['status']); ?>" data-note="<?php echo h($room['note']); ?>">Sửa</button></td>
                                    </tr><?php endforeach; ?></tbody>
                                </table></div>
                            <?php endif; ?>
                        </details>
                    <?php endforeach; ?>
                    <?php if (!$roomsByHotel): ?><div class="empty-panel text-center">Không có phòng phù hợp bộ lọc hiện tại.</div><?php endif; ?>
                </div>
                <div class="admin-modal" id="roomModal" aria-hidden="true">
                <form class="modal-panel" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_room">
                    <input type="hidden" name="id_room" value="">
                    <div class="modal-head">
                        <div><div class="kicker">Room setup</div><h2 class="h5 fw-bold mb-0" data-modal-heading>Thêm phòng mới</h2></div>
                        <button class="btn btn-light modal-close" type="button" data-close-modal aria-label="Đóng"><i class="fa fa-xmark"></i></button>
                    </div>
                    <div class="modal-body">
                    <label class="form-label fw-bold">Khách sạn</label><select class="form-select mb-2" name="id_hotel" required><option value="">Chọn khách sạn</option><?php foreach ($hotels as $hotel): ?><option value="<?php echo (int)$hotel['id_hotel']; ?>"><?php echo h($hotel['hotel_name'] . ' - ' . $hotel['city']); ?></option><?php endforeach; ?></select>
                    <div class="row g-2"><div class="col-5"><label class="form-label fw-bold">Số phòng</label><input class="form-control" name="room_number" required></div><div class="col-7"><label class="form-label fw-bold">Loại phòng</label><input class="form-control" name="room_type" required></div></div>
                    <div class="row g-2 mt-1"><div class="col-4"><label class="form-label fw-bold">Tầng</label><input class="form-control" type="number" name="floor_no" value="1" min="1"></div><div class="col-4"><label class="form-label fw-bold">Khách</label><select class="form-select" name="capacity"><?php echo adminCapacityOptions(2); ?></select></div><div class="col-4"><label class="form-label fw-bold">Diện tích</label><input class="form-control" name="room_size" placeholder="48 m2"></div></div>
                    <label class="form-label fw-bold mt-2">Giường</label><input class="form-control mb-2" name="bed_type" placeholder="1 King hoặc 2 Twin">
                    <div class="row g-2"><div class="col-6"><label class="form-label fw-bold">Giá/đêm</label><input class="form-control" type="number" name="price_per_night" min="0" required></div><div class="col-6"><label class="form-label fw-bold">Giá/giờ</label><input class="form-control" type="number" name="hourly_rate" min="0" required></div></div>
                    <label class="form-label fw-bold mt-2">% phụ thu thêm người</label><div class="input-group mb-2"><input class="form-control" type="number" name="extra_guest_rate_percent" value="25" min="0" max="100" step="0.01" data-default-value="25"><span class="input-group-text">%</span></div>
                    <input type="hidden" name="image_url" value="">
                    <input type="hidden" name="gallery_urls" value="">
                    <label class="form-label fw-bold mt-2">Ảnh phòng</label><input class="form-control mb-2" type="file" name="room_image_file" accept="image/*">
                    <label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="delete_room_image" value="1"> <span class="form-check-label">Xóa ảnh phòng hiện tại</span></label>
                    <label class="form-label fw-bold">Thêm nhiều ảnh phòng</label><input class="form-control mb-2" type="file" name="gallery_image_files[]" accept="image/*" multiple>
                    <label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="delete_room_gallery" value="1"> <span class="form-check-label">Xóa toàn bộ ảnh phụ hiện tại trước khi thêm ảnh mới</span></label>
                    <label class="form-label fw-bold">Tiện nghi</label><textarea class="form-control mb-2" name="amenities" rows="2" placeholder="Wi-Fi, minibar, bồn tắm..."></textarea>
                    <label class="form-label fw-bold">Gói phòng</label><input class="form-control mb-2" name="package_name" placeholder="Deluxe Retreat">
                    <label class="form-label fw-bold">Dịch vụ trong gói</label><textarea class="form-control mb-2" name="package_services" rows="2" placeholder="Ăn sáng buffet, hồ bơi, spa..."></textarea>
                    <label class="form-label fw-bold">Trạng thái</label><select class="form-select mb-2" name="status"><option value="available">Đang hoạt động</option><?php if ($canSetMaintenance): ?><option value="maintenance">Bảo trì</option><?php endif; ?></select>
                    <label class="form-label fw-bold">Ghi chú</label><textarea class="form-control mb-3" name="note" rows="3"></textarea>
                    <button class="btn btn-hotel w-100">Lưu phòng</button>
                    </div>
                </form>
                </div>
            </section>

                <?php if ($roomMode === 'walkin'): ?>
                <div class="admin-modal" id="walkinModal" aria-hidden="true">
                <form class="modal-panel" method="post">
                    <input type="hidden" name="action" value="walk_in">
                    <input type="hidden" name="id_room" value="">
                    <input type="hidden" name="capacity" value="2">
                    <input type="hidden" name="extra_guest_rate_percent" value="25">
                    <input type="hidden" name="booked_ranges" value="[]">
                    <input type="hidden" name="return_url" value="<?php echo h($currentAdminUrl); ?>">
                    <div class="modal-head">
                        <div><div class="kicker">Walk-in</div><h2 class="h5 fw-bold mb-0" data-modal-heading>Nhận phòng ngay</h2></div>
                        <button class="btn btn-light modal-close" type="button" data-close-modal aria-label="Đóng"><i class="fa fa-xmark"></i></button>
                    </div>
                    <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-md-6"><label class="form-label fw-bold">Họ tên</label><input class="form-control" name="full_name" required></div>
                        <div class="col-md-6"><label class="form-label fw-bold">Điện thoại</label><input class="form-control" name="phone" required></div>
                        <div class="col-md-6"><label class="form-label fw-bold">Email</label><input class="form-control" type="email" name="email" required></div>
                        <div class="col-md-6"><label class="form-label fw-bold">CCCD/Hộ chiếu</label><input class="form-control" name="identity_no" required></div>
                        <div class="col-md-6"><label class="form-label fw-bold">Kiểu lưu trú</label><select class="form-select" name="walkin_mode" data-walkin-mode><option value="hour">Theo giờ</option><option value="night">Theo ngày</option></select></div>
                        <div class="col-md-6" data-hour-stay-field><label class="form-label fw-bold">Số giờ ở</label><input class="form-control" type="number" name="expected_hours" min="1" value="2"></div>
                        <div class="col-md-6 d-none" data-night-stay-field><label class="form-label fw-bold">Ngày trả phòng</label><input class="form-control" name="walkin_check_out" data-walkin-checkout readonly value="<?php echo h($tomorrow); ?>"></div>
                        <div class="col-md-6"><label class="form-label fw-bold">Người lớn</label><select class="form-select" name="adults" data-adults-select><?php echo adminGuestOptions(1, 3, 1, 'người lớn'); ?></select></div>
                        <div class="col-md-6"><label class="form-label fw-bold">Trẻ em</label><select class="form-select" name="children" data-children-select><?php echo adminGuestOptions(0, 2, 0, 'trẻ em'); ?></select></div>
                    </div>
                    <div class="text-muted small mt-1" data-occupancy-note>Chọn phòng để áp dụng số khách theo sức chứa.</div>
                    <label class="form-label fw-bold mt-2">Phòng nhận khách</label>
                    <input class="form-control mb-2" name="selected_room" readonly value="">
                    <label class="form-label fw-bold">Ghi chú</label><textarea class="form-control mb-3" name="note" rows="3"></textarea>
                    <button class="btn btn-hotel"><i class="fa fa-key"></i> Tạo và check-in</button>
                    </div>
                </form>
                </div>
                <?php endif; ?>

        <?php elseif ($view === 'room_status'): ?>
            <section class="panel panel-body">
                <div class="panel-head">
                    <div>
                        <div class="kicker">Live rooms</div>
                        <h2 class="h5 fw-bold mb-0">Phòng đang sử dụng</h2>
                    </div>
                    <div class="panel-actions">
                        <div class="refresh-note"><i class="fa fa-rotate"></i> Tự cập nhật mỗi 30 giây</div>
                        <form class="d-flex gap-2" method="get">
                            <input type="hidden" name="view" value="room_status">
                            <select class="form-select" name="hotel" onchange="this.form.submit()">
                                <option value="0">Tất cả cơ sở</option>
                                <?php foreach ($hotels as $hotel): ?><option value="<?php echo (int)$hotel['id_hotel']; ?>" <?php echo (int)$hotel['id_hotel'] === $selectedHotelId ? 'selected' : ''; ?>><?php echo h($hotel['hotel_name']); ?></option><?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                </div>
                <div class="stat-grid">
                    <div class="panel stat"><i class="fa fa-key"></i><strong><?php echo (int)$stats['active_now']; ?></strong><span>Đang sử dụng hiện tại</span></div>                    <div class="panel stat"><i class="fa fa-building"></i><strong><?php echo $selectedHotelId > 0 ? '1' : count($hotels); ?></strong><span>Cơ sở theo dõi</span></div>
                </div>
                <?php if (!$activeRoomRows): ?>
                    <div class="empty-panel text-center">Hiện chưa có phòng nào đến giờ sử dụng hoặc đang check-in.</div>
                <?php endif; ?>
                <div class="live-grid">
                    <?php foreach ($activeRoomRows as $room): ?>
                        <article class="live-room is-active">
                            <img src="<?php echo h(roomDisplayImage($room)); ?>" alt="<?php echo h($room['room_type']); ?>">
                            <div class="live-room-body">
                                <div class="d-flex justify-content-between gap-2 align-items-start">
                                    <div><strong>Phòng <?php echo h($room['room_number']); ?></strong><div class="text-muted small"><?php echo h($room['hotel_name'] . ' - ' . $room['room_type']); ?></div></div>
                                    <span class="status-pill status-occupied">Đang sử dụng</span>
                                </div>
                                <div class="live-meta">
                                    <div><i class="fa fa-user"></i> <?php echo h($room['full_name']); ?></div>
                                    <div><i class="fa fa-clock"></i> <?php echo h(adminDateTime($room['check_in_at'] ?: $room['check_in'])); ?> -> <?php echo h(adminDateTime($room['expected_check_out_at'] ?: $room['check_out'])); ?></div>
                                </div>
                                <?php if ($currentAdminRole !== 'housekeeping'): ?>
                                    <div class="live-actions">
                                        <button class="btn btn-outline-dark btn-sm" type="button" data-open-modal="guestInfo<?php echo (int)$room['id_booking']; ?>">Thông tin khách</button>
                                        <a class="btn btn-outline-dark btn-sm" href="?view=room_status&booking=<?php echo (int)$room['id_booking']; ?>&hotel=<?php echo (int)$selectedHotelId; ?>">Xuất hóa đơn</a>
                                    </div>
                                <?php else: ?>
                                    <div class="text-muted small mt-2"><i class="fa fa-eye"></i> Chỉ xem phòng đang hoạt động</div>
                                <?php endif; ?>
                            </div>
                        </article>
                        <div class="admin-modal" id="guestInfo<?php echo (int)$room['id_booking']; ?>" aria-hidden="true">
                            <div class="modal-panel">
                                <div class="modal-head">
                                    <div><div class="kicker">Guest profile</div><h2 class="h5 fw-bold mb-0">Thông tin khách hàng</h2></div>
                                    <button class="btn btn-light modal-close" type="button" data-close-modal aria-label="Đóng"><i class="fa fa-xmark"></i></button>
                                </div>
                                <div class="modal-body">
                                    <div class="invoice-line"><span>Mã khách</span><strong>KH<?php echo str_pad((string)$room['id_guest'], 5, '0', STR_PAD_LEFT); ?></strong></div>
                                    <div class="invoice-line"><span>Khách hàng</span><strong><?php echo h($room['full_name']); ?></strong></div>
                                    <div class="invoice-line"><span>Điện thoại</span><strong><?php echo h($room['phone']); ?></strong></div>
                                    <div class="invoice-line"><span>Email</span><strong><?php echo h($room['email'] ?: '-'); ?></strong></div>
                                    <div class="invoice-line"><span>CCCD/Hộ chiếu</span><strong><?php echo h($room['identity_no'] ?: '-'); ?></strong></div>
                                    <div class="invoice-line"><span>Địa chỉ</span><strong><?php echo h($room['guest_address'] ?: '-'); ?></strong></div>
                                    <?php $roomGuestTier = memberTier(guestTotalPaid($db, (int)$room['id_guest'])); ?>
                                    <div class="invoice-line"><span>Hạng member</span><strong><?php echo h(memberTierLabel($roomGuestTier)); ?></strong></div>
                                    <div class="invoice-line"><span>Điểm</span><strong><span class="point-badge"><i class="fa fa-coins"></i><?php echo h(points($room['loyalty_points'] ?? 0)); ?></span></strong></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($selectedBooking): ?><?php
                    $totals = bookingTotals($db, $selectedBooking);
                    $isHourlyBill = ($selectedBooking['pricing_mode'] ?? 'night') === 'hour';
                    $billingStart = $selectedBooking['check_in_at'] ?: ($selectedBooking['check_in'] . ' 14:00:00');
                    $billingEnd = bookingBillingEnd($selectedBooking);
                    $roomBaseTotal = $totals['room'] - $totals['late_fee'] - $totals['extra_guest_fee'];
                    $serviceRows = bookingServices($db, (int)$selectedBooking['id_booking']);
                    $paymentRows = bookingPayments($db, (int)$selectedBooking['id_booking']);
                    $paymentMethodTotals = paymentMethodTotals($paymentRows);
                    $billMeta = adminBillMeta($selectedBooking, $paymentRows);
                    $invoiceGuestTotal = guestTotalPaid($db, (int)$selectedBooking['id_guest']);
                    $invoiceTier = memberTier($invoiceGuestTotal);
                    $invoicePaidStatus = $totals['debt'] <= 0 ? 'Đã thanh toán' : 'Chưa thanh toán đủ';
                    $unitPrice = $isHourlyBill ? (float)$selectedBooking['hourly_rate'] : (float)$selectedBooking['price_per_night'];
                    $roomChargeLabel = $isHourlyBill ? 'Tiền phòng theo giờ' : 'Tiền phòng qua đêm';
                    $unitText = $isHourlyBill ? 'giờ' : 'đêm';
                ?>
                    <div class="admin-modal" id="invoiceModal" aria-hidden="true">
                        <div class="modal-panel invoice-bill" id="printInvoice">
                            <div class="modal-head no-print">
                                <div><div class="kicker">Invoice</div><h2 class="h5 fw-bold mb-0">Hóa đơn theo thời gian thực</h2></div>
                                <button class="btn btn-light modal-close" type="button" data-close-modal aria-label="Đóng"><i class="fa fa-xmark"></i></button>
                            </div>
                            <div class="modal-body">
                                <div class="invoice-sheet">
                                    <div class="invoice-title">
                                        <div class="invoice-brand">
                                            <div class="invoice-logo"><i class="fa fa-hotel"></i></div>
                                            <div>
                                                <div class="kicker">Spotki Hotels</div>
                                                <h2>Hóa đơn thanh toán</h2>
                                                <div class="text-muted fw-bold"><?php echo h($selectedBooking['hotel_name'] ?? 'Spotki Hotel'); ?></div>
                                                <div class="small text-muted"><?php echo h(trim(($selectedBooking['hotel_address'] ?? '') . ', ' . ($selectedBooking['hotel_city'] ?? ''), ', ')); ?></div>
                                                <div class="small text-muted"><?php echo h(trim(($selectedBooking['hotel_phone'] ?? '') . ' | ' . ($selectedBooking['hotel_email'] ?? ''), ' |')); ?></div>
                                                <div class="mt-2"><span class="status-pill status-<?php echo $totals['debt'] <= 0 ? 'available' : 'occupied'; ?>"><?php echo h($invoicePaidStatus); ?></span></div>
                                            </div>
                                        </div>
                                        <div class="invoice-code">
                                            Mã hóa đơn
                                            <strong><?php echo h(invoiceCode($selectedBooking)); ?></strong>
                                            <div>Booking <?php echo h($selectedBooking['booking_code']); ?></div>
                                            <div>Giờ ra bill <?php echo h(adminDateTime($billMeta['issued_at'])); ?></div>
                                            <div>Nhân viên thanh toán <?php echo h($billMeta['cashier_name'] ?: '-'); ?></div>
                                            <button class="btn btn-outline-dark btn-sm no-print mt-2" onclick="window.print()"><i class="fa fa-print"></i> In bill</button>
                                        </div>
                                    </div>

                                    <div class="invoice-info-grid invoice-stack">
                                        <div class="invoice-box">
                                            <div class="invoice-box-title">Thông tin khách</div>
                                            <div class="invoice-row"><span>Tên khách</span><strong><?php echo h($selectedBooking['contact_name'] ?: $selectedBooking['full_name']); ?></strong></div>
                                            <div class="invoice-row"><span>SĐT</span><strong><?php echo h($selectedBooking['contact_phone'] ?: $selectedBooking['phone']); ?></strong></div>
                                            <div class="invoice-row"><span>Hạng member</span><strong><?php echo h(memberTierLabel($invoiceTier)); ?></strong></div>
                                            <div class="invoice-row"><span>Điểm hiện có</span><strong><span class="point-badge"><i class="fa fa-coins"></i><?php echo h(points($selectedBooking['loyalty_points'] ?? 0)); ?></span></strong></div>
                                        </div>
                                        <div class="invoice-box">
                                            <div class="invoice-box-title">Thông tin lưu trú</div>
                                            <div class="invoice-row"><span>Phòng</span><strong><?php echo h($selectedBooking['room_number'] . ' - ' . $selectedBooking['room_type']); ?></strong></div>
                                            <div class="invoice-row"><span>Giờ vào</span><strong><?php echo h(adminDateTime($billingStart)); ?></strong></div>
                                            <div class="invoice-row"><span>Giờ ra</span><strong><?php echo h(adminDateTime($billingEnd)); ?></strong></div>
                                        </div>
                                    </div>

                                    <table class="invoice-table">
                                        <thead><tr><th>Hạng mục</th><th>Đơn giá</th><th>Số lượng</th><th class="text-end">Thành tiền</th></tr></thead>
                                        <tbody>
                                            <tr>
                                                <td>
                                                    <strong><?php echo h($roomChargeLabel); ?></strong>
                                                </td>
                                                <td><?php echo money($unitPrice); ?>/<?php echo h($unitText); ?></td>
                                                <td><?php echo h($totals['unit_label']); ?></td>
                                                <td class="money-cell"><?php echo money($roomBaseTotal); ?></td>
                                            </tr>
                                            <?php if ($totals['extra_guest_fee'] > 0): ?>
                                                <tr>
                                                    <td>
                                                        <strong>Phụ thu thêm người</strong>
                                                        <div class="invoice-note">Vượt <?php echo (int)$totals['extra_guests']; ?> người so với sức chứa phòng.</div>
                                                    </td>
                                                    <td><?php echo rtrim(rtrim(number_format((float)($totals['extra_guest_rate_percent'] ?? 25), 2, ',', '.'), '0'), ','); ?>% tiền phòng</td>
                                                    <td>1</td>
                                                    <td class="money-cell text-danger"><?php echo money($totals['extra_guest_fee']); ?></td>
                                                </tr>
                                            <?php endif; ?>
                                            <?php if (!$isHourlyBill): ?>
                                                <tr>
                                                    <td>
                                                        <strong>Phụ thu check-out muộn</strong>
                                                    </td>
                                                    <td><?php echo money((float)$selectedBooking['hourly_rate']); ?>/giờ</td>
                                                    <td><?php echo (int)$totals['late_hours']; ?> giờ</td>
                                                    <td class="money-cell <?php echo $totals['late_fee'] > 0 ? 'text-danger' : ''; ?>"><?php echo money($totals['late_fee']); ?></td>
                                                </tr>
                                            <?php endif; ?>
                                            <?php foreach ($serviceRows as $item): ?>
                                                <tr>
                                                    <td><strong><?php echo h($item['service_name']); ?></strong></td>
                                                    <td><?php echo money($item['price']); ?>/<?php echo h($item['unit']); ?></td>
                                                    <td><?php echo (int)$item['quantity']; ?></td>
                                                    <td class="money-cell"><?php echo money((float)$item['price'] * (int)$item['quantity']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>

                                    <div class="invoice-summary">
                                        <div class="invoice-total-box">
                                            <div class="invoice-total-row"><span>Tiền phòng</span><strong><?php echo money($roomBaseTotal); ?></strong></div>
                                            <?php if ($totals['extra_guest_fee'] > 0): ?><div class="invoice-total-row"><span>Phụ thu thêm người</span><strong><?php echo money($totals['extra_guest_fee']); ?></strong></div><?php endif; ?>
                                            <?php if (!$isHourlyBill): ?><div class="invoice-total-row"><span>Trả phòng muộn</span><strong><?php echo money($totals['late_fee']); ?></strong></div><?php endif; ?>
                                            <div class="invoice-total-row"><span>Đồ ăn & đồ uống</span><strong><?php echo money($totals['service']); ?></strong></div>
                                            <div class="invoice-total-row"><span>Tạm tính</span><strong><?php echo money($totals['subtotal']); ?></strong></div>
                                            <div class="invoice-total-row"><span>VAT 8%</span><strong><?php echo money($totals['vat']); ?></strong></div>
                                            <div class="invoice-total-row grand"><span>Tổng cộng</span><strong><?php echo money($totals['grand']); ?></strong></div>
                                            <?php foreach ($paymentMethodTotals as $method => $amount): ?>
                                                <div class="invoice-total-row payment-method"><span><?php echo h($method); ?></span><strong><?php echo money($amount); ?></strong></div>
                                            <?php endforeach; ?>
                                            <div class="invoice-total-row debt"><span>Còn phải thu</span><strong><?php echo money($totals['debt']); ?></strong></div>
                                        </div>
                                    </div>
                                </div>
                                <form class="mt-3 no-print" method="post">
                                    <input type="hidden" name="action" value="add_booking_service">
                                    <input type="hidden" name="id_booking" value="<?php echo (int)$selectedBooking['id_booking']; ?>">
                                    <input type="hidden" name="return_url" value="<?php echo h($currentAdminUrl); ?>">
                                    <label class="form-label fw-bold">Thêm đồ ăn thức uống đã dùng</label>
                                    <div class="d-flex gap-2">
                                        <select class="form-select" name="id_service" required>
                                            <option value="">Chọn item</option>
                                            <?php foreach ($activeServices as $service): ?><option value="<?php echo (int)$service['id_service']; ?>"><?php echo h($service['service_name'] . ' - ' . money($service['price']) . '/' . $service['unit']); ?></option><?php endforeach; ?>
                                        </select>
                                        <input class="form-control" type="number" name="quantity" min="1" value="1" style="max-width:90px">
                                        <button class="btn btn-outline-dark">Thêm</button>
                                    </div>
                                </form>
                                <form class="mt-3 no-print payment-panel" method="post" data-payment-form data-payment-debt="<?php echo h((string)$totals['debt']); ?>">
                                    <input type="hidden" name="action" value="add_payment">
                                    <input type="hidden" name="id_booking" value="<?php echo (int)$selectedBooking['id_booking']; ?>">
                                    <input type="hidden" name="return_url" value="<?php echo h($currentAdminUrl); ?>">
                                    <label class="form-label fw-bold">Thanh toán tiếp</label>
                                    <div class="payment-stats">
                                        <div class="payment-stat"><span>Đã thanh toán</span><strong><?php echo money($totals['paid']); ?></strong></div>
                                        <div class="payment-stat"><span>Còn phải thu</span><strong><?php echo money($totals['debt']); ?></strong></div>
                                        <div class="payment-stat"><span>Điểm khách</span><strong><span class="point-badge"><i class="fa fa-coins"></i><?php echo h(points($selectedBooking['loyalty_points'] ?? 0)); ?></span></strong></div>
                                        <div class="payment-stat"><span>Nhập lần này</span><strong data-payment-current><?php echo money(0); ?></strong></div>
                                    </div>
                                    <div class="payment-entry-grid mb-2">
                                        <div>
                                            <label class="form-label small fw-bold">Số tiền</label>
                                            <input class="form-control" type="number" name="amount" placeholder="Nhập số tiền" min="0" max="<?php echo h((string)$totals['debt']); ?>" data-payment-amount>
                                        </div>
                                        <div>
                                            <label class="form-label small fw-bold">Hình thức</label>
                                            <select class="form-select" name="method">
                                                <option value="">Chọn hình thức</option>
                                                <option>Tiền mặt</option>
                                                <option>Chuyển khoản</option>
                                                <option>Thẻ</option>
                                                <option>Ví điện tử</option>
                                            </select>
                                        </div>
                                        <div>
                                            <?php $redeemablePoints = min((int)($selectedBooking['loyalty_points'] ?? 0), (int)floor((float)$totals['debt'])); ?>
                                            <label class="form-label small fw-bold">Điểm tích lũy</label>
                                            <input type="hidden" name="redeem_points" value="0" data-payment-amount data-point-redeem-input>
                                            <button class="btn btn-outline-dark w-100" type="button" data-use-all-points="<?php echo (int)$redeemablePoints; ?>" <?php echo $redeemablePoints > 0 ? '' : 'disabled'; ?>>
                                                Trừ <?php echo h(points($redeemablePoints)); ?>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button class="btn btn-outline-dark btn-sm" type="button" data-fill-cash>Thu đủ</button>
                                        <button class="btn btn-hotel flex-fill">Ghi nhận thanh toán</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

        <?php elseif ($view === 'housekeeping'): ?>
            <section class="panel panel-body">
                <div class="panel-head">
                    <div>
                        <div class="kicker">Housekeeping</div>
                        <h2 class="h5 fw-bold mb-0">Dọn phòng sau check-out</h2>
                    </div>
                    <div class="panel-actions">
                        <div class="refresh-note"><i class="fa fa-rotate"></i> Tự cập nhật mỗi 30 giây</div>
                        <form class="d-flex gap-2" method="get">
                            <input type="hidden" name="view" value="housekeeping">
                            <select class="form-select" name="hotel" onchange="this.form.submit()">
                                <option value="0">Tất cả cơ sở</option>
                                <?php foreach ($hotels as $hotel): ?><option value="<?php echo (int)$hotel['id_hotel']; ?>" <?php echo (int)$hotel['id_hotel'] === $selectedHotelId ? 'selected' : ''; ?>><?php echo h($hotel['hotel_name']); ?></option><?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                </div>
                <div class="stat-grid">
                    <div class="panel stat"><i class="fa fa-broom"></i><strong><?php echo count($housekeepingRows); ?></strong><span>Phòng cần xử lý</span></div>
                    <div class="panel stat"><i class="fa fa-list-check"></i><strong><?php echo $housekeepingPendingCount; ?></strong><span>Cần dọn</span></div>
                    <div class="panel stat"><i class="fa fa-person-digging"></i><strong><?php echo $housekeepingCleaningCount; ?></strong><span>Đang dọn</span></div>
                    <div class="panel stat"><i class="fa fa-building"></i><strong><?php echo $selectedHotelId > 0 ? '1' : count($hotels); ?></strong><span>Cơ sở theo dõi</span></div>
                </div>
                <?php if (!$housekeepingRows): ?>
                    <div class="empty-panel text-center">Không có phòng nào cần dọn trong bộ lọc hiện tại.</div>
                <?php endif; ?>
                <div class="live-grid">
                    <?php foreach ($housekeepingRows as $room): ?><?php $hkStatus = $room['housekeeping_status'] ?: 'pending'; ?>
                        <article class="live-room">
                            <img src="<?php echo h(roomDisplayImage($room)); ?>" alt="<?php echo h($room['room_type']); ?>">
                            <div class="live-room-body">
                                <div class="d-flex justify-content-between gap-2 align-items-start">
                                    <div><strong>Phòng <?php echo h($room['room_number']); ?></strong><div class="text-muted small"><?php echo h($room['hotel_name'] . ' - ' . $room['room_type']); ?></div></div>
                                    <span class="status-pill status-cleaning"><?php echo h(adminHousekeepingStatusLabel($hkStatus)); ?></span>
                                </div>
                                <div class="live-meta">
                                    <div><i class="fa fa-user"></i> <?php echo h($room['full_name'] ?: 'Khách vừa trả phòng'); ?></div>
                                    <div><i class="fa fa-clock"></i> Check-out: <?php echo h(adminDateTime($room['checked_out_at'] ?? null)); ?></div>
                                    <?php if (!empty($room['housekeeping_started_at'])): ?><div><i class="fa fa-broom"></i> Bắt đầu dọn: <?php echo h(adminDateTime($room['housekeeping_started_at'])); ?></div><?php endif; ?>
                                </div>
                                <?php if (adminCanRunAction($currentAdminRole, 'set_housekeeping_status')): ?>
                                    <div class="live-actions">
                                        <?php if ($hkStatus === 'pending'): ?>
                                            <form method="post">
                                                <input type="hidden" name="action" value="set_housekeeping_status">
                                                <input type="hidden" name="id_room" value="<?php echo (int)$room['id_room']; ?>">
                                                <input type="hidden" name="status" value="cleaning">
                                                <button class="btn btn-outline-dark btn-sm"><i class="fa fa-broom"></i> Bắt đầu dọn</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post">
                                            <input type="hidden" name="action" value="set_housekeeping_status">
                                            <input type="hidden" name="id_room" value="<?php echo (int)$room['id_room']; ?>">
                                            <input type="hidden" name="status" value="available">
                                            <button class="btn btn-hotel btn-sm"><i class="fa fa-check"></i> Hoàn tất, phòng trống</button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <div class="text-muted small mt-2"><i class="fa fa-eye"></i> Chỉ xem trạng thái dọn phòng</div>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

        <?php elseif ($view === 'walkin'): ?>
            <section>
                <div class="panel panel-body mb-3">
                    <div class="panel-head">
                        <div><div class="kicker">Walk-in</div><h2 class="h5 fw-bold mb-0">Nhận phòng khách vãng lai</h2></div>
                        <button class="btn btn-hotel btn-sm" type="button" data-open-modal="walkinModal"><i class="fa fa-key"></i> Tạo và check-in</button>
                    </div>
                    <div class="notice mb-0"><i class="fa fa-circle-info"></i><div>Chọn phòng sẵn sàng bên dưới rồi tạo lượt khách vãng lai trong popup.</div></div>
                </div>
                <div class="admin-modal" id="walkinModal" aria-hidden="true">
                <form class="modal-panel" method="post">
                    <input type="hidden" name="action" value="walk_in">
                    <input type="hidden" name="booked_ranges" value="[]">
                    <div class="modal-head">
                        <div><div class="kicker">Walk-in</div><h2 class="h5 fw-bold mb-0" data-modal-heading>Nhận phòng ngay</h2></div>
                        <button class="btn btn-light modal-close" type="button" data-close-modal aria-label="Đóng"><i class="fa fa-xmark"></i></button>
                    </div>
                    <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-md-6"><label class="form-label fw-bold">Họ tên</label><input class="form-control" name="full_name" required></div>
                        <div class="col-md-6"><label class="form-label fw-bold">Điện thoại</label><input class="form-control" name="phone" required></div>
                        <div class="col-md-6"><label class="form-label fw-bold">CCCD/Hộ chiếu</label><input class="form-control" name="identity_no" required></div>
                        <div class="col-md-6"><label class="form-label fw-bold">Kiểu lưu trú</label><select class="form-select" name="walkin_mode" data-walkin-mode><option value="hour">Theo giờ</option><option value="night">Theo ngày</option></select></div>
                        <div class="col-md-6" data-hour-stay-field><label class="form-label fw-bold">Số giờ ở</label><input class="form-control" type="number" name="expected_hours" min="1" value="2"></div>
                        <div class="col-md-6 d-none" data-night-stay-field><label class="form-label fw-bold">Ngày trả phòng</label><input class="form-control" name="walkin_check_out" data-walkin-checkout readonly value="<?php echo h($tomorrow); ?>"></div>
                        <div class="col-md-6"><label class="form-label fw-bold">Người lớn</label><select class="form-select" name="adults" data-adults-select><?php echo adminGuestOptions(1, 3, 1, 'người lớn'); ?></select></div>
                        <div class="col-md-6"><label class="form-label fw-bold">Trẻ em</label><select class="form-select" name="children" data-children-select><?php echo adminGuestOptions(0, 2, 0, 'trẻ em'); ?></select></div>
                    </div>
                    <div class="text-muted small mt-1" data-occupancy-note>Chọn phòng để áp dụng số khách theo sức chứa.</div>
                    <label class="form-label fw-bold mt-2">Phòng trống</label>
                    <select class="form-select mb-2" name="id_room" required data-room-select><option value="">Chọn phòng</option><?php foreach ($rooms as $room): ?><?php if ($room['status'] === 'available'): ?><option value="<?php echo (int)$room['id_room']; ?>" data-capacity="<?php echo (int)$room['capacity']; ?>" data-extra_guest_rate_percent="<?php echo h((string)($room['extra_guest_rate_percent'] ?? 25)); ?>" data-booked_ranges="<?php echo h(json_encode($walkinBookedRangesByRoom[(int)$room['id_room']] ?? [], JSON_UNESCAPED_SLASHES)); ?>"><?php echo h($room['hotel_name'] . ' - Phòng ' . $room['room_number'] . ' - ' . $room['room_type'] . ' - ' . (int)$room['capacity'] . ' khách - ' . money($room['hourly_rate']) . '/giờ'); ?></option><?php endif; ?><?php endforeach; ?></select>
                    <label class="form-label fw-bold">Ghi chú</label><textarea class="form-control mb-3" name="note" rows="3"></textarea>
                    <button class="btn btn-hotel"><i class="fa fa-key"></i> Tạo và check-in</button>
                    </div>
                </form>
                </div>
                <div class="panel panel-body">
                    <div class="panel-head"><div><div class="kicker">Ready rooms</div><h2 class="h5 fw-bold mb-0">Phòng sẵn sàng</h2></div></div>
                    <div class="room-board"><?php foreach ($rooms as $room): ?><?php if ($room['status'] === 'available'): ?><div class="room-tile"><strong><?php echo h($room['room_number']); ?></strong><span class="text-muted small"><?php echo h($room['hotel_name']); ?></span><span class="text-muted small"><?php echo h($room['room_type']); ?></span><span class="fw-bold"><?php echo money($room['hourly_rate']); ?>/giờ</span></div><?php endif; ?><?php endforeach; ?></div>
                </div>
            </section>

        <?php elseif ($view === 'guests'): ?>
            <section>
                <div class="panel panel-body">
                    <div class="panel-head">
                        <div><div class="kicker">CRM</div><h2 class="h5 fw-bold mb-0">Danh sách khách hàng</h2><div class="text-muted small"><?php echo count($guests); ?> khách hàng trong hệ thống</div></div>
                        <button class="btn btn-hotel btn-sm" type="button" data-open-modal="guestModal"><i class="fa fa-plus"></i> Thêm khách hàng</button>
                    </div>
                    <div class="table-responsive"><table class="table table-hover"><thead><tr><th>Mã</th><th>Khách hàng</th><th>Liên hệ</th><th>CCCD/Hộ chiếu</th><th>Hạng member</th><th>Tổng chi</th><th>Điểm</th><th>Lịch sử booking</th><th></th></tr></thead><tbody><?php foreach ($guests as $guest): ?><?php $guestId = (int)$guest['id_guest']; $guestStats = $guestStatsById[$guestId] ?? ['booking_count' => 0, 'total_paid' => 0]; $tier = memberTier((float)$guestStats['total_paid']); $history = $guestBookingsById[$guestId] ?? []; ?><tr><td class="fw-bold">KH<?php echo str_pad((string)$guestId, 5, '0', STR_PAD_LEFT); ?></td><td><?php echo h($guest['full_name']); ?><div class="text-muted small"><?php echo (int)$guestStats['booking_count']; ?> booking</div></td><td><?php echo h($guest['phone']); ?><div class="text-muted small"><?php echo h($guest['email'] ?: '-'); ?></div></td><td><?php echo h($guest['identity_no'] ?: '-'); ?></td><td><span class="status-pill status-<?php echo $tier === 'diamond' ? 'checked_out' : ($tier === 'gold' ? 'cleaning' : 'available'); ?>"><?php echo h(memberTierLabel($tier)); ?></span></td><td class="fw-bold"><?php echo money($guestStats['total_paid']); ?></td><td><span class="point-badge"><i class="fa fa-coins"></i><?php echo h(points($guest['loyalty_points'] ?? 0)); ?></span></td><td><?php if ($history): ?><button class="btn btn-outline-dark btn-sm" type="button" data-open-modal="guestHistory<?php echo $guestId; ?>">Xem lịch sử</button><?php else: ?><span class="text-muted">Chưa có</span><?php endif; ?></td><td><button class="btn btn-outline-dark btn-sm" type="button" data-open-modal="guestModal" data-modal-title="Chỉnh sửa khách hàng" data-id_guest="<?php echo $guestId; ?>" data-full_name="<?php echo h($guest['full_name']); ?>" data-phone="<?php echo h($guest['phone']); ?>" data-email="<?php echo h($guest['email']); ?>" data-identity_no="<?php echo h($guest['identity_no']); ?>" data-address="<?php echo h($guest['address']); ?>" data-loyalty_points="<?php echo (int)($guest['loyalty_points'] ?? 0); ?>">Sửa</button></td></tr><?php endforeach; ?></tbody></table></div>
                    <?php foreach ($guests as $guest): ?><?php $guestId = (int)$guest['id_guest']; $history = $guestBookingsById[$guestId] ?? []; if (!$history) { continue; } ?>
                        <div class="admin-modal" id="guestHistory<?php echo $guestId; ?>" aria-hidden="true">
                            <div class="modal-panel modal-wide">
                                <div class="modal-head">
                                    <div><div class="kicker">Booking history</div><h2 class="h5 fw-bold mb-0"><?php echo h($guest['full_name']); ?></h2></div>
                                    <button class="btn btn-light modal-close" type="button" data-close-modal aria-label="Đóng"><i class="fa fa-xmark"></i></button>
                                </div>
                                <div class="modal-body">
                                    <div class="table-responsive"><table class="table table-hover">
                                        <thead><tr><th>Mã booking</th><th>Cơ sở</th><th>Phòng</th><th>Giờ vào</th><th>Giờ ra</th><th>Kiểu</th><th>Trạng thái</th><th>Đã thu</th></tr></thead>
                                        <tbody><?php foreach ($history as $booking): ?><tr>
                                            <td class="fw-bold"><?php echo h($booking['booking_code']); ?></td>
                                            <td><?php echo h($booking['hotel_name'] ?: '-'); ?></td>
                                            <td><?php echo h($booking['room_number']); ?></td>
                                            <td><?php echo h(adminDateTime($booking['check_in_at'] ?: $booking['check_in'])); ?></td>
                                            <td><?php echo h(adminDateTime($booking['expected_check_out_at'] ?: $booking['check_out'])); ?></td>
                                            <td><?php echo h(adminPricingModeLabel($booking['pricing_mode'])); ?></td>
                                            <td><span class="status-pill status-<?php echo h($booking['status']); ?>"><?php echo h(adminBookingStatusLabel($booking['status'])); ?></span></td>
                                            <td class="fw-bold"><?php echo money($booking['paid']); ?></td>
                                        </tr><?php endforeach; ?></tbody>
                                    </table></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="admin-modal" id="guestModal" aria-hidden="true">
                <form class="modal-panel" method="post">
                    <input type="hidden" name="action" value="save_guest">
                    <input type="hidden" name="id_guest" value="">
                    <div class="modal-head">
                        <div><div class="kicker">Guest profile</div><h2 class="h5 fw-bold mb-0" data-modal-heading>Thêm khách hàng</h2></div>
                        <button class="btn btn-light modal-close" type="button" data-close-modal aria-label="Đóng"><i class="fa fa-xmark"></i></button>
                    </div>
                    <div class="modal-body">
                    <label class="form-label fw-bold">Họ tên</label><input class="form-control mb-2" name="full_name" required>
                    <label class="form-label fw-bold">Điện thoại</label><input class="form-control mb-2" name="phone" required>
                    <label class="form-label fw-bold">Email</label><input class="form-control mb-2" type="email" name="email">
                    <label class="form-label fw-bold">CCCD/Hộ chiếu</label><input class="form-control mb-2" name="identity_no">
                    <label class="form-label fw-bold">Địa chỉ</label><textarea class="form-control mb-3" name="address" rows="3"></textarea>
                    <label class="form-label fw-bold">Điểm</label><input class="form-control mb-3" type="number" name="loyalty_points" min="0" value="0">
                    <button class="btn btn-hotel w-100">Lưu khách</button>
                    </div>
                </form>
                </div>
            </section>

        <?php elseif ($view === 'bookings'): ?>
            <section>
                <div class="panel panel-body">
                    <div class="panel-head">
                        <div><div class="kicker">Reservations</div><h2 class="h5 fw-bold mb-0"><?php echo $search !== '' ? 'Kết quả tìm kiếm' : 'Phòng nhận đặt trước'; ?></h2></div>
                        <div class="panel-actions">
                            <div class="text-muted small"><?php echo h($dateRangeLabel); ?></div>
                            <div class="text-muted small"><?php echo count($bookings); ?> hồ sơ</div>
                            <button class="btn btn-hotel btn-sm" type="button" data-open-modal="bookingModal"><i class="fa fa-plus"></i> Tạo đặt phòng</button>
                        </div>
                    </div>
                    <div class="room-filter-bar">
                        <div class="mode-tabs">
                            <?php
                            $bookingFilterBase = '&hotel=' . (int)$selectedHotelId . ($search !== '' ? '&q=' . urlencode($search) : '');
                            $bookingTabs = [
                                'today' => ['fa-calendar-day', 'Hôm nay'],
                                'next7' => ['fa-calendar-week', '7 ngày tới'],
                                'prev3' => ['fa-clock-rotate-left', '3 ngày trước'],
                                'all' => ['fa-list', 'Tất cả'],
                            ];
                            ?>
                            <?php foreach ($bookingTabs as $rangeKey => $tab): ?>
                                <?php $bookingTabUrl = '?view=bookings&booking_range=' . urlencode($rangeKey) . $bookingFilterBase; ?>
                                <a class="mode-tab <?php echo $bookingRange === $rangeKey ? 'active' : ''; ?>" href="<?php echo h($bookingTabUrl); ?>"><i class="fa <?php echo h($tab[0]); ?>"></i> <?php echo h($tab[1]); ?></a>
                            <?php endforeach; ?>
                        </div>
                        <form class="d-flex gap-2" method="get">
                            <input type="hidden" name="view" value="bookings">
                            <input type="hidden" name="booking_range" value="custom">
                            <input type="hidden" name="hotel" value="<?php echo (int)$selectedHotelId; ?>">
                            <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?php echo h($search); ?>"><?php endif; ?>
                            <input class="form-control" type="date" name="date_from" value="<?php echo h($bookingRange === 'custom' ? $dateFrom : ''); ?>" title="Từ ngày">
                            <input class="form-control" type="date" name="date_to" value="<?php echo h($bookingRange === 'custom' ? $dateTo : ''); ?>" title="Đến ngày">
                            <button class="btn btn-outline-dark"><i class="fa fa-filter"></i> Lọc</button>
                        </form>
                    </div>
                    <div class="table-responsive"><table class="table table-hover">
                        <thead><tr><th>Mã</th><th>Khách</th><th>Cơ sở</th><th>Phòng</th><th>Số khách</th><th>Thời gian</th><th>Kiểu thanh toán</th><th>Trạng thái</th><th></th></tr></thead>
                        <tbody><?php foreach ($bookings as $booking): ?><tr>
                            <td class="fw-bold"><?php echo h($booking['booking_code']); ?></td>
                            <td><?php echo h($booking['contact_name'] ?: $booking['full_name']); ?><div class="text-muted small"><?php echo h($booking['contact_phone'] ?: $booking['phone']); ?></div><?php if ((int)($booking['customer_edit_count'] ?? 0) > 0): ?><div class="mt-1"><span class="status-pill status-cleaning">Đã sửa booking</span></div><?php endif; ?></td>
                            <td><?php echo h($booking['hotel_name']); ?><div class="text-muted small"><?php echo h($booking['hotel_city']); ?></div></td>
                            <td><?php echo h($booking['room_number']); ?><div class="text-muted small"><?php echo h($booking['room_type']); ?></div></td>
                            <td><?php $bookingPolicy = bookingOccupancyPolicy($booking); ?><?php echo (int)$booking['adults']; ?> NL · <?php echo (int)$booking['children']; ?> TE<div class="text-muted small"><?php echo (int)$booking['capacity']; ?> khách, free <?php echo (int)$bookingPolicy['free_children']; ?> em bé<?php echo $bookingPolicy['extra_guests'] > 0 ? ' · phụ thu ' . rtrim(rtrim(number_format((float)($booking['extra_guest_rate_percent'] ?? 25), 2, ',', '.'), '0'), ',') . '%' : ''; ?></div></td>
                            <td><?php echo h(adminDateTime($booking['check_in_at'] ?: $booking['check_in'])); ?><div class="text-muted small"><?php echo h(adminDateTime($booking['expected_check_out_at'] ?: $booking['check_out'])); ?></div></td>
                            <td><?php echo h(adminPricingModeLabel($booking['pricing_mode'])); ?></td>
                            <td><span class="status-pill status-<?php echo h($booking['status']); ?>"><?php echo h(adminBookingStatusLabel($booking['status'])); ?></span><?php if ((int)($booking['customer_edit_count'] ?? 0) > 0): ?><div class="small text-muted mt-1"><?php echo h(adminDateTime($booking['customer_edited_at'])); ?></div><?php endif; ?></td>
                            <td>
                                <div class="d-flex gap-1">
                                    <button class="btn btn-outline-dark btn-sm" type="button" data-open-modal="bookingModal" data-modal-title="Chỉnh sửa booking" data-id_booking="<?php echo (int)$booking['id_booking']; ?>" data-full_name="<?php echo h($booking['full_name']); ?>" data-phone="<?php echo h($booking['phone']); ?>" data-email="<?php echo h($booking['email']); ?>" data-identity_no="<?php echo h($booking['identity_no']); ?>" data-id_room="<?php echo (int)$booking['id_room']; ?>" data-id_hotel="<?php echo (int)$booking['id_hotel']; ?>" data-check_in="<?php echo h($booking['check_in']); ?>" data-check_out="<?php echo h($booking['check_out']); ?>" data-adults="<?php echo (int)$booking['adults']; ?>" data-children="<?php echo (int)$booking['children']; ?>" data-pricing_mode="<?php echo h($booking['pricing_mode']); ?>" data-status="<?php echo h($booking['status']); ?>" data-note="<?php echo h($booking['note']); ?>">Sửa</button>
                                    <form class="d-flex gap-1" method="post">
                                        <input type="hidden" name="action" value="set_booking_status">
                                        <input type="hidden" name="id_booking" value="<?php echo (int)$booking['id_booking']; ?>">
                                        <input type="hidden" name="hotel" value="<?php echo (int)$selectedHotelId; ?>">
                                        <input type="hidden" name="return_url" value="<?php echo h($currentAdminUrl); ?>">
                                        <select class="form-select form-select-sm" name="status">
                                            <option value="booked" selected>Booking</option>
                                            <option value="checked_in">Check-in</option>
                                            <option value="cancelled">Hủy booking</option>
                                        </select>
                                        <button class="btn btn-outline-dark btn-sm">Lưu</button>
                                    </form>
                                </div>
                            </td>
                        </tr><?php endforeach; ?></tbody>
                    </table></div>
                </div>
                <div class="admin-modal" id="bookingModal" aria-hidden="true">
                    <form class="modal-panel" method="post">
                        <input type="hidden" name="action" value="save_booking">
                        <input type="hidden" name="id_booking" value="">
                        <input type="hidden" name="return_url" value="<?php echo h($currentAdminUrl); ?>">
                        <div class="modal-head">
                            <div><div class="kicker">New reservation</div><h2 class="h5 fw-bold mb-0" data-modal-heading>Tạo đặt phòng</h2></div>
                            <button class="btn btn-light modal-close" type="button" data-close-modal aria-label="Đóng"><i class="fa fa-xmark"></i></button>
                        </div>
                        <div class="modal-body">
                        <label class="form-label fw-bold">Khách</label><input class="form-control mb-2" name="full_name" placeholder="Họ tên" required>
                        <input class="form-control mb-2" name="phone" placeholder="Điện thoại" required>
                        <input class="form-control mb-2" type="email" name="email" placeholder="Email" required>
                        <input class="form-control mb-2" name="identity_no" placeholder="CCCD/Hộ chiếu" required>
                        <input type="hidden" name="check_in" value="<?php echo h($today); ?>" data-default-value="<?php echo h($today); ?>">
                        <input type="hidden" name="check_out" value="<?php echo h($tomorrow); ?>" data-default-value="<?php echo h($tomorrow); ?>">
                        <label class="form-label fw-bold">Ngày nhận - ngày trả</label><input class="form-control mb-2" type="text" data-booking-date-range readonly value="<?php echo h(adminDate($today) . ' - ' . adminDate($tomorrow)); ?>">
                        <div class="row g-2 mt-1"><div class="col-6"><label class="form-label fw-bold">Người lớn</label><select class="form-select" name="adults" data-adults-select><?php echo adminGuestOptions(1, 3, 1, 'người lớn'); ?></select></div><div class="col-6"><label class="form-label fw-bold">Trẻ em</label><select class="form-select" name="children" data-children-select><?php echo adminGuestOptions(0, 2, 0, 'trẻ em'); ?></select></div></div>
                        <div class="text-muted small mt-1" data-occupancy-note>Chọn phòng để áp dụng số khách theo sức chứa.</div>
                        <label class="form-label fw-bold mt-2">Cơ sở</label><select class="form-select mb-2" name="booking_id_hotel" required data-booking-hotel-select><option value="">Chọn cơ sở trước</option><?php foreach ($hotels as $hotel): ?><?php if ($selectedHotelId > 0 && (int)$hotel['id_hotel'] !== $selectedHotelId) { continue; } ?><option value="<?php echo (int)$hotel['id_hotel']; ?>" <?php echo (int)$hotel['id_hotel'] === $selectedHotelId ? 'selected' : ''; ?>><?php echo h($hotel['hotel_name'] . ' - ' . $hotel['city']); ?></option><?php endforeach; ?></select>
                        <label class="form-label fw-bold mt-2">Phòng trống theo ngày và số người</label><select class="form-select mb-2" name="id_room" required data-room-select data-booking-room-select><option value="">Chọn phòng</option><?php foreach ($rooms as $room): ?><option value="<?php echo (int)$room['id_room']; ?>" data-id_hotel="<?php echo (int)$room['id_hotel']; ?>" data-capacity="<?php echo (int)$room['capacity']; ?>" data-extra_guest_rate_percent="<?php echo h((string)($room['extra_guest_rate_percent'] ?? 25)); ?>" data-status="<?php echo h($room['status']); ?>" data-booked_ranges="<?php echo h(json_encode($walkinBookedRangesByRoom[(int)$room['id_room']] ?? [], JSON_UNESCAPED_SLASHES)); ?>"><?php echo h('Phòng ' . $room['room_number'] . ' - ' . $room['room_type'] . ' - ' . (int)$room['capacity'] . ' khách - ' . money($room['price_per_night'])); ?></option><?php endforeach; ?></select>
                        <div class="text-muted small mb-2" data-booking-room-note>Chọn ngày và số người để lọc phòng trống.</div>
                        <label class="form-label fw-bold mt-2">Tính tiền</label><select class="form-select mb-2" name="pricing_mode"><option value="night">Qua đêm</option><option value="hour">Theo giờ</option></select>
                        <textarea class="form-control mb-3" name="note" rows="2" placeholder="Ghi chú"></textarea>
                        <button class="btn btn-hotel w-100">Tạo đặt phòng</button>
                        </div>
                    </form>
                </div>

            </section>

        <?php elseif ($view === 'staff'): ?>
            <section>
                <div class="panel panel-body">
                    <div class="panel-head">
                        <div><div class="kicker">Staff</div><h2 class="h5 fw-bold mb-0">Tài khoản nhân viên</h2></div>
                        <button class="btn btn-hotel btn-sm" type="button" data-open-modal="staffModal"><i class="fa fa-plus"></i> Thêm nhân viên</button>
                    </div>
                    <div class="table-responsive"><table class="table table-hover">
                        <thead><tr><th>Nhân viên</th><th>Tài khoản</th><th>Email</th><th>Vai trò</th><th>Cơ sở</th><th>Trạng thái</th><th>Lịch làm việc</th><th></th></tr></thead>
                        <tbody><?php foreach ($staff as $person): ?><tr>
                            <td class="fw-bold"><?php echo h($person['full_name']); ?></td>
                            <td><?php echo h($person['username']); ?></td>
                            <td><?php echo h($person['email']); ?></td>
                            <td><span class="status-pill status-<?php echo h(adminRoleClass($person['role'])); ?>"><?php echo h(adminRoleLabel($person['role'])); ?></span></td>
                            <td><?php echo h($person['hotel_name'] ?: 'Tất cả'); ?></td>
                            <td><span class="status-pill status-<?php echo $person['is_active'] ? 'available' : 'maintenance'; ?>"><?php echo $person['is_active'] ? 'Đang làm' : 'Tạm khóa'; ?></span></td>
                            <td class="text-muted small">Ca sáng / Ca chiều / Ca đêm</td>
                            <td><button class="btn btn-outline-dark btn-sm" type="button" data-open-modal="staffModal" data-modal-title="Chỉnh sửa nhân viên" data-id_admin="<?php echo (int)$person['id_admin']; ?>" data-username="<?php echo h($person['username']); ?>" data-full_name="<?php echo h($person['full_name']); ?>" data-email="<?php echo h($person['email']); ?>" data-role="<?php echo h($person['role']); ?>" data-id_hotel="<?php echo (int)($person['id_hotel'] ?? 0); ?>" data-is_active="<?php echo (int)$person['is_active']; ?>">Sửa</button></td>
                        </tr><?php endforeach; ?></tbody>
                    </table></div>
                </div>
                <div class="admin-modal" id="staffModal" aria-hidden="true">
                <form class="modal-panel" method="post">
                    <input type="hidden" name="action" value="save_staff">
                    <input type="hidden" name="id_admin" value="">
                    <div class="modal-head">
                        <div><div class="kicker">Permission</div><h2 class="h5 fw-bold mb-0" data-modal-heading>Thêm nhân viên</h2></div>
                        <button class="btn btn-light modal-close" type="button" data-close-modal aria-label="Đóng"><i class="fa fa-xmark"></i></button>
                    </div>
                    <div class="modal-body">
                    <label class="form-label fw-bold">Tài khoản</label><input class="form-control mb-2" name="username" required>
                    <label class="form-label fw-bold">Họ tên</label><input class="form-control mb-2" name="full_name" required>
                    <label class="form-label fw-bold">Email</label><input class="form-control mb-2" type="email" name="email">
                    <label class="form-label fw-bold">Mật khẩu</label><input class="form-control mb-2" type="password" name="password" data-required-on-create required>
                    <label class="form-label fw-bold">Phân quyền</label><select class="form-select mb-2" name="role">
                        <?php if ($currentAdminRole === 'admin'): ?><option value="admin">Admin</option><option value="manager">Quản lý</option><?php endif; ?>
                        <option value="reception">Lễ tân</option><option value="accounting">Kế toán</option><option value="housekeeping">Tạp vụ</option>
                    </select>
                    <label class="form-label fw-bold">Cơ sở phân quyền</label>
                    <?php if ($currentAdminRole === 'manager'): ?>
                        <input type="hidden" name="id_hotel" value="<?php echo (int)$currentAdminHotelId; ?>">
                        <input class="form-control mb-3" value="<?php echo h($hotels[0]['hotel_name'] ?? 'Cơ sở được gán'); ?>" disabled>
                    <?php else: ?>
                        <select class="form-select mb-3" name="id_hotel"><option value="0">Chọn cơ sở</option><?php foreach ($hotels as $hotel): ?><option value="<?php echo (int)$hotel['id_hotel']; ?>"><?php echo h($hotel['hotel_name']); ?></option><?php endforeach; ?></select>
                    <?php endif; ?>
                    <label class="form-label fw-bold">Trạng thái</label><select class="form-select mb-3" name="is_active"><option value="1">Đang làm</option><option value="0">Tạm khóa</option></select>
                    <button class="btn btn-hotel w-100">Tạo tài khoản</button>
                    </div>
                </form>
                </div>
            </section>

        <?php elseif ($view === 'invoices'): ?>
            <section>
                <div class="panel panel-body">
                    <div class="panel-head">
                        <div><div class="kicker">Payments</div><h2 class="h5 fw-bold mb-0">Hóa đơn</h2><div class="text-muted small mt-1"><?php echo h($dateRangeLabel); ?> · <?php echo count($invoiceRows); ?> hóa đơn</div></div>
                        <button class="btn btn-sm btn-outline-dark" onclick="window.print()"><i class="fa fa-print"></i> In PDF</button>
                    </div>
                    <div class="room-filter-bar">
                        <div class="mode-tabs">
                            <?php
                            $invoiceFilterBase = '&hotel=' . (int)$selectedHotelId . ($search !== '' ? '&q=' . urlencode($search) : '');
                            $invoiceTabs = [
                                'today' => ['fa-calendar-day', 'Hôm nay'],
                                'month' => ['fa-calendar-days', 'Tháng này'],
                                'prev3' => ['fa-clock-rotate-left', '3 ngày trước'],
                                'all' => ['fa-list', 'Tất cả'],
                            ];
                            ?>
                            <?php foreach ($invoiceTabs as $rangeKey => $tab): ?>
                                <?php $invoiceTabUrl = '?view=invoices&invoice_range=' . urlencode($rangeKey) . $invoiceFilterBase; ?>
                                <a class="mode-tab <?php echo $invoiceRange === $rangeKey ? 'active' : ''; ?>" href="<?php echo h($invoiceTabUrl); ?>"><i class="fa <?php echo h($tab[0]); ?>"></i> <?php echo h($tab[1]); ?></a>
                            <?php endforeach; ?>
                        </div>
                        <form class="d-flex gap-2" method="get">
                            <input type="hidden" name="view" value="invoices">
                            <input type="hidden" name="invoice_range" value="custom">
                            <input type="hidden" name="hotel" value="<?php echo (int)$selectedHotelId; ?>">
                            <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?php echo h($search); ?>"><?php endif; ?>
                            <input class="form-control" type="date" name="date_from" value="<?php echo h($invoiceRange === 'custom' ? $dateFrom : ''); ?>" title="Từ ngày">
                            <input class="form-control" type="date" name="date_to" value="<?php echo h($invoiceRange === 'custom' ? $dateTo : ''); ?>" title="Đến ngày">
                            <button class="btn btn-outline-dark"><i class="fa fa-filter"></i> Lọc</button>
                        </form>
                    </div>
                    <div class="table-responsive"><table class="table table-hover">
                        <thead><tr><th>Mã hóa đơn</th><th>Khách</th><th>Trạng thái</th><th>Thời gian xuất bill</th><th>Tổng bill</th><th>Hình thức thanh toán</th><th>Còn lại</th><th></th></tr></thead>
                        <tbody><?php foreach ($invoiceRows as $invoice): ?><?php $invoiceTotals = bookingTotals($db, $invoice); $invoicePayments = bookingPayments($db, (int)$invoice['id_booking']); $invoiceBillMeta = adminBillMeta($invoice, $invoicePayments); ?><tr>
                            <td class="fw-bold"><?php echo h(invoiceCode($invoice)); ?></td>
                            <td><?php echo h($invoice['contact_name'] ?: $invoice['full_name']); ?><div class="text-muted small"><?php echo h($invoice['contact_phone'] ?: $invoice['phone']); ?></div></td>
                            <td><span class="status-pill status-<?php echo h($invoice['status']); ?>"><?php echo h(adminBookingStatusLabel($invoice['status'])); ?></span></td>
                            <td><?php echo h(adminDateTime($invoiceBillMeta['issued_at'])); ?><div class="text-muted small">Nhân viên: <?php echo h($invoiceBillMeta['cashier_name'] ?: '-'); ?></div></td>
                            <td class="fw-bold"><?php echo money($invoiceTotals['grand']); ?><div class="text-muted small">Đã gồm VAT</div></td>
                            <td>
                                <?php $invoicePaymentTotals = paymentMethodTotals($invoicePayments); ?>
                                <?php if ($invoicePaymentTotals): ?>
                                    <?php foreach ($invoicePaymentTotals as $method => $amount): ?>
                                        <div><span class="text-muted"><?php echo h($method); ?>:</span> <strong><?php echo money($amount); ?></strong></div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="fw-bold <?php echo $invoiceTotals['debt'] > 0 ? 'text-danger' : 'text-success'; ?>"><?php echo money($invoiceTotals['debt']); ?></td>
                            <td><a class="btn btn-outline-dark btn-sm" href="?view=invoices&booking=<?php echo (int)$invoice['id_booking']; ?>&hotel=<?php echo (int)$selectedHotelId; ?>">Xem lại bill</a></td>
                        </tr><?php endforeach; ?></tbody>
                    </table></div>
                    <?php if ($selectedBooking): ?><?php
                        $invoiceViewTotals = bookingTotals($db, $selectedBooking);
                        $invoiceViewServices = bookingServices($db, (int)$selectedBooking['id_booking']);
                        $invoiceViewPayments = bookingPayments($db, (int)$selectedBooking['id_booking']);
                        $invoiceViewPaymentTotals = paymentMethodTotals($invoiceViewPayments);
                        $invoiceViewBillMeta = adminBillMeta($selectedBooking, $invoiceViewPayments);
                        $invoiceViewTier = memberTier(guestTotalPaid($db, (int)$selectedBooking['id_guest']));
                        $invoiceViewHourly = ($selectedBooking['pricing_mode'] ?? 'night') === 'hour';
                        $invoiceViewStart = $selectedBooking['check_in_at'] ?: ($selectedBooking['check_in'] . ' 14:00:00');
                        $invoiceViewEnd = bookingBillingEnd($selectedBooking);
                        $invoiceViewRoomBaseTotal = $invoiceViewTotals['room'] - $invoiceViewTotals['late_fee'] - $invoiceViewTotals['extra_guest_fee'];
                        $invoiceViewUnitPrice = $invoiceViewHourly ? (float)$selectedBooking['hourly_rate'] : (float)$selectedBooking['price_per_night'];
                        $invoiceViewUnitText = $invoiceViewHourly ? 'giờ' : 'đêm';
                    ?>
                        <div class="admin-modal" id="invoiceModal" aria-hidden="true">
                            <div class="modal-panel invoice-bill" id="printInvoice">
                                <div class="modal-head no-print">
                                    <div><div class="kicker">Invoice</div><h2 class="h5 fw-bold mb-0">Receipt</h2></div>
                                    <button class="btn btn-light modal-close" type="button" data-close-modal aria-label="Đóng"><i class="fa fa-xmark"></i></button>
                                </div>
                                <div class="modal-body">
                                    <div class="invoice-sheet">
                                        <div class="invoice-title">
                                            <div class="invoice-brand">
                                                <div class="invoice-logo"><i class="fa fa-hotel"></i></div>
                                                <div>
                                                    <div class="kicker">Spotki Hotels</div>
                                                    <h2>Hóa đơn thanh toán</h2>
                                                    <div class="text-muted fw-bold"><?php echo h($selectedBooking['hotel_name'] ?? 'Spotki Hotel'); ?></div>
                                                    <div class="small text-muted"><?php echo h(trim(($selectedBooking['hotel_address'] ?? '') . ', ' . ($selectedBooking['hotel_city'] ?? ''), ', ')); ?></div>
                                                    <div class="small text-muted"><?php echo h(trim(($selectedBooking['hotel_phone'] ?? '') . ' | ' . ($selectedBooking['hotel_email'] ?? ''), ' |')); ?></div>
                                                    <div class="mt-2"><span class="status-pill status-<?php echo $invoiceViewTotals['debt'] <= 0 ? 'available' : 'occupied'; ?>"><?php echo $invoiceViewTotals['debt'] <= 0 ? 'Đã thanh toán' : 'Chưa thanh toán đủ'; ?></span></div>
                                                </div>
                                            </div>
                                            <div class="invoice-code">
                                                Mã hóa đơn
                                                <strong><?php echo h(invoiceCode($selectedBooking)); ?></strong>
                                                <div>Booking- <?php echo h($selectedBooking['booking_code']); ?></div>
                                                <div>Giờ ra bill <?php echo h(adminDateTime($invoiceViewBillMeta['issued_at'])); ?></div>
                                                <div>Nhân viên:  <?php echo h($invoiceViewBillMeta['cashier_name'] ?: '-'); ?></div>
                                                <button class="btn btn-outline-dark btn-sm no-print mt-2" onclick="window.print()"><i class="fa fa-print"></i> In bill</button>
                                            </div>
                                        </div>
                                        <div class="invoice-info-grid invoice-stack">
                                            <div class="invoice-box">
                                                <div class="invoice-box-title">Thông tin khách</div>
                                                <div class="invoice-row"><span>Tên khách</span><strong><?php echo h($selectedBooking['contact_name'] ?: $selectedBooking['full_name']); ?></strong></div>
                                                <div class="invoice-row"><span>SĐT</span><strong><?php echo h($selectedBooking['contact_phone'] ?: $selectedBooking['phone']); ?></strong></div>
                                                <div class="invoice-row"><span>Hạng member</span><strong><?php echo h(memberTierLabel($invoiceViewTier)); ?></strong></div>
                                                <div class="invoice-row"><span>Điểm hiện có</span><strong><span class="point-badge"><i class="fa fa-coins"></i><?php echo h(points($selectedBooking['loyalty_points'] ?? 0)); ?></span></strong></div>
                                            </div>
                                            <div class="invoice-box">
                                                <div class="invoice-box-title">Thông tin lưu trú</div>
                                                <div class="invoice-row"><span>Phòng</span><strong><?php echo h($selectedBooking['room_number'] . ' - ' . $selectedBooking['room_type']); ?></strong></div>
                                                <div class="invoice-row"><span>Giờ vào</span><strong><?php echo h(adminDateTime($invoiceViewStart)); ?></strong></div>
                                                <div class="invoice-row"><span>Giờ ra</span><strong><?php echo h(adminDateTime($invoiceViewEnd)); ?></strong></div>
                                            </div>
                                        </div>
                                        <table class="invoice-table">
                                            <thead><tr><th>Hạng mục</th><th>Đơn giá</th><th>Số lượng</th><th class="text-end">Thành tiền</th></tr></thead>
                                            <tbody>
                                                <tr><td><strong><?php echo $invoiceViewHourly ? 'Tiền phòng theo giờ' : 'Tiền phòng qua đêm'; ?></strong></td><td><?php echo money($invoiceViewUnitPrice); ?>/<?php echo h($invoiceViewUnitText); ?></td><td><?php echo h($invoiceViewTotals['unit_label']); ?></td><td class="money-cell"><?php echo money($invoiceViewRoomBaseTotal); ?></td></tr>
                                                <?php if ($invoiceViewTotals['extra_guest_fee'] > 0): ?><tr><td><strong>Phụ thu thêm người</strong><div class="invoice-note">Vượt <?php echo (int)$invoiceViewTotals['extra_guests']; ?> người so với sức chứa phòng.</div></td><td><?php echo rtrim(rtrim(number_format((float)($invoiceViewTotals['extra_guest_rate_percent'] ?? 25), 2, ',', '.'), '0'), ','); ?>% tiền phòng</td><td>1</td><td class="money-cell text-danger"><?php echo money($invoiceViewTotals['extra_guest_fee']); ?></td></tr><?php endif; ?>
                                                <?php if (!$invoiceViewHourly): ?>
                                                    <tr><td><strong>Phụ thu check-out muộn</strong></td><td><?php echo money((float)$selectedBooking['hourly_rate']); ?>/giờ</td><td><?php echo (int)$invoiceViewTotals['late_hours']; ?> giờ</td><td class="money-cell <?php echo $invoiceViewTotals['late_fee'] > 0 ? 'text-danger' : ''; ?>"><?php echo money($invoiceViewTotals['late_fee']); ?></td></tr>
                                                <?php endif; ?>
                                                <?php foreach ($invoiceViewServices as $item): ?>
                                                    <tr><td><strong><?php echo h($item['service_name']); ?></strong></td><td><?php echo money($item['price']); ?>/<?php echo h($item['unit']); ?></td><td><?php echo (int)$item['quantity']; ?></td><td class="money-cell"><?php echo money((float)$item['price'] * (int)$item['quantity']); ?></td></tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                        <div class="invoice-summary">
                                            <div class="invoice-total-box">
                                                <div class="invoice-total-row"><span>Tiền phòng</span><strong><?php echo money($invoiceViewRoomBaseTotal); ?></strong></div>
                                                <?php if ($invoiceViewTotals['extra_guest_fee'] > 0): ?><div class="invoice-total-row"><span>Phụ thu thêm người</span><strong><?php echo money($invoiceViewTotals['extra_guest_fee']); ?></strong></div><?php endif; ?>
                                                <?php if (!$invoiceViewHourly): ?><div class="invoice-total-row"><span>Trả phòng muộn</span><strong><?php echo money($invoiceViewTotals['late_fee']); ?></strong></div><?php endif; ?>
                                                <div class="invoice-total-row"><span>Đồ ăn & đồ uống</span><strong><?php echo money($invoiceViewTotals['service']); ?></strong></div>
                                                <div class="invoice-total-row"><span>Tạm tính</span><strong><?php echo money($invoiceViewTotals['subtotal']); ?></strong></div>
                                                <div class="invoice-total-row"><span>VAT 8%</span><strong><?php echo money($invoiceViewTotals['vat']); ?></strong></div>
                                                <div class="invoice-total-row grand"><span>Tổng cộng</span><strong><?php echo money($invoiceViewTotals['grand']); ?></strong></div>
                                                <?php foreach ($invoiceViewPaymentTotals as $method => $amount): ?>
                                                    <div class="invoice-total-row payment-method"><span><?php echo h($method); ?></span><strong><?php echo money($amount); ?></strong></div>
                                                <?php endforeach; ?>
                                                <div class="invoice-total-row debt"><span>Còn phải thu</span><strong><?php echo money($invoiceViewTotals['debt']); ?></strong></div>
                                            </div>
                                        </div>
                                        <?php if ($invoiceViewPayments): ?>
                                            <div class="invoice-box">
                                                <div class="invoice-box-title">Lịch sử thanh toán</div>
                                                <table class="invoice-table">
                                                    <thead><tr><th>Hình thức</th><th>Thời gian</th><th>Nhân viên</th><th class="text-end">Số tiền</th></tr></thead>
                                                    <tbody>
                                                        <?php foreach ($invoiceViewPayments as $payment): ?>
                                                            <tr>
                                                                <td>
                                                                    <strong><?php echo h(paymentMethodLabel((string)$payment['method'])); ?></strong>
                                                                    <?php if (trim((string)($payment['note'] ?? '')) !== ''): ?><div class="invoice-note"><?php echo h($payment['note']); ?></div><?php endif; ?>
                                                                </td>
                                                                <td><?php echo h(adminDateTime($payment['paid_at'] ?? null)); ?></td>
                                                                <td><?php echo h(trim((string)($payment['cashier_name'] ?? '')) ?: '-'); ?></td>
                                                                <td class="money-cell"><?php echo money((float)$payment['amount']); ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

<?php elseif ($view === 'reports'): ?>
    <section class="layout">

        <!-- CỘT TRÁI: BÁO CÁO BOOKING THEO PHÒNG -->
        <div>
            <div class="panel panel-body mb-3">
                <div class="panel-head">
                    <div>
                        <div class="kicker">Room Booking Report</div>
                        <h2 class="h5 fw-bold mb-0">Báo cáo chi tiết</h2>
                        <div class="text-muted small mt-1"><?php echo h($dateRangeLabel); ?></div>
                    </div>
                    <button class="btn btn-sm btn-outline-dark" type="button" onclick="window.print()">
                        Export PDF
                    </button>
                </div>

                <form class="row g-2 align-items-end mb-3" method="get">
                    <input type="hidden" name="view" value="reports">

                    <div class="col-md-3">
                        <label class="form-label fw-bold">Lọc theo cơ sở</label>

                        <select class="form-select" name="hotel" onchange="this.form.submit()" <?php echo $currentAdminRole !== 'admin' ? 'disabled' : ''; ?>>
                            <option value="0">Tất cả cơ sở</option>

                            <?php foreach ($hotels as $hotel): ?>
                                <option value="<?php echo (int)$hotel['id_hotel']; ?>" <?php echo (int)$hotel['id_hotel'] === $selectedHotelId ? 'selected' : ''; ?>>
                                    <?php echo h($hotel['hotel_name'] . ' - ' . $hotel['city']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <?php if ($currentAdminRole !== 'admin'): ?>
                            <input type="hidden" name="hotel" value="<?php echo (int)$selectedHotelId; ?>">
                        <?php endif; ?>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-bold">Khoảng thời gian</label>
                        <select class="form-select" name="report_range">
                            <option value="all" <?php echo $reportRange === 'all' ? 'selected' : ''; ?>>Tất cả thời gian</option>
                            <option value="today" <?php echo $reportRange === 'today' ? 'selected' : ''; ?>>Hôm nay</option>
                            <option value="month" <?php echo $reportRange === 'month' ? 'selected' : ''; ?>>Tháng này</option>
                            <option value="custom" <?php echo $reportRange === 'custom' ? 'selected' : ''; ?>>Tùy chọn ngày</option>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label fw-bold">Từ ngày</label>
                        <input class="form-control" type="date" name="date_from" value="<?php echo h($reportRange === 'custom' ? $dateFrom : ''); ?>" onchange="this.form.report_range.value='custom'">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label fw-bold">Đến ngày</label>
                        <input class="form-control" type="date" name="date_to" value="<?php echo h($reportRange === 'custom' ? $dateTo : ''); ?>" onchange="this.form.report_range.value='custom'">
                    </div>

                    <div class="col-md-2 d-grid">
                        <button class="btn btn-hotel" type="submit">
                            <i class="fa fa-filter"></i> Lọc báo cáo
                        </button>
                    </div>
                </form>

                <div class="module-grid mb-3">
    <div class="module-card">
        <i class="fa fa-bed"></i>
        <strong><?php echo count($roomBookingRows); ?></strong>
        <div class="text-muted">Phòng đang thống kê</div>
    </div>

    <div class="module-card">
        <i class="fa fa-calendar-check"></i>
        <strong><?php echo (int)$totalRoomBooking; ?></strong>
        <div class="text-muted">Tổng lượt booking</div>
    </div>

    <div class="module-card">
        <i class="fa fa-money-bill-wave"></i>
        <strong><?php echo money($totalRoomRevenue); ?></strong>
        <div class="text-muted">Tổng doanh thu phòng</div>
    </div>

</div>

                <?php if ($roomBookingRows): ?>
                    <div class="chart mb-3">
                    <?php foreach (array_slice($roomBookingRows, 0, 12) as $roomReport): ?>
        <div class="bar">
            <div class="small fw-bold text-danger text-center">
                <?php echo money($roomReport['total_revenue']); ?>
            </div>

            <div 
                class="bar-fill" 
                style="height:<?php echo max(8, round(((float)$roomReport['total_revenue'] / $maxRoomRevenue) * 150)); ?>px">
            </div>

            <div class="bar-label">
                P.<?php echo h($roomReport['room_number']); ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Phòng</th>
                                    <th>Loại phòng</th>
                                    <th>Cơ sở</th>
                                    <th class="text-end">booking</th>
                                    <th class="text-end">Doanh thu</th>
                                    <th class="text-end">Đã đặt</th>
                                    <th class="text-end">Đã trả</th>
                                    <th class="text-end">Đã hủy</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($roomBookingRows as $roomReport): ?>
                                    <tr>
                                        <td class="fw-bold">
                                            Phòng <?php echo h($roomReport['room_number']); ?>
                                        </td>

                                        <td>
                                            <?php echo h($roomReport['room_type']); ?>
                                        </td>

                                        <td>
                                            <?php 
                                                echo h(
                                                    ($roomReport['hotel_name'] ?? 'Chưa gán cơ sở') 
                                                    . (!empty($roomReport['hotel_city']) ? ' - ' . $roomReport['hotel_city'] : '')
                                                ); 
                                            ?>
                                        </td>

                                        <td class="text-center fw-bold">
                                            <?php echo (int)$roomReport['total_booking']; ?>
                                        </td>

                                        <td class="text-center fw-bold text-danger">
                                            <?php echo money($roomReport['total_revenue']); ?>

                                        </td>
                                        <td class="text-center">
                                            <?php echo (int)$roomReport['booked_count']; ?>
                                        </td>

                                        <td class="text-center">
                                            <?php echo (int)$roomReport['checked_out_count']; ?>
                                        </td>

                                        <td class="text-center text-muted">
                                            <?php echo (int)$roomReport['cancelled_count']; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-light border mb-0">
                        Chưa có phòng nào để thống kê trong cơ sở đã chọn.
                    </div>
                <?php endif; ?>
            </div>
        </div>


        <!-- CỘT PHẢI: TOP KHÁCH VÀ DỊCH VỤ GIỮ NGUYÊN Ở TRÊN -->
        <div>
            <div class="panel panel-body mb-3">
                <div class="kicker">Top guests</div>
                <h2 class="h5 fw-bold mb-3">Khách hàng thân thiết nhất</h2>

                <?php if ($topGuests): ?>
                    <?php foreach ($topGuests as $guest): ?>
                        <div class="operation-item mb-2">
                            <span>
                                <?php echo h($guest['full_name']); ?>
                                <div class="text-muted small">
                                    <?php echo h($guest['phone']); ?>
                                </div>
                            </span>

                            <strong>
                                <?php echo (int)$guest['stays']; ?> lượt booking
                            </strong>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-muted">
                        Chưa có dữ liệu khách hàng.
                    </div>
                <?php endif; ?>
            </div>

            <div class="panel panel-body">
                <div class="kicker">Best sellers</div>
                <h2 class="h5 fw-bold mb-3">Đồ ăn thức uống bán chạy</h2>

                <?php if ($topServices): ?>
                    <?php foreach ($topServices as $service): ?>
                        <div class="operation-item mb-2">
                            <span>
                                <?php echo h($service['service_name']); ?>
                            </span>

                            <strong>
                                <?php echo (int)$service['qty']; ?>
                            </strong>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-muted">
                        Chưa có dữ liệu dịch vụ.
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </section>
            

        <?php elseif ($view === 'website'): ?>
            <section>
                <div class="panel panel-body">
                    <div class="panel-head">
                        <div><div class="kicker">CMS</div><h2 class="h5 fw-bold mb-0">Banner, tin tức, slider, gallery, review</h2></div>
                        <button class="btn btn-hotel btn-sm" type="button" data-open-modal="contentModal"><i class="fa fa-plus"></i> Thêm nội dung</button>
                    </div>
                    <div class="table-responsive"><table class="table table-hover">
                        <thead><tr><th>Loại</th><th>Tiêu đề</th><th>Ảnh</th><th>Trạng thái</th><th></th></tr></thead>
                        <tbody><?php foreach ($websiteContents as $content): ?><tr><td><?php echo h($content['content_type']); ?></td><td class="fw-bold"><?php echo h($content['title']); ?></td><td class="text-muted small"><?php echo h($content['image_url']); ?></td><td><span class="status-pill status-<?php echo !empty($content['is_active']) ? 'available' : 'maintenance'; ?>"><?php echo !empty($content['is_active']) ? 'Hiển thị' : 'Ẩn'; ?></span></td><td><button class="btn btn-outline-dark btn-sm" type="button" data-open-modal="contentModal" data-modal-title="Chỉnh sửa nội dung" data-id_content="<?php echo (int)$content['id_content']; ?>" data-content_type="<?php echo h($content['content_type']); ?>" data-title="<?php echo h($content['title']); ?>" data-image_url="<?php echo h($content['image_url']); ?>" data-body="<?php echo h($content['body']); ?>" data-is_active="<?php echo (int)$content['is_active']; ?>">Sửa</button></td></tr><?php endforeach; ?></tbody>
                    </table></div>
                </div>
                <div class="admin-modal" id="contentModal" aria-hidden="true">
                <form class="modal-panel" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_web_content">
                    <input type="hidden" name="id_content" value="">
                    <div class="modal-upload-shortcut">
                        <label class="form-label fw-bold">Chon anh tu may</label>
                        <input class="form-control mb-2" type="file" name="content_image_file" accept="image/*">
                    </div>
                    <div class="modal-head">
                        <div><div class="kicker">Website content</div><h2 class="h5 fw-bold mb-0" data-modal-heading>Thêm nội dung</h2></div>
                        <button class="btn btn-light modal-close" type="button" data-close-modal aria-label="Đóng"><i class="fa fa-xmark"></i></button>
                    </div>
                    <div class="modal-body">
                    <label class="form-label fw-bold">Loại nội dung</label><select class="form-select mb-2" name="content_type"><option value="banner">Banner</option><option value="news">Tin tức</option><option value="slider">Slider</option><option value="gallery">Gallery ảnh</option><option value="review">Review khách hàng</option><option value="promotion">Khuyến mãi</option></select>
                    <label class="form-label fw-bold">Tiêu đề</label><input class="form-control mb-2" name="title" required>
                    <label class="form-label fw-bold">Ảnh</label><input class="form-control mb-2" name="image_url" placeholder="https://...">
                    <label class="form-label fw-bold">Nội dung</label><textarea class="form-control mb-3" name="body" rows="4"></textarea>
                    <label class="form-label fw-bold">Trạng thái</label><select class="form-select mb-3" name="is_active"><option value="1">Hiển thị</option><option value="0">Ẩn</option></select>
                    <button class="btn btn-hotel w-100">Lưu nội dung</button>
                    </div>
                </form>
                </div>
            </section>

        <?php elseif ($view === 'settings'): ?>
            <section class="panel panel-body">
                <div class="panel-head"><div><div class="kicker">Security</div><h2 class="h5 fw-bold mb-0">Bảo mật</h2></div></div>
                <div class="module-grid">
                    <div class="module-card"><i class="fa fa-right-to-bracket"></i><h3 class="h6 fw-bold">Đăng nhập phiên</h3><p class="text-muted mb-0">Admin đang dùng session PHP và bắt buộc đăng nhập qua `requireAdmin()`.</p></div>
                    <div class="module-card"><i class="fa fa-lock"></i><h3 class="h6 fw-bold">Mã hóa mật khẩu</h3><p class="text-muted mb-0">Mật khẩu nhân viên lưu bằng `password_hash()`.</p></div>
                    <div class="module-card"><i class="fa fa-user-shield"></i><h3 class="h6 fw-bold">Phân quyền vai trò</h3><p class="text-muted mb-0">Vai trò hiện có: Admin, Lễ tân, Kế toán, Quản lý và Tạp vụ.</p></div>
                    <div class="module-card"><i class="fa fa-clipboard-list"></i><h3 class="h6 fw-bold">Log hoạt động</h3><p class="text-muted mb-0">Các sự kiện đăng nhập, CSRF và thao tác quan trọng được ghi vào security_logs.</p></div>
                    <div class="module-card"><i class="fa fa-envelope-circle-check"></i><h3 class="h6 fw-bold">OTP/email</h3><p class="text-muted mb-0">Sẵn chỗ để tích hợp SMTP hoặc OTP khi triển khai thật.</p></div>
                    <div class="module-card"><i class="fa fa-code"></i><h3 class="h6 fw-bold">Công nghệ</h3><p class="text-muted mb-0">PHP, MySQL, Bootstrap 5, JavaScript. Có thể nâng cấp Laravel sau.</p></div>
                </div>
            </section>
        <?php elseif ($view === 'services'): ?>
            <section class="panel panel-body">
                <div class="panel-head">
                    <div><div class="kicker">Room services</div><h2 class="h5 fw-bold mb-0">Đồ ăn thức uống trong phòng</h2></div>
                    <button class="btn btn-hotel btn-sm" type="button" data-open-modal="serviceModal"><i class="fa fa-plus"></i> Thêm item</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead><tr><th>Tên item</th><th>Đơn vị</th><th>Giá</th><th>Trạng thái</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($services as $service): ?>
                            <tr>
                                <td class="fw-bold"><?php echo h($service['service_name']); ?></td>
                                <td><?php echo h($service['unit']); ?></td>
                                <td class="fw-bold"><?php echo money($service['price']); ?></td>
                                <td><span class="status-pill status-<?php echo !empty($service['is_active']) ? 'available' : 'maintenance'; ?>"><?php echo !empty($service['is_active']) ? 'Đang bán' : 'Tạm ẩn'; ?></span></td>
                                <td><button class="btn btn-outline-dark btn-sm" type="button" data-open-modal="serviceModal" data-modal-title="Chỉnh sửa item" data-id_service="<?php echo (int)$service['id_service']; ?>" data-service_name="<?php echo h($service['service_name']); ?>" data-unit="<?php echo h($service['unit']); ?>" data-price="<?php echo h((string)$service['price']); ?>" data-is_active="<?php echo (int)$service['is_active']; ?>">Sửa</button></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$services): ?><tr><td colspan="5" class="text-center text-muted py-4">Chưa có item nào.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <div class="admin-modal" id="serviceModal" aria-hidden="true">
                <form class="modal-panel" method="post">
                    <input type="hidden" name="action" value="save_service">
                    <input type="hidden" name="id_service" value="">
                    <div class="modal-head"><div><div class="kicker">New item</div><h2 class="h5 fw-bold mb-0" data-modal-heading>Thêm item</h2></div><button class="btn btn-light modal-close" type="button" data-close-modal aria-label="Đóng"><i class="fa fa-xmark"></i></button></div>
                    <div class="modal-body">
                        <label class="form-label fw-bold">Tên item</label><input class="form-control mb-2" name="service_name" required>
                        <label class="form-label fw-bold">Đơn vị</label><input class="form-control mb-2" name="unit" value="lon">
                        <label class="form-label fw-bold">Giá</label><input class="form-control mb-2" type="number" name="price" min="0" required>
                        <label class="form-label fw-bold">Trạng thái</label><select class="form-select mb-3" name="is_active"><option value="1">Đang bán</option><option value="0">Tạm ẩn</option></select>
                        <button class="btn btn-hotel w-100">Lưu item</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
const freeChildrenForCapacity = (capacity) => {
    if (capacity >= 4) {
        return 2;
    }
    if (capacity >= 2) {
        return 1;
    }
    return 0;
};

const fillGuestSelect = (select, min, max, selected, label) => {
    if (!select) {
        return;
    }
    const safeSelected = Math.min(Math.max(Number(selected || min), min), max);
    select.innerHTML = '';
    for (let value = min; value <= max; value += 1) {
        const option = document.createElement('option');
        option.value = String(value);
        option.textContent = `${value} ${label}`;
        option.selected = value === safeSelected;
        select.appendChild(option);
    }
};

const updateOccupancyControls = (form) => {
    const roomSelect = form.querySelector('[data-room-select]');
    const selectedRoom = roomSelect ? roomSelect.selectedOptions[0] : null;
    const capacityField = form.elements.capacity;
    const capacity = Math.max(1, Number(selectedRoom?.dataset.capacity || capacityField?.value || 2));
    const extraGuestRate = Math.max(0, Number(selectedRoom?.dataset.extra_guest_rate_percent || form.elements.extra_guest_rate_percent?.value || 25));
    const freeChildren = freeChildrenForCapacity(capacity);
    const adultsSelect = form.querySelector('[data-adults-select]');
    const childrenSelect = form.querySelector('[data-children-select]');
    const currentAdults = Number(adultsSelect?.value || 1);
    const currentChildren = Number(childrenSelect?.value || 0);
    const maxAdults = capacity + 1;
    const boundedAdults = Math.min(Math.max(currentAdults, 1), maxAdults);
    const maxChildren = Math.max(0, freeChildren + maxAdults - boundedAdults);
    fillGuestSelect(adultsSelect, 1, maxAdults, boundedAdults, 'người lớn');
    fillGuestSelect(childrenSelect, 0, maxChildren, currentChildren, 'trẻ em');
    const note = form.querySelector('[data-occupancy-note]');
    if (note) {
        note.textContent = `Phòng ${capacity} người miễn phí ${freeChildren} em bé; vượt tối đa 1 người sẽ phụ thu ${extraGuestRate}% tiền phòng.`;
    }
};

const walkinDatePickers = new WeakMap();
const addDateDays = (dateText, days) => {
    const date = new Date(`${dateText}T00:00:00`);
    date.setDate(date.getDate() + days);
    return date.toISOString().slice(0, 10);
};
const tomorrowDateText = () => {
    const date = new Date();
    date.setDate(date.getDate() + 1);
    return date.toISOString().slice(0, 10);
};
const walkinBookedRanges = (form) => {
    const roomSelect = form.querySelector('[data-room-select]');
    const selectedRoom = roomSelect ? roomSelect.selectedOptions[0] : null;
    const raw = selectedRoom?.getAttribute('data-booked_ranges') || form.elements.booked_ranges?.value || '[]';
    try {
        return JSON.parse(raw) || [];
    } catch (error) {
        return [];
    }
};
const datesOverlap = (startDate, endDate, rangeStart, rangeEnd) => startDate < rangeEnd && endDate > rangeStart;
const optionBookedRanges = (option) => {
    const raw = option?.getAttribute('data-booked_ranges') || '[]';
    try {
        return JSON.parse(raw) || [];
    } catch (error) {
        return [];
    }
};
const bookingRoomAvailability = (option, form) => {
    const checkIn = form.elements.check_in?.value || '';
    const checkOut = form.elements.check_out?.value || '';
    const currentBookingId = Number(form.elements.id_booking?.value || 0);
    const adults = Math.max(1, Number(form.elements.adults?.value || 1));
    const children = Math.max(0, Number(form.elements.children?.value || 0));
    const capacity = Math.max(1, Number(option.dataset.capacity || 1));
    const freeChildren = freeChildrenForCapacity(capacity);
    const extraGuests = Math.max(0, adults + Math.max(0, children - freeChildren) - capacity);
    const invalidGuests = extraGuests > 1;
    const isMaintenance = option.dataset.status === 'maintenance';
    const isBooked = checkIn !== '' && checkOut !== '' && optionBookedRanges(option).some((range) => {
        if (currentBookingId > 0 && Number(range.id || 0) === currentBookingId) {
            return false;
        }
        return datesOverlap(checkIn, checkOut, range.from, range.to);
    });
    const invalidDateRange = checkIn !== '' && checkOut !== '' && checkOut <= checkIn;
    return {
        unavailable: invalidGuests || isMaintenance || isBooked || invalidDateRange,
        invalidGuests,
        isMaintenance,
        isBooked,
        invalidDateRange,
    };
};
const updateBookingRoomOptions = (form) => {
    const roomSelect = form.querySelector('[data-booking-room-select]');
    if (!roomSelect) {
        return;
    }
    const hotelSelect = form.querySelector('[data-booking-hotel-select]');
    const checkIn = form.elements.check_in?.value || '';
    const checkOut = form.elements.check_out?.value || '';
    let selectedValue = roomSelect.value;
    let selectedHotelId = hotelSelect?.value || '';
    if (!selectedHotelId && selectedValue) {
        const selectedOption = Array.from(roomSelect.options).find((option) => option.value === selectedValue);
        selectedHotelId = selectedOption?.dataset.id_hotel || '';
        if (hotelSelect && selectedHotelId) {
            hotelSelect.value = selectedHotelId;
            selectedHotelId = hotelSelect.value;
        }
    }
    const requiresHotel = Boolean(hotelSelect);
    const availableRoomsByHotel = {};
    Array.from(roomSelect.options).forEach((option) => {
        if (!option.value) {
            return;
        }
        if (!option.dataset.defaultLabel) {
            option.dataset.defaultLabel = option.textContent;
        }
        if (!bookingRoomAvailability(option, form).unavailable) {
            const hotelId = option.dataset.id_hotel || '';
            availableRoomsByHotel[hotelId] = (availableRoomsByHotel[hotelId] || 0) + 1;
        }
    });
    let availableHotelCount = 0;
    if (hotelSelect) {
        Array.from(hotelSelect.options).forEach((option) => {
            if (!option.value) {
                return;
            }
            const hasAvailableRooms = (availableRoomsByHotel[option.value] || 0) > 0;
            option.disabled = !hasAvailableRooms;
            option.hidden = !hasAvailableRooms;
            if (hasAvailableRooms) {
                availableHotelCount += 1;
            }
        });
        if (selectedHotelId && (availableRoomsByHotel[selectedHotelId] || 0) <= 0) {
            hotelSelect.value = '';
            selectedHotelId = '';
            roomSelect.value = '';
            selectedValue = '';
        }
    }
    roomSelect.disabled = requiresHotel && !selectedHotelId;
    let availableCount = 0;
    Array.from(roomSelect.options).forEach((option) => {
        if (!option.value) {
            return;
        }
        if (!option.dataset.defaultLabel) {
            option.dataset.defaultLabel = option.textContent;
        }
        const hotelMismatch = requiresHotel && (!selectedHotelId || option.dataset.id_hotel !== selectedHotelId);
        const availability = bookingRoomAvailability(option, form);
        const unavailable = hotelMismatch || availability.unavailable;
        option.disabled = unavailable;
        option.hidden = unavailable;
        let unavailableReason = '';
        if (!hotelMismatch) {
            if (availability.invalidGuests) {
                unavailableReason = ' - không đủ sức chứa';
            } else if (availability.isMaintenance) {
                unavailableReason = ' - bảo trì';
            } else if (availability.isBooked) {
                unavailableReason = ' - đã có booking';
            } else if (availability.invalidDateRange) {
                unavailableReason = ' - ngày không hợp lệ';
            }
        }
        option.textContent = option.dataset.defaultLabel + unavailableReason;
        if (!unavailable) {
            availableCount += 1;
        }
    });
    if (selectedValue && roomSelect.selectedOptions[0]?.disabled) {
        roomSelect.value = '';
    }
    const note = form.querySelector('[data-booking-room-note]');
    if (note) {
        note.textContent = requiresHotel && availableHotelCount === 0
            ? 'Không có cơ sở nào còn phòng phù hợp.'
            : requiresHotel && !selectedHotelId
            ? 'Chọn cơ sở trước để xem danh sách phòng trống.'
            : checkIn && checkOut
            ? `${availableCount} phòng trống phù hợp ngày và số người đã chọn.`
            : 'Chọn ngày và số người để lọc phòng trống.';
    }
    updateOccupancyControls(form);
};
const formatDateLabel = (date) => `${String(date.getDate()).padStart(2, '0')}/${String(date.getMonth() + 1).padStart(2, '0')}/${date.getFullYear()}`;
const configureBookingDateRange = (form) => {
    const input = form.querySelector('[data-booking-date-range]');
    const checkInField = form.elements.check_in;
    const checkOutField = form.elements.check_out;
    if (!input || !checkInField || !checkOutField || !window.flatpickr) {
        return;
    }
    if (input._flatpickr) {
        input._flatpickr.destroy();
    }
    flatpickr(input, {
        mode: 'range',
        dateFormat: 'Y-m-d',
        minDate: 'today',
        defaultDate: [checkInField.value, checkOutField.value],
        onReady: (selectedDates) => {
            if (selectedDates.length === 2) {
                input.value = `${formatDateLabel(selectedDates[0])} - ${formatDateLabel(selectedDates[1])}`;
            }
        },
        onChange: (selectedDates, dateStr, instance) => {
            if (selectedDates.length !== 2) {
                return;
            }
            checkInField.value = instance.formatDate(selectedDates[0], 'Y-m-d');
            checkOutField.value = instance.formatDate(selectedDates[1], 'Y-m-d');
            input.value = `${formatDateLabel(selectedDates[0])} - ${formatDateLabel(selectedDates[1])}`;
            updateBookingRoomOptions(form);
        },
    });
};
const configureWalkinCheckout = (form) => {
    const input = form.querySelector('[data-walkin-checkout]');
    if (!input || !window.flatpickr) {
        return;
    }
    const minDate = tomorrowDateText();
    const ranges = walkinBookedRanges(form);
    const futureRanges = ranges.filter((range) => range.from >= minDate).sort((a, b) => a.from.localeCompare(b.from));
    const firstBlocked = futureRanges[0]?.from || null;
    const disabledRanges = futureRanges.map((range) => ({
        from: addDateDays(range.from, 1),
        to: range.to,
    }));
    const currentPicker = walkinDatePickers.get(input);
    if (currentPicker) {
        currentPicker.destroy();
    }
    const picker = flatpickr(input, {
        dateFormat: 'Y-m-d',
        minDate,
        maxDate: firstBlocked || null,
        defaultDate: input.value && input.value >= minDate ? input.value : minDate,
        disable: disabledRanges,
    });
    walkinDatePickers.set(input, picker);
};
const updateWalkinStayMode = (form) => {
    const mode = form.querySelector('[data-walkin-mode]')?.value || 'hour';
    form.querySelectorAll('[data-hour-stay-field]').forEach((field) => field.classList.toggle('d-none', mode !== 'hour'));
    form.querySelectorAll('[data-night-stay-field]').forEach((field) => field.classList.toggle('d-none', mode !== 'night'));
    if (mode === 'night') {
        configureWalkinCheckout(form);
    }
};

const fillModalForm = (modal, trigger) => {
    const form = modal.querySelector('form');
    if (!form) {
        return;
    }
    form.reset();
    form.querySelectorAll('input[type="hidden"]').forEach((input) => {
        if (input.name !== 'action' && input.name !== 'return_url' && input.name !== 'csrf_token') {
            input.value = '';
        }
    });

    const heading = modal.querySelector('[data-modal-heading]');
    if (heading) {
        if (!heading.dataset.defaultText) {
            heading.dataset.defaultText = heading.textContent;
        }
        heading.textContent = trigger.dataset.modalTitle || heading.dataset.defaultText;
    }
    const submitButton = form.querySelector('button:not([type="button"])');
    if (submitButton) {
        if (!submitButton.dataset.defaultText) {
            submitButton.dataset.defaultText = submitButton.textContent;
        }
        submitButton.textContent = trigger.dataset.modalTitle ? 'Lưu thay đổi' : submitButton.dataset.defaultText;
    }
    form.querySelectorAll('[data-required-on-create]').forEach((field) => {
        field.required = !trigger.dataset.modalTitle;
        field.placeholder = trigger.dataset.modalTitle ? 'Để trống nếu không đổi' : '';
    });

    Array.from(trigger.attributes).forEach((attr) => {
        if (!attr.name.startsWith('data-') || attr.name === 'data-open-modal' || attr.name === 'data-modal-title') {
            return;
        }
        const fieldName = attr.name.slice(5);
        const field = form.elements[fieldName];
        if (field) {
            field.value = attr.value;
        }
    });
    form.querySelectorAll('[data-default-value]').forEach((field) => {
        if (!field.value) {
            field.value = field.dataset.defaultValue || '';
        }
    });
    configureBookingDateRange(form);
    updateOccupancyControls(form);
    updateWalkinStayMode(form);
    updateBookingRoomOptions(form);
    const deleteMap = [
        ['id_room', 'delete_room', 'Xóa phòng', 'Xóa phòng này?'],
        ['id_guest', 'delete_guest', 'Xóa khách hàng', 'Xóa khách hàng này?'],
        ['id_admin', 'delete_staff', 'Xóa nhân viên', 'Xóa nhân viên này?'],
        ['id_service', 'delete_service', 'Xóa dịch vụ', 'Xóa dịch vụ này?'],
        ['id_hotel', 'delete_hotel', 'Xóa cơ sở', 'Xóa cơ sở này?']
    ];
    const modalBody = form.querySelector('.modal-body');
    let deleteButton = form.querySelector('[data-delete-button]');
    const config = deleteMap.find(([fieldName]) => form.elements[fieldName] && !(fieldName === 'id_room' && ['bookingModal', 'walkinModal'].includes(modal.id)));
    if (modalBody && config) {
        if (!deleteButton) {
            deleteButton = document.createElement('button');
            deleteButton.type = 'submit';
            deleteButton.name = 'action';
            deleteButton.className = 'btn btn-outline-danger w-100 mt-2 d-none';
            deleteButton.dataset.deleteButton = '1';
            deleteButton.formNoValidate = true;
            deleteButton.addEventListener('click', (event) => {
                if (!window.confirm(deleteButton.dataset.confirmDelete || 'Xóa mục này?')) {
                    event.preventDefault();
                }
            });
            modalBody.appendChild(deleteButton);
        }
        const idField = form.elements[config[0]];
        deleteButton.value = config[1];
        deleteButton.textContent = config[2];
        deleteButton.dataset.confirmDelete = config[3];
        deleteButton.classList.toggle('d-none', !idField || !idField.value);
    } else if (deleteButton) {
        deleteButton.classList.add('d-none');
    }
};

document.querySelectorAll('input[name="hero_image"], input[name="image_url"], textarea[name="gallery_urls"]').forEach((field) => {
    field.classList.add('d-none');
    const label = field.previousElementSibling;
    if (label && label.tagName === 'LABEL') {
        label.classList.add('d-none');
    }
});

const openAdminModal = (id, trigger = null) => {
    const modal = document.getElementById(id);
    if (!modal) {
        return;
    }
    if (trigger) {
        fillModalForm(modal, trigger);
    }
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('admin-modal-open');
};

const closeAdminModal = (modal) => {
    if (!modal) {
        return;
    }
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    if (!document.querySelector('.admin-modal.is-open')) {
        document.body.classList.remove('admin-modal-open');
    }
};

document.querySelectorAll('[data-open-modal]').forEach((button) => {
    button.addEventListener('click', () => openAdminModal(button.dataset.openModal, button));
});

document.querySelectorAll('form [data-room-select]').forEach((select) => {
    updateOccupancyControls(select.form);
    select.addEventListener('change', () => {
        updateOccupancyControls(select.form);
        updateWalkinStayMode(select.form);
    });
});

document.querySelectorAll('form [data-booking-hotel-select]').forEach((select) => {
    select.addEventListener('change', () => {
        const roomSelect = select.form.querySelector('[data-booking-room-select]');
        if (roomSelect) {
            roomSelect.value = '';
        }
        updateBookingRoomOptions(select.form);
    });
});

document.querySelectorAll('form [data-booking-room-select]').forEach((select) => {
    const form = select.form;
    configureBookingDateRange(form);
    updateBookingRoomOptions(form);
    ['check_in', 'check_out'].forEach((fieldName) => {
        const field = form.elements[fieldName];
        if (field) {
            field.addEventListener('change', () => updateBookingRoomOptions(form));
        }
    });
});

document.querySelectorAll('form [data-adults-select]').forEach((select) => {
    select.addEventListener('change', () => {
        updateOccupancyControls(select.form);
        updateBookingRoomOptions(select.form);
    });
});

document.querySelectorAll('form [data-children-select]').forEach((select) => {
    select.addEventListener('change', () => updateBookingRoomOptions(select.form));
});

document.querySelectorAll('form [data-walkin-mode]').forEach((select) => {
    updateWalkinStayMode(select.form);
    select.addEventListener('change', () => updateWalkinStayMode(select.form));
});

document.querySelectorAll('[data-close-modal]').forEach((button) => {
    button.addEventListener('click', () => closeAdminModal(button.closest('.admin-modal')));
});

document.querySelectorAll('.admin-modal').forEach((modal) => {
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeAdminModal(modal);
        }
    });
});

const formatVnd = (value) => new Intl.NumberFormat('vi-VN').format(Math.max(0, Math.round(value))) + ' đ';
document.querySelectorAll('[data-payment-form]').forEach((form) => {
    const debt = Number(form.dataset.paymentDebt || 0);
    const inputs = Array.from(form.querySelectorAll('[data-payment-amount]'));
    const current = form.querySelector('[data-payment-current]');
    const fillCash = form.querySelector('[data-fill-cash]');
    const method = form.querySelector('select[name="method"]');
    const pointButtons = Array.from(form.querySelectorAll('[data-use-all-points]'));
    const updatePaymentTotal = () => {
        const total = inputs.reduce((sum, input) => sum + Math.max(0, Number(input.value || 0)), 0);
        if (current) {
            current.textContent = formatVnd(total);
            current.classList.toggle('text-danger', total > debt);
        }
    };
    inputs.forEach((input) => input.addEventListener('input', () => {
        if (!input.matches('[data-point-redeem-input]')) {
            const pointInput = form.querySelector('[data-point-redeem-input]');
            if (pointInput) {
                pointInput.value = '0';
            }
        }
        updatePaymentTotal();
    }));
    pointButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const input = form.querySelector('[data-point-redeem-input]');
            if (!input) {
                return;
            }
            input.value = Number(button.dataset.useAllPoints || 0);
            inputs.forEach((paymentInput) => {
                if (paymentInput !== input) {
                    paymentInput.value = '';
                }
            });
            updatePaymentTotal();
        });
    });
    if (fillCash && inputs[0]) {
        fillCash.addEventListener('click', () => {
            inputs.forEach((input, index) => {
                input.value = index === 0 ? Math.round(debt) : '';
            });
            if (method) {
                method.value = 'Tiền mặt';
            }
            updatePaymentTotal();
        });
    }
    updatePaymentTotal();
});
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        closeAdminModal(document.querySelector('.admin-modal.is-open'));
    }
});

const adminClock = document.getElementById('adminClock');
const adminServerTime = Date.parse(<?php echo json_encode(date('c'), JSON_UNESCAPED_SLASHES); ?>);
const adminClockStartedAt = performance.now();
const adminTimezone = <?php echo json_encode($appTimezone ?? 'Asia/Ho_Chi_Minh', JSON_UNESCAPED_SLASHES); ?>;
const adminClockFormatter = new Intl.DateTimeFormat('vi-VN', {
    timeZone: adminTimezone,
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour12: false
});
const updateAdminClock = () => {
    const now = new Date(adminServerTime + (performance.now() - adminClockStartedAt));
    const parts = Object.fromEntries(adminClockFormatter.formatToParts(now).map((part) => [part.type, part.value]));
    const text = `${parts.hour}:${parts.minute}:${parts.second} ${parts.day}/${parts.month}/${parts.year}`;
    if (adminClock) {
        adminClock.textContent = text;
    }
};
updateAdminClock();
setInterval(updateAdminClock, 1000);

<?php if (in_array($view, ['room_status', 'invoices'], true) && $bookingId > 0 && $selectedBooking): ?>
openAdminModal('invoiceModal');
<?php endif; ?>
</script>
</body>
</html>
