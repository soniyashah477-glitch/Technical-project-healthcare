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

// Upcoming appointments
$appts = [];
if ($pid) {
    $stmt = $conn->prepare(
        "SELECT a.*, u.full_name AS clinician_name FROM appointments a
         JOIN users u ON a.clinician_id = u.user_id
         WHERE a.patient_id = ? AND a.appointment_date >= CURDATE() AND a.status = 'scheduled'
         ORDER BY a.appointment_date, a.appointment_time LIMIT 5");
    $stmt->bind_param('i', $pid); $stmt->execute();
    $r = $stmt->get_result(); while($row = $r->fetch_assoc()) $appts[] = $row; $stmt->close();
}

// Active prescriptions
$rxs = [];
if ($pid) {
    $stmt = $conn->prepare(
        "SELECT p.*, u.full_name AS clinician_name FROM prescriptions p
         JOIN users u ON p.prescribing_clinician_id = u.user_id
         WHERE p.patient_id = ? AND p.status = 'active' ORDER BY p.created_at DESC LIMIT 5");
    $stmt->bind_param('i', $pid); $stmt->execute();
    $r = $stmt->get_result(); while($row = $r->fetch_assoc()) $rxs[] = $row; $stmt->close();
}

// Recent lab results
$labs = [];
if ($pid) {
    $stmt = $conn->prepare(
        "SELECT * FROM lab_results WHERE patient_id = ? ORDER BY result_date DESC LIMIT 5");
    $stmt->bind_param('i', $pid); $stmt->execute();
    $r = $stmt->get_result(); while($row = $r->fetch_assoc()) $labs[] = $row; $stmt->close();
}

// Unread messages
$msgs = [];
$stmt = $conn->prepare(
    "SELECT m.*, u.full_name AS sender_name FROM messages m JOIN users u ON m.sender_id = u.user_id
     WHERE m.receiver_id = ? AND m.is_read = 0 ORDER BY m.sent_at DESC LIMIT 5");
$stmt->bind_param('i', $uid); $stmt->execute();
$r = $stmt->get_result(); while($row = $r->fetch_assoc()) $msgs[] = $row; $stmt->close();

// AI Predictions
$predictions = [];
if ($pid) {
    require_once __DIR__ . '/../modules/ai_engine/risk_predictor.php';
    $predictions = get_patient_predictions($pid);
}

// Counts
$appt_count = count($appts);
$rx_count   = count($rxs);
$lab_count  = 0;
if ($pid) {
    $r = $conn->query("SELECT COUNT(*) AS c FROM lab_results WHERE patient_id = $pid");
    $lab_count = (int)$r->fetch_assoc()['c'];
}
$consent_count = 0;
if ($pid) {
    $r = $conn->query("SELECT COUNT(*) AS c FROM consents WHERE patient_id = $pid AND is_active = 1");
    $consent_count = (int)$r->fetch_assoc()['c'];
}

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';

function risk_badge(string $level): string {
    return '<span class="risk-badge risk-' . $level . '"><span class="risk-dot"></span>' . ucfirst($level) . '</span>';
}
?>
<div class="page-wrapper">
<?= render_flash() ?>

<!-- Welcome -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h2 class="fw-bold mb-1">Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?> </h2>
        <p class="text-muted mb-0">Here's your health summary for today — <?= date('l, d F Y') ?></p>
    </div>
    <a href="<?= BASE_URL ?>/modules/appointments/book_appointment.php" class="btn btn-primary">
        <i class="bi bi-calendar-plus me-2"></i>Book Appointment
    </a>
</div>

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
    <!-- AI Risk Scores -->
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
                <div class="alert alert-info">No vitals/lab data available to generate predictions. Ask your clinician to record your vitals.</div>
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

    <!-- Upcoming Appointments -->
    <div class="col-lg-7">
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
<?php include __DIR__ . '/../includes/footer.php'; ?>
