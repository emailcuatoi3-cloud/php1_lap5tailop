<?php
session_start();
require "./db_utils.php";
$db_untils = new DB_UTILS();

if (!isset($_SESSION['cart'])) { $_SESSION['cart'] = []; }

$success = "";
$error_msg = "";

// --- 📡 XỬ LÝ AJAX CHECKOUT & WEBSOCKET PUSH ---
if (isset($_GET['api']) && $_GET['api'] === 'checkout') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user'])) {
        echo json_encode(['status' => 'error', 'message' => '🔒 Bạn phải đăng nhập mới có thể tiến hành đặt hàng!']);
        exit();
    }

    $fullname = trim($_POST['fullname'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $address  = trim($_POST['address'] ?? '');
    $payment_method = $_POST['payment_method'] ?? '';

    if(empty($fullname) || empty($phone) || empty($email) || empty($address) || empty($payment_method)){
        echo json_encode(['status' => 'error', 'message' => 'Vui lòng nhập đầy đủ thông tin và chọn phương thức thanh toán!']);
        exit();
    } 
    if(count($_SESSION['cart']) == 0){
        echo json_encode(['status' => 'error', 'message' => 'Giỏ hàng của bạn đang trống!']);
        exit();
    }

    $total = 0;
    foreach($_SESSION['cart'] as $item) {
        $total += $item['price'] * $item['quantity'];
    }

    $userId = $_SESSION['user']['id'] ?? null;
    $db_untils->execute("INSERT INTO orders (user_id, fullname, phone, email, address, payment_method, total_money, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Chờ xác nhận')", [$userId, $fullname, $phone, $email, $address, $payment_method, $total]);
    $order_id = $db_untils->getLastInsertId();

    if($order_id) {
        $product_titles = [];
        foreach($_SESSION['cart'] as $item) {
            $db_untils->execute("INSERT INTO order_details (order_id, product_id, price, quantity) VALUES (?, ?, ?, ?)", [$order_id, $item['id'], $item['price'], $item['quantity']]);
            $product_titles[] = "- " . $item['name'] . " (SL: <strong>" . $item['quantity'] . "</strong>)";
        }
        
        // --- 🚀 THÔNG SỐ CONFIG PUSHER CHÍNH XÁC CỦA BẠN ---
        $pusher_app_id  = "2165330";
        $pusher_key     = "94c4c17f4353f8cdc5af";
        $pusher_secret  = "dd8e86dc55a80ca269fa";
        $pusher_cluster = "ap1";

        $pusher_data = [
            'id' => $order_id,
            'fullname' => htmlspecialchars($fullname),
            'phone' => htmlspecialchars($phone),
            'address' => htmlspecialchars($address),
            'payment_method' => $payment_method,
            'total_money' => number_format($total),
            'status' => 'Chờ xác nhận',
            'products_html' => implode('<br>', $product_titles)
        ];

        $payload = json_encode([
            'name' => 'new-order-event',
            'channels' => ['store-channel'],
            'data' => json_encode($pusher_data, JSON_UNESCAPED_UNICODE)
        ], JSON_UNESCAPED_UNICODE);

        $time = time();
        $body_md5 = md5($payload);
        
        // CHUẨN HÓA: Sắp xếp các tham số truy vấn theo đúng bảng chữ cái Alphabet bắt buộc của Pusher
        $query_string = "auth_key=$pusher_key&auth_timestamp=$time&auth_version=1.0&body_md5=$body_md5";
        
        // Tạo chuỗi ký tự ký duyệt dữ liệu chuẩn quy định
        $string_to_sign = "POST\n/apps/$pusher_app_id/events\n$query_string";
        $auth_signature = hash_hmac('sha256', $string_to_sign, $pusher_secret);
        
        // Khởi tạo luồng cổng cURL hướng ngoại phát sóng dữ liệu
        $url = "https://api-$pusher_cluster.pusher.com/apps/$pusher_app_id/events?$query_string&auth_signature=$auth_signature";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        // Bỏ qua xác thực SSL để chạy mượt mà trên môi trường Localhost (XAMPP)
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        
        $pusher_response = curl_exec($ch);
        curl_close($ch);

        $_SESSION['cart'] = [];
        echo json_encode([
            'status' => 'success', 
            'message' => 'Đặt hàng thành công! Đơn hàng của bạn đã gửi tín hiệu thời gian thực đến hệ thống Admin.',
            'debug_pusher' => $pusher_response // Trả về phản hồi từ Pusher để kiểm tra nếu cần
        ]);
        exit();
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Gặp sự cố kết nối dữ liệu!']);
        exit();
    }
}

// Giữ nguyên đoạn tăng giảm số lượng sản phẩm
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
    </style>
</head>

<body>
    <header>
        <div class="header-logo">
            <h1>🛒 Giỏ hàng & Thanh toán</h1>
        </div>
        <div class="header-actions">
            <?php if (isset($_SESSION['user'])) { ?>
            <a href="user_orders.php" class="cart-btn" style="background: #10b981; margin-right: 10px;">📋 Đơn hàng của
                tôi</a>
            <?php } ?>
            <a href="lap4.php" class="cart-btn" style="background: #4b5563;">← Cửa hàng</a>
        </div>
    </header>

    <div id="success-panel" class="success-box" style="display: none;">
        <div class="success" id="success-message"></div>
        <a href="lap4.php" class="btn" style="background: #2563eb; margin-top: 15px;">Tiếp tục mua sắm</a>
    </div>

    <div class="onepage-container" id="main-checkout-layout">
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
                        <td style="color: #dc2626; font-weight: bold;"><?= number_format($subtotal) ?> đ</td>
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
            <div class="error" id="error-alert" style="display: none; margin-bottom: 15px;"></div>
            <form id="orderForm" method="POST">
                <div class="form-group"><label>Họ và tên người nhận</label><input type="text" name="fullname"
                        placeholder="Nhập họ và tên"
                        value="<?= isset($_SESSION['user']['fullname']) ? htmlspecialchars($_SESSION['user']['fullname']) : '' ?>">
                </div>
                <div class="form-group"><label>Số điện thoại</label><input type="text" name="phone"
                        placeholder="Nhập số điện thoại nhận hàng"></div>
                <div class="form-group"><label>Email</label><input type="email" name="email"
                        placeholder="Nhập địa chỉ email"
                        value="<?= isset($_SESSION['user']['email']) ? htmlspecialchars($_SESSION['user']['email']) : '' ?>">
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
                <div class="total">Tổng thanh toán: <?= number_format($total) ?> đ</div>
                <button type="button" id="btn-submit-checkout" class="btn"
                    <?= (count($_SESSION['cart']) == 0) ? 'disabled style="background:#cbd5e1; cursor:not-allowed;"' : '' ?>>Xác
                    nhận đặt hàng</button>
            </form>
        </div>
    </div>

    <script>
    document.getElementById('btn-submit-checkout').addEventListener('click', function(e) {
        e.preventDefault();
        const form = document.getElementById('orderForm');
        const formData = new FormData(form);
        const errorAlert = document.getElementById('error-alert');
        errorAlert.style.display = 'none';

        fetch('cart.php?api=checkout', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(res => {
                if (res.status === 'error') {
                    errorAlert.innerHTML = '⚠️ ' + res.message;
                    errorAlert.style.display = 'block';
                } else {
                    document.getElementById('main-checkout-layout').style.display = 'none';
                    document.getElementById('success-message').innerHTML = '🎉 ' + res.message;
                    document.getElementById('success-panel').style.display = 'block';
                }
            });
    });
    </script>
</body>

</html>