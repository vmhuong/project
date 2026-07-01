<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/services/room_service.php';

[$checkIn, $checkOut, $guests] = requestStayDefaults($today, $tomorrow);
$dateRangeValid = strtotime($checkOut) > strtotime($checkIn);
$hotels = $dateRangeValid ? fetchBookableHotels($db, $guests, $checkIn, $checkOut) : [];
$hasSelectedHotel = isset($_GET['hotel']) && (int)$_GET['hotel'] > 0;
$selectedHotelId = $hasSelectedHotel ? (int)$_GET['hotel'] : 0;
$selectedHotel = $hasSelectedHotel ? selectedHotelFromList($hotels, $selectedHotelId, false) : null;
if (!$selectedHotel && $hasSelectedHotel) {
    $hasSelectedHotel = false;
    $selectedHotelId = 0;
}

$rooms = [];
if ($dateRangeValid) {
    $rooms = fetchAvailableRooms($db, $guests, $checkIn, $checkOut, $hasSelectedHotel ? $selectedHotelId : 0);
}
$formatStayDate = static function (string $date): string {
    $time = strtotime($date);
    return $time ? date('j', $time) . ' thg ' . date('n', $time) : $date;
};
$dateRangeLabel = $formatStayDate($checkIn) . ' -> ' . $formatStayDate($checkOut);
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php $pageTitle = 'Phòng - Spotki Hotel'; include __DIR__ . '/includes/layout/assets.php'; ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        :root { --red:#dc2626; --dark:#111827; --muted:#6b7280; --line:#e5e7eb; --bg:#f6f7fb; }
        body { margin:0; background:var(--bg); color:var(--dark); font-family:"Inter","Segoe UI",Arial,sans-serif; font-size:15px; line-height:1.55; -webkit-font-smoothing:antialiased; text-rendering:optimizeLegibility; }
        .site-header { position:fixed; z-index:30; top:0; left:0; right:0; transition:box-shadow .24s ease; }
        .nav { border-bottom:1px solid transparent; padding:10px 0 0; background:transparent; transition:background .24s ease, padding .24s ease, box-shadow .24s ease, backdrop-filter .24s ease; }
        .nav .container { min-height:58px; display:grid; grid-template-columns:1fr auto 1fr; align-items:center; gap:18px; }
        .brand,.links a { color:#fff; text-decoration:none; font-weight:500; }
        .brand { display:flex; align-items:center; gap:10px; font-size:19px; letter-spacing:.01em; transition:font-size .24s ease; }
        .brand i { width:36px; height:36px; border-radius:50%; background:rgba(255,255,255,.15); display:inline-flex; align-items:center; justify-content:center; box-shadow:0 0 0 2px rgba(255,255,255,.22); transition:width .24s ease,height .24s ease,background .24s ease; }
        .links { display:flex; align-items:center; gap:4px; }
        .links a { font-size:14px; font-weight:500; text-transform:uppercase; padding:16px 12px 13px; border-bottom:2px solid transparent; text-shadow:0 2px 10px rgba(0,0,0,.34); letter-spacing:0; }
        .links a:hover,.links a.active { border-bottom-color:#fff; }
        .header-actions { display:flex; justify-content:flex-end; align-items:center; gap:10px; }
        .header-icon { width:30px; height:30px; display:inline-flex; align-items:center; justify-content:center; border-radius:50%; background:rgba(255,255,255,.14); color:#fff; text-decoration:none; }
        .site-header.is-scrolled .nav { padding:0; background:rgba(17,24,39,.94); border-bottom-color:rgba(255,255,255,.08); box-shadow:0 8px 22px rgba(15,23,42,.18); backdrop-filter:blur(12px); }
        .site-header.is-scrolled .brand { font-size:16px; }
        .site-header.is-scrolled .brand i { width:26px; height:26px; background:rgba(220,38,38,.9); }
        .site-header.is-scrolled .links a { padding:9px 12px 11px; text-shadow:none; }
        .hero { min-height:420px; display:flex; align-items:end; color:#fff; padding:110px 0 42px; background:linear-gradient(90deg,rgba(17,24,39,.86),rgba(17,24,39,.28)), url('<?php echo h(($selectedHotel['hero_image'] ?? '') ?: beachHotelHeroImage()); ?>') center/cover; }
        .hero h1 { font-size:clamp(34px,5vw,64px); font-weight:750; line-height:1; }
        .kicker { color:var(--red); font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; }
        .section { padding:46px 0; }
        .filter-box,.room-card,.hotel-card,.empty-panel { background:#fff; border:1px solid var(--line); border-radius:8px; box-shadow:0 14px 36px rgba(15,23,42,.10); }
        .filter-box { padding:18px; margin-top:-54px; position:relative; z-index:2; }
        .date-range-wrap { position:relative; }
        .date-range-wrap i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--muted); pointer-events:none; }
        .date-range-input { padding-left:38px; cursor:pointer; background:#fff; font-size:16px; font-weight:650; color:var(--dark); letter-spacing:0; }
        .btn-hotel { background:var(--red); border-color:var(--red); color:#fff; font-weight:700; }
        .btn-hotel:hover { background:#991b1b; border-color:#991b1b; color:#fff; }
        .room-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:16px; }
        .hotel-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:16px; }
        .hotel-grid { max-width:1120px; margin:0 auto; }
        .hotel-choice-head { text-align:center; max-width:760px; margin:0 auto 24px; }
        .room-card { overflow:hidden; display:flex; flex-direction:column; height:100%; }
        .room-image { display:block; aspect-ratio:4/3; background:#e5e7eb; overflow:hidden; }
        .room-image img { width:100%; height:100%; object-fit:cover; display:block; transition:transform .24s ease; }
        .room-image:hover img { transform:scale(1.04); }
        .room-body { padding:15px; display:flex; flex:1; flex-direction:column; }
        .room-body > .btn { margin-top:auto; }
        .meta { display:flex; flex-wrap:wrap; gap:8px; margin:10px 0; color:var(--muted); font-size:13px; }
        .room-rating { display:flex; align-items:center; gap:6px; margin-top:5px; color:var(--muted); font-size:13px; font-weight:700; }
        .room-rating i { color:#f59e0b; }
        .room-rating strong { color:var(--dark); }
        .chip-list { display:flex; flex-wrap:wrap; gap:6px; margin:10px 0; }
        .chip { display:inline-flex; align-items:center; min-height:24px; padding:3px 8px; border-radius:999px; background:#f3f4f6; font-size:12px; font-weight:600; color:#374151; }
        .price { color:var(--red); font-size:18px; font-weight:750; white-space:nowrap; }
        .hotel-card { display:block; overflow:hidden; color:inherit; text-decoration:none; }
        .hotel-card.active { outline:3px solid rgba(220,38,38,.28); border-color:var(--red); }
        .hotel-card img { width:100%; aspect-ratio:16/9; object-fit:cover; display:block; }
        .hotel-card-body { padding:14px; }
        .stars { color:#f59e0b; font-size:13px; }
        .empty-panel { padding:24px; }
        .fw-bold { font-weight:650 !important; }
        .form-label { font-weight:600 !important; }
        h1,h2,h3,.h5 { letter-spacing:0; }
        footer { background:#111827; color:#d1d5db; padding:28px 0; }
        @media (max-width:1200px) { .room-grid { grid-template-columns:repeat(3,minmax(0,1fr)); } }
        @media (max-width:992px) { .room-grid,.hotel-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
        @media (max-width:640px) { .links { display:none; } .room-grid,.hotel-grid { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<?php $activePage = 'rooms'; $navClass = 'nav'; $servicesLabel = 'Dịch vụ và tiện ích'; $showPhone = true; include __DIR__ . '/includes/layout/header.php'; ?>

<header class="hero">
    <div class="container">
        <div class="kicker mb-2"><?php echo h($selectedHotel['city'] ?? 'Spotki Hotel'); ?></div>
        <h1 class="mb-3"><?php echo h($selectedHotel['hotel_name'] ?? 'Danh sách phòng'); ?></h1>
    </div>
</header>

<main>
    <section class="container">
        <form class="filter-box" method="get" action="rooms.php#rooms">
            <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
                <div>
                    <div class="kicker mb-1">Chọn cơ sở</div><h2 class="h5 fw-bold mb-0">Xem phòng theo khách sạn</h2>
                </div>
                <?php if (!$hasSelectedHotel): ?><div class="text-muted fw-bold small">Đang hiển thị tất cả phòng trong hệ thống.</div><?php endif; ?>
            </div>
            <div class="row g-3 align-items-end">
                <div class="col-md-4"><label class="form-label fw-bold">Cơ sở</label><select class="form-select" name="hotel"><option value="">Tất cả cơ sở</option><?php foreach ($hotels as $hotel): ?><option value="<?php echo (int)$hotel['id_hotel']; ?>" <?php echo (int)$hotel['id_hotel'] === $selectedHotelId ? 'selected' : ''; ?>><?php echo h($hotel['hotel_name'] . ' - ' . $hotel['city']); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4">
                    <label class="form-label fw-bold">Ngày vào - ngày ra</label>
                    <input type="hidden" name="check_in" id="checkInInput" value="<?php echo h($checkIn); ?>">
                    <input type="hidden" name="check_out" id="checkOutInput" value="<?php echo h($checkOut); ?>">
                    <div class="date-range-wrap">
                        <i class="fa fa-calendar-days"></i>
                        <input class="form-control date-range-input" id="dateRangeInput" type="text" value="<?php echo h($dateRangeLabel); ?>" readonly required>
                    </div>
                </div>
                <div class="col-md-2"><label class="form-label fw-bold">Khách</label><input class="form-control" type="number" name="guests" min="1" value="<?php echo (int)$guests; ?>"></div>
                <div class="col-md-2 d-grid"><button class="btn btn-hotel">Xem phòng</button></div>
            </div>
        </form>
    </section><!--  -->

    <section class="section bg-white" id="hotels">
        <div class="container">
            <div class="hotel-choice-head">
                <div class="kicker mb-1">Hotel locations</div>
                <h2 class="fw-bold mb-2">Chọn phòng theo cơ sở</h2>
                </div>
            <div class="hotel-grid">
                <?php foreach ($hotels as $hotel): ?>
                    <a class="hotel-card <?php echo (int)$hotel['id_hotel'] === $selectedHotelId ? 'active' : ''; ?>" href="rooms.php?hotel=<?php echo (int)$hotel['id_hotel']; ?>&check_in=<?php echo urlencode($checkIn); ?>&check_out=<?php echo urlencode($checkOut); ?>&guests=<?php echo (int)$guests; ?>#rooms">
                        <img src="<?php echo h($hotel['hero_image']); ?>" alt="<?php echo h($hotel['hotel_name']); ?>">
                        <div class="hotel-card-body">
                            <div class="stars"><?php echo str_repeat('★', (int)$hotel['star_rating']); ?></div>
                            <h3 class="h5 fw-bold mb-1"><?php echo h($hotel['hotel_name']); ?></h3>
                            <div class="text-muted small"><i class="fa fa-location-dot"></i> <?php echo h($hotel['address'] . ', ' . $hotel['city']); ?></div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <section class="section" id="rooms">
        <div class="container">
            <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
                <div><div class="kicker mb-1">Rooms</div><h2 class="fw-bold mb-0"><?php echo $hasSelectedHotel ? 'Phòng tại ' . h($selectedHotel['hotel_name'] ?? 'Spotki Hotel') : 'Tất cả phòng hiện có'; ?></h2></div>
            </div>
            <?php if (!$dateRangeValid): ?>
                <div class="alert alert-danger">Ngày ra phải sau ngày vào để lọc phòng.</div>
            <?php elseif (!$rooms): ?>
                <div class="empty-panel text-center">
                    <div class="kicker mb-2">No rooms</div>
                    <h3 class="h5 fw-bold mb-2">Không có phòng phù hợp</h3>
                    <p class="text-muted mb-0">Vui lòng đổi ngày lưu trú, số khách hoặc chọn cơ sở khác.</p>
                </div>
            <?php endif; ?>
            <div class="room-grid">
                <?php foreach ($rooms as $room): ?>
                    <?php $available = true; ?>
                    <article class="room-card">
                        <a class="room-image" href="room_detail.php?id_room=<?php echo (int)$room['id_room']; ?>&check_in=<?php echo urlencode($checkIn); ?>&check_out=<?php echo urlencode($checkOut); ?>&guests=<?php echo (int)$guests; ?>" aria-label="Xem chi tiết <?php echo h($room['room_type']); ?>"><img src="<?php echo h(roomDisplayImage($room)); ?>" alt="<?php echo h($room['room_type']); ?>"></a>
                        <div class="room-body">
                            <div class="d-flex justify-content-between gap-2">
                                <div>
                                    <h3 class="h5 fw-bold mb-1"><?php echo h($room['room_type']); ?></h3>
                                    <div class="text-muted small">Phòng <?php echo h($room['room_number']); ?></div>
                                    <?php if ((int)($room['review_count'] ?? 0) > 0): ?>
                                        <div class="room-rating"><i class="fa fa-star"></i><strong><?php echo number_format((float)$room['avg_rating'], 1, ',', '.'); ?>/5</strong><span><?php echo (int)$room['review_count']; ?> đánh giá</span></div>
                                    <?php endif; ?>
                                </div>
                                <div class="price"><?php echo money($room['price_per_night']); ?></div>
                            </div>
                            <div class="meta"><span><i class="fa fa-users"></i> <?php echo (int)$room['capacity']; ?> khách</span><span><i class="fa fa-ruler-combined"></i> <?php echo h($room['room_size']); ?></span><span><i class="fa fa-bed"></i> <?php echo h($room['bed_type']); ?></span><span><i class="fa fa-clock"></i> <?php echo money($room['hourly_rate']); ?>/giờ</span></div>
                            <div class="chip-list">
                                <?php foreach (array_slice(array_map('trim', explode(',', (string)$room['amenities'])), 0, 4) as $amenity): ?>
                                    <?php if ($amenity !== ''): ?><span class="chip"><?php echo h($amenity); ?></span><?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <p class="text-muted small mb-3"><?php echo h($room['note'] ?: 'Phòng được trang bị đầy đủ tiện nghi cho kỳ nghỉ thoải mái.'); ?></p>
                            <a class="btn <?php echo $available ? 'btn-hotel' : 'btn-outline-secondary'; ?> w-100" href="room_detail.php?id_room=<?php echo (int)$room['id_room']; ?>&check_in=<?php echo urlencode($checkIn); ?>&check_out=<?php echo urlencode($checkOut); ?>&guests=<?php echo (int)$guests; ?>"><?php echo $available ? 'Xem chi tiết và đặt phòng' : 'Xem chi tiết phòng'; ?></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
</main>

<?php include __DIR__ . '/includes/layout/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
const dateRangeInput = document.getElementById('dateRangeInput');
const checkInInput = document.getElementById('checkInInput');
const checkOutInput = document.getElementById('checkOutInput');
function formatStayDate(date) {
    return date.getDate() + ' thg ' + (date.getMonth() + 1);
}
function syncDateRangeLabel(dates) {
    if (dates.length === 2) {
        dateRangeInput.value = formatStayDate(dates[0]) + ' -> ' + formatStayDate(dates[1]);
    }
}
if (dateRangeInput && checkInInput && checkOutInput && window.flatpickr) {
    flatpickr(dateRangeInput, {
        mode: 'range',
        dateFormat: 'Y-m-d',
        minDate: 'today',
        defaultDate: [checkInInput.value, checkOutInput.value],
        onReady: function(selectedDates) {
            syncDateRangeLabel(selectedDates);
        },
        onChange: function(selectedDates, dateStr, instance) {
            if (selectedDates.length === 2) {
                checkInInput.value = instance.formatDate(selectedDates[0], 'Y-m-d');
                checkOutInput.value = instance.formatDate(selectedDates[1], 'Y-m-d');
                syncDateRangeLabel(selectedDates);
            }
        },
        onClose: function(selectedDates) {
            syncDateRangeLabel(selectedDates);
        }
    });
}
</script>
<script>
const siteHeader = document.getElementById('siteHeader');
function syncHeaderState() {
    if (siteHeader) {
        siteHeader.classList.toggle('is-scrolled', window.scrollY > 40);
    }
}
syncHeaderState();
window.addEventListener('scroll', syncHeaderState, { passive: true });
</script>
</body>
</html>
        siteHeader.classList.toggle('is-scrolled', window.scrollY > 40);
    }
}
syncHeaderState();
window.addEventListener('scroll', syncHeaderState, { passive: true });
</script>
</body>
</html>
