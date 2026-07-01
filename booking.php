<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/services/room_service.php';
require_once __DIR__ . '/includes/services/booking_service.php';

$roomId = (int)($_GET['id_room'] ?? $_POST['id_room'] ?? 0);
$checkIn = $_POST['check_in'] ?? $_GET['check_in'] ?? $today;
$checkOut = $_POST['check_out'] ?? $_GET['check_out'] ?? $tomorrow;
$guests = max(1, (int)($_POST['guests'] ?? $_GET['guests'] ?? 2));
$adults = max(1, (int)($_POST['adults'] ?? $_GET['adults'] ?? $guests));
$children = max(0, (int)($_POST['children'] ?? $_GET['children'] ?? 0));
$message = '';
$isError = false;

$room = fetchRoomDetail($db, $roomId);
if (!$room) {
    http_response_code(404);
    echo 'Không tìm thấy phòng.';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        verifyCsrfToken();
        $message = createRoomDetailBooking($db, $room, $_POST, $checkIn, $checkOut, $guests);
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $isError = true;
    }
}

$capacity = max(1, (int)$room['capacity']);
$freeChildren = freeChildrenForCapacity($capacity);
$adults = min($adults, $capacity + 1);
$children = min($children, max(0, $freeChildren + ($capacity + 1) - $adults));
$occupancyPolicy = bookingOccupancyPolicy([
    'capacity' => $capacity,
    'adults' => $adults,
    'children' => $children,
]);
$available = $room['status'] === 'available'
    && strtotime($checkOut) > strtotime($checkIn)
    && $occupancyPolicy['is_valid']
    && isRoomAvailable($db, $roomId, $checkIn . ' 14:00:00', $checkOut . ' 12:00:00');
