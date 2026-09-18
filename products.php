<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireRole('customer');
requireCompanyAccess();
$userId = (int)$_SESSION['user_id'];
$companyId = currentCompanyId();
$q = trim($_GET['q'] ?? '');
$category = (int)($_GET['category'] ?? 0);
$st = $conn->prepare("SELECT id,name FROM categories WHERE company_id=? AND status='active' ORDER BY name");
$st->execute([$companyId]);
$cats = $st->fetchAll();
$sql = "SELECT p.id,p.name,p.description,p.price,p.stock,p.unit,p.image,c.name category_name,(SELECT pi.image FROM product_images pi WHERE pi.product_id=p.id ORDER BY pi.is_primary DESC,pi.sort_order,pi.id LIMIT 1) primary_image FROM products p LEFT JOIN categories c ON c.id=p.category_id AND c.company_id=p.company_id WHERE p.company_id=? AND p.status='active'";
$params = [$companyId];
if ($q !== '') {
    $sql .= " AND (p.name LIKE ? OR p.description LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
}
if ($category > 0) {
    $sql .= " AND p.category_id=?";
    $params[] = $category;
}
$sql .= " ORDER BY p.created_at DESC";
$st = $conn->prepare($sql);
$st->execute($params);
$products = $st->fetchAll();
$st = $conn->prepare("SELECT COALESCE(SUM(c.quantity),0) FROM cart c INNER JOIN products p ON p.id=c.product_id WHERE c.user_id=? AND p.company_id=?");
$st->execute([$userId, $companyId]);
$cartCount = (float)$st->fetchColumn();
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Shop Products | GroceryDelivery</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/admin.css" rel="stylesheet">
    <link href="assets/css/customer.css" rel="stylesheet">
</head>

<body>
    <?php include __DIR__ . '/includes/loader.php';
    include __DIR__ . '/includes/customer_sidebar.php'; ?>
    <main class="main-content customer-main">
        <div class="topbar">
            <div class="topbar-left"><button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
                <div>
                    <h1 class="topbar-title">Shop Products</h1>
                    <p class="topbar-subtitle">Fresh groceries ready for delivery.</p>
                </div>
            </div><a href="cart.php" class="btn btn-success"><i class="bi bi-cart3 me-1"></i> Cart <span class="badge text-bg-light ms-1"><?= number_format($cartCount, 2) ?></span></a>
        </div>
        <div class="filter-card p-3 mb-4">
            <form class="row g-2">
                <div class="col-md-7">
                    <div class="input-group"><span class="input-group-text"><i class="bi bi-search"></i></span><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search groceries..."></div>
                </div>
                <div class="col-md-3"><select class="form-select" name="category">
                        <option value="0">All categories</option><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $category === $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-md-2 d-grid"><button class="btn btn-success">Filter</button></div>
            </form>
        </div>
        <div class="row g-4"><?php if (!$products): ?><div class="col-12">
                    <div class="filter-card p-5 text-center"><i class="bi bi-basket fs-1 text-muted"></i>
                        <h4 class="mt-3">No products found</h4>
                        <p class="text-muted mb-0">Try another search or category.</p>
                    </div>
                </div><?php endif; ?><?php foreach ($products as $p): ?><div class="col-xl-3 col-lg-4 col-md-6">
                    <div class="product-card"><?php if (img($p)): ?><img src="<?= e(img($p)) ?>" class="product-thumb" alt="<?= e($p['name']) ?>"><?php else: ?><div class="product-placeholder"><i class="bi bi-image"></i></div><?php endif; ?><div class="p-3">
                            <div class="small text-muted mb-1"><?= e($p['category_name'] ?? 'Uncategorized') ?></div>
                            <h5 class="fw-bold text-truncate"><?= e($p['name']) ?></h5>
                            <div class="price mb-2">GH₵<?= number_format($p['price'], 2) ?></div>
                            <div class="small text-muted mb-3"><?= $p['stock'] > 0 ? e(number_format($p['stock'], 2) . ' ' . $p['unit'] . ' available') : 'Out of stock' ?></div>
                            <div class="d-flex gap-2"><a class="btn btn-outline-secondary flex-fill" href="product.php?id=<?= (int)$p['id'] ?>">View</a><button class="btn btn-success flex-fill add-cart" data-id="<?= (int)$p['id'] ?>" <?= $p['stock'] <= 0 ? 'disabled' : '' ?>><i class="bi bi-cart-plus"></i> Add</button></div>
                        </div>
                    </div>
                </div><?php endforeach; ?></div>
    </main>
    <script>
        document.querySelectorAll('.add-cart').forEach(function(b) {
            b.addEventListener('click', async function() {
                b.disabled = true;
                var fd = new FormData();
                fd.append('product_id', b.dataset.id);
                fd.append('quantity', '1');
                try {
                    var r = await fetch('api/add_to_cart.php', {
                        method: 'POST',
                        body: fd
                    });
                    var j = await r.json();
                    if (j.success) {
                        location.href = 'cart.php'
                    } else {
                        alert(j.message || 'Could not add product.');
                        b.disabled = false
                    }
                } catch (e) {
                    alert('Could not add product.');
                    b.disabled = false
                }
            })
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>