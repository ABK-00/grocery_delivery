<?php

require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/storefront.php';

requireRole('admin');
requireCompanyAccess();

$companyId = currentCompanyId();

$msg = '';
$err = '';

/*
|--------------------------------------------------------------------------
| GET COMPANY
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT *
    FROM companies
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$companyId]);
$company = $stmt->fetch();

if (!$company) {
    die('Company not found.');
}


/*
|--------------------------------------------------------------------------
| MAKE SURE STOREFRONT EXISTS
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT *
    FROM company_storefronts
    WHERE company_id = ?
    LIMIT 1
");

$stmt->execute([$companyId]);
$storefront = $stmt->fetch();

if (!$storefront) {

    $stmt = $conn->prepare("
        INSERT INTO company_storefronts (
            company_id,
            display_name,
            email,
            phone,
            whatsapp,
            primary_color,
            store_status
        )
        VALUES (
            ?, ?, ?, ?, ?,
            '#198754',
            'open'
        )
    ");

    $stmt->execute([
        $companyId,
        $company['company_name'],
        $company['email'],
        $company['phone'],
        $company['phone']
    ]);
}


/*
|--------------------------------------------------------------------------
| UPDATE STOREFRONT
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'update_storefront'
) {

    $displayName = trim($_POST['display_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $whatsapp = trim($_POST['whatsapp'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $primaryColor = trim($_POST['primary_color'] ?? '#198754');
    $storeStatus = $_POST['store_status'] ?? 'open';
    $deliveryInformation = trim($_POST['delivery_information'] ?? '');

    if ($displayName === '') {

        $err = 'Store name is required.';

    } elseif (
        $email !== ''
        && !filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {

        $err = 'Enter a valid store email address.';

    } elseif (
        !in_array($storeStatus, ['open', 'closed'], true)
    ) {

        $err = 'Invalid store status.';

    } elseif (
        !preg_match('/^#[0-9A-Fa-f]{6}$/', $primaryColor)
    ) {

        $err = 'Invalid brand colour.';

    } else {

        try {

            /*
            |--------------------------------------------------------------------------
            | CURRENT STOREFRONT
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                SELECT *
                FROM company_storefronts
                WHERE company_id = ?
                LIMIT 1
            ");

            $stmt->execute([$companyId]);

            $currentStorefront = $stmt->fetch();

            $logo = $currentStorefront['logo'] ?? null;
            $banner = $currentStorefront['banner_image'] ?? null;


            /*
            |--------------------------------------------------------------------------
            | UPLOAD DIRECTORY
            |--------------------------------------------------------------------------
            */

            $uploadDir =
                __DIR__
                . '/../assets/uploads/storefronts/';

            if (!is_dir($uploadDir)) {

                if (
                    !mkdir(
                        $uploadDir,
                        0775,
                        true
                    )
                    && !is_dir($uploadDir)
                ) {
                    throw new RuntimeException(
                        'Could not create storefront upload directory.'
                    );
                }
            }


            /*
            |--------------------------------------------------------------------------
            | ALLOWED IMAGE TYPES
            |--------------------------------------------------------------------------
            */

            $allowedTypes = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp'
            ];


            /*
            |--------------------------------------------------------------------------
            | LOGO UPLOAD
            |--------------------------------------------------------------------------
            */

            if (
                isset($_FILES['logo'])
                && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE
            ) {

                if ($_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException(
                        'Logo upload failed.'
                    );
                }

                if ($_FILES['logo']['size'] > 2 * 1024 * 1024) {
                    throw new RuntimeException(
                        'Logo must not exceed 2MB.'
                    );
                }

                $mime = mime_content_type(
                    $_FILES['logo']['tmp_name']
                );

                if (!isset($allowedTypes[$mime])) {
                    throw new RuntimeException(
                        'Logo must be JPG, PNG or WEBP.'
                    );
                }

                $extension = $allowedTypes[$mime];

                $filename =
                    'company_'
                    . $companyId
                    . '_logo_'
                    . bin2hex(random_bytes(5))
                    . '.'
                    . $extension;

                $destination =
                    $uploadDir
                    . $filename;

                if (
                    !move_uploaded_file(
                        $_FILES['logo']['tmp_name'],
                        $destination
                    )
                ) {
                    throw new RuntimeException(
                        'Could not save the logo.'
                    );
                }

                /*
                 * Delete previous locally uploaded logo.
                 */
                if (
                    $logo
                    && str_starts_with(
                        $logo,
                        'assets/uploads/storefronts/'
                    )
                ) {

                    $oldFile =
                        __DIR__
                        . '/../'
                        . $logo;

                    if (is_file($oldFile)) {
                        @unlink($oldFile);
                    }
                }

                $logo =
                    'assets/uploads/storefronts/'
                    . $filename;
            }


            /*
            |--------------------------------------------------------------------------
            | BANNER UPLOAD
            |--------------------------------------------------------------------------
            */

            if (
                isset($_FILES['banner'])
                && $_FILES['banner']['error'] !== UPLOAD_ERR_NO_FILE
            ) {

                if ($_FILES['banner']['error'] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException(
                        'Banner upload failed.'
                    );
                }

                if ($_FILES['banner']['size'] > 5 * 1024 * 1024) {
                    throw new RuntimeException(
                        'Banner must not exceed 5MB.'
                    );
                }

                $mime = mime_content_type(
                    $_FILES['banner']['tmp_name']
                );

                if (!isset($allowedTypes[$mime])) {
                    throw new RuntimeException(
                        'Banner must be JPG, PNG or WEBP.'
                    );
                }

                $extension = $allowedTypes[$mime];

                $filename =
                    'company_'
                    . $companyId
                    . '_banner_'
                    . bin2hex(random_bytes(5))
                    . '.'
                    . $extension;

                $destination =
                    $uploadDir
                    . $filename;

                if (
                    !move_uploaded_file(
                        $_FILES['banner']['tmp_name'],
                        $destination
                    )
                ) {
                    throw new RuntimeException(
                        'Could not save the banner.'
                    );
                }

                if (
                    $banner
                    && str_starts_with(
                        $banner,
                        'assets/uploads/storefronts/'
                    )
                ) {

                    $oldFile =
                        __DIR__
                        . '/../'
                        . $banner;

                    if (is_file($oldFile)) {
                        @unlink($oldFile);
                    }
                }

                $banner =
                    'assets/uploads/storefronts/'
                    . $filename;
            }


            /*
            |--------------------------------------------------------------------------
            | UPDATE DATABASE
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                UPDATE company_storefronts

                SET
                    display_name = ?,
                    description = ?,
                    logo = ?,
                    banner_image = ?,
                    email = ?,
                    phone = ?,
                    whatsapp = ?,
                    address = ?,
                    primary_color = ?,
                    store_status = ?,
                    delivery_information = ?

                WHERE company_id = ?
            ");

            $stmt->execute([
                $displayName,
                $description !== '' ? $description : null,
                $logo,
                $banner,
                $email !== '' ? $email : null,
                $phone !== '' ? $phone : null,
                $whatsapp !== '' ? $whatsapp : null,
                $address !== '' ? $address : null,
                $primaryColor,
                $storeStatus,
                $deliveryInformation !== ''
                    ? $deliveryInformation
                    : null,
                $companyId
            ]);

            header(
                'Location: storefront.php?updated=1'
            );

            exit;

        } catch (Throwable $e) {

            $err =
                'Could not update storefront: '
                . $e->getMessage();
        }
    }
}


/*
|--------------------------------------------------------------------------
| SUCCESS MESSAGE
|--------------------------------------------------------------------------
*/

