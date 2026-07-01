<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/services/review_service.php';

if (empty($_SESSION['hotel_customer_id'])) {
    header('Location: login.php?mode=customer-login');
    exit;
}

$guestId = (int)$_SESSION['hotel_customer_id'];
$message = '';
$isError = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        verifyCsrfToken();
        $action = $_POST['action'] ?? '';
        if ($action === 'update_profile') {
            $fullName = trim($_POST['full_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $identity = trim($_POST['identity_no'] ?? '');
            $address = trim($_POST['address'] ?? '');

            if ($fullName === '' || $phone === '' || $email === '') {
                throw new Exception('Vui lòng nhập họ tên, điện thoại và email.');
            }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Email không hợp lệ.');
        }

        $stmtCheck = $db->prepare("SELECT id_guest FROM guests WHERE email=? AND id_guest<>? LIMIT 1");
        $stmtCheck->bind_param('si', $email, $guestId);
        $stmtCheck->execute();
        if ($stmtCheck->get_result()->fetch_assoc()) {
            throw new Exception('Email này đã được dùng cho tài khoản khác.');
        }
        $stmtCheckIdentity = $db->prepare("SELECT id_guest FROM guests
            WHERE id_guest<>?
              AND ((phone <> '' AND phone = ?) OR (? <> '' AND identity_no <> '' AND identity_no = ?))
            LIMIT 1");
        $stmtCheckIdentity->bind_param('isss', $guestId, $phone, $identity, $identity);
        $stmtCheckIdentity->execute();
        if ($stmtCheckIdentity->get_result()->fetch_assoc()) {
            throw new Exception('Số điện thoại hoặc CCCD/hộ chiếu đã được dùng cho tài khoản khác.');
        }

        $stmtUpdate = $db->prepare("UPDATE guests SET full_name=?, phone=?, email=?, identity_no=?, address=? WHERE id_guest=?");
            $stmtUpdate->bind_param('sssssi', $fullName, $phone, $email, $identity, $address, $guestId);
            $stmtUpdate->execute();

            $_SESSION['hotel_customer_name'] = $fullName;
            $_SESSION['hotel_customer_email'] = $email;
            logSecurityEvent($db, 'customer_profile_updated', 'success', 'Khach hang cap nhat ho so.', ['guest_id' => $guestId]);
            $message = 'Đã cập nhật thông tin tài khoản.';
        } elseif ($action === 'update_booking') {
            $bookingId = (int)($_POST['id_booking'] ?? 0);
            $checkIn = $_POST['check_in'] ?? '';
            $checkOut = $_POST['check_out'] ?? '';
            $contactName = trim($_POST['contact_name'] ?? '');
            $contactPhone = trim($_POST['contact_phone'] ?? '');
            $adults = max(1, (int)($_POST['adults'] ?? 1));
            $children = max(0, (int)($_POST['children'] ?? 0));
            $note = trim($_POST['note'] ?? '');

            $stmtBooking = $db->prepare("SELECT b.id_booking, b.id_room, b.status, b.customer_edit_count, r.capacity FROM bookings b JOIN rooms r ON r.id_room=b.id_room WHERE b.id_booking=? AND b.id_guest=? LIMIT 1");
            $stmtBooking->bind_param('ii', $bookingId, $guestId);
            $stmtBooking->execute();
            $booking = $stmtBooking->get_result()->fetch_assoc();
            if (!$booking) {
                throw new Exception('Booking không tồn tại.');
            }
            if (($booking['status'] ?? '') !== 'booked') {
                throw new Exception('Chỉ có thể sửa booking đang ở trạng thái đã đặt.');
            }
            if ((int)($booking['customer_edit_count'] ?? 0) >= 1) {
                throw new Exception('Booking này đã được chỉnh sửa một lần. Vui lòng liên hệ lễ tân nếu cần đổi thêm.');
            }
            if ($contactName === '' || $contactPhone === '') {
                throw new Exception('Vui lòng nhập tên gợi nhớ và số điện thoại tạm thời cho booking.');
            }
            if (strtotime($checkOut) <= strtotime($checkIn)) {
                throw new Exception('Ngày trả phòng phải sau ngày nhận phòng.');
            }
            if (($adults + $children) > (int)$booking['capacity']) {
                throw new Exception('Số khách vượt quá sức chứa của phòng.');
            }

            $startAt = $checkIn . ' 14:00:00';
            $endAt = $checkOut . ' 12:00:00';
            if (!isRoomAvailable($db, (int)$booking['id_room'], $startAt, $endAt, $bookingId)) {
                throw new Exception('Phòng đã có booking khác trong khoảng ngày này.');
            }

            $editedAt = date('Y-m-d H:i:s');
            $stmtUpdate = $db->prepare("UPDATE bookings SET check_in=?, check_out=?, check_in_at=?, expected_check_out_at=?, contact_name=?, contact_phone=?, adults=?, children=?, note=?, customer_edit_count=customer_edit_count+1, customer_edited_at=? WHERE id_booking=? AND id_guest=?");
            $stmtUpdate->bind_param('ssssssiissii', $checkIn, $checkOut, $startAt, $endAt, $contactName, $contactPhone, $adults, $children, $note, $editedAt, $bookingId, $guestId);
            $stmtUpdate->execute();
            hotelBlockchainRecordBooking($db, $bookingId, 'booking_updated_by_customer');
            logSecurityEvent($db, 'customer_booking_updated', 'success', 'Khach hang cap nhat booking.', ['booking_id' => $bookingId]);
            $message = 'Đã cập nhật thông tin đặt phòng.';
        } elseif ($action === 'cancel_booking') {
            $bookingId = (int)($_POST['id_booking'] ?? 0);
            $stmtCancel = $db->prepare("UPDATE bookings SET status='cancelled' WHERE id_booking=? AND id_guest=? AND status='booked'");
            $stmtCancel->bind_param('ii', $bookingId, $guestId);
            $stmtCancel->execute();
            if ($stmtCancel->affected_rows <= 0) {
                throw new Exception('Chỉ có thể hủy booking đang ở trạng thái đã đặt.');
            }
            hotelBlockchainRecordBooking($db, $bookingId, 'booking_cancelled_by_customer');
            logSecurityEvent($db, 'customer_booking_cancelled', 'success', 'Khach hang huy booking.', ['booking_id' => $bookingId]);
            $message = 'Đã hủy booking.';
        } elseif ($action === 'submit_review') {
            $message = createBookingReview($db, $guestId, $_POST);
        }
    } catch (Throwable $e) {
        logSecurityEvent($db, 'customer_profile_action_failed', 'failed', $e->getMessage(), ['action' => (string)($_POST['action'] ?? '')]);
        $message = $e->getMessage();
        $isError = true;
    }
}

