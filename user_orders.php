<?php
session_start();
require "./db_utils.php";
$db_untils = new DB_UTILS();

// Ép buộc người dùng đăng nhập để xem đơn cá nhân
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

$userId = $_SESSION['user']['id'];
$success_msg = "";
$error_msg = "";

// 1. XỬ LÝ CHỨC NĂNG HỦY ĐƠN HÀNG (TRONG VÒNG 2 GIỜ)
if (isset($_POST['cancel_order_user'])) {
    $order_id = (int)$_POST['order_id'];
    $ly_do_huy = trim($_POST['ly_do_huy']);

    if (empty($ly_do_huy)) {
        $error_msg = "Vui lòng nhập lý do hủy đơn hàng!";
    } else {
        // Kiểm tra xem đơn hàng có thuộc về user này không và thời gian < 2 giờ
        $order = $db_untils->getOne("SELECT status, created_at FROM orders WHERE id = ? AND user_id = ?", [$order_id, $userId]);
        
        if ($order) {
            $order_time = strtotime($order['created_at']);
            $current_time = time();
            $hours_diff = ($current_time - $order_time) / 3600;

            if ($order['status'] !== 'Chờ xác nhận') {
                $error_msg = "Đơn hàng đã được xử lý hoặc đang giao, không thể tự hủy!";
            } elseif ($hours_diff > 2) {
                $error_msg = "Đã quá giới hạn thời gian 2 giờ kể từ lúc đặt, không thể tự hủy đơn!";
            } else {
                // Hoàn lại số lượng tồn kho cho sản phẩm
                $items = $db_untils->getAll("SELECT product_id, quantity FROM order_details WHERE order_id = ?", [$order_id]);
                foreach($items as $item) {
                    $db_untils->execute("UPDATE products SET ton_kho = ton_kho + ? WHERE maSP = ?", [$item['quantity'], $item['product_id']]);
                }
                // Cập nhật trạng thái hủy
                $db_untils->execute("UPDATE orders SET status = 'Đã hủy', ly_do_huy = ? WHERE id = ?", ["Khách hàng hủy: " . $ly_do_huy, $order_id]);
                $success_msg = "Đã hủy thành công đơn hàng #" . $order_id . " và hoàn kho hàng thành công!";
            }
        }
    }
}

// 2. XỬ LÝ CHỨC NĂNG MUA LẠI ĐƠN HÀNG (ĐÃ NHẬN HOẶC ĐÃ HỦY)
if (isset($_GET['action']) && $_GET['action'] == 'reorder') {
    $order_id = (int)$_GET['order_id'];
    
    // Lấy danh sách sản phẩm từ chi tiết đơn hàng cũ
    $items = $db_untils->getAll("SELECT product_id, quantity FROM order_details WHERE order_id = ?", [$order_id]);
    
    if (count($items) > 0) {
        if (!isset($_SESSION['cart'])) { $_SESSION['cart'] = []; }
        
        foreach ($items as $item) {
            $pId = $item['product_id'];
            $qty = $item['quantity'];
            
            $productInfo = $db_untils->getOne("SELECT * FROM products WHERE maSP = ?", [$pId]);
            if ($productInfo && $productInfo['ton_kho'] > 0) {
                // Giới hạn nạp tối đa bằng số lượng tồn kho thực tế hiện tại
                $final_qty = min($qty, $productInfo['ton_kho']);
                
                $_SESSION['cart'][$pId] = [
                    'id' => $productInfo['maSP'],
                    'name' => $productInfo['mota'],
                    'price' => $productInfo['gia'],
                    'image' => $productInfo['hinhAnh'],
                    'quantity' => $final_qty
                ];
            }
        }
        echo "<script>alert('Đã tải lại toàn bộ sản phẩm hợp lệ của đơn hàng này vào giỏ! Đang chuyển đến giỏ hàng.'); window.location.href='cart.php';</script>";
        exit();
    }
}

