<?php
// ============================================================
//  dashboards/patient_dashboard.php
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
$protocol  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$base_path = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
define('BASE_URL', $protocol . '://' . $_SERVER['HTTP_HOST'] . $base_path);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_login();
check_permission('health_records', 'read');

$uid = (int)$_SESSION['user_id'];
$page_title = 'Patient Dashboard';

// Get patient record
$stmt = $conn->prepare("SELECT * FROM patients WHERE user_id = ? LIMIT 1");
$stmt->bind_param('i', $uid); $stmt->execute();
$patient = $stmt->get_result()->fetch_assoc(); $stmt->close();
$pid = $patient ? (int)$patient['patient_id'] : 0;

// Generate unique_patient_id display
$display_pid = $patient['unique_patient_id'] ?? ('PAT-' . str_pad($pid, 5, '0', STR_PAD_LEFT));

// Get dependents for this patient
$dependents = [];
if ($pid) {
    $stmt = $conn->prepare("SELECT * FROM dependents WHERE primary_patient_id = ? ORDER BY created_at ASC");
    $stmt->bind_param('i', $pid); $stmt->execute();
    $r = $stmt->get_result(); while ($row = $r->fetch_assoc()) $dependents[] = $row; $stmt->close();
}

// Which profile are we viewing? (main or a dependent)
$viewing_dependent_id = isset($_GET['dep']) ? (int)$_GET['dep'] : 0;
$viewing_dependent = null;
if ($viewing_dependent_id) {
    foreach ($dependents as $d) {
        if ($d['dependent_id'] === $viewing_dependent_id) { $viewing_dependent = $d; break; }
    }
}

