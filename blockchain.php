<?php
require_once __DIR__ . '/common.php';
requireAdmin();

$db = db();
$message = '';
$isError = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        verifyCsrfToken();
        $action = $_POST['action'] ?? '';
        if ($action === 'create_demo_block') {
            $block = hotelBlockchainAppend($db, 'demo_block_created', 'teacher_demo', null, [
                'purpose' => 'show_block_hash',
                'note' => 'Demo block for blockchain ledger presentation.',
                'nonce' => bin2hex(random_bytes(12)),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            if (!$block) {
                throw new Exception('Không thể tạo block demo.');
            }
            $message = 'Đã tạo block demo: ' . $block['block_hash'];
        } elseif ($action === 'tamper_demo_block') {
            $block = hotelBlockchainTamperLatestDemoBlock($db);
            $message = 'Đã phá block demo #' . $block['id_block'] . '. Trạng thái chuỗi sẽ chuyển sang không hợp lệ.';
        } elseif ($action === 'restore_demo_block') {
            $block = hotelBlockchainRestoreTamperedDemoBlock($db);
            $message = 'Đã sửa lại block demo #' . $block['id_block'] . '. Chuỗi đã được khôi phục về trạng thái hợp lệ.';
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $isError = true;
    }
}

$verification = hotelBlockchainVerifyChain($db);
$blocks = $db->query("SELECT id_block, chain_name, event_type, entity_type, entity_id, source_table, source_id, payload_hash, previous_hash, block_hash, tx_hash, actor_type, actor_id, created_at
    FROM blockchain_ledger
    ORDER BY id_block DESC
    LIMIT 80")->fetch_all(MYSQLI_ASSOC);
$eventStats = $db->query("SELECT event_type, COUNT(*) AS total
    FROM blockchain_ledger
    GROUP BY event_type
    ORDER BY total DESC, event_type ASC
    LIMIT 8")->fetch_all(MYSQLI_ASSOC);
$latest = $blocks[0] ?? null;
$isChainValid = (bool)($verification['valid'] ?? false);
$brokenBlockId = (int)($verification['broken_block_id'] ?? 0);
$verifyCode = trim((string)($_GET['verify_code'] ?? ''));
$verifyResult = null;

function shortHash(?string $hash): string
{
    $hash = (string)$hash;
    if (strlen($hash) <= 18) {
        return $hash;
    }
    return substr($hash, 0, 10) . '...' . substr($hash, -8);
}

function eventLabel(string $eventType): string
{
    return [
        'demo_block_created' => 'Demo block',
        'public_booking_created' => 'Đặt phòng',
        'booking_deposit_recorded' => 'Cọc phòng',
        'payment_added_by_admin' => 'Thanh toán',
        'booking_payment_added' => 'Cập nhật thanh toán',
        'booking_payment_completed' => 'Hoàn tất thanh toán',
        'review_created' => 'Đánh giá',
        'security_log_recorded' => 'Log bảo mật',
        'booking_updated_by_customer' => 'Khách sửa booking',
        'booking_cancelled_by_customer' => 'Khách hủy booking',
        'booking_created_by_admin' => 'Admin tạo booking',
        'booking_updated_by_admin' => 'Admin sửa booking',
        'booking_status_updated_by_admin' => 'Đổi trạng thái',
        'booking_cancelled_by_admin' => 'Admin hủy booking',
    ][$eventType] ?? $eventType;
}

function verificationStatusLabel(?array $result, bool $isChainValid): string
{
    if (!$result) {
        return 'Chưa xác minh';
    }
    if (empty($result['booking'])) {
        return 'Không tìm thấy';
    }
    if (!$isChainValid) {
        return 'Chuỗi đang lỗi';
    }
    if (empty($result['blocks'])) {
        return 'Chưa có proof';
    }
    return 'Đã xác minh';
}

function findBookingForBlockchainVerification(mysqli $db, string $code): ?array
{
    $code = trim($code);
    if ($code === '') {
        return null;
    }

    $normalized = strtoupper(preg_replace('/\s+/', '', $code));
    if (preg_match('/^HD0*(\d+)$/', $normalized, $match)) {
        return fetchBooking($db, (int)$match[1]);
    }

    $stmt = $db->prepare("SELECT id_booking FROM bookings WHERE UPPER(REPLACE(booking_code, ' ', ''))=? LIMIT 1");
    $stmt->bind_param('s', $normalized);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return null;
    }

    return fetchBooking($db, (int)$row['id_booking']);
}

function blockchainVerificationResult(mysqli $db, string $code): ?array
{
    $code = trim($code);
    if ($code === '') {
        return null;
    }

    $booking = findBookingForBlockchainVerification($db, $code);
    if (!$booking) {
        return [
            'code' => $code,
            'mode' => preg_match('/^HD/i', trim($code)) ? 'invoice' : 'booking',
            'booking' => null,
            'blocks' => [],
            'payments' => [],
            'totals' => null,
        ];
    }

    $bookingId = (int)$booking['id_booking'];
    $stmtBlocks = $db->prepare("SELECT DISTINCT l.id_block, l.chain_name, l.event_type, l.entity_type, l.entity_id, l.source_table, l.source_id,
            l.payload_hash, l.previous_hash, l.block_hash, l.tx_hash, l.actor_type, l.actor_id, l.created_at
        FROM blockchain_ledger l
        LEFT JOIN payments p ON l.source_table='payments' AND l.source_id=p.id_payment
        LEFT JOIN reviews rv ON l.source_table='reviews' AND l.source_id=rv.id_review
        WHERE (l.entity_type='booking' AND l.entity_id=?)
           OR (p.id_booking=?)
           OR (rv.id_booking=?)
        ORDER BY l.id_block DESC");
    $stmtBlocks->bind_param('iii', $bookingId, $bookingId, $bookingId);
    $stmtBlocks->execute();
    $relatedBlocks = $stmtBlocks->get_result()->fetch_all(MYSQLI_ASSOC);

    $payments = bookingPayments($db, $bookingId);
    $totals = bookingTotals($db, $booking);

    return [
        'code' => $code,
        'mode' => preg_match('/^HD/i', trim($code)) ? 'invoice' : 'booking',
        'booking' => $booking,
        'blocks' => $relatedBlocks,
        'payments' => $payments,
        'totals' => $totals,
        'invoice_code' => invoiceCode($booking),
    ];
}

if ($verifyCode !== '') {
    $verifyResult = blockchainVerificationResult($db, $verifyCode);
}
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php $pageTitle = 'Blockchain Ledger'; include __DIR__ . '/includes/layout/assets.php'; ?>
    <style>
        :root {
            --ink:#172033;
            --muted:#667085;
            --line:#dfe5ef;
            --surface:#ffffff;
            --page:#f4f6f9;
            --soft:#f8fafc;
            --red:#dc2626;
            --red-dark:#991b1b;
            --green:#047857;
            --amber:#b45309;
            --blue:#2563eb;
        }
        body {
            margin:0;
            background:var(--page);
            color:var(--ink);
            font-family:"Inter","Segoe UI",Arial,sans-serif;
            font-size:15px;
            line-height:1.55;
            -webkit-font-smoothing:antialiased;
        }
        .topbar {
            background:#111827;
            color:#fff;
            border-bottom:1px solid rgba(255,255,255,.08);
        }
        .topbar .container {
            min-height:66px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:16px;
        }
        .brand {
            display:flex;
            align-items:center;
            gap:10px;
            color:#fff;
            text-decoration:none;
            font-weight:900;
        }
        .brand i {
            width:34px;
            height:34px;
            border-radius:8px;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            background:var(--red);
        }
        .top-actions {
            display:flex;
            align-items:center;
            justify-content:flex-end;
            gap:8px;
            flex-wrap:wrap;
        }
        .page {
            padding:24px 0 44px;
        }
        .masthead {
            display:grid;
            grid-template-columns:minmax(0,1fr) minmax(320px,420px);
            gap:16px;
            align-items:stretch;
            margin-bottom:16px;
        }
        .intro,
        .status-panel,
        .tool-panel,
        .hash-panel,
        .events-panel,
        .table-panel,
        .metric-card {
            background:var(--surface);
            border:1px solid var(--line);
            border-radius:8px;
            box-shadow:0 12px 26px rgba(15,23,42,.07);
        }
        .intro {
            padding:22px;
            min-height:190px;
            display:flex;
            flex-direction:column;
            justify-content:space-between;
        }
        .kicker {
            color:var(--red);
            text-transform:uppercase;
            letter-spacing:.08em;
            font-size:12px;
            font-weight:900;
        }
        h1 {
            font-size:32px;
            line-height:1.15;
            margin:8px 0 10px;
            font-weight:900;
            letter-spacing:0;
        }
        .lead-text {
            max-width:760px;
            color:#4b5563;
            margin:0;
        }
        .status-panel {
            padding:18px;
            display:grid;
            gap:14px;
            border-left:5px solid var(--green);
        }
        .status-panel.is-broken {
            border-left-color:var(--red);
        }
        .status-title {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
        }
        .status-mark {
            display:inline-flex;
            align-items:center;
            gap:8px;
            font-size:18px;
            font-weight:900;
            color:var(--green);
        }
        .status-panel.is-broken .status-mark {
            color:var(--red);
        }
        .status-mark i {
            width:38px;
            height:38px;
            border-radius:8px;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            background:#ecfdf5;
        }
        .status-panel.is-broken .status-mark i {
            background:#fef2f2;
        }
        .status-detail {
            color:var(--muted);
            margin:0;
        }
        .latest-mini {
            display:grid;
            gap:8px;
            padding-top:10px;
            border-top:1px solid var(--line);
        }
        .mini-row {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
            color:#475467;
            font-size:13px;
        }
        .mini-row strong {
            color:var(--ink);
            font-size:14px;
        }
        .action-bar {
            display:grid;
            grid-template-columns:repeat(3,minmax(0,1fr));
            gap:10px;
            margin-bottom:16px;
        }
        .action-form,
        .action-form button {
            width:100%;
        }
        .action-form button {
            min-height:48px;
            border-radius:8px;
            font-weight:900;
            display:flex;
            align-items:center;
            justify-content:center;
            gap:8px;
        }
        .metric-grid {
            display:grid;
            grid-template-columns:repeat(4,minmax(0,1fr));
            gap:12px;
            margin-bottom:16px;
        }
        .verify-panel {
            background:var(--surface);
            border:1px solid var(--line);
            border-radius:8px;
            box-shadow:0 12px 26px rgba(15,23,42,.07);
            margin-bottom:16px;
            overflow:hidden;
        }
        .verify-form {
            display:grid;
            grid-template-columns:minmax(220px,1fr) auto;
            gap:10px;
            align-items:end;
        }
        .verify-form .form-control {
            min-height:46px;
            border-radius:8px;
        }
        .verify-form .btn {
            min-height:46px;
            border-radius:8px;
            font-weight:900;
            display:inline-flex;
            align-items:center;
            gap:8px;
        }
        .verify-result {
            margin-top:14px;
            padding-top:14px;
            border-top:1px solid var(--line);
        }
        .verify-summary {
            display:grid;
            grid-template-columns:minmax(0,1fr) auto;
            gap:12px;
            align-items:start;
            margin-bottom:12px;
        }
        .verify-badge {
            display:inline-flex;
            align-items:center;
            gap:7px;
            min-height:34px;
            padding:6px 10px;
            border-radius:999px;
            font-size:13px;
            font-weight:900;
            background:#eff6ff;
            color:#1d4ed8;
            white-space:nowrap;
        }
        .verify-badge.ok {
            background:#ecfdf5;
            color:var(--green);
        }
        .verify-badge.warn {
            background:#fffbeb;
            color:var(--amber);
        }
        .verify-badge.bad {
            background:#fef2f2;
            color:var(--red);
        }
        .proof-grid {
            display:grid;
            grid-template-columns:repeat(4,minmax(0,1fr));
            gap:10px;
            margin-bottom:12px;
        }
        .proof-card {
            min-height:84px;
            padding:12px;
            border:1px solid var(--line);
            border-radius:8px;
            background:var(--soft);
        }
        .proof-card span {
            display:block;
            color:var(--muted);
            font-size:12px;
            font-weight:900;
            text-transform:uppercase;
        }
        .proof-card strong {
            display:block;
            margin-top:6px;
            color:var(--ink);
            font-size:17px;
            font-weight:900;
            overflow-wrap:anywhere;
        }
        .verify-block-list {
            display:grid;
            gap:8px;
        }
        .verify-block {
            display:grid;
            grid-template-columns:110px minmax(0,1fr) 116px;
            gap:10px;
            align-items:center;
            min-height:48px;
            padding:9px 10px;
            border:1px solid var(--line);
            border-radius:8px;
            background:#fff;
        }
        .verify-block.is-broken {
            background:#fff7ed;
            border-color:#fed7aa;
        }
        .verify-block code {
            color:#101828;
            font-size:12px;
            overflow-wrap:anywhere;
        }
        .metric-card {
            min-height:116px;
            padding:16px;
            display:flex;
            flex-direction:column;
            justify-content:space-between;
        }
        .metric-card i {
            color:var(--red);
            font-size:20px;
        }
        .metric-card strong {
            display:block;
            font-size:23px;
            line-height:1.1;
            font-weight:900;
        }
        .metric-card span {
            color:var(--muted);
            font-size:13px;
            font-weight:800;
        }
        .status-ok {
            color:var(--green);
            font-weight:900;
        }
        .status-bad {
            color:var(--red);
            font-weight:900;
        }
        .main-grid {
            display:grid;
            grid-template-columns:minmax(0,1.4fr) minmax(300px,.6fr);
            gap:16px;
            align-items:start;
            margin-bottom:16px;
        }
        .section-head {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            padding:18px 18px 0;
        }
        .section-title {
            margin:3px 0 0;
            font-size:19px;
            font-weight:900;
        }
        .panel-body {
            padding:18px;
        }
        .hash-list {
            display:grid;
            gap:10px;
        }
        .hash-line {
            display:grid;
            grid-template-columns:138px minmax(0,1fr) 38px;
            gap:12px;
            align-items:center;
            min-height:58px;
            padding:10px 12px;
            background:var(--soft);
            border:1px solid var(--line);
            border-radius:8px;
        }
        .hash-label {
            color:#475467;
            font-size:12px;
            font-weight:900;
            text-transform:uppercase;
        }
        code.hash {
            color:#101828;
            white-space:normal;
            overflow-wrap:anywhere;
            word-break:break-word;
            font-size:12px;
        }
        .copy-btn {
            width:38px;
            height:38px;
            padding:0;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            border-radius:8px;
        }
        .event-list {
            display:grid;
            gap:8px;
        }
        .event-item {
            display:flex;
            justify-content:space-between;
            gap:12px;
            align-items:center;
            min-height:44px;
            padding:9px 10px;
            border:1px solid var(--line);
            border-radius:8px;
            background:var(--soft);
        }
        .event-name {
            color:var(--ink);
            font-weight:800;
            min-width:0;
            overflow:hidden;
            text-overflow:ellipsis;
            white-space:nowrap;
        }
        .event-count {
            min-width:34px;
            height:28px;
            padding:4px 8px;
            border-radius:999px;
            background:#eff6ff;
            color:#1d4ed8;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            font-weight:900;
            font-size:13px;
        }
        .event-pill {
            display:inline-flex;
            min-height:26px;
            align-items:center;
            padding:4px 8px;
            border-radius:999px;
            background:#fef2f2;
            color:#991b1b;
            font-weight:800;
            font-size:12px;
            white-space:nowrap;
        }
        .table-panel {
            overflow:hidden;
        }
        .table-wrap {
            overflow:auto;
            border-top:1px solid var(--line);
        }
        table {
            min-width:1120px;
            margin:0;
        }
        th {
            color:#475467;
            font-size:12px;
            text-transform:uppercase;
            white-space:nowrap;
            background:#f8fafc;
        }
        td {
            vertical-align:middle;
        }
        tr.is-broken-row td {
            background:#fff7ed;
        }
        .empty {
            text-align:center;
            padding:42px 18px;
            color:var(--muted);
        }
        .alert {
            border-radius:8px;
            border-width:1px;
            box-shadow:0 10px 22px rgba(15,23,42,.06);
        }
        @media (max-width:1080px) {
            .masthead,
            .main-grid {
                grid-template-columns:1fr;
            }
            .metric-grid {
                grid-template-columns:repeat(2,minmax(0,1fr));
            }
        }
        @media (max-width:720px) {
            .topbar .container {
                display:grid;
                justify-content:stretch;
            }
            .top-actions {
                justify-content:flex-start;
            }
            h1 {
                font-size:26px;
            }
            .action-bar,
            .metric-grid,
            .proof-grid {
                grid-template-columns:1fr;
            }
            .verify-form,
            .verify-summary,
            .verify-block {
                grid-template-columns:1fr;
            }
            .hash-line {
                grid-template-columns:1fr 38px;
            }
            .hash-label {
                grid-column:1 / -1;
            }
        }
    </style>
</head>
<body>
<header class="topbar">
    <div class="container">
        <a class="brand" href="admin.php"><i class="fa fa-link"></i> Blockchain Ledger</a>
        <div class="top-actions">
            <a class="btn btn-sm btn-light" href="admin.php"><i class="fa fa-arrow-left"></i> Admin</a>
            <a class="btn btn-sm btn-outline-light" href="logout.php"><i class="fa fa-right-from-bracket"></i> Đăng xuất</a>
        </div>
    </div>
</header>

<main class="page">
    <div class="container">
        <section class="masthead">
            <div class="intro">
                <div>
                    <div class="kicker">Proof of integrity</div>
                    <h1>Blockchain ledger</h1>
                    <p class="lead-text">Theo dõi block hash, previous hash, tx hash và xác minh mã booking/hóa đơn bằng các block proof liên quan.</p>
                </div>
                <div class="text-muted small mt-3">Chuỗi: hotel_audit</div>
            </div>
            <div class="status-panel <?php echo $isChainValid ? '' : 'is-broken'; ?>">
                <div class="status-title">
                    <div class="status-mark">
                        <i class="fa <?php echo $isChainValid ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
                        <?php echo $isChainValid ? 'Chuỗi hợp lệ' : 'Chuỗi bị lỗi'; ?>
                    </div>
                    <span class="event-pill"><?php echo (int)($verification['total_blocks'] ?? 0); ?> blocks</span>
                </div>
                <p class="status-detail">
                    <?php echo $isChainValid ? 'Tất cả payload hash, previous hash và block hash đang khớp.' : 'Phát hiện sai lệch tại block #' . $brokenBlockId . '.'; ?>
                </p>
                <div class="latest-mini">
                    <div class="mini-row"><span>Block mới nhất</span><strong><?php echo $latest ? '#' . (int)$latest['id_block'] : '-'; ?></strong></div>
                    <div class="mini-row"><span>Thời điểm</span><strong><?php echo $latest ? h(date('d/m/Y H:i:s', strtotime($latest['created_at']))) : '-'; ?></strong></div>
                    <div class="mini-row"><span>Hash</span><strong><?php echo $latest ? h(shortHash($latest['block_hash'])) : '-'; ?></strong></div>
                </div>
            </div>
        </section>

        <section class="action-bar">
            <form class="action-form" method="post">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="create_demo_block">
                <button class="btn btn-danger" type="submit"><i class="fa fa-plus"></i> Tạo block demo</button>
            </form>
            <form class="action-form" method="post">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="tamper_demo_block">
                <button class="btn btn-warning" type="submit"><i class="fa fa-triangle-exclamation"></i> Phá block demo</button>
            </form>
            <form class="action-form" method="post">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="restore_demo_block">
                <button class="btn btn-success" type="submit"><i class="fa fa-screwdriver-wrench"></i> Sửa lại hợp lệ</button>
            </form>
        </section>

        <?php if ($message !== ''): ?>
            <div class="alert <?php echo $isError ? 'alert-danger' : 'alert-success'; ?>"><?php echo h($message); ?></div>
        <?php endif; ?>

        <section class="verify-panel">
            <div class="section-head">
                <div>
                    <div class="kicker">Booking / Invoice verification</div>
                    <h2 class="section-title">Xác minh booking hoặc hóa đơn</h2>
                </div>
            </div>
            <div class="panel-body">
                <form class="verify-form" method="get">
                    <div>
                        <label class="form-label fw-bold" for="verifyCode">Mã booking hoặc mã hóa đơn</label>
                        <input class="form-control" id="verifyCode" name="verify_code" value="<?php echo h($verifyCode); ?>" placeholder="Ví dụ: BABC12 hoặc HD00038">
                    </div>
                    <button class="btn btn-dark" type="submit"><i class="fa fa-magnifying-glass"></i> Xác minh</button>
                </form>

                <?php if ($verifyResult): ?>
                    <?php
                    $verifiedBooking = $verifyResult['booking'];
                    $relatedBlocks = $verifyResult['blocks'] ?? [];
                    $verifyStatus = verificationStatusLabel($verifyResult, $isChainValid);
                    $verifyBadgeClass = 'warn';
                    if (!$verifiedBooking || !$isChainValid) {
                        $verifyBadgeClass = 'bad';
                    } elseif ($relatedBlocks) {
                        $verifyBadgeClass = 'ok';
                    }
                    $bookingProofs = array_values(array_filter($relatedBlocks, static fn(array $block): bool => (string)$block['entity_type'] === 'booking'));
                    $paymentProofs = array_values(array_filter($relatedBlocks, static fn(array $block): bool => (string)$block['source_table'] === 'payments' || (string)$block['entity_type'] === 'payment'));
                    $reviewProofs = array_values(array_filter($relatedBlocks, static fn(array $block): bool => (string)$block['source_table'] === 'reviews' || (string)$block['entity_type'] === 'review'));
                    ?>
                    <div class="verify-result">
                        <div class="verify-summary">
                            <div>
                                <div class="kicker mb-1">Verification result</div>
                                <h3 class="h5 fw-bold mb-1"><?php echo $verifiedBooking ? 'Kết quả cho ' . h($verifyCode) : 'Không tìm thấy mã'; ?></h3>
                                <p class="text-muted mb-0">
                                    <?php if ($verifiedBooking): ?>
                                        Booking <?php echo h($verifiedBooking['booking_code']); ?> · Hóa đơn <?php echo h($verifyResult['invoice_code']); ?> · <?php echo h($verifiedBooking['hotel_name'] ?? 'Khách sạn'); ?>
                                    <?php else: ?>
                                        Không có booking hoặc hóa đơn nào khớp với mã này.
                                    <?php endif; ?>
                                </p>
                            </div>
                            <span class="verify-badge <?php echo h($verifyBadgeClass); ?>">
                                <i class="fa <?php echo $verifyBadgeClass === 'ok' ? 'fa-circle-check' : ($verifyBadgeClass === 'bad' ? 'fa-circle-xmark' : 'fa-circle-info'); ?>"></i>
                                <?php echo h($verifyStatus); ?>
                            </span>
                        </div>

                        <?php if ($verifiedBooking): ?>
                            <div class="proof-grid">
                                <div class="proof-card"><span>Mã booking</span><strong><?php echo h($verifiedBooking['booking_code']); ?></strong></div>
                                <div class="proof-card"><span>Mã hóa đơn</span><strong><?php echo h($verifyResult['invoice_code']); ?></strong></div>
                                <div class="proof-card"><span>Tổng hóa đơn</span><strong><?php echo h(money($verifyResult['totals']['grand'] ?? 0)); ?></strong></div>
                                <div class="proof-card"><span>Block liên quan</span><strong><?php echo count($relatedBlocks); ?></strong></div>
                                <div class="proof-card"><span>Proof booking</span><strong><?php echo count($bookingProofs); ?></strong></div>
                                <div class="proof-card"><span>Proof thanh toán</span><strong><?php echo count($paymentProofs); ?></strong></div>
                                <div class="proof-card"><span>Proof đánh giá</span><strong><?php echo count($reviewProofs); ?></strong></div>
                                <div class="proof-card"><span>Trạng thái</span><strong><?php echo h($verifiedBooking['status']); ?></strong></div>
                            </div>

                            <?php if ($relatedBlocks): ?>
                                <div class="verify-block-list">
                                    <?php foreach (array_slice($relatedBlocks, 0, 10) as $block): ?>
                                        <?php $isRelatedBroken = !$isChainValid && (int)$block['id_block'] === $brokenBlockId; ?>
                                        <div class="verify-block <?php echo $isRelatedBroken ? 'is-broken' : ''; ?>">
                                            <strong>#<?php echo (int)$block['id_block']; ?></strong>
                                            <div>
                                                <span class="event-pill"><?php echo h(eventLabel((string)$block['event_type'])); ?></span>
                                                <div class="mt-1"><code><?php echo h(shortHash($block['block_hash'])); ?></code></div>
                                            </div>
                                            <span class="text-muted small"><?php echo h(date('d/m/Y H:i', strtotime($block['created_at']))); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning mb-0">Booking này tồn tại nhưng chưa có block proof trong blockchain ledger. Thường là dữ liệu được tạo trước khi thêm chức năng blockchain.</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="metric-grid">
            <div class="metric-card"><i class="fa fa-cubes"></i><strong><?php echo (int)($verification['total_blocks'] ?? 0); ?></strong><span>Tổng số block</span></div>
            <div class="metric-card"><i class="fa fa-shield-halved"></i><strong class="<?php echo $isChainValid ? 'status-ok' : 'status-bad'; ?>"><?php echo $isChainValid ? 'Hợp lệ' : 'Bị lỗi'; ?></strong><span><?php echo $isChainValid ? 'Kiểm tra toàn chuỗi' : 'Lỗi tại block #' . $brokenBlockId; ?></span></div>
            <div class="metric-card"><i class="fa fa-hashtag"></i><strong><?php echo $latest ? '#' . (int)$latest['id_block'] : '-'; ?></strong><span>Block mới nhất</span></div>
            <div class="metric-card"><i class="fa fa-user-shield"></i><strong><?php echo $latest ? h($latest['actor_type']) : '-'; ?></strong><span>Actor gần nhất</span></div>
        </section>

        <section class="main-grid">
            <div class="hash-panel">
                <div class="section-head">
                    <div>
                        <div class="kicker">Latest block</div>
                        <h2 class="section-title">Hash mới nhất</h2>
                    </div>
                    <span class="<?php echo $isChainValid ? 'status-ok' : 'status-bad'; ?>"><?php echo h((string)($verification['message'] ?? '')); ?></span>
                </div>
                <div class="panel-body">
                    <?php if ($latest): ?>
                        <div class="hash-list">
                            <div class="hash-line"><span class="hash-label">Block hash</span><code class="hash"><?php echo h($latest['block_hash']); ?></code><button class="btn btn-outline-dark copy-btn" type="button" data-copy="<?php echo h($latest['block_hash']); ?>" title="Copy block hash"><i class="fa fa-copy"></i></button></div>
                            <div class="hash-line"><span class="hash-label">Previous hash</span><code class="hash"><?php echo h($latest['previous_hash']); ?></code><button class="btn btn-outline-dark copy-btn" type="button" data-copy="<?php echo h($latest['previous_hash']); ?>" title="Copy previous hash"><i class="fa fa-copy"></i></button></div>
                            <div class="hash-line"><span class="hash-label">Payload hash</span><code class="hash"><?php echo h($latest['payload_hash']); ?></code><button class="btn btn-outline-dark copy-btn" type="button" data-copy="<?php echo h($latest['payload_hash']); ?>" title="Copy payload hash"><i class="fa fa-copy"></i></button></div>
                            <div class="hash-line"><span class="hash-label">Tx hash</span><code class="hash"><?php echo h($latest['tx_hash']); ?></code><button class="btn btn-outline-dark copy-btn" type="button" data-copy="<?php echo h($latest['tx_hash']); ?>" title="Copy tx hash"><i class="fa fa-copy"></i></button></div>
                        </div>
                    <?php else: ?>
                        <div class="empty">Chưa có block nào.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="events-panel">
                <div class="section-head">
                    <div>
                        <div class="kicker">Event types</div>
                        <h2 class="section-title">Phân loại</h2>
                    </div>
                </div>
                <div class="panel-body">
                    <?php if ($eventStats): ?>
                        <div class="event-list">
                            <?php foreach ($eventStats as $event): ?>
                                <div class="event-item">
                                    <span class="event-name" title="<?php echo h($event['event_type']); ?>"><?php echo h(eventLabel((string)$event['event_type'])); ?></span>
                                    <span class="event-count"><?php echo (int)$event['total']; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty">Chưa có sự kiện.</div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="table-panel">
            <div class="section-head">
                <div>
                    <div class="kicker">Ledger blocks</div>
                    <h2 class="section-title">Danh sách block gần nhất</h2>
                </div>
                <span class="text-muted small">Tối đa 80 block</span>
            </div>
            <?php if ($blocks): ?>
                <div class="table-wrap">
                    <table class="table table-hover align-middle">
                        <thead>
                        <tr>
                            <th>Block</th>
                            <th>Sự kiện</th>
                            <th>Entity</th>
                            <th>Block hash</th>
                            <th>Previous hash</th>
                            <th>Tx hash</th>
                            <th>Actor</th>
                            <th>Thời gian</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($blocks as $block): ?>
                            <?php $isBrokenRow = !$isChainValid && (int)$block['id_block'] === $brokenBlockId; ?>
                            <tr class="<?php echo $isBrokenRow ? 'is-broken-row' : ''; ?>">
                                <td class="fw-bold">#<?php echo (int)$block['id_block']; ?></td>
                                <td><span class="event-pill"><?php echo h(eventLabel((string)$block['event_type'])); ?></span></td>
                                <td><?php echo h($block['entity_type']); ?><?php echo $block['entity_id'] !== null ? ' #' . (int)$block['entity_id'] : ''; ?></td>
                                <td><code class="hash"><?php echo h(shortHash($block['block_hash'])); ?></code></td>
                                <td><code class="hash"><?php echo h(shortHash($block['previous_hash'])); ?></code></td>
                                <td><code class="hash"><?php echo h(shortHash($block['tx_hash'])); ?></code></td>
                                <td><?php echo h($block['actor_type']); ?><?php echo $block['actor_id'] !== null ? ' #' . (int)$block['actor_id'] : ''; ?></td>
                                <td><?php echo h(date('d/m/Y H:i:s', strtotime($block['created_at']))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty">Chưa có dữ liệu blockchain ledger.</div>
            <?php endif; ?>
        </section>
    </div>
</main>

<script>
document.querySelectorAll('[data-copy]').forEach(function (button) {
    button.addEventListener('click', function () {
        const value = button.getAttribute('data-copy') || '';
        if (!value) return;

        const markCopied = function () {
            button.classList.remove('btn-outline-dark');
            button.classList.add('btn-success');
            setTimeout(function () {
                button.classList.add('btn-outline-dark');
                button.classList.remove('btn-success');
            }, 900);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(markCopied);
            return;
        }

        const input = document.createElement('textarea');
        input.value = value;
        input.setAttribute('readonly', 'readonly');
        input.style.position = 'fixed';
        input.style.left = '-9999px';
        document.body.appendChild(input);
        input.select();
        try {
            document.execCommand('copy');
            markCopied();
        } finally {
            document.body.removeChild(input);
        }
    });
});
</script>
</body>
</html>
