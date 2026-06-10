<?php
session_start();
require "./db_utils.php";
$db_untils = new DB_UTILS();

if (!isset($_SESSION['cart'])) { $_SESSION['cart'] = []; }

$success = "";
$error_msg = "";

// Cập nhật tăng/giảm số lượng và xóa sản phẩm
if (isset($_GET['action'])) {
    $id = $_GET['id'] ?? '';
    if ($_GET['action'] == 'increase' && isset($_SESSION['cart'][$id])) {
        $prod = $db_untils->getOne("SELECT ton_kho FROM products WHERE maSP = ?", [$id]);
        if ($prod && $_SESSION['cart'][$id]['quantity'] >= $prod['ton_kho']) {
            echo "<script>alert('Không thể tăng! Đã đạt giới hạn tồn kho.'); window.location.href='cart.php';</script>";
            exit();
        }
        $_SESSION['cart'][$id]['quantity']++;
    }
    if ($_GET['action'] == 'decrease' && isset($_SESSION['cart'][$id])) {
        $_SESSION['cart'][$id]['quantity']--;
        if ($_SESSION['cart'][$id]['quantity'] <= 0) unset($_SESSION['cart'][$id]);
    }
    if ($_GET['action'] == 'remove') unset($_SESSION['cart'][$id]);
    header("Location: cart.php"); exit();
}

// Tính toán tổng số tiền
$total = 0;
foreach($_SESSION['cart'] as $maSPKey => $item){
    if (!is_array($item)) {
        $productFix = $db_untils->getOne("SELECT * FROM products WHERE maSP = ?", [$maSPKey]);
        if ($productFix) {
            $_SESSION['cart'][$maSPKey] = [
                'id' => $productFix['maSP'], 'name' => $productFix['mota'],
                'price' => $productFix['gia'], 'image' => $productFix['hinhAnh'], 'quantity' => 1
            ];
            $item = $_SESSION['cart'][$maSPKey];
        } else { unset($_SESSION['cart'][$maSPKey]); continue; }
    }
    $total += $item['price'] * $item['quantity'];
}

