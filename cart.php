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
    // Thiết lập trạng thái ban đầu: VNPAY, MOMO sẽ là 'Chờ thanh toán', COD là 'Chờ xác nhận'
    $order_status = ($payment_method === 'COD') ? 'Chờ xác nhận' : 'Chờ thanh toán';

    $db_untils->execute("INSERT INTO orders (user_id, fullname, phone, email, address, payment_method, total_money, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)", [$userId, $fullname, $phone, $email, $address, $payment_method, $total, $order_status]);
    $order_id = $db_untils->getLastInsertId();

    if($order_id) {
        $product_titles = [];
        foreach($_SESSION['cart'] as $item) {
            $db_untils->execute("INSERT INTO order_details (order_id, product_id, price, quantity) VALUES (?, ?, ?, ?)", [$order_id, $item['id'], $item['price'], $item['quantity']]);
            $product_titles[] = "- " . $item['name'] . " (SL: <strong>" . $item['quantity'] . "</strong>)";
        }
        
        $products_html = implode('<br>', $product_titles);
        date_default_timezone_set('Asia/Ho_Chi_Minh');

        // --- HƯỚNG XỬ LÝ 1: THANH TOÁN QUA CỔNG VNPAY ---
        if ($payment_method === 'VNPAY') {
            $vnp_TmnCode = "A60MS9RE";
            $vnp_HashSecret = "WTLFGFZCOALXUZXOUYXZMMXMMXALZHYG";
            $vnp_Url = "https://sandbox.vnpayment.vn/paymentv2/vpcpay.html";
            
            $vnp_Returnurl = "http://" . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?') . "vnpay_return.php";
            $vnp_Returnurl = str_replace("cart.php", "vnpay_return.php", $vnp_Returnurl);

            $vnp_TxnRef = $order_id;
            $vnp_OrderInfo = "Thanh toan don hang #" . $order_id;
            $vnp_OrderType = "billpayment";
            $vnp_Amount = $total * 100;
            $vnp_Locale = "vi";
            $vnp_BankCode = "NCB";
            $vnp_IpAddr = $_SERVER['REMOTE_ADDR'];

            $inputData = array(
                "vnp_Version" => "2.1.0",
                "vnp_TmnCode" => $vnp_TmnCode,
                "vnp_Amount" => $vnp_Amount,
                "vnp_Command" => "pay",
                "vnp_CreateDate" => date('YmdHis'),
                "vnp_CurrCode" => "VND",
                "vnp_IpAddr" => $vnp_IpAddr,
                "vnp_Locale" => $vnp_Locale,
                "vnp_OrderInfo" => $vnp_OrderInfo,
                "vnp_OrderType" => $vnp_OrderType,
                "vnp_ReturnUrl" => $vnp_Returnurl,
                "vnp_TxnRef" => $vnp_TxnRef
            );
            
            if (isset($vnp_BankCode) && $vnp_BankCode != "") {
                $inputData['vnp_BankCode'] = $vnp_BankCode;
            }

            ksort($inputData);
            $query = "";
            $i = 0;
            $hashdata = "";
            foreach ($inputData as $key => $value) {
                if ($i == 1) {
                    $query .= '&' . urlencode($key) . "=" . urlencode($value);
                } else {
                    $query .= urlencode($key) . "=" . urlencode($value);
                    $i = 1;
                }
                $hashdata .= urlencode($key) . '=' . urlencode($value) . '&';
            }
            
            $hashdata = rtrim($hashdata, '&');
            $vnp_Url = $vnp_Url . "?" . $query;
            if (isset($vnp_HashSecret)) {
                $vnpSecureHash = hash_hmac('sha512', $hashdata, $vnp_HashSecret);
                $vnp_Url .= '&vnp_SecureHash=' . $vnpSecureHash;
            }

            $_SESSION['cart'] = [];
            echo json_encode([
                'status' => 'redirect_vnpay',
                'redirect_url' => $vnp_Url,
                'message' => 'Đang kết nối đến cổng VNPAY...'
            ]);
            exit();

        } 
        // -----------------------------------------------------------------
        // HƯỚNG XỬ LÝ B: THANH TOÁN QUA CỔNG ĐIỆN TỬ MOMO SANDBOX (MỚI CHUẨN V2)
        // -----------------------------------------------------------------
        elseif ($payment_method === 'MOMO') {
            $endpoint = "https://test-payment.momo.vn/v2/gateway/api/create";
            
            $partnerCode = "MOMOBKUN20180529";
            $accessKey   = "klm0566894333044";
            $secretKey   = "at67q66895433100";
            
            $redirectUrl = "http://" . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?') . "momo_return.php";
            $redirectUrl = str_replace("cart.php", "momo_return.php", $redirectUrl);
            $ipnUrl      = $redirectUrl; 

            $orderInfo = "Thanh toan don hang #" . $order_id . " qua vi MoMo";
            $amount    = strval($total); 
            $requestId = strval($order_id . '_' . time());
            $requestType = "captureWallet"; 
            $extraData = "";

            // ⚠️ FIX QUAN TRỌNG: Thứ tự chuỗi RawHash bắt buộc phải xếp đúng Alphabet tuyệt đối của MoMo V2
            $rawHash = "accessKey=" . $accessKey .
                       "&amount=" . $amount .
                       "&extraData=" . $extraData .
                       "&ipnUrl=" . $ipnUrl .
                       "&orderId=" . $order_id .
                       "&orderInfo=" . $orderInfo .
                       "&partnerCode=" . $partnerCode .
                       "&redirectUrl=" . $redirectUrl .
                       "&requestId=" . $requestId .
                       "&requestType=" . $requestType;

            $signature = hash_hmac("sha256", $rawHash, $secretKey);

            // Mảng dữ liệu đóng gói gửi đi POST sang MoMo
            $data = array(
                'partnerCode' => $partnerCode,
                'partnerName' => "Test Store Realtime",
                'storeId'     => "MomoTestStore",
                'requestId'   => $requestId,
                'amount'      => $amount,
                'orderId'     => $order_id,
                'orderInfo'   => $orderInfo,
                'redirectUrl' => $redirectUrl,
                'ipnUrl'      => $ipnUrl,
                'lang'        => 'vi',
                'extraData'   => $extraData,
                'requestType' => $requestType,
                'signature'   => $signature
            );

            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen(json_encode($data))
            ));
            
            $result = curl_exec($ch);
            curl_close($ch);
            
            $jsonResult = json_decode($result, true);

            if (isset($jsonResult['payUrl'])) {
                $_SESSION['cart'] = []; // Xóa giỏ hàng khi nổ link thành công
                echo json_encode([
                    'status' => 'redirect_momo',
                    'redirect_url' => $jsonResult['payUrl'],
                    'message' => 'Đang kết nối liên kết tới ví điện tử MoMo...'
                ]);
            } else {
                // Trả về thông tin lỗi chuẩn trực quan
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Lỗi kết nối cổng MoMo: ' . ($jsonResult['message'] ?? 'Sai lệch mã chữ ký hoặc tham số hệ thống!')
                ]);
            }
            exit();
        }
        // --- HƯỚNG XỬ LÝ 3: THANH TOÁN COD TIỀN MẶT TRUYỀN THỐNG ---
        else {
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
                'products_html' => $products_html
            ];

            $payload = json_encode([
                'name' => 'new-order-event',
                'channels' => ['store-channel'],
                'data' => json_encode($pusher_data, JSON_UNESCAPED_UNICODE)
            ], JSON_UNESCAPED_UNICODE);

            $time = time();
            $auth_signature = hash_hmac('sha256', "POST\n/apps/$pusher_app_id/events\nauth_key=$pusher_key&auth_timestamp=$time&auth_version=1.0&body_md5=" . md5($payload), $pusher_secret);
            
            $ch = curl_init("https://api-$pusher_cluster.pusher.com/apps/$pusher_app_id/events?auth_key=$pusher_key&auth_timestamp=$time&auth_version=1.0&auth_signature=$auth_signature");
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_exec($ch);
            curl_close($ch);

            $_SESSION['cart'] = [];
            echo json_encode(['status' => 'success', 'message' => 'Đặt hàng thành công! Đơn hàng của bạn đã gửi tín hiệu thời gian thực đến hệ thống Admin.']);
            exit();
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Gặp sự cố kết nối dữ liệu!']);
        exit();
    }
}

// Luồng tăng giảm số lượng sản phẩm
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
        transition: 0.2s;
    }

    .pay-item input {
        display: none;
    }

    .pay-item:has(input:checked) {
        border-color: #f57224;
        background: #fff7ed;
        color: #f57224;
        box-shadow: 0 0 6px rgba(245, 114, 36, 0.15);
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
                } else if (res.status === 'redirect_vnpay') {
                    window.location.href = res.redirect_url;
                } else if (res.status === 'redirect_momo') {
                    // Tự động chuyển hướng sang trang MoMo Gateway Sandbox
                    window.location.href = res.redirect_url;
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