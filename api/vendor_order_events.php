<?php

declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();
requireCompanyAccess();

$role = (string) ($_SESSION['role'] ?? '');

if (!in_array($role, ['admin', 'staff'], true)) {
    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => 'Vendor access required.'
    ]);
    exit;
}

$companyId = currentCompanyId();
$afterId = max(
    0,
    (int) ($_GET['after_id'] ?? 0)
);

$stmt = $conn->prepare("
    SELECT
        id,
        channel,
        event_type,
        payload,
        created_at
    FROM realtime_events
    WHERE company_id = ?
      AND channel = 'vendor:orders'
      AND id > ?
    ORDER BY id ASC
    LIMIT 100
");

$stmt->execute([
    $companyId,
    $afterId
]);

$events = [];

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $events[] = [
        'id' => (int) $row['id'],
        'channel' => $row['channel'],
        'event_type' => $row['event_type'],
        'payload' => json_decode(
            (string) $row['payload'],
            true
        ),
        'created_at' => $row['created_at']
    ];
}

echo json_encode([
    'success' => true,
    'channel' => 'vendor:orders',
    'events' => $events,
    'last_id' => $events
        ? (int) end($events)['id']
        : $afterId
], JSON_UNESCAPED_SLASHES);
