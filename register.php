<?php

require_once __DIR__ . "/config/db.php";

session_start();

$message = "";
$messageType = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $name = trim($_POST["name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $companyCode = strtoupper(trim($_POST["company_code"] ?? ""));
    $password = $_POST["password"] ?? "";
    $confirmPassword = $_POST["confirm_password"] ?? "";

    if ($name === "" || $email === "" || $phone === "" || $companyCode === "" || $password === "") {

        $message = "Please fill in all fields.";
        $messageType = "danger";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = "Please enter a valid email address.";
        $messageType = "danger";

    } elseif ($password !== $confirmPassword) {

        $message = "Passwords do not match.";
        $messageType = "danger";

    } elseif (strlen($password) < 6) {

        $message = "Password must be at least 6 characters.";
        $messageType = "danger";

    } else {

        $companyStmt = $conn->prepare("SELECT id FROM companies WHERE company_code=? AND status='active' AND ((subscription_plan='trial' AND trial_ends_at>=NOW()) OR (subscription_plan<>'trial' AND subscription_ends_at>=NOW())) LIMIT 1");
        $companyStmt->execute([$companyCode]);
        $companyId = $companyStmt->fetchColumn();

        if (!$companyId) {
            $message = "Invalid company code, or this company does not currently have platform access.";
            $messageType = "danger";
        } else {

        $check = $conn->prepare(
            "SELECT id FROM users WHERE email = ?"
        );

        $check->execute([$email]);

        if ($check->fetch()) {

            $message = "An account with this email already exists.";
            $messageType = "danger";

        } else {

            $hashedPassword = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            $stmt = $conn->prepare(
                "INSERT INTO users
                (company_id, name, email, phone, password, role, status)
                VALUES (?, ?, ?, ?, ?, 'customer', 'active')"
            );

            $stmt->execute([
                $companyId,
                $name,
                $email,
                $phone,
                $hashedPassword
            ]);

            $message = "Registration successful! You can now log in.";
            $messageType = "success";
        }
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

    <title>Create Account - Grocery Delivery</title>

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

        <div class="col-md-6 col-lg-5">

            <div class="card shadow border-0">

                <div class="card-body p-4">

                    <h2 class="text-center mb-2">
                        Create Account
                    </h2>

                    <p class="text-center text-muted mb-4">
                        Join our grocery delivery service
                    </p>

                    <?php if ($message): ?>

                        <div class="alert alert-<?php echo $messageType; ?>">
                            <?php echo htmlspecialchars($message); ?>
                        </div>

                    <?php endif; ?>

                    <form method="POST">

                        <div class="mb-3">
                            <label class="form-label">Company Code</label>
                            <input type="text" name="company_code" class="form-control" placeholder="e.g. DEFAULT001" required>
                            <div class="form-text">Enter the code provided by your grocery company.</div>
                        </div>

                        <div class="mb-3">

                            <label class="form-label">
                                Full Name
                            </label>

                            <input
                                type="text"
                                name="name"
                                class="form-control"
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
                                required>

                        </div>

                        <div class="mb-3">

                            <label class="form-label">
                                Phone Number
                            </label>

                            <input
                                type="text"
                                name="phone"
                                class="form-control"
                                required>

                        </div>

                        <div class="mb-3">

                            <label for="reg-password" class="form-label">
                                Password
                            </label>

                            <div class="input-group">
                                <input
                                    type="password"
                                    id="reg-password"
                                    name="password"
                                    class="form-control"
                                    required>
                                <button
                                    class="btn btn-outline-secondary toggle-password-btn"
                                    type="button"
                                    data-target="reg-password"
                                    aria-label="Toggle password visibility">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>

                        </div>

                        <div class="mb-3">

                            <label for="reg-confirm-password" class="form-label">
                                Confirm Password
                            </label>

                            <div class="input-group">
                                <input
                                    type="password"
                                    id="reg-confirm-password"
                                    name="confirm_password"
                                    class="form-control"
                                    required>
                                <button
                                    class="btn btn-outline-secondary toggle-password-btn"
                                    type="button"
                                    data-target="reg-confirm-password"
                                    aria-label="Toggle confirm password visibility">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>

                        </div>

                        <button
                            type="submit"
                            class="btn btn-success w-100">

                            Create Account

                        </button>

                    </form>

                    <div class="text-center mt-3">

                        Already have an account?

                        <a href="login.php">
                            Login
                        </a>

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>

<script>
document.querySelectorAll('.toggle-password-btn').forEach(button => {
    button.addEventListener('click', function () {
        const targetId = this.getAttribute('data-target');
        const input = document.getElementById(targetId);
        const icon = this.querySelector('i');
        if (input && icon) {
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        }
    });
});
</script>
</body>
</html>