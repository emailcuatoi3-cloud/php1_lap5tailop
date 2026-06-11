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

    @keyframes highlightFlash {
        0% {
            background-color: #fff7ed;
        }

        50% {
            background-color: #ffedd5;
        }

        100% {
            background-color: #ffffff;
        }
    }

    .new-websocket-row {
        animation: highlightFlash 3s ease-out forwards;
    }
    </style>
    <script src="https://js.pusher.com/8.0.1/pusher.min.js"></script>
</head>

<body>
    <audio id="live-audio" src="https://assets.mixkit.co/active_storage/sfx/2869/2869-600.wav" preload="auto"></audio>

    <header>
        <div class="header-logo">
            <h1>⚙️ Hệ thống Quản trị Đơn hàng Real-time (Pusher)</h1>
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
            <tbody id="orders-tbody-list">
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

    <script>
    // Kích hoạt kết nối với Pusher dựa trên Key ứng dụng của bạn
    const pusher = new Pusher('94c4c17f4353f8cdc5af', {
        cluster: 'ap1'
    });
    const channel = pusher.subscribe('store-channel');

    channel.bind('new-order-event', function(data) {
        // KIỂM TRA ĐỊNH DẠNG: Nếu Pusher tự động hóa mã hóa đối tượng thành chuỗi String, ta tiến hành bóc tách nó ra
        let order = (typeof data === 'string') ? JSON.parse(data) : data;
        if (typeof order.data === 'string') {
            order = JSON.parse(order.data);
        } else if (order.data) {
            order = order.data;
        }

        const tbody = document.getElementById("orders-tbody-list");
        const newRow = document.createElement("tr");
        newRow.className = "new-websocket-row";

        newRow.innerHTML = `
            <td><strong>#${order.id}</strong></td>
            <td style="text-align: left; font-size: 13px;">
                👤 <strong>${order.fullname}</strong><br>
                📞 ${order.phone}<br>
                📍 ${order.address}
            </td>
            <td><span style="font-weight: bold; color:#4b5563;">${order.payment_method}</span></td>
            <td style="color:#dc2626; font-weight:bold;">${order.total_money} đ</td>
            <td><span class="status-badge status-waiting">${order.status}</span></td>
            <td style="text-align: left; font-size: 13px;">${order.products_html}</td>
            <td class="action-links">
                <a href="admin_orders.php?order_id=${order.id}&update_status=Đang giao" class="btn-confirm" onclick="return confirm('Duyệt giao đơn này?')">✔ Xác nhận</a>
                <a href="admin_orders.php?order_id=${order.id}&update_status=Đã hủy" class="btn-cancel" onclick="return confirm('Hủy đơn hàng?')">❌ Hủy</a>
            </td>
        `;

        // Đẩy hàng đơn mới trồi hẳn lên vị trí đầu tiên của bảng danh sách mà không cần F5 trang
        if (tbody.firstChild) {
            tbody.insertBefore(newRow, tbody.firstChild);
        } else {
            tbody.appendChild(newRow);
        }

        // Bật chuông thông báo âm thanh "Ting Ting" Real-time
        try {
            document.getElementById("live-audio").play();
        } catch (e) {
            console.log(
                "Trình duyệt yêu cầu Admin phải tương tác click chuột lên trang ít nhất 1 lần trước khi phát âm thanh tự động."
            );
        }
    });
    </script>
</body>

</html>