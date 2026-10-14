<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin');

$userId = currentUserId();

if (empty($_SESSION['platform_settings_csrf'])) {
    $_SESSION['platform_settings_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

$keys = [
    'platform_name',
    'support_email',
    'subscription_monthly_price',
    'subscription_quarterly_price',
    'subscription_yearly_price',
    'default_delivery_fee',
    'ai_daily_request_limit',
    'ai_enabled'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');

    if ($csrf === '' || !hash_equals($_SESSION['platform_settings_csrf'], $csrf)) {
        $error = 'Your session expired. Refresh and try again.';
    } else {
        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("
                INSERT INTO platform_settings (
                    setting_key,
                    setting_value,
                    updated_by
                )
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    setting_value = VALUES(setting_value),
                    updated_by = VALUES(updated_by),
                    updated_at = CURRENT_TIMESTAMP
            ");

            foreach ($keys as $key) {
                $value = trim((string)($_POST[$key] ?? ''));

                if (str_starts_with($key, 'subscription_') || $key === 'default_delivery_fee') {
                    if ($value !== '' && (!is_numeric($value) || (float)$value < 0)) {
                        throw new RuntimeException('Prices and fees must be valid non-negative numbers.');
                    }
                }

                if ($key === 'ai_enabled') {
                    $value =
                        isset($_POST['ai_enabled'])
                            ? '1'
                            : '0';
                }

                if ($key === 'ai_daily_request_limit') {
                    if (
                        $value !== ''
                        && (
                            filter_var($value, FILTER_VALIDATE_INT) === false
                            || (int) $value < 1
                            || (int) $value > 1000
                        )
                    ) {
                        throw new RuntimeException(
                            'AI daily request limit must be between 1 and 1000.'
                        );
                    }
                }

                $stmt->execute([
                    $key,
                    $value !== '' ? $value : null,
                    $userId
                ]);
            }

            writeAuditLog(
                $conn,
                $userId,
                null,
                'platform.settings_updated',
                'platform_settings',
                null,
                ['keys' => $keys]
            );

            $conn->commit();
            $success = 'Platform settings saved.';
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

$settings = [];
$stmt = $conn->query("
    SELECT setting_key, setting_value
    FROM platform_settings
");
foreach ($stmt->fetchAll() as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Platform Settings | GroceryDelivery</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/admin.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/../includes/super_admin_sidebar.php'; ?>
<?php include __DIR__ . '/../includes/loader.php'; ?>
<main class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" type="button"><i class="bi bi-list"></i></button>
            <div>
                <h1 class="topbar-title">Platform Settings</h1>
                <p class="topbar-subtitle">Control platform identity, support contact and subscription pricing.</p>
            </div>
        </div>
    </div>

    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

    <div class="content-card p-4">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['platform_settings_csrf']) ?>">

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Platform Name</label>
                    <input class="form-control" name="platform_name" value="<?= e($settings['platform_name'] ?? 'GroceryDelivery') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Support Email</label>
                    <input type="email" class="form-control" name="support_email" value="<?= e($settings['support_email'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Monthly Price (GHS)</label>
                    <input type="number" step="0.01" min="0" class="form-control" name="subscription_monthly_price" value="<?= e($settings['subscription_monthly_price'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Quarterly Price (GHS)</label>
                    <input type="number" step="0.01" min="0" class="form-control" name="subscription_quarterly_price" value="<?= e($settings['subscription_quarterly_price'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Yearly Price (GHS)</label>
                    <input type="number" step="0.01" min="0" class="form-control" name="subscription_yearly_price" value="<?= e($settings['subscription_yearly_price'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Default Delivery Fee (GHS)</label>
                    <input type="number" step="0.01" min="0" class="form-control" name="default_delivery_fee" value="<?= e($settings['default_delivery_fee'] ?? '20.00') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">AI Requests / User / Day</label>
                    <input
                        type="number"
                        min="1"
                        max="1000"
                        class="form-control"
                        name="ai_daily_request_limit"
                        value="<?= e($settings['ai_daily_request_limit'] ?? '100') ?>"
                    >
                </div>

                <div class="col-md-4">
                    <label class="form-label d-block">AI Assistant</label>
                    <div class="form-check form-switch mt-2">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            id="aiEnabled"
                            name="ai_enabled"
                            value="1"
                            <?= ($settings['ai_enabled'] ?? '1') !== '0' ? 'checked' : '' ?>
                        >
                        <label
                            class="form-check-label"
                            for="aiEnabled"
                        >
                            Enable AI across the platform
                        </label>
                    </div>
                </div>
            </div>

            <div class="alert alert-info mt-4 mb-3">
                Subscription checkout stays disabled until a price is configured for that plan.
            </div>

            <button class="btn btn-dark" type="submit">
                <i class="bi bi-save me-1"></i> Save Settings
            </button>
        </form>
    </div>
</main>
</body>
</html>
