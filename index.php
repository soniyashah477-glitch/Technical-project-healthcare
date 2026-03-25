<?php
// ============================================================
//  index.php  —  Login / Landing Page
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

// Base URL detection
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$base_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
define('BASE_URL', $protocol . '://' . $_SERVER['HTTP_HOST'] . $base_path);

// Already logged in → redirect to dashboard
if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    $dashboards = [
        'patient'    => 'dashboards/patient_dashboard.php',
        'clinician'  => 'dashboards/clinician_dashboard.php',
        'pharmacist' => 'dashboards/pharmacist_dashboard.php',
        'insurer'    => 'dashboards/insurer_dashboard.php',
        'admin'      => 'dashboards/admin_dashboard.php',
    ];
    $dest = $dashboards[$_SESSION['role']] ?? 'index.php';
    header('Location: ' . BASE_URL . '/' . $dest);
    exit;
}

$error = '';
$msg   = $_GET['msg'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Smart Health Record System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="login-wrapper">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-5 col-lg-4">
            <div class="card login-card shadow-lg">
                <div class="card-body p-5">
                    <div class="text-center mb-4">
                        <div class="login-logo mb-2"><i class="bi bi-hospital-fill"></i></div>
                        <h3 class="fw-bold text-primary">SHRS</h3>
                        <p class="text-muted small">Smart Health Record System</p>
                    </div>

                    <?php if ($msg === 'session_expired'): ?>
                    <div class="alert alert-warning alert-sm py-2"><i class="bi bi-clock me-2"></i>Session expired. Please log in again.</div>
                    <?php endif; ?>
                    <?php if ($msg === 'logged_out'): ?>
                    <div class="alert alert-success alert-sm py-2"><i class="bi bi-check-circle me-2"></i>You have been logged out.</div>
                    <?php endif; ?>
                    <?php if ($error): ?>
                    <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form method="POST" action="<?= BASE_URL ?>/auth/login.php" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php
                            if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                            echo $_SESSION['csrf_token'];
                        ?>">

                        <div class="mb-3">
                            <label for="email" class="form-label">Email Address</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                <input type="email" class="form-control" id="email" name="email"
                                       placeholder="you@example.com" required autofocus
                                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="password" class="form-label">Password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                <input type="password" class="form-control" id="password" name="password"
                                       placeholder="••••••••" required>
                                <button class="btn btn-outline-secondary" type="button" id="togglePass">
                                    <i class="bi bi-eye" id="eyeIcon"></i>
                                </button>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-2">
                            <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
                        </button>
                    </form>

                    <hr class="my-4">
                    <div class="text-center">
                        <p class="text-muted small mb-0">Don't have an account?</p>
                        <a href="<?= BASE_URL ?>/register.php" class="btn btn-outline-primary btn-sm mt-2">
                            <i class="bi bi-person-plus me-1"></i>Register
                        </a>
                    </div>

                    <div class="mt-4 p-3 bg-light rounded small text-muted">
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('togglePass').addEventListener('click', function(){
    var p = document.getElementById('password');
    var i = document.getElementById('eyeIcon');
    if (p.type === 'password') { p.type = 'text'; i.className = 'bi bi-eye-slash'; }
    else { p.type = 'password'; i.className = 'bi bi-eye'; }
});
</script>
</body>
</html>
