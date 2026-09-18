<?php

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../includes/functions.php";

requireRole("admin");
requireCompanyAccess();
$companyId = currentCompanyId();

$message = "";
$messageType = "success";

/* Company-safe delivery update */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_delivery"])) {
    $deliveryId = (int)($_POST["delivery_id"] ?? 0);
    $partnerId = (int)($_POST["partner_id"] ?? 0);
    $status = trim($_POST["status"] ?? "");
    $allowedStatuses = ['assigned','picked_up','out_for_delivery','delivered','cancelled'];

    if ($deliveryId > 0 && in_array($status, $allowedStatuses, true)) {
        try {
            $conn->beginTransaction();

            $ownStmt = $conn->prepare("SELECT d.id, d.delivery_partner_id FROM deliveries d JOIN orders o ON o.id=d.order_id WHERE d.id=? AND o.company_id=? FOR UPDATE");
            $ownStmt->execute([$deliveryId, $companyId]);
            $ownedDelivery = $ownStmt->fetch();
            if (!$ownedDelivery) throw new RuntimeException("Delivery does not belong to your company.");

            if ($partnerId > 0) {
                $partnerStmt = $conn->prepare("SELECT dp.id FROM delivery_partners dp JOIN users u ON u.id=dp.user_id WHERE dp.id=? AND u.company_id=? AND u.role='delivery_partner' AND u.status='active' LIMIT 1");
                $partnerStmt->execute([$partnerId, $companyId]);
                if (!$partnerStmt->fetch()) throw new RuntimeException("Invalid delivery partner for this company.");
            }

            $oldPartnerId = (int)($ownedDelivery['delivery_partner_id'] ?? 0);
            $stmt = $conn->prepare("UPDATE deliveries SET delivery_partner_id=?, status=? WHERE id=?");
            $stmt->execute([$partnerId ?: null, $status, $deliveryId]);

            if ($oldPartnerId && $oldPartnerId !== $partnerId) {
                $conn->prepare("UPDATE delivery_partners dp JOIN users u ON u.id=dp.user_id SET dp.status='available' WHERE dp.id=? AND u.company_id=?")->execute([$oldPartnerId, $companyId]);
            }
            if ($partnerId > 0) {
                $partnerStatus = in_array($status, ['delivered','cancelled'], true) ? 'available' : 'busy';
                $conn->prepare("UPDATE delivery_partners dp JOIN users u ON u.id=dp.user_id SET dp.status=? WHERE dp.id=? AND u.company_id=?")->execute([$partnerStatus, $partnerId, $companyId]);
            }

            $conn->commit();
            $message = "Delivery status updated successfully.";
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $message = $e instanceof RuntimeException ? $e->getMessage() : "Failed to update delivery.";
            $messageType = "danger";
        }
    } else {
        $message = "Invalid delivery update.";
        $messageType = "danger";
    }
}

$statusFilter = trim($_GET["status"] ?? "");
$search = trim($_GET["search"] ?? "");
$sql = "SELECT d.*, o.order_number,o.total_amount,o.delivery_address,o.delivery_phone,u.name AS customer_name,pu.name AS partner_name
        FROM deliveries d
        JOIN orders o ON o.id=d.order_id
        JOIN users u ON u.id=o.user_id
        LEFT JOIN delivery_partners dp ON dp.id=d.delivery_partner_id
        LEFT JOIN users pu ON pu.id=dp.user_id
        WHERE o.company_id=?";
$params = [$companyId];
if ($search !== '') { $sql .= " AND (o.order_number LIKE ? OR u.name LIKE ? OR pu.name LIKE ?)"; $like="%{$search}%"; array_push($params,$like,$like,$like); }
if ($statusFilter !== '') { $sql .= " AND d.status=?"; $params[]=$statusFilter; }
$sql .= " ORDER BY d.id DESC";
$stmt=$conn->prepare($sql); $stmt->execute($params); $deliveries=$stmt->fetchAll();

function companyDeliveryCount(PDO $conn, int $companyId, ?string $where=null): int {
    $sql="SELECT COUNT(*) FROM deliveries d JOIN orders o ON o.id=d.order_id WHERE o.company_id=?" . ($where ? " AND $where" : "");
    $st=$conn->prepare($sql); $st->execute([$companyId]); return (int)$st->fetchColumn();
}
$totalDeliveries=companyDeliveryCount($conn,$companyId);
$pendingDeliveries=companyDeliveryCount($conn,$companyId,"d.status IN ('pending','assigned')");
$outDeliveries=companyDeliveryCount($conn,$companyId,"d.status='out_for_delivery'");
$completedDeliveries=companyDeliveryCount($conn,$companyId,"d.status='delivered'");

$partnersStmt=$conn->prepare("SELECT dp.id,u.name FROM delivery_partners dp JOIN users u ON u.id=dp.user_id WHERE u.company_id=? AND u.role='delivery_partner' AND u.status='active' ORDER BY u.name ASC");
$partnersStmt->execute([$companyId]); $partners=$partnersStmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deliveries Management | Grocery Delivery</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/admin.css" rel="stylesheet">
</head>
<body>

