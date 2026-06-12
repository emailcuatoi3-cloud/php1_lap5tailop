<?php
session_start();
require "./db_utils.php";
$db_untils = new DB_UTILS();


$vnp_HashSecret = "WTLFGFZCOALXUZXOUYXZMMXMMXALZHYG"; // Khóa bảo mật môi trường Sandbox


$vnp_Data = $_GET;
$vnp_SecureHash = $_GET['vnp_SecureHash'] ?? '';
unset($vnp_Data['vnp_SecureHash']);
unset($vnp_Data['vnp_SecureHashType']);

ksort($vnp_Data);
$i = 0;
$hashdata = "";
foreach ($vnp_Data as $key => $value) {
    if ($i == 1) {
        $hashdata .= '&' . urlencode($key) . "=" . urlencode($value);
    } else {
        $hashdata .= urlencode($key) . "=" . urlencode($value);
        $i = 1;
    }
}

$secureHash = hash_hmac('sha512', $hashdata, $vnp_HashSecret);

$order_id = (int)($_GET['vnp_TxnRef'] ?? 0);
$vnp_ResponseCode = $_GET['vnp_ResponseCode'] ?? '99';

$payment_status = "Thất bại";
$alert_class = "alert-danger";
$message = "Thanh toán qua cổng VNPAY không thành công hoặc đã bị hủy bỏ.";

if ($secureHash === $vnp_SecureHash) {
    if ($vnp_ResponseCode == '00') {
        // Cập nhật Database chuyển từ Chờ thanh toán -> Chờ xác nhận
        $db_untils->execute("UPDATE orders SET status = 'Chờ xác nhận' WHERE id = ?", [$order_id]);
        
        $payment_status = "Thành công";
        $alert_class = "alert-success";
        $message = "🎉 Thanh toán thành công! Đơn hàng #" . $order_id . " đã ghi nhận giao dịch trực tuyến.";

        // --- BẮN SỰ KIỆN PUSHER REAL-TIME SANG ADMIN ---
        $order = $db_untils->getOne("SELECT * FROM orders WHERE id = ?", [$order_id]);
        if($order) {
            $details = $db_untils->getAll("SELECT od.*, p.mota FROM order_details od JOIN products p ON od.product_id = p.maSP WHERE od.order_id = ?", [$order_id]);
            $product_titles = [];
            foreach($details as $item) {
                $product_titles[] = "- " . $item['mota'] . " (SL: <strong>" . $item['quantity'] . "</strong>)";
            }

            $pusher_app_id  = "2165330"; 
            $pusher_key     = "94c4c17f4353f8cdc5af"; 
            $pusher_secret  = "dd8e86dc55a80ca269fa"; 
            $pusher_cluster = "ap1"; 

            $pusher_data = [
                'id' => $order_id,
                'fullname' => htmlspecialchars($order['fullname']),
                'phone' => htmlspecialchars($order['phone']),
                'address' => htmlspecialchars($order['address']),
                'payment_method' => $order['payment_method'] . " (Đã thanh toán)",
                
                'total_money' => number_format($order['total_money']),
                'status' => 'Chờ xác nhận',
                'products_html' => implode('<br>', $product_titles)
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
        }
    }
} else {
    $message = "⚠️ Chữ ký bảo mật không chính xác!";
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Kết quả thanh toán VNPAY</title>
    <link rel="stylesheet" href="./style.css?v=<?= time() ?>">
    <style>
    .vnpay-box {
        max-width: 600px;
        margin: 50px auto;
        background: white;
        padding: 30px;
        border-radius: 8px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.06);
        text-align: center;
    }

    .alert-success {
        color: #15803d;
        background: #f0fdf4;
        padding: 12px;
        border-radius: 6px;
        font-weight: bold;
        margin-bottom: 20px;
    }

    .alert-danger {
        color: #b91c1c;
        background: #fef2f2;
        padding: 12px;
        border-radius: 6px;
        margin-bottom: 20px;
        font-weight: bold;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 20px;
        text-align: left;
    }

    table td {
        padding: 10px;
        border-bottom: 1px solid #e2e8f0;
    }
    </style>
</head>

<body>
    <div class="vnpay-box">
        <h2>Kết Quả Giao Dịch</h2>
        <div class="<?= $alert_class ?>"><?= $message ?></div>
        <table>
            <tr>
                <td>Mã đơn hàng:</td>
                <td><strong>#<?= $order_id ?></strong></td>
            </tr>
            <tr>
                <td>Số tiền:</td>
                <td style="color:#dc2626; font-weight:bold;"><?= number_format(($_GET['vnp_Amount'] ?? 0) / 100) ?> đ
                </td>
            </tr>
            <tr>
                <td>Mã giao dịch VNPAY:</td>
                <td><code><?= htmlspecialchars($_GET['vnp_TransactionNo'] ?? 'N/A') ?></code></td>
            </tr>
            <tr>
                <td>Trạng thái:</td>
                <td><strong><?= $payment_status ?></strong></td>
            </tr>
        </table>
        <a href="lap4.php" class="btn"
            style="background: #2563eb; color:white; padding:10px 20px; text-decoration:none; border-radius:4px; display:inline-block;">Quay
            lại cửa hàng</a>
    </div>
</body>

</html>