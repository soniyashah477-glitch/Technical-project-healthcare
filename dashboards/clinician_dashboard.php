<?php
// ============================================================
//  dashboards/clinician_dashboard.php
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
$protocol  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$base_path = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
define('BASE_URL', $protocol . '://' . $_SERVER['HTTP_HOST'] . $base_path);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_login();
if ($_SESSION['role'] !== 'clinician' && $_SESSION['role'] !== 'admin') {
    include __DIR__ . '/../includes/403.php'; exit;
}

$uid = (int)$_SESSION['user_id'];
$page_title = 'Clinician Dashboard';

// Today's appointments — only THIS doctor's
$today_appts = [];
$stmt = $conn->prepare(
    "SELECT a.*, u.full_name AS patient_name FROM appointments a
     JOIN patients p ON a.patient_id = p.patient_id
     JOIN users u ON p.user_id = u.user_id
     WHERE a.clinician_id = ? AND a.appointment_date = CURDATE()
     ORDER BY a.appointment_time");
$stmt->bind_param('i', $uid); $stmt->execute();
$r = $stmt->get_result(); while ($row = $r->fetch_assoc()) $today_appts[] = $row; $stmt->close();

// Recent records — only created by THIS doctor
$recent_records = [];
$stmt = $conn->prepare(
    "SELECT hr.*, u.full_name AS patient_name FROM health_records hr
     JOIN patients p ON hr.patient_id = p.patient_id
     JOIN users u ON p.user_id = u.user_id
     WHERE hr.clinician_id = ? ORDER BY hr.created_at DESC LIMIT 8");
$stmt->bind_param('i', $uid); $stmt->execute();
$r = $stmt->get_result(); while ($row = $r->fetch_assoc()) $recent_records[] = $row; $stmt->close();

// Deterioration alerts — only THIS doctor's patients
require_once __DIR__ . '/../modules/ai_engine/deterioration_alert.php';
$high_risk = get_high_risk_patients();

// ============================================================
//  FIXED COUNTS — all scoped to THIS doctor only
// ============================================================

// My patients = patients who have appointments or records with me
$stmt = $conn->prepare(
    "SELECT COUNT(DISTINCT p.patient_id) AS c FROM patients p
     LEFT JOIN appointments a ON a.patient_id = p.patient_id AND a.clinician_id = ?
     LEFT JOIN health_records hr ON hr.patient_id = p.patient_id AND hr.clinician_id = ?
     WHERE a.clinician_id = ? OR hr.clinician_id = ?");
$stmt->bind_param('iiii', $uid, $uid, $uid, $uid); $stmt->execute();
$total_patients = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

// My active prescriptions only
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS c FROM prescriptions WHERE prescribing_clinician_id = ? AND status = 'active'");
$stmt->bind_param('i', $uid); $stmt->execute();
$pending_rx = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$today_count  = count($today_appts);
$alerts_count = count($high_risk);

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>
<div class="page-wrapper">
<?= render_flash() ?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h2 class="fw-bold mb-1">Clinician Dashboard</h2>
        <p class="text-muted mb-0"><?= date('l, d F Y') ?> &mdash; Dr. <?= htmlspecialchars($_SESSION['full_name']) ?></p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/records/create_record.php" class="btn btn-primary"><i class="bi bi-plus-circle me-2"></i>New Record</a>
        <a href="<?= BASE_URL ?>/modules/records/search_records.php" class="btn btn-outline-primary"><i class="bi bi-search me-2"></i>Search Patients</a>
    </div>
</div>

<!-- Stat cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card bg-gradient-primary">
            <div class="stat-icon"><i class="bi bi-calendar-day"></i></div>
            <div><div class="stat-value"><?= $today_count ?></div><div class="stat-label">Today's Appointments</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card bg-gradient-success">
            <div class="stat-icon"><i class="bi bi-people"></i></div>
            <div><div class="stat-value"><?= $total_patients ?></div><div class="stat-label">My Patients</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card bg-gradient-warning">
            <div class="stat-icon"><i class="bi bi-capsule"></i></div>
            <div><div class="stat-value"><?= $pending_rx ?></div><div class="stat-label">My Active Prescriptions</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card bg-gradient-danger">
            <div class="stat-icon"><i class="bi bi-exclamation-triangle"></i></div>
            <div><div class="stat-value"><?= $alerts_count ?></div><div class="stat-label">ICU Alerts</div></div>
        </div>
    </div>
</div>

<?php if (!empty($high_risk)): ?>
<div class="alert alert-danger d-flex align-items-start gap-3 mb-4">
    <i class="bi bi-exclamation-octagon-fill fs-3 flex-shrink-0"></i>
    <div>
        <strong>⚠️ ICU Deterioration Alerts (<?= count($high_risk) ?> patient<?= count($high_risk) > 1 ? 's' : '' ?>)</strong><br>
        <?php foreach ($high_risk as $hr): ?>
        <span class="badge bg-danger me-1">
            <?= htmlspecialchars($hr['full_name']) ?> — Score: <?= round((float)$hr['risk_score'] * 100, 0) ?>%
        </span>
        <?php endforeach; ?>
        <br><small>Immediate clinical review recommended for highlighted patients.</small>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">
    <!-- Today's Schedule -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="section-title mb-0"><i class="bi bi-calendar-check me-2"></i>Today's Schedule</span>
                <a href="<?= BASE_URL ?>/modules/appointments/view_appointments.php" class="btn btn-sm btn-outline-primary">Full Schedule</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($today_appts)): ?>
                <div class="p-4 text-center text-muted"><i class="bi bi-calendar-x fs-2 d-block mb-2"></i>No appointments today.</div>
                <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($today_appts as $a): ?>
                    <div class="list-group-item d-flex align-items-center gap-3 py-3">
                        <div class="text-center bg-success text-white rounded p-2" style="min-width:55px;">
                            <div class="fw-bold"><?= date('H:i', strtotime($a['appointment_time'])) ?></div>
                        </div>
                        <div class="flex-grow-1">
                            <div class="fw-semibold"><?= htmlspecialchars($a['patient_name']) ?></div>
                            <small class="text-muted"><?= htmlspecialchars($a['purpose'] ?? 'No purpose given') ?></small>
                        </div>
                        <?php
                        $sbadge = ['scheduled'=>'primary','completed'=>'success','cancelled'=>'secondary','no_show'=>'warning'];
                        $sc = $sbadge[$a['status']] ?? 'secondary';
                        ?>
                        <span class="badge bg-<?= $sc ?>"><?= ucfirst(str_replace('_',' ',$a['status'])) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Recent Records — only this doctor's -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="section-title mb-0"><i class="bi bi-file-medical me-2"></i>My Recent Records</span>
                <a href="<?= BASE_URL ?>/modules/records/search_records.php" class="btn btn-sm btn-outline-primary">All Records</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recent_records)): ?>
                <div class="p-4 text-center text-muted">
                    <i class="bi bi-file-earmark-x fs-2 d-block mb-2"></i>
                    No records yet. <a href="<?= BASE_URL ?>/modules/records/create_record.php">Create your first record</a>.
                </div>
                <?php else: ?>
                <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light"><tr><th>Patient</th><th>Type</th><th>Diagnosis</th><th>Date</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent_records as $rec): ?>
                    <tr data-href="<?= BASE_URL ?>/modules/records/view_record.php?id=<?= $rec['record_id'] ?>">
                        <td><?= htmlspecialchars($rec['patient_name']) ?></td>
                        <td><span class="badge bg-info text-dark"><?= ucfirst(str_replace('_',' ',$rec['record_type'])) ?></span></td>
                        <td class="text-truncate" style="max-width:130px"><?= htmlspecialchars($rec['diagnosis'] ?? '—') ?></td>
                        <td><small><?= date('d M', strtotime($rec['created_at'])) ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Quick Add Vitals -->
    <div class="col-12">
        <div class="card">
            <div class="card-header"><span class="section-title mb-0"><i class="bi bi-heart-pulse me-2"></i>Quick Add Vital Signs</span></div>
            <div class="card-body">
                <form method="POST" action="<?= BASE_URL ?>/modules/records/create_record.php" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="quick_vitals" value="1">
                    <div class="col-md-3">
                        <label class="form-label">Patient ID</label>
                        <input type="number" name="qv_patient_id" class="form-control" placeholder="e.g. 1" min="1">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Heart Rate</label>
                        <input type="number" name="qv_hr" class="form-control" placeholder="bpm">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">SpO2 (%)</label>
                        <input type="number" name="qv_spo2" class="form-control" placeholder="%" step="0.1">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">MAP (mmHg)</label>
                        <input type="number" name="qv_map" class="form-control" placeholder="mmHg" step="0.1">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Temperature (°C)</label>
                        <input type="number" name="qv_temp" class="form-control" placeholder="°C" step="0.1">
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                        <button type="submit" class="btn btn-success w-100"><i class="bi bi-save"></i></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