$nightCount = nights($checkIn, $checkOut);
$roomBaseTotal = $nightCount * (float)$room['price_per_night'];
$extraGuestRatePercent = max(0, (float)($room['extra_guest_rate_percent'] ?? 25));
$extraGuestRateLabel = rtrim(rtrim(number_format($extraGuestRatePercent, 2, ',', '.'), '0'), ',');
$extraGuestFee = $occupancyPolicy['extra_guests'] > 0 ? $roomBaseTotal * ($extraGuestRatePercent / 100) : 0.0;
$roomTotal = $roomBaseTotal + $extraGuestFee;
$depositAmount = $roomTotal * 0.5;
$customerProfile = currentCustomer($db);
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php $pageTitle = 'Đặt phòng - Spotki Hotel'; include __DIR__ . '/includes/layout/assets.php'; ?>
    <style>
        :root { --red:#dc2626; --dark:#111827; --muted:#6b7280; --line:#e5e7eb; --bg:#f6f7fb; }
        body { margin:0; background:var(--bg); color:var(--dark); font-family:"Inter","Segoe UI",Arial,sans-serif; font-size:15px; line-height:1.55; }
        .site-header { position:fixed; z-index:30; top:0; left:0; right:0; }
        .nav { padding:0; background:#fff; border-bottom:1px solid var(--line); box-shadow:0 8px 22px rgba(15,23,42,.08); }
        .nav .container { min-height:58px; display:grid; grid-template-columns:1fr auto 1fr; align-items:center; gap:18px; }
        .brand,.links a { color:var(--dark); text-decoration:none; font-weight:500; }
        .brand { display:flex; align-items:center; gap:10px; font-size:16px; }
                            <div class="small text-muted mt-1">Tương đương 50% tổng tiền phòng dự kiến.</div>
        .links { display:flex; align-items:center; gap:4px; }
        .links a { font-size:14px; text-transform:uppercase; padding:9px 12px 11px; border-bottom:2px solid transparent; }
        .links a:hover,.links a.active { border-bottom-color:var(--red); color:var(--red); }
        .header-actions { display:flex; justify-content:flex-end; align-items:center; gap:10px; }
        .header-icon { text-decoration:none; }
        .page { padding:92px 0 54px; }
        .crumb { color:var(--muted); text-decoration:none; font-weight:800; font-size:14px; }
        .layout { display:grid; grid-template-columns:minmax(0,1fr) 380px; gap:18px; align-items:start; margin-top:16px; }
        .panel { background:#fff; border:1px solid var(--line); border-radius:8px; box-shadow:0 14px 34px rgba(15,23,42,.09); }
        .panel-body { padding:18px; }
        .kicker { color:var(--red); font-size:12px; font-weight:900; text-transform:uppercase; letter-spacing:.08em; }
        .price { font-size:26px; font-weight:900; color:var(--red); line-height:1; }
        .chip { display:inline-flex; align-items:center; min-height:28px; padding:4px 9px; border-radius:999px; background:#f3f4f6; color:#374151; }
        .summary-line { display:flex; justify-content:space-between; gap:12px; padding:9px 0; border-bottom:1px dashed var(--line); }
        .summary-line:last-child { border-bottom:0; }
        .deposit-box { border:1px solid #fecaca; background:#fff7f7; border-radius:8px; padding:12px; margin:12px 0; }
        .deposit-amount { color:var(--red); font-size:22px; font-weight:800; line-height:1; }
        .btn-hotel { background:var(--red); border-color:var(--red); color:#fff; font-weight:900; min-height:42px; }
        .btn-hotel:hover { background:#991b1b; border-color:#991b1b; color:#fff; }
        .booking-form label { font-weight:800; font-size:14px; }
        .form-control,.form-select { min-height:42px; border-color:var(--line); }
        .status { border-radius:8px; padding:11px 12px; font-weight:800; }
        .status.ok { background:#dcfce7; color:#166534; }
        .status.bad { background:#fee2e2; color:#991b1b; }
        footer { background:#111827; color:#d1d5db; padding:28px 0; }
        @media (max-width:900px) { .links { display:none; } .layout { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<?php $activePage = 'rooms'; $navClass = 'nav'; $servicesLabel = 'Dịch vụ'; include __DIR__ . '/includes/layout/header.php'; ?>
<main class="page">
    <div class="container">
        <a class="crumb" href="room_detail.php?id_room=<?php echo (int)$roomId; ?>&check_in=<?php echo urlencode($checkIn); ?>&check_out=<?php echo urlencode($checkOut); ?>&guests=<?php echo (int)$guests; ?>"><i class="fa fa-arrow-left"></i> Quay lại chi tiết phòng</a>

        <?php if ($message !== ''): ?>
            <div class="alert <?php echo $isError ? 'alert-danger' : 'alert-success'; ?> mt-3"><?php echo h($message); ?></div>
        <?php endif; ?>

        <section class="layout">
            <form class="panel panel-body booking-form" method="post">
                <input type="hidden" name="id_room" value="<?php echo (int)$roomId; ?>">
                <input type="hidden" name="check_in" value="<?php echo h($checkIn); ?>">
                <input type="hidden" name="check_out" value="<?php echo h($checkOut); ?>">
                <input type="hidden" name="guests" value="<?php echo (int)$guests; ?>">
                <input type="hidden" name="adults" value="<?php echo (int)$adults; ?>">
                <input type="hidden" name="children" value="<?php echo (int)$children; ?>">

                <div class="kicker mb-1">Guest information</div>
                <h1 class="h4 fw-bold mb-3">Thông tin đặt phòng</h1>
                <div class="row g-3">
                    <?php if ($customerProfile): ?>
                        <div class="col-12"><div class="alert alert-info mb-0">Email, CCCD/hộ chiếu và địa chỉ được lấy từ trang cá nhân để tích điểm. Tên gợi nhớ và số điện thoại tạm thời chỉ áp dụng cho booking này.</div></div>
                        <div class="col-md-6"><label class="form-label">Tên gợi nhớ</label><input class="form-control" name="contact_name" value="<?php echo h($customerProfile['full_name']); ?>" required <?php echo $available ? '' : 'disabled'; ?>></div>
                        <div class="col-md-6"><label class="form-label">Số điện thoại tạm thời</label><input class="form-control" name="contact_phone" value="<?php echo h($customerProfile['phone']); ?>" required <?php echo $available ? '' : 'disabled'; ?>></div>
                        <div class="col-12"><label class="form-label">Email</label><input class="form-control" type="email" value="<?php echo h($customerProfile['email']); ?>" disabled></div>
                        <div class="col-md-6"><label class="form-label">CCCD/Hộ chiếu</label><input class="form-control" value="<?php echo h($customerProfile['identity_no']); ?>" disabled></div>
                        <div class="col-md-6"><label class="form-label">Địa chỉ</label><input class="form-control" value="<?php echo h($customerProfile['address']); ?>" disabled></div>
                    <?php else: ?>
                        <div class="col-md-6"><label class="form-label">Họ tên</label><input class="form-control" name="full_name" required <?php echo $available ? '' : 'disabled'; ?>></div>
                        <div class="col-md-6"><label class="form-label">Điện thoại</label><input class="form-control" name="phone" required <?php echo $available ? '' : 'disabled'; ?>></div>
                        <div class="col-12"><label class="form-label">Email</label><input class="form-control" type="email" name="email" required <?php echo $available ? '' : 'disabled'; ?>></div>
                        <div class="col-md-6"><label class="form-label">CCCD/Hộ chiếu</label><input class="form-control" name="identity_no" required <?php echo $available ? '' : 'disabled'; ?>></div>
                        <div class="col-md-6"><label class="form-label">Địa chỉ</label><input class="form-control" name="address" required <?php echo $available ? '' : 'disabled'; ?>></div>
                    <?php endif; ?>
                    <div class="col-md-6"><label class="form-label">Người lớn</label><input class="form-control" value="<?php echo (int)$adults; ?> người lớn" disabled></div>
                    <div class="col-md-6"><label class="form-label">Em bé</label><input class="form-control" value="<?php echo (int)$children; ?> em bé" disabled></div>
                    <div class="col-12"><div class="text-muted small">Phòng <?php echo (int)$capacity; ?> người free <?php echo (int)$freeChildren; ?> em bé. Chỉ được vượt tối đa 1 người, phụ thu <?php echo h($extraGuestRateLabel); ?>% tiền phòng.</div></div>
                    <div class="col-12"><label class="form-label">Ghi chú</label><textarea class="form-control" name="note" rows="3" <?php echo $available ? '' : 'disabled'; ?>></textarea></div>

                    <div class="col-12">
                        <div class="deposit-box">
                            <div class="small text-muted mb-1">Số tiền cọc cần thanh toán</div>
                            <div class="deposit-amount"><?php echo money($depositAmount); ?></div>
                            <div class="small text-muted mt-1">Tương đương 50% tổng tiền phòng dự kiến.</div>
                        </div>
                    </div>

                    <div class="col-12"><label class="form-label">Phương thức cọc</label><select class="form-control" name="deposit_method" required <?php echo $available ? '' : 'disabled'; ?>><option value="">Chọn phương thức</option><option>Chuyển khoản ngân hàng</option><option>Ví điện tử</option><option>Thẻ nội địa/quốc tế</option></select></div>
                    <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="deposit_confirm" value="1" id="depositConfirm" required <?php echo $available ? '' : 'disabled'; ?>><label class="form-check-label" for="depositConfirm">Tôi xác nhận sẽ tiến hành cọc 50% để giữ phòng. Phần còn lại thanh toán khi nhận/trả phòng theo quy định khách sạn.</label></div></div>
                    <div class="col-12 d-grid"><button class="btn btn-hotel" <?php echo $available ? '' : 'disabled'; ?>>Tiến hành cọc 50% và đặt phòng</button></div>
                </div>
            </form>

            <aside class="panel panel-body">
                <div class="kicker">Booking summary</div>
                <h2 class="h5 fw-bold mb-2"><?php echo h($room['room_type']); ?> - Phòng <?php echo h($room['room_number']); ?></h2>
                <div class="text-muted small mb-3"><?php echo h($room['hotel_name']); ?></div>
                <div class="price mb-1"><?php echo money($room['price_per_night']); ?></div>
                <div class="text-muted small mb-3">Thêm giờ · <?php echo money($room['hourly_rate']); ?>/giờ</div>
                <span class="chip mb-3"><?php echo h($room['package_name']); ?></span>
                <div class="status <?php echo $available ? 'ok' : 'bad'; ?> mb-3">
                    <?php echo $available ? 'Phòng còn trống trong khoảng ngày này.' : 'Phòng không khả dụng trong khoảng ngày này.'; ?>
                </div>
                <div class="summary-line"><span>Ngày nhận</span><strong><?php echo h($checkIn); ?></strong></div>
                <div class="summary-line"><span>Ngày trả</span><strong><?php echo h($checkOut); ?></strong></div>
                <div class="summary-line"><span><?php echo (int)$nightCount; ?> đêm</span><strong><?php echo money($roomBaseTotal); ?></strong></div>
                <?php if ($extraGuestFee > 0): ?><div class="summary-line"><span>Phụ thu thêm người (<?php echo h($extraGuestRateLabel); ?>% tiền phòng)</span><strong><?php echo money($extraGuestFee); ?></strong></div><?php endif; ?>
                <div class="summary-line"><span>Tổng tiền phòng dự kiến</span><strong><?php echo money($roomTotal); ?></strong></div>
                <div class="summary-line"><span>Cọc giữ phòng 50%</span><strong><?php echo money($depositAmount); ?></strong></div>
            </aside>
        </section>
    </div>
</main>
<?php include __DIR__ . '/includes/layout/footer.php'; ?>
<script>
const capacity = <?php echo (int)$capacity; ?>;
const freeChildren = <?php echo (int)$freeChildren; ?>;
const adultsSelect = null;
const childrenSelect = null;
function refreshChildrenChoices() {
    if (!adultsSelect || !childrenSelect) return;
    const current = Number(childrenSelect.value || 0);
    const adults = Math.min(Math.max(Number(adultsSelect.value || 1), 1), capacity + 1);
    const maxChildren = Math.max(0, freeChildren + (capacity + 1) - adults);
    childrenSelect.innerHTML = '';
    for (let value = 0; value <= maxChildren; value += 1) {
        const option = document.createElement('option');
        option.value = String(value);
        option.textContent = value + ' trẻ em';
        option.selected = value === Math.min(current, maxChildren);
        childrenSelect.appendChild(option);
    }
}
if (adultsSelect) {
    adultsSelect.addEventListener('change', refreshChildrenChoices);
    refreshChildrenChoices();
}
</script>
</body>
</html>
