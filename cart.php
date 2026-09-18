<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireRole('customer');
requireCompanyAccess();
$companyId = currentCompanyId();
$uid = (int)$_SESSION['user_id'];
$st = $conn->prepare("SELECT c.id cart_id,c.quantity,p.id product_id,p.name,p.price,p.stock,p.unit,p.image,(SELECT pi.image FROM product_images pi WHERE pi.product_id=p.id ORDER BY pi.is_primary DESC,pi.sort_order,pi.id LIMIT 1) primary_image FROM cart c JOIN products p ON p.id=c.product_id WHERE c.user_id=? AND p.company_id=? ORDER BY c.updated_at DESC");
$st->execute([$uid, $companyId]);
$items = $st->fetchAll();
$subtotal = 0;
foreach ($items as $i) $subtotal += (float)$i['price'] * (float)$i['quantity'];
$delivery = 20.00;
$total = $subtotal + ($items ? $delivery : 0);
function cimg($p)
{
    $i = $p['primary_image'] ?: $p['image'];
    return $i ? 'assets/images/products/' . rawurlencode($i) : null;
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>My Cart | GroceryDelivery</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/admin.css" rel="stylesheet">
    <link href="assets/css/customer.css" rel="stylesheet">
    <style>
        .cart-img {
            width: 80px;
            height: 70px;
            object-fit: cover;
            border-radius: 10px
        }

        .cart-row {
            background: var(--gd-surface);
            border: 1px solid var(--gd-border);
            border-radius: 15px;
            padding: 16px;
            margin-bottom: 12px
        }

        .summary {
            background: var(--gd-surface);
            border: 1px solid var(--gd-border);
            border-radius: 16px;
            padding: 22px;
            position: sticky;
            top: 20px
        }
    </style>
</head>

<body><?php include __DIR__ . '/includes/loader.php';
        include __DIR__ . '/includes/customer_sidebar.php'; ?><main class="main-content customer-main">
        <div class="topbar">
            <div class="topbar-left"><button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
                <div>
                    <h1 class="topbar-title">My Cart</h1>
                    <p class="topbar-subtitle">Review your groceries before checkout.</p>
                </div>
            </div><a href="products.php" class="btn btn-outline-success"><i class="bi bi-arrow-left"></i> Continue Shopping</a>
        </div>
        <div class="row g-4">
            <div class="col-lg-8"><?php if (!$items): ?><div class="section-card p-5 text-center"><i class="bi bi-cart-x fs-1 text-muted"></i>
                        <h4 class="mt-3">Your cart is empty</h4><a href="products.php" class="btn btn-success mt-2">Start Shopping</a>
                    </div><?php endif; ?><?php foreach ($items as $i): ?><div class="cart-row">
                        <div class="row align-items-center g-3">
                            <div class="col-auto"><?php if (cimg($i)): ?><img class="cart-img" src="<?= e(cimg($i)) ?>" alt=""><?php else: ?><div class="cart-img d-flex align-items-center justify-content-center bg-light"><i class="bi bi-image"></i></div><?php endif; ?></div>
                            <div class="col">
                                <div class="fw-bold"><?= e($i['name']) ?></div><small class="text-muted">GH₵<?= number_format($i['price'], 2) ?> / <?= e($i['unit']) ?></small>
                            </div>
                            <div class="col-sm-3"><input class="form-control qty" type="number" min="0.01" step="0.01" max="<?= e($i['stock']) ?>" value="<?= e($i['quantity']) ?>" data-product="<?= (int)$i['product_id'] ?>"></div>
                            <div class="col-sm-2 fw-bold">GH₵<?= number_format($i['price'] * $i['quantity'], 2) ?></div>
                            <div class="col-auto"><button class="btn btn-outline-danger remove" data-product="<?= (int)$i['product_id'] ?>"><i class="bi bi-trash"></i></button></div>
                        </div>
                    </div><?php endforeach; ?></div>
            <div class="col-lg-4">
                <div class="summary">
                    <h5 class="fw-bold mb-4">Order Summary</h5>
                    <div class="d-flex justify-content-between mb-2"><span>Subtotal</span><strong>GH₵<?= number_format($subtotal, 2) ?></strong></div>
                    <div class="d-flex justify-content-between mb-3"><span>Delivery</span><strong>GH₵<?= number_format($items ? $delivery : 0, 2) ?></strong></div>
                    <hr>
                    <div class="d-flex justify-content-between fs-5 mb-4"><span>Total</span><strong>GH₵<?= number_format($total, 2) ?></strong></div><a href="checkout.php" class="btn btn-success w-100 <?= !$items ? 'disabled' : '' ?>"><i class="bi bi-lock me-1"></i> Proceed to Checkout</a>
                </div>
            </div>
        </div>
    </main>
    <script>
        async function post(url, id, q) {
            var f = new FormData();
            f.append('product_id', id);
            if (q !== undefined) f.append('quantity', q);
            var r = await fetch(url, {
                method: 'POST',
                body: f
            });
            return r.json()
        }
        document.querySelectorAll('.qty').forEach(x => x.addEventListener('change', async () => {
            try {
                var j = await post('api/update_cart.php', x.dataset.product, x.value);
                if (j.success) location.reload();
                else alert(j.message || 'Unable to update cart')
            } catch (e) {
                alert('Unable to update cart')
            }
        }));
        document.querySelectorAll('.remove').forEach(x => x.addEventListener('click', async () => {
            if (!confirm('Remove this item from your cart?')) return;
            try {
                var j = await post('api/remove_from_cart.php', x.dataset.product);
                if (j.success) location.reload();
                else alert(j.message || 'Unable to remove item')
            } catch (e) {
                alert('Unable to remove item')
            }
        }));
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>