// Xử lý ghi nhận Đơn đặt hàng mới vào database
if(isset($_POST['submit_order'])){
    if (!isset($_SESSION['user'])) {
        $error_msg = "Bạn cần phải đăng nhập tài khoản trước khi tiến hành đặt hàng thanh toán! <a href='login.php' style='color:#991b1b; font-weight:bold; text-decoration:underline;'>Đăng nhập ngay</a>";
    } else {
        $fullname = trim($_POST['fullname']);
        $phone    = trim($_POST['phone']);
        $email    = trim($_POST['email']);
        $address  = trim($_POST['address']);
        $payment_method = $_POST['payment_method'] ?? '';

        // Lấy thông tin thanh toán bổ sung
        $momo_phone = trim($_POST['momo_phone'] ?? '');
        $vnpay_card = trim($_POST['vnpay_card'] ?? '');

        if(empty($fullname) || empty($phone) || empty($email) || empty($address) || empty($payment_method)){
            $error_msg = "Vui lòng nhập đầy đủ thông tin nhận hàng và chọn phương thức thanh toán!";
        } elseif($payment_method === 'MOMO' && empty($momo_phone)) {
            $error_msg = "Vui lòng nhập số điện thoại đăng ký Ví MoMo để hệ thống liên kết thanh toán!";
        } elseif($payment_method === 'VNPAY' && empty($vnpay_card)) {
            $error_msg = "Vui lòng nhập số thẻ/tài khoản ngân hàng liên kết VNPAY!";
        } elseif(count($_SESSION['cart']) == 0){
            $error_msg = "Giỏ hàng của bạn đang trống!";
        } else {
            // Kiểm tra hàng tồn kho trước khi đặt
            $check_stock = true;
            foreach($_SESSION['cart'] as $item) {
                $p_stock = $db_untils->getOne("SELECT ton_kho FROM products WHERE maSP = ?", [$item['id']]);
                if ($p_stock && $item['quantity'] > $p_stock['ton_kho']) {
                    $error_msg = "Sản phẩm '" . htmlspecialchars($item['name']) . "' chỉ còn lại " . $p_stock['ton_kho'] . " sản phẩm trong kho.";
                    $check_stock = false;
                    break;
                }
            }

            if ($check_stock) {
                $userId = $_SESSION['user']['id'] ?? null;
                
                // Ghi chú thông tin tài khoản trực tuyến vào cột hình thức thanh toán nếu có
                $final_payment = $payment_method;
                if($payment_method === 'MOMO') $final_payment .= " (Ví: " . $momo_phone . ")";
                if($payment_method === 'VNPAY') $final_payment .= " (Thẻ: " . $vnpay_card . ")";

                $db_untils->execute("INSERT INTO orders (user_id, fullname, phone, email, address, payment_method, total_money, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Chờ xác nhận')", [$userId, $fullname, $phone, $email, $address, $final_payment, $total]);
                $order_id = $db_untils->getLastInsertId();

                if($order_id) {
                    foreach($_SESSION['cart'] as $item) {
                        $db_untils->execute("INSERT INTO order_details (order_id, product_id, price, quantity) VALUES (?, ?, ?, ?)", [$order_id, $item['id'], $item['price'], $item['quantity']]);
                        // Trừ hàng tồn kho
                        $db_untils->execute("UPDATE products SET ton_kho = ton_kho - ? WHERE maSP = ?", [$item['quantity'], $item['id']]);
                    }
                    $_SESSION['cart'] = []; // Làm sạch giỏ hàng
                    $success = "Đặt hàng thành công! Đơn hàng trực tuyến của bạn đang chờ phê duyệt xác nhận.";
                } else {
                    $error_msg = "Hệ thống gặp sự cố, vui lòng thử lại sau!";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Giỏ hàng & Thanh toán</title>
    <link rel="stylesheet" href="./style.css?v=<?= time() ?>">
    <style>
    .payment-options {
        display: flex;
        gap: 15px;
        margin-top: 5px;
        margin-bottom: 15px;
    }

    .pay-item {
        flex: 1;
        text-align: center;
        border: 1px solid #ddd;
        padding: 12px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 13px;
        font-weight: bold;
        background: #fafafa;
        transition: 0.2s;
    }

    .pay-item input {
        display: none;
    }

    .pay-item:has(input:checked) {
        border-color: #f57224;
        background: #fff7ed;
        color: #f57224;
        box-shadow: 0 0 5px rgba(245, 114, 36, 0.2);
    }

    .login-required-alert {
        background: #fee2e2;
        border: 1px solid #fca5a5;
        color: #991b1b;
        padding: 12px;
        border-radius: 6px;
        margin-bottom: 15px;
        text-align: center;
        font-size: 14px;
        font-weight: bold;
    }

    /* Giao diện phụ nhập thông tin online */
    .online-pay-fields {
        display: none;
        background: #f9fafb;
        border: 1px dashed #d1d5db;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 15px;
        animation: fadeIn 0.3s ease;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(-5px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    </style>
</head>

<body>
    <header>
        <div class="header-logo">
            <h1>🛒 Giỏ hàng & Thanh toán</h1>
        </div>
        <div class="header-actions">
            <a href="user_orders.php" class="cart-btn" style="background: #10b981; margin-right: 10px;">📋 Đơn hàng của
                tôi</a>
            <a href="lap4.php" class="cart-btn" style="background: #4b5563;">← Cửa hàng</a>
        </div>
    </header>

    <?php if(!empty($success)){ ?>
    <div class="success-box">
        <div class="success">🎉 <?= $success ?></div><a href="lap4.php" class="btn" style="background: #2563eb;">Tiếp
            tục mua sắm</a>
    </div>
    <?php exit(); } ?>

    <div class="onepage-container">
        <div class="checkout-left-panel">
            <h2>Sản phẩm trong giỏ hàng</h2>
            <?php if(count($_SESSION['cart']) > 0){ ?>
            <table class="cart-table">
                <thead>
                    <tr>
                        <th>Ảnh</th>
                        <th>Sản phẩm</th>
                        <th>Giá</th>
                        <th>Số lượng</th>
                        <th>Thành tiền</th>
                        <th>Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($_SESSION['cart'] as $item){ $subtotal = $item['price'] * $item['quantity']; ?>
                    <tr>
                        <td><img src="<?= htmlspecialchars($item['image']) ?>"></td>
                        <td style="text-align: left; max-width: 240px;"><?= htmlspecialchars($item['name']) ?></td>
                        <td><?= number_format($item['price']) ?> đ</td>
                        <td>
                            <a href="cart.php?action=decrease&id=<?= $item['id'] ?>" class="qty-btn">-</a>
                            <span class="qty-number"><?= $item['quantity'] ?></span>
                            <a href="cart.php?action=increase&id=<?= $item['id'] ?>" class="qty-btn">+</a>
                        </td>
                        <?php $is_red = ($subtotal > 500000); ?>
                        <td style="color: <?= $is_red ? '#dc2626' : '#111827' ?>; font-weight: bold;">
                            <?= number_format($subtotal) ?> đ</td>
                        <td><a href="cart.php?action=remove&id=<?= $item['id'] ?>" class="delete-btn"
                                onclick="return confirm('Xóa sản phẩm này?');">❌ Xóa</a></td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
            <?php } else { echo "<div style='text-align: center; padding: 40px 0;'>Giỏ hàng trống.</div>"; } ?>
        </div>

        <div class="checkout-right-panel">
            <h2>Thông tin mua hàng</h2>
            <?php if(!empty($error_msg)){ echo "<div class='error'>⚠️ $error_msg</div>"; } ?>

            <?php if(!isset($_SESSION['user'])){ ?>
            <div class="login-required-alert">🔒 Bạn phải <a href="login.php"
                    style="color: #991b1b; text-decoration: underline;">Đăng nhập</a> mới có thể tiến hành đặt hàng!
            </div>
            <?php } ?>

            <form method="POST">
                <div class="form-group"><label>Họ và tên người nhận</label><input type="text" name="fullname"
                        placeholder="Nhập họ và tên"
                        value="<?= isset($_SESSION['user']) ? htmlspecialchars($_SESSION['user']['fullname']) : '' ?>">
                </div>
                <div class="form-group"><label>Số điện thoại</label><input type="text" name="phone"
                        placeholder="Nhập số điện thoại nhận hàng"></div>
                <div class="form-group"><label>Email</label><input type="email" name="email"
                        placeholder="Nhập địa chỉ email"
                        value="<?= isset($_SESSION['user']) ? htmlspecialchars($_SESSION['user']['email']) : '' ?>">
                </div>
                <div class="form-group"><label>Địa chỉ nhận hàng</label><textarea name="address" rows="3"
                        placeholder="Số nhà, tên đường, phường/xã, quận/huyện..."></textarea></div>

                <div class="form-group">
                    <label>Phương thức thanh toán</label>
                    <div class="payment-options">
                        <label class="pay-item"><input type="radio" name="payment_method" value="COD" checked>💵 Tiền
                            mặt (COD)</label>
                        <label class="pay-item"><input type="radio" name="payment_method" value="MOMO">🔴 Ví
                            MoMo</label>
                        <label class="pay-item"><input type="radio" name="payment_method" value="VNPAY">🔵 VNPAY</label>
                    </div>
                </div>

                <div id="momo-fields" class="online-pay-fields">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label style="color: #d11a59;">Số điện thoại đăng ký MoMo</label>
                        <input type="text" name="momo_phone" placeholder="Ví dụ: 0912345678"
                            style="border-color: #fca5a5;">
                    </div>
                </div>

                <div id="vnpay-fields" class="online-pay-fields">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label style="color: #005baa;">Số thẻ / Số tài khoản ngân hàng</label>
                        <input type="text" name="vnpay_card" placeholder="Nhập số thẻ hoặc số tài khoản nội địa"
                            style="border-color: #93c5fd;">
                    </div>
                </div>

                <div class="total">Tổng thanh toán: <?= number_format($total) ?> đ</div>
                <button type="submit" name="submit_order" class="btn"
                    <?= (count($_SESSION['cart']) == 0 || !isset($_SESSION['user'])) ? 'disabled style="background:#cbd5e1; cursor:not-allowed;"' : '' ?>>Xác
                    nhận đặt hàng</button>
            </form>
        </div>
    </div>

    <script>
    document.querySelectorAll('input[name="payment_method"]').forEach(radio => {
        radio.addEventListener('change', function() {
            const momoFields = document.getElementById('momo-fields');
            const vnpayFields = document.getElementById('vnpay-fields');

            // Ẩn tất cả trước khi xử lý
            momoFields.style.display = 'none';
            vnpayFields.style.display = 'none';

            // Kiểm tra hiển thị theo giá trị được chọn
            if (this.value === 'MOMO') {
                momoFields.style.display = 'block';
            } else if (this.value === 'VNPAY') {
                vnpayFields.style.display = 'block';
            }
        });
    });
    </script>
</body>

</html>