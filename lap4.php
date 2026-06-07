<?php
require "./db_utils.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$db_untils = new DB_UTILS();

$errors = [];
$success = "";

// Kiểm tra quyền Admin
$isAdmin = isset($_SESSION['user']) && ($_SESSION['user']['role'] === 'admin' || $_SESSION['user']['username'] === 'admin');

if(isset($_GET['success'])){
    switch($_GET['success']){
        case 'add': $success = "Thêm sản phẩm thành công!"; break;
        case 'update': $success = "Cập nhật sản phẩm thành công!"; break;
        case 'delete': $success = "Xóa sản phẩm thành công!"; break;
    }
}

// =======================
// XỬ LÝ THÊM VÀO GIỎ HÀNG KHÔNG ĐỔI TRANG (FALLBACK)
// =======================
if (isset($_GET['action']) && $_GET['action'] === 'add' && isset($_GET['id'])) {
    $pId = $_GET['id'];
    
    if (isset($_SESSION['cart'][$pId]) && is_array($_SESSION['cart'][$pId])) {
        $_SESSION['cart'][$pId]['quantity']++;
    } else {
        $productInfo = $db_untils->getOne("SELECT * FROM products WHERE maSP = ?", [$pId]);
        if ($productInfo) {
            $_SESSION['cart'][$pId] = [
                'id' => $productInfo['maSP'], 'name' => $productInfo['mota'],
                'price' => $productInfo['gia'], 'image' => $productInfo['hinhAnh'], 'quantity' => 1
            ];
        }
    }
    $currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $currentKeyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
    header("Location: lap4.php?page=" . $currentPage . "&keyword=" . urlencode($currentKeyword));
    exit();
}

// =======================
// XÓA SẢN PHẨM (CHỈ ADMIN)
// =======================
if (isset($_GET['delete'])) {
    if (!$isAdmin) { die("BẠN KHÔNG CÓ QUYỀN THỰC HIỆN HÀNH ĐỘNG NÀY!"); }
    $maSP = $_GET['delete'];
    $db_untils->execute("DELETE FROM products WHERE maSP = ?", [$maSP]);
    header("Location: lap4.php?success=delete");
    exit();
}

// =======================
// LẤY THÔNG TIN SỬA (CHỈ ADMIN)
// =======================
$editProduct = null;
if (isset($_GET['edit'])) {
    if (!$isAdmin) { die("BẠN KHÔNG CÓ QUYỀN THỰC HIỆN HÀNH ĐỘNG NÀY!"); }
    $maSP = $_GET['edit'];
    $editProduct = $db_untils->getOne("SELECT * FROM products WHERE maSP = ?", [$maSP]);
}

// =======================
// CHI TIẾT SẢN PHẨM
// =======================
$detailProduct = null;
if (isset($_GET['detail'])) {
    $maSP = $_GET['detail'];
    $detailProduct = $db_untils->getOne("SELECT * FROM products WHERE maSP = ?", [$maSP]);
}

// =======================
// XỬ LÝ FORM: THÊM / SỬA (CHỈ ADMIN)
// =======================
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!$isAdmin) { die("BẠN KHÔNG CÓ QUYỀN THỰC HIỆN HÀNH ĐỘNG NÀY!"); }
    $productId = trim($_POST['productId']);
    $description = trim($_POST['description']);
    $price = trim($_POST['price']);
    $image = trim($_POST['image']);

    if (empty($productId)) { $errors[] = "ID không được để trống"; }
    if (empty($price)) { $errors[] = "Giá tiền không được để trống"; }

    if (isset($_POST['update'])) {
        if (count($errors) == 0) {
            $db_untils->execute("UPDATE products SET mota = ?, gia = ?, hinhAnh = ? WHERE maSP = ?", [$description, $price, $image, $productId]);
            header("Location: lap4.php?success=update"); exit();
        }
    } else {
        $check_product = $db_untils->getOne("SELECT * FROM products WHERE maSP = ?", [$productId]);
        if ($check_product) { $errors[] = "Mã sản phẩm đã tồn tại!"; }
        if (count($errors) == 0) {
            $db_untils->execute("INSERT INTO products (maSP,mota,gia,hinhAnh) VALUES (?,?,?,?)", [$productId, $description, $price, $image]);
            header("Location: lap4.php?success=add"); exit();
        }
    }
}

// =======================
// TÌM KIẾM + PHÂN TRANG
// =======================
$limit = 6;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) { $page = 1; }
$start = ($page - 1) * $limit;
$keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : "";

