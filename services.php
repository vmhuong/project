<?php
require_once __DIR__ . '/includes/bootstrap.php';

$amenities = [
    [
        'title' => 'Bể bơi bốn mùa',
        'icon' => 'fa-water-ladder',
        'image' => localizeRemoteImage('https://images.unsplash.com/photo-1758973470049-4514352776eb?auto=format&fit=crop&w=1400&q=82', 'service'),
        'text' => 'Không gian hồ bơi trong nhà được kiểm soát nhiệt độ ổn định, phù hợp để thư giãn, bơi nhẹ hoặc nghỉ ngơi sau hành trình dài.',
        'details' => ['9h00 - 17h00 từ tháng 10 đến tháng 3', '7h00 - 19h00 từ tháng 4 đến tháng 9'],
    ],
    [
        'title' => 'Khu vui chơi trẻ em trong nhà',
        'icon' => 'fa-child-reaching',
        'image' => localizeRemoteImage('https://images.unsplash.com/photo-1564429238817-393bd4286b2d?auto=format&fit=crop&w=1400&q=82', 'service'),
        'text' => 'Khu vui chơi có sàn mềm, đồ chơi vận động, góc đọc sách và nhân sự hỗ trợ để gia đình yên tâm tận hưởng kỳ nghỉ.',
        'details' => ['8h00 - 20h00 hằng ngày', 'Phù hợp trẻ từ 3 đến 10 tuổi'],
    ],
    [
        'title' => 'Gym & Yoga',
        'icon' => 'fa-dumbbell',
        'image' => localizeRemoteImage('https://images.unsplash.com/photo-1534438327276-14e5300c3a48?auto=format&fit=crop&w=1400&q=82', 'service'),
        'text' => 'Phòng tập trang bị máy cardio, tạ tự do và khu yoga yên tĩnh với ánh sáng tự nhiên, đáp ứng lịch tập nhẹ lẫn chuyên sâu.',
        'details' => ['Gym: 6h00 - 22h00', 'Yoga theo lịch lớp buổi sáng và chiều'],
    ],
    [
        'title' => 'Sân pickleball',
        'icon' => 'fa-table-tennis-paddle-ball',
        'image' => localizeRemoteImage('https://images.unsplash.com/photo-1693142518820-78d7a05f1546?auto=format&fit=crop&w=1400&q=82', 'service'),
        'text' => 'Sân pickleball ngoài trời có bề mặt tiêu chuẩn, cho thuê vợt bóng và đặt lịch theo khung giờ, phù hợp khách cá nhân hoặc nhóm.',
        'details' => ['6h00 - 21h00 hằng ngày', 'Có thể đặt sân trước tại lễ tân'],
    ],
    [
        'title' => 'Nhà hàng & lounge',
        'icon' => 'fa-utensils',
        'image' => localizeRemoteImage('https://images.unsplash.com/photo-1555396273-367ea4eb4db5?auto=format&fit=crop&w=1400&q=82', 'service'),
        'text' => 'Thực đơn kết hợp món Việt, món Á và lựa chọn Âu nhẹ nhàng, phục vụ trong không gian ấm cúng cho bữa sáng, trà chiều và bữa tối.',
        'details' => ['Buffet sáng: 6h30 - 10h00', 'Lounge: 10h00 - 23h00'],
    ],
    [
        'title' => 'Spa thư giãn',
        'icon' => 'fa-spa',
        'image' => localizeRemoteImage('https://images.unsplash.com/photo-1540555700478-4be289fbecef?auto=format&fit=crop&w=1400&q=82', 'service'),
        'text' => 'Các liệu trình massage, chăm sóc cơ thể và xông hơi được thiết kế để phục hồi năng lượng sau ngày dài di chuyển hoặc làm việc.',
        'details' => ['10h00 - 22h00 hằng ngày', 'Khuyến nghị đặt lịch trước 2 giờ'],
    ],
];
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php $pageTitle = 'Dịch vụ & Tiện ích - Spotki Hotel'; include __DIR__ . '/includes/layout/assets.php'; ?>
    <style>
        :root { --red:#dc2626; --dark:#111827; --muted:#6b7280; --line:#e5e7eb; --bg:#f6f7fb; }
        body { margin:0; background:var(--bg); color:var(--dark); font-family:"Inter","Segoe UI",Arial,sans-serif; font-size:15px; line-height:1.6; }
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
        .service-hero { min-height:72svh; display:flex; align-items:flex-end; padding:150px 0 70px; color:#fff; position:relative; overflow:hidden; background:url('<?php echo h(serviceHeroImage()); ?>') center/cover; }
        .service-hero:before { content:""; position:absolute; inset:0; background:linear-gradient(90deg,rgba(17,24,39,.84),rgba(17,24,39,.28)); }
        .service-hero .container { position:relative; z-index:1; }
        .service-hero h1 { font-size:clamp(42px,7vw,86px); line-height:.96; font-weight:900; max-width:900px; margin:0 0 18px; }
        .service-hero p { max-width:700px; color:rgba(255,255,255,.88); font-size:18px; line-height:1.65; margin:0; }
        .section { padding:74px 0; }
        .section.bg-white { background:#fff; }
        .kicker { color:var(--red); font-size:12px; font-weight:900; text-transform:uppercase; letter-spacing:.08em; }
        .lead-text { color:#4b5563; font-size:17px; line-height:1.75; }
        .intro-grid { display:grid; grid-template-columns:minmax(0,.95fr) minmax(0,1.05fr); gap:34px; align-items:center; }
        .intro-image,.amenity-image { border-radius:8px; overflow:hidden; background:#e5e7eb; box-shadow:0 16px 40px rgba(15,23,42,.12); }
        .intro-image { min-height:420px; }
        .intro-image img,.amenity-image img { width:100%; height:100%; object-fit:cover; display:block; transition:transform .42s ease, filter .42s ease; }
        .intro-image:hover img,.amenity-row:hover .amenity-image img { transform:scale(1.06); filter:saturate(1.08) contrast(1.04); }
        .intro-copy h2 { font-size:clamp(30px,4vw,48px); line-height:1.08; font-weight:900; margin:0 0 18px; }
        .amenities { display:grid; gap:42px; }
        .amenity-row { display:grid; grid-template-columns:minmax(0,1.08fr) minmax(360px,.92fr); gap:38px; align-items:center; padding:28px; background:#fff; border:1px solid var(--line); border-radius:8px; box-shadow:0 14px 36px rgba(15,23,42,.10); }
        .amenity-row:nth-child(even) { grid-template-columns:minmax(360px,.92fr) minmax(0,1.08fr); }
        .amenity-row:nth-child(even) .amenity-image { order:2; }
        .amenity-image { min-height:430px; }
        .amenity-copy { padding:14px clamp(4px,2vw,24px); }
        .amenity-copy i { width:46px; height:46px; border-radius:8px; display:inline-flex; align-items:center; justify-content:center; background:#fee2e2; color:var(--red); font-size:20px; margin-bottom:18px; }
        .amenity-copy h2 { font-size:clamp(26px,3vw,40px); line-height:1.12; font-weight:900; margin:0 0 16px; }
        .amenity-copy p { color:#4b5563; font-size:17px; line-height:1.78; margin:0 0 18px; }
        .detail-list { display:flex; flex-wrap:wrap; gap:8px; margin:0; padding:0; list-style:none; }
        .detail-list li { min-height:28px; padding:5px 10px; border-radius:999px; background:#f3f4f6; color:#374151; font-size:13px; font-weight:700; }
        @media (max-width:992px) { .intro-grid,.amenity-row,.amenity-row:nth-child(even) { grid-template-columns:1fr; } .amenity-row:nth-child(even) .amenity-image { order:0; } .amenity-image,.intro-image { min-height:320px; } }
        @media (max-width:640px) { .links { display:none; } }
    </style>
</head>
<body>
<?php $activePage = 'services'; $navClass = 'hotel-navbar'; $servicesLabel = 'Dịch vụ và tiện ích'; $showPhone = true; include __DIR__ . '/includes/layout/header.php'; ?>

<header class="service-hero">
    <div class="container">
        <div class="kicker mb-2">Hotel services</div>
        <h1>Dịch vụ &amp; Tiện ích</h1>
        <p>Tận hưởng đầy đủ tiện ích nghỉ dưỡng, vận động và thư giãn trong cùng một hệ thống Spotki Hotel.</p>
    </div>
</header>

<main>
    <section class="section bg-white">
        <div class="container">
            <div class="intro-grid">
                <div class="intro-copy">
                    <div class="kicker mb-2">Tổng quan</div>
                    <h2>Kỳ nghỉ trọn vẹn với dịch vụ được chuẩn bị chỉn chu</h2>
                    <p class="lead-text mb-0">Spotki Hotel không chỉ là nơi lưu trú, mà còn là không gian nghỉ dưỡng cho gia đình, khách công tác và nhóm bạn. Từ bể bơi bốn mùa, khu vui chơi trẻ em, gym-yoga đến sân pickleball, mỗi tiện ích đều được bố trí để lịch trình của bạn thoải mái và chủ động hơn.</p>
                </div>
                <div class="intro-image">
                    <img src='images/3.jpg'>
                </div>
            </div>
        </div>
    </section>

    <section class="section">
        <div class="container">
            <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
                <div>
                    <div class="kicker mb-1">Facilities</div>
                    <h2 class="fw-bold mb-0">Tiện ích nổi bật</h2>
                </div>
                <p class="lead-text mb-0 col-lg-5">Các khu vực tiện ích được tách rõ theo nhu cầu nghỉ dưỡng, vận động, gia đình và ẩm thực.</p>
            </div>

            <div class="amenities">
                <?php foreach ($amenities as $amenity): ?>
                    <article class="amenity-row">
                        <div class="amenity-image"><img src="<?php echo h($amenity['image']); ?>" alt="<?php echo h($amenity['title']); ?>"></div>
                        <div class="amenity-copy">
                            <i class="fa <?php echo h($amenity['icon']); ?>"></i>
                            <h2><?php echo h($amenity['title']); ?></h2>
                            <p><?php echo h($amenity['text']); ?></p>
                            <ul class="detail-list">
                                <?php foreach ($amenity['details'] as $detail): ?><li><?php echo h($detail); ?></li><?php endforeach; ?>
                            </ul>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
</main>

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
</script>
</body>
</html>
