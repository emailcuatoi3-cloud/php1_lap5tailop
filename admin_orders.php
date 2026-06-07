<?php
session_start();
require "./db_utils.php";
$db_untils = new DB_UTILS();

// Chặn truy cập nếu không có quyền admin
if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] !== 'admin' && $_SESSION['user']['username'] !== 'admin')) {
    die("<h2 style='color:red; text-align:center; margin-top:50px;'>Bạn không có quyền truy cập trang quản lý đơn hàng!</h2>");
}

// Thực hiện thay đổi trạng thái tiến độ đơn hàng
if (isset($_GET['update_status']) && isset($_GET['order_id'])) {
    $order_id = (int)$_GET['order_id'];
    $new_status = $_GET['update_status'];
    if (in_array($new_status, ['Chờ xác nhận', 'Đang giao', 'Đã nhận', 'Đã hủy'])) {
        $db_untils->execute("UPDATE orders SET status = ? WHERE id = ?", [$new_status, $order_id]);
    }
    header("Location: admin_orders.php"); exit();
}

$orders = $db_untils->getAll("SELECT * FROM orders ORDER BY id DESC");
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Hệ thống Đơn hàng - Admin Store</title>
    <link rel="stylesheet" href="./style.css?v=<?= time() ?>">
    <style>
    .admin-box {
        max-width: 1400px;
        margin: 20px auto;
        background: white;
        padding: 30px;
        border-radius: 12px;
        box-shadow: 0 0 15px rgba(0, 0, 0, 0.05);
    }

    .status-badge {
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: bold;
    }

    .status-waiting {
        background: #fef3c7;
        color: #d97706;
    }

    .status-shipping {
        background: #dbeafe;
        color: #2563eb;
    }

    .status-completed {
        background: #dcfce7;
        color: #166534;
    }

    .status-cancelled {
        background: #fee2e2;
        color: #991b1b;
    }

    .action-links a {
        text-decoration: none;
        font-size: 13px;
        font-weight: bold;
        margin-right: 8px;
        padding: 4px 10px;
        border-radius: 4px;
        display: inline-block;
    }

    .btn-confirm {
        background: #2563eb;
        color: white;
    }

    .btn-ship {
        background: #10b981;
        color: white;
    }

    .btn-cancel {
        background: #ef4444;
        color: white;
    }
    </style>
</head>

<body>

    <header>
        <div class="header-logo">
            <h1>⚙️ Hệ thống Quản trị Đơn hàng</h1>
        </div>
        <div class="header-actions"><a href="lap4.php" class="cart-btn" style="background: #4b5563;">← Trang chủ
                shop</a></div>
    </header>

    <div class="admin-box">
        <table class="cart-table">
            <thead>
                <tr>
                    <th>Mã ĐH</th>
                    <th>Thông tin khách nhận</th>
                    <th>Hình thức</th>
                    <th>Tổng tiền</th>
                    <th>Trạng thái đơn</th>
                    <th>Sản phẩm đặt</th>
                    <th>Thao tác xử lý đơn</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order) { 
                    $details = $db_untils->getAll("SELECT od.*, p.mota FROM order_details od JOIN products p ON od.product_id = p.maSP WHERE od.order_id = ?", [$order['id']]);
                    $badge = 'status-waiting';
                    if ($order['status'] == 'Đang giao') $badge = 'status-shipping';
                    if ($order['status'] == 'Đã nhận') $badge = 'status-completed';
                    if ($order['status'] == 'Đã hủy') $badge = 'status-cancelled';
                ?>
                <tr>
                    <td><strong>#<?= $order['id'] ?></strong></td>
                    <td style="text-align: left; font-size: 13px;">
                        👤 <strong><?= htmlspecialchars($order['fullname']) ?></strong><br>
                        📞 <?= htmlspecialchars($order['phone']) ?><br>
                        📍 <?= htmlspecialchars($order['address']) ?>
                    </td>
                    <td><span style="font-weight: bold; color:#4b5563;"><?= $order['payment_method'] ?></span></td>
                    <td style="color:#dc2626; font-weight:bold;"><?= number_format($order['total_money']) ?> đ</td>
                    <td><span class="status-badge <?= $badge ?>"><?= $order['status'] ?></span></td>
                    <td style="text-align: left; font-size: 13px;">
                        <?php foreach($details as $d) { echo "- " . htmlspecialchars($d['mota']) . " (SL: <strong>" . $d['quantity'] . "</strong>)<br>"; } ?>
                    </td>
                    <td class="action-links">
                        <?php if($order['status'] == 'Chờ xác nhận') { ?>
                        <a href="admin_orders.php?order_id=<?= $order['id'] ?>&update_status=Đang giao"
                            class="btn-confirm" onclick="return confirm('Duyệt giao đơn này?')">✔ Xác nhận</a>
                        <a href="admin_orders.php?order_id=<?= $order['id'] ?>&update_status=Đã hủy" class="btn-cancel"
                            onclick="return confirm('Hủy đơn hàng?')">❌ Hủy</a>
                        <?php } elseif($order['status'] == 'Đang giao') { ?>
                        <a href="admin_orders.php?order_id=<?= $order['id'] ?>&update_status=Đã nhận" class="btn-ship"
                            onclick="return confirm('Đơn hàng đã giao thành công?')">📦 Đã nhận hàng</a>
                        <a href="admin_orders.php?order_id=<?= $order['id'] ?>&update_status=Đã hủy" class="btn-cancel"
                            onclick="return confirm('Hủy đơn hàng?')">❌ Hủy</a>
                        <?php } else { echo "<span style='color:#9ca3af; font-size:12px;'>Đơn hoàn thành</span>"; } ?>
                    </td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</body>

</html>