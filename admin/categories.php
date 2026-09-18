<?php

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../includes/functions.php";

requireRole("admin");
requireCompanyAccess();
$companyId = currentCompanyId();

$message = "";
$messageType = "success";

/* =========================
   ADD CATEGORY
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_category"])) {

    $name = trim($_POST["name"] ?? "");
    $description = trim($_POST["description"] ?? "");

    if ($name === "") {
        $message = "Category name is required.";
        $messageType = "danger";
    } else {

        $check = $conn->prepare("
            SELECT id
            FROM categories
            WHERE name = ?
            LIMIT 1
        ");

        $check->execute([$companyId, $name]);

        if ($check->fetch()) {

            $message = "A category with this name already exists.";
            $messageType = "danger";

        } else {

            $stmt = $conn->prepare("
                INSERT INTO categories (company_id, name, description, status)
                VALUES (?, ?, ?, 'active')
            ");

            $stmt->execute([
                $name,
                $description !== "" ? $description : null
            ]);

            $message = "Category added successfully.";
        }
    }
}


/* =========================
   EDIT CATEGORY
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["edit_category"])) {

    $id = (int)($_POST["id"] ?? 0);
    $name = trim($_POST["name"] ?? "");
    $description = trim($_POST["description"] ?? "");

    if ($id <= 0 || $name === "") {

        $message = "Category name is required.";
        $messageType = "danger";

    } else {

        $check = $conn->prepare("
            SELECT id
            FROM categories
            WHERE name = ?
            AND id != ?
            LIMIT 1
        ");

        $check->execute([$name, $id]);

        if ($check->fetch()) {

            $message = "Another category already uses this name.";
            $messageType = "danger";

        } else {

            $stmt = $conn->prepare("
                UPDATE categories
                SET name = ?,
                    description = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $name,
                $description !== "" ? $description : null,
                $id
            ]);

            $message = "Category updated successfully.";
        }
    }
}


/* =========================
   TOGGLE STATUS
========================= */
if (isset($_GET["toggle"])) {

    $id = (int)$_GET["toggle"];

    $stmt = $conn->prepare("
        SELECT status
        FROM categories
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$id]);
    $category = $stmt->fetch();

    if ($category) {

        $newStatus = $category["status"] === "active"
            ? "inactive"
            : "active";

        $update = $conn->prepare("
            UPDATE categories
            SET status = ?
            WHERE id = ?
        ");

        $update->execute([$newStatus, $id]);

        $message = "Category status updated successfully.";

    } else {

        $message = "Category not found.";
        $messageType = "danger";
    }
}


