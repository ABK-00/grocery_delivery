<?php

require_once __DIR__ . "/config/db.php";

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $name = trim($_POST["name"]);
    $email = trim($_POST["email"]);
    $password = $_POST["password"];

    if ($name === "" || $email === "" || $password === "") {
        $message = "Please fill in all fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
    } elseif (strlen($password) < 6) {
        $message = "Password must be at least 6 characters.";
    } else {

        $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);

        if ($check->fetch()) {

            $message = "An account with this email already exists.";

        } else {

            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $conn->prepare("
                INSERT INTO users 
                (name, email, password, role, status)
                VALUES (?, ?, ?, 'admin', 'active')
            ");

            $stmt->execute([
                $name,
                $email,
                $hashedPassword
            ]);

            $message = "Super Admin created successfully. You can now log in.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Create Super Admin</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
</head>

<body class="bg-light">

<div class="container">
    <div class="row justify-content-center align-items-center"
         style="min-height: 100vh;">

        <div class="col-md-5">

            <div class="card shadow border-0">

                <div class="card-body p-4">

                    <h3 class="text-center mb-2">
                        Grocery Delivery
                    </h3>

                    <p class="text-center text-muted mb-4">
                        Create Super Admin Account
                    </p>

                    <?php if ($message): ?>

                        <div class="alert alert-info">
                            <?= htmlspecialchars($message) ?>
                        </div>

                    <?php endif; ?>

                    <form method="POST">

                        <div class="mb-3">
                            <label class="form-label">
                                Full Name
                            </label>

                            <input
                                type="text"
                                name="name"
                                class="form-control"
                                required
                            >
                        </div>

                        <div class="mb-3">
                            <label class="form-label">
                                Email
                            </label>

                            <input
                                type="email"
                                name="email"
                                class="form-control"
                                required
                            >
                        </div>

                        <div class="mb-4">
                            <label class="form-label">
                                Password
                            </label>

                            <input
                                type="password"
                                name="password"
                                class="form-control"
                                minlength="6"
                                required
                            >
                        </div>

                        <button
                            type="submit"
                            class="btn btn-success w-100"
                        >
                            Create Super Admin
                        </button>

                    </form>

                    <div class="text-center mt-3">
                        <a href="login.php">
                            Back to Login
                        </a>
                    </div>

                </div>

            </div>

        </div>

    </div>
</div>

</body>
</html>