if (($_GET['updated'] ?? '') === '1') {
    $msg = 'Storefront updated successfully.';
}


/*
|--------------------------------------------------------------------------
| RELOAD STOREFRONT
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT *
    FROM company_storefronts
    WHERE company_id = ?
    LIMIT 1
");

$stmt->execute([$companyId]);
$storefront = $stmt->fetch();


/*
|--------------------------------------------------------------------------
| STOREFRONT URL
|--------------------------------------------------------------------------
*/

$storeUrl =
    '../store.php?store='
    . urlencode($company['storefront_slug']);

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
        Storefront | Admin
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <style>

        body {
            background: #f5f7fa;
        }

        .main-content {
            padding: 30px;
        }

        .store-card {
            background: var(--bs-body-bg);
            border-radius: 18px;
            border: 1px solid rgba(0,0,0,.08);
            box-shadow: 0 8px 25px rgba(0,0,0,.05);
        }

        .preview-banner {
            width: 100%;
            height: 230px;
            object-fit: cover;
            border-radius: 15px;
            background: #e9ecef;
        }

        .preview-logo {
            width: 95px;
            height: 95px;
            object-fit: cover;
            border-radius: 20px;
            background: white;
            border: 4px solid white;
            box-shadow: 0 5px 20px rgba(0,0,0,.15);
        }

        .color-preview {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            border: 1px solid #ddd;
        }

        @media(max-width: 900px) {

            .main-content {
                padding: 20px 15px;
            }

        }

    </style>

</head>

<body>

<?php

include '../includes/loader.php';

/*
 * If your project already has admin_sidebar.php,
 * this will use it.
 */
if (file_exists('../includes/admin_sidebar.php')) {
    include '../includes/admin_sidebar.php';
}

?>


