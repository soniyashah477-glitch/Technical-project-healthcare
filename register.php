<?php
// ============================================================
//  register.php  —  User Registration
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

$protocol  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$base_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
define('BASE_URL', $protocol . '://' . $_SERVER['HTTP_HOST'] . $base_path);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/rbac.php';

$errors  = [];
$success = false;

// ============================================================
//  ROLE SECRET CODES — change these to whatever you want!
// ============================================================
$role_codes = [
    'clinician'  => '123',
    'pharmacist' => '456',
    'insurer'    => '789',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $errors[] = 'Invalid CSRF token. Please try again.';
    } else {
        $full_name   = trim(htmlspecialchars($_POST['full_name']   ?? ''));
        $email       = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?? '');
        $password    = $_POST['password']    ?? '';
        $confirm     = $_POST['confirm_pwd'] ?? '';
        $role        = $_POST['role']        ?? 'patient';
        $institution = trim(htmlspecialchars($_POST['institution'] ?? ''));
        $phone       = trim(htmlspecialchars($_POST['phone']       ?? ''));
        $role_code   = trim($_POST['role_code'] ?? '');

        // Basic validations
        if (empty($full_name))                              $errors[] = 'Full name is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))    $errors[] = 'Invalid email address.';
        if (strlen($password) < 8)                         $errors[] = 'Password must be at least 8 characters.';
        if ($password !== $confirm)                        $errors[] = 'Passwords do not match.';
        if (!in_array($role, ['patient','clinician','pharmacist','insurer'])) $errors[] = 'Invalid role.';

        // Role code validation for non-patients
        if (in_array($role, ['clinician','pharmacist','insurer'])) {
            if (empty($role_code)) {
                $errors[] = 'A registration code is required for ' . ucfirst($role) . '.';
            } elseif ($role_code !== $role_codes[$role]) {
                $errors[] = 'Invalid registration code for ' . ucfirst($role) . '. Please contact your administrator.';
            }
        }

        if (empty($errors)) {
            // Check email uniqueness
            $chk = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
            $chk->bind_param('s', $email);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $errors[] = 'This email address is already registered. Please use a different email or log in.';
            }
            $chk->close();
        }

        if (empty($errors)) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $ins  = $conn->prepare("INSERT INTO users (full_name, email, password_hash, role, institution, phone) VALUES (?, ?, ?, ?, ?, ?)");
            $ins->bind_param('ssssss', $full_name, $email, $hash, $role, $institution, $phone);

            if ($ins->execute()) {
                $new_uid = $conn->insert_id;
                $ins->close();

                // If patient role, create patient record with unique Patient ID
                if ($role === 'patient') {
                    $dob    = $_POST['dob']    ?? null;
                    $gender = $_POST['gender'] ?? null;

                    // Generate unique Patient ID: PAT-XXXXX (zero-padded)
                    $unique_patient_id = 'PAT-' . str_pad($new_uid, 5, '0', STR_PAD_LEFT);

                    $pi = $conn->prepare("INSERT INTO patients (user_id, date_of_birth, gender) VALUES (?, ?, ?)");
                    $pi->bind_param('iss', $new_uid, $dob, $gender);
                    $pi->execute();
                    $pi->close();

                    // Store patient ID in session to show after registration
                    $_SESSION['new_patient_id'] = $unique_patient_id;
                }

                $success = true;
                flash('success', 'Registration successful! You can now log in.');
            } else {
                $ins->close();
                $errors[] = 'Registration failed. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register — SHRS</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="login-wrapper">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-6">
            <div class="card shadow-lg" style="border-radius:20px;">
                <div class="card-body p-5">
                    <div class="text-center mb-4">
                        <div class="login-logo mb-2"><i class="bi bi-person-plus-fill"></i></div>
                        <h3 class="fw-bold text-primary">Create Account</h3>
                        <p class="text-muted small">Smart Health Record System</p>
                    </div>

                    <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle me-2"></i>Registration successful!
                        <?php if (!empty($_SESSION['new_patient_id'])): ?>
                        <div class="mt-2 p-2 bg-light rounded text-center">
                            <strong>Your Unique Patient ID:</strong><br>
                            <span class="fs-4 fw-bold text-primary"><?= htmlspecialchars($_SESSION['new_patient_id']) ?></span><br>
                            <small class="text-muted">Please save this ID — doctors will use it to find your records.</small>
                        </div>
                        <?php unset($_SESSION['new_patient_id']); ?>
                        <?php endif; ?>
                        <div class="mt-2">
                            <a href="<?= BASE_URL ?>/index.php" class="alert-link">Click here to log in</a>.
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php foreach ($errors as $err): ?>
                    <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($err) ?></div>
                    <?php endforeach; ?>

                    <?php if (!$success): ?>
                    <form method="POST" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Full Name *</label>
                                <input type="text" name="full_name" class="form-control" required value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email *</label>
                                <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Password *</label>
                                <input type="password" name="password" class="form-control" required minlength="8">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Confirm Password *</label>
                                <input type="password" name="confirm_pwd" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Role *</label>
                                <select name="role" class="form-select" id="roleSelect">
                                    <option value="patient"    <?= ($_POST['role'] ?? '') === 'patient'    ? 'selected' : '' ?>>Patient</option>
                                    <option value="clinician"  <?= ($_POST['role'] ?? '') === 'clinician'  ? 'selected' : '' ?>>Clinician</option>
                                    <option value="pharmacist" <?= ($_POST['role'] ?? '') === 'pharmacist' ? 'selected' : '' ?>>Pharmacist</option>
                                    <option value="insurer"    <?= ($_POST['role'] ?? '') === 'insurer'    ? 'selected' : '' ?>>Insurer</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone</label>
                                <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Institution / Hospital</label>
                                <input type="text" name="institution" class="form-control" value="<?= htmlspecialchars($_POST['institution'] ?? '') ?>">
                            </div>

                            <!-- Role Code Field (shown only for Clinician, Pharmacist, Insurer) -->
                            <div class="col-12" id="roleCodeField" style="display:none;">
                                <label class="form-label">Registration Code *</label>
                                <input type="password" name="role_code" class="form-control" placeholder="Enter the code provided by your administrator" value="">
                                <div class="form-text text-muted"><i class="bi bi-shield-lock me-1"></i>This code is required to register as a healthcare professional.</div>
                            </div>

                            <!-- Patient-only fields -->
                            <div id="patientFields">
                                <div class="col-md-6 mt-3">
                                    <label class="form-label">Date of Birth</label>
                                    <input type="date" name="dob" class="form-control" value="<?= htmlspecialchars($_POST['dob'] ?? '') ?>">
                                </div>
                                <div class="col-md-6 mt-3">
                                    <label class="form-label">Gender</label>
                                    <select name="gender" class="form-select">
                                        <option value="">— Select —</option>
                                        <option value="male">Male</option>
                                        <option value="female">Female</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="col-12 mt-2">
                                    <div class="alert alert-info py-2 mb-0">
                                        <i class="bi bi-info-circle me-2"></i>
                                        A unique <strong>Patient ID</strong> (e.g. PAT-00001) will be generated for you after registration. Doctors can use it to find your records.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-2 mt-4">
                            <i class="bi bi-person-check me-2"></i>Register
                        </button>
                    </form>
                    <?php endif; ?>

                    <div class="text-center mt-3">
                        <a href="<?= BASE_URL ?>/index.php" class="text-muted small"><i class="bi bi-arrow-left me-1"></i>Back to Login</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('roleSelect').addEventListener('change', function(){
    var role = this.value;
    // Show/hide patient fields
    document.getElementById('patientFields').style.display = (role === 'patient') ? '' : 'none';
    // Show/hide role code field for professionals
    document.getElementById('roleCodeField').style.display = (['clinician','pharmacist','insurer'].includes(role)) ? '' : 'none';
});
document.getElementById('roleSelect').dispatchEvent(new Event('change'));
</script>
</body>
</html>
