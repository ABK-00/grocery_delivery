<?php

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../includes/functions.php";

requireRole("admin");
requireCompanyAccess();
$companyId = currentCompanyId();

$uploadDir = __DIR__ . "/../assets/images/products/";
$uploadUrl = "../assets/images/products/";

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$message = "";
$messageType = "success";

function uploadProductImage($file, $uploadDir)
{
    if (
        !isset($file["tmp_name"]) ||
        $file["error"] !== UPLOAD_ERR_OK
    ) {
        return false;
    }

    if ($file["size"] > 5 * 1024 * 1024) {
        throw new Exception("Each image must not exceed 5MB.");
    }

    $allowedTypes = [
        "image/jpeg" => "jpg",
        "image/png"  => "png",
        "image/webp" => "webp"
    ];

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file["tmp_name"]);
    finfo_close($finfo);

    if (!isset($allowedTypes[$mime])) {
        throw new Exception("Only JPG, PNG and WEBP images are allowed.");
    }

    $extension = $allowedTypes[$mime];

    $filename = uniqid("product_", true) . "." . $extension;

    if (!move_uploaded_file(
        $file["tmp_name"],
        $uploadDir . $filename
    )) {
        throw new Exception("Failed to upload image.");
    }

    return $filename;
}

/*
|--------------------------------------------------------------------------
| DELETE IMAGE
|--------------------------------------------------------------------------
*/
if (
    isset($_GET["delete_image"]) &&
    is_numeric($_GET["delete_image"])
) {
    $imageId = (int) $_GET["delete_image"];

    $stmt = $conn->prepare("
        SELECT *
        FROM product_images
        WHERE id = ?
    ");

    $stmt->execute([$imageId]);
    $image = $stmt->fetch();

    if ($image) {

        $productId = $image["product_id"];

        $delete = $conn->prepare("
            DELETE FROM product_images
            WHERE id = ?
        ");

        $delete->execute([$imageId]);

        $filePath = $uploadDir . $image["image"];

        if (file_exists($filePath)) {
            unlink($filePath);
        }

        /*
        | If deleted image was primary,
        | automatically make another image primary.
        */
        if ($image["is_primary"]) {

            $newPrimary = $conn->prepare("
                SELECT id
                FROM product_images
                WHERE product_id = ?
                ORDER BY sort_order ASC, id ASC
                LIMIT 1
            ");

            $newPrimary->execute([$productId]);

            $newPrimaryId = $newPrimary->fetchColumn();

            if ($newPrimaryId) {

                $conn->prepare("
                    UPDATE product_images
                    SET is_primary = 0
                    WHERE product_id = ?
                ")->execute([$productId]);

                $conn->prepare("
                    UPDATE product_images
                    SET is_primary = 1
                    WHERE id = ?
                ")->execute([$newPrimaryId]);
            }
        }

        header("Location: products.php?success=image_deleted");
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| SET PRIMARY IMAGE
|--------------------------------------------------------------------------
*/
if (
    isset($_GET["primary_image"]) &&
    is_numeric($_GET["primary_image"])
) {
    $imageId = (int) $_GET["primary_image"];

    $stmt = $conn->prepare("
        SELECT product_id
        FROM product_images
        WHERE id = ?
    ");

    $stmt->execute([$imageId]);
    $productId = $stmt->fetchColumn();

    if ($productId) {

        $conn->prepare("
            UPDATE product_images
            SET is_primary = 0
            WHERE product_id = ?
        ")->execute([$productId]);

        $conn->prepare("
            UPDATE product_images
            SET is_primary = 1
            WHERE id = ?
        ")->execute([$imageId]);
    }

    header("Location: products.php?success=primary_set");
    exit;
}

/*
|--------------------------------------------------------------------------
| SUCCESS MESSAGES
|--------------------------------------------------------------------------
*/
if (isset($_GET["success"])) {

    switch ($_GET["success"]) {

        case "image_deleted":
            $message = "Product image deleted successfully.";
            break;

        case "primary_set":
            $message = "Primary image updated successfully.";
            break;

        case "created":
            $message = "Product created successfully.";
            break;

        case "updated":
            $message = "Product updated successfully.";
            break;

        case "deleted":
            $message = "Product deleted successfully.";
            break;
    }
}

/*
|--------------------------------------------------------------------------
| CREATE / UPDATE PRODUCT
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";

    try {

        /*
        |--------------------------------------------------------------------------
        | CREATE PRODUCT
        |--------------------------------------------------------------------------
        */
        if ($action === "create") {

            $name = trim($_POST["name"] ?? "");
            $categoryId = !empty($_POST["category_id"])
                ? (int) $_POST["category_id"]
                : null;

            $description = trim($_POST["description"] ?? "");

            $price = (float) ($_POST["price"] ?? 0);
            $stock = (float) ($_POST["stock"] ?? 0);

            $unit = trim($_POST["unit"] ?? "piece");

            if ($name === "") {
                throw new Exception("Product name is required.");
            }

            if ($price < 0) {
                throw new Exception("Price cannot be negative.");
            }

            if ($stock < 0) {
                throw new Exception("Stock cannot be negative.");
            }

            /*
            | Check duplicate product name
            */
            $check = $conn->prepare("
                SELECT id
                FROM products
                WHERE LOWER(name) = LOWER(?)
                AND company_id = ?
                LIMIT 1
            ");

            $check->execute([$name, $companyId]);

            if ($check->fetch()) {
                throw new Exception("A product with this name already exists.");
            }

            /*
            | Create product
            */
            $stmt = $conn->prepare("
                INSERT INTO products
                (
                    company_id,
                    category_id,
                    name,
                    description,
                    price,
                    stock,
                    unit,
                    status
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
            ");

            $stmt->execute([
                $companyId,
                $categoryId,
                $name,
                $description,
                $price,
                $stock,
                $unit
            ]);

            $productId = (int) $conn->lastInsertId();

            /*
            | Upload up to 5 images
            */
            $files = $_FILES["images"] ?? null;

            if ($files && isset($files["name"])) {

                $imageCount = count($files["name"]);

                if ($imageCount > 5) {
                    throw new Exception(
                        "You can upload a maximum of 5 images per product."
                    );
                }

                $uploadedImages = [];

                for ($i = 0; $i < $imageCount; $i++) {

                    if ($files["error"][$i] === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }

                    $file = [
                        "name"     => $files["name"][$i],
                        "type"     => $files["type"][$i],
                        "tmp_name" => $files["tmp_name"][$i],
                        "error"    => $files["error"][$i],
                        "size"     => $files["size"][$i]
                    ];

                    $filename = uploadProductImage(
                        $file,
                        $uploadDir
                    );

                    if ($filename) {
                        $uploadedImages[] = $filename;
                    }
                }

                /*
                | First image becomes primary.
                */
                foreach ($uploadedImages as $index => $filename) {

                    $isPrimary = ($index === 0) ? 1 : 0;

                    $imageStmt = $conn->prepare("
                        INSERT INTO product_images
                        (
                            product_id,
                            image,
                            is_primary,
                            sort_order
                        )
                        VALUES (?, ?, ?, ?)
                    ");

                    $imageStmt->execute([
                        $productId,
                        $filename,
                        $isPrimary,
                        $index
                    ]);
                }

                /*
                | Keep legacy products.image synchronized
                | with primary image.
                */
                if (!empty($uploadedImages)) {

                    $conn->prepare("
                        UPDATE products
                        SET image = ?
                        WHERE id = ?
                    ")->execute([
                        $uploadedImages[0],
                        $productId
                    ]);
                }
            }

            header("Location: products.php?success=created");
            exit;
        }

        /*
        |--------------------------------------------------------------------------
        | UPDATE PRODUCT
        |--------------------------------------------------------------------------
        */
        if ($action === "update") {

            $productId = (int) ($_POST["product_id"] ?? 0);

            $name = trim($_POST["name"] ?? "");
            $categoryId = !empty($_POST["category_id"])
                ? (int) $_POST["category_id"]
                : null;

            $description = trim($_POST["description"] ?? "");

            $price = (float) ($_POST["price"] ?? 0);
            $stock = (float) ($_POST["stock"] ?? 0);

            $unit = trim($_POST["unit"] ?? "piece");

            if ($productId <= 0) {
                throw new Exception("Invalid product.");
            }

            if ($name === "") {
                throw new Exception("Product name is required.");
            }

            /*
            | Duplicate name check
            */
            $check = $conn->prepare("
                SELECT id
                FROM products
                WHERE LOWER(name) = LOWER(?)
                AND id != ?
                LIMIT 1
            ");

            $check->execute([
                $name,
                $productId
            ]);

            if ($check->fetch()) {
                throw new Exception("Another product already uses this name.");
            }

            /*
            | Update product
            */
            $stmt = $conn->prepare("
                UPDATE products
                SET
                    category_id = ?,
                    name = ?,
                    description = ?,
                    price = ?,
                    stock = ?,
                    unit = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $categoryId,
                $name,
                $description,
                $price,
                $stock,
                $unit,
                $productId
            ]);

            /*
            | Count existing images
            */
            $countStmt = $conn->prepare("
                SELECT COUNT(*)
                FROM product_images
                WHERE product_id = ?
            ");

            $countStmt->execute([$productId]);

            $existingCount = (int) $countStmt->fetchColumn();

            /*
            | Upload additional images
            */
            $files = $_FILES["images"] ?? null;

            if ($files && isset($files["name"])) {

                $selectedFiles = [];

                for ($i = 0; $i < count($files["name"]); $i++) {

                    if ($files["error"][$i] !== UPLOAD_ERR_NO_FILE) {
                        $selectedFiles[] = $i;
                    }
                }

                if (
                    $existingCount + count($selectedFiles) > 5
                ) {
                    throw new Exception(
                        "A product can have a maximum of 5 images. " .
                        "This product currently has {$existingCount}."
                    );
                }

                $sortStmt = $conn->prepare("
                    SELECT COALESCE(MAX(sort_order), -1)
                    FROM product_images
                    WHERE product_id = ?
                ");

                $sortStmt->execute([$productId]);

                $sortOrder = (int) $sortStmt->fetchColumn() + 1;

                foreach ($selectedFiles as $index) {

                    $file = [
                        "name"     => $files["name"][$index],
                        "type"     => $files["type"][$index],
                        "tmp_name" => $files["tmp_name"][$index],
                        "error"    => $files["error"][$index],
                        "size"     => $files["size"][$index]
                    ];

                    $filename = uploadProductImage(
                        $file,
                        $uploadDir
                    );

                    /*
                    | If product has no image yet,
                    | first uploaded image becomes primary.
                    */
                    $isPrimary = ($existingCount === 0 && $sortOrder === 0)
                        ? 1
                        : 0;

                    $imageStmt = $conn->prepare("
                        INSERT INTO product_images
                        (
                            product_id,
                            image,
                            is_primary,
                            sort_order
                        )
                        VALUES (?, ?, ?, ?)
                    ");

                    $imageStmt->execute([
                        $productId,
                        $filename,
                        $isPrimary,
                        $sortOrder
                    ]);

                    if ($isPrimary) {

                        $conn->prepare("
                            UPDATE products
                            SET image = ?
                            WHERE id = ?
                        ")->execute([
                            $filename,
                            $productId
                        ]);
                    }

                    $sortOrder++;
                    $existingCount++;
                }
            }

            header("Location: products.php?success=updated");
            exit;
        }

        /*
        |--------------------------------------------------------------------------
        | DELETE PRODUCT
        |--------------------------------------------------------------------------
        */
        if ($action === "delete") {

            $productId = (int) ($_POST["product_id"] ?? 0);

            /*
            | Get all images first
            */
            $stmt = $conn->prepare("
                SELECT image
                FROM product_images
                WHERE product_id = ?
            ");

            $stmt->execute([$productId]);

            $images = $stmt->fetchAll();

            /*
            | Delete product.
            | product_images records will be removed
            | automatically through ON DELETE CASCADE.
            */
            $delete = $conn->prepare("
                DELETE FROM products
                WHERE id = ?
            ");

            $delete->execute([$productId]);

            /*
            | Delete physical files
            */
            foreach ($images as $image) {

                $filePath = $uploadDir . $image["image"];

                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            }

            header("Location: products.php?success=deleted");
            exit;
        }

        /*
        |--------------------------------------------------------------------------
        | TOGGLE STATUS
        |--------------------------------------------------------------------------
        */
        if ($action === "toggle_status") {

            $productId = (int) ($_POST["product_id"] ?? 0);

            $stmt = $conn->prepare("
                UPDATE products
                SET status =
                    CASE
                        WHEN status = 'active'
                        THEN 'inactive'
                        ELSE 'active'
                    END
                WHERE id = ?
            ");

            $stmt->execute([$productId]);

            header("Location: products.php?success=updated");
            exit;
        }

    } catch (Exception $e) {

        $message = $e->getMessage();
        $messageType = "danger";
    }
}

/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/
$search = trim($_GET["search"] ?? "");
$categoryFilter = $_GET["category"] ?? "";
$statusFilter = $_GET["status"] ?? "";

/*
|--------------------------------------------------------------------------
| CATEGORIES
|--------------------------------------------------------------------------
*/
$categoryStmt = $conn->prepare("
    SELECT *
    FROM categories
    WHERE company_id = ?
    ORDER BY name ASC
");
$categoryStmt->execute([$companyId]);
$categories = $categoryStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| PRODUCTS
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT
        p.*,
        c.name AS category_name
    FROM products p
    LEFT JOIN categories c
        ON p.category_id = c.id
    WHERE p.company_id = ?
";

$params = [$companyId];

if ($search !== "") {

    $sql .= "
        AND (
            p.name LIKE ?
            OR p.description LIKE ?
        )
    ";

    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if ($categoryFilter !== "") {

    $sql .= "
        AND p.category_id = ?
    ";

    $params[] = (int) $categoryFilter;
}

if ($statusFilter !== "") {

    $sql .= "
        AND p.status = ?
    ";

    $params[] = $statusFilter;
}

$sql .= "
    ORDER BY p.created_at DESC
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);

$products = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| PRODUCT IMAGES
|--------------------------------------------------------------------------
*/
$imageStmt = $conn->prepare("
    SELECT pi.*
    FROM product_images pi
    INNER JOIN products p ON p.id = pi.product_id
    WHERE p.company_id = ?
    ORDER BY pi.product_id ASC, pi.sort_order ASC, pi.id ASC
");
$imageStmt->execute([$companyId]);

$productImages = [];

foreach ($imageStmt->fetchAll() as $image) {

    $productImages[$image["product_id"]][] = $image;
}

/*
|--------------------------------------------------------------------------
| STATS
|--------------------------------------------------------------------------
*/
$stat = $conn->prepare("SELECT COUNT(*) FROM products WHERE company_id = ?"); $stat->execute([$companyId]); $totalProducts = (int)$stat->fetchColumn();

$stat = $conn->prepare("SELECT COUNT(*) FROM products WHERE company_id = ? AND status = 'active'"); $stat->execute([$companyId]); $activeProducts = (int)$stat->fetchColumn();

$stat = $conn->prepare("SELECT COUNT(*) FROM products WHERE company_id = ? AND status = 'inactive'"); $stat->execute([$companyId]); $inactiveProducts = (int)$stat->fetchColumn();

$stat = $conn->prepare("SELECT COALESCE(SUM(stock), 0) FROM products WHERE company_id = ?"); $stat->execute([$companyId]); $totalStock = (float)$stat->fetchColumn();

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Products | GroceryDelivery</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet">

    <link
        href="../assets/css/admin.css"
        rel="stylesheet">

    <style>
        .product-image {
            width: 65px;
            height: 65px;
            object-fit: cover;
            border-radius: 12px;
            background: #f0f2f5;
        }

        .image-placeholder {
            width: 65px;
            height: 65px;
            border-radius: 12px;
            background: #edf1f5;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #9aa5b1;
            font-size: 24px;
        }

        .gallery {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }

        .gallery-thumb {
            width: 42px;
            height: 42px;
            object-fit: cover;
            border-radius: 7px;
            border: 2px solid transparent;
        }

        .gallery-thumb.primary {
            border-color: #22c55e;
        }

        .badge-active {
            background: #dcfce7;
            color: #15803d;
        }

        .badge-inactive {
            background: #fee2e2;
            color: #b91c1c;
        }

        .image-manager {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 12px;
        }

        .image-box {
            position: relative;
            border: 1px solid #e5e9ef;
            border-radius: 12px;
            padding: 6px;
            background: #fafbfc;
        }

        .image-box img {
            width: 100%;
            aspect-ratio: 1;
            object-fit: cover;
            border-radius: 8px;
        }

        .primary-label {
            position: absolute;
            top: 10px;
            left: 10px;
            background: #22c55e;
            color: #111827;
            font-size: 11px;
            font-weight: 800;
            padding: 4px 7px;
            border-radius: 6px;
        }

        .image-actions {
            display: flex;
            gap: 5px;
            margin-top: 6px;
        }

        .image-actions a {
            flex: 1;
            font-size: 11px;
        }

        @media (max-width: 991px) {
            .image-manager {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 576px) {
            .image-manager {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>

</head>

<body>

<?php require_once __DIR__ . "/../includes/admin_sidebar.php"; ?>
<?php include "../includes/loader.php"; ?>


<main class="main-content">

    <!-- TOPBAR -->

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
                    Products Management
                </h1>

                <p class="topbar-subtitle">
                    Add, edit, and manage grocery items in your store inventory.
                </p>
            </div>

        </div>

        <div>
            <button
                class="btn btn-success"
                data-bs-toggle="modal"
                data-bs-target="#addProductModal"
                type="button"
            >
                <i class="bi bi-plus-lg me-1"></i>
                Add Product
            </button>
        </div>

    </div>

            <p>
                Manage grocery products, inventory and product galleries.
            </p>

        </div>

        <button
            class="btn btn-primary-custom"
            data-bs-toggle="modal"
            data-bs-target="#addProductModal">

            <i class="bi bi-plus-lg me-1"></i>
            Add Product

        </button>

    </div>


    <!-- Message -->
    <?php if ($message): ?>

        <div class="alert alert-<?= e($messageType) ?> alert-dismissible fade show">

            <?= e($message) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert">
            </button>

        </div>

    <?php endif; ?>


    <!-- Stats -->
    <div class="row g-3 mb-4">

        <div class="col-md-3">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-box-seam"></i>
                </div>

                <div class="stat-number">
                    <?= number_format($totalProducts) ?>
                </div>

                <div class="stat-label">
                    Total Products
                </div>

            </div>

        </div>


        <div class="col-md-3">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-check-circle"></i>
                </div>

                <div class="stat-number">
                    <?= number_format($activeProducts) ?>
                </div>

                <div class="stat-label">
                    Active Products
                </div>

            </div>

        </div>


        <div class="col-md-3">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-pause-circle"></i>
                </div>

                <div class="stat-number">
                    <?= number_format($inactiveProducts) ?>
                </div>

                <div class="stat-label">
                    Inactive Products
                </div>

            </div>

        </div>


        <div class="col-md-3">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-boxes"></i>
                </div>

                <div class="stat-number">
                    <?= number_format($totalStock, 2) ?>
                </div>

                <div class="stat-label">
                    Total Stock
                </div>

            </div>

        </div>

    </div>


    <!-- Products -->
    <div class="content-card">

        <!-- Filters -->
        <div class="filters">

            <form method="GET">

                <div class="row g-2">

                    <div class="col-lg-5">

                        <div class="input-group">

                            <span class="input-group-text">
                                <i class="bi bi-search"></i>
                            </span>

                            <input
                                type="text"
                                name="search"
                                class="form-control"
                                placeholder="Search products..."
                                value="<?= e($search) ?>">

                        </div>

                    </div>


                    <div class="col-lg-3">

                        <select
                            name="category"
                            class="form-select">

                            <option value="">
                                All Categories
                            </option>

                            <?php foreach ($categories as $category): ?>

                                <option
                                    value="<?= $category["id"] ?>"
                                    <?= $categoryFilter == $category["id"] ? "selected" : "" ?>>

                                    <?= e($category["name"]) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <div class="col-lg-2">

                        <select
                            name="status"
                            class="form-select">

                            <option value="">
                                All Status
                            </option>

                            <option
                                value="active"
                                <?= $statusFilter === "active" ? "selected" : "" ?>>

                                Active

                            </option>

                            <option
                                value="inactive"
                                <?= $statusFilter === "inactive" ? "selected" : "" ?>>

                                Inactive

                            </option>

                        </select>

                    </div>


                    <div class="col-lg-2">

                        <button
                            class="btn btn-dark w-100">

                            <i class="bi bi-funnel me-1"></i>
                            Filter

                        </button>

                    </div>

                </div>

            </form>

        </div>


        <!-- Table -->
        <div class="table-responsive">

            <table class="table table-hover mb-0 product-table">

                <thead>

                    <tr>

                        <th class="px-4 py-3">
                            Product
                        </th>

                        <th>
                            Category
                        </th>

                        <th>
                            Price
                        </th>

                        <th>
                            Stock
                        </th>

                        <th>
                            Gallery
                        </th>

                        <th>
                            Status
                        </th>

                        <th class="text-end px-4">
                            Actions
                        </th>

                    </tr>

                </thead>

                <tbody>

                <?php if (!$products): ?>

                    <tr>

                        <td
                            colspan="7"
                            class="text-center py-5 text-muted">

                            <i
                                class="bi bi-box-seam fs-1 d-block mb-2">
                            </i>

                            No products found.

                        </td>

                    </tr>

                <?php endif; ?>


                <?php foreach ($products as $product): ?>

                    <?php

                    $images =
                        $productImages[$product["id"]] ?? [];

                    $primaryImage = null;

                    foreach ($images as $img) {

                        if ($img["is_primary"]) {
                            $primaryImage = $img;
                            break;
                        }
                    }

                    if (!$primaryImage && !empty($images)) {
                        $primaryImage = $images[0];
                    }

                    ?>

                    <tr>

                        <!-- Product -->
                        <td class="px-4">

                            <div class="d-flex align-items-center gap-3">

                                <?php if ($primaryImage): ?>

                                    <img
                                        src="<?= $uploadUrl . e($primaryImage["image"]) ?>"
                                        class="product-image"
                                        alt="<?= e($product["name"]) ?>">

                                <?php elseif (!empty($product["image"])): ?>

                                    <img
                                        src="<?= $uploadUrl . e($product["image"]) ?>"
                                        class="product-image"
                                        alt="<?= e($product["name"]) ?>">

                                <?php else: ?>

                                    <div class="image-placeholder">

                                        <i class="bi bi-image"></i>

                                    </div>

                                <?php endif; ?>


                                <div>

                                    <div class="fw-bold">
                                        <?= e($product["name"]) ?>
                                    </div>

                                    <small class="text-muted">
                                        <?= e($product["unit"]) ?>
                                    </small>

                                </div>

                            </div>

                        </td>


                        <!-- Category -->
                        <td>

                            <?= e(
                                $product["category_name"]
                                ?? "Uncategorized"
                            ) ?>

                        </td>


                        <!-- Price -->
                        <td>

                            <strong>
                                GH₵ <?= number_format(
                                    $product["price"],
                                    2
                                ) ?>
                            </strong>

                        </td>


                        <!-- Stock -->
                        <td>

                            <?= number_format(
                                $product["stock"],
                                2
                            ) ?>

                            <?= e($product["unit"]) ?>

                        </td>


                        <!-- Gallery -->
                        <td>

                            <div class="gallery">

                                <?php foreach ($images as $img): ?>

                                    <img
                                        src="<?= $uploadUrl . e($img["image"]) ?>"
                                        class="gallery-thumb <?= $img["is_primary"] ? "primary" : "" ?>"
                                        title="<?= $img["is_primary"] ? "Primary image" : "Product image" ?>">

                                <?php endforeach; ?>


                                <?php if (!$images): ?>

                                    <span class="text-muted small">
                                        No images
                                    </span>

                                <?php endif; ?>

                            </div>

                            <small class="text-muted">

                                <?= count($images) ?>/5 images

                            </small>

                        </td>


                        <!-- Status -->
                        <td>

                            <?php if ($product["status"] === "active"): ?>

                                <span class="badge badge-active px-3 py-2">
                                    Active
                                </span>

                            <?php else: ?>

                                <span class="badge badge-inactive px-3 py-2">
                                    Inactive
                                </span>

                            <?php endif; ?>

                        </td>


                        <!-- Actions -->
                        <td class="text-end px-4">

                            <div class="dropdown">

                                <button
                                    class="btn btn-sm btn-light"
                                    data-bs-toggle="dropdown">

                                    <i class="bi bi-three-dots"></i>

                                </button>

                                <ul class="dropdown-menu dropdown-menu-end">

                                    <li>

                                        <button
                                            class="dropdown-item"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editProduct<?= $product["id"] ?>">

                                            <i class="bi bi-pencil me-2"></i>
                                            Edit Product

                                        </button>

                                    </li>


                                    <li>

                                        <button
                                            class="dropdown-item"
                                            data-bs-toggle="modal"
                                            data-bs-target="#imagesModal<?= $product["id"] ?>">

                                            <i class="bi bi-images me-2"></i>
                                            Manage Images

                                        </button>

                                    </li>


                                    <li>

                                        <form method="POST">

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="toggle_status">

                                            <input
                                                type="hidden"
                                                name="product_id"
                                                value="<?= $product["id"] ?>">

                                            <button
                                                class="dropdown-item">

                                                <i class="bi bi-toggle-on me-2"></i>

                                                <?= $product["status"] === "active"
                                                    ? "Deactivate"
                                                    : "Activate" ?>

                                            </button>

                                        </form>

                                    </li>


                                    <li>
                                        <hr class="dropdown-divider">
                                    </li>


                                    <li>

                                        <form
                                            method="POST"
                                            onsubmit="return confirm('Delete this product and all its images?');">

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="delete">

                                            <input
                                                type="hidden"
                                                name="product_id"
                                                value="<?= $product["id"] ?>">

                                            <button
                                                class="dropdown-item text-danger">

                                                <i class="bi bi-trash me-2"></i>
                                                Delete Product

                                            </button>

                                        </form>

                                    </li>

                                </ul>

                            </div>

                        </td>

                    </tr>


                    <!-- EDIT PRODUCT MODAL -->
                    <div
                        class="modal fade"
                        id="editProduct<?= $product["id"] ?>"
                        tabindex="-1">

                        <div class="modal-dialog modal-lg modal-dialog-centered">

                            <div class="modal-content">

                                <form
                                    method="POST"
                                    enctype="multipart/form-data">

                                    <div class="modal-header">

                                        <h5 class="modal-title">

                                            <i class="bi bi-pencil-square me-2"></i>

                                            Edit Product

                                        </h5>

                                        <button
                                            type="button"
                                            class="btn-close"
                                            data-bs-dismiss="modal">
                                        </button>

                                    </div>


                                    <div class="modal-body">

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="update">

                                        <input
                                            type="hidden"
                                            name="product_id"
                                            value="<?= $product["id"] ?>">


                                        <div class="row g-3">

                                            <div class="col-md-8">

                                                <label class="form-label">
                                                    Product Name
                                                </label>

                                                <input
                                                    type="text"
                                                    name="name"
                                                    class="form-control"
                                                    value="<?= e($product["name"]) ?>"
                                                    required>

                                            </div>


                                            <div class="col-md-4">

                                                <label class="form-label">
                                                    Category
                                                </label>

                                                <select
                                                    name="category_id"
                                                    class="form-select">

                                                    <option value="">
                                                        Uncategorized
                                                    </option>

                                                    <?php foreach ($categories as $category): ?>

                                                        <option
                                                            value="<?= $category["id"] ?>"
                                                            <?= $product["category_id"] == $category["id"]
                                                                ? "selected"
                                                                : "" ?>>

                                                            <?= e($category["name"]) ?>

                                                        </option>

                                                    <?php endforeach; ?>

                                                </select>

                                            </div>


                                            <div class="col-md-4">

                                                <label class="form-label">
                                                    Price (GH₵)
                                                </label>

                                                <input
                                                    type="number"
                                                    name="price"
                                                    step="0.01"
                                                    min="0"
                                                    class="form-control"
                                                    value="<?= e($product["price"]) ?>"
                                                    required>

                                            </div>


                                            <div class="col-md-4">

                                                <label class="form-label">
                                                    Stock
                                                </label>

                                                <input
                                                    type="number"
                                                    name="stock"
                                                    step="0.01"
                                                    min="0"
                                                    class="form-control"
                                                    value="<?= e($product["stock"]) ?>"
                                                    required>

                                            </div>


                                            <div class="col-md-4">

                                                <label class="form-label">
                                                    Unit
                                                </label>

                                                <select
                                                    name="unit"
                                                    class="form-select">

                                                    <?php

                                                    $units = [
                                                        "piece",
                                                        "kg",
                                                        "gram",
                                                        "litre",
                                                        "ml",
                                                        "pack",
                                                        "box",
                                                        "bottle",
                                                        "bag",
                                                        "dozen"
                                                    ];

                                                    foreach ($units as $unit):

                                                    ?>

                                                        <option
                                                            value="<?= $unit ?>"
                                                            <?= $product["unit"] === $unit
                                                                ? "selected"
                                                                : "" ?>>

                                                            <?= ucfirst($unit) ?>

                                                        </option>

                                                    <?php endforeach; ?>

                                                </select>

                                            </div>


                                            <div class="col-12">

                                                <label class="form-label">
                                                    Description
                                                </label>

                                                <textarea
                                                    name="description"
                                                    rows="4"
                                                    class="form-control"><?= e($product["description"]) ?></textarea>

                                            </div>


                                            <div class="col-12">

                                                <label class="form-label">

                                                    Add More Images

                                                    <span class="text-muted">
                                                        (maximum 5 total)
                                                    </span>

                                                </label>

                                                <input
                                                    type="file"
                                                    name="images[]"
                                                    class="form-control"
                                                    accept=".jpg,.jpeg,.png,.webp"
                                                    multiple>

                                                <small class="text-muted">
                                                    JPG, PNG or WEBP. Maximum 5MB each.
                                                </small>

                                            </div>

                                        </div>

                                    </div>


                                    <div class="modal-footer">

                                        <button
                                            type="button"
                                            class="btn btn-light"
                                            data-bs-dismiss="modal">

                                            Cancel

                                        </button>

                                        <button
                                            type="submit"
                                            class="btn btn-primary-custom">

                                            Save Changes

                                        </button>

                                    </div>

                                </form>

                            </div>

                        </div>

                    </div>


                    <!-- IMAGE MANAGEMENT MODAL -->
                    <div
                        class="modal fade"
                        id="imagesModal<?= $product["id"] ?>"
                        tabindex="-1">

                        <div class="modal-dialog modal-lg modal-dialog-centered">

                            <div class="modal-content">

                                <div class="modal-header">

                                    <div>

                                        <h5 class="modal-title">

                                            <i class="bi bi-images me-2"></i>

                                            Product Images

                                        </h5>

                                        <small class="text-muted">

                                            <?= e($product["name"]) ?>

                                        </small>

                                    </div>

                                    <button
                                        type="button"
                                        class="btn-close"
                                        data-bs-dismiss="modal">
                                    </button>

                                </div>


                                <div class="modal-body">

                                    <div class="d-flex justify-content-between mb-3">

                                        <strong>
                                            <?= count($images) ?> / 5 Images
                                        </strong>

                                        <span class="text-muted small">
                                            Green border = primary image
                                        </span>

                                    </div>


                                    <?php if ($images): ?>

                                        <div class="image-manager">

                                            <?php foreach ($images as $img): ?>

                                                <div class="image-box">

                                                    <?php if ($img["is_primary"]): ?>

                                                        <span class="primary-label">
                                                            PRIMARY
                                                        </span>

                                                    <?php endif; ?>


                                                    <img
                                                        src="<?= $uploadUrl . e($img["image"]) ?>"
                                                        alt="Product image">


                                                    <div class="image-actions">

                                                        <?php if (!$img["is_primary"]): ?>

                                                            <a
                                                                href="?primary_image=<?= $img["id"] ?>"
                                                                class="btn btn-sm btn-outline-success">

                                                                <i class="bi bi-star"></i>

                                                            </a>

                                                        <?php endif; ?>


                                                        <a
                                                            href="?delete_image=<?= $img["id"] ?>"
                                                            class="btn btn-sm btn-outline-danger"
                                                            onclick="return confirm('Delete this image?');">

                                                            <i class="bi bi-trash"></i>

                                                        </a>

                                                    </div>

                                                </div>

                                            <?php endforeach; ?>

                                        </div>

                                    <?php else: ?>

                                        <div class="text-center py-4 text-muted">

                                            <i class="bi bi-images fs-1"></i>

                                            <p class="mt-2">
                                                No images have been added yet.
                                            </p>

                                        </div>

                                    <?php endif; ?>


                                    <?php if (count($images) < 5): ?>

                                        <hr class="my-4">

                                        <form
                                            method="POST"
                                            enctype="multipart/form-data">

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="update">

                                            <input
                                                type="hidden"
                                                name="product_id"
                                                value="<?= $product["id"] ?>">

                                            <input
                                                type="hidden"
                                                name="name"
                                                value="<?= e($product["name"]) ?>">

                                            <input
                                                type="hidden"
                                                name="category_id"
                                                value="<?= e($product["category_id"]) ?>">

                                            <input
                                                type="hidden"
                                                name="description"
                                                value="<?= e($product["description"]) ?>">

                                            <input
                                                type="hidden"
                                                name="price"
                                                value="<?= e($product["price"]) ?>">

                                            <input
                                                type="hidden"
                                                name="stock"
                                                value="<?= e($product["stock"]) ?>">

                                            <input
                                                type="hidden"
                                                name="unit"
                                                value="<?= e($product["unit"]) ?>">


                                            <label class="form-label fw-bold">

                                                Add Images

                                            </label>

                                            <input
                                                type="file"
                                                name="images[]"
                                                class="form-control"
                                                accept=".jpg,.jpeg,.png,.webp"
                                                multiple
                                                onchange="limitImages(this, <?= 5 - count($images) ?>)">

                                            <small class="text-muted">
                                                You can add
                                                <?= 5 - count($images) ?>
                                                more image(s).
                                            </small>


                                            <button
                                                type="submit"
                                                class="btn btn-primary-custom mt-3">

                                                <i class="bi bi-cloud-upload me-1"></i>

                                                Upload Images

                                            </button>

                                        </form>

                                    <?php else: ?>

                                        <div class="alert alert-success mt-4 mb-0">

                                            <i class="bi bi-check-circle me-2"></i>

                                            This product already has the maximum
                                            5 images.

                                        </div>

                                    <?php endif; ?>

                                </div>

                            </div>

                        </div>

                    </div>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    </div>

</main>


<!-- ADD PRODUCT MODAL -->
<div
    class="modal fade"
    id="addProductModal"
    tabindex="-1">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content">

            <form
                method="POST"
                enctype="multipart/form-data">

                <div class="modal-header">

                    <h5 class="modal-title">

                        <i class="bi bi-plus-circle me-2"></i>

                        Add New Product

                    </h5>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal">
                    </button>

                </div>


                <div class="modal-body">

                    <input
                        type="hidden"
                        name="action"
                        value="create">


                    <div class="row g-3">

                        <div class="col-md-8">

                            <label class="form-label">
                                Product Name
                            </label>

                            <input
                                type="text"
                                name="name"
                                class="form-control"
                                placeholder="e.g. Fresh Tomatoes"
                                required>

                        </div>


                        <div class="col-md-4">

                            <label class="form-label">
                                Category
                            </label>

                            <select
                                name="category_id"
                                class="form-select">

                                <option value="">
                                    Uncategorized
                                </option>

                                <?php foreach ($categories as $category): ?>

                                    <option
                                        value="<?= $category["id"] ?>">

                                        <?= e($category["name"]) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <div class="col-md-4">

                            <label class="form-label">
                                Price (GH₵)
                            </label>

                            <input
                                type="number"
                                name="price"
                                step="0.01"
                                min="0"
                                class="form-control"
                                placeholder="0.00"
                                required>

                        </div>


                        <div class="col-md-4">

                            <label class="form-label">
                                Stock
                            </label>

                            <input
                                type="number"
                                name="stock"
                                step="0.01"
                                min="0"
                                class="form-control"
                                placeholder="0"
                                required>

                        </div>


                        <div class="col-md-4">

                            <label class="form-label">
                                Unit
                            </label>

                            <select
                                name="unit"
                                class="form-select">

                                <?php

                                $units = [
                                    "piece",
                                    "kg",
                                    "gram",
                                    "litre",
                                    "ml",
                                    "pack",
                                    "box",
                                    "bottle",
                                    "bag",
                                    "dozen"
                                ];

                                foreach ($units as $unit):

                                ?>

                                    <option value="<?= $unit ?>">

                                        <?= ucfirst($unit) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <div class="col-12">

                            <label class="form-label">
                                Description
                            </label>

                            <textarea
                                name="description"
                                rows="4"
                                class="form-control"
                                placeholder="Describe the product..."></textarea>

                        </div>


                        <div class="col-12">

                            <label class="form-label fw-bold">

                                Product Images

                                <span class="text-muted">
                                    (up to 5)
                                </span>

                            </label>

                            <input
                                type="file"
                                name="images[]"
                                class="form-control"
                                accept=".jpg,.jpeg,.png,.webp"
                                multiple
                                onchange="limitImages(this, 5)">

                            <small class="text-muted">

                                You can select up to 5 images.
                                The first image will automatically become
                                the primary image.

                                JPG, PNG or WEBP — maximum 5MB each.

                            </small>

                        </div>

                    </div>

                </div>


                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-light"
                        data-bs-dismiss="modal">

                        Cancel

                    </button>

                    <button
                        type="submit"
                        class="btn btn-primary-custom">

                        <i class="bi bi-plus-lg me-1"></i>

                        Create Product

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
</script>


<script>

function limitImages(input, maximum) {

    if (input.files.length > maximum) {

        alert(
            "You can select a maximum of " +
            maximum +
            " image(s)."
        );

        input.value = "";
    }
}

</script>

</body>
</html>
