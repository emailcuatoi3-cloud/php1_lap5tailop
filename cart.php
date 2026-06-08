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
    if ($_GET['action'] == 'increase' && isset($_SESSION['cart'][$id])) $_SESSION['cart'][$id]['quantity']++;
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
    // BẮT BUỘC ĐĂNG NHẬP: Kiểm tra xem người dùng đã đăng nhập chưa
    if (!isset($_SESSION['user'])) {
        $error_msg = "Bạn cần phải đăng nhập tài khoản trước khi tiến hành đặt hàng thanh toán! <a href='login.php' style='color:#991b1b; font-weight:bold; text-decoration:underline;'>Đăng nhập ngay</a>";
    } else {
        $fullname = trim($_POST['fullname']);
        $phone    = trim($_POST['phone']);
        $email    = trim($_POST['email']);
        $address  = trim($_POST['address']);
        $payment_method = $_POST['payment_method'] ?? '';

        if(empty($fullname) || empty($phone) || empty($email) || empty($address) || empty($payment_method)){
            $error_msg = "Vui lòng nhập đầy đủ thông tin và chọn phương thức thanh toán!";
        } elseif(count($_SESSION['cart']) == 0){
            $error_msg = "Giỏ hàng của bạn đang trống!";
        } else {
            $userId = $_SESSION['user']['id'] ?? null;
            $db_untils->execute("INSERT INTO orders (user_id, fullname, phone, email, address, payment_method, total_money) VALUES (?, ?, ?, ?, ?, ?, ?)", [$userId, $fullname, $phone, $email, $address, $payment_method, $total]);
            $order_id = $db_untils->getLastInsertId();

            if($order_id) {
                foreach($_SESSION['cart'] as $item) {
                    $db_untils->execute("INSERT INTO order_details (order_id, product_id, price, quantity) VALUES (?, ?, ?, ?)", [$order_id, $item['id'], $item['price'], $item['quantity']]);
                }
                $_SESSION['cart'] = []; // Làm sạch giỏ hàng
                $success = "Đặt hàng thành công! Đơn hàng đang chờ quản trị viên phê duyệt.";
            } else {
                $error_msg = "Hệ thống gặp sự cố cố định, vui lòng thử lại sau!";
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
    }

    .pay-item input {
        display: none;
    }

    .pay-item:has(input:checked) {
        border-color: #f57224;
        background: #fff7ed;
        color: #f57224;
    }

    /* Định dạng hộp cảnh báo yêu cầu đăng nhập riêng biệt */
    .login-required-alert {
        background: #fffbdf;
        border: 1px solid #f6e05e;
        color: #856404;
        padding: 12px;
        border-radius: 6px;
        margin-bottom: 15px;
        text-align: center;
        font-size: 14px;
        font-weight: 500;
    }

    .login-required-alert a {
        color: #b7791f;
        font-weight: bold;
        text-decoration: underline;
    }
    </style>
</head>

<body>

    <header>
        <div class="header-logo">
            <h1>🛒 Giỏ hàng & Thanh toán</h1>
        </div>
        <div class="header-actions"><a href="lap4.php" class="cart-btn" style="background: #4b5563;">← Cửa hàng</a>
        </div>
    </header>

    <?php if(!empty($success)){ ?>
    <div class="success-box">
        <div class="success">🎉 <?= $success ?></div><a href="lap4.php" class="btn" style="background: #43567e;">Tiếp
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
                        <?php 
                        // Nếu giá trị lưu trong database là dạng đầy đủ (ví dụ: 500000, 1000000)
                        $is_red = ($subtotal > 500000); 

                        // HOẶC nếu database của bạn chỉ lưu số rút gọn (ví dụ: Giá là 500 thay vì 500000)
                        // hãy đổi điều kiện thành: $is_red = ($subtotal > 500);
                            ?>
                        <td style="color: <?= $is_red ? '#dc2626' : '#111827' ?>; font-weight: bold;">
                            <?= number_format($subtotal) ?> đ
                        </td>
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
            <div class="login-required-alert">
                🔒 Bạn đang thao tác với quyền Khách. Vui lòng <a href="login.php">Đăng nhập tài khoản</a> để hệ thống
                xác thực đơn hàng thành công!
            </div>
            <?php } ?>

            <form method="POST">
                <div class="form-group"><label>Họ và tên người nhận</label>
                    <input type="text" name="fullname" placeholder="Nhập họ và tên"
                        value="<?= isset($_SESSION['user']) ? htmlspecialchars($_SESSION['user']['fullname']) : '' ?>">
                </div>
                <div class="form-group"><label>Số điện thoại</label>
                    <input type="text" name="phone" placeholder="Nhập số điện thoại nhận hàng">
                </div>
                <div class="form-group"><label>Email</label>
                    <input type="email" name="email" placeholder="Nhập địa chỉ email"
                        value="<?= isset($_SESSION['user']) ? htmlspecialchars($_SESSION['user']['email']) : '' ?>">
                </div>
                <div class="form-group"><label>Địa chỉ nhận hàng</label>
                    <textarea name="address" rows="3"
                        placeholder="Số nhà, tên đường, phường/xã, quận/huyện..."></textarea>
                </div>
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
                <div class="total">Tổng thanh toán: <?= number_format($total) ?> đ</div>

                <button type="submit" name="submit_order" class="btn"
                    <?= (count($_SESSION['cart']) == 0) ? 'disabled style="background:#cbd5e1; cursor:not-allowed;"' : '' ?>>
                    Xác nhận đặt hàng
                </button>
            </form>
        </div>
    </div>
</body>

</html>