<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/services/room_service.php';
require_once __DIR__ . '/includes/services/review_service.php';

$roomId = (int)($_GET['id_room'] ?? 0);
$checkIn = $_GET['check_in'] ?? $today;
$checkOut = $_GET['check_out'] ?? $tomorrow;
$guests = max(1, (int)($_GET['guests'] ?? 2));
$adults = max(1, (int)($_GET['adults'] ?? $guests));
$children = max(0, (int)($_GET['children'] ?? 0));

$room = fetchRoomDetail($db, $roomId);
if (!$room) {
    http_response_code(404);
    echo 'Không tìm thấy phòng.';
    exit;
}

$capacity = max(1, (int)$room['capacity']);
$freeChildren = freeChildrenForCapacity($capacity);
$maxGuests = $capacity + 1;
$adults = min($adults, $maxGuests);
$maxChildren = max(0, $freeChildren + $maxGuests - $adults);
$children = min($children, $maxChildren);
$detailOccupancyPolicy = bookingOccupancyPolicy([
    'capacity' => $capacity,
    'adults' => $adults,
    'children' => $children,
]);
$images = roomGallery($room);
$reviewSummary = fetchRoomReviewSummary($db, $roomId);
$roomReviews = fetchRoomReviews($db, $roomId);
$amenities = array_values(array_filter(array_map('trim', explode(',', (string)$room['amenities']))));
$packageServices = array_values(array_filter(array_map('trim', explode(',', (string)$room['package_services']))));
$available = $room['status'] === 'available'
    && strtotime($checkOut) > strtotime($checkIn)
    && $detailOccupancyPolicy['is_valid']
    && isRoomAvailable($db, $roomId, $checkIn . ' 14:00:00', $checkOut . ' 12:00:00');