// Upcoming appointments
$appts = [];
if ($pid) {
    if ($viewing_dependent) {
        $stmt = $conn->prepare(
            "SELECT a.*, u.full_name AS clinician_name FROM appointments a
             JOIN users u ON a.clinician_id = u.user_id
             WHERE a.patient_id = ? AND a.dependent_id = ? AND a.appointment_date >= CURDATE() AND a.status = 'scheduled'
             ORDER BY a.appointment_date, a.appointment_time LIMIT 5");
        $stmt->bind_param('ii', $pid, $viewing_dependent_id);
    } else {
        $stmt = $conn->prepare(
            "SELECT a.*, u.full_name AS clinician_name FROM appointments a
             JOIN users u ON a.clinician_id = u.user_id
             WHERE a.patient_id = ? AND (a.dependent_id IS NULL OR a.dependent_id = 0) AND a.appointment_date >= CURDATE() AND a.status = 'scheduled'
             ORDER BY a.appointment_date, a.appointment_time LIMIT 5");
        $stmt->bind_param('i', $pid);
    }
    $stmt->execute();
    $r = $stmt->get_result(); while($row = $r->fetch_assoc()) $appts[] = $row; $stmt->close();
}

// Active prescriptions
$rxs = [];
if ($pid) {
    if ($viewing_dependent) {
        $stmt = $conn->prepare(
            "SELECT p.*, u.full_name AS clinician_name FROM prescriptions p
             JOIN users u ON p.prescribing_clinician_id = u.user_id
             WHERE p.patient_id = ? AND p.dependent_id = ? AND p.status = 'active' ORDER BY p.created_at DESC LIMIT 5");
        $stmt->bind_param('ii', $pid, $viewing_dependent_id);
    } else {
        $stmt = $conn->prepare(
            "SELECT p.*, u.full_name AS clinician_name FROM prescriptions p
             JOIN users u ON p.prescribing_clinician_id = u.user_id
             WHERE p.patient_id = ? AND (p.dependent_id IS NULL OR p.dependent_id = 0) AND p.status = 'active' ORDER BY p.created_at DESC LIMIT 5");
        $stmt->bind_param('i', $pid);
    }
    $stmt->execute();
    $r = $stmt->get_result(); while($row = $r->fetch_assoc()) $rxs[] = $row; $stmt->close();
}

// Recent lab results
$labs = [];
if ($pid) {
    if ($viewing_dependent) {
        $stmt = $conn->prepare("SELECT * FROM lab_results WHERE patient_id = ? AND dependent_id = ? ORDER BY result_date DESC LIMIT 5");
        $stmt->bind_param('ii', $pid, $viewing_dependent_id);
    } else {
        $stmt = $conn->prepare("SELECT * FROM lab_results WHERE patient_id = ? AND (dependent_id IS NULL OR dependent_id = 0) ORDER BY result_date DESC LIMIT 5");
        $stmt->bind_param('i', $pid);
    }
    $stmt->execute();
    $r = $stmt->get_result(); while($row = $r->fetch_assoc()) $labs[] = $row; $stmt->close();
}

// Unread messages
$msgs = [];
$stmt = $conn->prepare(
    "SELECT m.*, u.full_name AS sender_name FROM messages m JOIN users u ON m.sender_id = u.user_id
     WHERE m.receiver_id = ? AND m.is_read = 0 ORDER BY m.sent_at DESC LIMIT 5");
$stmt->bind_param('i', $uid); $stmt->execute();
$r = $stmt->get_result(); while($row = $r->fetch_assoc()) $msgs[] = $row; $stmt->close();

// AI Predictions (only for main patient)
$predictions = [];
if ($pid && !$viewing_dependent) {
    require_once __DIR__ . '/../modules/ai_engine/risk_predictor.php';
    $predictions = get_patient_predictions($pid);
}

// Counts
$appt_count = count($appts);
$rx_count   = count($rxs);
$lab_count  = 0;
if ($pid) {
    if ($viewing_dependent) {
        $r = $conn->query("SELECT COUNT(*) AS c FROM lab_results WHERE patient_id=$pid AND dependent_id=$viewing_dependent_id");
    } else {
        $r = $conn->query("SELECT COUNT(*) AS c FROM lab_results WHERE patient_id=$pid AND (dependent_id IS NULL OR dependent_id=0)");
    }
    $lab_count = (int)$r->fetch_assoc()['c'];
}
$consent_count = 0;
if ($pid) {
    $r = $conn->query("SELECT COUNT(*) AS c FROM consents WHERE patient_id=$pid AND is_active=1");
    $consent_count = (int)$r->fetch_assoc()['c'];
}

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';

function risk_badge(string $level): string {
    return '<span class="risk-badge risk-' . $level . '"><span class="risk-dot"></span>' . ucfirst($level) . '</span>';
}

// Current viewing name
$current_name = $viewing_dependent ? $viewing_dependent['full_name'] : $_SESSION['full_name'];
$current_sub_id = $viewing_dependent ? $viewing_dependent['sub_id'] : $display_pid;
?>
<div class="page-wrapper">
<?= render_flash() ?>

<!-- ============================================================
     PATIENT ID CARD — always visible at top
     ============================================================ -->
<div class="card mb-4 border-0" style="background: linear-gradient(135deg, #0d6efd, #0dcaf0); border-radius:16px;">
    <div class="card-body py-3 px-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="bg-white rounded-circle d-flex align-items-center justify-content-center" style="width:52px;height:52px;">
                    <i class="bi bi-person-badge-fill text-primary fs-4"></i>
                </div>
                <div>
                    <div class="text-white-50 small">Your Patient ID</div>
                    <div class="text-white fw-bold fs-4 font-monospace"><?= htmlspecialchars($display_pid) ?></div>
                    <div class="text-white-50 small">Give this ID to your doctor to find your records</div>
                </div>
            </div>

            <!-- Dependent switcher -->
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <!-- Main account button -->
                <a href="<?= BASE_URL ?>/dashboards/patient_dashboard.php"
                   class="btn btn-sm <?= !$viewing_dependent ? 'btn-light' : 'btn-outline-light' ?>">
                    <i class="bi bi-person me-1"></i><?= htmlspecialchars($_SESSION['full_name']) ?>
                    <span class="badge <?= !$viewing_dependent ? 'bg-primary' : 'bg-light text-dark' ?> ms-1"><?= htmlspecialchars($display_pid) ?></span>
                </a>

                <!-- Dependent buttons -->
                <?php foreach ($dependents as $d): ?>
                <a href="?dep=<?= $d['dependent_id'] ?>"
                   class="btn btn-sm <?= $viewing_dependent_id === $d['dependent_id'] ? 'btn-light' : 'btn-outline-light' ?>">
                    <i class="bi bi-person-heart me-1"></i><?= htmlspecialchars($d['full_name']) ?>
                    <span class="badge <?= $viewing_dependent_id === $d['dependent_id'] ? 'bg-primary' : 'bg-light text-dark' ?> ms-1"><?= htmlspecialchars($d['sub_id']) ?></span>
                </a>
                <?php endforeach; ?>

                <!-- Add dependent button -->
                <button class="btn btn-sm btn-outline-light" data-bs-toggle="modal" data-bs-target="#addDependentModal">
                    <i class="bi bi-person-plus me-1"></i>Add Family Member
                </button>
            </div>
        </div>

        <?php if ($viewing_dependent): ?>
        <div class="mt-2 pt-2 border-top border-white border-opacity-25">
            <span class="text-white"><i class="bi bi-eye me-1"></i>Viewing records for: <strong><?= htmlspecialchars($viewing_dependent['full_name']) ?></strong>
            (<?= htmlspecialchars($viewing_dependent['relationship']) ?>) — ID: <span class="font-monospace"><?= htmlspecialchars($viewing_dependent['sub_id']) ?></span></span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Welcome -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h2 class="fw-bold mb-1">
            <?php if ($viewing_dependent): ?>
            <i class="bi bi-person-heart me-2 text-primary"></i><?= htmlspecialchars($viewing_dependent['full_name']) ?>'s Records
            <?php else: ?>
            Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?>
            <?php endif; ?>
        </h2>
        <p class="text-muted mb-0">
            <?= $viewing_dependent ? 'Viewing family member records' : "Here's your health summary for today" ?> — <?= date('l, d F Y') ?>
        </p>
    </div>
    <a href="<?= BASE_URL ?>/modules/appointments/book_appointment.php<?= $viewing_dependent ? '?dep='.$viewing_dependent_id : '' ?>" class="btn btn-primary">
        <i class="bi bi-calendar-plus me-2"></i>Book Appointment
    </a>
</div>

<style>
.stat-card { background: rgba(255,255,255,0.15) !important; backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.3) !important; color: white !important; }
.bg-gradient-primary { background: rgba(13,110,253,0.75) !important; }
.bg-gradient-success  { background: rgba(25,135,84,0.75) !important; }
.bg-gradient-info     { background: rgba(13,202,240,0.75) !important; }
.bg-gradient-warning  { background: rgba(255,193,7,0.75) !important; }
</style>

<!-- Stat cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card bg-gradient-primary">
            <div class="stat-icon"><i class="bi bi-calendar3"></i></div>
            <div><div class="stat-value"><?= $appt_count ?></div><div class="stat-label">Upcoming Appts</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card bg-gradient-success">
            <div class="stat-icon"><i class="bi bi-capsule"></i></div>
            <div><div class="stat-value"><?= $rx_count ?></div><div class="stat-label">Active Prescriptions</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card bg-gradient-info">
            <div class="stat-icon"><i class="bi bi-flask"></i></div>
            <div><div class="stat-value"><?= $lab_count ?></div><div class="stat-label">Lab Results</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card bg-gradient-warning">
            <div class="stat-icon"><i class="bi bi-shield-check"></i></div>
            <div><div class="stat-value"><?= $consent_count ?></div><div class="stat-label">Active Consents</div></div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- AI Risk Scores — only for main patient -->
    <?php if (!$viewing_dependent): ?>
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="section-title mb-0"><i class="bi bi-robot me-2"></i>AI Health Risk Assessment</span>
                <span class="badge bg-primary">AI-Powered</span>
            </div>
            <div class="card-body">
                <?php if (!$pid): ?>
                <div class="alert alert-warning">Patient profile not found. Please complete your profile.</div>
                <?php elseif (empty($predictions)): ?>
                <div class="alert alert-info">No vitals/lab data available. Ask your clinician to record your vitals.</div>
                <?php else: ?>
                <?php foreach ($predictions as $model => $pred):
                    $label = str_replace(['_risk','_'], [' Risk',' '], $model);
                    $label = ucwords($label);
                    $pct   = round((float)$pred['risk_score'] * 100, 1);
                    $colors = ['low'=>'success','moderate'=>'warning','high'=>'danger'];
                    $col = $colors[$pred['risk_level']] ?? 'secondary';
                ?>
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="fw-semibold small"><?= $label ?></span>
                        <?= risk_badge($pred['risk_level']) ?>
                    </div>
                    <div class="progress risk-progress mb-1">
                        <div class="progress-bar bg-<?= $col ?> risk-bar-fill" role="progressbar"
                             data-score="<?= $pred['risk_score'] ?>"
                             style="width:0%" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
                        </div>
                    </div>
                    <div class="d-flex justify-content-between">
                        <small class="text-muted"><?= $pct ?>% risk score</small>
                        <small class="text-muted"><?= date('d M Y', strtotime($pred['generated_at'])) ?></small>
                    </div>
                    <p class="small text-muted mt-1 mb-0"><i class="bi bi-lightbulb me-1 text-warning"></i><?= htmlspecialchars($pred['recommendation']) ?></p>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
                <div class="text-center mt-3">
                    <a href="<?= BASE_URL ?>/modules/consent/manage_consent.php" class="btn btn-outline-primary btn-sm w-100">
                        <i class="bi bi-shield-lock me-2"></i>Manage Data Consent
                    </a>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
    <?php else: ?>
    <div class="col-12">
    <?php endif; ?>

        <!-- Upcoming Appointments -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="section-title mb-0"><i class="bi bi-calendar-check me-2"></i>Upcoming Appointments</span>
                <a href="<?= BASE_URL ?>/modules/appointments/view_appointments.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($appts)): ?>
                <div class="p-4 text-center text-muted"><i class="bi bi-calendar-x fs-2 d-block mb-2"></i>No upcoming appointments.</div>
                <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($appts as $a): ?>
                    <div class="list-group-item d-flex align-items-center gap-3 py-3">
                        <div class="text-center bg-primary text-white rounded p-2" style="min-width:50px">
                            <div class="fw-bold" style="font-size:1.1rem"><?= date('d', strtotime($a['appointment_date'])) ?></div>
                            <div style="font-size:.7rem"><?= date('M', strtotime($a['appointment_date'])) ?></div>
                        </div>
                        <div class="flex-grow-1">
                            <div class="fw-semibold"><?= htmlspecialchars($a['purpose'] ?? 'Appointment') ?></div>
                            <small class="text-muted"><i class="bi bi-person-badge me-1"></i><?= htmlspecialchars($a['clinician_name']) ?>
                            &nbsp;·&nbsp;<i class="bi bi-clock me-1"></i><?= date('H:i', strtotime($a['appointment_time'])) ?></small>
                        </div>
                        <span class="badge bg-primary">Scheduled</span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Active Prescriptions -->
        <div class="card mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="section-title mb-0"><i class="bi bi-capsule me-2"></i>Active Prescriptions</span>
                <a href="<?= BASE_URL ?>/modules/prescriptions/view_prescriptions.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($rxs)): ?>
                <div class="p-4 text-center text-muted"><i class="bi bi-capsule fs-2 d-block mb-2"></i>No active prescriptions.</div>
                <?php else: ?>
                <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light"><tr><th>Medication</th><th>Dosage</th><th>Frequency</th><th>Duration</th></tr></thead>
                    <tbody>
                    <?php foreach ($rxs as $rx): ?>
                    <tr>
                        <td class="fw-semibold"><?= htmlspecialchars($rx['medication_name']) ?></td>
                        <td><?= htmlspecialchars($rx['dosage']) ?></td>
                        <td><?= htmlspecialchars($rx['frequency']) ?></td>
                        <td><?= $rx['duration_days'] ?> days</td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Recent Lab Results -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="section-title mb-0"><i class="bi bi-clipboard2-pulse me-2"></i>Recent Lab Results</span>
                <a href="<?= BASE_URL ?>/modules/lab_results/view_lab_results.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($labs)): ?>
                <div class="p-4 text-center text-muted"><i class="bi bi-flask fs-2 d-block mb-2"></i>No lab results yet.</div>
                <?php else: ?>
                <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light"><tr><th>Test</th><th>Result</th><th>Date</th><th>Flag</th></tr></thead>
                    <tbody>
                    <?php foreach ($labs as $lab): ?>
                    <tr>
                        <td><?= htmlspecialchars($lab['test_name']) ?></td>
                        <td><?= htmlspecialchars($lab['result_value']) ?> <?= htmlspecialchars($lab['unit'] ?? '') ?></td>
                        <td><?= date('d M Y', strtotime($lab['result_date'])) ?></td>
                        <td><?= $lab['is_abnormal'] ? '<span class="badge bg-danger">Abnormal</span>' : '<span class="badge bg-success">Normal</span>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Messages -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="section-title mb-0"><i class="bi bi-chat-dots me-2"></i>Unread Messages</span>
                <a href="<?= BASE_URL ?>/modules/messaging/inbox.php" class="btn btn-sm btn-outline-primary">Inbox</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($msgs)): ?>
                <div class="p-4 text-center text-muted"><i class="bi bi-envelope-open fs-2 d-block mb-2"></i>No unread messages.</div>
                <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($msgs as $m): ?>
                    <a href="<?= BASE_URL ?>/modules/messaging/inbox.php?id=<?= $m['message_id'] ?>" class="list-group-item list-group-item-action">
                        <div class="d-flex justify-content-between">
                            <span class="fw-semibold small"><?= htmlspecialchars($m['sender_name']) ?></span>
                            <small class="text-muted"><?= date('d M', strtotime($m['sent_at'])) ?></small>
                        </div>
                        <div class="small text-truncate"><?= htmlspecialchars($m['subject'] ?? '(No subject)') ?></div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</div>

<!-- Add Dependent Modal -->
<div class="modal fade" id="addDependentModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="<?= BASE_URL ?>/modules/dependents/add_dependent.php" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Add Family Member</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">They will get a sub-ID like <strong><?= htmlspecialchars($display_pid) ?>-D1</strong> so their records are kept separate from yours.</p>
                <div class="mb-3">
                    <label class="form-label">Full Name *</label>
                    <input type="text" name="full_name" class="form-control" required placeholder="e.g. Priya Sharma">
                </div>
                <div class="mb-3">
                    <label class="form-label">Relationship *</label>
                    <select name="relationship" class="form-select" required>
                        <option value="">— Select —</option>
                        <option value="Daughter">Daughter</option>
                        <option value="Son">Son</option>
                        <option value="Spouse">Spouse</option>
                        <option value="Mother">Mother</option>
                        <option value="Father">Father</option>
                        <option value="Sibling">Sibling</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label">Date of Birth</label>
                        <input type="date" name="date_of_birth" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Gender</label>
                        <select name="gender" class="form-select">
                            <option value="">— Select —</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-person-check me-1"></i>Add Family Member</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
