<?php
session_start();
require "./db_utils.php";
$db_untils = new DB_UTILS();

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

$userId = $_SESSION['user']['id'];
$success_msg = "";
$error_msg = "";

// XỬ LÝ HỦY ĐƠN HÀNG PHÍA USER
if (isset($_POST['cancel_order_user'])) {
    $order_id = (int)$_POST['order_id'];
    $ly_do_huy = trim($_POST['ly_do_huy']);

    if (empty($ly_do_huy)) {
        $error_msg = "Vui lòng nhập lý do hủy đơn hàng!";
    } else {
        $order = $db_untils->getOne("SELECT status, created_at FROM orders WHERE id = ? AND user_id = ?", [$order_id, $userId]);
        
        if ($order) {
            $time_diff = (time() - strtotime($order['created_at'])) / 3600;

            if ($order['status'] === 'Đang giao') {
                $error_msg = "Đơn hàng đang được giao đi, không thể hủy bỏ lúc này!";
            } elseif ($order['status'] === 'Đã nhận') {
                $error_msg = "Đơn hàng đã hoàn thành, không thể hủy!";
            } elseif ($order['status'] === 'Đã hủy') {
                $error_msg = "Đơn hàng này vốn đã được hủy trước đó.";
            } elseif ($time_diff > 2) {
                $error_msg = "Đã quá hạn 2 tiếng để tự hủy đơn hàng!";
            } else {
                // Hoàn lại kho và hủy đơn
                $items = $db_untils->getAll("SELECT product_id, quantity FROM order_details WHERE order_id = ?", [$order_id]);
                foreach($items as $item) {
                    $db_untils->execute("UPDATE products SET ton_kho = ton_kho + ? WHERE maSP = ?", [$item['quantity'], $item['product_id']]);
                }
                $db_untils->execute("UPDATE orders SET status = 'Đã hủy', ly_do_huy = ? WHERE id = ?", ["Khách hàng hủy: " . $ly_do_huy, $order_id]);
                $success_msg = "Đã hủy thành công đơn hàng #" . $order_id . " và hoàn lại số lượng sản phẩm vào kho.";
            }
        }
    }
}

// XỬ LÝ MUA LẠI ĐƠN HÀNG
if (isset($_GET['action']) && $_GET['action'] == 'reorder') {
    $order_id = (int)$_GET['order_id'];
    $items = $db_untils->getAll("SELECT product_id, quantity FROM order_details WHERE order_id = ?", [$order_id]);
    if (count($items) > 0) {
        if (!isset($_SESSION['cart'])) { $_SESSION['cart'] = []; }
        foreach ($items as $item) {
            $pId = $item['product_id'];
            $qty = $item['quantity'];
            $productInfo = $db_untils->getOne("SELECT * FROM products WHERE maSP = ?", [$pId]);
            if ($productInfo && $productInfo['ton_kho'] > 0) {
                $_SESSION['cart'][$pId] = [
                    'id' => $productInfo['maSP'],
                    'name' => $productInfo['mota'],
                    'price' => $productInfo['gia'],
                    'image' => $productInfo['hinhAnh'],
                    'quantity' => min($qty, $productInfo['ton_kho'])
                ];
            }
        }
        echo "<script>alert('Đã thêm các sản phẩm vào giỏ hàng!'); window.location.href='cart.php';</script>";
        exit();
    }
}