<main class="main-content">

    <div
        class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4"
    >

        <div>

            <h2 class="fw-bold mb-1">
                Storefront
            </h2>

            <p class="text-muted mb-0">
                Manage how customers see your business.
            </p>

        </div>


        <a
            href="<?= htmlspecialchars($storeUrl) ?>"
            target="_blank"
            class="btn btn-outline-success"
        >

            <i class="bi bi-box-arrow-up-right me-1"></i>

            View Storefront

        </a>

    </div>


    <?php if ($msg): ?>

        <div
            class="alert alert-success alert-dismissible fade show"
        >

            <i class="bi bi-check-circle me-2"></i>

            <?= htmlspecialchars($msg) ?>

            <button
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <?php if ($err): ?>

        <div
            class="alert alert-danger alert-dismissible fade show"
        >

            <?= htmlspecialchars($err) ?>

            <button
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <div class="row g-4">

        <!-- =================================================
             FORM
        ================================================== -->

        <div class="col-xl-8">

            <form
                method="POST"
                enctype="multipart/form-data"
                class="store-card p-4"
            >

                <input
                    type="hidden"
                    name="action"
                    value="update_storefront"
                >


                <h5 class="fw-bold mb-4">
                    Store Information
                </h5>


                <div class="row g-3">

                    <div class="col-md-8">

                        <label class="form-label">
                            Store Display Name *
                        </label>

                        <input
                            type="text"
                            name="display_name"
                            class="form-control"
                            value="<?= htmlspecialchars(
                                $storefront['display_name'] ?? ''
                            ) ?>"
                            required
                        >

                    </div>


                    <div class="col-md-4">

                        <label class="form-label">
                            Store Status
                        </label>

                        <select
                            name="store_status"
                            class="form-select"
                        >

                            <option
                                value="open"
                                <?= ($storefront['store_status'] ?? '')
                                    === 'open'
                                    ? 'selected'
                                    : '' ?>
                            >
                                Open
                            </option>

                            <option
                                value="closed"
                                <?= ($storefront['store_status'] ?? '')
                                    === 'closed'
                                    ? 'selected'
                                    : '' ?>
                            >
                                Closed
                            </option>

                        </select>

                    </div>


                    <div class="col-12">

                        <label class="form-label">
                            Store Description
                        </label>

                        <textarea
                            name="description"
                            class="form-control"
                            rows="4"
                            placeholder="Tell customers about your business..."
                        ><?= htmlspecialchars(
                            $storefront['description'] ?? ''
                        ) ?></textarea>

                    </div>


                    <div class="col-md-6">

                        <label class="form-label">
                            Store Logo
                        </label>

                        <input
                            type="file"
                            name="logo"
                            class="form-control"
                            accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                        >

                        <small class="text-muted">
                            JPG, PNG or WEBP. Maximum 2MB.
                        </small>

                    </div>


                    <div class="col-md-6">

                        <label class="form-label">
                            Store Banner
                        </label>

                        <input
                            type="file"
                            name="banner"
                            class="form-control"
                            accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                        >

                        <small class="text-muted">
                            Recommended: wide landscape image. Maximum 5MB.
                        </small>

                    </div>


                    <div class="col-12">
                        <hr>
                    </div>


                    <div class="col-md-6">

                        <label class="form-label">
                            Store Email
                        </label>

                        <input
                            type="email"
                            name="email"
                            class="form-control"
                            value="<?= htmlspecialchars(
                                $storefront['email'] ?? ''
                            ) ?>"
                        >

                    </div>


                    <div class="col-md-6">

                        <label class="form-label">
                            Phone
                        </label>

                        <input
                            type="text"
                            name="phone"
                            class="form-control"
                            value="<?= htmlspecialchars(
                                $storefront['phone'] ?? ''
                            ) ?>"
                        >

                    </div>


                    <div class="col-md-6">

                        <label class="form-label">
                            WhatsApp
                        </label>

                        <input
                            type="text"
                            name="whatsapp"
                            class="form-control"
                            value="<?= htmlspecialchars(
                                $storefront['whatsapp'] ?? ''
                            ) ?>"
                            placeholder="e.g. 233241234567"
                        >

                    </div>


                    <div class="col-md-6">

                        <label class="form-label">
                            Brand Colour
                        </label>

                        <div class="input-group">

                            <input
                                type="color"
                                name="primary_color"
                                id="primaryColor"
                                class="form-control form-control-color"
                                value="<?= htmlspecialchars(
                                    $storefront['primary_color']
                                    ?? '#198754'
                                ) ?>"
                            >

                            <input
                                type="text"
                                id="colorText"
                                class="form-control"
                                value="<?= htmlspecialchars(
                                    $storefront['primary_color']
                                    ?? '#198754'
                                ) ?>"
                                readonly
                            >

                        </div>

                    </div>


                    <div class="col-12">

                        <label class="form-label">
                            Address / Location
                        </label>

                        <input
                            type="text"
                            name="address"
                            class="form-control"
                            value="<?= htmlspecialchars(
                                $storefront['address'] ?? ''
                            ) ?>"
                            placeholder="Business location"
                        >

                    </div>


                    <div class="col-12">

                        <label class="form-label">
                            Delivery Information
                        </label>

                        <textarea
                            name="delivery_information"
                            class="form-control"
                            rows="4"
                            placeholder="Delivery areas, times, fees or customer instructions..."
                        ><?= htmlspecialchars(
                            $storefront['delivery_information'] ?? ''
                        ) ?></textarea>

                    </div>


                    <div class="col-12 mt-4">

                        <button
                            type="submit"
                            class="btn btn-success px-4"
                        >

                            <i class="bi bi-floppy me-1"></i>

                            Save Storefront

                        </button>

                    </div>

                </div>

            </form>

        </div>


        <!-- =================================================
             PREVIEW
        ================================================== -->

        <div class="col-xl-4">

            <div class="store-card p-3">

                <h6 class="fw-bold mb-3">
                    Current Storefront
                </h6>


                <?php if (!empty($storefront['banner_image'])): ?>

                    <img
                        src="../<?= htmlspecialchars(
                            $storefront['banner_image']
                        ) ?>"
                        class="preview-banner"
                        alt="Store Banner"
                    >

                <?php else: ?>

                    <div
                        class="preview-banner d-flex align-items-center justify-content-center text-muted"
                    >

                        <div class="text-center">

                            <i class="bi bi-image fs-1"></i>

                            <div>
                                No banner
                            </div>

                        </div>

                    </div>

                <?php endif; ?>


                <div class="px-3">

                    <div
                        style="
                            margin-top:-45px;
                            position:relative;
                            z-index:2;
                        "
                    >

                        <?php if (!empty($storefront['logo'])): ?>

                            <img
                                src="../<?= htmlspecialchars(
                                    $storefront['logo']
                                ) ?>"
                                class="preview-logo"
                                alt="Store Logo"
                            >

                        <?php else: ?>

                            <div
                                class="preview-logo d-flex align-items-center justify-content-center"
                            >

                                <i
                                    class="bi bi-shop fs-2 text-muted"
                                ></i>

                            </div>

                        <?php endif; ?>

                    </div>


                    <h4 class="fw-bold mt-3 mb-1">

                        <?= htmlspecialchars(
                            $storefront['display_name']
                            ?: $company['company_name']
                        ) ?>

                    </h4>


                    <p class="text-muted small">

                        <?= htmlspecialchars(
                            $storefront['description']
                            ?: 'No store description yet.'
                        ) ?>

                    </p>


                    <div class="d-flex align-items-center gap-2 mb-3">

                        <div
                            class="color-preview"
                            style="
                                background:
                                <?= htmlspecialchars(
                                    $storefront['primary_color']
                                    ?? '#198754'
                                ) ?>;
                            "
                        ></div>

                        <small class="text-muted">
                            Brand colour
                        </small>

                    </div>


                    <?php if (
                        ($storefront['store_status'] ?? 'open')
                        === 'open'
                    ): ?>

                        <span class="badge text-bg-success">
                            Store Open
                        </span>

                    <?php else: ?>

                        <span class="badge text-bg-secondary">
                            Store Closed
                        </span>

                    <?php endif; ?>

                </div>

            </div>


            <div class="store-card p-3 mt-3">

                <small class="text-muted d-block mb-1">
                    Storefront Link
                </small>

                <div class="input-group">

                    <input
                        type="text"
                        class="form-control"
                        id="storeUrl"
                        value="<?= htmlspecialchars(
                            $storeUrl
                        ) ?>"
                        readonly
                    >

                    <button
                        type="button"
                        class="btn btn-outline-secondary"
                        id="copyStoreUrl"
                    >

                        <i class="bi bi-copy"></i>

                    </button>

                </div>

            </div>

        </div>

    </div>

</main>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script>

const colorInput =
    document.getElementById('primaryColor');

const colorText =
    document.getElementById('colorText');

if (colorInput && colorText) {

    colorInput.addEventListener(
        'input',
        function () {

            colorText.value =
                colorInput.value.toUpperCase();

        }
    );

}


const copyButton =
    document.getElementById('copyStoreUrl');

if (copyButton) {

    copyButton.addEventListener(
        'click',
        async function () {

            const input =
                document.getElementById('storeUrl');

            try {

                const absoluteUrl =
                    new URL(
                        input.value,
                        window.location.href
                    ).href;

                await navigator.clipboard.writeText(
                    absoluteUrl
                );

                copyButton.innerHTML =
                    '<i class="bi bi-check-lg"></i>';

                setTimeout(function () {

                    copyButton.innerHTML =
                        '<i class="bi bi-copy"></i>';

                }, 1500);

            } catch (error) {

                input.select();
                document.execCommand('copy');

            }

        }
    );

}

</script>

</body>
</html>