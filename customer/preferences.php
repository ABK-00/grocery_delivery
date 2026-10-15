<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('customer');

$userId = (int) currentUserId();

if (empty($_SESSION['preferences_csrf'])) {
    $_SESSION['preferences_csrf'] =
        bin2hex(random_bytes(32));
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf =
        (string) ($_POST['csrf_token'] ?? '');

    if (
        $csrf === ''
        || !hash_equals(
            (string) $_SESSION['preferences_csrf'],
            $csrf
        )
    ) {
        $error =
            'Your session expired. Refresh and try again.';
    } else {
        $preferredCategories = trim(
            (string) ($_POST['preferred_categories'] ?? '')
        );

        $preferredStores = trim(
            (string) ($_POST['preferred_stores'] ?? '')
        );

        $dietaryPreferences = trim(
            (string) ($_POST['dietary_preferences'] ?? '')
        );

        $dislikedProducts = trim(
            (string) ($_POST['disliked_products'] ?? '')
        );

        $preferredPaymentMethod = trim(
            (string) ($_POST['preferred_payment_method'] ?? '')
        );

        $preferredLanguage = trim(
            (string) ($_POST['preferred_language'] ?? 'English')
        );

        $allowPersonalization =
            isset($_POST['allow_personalization'])
                ? 1
                : 0;

        $allowMarketing =
            isset($_POST['allow_marketing'])
                ? 1
                : 0;

        $allowLocationPersonalization =
            isset($_POST['allow_location_personalization'])
                ? 1
                : 0;

        try {
            $stmt = $conn->prepare("
                INSERT INTO customer_preferences (
                    user_id,
                    preferred_categories,
                    preferred_stores,
                    dietary_preferences,
                    disliked_products,
                    preferred_payment_method,
                    allow_personalization,
                    allow_marketing,
                    allow_location_personalization,
                    preferred_language
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    preferred_categories =
                        VALUES(preferred_categories),
                    preferred_stores =
                        VALUES(preferred_stores),
                    dietary_preferences =
                        VALUES(dietary_preferences),
                    disliked_products =
                        VALUES(disliked_products),
                    preferred_payment_method =
                        VALUES(preferred_payment_method),
                    allow_personalization =
                        VALUES(allow_personalization),
                    allow_marketing =
                        VALUES(allow_marketing),
                    allow_location_personalization =
                        VALUES(allow_location_personalization),
                    preferred_language =
                        VALUES(preferred_language),
                    updated_at = CURRENT_TIMESTAMP
            ");

            $stmt->execute([
                $userId,
                $preferredCategories !== ''
                    ? $preferredCategories
                    : null,
                $preferredStores !== ''
                    ? $preferredStores
                    : null,
                $dietaryPreferences !== ''
                    ? $dietaryPreferences
                    : null,
                $dislikedProducts !== ''
                    ? $dislikedProducts
                    : null,
                $preferredPaymentMethod !== ''
                    ? $preferredPaymentMethod
                    : null,
                $allowPersonalization,
                $allowMarketing,
                $allowLocationPersonalization,
                $preferredLanguage !== ''
                    ? $preferredLanguage
                    : 'English'
            ]);

            $success =
                'Your shopping preferences were updated.';
        } catch (Throwable $e) {
            $error =
                'Unable to update preferences right now.';
        }
    }
}

$stmt = $conn->prepare("
    SELECT
        preferred_categories,
        preferred_stores,
        dietary_preferences,
        disliked_products,
        preferred_payment_method,
        allow_personalization,
        allow_marketing,
        allow_location_personalization,
        preferred_language,
        ai_profile_summary
    FROM customer_preferences
    WHERE user_id = ?
    LIMIT 1
");

$stmt->execute([
    $userId
]);

$preferences = $stmt->fetch() ?: [
    'preferred_categories' => '',
    'preferred_stores' => '',
    'dietary_preferences' => '',
    'disliked_products' => '',
    'preferred_payment_method' => '',
    'allow_personalization' => 1,
    'allow_marketing' => 0,
    'allow_location_personalization' => 0,
    'preferred_language' => 'English',
    'ai_profile_summary' => ''
];

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        Shopping Preferences | GroceryDelivery
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <link
        href="../assets/css/admin.css"
        rel="stylesheet"
    >

    <link
        href="../assets/css/customer.css"
        rel="stylesheet"
    >
</head>

<body>

<?php include __DIR__ . '/../includes/customer_sidebar.php'; ?>
<?php include __DIR__ . '/../includes/loader.php'; ?>

<main class="customer-main main-content">

    <div class="topbar">

        <div class="topbar-left">

            <button
                class="sidebar-toggle"
                id="sidebarToggle"
                type="button"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>
                <h1 class="topbar-title">
                    Shopping Preferences
                </h1>

                <p class="topbar-subtitle">
                    Control what Somame AI may use to personalize shopping help.
                </p>
            </div>

        </div>

        <a
            href="<?= BASE_URL ?>/assistant.php?mode=personalization"
            class="btn btn-success"
        >
            <i class="bi bi-stars"></i>
            Ask AI
        </a>

    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <?= e($success) ?>
        </div>
    <?php endif; ?>

    <div class="section-card p-4">

        <form method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION['preferences_csrf']) ?>"
            >

            <div class="row g-4">

                <div class="col-lg-6">
                    <label class="form-label fw-semibold">
                        Favorite categories
                    </label>

                    <textarea
                        name="preferred_categories"
                        class="form-control"
                        rows="3"
                        placeholder="Fresh produce, beverages, bakery..."
                    ><?= e($preferences['preferred_categories']) ?></textarea>
                </div>

                <div class="col-lg-6">
                    <label class="form-label fw-semibold">
                        Preferred stores
                    </label>

                    <textarea
                        name="preferred_stores"
                        class="form-control"
                        rows="3"
                        placeholder="Stores or vendors you usually prefer..."
                    ><?= e($preferences['preferred_stores']) ?></textarea>
                </div>

                <div class="col-lg-6">
                    <label class="form-label fw-semibold">
                        Dietary preferences
                    </label>

                    <textarea
                        name="dietary_preferences"
                        class="form-control"
                        rows="3"
                        placeholder="Vegetarian, low sugar, halal-friendly..."
                    ><?= e($preferences['dietary_preferences']) ?></textarea>
                </div>

                <div class="col-lg-6">
                    <label class="form-label fw-semibold">
                        Products you avoid
                    </label>

                    <textarea
                        name="disliked_products"
                        class="form-control"
                        rows="3"
                        placeholder="Products or ingredients you usually avoid..."
                    ><?= e($preferences['disliked_products']) ?></textarea>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">
                        Preferred payment method
                    </label>

                    <select
                        name="preferred_payment_method"
                        class="form-select"
                    >
                        <?php foreach ([
                            '' => 'No preference',
                            'mobile_money' => 'Mobile Money',
                            'card' => 'Bank Card'
                        ] as $value => $label): ?>
                            <option
                                value="<?= e($value) ?>"
                                <?= (string) $preferences['preferred_payment_method'] === $value
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">
                        Preferred language
                    </label>

                    <input
                        type="text"
                        name="preferred_language"
                        class="form-control"
                        maxlength="60"
                        value="<?= e($preferences['preferred_language'] ?: 'English') ?>"
                    >
                </div>

                <div class="col-12">

                    <div class="border rounded-3 p-3">

                        <div class="form-check form-switch mb-3">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                id="allowPersonalization"
                                name="allow_personalization"
                                value="1"
                                <?= !empty($preferences['allow_personalization'])
                                    ? 'checked'
                                    : '' ?>
                            >

                            <label
                                class="form-check-label fw-semibold"
                                for="allowPersonalization"
                            >
                                Allow personalized AI shopping recommendations
                            </label>
                        </div>

                        <div class="form-check form-switch mb-3">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                id="allowLocation"
                                name="allow_location_personalization"
                                value="1"
                                <?= !empty($preferences['allow_location_personalization'])
                                    ? 'checked'
                                    : '' ?>
                            >

                            <label
                                class="form-check-label"
                                for="allowLocation"
                            >
                                Allow location-based personalization
                            </label>
                        </div>

                        <div class="form-check form-switch">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                id="allowMarketing"
                                name="allow_marketing"
                                value="1"
                                <?= !empty($preferences['allow_marketing'])
                                    ? 'checked'
                                    : '' ?>
                            >

                            <label
                                class="form-check-label"
                                for="allowMarketing"
                            >
                                Allow marketing personalization
                            </label>
                        </div>

                    </div>

                </div>

                <?php if (!empty($preferences['ai_profile_summary'])): ?>

                    <div class="col-12">
                        <div class="alert alert-light border mb-0">
                            <div class="fw-semibold mb-1">
                                Current AI profile summary
                            </div>

                            <div class="small text-muted">
                                <?= e($preferences['ai_profile_summary']) ?>
                            </div>
                        </div>
                    </div>

                <?php endif; ?>

                <div class="col-12">

                    <button
                        type="submit"
                        class="btn btn-success"
                    >
                        <i class="bi bi-check2-circle me-1"></i>
                        Save Preferences
                    </button>

                </div>

            </div>

        </form>

    </div>

</main>

</body>
</html>