$stmt = $db->prepare("SELECT id_guest, full_name, phone, email, identity_no, address, loyalty_points, created_at FROM guests WHERE id_guest=? LIMIT 1");
$stmt->bind_param('i', $guestId);
$stmt->execute();
$guest = $stmt->get_result()->fetch_assoc();
if (!$guest) {
    unset($_SESSION['hotel_customer_id'], $_SESSION['hotel_customer_name'], $_SESSION['hotel_customer_email']);
    header('Location: login.php?mode=customer-login');
    exit;
}

$stmt = $db->prepare("SELECT b.*, r.room_number, r.room_type, r.capacity, r.price_per_night, r.hourly_rate, r.extra_guest_rate_percent,
        h.hotel_name, h.city, h.address AS hotel_address, h.city AS hotel_city, h.phone AS hotel_phone, h.email AS hotel_email,
        rv.id_review, rv.room_rating AS reviewed_room_rating, rv.service_rating AS reviewed_service_rating
    FROM bookings b
    JOIN rooms r ON r.id_room=b.id_room
    LEFT JOIN hotels h ON h.id_hotel=r.id_hotel
    LEFT JOIN reviews rv ON rv.id_booking=b.id_booking
    WHERE b.id_guest=?
    ORDER BY b.created_at DESC, b.id_booking DESC");
$stmt->bind_param('i', $guestId);
$stmt->execute();
$bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$activeBookings = array_values(array_filter($bookings, static function (array $booking): bool {
    return in_array((string)$booking['status'], ['booked', 'checked_in'], true);
}));
$pendingReviewBookings = array_values(array_filter($bookings, static function (array $booking): bool {
    return (string)$booking['status'] === 'checked_out' && empty($booking['id_review']);
}));
$historyBookings = array_values(array_filter($bookings, static function (array $booking): bool {
    return (string)$booking['status'] === 'cancelled' || ((string)$booking['status'] === 'checked_out' && !empty($booking['id_review']));
}));
$invoiceBookings = array_values(array_filter($bookings, static function (array $booking): bool {
    return in_array((string)$booking['status'], ['checked_out', 'cancelled'], true);
}));

$totalPaid = guestTotalPaid($db, $guestId);
$tier = memberTier($totalPaid);
$profileHeroImage = localizeRemoteImage('https://images.unsplash.com/photo-1584132967334-10e028bd69f7?auto=format&fit=crop&w=2200&q=86', 'profile');
$statusLabels = [
    'booked' => 'Đã đặt',
    'checked_in' => 'Đã nhận phòng',
    'checked_out' => 'Đã trả phòng',
    'cancelled' => 'Đã hủy',
];

function profileDateTime(?string $value): string
{
    if (!$value) {
        return '-';
    }
    $time = strtotime($value);
    return $time ? date('d/m/Y H:i', $time) : $value;
}

function profileBillMeta(array $booking, array $payments): array
{
    $latestPayment = $payments ? $payments[count($payments) - 1] : [];
    $cashierName = '';
    foreach (array_reverse($payments) as $payment) {
        $cashierName = trim((string)($payment['cashier_name'] ?? ''));
        if ($cashierName !== '') {
            break;
        }
    }
    $issuedAt = trim((string)($latestPayment['paid_at'] ?? ''));
    $checkedOutAt = trim((string)($booking['checked_out_at'] ?? ''));
    if ($checkedOutAt !== '' && ($issuedAt === '' || strtotime($checkedOutAt) > strtotime($issuedAt))) {
        $issuedAt = $checkedOutAt;
    }
    if ($issuedAt === '') {
        $issuedAt = date('Y-m-d H:i:s');
    }
    return [
        'issued_at' => $issuedAt,
        'cashier_name' => $cashierName,
    ];
}
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php $pageTitle = 'Trang cá nhân - Spotki Hotel'; include __DIR__ . '/includes/layout/assets.php'; ?>
    <style>
        :root { --red:#dc2626; --dark:#111827; --muted:#6b7280; --line:#e5e7eb; --bg:#f6f7fb; }
        body { margin:0; background:var(--bg); color:var(--dark); font-family:"Inter","Segoe UI",Arial,sans-serif; font-size:15px; line-height:1.6; -webkit-font-smoothing:antialiased; text-rendering:optimizeLegibility; }
        .site-header { position:fixed; z-index:30; top:0; left:0; right:0; transition:box-shadow .24s ease; }
        .hotel-navbar { border-bottom:1px solid transparent; padding:10px 0 0; transition:background .24s ease, padding .24s ease, box-shadow .24s ease, backdrop-filter .24s ease; }
        .hotel-navbar .container { min-height:58px; display:grid; grid-template-columns:1fr auto 1fr; align-items:center; gap:18px; }
        .brand { color:#fff; text-decoration:none; font-weight:500; font-size:19px; display:flex; align-items:center; gap:10px; letter-spacing:.01em; transition:font-size .24s ease; }
        .brand i { width:36px; height:36px; border-radius:50%; background:rgba(255,255,255,.15); display:inline-flex; align-items:center; justify-content:center; box-shadow:0 0 0 2px rgba(255,255,255,.22); transition:width .24s ease,height .24s ease,background .24s ease; }
        .links { display:flex; align-items:center; gap:4px; }
        .links a { color:#fff; text-decoration:none; font-weight:500; font-size:14px; text-transform:uppercase; padding:16px 12px 13px; border-bottom:2px solid transparent; text-shadow:0 2px 10px rgba(0,0,0,.34); letter-spacing:0; }
        .links a:hover,.links a.active { border-bottom-color:#fff; }
        .header-actions { display:flex; justify-content:flex-end; align-items:center; gap:10px; }
        .header-icon { width:30px; height:30px; display:inline-flex; align-items:center; justify-content:center; border-radius:50%; background:rgba(255,255,255,.14); color:#fff; text-decoration:none; }
        .site-header.is-scrolled .hotel-navbar { padding:0; background:rgba(17,24,39,.94); border-bottom-color:rgba(255,255,255,.08); box-shadow:0 8px 22px rgba(15,23,42,.18); backdrop-filter:blur(12px); }
        .site-header.is-scrolled .brand { font-size:16px; }
        .site-header.is-scrolled .brand i { width:26px; height:26px; background:rgba(220,38,38,.9); }
        .site-header.is-scrolled .links a { padding:9px 12px 11px; text-shadow:none; }
        .profile-hero { min-height:62svh; display:flex; align-items:flex-end; padding:150px 0 62px; color:#fff; position:relative; overflow:hidden; background:url('<?php echo h($profileHeroImage); ?>') center/cover; }
        .profile-hero:before { content:""; position:absolute; inset:0; background:linear-gradient(90deg,rgba(17,24,39,.86),rgba(17,24,39,.34),rgba(17,24,39,.10)); }
        .profile-hero:after { content:""; position:absolute; left:0; right:0; bottom:0; height:70px; background:linear-gradient(0deg,rgba(246,247,251,.76),rgba(246,247,251,0)); pointer-events:none; }
        .profile-hero .container { position:relative; z-index:1; }
        .profile-hero h1 { font-size:clamp(42px,7vw,86px); line-height:.95; font-weight:900; margin:0 0 16px; letter-spacing:0; max-width:900px; }
        .profile-hero p { color:rgba(255,255,255,.86); margin:0; max-width:720px; font-size:18px; line-height:1.65; }
        .section { padding:38px 0 70px; }
        .summary-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-bottom:18px; }
        .summary-card,.panel { background:#fff; border:1px solid var(--line); border-radius:8px; box-shadow:0 12px 30px rgba(15,23,42,.08); }
        .summary-card { padding:18px; }
        .summary-card span { display:block; color:var(--muted); font-size:13px; font-weight:800; }
        .summary-card strong { display:block; margin-top:4px; font-size:24px; line-height:1.1; }
        .panel { padding:18px; }
        .panel-head { display:flex; justify-content:space-between; align-items:end; gap:16px; margin-bottom:14px; }
        .modal-mask { position:fixed; inset:0; z-index:60; display:none; align-items:center; justify-content:center; padding:18px; background:rgba(17,24,39,.62); }
        .modal-mask.is-open { display:flex; }
        .modal-panel { width:min(620px,100%); max-height:calc(100vh - 36px); overflow:auto; background:#fff; border-radius:8px; box-shadow:0 24px 70px rgba(0,0,0,.28); }
        .modal-panel.invoice-bill { width:min(780px,100%); }
        .modal-head { display:flex; justify-content:space-between; align-items:center; gap:16px; padding:16px 18px; border-bottom:1px solid var(--line); }
        .modal-body { padding:18px; }
        .modal-panel.invoice-bill .modal-body { max-height:calc(100vh - 150px); overflow:auto; }
        .btn-hotel { background:var(--red); border-color:var(--red); color:#fff; font-weight:900; }
        .btn-hotel:hover { background:#991b1b; border-color:#991b1b; color:#fff; }
        .booking-actions { display:flex; flex-wrap:wrap; gap:8px; }
        .btn-danger-soft { background:#fef2f2; border-color:#fecaca; color:#991b1b; font-weight:800; }
        .btn-danger-soft:hover { background:#fee2e2; color:#7f1d1d; }
        .kicker { color:var(--red); font-size:12px; font-weight:900; text-transform:uppercase; letter-spacing:.08em; }
        .status-pill { display:inline-flex; align-items:center; min-height:26px; padding:4px 9px; border-radius:999px; background:#f3f4f6; color:#374151; font-size:12px; font-weight:800; }
        .status-booked { background:#eff6ff; color:#1d4ed8; }
        .status-checked_in { background:#ecfdf5; color:#047857; }
        .status-checked_out { background:#f3f4f6; color:#374151; }
        .status-cancelled { background:#fef2f2; color:#b91c1c; }
        .table { vertical-align:middle; }
        .money { font-weight:900; color:#991b1b; white-space:nowrap; }
        .review-stars { color:#f59e0b; font-size:16px; letter-spacing:1px; white-space:nowrap; }
        .review-form-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
        .review-form-grid textarea { grid-column:1 / -1; }
        .invoice-sheet { display:grid; gap:16px; color:#0f172a; background:#fff; }
        .invoice-title { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:18px; align-items:start; padding:18px; border:1px solid #dbe3ee; border-radius:8px; background:linear-gradient(180deg,#fff,#f8fafc); }
        .invoice-brand { display:flex; gap:12px; align-items:flex-start; }
        .invoice-logo { width:48px; height:48px; flex:0 0 48px; border-radius:8px; display:inline-flex; align-items:center; justify-content:center; background:#991b1b; color:#fff; font-size:22px; box-shadow:inset 0 -8px 18px rgba(0,0,0,.12); }
        .invoice-title h2 { margin:0; font-size:24px; font-weight:850; letter-spacing:0; }
        .invoice-code { min-width:190px; text-align:right; font-size:13px; color:var(--muted); }
        .invoice-code strong { display:block; color:#0f172a; font-size:18px; }
        .invoice-info-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
        .invoice-box { border:1px solid #dbe3ee; border-radius:8px; padding:12px; background:#fff; }
        .invoice-box-title { font-size:12px; font-weight:800; text-transform:uppercase; color:#64748b; margin-bottom:8px; }
        .invoice-row { display:flex; justify-content:space-between; gap:12px; padding:4px 0; font-size:14px; }
        .invoice-row span { color:#64748b; }
        .invoice-row strong { text-align:right; }
        .invoice-table-wrap { overflow-x:auto; }
        .invoice-table { width:100%; border-collapse:separate; border-spacing:0; border:1px solid #dbe3ee; border-radius:8px; overflow:hidden; }
        .invoice-table th { background:#f1f5f9; color:#475569; font-size:12px; text-transform:uppercase; padding:11px 12px; border-bottom:1px solid #dbe3ee; white-space:nowrap; }
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
        .point-badge { display:inline-flex; align-items:center; gap:6px; padding:5px 9px; border-radius:999px; background:#fff7ed; color:#9a3412; font-weight:800; white-space:nowrap; }
        .point-badge i { color:#f59e0b; }
        @media (max-width:992px) { .summary-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
        @media (max-width:992px) { .profile-hero { min-height:560px; } }
        @media (max-width:700px) { .invoice-info-grid,.invoice-summary,.invoice-title { grid-template-columns:1fr; } .invoice-code { min-width:0; text-align:left; } .invoice-summary .invoice-total-box { grid-column:1; } .invoice-table { min-width:620px; } }
        @media (max-width:640px) { .links { display:none; } .summary-grid,.review-form-grid { grid-template-columns:1fr; } .panel { padding:14px; } .profile-hero { min-height:520px; padding-top:120px; } .profile-hero h1 { font-size:42px; } .profile-hero p { font-size:16px; } }
        @media print {
            body.is-printing-invoice * { visibility:hidden; }
            body.is-printing-invoice .modal-mask.is-open .print-invoice,
            body.is-printing-invoice .modal-mask.is-open .print-invoice * { visibility:visible; }
            body.is-printing-invoice .modal-mask.is-open .print-invoice { position:absolute; inset:0; max-height:none; box-shadow:none; border:0; }
            body.is-printing-invoice .no-print { display:none !important; }
        }
    </style>
</head>
<body>
<?php $activePage = ''; $navClass = 'hotel-navbar'; $servicesLabel = 'Dịch vụ và tiện ích'; $showPhone = true; include __DIR__ . '/includes/layout/header.php'; ?>

<header class="profile-hero">
    <div class="container">
        <div class="kicker mb-2">Tài khoản của tôi</div>
        <h1><?php echo h($guest['full_name']); ?></h1>
        <p>Quản lý thông tin tài khoản, theo dõi lịch sử booking và số điểm tích lũy tại Spotki Hotel.</p>
    </div>
</header>

<main class="section">
    <div class="container">
        <?php if ($message !== ''): ?>
            <div class="alert <?php echo $isError ? 'alert-danger' : 'alert-success'; ?> mb-3"><?php echo h($message); ?></div>
        <?php endif; ?>
        <div class="summary-grid">
            <div class="summary-card"><span>Điểm hiện có</span><strong><?php echo number_format((int)($guest['loyalty_points'] ?? 0), 0, ',', '.'); ?></strong></div>
            <div class="summary-card"><span>Hạng thành viên</span><strong><?php echo h(memberTierLabel($tier)); ?></strong></div>
            <div class="summary-card"><span>Tổng đã thanh toán</span><strong><?php echo money($totalPaid); ?></strong></div>
            <div class="summary-card"><span>Số booking</span><strong><?php echo count($bookings); ?></strong></div>
        </div>

        <div class="panel mb-3">
            <div class="panel-head">
                <div>
                    <div class="kicker">Hồ sơ</div>
                    <h2 class="h5 fw-bold mb-0">Thông tin tài khoản</h2>
                </div>
                <button class="btn btn-outline-dark btn-sm" type="button" id="openProfileModal"><i class="fa fa-pen-to-square"></i> Sửa thông tin</button>
            </div>
            <div class="row g-3">
                <div class="col-md-3"><div class="text-muted small fw-bold">Điện thoại</div><div><?php echo h($guest['phone'] ?: '-'); ?></div></div>
                <div class="col-md-3"><div class="text-muted small fw-bold">Email</div><div><?php echo h($guest['email'] ?: '-'); ?></div></div>
                <div class="col-md-3"><div class="text-muted small fw-bold">CCCD/Hộ chiếu</div><div><?php echo h($guest['identity_no'] ?: '-'); ?></div></div>
                <div class="col-md-3"><div class="text-muted small fw-bold">Ngày tạo</div><div><?php echo h(date('d/m/Y', strtotime($guest['created_at']))); ?></div></div>
            </div>
        </div>

        <div class="panel mb-3">
            <div class="panel-head">
                <div>
                    <div class="kicker">Phòng đang đặt</div>
                    <h2 class="h5 fw-bold mb-0">Phòng đang đặt</h2>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead><tr><th>Mã</th><th>Liên hệ tạm</th><th>Khách sạn</th><th>Phòng</th><th>Thời gian</th><th>Khách</th><th>Trạng thái</th><th>Quản lý</th></tr></thead>
                    <tbody>
                    <?php if (!$activeBookings): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">Bạn chưa có phòng đang đặt.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($activeBookings as $booking): ?>
                        <tr>
                            <td class="fw-bold"><?php echo h($booking['booking_code']); ?></td>
                            <td><?php echo h($booking['contact_name'] ?: $guest['full_name']); ?><div class="text-muted small"><?php echo h($booking['contact_phone'] ?: $guest['phone']); ?></div></td>
                            <td><?php echo h($booking['hotel_name'] ?: 'Spotki Hotel'); ?><div class="text-muted small"><?php echo h($booking['city'] ?: '-'); ?></div></td>
                            <td><?php echo h($booking['room_type']); ?><div class="text-muted small">Phòng <?php echo h($booking['room_number']); ?></div></td>
                            <td><?php echo h(date('d/m/Y', strtotime($booking['check_in']))); ?> - <?php echo h(date('d/m/Y', strtotime($booking['check_out']))); ?></td>
                            <td><?php echo (int)$booking['adults']; ?> người lớn, <?php echo (int)$booking['children']; ?> trẻ em</td>
                            <td><span class="status-pill status-<?php echo h($booking['status']); ?>"><?php echo h($statusLabels[$booking['status']] ?? $booking['status']); ?></span></td>
                            <td>
                                <?php if (($booking['status'] ?? '') === 'booked'): ?>
                                    <div class="booking-actions">
                                        <?php if ((int)($booking['customer_edit_count'] ?? 0) < 1): ?>
                                            <button class="btn btn-outline-dark btn-sm" type="button" data-open-booking-modal="bookingModal<?php echo (int)$booking['id_booking']; ?>"><i class="fa fa-pen-to-square"></i> Sửa</button>
                                        <?php else: ?>
                                            <span class="status-pill status-cleaning">Đã sửa booking</span>
                                        <?php endif; ?>
                                        <form method="post" data-cancel-booking>
                                            <input type="hidden" name="action" value="cancel_booking">
                                            <input type="hidden" name="id_booking" value="<?php echo (int)$booking['id_booking']; ?>">
                                            <button class="btn btn-danger-soft btn-sm" type="submit"><i class="fa fa-ban"></i> Hủy</button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted small">Đã nhận phòng, vui lòng liên hệ lễ tân.</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel mb-3">
            <div class="panel-head">
                <div>
                    <div class="kicker">Chờ đánh giá</div>
                    <h2 class="h5 fw-bold mb-0">Phòng đã trả chờ đánh giá</h2>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead><tr><th>Mã</th><th>Khách sạn</th><th>Phòng</th><th>Thời gian</th><th>Tổng bill</th><th>Xem hóa đơn</th><th>Thao tác</th></tr></thead>
                    <tbody>
                    <?php if (!$pendingReviewBookings): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">Bạn chưa có phòng nào đang chờ đánh giá.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($pendingReviewBookings as $booking): ?>
                        <?php $totals = bookingTotals($db, $booking); ?>
                        <tr>
                            <td class="fw-bold"><?php echo h($booking['booking_code']); ?></td>
                            <td><?php echo h($booking['hotel_name'] ?: 'Spotki Hotel'); ?><div class="text-muted small"><?php echo h($booking['city'] ?: '-'); ?></div></td>
                            <td><?php echo h($booking['room_type']); ?><div class="text-muted small">Phòng <?php echo h($booking['room_number']); ?></div></td>
                            <td><?php echo h(date('d/m/Y', strtotime($booking['check_in']))); ?> - <?php echo h(date('d/m/Y', strtotime($booking['check_out']))); ?></td>
                            <td class="money"><?php echo money($totals['grand']); ?></td>
                            <td><button class="btn btn-outline-dark btn-sm" type="button" data-open-booking-modal="invoiceModal<?php echo (int)$booking['id_booking']; ?>"><i class="fa fa-file-invoice"></i> Xem hóa đơn</button></td>
                            <td><button class="btn btn-hotel btn-sm" type="button" data-open-booking-modal="reviewModal<?php echo (int)$booking['id_booking']; ?>"><i class="fa fa-star"></i> Đánh giá ngay</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <div>
                    <div class="kicker">Booking history</div>
                    <h2 class="h5 fw-bold mb-0">Lịch sử booking</h2>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead><tr><th>Mã</th><th>Khách sạn</th><th>Phòng</th><th>Thời gian</th><th>Trạng thái</th><th>Tổng bill</th><th>Xem hóa đơn</th><th>Còn lại</th></tr></thead>
                    <tbody>
                    <?php if (!$historyBookings): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">Bạn chưa có booking đã trả hoặc đã hủy.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($historyBookings as $booking): ?>
                        <?php $totals = bookingTotals($db, $booking); ?>
                        <tr>
                            <td class="fw-bold"><?php echo h($booking['booking_code']); ?></td>
                            <td><?php echo h($booking['hotel_name'] ?: 'Spotki Hotel'); ?><div class="text-muted small"><?php echo h($booking['city'] ?: '-'); ?></div></td>
                            <td><?php echo h($booking['room_type']); ?><div class="text-muted small">Phòng <?php echo h($booking['room_number']); ?></div></td>
                            <td><?php echo h(date('d/m/Y', strtotime($booking['check_in']))); ?> - <?php echo h(date('d/m/Y', strtotime($booking['check_out']))); ?></td>
                            <td><span class="status-pill status-<?php echo h($booking['status']); ?>"><?php echo h($statusLabels[$booking['status']] ?? $booking['status']); ?></span></td>
                            <td class="money"><?php echo money($totals['grand']); ?></td>
                            <td>
                                <?php if (($booking['status'] ?? '') === 'checked_out' || (float)$totals['paid'] > 0): ?>
                                    <button class="btn btn-outline-dark btn-sm" type="button" data-open-booking-modal="invoiceModal<?php echo (int)$booking['id_booking']; ?>"><i class="fa fa-file-invoice"></i> Xem hóa đơn</button>
                                <?php else: ?>
                                    <span class="text-muted small">Chưa có hóa đơn</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo money($totals['debt']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<div class="modal-mask" id="profileModal" aria-hidden="true">
    <form class="modal-panel" method="post">
        <input type="hidden" name="action" value="update_profile">
        <div class="modal-head">
            <div>
                <div class="kicker">Account</div>
                <h2 class="h5 fw-bold mb-0">Sửa thông tin cá nhân</h2>
            </div>
            <button class="btn btn-light" type="button" id="closeProfileModal" aria-label="Đóng"><i class="fa fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold">Họ tên</label>
                    <input class="form-control" name="full_name" value="<?php echo h($guest['full_name']); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">Điện thoại</label>
                    <input class="form-control" name="phone" value="<?php echo h($guest['phone']); ?>" required>
                </div>
                <div class="col-12">
                    <label class="form-label fw-bold">Email</label>
                    <input class="form-control" type="email" name="email" value="<?php echo h($guest['email']); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">CCCD/Hộ chiếu</label>
                    <input class="form-control" name="identity_no" value="<?php echo h($guest['identity_no']); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">Địa chỉ</label>
                    <input class="form-control" name="address" value="<?php echo h($guest['address']); ?>">
                </div>
                <div class="col-12 d-grid">
                    <button class="btn btn-hotel" type="submit">Lưu thay đổi</button>
                </div>
            </div>
        </div>
    </form>
</div>

<?php foreach ($invoiceBookings as $booking): ?>
    <?php
        $invoiceTotals = bookingTotals($db, $booking);
        if (($booking['status'] ?? '') !== 'checked_out' && (float)$invoiceTotals['paid'] <= 0) {
            continue;
        }
        $invoiceServices = bookingServices($db, (int)$booking['id_booking']);
        $invoicePayments = bookingPayments($db, (int)$booking['id_booking']);
        $invoicePaymentTotals = paymentMethodTotals($invoicePayments);
        $invoiceBillMeta = profileBillMeta($booking, $invoicePayments);
        $invoiceHourly = ($booking['pricing_mode'] ?? 'night') === 'hour';
        $invoiceStart = $booking['check_in_at'] ?: ($booking['check_in'] . ' 14:00:00');
        $invoiceEnd = bookingBillingEnd($booking);
        $invoiceRoomBaseTotal = $invoiceTotals['room'] - $invoiceTotals['late_fee'] - $invoiceTotals['extra_guest_fee'];
        $invoiceUnitPrice = $invoiceHourly ? (float)$booking['hourly_rate'] : (float)$booking['price_per_night'];
        $invoiceUnitText = $invoiceHourly ? 'giờ' : 'đêm';
        $invoiceRoomChargeLabel = $invoiceHourly ? 'Tiền phòng theo giờ' : 'Tiền phòng qua đêm';
        $invoicePaidStatus = $invoiceTotals['debt'] <= 0 ? 'Đã thanh toán' : 'Chưa thanh toán đủ';
        $invoiceStatusClass = $invoiceTotals['debt'] <= 0 ? 'checked_in' : 'cancelled';
        $hotelLocation = trim(($booking['hotel_address'] ?? '') . ', ' . ($booking['hotel_city'] ?? $booking['city'] ?? ''), ', ');
        $hotelContact = trim(($booking['hotel_phone'] ?? '') . ' | ' . ($booking['hotel_email'] ?? ''), ' |');
    ?>
    <div class="modal-mask" id="invoiceModal<?php echo (int)$booking['id_booking']; ?>" aria-hidden="true">
        <div class="modal-panel invoice-bill print-invoice">
            <div class="modal-head no-print">
                <div>
                    <div class="kicker">Invoice</div>
                    <h2 class="h5 fw-bold mb-0">Hóa đơn thanh toán</h2>
                </div>
                <button class="btn btn-light" type="button" data-close-booking-modal aria-label="Đóng"><i class="fa fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <div class="invoice-sheet">
                    <div class="invoice-title">
                        <div class="invoice-brand">
                            <div class="invoice-logo"><i class="fa fa-hotel"></i></div>
                            <div>
                                <div class="kicker">Spotki Hotels</div>
                                <h2>Hóa đơn thanh toán</h2>
                                <div class="text-muted fw-bold"><?php echo h($booking['hotel_name'] ?: 'Spotki Hotel'); ?></div>
                                <?php if ($hotelLocation !== ''): ?><div class="small text-muted"><?php echo h($hotelLocation); ?></div><?php endif; ?>
                                <?php if ($hotelContact !== ''): ?><div class="small text-muted"><?php echo h($hotelContact); ?></div><?php endif; ?>
                                <div class="mt-2"><span class="status-pill status-<?php echo h($invoiceStatusClass); ?>"><?php echo h($invoicePaidStatus); ?></span></div>
                            </div>
                        </div>
                        <div class="invoice-code">
                            Mã hóa đơn
                            <strong><?php echo h(invoiceCode($booking)); ?></strong>
                            <div>Booking <?php echo h($booking['booking_code']); ?></div>
                            <div>Giờ ra bill <?php echo h(profileDateTime($invoiceBillMeta['issued_at'])); ?></div>
                            <div>Nhân viên: <?php echo h($invoiceBillMeta['cashier_name'] ?: '-'); ?></div>
                            <button class="btn btn-outline-dark btn-sm no-print mt-2" type="button" data-print-invoice><i class="fa fa-print"></i> In hóa đơn</button>
                        </div>
                    </div>

                    <div class="invoice-info-grid">
                        <div class="invoice-box">
                            <div class="invoice-box-title">Thông tin khách</div>
                            <div class="invoice-row"><span>Tên khách</span><strong><?php echo h($booking['contact_name'] ?: $guest['full_name']); ?></strong></div>
                            <div class="invoice-row"><span>SĐT</span><strong><?php echo h($booking['contact_phone'] ?: $guest['phone']); ?></strong></div>
                            <div class="invoice-row"><span>Hạng member</span><strong><?php echo h(memberTierLabel($tier)); ?></strong></div>
                            <div class="invoice-row"><span>Điểm hiện có</span><strong><span class="point-badge"><i class="fa fa-coins"></i><?php echo h(points($guest['loyalty_points'] ?? 0)); ?></span></strong></div>
                        </div>
                        <div class="invoice-box">
                            <div class="invoice-box-title">Thông tin lưu trú</div>
                            <div class="invoice-row"><span>Phòng</span><strong><?php echo h($booking['room_number'] . ' - ' . $booking['room_type']); ?></strong></div>
                            <div class="invoice-row"><span>Giờ vào</span><strong><?php echo h(profileDateTime($invoiceStart)); ?></strong></div>
                            <div class="invoice-row"><span>Giờ ra</span><strong><?php echo h(profileDateTime($invoiceEnd)); ?></strong></div>
                        </div>
                    </div>

                    <div class="invoice-table-wrap">
                        <table class="invoice-table">
                            <thead><tr><th>Hạng mục</th><th>Đơn giá</th><th>Số lượng</th><th class="text-end">Thành tiền</th></tr></thead>
                            <tbody>
                                <tr>
                                    <td><strong><?php echo h($invoiceRoomChargeLabel); ?></strong></td>
                                    <td><?php echo money($invoiceUnitPrice); ?>/<?php echo h($invoiceUnitText); ?></td>
                                    <td><?php echo h($invoiceTotals['unit_label']); ?></td>
                                    <td class="money-cell"><?php echo money($invoiceRoomBaseTotal); ?></td>
                                </tr>
                                <?php if ($invoiceTotals['extra_guest_fee'] > 0): ?>
                                    <tr>
                                        <td><strong>Phụ thu thêm người</strong><div class="invoice-note">Vượt <?php echo (int)$invoiceTotals['extra_guests']; ?> người so với sức chứa phòng.</div></td>
                                        <td><?php echo rtrim(rtrim(number_format((float)($invoiceTotals['extra_guest_rate_percent'] ?? 25), 2, ',', '.'), '0'), ','); ?>% tiền phòng</td>
                                        <td>1</td>
                                        <td class="money-cell text-danger"><?php echo money($invoiceTotals['extra_guest_fee']); ?></td>
                                    </tr>
                                <?php endif; ?>
                                <?php if (!$invoiceHourly): ?>
                                    <tr>
                                        <td><strong>Phụ thu check-out muộn</strong></td>
                                        <td><?php echo money((float)$booking['hourly_rate']); ?>/giờ</td>
                                        <td><?php echo (int)$invoiceTotals['late_hours']; ?> giờ</td>
                                        <td class="money-cell <?php echo $invoiceTotals['late_fee'] > 0 ? 'text-danger' : ''; ?>"><?php echo money($invoiceTotals['late_fee']); ?></td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($invoiceServices as $item): ?>
                                    <tr>
                                        <td><strong><?php echo h($item['service_name']); ?></strong></td>
                                        <td><?php echo money($item['price']); ?>/<?php echo h($item['unit']); ?></td>
                                        <td><?php echo (int)$item['quantity']; ?></td>
                                        <td class="money-cell"><?php echo money((float)$item['price'] * (int)$item['quantity']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="invoice-summary">
                        <div class="invoice-total-box">
                            <div class="invoice-total-row"><span>Tiền phòng</span><strong><?php echo money($invoiceRoomBaseTotal); ?></strong></div>
                            <?php if ($invoiceTotals['extra_guest_fee'] > 0): ?><div class="invoice-total-row"><span>Phụ thu thêm người</span><strong><?php echo money($invoiceTotals['extra_guest_fee']); ?></strong></div><?php endif; ?>
                            <?php if (!$invoiceHourly): ?><div class="invoice-total-row"><span>Trả phòng muộn</span><strong><?php echo money($invoiceTotals['late_fee']); ?></strong></div><?php endif; ?>
                            <div class="invoice-total-row"><span>Đồ ăn & đồ uống</span><strong><?php echo money($invoiceTotals['service']); ?></strong></div>
                            <div class="invoice-total-row"><span>Tạm tính</span><strong><?php echo money($invoiceTotals['subtotal']); ?></strong></div>
                            <div class="invoice-total-row"><span>VAT 8%</span><strong><?php echo money($invoiceTotals['vat']); ?></strong></div>
                            <div class="invoice-total-row grand"><span>Tổng cộng</span><strong><?php echo money($invoiceTotals['grand']); ?></strong></div>
                            <?php foreach ($invoicePaymentTotals as $method => $amount): ?>
                                <div class="invoice-total-row payment-method"><span><?php echo h($method); ?></span><strong><?php echo money($amount); ?></strong></div>
                            <?php endforeach; ?>
                            <div class="invoice-total-row debt"><span>Còn phải thu</span><strong><?php echo money($invoiceTotals['debt']); ?></strong></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php foreach ($pendingReviewBookings as $booking): ?>
    <div class="modal-mask" id="reviewModal<?php echo (int)$booking['id_booking']; ?>" aria-hidden="true">
        <form class="modal-panel" method="post">
            <input type="hidden" name="action" value="submit_review">
            <input type="hidden" name="id_booking" value="<?php echo (int)$booking['id_booking']; ?>">
            <div class="modal-head">
                <div>
                    <div class="kicker">Đánh giá lưu trú</div>
                    <h2 class="h5 fw-bold mb-0"><?php echo h($booking['room_type']); ?> - Phòng <?php echo h($booking['room_number']); ?></h2>
                    <div class="small text-muted"><?php echo h($booking['booking_code'] . ' · ' . ($booking['hotel_name'] ?: 'Spotki Hotel')); ?></div>
                </div>
                <button class="btn btn-light" type="button" data-close-booking-modal aria-label="Đóng"><i class="fa fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <div class="review-form-grid">
                    <div>
                        <label class="form-label fw-bold">Phòng</label>
                        <select class="form-control" name="room_rating" required>
                            <option value="5">5 sao - Rất hài lòng</option>
                            <option value="4">4 sao - Hài lòng</option>
                            <option value="3">3 sao - Bình thường</option>
                            <option value="2">2 sao - Chưa tốt</option>
                            <option value="1">1 sao - Không hài lòng</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label fw-bold">Dịch vụ</label>
                        <select class="form-control" name="service_rating" required>
                            <option value="5">5 sao - Rất hài lòng</option>
                            <option value="4">4 sao - Hài lòng</option>
                            <option value="3">3 sao - Bình thường</option>
                            <option value="2">2 sao - Chưa tốt</option>
                            <option value="1">1 sao - Không hài lòng</option>
                        </select>
                    </div>
                    <textarea class="form-control" name="comment" rows="4" maxlength="1500" placeholder="Chia sẻ ngắn gọn trải nghiệm của bạn"></textarea>
                    <button class="btn btn-hotel" type="submit"><i class="fa fa-paper-plane"></i> Gửi đánh giá</button>
                </div>
            </div>
        </form>
    </div>
<?php endforeach; ?>

<?php foreach ($activeBookings as $booking): ?>
    <?php if (($booking['status'] ?? '') !== 'booked') { continue; } ?>
    <div class="modal-mask" id="bookingModal<?php echo (int)$booking['id_booking']; ?>" aria-hidden="true">
        <form class="modal-panel" method="post">
            <input type="hidden" name="action" value="update_booking">
            <input type="hidden" name="id_booking" value="<?php echo (int)$booking['id_booking']; ?>">
            <div class="modal-head">
                <div>
                    <div class="kicker">Đặt phòng</div>
                    <h2 class="h5 fw-bold mb-0">Sửa thông tin đặt phòng</h2>
                    <div class="small text-muted"><?php echo h($booking['booking_code'] . ' - Phòng ' . $booking['room_number']); ?></div>
                </div>
                <button class="btn btn-light" type="button" data-close-booking-modal aria-label="Đóng"><i class="fa fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Tên gợi nhớ</label>
                        <input class="form-control" name="contact_name" value="<?php echo h($booking['contact_name'] ?: $guest['full_name']); ?>" required>
                        <div class="small text-muted mt-1">Chỉ áp dụng cho booking này, không đổi tên tài khoản.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Số điện thoại tạm thời</label>
                        <input class="form-control" name="contact_phone" value="<?php echo h($booking['contact_phone'] ?: $guest['phone']); ?>" required>
                        <div class="small text-muted mt-1">Điểm vẫn tích theo CCCD/hồ sơ tài khoản.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Ngày nhận</label>
                        <input class="form-control" type="date" name="check_in" value="<?php echo h($booking['check_in']); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Ngày trả</label>
                        <input class="form-control" type="date" name="check_out" value="<?php echo h($booking['check_out']); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Người lớn</label>
                        <input class="form-control" type="number" name="adults" min="1" value="<?php echo (int)$booking['adults']; ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Trẻ em</label>
                        <input class="form-control" type="number" name="children" min="0" value="<?php echo (int)$booking['children']; ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-bold">Ghi chú</label>
                        <textarea class="form-control" name="note" rows="3"><?php echo h($booking['note']); ?></textarea>
                    </div>
                    <div class="col-12 d-grid">
                        <button class="btn btn-hotel" type="submit">Lưu thông tin đặt phòng</button>
                    </div>
                </div>
            </div>
        </form>
    </div>
<?php endforeach; ?>

<?php include __DIR__ . '/includes/layout/footer.php'; ?>
<script>
const siteHeader = document.getElementById('siteHeader');
function syncHeaderState() {
    if (siteHeader) {
        siteHeader.classList.toggle('is-scrolled', window.scrollY > 40);
    }
}
syncHeaderState();
window.addEventListener('scroll', syncHeaderState, { passive: true });

const profileModal = document.getElementById('profileModal');
const openProfileModal = document.getElementById('openProfileModal');
const closeProfileModal = document.getElementById('closeProfileModal');
function setProfileModal(open) {
    if (!profileModal) return;
    profileModal.classList.toggle('is-open', open);
    profileModal.setAttribute('aria-hidden', open ? 'false' : 'true');
}
if (openProfileModal) openProfileModal.addEventListener('click', () => setProfileModal(true));
if (closeProfileModal) closeProfileModal.addEventListener('click', () => setProfileModal(false));
if (profileModal) {
    profileModal.addEventListener('click', (event) => {
        if (event.target === profileModal) setProfileModal(false);
    });
}
document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    setProfileModal(false);
    document.querySelectorAll('.modal-mask.is-open').forEach((modal) => {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
    });
});

document.querySelectorAll('[data-open-booking-modal]').forEach((button) => {
    button.addEventListener('click', () => {
        const modal = document.getElementById(button.dataset.openBookingModal);
        if (!modal) return;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
    });
});
document.querySelectorAll('[data-close-booking-modal]').forEach((button) => {
    button.addEventListener('click', () => {
        const modal = button.closest('.modal-mask');
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
    });
});
document.querySelectorAll('.modal-mask').forEach((modal) => {
    modal.addEventListener('click', (event) => {
        if (event.target !== modal || modal.id === 'profileModal') return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
    });
});
document.querySelectorAll('[data-cancel-booking]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!confirm('Bạn chắc chắn muốn hủy booking này?')) {
            event.preventDefault();
        }
    });
});
document.querySelectorAll('[data-print-invoice]').forEach((button) => {
    button.addEventListener('click', () => {
        document.body.classList.add('is-printing-invoice');
        window.print();
    });
});
window.addEventListener('beforeprint', () => {
    if (document.querySelector('.modal-mask.is-open .print-invoice')) {
        document.body.classList.add('is-printing-invoice');
    }
});
window.addEventListener('afterprint', () => {
    document.body.classList.remove('is-printing-invoice');
});
</script>
</body>
</html>
