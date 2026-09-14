<?php

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../includes/auth.php";

requireRole("admin");

$message = "";
$messageType = "success";

/*
|--------------------------------------------------------------------------
| CREATE STAFF
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["create_staff"])) {

    $name = trim($_POST["name"]);
    $email = trim($_POST["email"]);
    $phone = trim($_POST["phone"]);
    $whatsapp = trim($_POST["whatsapp"]);
    $password = $_POST["password"];

    if ($name === "" || $email === "" || $phone === "" || $password === "") {

        $message = "Name, email, phone number and password are required.";
        $messageType = "danger";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = "Please enter a valid email address.";
        $messageType = "danger";

    } elseif (strlen($password) < 6) {

        $message = "Password must be at least 6 characters.";
        $messageType = "danger";

    } else {

        $check = $conn->prepare("
            SELECT id
            FROM users
            WHERE email = ?
        ");

        $check->execute([$email]);

        if ($check->fetch()) {

            $message = "An account with this email already exists.";
            $messageType = "danger";

        } else {

            $hashedPassword = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            $stmt = $conn->prepare("
                INSERT INTO users
                (name, email, phone, whatsapp, password, role, status)
                VALUES (?, ?, ?, ?, ?, 'staff', 'active')
            ");

            $stmt->execute([
                $name,
                $email,
                $phone,
                $whatsapp,
                $hashedPassword
            ]);

            $message = "Staff account created successfully.";
        }
    }
}


/*
|--------------------------------------------------------------------------
| UPDATE STAFF
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_staff"])) {

    $staffId = (int) $_POST["staff_id"];

    $name = trim($_POST["name"]);
    $email = trim($_POST["email"]);
    $phone = trim($_POST["phone"]);
    $whatsapp = trim($_POST["whatsapp"]);

    if ($name === "" || $email === "" || $phone === "") {

        $message = "Name, email and phone number are required.";
        $messageType = "danger";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = "Please enter a valid email address.";
        $messageType = "danger";

    } else {

        $check = $conn->prepare("
            SELECT id
            FROM users
            WHERE email = ?
            AND id != ?
        ");

        $check->execute([
            $email,
            $staffId
        ]);

        if ($check->fetch()) {

            $message = "Another account is already using this email.";
            $messageType = "danger";

        } else {

            $stmt = $conn->prepare("
                UPDATE users
                SET
                    name = ?,
                    email = ?,
                    phone = ?,
                    whatsapp = ?
                WHERE id = ?
                AND role = 'staff'
            ");

            $stmt->execute([
                $name,
                $email,
                $phone,
                $whatsapp,
                $staffId
            ]);

            $message = "Staff information updated successfully.";
        }
    }
}


/*
|--------------------------------------------------------------------------
| CHANGE STATUS
|--------------------------------------------------------------------------
*/