// Lấy danh sách đơn hàng cá nhân của người dùng
$orders = $db_untils->getAll("SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC", [$userId]);
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Đơn hàng của tôi</title>
    <link rel="stylesheet" href="./style.css?v=<?= time() ?>">
    <style>
    .orders-container {
        max-width: 1200px;
        margin: 20px auto;
        background: white;
        padding: 25px;
        border-radius: 12px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
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

    .btn-action {
        text-decoration: none;
        font-size: 13px;
        font-weight: bold;
        padding: 5px 12px;
        border-radius: 6px;
        border: none;
        cursor: pointer;
        display: inline-block;
    }

    .btn-reorder {
        background: #ffd814;
        color: #111;
        border: 1px solid #fcd200;
    }

    .btn-cancel-user {
        background: #ef4444;
        color: white;
    }

    /* Modal Popup */
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
            <h1>📋 Danh sách Đơn hàng của tôi</h1>
        </div>
        <div class="header-actions"><a href="lap4.php" class="cart-btn" style="background: #4b5563;">← Cửa hàng</a>
        </div>
    </header>

    <div class="orders-container">
        <?php if(!empty($success_msg)){ echo "<div class='success'>✔ $success_msg</div>"; } ?>
        <?php if(!empty($error_msg)){ echo "<div class='error'>⚠️ $error_msg</div>"; } ?>

        <table class="cart-table">
            <thead>
                <tr>
                    <th>Mã đơn</th>
                    <th>Thời gian đặt</th>
                    <th>Tổng tiền</th>
                    <th>Hình thức</th>
                    <th>Trạng thái</th>
                    <th>Chi tiết sản phẩm</th>
                    <th>Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order) { 
                    $details = $db_untils->getAll("SELECT od.*, p.mota FROM order_details od JOIN products p ON od.product_id = p.maSP WHERE od.order_id = ?", [$order['id']]);
                    
                    $badge = 'status-waiting';
                    if ($order['status'] == 'Đang giao') $badge = 'status-shipping';
                    if ($order['status'] == 'Đã nhận') $badge = 'status-completed';
                    if ($order['status'] == 'Đã hủy') $badge = 'status-cancelled';

                    // Tính thời gian xem có hợp lệ trong vòng 2 giờ không
                    $can_cancel = false;
                    if ($order['status'] == 'Chờ xác nhận') {
                        $time_diff = (time() - strtotime($order['created_at'])) / 3600;
                        if ($time_diff <= 2) { $can_cancel = true; }
                    }
                ?>
                <tr>
                    <td><strong>#<?= $order['id'] ?></strong></td>
                    <td style="font-size: 13px;"><?= $order['created_at'] ?></td>
                    <td style="color: #dc2626; font-weight: bold;"><?= number_format($order['total_money']) ?> đ</td>
                    <td><strong><?= $order['payment_method'] ?></strong></td>
                    <td>
                        <span class="status-badge <?= $badge ?>"><?= $order['status'] ?></span>
                        <?php if(!empty($order['ly_do_huy'])) { ?>
                        <div
                            style="font-size: 11px; color:#991b1b; margin-top:4px; max-width: 150px; text-align: left;">
                            [<?= htmlspecialchars($order['ly_do_huy']) ?>]</div>
                        <?php } ?>
                    </td>
                    <td style="text-align: left; font-size: 13px;">
                        <?php foreach($details as $d) { echo "- " . htmlspecialchars($d['mota']) . " (SL: " . $d['quantity'] . ")<br>"; } ?>
                    </td>
                    <td>
                        <?php if ($can_cancel) { ?>
                        <button class="btn-action btn-cancel-user" onclick="openCancelModal(<?= $order['id'] ?>)">❌ Hủy
                            đơn</button>
                        <?php } ?>

                        <?php if ($order['status'] == 'Đã nhận' || $order['status'] == 'Đã hủy') { ?>
                        <a href="user_orders.php?action=reorder&order_id=<?= $order['id'] ?>"
                            class="btn-action btn-reorder"
                            onclick="return confirm('Thêm lại toàn bộ các sản phẩm này vào giỏ hàng?')">🔄 Mua lại</a>
                        <?php } ?>

                        <?php if (!$can_cancel && $order['status'] == 'Chờ xác nhận') { echo "<span style='color:#6b7280; font-size:12px;'>Quá hạn 2h tự hủy</span>"; } ?>
                        <?php if ($order['status'] == 'Đang giao') { echo "<span style='color:#2563eb; font-size:12px;'>Đang vận chuyển</span>"; } ?>
                    </td>
                </tr>
                <?php } ?>
                <?php if(count($orders) == 0) { echo "<tr><td colspan='7'>Bạn chưa mua đơn hàng nào.</td></tr>"; } ?>
            </tbody>
        </table>
    </div>

    <div id="userCancelModal" class="modal-overlay">
        <div class="modal-box">
            <h3 style="margin-bottom: 15px;">Nhập lý do khách hàng hủy đơn</h3>
            <form method="POST" action="user_orders.php">
                <input type="hidden" name="order_id" id="modal_order_id">
                <div class="form-group">
                    <textarea name="ly_do_huy" rows="3" placeholder="Ghi rõ lý do bạn muốn hủy đơn đặt hàng..."
                        required></textarea>
                </div>
                <div class="modal-buttons">
                    <button type="submit" name="cancel_order_user" class="btn"
                        style="background:#ef4444; padding:10px;">Xác nhận hủy</button>
                    <button type="button" class="btn" style="background:#6b7280; padding:10px;"
                        onclick="closeCancelModal()">Đóng</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openCancelModal(orderId) {
        document.getElementById('modal_order_id').value = orderId;
        document.getElementById('userCancelModal').style.display = 'flex';
    }

    function closeCancelModal() {
        document.getElementById('userCancelModal').style.display = 'none';
    }
    </script>
</body>

</html>