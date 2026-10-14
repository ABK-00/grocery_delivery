<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/paystack.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');
requireCompanyAccess(true);

$userId = currentUserId();
$companyId = currentCompanyId();
$plan = trim((string)($_GET['plan'] ?? ''));

$planSettings = [
    'monthly' => 'subscription_monthly_price',
    'quarterly' => 'subscription_quarterly_price',
    'yearly' => 'subscription_yearly_price'
];

if (!isset($planSettings[$plan])) {
    http_response_code(400);
    exit('Invalid subscription plan.');
}

$stmt = $conn->prepare("
    SELECT
        c.company_name,
        u.email,
        u.name
    FROM companies c
    INNER JOIN users u
        ON u.id = ?
       AND u.company_id = c.id
       AND u.role = 'admin'
    WHERE c.id = ?
      AND c.status <> 'blocked'
    LIMIT 1
");
$stmt->execute([$userId, $companyId]);
$account = $stmt->fetch();

if (!$account) {
    http_response_code(403);
    exit('Subscription access unavailable.');
}

$priceStmt = $conn->prepare("
    SELECT setting_value
    FROM platform_settings
    WHERE setting_key = ?
    LIMIT 1
");
$priceStmt->execute([$planSettings[$plan]]);
$price = (float)($priceStmt->fetchColumn() ?: 0);

if ($price <= 0) {
    throw new RuntimeException('Subscription pricing is not configured for this plan.');
}

$amount = (int) round($price * 100);

$https =
    !empty($_SERVER['HTTPS'])
    && strtolower((string)$_SERVER['HTTPS']) !== 'off';

$scheme = $https ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$callbackUrl =
    $scheme
    . '://'
    . $host
    . BASE_URL
    . '/subscription_callback.php';

try {
    $conn->beginTransaction();

    $pendingStmt = $conn->prepare("
        SELECT
            id,
            reference,
            authorization_url,
            created_at
        FROM subscription_payments
        WHERE company_id = ?
          AND user_id = ?
          AND plan = ?
          AND status = 'pending'
        ORDER BY id DESC
        LIMIT 1
        FOR UPDATE
    ");

    $pendingStmt->execute([
        $companyId,
        $userId,
        $plan
    ]);

    $pending = $pendingStmt->fetch();

    if ($pending) {
        $createdAt = strtotime((string) $pending['created_at']);
        $expired =
            $createdAt !== false
            && (time() - $createdAt) >= PAYMENT_SESSION_TIMEOUT_SECONDS;

        if ($expired) {
            $conn->prepare("
                UPDATE subscription_payments
                SET status = 'failed'
                WHERE id = ?
                  AND status = 'pending'
            ")->execute([
                $pending['id']
            ]);

            $pending = null;
        }
    }

    if (
        $pending
        && !empty($pending['authorization_url'])
    ) {
        $authorizationUrl =
            (string) $pending['authorization_url'];

        $conn->commit();

        header('Location: ' . $authorizationUrl);
        exit;
    }

    if ($pending) {
        $paymentId = (int) $pending['id'];
        $reference = (string) $pending['reference'];

        $conn->prepare("
            UPDATE subscription_payments
            SET amount = ?
            WHERE id = ?
        ")->execute([
            $price,
            $paymentId
        ]);
    } else {
        $reference =
            'SUB-'
            . date('YmdHis')
            . '-'
            . strtoupper(bin2hex(random_bytes(4)));

        $insert = $conn->prepare("
            INSERT INTO subscription_payments (
                company_id,
                user_id,
                plan,
                reference,
                amount,
                currency,
                status
            )
            VALUES (?, ?, ?, ?, ?, 'GHS', 'pending')
        ");
        $insert->execute([
            $companyId,
            $userId,
            $plan,
            $reference,
            $price
        ]);

        $paymentId = (int) $conn->lastInsertId();
    }

    $payload = [
        'email' => $account['email'],
        'amount' => $amount,
        'currency' => 'GHS',
        'reference' => $reference,
        'callback_url' => $callbackUrl,
        'channels' => [
            'card',
            'mobile_money',
            'bank_transfer',
            'ussd'
        ],
        'metadata' => [
            'type' => 'subscription',
            'company_id' => (int)$companyId,
            'company_name' => $account['company_name'],
            'plan' => $plan,
            'user_id' => (int)$userId
        ]
    ];

    $ch = curl_init(PAYSTACK_BASE_URL . '/transaction/initialize');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . PAYSTACK_SECRET_KEY,
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Paystack connection failed: ' . $error);
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($response, true);

    if (
        $httpCode < 200
        || $httpCode >= 300
        || empty($result['status'])
        || empty($result['data']['authorization_url'])
    ) {
        throw new RuntimeException(
            is_array($result)
                ? ($result['message'] ?? 'Unable to initialize subscription payment.')
                : 'Unable to initialize subscription payment.'
        );
    }

    $authorizationUrl = (string)$result['data']['authorization_url'];

    $update = $conn->prepare("
        UPDATE subscription_payments
        SET authorization_url = ?
        WHERE id = ?
    ");
    $update->execute([
        $authorizationUrl,
        $paymentId
    ]);

    writeAuditLog(
        $conn,
        $userId,
        $companyId,
        'subscription.payment_started',
        'subscription_payment',
        $paymentId,
        [
            'plan' => $plan,
            'reference' => $reference,
            'amount' => $price
        ]
    );

    $conn->commit();

    header('Location: ' . $authorizationUrl);
    exit;

} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    http_response_code(500);
    exit(
        'Subscription payment error: '
        . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
    );
}