if (isset($_GET["toggle"])) {

    $staffId = (int) $_GET["toggle"];

    $stmt = $conn->prepare("
        SELECT status
        FROM users
        WHERE id = ?
        AND role = 'staff'
    ");

    $stmt->execute([$staffId]);

    $staff = $stmt->fetch();

    if ($staff) {

        $newStatus = $staff["status"] === "active"
            ? "inactive"
            : "active";

        $update = $conn->prepare("
            UPDATE users
            SET status = ?
            WHERE id = ?
            AND role = 'staff'
        ");

        $update->execute([
            $newStatus,
            $staffId
        ]);
    }

    header("Location: staff.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| DELETE STAFF
|--------------------------------------------------------------------------
*/

if (isset($_GET["delete"])) {

    $staffId = (int) $_GET["delete"];

    $stmt = $conn->prepare("
        DELETE FROM users
        WHERE id = ?
        AND role = 'staff'
    ");

    $stmt->execute([$staffId]);

    header("Location: staff.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| GET STAFF
|--------------------------------------------------------------------------
*/

$stmt = $conn->query("
    SELECT
        id,
        name,
        email,
        phone,
        whatsapp,
        status,
        created_at
    FROM users
    WHERE role = 'staff'
    ORDER BY id DESC
");

$staffMembers = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Staff Management | Grocery Delivery</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet">    <link
        href="../assets/css/admin.css"
        rel="stylesheet">

    <style>
        .table-card {
            background: white;
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 3px 15px rgba(0,0,0,.04);
        }

        .avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: #dcfce7;
            color: #16a34a;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        .whatsapp {
            color: #16a34a;
        }
    </style>

</head>

<body>


<!-- SIDEBAR -->

<?php require_once __DIR__ . "/../includes/admin_sidebar.php"; ?>


<!-- MAIN -->

<main class="main-content">

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
                    Staff Management
                </h1>

                <p class="topbar-subtitle">
                    Create and manage shop staff accounts.
                </p>
            </div>

        </div>

        <button
            class="btn btn-success"
            data-bs-toggle="modal"
            data-bs-target="#addStaffModal">

            <i class="bi bi-person-plus me-1"></i>
            Add Staff

        </button>

    </div>


    <?php if ($message): ?>

        <div class="alert alert-<?= $messageType ?> alert-dismissible fade show">

            <?= htmlspecialchars($message) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert">
            </button>

        </div>

    <?php endif; ?>


    <div class="table-card">

        <div class="mb-4">

            <h5 class="mb-1">
                Shop Staff
            </h5>

            <small class="text-muted">
                <?= count($staffMembers) ?> staff account(s)
            </small>

        </div>


        <div class="table-responsive">

            <table class="table align-middle">

                <thead>

                    <tr>

                        <th>Staff</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>WhatsApp</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th class="text-end">Actions</th>

                    </tr>

                </thead>

                <tbody>

                <?php if (!$staffMembers): ?>

                    <tr>

                        <td colspan="7"
                            class="text-center text-muted py-5">

                            <i class="bi bi-person-x fs-1 d-block mb-2"></i>

                            No staff accounts yet.

                        </td>

                    </tr>

                <?php else: ?>

                    <?php foreach ($staffMembers as $staff): ?>

                        <tr>

                            <td>

                                <div class="d-flex align-items-center gap-3">

                                    <div class="avatar">

                                        <?= strtoupper(
                                            substr($staff["name"], 0, 1)
                                        ) ?>

                                    </div>

                                    <strong>
                                        <?= htmlspecialchars($staff["name"]) ?>
                                    </strong>

                                </div>

                            </td>

                            <td>
                                <?= htmlspecialchars($staff["email"]) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($staff["phone"]) ?>
                            </td>

                            <td>

                                <?php if (!empty($staff["whatsapp"])): ?>

                                    <span class="whatsapp">

                                        <i class="bi bi-whatsapp"></i>

                                        <?= htmlspecialchars(
                                            $staff["whatsapp"]
                                        ) ?>

                                    </span>

                                <?php else: ?>

                                    <span class="text-muted">
                                        Not provided
                                    </span>

                                <?php endif; ?>

                            </td>

                            <td>

                                <?php if ($staff["status"] === "active"): ?>

                                    <span class="badge bg-success">
                                        Active
                                    </span>

                                <?php else: ?>

                                    <span class="badge bg-secondary">
                                        Inactive
                                    </span>

                                <?php endif; ?>

                            </td>

                            <td>
                                <?= date(
                                    "d M Y",
                                    strtotime($staff["created_at"])
                                ) ?>
                            </td>

                            <td class="text-end">

                                <!-- EDIT -->

                                <button
                                    class="btn btn-sm btn-outline-primary"
                                    data-bs-toggle="modal"
                                    data-bs-target="#editStaff<?= $staff["id"] ?>">

                                    <i class="bi bi-pencil"></i>

                                </button>


                                <!-- STATUS -->

                                <a
                                    href="?toggle=<?= $staff["id"] ?>"
                                    class="btn btn-sm btn-outline-success">

                                    <i class="bi bi-power"></i>

                                </a>


                                <!-- DELETE -->

                                <a
                                    href="?delete=<?= $staff["id"] ?>"
                                    class="btn btn-sm btn-outline-danger"
                                    onclick="return confirm(
                                        'Are you sure you want to delete this staff account?'
                                    );">

                                    <i class="bi bi-trash"></i>

                                </a>

                            </td>

                        </tr>


                        <!-- EDIT MODAL -->

                        <div class="modal fade"
                             id="editStaff<?= $staff["id"] ?>"
                             tabindex="-1">

                            <div class="modal-dialog modal-dialog-centered">

                                <div class="modal-content border-0 shadow">

                                    <form method="POST">

                                        <div class="modal-header">

                                            <h5 class="modal-title">
                                                Edit Staff Information
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
                                                name="staff_id"
                                                value="<?= $staff["id"] ?>">


                                            <div class="mb-3">

                                                <label class="form-label">
                                                    Full Name
                                                </label>

                                                <input
                                                    type="text"
                                                    name="name"
                                                    class="form-control"
                                                    value="<?= htmlspecialchars(
                                                        $staff["name"]
                                                    ) ?>"
                                                    required>

                                            </div>


                                            <div class="mb-3">

                                                <label class="form-label">
                                                    Email Address
                                                </label>

                                                <input
                                                    type="email"
                                                    name="email"
                                                    class="form-control"
                                                    value="<?= htmlspecialchars(
                                                        $staff["email"]
                                                    ) ?>"
                                                    required>

                                            </div>


                                            <div class="mb-3">

                                                <label class="form-label">
                                                    Phone Number
                                                    <span class="text-danger">*</span>
                                                </label>

                                                <input
                                                    type="tel"
                                                    name="phone"
                                                    class="form-control"
                                                    value="<?= htmlspecialchars(
                                                        $staff["phone"]
                                                    ) ?>"
                                                    required>

                                            </div>


                                            <div class="mb-3">

                                                <label class="form-label">
                                                    WhatsApp Contact
                                                </label>

                                                <input
                                                    type="tel"
                                                    name="whatsapp"
                                                    class="form-control"
                                                    value="<?= htmlspecialchars(
                                                        $staff["whatsapp"] ?? ""
                                                    ) ?>"
                                                    placeholder="WhatsApp number">

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
                                                name="update_staff"
                                                class="btn btn-primary">

                                                <i class="bi bi-check-lg"></i>
                                                Save Changes

                                            </button>

                                        </div>

                                    </form>

                                </div>

                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</main>


<!-- ADD STAFF MODAL -->

<div class="modal fade"
     id="addStaffModal"
     tabindex="-1">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content border-0 shadow">

            <form method="POST">

                <div class="modal-header">

                    <h5 class="modal-title">
                        <i class="bi bi-person-plus text-success"></i>
                        Create Staff Account
                    </h5>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal">
                    </button>

                </div>


                <div class="modal-body">

                    <div class="mb-3">

                        <label class="form-label">
                            Full Name
                            <span class="text-danger">*</span>
                        </label>

                        <input
                            type="text"
                            name="name"
                            class="form-control"
                            placeholder="Enter staff name"
                            required>

                    </div>


                    <div class="mb-3">

                        <label class="form-label">
                            Email Address
                            <span class="text-danger">*</span>
                        </label>

                        <input
                            type="email"
                            name="email"
                            class="form-control"
                            placeholder="staff@example.com"
                            required>

                    </div>


                    <div class="mb-3">

                        <label class="form-label">
                            Phone Number
                            <span class="text-danger">*</span>
                        </label>

                        <input
                            type="tel"
                            name="phone"
                            class="form-control"
                            placeholder="Enter phone number"
                            required>

                    </div>


                    <div class="mb-3">

                        <label class="form-label">
                            WhatsApp Contact
                        </label>

                        <input
                            type="tel"
                            name="whatsapp"
                            class="form-control"
                            placeholder="WhatsApp number (optional)">

                    </div>


                    <div class="mb-3">

                        <label class="form-label">
                            Temporary Password
                            <span class="text-danger">*</span>
                        </label>

                        <input
                            type="password"
                            name="password"
                            class="form-control"
                            minlength="6"
                            required>

                        <small class="text-muted">
                            Minimum 6 characters.
                        </small>

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
                        name="create_staff"
                        class="btn btn-success">

                        <i class="bi bi-check-lg"></i>
                        Create Staff

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>


<script
src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
</script>

</body>
</html>
