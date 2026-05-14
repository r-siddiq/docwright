<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If already logged in, redirect to admin
if (!empty($_SESSION['staff_id'])) {
    header('Location: /admin.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Email and password are required.';
    } else {
        $stmt = db()->prepare('SELECT * FROM staff WHERE email = ?');
        $stmt->execute([$email]);
        $staff = $stmt->fetch();

        if ($staff && password_verify($password, $staff['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['staff_id'] = $staff['id'];
            header('Location: /admin.php');
            exit;
        } else {
            $error = 'Invalid email or password.';
        }
    }
}

render_header('Login');
?>
<h1 class="page-title">Login</h1>
<?php if ($error): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>
<form method="post">
    <div class="form-field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" required autofocus>
    </div>
    <div class="form-field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>
    </div>
    <button type="submit" class="btn">Log in</button>
</form>
<?php render_footer(); ?>