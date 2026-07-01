<?php
require_once __DIR__ . '/common.php';
$db = db();

$mode = $_GET['mode'] ?? 'customer-login';
if (!in_array($mode, ['customer-login', 'customer-register', 'admin'], true)) {
    $mode = 'customer-login';
}
if (!empty($_SESSION['hotel_admin_id']) && $mode === 'admin') {
    header('Location: admin.php');
    exit;
}
if (!empty($_SESSION['hotel_customer_id']) && $mode !== 'admin') {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';
$postedMode = $_POST['mode'] ?? $mode;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        verifyCsrfToken();
        if ($postedMode === 'admin') {
            $mode = 'admin';
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $stmt = $db->prepare("SELECT id_admin, username, password_hash, full_name, is_active FROM admin_users WHERE username=? LIMIT 1");
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $admin = $stmt->get_result()->fetch_assoc();

            if ($admin && (int)($admin['is_active'] ?? 1) === 1 && password_verify($password, $admin['password_hash'])) {
                $_SESSION['hotel_admin_id'] = (int)$admin['id_admin'];
                $_SESSION['hotel_admin_name'] = $admin['full_name'];
                logSecurityEvent($db, 'admin_login', 'success', 'Admin dang nhap thanh cong.', ['username' => $username]);
                header('Location: admin.php');
                exit;
            }
            logSecurityEvent($db, 'admin_login', 'failed', 'Dang nhap admin that bai.', ['username' => $username]);
            throw new Exception('Sai tài khoản hoặc mật khẩu quản trị.');
        }

        if ($postedMode === 'customer-register') {
            $mode = 'customer-register';
            $fullName = trim($_POST['full_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $password = $_POST['password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if ($fullName === '' || $phone === '' || $email === '' || $password === '') {
                throw new Exception('Vui lòng nhập đầy đủ họ tên, điện thoại, email và mật khẩu.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Email không hợp lệ.');
            }
            if (strlen($password) < 6) {
                throw new Exception('Mật khẩu cần tối thiểu 6 ký tự.');
            }
            if ($password !== $confirmPassword) {
                throw new Exception('Mật khẩu xác nhận không khớp.');
            }

            $stmt = $db->prepare("SELECT id_guest, password_hash FROM guests WHERE email=? ORDER BY id_guest ASC LIMIT 1");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $guest = $stmt->get_result()->fetch_assoc();
            if ($guest && !empty($guest['password_hash'])) {
                throw new Exception('Email này đã có tài khoản. Vui lòng đăng nhập.');
            }
            if (!$guest) {
                $stmtPhone = $db->prepare("SELECT id_guest, email, password_hash FROM guests WHERE phone=? ORDER BY id_guest DESC LIMIT 1");
                $stmtPhone->bind_param('s', $phone);
                $stmtPhone->execute();
                $phoneGuest = $stmtPhone->get_result()->fetch_assoc();
                if ($phoneGuest && !empty($phoneGuest['password_hash'])) {
                    throw new Exception('Số điện thoại này đã có tài khoản. Vui lòng đăng nhập hoặc dùng số khác.');
                }
                if ($phoneGuest) {
                    $guest = $phoneGuest;
                }
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            if ($guest) {
                $guestId = (int)$guest['id_guest'];
                $stmt = $db->prepare("UPDATE guests SET full_name=?, phone=?, email=?, password_hash=?, customer_is_active=1 WHERE id_guest=?");
                $stmt->bind_param('ssssi', $fullName, $phone, $email, $hash, $guestId);
                $stmt->execute();
            } else {
                $stmt = $db->prepare("INSERT INTO guests (full_name, phone, email, password_hash, customer_is_active) VALUES (?, ?, ?, ?, 1)");
                $stmt->bind_param('ssss', $fullName, $phone, $email, $hash);
                $stmt->execute();
                $guestId = (int)$db->insert_id;
            }

            $_SESSION['hotel_customer_id'] = $guestId;
            $_SESSION['hotel_customer_name'] = $fullName;
            $_SESSION['hotel_customer_email'] = $email;
            logSecurityEvent($db, 'customer_register', 'success', 'Khach hang tao tai khoan.', ['guest_id' => $guestId]);
            header('Location: index.php');
            exit;
        }

        $mode = 'customer-login';
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $stmt = $db->prepare("SELECT id_guest, full_name, email, password_hash, customer_is_active FROM guests WHERE email=? AND password_hash IS NOT NULL ORDER BY id_guest ASC LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $guest = $stmt->get_result()->fetch_assoc();

        if ($guest && (int)($guest['customer_is_active'] ?? 1) === 1 && password_verify($password, $guest['password_hash'])) {
            $_SESSION['hotel_customer_id'] = (int)$guest['id_guest'];
            $_SESSION['hotel_customer_name'] = $guest['full_name'];
            $_SESSION['hotel_customer_email'] = $guest['email'];
            logSecurityEvent($db, 'customer_login', 'success', 'Khach hang dang nhap thanh cong.', ['guest_id' => (int)$guest['id_guest']]);
            header('Location: index.php');
            exit;
        }
        logSecurityEvent($db, 'customer_login', 'failed', 'Dang nhap khach hang that bai.', ['email' => $email]);
        throw new Exception('Sai email hoặc mật khẩu.');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$backgroundImage = serviceHeroImage();
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Đăng nhập - Spotki Hotel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --red:#dc2626; --red-dark:#991b1b; --ink:#111827; --muted:#6b7280; --line:#e5e7eb; --soft:#f8fafc; }
        * { box-sizing:border-box; }
        body {
            min-height:100vh;
            margin:0;
            color:var(--ink);
            font-family:"Inter","Segoe UI",Arial,sans-serif;
            background:
                linear-gradient(110deg,rgba(17,24,39,.90),rgba(17,24,39,.54) 46%,rgba(17,24,39,.16)),
                url('<?php echo h($backgroundImage); ?>') center/cover fixed;
            -webkit-font-smoothing:antialiased;
        }
        .login-shell { min-height:100vh; display:grid; place-items:center; padding:34px 18px; }
        .login-wrap {
            width:min(1080px,100%);
            min-height:660px;
            display:grid;
            grid-template-columns:minmax(0,1.04fr) minmax(390px,.96fr);
            overflow:hidden;
            border:1px solid rgba(255,255,255,.22);
            border-radius:8px;
            background:rgba(255,255,255,.92);
            box-shadow:0 30px 90px rgba(0,0,0,.32);
        }
        .brand-panel {
            position:relative;
            display:flex;
            flex-direction:column;
            justify-content:space-between;
            padding:34px;
            color:#fff;
            background:
                linear-gradient(180deg,rgba(17,24,39,.12),rgba(17,24,39,.88)),
                url('<?php echo h($backgroundImage); ?>') center/cover;
        }
        .brand-panel:before { content:""; position:absolute; inset:0; background:linear-gradient(90deg,rgba(17,24,39,.72),rgba(17,24,39,.12)); }
        .brand-panel > * { position:relative; z-index:1; }
        .brand-mark { display:flex; align-items:center; gap:12px; font-weight:900; font-size:20px; }
        .brand-icon { width:44px; height:44px; border-radius:8px; display:inline-flex; align-items:center; justify-content:center; background:var(--red); color:#fff; box-shadow:0 14px 28px rgba(220,38,38,.34); }
        .brand-copy { max-width:530px; }
        .brand-copy h1 { margin:0 0 14px; font-size:clamp(34px,4.8vw,58px); line-height:1; font-weight:900; letter-spacing:0; }
        .brand-copy p { margin:0; max-width:470px; color:rgba(255,255,255,.80); font-size:16px; line-height:1.7; }
        .brand-stats { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; margin-top:28px; }
        .brand-stat { border-left:1px solid rgba(255,255,255,.36); padding-left:12px; }
        .brand-stat strong { display:block; font-size:16px; line-height:1.2; }
        .brand-stat span { color:rgba(255,255,255,.74); font-size:12px; font-weight:700; }
        .login-panel { display:flex; flex-direction:column; justify-content:center; padding:42px; background:#fff; }
        .login-card { width:100%; max-width:450px; margin:0 auto; }
        .auth-tabs { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:6px; padding:5px; border:1px solid var(--line); border-radius:8px; background:var(--soft); margin-bottom:24px; }
        .auth-tabs a { min-height:38px; display:flex; align-items:center; justify-content:center; padding:8px; border-radius:6px; color:#4b5563; text-decoration:none; font-size:13px; font-weight:900; text-align:center; }
        .auth-tabs a.active { background:#fff; color:var(--red-dark); box-shadow:0 8px 18px rgba(15,23,42,.08); }
        .eyebrow { display:inline-flex; align-items:center; gap:8px; min-height:30px; padding:5px 10px; border:1px solid #fecaca; border-radius:999px; background:#fff1f2; color:var(--red-dark); font-size:12px; font-weight:900; text-transform:uppercase; letter-spacing:.08em; }
        .login-title { margin:18px 0 8px; font-size:30px; line-height:1.15; font-weight:900; letter-spacing:0; }
        .login-subtitle { margin:0 0 24px; color:var(--muted); line-height:1.65; }
        .alert { border-radius:8px; border:0; font-weight:700; }
        .form-label { margin-bottom:8px; font-size:13px; font-weight:900; color:#374151; }
        .field-wrap { position:relative; margin-bottom:16px; }
        .field-wrap .field-icon { position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#9ca3af; pointer-events:none; }
        .field-wrap .form-control { height:48px; padding-left:42px; border:1px solid var(--line); border-radius:8px; background:var(--soft); font-weight:700; }
        .field-wrap .form-control:focus { border-color:rgba(220,38,38,.55); box-shadow:0 0 0 .2rem rgba(220,38,38,.12); background:#fff; }
        .password-toggle { position:absolute; right:8px; top:50%; transform:translateY(-50%); width:36px; height:36px; border:0; border-radius:8px; background:transparent; color:#6b7280; }
        .password-toggle:hover { background:#eef2f7; color:var(--ink); }
        .btn-hotel { min-height:48px; border-radius:8px; background:var(--red); border-color:var(--red); color:#fff; font-weight:900; box-shadow:0 14px 26px rgba(220,38,38,.22); }
        .btn-hotel:hover { background:var(--red-dark); border-color:var(--red-dark); color:#fff; }
        .back-link { min-height:42px; display:inline-flex; align-items:center; justify-content:center; gap:8px; color:#374151; text-decoration:none; font-weight:800; }
        .back-link:hover { color:var(--red-dark); }
        .security-note { display:flex; gap:10px; padding:12px; border:1px solid var(--line); border-radius:8px; background:var(--soft); color:#4b5563; font-size:13px; line-height:1.5; }
        .security-note i { color:#047857; margin-top:2px; }
        @media (max-width:920px) {
            .login-wrap { grid-template-columns:1fr; min-height:0; }
            .brand-panel { min-height:330px; padding:26px; }
            .login-panel { padding:30px 22px; }
        }
        @media (max-width:560px) {
            .login-shell { padding:0; align-items:stretch; }
            .login-wrap { min-height:100vh; border-radius:0; border:0; }
            .brand-panel { min-height:250px; }
            .brand-copy h1 { font-size:32px; }
            .brand-copy p { font-size:14px; }
            .brand-stats { grid-template-columns:1fr; gap:8px; margin-top:18px; }
            .auth-tabs { grid-template-columns:1fr; }
            .login-title { font-size:26px; }
        }
    </style>
</head>
<body>
    <main class="login-shell">
        <div class="login-wrap">
            <section class="brand-panel" aria-label="Spotki Hotel">
                <div class="brand-mark">
                    <span class="brand-icon"><i class="fa fa-hotel"></i></span>
                    <span>Spotki Hotel</span>
                </div>
                <div class="brand-copy">
                    <h1><?php echo $mode === 'admin' ? 'Quản trị khách sạn' : 'Tài khoản khách hàng'; ?></h1>
                    <p><?php echo $mode === 'admin' ? 'Theo dõi đặt phòng, vận hành buồng phòng, hóa đơn và nhân sự trong một bảng điều khiển tập trung.' : 'Đăng nhập để đặt phòng nhanh hơn, lưu thông tin cá nhân và theo dõi các quyền lợi thành viên.'; ?></p>
                    <div class="brand-stats" aria-label="Tiêu chuẩn vận hành">
                        <div class="brand-stat"><strong>24/7</strong><span>Hỗ trợ lễ tân</span></div>
                        <div class="brand-stat"><strong>Member</strong><span>Tích điểm lưu trú</span></div>
                        <div class="brand-stat"><strong>Secure</strong><span>Bảo mật tài khoản</span></div>
                    </div>
                </div>
            </section>

            <section class="login-panel">
                <div class="login-card">
                    <nav class="auth-tabs" aria-label="Chọn loại đăng nhập">
                        <a class="<?php echo $mode === 'customer-login' ? 'active' : ''; ?>" href="login.php?mode=customer-login">Khách đăng nhập</a>
                        <a class="<?php echo $mode === 'customer-register' ? 'active' : ''; ?>" href="login.php?mode=customer-register">Đăng ký</a>
                        <a class="<?php echo $mode === 'admin' ? 'active' : ''; ?>" href="login.php?mode=admin">Quản trị</a>
                    </nav>

                    <span class="eyebrow"><i class="fa fa-shield-halved"></i> <?php echo $mode === 'admin' ? 'Admin access' : 'Customer access'; ?></span>
                    <h2 class="login-title"><?php echo $mode === 'customer-register' ? 'Tạo tài khoản khách hàng' : ($mode === 'admin' ? 'Đăng nhập quản trị' : 'Đăng nhập khách hàng'); ?></h2>
                    <p class="login-subtitle">
                        <?php echo $mode === 'customer-register' ? 'Tạo tài khoản để sử dụng thông tin đặt phòng nhanh hơn trong các lần sau.' : ($mode === 'admin' ? 'Sử dụng tài khoản được cấp để truy cập hệ thống quản lý Spotki Hotel.' : 'Sử dụng email và mật khẩu đã đăng ký để tiếp tục đặt phòng.'); ?>
                    </p>

                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger d-flex align-items-center gap-2">
                            <i class="fa fa-circle-exclamation"></i>
                            <span><?php echo h($error); ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="post">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="mode" value="<?php echo h($mode); ?>">

                        <?php if ($mode === 'admin'): ?>
                            <label class="form-label" for="username">Tài khoản</label>
                            <div class="field-wrap">
                                <i class="fa fa-user field-icon"></i>
                                <input class="form-control" id="username" name="username" autocomplete="username" placeholder="Nhập tài khoản quản trị" required autofocus>
                            </div>
                        <?php else: ?>
                            <?php if ($mode === 'customer-register'): ?>
                                <label class="form-label" for="full_name">Họ tên</label>
                                <div class="field-wrap">
                                    <i class="fa fa-id-card field-icon"></i>
                                    <input class="form-control" id="full_name" name="full_name" autocomplete="name" placeholder="Nhập họ tên" required autofocus>
                                </div>
                                <label class="form-label" for="phone">Điện thoại</label>
                                <div class="field-wrap">
                                    <i class="fa fa-phone field-icon"></i>
                                    <input class="form-control" id="phone" name="phone" autocomplete="tel" placeholder="Nhập số điện thoại" required>
                                </div>
                            <?php endif; ?>

                            <label class="form-label" for="email">Email</label>
                            <div class="field-wrap">
                                <i class="fa fa-envelope field-icon"></i>
                                <input class="form-control" id="email" type="email" name="email" autocomplete="email" placeholder="Nhập email" required <?php echo $mode === 'customer-login' ? 'autofocus' : ''; ?>>
                            </div>
                        <?php endif; ?>

                        <label class="form-label" for="password">Mật khẩu</label>
                        <div class="field-wrap">
                            <i class="fa fa-lock field-icon"></i>
                            <input class="form-control" id="password" type="password" name="password" autocomplete="<?php echo $mode === 'customer-register' ? 'new-password' : 'current-password'; ?>" placeholder="Nhập mật khẩu" required>
                            <button class="password-toggle" type="button" data-password-toggle="password" aria-label="Hiện mật khẩu"><i class="fa fa-eye"></i></button>
                        </div>

                        <?php if ($mode === 'customer-register'): ?>
                            <label class="form-label" for="confirm_password">Xác nhận mật khẩu</label>
                            <div class="field-wrap">
                                <i class="fa fa-lock field-icon"></i>
                                <input class="form-control" id="confirm_password" type="password" name="confirm_password" autocomplete="new-password" placeholder="Nhập lại mật khẩu" required>
                                <button class="password-toggle" type="button" data-password-toggle="confirm_password" aria-label="Hiện mật khẩu"><i class="fa fa-eye"></i></button>
                            </div>
                        <?php endif; ?>

                        <div class="security-note mb-3">
                            <i class="fa fa-circle-check"></i>
                            <span><?php echo $mode === 'admin' ? 'Phiên đăng nhập chỉ dành cho nhân sự được phân quyền. Vui lòng đăng xuất sau khi sử dụng trên thiết bị chung.' : 'Thông tin tài khoản được dùng để hỗ trợ đặt phòng và chăm sóc khách hàng tại Spotki Hotel.'; ?></span>
                        </div>

                        <button class="btn btn-hotel w-100" type="submit"><?php echo $mode === 'customer-register' ? 'Tạo tài khoản' : 'Đăng nhập'; ?></button>
                    </form>

                    <div class="text-center mt-3">
                        <a class="back-link" href="index.php"><i class="fa fa-arrow-left"></i> Về website</a>
                    </div>
                </div>
            </section>
        </div>
    </main>
    <script>
        document.querySelectorAll('[data-password-toggle]').forEach((button) => {
            const input = document.getElementById(button.dataset.passwordToggle);
            if (!input) return;
            button.addEventListener('click', () => {
                const isHidden = input.type === 'password';
                input.type = isHidden ? 'text' : 'password';
                button.setAttribute('aria-label', isHidden ? 'Ẩn mật khẩu' : 'Hiện mật khẩu');
                button.innerHTML = isHidden ? '<i class="fa fa-eye-slash"></i>' : '<i class="fa fa-eye"></i>';
            });
        });
    </script>
</body>
</html>