$nightCount = nights($checkIn, $checkOut);
$roomBaseTotal = $nightCount * (float)$room['price_per_night'];
$extraGuestRatePercent = max(0, (float)($room['extra_guest_rate_percent'] ?? 25));
$extraGuestRateLabel = rtrim(rtrim(number_format($extraGuestRatePercent, 2, ',', '.'), '0'), ',');
$extraGuestFee = $detailOccupancyPolicy['extra_guests'] > 0 ? $roomBaseTotal * ($extraGuestRatePercent / 100) : 0.0;
$roomTotal = $roomBaseTotal + $extraGuestFee;
$depositAmount = $roomTotal * 0.5;
$formatStayDate = static function (string $date): string {
    $time = strtotime($date);
    return $time ? date('j', $time) . ' thg ' . date('n', $time) : $date;
};
$dateRangeLabel = $formatStayDate($checkIn) . ' -> ' . $formatStayDate($checkOut);
$topAmenities = array_slice($amenities, 0, 10);
$amenityGroups = [
    ['fa-user-check', 'Phù hợp cho kỳ lưu trú', array_merge($topAmenities, ['Dọn phòng hằng ngày', 'Lễ tân 24 giờ'])],
    ['fa-wifi', 'Internet', ['WiFi miễn phí trong toàn bộ khách sạn', 'Tốc độ cao cho công việc và giải trí']],
    ['fa-square-parking', 'Chỗ đậu xe', ['Có chỗ đậu xe cho khách', 'Hỗ trợ đưa đón sân bay theo yêu cầu']],
    ['fa-bell-concierge', 'Dịch vụ', array_merge($packageServices, ['Giữ hành lí', 'Giặt ủi phụ phí'])],
    ['fa-lock', 'An ninh', ['Bình chữa cháy', 'Hệ thống CCTV khu vực chung', 'Bảo vệ 24/7', 'Két an toàn']],
    ['fa-circle-info', 'Tổng quát', ['Điều hòa nhiệt độ', 'Phòng không hút thuốc', 'Thang máy', 'Nhà hàng']],
    ['fa-language', 'Ngôn ngữ', ['Tiếng Việt', 'Tiếng Anh']],
];
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php $pageTitle = h($room['room_type']) . ' - Spotki Hotel'; include __DIR__ . '/includes/layout/assets.php'; ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        :root { --red:#dc2626; --dark:#111827; --muted:#6b7280; --line:#e5e7eb; --bg:#f6f7fb; --green:#059669; }
        body { margin:0; background:var(--bg); color:var(--dark); font-family:"Inter","Segoe UI",Arial,sans-serif; font-size:15px; line-height:1.55; -webkit-font-smoothing:antialiased; text-rendering:optimizeLegibility; }
        .site-header { position:fixed; z-index:30; top:0; left:0; right:0; animation:headerDrop .42s ease-out both; transition:transform .24s ease, box-shadow .24s ease; }
        .nav { padding:10px 0 0; background:rgba(255,255,255,.98); border-bottom:1px solid var(--line); box-shadow:0 8px 22px rgba(15,23,42,.08); backdrop-filter:blur(10px); transition:background .24s ease, padding .24s ease, box-shadow .24s ease, backdrop-filter .24s ease; }
        .nav .container { min-height:58px; display:grid; grid-template-columns:1fr auto 1fr; align-items:center; gap:18px; }
        .brand,.links a { color:var(--red); text-decoration:none; font-weight:500; }
        .brand { display:flex; align-items:center; gap:10px; font-size:19px; letter-spacing:.01em; transition:font-size .24s ease, color .24s ease; }
        .brand i { width:36px; height:36px; border-radius:50%; background:rgba(220,38,38,.1); color:var(--red); display:inline-flex; align-items:center; justify-content:center; box-shadow:0 0 0 2px rgba(220,38,38,.18); transition:width .24s ease,height .24s ease,background .24s ease,color .24s ease,box-shadow .24s ease; }
        .links { display:flex; align-items:center; gap:4px; }
        .links a { font-size:14px; text-transform:uppercase; padding:16px 12px 13px; border-bottom:2px solid transparent; letter-spacing:0; transition:padding .24s ease, color .2s ease, border-color .2s ease; }
        .links a:hover,.links a.active { border-bottom-color:var(--red); color:#991b1b; }
        .header-actions { display:flex; justify-content:flex-end; align-items:center; gap:10px; }
        .header-icon { width:30px; height:30px; border-radius:50%; background:rgba(220,38,38,.1); color:var(--red); display:inline-flex; align-items:center; justify-content:center; text-decoration:none; box-shadow:0 0 0 1px rgba(220,38,38,.16); transition:width .24s ease,height .24s ease,background .24s ease,color .24s ease,box-shadow .24s ease; }
        .site-header.is-scrolled { transform:translateY(0); }
        .site-header.is-scrolled .nav { padding:0; background:rgba(17,24,39,.94); border-bottom-color:rgba(255,255,255,.08); box-shadow:0 8px 22px rgba(15,23,42,.18); backdrop-filter:blur(12px); }
        .site-header.is-scrolled .brand,.site-header.is-scrolled .links a { color:#fff; }
        .site-header.is-scrolled .brand { font-size:16px; }
        .site-header.is-scrolled .brand i { width:26px; height:26px; background:rgba(220,38,38,.9); color:#fff; box-shadow:0 0 0 1px rgba(255,255,255,.12); }
        .site-header.is-scrolled .header-icon { width:28px; height:28px; background:rgba(255,255,255,.14); color:#fff; box-shadow:none; }
        .site-header.is-scrolled .links a { padding:9px 12px 11px; text-shadow:none; }
        .site-header.is-scrolled .links a:hover,.site-header.is-scrolled .links a.active { border-bottom-color:#fff; color:#fff; }
        @keyframes headerDrop { from { opacity:0; transform:translateY(-18px); } to { opacity:1; transform:translateY(0); } }
        .page { padding:92px 0 54px; }
        .crumb { color:var(--muted); text-decoration:none; font-weight:800; font-size:14px; }
        .hero-grid { display:grid; grid-template-columns:minmax(0,1.12fr) minmax(340px,.88fr); gap:18px; align-items:start; margin-top:14px; }
        .room-gallery { display:grid; grid-template-columns:minmax(0,1fr) 122px; gap:10px; }
        .room-gallery.is-single { grid-template-columns:1fr; }
        .gallery-main { position:relative; min-height:438px; overflow:hidden; border:0; padding:0; border-radius:8px; background:#e5e7eb; box-shadow:0 16px 42px rgba(15,23,42,.14); cursor:pointer; text-align:left; }
        .gallery-main img { width:100%; height:100%; min-height:438px; object-fit:cover; display:block; transition:transform .35s ease; }
        .gallery-main:hover img { transform:scale(1.025); }
        .gallery-main::after { content:""; position:absolute; inset:0; background:linear-gradient(180deg,rgba(15,23,42,0) 58%,rgba(15,23,42,.46)); pointer-events:none; }
        .gallery-open { position:absolute; right:14px; bottom:14px; z-index:2; min-height:38px; padding:8px 12px; border:0; border-radius:8px; background:rgba(255,255,255,.95); color:var(--dark); font-weight:900; box-shadow:0 12px 26px rgba(15,23,42,.18); display:inline-flex; align-items:center; gap:8px; }
        .gallery-count { position:absolute; left:14px; bottom:14px; z-index:2; min-height:34px; padding:7px 11px; border-radius:999px; background:rgba(17,24,39,.72); color:#fff; font-weight:900; font-size:13px; backdrop-filter:blur(8px); }
        .gallery-thumbs { display:grid; grid-template-rows:repeat(4,1fr); gap:10px; min-height:438px; }
        .gallery-thumb { position:relative; width:100%; min-height:0; padding:0; border:2px solid transparent; border-radius:8px; overflow:hidden; background:#e5e7eb; cursor:pointer; box-shadow:0 8px 18px rgba(15,23,42,.1); transition:border-color .18s ease, transform .18s ease, opacity .18s ease; }
        .gallery-thumb img { width:100%; height:100%; object-fit:cover; display:block; }
        .gallery-thumb:hover { transform:translateY(-1px); }
        .gallery-thumb.is-active { border-color:var(--red); }
        .gallery-thumb-more::after { content:attr(data-more-label); position:absolute; inset:0; display:flex; align-items:center; justify-content:center; background:rgba(17,24,39,.6); color:#fff; font-size:22px; font-weight:900; }
        .gallery-lightbox { position:fixed; inset:0; z-index:80; display:none; align-items:center; justify-content:center; padding:22px; background:rgba(17,24,39,.88); }
        .gallery-lightbox.is-open { display:flex; }
        .gallery-lightbox img { max-width:min(1120px,96vw); max-height:86vh; width:auto; height:auto; object-fit:contain; border-radius:8px; box-shadow:0 24px 70px rgba(0,0,0,.36); }
        .gallery-lightbox button { position:absolute; border:0; border-radius:999px; background:rgba(255,255,255,.95); color:var(--dark); width:42px; height:42px; display:inline-flex; align-items:center; justify-content:center; box-shadow:0 12px 30px rgba(0,0,0,.25); }
        .gallery-lightbox-close { top:18px; right:18px; }
        .gallery-lightbox-prev { left:18px; top:50%; transform:translateY(-50%); }
        .gallery-lightbox-next { right:18px; top:50%; transform:translateY(-50%); }
        .panel { background:#fff; border:1px solid var(--line); border-radius:8px; box-shadow:0 14px 34px rgba(15,23,42,.09); }
        .panel-body { padding:18px; }
        .room-title { font-size:clamp(28px,4vw,42px); line-height:1.08; font-weight:900; margin:0 0 8px; }
        .kicker { color:var(--red); font-size:12px; font-weight:900; text-transform:uppercase; letter-spacing:.08em; }
        .stars { color:#f59e0b; font-size:13px; }
        .detail-rating { display:flex; flex-wrap:wrap; align-items:center; gap:7px; margin-top:8px; color:var(--muted); font-size:14px; font-weight:800; }
        .detail-rating i { color:#f59e0b; }
        .detail-rating strong { color:var(--dark); }
        .meta { display:flex; flex-wrap:wrap; gap:8px; color:var(--muted); font-size:14px; margin:14px 0; }
        .meta span,.chip { display:inline-flex; align-items:center; gap:6px; min-height:28px; padding:4px 9px; border-radius:999px; background:#f3f4f6; }
        .booking-panel { position:sticky; top:78px; }
        .price { font-size:28px; font-weight:900; color:var(--red); line-height:1; }
        .summary-line { display:flex; justify-content:space-between; gap:12px; padding:9px 0; border-bottom:1px dashed var(--line); }
        .summary-line:last-child { border-bottom:0; }
        .booking-form label { font-weight:800; font-size:14px; }
        .form-control { min-height:42px; border-color:var(--line); }
        .date-range-wrap { position:relative; }
        .date-range-wrap i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--muted); pointer-events:none; }
        .date-range-input { padding-left:38px; cursor:pointer; background:#fff; font-size:16px; font-weight:650; color:var(--dark); letter-spacing:0; }
        .status { border-radius:8px; padding:11px 12px; font-weight:800; }
        .status.ok { background:#dcfce7; color:#166534; }
        .status.bad { background:#fee2e2; color:#991b1b; }
        .btn-hotel { background:var(--red); border-color:var(--red); color:#fff; font-weight:900; min-height:42px; }
        .btn-hotel:hover { background:#991b1b; border-color:#991b1b; color:#fff; }
        .amenity-panel { padding:18px; margin-top:18px; }
        .amenity-panel-head { display:flex; justify-content:space-between; gap:16px; align-items:flex-end; margin-bottom:14px; }
        .amenity-panel-head h2 { font-size:20px; font-weight:900; margin:0; }
        .amenity-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; }
        .amenity-group { display:grid; grid-template-columns:24px minmax(0,1fr); column-gap:9px; align-items:start; padding:13px; border:1px solid var(--line); border-radius:8px; background:#fff; }
        .amenity-group > i { width:24px; height:24px; border-radius:6px; display:inline-flex; align-items:center; justify-content:center; background:#ecfdf5; color:var(--green); font-size:13px; margin-top:1px; }
        .amenity-group h3 { font-size:15px; font-weight:900; margin:0 0 7px; line-height:1.25; }
        .amenity-group ul { list-style:none; padding:0; margin:0; display:grid; gap:5px; }
        .amenity-group li { position:relative; padding-left:15px; line-height:1.32; color:#4b5563; font-size:13px; }
        .amenity-group li::before { content:""; position:absolute; left:0; top:.58em; width:5px; height:5px; border-radius:50%; background:var(--green); }
        .review-panel { padding:18px; margin-top:18px; }
        .review-summary { display:grid; grid-template-columns:220px minmax(0,1fr); gap:18px; align-items:start; margin-bottom:16px; }
        .review-score { border:1px solid var(--line); border-radius:8px; padding:16px; background:#fff; }
        .review-score strong { display:block; font-size:36px; line-height:1; color:var(--red); }
        .review-stars { color:#f59e0b; font-size:17px; letter-spacing:1px; white-space:nowrap; }
        .review-list { display:grid; gap:10px; }
        .review-item { border:1px solid var(--line); border-radius:8px; padding:13px; background:#fff; }
        .review-item-head { display:flex; justify-content:space-between; gap:12px; align-items:start; margin-bottom:8px; }
        .review-author-line { display:flex; flex-wrap:wrap; align-items:baseline; gap:8px; }
        .review-metrics { display:flex; flex-wrap:wrap; gap:8px; color:var(--muted); font-size:13px; }
        .review-metrics span { display:inline-flex; align-items:center; gap:5px; }
        footer { background:#111827; color:#d1d5db; padding:28px 0; }
        @media (max-width:992px) { .hero-grid,.review-summary { grid-template-columns:1fr; } .booking-panel { position:static; } .amenity-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } .room-gallery { grid-template-columns:1fr; } .gallery-main,.gallery-main img { min-height:360px; } .gallery-thumbs { grid-template-columns:repeat(4,1fr); grid-template-rows:96px; min-height:96px; } }
        @media (max-width:640px) { .links { display:none; } .amenity-grid { grid-template-columns:1fr; } .page { padding-top:82px; } .gallery-main,.gallery-main img { min-height:300px; } .gallery-thumbs { grid-template-rows:78px; min-height:78px; gap:8px; } .gallery-open { left:12px; right:12px; justify-content:center; } .gallery-count { top:12px; bottom:auto; } }
    </style>
</head>
<body>
<?php $activePage = 'rooms'; $navClass = 'nav'; $servicesLabel = 'Dịch vụ và tiện ích'; include __DIR__ . '/includes/layout/header.php'; ?>

<main class="page">
    <div class="container">
        <a class="crumb" href="rooms.php?hotel=<?php echo (int)$room['id_hotel']; ?>&check_in=<?php echo urlencode($checkIn); ?>&check_out=<?php echo urlencode($checkOut); ?>&guests=<?php echo (int)$guests; ?>#rooms"><i class="fa fa-arrow-left"></i> Quay lại danh sách phòng</a>

        <section class="hero-grid">
            <div>
                <div class="room-gallery <?php echo count($images) <= 1 ? 'is-single' : ''; ?>" data-room-gallery>
                    <button class="gallery-main" type="button" data-gallery-open aria-label="Xem ảnh phòng">
                        <img src="<?php echo h($images[0]); ?>" alt="<?php echo h($room['room_type']); ?>" data-gallery-main>
                        <span class="gallery-count" data-gallery-count>1 / <?php echo count($images); ?></span>                    </button>
                    <?php if (count($images) > 1): ?>
                        <div class="gallery-thumbs" aria-label="Danh sách ảnh phòng">
                            <?php foreach (array_slice($images, 0, 4) as $index => $image): ?>
                                <?php $hiddenImageCount = count($images) - 4; ?>
                                <button class="gallery-thumb <?php echo $index === 0 ? 'is-active' : ''; ?> <?php echo $index === 3 && $hiddenImageCount > 0 ? 'gallery-thumb-more' : ''; ?>" type="button" data-gallery-thumb data-index="<?php echo (int)$index; ?>" <?php echo $index === 3 && $hiddenImageCount > 0 ? 'data-more-label="+' . (int)$hiddenImageCount . '"' : ''; ?> aria-label="Anh phong <?php echo (int)($index + 1); ?>">
                                    <img src="<?php echo h($image); ?>" alt="<?php echo h($room['room_type']); ?>">
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="panel panel-body mt-3">
                    <div class="kicker"><?php echo h($room['hotel_name']); ?></div>
                    <h1 class="room-title"><?php echo h($room['room_type']); ?> - Phòng <?php echo h($room['room_number']); ?></h1>
                    <div class="stars"><?php echo str_repeat('★', (int)$room['star_rating']); ?> <span class="text-muted"><?php echo h($room['hotel_address'] . ', ' . $room['hotel_city']); ?></span></div>
                    <?php if ((int)$reviewSummary['total'] > 0): ?>
                        <div class="detail-rating"><i class="fa fa-star"></i><strong><?php echo number_format((float)$reviewSummary['avg_rating'], 1, ',', '.'); ?>/5</strong><span><?php echo (int)$reviewSummary['total']; ?> Đánh giá</span></div>
                    <?php endif; ?>
                    <div class="meta">
                        <span><i class="fa fa-users"></i> <?php echo (int)$room['capacity']; ?> khách</span>
                        <span><i class="fa fa-child"></i> Free <?php echo (int)$freeChildren; ?> em bé</span>
                        <span><i class="fa fa-ruler-combined"></i> <?php echo h($room['room_size']); ?></span>
                        <span><i class="fa fa-bed"></i> <?php echo h($room['bed_type']); ?></span>
                        <span><i class="fa fa-building"></i> Tầng <?php echo (int)$room['floor_no']; ?></span>
                    </div>
                    <p class="mb-0 text-muted"><?php echo h($room['note'] ?: 'Phòng được bố trí gọn gàng, đủ tiện nghi cho kỳ nghỉ và công tác. Giá phòng được tính theo đêm hoặc theo giờ tùy nhu cầu lưu trú.'); ?></p>
                </div>
            </div>

            <aside class="panel panel-body booking-panel">
                <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                    <div>
                        <div class="kicker">Giá phòng</div>
                        <div class="price"><?php echo money($room['price_per_night']); ?></div>
                        <div class="text-muted small">Thêm giờ · <?php echo money($room['hourly_rate']); ?>/giờ</div>
                    </div>
                    <span class="chip"><?php echo h($room['package_name']); ?></span>
                </div>

                <form class="booking-form" method="get">
                    <input type="hidden" name="id_room" value="<?php echo (int)$roomId; ?>">
                    <input type="hidden" name="check_in" id="checkInInput" value="<?php echo h($checkIn); ?>">
                    <input type="hidden" name="check_out" id="checkOutInput" value="<?php echo h($checkOut); ?>">
                    <div class="row g-2">
                        <div class="col-12">
                            <label class="form-label">Ngày vào - ngày ra</label>
                            <div class="date-range-wrap">
                                <i class="fa fa-calendar-days"></i>
                                <input class="form-control date-range-input" id="dateRangeInput" type="text" value="<?php echo h($dateRangeLabel); ?>" readonly required>
                            </div>
                        </div>
                        <input type="hidden" name="guests" value="<?php echo (int)$adults; ?>">
                        <div class="col-6"><label class="form-label">Người lớn</label><select class="form-control" name="adults" id="adultsSelect"><?php for ($i = 1; $i <= $maxGuests; $i++): ?><option value="<?php echo $i; ?>" <?php echo $i === $adults ? 'selected' : ''; ?>><?php echo $i; ?> người lớn</option><?php endfor; ?></select></div>
                        <div class="col-6"><label class="form-label">Em bé</label><select class="form-control" name="children" id="childrenSelect"><?php for ($i = 0; $i <= $maxChildren; $i++): ?><option value="<?php echo $i; ?>" <?php echo $i === $children ? 'selected' : ''; ?>><?php echo $i; ?> em bé</option><?php endfor; ?></select></div>
                        <div class="col-12"><div class="text-muted small">Phòng <?php echo (int)$capacity; ?> người free <?php echo (int)$freeChildren; ?> em bé. Chỉ được vượt tối đa 1 người, phụ thu <?php echo h($extraGuestRateLabel); ?>% tiền phòng.</div></div>
                        <div class="col-12 d-grid"><button class="btn btn-outline-dark">Kiểm tra ngày</button></div>
                    </div>
                </form>

                <div class="status <?php echo $available ? 'ok' : 'bad'; ?> my-3">
                    <?php echo $available ? 'Phòng còn trống trong khoảng ngày này.' : 'Phòng không khả dụng trong khoảng ngày này.'; ?>
                </div>

                <div class="summary-line"><span><?php echo (int)$nightCount; ?> đêm</span><strong><?php echo money($roomBaseTotal); ?></strong></div>
                <?php if ($extraGuestFee > 0): ?><div class="summary-line"><span>Phụ thu thêm người (<?php echo h($extraGuestRateLabel); ?>% tiền phòng)</span><strong><?php echo money($extraGuestFee); ?></strong></div><?php endif; ?>
                <div class="summary-line"><span>Gói phòng</span><strong><?php echo h($room['package_name']); ?></strong></div>
                <div class="summary-line"><span>Cọc giữ phòng 50%</span><strong><?php echo money($depositAmount); ?></strong></div>
                <div class="d-grid mt-3">
                    <a class="btn btn-hotel <?php echo $available ? '' : 'disabled'; ?>" id="bookingLink" href="booking.php?id_room=<?php echo (int)$roomId; ?>&check_in=<?php echo urlencode($checkIn); ?>&check_out=<?php echo urlencode($checkOut); ?>&guests=<?php echo (int)$adults; ?>&adults=<?php echo (int)$adults; ?>&children=<?php echo (int)$children; ?>" <?php echo $available ? '' : 'aria-disabled="true"'; ?>>Đặt phòng và thanh toán cọc</a>
                </div>
            </aside>
        </section>

        <section class="panel amenity-panel">
            <div class="amenity-panel-head">
                <div>
                    <div class="kicker mb-1">Popular amenities</div>
                    <h2>Dịch vụ và tiện nghi</h2>
                </div>
                <span class="text-muted small"><?php echo count($amenities); ?> tiện nghi</span>
            </div>
            <div class="amenity-grid">
                <?php foreach ($amenityGroups as $group): ?>
                    <div class="amenity-group">
                        <i class="fa <?php echo h($group[0]); ?>"></i>
                        <div>
                            <h3><?php echo h($group[1]); ?></h3>
                            <ul>
                                <?php foreach (array_slice(array_unique($group[2]), 0, 4) as $item): ?>
                                    <li><?php echo h($item); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="panel review-panel">
            <div class="amenity-panel-head">
                <div>
                    <div class="kicker mb-1">Guest reviews</div>
                    <h2>Đánh giá</h2>
                </div>
                <span class="text-muted small"><?php echo (int)$reviewSummary['total']; ?> Đánh giá</span>
            </div>
            <?php if ((int)$reviewSummary['total'] <= 0): ?>
                <div class="text-muted">Phòng này chưa có đánh giá.</div>
            <?php else: ?>
                <div class="review-summary">
                    <div class="review-score">
                        <strong><?php echo number_format((float)$reviewSummary['avg_rating'], 1, ',', '.'); ?></strong>
                        <div class="review-stars"><?php echo h(renderReviewStars((float)$reviewSummary['avg_rating'])); ?></div>
                        <div class="text-muted small mt-2">Điểm trung bình từ khách đã trả phòng.</div>
                    </div>
                    <div class="review-list">
                        <div class="review-metrics">
                            <span><i class="fa fa-bed"></i> Phòng <?php echo number_format((float)$reviewSummary['avg_room_rating'], 1, ',', '.'); ?>/5</span>
                            <span><i class="fa fa-bell-concierge"></i> Dịch vụ <?php echo number_format((float)$reviewSummary['avg_service_rating'], 1, ',', '.'); ?>/5</span>
                        </div>
                        <?php foreach ($roomReviews as $review): ?>
                            <article class="review-item">
                                <div class="review-item-head">
                                    <div class="review-author-line">
                                        <strong><?php echo h($review['full_name']); ?></strong>
                                        <span class="text-muted small"><?php echo h(date('d/m/Y', strtotime($review['created_at']))); ?></span>
                                    </div>
                                    <div class="review-stars"><?php echo h(renderReviewStars(((float)$review['room_rating'] + (float)$review['service_rating']) / 2)); ?></div>
                                </div>
                                <div class="review-metrics mb-2">
                                    <span><i class="fa fa-bed"></i> Phòng <?php echo number_format((float)$review['room_rating'], 1, ',', '.'); ?>/5</span>
                                    <span><i class="fa fa-bell-concierge"></i> Dịch vụ <?php echo number_format((float)$review['service_rating'], 1, ',', '.'); ?>/5</span>
                                </div>
                                <?php if (trim((string)$review['comment']) !== ''): ?>
                                    <p class="mb-0 text-muted"><?php echo h($review['comment']); ?></p>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>
<div class="gallery-lightbox" data-gallery-lightbox aria-hidden="true">
    <button class="gallery-lightbox-close" type="button" data-gallery-close aria-label="Dong"><i class="fa fa-xmark"></i></button>
    <button class="gallery-lightbox-prev" type="button" data-gallery-prev aria-label="Anh truoc"><i class="fa fa-chevron-left"></i></button>
    <img src="<?php echo h($images[0]); ?>" alt="<?php echo h($room['room_type']); ?>" data-gallery-lightbox-image>
    <button class="gallery-lightbox-next" type="button" data-gallery-next aria-label="Anh tiep theo"><i class="fa fa-chevron-right"></i></button>
</div>
<?php include __DIR__ . '/includes/layout/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
const roomGalleryImages = <?php echo json_encode(array_values($images), JSON_UNESCAPED_SLASHES); ?>;
const galleryMainImage = document.querySelector('[data-gallery-main]');
const galleryCount = document.querySelector('[data-gallery-count]');
const galleryThumbs = Array.from(document.querySelectorAll('[data-gallery-thumb]'));
const galleryOpen = document.querySelector('[data-gallery-open]');
const galleryLightbox = document.querySelector('[data-gallery-lightbox]');
const galleryLightboxImage = document.querySelector('[data-gallery-lightbox-image]');
let activeGalleryIndex = 0;

const setGalleryImage = (index) => {
    if (!roomGalleryImages.length) return;
    activeGalleryIndex = (index + roomGalleryImages.length) % roomGalleryImages.length;
    const image = roomGalleryImages[activeGalleryIndex];
    if (galleryMainImage) galleryMainImage.src = image;
    if (galleryLightboxImage) galleryLightboxImage.src = image;
    if (galleryCount) galleryCount.textContent = `${activeGalleryIndex + 1} / ${roomGalleryImages.length}`;
    galleryThumbs.forEach((thumb) => {
        thumb.classList.toggle('is-active', Number(thumb.dataset.index || 0) === activeGalleryIndex);
    });
};

const openGalleryLightbox = () => {
    if (!galleryLightbox) return;
    setGalleryImage(activeGalleryIndex);
    galleryLightbox.classList.add('is-open');
    galleryLightbox.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
};

const closeGalleryLightbox = () => {
    if (!galleryLightbox) return;
    galleryLightbox.classList.remove('is-open');
    galleryLightbox.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
};

galleryThumbs.forEach((thumb) => {
    thumb.addEventListener('click', () => setGalleryImage(Number(thumb.dataset.index || 0)));
});
if (galleryOpen) galleryOpen.addEventListener('click', openGalleryLightbox);
document.querySelector('[data-gallery-close]')?.addEventListener('click', closeGalleryLightbox);
document.querySelector('[data-gallery-prev]')?.addEventListener('click', () => setGalleryImage(activeGalleryIndex - 1));
document.querySelector('[data-gallery-next]')?.addEventListener('click', () => setGalleryImage(activeGalleryIndex + 1));
galleryLightbox?.addEventListener('click', (event) => {
    if (event.target === galleryLightbox) closeGalleryLightbox();
});
document.addEventListener('keydown', (event) => {
    if (!galleryLightbox?.classList.contains('is-open')) return;
    if (event.key === 'Escape') closeGalleryLightbox();
    if (event.key === 'ArrowLeft') setGalleryImage(activeGalleryIndex - 1);
    if (event.key === 'ArrowRight') setGalleryImage(activeGalleryIndex + 1);
});
</script>
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
                if (typeof updateBookingLink === 'function') updateBookingLink();
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

const adultsSelect = document.getElementById('adultsSelect');
const childrenSelect = document.getElementById('childrenSelect');
const bookingLink = document.getElementById('bookingLink');
const capacity = <?php echo (int)$capacity; ?>;
const freeChildren = <?php echo (int)$freeChildren; ?>;
const updateBookingLink = () => {
    if (!bookingLink || !adultsSelect || !childrenSelect || !checkInInput || !checkOutInput) return;
    const adults = Math.min(Math.max(Number(adultsSelect.value || 1), 1), capacity + 1);
    const children = Math.max(0, Number(childrenSelect.value || 0));
    const params = new URLSearchParams({
        id_room: '<?php echo (int)$roomId; ?>',
        check_in: checkInInput.value,
        check_out: checkOutInput.value,
        guests: String(adults),
        adults: String(adults),
        children: String(children),
    });
    bookingLink.href = 'booking.php?' + params.toString();
};
const refreshChildrenChoices = () => {
    if (!adultsSelect || !childrenSelect) return;
    const currentChildren = Number(childrenSelect.value || 0);
    const adults = Math.min(Math.max(Number(adultsSelect.value || 1), 1), capacity + 1);
    const maxChildren = Math.max(0, freeChildren + (capacity + 1) - adults);
    childrenSelect.innerHTML = '';
    for (let value = 0; value <= maxChildren; value += 1) {
        const option = document.createElement('option');
        option.value = String(value);
        option.textContent = value + ' em bé';
        option.selected = value === Math.min(currentChildren, maxChildren);
        childrenSelect.appendChild(option);
    }
    updateBookingLink();
};
if (adultsSelect && childrenSelect) {
    adultsSelect.addEventListener('change', refreshChildrenChoices);
    childrenSelect.addEventListener('change', updateBookingLink);
    refreshChildrenChoices();
}
</script>
</body>
</html>
