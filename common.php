<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$databaseConfigFile = __DIR__ . '/config/database.php';
$databaseConfig = is_file($databaseConfigFile) ? require $databaseConfigFile : [];
$dbHost = getenv('DB_HOST') ?: ($databaseConfig['host'] ?? 'localhost');
$dbPort = (int)(getenv('DB_PORT') ?: ($databaseConfig['port'] ?? 3306));
$dbUser = getenv('DB_USER') ?: ($databaseConfig['user'] ?? 'root');
$dbPass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : ($databaseConfig['password'] ?? '');
$dbName = getenv('DB_NAME') ?: ($databaseConfig['name'] ?? 'hotel_manager');
$appTimezone = getenv('APP_TIMEZONE') ?: ($databaseConfig['timezone'] ?? 'Asia/Ho_Chi_Minh');
try {
    new DateTimeZone($appTimezone);
} catch (Throwable $ignored) {
    $appTimezone = 'Asia/Ho_Chi_Minh';
}
date_default_timezone_set($appTimezone);

require_once __DIR__ . '/includes/services/blockchain_service.php';

function db(): mysqli
{
    static $db = null;
    global $dbHost, $dbPort, $dbUser, $dbPass, $dbName;

    if ($db instanceof mysqli) {
        return $db;
    }

    $server = new mysqli($dbHost, $dbUser, $dbPass, '', $dbPort);
    $server->set_charset('utf8mb4');
    $server->query("SET time_zone = '+07:00'");
    $server->query("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $server->close();

    $db = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    $db->set_charset('utf8mb4');
    $db->query("SET time_zone = '+07:00'");
    ensureHotelSchema($db);
    seedHotelData($db);
    localizeStoredImages($db);
    return $db;
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
}

function verifyCsrfToken(?string $token = null): void
{
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    $submittedToken = (string)($token ?? ($_POST['csrf_token'] ?? ''));
    if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        try {
            logSecurityEvent(db(), 'csrf_failed', 'blocked', 'Yeu cau POST thieu hoac sai CSRF token.');
        } catch (Throwable $ignored) {
        }
        throw new Exception('Phiên làm việc không hợp lệ. Vui lòng tải lại trang và thử lại.');
    }
}

function securityClientIp(): string
{
    foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        $value = trim((string)($_SERVER[$key] ?? ''));
        if ($value !== '') {
            return substr(explode(',', $value)[0], 0, 45);
        }
    }
    return '';
}

function currentSecurityActor(): array
{
    if (!empty($_SESSION['hotel_admin_id'])) {
        return ['admin', (int)$_SESSION['hotel_admin_id']];
    }
    if (!empty($_SESSION['hotel_customer_id'])) {
        return ['customer', (int)$_SESSION['hotel_customer_id']];
    }
    return ['guest', null];
}

function logSecurityEvent(mysqli $db, string $action, string $status = 'info', string $message = '', array $context = []): void
{
    try {
        [$actorType, $actorId] = currentSecurityActor();
        $ip = securityClientIp();
        $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
        $requestUri = substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 500);
        if ($requestUri !== '') {
            $context = ['uri' => $requestUri] + $context;
        }
        $contextJson = $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $stmt = $db->prepare("INSERT INTO security_logs (actor_type, actor_id, action, status, message, ip_address, user_agent, context_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('sissssss', $actorType, $actorId, $action, $status, $message, $ip, $userAgent, $contextJson);
        $stmt->execute();
        hotelBlockchainRecordSecurityLog($db, (int)$db->insert_id, [
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $action,
            'status' => $status,
            'message' => $message,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'context_json' => $contextJson,
        ]);
    } catch (Throwable $ignored) {
        // Logging must not break the main user flow.
    }
}

function money($value): string
{
    return number_format((float)$value, 0, ',', '.') . ' đ';
}

function points($value): string
{
    return number_format((int)$value, 0, ',', '.') . ' điểm';
}

function imageStorageDir(): string
{
    $dir = __DIR__ . '/images';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

function isRemoteImagePath(string $path): bool
{
    return (bool)preg_match('#^https?://#i', trim($path));
}

function localizeRemoteImage(string $url, string $prefix = 'asset'): string
{
    $url = trim($url);
    if ($url === '' || !isRemoteImagePath($url)) {
        return $url;
    }

    $safePrefix = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($prefix)) ?: 'asset';
    $filename = $safePrefix . '-' . substr(sha1($url), 0, 16) . '.jpg';
    $relative = 'images/' . $filename;
    $target = imageStorageDir() . '/' . $filename;
    if (is_file($target) && filesize($target) > 0) {
        return $relative;
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 18,
            'follow_location' => 1,
            'header' => "User-Agent: SpotkiHotelDemo/1.0\r\n",
        ],
        'https' => [
            'timeout' => 18,
            'follow_location' => 1,
            'header' => "User-Agent: SpotkiHotelDemo/1.0\r\n",
        ],
    ]);
    $data = @file_get_contents($url, false, $context);
    if ($data === false || strlen($data) < 100) {
        return $url;
    }
    file_put_contents($target, $data);
    return $relative;
}

function saveUploadedImage(string $field, string $prefix): string
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if (($_FILES[$field]['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new Exception('Tai anh len that bai.');
    }
    $tmp = (string)($_FILES[$field]['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp) || !@getimagesize($tmp)) {
        throw new Exception('File tai len khong phai anh hop le.');
    }
    $original = (string)($_FILES[$field]['name'] ?? 'image');
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
        $ext = 'jpg';
    }
    $safePrefix = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($prefix)) ?: 'upload';
    $filename = $safePrefix . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = imageStorageDir() . '/' . $filename;
    if (!move_uploaded_file($tmp, $target)) {
        throw new Exception('Khong the luu anh vao thu muc images.');
    }
    return 'images/' . $filename;
}

function saveUploadedImages(string $field, string $prefix): array
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]['name'] ?? null)) {
        return [];
    }

    $saved = [];
    $files = $_FILES[$field];
    foreach ($files['name'] as $index => $name) {
        if (($files['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $_FILES[$field . '_single'] = [
            'name' => $name,
            'type' => $files['type'][$index] ?? '',
            'tmp_name' => $files['tmp_name'][$index] ?? '',
            'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$index] ?? 0,
        ];
        $saved[] = saveUploadedImage($field . '_single', $prefix);
        unset($_FILES[$field . '_single']);
    }
    return $saved;
}

function splitImageList(string $value): array
{
    return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $value) ?: [])));
}

function localizeImageList(string $value, string $prefix): string
{
    $images = [];
    foreach (splitImageList($value) as $image) {
        $images[] = localizeRemoteImage($image, $prefix);
    }
    return implode(',', array_values(array_unique($images)));
}

function hotelRoomImageRemoteSet(): array
{
    return [
        'https://images.unsplash.com/photo-1755613708939-d572099433ab?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1652161854125-1f5289c13eac?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1761416376095-c2b89d845c50?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1566665797739-1674de7a421a?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1598928636135-d146006ff4be?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1616594039964-ae9021a400a0?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1618220179428-22790b461013?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1600566753190-17f0baa2a6c3?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1615873968403-89e068629265?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1595526114035-0d45ed16cfbf?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1616486338812-3dadae4b4ace?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1600210492493-0946911123ea?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1540518614846-7eded433c457?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1512918728675-ed5a9ecdebfd?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1560185007-cde436f6a4d0?auto=format&fit=crop&w=1200&q=80',
    ];
}

function hotelRoomImageSet(): array
{
    return array_map(static fn(string $url): string => localizeRemoteImage($url, 'room'), hotelRoomImageRemoteSet());
}

function defaultHotelHeroImage(): string
{
    return localizeRemoteImage('https://images.unsplash.com/photo-1566073771259-6a8506099945?auto=format&fit=crop&w=1800&q=80', 'hotel');
}

function beachHotelHeroImage(): string
{
    return localizeRemoteImage('https://images.unsplash.com/photo-1582719508461-905c673771fd?auto=format&fit=crop&w=1800&q=80', 'hotel');
}

function serviceHeroImage(): string
{
    return localizeRemoteImage('https://images.unsplash.com/photo-1584132967334-10e028bd69f7?auto=format&fit=crop&w=2200&q=86', 'service');
}

function defaultRoomImage(): string
{
    return hotelRoomImageSet()[0];
}

function roomDisplayImage(array $room): string
{
    $image = trim((string)($room['image_url'] ?? ''));
    return $image !== '' ? $image : defaultRoomImage();
}

function roomGallery(array $room): array
{
    $images = [];
    foreach (explode(',', (string)($room['gallery_urls'] ?? '')) as $url) {
        $url = trim($url);
        if ($url !== '') {
            $images[] = $url;
        }
    }
    if (!empty($room['image_url'])) {
        array_unshift($images, (string)$room['image_url']);
    }
    if (!$images) {
        $images[] = defaultRoomImage();
    }
    return array_values(array_unique($images));
}

