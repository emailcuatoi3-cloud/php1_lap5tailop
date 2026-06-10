<?php
session_start();
require "./db_utils.php";
$db_untils = new DB_UTILS();

if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] !== 'admin' && $_SESSION['user']['username'] !== 'admin')) {
    die("<h2 style='color:red; text-align:center; margin-top:50px;'>Bạn không có quyền truy cập trang quản lý đơn hàng!</h2>");
}

// XỬ LÝ THAY ĐỔI TRẠNG THÁI TIẾN ĐỘ ĐƠN HÀNG PHÍA ADMIN
if (isset($_POST['action_update'])) {
    $order_id = (int)$_POST['order_id'];
    $new_status = $_POST['status'];
    $ly_do_huy = isset($_POST['ly_do_huy']) ? trim($_POST['ly_do_huy']) : null;
    
    // Lấy trạng thái hiện tại từ DB để kiểm tra điều kiện chặn
    $current_order = $db_untils->getOne("SELECT status FROM orders WHERE id = ?", [$order_id]);
    
    if ($current_order) {
        // NẾU ĐƠN HÀNG ĐANG GIAO THÌ KHÔNG ĐƯỢC PHÉP HỦY
        if ($current_order['status'] === 'Đang giao' && $new_status === 'Đã hủy') {
            echo "<script>alert('Đơn hàng đang đi giao, hệ thống chặn tính năng hủy đơn!'); window.location.href='admin_orders.php';</script>";
            exit();
        }

        if (in_array($new_status, ['Đang giao', 'Đã nhận', 'Đã hủy'])) {
            if ($new_status === 'Đã hủy') {
                $items = $db_untils->getAll("SELECT product_id, quantity FROM order_details WHERE order_id = ?", [$order_id]);
                foreach($items as $item) {
                    $db_untils->execute("UPDATE products SET ton_kho = ton_kho + ? WHERE maSP = ?", [$item['quantity'], $item['product_id']]);
                }
                $db_untils->execute("UPDATE orders SET status = ?, ly_do_huy = ? WHERE id = ?", [$new_status, "Shop hủy: " . $ly_do_huy, $order_id]);
            } else {
                $db_untils->execute("UPDATE orders SET status = ?, ly_do_huy = NULL WHERE id = ?", [$new_status, $order_id]);
            }
        }
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

    .btn-action-submit {
        border: none;
        font-size: 13px;
        font-weight: bold;
        margin-right: 5px;
        padding: 6px 12px;
        border-radius: 4px;
        cursor: pointer;
        color: white;
        display: inline-block;
        text-decoration: none;
    }

    .btn-confirm {
        background: #2563eb;
    }

    .btn-ship {
        background: #10b981;
    }

    .btn-cancel {
        background: #ef4444;
    }

    /* THEO DÕI ĐƠN HÀNG MINI TRÊN BẢNG ADMIN */
    .admin-track {
        display: flex;
        gap: 5px;
        margin-top: 5px;
        font-size: 11px;
        font-weight: bold;
    }

    .adm-step {
        padding: 2px 6px;
        background: #e5e7eb;
        color: #6b7280;
        border-radius: 4px;
    }

    .adm-step.done {
        background: #059669;
        color: white;
    }

    .adm-step.fail {
        background: #dc2626;
        color: white;
    }

    .modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 2000;
        justify-content: center;
        align-items: center;
    }

    .modal-box {
        background: white;
        padding: 25px;
        border-radius: 8px;
        width: 400px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
    }

    .modal-buttons {
        display: flex;
        gap: 10px;
        margin-top: 15px;
    }
    </style>
</head>

<body>
    <header>
        <div class="header-logo">
            <h1>⚙️ Hệ thống Quản trị Đơn hàng & Vận đơn</h1>
        </div>
        <div class="header-actions"><a href="lap4.php" class="cart-btn" style="background: #4b5563;">← Xem Cửa hàng</a>
        </div>
    </header>

    <div class="admin-box">
        <table class="cart-table">
            <thead>
                <tr>
                    <th>Mã ĐH</th>
                    <th>Khách nhận hàng</th>
                    <th>Hình thức</th>
                    <th>Tổng tiền</th>
                    <th>Trạng thái đơn</th>
                    <th>Sản phẩm đặt & Luồng giám sát</th>
                    <th>Thao tác xử lý</th>
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
                    <td>
                        <span class="status-badge <?= $badge ?>"><?= $order['status'] ?></span>
                        <?php if($order['status'] == 'Đã hủy' && !empty($order['ly_do_huy'])) { ?>
                        <div
                            style="font-size: 11px; color:#dc2626; margin-top:5px; max-width:180px; text-align:left; font-style: italic;">
                            <strong>Lý do:</strong> <?= htmlspecialchars($order['ly_do_huy']) ?>
                        </div>
                        <?php } ?>
                    </td>
                    <td style="text-align: left; font-size: 13px;">
                        <div style="margin-bottom: 6px;">
                            <?php foreach($details as $d) { echo "- " . htmlspecialchars($d['mota']) . " (SL: <strong>" . $d['quantity'] . "</strong>)<br>"; } ?>
                        </div>

                        <div class="admin-track">
                            <span class="adm-step done">1. Nhận đơn</span>
                            <?php if($order['status'] !== 'Đã hủy') { ?>
                            <span
                                class="adm-step <?= ($order['status']=='Đang giao'||$order['status']=='Đã nhận')?'done':'' ?>">2.
                                Đang giao</span>
                            <span class="adm-step <?= ($order['status']=='Đã nhận')?'done':'' ?>">3. Hoàn tất</span>
                            <?php } else { ?>
                            <span class="adm-step fail">❌ Đơn đã hủy</span>
                            <?php } ?>
                        </div>
                    </td>
                    <td>
                        <?php if($order['status'] == 'Chờ xác nhận') { ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                            <input type="hidden" name="status" value="Đang giao">
                            <button type="submit" name="action_update" class="btn-action-submit btn-confirm"
                                onclick="return confirm('Duyệt giao đơn này?')">✔ Duyệt giao hàng</button>
                        </form>
                        <button class="btn-action-submit btn-cancel" onclick="openCancelModal(<?= $order['id'] ?>)">❌
                            Hủy đơn</button>

                        <?php } elseif($order['status'] == 'Đang giao') { ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                            <input type="hidden" name="status" value="Đã nhận">
                            <button type="submit" name="action_update" class="btn-action-submit btn-ship"
                                onclick="return confirm('Đơn hàng đã giao thành công?')">📦 Khách đã nhận</button>
                        </form>
                        <span style="color:#6b7280; font-size:12px; display:block; margin-top:5px;">🚫 Đang giao
                            hàng<br>(Khóa tính năng hủy)</span>

                        <?php } else { ?>
                        <span style="color:#9ca3af; font-size:12px;">🔒 Đóng hồ sơ đơn</span>
                        <?php } ?>
                    </td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>

    <div id="cancelModal" class="modal-overlay">
        <div class="modal-box">
            <h3>Nhập lý do shop hủy đơn hàng</h3>
            <form method="POST" action="admin_orders.php">
                <input type="hidden" name="order_id" id="modal_order_id">
                <input type="hidden" name="status" value="Đã hủy">
                <div class="form-group">
                    <textarea name="ly_do_huy" rows="3" placeholder="Lý do hủy đơn từ phía shop/hết hàng..."
                        required></textarea>
                </div>
                <div class="modal-buttons">
                    <button type="submit" name="action_update" class="btn" style="background:#ef4444; padding:10px;">Xác
                        nhận hủy</button>
                    <button type="button" class="btn" style="background:#6b7280; padding:10px;"
                        onclick="closeCancelModal()">Đóng</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openCancelModal(orderId) {
        document.getElementById('modal_order_id').value = orderId;
        document.getElementById('cancelModal').style.display = 'flex';
    }

    function closeCancelModal() {
        document.getElementById('cancelModal').style.display = 'none';
    }
    </script>
</body>

</html>