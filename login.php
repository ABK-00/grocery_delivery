<?php

require_once __DIR__ . "/config/db.php";
require_once __DIR__ . "/includes/auth.php";

if (isLoggedIn()) {
    redirectByRole();
}

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($email === "" || $password === "") {

        $message = "Please enter your email and password.";

    } else {

        $stmt = $conn->prepare(
            "SELECT u.*,
                    c.company_name,
                    c.status AS company_status,
                    c.subscription_plan,
                    c.trial_ends_at,
                    c.subscription_ends_at
             FROM users u
             LEFT JOIN companies c ON c.id = u.company_id
             WHERE u.email = ?
             LIMIT 1"
        );

        $stmt->execute([$email]);

        $user = $stmt->fetch();

        if (
            $user &&
            password_verify($password, $user["password"])
        ) {

            if ($user["status"] !== "active") {

                $message = "Your account is not active.";

            } else {

                session_regenerate_id(true);

                $_SESSION["user_id"] = $user["id"];
                $_SESSION["user_name"] = $user["name"];
                $_SESSION["user_email"] = $user["email"];
                $_SESSION["user_role"] = $user["role"];
                $_SESSION["company_id"] = $user["company_id"] ?? null;
                $_SESSION["company_name"] = $user["company_name"] ?? null;

                if ($user["role"] !== "super_admin") {
                    if (!$user["company_id"] || $user["company_status"] !== "active") {
                        session_unset();
                        session_destroy();
                        $message = "Your company account is currently unavailable. Please contact the platform administrator.";
                    } else {
                        $now = new DateTime();
                        $accessUntil = $user["subscription_plan"] === "trial"
                            ? ($user["trial_ends_at"] ?? null)
                            : ($user["subscription_ends_at"] ?? null);

                        if ($accessUntil && new DateTime($accessUntil) < $now) {
                            session_unset();
                            session_destroy();
                            $message = "Your company's subscription has expired. Please renew access.";
                        } else {
                            $_SESSION["company_access"] = "active";
                            redirectByRole();
                        }
                    }
                } else {
                    $_SESSION["company_access"] = "platform";
                    redirectByRole();
                }
            }

        } else {

            $message = "Invalid email or password.";
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1">

    <title>Login - Grocery Delivery</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet">

</head>

<body class="bg-light">
<?php include __DIR__ . '/includes/loader.php'; ?>
<div class="container">

    <div class="row justify-content-center align-items-center"
         style="min-height:100vh;">

        <div class="col-md-5 col-lg-4">

            <div class="card shadow border-0">

                <div class="card-body p-4">

                    <h2 class="text-center mb-2">
                        Grocery Delivery
                    </h2>

                    <p class="text-center text-muted mb-4">
                        Sign in to continue
                    </p>

                    <?php if ($message): ?>

                        <div class="alert alert-danger">
                            <?php echo htmlspecialchars($message); ?>
                        </div>

                    <?php endif; ?>

                    <form method="POST">

                        <div class="mb-3">

                            <label class="form-label">
                                Email Address
                            </label>

                            <input
                                type="email"
                                name="email"
                                class="form-control"
                                required>

                        </div>

                        <div class="mb-3">

                            <label for="password" class="form-label">
                                Password
                            </label>

                            <div class="input-group">
                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    class="form-control"
                                    required>
                                <button
                                    class="btn btn-outline-secondary"
                                    type="button"
                                    id="togglePassword"
                                    aria-label="Toggle password visibility">
                                    <i class="bi bi-eye" id="togglePasswordIcon"></i>
                                </button>
                            </div>

                        </div>

                        <button
                            type="submit"
                            class="btn btn-success w-100">

                            Login

                        </button>

                    </form>

                    <div class="text-center mt-3">

                        Don't have an account?

                        <a href="register.php">
                            Register
                        </a>

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>

<script>
document.getElementById('togglePassword')?.addEventListener('click', function () {
    const passwordInput = document.getElementById('password');
    const icon = document.getElementById('togglePasswordIcon');
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        icon.classList.remove('bi-eye');
        icon.classList.add('bi-eye-slash');
    } else {
        passwordInput.type = 'password';
        icon.classList.remove('bi-eye-slash');
        icon.classList.add('bi-eye');
    }
});
</script>
</body>
</html>