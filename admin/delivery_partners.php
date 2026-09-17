<?php

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../includes/auth.php";

requireRole("admin");

$message = "";
$error = "";


/* =========================================================
   CREATE DELIVERY PARTNER
   ========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["create_partner"])) {

    $name = trim($_POST["name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $whatsapp = trim($_POST["whatsapp"] ?? "");
    $vehicle_type = trim($_POST["vehicle_type"] ?? "");
    $vehicle_registration = trim($_POST["vehicle_registration"] ?? "");
    $password = $_POST["password"] ?? "";

    if (
        $name === "" ||
        $email === "" ||
        $phone === "" ||
        $vehicle_type === "" ||
        $vehicle_registration === "" ||
        $password === ""
    ) {

        $error = "Please fill in all required fields.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = "Please enter a valid email address.";

    } elseif (strlen($password) < 6) {

        $error = "Password must be at least 6 characters.";

    } else {

        try {

            $check = $conn->prepare("
                SELECT id
                FROM users
                WHERE email = ?
                LIMIT 1
            ");

            $check->execute([$email]);

            if ($check->fetch()) {

                $error = "An account with this email already exists.";

            } else {

                $conn->beginTransaction();

                $hashedPassword = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $stmt = $conn->prepare("
                    INSERT INTO users
                    (
                        name,
                        email,
                        phone,
                        whatsapp,
                        password,
                        role,
                        status
                    )
                    VALUES
                    (?, ?, ?, ?, ?, 'delivery_partner', 'active')
                ");

                $stmt->execute([
                    $name,
                    $email,
                    $phone,
                    $whatsapp !== "" ? $whatsapp : null,
                    $hashedPassword
                ]);

                $userId = $conn->lastInsertId();

                $partnerStmt = $conn->prepare("
                    INSERT INTO delivery_partners
                    (
                        user_id,
                        vehicle_type,
                        vehicle_registration,
                        status
                    )
                    VALUES
                    (?, ?, ?, 'available')
                ");

                $partnerStmt->execute([
                    $userId,
                    $vehicle_type,
                    $vehicle_registration
                ]);

                $conn->commit();

                $message = "Delivery partner added successfully.";
            }

        } catch (PDOException $e) {

            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            $error = "Unable to add delivery partner.";
        }
    }
}


/* =========================================================
   UPDATE DELIVERY PARTNER
   ========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_partner"])) {

    $partnerId = (int)($_POST["partner_id"] ?? 0);
    $userId = (int)($_POST["user_id"] ?? 0);

    $name = trim($_POST["edit_name"] ?? "");
    $email = trim($_POST["edit_email"] ?? "");
    $phone = trim($_POST["edit_phone"] ?? "");
    $whatsapp = trim($_POST["edit_whatsapp"] ?? "");
    $vehicle_type = trim($_POST["edit_vehicle_type"] ?? "");
    $vehicle_registration = trim($_POST["edit_vehicle_registration"] ?? "");

    if (
        $partnerId <= 0 ||
        $userId <= 0 ||
        $name === "" ||
        $email === "" ||
        $phone === "" ||
        $vehicle_type === "" ||
        $vehicle_registration === ""
    ) {

        $error = "Please complete all required fields.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = "Please enter a valid email address.";

    } else {

        try {

            $check = $conn->prepare("
                SELECT id
                FROM users
                WHERE email = ?
                AND id != ?
                LIMIT 1
            ");

            $check->execute([
                $email,
                $userId
            ]);

            if ($check->fetch()) {

                $error = "Another account is already using this email.";

            } else {

                $conn->beginTransaction();

                $stmt = $conn->prepare("
                    UPDATE users
                    SET
                        name = ?,
                        email = ?,
                        phone = ?,
                        whatsapp = ?
                    WHERE id = ?
                    AND role = 'delivery_partner'
                ");

                $stmt->execute([
                    $name,
                    $email,
                    $phone,
                    $whatsapp !== "" ? $whatsapp : null,
                    $userId
                ]);

                $partnerStmt = $conn->prepare("
                    UPDATE delivery_partners
                    SET
                        vehicle_type = ?,
                        vehicle_registration = ?
                    WHERE id = ?
                    AND user_id = ?
                ");

                $partnerStmt->execute([
                    $vehicle_type,
                    $vehicle_registration,
                    $partnerId,
                    $userId
                ]);

                $conn->commit();

                $message = "Delivery partner information updated successfully.";
            }

        } catch (PDOException $e) {

            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            $error = "Unable to update delivery partner.";
        }
    }
}


/* =========================================================
   TOGGLE AVAILABILITY
   ========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["toggle_status"])) {

    $partnerId = (int)($_POST["partner_id"] ?? 0);

    try {

        $stmt = $conn->prepare("
            SELECT status
            FROM delivery_partners
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([$partnerId]);

        $partner = $stmt->fetch();

        if ($partner) {

            $newStatus =
                $partner["status"] === "available"
                ? "offline"
                : "available";

            $update = $conn->prepare("
                UPDATE delivery_partners
                SET status = ?
                WHERE id = ?
            ");

            $update->execute([
                $newStatus,
                $partnerId
            ]);

            $message = "Partner availability updated.";
        }

    } catch (PDOException $e) {

        $error = "Unable to update availability.";
    }
}


/* =========================================================
   TOGGLE ACCOUNT STATUS
   ========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["toggle_account"])) {

    $userId = (int)($_POST["user_id"] ?? 0);

    try {

        $stmt = $conn->prepare("
            SELECT status
            FROM users
            WHERE id = ?
            AND role = 'delivery_partner'
            LIMIT 1
        ");

        $stmt->execute([$userId]);

        $user = $stmt->fetch();

        if ($user) {

            $newStatus =
                $user["status"] === "active"
                ? "inactive"
                : "active";

            $update = $conn->prepare("
                UPDATE users
                SET status = ?
                WHERE id = ?
                AND role = 'delivery_partner'
            ");

            $update->execute([
                $newStatus,
                $userId
            ]);

            $message = "Account status updated.";
        }

    } catch (PDOException $e) {

        $error = "Unable to update account status.";
    }
}


/* =========================================================
   DELETE PARTNER
   ========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_partner"])) {

    $userId = (int)($_POST["user_id"] ?? 0);

    try {

        $conn->beginTransaction();

        $stmt = $conn->prepare("
            DELETE FROM delivery_partners
            WHERE user_id = ?
        ");

        $stmt->execute([$userId]);

        $stmt = $conn->prepare("
            DELETE FROM users
            WHERE id = ?
            AND role = 'delivery_partner'
        ");

        $stmt->execute([$userId]);

        $conn->commit();

        $message = "Delivery partner deleted successfully.";

    } catch (PDOException $e) {

        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        $error = "Unable to delete delivery partner.";
    }
}


/* =========================================================
   GET DELIVERY PARTNERS
   ========================================================= */

