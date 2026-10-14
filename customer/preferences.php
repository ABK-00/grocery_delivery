<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('customer');

$userId = currentUserId();

if (empty($_SESSION['preferences_csrf'])) {
    $_SESSION['preferences_csrf'] =
        bin2hex(random_bytes(32));
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string) ($_POST['csrf_token'] ?? '');

    if (
        $csrf === ''
        || !hash_equals(
            $_SESSION['preferences_csrf'],
            $csrf
        )
    ) {
        $error =
            'Your session expired. Refresh and try again.';
    } else {
        $preferredCategories = trim(
            (string) ($_POST['preferred_categories'] ?? '')
        );

        $dislikedItems = trim(
            (string) ($_POST['disliked_items'] ?? '')
        );

        $dietaryNotes = trim(
            (string) ($_POST['dietary_notes'] ?? '')
        );

        $budget = (string) (
            $_POST['budget_preference']
            ?? 'balanced'
        );

        $enabled =
            isset($_POST['personalization_enabled'])
            ? 1
            : 0;

        if (!in_array(
            $budget,
            ['budget', 'balanced', 'premium'],
            true
        )) {
            $error =
                'Choose a valid budget preference.';
        } else {
            $stmt = $conn->prepare("
                INSERT INTO customer_preferences (
                    user_id,
                    preferred_categories,
                    disliked_items,
                    budget_preference,
                    dietary_notes,
                    personalization_enabled
                )
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    preferred_categories = VALUES(preferred_categories),
                    disliked_items = VALUES(disliked_items),
                    budget_preference = VALUES(budget_preference),
                    dietary_notes = VALUES(dietary_notes),
                    personalization_enabled = VALUES(personalization_enabled),
                    updated_at = CURRENT_TIMESTAMP
            ");

            $stmt->execute([
                $userId,
                $preferredCategories ?: null,
                $dislikedItems ?: null,
                $budget,
                $dietaryNotes ?: null,
                $enabled
            ]);

            $success =
                'Your shopping preferences were updated.';
        }
    }
}

$stmt = $conn->prepare("
    SELECT
        preferred_categories,
        disliked_items,
        budget_preference,
        dietary_notes,
        personalization_enabled
    FROM customer_preferences
    WHERE user_id = ?
    LIMIT 1
");

$stmt->execute([$userId]);

$preferences = $stmt->fetch() ?: [
    'preferred_categories' => '',
    'disliked_items' => '',
    'budget_preference' => 'balanced',
    'dietary_notes' => '',
    'personalization_enabled' => 1
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
    <title>Shopping Preferences | GroceryDelivery</title>
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
                    Help the assistant tailor recommendations to you.
                </p>
            </div>
        </div>

        <a
            href="<?= BASE_URL ?>/assistant.php"
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

            <div class="mb-4">
                <label class="form-label fw-semibold">
                    Favorite categories
                </label>
                <textarea
                    name="preferred_categories"
                    class="form-control"
                    rows="3"
                    placeholder="e.g. Fresh produce, beverages, bakery"
                ><?= e($preferences['preferred_categories']) ?></textarea>
                <small class="text-muted">
                    Use plain language. The AI assistant will use this as preference context.
                </small>
            </div>

            <div class="mb-4">
                <label class="form-label fw-semibold">
                    Items you usually avoid
                </label>
                <textarea
                    name="disliked_items"
                    class="form-control"
                    rows="3"
                    placeholder="e.g. Very spicy food, sugary drinks"
                ><?= e($preferences['disliked_items']) ?></textarea>
            </div>

            <div class="mb-4">
                <label class="form-label fw-semibold">
                    Budget preference
                </label>

                <select
                    name="budget_preference"
                    class="form-select"
                >
                    <?php foreach ([
                        'budget' => 'Budget conscious',
                        'balanced' => 'Balanced',
                        'premium' => 'Premium'
                    ] as $value => $label): ?>
                        <option
                            value="<?= e($value) ?>"
                            <?= $preferences['budget_preference'] === $value ? 'selected' : '' ?>
                        >
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-4">
                <label class="form-label fw-semibold">
                    Dietary or shopping notes
                </label>
                <textarea
                    name="dietary_notes"
                    class="form-control"
                    rows="4"
                    placeholder="Optional preferences, allergies, household notes, brand preferences..."
                ><?= e($preferences['dietary_notes']) ?></textarea>
            </div>

            <div class="form-check form-switch mb-4">
                <input
                    type="checkbox"
                    class="form-check-input"
                    id="personalizationEnabled"
                    name="personalization_enabled"
                    value="1"
                    <?= !empty($preferences['personalization_enabled']) ? 'checked' : '' ?>
                >
                <label
                    class="form-check-label"
                    for="personalizationEnabled"
                >
                    Allow the AI assistant to use these preferences for shopping recommendations.
                </label>
            </div>

            <button
                type="submit"
                class="btn btn-success"
            >
                Save Preferences
            </button>

        </form>
    </div>

</main>

</body>
</html>
