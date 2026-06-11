<?php
session_start();
require "./db_utils.php";
$db_untils = new DB_UTILS();

// Thiết lập cấu hình Header bắt buộc cho cổng kết nối dữ liệu liên tục Real-time (SSE)
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

// Lấy mốc ID đơn hàng lớn nhất hiện tại mà Admin đang nhìn thấy trên màn hình
$last_seen_id = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;

while (true) {
    // Tìm kiếm xem có đơn hàng nào mới tinh (ID lớn hơn mốc đang nhìn thấy) không
    $new_orders = $db_untils->getAll("SELECT * FROM orders WHERE id > ? ORDER BY id ASC", [$last_seen_id]);

    if (!empty($new_orders)) {
        $result_orders = [];
        foreach ($new_orders as $order) {
            // Lấy thêm chi tiết các sản phẩm của đơn hàng mới đó
            $order['products'] = $db_untils->getAll("
                SELECT od.*, p.mota 
                FROM order_details od 
                JOIN products p ON od.product_id = p.maSP 
                WHERE od.order_id = ?
            ", [$order['id']]);
            
            $result_orders[] = $order;
            
            // Cập nhật lại cột mốc ID lớn nhất để vòng lặp sau không bị lấy trùng
            if ($order['id'] > $last_seen_id) {
                $last_seen_id = $order['id'];
            }
        }

        // Bắn gói dữ liệu Real-time về cho trình duyệt Admin
        echo "data: " . json_encode([
            'has_new' => true, 
            'last_id' => $last_seen_id, 
            'orders' => $result_orders
        ]) . "\n\n";
    } else {
        // Nếu không có đơn mới, gửi tín hiệu giữ kết nối (ping) định kỳ
        echo "data: " . json_encode(['has_new' => false, 'last_id' => $last_seen_id]) . "\n\n";
    }

    // Đẩy dữ liệu ra khỏi bộ đệm để Client nhận được ngay lập tức
    ob_flush();
    flush();
    
    // Nghỉ 2 giây trước khi lặp lại chu kỳ quét DB tiếp theo
    sleep(2);
}