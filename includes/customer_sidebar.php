<?php
$currentPage = basename($_SERVER['PHP_SELF']);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$customerName = $_SESSION['name'] ?? $_SESSION['user_name'] ?? 'Customer';
$inCustomerDir = basename(dirname($_SERVER['PHP_SELF'])) === 'customer';
$rootPrefix = $inCustomerDir ? '../' : '';
$customerPrefix = $inCustomerDir ? '' : 'customer/';
?>
<aside class="sidebar" id="customerSidebar">
    <div class="sidebar-brand"><a href="<?= BASE_URL ?>/customer/dashboard.php"><div class="brand-icon"><i class="bi bi-basket2-fill"></i></div><div class="brand-text">Grocery<span>Delivery</span></div></a></div>
    <div style="padding:14px 16px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:11px"><div style="width:38px;height:38px;min-width:38px;border-radius:50%;background:#22c55e;color:#111827;display:flex;align-items:center;justify-content:center;font-size:17px;font-weight:700"><?= htmlspecialchars(strtoupper(substr($customerName,0,1))) ?></div><div style="min-width:0;overflow:hidden"><div style="font-weight:600;font-size:13px;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($customerName) ?></div><div style="font-size:11px;color:#6b7280">Customer Portal</div></div></div>
    <div class="sidebar-nav">
        <div class="sidebar-section-title">Main Menu</div>
        <a href="<?= BASE_URL ?>/customer/dashboard.php" class="nav-link <?= $currentPage==='dashboard.php'?'active':'' ?>"><i class="bi bi-grid-1x2-fill"></i><span>Dashboard</span></a>
        <a href="<?= BASE_URL ?>/products.php" class="nav-link <?= in_array($currentPage,['products.php','product.php'])?'active':'' ?>"><i class="bi bi-shop"></i><span>Shop Products</span></a>
        <a href="<?= BASE_URL ?>/cart.php" class="nav-link <?= $currentPage==='cart.php'?'active':'' ?>"><i class="bi bi-cart3"></i><span>My Cart</span></a>
        <a href="<?= BASE_URL ?>/orders.php" class="nav-link <?= in_array($currentPage,['orders.php','order_details.php','order_success.php'])?'active':'' ?>"><i class="bi bi-bag-check"></i><span>My Orders</span></a>
        <a href="<?= BASE_URL ?>/customer/track_order.php" class="nav-link <?= $currentPage==='track_order.php'?'active':'' ?>"><i class="bi bi-geo-alt-fill"></i><span>Track Delivery</span></a>
        <div class="sidebar-section-title" style="margin-top:12px">Account</div>
        <a href="<?= BASE_URL ?>/customer/profile.php" class="nav-link <?= $currentPage==='profile.php'?'active':'' ?>"><i class="bi bi-person-circle"></i><span>My Profile</span></a>
        <a href="<?= BASE_URL ?>/customer/addresses.php" class="nav-link <?= $currentPage==='addresses.php'?'active':'' ?>"><i class="bi bi-geo-alt"></i><span>Saved Addresses</span></a>
        <a href="<?= BASE_URL ?>/customer/preferences.php" class="nav-link <?= $currentPage==='preferences.php'?'active':'' ?>"><i class="bi bi-sliders"></i><span>Shopping Preferences</span></a>
        <a href="<?= BASE_URL ?>/assistant.php" class="nav-link <?= $currentPage==='assistant.php'?'active':'' ?>"><i class="bi bi-stars"></i><span>AI Assistant</span></a>
        <a href="<?= BASE_URL ?>/notifications.php" class="nav-link <?= $currentPage==='notifications.php'?'active':'' ?>"><i class="bi bi-bell"></i><span>Notifications</span></a>
        <a href="<?= BASE_URL ?>/support.php" class="nav-link <?= in_array($currentPage,['support.php','support_ticket.php'])?'active':'' ?>"><i class="bi bi-life-preserver"></i><span>Support</span></a>
        <button type="button" class="nav-link theme-toggle" data-theme-toggle><i class="bi bi-moon-stars-fill theme-icon"></i><span class="theme-label">Dark Theme</span><small class="theme-state">Off</small></button>
    </div>
    <div class="sidebar-footer"><a href="<?= BASE_URL ?>/logout.php" class="nav-link"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a></div>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script>document.addEventListener('DOMContentLoaded',function(){var s=document.getElementById('customerSidebar'),o=document.getElementById('sidebarOverlay');['sidebarToggle','customerMenuBtn'].forEach(function(id){var b=document.getElementById(id);if(b&&s)b.addEventListener('click',function(){s.classList.toggle('show');if(o)o.classList.toggle('show')})});if(o&&s)o.addEventListener('click',function(){s.classList.remove('show');o.classList.remove('show')});if(s)s.querySelectorAll('.nav-link').forEach(function(a){a.addEventListener('click',function(){if(window.innerWidth<=900&&!a.hasAttribute('data-theme-toggle')){s.classList.remove('show');if(o)o.classList.remove('show')}})})});</script>

<script src="<?= BASE_URL ?>/assets/js/theme.js"></script>