if (!empty($keyword)) {
    $search = "%$keyword%";
    $countProducts = $db_untils->getAll("SELECT * FROM products WHERE maSP LIKE ? OR mota LIKE ?", [$search, $search]);
    $products = $db_untils->getAll("SELECT * FROM products WHERE maSP LIKE ? OR mota LIKE ? LIMIT $start,$limit", [$search, $search]);
} else {
    $countProducts = $db_untils->getAll("SELECT * FROM products");
    $products = $db_untils->getAll("SELECT * FROM products LIMIT $start,$limit");
}
$totalProducts = count($countProducts);
$totalPages = ceil($totalProducts / $limit);
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cửa hàng sản phẩm</title>
    <link rel="stylesheet" href="./style.css?v=<?= time() ?>">
</head>

<body>

    <header>
        <div class="header-logo">
            <h1>Hệ Thống Quản Lý Sản Phẩm</h1>
        </div>
        <div class="header-actions">
            <div class="user-nav-box">
                <?php if (isset($_SESSION['user'])) { ?>
                <span>Xin chào, <strong><?= htmlspecialchars($_SESSION['user']['fullname']) ?></strong>
                    <span
                        style="font-size:11px; padding:2px 6px; background:#e0f2fe; color:#0369a1; border-radius:10px; font-weight:bold; text-transform:uppercase;">
                        <?= htmlspecialchars($_SESSION['user']['role']) ?>
                    </span></span>
                <?php if ($isAdmin) { ?>
                <a href="admin_orders.php" style="color: #16a34a;">📦 Đơn đặt hàng</a>
                <?php } ?>
                <a href="logout.php" class="btn-logout-link"
                    onclick="return confirm('Bạn có chắc muốn đăng xuất?')">Đăng xuất</a>
                <?php } else { ?>
                <a href="login.php">🔑 Đăng nhập</a>
                <?php } ?>
            </div>
            <a href="cart.php" class="cart-btn">🛒 Giỏ hàng (<span
                    id="cart-counter"><?= count($_SESSION['cart']) ?></span>)</a>
        </div>
    </header>

    <?php if ($detailProduct) { ?>
    <div class="amazon-detail-container">
        <div class="detail-navigation"><a href="?page=<?= $page ?>&keyword=<?= urlencode($keyword) ?>"
                class="btn-back-amazon">‹ Quay lại danh sách</a></div>
        <div class="detail-main-layout">
            <div class="detail-image-panel">
                <div class="detail-image-wrapper"><img src="<?= htmlspecialchars($detailProduct['hinhAnh']) ?>"></div>
            </div>
            <div class="detail-info-panel">
                <h2 class="amazon-title">Sản phẩm mã số: <?= htmlspecialchars($detailProduct['maSP']) ?></h2>
                <a href="#" class="amazon-brand-link">Ghé thăm cửa hàng Store chính hãng</a>
                <div class="amazon-rating"><span class="stars">★★★★★</span><span class="rating-count">(4.8 trên 5
                        sao)</span></div>
                <div class="divider"></div>
                <div class="amazon-price-row">
                    <span class="price-label">Giá bán:</span><span
                        class="amazon-detail-price"><?= number_format($detailProduct['gia'], 0, ',', '.') ?> đ</span>
                </div>
                <div class="divider"></div>
                <div class="amazon-description-box">
                    <h3>Mô tả chi tiết:</h3>
                    <p class="amazon-description-text"><?= htmlspecialchars($detailProduct['mota']) ?></p>
                </div>
            </div>
            <div class="detail-buy-box">
                <span class="buy-box-price"><?= number_format($detailProduct['gia'], 0, ',', '.') ?> đ</span>
                <div class="stock-status">Còn hàng</div>
                <div class="delivery-text">Giao hàng COD miễn phí toàn quốc nhanh chóng từ 2-3 ngày.</div>
                <a href="lap4.php?action=add&id=<?= $detailProduct['maSP'] ?>&page=<?= $page ?>&keyword=<?= urlencode($keyword) ?>"
                    class="amazon-cart-btn ajax-add-to-cart">🛒 Thêm vào giỏ hàng</a>
            </div>
        </div>
    </div>
    <?php } ?>

    <?php if (!$detailProduct) { ?>
    <div class="main-layout">
        <?php if ($isAdmin) { ?>
        <div class="left-panel">
            <form class="product-form" method="POST">
                <h2><?= $editProduct ? "Sửa sản phẩm" : "Thêm sản phẩm mới" ?></h2>
                <div class="form-group"><label>ID sản phẩm</label><input type="text" name="productId"
                        value="<?= $editProduct['maSP'] ?? '' ?>" <?= $editProduct ? "readonly" : "" ?>></div>
                <div class="form-group"><label>Mô tả</label><textarea
                        name="description"><?= $editProduct['mota'] ?? '' ?></textarea></div>
                <div class="form-group"><label>Giá tiền</label><input type="text" name="price"
                        value="<?= $editProduct['gia'] ?? '' ?>"></div>
                <div class="form-group"><label>Đường dẫn hình ảnh (URL)</label><input type="url" name="image"
                        value="<?= $editProduct['hinhAnh'] ?? '' ?>"></div>
                <?php if ($editProduct) { ?><button type="submit" name="update" class="btn-edit-form">Cập nhật sản
                    phẩm</button>
                <?php } else { ?><button type="submit" class="btn-add">Thêm sản phẩm</button><?php } ?>
                <?php foreach($errors as $error){ echo "<div class='alert-danger'>$error</div>"; } ?>
                <?php if(!empty($success)){ echo "<div class='alert-success'>$success</div>"; } ?>
            </form>
        </div>
        <?php } ?>

        <div class="right-panel" style="<?= !$isAdmin ? 'width: 100%;' : '' ?>">
            <form method="GET" style="max-width:600px; margin:0 auto 20px auto; display:flex; gap:10px;">
                <input type="text" name="keyword" placeholder="Nhập mã sản phẩm hoặc mô tả..."
                    value="<?= htmlspecialchars($keyword) ?>"
                    style="flex:1; padding:10px; border:1px solid #ccc; border-radius:8px;">
                <button type="submit" style="width:120px; background:#2563eb;">Tìm kiếm</button>
                <a href="lap4.php"><button type="button" style="width:120px; background:#6b7280;">Xóa bộ
                        lọc</button></a>
            </form>

            <h2>Danh sách sản phẩm hiện có</h2>
            <?php if(count($products) == 0){ echo "<div style='text-align:center; color:#6b7280; font-weight:600;'>🔍 Không tìm thấy sản phẩm phù hợp</div>"; } ?>

            <div class="product-list">
                <?php foreach($products as $product){ $image = !empty($product['hinhAnh']) ? $product['hinhAnh'] : 'https://via.placeholder.com/400x250'; ?>
                <div class="product-card">
                    <img src="<?= htmlspecialchars($image) ?>">
                    <div class="product-info">
                        <p><strong>ID:</strong> <?= htmlspecialchars($product['maSP']) ?></p>
                        <p><strong>Mô tả:</strong> <?= htmlspecialchars($product['mota']) ?></p>
                        <p class="product-price"><?= number_format($product['gia'], 0, ',', '.') ?> đ</p>
                    </div>
                    <div class="action-group">
                        <a href="?detail=<?= $product['maSP'] ?>&page=<?= $page ?>&keyword=<?= urlencode($keyword) ?>"
                            class="btn-amazon btn-detail">Chi tiết</a>
                        <?php if ($isAdmin) { ?>
                        <a href="?edit=<?= $product['maSP'] ?>&page=<?= $page ?>&keyword=<?= urlencode($keyword) ?>"
                            class="btn-amazon btn-edit">Sửa</a>
                        <a href="?delete=<?= $product['maSP'] ?>" class="btn-amazon btn-delete"
                            onclick="return confirm('Bạn có chắc muốn xóa sản phẩm này?')">Xóa</a>
                        <?php } ?>
                    </div>
                    <a href="lap4.php?action=add&id=<?= $product['maSP'] ?>&page=<?= $page ?>&keyword=<?= urlencode($keyword) ?>"
                        class="amazon-cart-btn ajax-add-to-cart">🛒 Thêm vào giỏ hàng</a>
                </div>
                <?php } ?>
            </div>

            <div class="pagination">
                <?php for($i = 1; $i <= $totalPages; $i++){ ?><a class="<?= $page == $i ? 'active' : '' ?>"
                    href="?page=<?= $i ?>&keyword=<?= urlencode($keyword) ?>"><?= $i ?></a><?php } ?>
            </div>
        </div>
    </div>
    <?php } ?>

    <script>
    document.querySelectorAll('.ajax-add-to-cart').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            fetch(this.getAttribute('href')).then(res => {
                if (res.ok) {
                    const counter = document.getElementById('cart-counter');
                    if (counter) counter.innerText = parseInt(counter.innerText) + 1;
                    alert('Đã thêm sản phẩm vào giỏ hàng thành công! 🎉');
                }
            });
        });
    });
    </script>
</body>

</html>