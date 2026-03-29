<?php
// ============================================================
//  dashboards/pharmacist_dashboard.php
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
$protocol  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$base_path = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
define('BASE_URL', $protocol . '://' . $_SERVER['HTTP_HOST'] . $base_path);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_login();
if ($_SESSION['role'] !== 'pharmacist' && $_SESSION['role'] !== 'admin') {
    include __DIR__ . '/../includes/403.php'; exit;
}

$uid = (int)$_SESSION['user_id'];
$page_title = 'Pharmacist Dashboard';

// Handle dispense action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dispense_id'])) {
    if (!csrf_validate()) { flash('error', 'Invalid CSRF token.'); }
    else {
        $dispense_id = (int)$_POST['dispense_id'];
        $stmt = $conn->prepare("UPDATE prescriptions SET status='dispensed', dispensed_by=?, dispensed_at=NOW() WHERE prescription_id=? AND status='active'");
        $stmt->bind_param('ii', $uid, $dispense_id);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            flash('success', 'Prescription #' . $dispense_id . ' marked as dispensed.');
            require_once __DIR__ . '/../modules/blockchain/audit_logger.php';
            log_transaction('RecordUpdate', $uid, $dispense_id, ['action'=>'prescription_dispensed','prescription_id'=>$dispense_id], $_SERVER['REMOTE_ADDR'] ?? '');
        } else {
            flash('warning', 'Prescription not updated. It may already be dispensed.');
        }
        $stmt->close();
    }
    header('Location: ' . BASE_URL . '/dashboards/pharmacist_dashboard.php');
    exit;
}

// Pending prescriptions — ALL active ones (pharmacist needs to see queue to dispense)
$pending = [];
$stmt = $conn->prepare(
    "SELECT pr.*, u_p.full_name AS patient_name, u_c.full_name AS clinician_name
     FROM prescriptions pr
     JOIN patients p ON pr.patient_id = p.patient_id
     JOIN users u_p ON p.user_id = u_p.user_id
     JOIN users u_c ON pr.prescribing_clinician_id = u_c.user_id
     WHERE pr.status = 'active'
     ORDER BY pr.created_at DESC LIMIT 20");
$stmt->execute();
$r = $stmt->get_result(); while ($row = $r->fetch_assoc()) $pending[] = $row; $stmt->close();

// ============================================================
//  FIXED COUNTS — scoped to THIS pharmacist only
// ============================================================

// Dispensed TODAY by this pharmacist only
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM prescriptions WHERE dispensed_by=? AND DATE(dispensed_at)=CURDATE()");
$stmt->bind_param('i', $uid); $stmt->execute();
$dispensed_today = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

// Total dispensed EVER by this pharmacist
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM prescriptions WHERE dispensed_by=?");
$stmt->bind_param('i', $uid); $stmt->execute();
$my_total_dispensed = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

// Total currently pending in the queue (all, so pharmacist knows workload)
$total_pending = count($pending);

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>
<div class="page-wrapper">
<?= render_flash() ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="fw-bold mb-1">Pharmacist Dashboard</h2>
        <p class="text-muted mb-0"><?= date('l, d F Y') ?> — <?= htmlspecialchars($_SESSION['full_name']) ?></p>
    </div>
    <a href="<?= BASE_URL ?>/modules/prescriptions/view_prescriptions.php" class="btn btn-outline-primary">
        <i class="bi bi-list-ul me-2"></i>All Prescriptions
    </a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-card bg-gradient-warning">
            <div class="stat-icon"><i class="bi bi-hourglass-split"></i></div>
            <div><div class="stat-value"><?= $total_pending ?></div><div class="stat-label">Pending in Queue</div></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card bg-gradient-success">
            <div class="stat-icon"><i class="bi bi-check2-circle"></i></div>
            <div><div class="stat-value"><?= $dispensed_today ?></div><div class="stat-label">I Dispensed Today</div></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card bg-gradient-primary">
            <div class="stat-icon"><i class="bi bi-capsule"></i></div>
            <div><div class="stat-value"><?= $my_total_dispensed ?></div><div class="stat-label">My Total Dispensed</div></div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><span class="section-title mb-0"><i class="bi bi-list-check me-2"></i>Prescription Dispensing Queue</span></div>
    <div class="card-body p-0">
        <?php if (empty($pending)): ?>
        <div class="p-5 text-center text-muted"><i class="bi bi-check-all fs-1 d-block mb-2 text-success"></i>All prescriptions have been dispensed!</div>
        <?php else: ?>
        <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>#</th><th>Patient</th><th>Medication</th><th>Dosage</th><th>Frequency</th><th>Days</th><th>Prescribed By</th><th>Date</th><th>Action</th></tr>
            </thead>
            <tbody>
            <?php foreach ($pending as $rx): ?>
            <?php
                $allergy_warn = false;
                $stmt_al = $conn->prepare("SELECT allergies FROM patients WHERE patient_id = ?");
                $stmt_al->bind_param('i', $rx['patient_id']); $stmt_al->execute();
                $al_row = $stmt_al->get_result()->fetch_assoc(); $stmt_al->close();
                $allergies = strtolower($al_row['allergies'] ?? '');
                $med_name  = strtolower($rx['medication_name']);
                foreach (explode(',', $allergies) as $alg) {
                    if (trim($alg) && strpos($med_name, trim($alg)) !== false) { $allergy_warn = true; break; }
                }
            ?>
            <tr class="<?= $allergy_warn ? 'table-danger' : '' ?>">
                <td>#<?= $rx['prescription_id'] ?></td>
                <td><?= htmlspecialchars($rx['patient_name']) ?></td>
                <td>
                    <strong><?= htmlspecialchars($rx['medication_name']) ?></strong>
                    <?php if ($allergy_warn): ?>
                    <br><span class="badge bg-danger"><i class="bi bi-exclamation-triangle me-1"></i>ALLERGY ALERT</span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($rx['dosage']) ?></td>
                <td><?= htmlspecialchars($rx['frequency']) ?></td>
                <td><?= $rx['duration_days'] ?></td>
                <td><?= htmlspecialchars($rx['clinician_name']) ?></td>
                <td><small><?= date('d M Y', strtotime($rx['created_at'])) ?></small></td>
                <td>
                    <?php if (!$allergy_warn): ?>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="dispense_id" value="<?= $rx['prescription_id'] ?>">
                        <button type="submit" class="btn btn-success btn-sm"
                                data-confirm="Dispense <?= htmlspecialchars($rx['medication_name']) ?> to <?= htmlspecialchars($rx['patient_name']) ?>?">
                            <i class="bi bi-check-circle me-1"></i>Dispense
                        </button>
                    </form>
                    <?php else: ?>
                    <span class="text-danger small fw-bold">⚠️ Do Not Dispense</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
