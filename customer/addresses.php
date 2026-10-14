<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('customer');

$userId = currentUserId();

if (empty($_SESSION['address_csrf'])) {
    $_SESSION['address_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $action = (string) ($_POST['action'] ?? '');

    if (
        $csrf === ''
        || !hash_equals($_SESSION['address_csrf'], $csrf)
    ) {
        $error = 'Your session expired. Refresh and try again.';
    } else {
        try {
            $conn->beginTransaction();

            if ($action === 'save') {
                $addressId = (int) ($_POST['address_id'] ?? 0);
                $label = trim((string) ($_POST['label'] ?? 'Home'));
                $recipient = trim((string) ($_POST['recipient_name'] ?? ''));
                $phone = trim((string) ($_POST['phone'] ?? ''));
                $address = trim((string) ($_POST['address'] ?? ''));
                $isDefault = isset($_POST['is_default']) ? 1 : 0;

                if ($label === '' || $recipient === '' || $phone === '' || $address === '') {
                    throw new RuntimeException('Complete all required address fields.');
                }

                if ($isDefault) {
                    $clear = $conn->prepare("
                        UPDATE customer_addresses
                        SET is_default = 0
                        WHERE user_id = ?
                    ");
                    $clear->execute([$userId]);
                }

                if ($addressId > 0) {
                    $stmt = $conn->prepare("
                        UPDATE customer_addresses
                        SET
                            label = ?,
                            recipient_name = ?,
                            phone = ?,
                            address = ?,
                            is_default = ?
                        WHERE id = ?
                          AND user_id = ?
                    ");
                    $stmt->execute([
                        mb_substr($label, 0, 60),
                        mb_substr($recipient, 0, 120),
                        mb_substr($phone, 0, 30),
                        $address,
                        $isDefault,
                        $addressId,
                        $userId
                    ]);

                    if ($stmt->rowCount() === 0) {
                        $exists = $conn->prepare("
                            SELECT id
                            FROM customer_addresses
                            WHERE id = ? AND user_id = ?
                        ");
                        $exists->execute([$addressId, $userId]);
                        if (!$exists->fetch()) {
                            throw new RuntimeException('Address not found.');
                        }
                    }
                } else {
                    $count = $conn->prepare("
                        SELECT COUNT(*)
                        FROM customer_addresses
                        WHERE user_id = ?
                    ");
                    $count->execute([$userId]);
                    $existingCount = (int) $count->fetchColumn();

                    if ($existingCount === 0) {
                        $isDefault = 1;
                    }

                    $stmt = $conn->prepare("
                        INSERT INTO customer_addresses (
                            user_id,
                            label,
                            recipient_name,
                            phone,
                            address,
                            is_default
                        )
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $userId,
                        mb_substr($label, 0, 60),
                        mb_substr($recipient, 0, 120),
                        mb_substr($phone, 0, 30),
                        $address,
                        $isDefault
                    ]);
                }

                $success = 'Address saved successfully.';
            } elseif ($action === 'default') {
                $addressId = (int) ($_POST['address_id'] ?? 0);

                $check = $conn->prepare("
                    SELECT id
                    FROM customer_addresses
                    WHERE id = ?
                      AND user_id = ?
                    FOR UPDATE
                ");
                $check->execute([$addressId, $userId]);

                if (!$check->fetch()) {
                    throw new RuntimeException('Address not found.');
                }

                $conn->prepare("
                    UPDATE customer_addresses
                    SET is_default = 0
                    WHERE user_id = ?
                ")->execute([$userId]);

                $conn->prepare("
                    UPDATE customer_addresses
                    SET is_default = 1
                    WHERE id = ?
                      AND user_id = ?
                ")->execute([$addressId, $userId]);

                $success = 'Default address updated.';
            } elseif ($action === 'delete') {
                $addressId = (int) ($_POST['address_id'] ?? 0);

                $stmt = $conn->prepare("
                    DELETE FROM customer_addresses
                    WHERE id = ?
                      AND user_id = ?
                ");
                $stmt->execute([$addressId, $userId]);

                $success = 'Address removed.';
            } else {
                throw new RuntimeException('Invalid address action.');
            }

            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            $error = $e->getMessage();
        }
    }
}

$stmt = $conn->prepare("
    SELECT *
    FROM customer_addresses
    WHERE user_id = ?
    ORDER BY is_default DESC, updated_at DESC
");
$stmt->execute([$userId]);
$addresses = $stmt->fetchAll();

$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
$editAddress = null;

if ($editId) {
    $stmt = $conn->prepare("
        SELECT *
        FROM customer_addresses
        WHERE id = ?
          AND user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$editId, $userId]);
    $editAddress = $stmt->fetch() ?: null;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Saved Addresses | GroceryDelivery</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/admin.css" rel="stylesheet">
    <link href="../assets/css/customer.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/../includes/customer_sidebar.php'; ?>
<?php include __DIR__ . '/../includes/loader.php'; ?>

<main class="customer-main main-content">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" type="button"><i class="bi bi-list"></i></button>
            <div>
                <h1 class="topbar-title">Saved Addresses</h1>
                <p class="topbar-subtitle">Manage delivery locations for faster checkout.</p>
            </div>
        </div>
    </div>

    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="section-card p-4">
                <h5 class="fw-bold mb-3"><?= $editAddress ? 'Edit Address' : 'Add Address' ?></h5>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['address_csrf']) ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="address_id" value="<?= (int)($editAddress['id'] ?? 0) ?>">

                    <div class="mb-3">
                        <label class="form-label">Label</label>
                        <input class="form-control" name="label" maxlength="60" required value="<?= e($editAddress['label'] ?? 'Home') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Recipient Name</label>
                        <input class="form-control" name="recipient_name" maxlength="120" required value="<?= e($editAddress['recipient_name'] ?? ($_SESSION['name'] ?? '')) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone</label>
                        <input class="form-control" name="phone" maxlength="30" required value="<?= e($editAddress['phone'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Full Address</label>
                        <textarea class="form-control" name="address" rows="4" required><?= e($editAddress['address'] ?? '') ?></textarea>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="is_default" id="isDefault" <?= !empty($editAddress['is_default']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="isDefault">Use as default address</label>
                    </div>
                    <button class="btn btn-success" type="submit">
                        <i class="bi bi-check2-circle me-1"></i> Save Address
                    </button>
                    <?php if ($editAddress): ?>
                        <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>/customer/addresses.php">Cancel</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="col-lg-7">
            <?php if (!$addresses): ?>
                <div class="section-card p-5 text-center">
                    <i class="bi bi-geo-alt fs-1 text-muted"></i>
                    <h5 class="mt-3">No saved addresses yet</h5>
                </div>
            <?php endif; ?>

            <?php foreach ($addresses as $item): ?>
                <div class="section-card p-4 mb-3">
                    <div class="d-flex justify-content-between gap-3">
                        <div>
                            <div class="fw-bold">
                                <?= e($item['label']) ?>
                                <?php if ($item['is_default']): ?>
                                    <span class="badge bg-success ms-2">Default</span>
                                <?php endif; ?>
                            </div>
                            <div class="mt-2"><?= nl2br(e($item['address'])) ?></div>
                            <div class="text-muted small mt-2">
                                <?= e($item['recipient_name']) ?> · <?= e($item['phone']) ?>
                            </div>
                        </div>
                        <div class="d-flex gap-2 align-items-start">
                            <a class="btn btn-sm btn-outline-secondary" href="?edit=<?= (int)$item['id'] ?>">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <?php if (!$item['is_default']): ?>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['address_csrf']) ?>">
                                    <input type="hidden" name="action" value="default">
                                    <input type="hidden" name="address_id" value="<?= (int)$item['id'] ?>">
                                    <button class="btn btn-sm btn-outline-success">Default</button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" onsubmit="return confirm('Delete this address?');">
                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['address_csrf']) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="address_id" value="<?= (int)$item['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</main>
</body>
</html>
