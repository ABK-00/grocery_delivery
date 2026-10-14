<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/paystack.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireRole('admin');
requireCompanyAccess(true);

$userId = currentUserId();
$companyId = currentCompanyId();
$reference = trim((string)($_GET['reference'] ?? ''));

if ($reference === '') {
    http_response_code(400);
    exit('Payment reference is missing.');
}

try {
    $url =
        PAYSTACK_BASE_URL
        . '/transaction/verify/'
        . rawurlencode($reference);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
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
        throw new RuntimeException('Payment verification failed: ' . $error);
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($response, true);

    if (
        $httpCode < 200
        || $httpCode >= 300
        || empty($result['status'])
        || empty($result['data'])
    ) {
        throw new RuntimeException(
            is_array($result)
                ? ($result['message'] ?? 'Unable to verify subscription payment.')
                : 'Unable to verify subscription payment.'
        );
    }

    $transaction = $result['data'];

    if (($transaction['reference'] ?? '') !== $reference) {
        throw new RuntimeException('Subscription reference verification failed.');
    }

    $conn->beginTransaction();

    $stmt = $conn->prepare("
        SELECT *
        FROM subscription_payments
        WHERE reference = ?
          AND company_id = ?
          AND user_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([
        $reference,
        $companyId,
        $userId
    ]);
    $payment = $stmt->fetch();

    if (!$payment) {
        $conn->rollBack();
        http_response_code(404);
        exit('Subscription payment record not found.');
    }

    if ($payment['status'] === 'paid') {
        $conn->commit();
        header(
            'Location: '
            . BASE_URL
            . '/admin/subscription.php?paid=1'
        );
        exit;
    }

    $expectedAmount =
        (int)round((float)$payment['amount'] * 100);

    $paidAmount =
        (int)($transaction['amount'] ?? 0);

    $currency =
        strtoupper((string)($transaction['currency'] ?? ''));

    if (
        $expectedAmount !== $paidAmount
        || $currency !== 'GHS'
    ) {
        $update = $conn->prepare("
            UPDATE subscription_payments
            SET
                status = 'failed',
                gateway_response = ?
            WHERE id = ?
              AND status <> 'paid'
        ");
        $update->execute([
            json_encode($transaction),
            $payment['id']
        ]);

        writeAuditLog(
            $conn,
            $userId,
            $companyId,
            'subscription.payment_failed',
            'subscription_payment',
            (int)$payment['id'],
            ['reference' => $reference]
        );

        $conn->commit();

        throw new RuntimeException(
            'Subscription payment amount or currency verification failed.'
        );
    }

    if (($transaction['status'] ?? '') !== 'success') {
        $gatewayStatus =
            (string)($transaction['status'] ?? 'failed');

        $localStatus = in_array(
            $gatewayStatus,
            ['pending', 'ongoing', 'processing'],
            true
        )
            ? 'pending'
            : 'failed';

        $update = $conn->prepare("
            UPDATE subscription_payments
            SET
                status = ?,
                gateway_response = ?
            WHERE id = ?
              AND status <> 'paid'
        ");
        $update->execute([
            $localStatus,
            json_encode($transaction),
            $payment['id']
        ]);

        $conn->commit();

        header(
            'Location: '
            . BASE_URL
            . '/admin/subscription.php?payment='
            . urlencode($localStatus)
        );
        exit;
    }

    $months = match ($payment['plan']) {
        'monthly' => 1,
        'quarterly' => 3,
        'yearly' => 12,
        default => throw new RuntimeException(
            'Invalid subscription plan stored for payment.'
        )
    };

    $companyStmt = $conn->prepare("
        SELECT subscription_ends_at
        FROM companies
        WHERE id = ?
        FOR UPDATE
    ");
    $companyStmt->execute([$companyId]);
    $currentEnd = $companyStmt->fetchColumn();

    $now = new DateTimeImmutable('now');
    $baseDate = $now;

    if (
        $currentEnd
        && strtotime((string)$currentEnd) > time()
    ) {
        $baseDate = new DateTimeImmutable(
            (string)$currentEnd
        );
    }

    $newEnd =
        $baseDate->modify('+' . $months . ' months');

    $paidAt = !empty($transaction['paid_at'])
        ? date(
            'Y-m-d H:i:s',
            strtotime((string)$transaction['paid_at'])
        )
        : date('Y-m-d H:i:s');

    $updatePayment = $conn->prepare("
        UPDATE subscription_payments
        SET
            status = 'paid',
            gateway_response = ?,
            paid_at = ?
        WHERE id = ?
          AND status <> 'paid'
    ");
    $updatePayment->execute([
        json_encode($transaction),
        $paidAt,
        $payment['id']
    ]);

    $updateCompany = $conn->prepare("
        UPDATE companies
        SET
            subscription_plan = ?,
            subscription_starts_at = COALESCE(subscription_starts_at, NOW()),
            subscription_ends_at = ?,
            status = CASE
                WHEN status = 'suspended'
                THEN 'active'
                ELSE status
            END
        WHERE id = ?
    ");
    $updateCompany->execute([
        $payment['plan'],
        $newEnd->format('Y-m-d H:i:s'),
        $companyId
    ]);

    createNotification(
        $conn,
        $userId,
        'subscription',
        'Subscription activated',
        ucfirst($payment['plan'])
            . ' subscription is active until '
            . $newEnd->format('d M Y')
            . '.',
        BASE_URL . '/admin/subscription.php'
    );

    writeAuditLog(
        $conn,
        $userId,
        $companyId,
        'subscription.payment_confirmed',
        'subscription_payment',
        (int)$payment['id'],
        [
            'reference' => $reference,
            'plan' => $payment['plan'],
            'subscription_ends_at' =>
                $newEnd->format('Y-m-d H:i:s')
        ]
    );

    $conn->commit();

    header(
        'Location: '
        . BASE_URL
        . '/admin/subscription.php?paid=1'
    );
    exit;

} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    http_response_code(500);
    exit(
        'Subscription verification error: '
        . htmlspecialchars(
            $e->getMessage(),
            ENT_QUOTES,
            'UTF-8'
        )
    );
}