function hasColumn(mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    return (int)$stmt->get_result()->fetch_assoc()['total'] > 0;
}

function addColumnIfMissing(mysqli $db, string $table, string $column, string $definition): void
{
    if (!hasColumn($db, $table, $column)) {
        $db->query("ALTER TABLE `$table` ADD COLUMN $definition");
    }
}

function currentCustomer(mysqli $db): ?array
{
    $guestId = (int)($_SESSION['hotel_customer_id'] ?? 0);
    if ($guestId <= 0) {
        return null;
    }
    $stmt = $db->prepare("SELECT id_guest, full_name, phone, email, identity_no, address, loyalty_points FROM guests WHERE id_guest=? LIMIT 1");
    $stmt->bind_param('i', $guestId);
    $stmt->execute();
    $guest = $stmt->get_result()->fetch_assoc();
    return $guest ?: null;
}

function ensureHotelSchema(mysqli $db): void
{
    $db->query("CREATE TABLE IF NOT EXISTS admin_users (
        id_admin INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(80) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        full_name VARCHAR(160) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    addColumnIfMissing($db, 'admin_users', 'email', "email VARCHAR(160) NULL AFTER full_name");
    addColumnIfMissing($db, 'admin_users', 'role', "role ENUM('admin','reception','accounting','manager','housekeeping') NOT NULL DEFAULT 'admin' AFTER email");
    addColumnIfMissing($db, 'admin_users', 'id_hotel', "id_hotel INT NULL AFTER role");
    addColumnIfMissing($db, 'admin_users', 'is_active', "is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role");
    $db->query("ALTER TABLE admin_users MODIFY role ENUM('admin','reception','accounting','manager','housekeeping') NOT NULL DEFAULT 'admin'");

    $db->query("CREATE TABLE IF NOT EXISTS hotels (
        id_hotel INT AUTO_INCREMENT PRIMARY KEY,
        hotel_name VARCHAR(180) NOT NULL,
        brand_name VARCHAR(120) NOT NULL DEFAULT 'Spotki Hotels',
        star_rating TINYINT NOT NULL DEFAULT 5,
        address VARCHAR(255) NOT NULL,
        city VARCHAR(120) NOT NULL,
        phone VARCHAR(40) NULL,
        email VARCHAR(160) NULL,
        hero_image VARCHAR(500) NULL,
        description TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS rooms (
        id_room INT AUTO_INCREMENT PRIMARY KEY,
        id_hotel INT NULL,
        room_number VARCHAR(30) NOT NULL UNIQUE,
        room_type VARCHAR(80) NOT NULL,
        floor_no INT NOT NULL DEFAULT 1,
        capacity INT NOT NULL DEFAULT 2,
        room_size VARCHAR(40) NULL,
        bed_type VARCHAR(120) NULL,
        price_per_night DECIMAL(12,2) NOT NULL DEFAULT 0,
        hourly_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
        extra_guest_rate_percent DECIMAL(5,2) NOT NULL DEFAULT 25.00,
        image_url VARCHAR(500) NULL,
        gallery_urls TEXT NULL,
        amenities TEXT NULL,
        package_name VARCHAR(160) NULL,
        package_services TEXT NULL,
        status ENUM('available','occupied','cleaning','maintenance') NOT NULL DEFAULT 'available',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        housekeeping_status ENUM('pending','cleaning') NULL,
        housekeeping_started_at DATETIME NULL,
        cleaned_at DATETIME NULL,
        note TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    addColumnIfMissing($db, 'rooms', 'id_hotel', "id_hotel INT NULL AFTER id_room");
    addColumnIfMissing($db, 'rooms', 'room_size', "room_size VARCHAR(40) NULL AFTER capacity");
    addColumnIfMissing($db, 'rooms', 'bed_type', "bed_type VARCHAR(120) NULL AFTER room_size");
    addColumnIfMissing($db, 'rooms', 'hourly_rate', "hourly_rate DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER price_per_night");
    addColumnIfMissing($db, 'rooms', 'extra_guest_rate_percent', "extra_guest_rate_percent DECIMAL(5,2) NOT NULL DEFAULT 25.00 AFTER hourly_rate");
    addColumnIfMissing($db, 'rooms', 'image_url', "image_url VARCHAR(500) NULL AFTER extra_guest_rate_percent");
    addColumnIfMissing($db, 'rooms', 'gallery_urls', "gallery_urls TEXT NULL AFTER image_url");
    addColumnIfMissing($db, 'rooms', 'amenities', "amenities TEXT NULL AFTER gallery_urls");
    addColumnIfMissing($db, 'rooms', 'package_name', "package_name VARCHAR(160) NULL AFTER amenities");
    addColumnIfMissing($db, 'rooms', 'package_services', "package_services TEXT NULL AFTER package_name");
    addColumnIfMissing($db, 'rooms', 'is_active', "is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER status");
    addColumnIfMissing($db, 'rooms', 'housekeeping_status', "housekeeping_status ENUM('pending','cleaning') NULL AFTER status");
    addColumnIfMissing($db, 'rooms', 'housekeeping_started_at', "housekeeping_started_at DATETIME NULL AFTER housekeeping_status");
    addColumnIfMissing($db, 'rooms', 'cleaned_at', "cleaned_at DATETIME NULL AFTER housekeeping_started_at");

    $db->query("CREATE TABLE IF NOT EXISTS guests (
        id_guest INT AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(255) NOT NULL,
        phone VARCHAR(30) NOT NULL,
        email VARCHAR(255) NULL,
        password_hash VARCHAR(255) NULL,
        customer_is_active TINYINT(1) NOT NULL DEFAULT 1,
        identity_no VARCHAR(60) NULL,
        address TEXT NULL,
        is_vip TINYINT(1) NOT NULL DEFAULT 0,
        loyalty_points INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_phone (phone)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    addColumnIfMissing($db, 'guests', 'password_hash', "password_hash VARCHAR(255) NULL AFTER email");
    addColumnIfMissing($db, 'guests', 'customer_is_active', "customer_is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER password_hash");
    addColumnIfMissing($db, 'guests', 'is_vip', "is_vip TINYINT(1) NOT NULL DEFAULT 0 AFTER address");
    addColumnIfMissing($db, 'guests', 'loyalty_points', "loyalty_points INT NOT NULL DEFAULT 0 AFTER is_vip");

    $db->query("CREATE TABLE IF NOT EXISTS bookings (
        id_booking INT AUTO_INCREMENT PRIMARY KEY,
        booking_code VARCHAR(30) NOT NULL UNIQUE,
        id_guest INT NOT NULL,
        id_room INT NOT NULL,
        check_in DATE NOT NULL,
        check_out DATE NOT NULL,
        check_in_at DATETIME NULL,
        expected_check_out_at DATETIME NULL,
        checked_out_at DATETIME NULL,
        pricing_mode ENUM('night','hour') NOT NULL DEFAULT 'night',
        contact_name VARCHAR(160) NULL,
        contact_phone VARCHAR(40) NULL,
        customer_edit_count INT NOT NULL DEFAULT 0,
        customer_edited_at DATETIME NULL,
        loyalty_awarded_at DATETIME NULL,
        adults INT NOT NULL DEFAULT 1,
        children INT NOT NULL DEFAULT 0,
        status ENUM('booked','checked_in','checked_out','cancelled') NOT NULL DEFAULT 'booked',
        note TEXT NULL,
        package_snapshot TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (id_guest) REFERENCES guests(id_guest),
        FOREIGN KEY (id_room) REFERENCES rooms(id_room),
        INDEX idx_dates (check_in, check_out),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    addColumnIfMissing($db, 'bookings', 'check_in_at', "check_in_at DATETIME NULL AFTER check_out");
    addColumnIfMissing($db, 'bookings', 'expected_check_out_at', "expected_check_out_at DATETIME NULL AFTER check_in_at");
    addColumnIfMissing($db, 'bookings', 'checked_out_at', "checked_out_at DATETIME NULL AFTER expected_check_out_at");
    addColumnIfMissing($db, 'bookings', 'pricing_mode', "pricing_mode ENUM('night','hour') NOT NULL DEFAULT 'night' AFTER checked_out_at");
    addColumnIfMissing($db, 'bookings', 'contact_name', "contact_name VARCHAR(160) NULL AFTER pricing_mode");
    addColumnIfMissing($db, 'bookings', 'contact_phone', "contact_phone VARCHAR(40) NULL AFTER contact_name");
    addColumnIfMissing($db, 'bookings', 'customer_edit_count', "customer_edit_count INT NOT NULL DEFAULT 0 AFTER contact_phone");
    addColumnIfMissing($db, 'bookings', 'customer_edited_at', "customer_edited_at DATETIME NULL AFTER customer_edit_count");
    addColumnIfMissing($db, 'bookings', 'loyalty_awarded_at', "loyalty_awarded_at DATETIME NULL AFTER customer_edited_at");
    addColumnIfMissing($db, 'bookings', 'package_snapshot', "package_snapshot TEXT NULL AFTER note");

    $db->query("CREATE TABLE IF NOT EXISTS services (
        id_service INT AUTO_INCREMENT PRIMARY KEY,
        service_name VARCHAR(255) NOT NULL,
        unit VARCHAR(50) NOT NULL DEFAULT 'lần',
        price DECIMAL(12,2) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS booking_services (
        id_booking_service INT AUTO_INCREMENT PRIMARY KEY,
        id_booking INT NOT NULL,
        id_service INT NOT NULL,
        quantity INT NOT NULL DEFAULT 1,
        price DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (id_booking) REFERENCES bookings(id_booking) ON DELETE CASCADE,
        FOREIGN KEY (id_service) REFERENCES services(id_service)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS payments (
        id_payment INT AUTO_INCREMENT PRIMARY KEY,
        id_booking INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        method VARCHAR(80) NOT NULL DEFAULT 'Tiền mặt',
        paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        note TEXT NULL,
        payer_name VARCHAR(160) NULL,
        cashier_name VARCHAR(160) NULL,
        FOREIGN KEY (id_booking) REFERENCES bookings(id_booking) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    addColumnIfMissing($db, 'payments', 'payer_name', "payer_name VARCHAR(160) NULL AFTER note");
    addColumnIfMissing($db, 'payments', 'cashier_name', "cashier_name VARCHAR(160) NULL AFTER payer_name");

    $db->query("CREATE TABLE IF NOT EXISTS reviews (
        id_review INT AUTO_INCREMENT PRIMARY KEY,
        id_booking INT NOT NULL,
        id_room INT NOT NULL,
        id_guest INT NOT NULL,
        room_rating DECIMAL(2,1) NOT NULL,
        service_rating DECIMAL(2,1) NOT NULL,
        comment TEXT NULL,
        status ENUM('visible','hidden') NOT NULL DEFAULT 'visible',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_booking_review (id_booking),
        INDEX idx_room_status (id_room, status),
        FOREIGN KEY (id_booking) REFERENCES bookings(id_booking) ON DELETE CASCADE,
        FOREIGN KEY (id_room) REFERENCES rooms(id_room) ON DELETE CASCADE,
        FOREIGN KEY (id_guest) REFERENCES guests(id_guest) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->query("ALTER TABLE reviews MODIFY room_rating DECIMAL(2,1) NOT NULL");
    $db->query("ALTER TABLE reviews MODIFY service_rating DECIMAL(2,1) NOT NULL");

    $db->query("CREATE TABLE IF NOT EXISTS security_logs (
        id_log INT AUTO_INCREMENT PRIMARY KEY,
        actor_type ENUM('guest','customer','admin') NOT NULL DEFAULT 'guest',
        actor_id INT NULL,
        action VARCHAR(120) NOT NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'info',
        message TEXT NULL,
        ip_address VARCHAR(45) NULL,
        user_agent VARCHAR(500) NULL,
        context_json TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_action_created (action, created_at),
        INDEX idx_actor_created (actor_type, actor_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    hotelBlockchainEnsureSchema($db);
}

function seedHotelData(mysqli $db): void
{
    $adminCount = (int)$db->query("SELECT COUNT(*) AS total FROM admin_users")->fetch_assoc()['total'];
    if ($adminCount === 0) {
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO admin_users (username, password_hash, full_name) VALUES ('admin', ?, 'Quản lý khách sạn')");
        $stmt->bind_param('s', $hash);
        $stmt->execute();
    }

    $hotelCount = (int)$db->query("SELECT COUNT(*) AS total FROM hotels")->fetch_assoc()['total'];
    if ($hotelCount === 0) {
        $hotels = [
            ['Spotki Grand Saigon', 'Spotki Hotels', 5, '88 Đồng Khởi, Phường Bến Nghé, Quận 1', 'TP.HCM', '028 3822 8899', 'saigon@spotki.vn', 'https://images.unsplash.com/photo-1566073771259-6a8506099945?auto=format&fit=crop&w=1800&q=80', 'Khách sạn 5 sao trung tâm thành phố, khu nghỉ dưỡng cao cấp.'],
            ['Spotki Bay Đà Nẵng', 'Spotki Hotels', 5, '12 Võ Nguyên Giáp, Sơn Trà', 'Đà Nẵng', '0236 355 6688', 'danang@spotki.vn', 'https://images.unsplash.com/photo-1582719508461-905c673771fd?auto=format&fit=crop&w=1800&q=80', 'Khách sạn biển 5 sao với hồ bơi, spa và khu lounge riêng.'],
            ['Spotki Heritage Hà Nội', 'Spotki Hotels', 4, '46 Tràng Tiền, Hoàn Kiếm', 'Hà Nội', '024 3936 2288', 'hanoi@spotki.vn', 'https://images.unsplash.com/photo-1590490360182-c33d57733427?auto=format&fit=crop&w=1800&q=80', 'Khách sạn 4 sao phong cách boutique gần khu phố cổ.'],
        ];
        $stmt = $db->prepare("INSERT INTO hotels (hotel_name, brand_name, star_rating, address, city, phone, email, hero_image, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($hotels as $hotel) {
            $stmt->bind_param('ssissssss', $hotel[0], $hotel[1], $hotel[2], $hotel[3], $hotel[4], $hotel[5], $hotel[6], $hotel[7], $hotel[8]);
            $stmt->execute();
        }
    }

    $defaultHotelId = (int)$db->query("SELECT id_hotel FROM hotels ORDER BY id_hotel LIMIT 1")->fetch_assoc()['id_hotel'];
    $db->query("UPDATE rooms SET id_hotel = $defaultHotelId WHERE id_hotel IS NULL");

    $roomCount = (int)$db->query("SELECT COUNT(*) AS total FROM rooms")->fetch_assoc()['total'];
    if ($roomCount === 0) {
        $rooms = [
            ['101', 'Standard', 1, 2, 550000, 120000, 'available', 'Phòng tiêu chuẩn, phù hợp khách công tác hoặc cặp đôi.'],
            ['102', 'Standard', 1, 2, 550000, 120000, 'available', 'Phòng gọn, đầy đủ tiện nghi cơ bản.'],
            ['201', 'Superior', 2, 2, 750000, 160000, 'available', 'Phòng rộng hơn, có bàn làm việc và cửa sổ thoáng.'],
            ['202', 'Superior', 2, 3, 820000, 180000, 'cleaning', 'Đang dọn, có thể nhận sau khi hoàn tất vệ sinh.'],
            ['301', 'Deluxe', 3, 3, 1150000, 240000, 'available', 'Phòng deluxe có khu tiếp khách nhỏ.'],
            ['401', 'Family Suite', 4, 5, 1750000, 360000, 'available', 'Suite gia đình, phù hợp nhóm khách.'],
            ['501', 'VIP Suite', 5, 2, 2500000, 520000, 'available', 'Suite cao cấp với dịch vụ ưu tiên.'],
        ];
        $stmt = $db->prepare("INSERT INTO rooms (room_number, room_type, floor_no, capacity, price_per_night, hourly_rate, status, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($rooms as $room) {
            $stmt->bind_param('ssiiddss', $room[0], $room[1], $room[2], $room[3], $room[4], $room[5], $room[6], $room[7]);
            $stmt->execute();
        }
    }
    $db->query("UPDATE rooms SET hourly_rate = ROUND(price_per_night / 5, -3) WHERE hourly_rate = 0 AND price_per_night > 0");
    $db->query("UPDATE rooms SET room_size = CASE room_type WHEN 'Standard' THEN '32 m2' WHEN 'Superior' THEN '38 m2' WHEN 'Deluxe' THEN '48 m2' WHEN 'Family Suite' THEN '72 m2' ELSE '86 m2' END WHERE room_size IS NULL");
    $db->query("UPDATE rooms SET bed_type = CASE room_type WHEN 'Family Suite' THEN '2 Queen' WHEN 'VIP Suite' THEN '1 King' ELSE '1 King hoặc 2 Twin' END WHERE bed_type IS NULL");
    $db->query("UPDATE rooms SET amenities = 'Wi-Fi tốc độ cao, TV thông minh, minibar, két an toàn, bàn làm việc, đồ dùng tắm cao cấp' WHERE amenities IS NULL");
    $db->query("UPDATE rooms SET package_name = CASE WHEN room_type LIKE '%Suite%' THEN 'Executive Privilege' WHEN room_type='Deluxe' THEN 'Deluxe Retreat' ELSE 'Business Comfort' END WHERE package_name IS NULL");
    $db->query("UPDATE rooms SET package_services = CASE WHEN room_type LIKE '%Suite%' THEN 'Ăn sáng buffet, lounge, đưa đón sân bay 1 chiều, spa 30 phút, minibar' WHEN room_type='Deluxe' THEN 'Ăn sáng buffet, welcome drink, phòng gym, hồ bơi' ELSE 'Ăn sáng buffet, Wi-Fi, nước suối hằng ngày, phòng gym' END WHERE package_services IS NULL");
    seedBranchRooms($db);
    seedAdditionalDemoRooms($db);
    refreshDuplicateRoomImages($db);

    $serviceCount = (int)$db->query("SELECT COUNT(*) AS total FROM services")->fetch_assoc()['total'];
    if ($serviceCount === 0) {
        $services = [
            ['Nước suối', 'chai', 15000],
            ['Nước ngọt', 'lon', 25000],
            ['Bia', 'lon', 35000],
            ['Mì ly', 'ly', 30000],
            ['Snack', 'gói', 25000],
        ];
        $stmt = $db->prepare("INSERT INTO services (service_name, unit, price) VALUES (?, ?, ?)");
        foreach ($services as $service) {
            $stmt->bind_param('ssd', $service[0], $service[1], $service[2]);
            $stmt->execute();
        }
    }
    $defaultItemMap = [
        'Ăn sáng buffet' => ['Nước suối', 'chai', 15000],
        'Giặt ủi' => ['Nước ngọt', 'lon', 25000],
        'Đưa đón sân bay' => ['Bia', 'lon', 35000],
        'Spa thư giãn' => ['Mì ly', 'ly', 30000],
        'Thuê xe máy' => ['Snack', 'gói', 25000],
    ];
    $stmtUpdateItem = $db->prepare("UPDATE services SET service_name=?, unit=?, price=? WHERE service_name=?");
    foreach ($defaultItemMap as $oldName => $item) {
        $stmtUpdateItem->bind_param('ssds', $item[0], $item[1], $item[2], $oldName);
        $stmtUpdateItem->execute();
    }

    seedRoomReviews($db);
}

function seedReviewGuests(mysqli $db): array
{
    $guests = [
        ['Nguyen Minh Anh', '0909001001', 'reviewer01@spotki.local', '079200000001', 'TP.HCM'],
        ['Tran Quoc Bao', '0909001002', 'reviewer02@spotki.local', '079200000002', 'Da Nang'],
        ['Le Hoang Linh', '0909001003', 'reviewer03@spotki.local', '079200000003', 'Ha Noi'],
        ['Pham Thu Ha', '0909001004', 'reviewer04@spotki.local', '079200000004', 'Can Tho'],
        ['Do Gia Huy', '0909001005', 'reviewer05@spotki.local', '079200000005', 'Nha Trang'],
        ['Vo Ngoc Mai', '0909001006', 'reviewer06@spotki.local', '079200000006', 'Hue'],
        ['Bui Thanh Nam', '0909001007', 'reviewer07@spotki.local', '079200000007', 'Quang Ninh'],
    ];

    $ids = [];
    $stmtFind = $db->prepare("SELECT id_guest FROM guests WHERE email=? ORDER BY id_guest ASC LIMIT 1");
    $stmtInsert = $db->prepare("INSERT INTO guests (full_name, phone, email, identity_no, address, customer_is_active) VALUES (?, ?, ?, ?, ?, 1)");
    foreach ($guests as $guest) {
        $stmtFind->bind_param('s', $guest[2]);
        $stmtFind->execute();
        $existing = $stmtFind->get_result()->fetch_assoc();
        if ($existing) {
            $ids[] = (int)$existing['id_guest'];
            continue;
        }

        $stmtInsert->bind_param('sssss', $guest[0], $guest[1], $guest[2], $guest[3], $guest[4]);
        $stmtInsert->execute();
        $ids[] = (int)$db->insert_id;
    }

    return $ids;
}

function demoReviewBookingCode(mysqli $db, int $roomId, int $slot): string
{
    $base = 'RV' . str_pad((string)$roomId, 5, '0', STR_PAD_LEFT) . str_pad((string)$slot, 2, '0', STR_PAD_LEFT);
    $code = $base;
    $suffix = 1;
    $stmt = $db->prepare("SELECT COUNT(*) AS total FROM bookings WHERE booking_code=?");
    while (true) {
        $stmt->bind_param('s', $code);
        $stmt->execute();
        if ((int)$stmt->get_result()->fetch_assoc()['total'] === 0) {
            return $code;
        }
        $code = $base . '-' . $suffix;
        $suffix++;
    }
}

function seedRoomReviews(mysqli $db): void
{
    $rooms = $db->query("SELECT id_room, room_number, room_type, capacity, price_per_night, hourly_rate, package_name, package_services FROM rooms ORDER BY id_room")->fetch_all(MYSQLI_ASSOC);
    if (!$rooms) {
        return;
    }

    $guestIds = seedReviewGuests($db);
    if (!$guestIds) {
        return;
    }

    $comments = [
        'Phòng rất sạch, ánh sáng dễ chịu và đúng như hình. Nhân viên lễ tân hỗ trợ nhanh khi nhận phòng.',
        'Không gian yên tĩnh, giường ngủ thoải mái. Dịch vụ phòng và vệ sinh đều rất tốt cho kỳ nghỉ ngắn ngày.',
        'Vị trí thuận tiện, tiện nghi đầy đủ. Trải nghiệm tổng thể rất hài lòng và muốn quay lại.',
        'Phòng có bố cục gọn gàng, điều hòa và Wi-Fi hoạt động ổn định. Đội ngũ phục vụ lịch sự, chu đáo.',
        'Giá phòng hợp lý so với chất lượng. Quy trình nhận và trả phòng rõ ràng, không mất nhiều thời gian.',
        'Dịch vụ tốt, phòng thơm và đầy đủ vật dụng cần thiết. Rất phù hợp cho cả công tác và nghỉ dưỡng.',
        'Không gian phòng sang trọng, yên tĩnh và được chuẩn bị kỹ. Nhân viên phản hồi nhanh mọi yêu cầu.',
        'Tôi hài lòng với độ sạch, tiện nghi và cách phục vụ. Điểm cộng lớn là phòng rất đúng mô tả.',
    ];
    refreshSeedReviewContent($db, $comments);
    $stmtCount = $db->prepare("SELECT COUNT(*) AS total FROM reviews WHERE id_room=?");
    $stmtBooking = $db->prepare("INSERT INTO bookings (booking_code, id_guest, id_room, check_in, check_out, check_in_at, expected_check_out_at, checked_out_at, pricing_mode, contact_name, contact_phone, adults, children, status, note, package_snapshot, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'night', ?, ?, ?, ?, 'checked_out', ?, ?, ?)");
    $stmtReview = $db->prepare("INSERT INTO reviews (id_booking, id_room, id_guest, room_rating, service_rating, comment, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");

    foreach ($rooms as $roomIndex => $room) {
        $roomId = (int)$room['id_room'];
        $stmtCount->bind_param('i', $roomId);
        $stmtCount->execute();
        $currentReviews = (int)$stmtCount->get_result()->fetch_assoc()['total'];
        if ($currentReviews >= 3) {
            continue;
        }

        $targetReviews = 3 + (($roomId + strlen((string)$room['room_number'])) % 3);
        for ($slot = $currentReviews + 1; $slot <= $targetReviews; $slot++) {
            $guestId = $guestIds[($roomIndex + $slot) % count($guestIds)];
            $guest = fetchSeedGuest($db, $guestId);
            if (!$guest) {
                continue;
            }

            $daysAgo = 18 + ($roomIndex * 5) + ($slot * 3);
            $checkIn = date('Y-m-d', strtotime('-' . ($daysAgo + 1) . ' days'));
            $checkOut = date('Y-m-d', strtotime('-' . $daysAgo . ' days'));
            $checkInAt = $checkIn . ' 14:00:00';
            $expectedOut = $checkOut . ' 12:00:00';
            $checkedOutAt = $checkOut . ' 11:20:00';
            $createdAt = $checkOut . ' 13:00:00';
            $bookingCode = demoReviewBookingCode($db, $roomId, $slot);
            $adults = min(max(1, (int)$room['capacity']), 2);
            $children = (int)$room['capacity'] >= 3 && $slot % 2 === 0 ? 1 : 0;
            $note = 'Booking mẫu để tạo đánh giá demo, không phải booking vận hành thực tế.';
            $packageSnapshot = trim((string)($room['package_name'] ?? '') . ': ' . (string)($room['package_services'] ?? ''));
            $contactName = (string)$guest['full_name'];
            $contactPhone = (string)$guest['phone'];
            $stmtBooking->bind_param(
                'siisssssssiisss',
                $bookingCode,
                $guestId,
                $roomId,
                $checkIn,
                $checkOut,
                $checkInAt,
                $expectedOut,
                $checkedOutAt,
                $contactName,
                $contactPhone,
                $adults,
                $children,
                $note,
                $packageSnapshot,
                $createdAt
            );
            $stmtBooking->execute();
            $bookingId = (int)$db->insert_id;

            $roomRating = seedReviewRating($roomId);
            $serviceRating = seedReviewRating($roomId + 1);
            $comment = $comments[($roomIndex + $slot) % count($comments)];
            $stmtReview->bind_param('iiiddss', $bookingId, $roomId, $guestId, $roomRating, $serviceRating, $comment, $createdAt);
            $stmtReview->execute();
        }
    }
}

function seedReviewRating(int $seed): float
{
    return $seed % 3 === 0 ? 5.0 : 4.9;
}

function refreshSeedReviewContent(mysqli $db, array $comments): void
{
    $reviews = $db->query("SELECT rv.id_review, rv.id_room
        FROM reviews rv
        JOIN bookings b ON b.id_booking=rv.id_booking
        JOIN guests g ON g.id_guest=rv.id_guest
        WHERE b.note LIKE 'Seed review booking%'
           OR b.note LIKE 'Booking mẫu để tạo đánh giá demo%'
           OR g.email LIKE 'reviewer%@spotki.local'
        ORDER BY rv.id_room, rv.id_review")->fetch_all(MYSQLI_ASSOC);
    if (!$reviews) {
        return;
    }

    $stmt = $db->prepare("UPDATE reviews SET room_rating=?, service_rating=?, comment=? WHERE id_review=?");
    foreach ($reviews as $index => $review) {
        $roomId = (int)$review['id_room'];
        $reviewId = (int)$review['id_review'];
        $roomRating = seedReviewRating($roomId);
        $serviceRating = seedReviewRating($roomId + 1);
        $comment = $comments[$index % count($comments)];
        $stmt->bind_param('ddsi', $roomRating, $serviceRating, $comment, $reviewId);
        $stmt->execute();
    }

    $db->query("UPDATE bookings b
        JOIN reviews rv ON rv.id_booking=b.id_booking
        JOIN guests g ON g.id_guest=rv.id_guest
        SET b.note='Booking mẫu để tạo đánh giá demo, không phải booking vận hành thực tế.'
        WHERE b.note LIKE 'Seed review booking%'
           OR b.note LIKE 'Booking mẫu để tạo đánh giá demo%'
           OR g.email LIKE 'reviewer%@spotki.local'");
}

function fetchSeedGuest(mysqli $db, int $guestId): ?array
{
    $stmt = $db->prepare("SELECT id_guest, full_name, phone FROM guests WHERE id_guest=? LIMIT 1");
    $stmt->bind_param('i', $guestId);
    $stmt->execute();
    $guest = $stmt->get_result()->fetch_assoc();
    return $guest ?: null;
}

function refreshDuplicateRoomImages(mysqli $db): void
{
    $roomImages = hotelRoomImageSet();
    $legacyImages = [
        'https://images.unsplash.com/photo-1578683010236-d716f9a3f461?auto=format&fit=crop&w=900&q=80',
        'https://images.unsplash.com/photo-1578683010236-d716f9a3f461?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1560448204-603b3fc33ddc?auto=format&fit=crop&w=900&q=80',
        'https://images.unsplash.com/photo-1560448204-603b3fc33ddc?auto=format&fit=crop&w=1000&q=80',
        'https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?auto=format&fit=crop&w=900&q=80',
        'https://images.unsplash.com/photo-1590490359683-658d3d23f972?auto=format&fit=crop&w=900&q=80',
        'https://images.unsplash.com/photo-1602002418082-a4443e081dd1?auto=format&fit=crop&w=900&q=80',
    ];

    $rooms = $db->query("SELECT id_room, image_url, gallery_urls FROM rooms ORDER BY id_hotel, floor_no, room_number")->fetch_all(MYSQLI_ASSOC);
    $urlCounts = [];
    foreach ($rooms as $room) {
        $url = trim((string)($room['image_url'] ?? ''));
        if ($url !== '') {
            $urlCounts[$url] = ($urlCounts[$url] ?? 0) + 1;
        }
    }

    $stmt = $db->prepare("UPDATE rooms SET image_url=?, gallery_urls=IF(gallery_urls IS NULL OR gallery_urls='', ?, gallery_urls) WHERE id_room=?");
    foreach ($rooms as $index => $room) {
        $url = trim((string)($room['image_url'] ?? ''));
        $needsRefresh = $url !== '' && (in_array($url, $legacyImages, true) || (($urlCounts[$url] ?? 0) > 1));
        if (!$needsRefresh) {
            continue;
        }

        $image = $roomImages[$index % count($roomImages)];
        $gallery = implode(',', [
            $image,
            $roomImages[($index + 1) % count($roomImages)],
            $roomImages[($index + 2) % count($roomImages)],
            $roomImages[($index + 3) % count($roomImages)],
        ]);
        $roomId = (int)$room['id_room'];
        $stmt->bind_param('ssi', $image, $gallery, $roomId);
        $stmt->execute();
    }
}

function tableExists(mysqli $db, string $table): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    return (int)$stmt->get_result()->fetch_assoc()['total'] > 0;
}

function localizeStoredImages(mysqli $db): void
{
    $hotels = $db->query("SELECT id_hotel, hero_image FROM hotels WHERE hero_image LIKE 'http%'")->fetch_all(MYSQLI_ASSOC);
    $stmtHotel = $db->prepare("UPDATE hotels SET hero_image=? WHERE id_hotel=?");
    foreach ($hotels as $hotel) {
        $local = localizeRemoteImage((string)$hotel['hero_image'], 'hotel');
        if ($local !== $hotel['hero_image']) {
            $hotelId = (int)$hotel['id_hotel'];
            $stmtHotel->bind_param('si', $local, $hotelId);
            $stmtHotel->execute();
        }
    }

    $rooms = $db->query("SELECT id_room, image_url, gallery_urls FROM rooms WHERE image_url LIKE 'http%' OR gallery_urls LIKE '%http%'")->fetch_all(MYSQLI_ASSOC);
    $stmtRoom = $db->prepare("UPDATE rooms SET image_url=?, gallery_urls=? WHERE id_room=?");
    foreach ($rooms as $room) {
        $image = localizeRemoteImage((string)($room['image_url'] ?? ''), 'room');
        $gallery = localizeImageList((string)($room['gallery_urls'] ?? ''), 'room');
        if ($image !== ($room['image_url'] ?? '') || $gallery !== ($room['gallery_urls'] ?? '')) {
            $roomId = (int)$room['id_room'];
            $stmtRoom->bind_param('ssi', $image, $gallery, $roomId);
            $stmtRoom->execute();
        }
    }

    if (tableExists($db, 'website_contents')) {
        $contents = $db->query("SELECT id_content, image_url FROM website_contents WHERE image_url LIKE 'http%'")->fetch_all(MYSQLI_ASSOC);
        $stmtContent = $db->prepare("UPDATE website_contents SET image_url=? WHERE id_content=?");
        foreach ($contents as $content) {
            $local = localizeRemoteImage((string)$content['image_url'], 'content');
            if ($local !== $content['image_url']) {
                $contentId = (int)$content['id_content'];
                $stmtContent->bind_param('si', $local, $contentId);
                $stmtContent->execute();
            }
        }
    }
}

function deleteLocalImageIfUnused(mysqli $db, string $path): void
{
    $path = trim($path);
    if ($path === '' || isRemoteImagePath($path) || !str_starts_with(str_replace('\\', '/', $path), 'images/')) {
        return;
    }

    $likePath = '%' . $path . '%';
    $refs = 0;
    $stmtHotel = $db->prepare("SELECT COUNT(*) AS total FROM hotels WHERE hero_image=?");
    $stmtHotel->bind_param('s', $path);
    $stmtHotel->execute();
    $refs += (int)$stmtHotel->get_result()->fetch_assoc()['total'];

    $stmtRoom = $db->prepare("SELECT COUNT(*) AS total FROM rooms WHERE image_url=? OR gallery_urls LIKE ?");
    $stmtRoom->bind_param('ss', $path, $likePath);
    $stmtRoom->execute();
    $refs += (int)$stmtRoom->get_result()->fetch_assoc()['total'];

    if (tableExists($db, 'website_contents')) {
        $stmtContent = $db->prepare("SELECT COUNT(*) AS total FROM website_contents WHERE image_url=?");
        $stmtContent->bind_param('s', $path);
        $stmtContent->execute();
        $refs += (int)$stmtContent->get_result()->fetch_assoc()['total'];
    }

    if ($refs > 0) {
        return;
    }

    $file = __DIR__ . '/' . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    $imageRoot = realpath(imageStorageDir());
    $target = realpath($file);
    if ($imageRoot && $target && str_starts_with($target, $imageRoot) && is_file($target)) {
        @unlink($target);
    }
}

function requireAdmin(): void
{
    if (empty($_SESSION['hotel_admin_id'])) {
        header('Location: login.php');
        exit;
    }
}

function seedBranchRooms(mysqli $db): void
{
    $hotels = $db->query("SELECT id_hotel, hotel_name, city FROM hotels ORDER BY id_hotel")->fetch_all(MYSQLI_ASSOC);
    if (count($hotels) < 2) {
        return;
    }

    $templates = [
        ['STD', 'Standard', 1, 2, '32 m2', '1 Queen', 900000, 190000, hotelRoomImageSet()[3], 'Business Comfort', 'Ăn sáng buffet, Wi-Fi, nước suối hằng ngày, phòng gym', 'Phòng tiêu chuẩn yên tĩnh cho khách công tác.'],
        ['DLX', 'Deluxe', 3, 3, '48 m2', '1 King hoặc 2 Twin', 1450000, 290000, hotelRoomImageSet()[4], 'Deluxe Retreat', 'Ăn sáng buffet, welcome drink, hồ bơi, phòng gym', 'Phòng deluxe rộng, phù hợp cặp đôi hoặc gia đình nhỏ.'],
        ['STE', 'Executive Suite', 5, 2, '78 m2', '1 King', 2800000, 560000, hotelRoomImageSet()[5], 'Executive Privilege', 'Ăn sáng buffet, lounge, spa 30 phút, đưa đón sân bay 1 chiều, minibar', 'Suite cao cấp với không gian tiếp khách riêng.'],
    ];

    $stmtCount = $db->prepare("SELECT COUNT(*) AS total FROM rooms WHERE id_hotel=?");
    $stmtRoomExists = $db->prepare("SELECT COUNT(*) AS total FROM rooms WHERE room_number=?");
    $stmtInsert = $db->prepare("INSERT INTO rooms (id_hotel, room_number, room_type, floor_no, capacity, room_size, bed_type, price_per_night, hourly_rate, status, image_url, amenities, package_name, package_services, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'available', ?, ?, ?, ?, ?)");
    foreach ($hotels as $index => $hotel) {
        $hotelId = (int)$hotel['id_hotel'];
        $stmtCount->bind_param('i', $hotelId);
        $stmtCount->execute();
        if ((int)$stmtCount->get_result()->fetch_assoc()['total'] > 0) {
            continue;
        }
        foreach ($templates as $i => $room) {
            $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $hotel['city']), 0, 2));
            if ($prefix === '') {
                $prefix = 'HT';
            }
            $roomNumber = $prefix . '-' . ($i + 1) . '01';
            $stmtRoomExists->bind_param('s', $roomNumber);
            $stmtRoomExists->execute();
            if ((int)$stmtRoomExists->get_result()->fetch_assoc()['total'] > 0) {
                $roomNumber = $prefix . $hotelId . '-' . ($i + 1) . '01';
            }
            $price = $room[6] + ($index * 120000);
            $hourly = $room[7] + ($index * 20000);
            $amenities = 'Wi-Fi tốc độ cao, TV thông minh, minibar, két an toàn, bàn làm việc, đồ dùng tắm cao cấp';
            $stmtInsert->bind_param('issiissddsssss', $hotelId, $roomNumber, $room[1], $room[2], $room[3], $room[4], $room[5], $price, $hourly, $room[8], $amenities, $room[9], $room[10], $room[11]);
            $stmtInsert->execute();
        }
    }
}

function seedAdditionalDemoRooms(mysqli $db): void
{
    $hotels = $db->query("SELECT id_hotel, city FROM hotels ORDER BY id_hotel")->fetch_all(MYSQLI_ASSOC);
    if (!$hotels) {
        return;
    }

    $demoRooms = [
        ['D-601', 'Premier City View', 6, 2, '42 m2', '1 King', 1320000, 270000, 'https://images.unsplash.com/photo-1631049307264-da0ec9d70304?auto=format&fit=crop&w=1200&q=80', 'https://images.unsplash.com/photo-1631049552057-403cdb8f0658?auto=format&fit=crop&w=1200&q=80,https://images.unsplash.com/photo-1590490360182-c33d57733427?auto=format&fit=crop&w=1200&q=80,https://images.unsplash.com/photo-1584132915807-fd1f5fbc078f?auto=format&fit=crop&w=1200&q=80', 'Premier Comfort', 'An sang buffet, welcome drink, Wi-Fi, phong gym', 'Phong huong thanh pho, noi that hien dai va khong gian lam viec rieng.'],
        ['D-602', 'Garden Deluxe', 6, 2, '46 m2', '1 Queen', 1480000, 300000, 'https://images.unsplash.com/photo-1611892440504-42a792e24d32?auto=format&fit=crop&w=1200&q=80', 'https://images.unsplash.com/photo-1598928506311-c55ded91a20c?auto=format&fit=crop&w=1200&q=80,https://images.unsplash.com/photo-1595576508898-0ad5c879a061?auto=format&fit=crop&w=1200&q=80,https://images.unsplash.com/photo-1560448075-bb485b067938?auto=format&fit=crop&w=1200&q=80', 'Deluxe Retreat', 'An sang buffet, ho boi, welcome drink, tra chieu', 'Phong co tong mau am, phu hop cap doi nghi duong ngan ngay.'],
        ['D-701', 'Ocean Breeze Room', 7, 3, '50 m2', '2 Twin', 1650000, 330000, 'https://images.unsplash.com/photo-1596394516093-501ba68a0ba6?auto=format&fit=crop&w=1200&q=80', 'https://images.unsplash.com/photo-1609766857041-ed402ea8069a?auto=format&fit=crop&w=1200&q=80,https://images.unsplash.com/photo-1571508601939-9d5a2e087a7d?auto=format&fit=crop&w=1200&q=80,https://images.unsplash.com/photo-1566195992011-5f6b21e539aa?auto=format&fit=crop&w=1200&q=80', 'Beach Escape', 'An sang buffet, ho boi, dua don bai bien, minibar', 'Phong thoang rong, thich hop cho gia dinh nho hoac nhom ban.'],
        ['D-702', 'Heritage Balcony', 7, 2, '44 m2', '1 King', 1580000, 320000, 'https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?auto=format&fit=crop&w=1200&q=80', 'https://images.unsplash.com/photo-1560448075-cbc16bb4af8e?auto=format&fit=crop&w=1200&q=80,https://images.unsplash.com/photo-1560185127-6ed189bf02f4?auto=format&fit=crop&w=1200&q=80,https://images.unsplash.com/photo-1560185008-b033106af5c3?auto=format&fit=crop&w=1200&q=80', 'Heritage Stay', 'An sang buffet, tra chieu, Wi-Fi, late checkout', 'Phong co ban cong, phong cach boutique va anh sang tu nhien.'],
        ['D-801', 'Junior Suite', 8, 3, '64 m2', '1 King va sofa bed', 2200000, 440000, 'https://images.unsplash.com/photo-1618773928121-c32242e63f39?auto=format&fit=crop&w=1200&q=80', 'https://images.unsplash.com/photo-1591088398332-8a7791972843?auto=format&fit=crop&w=1200&q=80,https://images.unsplash.com/photo-1600585154340-be6161a56a0c?auto=format&fit=crop&w=1200&q=80,https://images.unsplash.com/photo-1600566752355-35792bedcfea?auto=format&fit=crop&w=1200&q=80', 'Suite Privilege', 'An sang buffet, lounge, minibar, spa 30 phut', 'Suite co khu tiep khach rieng, phu hop ky nghi dai ngay.'],
        ['D-802', 'Family Connect', 8, 5, '76 m2', '2 Queen', 2450000, 490000, 'https://images.unsplash.com/photo-1600210492486-724fe5c67fb0?auto=format&fit=crop&w=1200&q=80', 'https://images.unsplash.com/photo-1600210491892-03d54c0aaf87?auto=format&fit=crop&w=1200&q=80,https://images.unsplash.com/photo-1600607687939-ce8a6c25118c?auto=format&fit=crop&w=1200&q=80,https://images.unsplash.com/photo-1600566753086-00f18fb6b3ea?auto=format&fit=crop&w=1200&q=80', 'Family Holiday', 'An sang buffet, khu vui choi tre em, ho boi, minibar', 'Phong gia dinh rong, bo tri tien cho tre em va nguoi lon.'],
        ['D-901', 'Executive Panorama', 9, 2, '82 m2', '1 King', 3150000, 630000, 'https://images.unsplash.com/photo-1600607687644-c7171b42498f?auto=format&fit=crop&w=1200&q=80', 'https://images.unsplash.com/photo-1600607687920-4e2a09cf159d?auto=format&fit=crop&w=1200&q=80,https://images.unsplash.com/photo-1600607688066-890987f18a86?auto=format&fit=crop&w=1200&q=80,https://images.unsplash.com/photo-1600607688969-a5bfcd646154?auto=format&fit=crop&w=1200&q=80', 'Executive Privilege', 'An sang buffet, lounge, dua don san bay 1 chieu, spa 45 phut', 'Suite tang cao voi tam nhin rong va dich vu uu tien.'],
        ['D-902', 'Skyline Suite', 9, 2, '88 m2', '1 King', 3450000, 690000, 'https://images.unsplash.com/photo-1600607688960-e095ff83135c?auto=format&fit=crop&w=1200&q=80', 'https://images.unsplash.com/photo-1600585154526-990dced4db0d?auto=format&fit=crop&w=1200&q=80,https://images.unsplash.com/photo-1600566753051-f0b89df2dd90?auto=format&fit=crop&w=1200&q=80,https://images.unsplash.com/photo-1600573472592-401b489a3cdc?auto=format&fit=crop&w=1200&q=80', 'Skyline Retreat', 'An sang buffet, lounge, minibar, late checkout, spa', 'Suite cao cap co phong khach rieng va khong gian nghi ngoi yen tinh.'],
        ['D-1001', 'Presidential Suite', 10, 4, '128 m2', '1 King va 1 Queen', 5200000, 1040000, 'https://images.unsplash.com/photo-1600566753151-384129cf4e3e?auto=format&fit=crop&w=1200&q=80', 'https://images.unsplash.com/photo-1600566752734-2a0fd6b91a20?auto=format&fit=crop&w=1200&q=80,https://images.unsplash.com/photo-1600607688960-e095ff83135c?auto=format&fit=crop&w=1200&q=80,https://images.unsplash.com/photo-1600585154526-990dced4db0d?auto=format&fit=crop&w=1200&q=80', 'Presidential Service', 'An sang buffet, lounge rieng, dua don san bay, spa, minibar cao cap', 'Suite tong thong voi phong khach, ban an va dac quyen rieng.'],
        ['D-1002', 'Penthouse Retreat', 10, 4, '116 m2', '2 King', 4800000, 960000, 'https://images.unsplash.com/photo-1600607687939-ce8a6c25118c?auto=format&fit=crop&w=1200&q=80', 'https://images.unsplash.com/photo-1600566752355-35792bedcfea?auto=format&fit=crop&w=1200&q=80,https://images.unsplash.com/photo-1600210492493-0946911123ea?auto=format&fit=crop&w=1200&q=80,https://images.unsplash.com/photo-1600566753190-17f0baa2a6c3?auto=format&fit=crop&w=1200&q=80', 'Penthouse Escape', 'An sang buffet, lounge, cocktail toi, spa 60 phut, minibar', 'Penthouse co khong gian mo, phu hop khach VIP va gia dinh cao cap.'],
    ];

    $amenities = 'Wi-Fi toc do cao, TV thong minh, minibar, ket an toan, ban lam viec, do dung tam cao cap';
    $stmtExists = $db->prepare("SELECT id_room FROM rooms WHERE room_number=? LIMIT 1");
    $stmtInsert = $db->prepare("INSERT INTO rooms (id_hotel, room_number, room_type, floor_no, capacity, room_size, bed_type, price_per_night, hourly_rate, status, image_url, gallery_urls, amenities, package_name, package_services, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'available', ?, ?, ?, ?, ?, ?)");
    foreach ($demoRooms as $index => $room) {
        $stmtExists->bind_param('s', $room[0]);
        $stmtExists->execute();
        if ($stmtExists->get_result()->fetch_assoc()) {
            continue;
        }

        $hotelId = (int)$hotels[$index % count($hotels)]['id_hotel'];
        $stmtInsert->bind_param('issiissddssssss', $hotelId, $room[0], $room[1], $room[2], $room[3], $room[4], $room[5], $room[6], $room[7], $room[8], $room[9], $amenities, $room[10], $room[11], $room[12]);
        $stmtInsert->execute();
    }
}

function nights($checkIn, $checkOut): int
{
    $start = strtotime((string)$checkIn);
    $end = strtotime((string)$checkOut);
    if (!$start || !$end || $end <= $start) {
        return 1;
    }
    return max(1, (int)ceil(($end - $start) / 86400));
}

function bookingBillingEnd(array $booking): string
{
    if (!empty($booking['checked_out_at'])) {
        return $booking['checked_out_at'];
    }
    if (($booking['status'] ?? '') === 'checked_in') {
        return date('Y-m-d H:i:s');
    }
    return $booking['expected_check_out_at'] ?: ($booking['check_out'] . ' 12:00:00');
}

function billableHours(array $booking): int
{
    $start = strtotime($booking['check_in_at'] ?: $booking['check_in'] . ' 14:00:00');
    $endValue = bookingBillingEnd($booking);
    $end = strtotime($endValue);
    if (!$start || !$end || $end <= $start) {
        return 1;
    }
    return max(1, (int)ceil(($end - $start) / 3600));
}

function lateCheckoutHours(array $booking): int
{
    $expected = strtotime($booking['expected_check_out_at'] ?: $booking['check_out'] . ' 12:00:00');
    $actual = strtotime(bookingBillingEnd($booking));
    if (!$expected || !$actual || $actual <= $expected) {
        return 0;
    }
    return (int)ceil(($actual - $expected) / 3600);
}

function freeChildrenForCapacity(int $capacity): int
{
    if ($capacity >= 4) {
        return 2;
    }
    if ($capacity >= 2) {
        return 1;
    }
    return 0;
}

function bookingOccupancyPolicy(array $booking): array
{
    $capacity = max(1, (int)($booking['capacity'] ?? 1));
    $adults = max(1, (int)($booking['adults'] ?? 1));
    $children = max(0, (int)($booking['children'] ?? 0));
    $freeChildren = freeChildrenForCapacity($capacity);
    $chargeableChildren = max(0, $children - $freeChildren);
    $billableGuests = $adults + $chargeableChildren;
    $extraGuests = max(0, $billableGuests - $capacity);

    return [
        'capacity' => $capacity,
        'free_children' => $freeChildren,
        'chargeable_children' => $chargeableChildren,
        'billable_guests' => $billableGuests,
        'extra_guests' => $extraGuests,
        'is_valid' => $extraGuests <= 1,
    ];
}

function bookingTotals(mysqli $db, array $booking): array
{
    $lateHours = lateCheckoutHours($booking);
    $lateFee = 0.0;
    $occupancyPolicy = bookingOccupancyPolicy($booking);
    if (($booking['pricing_mode'] ?? 'night') === 'hour') {
        $units = billableHours($booking);
        $baseRoomTotal = $units * (float)$booking['hourly_rate'];
        $unitLabel = $units . ' giờ';
    } else {
        $units = nights($booking['check_in'], $booking['check_out']);
        $lateFee = $lateHours * (float)$booking['hourly_rate'];
        $baseRoomTotal = $units * (float)$booking['price_per_night'];
        $unitLabel = $units . ' đêm';
    }
    $extraGuestRatePercent = max(0, (float)($booking['extra_guest_rate_percent'] ?? 25));
    $extraGuestFee = $occupancyPolicy['extra_guests'] > 0 ? $baseRoomTotal * ($extraGuestRatePercent / 100) : 0.0;
    $roomTotal = $baseRoomTotal + $extraGuestFee + $lateFee;

    $stmt = $db->prepare("SELECT COALESCE(SUM(quantity * price), 0) AS total FROM booking_services WHERE id_booking = ?");
    $stmt->bind_param('i', $booking['id_booking']);
    $stmt->execute();
    $serviceTotal = (float)$stmt->get_result()->fetch_assoc()['total'];

    $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) AS total FROM payments WHERE id_booking = ?");
    $stmt->bind_param('i', $booking['id_booking']);
    $stmt->execute();
    $paid = (float)$stmt->get_result()->fetch_assoc()['total'];

    $subtotal = $roomTotal + $serviceTotal;
    $vat = $subtotal * 0.08;
    $grand = $subtotal + $vat;
    return [
        'units' => $units,
        'unit_label' => $unitLabel,
        'late_hours' => $lateHours,
        'late_fee' => $lateFee,
        'extra_guest_fee' => $extraGuestFee,
        'extra_guest_rate_percent' => $extraGuestRatePercent,
        'extra_guests' => $occupancyPolicy['extra_guests'],
        'free_children' => $occupancyPolicy['free_children'],
        'billable_guests' => $occupancyPolicy['billable_guests'],
        'room' => $roomTotal,
        'service' => $serviceTotal,
        'subtotal' => $subtotal,
        'vat' => $vat,
        'grand' => $grand,
        'paid' => $paid,
        'debt' => max(0, $grand - $paid),
    ];
}

function isRoomAvailable(mysqli $db, int $roomId, string $startAt, string $endAt, int $ignoreBookingId = 0): bool
{
    $checkIn = substr($startAt, 0, 10);
    $checkOut = substr($endAt, 0, 10);
    $sql = "SELECT COUNT(*) AS total FROM bookings
        WHERE id_room = ?
        AND id_booking <> ?
        AND status IN ('booked','checked_in')
        AND check_in < ?
        AND check_out > ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('iiss', $roomId, $ignoreBookingId, $checkOut, $checkIn);
    $stmt->execute();
    return (int)$stmt->get_result()->fetch_assoc()['total'] === 0;
}

function bookingCode(): string
{
    return 'B' . strtoupper(substr(base_convert((string)(time() % 46656), 10, 36), -3)) . random_int(10, 99);
}

function invoiceCode(array $booking): string
{
    return 'HD' . str_pad((string)($booking['id_booking'] ?? 0), 5, '0', STR_PAD_LEFT);
}

function memberTier(float $totalPaid): string
{
    if ($totalPaid > 20000000) {
        return 'diamond';
    }
    if ($totalPaid > 5000000) {
        return 'gold';
    }
    return 'member';
}

function memberTierLabel(string $tier): string
{
    return [
        'member' => 'Member',
        'gold' => 'Gold',
        'diamond' => 'Diamond',
    ][$tier] ?? 'Member';
}

function memberPointRate(string $tier): float
{
    return [
        'member' => 0.03,
        'gold' => 0.07,
        'diamond' => 0.10,
    ][$tier] ?? 0.03;
}

function guestTotalPaid(mysqli $db, int $guestId): float
{
    $stmt = $db->prepare("SELECT COALESCE(SUM(CASE WHEN p.method NOT IN ('Diem tich luy', 'Điểm tích lũy') THEN p.amount ELSE 0 END),0) AS total
        FROM bookings b
        LEFT JOIN payments p ON p.id_booking=b.id_booking
        WHERE b.id_guest=?");
    $stmt->bind_param('i', $guestId);
    $stmt->execute();
    return (float)$stmt->get_result()->fetch_assoc()['total'];
}

function findGuestByIdentity(mysqli $db, string $phone, string $identity, string $email): ?array
{
    $stmt = $db->prepare("SELECT id_guest, full_name, phone, email, identity_no, address FROM guests
        WHERE (phone <> '' AND phone = ?)
           OR (identity_no <> '' AND identity_no = ?)
        ORDER BY id_guest DESC
        LIMIT 1");
    $stmt->bind_param('ss', $phone, $identity);
    $stmt->execute();
    $guest = $stmt->get_result()->fetch_assoc();
    return $guest ?: null;
}

function upsertGuestByIdentity(mysqli $db, string $name, string $phone, string $email, string $identity, string $address = ''): int
{
    $guest = findGuestByIdentity($db, $phone, $identity, $email);
    if ($guest) {
        $guestId = (int)$guest['id_guest'];
        $newName = $name !== '' ? $name : (string)$guest['full_name'];
        $newPhone = $phone !== '' ? $phone : (string)$guest['phone'];
        $newEmail = $email !== '' ? $email : (string)($guest['email'] ?? '');
        $newIdentity = $identity !== '' ? $identity : (string)($guest['identity_no'] ?? '');
        $newAddress = $address !== '' ? $address : (string)($guest['address'] ?? '');
        $stmt = $db->prepare("UPDATE guests SET full_name=?, phone=?, email=?, identity_no=?, address=? WHERE id_guest=?");
        $stmt->bind_param('sssssi', $newName, $newPhone, $newEmail, $newIdentity, $newAddress, $guestId);
        $stmt->execute();
        return $guestId;
    }

    $stmt = $db->prepare("INSERT INTO guests (full_name, phone, email, identity_no, address) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param('sssss', $name, $phone, $email, $identity, $address);
    $stmt->execute();
    return (int)$db->insert_id;
}

function addGuestLoyaltyForPayment(mysqli $db, int $bookingId, float $amount): void
{
    $booking = fetchBooking($db, $bookingId);
    if (!$booking || $amount <= 0) {
        return;
    }
    $guestId = (int)$booking['id_guest'];
    $tier = memberTier(guestTotalPaid($db, $guestId));
    $points = (int)round($amount * memberPointRate($tier));
    $stmt = $db->prepare("UPDATE guests SET loyalty_points=COALESCE(loyalty_points,0)+? WHERE id_guest=?");
    $stmt->bind_param('ii', $points, $guestId);
    $stmt->execute();
}

function awardGuestLoyaltyForClosedBill(mysqli $db, int $bookingId): void
{
    $booking = fetchBooking($db, $bookingId);
    if (!$booking || ($booking['status'] ?? '') !== 'checked_out' || !empty($booking['loyalty_awarded_at'])) {
        return;
    }
    $totals = bookingTotals($db, $booking);
    if ((float)$totals['debt'] > 0.01) {
        return;
    }
    $payments = bookingPayments($db, $bookingId);
    $cashPaid = 0.0;
    foreach ($payments as $payment) {
        if (in_array((string)($payment['method'] ?? ''), ['Diem tich luy', 'Điểm tích lũy'], true)) {
            continue;
        }
        $cashPaid += (float)($payment['amount'] ?? 0);
    }
    if ($cashPaid <= 0) {
        return;
    }
    $guestId = (int)$booking['id_guest'];
    $tier = memberTier(guestTotalPaid($db, $guestId));
    $points = (int)round($cashPaid * memberPointRate($tier));
    if ($points <= 0) {
        return;
    }
    $stmt = $db->prepare("UPDATE guests SET loyalty_points=COALESCE(loyalty_points,0)+? WHERE id_guest=?");
    $stmt->bind_param('ii', $points, $guestId);
    $stmt->execute();
    $awardedAt = date('Y-m-d H:i:s');
    $stmt = $db->prepare("UPDATE bookings SET loyalty_awarded_at=? WHERE id_booking=? AND loyalty_awarded_at IS NULL");
    $stmt->bind_param('si', $awardedAt, $bookingId);
    $stmt->execute();
}

function redeemGuestLoyaltyPoints(mysqli $db, int $guestId, int $points): void
{
    if ($guestId <= 0 || $points <= 0) {
        return;
    }
    $stmt = $db->prepare("UPDATE guests SET loyalty_points=GREATEST(0, COALESCE(loyalty_points,0)-?) WHERE id_guest=?");
    $stmt->bind_param('ii', $points, $guestId);
    $stmt->execute();
}

function fetchBooking(mysqli $db, int $bookingId): ?array
{
    $stmt = $db->prepare("SELECT b.*, g.full_name, g.phone, g.email, g.identity_no, g.address, g.loyalty_points,
            r.id_hotel, r.room_number, r.room_type, r.capacity, r.price_per_night, r.hourly_rate, r.extra_guest_rate_percent, r.package_name, r.package_services,
            h.hotel_name, h.brand_name, h.star_rating, h.address AS hotel_address, h.city AS hotel_city, h.phone AS hotel_phone, h.email AS hotel_email
        FROM bookings b
        JOIN guests g ON g.id_guest = b.id_guest
        JOIN rooms r ON r.id_room = b.id_room
        LEFT JOIN hotels h ON h.id_hotel = r.id_hotel
        WHERE b.id_booking = ?");
    $stmt->bind_param('i', $bookingId);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();
    return $booking ?: null;
}

function bookingServices(mysqli $db, int $bookingId): array
{
    $stmt = $db->prepare("SELECT bs.id_booking_service, bs.id_booking, bs.id_service, bs.quantity, bs.price, bs.created_at, s.service_name, s.unit FROM booking_services bs JOIN services s ON s.id_service = bs.id_service WHERE bs.id_booking = ? ORDER BY bs.id_booking_service");
    $stmt->bind_param('i', $bookingId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function bookingPayments(mysqli $db, int $bookingId): array
{
    $stmt = $db->prepare("SELECT id_payment, id_booking, amount, method, paid_at, note, payer_name, cashier_name FROM payments WHERE id_booking=? ORDER BY paid_at, id_payment");
    $stmt->bind_param('i', $bookingId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function paymentMethodLabel(string $method): string
{
    $method = trim($method);
    if ($method === '') {
        return 'Không rõ';
    }
    if ($method === 'Diem tich luy' || str_contains($method, 'tÃ­ch l') || str_contains($method, 'tich luy')) {
        return 'Điểm tích lũy';
    }
    if (str_contains($method, 'Chuyá') || str_contains($method, 'Chuy?n') || str_contains($method, 'khoáº£n') || str_contains($method, 'kho?n')) {
        return str_contains($method, 'cọc') || str_contains($method, 'cá»c') || str_contains($method, 'c?c')
            ? 'Đã cọc 50% - Chuyển khoản ngân hàng'
            : 'Chuyển khoản ngân hàng';
    }
    if (str_contains($method, 'Tiá»n') || str_contains($method, 'Ti?n')) {
        return 'Tiền mặt';
    }
    if (str_contains($method, 'VÃ­') || str_contains($method, 'V?')) {
        return 'Ví điện tử';
    }
    if (str_contains($method, 'Tháº»') || str_contains($method, 'Th?')) {
        return 'Thẻ';
    }
    if (str_contains($method, 'ÄÃ£ cá»c') || str_contains($method, 'Da coc')) {
        return str_replace(['ÄÃ£ cá»c', 'Da coc'], 'Đã cọc', $method);
    }
    return $method;
}

function paymentMethodTotals(array $payments): array
{
    $totals = [];
    foreach ($payments as $payment) {
        $method = paymentMethodLabel((string)($payment['method'] ?? ''));
        $totals[$method] = ($totals[$method] ?? 0) + (float)($payment['amount'] ?? 0);
    }
    return $totals;
}