$orders = $db_untils->getAll("SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC", [$userId]);
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Theo dõi Đơn hàng của tôi</title>
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
        display: inline-block;
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
        font-size: 13px;
        font-weight: bold;
        padding: 6px 12px;
        border-radius: 6px;
        border: none;
        cursor: pointer;
        display: inline-block;
        text-decoration: none;
        text-align: center;
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

    /* THANH THEO DÕI TIẾN TRÌNH ĐƠN HÀNG (TRACKING FLOW) */
    .track-flow {
        display: flex;
        justify-content: space-between;
        margin-top: 10px;
        padding: 10px 0;
        background: #f9fafb;
        border-radius: 8px;
        border: 1px solid #f3f4f6;
        position: relative;
    }

    .track-step {
        flex: 1;
        text-align: center;
        font-size: 11px;
        color: #9ca3af;
        position: relative;
        font-weight: bold;
    }

    .track-step::after {
        content: "➔";
        position: absolute;
        right: -8%;
        top: 20%;
        color: #d1d5db;
        font-size: 12px;
    }

    .track-step:last-child::after {
        content: "";
    }

    .track-step.active {
        color: #10b981;
    }

    .track-step.active-cancel {
        color: #ef4444;
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
            <h1>📋 Lịch sử & Theo dõi tiến độ đơn hàng</h1>
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
                    <th>Mã ĐH</th>
                    <th>Thời gian đặt</th>
                    <th>Tổng tiền</th>
                    <th>Trạng thái</th>
                    <th>Chi tiết sản phẩm & Hành trình theo dõi</th>
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

                    // Tính thời gian xem có hợp lệ tự hủy (trong vòng 2h & trạng thái là Chờ xác nhận)
                    $can_cancel = false;
                    if ($order['status'] === 'Chờ xác nhận') {
                        $time_diff = (time() - strtotime($order['created_at'])) / 3600;
                        if ($time_diff <= 2) { $can_cancel = true; }
                    }
                ?>
                <tr>
                    <td><strong>#<?= $order['id'] ?></strong></td>
                    <td style="font-size: 13px;"><?= $order['created_at'] ?></td>
                    <td style="color: #dc2626; font-weight: bold;"><?= number_format($order['total_money']) ?> đ</td>
                    <td>
                        <span class="status-badge <?= $badge ?>"><?= $order['status'] ?></span>
                        <?php if(!empty($order['ly_do_huy'])) { ?>
                        <div
                            style="font-size: 11px; color:#991b1b; margin-top:4px; max-width: 140px; text-align: left; font-style: italic;">
                            [<?= htmlspecialchars($order['ly_do_huy']) ?>]</div>
                        <?php } ?>
                    </td>
                    <td style="text-align: left;">
                        <div style="font-size: 13px; margin-bottom: 8px;">
                            <?php foreach($details as $d) { echo "• " . htmlspecialchars($d['mota']) . " (SL: " . $d['quantity'] . ")<br>"; } ?>
                        </div>

                        <div class="track-flow">
                            <?php if($order['status'] !== 'Đã hủy') { ?>
                            <div
                                class="track-step <?= ($order['status']=='Chờ xác nhận'||$order['status']=='Đang giao'||$order['status']=='Đã nhận')?'active':'' ?>">
                                📝 Đã đặt đơn</div>
                            <div
                                class="track-step <?= ($order['status']=='Đang giao'||$order['status']=='Đã nhận')?'active':'' ?>">
                                🚚 Đang giao hàng</div>
                            <div class="track-step <?= ($order['status']=='Đã nhận')?'active':'' ?>">🎉 Đã nhận hàng
                            </div>
                            <?php } else { ?>
                            <div class="track-step">📝 Tiếp nhận</div>
                            <div class="track-step active-cancel">❌ Đơn hàng đã hủy</div>
                            <?php } ?>
                        </div>
                    </td>
                    <td>
                        <?php if ($can_cancel) { ?>
                        <button class="btn-action btn-cancel-user" onclick="openCancelModal(<?= $order['id'] ?>)">❌ Hủy
                            đơn</button>
                        <?php } elseif ($order['status'] === 'Đang giao') { ?>
                        <span style="color:#2563eb; font-size:12px; font-weight:bold;">🚫 Không thể hủy<br>(Đang
                            giao)</span>
                        <?php } ?>

                        <?php if ($order['status'] == 'Đã nhận' || $order['status'] == 'Đã hủy') { ?>
                        <a href="user_orders.php?action=reorder&order_id=<?= $order['id'] ?>"
                            class="btn-action btn-reorder"
                            onclick="return confirm('Thêm lại các sản phẩm này vào giỏ hàng?')">🔄 Mua lại</a>
                        <?php } ?>
                    </td>
                </tr>
                <?php } ?>
                <?php if(count($orders) == 0) { echo "<tr><td colspan='6'>Bạn chưa mua đơn hàng nào.</td></tr>"; } ?>
            </tbody>
        </table>
    </div>

    <div id="userCancelModal" class="modal-overlay">
        <div class="modal-box">
            <h3 style="margin-bottom: 15px;">Lý do hủy đơn hàng</h3>
            <form method="POST" action="user_orders.php">
                <input type="hidden" name="order_id" id="modal_order_id">
                <div class="form-group">
                    <textarea name="ly_do_huy" rows="3" placeholder="Vui lòng ghi rõ lý do hủy đơn hàng..."
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