$stmt = $conn->query("
    SELECT
        dp.id AS partner_id,
        dp.user_id,
        dp.vehicle_type,
        dp.vehicle_registration,
        dp.status AS availability,
        dp.created_at,
        u.name,
        u.email,
        u.phone,
        u.whatsapp,
        u.status AS account_status
    FROM delivery_partners dp
    INNER JOIN users u
        ON u.id = dp.user_id
    ORDER BY dp.created_at DESC
");

$partners = $stmt->fetchAll();

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Delivery Partners | GroceryDelivery</title>


    <!-- Bootstrap -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <!-- Bootstrap Icons -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >


    <link
        href="../assets/css/admin.css"
        rel="stylesheet"
    >

</head>


<body>


<!-- =====================================================
     SIDEBAR
     ===================================================== -->

<?php require_once __DIR__ . "/../includes/admin_sidebar.php"; ?>


<!-- =====================================================
     MAIN CONTENT
     ===================================================== -->

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
                    Delivery Partners
                </h1>

                <p class="topbar-subtitle">
                    Manage drivers, vehicles and delivery availability.
                </p>

            </div>

        </div>


        <button
            class="btn btn-success"
            data-bs-toggle="modal"
            data-bs-target="#addPartnerModal"
        >

            <i class="bi bi-plus-lg me-1"></i>

            Add Partner

        </button>

    </div>


    <!-- ALERTS -->

    <?php if ($message): ?>

        <div
            class="alert alert-success alert-dismissible fade show"
            role="alert"
        >

            <i class="bi bi-check-circle-fill me-2"></i>

            <?= htmlspecialchars($message) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <?php if ($error): ?>

        <div
            class="alert alert-danger alert-dismissible fade show"
            role="alert"
        >

            <i class="bi bi-exclamation-triangle-fill me-2"></i>

            <?= htmlspecialchars($error) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <!-- =================================================
         PARTNERS TABLE
         ================================================= -->

    <div class="card">


        <div class="card-header">

            <h5 class="mb-1">
                Delivery Partners
            </h5>

            <small class="text-muted">

                <?= count($partners) ?>
                registered partner(s)

            </small>

        </div>


        <div class="card-body p-0">

            <div class="table-responsive">

                <table class="table">


                    <thead>

                        <tr>

                            <th>
                                Partner
                            </th>

                            <th>
                                Contact
                            </th>

                            <th>
                                Vehicle
                            </th>

                            <th>
                                Availability
                            </th>

                            <th>
                                Account
                            </th>

                            <th>
                                Joined
                            </th>

                            <th class="text-end">
                                Actions
                            </th>

                        </tr>

                    </thead>


                    <tbody>


                    <?php if (!$partners): ?>

                        <tr>

                            <td
                                colspan="7"
                                class="text-center py-5 text-muted"
                            >

                                <i
                                    class="bi bi-bicycle fs-1 d-block mb-3"
                                ></i>

                                No delivery partners have been added yet.

                            </td>

                        </tr>

                    <?php endif; ?>


                    <?php foreach ($partners as $partner): ?>


                        <tr>


                            <!-- PARTNER -->

                            <td>

                                <div class="fw-semibold">

                                    <?= htmlspecialchars(
                                        $partner["name"]
                                    ) ?>

                                </div>

                                <small class="text-muted">

                                    <?= htmlspecialchars(
                                        $partner["email"]
                                    ) ?>

                                </small>

                            </td>


                            <!-- CONTACT -->

                            <td>

                                <div>

                                    <i class="bi bi-telephone me-1"></i>

                                    <?= htmlspecialchars(
                                        $partner["phone"]
                                    ) ?>

                                </div>


                                <?php if (!empty($partner["whatsapp"])): ?>

                                    <small class="text-success">

                                        <i
                                            class="bi bi-whatsapp me-1"
                                        ></i>

                                        <?= htmlspecialchars(
                                            $partner["whatsapp"]
                                        ) ?>

                                    </small>

                                <?php endif; ?>

                            </td>


                            <!-- VEHICLE -->

                            <td>

                                <div class="fw-semibold">

                                    <?= htmlspecialchars(
                                        $partner["vehicle_type"]
                                    ) ?>

                                </div>

                                <small class="text-muted">

                                    <?= htmlspecialchars(
                                        $partner["vehicle_registration"]
                                    ) ?>

                                </small>

                            </td>


                            <!-- AVAILABILITY -->

                            <td>

                                <?php if (
                                    $partner["availability"]
                                    === "available"
                                ): ?>

                                    <span
                                        class="badge bg-success-subtle text-success"
                                    >

                                        <i
                                            class="bi bi-circle-fill me-1"
                                        ></i>

                                        Available

                                    </span>

                                <?php elseif (
                                    $partner["availability"]
                                    === "busy"
                                ): ?>

                                    <span
                                        class="badge bg-warning-subtle text-warning"
                                    >

                                        <i
                                            class="bi bi-circle-fill me-1"
                                        ></i>

                                        Busy

                                    </span>

                                <?php else: ?>

                                    <span
                                        class="badge bg-secondary-subtle text-secondary"
                                    >

                                        <i
                                            class="bi bi-circle-fill me-1"
                                        ></i>

                                        Offline

                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- ACCOUNT -->

                            <td>

                                <?php if (
                                    $partner["account_status"]
                                    === "active"
                                ): ?>

                                    <span class="badge bg-success">
                                        Active
                                    </span>

                                <?php else: ?>

                                    <span class="badge bg-secondary">
                                        Inactive
                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- JOINED -->

                            <td>

                                <?= date(
                                    "M d, Y",
                                    strtotime(
                                        $partner["created_at"]
                                    )
                                ) ?>

                            </td>


                            <!-- ACTIONS -->

                            <td class="text-end">

                                <div class="dropdown">

                                    <button
                                        class="btn btn-sm btn-light border"
                                        data-bs-toggle="dropdown"
                                        type="button"
                                    >

                                        <i
                                            class="bi bi-three-dots"
                                        ></i>

                                    </button>


                                    <ul class="dropdown-menu dropdown-menu-end">


                                        <li>

                                            <button
                                                class="dropdown-item"
                                                data-bs-toggle="modal"
                                                data-bs-target="#editPartner<?= $partner["partner_id"] ?>"
                                                type="button"
                                            >

                                                <i
                                                    class="bi bi-pencil me-2"
                                                ></i>

                                                Edit

                                            </button>

                                        </li>


                                        <li>

                                            <form method="POST">

                                                <input
                                                    type="hidden"
                                                    name="partner_id"
                                                    value="<?= $partner["partner_id"] ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    name="toggle_status"
                                                    class="dropdown-item"
                                                >

                                                    <i
                                                        class="bi bi-toggle-on me-2"
                                                    ></i>

                                                    Toggle Availability

                                                </button>

                                            </form>

                                        </li>


                                        <li>

                                            <form method="POST">

                                                <input
                                                    type="hidden"
                                                    name="user_id"
                                                    value="<?= $partner["user_id"] ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    name="toggle_account"
                                                    class="dropdown-item"
                                                >

                                                    <i
                                                        class="bi bi-person-check me-2"
                                                    ></i>

                                                    Toggle Account

                                                </button>

                                            </form>

                                        </li>


                                        <li>

                                            <hr class="dropdown-divider">

                                        </li>


                                        <li>

                                            <form
                                                method="POST"
                                                onsubmit="return confirm('Are you sure you want to delete this delivery partner?');"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="user_id"
                                                    value="<?= $partner["user_id"] ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    name="delete_partner"
                                                    class="dropdown-item text-danger"
                                                >

                                                    <i
                                                        class="bi bi-trash me-2"
                                                    ></i>

                                                    Delete

                                                </button>

                                            </form>

                                        </li>


                                    </ul>

                                </div>

                            </td>


                        </tr>


                        <!-- =================================================
                             EDIT MODAL
                             ================================================= -->

                        <div
                            class="modal fade"
                            id="editPartner<?= $partner["partner_id"] ?>"
                            tabindex="-1"
                        >

                            <div
                                class="modal-dialog modal-lg modal-dialog-centered"
                            >

                                <div class="modal-content">


                                    <form method="POST">


                                        <div class="modal-header">

                                            <h5 class="modal-title">

                                                <i
                                                    class="bi bi-pencil-square me-2"
                                                ></i>

                                                Edit Delivery Partner

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
                                                name="partner_id"
                                                value="<?= $partner["partner_id"] ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="user_id"
                                                value="<?= $partner["user_id"] ?>"
                                            >


                                            <div class="row g-3">


                                                <div class="col-md-6">

                                                    <label class="form-label">
                                                        Full Name *
                                                    </label>

                                                    <input
                                                        type="text"
                                                        name="edit_name"
                                                        class="form-control"
                                                        value="<?= htmlspecialchars($partner["name"]) ?>"
                                                        required
                                                    >

                                                </div>


                                                <div class="col-md-6">

                                                    <label class="form-label">
                                                        Email *
                                                    </label>

                                                    <input
                                                        type="email"
                                                        name="edit_email"
                                                        class="form-control"
                                                        value="<?= htmlspecialchars($partner["email"]) ?>"
                                                        required
                                                    >

                                                </div>


                                                <div class="col-md-6">

                                                    <label class="form-label">
                                                        Phone *
                                                    </label>

                                                    <input
                                                        type="text"
                                                        name="edit_phone"
                                                        class="form-control"
                                                        value="<?= htmlspecialchars($partner["phone"]) ?>"
                                                        required
                                                    >

                                                </div>


                                                <div class="col-md-6">

                                                    <label class="form-label">
                                                        WhatsApp
                                                    </label>

                                                    <input
                                                        type="text"
                                                        name="edit_whatsapp"
                                                        class="form-control"
                                                        value="<?= htmlspecialchars($partner["whatsapp"] ?? "") ?>"
                                                    >

                                                </div>


                                                <div class="col-md-6">

                                                    <label class="form-label">
                                                        Vehicle Type *
                                                    </label>

                                                    <select
                                                        name="edit_vehicle_type"
                                                        class="form-select"
                                                        required
                                                    >

                                                        <option
                                                            value="Motorcycle"
                                                            <?= $partner["vehicle_type"] === "Motorcycle" ? "selected" : "" ?>
                                                        >
                                                            Motorcycle
                                                        </option>

                                                        <option
                                                            value="Car"
                                                            <?= $partner["vehicle_type"] === "Car" ? "selected" : "" ?>
                                                        >
                                                            Car
                                                        </option>

                                                        <option
                                                            value="Bicycle"
                                                            <?= $partner["vehicle_type"] === "Bicycle" ? "selected" : "" ?>
                                                        >
                                                            Bicycle
                                                        </option>

                                                        <option
                                                            value="Van"
                                                            <?= $partner["vehicle_type"] === "Van" ? "selected" : "" ?>
                                                        >
                                                            Van
                                                        </option>

                                                        <option
                                                            value="Truck"
                                                            <?= $partner["vehicle_type"] === "Truck" ? "selected" : "" ?>
                                                        >
                                                            Truck
                                                        </option>

                                                    </select>

                                                </div>


                                                <div class="col-md-6">

                                                    <label class="form-label">
                                                        Vehicle Registration *
                                                    </label>

                                                    <input
                                                        type="text"
                                                        name="edit_vehicle_registration"
                                                        class="form-control"
                                                        value="<?= htmlspecialchars($partner["vehicle_registration"]) ?>"
                                                        required
                                                    >

                                                </div>


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
                                                name="update_partner"
                                                class="btn btn-success"
                                            >

                                                <i
                                                    class="bi bi-check-lg me-1"
                                                ></i>

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

        </div>

    </div>


</main>


<!-- =====================================================
     ADD PARTNER MODAL
     ===================================================== -->

<div
    class="modal fade"
    id="addPartnerModal"
    tabindex="-1"
>

    <div
        class="modal-dialog modal-lg modal-dialog-centered"
    >

        <div class="modal-content">


            <form method="POST">


                <div class="modal-header">

                    <h5 class="modal-title">

                        <i class="bi bi-bicycle me-2"></i>

                        Add Delivery Partner

                    </h5>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                    ></button>

                </div>


                <div class="modal-body">


                    <div class="row g-3">


                        <div class="col-md-6">

                            <label class="form-label">
                                Full Name *
                            </label>

                            <input
                                type="text"
                                name="name"
                                class="form-control"
                                placeholder="Enter full name"
                                required
                            >

                        </div>


                        <div class="col-md-6">

                            <label class="form-label">
                                Email *
                            </label>

                            <input
                                type="email"
                                name="email"
                                class="form-control"
                                placeholder="partner@example.com"
                                required
                            >

                        </div>


                        <div class="col-md-6">

                            <label class="form-label">
                                Phone *
                            </label>

                            <input
                                type="text"
                                name="phone"
                                class="form-control"
                                placeholder="Phone number"
                                required
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
                                placeholder="WhatsApp number"
                            >

                        </div>


                        <div class="col-md-6">

                            <label class="form-label">
                                Vehicle Type *
                            </label>

                            <select
                                name="vehicle_type"
                                class="form-select"
                                required
                            >

                                <option value="">
                                    Select vehicle
                                </option>

                                <option value="Motorcycle">
                                    Motorcycle
                                </option>

                                <option value="Car">
                                    Car
                                </option>

                                <option value="Bicycle">
                                    Bicycle
                                </option>

                                <option value="Van">
                                    Van
                                </option>

                                <option value="Truck">
                                    Truck
                                </option>

                            </select>

                        </div>


                        <div class="col-md-6">

                            <label class="form-label">
                                Vehicle Registration *
                            </label>

                            <input
                                type="text"
                                name="vehicle_registration"
                                class="form-control"
                                placeholder="e.g. GR 1234-24"
                                required
                            >

                        </div>


                        <div class="col-md-6">

                            <label class="form-label">
                                Login Password *
                            </label>

                            <input
                                type="password"
                                name="password"
                                class="form-control"
                                placeholder="Minimum 6 characters"
                                minlength="6"
                                required
                            >

                        </div>


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
                        name="create_partner"
                        class="btn btn-success"
                    >

                        <i class="bi bi-plus-lg me-1"></i>

                        Add Partner

                    </button>

                </div>


            </form>

        </div>

    </div>

</div>


</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>