<?php
require_once __DIR__ . '/common.php';
try {
    logSecurityEvent(db(), 'logout', 'success', 'Người dùng đăng xuất.');
} catch (Throwable $ignored) {
}
unset(
    $_SESSION['hotel_admin_id'],
    $_SESSION['hotel_admin_name'],
    $_SESSION['hotel_customer_id'],
    $_SESSION['hotel_customer_name'],
    $_SESSION['hotel_customer_email']
);
header('Location: login.php');
exit;
