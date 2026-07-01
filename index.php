<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/services/room_service.php';
require_once __DIR__ . '/includes/services/booking_service.php';

[$checkIn, $checkOut, $guests] = requestStayDefaults($today, $tomorrow);
$customerProfile = currentCustomer($db);
$message = '';
$isError = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        verifyCsrfToken();
        $checkIn = $_POST['check_in'] ?? $today;
        $checkOut = $_POST['check_out'] ?? $tomorrow;
        $message = createHomepageBooking($db, $_POST, $today, $tomorrow);
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $isError = true;
    }
}

$hotels = fetchBookableHotels($db, $guests, $checkIn, $checkOut);
$selectedHotelId = (int)($_GET['hotel'] ?? ($hotels[0]['id_hotel'] ?? 0));
$selectedHotel = selectedHotelFromList($hotels, $selectedHotelId);
$selectedHotelId = (int)($selectedHotel['id_hotel'] ?? 0);
$rooms = fetchRoomsForHotel($db, $selectedHotelId);
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php $pageTitle = 'Spotki Hotel'; include __DIR__ . '/includes/layout/assets.php'; ?>
    <style>
        :root { --red:#dc2626; --dark:#111827; --muted:#6b7280; --line:#e5e7eb; --bg:#f6f7fb; --cream:#f8fafc; --green:#047857; }
        body { margin:0; background:var(--bg); color:var(--dark); font-family:"Inter","Segoe UI",Arial,sans-serif; font-size:15px; line-height:1.55; -webkit-font-smoothing:antialiased; text-rendering:optimizeLegibility; }
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
        .hero { min-height:100svh; display:flex; align-items:flex-end; padding:160px 0 54px; color:#fff; position:relative; overflow:hidden; }
        .hero-track { position:absolute; inset:0; display:flex; transition:transform .62s cubic-bezier(.22,.61,.36,1); will-change:transform; }
        .hero-slide { position:relative; flex:0 0 100%; background-position:center; background-size:cover; }
        .hero-slide:before { content:""; position:absolute; inset:0; background:linear-gradient(90deg,rgba(17,24,39,.86),rgba(17,24,39,.30),rgba(17,24,39,.08)); }
        .hero:after { content:""; position:absolute; left:0; right:0; bottom:0; height:70px; background:linear-gradient(0deg,rgba(246,247,251,.72),rgba(246,247,251,0)); pointer-events:none; }
        .hero .container { position:relative; z-index:1; }
        .hero h1 { font-size:clamp(42px,7vw,92px); line-height:.92; font-weight:900; max-width:900px; letter-spacing:0; }
        .hero p { max-width:680px; color:rgba(255,255,255,.86); font-size:18px; line-height:1.65; }
        .hero-actions { display:flex; flex-wrap:wrap; gap:12px; margin-top:26px; }
        .btn-glass { min-height:48px; padding:11px 18px; border:1px solid rgba(255,255,255,.5); color:#fff; border-radius:8px; font-weight:900; text-decoration:none; background:rgba(255,255,255,.12); backdrop-filter:blur(10px); }
        .btn-glass:hover { color:#fff; background:rgba(255,255,255,.2); }
        .hero-stats { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; max-width:520px; margin-top:36px; }
        .hero-stat { border-left:1px solid rgba(255,255,255,.45); padding-left:14px; }
        .hero-stat strong { display:block; font-size:17px; line-height:1.15; margin-bottom:5px; }
        .hero-stat span { color:rgba(255,255,255,.76); font-size:13px; font-weight:700; line-height:1.35; display:block; }
        .hero-hover-zone { position:absolute; z-index:3; top:0; bottom:0; width:18%; border:0; background:transparent; opacity:0; cursor:pointer; }
        .hero-hover-zone.prev { left:0; }
        .hero-hover-zone.next { right:0; }
        .hero-hover-zone:focus-visible { outline:2px solid rgba(255,255,255,.7); outline-offset:-12px; opacity:1; }
        .booking-box,.room-card,.service-card,.hotel-card { background:#fff; border:1px solid var(--line); border-radius:8px; box-shadow:0 14px 36px rgba(15,23,42,.12); }
        .booking-box { padding:16px; color:var(--dark); }
        .btn-hotel { background:var(--red); border-color:var(--red); color:#fff; font-weight:500; }
        .btn-hotel:hover { background:#991b1b; border-color:#991b1b; color:#fff; }
        .section { padding:74px 0; }
        .kicker { color:var(--red); font-size:12px; font-weight:900; text-transform:uppercase; letter-spacing:.08em; }
        .lead-text { color:#4b5563; font-size:17px; line-height:1.75; }
        .section-head { display:flex; justify-content:space-between; align-items:end; gap:24px; margin-bottom:28px; }
        .section-head h2 { max-width:620px; }
        .feature-strip { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:1px; background:var(--line); border-top:1px solid var(--line); border-bottom:1px solid var(--line); }
        .feature-item { background:#fff; padding:22px; min-height:150px; }
        .feature-item i { color:var(--red); font-size:24px; margin-bottom:14px; }
        .feature-item h3 { font-size:16px; font-weight:900; margin-bottom:8px; }
        .info-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:16px; }
        .info-panel { background:#fff; border:1px solid var(--line); border-radius:8px; padding:22px; min-height:210px; box-shadow:0 10px 28px rgba(15,23,42,.06); }
        .info-panel i { width:42px; height:42px; border-radius:8px; display:inline-flex; align-items:center; justify-content:center; background:#fee2e2; color:var(--red); margin-bottom:14px; }
        .info-panel h3 { font-size:18px; font-weight:900; margin-bottom:10px; }
        .story-grid { display:grid; grid-template-columns:minmax(0,.9fr) minmax(0,1.1fr); gap:34px; align-items:center; }
        .story-copy { max-width:560px; }
        .story-media { display:grid; grid-template-columns:1fr .72fr; gap:10px; align-items:stretch; }
        .story-media img { width:100%; height:100%; min-height:360px; object-fit:cover; display:block; border-radius:8px; }
        .story-media img:last-child { min-height:260px; align-self:end; }
        .full-bleed-gallery { display:grid; grid-template-columns:1.35fr .8fr .85fr; gap:10px; min-height:520px; }
        .full-bleed-gallery img { width:100%; height:100%; object-fit:cover; display:block; }
        .full-bleed-gallery img:first-child { grid-row:span 2; }
        .editorial-band { background:#111827; color:#fff; }
        .editorial-band .lead-text { color:rgba(255,255,255,.72); }
        .experience-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:16px; }
        .experience-card { position:relative; min-height:340px; overflow:hidden; border-radius:8px; color:#fff; display:flex; align-items:end; text-decoration:none; background:#111827; }
        .experience-card img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; opacity:.82; transition:transform .35s ease; }
        .experience-card:after { content:""; position:absolute; inset:0; background:linear-gradient(0deg,rgba(17,24,39,.78),rgba(17,24,39,.08)); }
        .experience-card:hover img { transform:scale(1.04); }
        .experience-card > div { position:relative; z-index:1; padding:22px; }
        .experience-card h3 { font-weight:900; margin:0 0 8px; }
        .experience-card p { color:rgba(255,255,255,.78); margin:0; }
        .hotel-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px; }
        .hotel-card { display:block; overflow:hidden; color:inherit; text-decoration:none; }
        .hotel-card.active { outline:3px solid rgba(220,38,38,.28); border-color:var(--red); }
        .hotel-card img { width:100%; aspect-ratio:16/9; object-fit:cover; display:block; }
        .hotel-card-body { padding:14px; }
        .stars { color:#f59e0b; font-size:13px; }
        .room-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:16px; }
        #rooms,#hotels { display:none; }
        .room-card { overflow:hidden; }
        .room-image { display:block; aspect-ratio:4/3; background:#e5e7eb; overflow:hidden; }
        .room-image img { width:100%; height:100%; object-fit:cover; display:block; transition:transform .24s ease; }
        .room-image:hover img { transform:scale(1.04); }
        .room-body { padding:15px; }
        .meta { display:flex; flex-wrap:wrap; gap:8px; margin:10px 0; color:var(--muted); font-size:13px; }
        .price { color:var(--red); font-size:18px; font-weight:900; white-space:nowrap; }
        .service-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; }
        .service-card { padding:16px; }
        .service-card i { width:38px; height:38px; border-radius:8px; display:inline-flex; align-items:center; justify-content:center; background:#fee2e2; color:var(--red); margin-bottom:10px; }
        .modal-mask { position:fixed; inset:0; z-index:50; display:none; align-items:center; justify-content:center; padding:18px; background:rgba(17,24,39,.62); }
        .modal-mask.is-open { display:flex; }
        .modal-panel { width:min(620px,100%); background:#fff; border-radius:8px; overflow:hidden; }
        .modal-head { display:flex; justify-content:space-between; align-items:center; padding:14px 16px; border-bottom:1px solid var(--line); }
        .modal-body { padding:16px; }
        footer { background:#111827; color:#d1d5db; padding:28px 0; }
        .chip-list { display:flex; flex-wrap:wrap; gap:6px; margin:10px 0; }
        .chip { display:inline-flex; align-items:center; min-height:24px; padding:3px 8px; border-radius:999px; background:#f3f4f6; font-size:12px; font-weight:800; color:#374151; }
        @media (max-width:992px) { .hotel-grid,.room-grid,.service-grid,.feature-strip,.experience-grid,.info-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } .story-grid,.full-bleed-gallery { grid-template-columns:1fr; } .hero { min-height:780px; } }
        @media (max-width:992px) { .hero-hover-zone { width:24%; } }
        @media (max-width:640px) { .links { display:none; } .hotel-grid,.room-grid,.service-grid,.feature-strip,.experience-grid,.hero-stats,.info-grid { grid-template-columns:1fr; } .section-head { display:block; } .hero { padding-top:110px; } .hero h1 { font-size:42px; } }
    </style>
</head>
<body>
<?php $activePage = 'home'; $navClass = 'hotel-navbar'; $servicesLabel = 'Dịch vụ và tiện ích'; $showPhone = true; include __DIR__ . '/includes/layout/header.php'; ?>

<header class="hero">
    <div class="hero-track" id="heroTrack">
        <?php if ($hotels): ?>
            <?php $lastHeroHotel = $hotels[count($hotels) - 1]; ?>
            <div class="hero-slide" data-hero-clone="last" style="background-image:url('<?php echo h($lastHeroHotel['hero_image'] ?: defaultHotelHeroImage()); ?>')"></div>
            <?php foreach ($hotels as $index => $hotel): ?>
                <div class="hero-slide" data-hero-slide="<?php echo $index; ?>" style="background-image:url('<?php echo h($hotel['hero_image'] ?: defaultHotelHeroImage()); ?>')"></div>
            <?php endforeach; ?>
            <?php $firstHeroHotel = $hotels[0]; ?>
            <div class="hero-slide" data-hero-clone="first" style="background-image:url('<?php echo h($firstHeroHotel['hero_image'] ?: defaultHotelHeroImage()); ?>')"></div>
        <?php endif; ?>
    </div>
    <div class="container">
        <div class="row g-4 align-items-end">
            <div class="col-lg-9">
                <div class="kicker mb-2" id="heroCity"><?php echo h($selectedHotel['city'] ?? 'Spotki Hotel'); ?></div>
                <h1 class="mb-3" id="heroTitle"><?php echo h($selectedHotel['hotel_name'] ?? 'Spotki Hotel'); ?></h1>
                <p class="mb-0" id="heroDescription"><?php echo h($selectedHotel['description'] ?? 'Tận hưởng phòng nghỉ rộng rãi, dịch vụ chỉn chu và quy trình đặt phòng rõ ràng trong hệ thống Spotki Hotel.'); ?></p>
                <?php if ($selectedHotel): ?>
                    <div class="mt-3"><span class="stars" id="heroStars"><?php echo str_repeat('★', (int)$selectedHotel['star_rating']); ?></span> <span id="heroAddress"><?php echo h($selectedHotel['address'] . ', ' . $selectedHotel['city']); ?></span></div>
                <?php endif; ?>
                <div class="hero-stats">
                    <div class="hero-stat" data-hero-criterion><strong>Không gian tinh tế</strong><span>thiết kế sạch, sáng và đủ riêng tư cho từng kỳ lưu trú</span></div>
                    <div class="hero-stat" data-hero-criterion><strong>Dịch vụ chuẩn mực</strong><span>lễ tân, dọn phòng, an ninh và hỗ trợ khách 24/7</span></div>
                    <div class="hero-stat" data-hero-criterion><strong>Vị trí xứng tầm</strong><span>kết nối thuận tiện đến trung tâm, biển hoặc khu phố cổ</span></div>
                </div>
            </div>
        </div>
    </div>
    <button class="hero-hover-zone prev" type="button" id="heroPrevZone" aria-label="Cơ sở trước"></button>
    <button class="hero-hover-zone next" type="button" id="heroNextZone" aria-label="Cơ sở tiếp theo"></button>
</header>

<main>
    <section class="feature-strip">
        <div class="feature-item"><i class="fa fa-bed"></i><h3>Phòng theo từng nhu cầu</h3><p class="text-muted mb-0">Từ Standard gọn gàng đến Suite rộng rãi, giá và sức chứa được hiển thị rõ trước khi đặt.</p></div>
        <div class="feature-item"><i class="fa fa-location-dot"></i><h3>Vị trí trung tâm</h3><p class="text-muted mb-0">Các chi nhánh nằm tại khu vực thuận tiện cho công tác, nghỉ dưỡng và di chuyển.</p></div>
        <div class="feature-item">
        <i class="fa fa-user-shield"></i>
        <h3>An ninh & riêng tư</h3>
        <p class="text-muted mb-0">
            Hệ thống bảo mật hiện đại cùng quy trình phục vụ chuyên nghiệp giúp khách hàng an tâm trong suốt kỳ nghỉ.
        </p>
        </div>
        <div class="feature-item">
    <i class="fa fa-headset"></i>
    <h3>Hỗ trợ 24/7</h3>
    <p class="text-muted mb-0">Đội ngũ chăm sóc khách hàng luôn sẵn sàng hỗ trợ đặt phòng, thay đổi lịch và giải đáp nhanh chóng.</p>
        </div>
    </section>

    <section class="section">
        <div class="container">
            <div class="section-head">
                <div>
                    <div class="kicker mb-2">Thông tin lưu trú</div>
                    <h2 class="fw-bold mb-0">Mọi thông tin cần biết trước khi chọn phòng</h2>
                </div>
                <p class="lead-text mb-0">Trang chủ tập trung giới thiệu hệ thống, trải nghiệm và những tiêu chuẩn vận hành để khách dễ đánh giá trước khi vào tab khách sạn.</p>
            </div>
            <div class="info-grid">
                <div class="info-panel"><i class="fa fa-clock"></i><h3>Giờ nhận và trả phòng</h3><p class="text-muted mb-0">Nhận phòng từ 14:00 và trả phòng trước 12:00. Các yêu cầu đến sớm hoặc trả muộn được lễ tân hỗ trợ theo tình trạng phòng thực tế.</p></div>
                <div class="info-panel"><i class="fa fa-wifi"></i><h3>Kết nối tiện ích</h3><p class="text-muted mb-0">Khách lưu trú được sử dụng Wi-Fi tốc độ cao, khu vực làm việc yên tĩnh và các tiện ích hỗ trợ sinh hoạt trong suốt thời gian nghỉ tại khách sạn.</p></div>
                <div class="info-panel"><i class="fa fa-broom"></i><h3>Vệ sinh tiêu chuẩn</h3><p class="text-muted mb-0">Phòng được dọn dẹp định kỳ, thay mới vật dụng cần thiết và kiểm tra kỹ trước khi bàn giao nhằm đảm bảo không gian luôn sạch sẽ, thoải mái.</p></div>
            </div>
        </div>
    </section>

    <section class="section bg-white" id="story">
        <div class="container">
            <div class="story-grid">
                <div class="story-copy">
                    <div class="kicker mb-2">The Spotki experience</div>
                    <h2 class="fw-bold mb-3">Một không gian nghỉ dưỡng được thiết kế cho cảm giác dễ chịu ngay khi bước vào.</h2>
                    <p class="lead-text mb-0">Spotki Hotel tập trung vào phòng nghỉ sáng, sạch, bố trí hợp lý và dịch vụ vận hành rõ ràng. Khách có thể xem ảnh phòng, giá theo từng hạng, tiện nghi và kiểm tra tình trạng phòng trước khi gửi yêu cầu đặt.</p>
                </div>
                <?php $homeImages = hotelRoomImageSet(); ?>
                <div class="story-media">
                    <img src="images/8.jpg" alt="Suite khách sạn">
                    <img src="images/4.jpg" alt="Góc phòng khách sạn">
                </div>
            </div>
        </div>
    </section>

    <section class="editorial-band section">
        <div class="container">
            <div class="section-head">
                <div>
                    <div class="kicker mb-2">Spaces</div>
                    <h2 class="fw-bold mb-0">Ảnh lớn từ khách sạn và các góc phòng nổi bật</h2>
                </div>
            </div>
        </div>
        <div class="full-bleed-gallery">
            <img src="images/5.jpg" alt="Khách sạn Spotki">
            <img src="<?php echo h($homeImages[1]); ?>" alt="Phòng ngủ khách sạn">
            <img src="images/6.jpg" alt="Không gian phòng khách sạn">
            <img src="images/3.jpg" alt="Khu nghỉ dưỡng">
            <img src="<?php echo h($homeImages[3]); ?>" alt="Phòng nghỉ cao cấp">
        </div>
    </section>

    <section class="section bg-white">
        <div class="container">
            <div class="section-head">
                <div>
                    <div class="kicker mb-2">Stay styles</div>
                    <h2 class="fw-bold mb-0">Chọn trải nghiệm phù hợp với chuyến đi</h2>
                </div>
            </div>
            <div class="experience-grid">
                <a class="experience-card" href="#rooms"><img src="images/7.jpg" alt="Công tác"><div><h3>Công tác gọn gàng</h3><p>Bàn làm việc, Wi-Fi, vị trí thuận tiện và giá phòng rõ ràng.</p></div></a>
                <a class="experience-card" href="#rooms"><img src="<?php echo h($homeImages[8]); ?>" alt="Nghỉ dưỡng"><div><h3>Nghỉ dưỡng cuối tuần</h3><p>Phòng sáng, giường lớn, dịch vụ ăn sáng và hỗ trợ lễ tân.</p></div></a>
                <a class="experience-card" href="#rooms"><img src="<?php echo h($homeImages[7]); ?>" alt="Suite gia đình"><div><h3>Suite cho gia đình</h3><p>Không gian rộng hơn, sức chứa lớn và tiện nghi đi kèm.</p></div></a>
            </div>
        </div>
    </section>

    <section class="section bg-white" id="hotels">
        <div class="container">
            <div class="kicker mb-1">Hotel chain</div>
            <h2 class="fw-bold mb-4">Chọn khách sạn trong hệ thống</h2>
            <div class="hotel-grid">
                <?php foreach ($hotels as $hotel): ?>
                    <a class="hotel-card" href="rooms.php?hotel=<?php echo (int)$hotel['id_hotel']; ?>&check_in=<?php echo urlencode($checkIn); ?>&check_out=<?php echo urlencode($checkOut); ?>&guests=<?php echo (int)$guests; ?>">
                        <img src="<?php echo h($hotel['hero_image']); ?>" alt="<?php echo h($hotel['hotel_name']); ?>">
                        <div class="hotel-card-body">
                            <div class="stars"><?php echo str_repeat('★', (int)$hotel['star_rating']); ?></div>
                            <h3 class="h5 fw-bold mb-1"><?php echo h($hotel['hotel_name']); ?></h3>
                            <div class="text-muted small"><i class="fa fa-location-dot"></i> <?php echo h($hotel['address'] . ', ' . $hotel['city']); ?></div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="mt-5 pt-2" id="rooms">
            <?php if ($message !== ''): ?><div class="alert <?php echo $isError ? 'alert-danger' : 'alert-success'; ?>"><?php echo h($message); ?></div><?php endif; ?>
            <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
                <div><div class="kicker mb-1">Rooms by hotel</div><h2 class="fw-bold mb-0">Danh sách phòng của cơ sở đang chọn</h2></div>
                <div class="text-muted fw-bold"><?php echo h($checkIn); ?> - <?php echo h($checkOut); ?> · <?php echo (int)$guests; ?> khách</div>
            </div>
            <div class="room-grid">
                <?php foreach ($rooms as $index => $room): ?>
                    <?php $available = $room['status'] === 'available' && isRoomAvailable($db, (int)$room['id_room'], $checkIn . ' 14:00:00', $checkOut . ' 12:00:00') && (int)$room['capacity'] >= $guests; ?>
                    <article class="room-card">
                        <a class="room-image" href="room_detail.php?id_room=<?php echo (int)$room['id_room']; ?>&check_in=<?php echo urlencode($checkIn); ?>&check_out=<?php echo urlencode($checkOut); ?>&guests=<?php echo (int)$guests; ?>" aria-label="Xem chi tiết <?php echo h($room['room_type']); ?>"><img src="<?php echo h(roomDisplayImage($room)); ?>" alt="<?php echo h($room['room_type']); ?>"></a>
                        <div class="room-body">
                            <div class="d-flex justify-content-between gap-2">
                                <div><h3 class="h5 fw-bold mb-1"><?php echo h($room['room_type']); ?></h3><div class="text-muted small"><?php echo h($room['hotel_name']); ?> · Phòng <?php echo h($room['room_number']); ?></div></div>
                                <div class="price"><?php echo money($room['price_per_night']); ?></div>
                            </div>
                            <div class="meta"><span><i class="fa fa-users"></i> <?php echo (int)$room['capacity']; ?> khách</span><span><i class="fa fa-ruler-combined"></i> <?php echo h($room['room_size']); ?></span><span><i class="fa fa-bed"></i> <?php echo h($room['bed_type']); ?></span><span><i class="fa fa-clock"></i> <?php echo money($room['hourly_rate']); ?>/giờ</span></div>
                            <div class="chip-list">
                                <?php foreach (array_slice(array_map('trim', explode(',', (string)$room['amenities'])), 0, 4) as $amenity): ?>
                                    <?php if ($amenity !== ''): ?><span class="chip"><?php echo h($amenity); ?></span><?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <p class="text-muted small mb-3"><?php echo h($room['note'] ?: 'Phòng được trang bị đầy đủ tiện nghi cho kỳ nghỉ thoải mái.'); ?></p>
                            <div class="d-grid gap-2">
                                <a class="btn btn-outline-dark" href="room_detail.php?id_room=<?php echo (int)$room['id_room']; ?>&check_in=<?php echo urlencode($checkIn); ?>&check_out=<?php echo urlencode($checkOut); ?>&guests=<?php echo (int)$guests; ?>">Xem chi tiết phòng</a>
                                <button class="btn <?php echo $available ? 'btn-hotel' : 'btn-outline-secondary'; ?> w-100 book-btn" type="button" <?php echo $available ? '' : 'disabled'; ?> data-room-id="<?php echo (int)$room['id_room']; ?>" data-room-name="<?php echo h($room['room_type'] . ' - Phòng ' . $room['room_number']); ?>" data-package="<?php echo h($room['package_name']); ?>"><?php echo $available ? 'Đặt nhanh' : 'Không khả dụng'; ?></button>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            </div>
        </div>
    </section>

    <section class="section bg-white" id="hotel-choice">
        <div class="container">
            <div class="section-head">
                <div>
                    <div class="kicker mb-2">Hotel locations</div>
                    <h2 class="fw-bold mb-0">Chọn cơ sở để xem danh sách phòng</h2>
                </div>
                <p class="lead-text mb-0">Mỗi cơ sở có hạng phòng, mức giá và tiện nghi riêng. Chọn cơ sở phù hợp để xem phòng đang có.</p>
            </div>
            <div class="hotel-grid">
                <?php foreach ($hotels as $hotel): ?>
                    <a class="hotel-card" href="rooms.php?hotel=<?php echo (int)$hotel['id_hotel']; ?>&check_in=<?php echo urlencode($checkIn); ?>&check_out=<?php echo urlencode($checkOut); ?>&guests=<?php echo (int)$guests; ?>">
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
</main>

<div class="modal-mask" id="bookingModal">
    <form class="modal-panel" method="post">
        <div class="modal-head"><div><div class="kicker mb-1">Booking request</div><h2 class="h5 fw-bold mb-0" id="modalRoomName">Đặt phòng</h2><div class="small text-muted" id="modalPackage"></div></div><button class="btn btn-light" type="button" id="closeModal">&times;</button></div>
<div class="modal-body">
            <input type="hidden" name="id_room" id="modalRoomId">
            <input type="hidden" name="check_in" value="<?php echo h($checkIn); ?>">
            <input type="hidden" name="check_out" value="<?php echo h($checkOut); ?>">

            <input type="hidden" name="room_total" id="modalRoomTotal" value="0">
            <div class="row g-3">
                <?php if ($customerProfile): ?>
                    <div class="col-12"><div class="alert alert-info mb-0">Email và CCCD/hộ chiếu lấy từ trang cá nhân để tích điểm. Tên gợi nhớ và số điện thoại tạm thời chỉ áp dụng cho booking này.</div></div>
                    <div class="col-md-6"><label class="form-label fw-bold">Tên gợi nhớ</label><input class="form-control" name="contact_name" value="<?php echo h($customerProfile['full_name']); ?>" required></div>
                    <div class="col-md-6"><label class="form-label fw-bold">Số điện thoại tạm thời</label><input class="form-control" name="contact_phone" value="<?php echo h($customerProfile['phone']); ?>" required></div>
                    <div class="col-12"><label class="form-label fw-bold">Email</label><input class="form-control" type="email" value="<?php echo h($customerProfile['email']); ?>" disabled></div>
                    <div class="col-md-6"><label class="form-label fw-bold">CCCD/Hộ chiếu</label><input class="form-control" value="<?php echo h($customerProfile['identity_no']); ?>" disabled></div>
                    <div class="col-md-6"><label class="form-label fw-bold">Địa chỉ</label><input class="form-control" value="<?php echo h($customerProfile['address']); ?>" disabled></div>
                <?php else: ?>
                    <div class="col-md-6"><label class="form-label fw-bold">Họ tên</label><input class="form-control" name="full_name" required></div>
                    <div class="col-md-6"><label class="form-label fw-bold">Điện thoại</label><input class="form-control" name="phone" required></div>
                    <div class="col-12"><label class="form-label fw-bold">Email</label><input class="form-control" type="email" name="email" required></div>
                    <div class="col-md-6"><label class="form-label fw-bold">CCCD/Hộ chiếu</label><input class="form-control" name="identity_no" required></div>
                    <div class="col-md-6"><label class="form-label fw-bold">Địa chỉ</label><input class="form-control" name="address" required></div>
                <?php endif; ?>

                <div class="col-md-6"><label class="form-label fw-bold">Người lớn</label><input class="form-control" type="number" name="adults" min="1" value="<?php echo max(1, $guests); ?>"></div>
                <div class="col-md-6"><label class="form-label fw-bold">Trẻ em</label><input class="form-control" type="number" name="children" min="0" value="0"></div>

                <div class="col-12"><label class="form-label fw-bold">Ghi chú</label><textarea class="form-control" name="note" rows="3"></textarea></div>

                <div class="col-12">
                    <div class="deposit-box">
                        <div class="small text-muted mb-1">Tổng tiền phòng dự kiến</div>
                        <div class="deposit-amount" id="modalRoomTotalText">0 đ</div>
                        <div class="small text-muted mt-2">Cọc giữ phòng 50%</div>
                        <div class="deposit-amount" id="modalDepositAmountText">0 đ</div>
                        <div class="small text-muted mt-1">Phần còn lại thanh toán khi nhận/trả phòng theo quy định khách sạn.</div>
                    </div>
                </div>

                <div class="col-12"><label class="form-label fw-bold">Phương thức cọc</label>
                    <select class="form-control" name="deposit_method" required>
                        <option value="">Chọn phương thức</option>
                        <option>Chuyển khoản ngân hàng</option>
                        <option>Ví điện tử</option>
                        <option>Thẻ nội địa/quốc tế</option>
                    </select>
                </div>

                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="deposit_confirm" value="1" id="modalDepositConfirm" required>
                        <label class="form-check-label" for="modalDepositConfirm">Tôi xác nhận sẽ tiến hành cọc 50% để giữ phòng.</label>
                    </div>
                </div>

                <div class="col-12 d-grid">
                    <button class="btn btn-hotel" type="submit">Tiến hành cọc 50% và đặt phòng</button>
                </div>
            </div>
        </div>
    </form>
</div>

<?php include __DIR__ . '/includes/layout/footer.php'; ?>

<script>
const hotelSlides = <?php echo json_encode(array_map(static function ($hotel) {
    return [
        'id' => (int)$hotel['id_hotel'],
        'name' => (string)$hotel['hotel_name'],
        'city' => (string)$hotel['city'],
        'address' => trim((string)$hotel['address'] . ', ' . (string)$hotel['city']),
        'description' => (string)$hotel['description'],
        'stars' => str_repeat('★', (int)$hotel['star_rating']),
    ];
}, $hotels), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const siteHeader = document.getElementById('siteHeader');
function syncHeaderState() {
    if (siteHeader) {
        siteHeader.classList.toggle('is-scrolled', window.scrollY > 40);
    }
}
syncHeaderState();
window.addEventListener('scroll', syncHeaderState, { passive: true });

const heroTitle = document.getElementById('heroTitle');
const heroCity = document.getElementById('heroCity');
const heroDescription = document.getElementById('heroDescription');
const heroStars = document.getElementById('heroStars');
const heroAddress = document.getElementById('heroAddress');
const heroRoomsLink = document.getElementById('heroRoomsLink');
const heroTrack = document.getElementById('heroTrack');
const heroPrev = document.getElementById('heroPrevZone');
const heroNext = document.getElementById('heroNextZone');
const heroCriteria = document.querySelectorAll('[data-hero-criterion]');
let heroIndex = 0;
let heroTrackIndex = hotelSlides.length > 1 ? 1 : 0;
let heroTimer = null;
let heroAnimating = false;

const hotelCriteria = [
    [
        ['Không gian tinh tế', 'thiết kế sạch, sáng và đủ riêng tư cho từng kỳ lưu trú'],
        ['Dịch vụ chuẩn mực', 'lễ tân, dọn phòng, an ninh và hỗ trợ khách 24/7'],
        ['Vị trí xứng tầm', 'kết nối thuận tiện đến trung tâm, biển hoặc khu phố cổ']
    ],
    [
        ['Tầm nhìn nghỉ dưỡng', 'không gian mở, gần biển và phù hợp cho kỳ nghỉ thư giãn'],
        ['Tiện ích trọn gói', 'hồ bơi, spa, ăn sáng và đưa đón theo nhu cầu'],
        ['Trải nghiệm yên tĩnh', 'nhịp lưu trú nhẹ nhàng, riêng tư và dễ phục hồi năng lượng']
    ],
    [
        ['Dấu ấn boutique', 'không gian có cá tính, gần phố cổ và thuận tiện đi bộ'],
        ['Ấm cúng đúng mực', 'phục vụ gần gũi, gọn gàng và chú ý từng chi tiết nhỏ'],
        ['Kết nối văn hóa', 'phù hợp khách muốn khám phá ẩm thực, lịch sử và phố phường']
    ]
];

function renderHeroContent(index) {
    if (!hotelSlides.length) return;
    index = (index + hotelSlides.length) % hotelSlides.length;
    heroIndex = index;
    const hotel = hotelSlides[index];
    if (!hotel) return;
    if (heroCity) heroCity.textContent = hotel.city || 'Spotki Hotel';
    if (heroTitle) heroTitle.textContent = hotel.name || 'Spotki Hotel';
    if (heroDescription) heroDescription.textContent = hotel.description || '';
    if (heroStars) heroStars.textContent = hotel.stars || '';
    if (heroAddress) heroAddress.textContent = hotel.address || '';
    if (heroRoomsLink) heroRoomsLink.href = '?hotel=' + encodeURIComponent(hotel.id) + '#rooms';
    const criteria = hotelCriteria[index % hotelCriteria.length] || hotelCriteria[0];
    heroCriteria.forEach(function(item, criterionIndex) {
        const value = criteria[criterionIndex] || ['', ''];
        const strong = item.querySelector('strong');
        const span = item.querySelector('span');
        if (strong) strong.textContent = value[0];
        if (span) span.textContent = value[1];
    });
}

function moveHeroTo(trackIndex, contentIndex) {
    if (!hotelSlides.length || !heroTrack || heroAnimating) return;
    heroAnimating = true;
    heroTrackIndex = trackIndex;
    heroTrack.style.transition = 'transform .62s cubic-bezier(.22,.61,.36,1)';
    heroTrack.style.transform = 'translateX(-' + (heroTrackIndex * 100) + '%)';
    renderHeroContent(contentIndex);
}

function setHeroHotel(index) {
    if (!hotelSlides.length) return;
    index = (index + hotelSlides.length) % hotelSlides.length;
    moveHeroTo(index + 1, index);
}

function syncHeroTrackWithoutAnimation(trackIndex) {
    if (!heroTrack) return;
    heroTrack.style.transition = 'none';
    heroTrackIndex = trackIndex;
    heroTrack.style.transform = 'translateX(-' + (heroTrackIndex * 100) + '%)';
    heroTrack.offsetHeight;
    heroTrack.style.transition = 'transform .62s cubic-bezier(.22,.61,.36,1)';
}

if (heroTrack) {
    syncHeroTrackWithoutAnimation(heroTrackIndex);
    heroTrack.addEventListener('transitionend', function(event) {
        if (event.propertyName !== 'transform') return;
        heroAnimating = false;
        if (hotelSlides.length <= 1) return;
        if (heroTrackIndex === hotelSlides.length + 1) {
            syncHeroTrackWithoutAnimation(1);
            heroIndex = 0;
            renderHeroContent(heroIndex);
        } else if (heroTrackIndex === 0) {
            syncHeroTrackWithoutAnimation(hotelSlides.length);
            heroIndex = hotelSlides.length - 1;
            renderHeroContent(heroIndex);
        }
    });
}

if (heroPrev) {
    heroPrev.addEventListener('click', function() {
        if (!hotelSlides.length) return;
        if (heroIndex === 0) {
            moveHeroTo(0, hotelSlides.length - 1);
        } else {
            setHeroHotel(heroIndex - 1);
        }
        restartHeroTimer();
    });
}

if (heroNext) {
    heroNext.addEventListener('click', function() {
        if (!hotelSlides.length) return;
        if (heroIndex === hotelSlides.length - 1) {
            moveHeroTo(hotelSlides.length + 1, 0);
        } else {
            setHeroHotel(heroIndex + 1);
        }
        restartHeroTimer();
    });
}

function startHeroTimer() {
    if (hotelSlides.length <= 1) return;
    heroTimer = window.setInterval(function() {
        if (heroIndex === hotelSlides.length - 1) {
            moveHeroTo(hotelSlides.length + 1, 0);
        } else {
            setHeroHotel(heroIndex + 1);
        }
    }, 7000);
}

function restartHeroTimer() {
    if (heroTimer) window.clearInterval(heroTimer);
    startHeroTimer();
}

startHeroTimer();

document.querySelectorAll('.book-btn').forEach(function(button) {
    button.addEventListener('click', function() {
        document.getElementById('modalRoomId').value = button.dataset.roomId || '';
        document.getElementById('modalRoomName').textContent = button.dataset.roomName || 'Đặt phòng';
        document.getElementById('modalPackage').textContent = button.dataset.package || '';

        // Reset hiển thị tiền cọc (tính server-side khi submit)
        const totalText = document.getElementById('modalRoomTotalText');
        const depositText = document.getElementById('modalDepositAmountText');
        if (totalText) totalText.textContent = '0 đ';
        if (depositText) depositText.textContent = '0 đ';
        const roomTotal = document.getElementById('modalRoomTotal');
        if (roomTotal) roomTotal.value = '0';

        document.getElementById('bookingModal').classList.add('is-open');
    });
});
document.getElementById('closeModal').addEventListener('click', function() {
    document.getElementById('bookingModal').classList.remove('is-open');
});
document.getElementById('bookingModal').addEventListener('click', function(event) {
    if (event.target === this) this.classList.remove('is-open');
});
</script>
</body>
</html>