<?php require_once __DIR__ . "/../includes/admin_sidebar.php"; ?>
<?php include "../includes/loader.php"; ?>


<main class="main-content">

    <!-- TOPBAR -->
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" type="button">
                <i class="bi bi-list"></i>
            </button>
            <div>
                <h1 class="topbar-title">Deliveries Management</h1>
                <p class="topbar-subtitle">Assign delivery partners, monitor order dispatches and track delivery statuses.</p>
            </div>
        </div>
    </div>

    <!-- ALERT -->
    <?php if ($message): ?>
        <div class="alert alert-<?= e($messageType) ?> alert-dismissible fade show mb-4">
            <?= e($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- STATS -->
    <div class="row g-4 mb-4">
        <div class="col-lg-3 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Total Deliveries</div>
                        <div class="stat-number"><?= $totalDeliveries ?></div>
                    </div>
                    <div class="stat-icon"><i class="bi bi-truck"></i></div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Pending Dispatch</div>
                        <div class="stat-number"><?= $pendingDeliveries ?></div>
                    </div>
                    <div class="stat-icon"><i class="bi bi-clock-history"></i></div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Out for Delivery</div>
                        <div class="stat-number"><?= $outDeliveries ?></div>
                    </div>
                    <div class="stat-icon"><i class="bi bi-bicycle"></i></div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Delivered</div>
                        <div class="stat-number"><?= $completedDeliveries ?></div>
                    </div>
                    <div class="stat-icon"><i class="bi bi-check-circle-fill"></i></div>
                </div>
            </div>
        </div>
    </div>

    <!-- TABLE CARD -->
    <div class="content-card">
        <div class="p-3 border-bottom">
            <form method="GET" class="row g-2">
                <div class="col-md-6">
                    <input type="text" name="search" class="form-control" placeholder="Search order #, customer, partner..." value="<?= e($search) ?>">
                </div>
                <div class="col-md-4">
                    <select name="status" class="form-select">
                        <option value="">All Statuses</option>
                        <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="assigned" <?= $statusFilter === 'assigned' ? 'selected' : '' ?>>Assigned</option>
                        <option value="out_for_delivery" <?= $statusFilter === 'out_for_delivery' ? 'selected' : '' ?>>Out for Delivery</option>
                        <option value="delivered" <?= $statusFilter === 'delivered' ? 'selected' : '' ?>>Delivered</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-success w-100"><i class="bi bi-filter"></i> Filter</button>
                </div>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Customer</th>
                        <th>Address</th>
                        <th>Delivery Partner</th>
                        <th>Status</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($deliveries)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">No delivery records found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($deliveries as $del): ?>
                            <tr>
                                <td class="fw-bold">#<?= e($del["order_number"] ?? $del["order_id"]) ?></td>
                                <td><?= e($del["customer_name"] ?? "Customer") ?></td>
                                <td>
                                    <small><?= e($del["delivery_address"] ?? "Store Pickup") ?></small>
                                </td>
                                <td>
                                    <?php if (!empty($del["partner_name"])): ?>
                                        <span class="fw-bold text-dark"><i class="bi bi-person me-1"></i><?= e($del["partner_name"]) ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Unassigned</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $stClass = "badge-pending";
                                    if ($del["status"] === "delivered") $stClass = "badge-delivered";
                                    elseif ($del["status"] === "out_for_delivery") $stClass = "badge-out";
                                    elseif ($del["status"] === "assigned") $stClass = "badge-confirmed";
                                    ?>
                                    <span class="badge <?= $stClass ?>"><?= ucfirst(str_replace('_', ' ', $del["status"])) ?></span>
                                </td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#editModal<?= $del["id"] ?>">
                                        <i class="bi bi-pencil-square"></i> Manage
                                    </button>
                                </td>
                            </tr>

                            <!-- EDIT MODAL -->
                            <div class="modal fade" id="editModal<?= $del["id"] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form method="POST">
                                            <input type="hidden" name="delivery_id" value="<?= $del["id"] ?>">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Manage Delivery #<?= e($del["order_number"] ?? $del["order_id"]) ?></h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="mb-3">
                                                    <label class="form-label">Assigned Delivery Partner</label>
                                                    <select name="partner_id" class="form-select">
                                                        <option value="0">-- Select Partner --</option>
                                                        <?php foreach ($partners as $p): ?>
                                                            <option value="<?= $p["id"] ?>" <?= ($del["delivery_partner_id"] == $p["id"]) ? "selected" : "" ?>>
                                                                <?= e($p["name"]) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Delivery Status</label>
                                                    <select name="status" class="form-select" required>
                                                        <option value="pending" <?= $del["status"] === "pending" ? "selected" : "" ?>>Pending</option>
                                                        <option value="assigned" <?= $del["status"] === "assigned" ? "selected" : "" ?>>Assigned</option>
                                                        <option value="out_for_delivery" <?= $del["status"] === "out_for_delivery" ? "selected" : "" ?>>Out for Delivery</option>
                                                        <option value="delivered" <?= $del["status"] === "delivered" ? "selected" : "" ?>>Delivered</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" name="update_delivery" class="btn btn-success">Save Changes</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