/* =========================
   DELETE CATEGORY
========================= */
if (isset($_GET["delete"])) {

    $id = (int)$_GET["delete"];

    $productCount = 0;
    try {
        $checkProducts = $conn->prepare("
            SELECT COUNT(*) 
            FROM products
            WHERE category_id = ? AND company_id = ?
        ");
        $checkProducts->execute([$id]);
        $productCount = (int)$checkProducts->fetchColumn();
    } catch (PDOException $e) {
        $productCount = 0;
    }

    if ($productCount > 0) {

        $message = "This category cannot be deleted because it contains "
                 . $productCount
                 . " product(s). Move or delete the products first.";

        $messageType = "danger";

    } else {

        $delete = $conn->prepare("
            DELETE FROM categories
            WHERE id = ? AND company_id = ?
        ");

        $delete->execute([$id]);

        $message = "Category deleted successfully.";
    }
}


/* =========================
   FETCH CATEGORIES
========================= */

$categories = [];
try {
    $stmt = $conn->prepare("
        SELECT
            c.id,
            c.name,
            c.description,
            c.status,
            c.created_at,
            COUNT(p.id) AS product_count
        FROM categories c
        LEFT JOIN products p
            ON p.category_id = c.id AND p.company_id = c.company_id
        WHERE c.company_id = ?
        GROUP BY
            c.id,
            c.name,
            c.description,
            c.status,
            c.created_at
        ORDER BY c.created_at DESC
    ");
    $stmt->execute([$companyId]);
    $stmt->execute([$companyId]);
    $categories = $stmt->fetchAll();
} catch (PDOException $e) {
    $stmt = $conn->prepare("
        SELECT
            c.id,
            c.name,
            c.description,
            c.status,
            c.created_at,
            0 AS product_count
        FROM categories c
        WHERE c.company_id = ?
        ORDER BY c.created_at DESC
    ");
    $categories = $stmt->fetchAll();
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Categories | GroceryDelivery</title>

    <!-- Bootstrap -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <!-- Bootstrap Icons -->
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

    <!-- Admin CSS -->
    <link
        href="../assets/css/admin.css"
        rel="stylesheet"
    >

    <style>
        .stat-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 15px;
            padding: 20px;
            height: 100%;
            box-shadow: 0 4px 15px rgba(15,23,42,0.04);
        }

        .stat-icon {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #ecfdf5;
            color: #16a34a;
            font-size: 20px;
        }

        .stat-label {
            margin-top: 13px;
            color: #64748b;
            font-size: 13px;
        }

        .stat-number {
            margin-top: 3px;
            font-size: 25px;
            font-weight: 750;
            color: #0f172a;
        }

        .category-name {
            font-weight: 650;
            color: #0f172a;
        }

        .category-description {
            color: #64748b;
            max-width: 350px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 10px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 650;
        }

        .status-active {
            color: #15803d;
            background: #dcfce7;
        }

        .status-inactive {
            color: #b45309;
            background: #fef3c7;
        }

        .product-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 35px;
            padding: 5px 9px;
            border-radius: 8px;
            background: #f1f5f9;
            color: #475569;
            font-weight: 650;
            font-size: 12px;
        }

        .action-buttons {
            display: flex;
            gap: 6px;
            white-space: nowrap;
        }

        .action-btn {
            width: 35px;
            height: 35px;
            border: none;
            border-radius: 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: 0.2s ease;
        }

        .edit-btn {
            background: #eff6ff;
            color: #2563eb;
        }

        .edit-btn:hover {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .toggle-btn {
            background: #fefce8;
            color: #ca8a04;
        }

        .toggle-btn:hover {
            background: #fef9c3;
            color: #a16207;
        }

        .delete-btn {
            background: #fef2f2;
            color: #dc2626;
        }

        .delete-btn:hover {
            background: #fee2e2;
            color: #b91c1c;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-icon {
            width: 70px;
            height: 70px;
            margin: 0 auto 15px;
            border-radius: 18px;
            background: #f1f5f9;
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
        }

        .empty-state h5 {
            color: #334155;
            font-weight: 700;
        }

        .empty-state p {
            color: #64748b;
            margin-bottom: 20px;
        }
    </style>

</head>

<body>


<?php require_once __DIR__ . "/../includes/admin_sidebar.php"; ?>

<?php include "../includes/loader.php"; ?>


<!-- =========================
     MAIN CONTENT
========================= -->

<main class="main-content">

    <!-- Topbar Header -->
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
                    Categories
                </h1>

                <p class="topbar-subtitle">
                    Organize and manage your grocery product categories.
                </p>
            </div>

        </div>

        <button
            type="button"
            class="btn btn-success"
            data-bs-toggle="modal"
            data-bs-target="#addCategoryModal"
        >
            <i class="bi bi-plus-lg me-1"></i>
            Add Category
        </button>

    </div>


    <!-- Flash Message -->
    <?php if ($message !== ""): ?>

        <div
            class="alert alert-<?= e($messageType) ?> alert-dismissible fade show"
            role="alert"
        >
            <?= e($message) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>
        </div>

    <?php endif; ?>


    <!-- Statistics -->
    <?php

    $totalCategories = count($categories);

    $activeCategories = 0;
    $inactiveCategories = 0;
    $totalProducts = 0;

    foreach ($categories as $category) {

        if ($category["status"] === "active") {
            $activeCategories++;
        } else {
            $inactiveCategories++;
        }

        $totalProducts += (int)$category["product_count"];
    }

    ?>

    <div class="row g-3">

        <div class="col-12 col-md-6 col-xl-3">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-tags-fill"></i>
                </div>

                <div class="stat-label">
                    Total Categories
                </div>

                <div class="stat-number">
                    <?= $totalCategories ?>
                </div>

            </div>

        </div>


        <div class="col-12 col-md-6 col-xl-3">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-check-circle-fill"></i>
                </div>

                <div class="stat-label">
                    Active Categories
                </div>

                <div class="stat-number">
                    <?= $activeCategories ?>
                </div>

            </div>

        </div>


        <div class="col-12 col-md-6 col-xl-3">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-pause-circle-fill"></i>
                </div>

                <div class="stat-label">
                    Inactive Categories
                </div>

                <div class="stat-number">
                    <?= $inactiveCategories ?>
                </div>

            </div>

        </div>


        <div class="col-12 col-md-6 col-xl-3">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-box-seam-fill"></i>
                </div>

                <div class="stat-label">
                    Products Assigned
                </div>

                <div class="stat-number">
                    <?= $totalProducts ?>
                </div>

            </div>

        </div>

    </div>


    <!-- Categories Table -->
    <div class="card mt-4">

        <div class="card-header d-flex align-items-center justify-content-between">

            <h5 class="mb-0 fw-bold">
                All Categories
            </h5>

            <span class="text-muted small">
                <?= $totalCategories ?> categor<?= $totalCategories === 1 ? "y" : "ies" ?>
            </span>

        </div>


        <?php if (empty($categories)): ?>

            <div class="empty-state">

                <div class="empty-icon">
                    <i class="bi bi-tags"></i>
                </div>

                <h5>
                    No categories yet
                </h5>

                <p>
                    Create your first grocery category to get started.
                </p>

                <button
                    type="button"
                    class="btn btn-success"
                    data-bs-toggle="modal"
                    data-bs-target="#addCategoryModal"
                >
                    <i class="bi bi-plus-lg me-1"></i>
                    Add First Category
                </button>

            </div>

        <?php else: ?>

            <div class="table-responsive">

                <table class="table">

                    <thead>

                        <tr>

                            <th>
                                #
                            </th>

                            <th>
                                Category
                            </th>

                            <th>
                                Description
                            </th>

                            <th>
                                Products
                            </th>

                            <th>
                                Status
                            </th>

                            <th>
                                Created
                            </th>

                            <th class="text-end">
                                Actions
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach ($categories as $index => $category): ?>

                        <tr>

                            <td class="text-muted">
                                <?= $index + 1 ?>
                            </td>

                            <td>

                                <div class="category-name">
                                    <?= e($category["name"]) ?>
                                </div>

                            </td>

                            <td>

                                <div class="category-description">

                                    <?php if (!empty($category["description"])): ?>

                                        <?= e($category["description"]) ?>

                                    <?php else: ?>

                                        <span class="text-muted">
                                            No description
                                        </span>

                                    <?php endif; ?>

                                </div>

                            </td>

                            <td>

                                <span class="product-count">
                                    <?= (int)$category["product_count"] ?>
                                </span>

                            </td>

                            <td>

                                <?php if ($category["status"] === "active"): ?>

                                    <span class="status-badge status-active">
                                        <i class="bi bi-check-circle-fill"></i>
                                        Active
                                    </span>

                                <?php else: ?>

                                    <span class="status-badge status-inactive">
                                        <i class="bi bi-pause-circle-fill"></i>
                                        Inactive
                                    </span>

                                <?php endif; ?>

                            </td>

                            <td class="text-muted">

                                <?= date(
                                    "M d, Y",
                                    strtotime($category["created_at"])
                                ) ?>

                            </td>

                            <td>

                                <div class="action-buttons justify-content-end">

                                    <!-- Edit -->
                                    <button
                                        type="button"
                                        class="action-btn edit-btn"
                                        title="Edit Category"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editCategory<?= (int)$category["id"] ?>"
                                    >
                                        <i class="bi bi-pencil-fill"></i>
                                    </button>


                                    <!-- Toggle -->
                                    <a
                                        href="?toggle=<?= (int)$category["id"] ?>"
                                        class="action-btn toggle-btn"
                                        title="<?= $category["status"] === "active" ? "Deactivate" : "Activate" ?>"
                                        onclick="return confirm('Are you sure you want to <?= $category["status"] === "active" ? "deactivate" : "activate" ?> this category?');"
                                    >
                                        <?php if ($category["status"] === "active"): ?>

                                            <i class="bi bi-pause-fill"></i>

                                        <?php else: ?>

                                            <i class="bi bi-play-fill"></i>

                                        <?php endif; ?>

                                    </a>


                                    <!-- Delete -->
                                    <a
                                        href="?delete=<?= (int)$category["id"] ?>"
                                        class="action-btn delete-btn"
                                        title="Delete Category"
                                        onclick="return confirm('Delete this category? This action cannot be undone.');"
                                    >
                                        <i class="bi bi-trash-fill"></i>
                                    </a>

                                </div>

                            </td>

                        </tr>


                        <!-- EDIT MODAL -->

                        <div
                            class="modal fade"
                            id="editCategory<?= (int)$category["id"] ?>"
                            tabindex="-1"
                            aria-hidden="true"
                        >

                            <div class="modal-dialog modal-dialog-centered">

                                <div class="modal-content">

                                    <form method="POST">

                                        <div class="modal-header">

                                            <h5 class="modal-title">
                                                <i class="bi bi-pencil-square me-2 text-success"></i>
                                                Edit Category
                                            </h5>

                                            <button
                                                type="button"
                                                class="btn-close"
                                                data-bs-dismiss="modal"
                                            ></button>

                                        </div>


                                        <div class="modal-body">

                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?= (int)$category["id"] ?>"
                                            >

                                            <div class="mb-3">

                                                <label class="form-label">
                                                    Category Name
                                                </label>

                                                <input
                                                    type="text"
                                                    name="name"
                                                    class="form-control"
                                                    value="<?= e($category["name"]) ?>"
                                                    required
                                                >

                                            </div>


                                            <div class="mb-3">

                                                <label class="form-label">
                                                    Description
                                                </label>

                                                <textarea
                                                    name="description"
                                                    class="form-control"
                                                    rows="4"
                                                    placeholder="Describe this category..."
                                                ><?= e($category["description"]) ?></textarea>

                                            </div>

                                        </div>


                                        <div class="modal-footer">

                                            <button
                                                type="button"
                                                class="btn btn-light"
                                                data-bs-dismiss="modal"
                                            >
                                                Cancel
                                            </button>

                                            <button
                                                type="submit"
                                                name="edit_category"
                                                class="btn btn-success"
                                            >
                                                <i class="bi bi-check-lg me-1"></i>
                                                Save Changes
                                            </button>

                                        </div>

                                    </form>

                                </div>

                            </div>

                        </div>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>

</main>


<!-- =========================
     ADD CATEGORY MODAL
========================= -->

<div
    class="modal fade"
    id="addCategoryModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <form method="POST">

                <div class="modal-header">

                    <h5 class="modal-title">

                        <i class="bi bi-plus-circle-fill me-2 text-success"></i>

                        Add Category

                    </h5>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                    ></button>

                </div>


                <div class="modal-body">

                    <div class="mb-3">

                        <label class="form-label">
                            Category Name
                        </label>

                        <input
                            type="text"
                            name="name"
                            class="form-control"
                            placeholder="e.g. Fruits & Vegetables"
                            required
                        >

                    </div>


                    <div class="mb-3">

                        <label class="form-label">
                            Description
                            <span class="text-muted fw-normal">
                                (Optional)
                            </span>
                        </label>

                        <textarea
                            name="description"
                            class="form-control"
                            rows="4"
                            placeholder="Briefly describe this category..."
                        ></textarea>

                    </div>

                </div>


                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-light"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        name="add_category"
                        class="btn btn-success"
                    >
                        <i class="bi bi-plus-lg me-1"></i>
                        Add Category
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>


<!-- Bootstrap JS -->
<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
</script>

</body>
</html>
